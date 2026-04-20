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
        if ($f === '.' || $f === '..') continue;
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
                                        f.ek_sure_gun, f.anlik_kesinti, f.ek_trafik_limiti, p.depolama_limiti
                                 FROM musteriler m
                                 JOIN firmalar f ON m.firma_id = f.id
                                 LEFT JOIN paketler p ON f.paket_id = p.id
                                 WHERE m.id = ?");
            $q->execute([$mid]);
            $fb = $q->fetch(PDO::FETCH_ASSOC);
            if ($fb) {
                $fid   = $fb['id'];
                $limit = (((int)$fb['depolama_limiti'] * 10) + (int)$fb['ek_trafik_limiti']) * 1048576;
                $yeni  = $fb['aylik_trafik_kullanimi'] + $fsize;
                if ($limit > 0 && $yeni > $limit) {
                    $asim = $fb['trafik_asim_tarihi'];
                    $esur = (int)$fb['ek_sure_gun'];
                    if ($fb['anlik_kesinti'] == 1 || ($asim && time() > strtotime("$asim +{$esur} days"))) {
                        die('<h2>Hizmet Kısıtlaması</h2><p>Aylık indirme limitiniz doldu.</p>');
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

$stmt = $db->prepare("SELECT m.*, f.id AS firma_id, f.firma_adi,
                             f.telefon AS firma_tel, f.email AS firma_mail, f.adres AS firma_adres
                      FROM musteriler m
                      JOIN firmalar f ON m.firma_id = f.id
                      WHERE m.id = ? LIMIT 1");
$stmt->execute([$m_id]);
$pd = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pd) die('Oturum hatası.');

$firma_id = $pd['firma_id']; // String! Örn: IBIR-WED-PRO

// Giriş logu
try {
    $db->prepare("INSERT INTO musteriportal (firma_id, musteri_id, last_login) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE last_login=NOW()")->execute([$firma_id, $m_id]);
} catch (Exception $e) {}

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
// POST
// ═══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula();
    if (isset($_POST['musteri_album_onay'])) {
        $kid = !empty($_POST['secilen_kapak']) ? (int)$_POST['secilen_kapak'] : null;
        $db->prepare("UPDATE musteriler SET secilen_kapak_id=?, kapak_onaylayan='Müşteri', workflow_status=5 WHERE id=? AND firma_id=?")->execute([$kid, $m_id, $firma_id]);
        
        // Fonksiyon yoksa kod çökmesin diye kontrol ekledik
        if (function_exists('sistem_log_kaydet')) {
            sistem_log_kaydet('Albüm Onaylandı', 'Müşteri albümü onayladı.', $firma_id, 0);
        }
        
        header('Location: dashboard.php?onay=basarili'); 
        exit;
    }
    if (isset($_POST['musteri_revize'])) {
        $not = trim(isset($_POST['revize_notu']) ? $_POST['revize_notu'] : '');
        if ($not) {
            $db->prepare("INSERT INTO revizeler (musteri_id, firma_id, notlar, durum, created_at) VALUES (?,?,?,0,NOW())")->execute([$m_id, $firma_id, $not]);
        }
        $db->prepare("UPDATE musteriler SET workflow_status=7 WHERE id=? AND firma_id=?")->execute([$m_id, $firma_id]);
        
        if (function_exists('sistem_log_kaydet')) {
            sistem_log_kaydet('Revize Talebi', "Revize: $not", $firma_id, $m_id);
        }
        
        header('Location: dashboard.php?revize=basarili'); 
        exit;
    }
}

// ═══════════════════════════════════════════════
// VERİ
// ═══════════════════════════════════════════════

// Kapaklar
$stmt = $db->prepare("SELECT * FROM kapak_modelleri WHERE firma_id=? AND durum=1");
$stmt->execute([$firma_id]);
$kapaklar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Medya yolları — firma_id string olduğu için (int) KULLANMA
$upload_base = __DIR__ . '/../ibircari.xyz/uploads/';
$dir_albumler = $upload_base . "haziralbumler/$firma_id/$m_id/";
$dir_videolar = $upload_base . "videolar/$firma_id/$m_id/";
$web_base     = 'https://ibircari.xyz/uploads/';
$url_albumler = $web_base . "haziralbumler/$firma_id/$m_id/";
$url_videolar = $web_base . "videolar/$firma_id/$m_id/";

$albumler = getMediaFiles($dir_albumler, ['jpg','jpeg','png','webp']);
$videolar = getMediaFiles($dir_videolar, ['mp4','mov','webm','avi','mkv']);

// Finans
$stmt = $db->prepare("SELECT SUM(CASE WHEN islem_turu='satis' THEN toplam_tutar ELSE 0 END) AS borc,
                             SUM(CASE WHEN islem_turu='tahsilat' THEN toplam_tutar ELSE 0 END) AS odenen
                      FROM hareketler WHERE musteri_id=?");
$stmt->execute([$m_id]);
$fin = $stmt->fetch(PDO::FETCH_ASSOC);
$kalan = ($fin['borc'] ?? 0) - ($fin['odenen'] ?? 0);

// Hareketler
$stmt = $db->prepare("SELECT * FROM hareketler WHERE musteri_id=? ORDER BY islem_tarihi DESC");
$stmt->execute([$m_id]);
$hareketler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Çekimler
$stmt = $db->prepare("SELECT urun_aciklama AS baslik, vade_tarihi AS tarih, notlar FROM hareketler WHERE musteri_id=? AND islem_turu='satis' AND vade_tarihi >= CURDATE() ORDER BY vade_tarihi ASC");
$stmt->execute([$m_id]);
$cekimler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sözleşme
$sozlesme = false;
try {
    $stmt = $db->prepare("SELECT sozlesme_maddeleri, sozlesme_no FROM sozlesmeler WHERE musteri_id=? LIMIT 1");
    $stmt->execute([$m_id]);
    $sozlesme = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Seçim sayısı
$secim_adet = 0;
$sc = isset($db_secim) ? $db_secim : $db;
try {
    $ss = $sc->prepare("SELECT COUNT(*) FROM user_selections WHERE cari_musteri_id=? AND (selection_type=1 OR selection_type=3)");
    $ss->execute([$m_id]);
    $secim_adet = (int)$ss->fetchColumn();
} catch (Exception $e) {}

$max_secim  = (int)(isset($pd['max_secim']) ? $pd['max_secim'] : 0);
$isim       = htmlspecialchars(explode(' ', isset($pd['ad_soyad']) ? $pd['ad_soyad'] : '')[0]);
$gelin      = htmlspecialchars(isset($pd['gelin_ad']) ? $pd['gelin_ad'] : '');
$damat      = htmlspecialchars(isset($pd['damat_ad']) ? $pd['damat_ad'] : '');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="assets/css/portal_lively.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>

<div class="dash-wrap">

<?php if (isset($_GET['onay']) && $_GET['onay'] == 'basarili'): ?>
<div class="flash success" style="background:#f0fdf4; border:1px solid #86efac; color:#15803d; padding:16px 20px; border-radius:12px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-weight:600; font-size:0.95rem; box-shadow:0 4px 12px rgba(21,128,61,0.08);">
    <i class="fas fa-check-circle fa-lg"></i>
    Albümünüz onaylandı ve stüdyoya iletildi. Süreci portaldan takip edebilirsiniz.
</div>
<?php endif; ?>

<?php if (isset($_GET['revize']) && $_GET['revize'] == 'basarili'): ?>
<div class="flash warning" style="background:#fffbeb; border:1px solid #fcd34d; color:#b45309; padding:16px 20px; border-radius:12px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-weight:600; font-size:0.95rem; box-shadow:0 4px 12px rgba(180,83,9,0.08);">
    <i class="fas fa-exclamation-circle fa-lg"></i>
    Revize talebiniz stüdyoya iletildi. Süreci portaldan takip edebilirsiniz.
</div>
<?php endif; ?>

<!-- ── KARŞILAMA ── -->
<div class="welcome-bar">
    <div>
        <p class="welcome-title">Merhaba, <span><?= $isim; ?>!</span> 👋</p>

        <div class="couple-badges">
            
            <?php if ($gelin): ?>
            <span class="couple-badge">👰 <?= $gelin; ?></span>
            <?php endif; ?>

            <?php if ($gelin && $damat): ?>
            <span class="couple-badge">❤️</span>
            <?php endif; ?>

            <?php if ($damat): ?>
            <span class="couple-badge">🤵 <?= $damat; ?></span>
            <?php endif; ?>

            <?php if ($sozlesme): ?>
            <button class="couple-badge" style="cursor:pointer;border:none;" data-bs-toggle="modal" data-bs-target="#sozlesmeModal">
                📄 Sözleşme
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
    </div>

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
                        <a href="?download_file=<?= $dl ?>&custom_name=<?= $dl_name ?>" onclick="event.stopPropagation();" class="btn btn-sm btn-light" style="color:#48bb78; padding:2px 6px; border-radius:4px; border:1px solid #e2e8f0; font-size:0.75rem;" title="İndir"><i class="fas fa-download"></i></a>
                    </div>
                </div>
                <?php $vi++; endforeach; ?>
            </div>
        <?php else: ?>
            <div style="margin-top:16px; font-size:0.75rem; color:var(--muted);">
                <i class="fas fa-hourglass-half me-1"></i> Henüz yüklenmedi
            </div>
        <?php endif; ?>
    </div>

</div>

<?php if (!empty($albumler)): ?>
<div class="stat-card" style="margin-bottom: 28px; padding: 20px 24px; display: flex; align-items: center; gap: 20px; border: 1px solid <?= $wf == 4 ? '#86efac' : 'transparent'; ?>;">
    <div class="stat-icon <?= $wf == 4 ? 'green' : 'gold' ?>" style="margin-bottom: 0; flex-shrink: 0; width: 56px; height: 56px;">
        <i class="fas <?= $wf == 4 ? 'fa-star' : 'fa-book-open' ?>"></i>
    </div>
    <div style="flex: 1;">
        <h4 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text);">
            Albüm Tasarımınız <?= $wf == 4 ? '<span class="badge bg-warning text-dark ms-2" style="font-size:0.7rem;">Onay Bekliyor</span>' : '' ?>
        </h4>
        <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: var(--muted);"><?= count($albumler); ?> Sayfadan oluşuyor</p>
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

<!-- ── ALT 2 KOLON ── -->
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
            <div class="hesap-footer">
                <span style="color:var(--muted);font-size:0.8rem;">Toplam Ödenen: <strong style="color:#38a169;"><?= number_format((float)($fin['odenen']??0),2); ?> ₺</strong></span>
                <br><span class="kalan-borc">Kalan: <?= number_format((float)$kalan,2); ?> ₺</span>
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

        // sadece rakamları al
             $wp = preg_replace('/[^0-9]/', '', $tel);

        // başında 0 varsa kaldır
        if (substr($wp,0,1) == "0") {
            $wp = substr($wp,1);
            }

        // başında 90 yoksa ekle
        if (substr($wp,0,2) != "90") {
             $wp = "90".$wp;
            }
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

</div></div><?php require_once 'modallar.php'; ?>
<script src="assets/js/dashboard.js"></script>

<?php require_once 'musteri_footer.php'; ?>