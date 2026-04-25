<?php
ob_start();
require_once 'musteri_header.php';

// ═══════════════════════════════════════════════
// CSRF
// ═══════════════════════════════════════════════
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function csrf_dogrula() {
    $gelen = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $token = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    if (!hash_equals($token, $gelen)) {
        http_response_code(403);
        die('Geçersiz istek.');
    }
}

// ═══════════════════════════════════════════════
// YARDIMCI FONKSİYONLAR
// ═══════════════════════════════════════════════
function getMediaFiles($dir, $extensions) {
    $results = [];
    if (!is_dir($dir)) return $results;
    $files = scandir($dir);
    if (!$files) return $results;
    foreach ($files as $f) {
        if ($f === '.' || $f === '..' || $f === 'cache') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $extensions, true)) {
            $results[] = ['name' => $f, 'size' => filesize($dir . $f)];
        }
    }
    return $results;
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576,    2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024,       2) . ' KB';
    return $bytes . ' B';
}

function guvenliFisyaAdi($name) {
    $tr  = ['ğ','Ğ','ç','Ç','ş','Ş','ü','Ü','ö','Ö','ı','İ'];
    $en  = ['g','G','c','C','s','S','u','U','o','O','i','I'];
    $name = str_replace($tr, $en, $name);
    $name = str_replace(['&',' ','%26','+','='], '_', $name);
    return preg_replace('/[^a-zA-Z0-9_.-]/', '', $name);
}

// ═══════════════════════════════════════════════
// DOWNLOAD & TRAFİK KOTASI
// ═══════════════════════════════════════════════
if (isset($_GET['download_file'])) {
    $raw       = $_GET['download_file'];
    $base      = realpath(__DIR__ . '/../ibircari.xyz/');
    $full_path = realpath(__DIR__ . '/../ibircari.xyz/' . $raw);

    if (!$full_path || !$base || strpos($full_path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full_path)) {
        http_response_code(404);
        die('Dosya bulunamadı.');
    }

    $fsize = filesize($full_path);
    $conn  = isset($db) ? $db : (isset($pdo) ? $pdo : null);
    $mid   = (int)(isset($_SESSION['musteri_id']) ? $_SESSION['musteri_id'] : 0);

    if ($conn && $mid > 0) {
        try {
            $q = $conn->prepare("SELECT f.id, f.aylik_trafik_kullanimi, f.trafik_asim_tarihi,
                                        f.ek_sure_gun, f.anlik_kesinti, f.ek_trafik_limiti, p.depolama_limiti, m.portal_indirme_izni
                                 FROM musteriler m
                                 JOIN firmalar f ON m.firma_id = f.id
                                 LEFT JOIN paketler p ON f.paket_id = p.id
                                 WHERE m.id = ?");
            $q->execute([$mid]);
            $fb = $q->fetch(PDO::FETCH_ASSOC);
            if ($fb) {
                // YENİ: İndirme izni kontrolü
                if($fb['portal_indirme_izni'] == 0) { die('<h2>Hata</h2><p>İndirme yetkiniz stüdyo tarafından kapatılmıştır.</p>'); }

                $fid   = $fb['id'];
                $limit = (((int)$fb['depolama_limiti'] * 10) + (int)$fb['ek_trafik_limiti']) * 1048576;
                $yeni  = $fb['aylik_trafik_kullanimi'] + $fsize;
                if ($limit > 0 && $yeni > $limit) {
                    $asim = $fb['trafik_asim_tarihi'];
                    $esur = (int)$fb['ek_sure_gun'];
                    if ($fb['anlik_kesinti'] == 1 || ($asim && time() > strtotime("$asim +{$esur} days"))) {
                        die('<h2>Hizmet Kısıtlaması</h2><p>Stüdyonun aylık indirme limiti doldu.</p>');
                    }
                    if (!$asim) $conn->prepare("UPDATE firmalar SET trafik_asim_tarihi=NOW() WHERE id=?")->execute([$fid]);
                }
                $conn->prepare("UPDATE firmalar SET aylik_trafik_kullanimi=aylik_trafik_kullanimi+?, toplam_trafik_kullanimi=toplam_trafik_kullanimi+? WHERE id=?")->execute([$fsize, $fsize, $fid]);
                $conn->prepare("INSERT INTO musteri_loglari (firma_id, musteri_id, islem, detay, ip_adresi) VALUES (?,?,'Dosya İndirme',?,?)")->execute([$fid, $mid, basename($full_path) . ' (' . formatSize($fsize) . ')', $_SERVER['REMOTE_ADDR']]);
            }
        } catch (Exception $e) {}
    }

    $pi   = pathinfo(isset($_GET['custom_name']) ? $_GET['custom_name'] : basename($full_path));
    $sname = guvenliFisyaAdi($pi['filename']) . (isset($pi['extension']) ? '.' . $pi['extension'] : '');

    while (ob_get_level()) ob_end_clean();
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $sname . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . $fsize);
    $h = @fopen($full_path, 'rb');
    if ($h) { while (!feof($h)) { echo fread($h, 1048576); ob_flush(); flush(); } fclose($h); }
    else readfile($full_path);
    exit;
}

// ═══════════════════════════════════════════════
// MÜŞTERİ & FİRMA
// ═══════════════════════════════════════════════
$m_id = (int)(isset($_SESSION['musteri_id']) ? $_SESSION['musteri_id'] : 0);
$conn = isset($db) ? $db : (isset($pdo) ? $pdo : null);

$stmt = $conn->prepare("SELECT m.*, f.id AS firma_id, f.firma_adi,
                             f.telefon AS firma_tel, f.email AS firma_mail, f.adres AS firma_adres
                      FROM musteriler m
                      JOIN firmalar f ON m.firma_id = f.id
                      WHERE m.id = ? LIMIT 1");
$stmt->execute([$m_id]);
$pd = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pd) die('Oturum hatası.');

$firma_id = $pd['firma_id'];

// Giriş logu
try {
    $conn->prepare("INSERT INTO musteriportal (firma_id, musteri_id, last_login) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE last_login=NOW()")->execute([$firma_id, $m_id]);
} catch (Exception $e) {}

// ═══════════════════════════════════════════════
// POST İŞLEMLERİ (Sözleşme, Müzik, Anket, Revize)
// ═══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula();
    
    // 1. E-İMZA & SÖZLEŞME ONAYI
    if (isset($_POST['sozlesme_imzala'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $conn->prepare("UPDATE musteriler SET kvkk_onay = 1, sozlesme_imza_ip = ?, sozlesme_imza_tarih = NOW() WHERE id = ?")->execute([$ip, $m_id]);
        header("Location: dashboard.php?msg=sozlesme_ok"); exit;
    }
    
    // 2. NPS MÜŞTERİ MEMNUNİYET ANKETİ
    if (isset($_POST['nps_gonder'])) {
        $puan = (int)$_POST['nps_puani'];
        $yorum = trim($_POST['nps_yorum'] ?? '');
        $conn->prepare("INSERT INTO musteri_degerlendirmeleri (firma_id, musteri_id, puan, yorum) VALUES (?, ?, ?, ?)")->execute([$firma_id, $m_id, $puan, $yorum]);
        header("Location: dashboard.php?msg=anket_ok"); exit;
    }
    
    // 3. MÜZİK/KLİP LİNKİ KAYDET
    if (isset($_POST['muzik_kaydet'])) {
        $link = trim($_POST['muzik_linki']);
        $eski_not = $pd['ozel_notlar_html'] ?? '';
        $yeni_not = $eski_not . "<br><br><b>Müşteri Klip Müziği İstedi:</b> <a href='$link' target='_blank'>$link</a>";
        $conn->prepare("UPDATE musteriler SET ozel_notlar_html = ? WHERE id = ?")->execute([$yeni_not, $m_id]);
        header("Location: dashboard.php?msg=muzik_ok"); exit;
    }

    if (isset($_POST['musteri_album_onay'])) {
        $kid = !empty($_POST['secilen_kapak']) ? (int)$_POST['secilen_kapak'] : null;
        $conn->prepare("UPDATE musteriler SET secilen_kapak_id=?, kapak_onaylayan='Müşteri', workflow_status=5 WHERE id=? AND firma_id=?")->execute([$kid, $m_id, $firma_id]);
        header('Location: dashboard.php?onay=basarili'); exit;
    }
    if (isset($_POST['musteri_revize'])) {
        $not = trim(isset($_POST['revize_notu']) ? $_POST['revize_notu'] : '');
        if ($not) { $conn->prepare("INSERT INTO revizeler (musteri_id, firma_id, notlar, durum, created_at) VALUES (?,?,?,0,NOW())")->execute([$m_id, $firma_id, $not]); }
        $conn->prepare("UPDATE musteriler SET workflow_status=7 WHERE id=? AND firma_id=?")->execute([$m_id, $firma_id]);
        header('Location: dashboard.php?revize=basarili'); exit;
    }
}

// ═══════════════════════════════════════════════
// PARÇALI ZIP İNDİRME MOTORU
// ═══════════════════════════════════════════════
if (isset($_GET['download_zip']) && isset($_GET['part'])) {
    if ($pd['portal_indirme_izni'] == 0) die('İndirme yetkiniz stüdyo tarafından kapatılmıştır.');
    
    $tur = $_GET['tur'] ?? 'haziralbumler';
    $part = (int)$_GET['part'];
    $batch_size = 100; 
    
    $base_path = realpath(__DIR__ . '/../ibircari.xyz/uploads/' . $tur . '/' . $firma_id . '/' . $m_id . '/');
    if (!$base_path || !is_dir($base_path)) die('Klasör bulunamadı.');

    $files = array_diff(scandir($base_path), ['.', '..', 'cache']);
    $valid_files = [];
    foreach ($files as $f) { if (is_file($base_path . '/' . $f)) $valid_files[] = $f; }
    
    if (empty($valid_files)) die('İndirilecek dosya yok.');

    $chunks = array_chunk($valid_files, $batch_size);
    $idx = $part - 1;
    if (!isset($chunks[$idx])) die('Geçersiz part numarası.');

    $zip_name = "Studio_{$m_id}_{$tur}_Part{$part}.zip";
    $zip_path = sys_get_temp_dir() . '/' . $zip_name;
    
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) die('ZIP oluşturulamadı. Sunucu hatası.');

    foreach ($chunks[$idx] as $f) { $zip->addFile($base_path . '/' . $f, $f); }
    $zip->close();

    $fsize = filesize($zip_path);

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$zip_name.'"');
    header('Content-Length: ' . $fsize);
    readfile($zip_path);
    unlink($zip_path); 
    exit;
}

// ═══════════════════════════════════════════════
// İŞ AKIŞI
// ═══════════════════════════════════════════════
$wf_raw = isset($pd['workflow_status']) ? $pd['workflow_status'] : 0;
if (!is_numeric($wf_raw)) {
    $wf_map = ['foto_bekleniyor'=>0,'secim_bekleniyor'=>1,'secim_tamam'=>2,'tasarimda'=>3,'album_onay_bekliyor'=>4,'baskida'=>5,'teslim_edildi'=>6];
    $wf = isset($wf_map[$wf_raw]) ? $wf_map[$wf_raw] : 0;
} else {
    $wf = (int)$wf_raw;
}

$wf_labels = [
    0 => ['Hazırlanıyor',        'step-gray',    'fa-hourglass-start'],
    1 => ['Seçim Bekliyor',      'step-blue',    'fa-images'],
    2 => ['Seçim Tamamlandı',    'step-cyan',    'fa-check-circle'],
    3 => ['Tasarımda',           'step-orange',  'fa-paint-brush'],
    4 => ['Albüm Onayında',      'step-green',   'fa-star'],
    5 => ['Baskıda',             'step-dark',    'fa-print'],
    6 => ['Teslim Edildi',       'step-success', 'fa-gift'],
    7 => ['Revize Talep Edildi', 'step-orange',  'fa-redo'],
];
$wl = isset($wf_labels[$wf]) ? $wf_labels[$wf] : ['İşlemde','step-gray','fa-circle'];

// ═══════════════════════════════════════════════
// VERİ
// ═══════════════════════════════════════════════

// Kapaklar
$stmt = $conn->prepare("SELECT * FROM kapak_modelleri WHERE firma_id=? AND durum=1");
$stmt->execute([$firma_id]);
$kapaklar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Medya yolları
$upload_base = __DIR__ . '/../ibircari.xyz/uploads/';
$dir_albumler = $upload_base . "haziralbumler/$firma_id/$m_id/";
$dir_videolar = $upload_base . "videolar/$firma_id/$m_id/";
$web_base     = 'https://ibircari.xyz/uploads/';
$url_albumler = $web_base . "haziralbumler/$firma_id/$m_id/";
$url_videolar = $web_base . "videolar/$firma_id/$m_id/";

$albumler = getMediaFiles($dir_albumler, ['jpg','jpeg','png','webp']);
$videolar = getMediaFiles($dir_videolar, ['mp4','mov','webm','avi','mkv']);
$fotograflar = getMediaFiles($upload_base . "albumler/$firma_id/$m_id/", ['jpg','jpeg','png','webp']);

// Finans
$stmt = $conn->prepare("SELECT SUM(CASE WHEN islem_turu='satis' THEN toplam_tutar ELSE 0 END) AS borc,
                             SUM(CASE WHEN islem_turu='tahsilat' THEN toplam_tutar ELSE 0 END) AS odenen
                      FROM hareketler WHERE musteri_id=?");
$stmt->execute([$m_id]);
$fin = $stmt->fetch(PDO::FETCH_ASSOC);
$kalan = ($fin['borc'] ?? 0) - ($fin['odenen'] ?? 0);

// Hareketler
$stmt = $conn->prepare("SELECT * FROM hareketler WHERE musteri_id=? ORDER BY islem_tarihi DESC");
$stmt->execute([$m_id]);
$hareketler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Çekimler
$stmt = $conn->prepare("SELECT urun_aciklama AS baslik, vade_tarihi AS tarih, notlar FROM hareketler WHERE musteri_id=? AND islem_turu='satis' AND vade_tarihi >= CURDATE() ORDER BY vade_tarihi ASC");
$stmt->execute([$m_id]);
$cekimler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sözleşme
$sozlesme = false;
try {
    $stmt = $conn->prepare("SELECT sozlesme_maddeleri, sozlesme_no FROM sozlesmeler WHERE musteri_id=? LIMIT 1");
    $stmt->execute([$m_id]);
    $sozlesme = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Seçim sayısı
$secim_adet = 0;
$sc = isset($db_secim) ? $db_secim : $conn;
try {
    $ss = $sc->prepare("SELECT COUNT(*) FROM user_selections WHERE cari_musteri_id=? AND (selection_type=1 OR selection_type=3)");
    $ss->execute([$m_id]);
    $secim_adet = (int)$ss->fetchColumn();
} catch (Exception $e) {}

// NPS Kontrol
$nps_verildi_mi = false;
try {
    $nps_kontrol = $conn->prepare("SELECT id FROM musteri_degerlendirmeleri WHERE musteri_id = ?");
    $nps_kontrol->execute([$m_id]);
    if ($nps_kontrol->fetch()) { $nps_verildi_mi = true; }
} catch(Exception $e){}

$max_secim  = (int)(isset($pd['max_secim']) ? $pd['max_secim'] : 0);
$isim       = htmlspecialchars(explode(' ', isset($pd['ad_soyad']) ? $pd['ad_soyad'] : '')[0]);
$gelin      = htmlspecialchars(isset($pd['gelin_ad']) ? $pd['gelin_ad'] : '');
$damat      = htmlspecialchars(isset($pd['damat_ad']) ? $pd['damat_ad'] : '');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Müşteri Paneli | <?= htmlspecialchars($pd['firma_adi'] ?? 'Stüdyo') ?></title>
<link rel="stylesheet" href="assets/css/portal_lively.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .blur-background { filter: blur(8px); pointer-events: none; user-select: none; }
    .kvkk-modal { display: block; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; overflow-y: auto; }
    .kvkk-content { max-width: 700px; margin: 40px auto; background: #fff; padding: 40px; border-radius: 20px; box-shadow: 0 15px 40px rgba(0,0,0,0.4); text-align: center; }
    .kvkk-btn { background: #3182ce; color: #fff; padding: 15px 30px; border-radius: 50px; font-size: 1.1rem; font-weight: bold; border: none; cursor: pointer; width: 100%; transition: 0.2s; }
    .kvkk-btn:hover { background: #2b6cb0; }
    
    .nps-modal { display: flex; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9998; }
    .nps-content { max-width: 500px; width: 90%; background: #fff; padding: 40px; border-radius: 20px; text-align: center; }
    .star-rating-input i { font-size: 35px; color: #e2e8f0; cursor: pointer; transition: 0.2s; }
    .star-rating-input i.active, .star-rating-input i:hover, .star-rating-input i:hover ~ i { color: #ecc94b; }
    
    .btn-download-part { background:#edf2f7; color:#2b6cb0; border:1px solid #cbd5e0; padding: 6px 12px; font-size: 0.8rem; border-radius: 50px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; transition: 0.2s; font-weight: bold; }
    .btn-download-part:hover { background: #2b6cb0; color:#fff; }
</style>
</head>
<body style="background-color: #f7fafc;">

<!-- 🔐 E-İMZA VE SÖZLEŞME ONAY KALKANI -->
<?php if ($pd['portal_kvkk_iste'] == 1 && $pd['kvkk_onay'] == 0): ?>
<div class="kvkk-modal">
    <div class="kvkk-content">
        <i class="fas fa-file-signature fa-4x text-primary" style="color: #3182ce; margin-bottom: 20px;"></i>
        <h3 style="margin-bottom: 10px; color: #2d3748;">Dijital Sözleşme ve İzin Onayı</h3>
        <p style="color: #718096; font-size: 0.9rem; margin-bottom: 20px;">Portala erişmek için sözleşme maddelerini ve KVKK izinlerini onaylamanız gerekmektedir.</p>
        
        <div style="background: #f7fafc; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; max-height: 250px; overflow-y: auto; font-size: 0.85rem; color: #4a5568; text-align: left; margin-bottom: 20px;">
            <?php if($sozlesme && !empty($sozlesme['sozlesme_maddeleri'])): ?>
                <b style="color:#2d3748;">Sözleşme Maddeleriniz:</b><br><br>
                <?= nl2br(htmlspecialchars($sozlesme['sozlesme_maddeleri'])) ?>
            <?php else: ?>
                Sözleşme maddesi bulunmamaktadır.
            <?php endif; ?>
            <hr style="border-top: 1px solid #cbd5e0; margin: 15px 0;">
            <b style="color:#2d3748;">6698 Sayılı KVKK Onayı:</b><br>
            Çekimini gerçekleştirdiğimiz fotoğraf ve videolarınızın stüdyomuzun kurumsal sosyal medya hesaplarında (Instagram, Web Sitesi vb.) referans amaçlı paylaşılabileceğine izin vermiş sayılırsınız. (İzin vermek istemiyorsanız onayladıktan sonra stüdyomuzla iletişime geçebilirsiniz).
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <button type="submit" name="sozlesme_imzala" class="kvkk-btn"><i class="fas fa-check-double me-2"></i> Okudum, İmzalandı Kabul Ediyorum</button>
            <div style="margin-top: 15px; font-size: 0.75rem; color: #a0aec0;"><i class="fas fa-fingerprint me-1"></i> Dijital imzanız IP adresiniz ve zaman damgasıyla birlikte kayıt altına alınacaktır.</div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ⭐ NPS MÜŞTERİ MEMNUNİYET ANKETİ (İş Bittiğinde Çıkar) -->
<?php if ($wf >= 6 && !$nps_verildi_mi && $pd['kvkk_onay'] == 1): ?>
<div class="nps-modal" id="npsModal">
    <div class="nps-content">
        <i class="fas fa-star fa-4x mb-3" style="color:#ecc94b; margin-bottom:15px;"></i>
        <h3 style="color:#2d3748; margin-bottom:10px;">Hizmetimizden Memnun Kaldınız mı?</h3>
        <p style="color:#718096; font-size:0.9rem; margin-bottom:20px;">Sürecin tamamını değerlendirerek bize puan verin. Sizin fikriniz bizim için çok değerli!</p>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="nps_puani" id="nps_puani" value="5">
            
            <div class="star-rating-input" onmouseout="resetStars()" style="margin-bottom:20px; display:flex; justify-content:center; gap:10px;">
                <i class="fas fa-star active" data-val="1" onmouseover="hoverStars(1)" onclick="setStars(1)"></i>
                <i class="fas fa-star active" data-val="2" onmouseover="hoverStars(2)" onclick="setStars(2)"></i>
                <i class="fas fa-star active" data-val="3" onmouseover="hoverStars(3)" onclick="setStars(3)"></i>
                <i class="fas fa-star active" data-val="4" onmouseover="hoverStars(4)" onclick="setStars(4)"></i>
                <i class="fas fa-star active" data-val="5" onmouseover="hoverStars(5)" onclick="setStars(5)"></i>
            </div>
            
            <textarea name="nps_yorum" rows="3" placeholder="Deneyiminizi kısaca özetler misiniz? (Opsiyonel)" style="width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:10px; margin-bottom:15px; font-family:inherit;"></textarea>
            
            <button type="submit" name="nps_gonder" style="width:100%; background:#ecc94b; color:#744210; padding:12px; border:none; border-radius:50px; font-weight:bold; cursor:pointer;"><i class="fas fa-paper-plane me-2"></i> Puanımı Gönder</button>
            <button type="button" onclick="document.getElementById('npsModal').style.display='none'" style="background:none; border:none; color:#a0aec0; margin-top:15px; cursor:pointer; font-size:0.85rem;">Daha Sonra</button>
        </form>
    </div>
</div>
<script>
    let currentNPS = 5;
    function hoverStars(val) {
        document.querySelectorAll('.star-rating-input i').forEach(el => {
            el.classList.toggle('active', el.getAttribute('data-val') <= val);
        });
    }
    function resetStars() {
        document.querySelectorAll('.star-rating-input i').forEach(el => {
            el.classList.toggle('active', el.getAttribute('data-val') <= currentNPS);
        });
    }
    function setStars(val) {
        currentNPS = val;
        document.getElementById('nps_puani').value = val;
    }
</script>
<?php endif; ?>

<!-- 🚀 ASIL PORTAL İÇERİĞİ -->
<div class="dash-wrap <?= ($pd['portal_kvkk_iste'] == 1 && $pd['kvkk_onay'] == 0) ? 'blur-background' : '' ?>">

    <!-- MESAJ KUTULARI -->
    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'sozlesme_ok'): ?>
            <div class="flash success" style="background:#f0fdf4; border:1px solid #86efac; color:#15803d; padding:16px 20px; border-radius:12px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-weight:600; font-size:0.95rem;">
                <i class="fas fa-check-circle fa-lg"></i> Sözleşme dijital olarak imzalandı. Teşekkürler.
            </div>
        <?php elseif ($_GET['msg'] == 'anket_ok'): ?>
            <div class="flash success" style="background:#fffff0; border:1px solid #fef08a; color:#744210; padding:16px 20px; border-radius:12px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-weight:600; font-size:0.95rem;">
                <i class="fas fa-heart fa-lg"></i> Değerlendirmeniz için çok teşekkür ederiz!
            </div>
        <?php elseif ($_GET['msg'] == 'muzik_ok'): ?>
            <div class="flash success" style="background:#ebf8ff; border:1px solid #90cdf4; color:#2c5282; padding:16px 20px; border-radius:12px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-weight:600; font-size:0.95rem;">
                <i class="fas fa-music fa-lg"></i> Müzik seçiminiz başarıyla stüdyomuza iletildi.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['onay']) && $_GET['onay'] == 'basarili'): ?>
    <div class="flash success" style="background:#f0fdf4; border:1px solid #86efac; color:#15803d; padding:16px 20px; border-radius:12px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-weight:600; font-size:0.95rem; box-shadow:0 4px 12px rgba(21,128,61,0.08);">
        <i class="fas fa-check-circle fa-lg"></i> Albümünüz onaylandı ve stüdyoya iletildi. Süreci portaldan takip edebilirsiniz.
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['revize']) && $_GET['revize'] == 'basarili'): ?>
    <div class="flash warning" style="background:#fffbeb; border:1px solid #fcd34d; color:#b45309; padding:16px 20px; border-radius:12px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-weight:600; font-size:0.95rem; box-shadow:0 4px 12px rgba(180,83,9,0.08);">
        <i class="fas fa-exclamation-circle fa-lg"></i> Revize talebiniz stüdyoya iletildi. Süreci portaldan takip edebilirsiniz.
    </div>
    <?php endif; ?>

    <!-- ── KARŞILAMA ── -->
    <div class="welcome-bar">
        <div>
            <p class="welcome-title">Merhaba, <span><?= $isim; ?>!</span> 👋</p>

            <div class="couple-badges">
                <?php if ($gelin): ?><span class="couple-badge">👰 <?= $gelin; ?></span><?php endif; ?>
                <?php if ($gelin && $damat): ?><span class="couple-badge">❤️</span><?php endif; ?>
                <?php if ($damat): ?><span class="couple-badge">🤵 <?= $damat; ?></span><?php endif; ?>
                <?php if ($sozlesme): ?>
                <button class="couple-badge" style="cursor:pointer;border:none;" data-bs-toggle="modal" data-bs-target="#sozlesmeModal">
                    📄 Sözleşme <?= $pd['kvkk_onay'] == 1 ? '(İmzalı)' : '' ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="status-pill">
            <span class="label">Güncel Aşama</span>
            <span class="value"><i class="fas <?= $wl[2]; ?> me-1"></i><?= $wl[0]; ?></span>
        </div>
    </div>

    <!-- ── İŞ AKIŞI ÇUBUĞU ── -->
    <div class="workflow-bar">
        <div class="workflow-steps">
            <?php
            $steps = [0=>'Hazırlık', 1=>'Seçim', 2=>'Seçim OK', 3=>'Tasarım', 4=>'Onay', 5=>'Baskı', 6=>'Teslim'];
            $display_wf = $wf == 7 ? 4 : $wf; // Revize = onay aşamasında göster
            foreach ($steps as $si => $sl):
                $cls = $si < $display_wf ? 'done' : ($si == $display_wf ? 'active' : '');
            ?>
            <div class="wf-step <?= $cls; ?>">
                <div class="wf-dot">
                    <?php if ($si < $display_wf): ?>
                        <i class="fas fa-check" style="font-size:0.7rem;"></i>
                    <?php else: ?>
                        <?= $si + 1; ?>
                    <?php endif; ?>
                </div>
                <span class="wf-label"><?= $sl; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── STAT KARTLAR ── -->
    <div class="stat-row">

        <!-- FOTOĞRAF SEÇİM KARTI -->
        <div class="stat-card green">
            <div class="stat-icon green"><i class="fas fa-camera"></i></div>
            <div class="stat-val"><?= $secim_adet; ?><?= $max_secim > 0 ? '<span style="font-size:1rem;color:var(--muted);"> / ' . $max_secim . '</span>' : '<span style="font-size:0.9rem;color:var(--muted);"> ∞</span>'; ?></div>
            <div class="stat-lbl">Seçilen Fotoğraf</div>

            <?php if ($max_secim > 0): $p = min(100, ($secim_adet/$max_secim)*100); ?>
            <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $p; ?>%"></div></div>
            <?php endif; ?>

            <?php if ($wf == 0): ?>
            <div style="margin-top:6px;font-size:0.75rem;color:var(--muted);"><i class="fas fa-hourglass-half me-1"></i>Fotoğraflar Hazırlanıyor</div>
            <?php elseif (in_array($wf, [2,3,4,5,6,7])): ?>
            <div style="margin-top:6px;font-size:0.75rem;color:var(--muted);"><i class="fas fa-lock me-1"></i>Seçim tamamlandı</div>
            <?php else: ?>
            <a href="secim.php" style="display:block;margin-top:6px;font-size:0.8rem;font-weight:600;color:#48bb78;text-decoration:none;">Seçime git →</a>
            <?php endif; ?>

            <!-- ZIP İNDİRME BÖLÜMÜ -->
            <?php if ($pd['portal_indirme_izni'] == 1 && count($fotograflar) > 0): ?>
            <div style="margin-top: 15px; border-top: 1px solid #e2e8f0; padding-top: 10px;">
                <span style="font-size:0.75rem; color:var(--text); font-weight:600; display:block; margin-bottom:8px;"><i class="fas fa-download me-1" style="color:#3182ce;"></i> Tümünü İndir (ZIP)</span>
                <div style="display:flex; flex-wrap:wrap; gap:5px;">
                    <?php 
                    $part_sayisi = ceil(count($fotograflar) / 100); 
                    for($i=1; $i<=$part_sayisi; $i++): 
                    ?>
                        <a href="?download_zip=1&tur=albumler&part=<?= $i ?>" class="btn-download-part">Part <?= $i ?></a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- VİDEO VE KLİP KARTI -->
        <div class="stat-card blue">
            <div class="stat-icon blue" style="margin-bottom: 8px;"><i class="fas fa-video"></i></div>
            <div class="stat-val" style="font-size:1.3rem;"><?= count($videolar); ?> <span style="font-size:0.9rem;color:var(--muted);">Klip Teslim Edildi</span></div>
            
            <?php if (count($videolar) > 0): ?>
                <div style="margin-top:12px; max-height: 90px; overflow-y: auto; padding-right:4px;">
                    <?php 
                    $vi=1; 
                    foreach($videolar as $v): 
                        $adi = $gelin . ($gelin && $damat ? ' & ' : '') . $damat . ' Klibi' . (count($videolar) > 1 ? ' ' . $vi : '');
                        $url = htmlspecialchars($url_videolar . $v['name'], ENT_QUOTES);
                        $dl = urlencode('uploads/videolar/' . $firma_id . '/' . $m_id . '/' . $v['name']);
                        $dl_name = urlencode(str_replace(' ','_',$adi).'.mp4');
                    ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; background:#f7fafc; padding:6px 10px; border-radius:8px; margin-bottom:6px; border:1px solid #edf2f7;">
                        <span style="font-size:0.8rem; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:60%;" title="<?= $adi ?>"><?= $adi ?></span>
                        <div style="display:flex; gap:6px;">
                            <button onclick="oynatVideo('<?= $url ?>')" class="btn btn-sm btn-light" style="color:#4299e1; padding:2px 6px; border-radius:4px; border:1px solid #e2e8f0; font-size:0.75rem;" title="İzle"><i class="fas fa-play"></i></button>
                            <?php if ($pd['portal_indirme_izni'] == 1): ?>
                                <a href="?download_file=<?= $dl ?>&custom_name=<?= $dl_name ?>" onclick="event.stopPropagation();" class="btn btn-sm btn-light" style="color:#48bb78; padding:2px 6px; border-radius:4px; border:1px solid #e2e8f0; font-size:0.75rem;" title="İndir"><i class="fas fa-download"></i></a>
                            <?php else: ?>
                                <button class="btn btn-sm btn-light" style="color:#a0aec0; padding:2px 6px; border-radius:4px; border:1px solid #e2e8f0; font-size:0.75rem;" disabled title="İndirme Kapalı"><i class="fas fa-lock"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php $vi++; endforeach; ?>
                </div>
            <?php else: ?>
                <div style="margin-top:16px; font-size:0.75rem; color:var(--muted);">
                    <i class="fas fa-hourglass-half me-1"></i> Henüz klip yüklenmedi
                </div>
                <!-- MÜZİK LİNKİ FORMU (Videolar Boşsa Çıkar) -->
                <div style="margin-top:15px; background:rgba(66, 153, 225, 0.1); padding:10px; border-radius:8px; border:1px solid rgba(66, 153, 225, 0.2);">
                    <strong style="color:#2b6cb0; font-size:0.8rem; display:block; margin-bottom:5px;"><i class="fab fa-spotify"></i> Klip Müziği Seçin</strong>
                    <form method="POST" style="display:flex; gap:5px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="url" name="muzik_linki" placeholder="Youtube/Spotify linki..." required style="flex:1; border:1px solid #cbd5e0; border-radius:4px; padding:4px 8px; font-size:0.75rem; outline:none;">
                        <button type="submit" name="muzik_kaydet" style="background:#2b6cb0; color:#fff; border:none; border-radius:4px; padding:4px 10px; font-size:0.75rem; font-weight:bold; cursor:pointer;">Gönder</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ALBÜM TASARIMI ONAY KARTI -->
    <?php if (!empty($albumler)): ?>
    <div class="stat-card" style="margin-bottom: 28px; padding: 20px 24px; display: flex; align-items: center; gap: 20px; border: 1px solid <?= $wf == 4 ? '#86efac' : 'transparent'; ?>;">
        <div class="stat-icon <?= $wf == 4 ? 'green' : 'gold' ?>" style="margin-bottom: 0; flex-shrink: 0; width: 56px; height: 56px;">
            <i class="fas <?= $wf == 4 ? 'fa-star' : 'fa-book-open' ?>"></i>
        </div>
        <div style="flex: 1;">
            <h4 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text);">
                Albüm Tasarımınız <?= $wf == 4 ? '<span style="background:#fefcbf; color:#744210; font-size:0.7rem; padding:2px 6px; border-radius:4px; margin-left:8px; border:1px solid #f6e05e;">Onay Bekliyor</span>' : '' ?>
            </h4>
            <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: var(--muted);"><?= count($albumler); ?> Sayfadan oluşuyor</p>
            
            <!-- ALBÜM ZIP İNDİRME -->
            <?php if ($pd['portal_indirme_izni'] == 1): ?>
                <div style="display:flex; flex-wrap:wrap; gap:5px; margin-top:8px;">
                    <span style="font-size:0.75rem; color:var(--text); margin-right:5px; align-self:center;"><i class="fas fa-download me-1"></i></span>
                    <?php 
                    $a_part_sayisi = ceil(count($albumler) / 100); 
                    for($i=1; $i<=$a_part_sayisi; $i++): 
                    ?>
                        <a href="?download_zip=1&tur=haziralbumler&part=<?= $i ?>" class="btn-download-part" style="padding:4px 8px; font-size:0.7rem;">Part <?= $i ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($wf == 4): ?>
                <button onclick="albumAc()" class="btn-onay" style="padding: 10px 24px; font-size: 0.9rem; white-space: nowrap;">
                    <i class="fas fa-search me-2"></i>İncele & İşlem Yap
                </button>
            <?php else: ?>
                <button onclick="albumAc()" style="background: #f7fafc; color: #48bb78; border: 1px solid #e2e8f0; border-radius: 50px; padding: 10px 24px; font-weight: 600; cursor: pointer; white-space: nowrap;">
                    <i class="fas fa-eye me-2"></i>Görüntüle
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── ALT 2 KOLON (Randevu, Hesap Özeti, Destek) ── -->
    <div class="two-col">

        <!-- SOL -->
        <div>
            <!-- Randevular -->
            <div class="section-card">
                <div class="section-card-header"><i class="fas fa-calendar-alt"></i>Planlanan Çekim & Randevular</div>
                <div class="section-card-body" style="padding-top:8px;padding-bottom:8px;">
                <?php if (!empty($cekimler)): foreach ($cekimler as $c): ?>
                <div class="randevu-item">
                    <div class="randevu-date">
                        <div class="gun"><?= date('d', strtotime($c['tarih'])); ?></div>
                        <div class="ay"><?= date('M', strtotime($c['tarih'])); ?></div>
                    </div>
                    <div class="randevu-info">
                        <div class="baslik"><?= htmlspecialchars($c['baslik']); ?></div>
                        <div class="aciklama">
                            <i class="fas fa-calendar-day me-1" style="color:var(--gold);"></i><?= date('d.m.Y', strtotime($c['tarih'])); ?>
                            <?php if (!empty($c['notlar'])): ?> — <?= htmlspecialchars($c['notlar']); ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div style="text-align:center;padding:28px;color:var(--muted);">
                    <i class="fas fa-calendar-check fa-2x mb-2" style="opacity:.2;display:block;"></i>
                    Planlanmış çekim bulunmuyor.
                </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- Hesap Özeti -->
            <div class="section-card">
                <div class="section-card-header"><i class="fas fa-receipt"></i>Hesap Özeti</div>
                <table class="hesap-table">
                    <thead><tr><th>Tarih</th><th>İşlem</th><th style="text-align:right;">Tutar</th></tr></thead>
                    <tbody>
                    <?php if (!empty($hareketler)): foreach ($hareketler as $h): ?>
                    <tr>
                        <td>
                            <?php $t = !empty($h['vade_tarihi']) ? $h['vade_tarihi'] : $h['islem_tarihi']; ?>
                            <span style="font-weight:600;color:var(--text);"><?= date('d.m.Y', strtotime($t)); ?></span><br>
                            <small style="color:var(--muted);font-size:0.7rem;"><?= !empty($h['vade_tarihi']) ? 'Planlanan' : 'Kayıt'; ?></small>
                        </td>
                        <td>
                            <span style="font-weight:600;color:var(--text);display:block;"><?= htmlspecialchars($h['urun_aciklama']); ?></span>
                            <?php if (!empty($h['notlar'])): ?><small style="color:var(--muted);"><?= htmlspecialchars($h['notlar']); ?></small><?php endif; ?>
                        </td>
                        <td class="<?= $h['islem_turu']=='satis' ? 'tutar-borc' : 'tutar-odeme'; ?>" style="text-align:right;">
                            <?= $h['islem_turu']=='satis' ? '−' : '+'; ?><?= number_format((float)$h['toplam_tutar'],2); ?> ₺
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="3" style="text-align:center;padding:28px;color:var(--muted);">Kayıtlı hareket yok.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- HESAP FOOTER VE KREDİ KARTI BUTONU -->
                <div class="hesap-footer" style="display:flex; flex-direction:column; gap:10px;">
                    <div>
                        <span style="color:var(--muted);font-size:0.8rem;">Toplam Ödenen: <strong style="color:#38a169;"><?= number_format((float)($fin['odenen']??0),2); ?> ₺</strong></span>
                        <br><span class="kalan-borc">Kalan: <?= number_format((float)$kalan,2); ?> ₺</span>
                    </div>
                    <?php if($kalan > 0): ?>
                    <a href="odeme.php?tur=musteri_borc&id=<?= $m_id ?>&ay=1" style="display:inline-block; text-align:center; background:#ffc107; color:#000; padding:12px; border-radius:8px; font-weight:bold; text-decoration:none; box-shadow:0 2px 5px rgba(0,0,0,0.1); margin-top:5px; transition:0.2s;">
                        <i class="fas fa-credit-card me-1"></i> Kredi Kartı İle Öde
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- SAĞ: İletişim -->
        <div>
            <div class="iletisim-card">
                <h5><i class="fas fa-headset me-2"></i>Müşteri Destek</h5>
                <div class="contact-row">
                    <i class="fas fa-phone-alt"></i>
                    <div>
                        <span class="c-label">Stüdyo Telefon</span>
                        <span class="c-val"><?= htmlspecialchars(isset($pd['firma_tel']) ? $pd['firma_tel'] : '—'); ?></span>
                    </div>
                </div>
                <div class="contact-row">
                    <i class="fas fa-envelope"></i>
                    <div>
                        <span class="c-label">E-Posta</span>
                        <span class="c-val"><?= htmlspecialchars(isset($pd['firma_mail']) ? $pd['firma_mail'] : '—'); ?></span>
                    </div>
                </div>
                <?php
                $tel = isset($pd['firma_tel']) ? $pd['firma_tel'] : '';
                $wp = preg_replace('/[^0-9]/', '', $tel);
                if (substr($wp,0,1) == "0") { $wp = substr($wp,1); }
                if (substr($wp,0,2) != "90") { $wp = "90".$wp; }
                ?>

            <a href="https://wa.me/<?= $wp; ?>" target="_blank" class="btn-wp">
             <i class="fab fa-whatsapp me-2"></i> WhatsApp ile Yaz
            </a>
            </div>
            <?php if (!empty($pd['firma_adres'])): ?>
            <div class="adres-card">
                <i class="fas fa-map-marker-alt"></i>
                <span><?= htmlspecialchars($pd['firma_adres']); ?></span>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /.two-col -->
</div><!-- /.dash-wrap -->

<?php require_once 'modallar.php'; ?>
<script src="assets/js/dashboard.js"></script>

<?php require_once 'musteri_footer.php'; ?>