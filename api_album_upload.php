<?php
/**
 * api_album_upload.php
 * Hazır Albüm Yükleme | %20 Küçültme | TRAFİK KOTASI VE SOFT-LİMİT KONTROLÜ EKLENDİ
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
    if (!is_dir($target_dir)) { mkdir($target_dir, 0775, true); }

    $uploaded_count = 0;
    $errors = [];
    $scaleRatio = 0.80; // %20 Küçültme
    $jpegQuality = 85;

    $num_files = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $num_files; $i++) {
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        if ($error !== 0) continue;

        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];

        $safe_name = str_replace([' ', '(', ')', '[', ']', '{', '}', 'ş','ı','ğ','ü','ö','ç','İ','Ş','Ğ','Ü','Ö','Ç'], '_', $name);
        $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;

        // Kaynak oluştur
        switch ($ext) {
            case 'jpg':
            case 'jpeg': $src = @imagecreatefromjpeg($tmp); break;
            case 'png': $src = @imagecreatefrompng($tmp); break;
            case 'webp': $src = @imagecreatefromwebp($tmp); break;
            default: $src = null;
        }

        if (!$src) { $errors[] = "$name açılamadı"; continue; }

        // EXIF Orientation
        if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            if (!empty($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3: $src = imagerotate($src, 180, 0); break;
                    case 6: $src = imagerotate($src, -90, 0); break;
                    case 8: $src = imagerotate($src, 90, 0); break;
                }
            }
        }

        // ORANTISAL PIXEL KÜÇÜLTME
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $newW = (int)($srcW * $scaleRatio);
        $newH = (int)($srcH * $scaleRatio);
        $dst = imagecreatetruecolor($newW, $newH);

        if ($ext === 'png' || $ext === 'webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        $dest_path = $target_dir . $safe_name;

        // Kaydet
        $saved = false;
        if ($ext === 'jpg' || $ext === 'jpeg') { $saved = imagejpeg($dst, $dest_path, $jpegQuality); } 
        elseif ($ext === 'png') { $saved = imagepng($dst, $dest_path, 6); } 
        elseif ($ext === 'webp') { $saved = imagewebp($dst, $dest_path, 80); }

        imagedestroy($src); imagedestroy($dst);

        if ($saved) $uploaded_count++;
        else $errors[] = "$name kaydedilemedi";
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