<?php
/**
 * api_album_upload.php
 * Hazır Albüm Yükleme | ORİJİNAL KORUMA + CACHE (ÖNİZLEME) OLUŞTURMA | TRAFİK KOTASI KONTROLÜ
 */
ini_set('memory_limit', '512M'); 
set_time_limit(300);

error_reporting(E_ALL);
ini_set('display_errors', 0); 

require_once 'baglanti.php';
header('Content-Type: application/json');

if (!isset($db)) {
    echo json_encode(['status' => 'error', 'message' => 'Veritabanı bağlantısı yok']);
    exit;
}

// --- WEB CACHE (ÖNİZLEME) OLUŞTURMA FONKSİYONU ---
function createWebCache($source, $destination, $scaleRatio = 0.50, $quality = 85) {
    $info = @getimagesize($source);
    if (!$info) return false;

    $mime = $info['mime'];
    $width = $info[0];
    $height = $info[1];

    switch ($mime) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($source); break;
        case 'image/png': $image = @imagecreatefrompng($source); break;
        case 'image/webp': $image = @imagecreatefromwebp($source); break;
        default: return false; 
    }
    if (!$image) return false;

    // Telefonla çekilen dik fotoğrafların yan yatmasını engelleme (EXIF Koruması)
    if ($mime == 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($source);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3: $image = imagerotate($image, 180, 0); break;
                case 6: $image = imagerotate($image, -90, 0); list($width, $height) = [$height, $width]; break;
                case 8: $image = imagerotate($image, 90, 0); list($width, $height) = [$height, $width]; break;
            }
        }
    }

    // ORANTISAL PIXEL KÜÇÜLTME (Albüm sayfaları için %50 oranında küçült)
    $new_width = (int)($width * $scaleRatio);
    $new_height = (int)($height * $scaleRatio);

    $thumb = imagecreatetruecolor($new_width, $new_height);
    if ($mime == 'image/png' || $mime == 'image/webp') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    switch ($mime) {
        case 'image/jpeg': imagejpeg($thumb, $destination, $quality); break;
        case 'image/png': imagepng($thumb, $destination, round(9 - ($quality / 11.11))); break;
        case 'image/webp': imagewebp($thumb, $destination, $quality); break;
    }

    imagedestroy($image);
    imagedestroy($thumb);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['albums'])) {
    $musteri_id = (int)($_POST['m_id'] ?? 0);

    // KOTA VE FİRMA BİLGİSİ KONTROLÜ
    $q = $db->prepare("SELECT f.id as firma_id, f.aylik_trafik_kullanimi, f.trafik_asim_tarihi, f.ek_sure_gun, f.anlik_kesinti, f.ek_trafik_limiti, p.depolama_limiti 
                       FROM musteriler m 
                       JOIN firmalar f ON m.firma_id = f.id 
                       LEFT JOIN paketler p ON f.paket_id = p.id 
                       WHERE m.id = ?");
    $q->execute([$musteri_id]);
    $firmaBilgi = $q->fetch(PDO::FETCH_ASSOC);

    if (!$firmaBilgi) {
        echo json_encode(['status' => 'error', 'message' => 'Müşteri/Firma bulunamadı']);
        exit;
    }

    $firma_id = $firmaBilgi['firma_id'];
    
    // Yüklenecek boyut hesabı (İnternet/Trafik Tüketimi)
    $files = $_FILES['albums'];
    $upload_size = array_sum(is_array($files['size']) ? $files['size'] : [$files['size']]);

    // LİMİT KONTROL MATEMATİĞİ (SOFT & HARD LİMİT)
    $paket_depolama = (int)$firmaBilgi['depolama_limiti'];
    $ek_trafik = (int)$firmaBilgi['ek_trafik_limiti'];
    $trafik_limiti_byte = (($paket_depolama * 10) + $ek_trafik) * 1048576;
    $yeni_kullanim_byte = $firmaBilgi['aylik_trafik_kullanimi'] + $upload_size;

    if ($trafik_limiti_byte > 0 && $yeni_kullanim_byte > $trafik_limiti_byte) {
        if ($firmaBilgi['anlik_kesinti'] == 1) {
            echo json_encode(['status' => 'error', 'message' => 'Aylık trafik kotası aşıldı (Anlık Kesinti aktif). Yükleme reddedildi.']);
            exit;
        }
        if ($firmaBilgi['trafik_asim_tarihi']) {
            $bitis_zamani = strtotime($firmaBilgi['trafik_asim_tarihi'] . " + " . (int)$firmaBilgi['ek_sure_gun'] . " days");
            if (time() > $bitis_zamani) {
                echo json_encode(['status' => 'error', 'message' => 'Trafik kotası aşıldı ve tanınan tolerans süresi doldu. Paketinizi yükseltin.']);
                exit;
            }
        } else {
            // İlk kez aşıldıysa tarihi atıyoruz
            $db->prepare("UPDATE firmalar SET trafik_asim_tarihi = NOW() WHERE id = ?")->execute([$firma_id]);
        }
    }

    // Klasör oluşturma
    $target_dir = __DIR__ . "/uploads/haziralbumler/{$firma_id}/{$musteri_id}/";
    $cache_dir = $target_dir . "cache/"; // Küçültülmüş dosyaların saklanacağı yer

    if (!is_dir($target_dir)) { @mkdir($target_dir, 0775, true); }
    if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0775, true); }

    $uploaded_count = 0;
    $errors = [];

    $num_files = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $num_files; $i++) {
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        if ($error !== 0) continue;

        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];

        $safe_name = str_replace([' ', '(', ')', '[', ']', '{', '}', 'ş','ı','ğ','ü','ö','ç','İ','Ş','Ğ','Ü','Ö','Ç'], '_', $name);
        $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;

        $dest_path_original = $target_dir . $safe_name;
        $dest_path_cache = $cache_dir . $safe_name;

        // 1. Orijinal dosyayı olduğu gibi (boyutunu bozmadan) indirilebilmesi için kaydet
        if (move_uploaded_file($tmp, $dest_path_original)) {
            // 2. Web önizlemesi için küçültülmüş kopyayı (cache) oluştur
            // Albüm sayfaları olduğu için %50 oranında küçültüyoruz (ScaleRatio = 0.50)
            createWebCache($dest_path_original, $dest_path_cache, 0.50, 85);
            
            $uploaded_count++;
        } else {
            $errors[] = "$name kaydedilemedi";
        }
    }

    // --- TRAFİK (BANDWIDTH) GÜNCELLEME İŞLEMİ ---
    if ($upload_size > 0) {
        $db->prepare("UPDATE firmalar SET aylik_trafik_kullanimi = aylik_trafik_kullanimi + ?, toplam_trafik_kullanimi = toplam_trafik_kullanimi + ? WHERE id = ?")
           ->execute([$upload_size, $upload_size, $firma_id]);
    }

    echo json_encode(['status' => 'success', 'count' => $uploaded_count, 'errors' => $errors]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Geçersiz istek']);
exit;