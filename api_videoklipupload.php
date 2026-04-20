<?php
// --- Hata Yakalama ---
ini_set('display_errors', 0);
error_reporting(0);

// --- Oturum Kontrolü (Güvenlik İçin) ---
session_start();
if (!isset($_SESSION['kullanici_id']) && !isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit("Erişim reddedildi.");
}

require_once 'baglanti.php';

// --- TUS Protokolü Sunucusu ---
$method = $_SERVER['REQUEST_METHOD'];

// AJAX/Fetch istekleri için gerekli CORS ve TUS Headerları
if ($method === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PATCH, HEAD");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Upload-Length, Upload-Offset, Tus-Resumable, Upload-Metadata");
    header("Tus-Resumable: 1.0.0");
    header("Tus-Max-Size: " . (10 * 1024 * 1024 * 1024)); // 10GB limit
    http_response_code(204);
    exit;
}

// Güvenli isim oluşturma
function cleanFileName($filename) {
    $filename = str_replace([' ', '(', ')', '[', ']', '{', '}', 'ş','ı','ğ','ü','ö','ç','İ','Ş','Ğ','Ü','Ö','Ç'], '_', $filename);
    $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', $filename);
    return $filename;
}

function getCurrentUrl() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'], 2)[0];
}

// 1. UPLOAD İSTEĞİ BAŞLATMA (POST)
if ($method === 'POST') {
    // Gelen Metadata'yı Çözümle
    $metadataHeader = $_SERVER['HTTP_UPLOAD_METADATA'] ?? '';
    $metadata = [];
    if (!empty($metadataHeader)) {
        foreach (explode(',', $metadataHeader) as $part) {
            list($key, $value) = explode(' ', $part);
            $metadata[$key] = base64_decode($value);
        }
    }

    $firma_id = $metadata['firma_id'] ?? '';
    $musteri_id = $metadata['musteri_id'] ?? '';
    $original_name = $metadata['original_name'] ?? 'video_' . time() . '.mp4';

    if(empty($firma_id) || empty($musteri_id)) {
        http_response_code(400);
        exit("Eksik Müşteri/Firma ID");
    }

    $fileSize = (int)($_SERVER['HTTP_UPLOAD_LENGTH'] ?? 0);

    // KOTA VE FİRMA BİLGİSİ KONTROLÜ
    $q = $db->prepare("SELECT aylik_trafik_kullanimi, trafik_asim_tarihi, ek_sure_gun, anlik_kesinti, ek_trafik_limiti, p.depolama_limiti 
                       FROM firmalar f 
                       LEFT JOIN paketler p ON f.paket_id = p.id 
                       WHERE f.id = ?");
    $q->execute([$firma_id]);
    $firmaBilgi = $q->fetch(PDO::FETCH_ASSOC);

    if ($firmaBilgi) {
        $trafik_limiti_byte = (((int)$firmaBilgi['depolama_limiti'] * 10) + (int)$firmaBilgi['ek_trafik_limiti']) * 1048576;
        $yeni_kullanim_byte = $firmaBilgi['aylik_trafik_kullanimi'] + $fileSize;

        if ($trafik_limiti_byte > 0 && $yeni_kullanim_byte > $trafik_limiti_byte) {
            if ($firmaBilgi['anlik_kesinti'] == 1) {
                http_response_code(403);
                exit("Aylık trafik kotanız aşıldığı için video yüklemesi reddedildi.");
            }
            if ($firmaBilgi['trafik_asim_tarihi']) {
                $bitis_zamani = strtotime($firmaBilgi['trafik_asim_tarihi'] . " + " . (int)$firmaBilgi['ek_sure_gun'] . " days");
                if (time() > $bitis_zamani) {
                    http_response_code(403);
                    exit("Trafik kotanız aşıldı ve tanınan ek süre doldu. Lütfen paketi yükseltin.");
                }
            } else {
                $db->prepare("UPDATE firmalar SET trafik_asim_tarihi = NOW() WHERE id = ?")->execute([$firma_id]);
            }
        }
    }

    // Müşteriye özel klasör yapısı
    $targetDir = __DIR__ . '/uploads/videolar/' . $firma_id . '/' . $musteri_id . '/';
    $cacheDir = $targetDir . 'cache/';

    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

    $fileId = uniqid('tus_');
    $filePath = $cacheDir . $fileId;
    $metaPath = $cacheDir . $fileId . '.meta';

    $metaDataContent = json_encode([
        'file_size' => $fileSize, 
        'original_name' => $original_name,
        'firma_id' => $firma_id,
        'musteri_id' => $musteri_id,
        'target_dir' => $targetDir
    ]);
    
    file_put_contents($metaPath, $metaDataContent);
    touch($filePath);

    header("Access-Control-Expose-Headers: Location, Tus-Resumable");
    header("Tus-Resumable: 1.0.0");
    header("Location: " . getCurrentUrl() . "?upload_id=" . $fileId . "&fid=" . urlencode($firma_id) . "&mid=" . urlencode($musteri_id));
    http_response_code(201);
    exit;
}

// 2. PARÇA PARÇA YÜKLEME VE TRAFİĞİ ANLIK YAZMA (PATCH)
if ($method === 'PATCH') {
    $fileId = $_GET['upload_id'] ?? null;
    $firma_id = $_GET['fid'] ?? null;
    $musteri_id = $_GET['mid'] ?? null;

    if (!$fileId || !$firma_id || !$musteri_id) { http_response_code(404); exit; }

    $cacheDir = __DIR__ . '/uploads/videolar/' . $firma_id . '/' . $musteri_id . '/cache/';
    $filePath = $cacheDir . $fileId;
    $metaPath = $cacheDir . $fileId . '.meta';

    if (!file_exists($filePath) || !file_exists($metaPath)) { http_response_code(404); exit; }

    // Gelen veri paketini al
    $chunk = file_get_contents('php://input');
    $chunkSize = strlen($chunk);
    
    // Veriyi geçici dosyaya yaz
    file_put_contents($filePath, $chunk, FILE_APPEND);
    
    // ANLIK TRAFİK SAYACI (Sistemin yorulmasını önler ve kopmalarda bile harcanan interneti faturaya yansıtır)
    if($chunkSize > 0) {
        $db->prepare("UPDATE firmalar SET aylik_trafik_kullanimi = aylik_trafik_kullanimi + ?, toplam_trafik_kullanimi = toplam_trafik_kullanimi + ? WHERE id = ?")
           ->execute([$chunkSize, $chunkSize, $firma_id]);
    }
    
    $currentSize = filesize($filePath);
    $metaData = json_decode(file_get_contents($metaPath), true);
    $totalSize = $metaData['file_size'];

    // EĞER YÜKLEME TAMAMLANDIYSA
    if ($currentSize >= $totalSize) {
        $finalPath = $metaData['target_dir'] . cleanFileName($metaData['original_name']);
        rename($filePath, $finalPath);
        unlink($metaPath);
    }

    header("Access-Control-Expose-Headers: Upload-Offset, Tus-Resumable");
    header("Tus-Resumable: 1.0.0");
    header("Upload-Offset: " . $currentSize);
    http_response_code(204);
    exit;
}

// 3. DURUM SORGULAMA (HEAD)
if ($method === 'HEAD') {
    $fileId = $_GET['upload_id'] ?? null;
    $firma_id = $_GET['fid'] ?? null;
    $musteri_id = $_GET['mid'] ?? null;

    if (!$fileId || !$firma_id || !$musteri_id) { http_response_code(404); exit; }

    $cacheDir = __DIR__ . '/uploads/videolar/' . $firma_id . '/' . $musteri_id . '/cache/';
    $filePath = $cacheDir . $fileId;

    if (!file_exists($filePath)) { http_response_code(404); exit; }

    $currentSize = filesize($filePath);

    header("Access-Control-Expose-Headers: Upload-Offset, Upload-Length, Tus-Resumable, Cache-Control");
    header("Cache-Control: no-store");
    header("Tus-Resumable: 1.0.0");
    header("Upload-Offset: " . $currentSize);
    http_response_code(200);
    exit;
}

http_response_code(404);
echo "Not Found";
?>