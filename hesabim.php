<?php
session_start();
require 'baglanti.php';

// --- FONKSİYONLARI DAHİL ET ---
$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

// 1. GÜVENLİK KONTROLÜ
if (file_exists('partials/security_check.php')) {
    require_once 'partials/security_check.php';
} else {
    if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
        header("Location: login.php");
        exit;
    }
}

// --- DEĞİŞKENLERİ HAZIRLA ---
$user_id = $_SESSION['kullanici_id'];
$firma_id = $_SESSION['firma_id'];
$my_role = $_SESSION['rol']; 

// --- GARANTİLİ YEREL LOG FONKSİYONU ---
function logKaydetLocal($db, $islem, $detay, $f_id, $k_id) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';
        $stmt = $db->prepare("INSERT INTO sistem_loglari (firma_id, kullanici_id, islem, detay, ip_adresi) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$f_id, $k_id, $islem, $detay, $ip]);
    } catch (Exception $e) {}
}

// --- YARDIMCI FONKSİYON: ŞİFRE DOĞRULAMA ---
function sifreDogruMu($db, $uid, $pass) {
    $sorgu = $db->prepare("SELECT sifre FROM yoneticiler WHERE id = ?");
    $sorgu->execute([$uid]);
    $kayitli = $sorgu->fetchColumn();
    return (password_verify($pass, $kayitli) || md5($pass) === $kayitli);
}

// Veritabanından Taze Veri Çek
$firma = $db->query("
    SELECT f.*, p.paket_adi, p.musteri_limiti, p.depolama_limiti, p.kullanici_limiti, p.sms_limiti 
    FROM firmalar f 
    LEFT JOIN paketler p ON f.paket_id = p.id 
    WHERE f.id='$firma_id'
")->fetch(PDO::FETCH_ASSOC);

$user = $db->query("SELECT * FROM yoneticiler WHERE id='$user_id'")->fetch(PDO::FETCH_ASSOC);

// Navbar'da kullanılan değişkenleri tanımla
$firma_adi = $firma['firma_adi'] ?? 'Firma Adı Yok';
$kullanici_adi = $user['kullanici_adi'] ?? 'Kullanıcı';
$rol = $user['rol'] ?? 'personel';
$page_title = "Hesap ve Sistem Ayarları";

// Alt Kullanıcılar
$subUsers = $db->query("SELECT * FROM yoneticiler WHERE firma_id='$firma_id' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// İstatistikler
$aktifMusteri = $db->query("SELECT COUNT(*) FROM musteriler WHERE firma_id = '$firma_id' AND silindi = 0")->fetchColumn() ?: 0;
$kullaniciSayisi = count($subUsers);

// --- DEPOLAMA (STORAGE) HESAPLAMA MANTIĞI ---
function getDirectorySize($path) {
    $bytestotal = 0;
    $path = realpath($path);
    if($path !== false && $path != '' && file_exists($path)){
        try {
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object){
                $bytestotal += $object->getSize();
            }
        } catch(Exception $e) {}
    }
    return $bytestotal;
}

$base_upload_dir = __DIR__ . '/uploads/';
$folders_to_check = ['albumler', 'haziralbumler', 'videoklipler', 'videolar'];
$kullanilan_bayt = 0;

foreach ($folders_to_check as $folder) {
    $firma_folder = $base_upload_dir . $folder . '/' . $firma_id;
    if (is_dir($firma_folder)) {
        $kullanilan_bayt += getDirectorySize($firma_folder);
    }
}

$kullanilan_mb = $kullanilan_bayt / 1048576;
$gosterim_boyut = "";
if ($kullanilan_mb >= 1024) {
    $gosterim_boyut = round($kullanilan_mb / 1024, 2) . ' GB';
} else {
    $gosterim_boyut = round($kullanilan_mb, 2) . ' MB';
}

$mesaj = "";
$mesajTuru = "";

// ============================================================================
// İŞLEMLER (POST & GET) MİMARİSİ
// ============================================================================

// 1. EKSİK FİRMA BİLGİLERİNİ GİRME VE ONAYA GÖNDERME
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_firma'])) {
    if ($my_role == 'admin') {
        if($firma['bilgi_onay_durumu'] > 0) {
            $mesaj = "Firma bilgileriniz kilitlenmiştir. Değişiklik yapmak için sistem yöneticisine başvurunuz.";
            $mesajTuru = "warning";
        } else {
            $dogrulama = $_POST['dogrulama_sifresi'];
            if (sifreDogruMu($db, $user_id, $dogrulama)) {
                $db->prepare("UPDATE firmalar SET sektor=?, adres=?, il=?, ilce=?, vergi_dairesi=?, vergi_no=?, bilgi_onay_durumu=1 WHERE id=?")->execute([$_POST['sektor'], $_POST['adres'], $_POST['il'], $_POST['ilce'], $_POST['vergi_dairesi'], $_POST['vergi_no'], $firma_id]);
                header("Location: hesabim.php?tab=firma&msg=firma_onay_ok"); exit;
            } else {
                $mesaj = "Güvenlik şifresi hatalı! İşlem yapılamadı."; $mesajTuru = "danger";
            }
        }
    }
}

// 2. TEKLİF & SÖZLEŞME AYARLARINI GÜNCELLE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_teklif_sozlesme'])) {
    if ($my_role == 'admin') {
        $db->prepare("UPDATE firmalar SET teklif_sartlari=?, teklif_alt_bilgi=?, sozlesme_maddeleri=? WHERE id=?")->execute([$_POST['teklif_sartlari'], $_POST['teklif_alt_bilgi'], $_POST['sozlesme_maddeleri'], $firma_id]);
        header("Location: hesabim.php?tab=teklif_sozlesme&msg=sablonlar_guncellendi"); exit;
    }
}

// 3. ŞİFRE DEĞİŞTİR
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_sifre'])) {
    $eski = $_POST['eski_sifre'];
    $yeni = $_POST['yeni_sifre'];
    $yeni_tekrar = $_POST['yeni_sifre_tekrar'];

    if (sifreDogruMu($db, $user_id, $eski)) {
        if ($yeni === $yeni_tekrar && strlen($yeni) >= 8) {
            $yeni_hash = password_hash($yeni, PASSWORD_DEFAULT);
            $db->prepare("UPDATE yoneticiler SET sifre=? WHERE id=?")->execute([$yeni_hash, $user_id]);
            header("Location: hesabim.php?tab=sifre&msg=sifre_ok"); exit;
        } else {
            $mesaj = "Şifreler uyuşmuyor veya 8 karakterden kısa."; $mesajTuru = "danger";
        }
    } else {
        $mesaj = "Mevcut şifrenizi yanlış girdiniz."; $mesajTuru = "danger";
    }
}

// 4. YENİ ALT KULLANICI EKLE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user']) && $my_role == 'admin') {
    $k_limit = (int)$firma['kullanici_limiti'];
    if ($k_limit > 0 && $kullaniciSayisi >= $k_limit) {
        $mesaj = "Paketinizin kullanıcı ekleme sınırına ulaştınız!"; $mesajTuru = "warning";
    } else {
        $u_adsoyad = trim($_POST['u_adsoyad']);
        $u_email = strtolower(trim($_POST['u_email']));
        $u_kadi = explode('@', $u_email)[0] . rand(10,99); 
        $u_sifre = trim($_POST['u_sifre']);
        $u_rol = $_POST['u_rol'];

        $chk = $db->prepare("SELECT id FROM yoneticiler WHERE email=?"); $chk->execute([$u_email]);
        if ($chk->rowCount() > 0) {
            $mesaj = "Bu e-posta adresi zaten kullanılıyor."; $mesajTuru = "danger";
        } else {
            $u_hash = password_hash($u_sifre, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO yoneticiler (firma_id, kullanici_adi, email, sifre, ad_soyad, rol, email_onayli) VALUES (?, ?, ?, ?, ?, ?, 1)")->execute([$firma_id, $u_kadi, $u_email, $u_hash, $u_adsoyad, $u_rol]);
            header("Location: hesabim.php?tab=kullanicilar&msg=user_eklendi"); exit;
        }
    }
}

// 5. KULLANICI SİL
if (isset($_GET['sil_user']) && $my_role == 'admin') {
    $sil_id = (int)$_GET['sil_user'];
    if ($sil_id != $user_id) {
        $db->prepare("DELETE FROM yoneticiler WHERE id=? AND firma_id=?")->execute([$sil_id, $firma_id]);
        header("Location: hesabim.php?tab=kullanicilar&msg=user_silindi"); exit;
    }
}

// ----------------------------------------------------------------------------
// CRM VE SİSTEM AYARLARI KISMI
// ----------------------------------------------------------------------------

// 6. VADE FARKI VE TAKSİTLER
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['vade_farki_kaydet']) && $my_role == 'admin') {
    $taksitler = $_POST['taksit_sayisi'] ?? [];
    $oranlar = $_POST['vade_orani'] ?? [];
    $vade_data = [];
    foreach($taksitler as $index => $taksit) {
        if(!empty($taksit) && isset($oranlar[$index])) {
            $vade_data[$taksit] = (float)$oranlar[$index];
        }
    }
    $vade_json = json_encode($vade_data, JSON_UNESCAPED_UNICODE);
    $db->prepare("UPDATE firmalar SET vade_farki_oranlari = ? WHERE id = ?")->execute([$vade_json, $firma_id]);
    header("Location: hesabim.php?tab=vade&msg=vade_ok"); exit;
}

// 7. İLETİŞİM ŞABLONLARI
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sablon_ekle']) && $my_role == 'admin') {
    $baslik = trim($_POST['baslik']);
    $tur = $_POST['tur'];
    $icerik = trim($_POST['icerik']);
    $durum = ($tur == 'sms') ? 'onay_bekliyor' : 'onaylandi';
    $db->prepare("INSERT INTO iletisim_sablonlari (firma_id, baslik, tur, icerik, durum) VALUES (?, ?, ?, ?, ?)")->execute([$firma_id, $baslik, $tur, $icerik, $durum]);
    header("Location: hesabim.php?tab=sablonlar&msg=sablon_eklendi"); exit;
}

if (isset($_GET['del_sablon']) && $my_role == 'admin') {
    $db->prepare("DELETE FROM iletisim_sablonlari WHERE id = ? AND firma_id = ?")->execute([(int)$_GET['del_sablon'], $firma_id]);
    header("Location: hesabim.php?tab=sablonlar&msg=sablon_silindi"); exit;
}

// 8. MÜŞTERİ ETİKETLERİ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['etiket_ekle']) && $my_role == 'admin') {
    $etiket_adi = trim($_POST['etiket_adi']);
    $renk = $_POST['renk'];
    if(!empty($etiket_adi)) {
        $db->prepare("INSERT INTO musteri_etiketleri (firma_id, etiket_adi, renk) VALUES (?, ?, ?)")->execute([$firma_id, $etiket_adi, $renk]);
    }
    header("Location: hesabim.php?tab=etiketler&msg=etiket_eklendi"); exit;
}

if (isset($_GET['del_etiket']) && $my_role == 'admin') {
    $e_id = (int)$_GET['del_etiket'];
    $db->prepare("DELETE FROM musteri_etiket_baglanti WHERE etiket_id = ?")->execute([$e_id]);
    $db->prepare("DELETE FROM musteri_etiketleri WHERE id = ? AND firma_id = ?")->execute([$e_id, $firma_id]);
    header("Location: hesabim.php?tab=etiketler&msg=etiket_silindi"); exit;
}

// --- GET MESAJ YAKALAYICI ---
if(isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if($m=='firma_onay_ok') { $mesaj = "Eksik bilgileriniz kaydedildi ve onaya gönderildi."; $mesajTuru = "success"; }
    elseif($m=='sablonlar_guncellendi') { $mesaj = "Teklif ve sözleşme şablonları güncellendi."; $mesajTuru = "success"; }
    elseif($m=='sifre_ok') { $mesaj = "Şifreniz başarıyla değiştirildi."; $mesajTuru = "success"; }
    elseif($m=='user_eklendi') { $mesaj = "Yeni personel eklendi."; $mesajTuru = "success"; }
    elseif($m=='user_silindi') { $mesaj = "Personel hesabı silindi."; $mesajTuru = "warning"; }
    elseif($m=='vade_ok') { $mesaj = "Vade farkı ve taksit oranları güncellendi."; $mesajTuru = "success"; }
    elseif($m=='sablon_eklendi') { $mesaj = "İletişim şablonu kaydedildi."; $mesajTuru = "success"; }
    elseif($m=='sablon_silindi') { $mesaj = "Şablon silindi."; $mesajTuru = "warning"; }
    elseif($m=='etiket_eklendi') { $mesaj = "Etiket başarıyla oluşturuldu."; $mesajTuru = "success"; }
    elseif($m=='etiket_silindi') { $mesaj = "Etiket silindi."; $mesajTuru = "warning"; }
}

// ----------------------------------------------------------------------------
// EKRANA BASILACAK VERİLERİ (SİSTEM AYARLARI) ÇEK
// ----------------------------------------------------------------------------
$vade_oranlari = !empty($firma['vade_farki_oranlari']) ? json_decode($firma['vade_farki_oranlari'], true) : [];

$sablonlar = $db->prepare("SELECT * FROM iletisim_sablonlari WHERE firma_id = ? ORDER BY tur ASC, id DESC");
$sablonlar->execute([$firma_id]);
$sablonlar = $sablonlar->fetchAll(PDO::FETCH_ASSOC);

$etiketler = $db->prepare("SELECT * FROM musteri_etiketleri WHERE firma_id = ? ORDER BY etiket_adi ASC");
$etiketler->execute([$firma_id]);
$etiketler = $etiketler->fetchAll(PDO::FETCH_ASSOC);


// UI Ayarları
$inline_css = '
    .list-group-item.active { background-color: #2c3e50; border-color: #2c3e50; color: white !important;}
    .menu-header { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: #a1aab2; letter-spacing: 1px; margin-top: 15px; margin-bottom: 5px; padding-left: 15px; }
    .card { border-radius: 15px; border:none; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1); }
    .limit-bar-bg { height: 10px; background-color: #e9ecef; border-radius: 5px; overflow: hidden; margin-top: 5px; }
    .limit-bar-fill { height: 100%; transition: width 0.5s ease; border-radius: 5px; }
    .input-disabled-custom { background-color: #e9ecef; opacity: 1; cursor: not-allowed; }
    .tag-badge { padding: 8px 15px; border-radius: 20px; color: white; font-weight: bold; font-size: 0.85rem; display: inline-block; margin: 5px; }
    .sms-char-counter { font-size: 0.85rem; font-weight: bold; }
    .sms-warning { color: #e74a3b; }
';

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'abonelik';

// Bilgi Onay Durumu Metni
$onayRozeti = "";
if($firma['bilgi_onay_durumu'] == 0) {
    $onayRozeti = '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle"></i> Bilgiler Eksik</span>';
} elseif($firma['bilgi_onay_durumu'] == 1) {
    $onayRozeti = '<span class="badge bg-info"><i class="fas fa-clock"></i> Onay Bekliyor</span>';
} else {
    $onayRozeti = '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Onaylı Firma</span>';
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | <?php echo htmlspecialchars($firma_adi); ?></title>
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style><?php echo $inline_css; ?></style>
</head>
<body class="yonetim-body">

    <?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim pb-5">
        
        <div class="row mb-4 align-items-center mt-4">
            <div class="col-md-6">
                <h3 class="text-secondary mb-0 fw-bold"><i class="fas fa-sliders-h me-2"></i>Kontrol Paneli (Hesap & Sistem)</h3>
            </div>
            <div class="col-md-6 text-end">
                <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold"><i class="fas fa-arrow-left me-1"></i>Ana Sayfa</a>
            </div>
        </div>

        <?php if($mesaj): ?>
            <div class="alert alert-<?php echo $mesajTuru; ?> shadow-sm border-0 rounded-4 mb-4 d-flex align-items-center">
                <i class="fas fa-info-circle fa-lg me-3"></i> <div><?php echo $mesaj; ?></div>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            
            <div class="col-xl-3 col-lg-4">
                
                <div class="card shadow-sm mb-3">
                    <div class="card-body text-center p-4">
                        <div class="display-4 text-secondary mb-3"><i class="fas fa-user-circle"></i></div>
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($user['ad_soyad'] ?: $user['kullanici_adi']); ?></h5>
                        <small class="text-muted d-block mb-3">@<?php echo htmlspecialchars($user['kullanici_adi']); ?></small>
                        <div class="badge bg-light text-dark border p-2 mb-2 w-100 text-truncate"><?php echo htmlspecialchars($firma_adi); ?></div>
                        <div class="d-flex justify-content-between text-start small mt-3">
                            <span class="text-muted">Rol:</span><span class="fw-bold text-primary"><?php echo ucfirst($my_role); ?></span>
                        </div>
                        <div class="d-flex justify-content-between text-start small mt-1 border-top pt-1">
                            <span class="text-muted">Firma Profili:</span><?= $onayRozeti ?>
                        </div>
                    </div>
                </div>

                <div class="list-group shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                    
                    <div class="menu-header">Kişisel & Firma Yönetimi</div>
                    <a href="?tab=abonelik" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'abonelik' ? 'active' : '' ?>"><i class="fas fa-box w-20px me-2 <?= $activeTab == 'abonelik' ? 'text-white' : 'text-primary' ?>"></i> Abonelik & Limitler</a>
                    <a href="?tab=firma" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'firma' ? 'active' : '' ?>"><i class="fas fa-building w-20px me-2 <?= $activeTab == 'firma' ? 'text-white' : 'text-secondary' ?>"></i> Firma Bilgileri</a>
                    <?php if($my_role == 'admin'): ?>
                    <a href="?tab=kullanicilar" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'kullanicilar' ? 'active' : '' ?>"><i class="fas fa-users w-20px me-2 <?= $activeTab == 'kullanicilar' ? 'text-white' : 'text-success' ?>"></i> Ekip / Alt Kullanıcılar</a>
                    <?php endif; ?>
                    <a href="?tab=profil" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'profil' ? 'active' : '' ?>"><i class="fas fa-user-edit w-20px me-2 <?= $activeTab == 'profil' ? 'text-white' : 'text-dark' ?>"></i> Kişisel Profilim</a>
                    <a href="?tab=sifre" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'sifre' ? 'active' : '' ?>"><i class="fas fa-lock w-20px me-2 <?= $activeTab == 'sifre' ? 'text-white' : 'text-danger' ?>"></i> Şifre Değiştir</a>

                    <?php if($my_role == 'admin'): ?>
                    <div class="menu-header mt-3">CRM & Sistem Ayarları</div>
                    <a href="?tab=teklif_sozlesme" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'teklif_sozlesme' ? 'active' : '' ?>"><i class="fas fa-file-contract w-20px me-2 <?= $activeTab == 'teklif_sozlesme' ? 'text-white' : 'text-info' ?>"></i> Teklif & Sözleşme</a>
                    <a href="?tab=vade" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'vade' ? 'active' : '' ?>"><i class="fas fa-percentage w-20px me-2 <?= $activeTab == 'vade' ? 'text-white' : 'text-warning' ?>"></i> Vade Farkı / Taksit</a>
                    <a href="?tab=sablonlar" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'sablonlar' ? 'active' : '' ?>"><i class="fas fa-comment-alt w-20px me-2 <?= $activeTab == 'sablonlar' ? 'text-white' : 'text-success' ?>"></i> İletişim Şablonları</a>
                    <a href="?tab=etiketler" class="list-group-item list-group-item-action border-0 <?= $activeTab == 'etiketler' ? 'active' : '' ?>"><i class="fas fa-tags w-20px me-2 <?= $activeTab == 'etiketler' ? 'text-white' : 'text-danger' ?>"></i> Müşteri Etiketleri</a>
                    <?php endif; ?>

                    <a href="cikis.php" class="list-group-item list-group-item-action border-0 text-danger mt-2"><i class="fas fa-sign-out-alt w-20px me-2"></i> Çıkış Yap</a>
                </div>
            </div>

            <div class="col-xl-9 col-lg-8">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-body p-4 p-md-5">
                        
                        <?php if($activeTab == 'abonelik'): ?>
                        <h5 class="fw-bold mb-4 text-dark border-bottom pb-3"><i class="fas fa-box text-primary me-2"></i>Abonelik Durumu ve Kullanım Limitleri</h5>
                        
                        <?php if($firma['bilgi_onay_durumu'] < 2): ?>
                            <div class="alert alert-warning shadow-sm border-0 small rounded-3">
                                <i class="fas fa-exclamation-triangle me-2"></i> <strong>Hesabınız Kısıtlıdır!</strong> Mağaza üzerinden satın alma yapabilmek, aboneliğinizi uzatmak ve SMS hizmetini kullanabilmek için <u>Firma Bilgileri</u> sekmesindeki resmi evrak bilgilerinizi doldurmalı ve yönetici onayından geçmelisiniz.
                            </div>
                        <?php endif; ?>

                        <div class="row g-4 mb-5">
                            <div class="col-md-6">
                                <div class="p-4 border rounded-4 bg-light h-100 position-relative overflow-hidden">
                                    <i class="fas fa-crown fa-4x position-absolute opacity-10" style="bottom:-10px; right:-10px;"></i>
                                    <span class="text-muted small fw-bold d-block mb-1">KULLANILAN PAKET</span>
                                    <h4 class="fw-bold text-primary mb-3"><?= htmlspecialchars($firma['paket_adi'] ?? 'Paket Yok') ?></h4>
                                    
                                    <?php if($my_role == 'admin'): ?>
                                        <?php if($firma['bilgi_onay_durumu'] == 2): ?>
                                            <a href="magaza.php" class="btn btn-primary rounded-pill shadow-sm fw-bold"><i class="fas fa-shopping-cart me-1"></i> Paket Yenile / Yükselt</a>
                                        <?php else: ?>
                                            <button class="btn btn-secondary rounded-pill opacity-50 fw-bold shadow-sm" disabled><i class="fas fa-lock me-1"></i> İşlemler Kısıtlı</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-4 border rounded-4 bg-light h-100 position-relative overflow-hidden">
                                    <i class="fas fa-calendar-check fa-4x position-absolute opacity-10" style="bottom:-10px; right:-10px;"></i>
                                    <span class="text-muted small fw-bold d-block mb-1">ABONELİK BİTİŞ TARİHİ</span>
                                    <?php 
                                    $bitis = strtotime($firma['abonelik_bitis'] ?? '');
                                    $kalan_gun = ceil(($bitis - time()) / (60 * 60 * 24));
                                    $renk = $kalan_gun <= 3 ? 'text-danger' : ($kalan_gun <= 10 ? 'text-warning' : 'text-success');
                                    ?>
                                    <h4 class="fw-bold <?= $renk ?> mb-2"><?= !empty($firma['abonelik_bitis']) ? date('d.m.Y', $bitis) : '-' ?></h4>
                                    <span class="badge <?= str_replace('text-', 'bg-', $renk) ?> px-3 py-2 rounded-pill shadow-sm"><i class="fas fa-clock me-1"></i> <?= $kalan_gun > 0 ? "$kalan_gun gün kaldı" : "Süresi Doldu" ?></span>
                                </div>
                            </div>
                        </div>

                        <h6 class="fw-bold mb-3 text-secondary text-uppercase" style="letter-spacing:1px;">Kullanım Limitleriniz</h6>

                        <?php 
                            $paket_depolama = (int)($firma['depolama_limiti'] ?? 0);
                            $ek_depolama = (int)($firma['ek_depolama_alani'] ?? 0);
                            $toplam_depolama_mb = $paket_depolama + $ek_depolama;
                            $depolama_yuzde = $toplam_depolama_mb > 0 ? min(100, ($kullanilan_mb / $toplam_depolama_mb) * 100) : 0;
                            $d_renk = $depolama_yuzde > 90 ? 'bg-danger' : 'bg-info';
                            $toplam_gosterim = $toplam_depolama_mb > 0 ? ($toplam_depolama_mb >= 1024 ? round($toplam_depolama_mb / 1024, 2) . ' GB' : $toplam_depolama_mb . ' MB') : 'Sınırsız';
                        ?>
                        <div class="mb-4 border p-4 rounded-4 bg-white shadow-xs">
                            <div class="d-flex justify-content-between align-items-end mb-2">
                                <span class="fw-bold text-dark"><i class="fas fa-hdd text-info me-2"></i> Depolama Alanı (Disk)</span>
                                <span class="fw-bold bg-light px-3 py-1 rounded border"><?= $gosterim_boyut ?> / <?= $toplam_gosterim ?></span>
                            </div>
                            <?php if($toplam_depolama_mb > 0): ?>
                            <div class="limit-bar-bg mb-2"><div class="limit-bar-fill <?= $d_renk ?>" style="width: <?= $depolama_yuzde ?>%;"></div></div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div class="small text-muted fw-bold">Paket: <?= $paket_depolama ?> MB <?php if($ek_depolama > 0): ?>| <span class="text-info">Ek: <?= $ek_depolama ?> MB</span><?php endif; ?></div>
                                <?php if($my_role == 'admin' && $firma['bilgi_onay_durumu'] == 2): ?><a href="magaza.php" class="btn btn-sm btn-outline-info rounded-pill fw-bold px-3">Alan Al</a><?php endif; ?>
                            </div>
                        </div>

                        <?php 
                            $aylik_trafik_mb = (int)($firma['aylik_trafik_kullanimi'] ?? 0) / 1048576;
                            $gosterim_trafik = $aylik_trafik_mb >= 1024 ? round($aylik_trafik_mb / 1024, 2) . ' GB' : round($aylik_trafik_mb, 2) . ' MB';
                            $ek_trafik = (int)($firma['ek_trafik_limiti'] ?? 0);
                            $toplam_trafik_mb = ($paket_depolama * 10) + $ek_trafik;
                            $toplam_trafik_gosterim = $toplam_trafik_mb >= 1024 ? round($toplam_trafik_mb / 1024, 2) . ' GB' : $toplam_trafik_mb . ' MB';
                            $trafik_yuzde = $toplam_trafik_mb > 0 ? min(100, ($aylik_trafik_mb / $toplam_trafik_mb) * 100) : 0;
                            $t_renk = $trafik_yuzde > 90 ? 'bg-danger' : ($trafik_yuzde > 75 ? 'bg-warning' : 'bg-primary');
                        ?>
                        <div class="mb-4 border p-4 rounded-4 bg-light border-primary border-opacity-25 shadow-xs">
                            <div class="d-flex justify-content-between align-items-end mb-2">
                                <span class="fw-bold text-dark"><i class="fas fa-wifi text-primary me-2"></i> Aylık Veri Trafiği</span>
                                <span class="fw-bold bg-white px-3 py-1 rounded border border-primary border-opacity-25 text-primary"><?= $gosterim_trafik ?> / <?= $toplam_trafik_gosterim ?></span>
                            </div>
                            <?php if($toplam_trafik_mb > 0): ?>
                            <div class="limit-bar-bg mb-2"><div class="limit-bar-fill <?= $t_renk ?>" style="width: <?= $trafik_yuzde ?>%;"></div></div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div class="small text-muted fw-bold">Her ayın 1'inde sıfırlanır. <?php if($ek_trafik > 0): ?>| <span class="text-primary">Ek Kota: <?= $ek_trafik ?> MB</span><?php endif; ?></div>
                                <?php if($my_role == 'admin' && $firma['bilgi_onay_durumu'] == 2): ?><a href="magaza.php" class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3">Kota Al</a><?php endif; ?>
                            </div>
                        </div>

                        <?php 
                            $aylik_sms = (int)($firma['aylik_sms_limiti'] ?? 0);
                            $ek_sms = (int)($firma['ek_sms_bakiyesi'] ?? 0);
                            $kullanilan_aylik = (int)($firma['kullanilan_sms_aylik'] ?? 0);
                            $ayliktan_kalan = max(0, $aylik_sms - $kullanilan_aylik);
                            $toplam_kullanilabilir = $ayliktan_kalan + $ek_sms;
                            $toplam_hedef_sms = $aylik_sms + $ek_sms;
                            $sms_yuzde = $toplam_hedef_sms > 0 ? min(100, (($kullanilan_aylik) / $toplam_hedef_sms) * 100) : 0;
                            $sms_renk = $sms_yuzde > 90 ? 'bg-danger' : ($sms_yuzde > 75 ? 'bg-warning' : 'bg-success');
                        ?>
                        <div class="mb-4 border p-4 rounded-4 bg-white shadow-xs">
                            <div class="d-flex justify-content-between align-items-end mb-2">
                                <span class="fw-bold text-dark"><i class="fas fa-sms text-success me-2"></i> SMS Kullanımı & Bakiyesi</span>
                                <span class="fw-bold bg-success bg-opacity-10 text-success border border-success px-3 py-1 rounded">Mevcut: <?= number_format($toplam_kullanilabilir) ?></span>
                            </div>
                            <div class="limit-bar-bg mb-2"><div class="limit-bar-fill <?= $sms_renk ?>" style="width: <?= $sms_yuzde ?>%;"></div></div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div class="small text-muted fw-bold">Aylık Hakediş: <?= number_format($aylik_sms) ?> | Devreden Ek Bakiye: <?= number_format($ek_sms) ?></div>
                                <?php if($my_role == 'admin' && $firma['bilgi_onay_durumu'] == 2): ?><a href="magaza.php" class="btn btn-sm btn-outline-success rounded-pill fw-bold px-3">SMS Al</a><?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-4">
                            <?php 
                                $toplam_musteri = (int)($firma['musteri_limiti'] ?? 0);
                                $musteri_yuzde = $toplam_musteri > 0 ? min(100, ($aktifMusteri / $toplam_musteri) * 100) : 0;
                                $toplam_ekip = (int)($firma['kullanici_limiti'] ?? 0);
                                $ekip_yuzde = $toplam_ekip > 0 ? min(100, ($kullaniciSayisi / $toplam_ekip) * 100) : 0;
                            ?>
                            <div class="col-md-6">
                                <div class="border p-3 rounded-4 bg-white">
                                    <div class="d-flex justify-content-between align-items-end mb-2">
                                        <span class="fw-bold small text-muted"><i class="fas fa-users text-secondary me-1"></i> Müşteri (Cari)</span>
                                        <span class="fw-bold"><?= number_format($aktifMusteri) ?> / <?= $toplam_musteri > 0 ? number_format($toplam_musteri) : 'Sınırsız' ?></span>
                                    </div>
                                    <?php if($toplam_musteri > 0): ?><div class="limit-bar-bg"><div class="limit-bar-fill bg-secondary" style="width: <?= $musteri_yuzde ?>%;"></div></div><?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border p-3 rounded-4 bg-white">
                                    <div class="d-flex justify-content-between align-items-end mb-2">
                                        <span class="fw-bold small text-muted"><i class="fas fa-user-shield text-warning me-1"></i> Personel (Ekip)</span>
                                        <span class="fw-bold"><?= number_format($kullaniciSayisi) ?> / <?= $toplam_ekip > 0 ? number_format($toplam_ekip) : 'Sınırsız' ?></span>
                                    </div>
                                    <?php if($toplam_ekip > 0): ?><div class="limit-bar-bg"><div class="limit-bar-fill bg-warning" style="width: <?= $ekip_yuzde ?>%;"></div></div><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php elseif($activeTab == 'firma'): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-building text-secondary me-2"></i> Firma Kimlik Bilgileri</h5>
                            <?= $onayRozeti ?>
                        </div>
                        
                        <?php if($firma['bilgi_onay_durumu'] == 0 && $my_role == 'admin'): ?>
                            <div class="alert alert-warning small border-0 shadow-sm rounded-3">
                                <i class="fas fa-exclamation-triangle me-1"></i> <b>Lütfen Dikkat:</b> Sistemi tam yetkili kullanabilmek için aşağıdaki resmi firma bilgilerinizi eksiksiz doldurmalısınız. Bilgiler kaydedildikten sonra <b>onaya gidecek ve kilitlenecektir</b>.
                            </div>
                        <?php elseif($firma['bilgi_onay_durumu'] == 1): ?>
                            <div class="alert alert-info small border-0 shadow-sm rounded-3">
                                <i class="fas fa-hourglass-half me-1"></i> Bilgileriniz sistem yöneticisi tarafından incelenmek üzere <b>onay bekliyor</b>. Bu süreçte bilgiler kilitlenmiştir.
                            </div>
                        <?php elseif($firma['bilgi_onay_durumu'] == 2): ?>
                            <div class="alert alert-success small border-0 shadow-sm rounded-3">
                                <i class="fas fa-check-circle me-1"></i> Firma bilgileriniz onaylanmıştır. Artık tüm paket ve SMS hizmetlerinden yararlanabilirsiniz.
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <input type="hidden" name="update_firma" value="1">
                            <fieldset <?= ($my_role != 'admin' || $firma['bilgi_onay_durumu'] > 0) ? 'disabled' : '' ?>>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-8">
                                        <label class="form-label small fw-bold text-muted">Firma Ünvanı <i class="fas fa-lock text-danger ms-1"></i></label>
                                        <input type="text" class="form-control input-disabled-custom fw-bold" value="<?= htmlspecialchars($firma['firma_adi']) ?>" disabled>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">Sektör</label>
                                        <input type="text" name="sektor" class="form-control" value="<?= htmlspecialchars($firma['sektor'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">İş/Kayıt Telefonu <i class="fas fa-lock text-danger ms-1"></i></label>
                                        <input type="text" class="form-control input-disabled-custom" value="<?= htmlspecialchars($firma['telefon'] ?? '') ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Yetkili Kişi <i class="fas fa-lock text-danger ms-1"></i></label>
                                        <input type="text" class="form-control input-disabled-custom" value="<?= htmlspecialchars($firma['yetkili_ad_soyad'] ?? '') ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Vergi Dairesi</label>
                                        <input type="text" name="vergi_dairesi" class="form-control" value="<?= htmlspecialchars($firma['vergi_dairesi'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Vergi Numarası / TC Kimlik</label>
                                        <input type="text" name="vergi_no" class="form-control" value="<?= htmlspecialchars($firma['vergi_no'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-bold text-muted">İl</label>
                                        <input type="text" name="il" class="form-control" value="<?= htmlspecialchars($firma['il'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-bold text-muted">İlçe</label>
                                        <input type="text" name="ilce" class="form-control" value="<?= htmlspecialchars($firma['ilce'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-bold text-muted">Açık Adres</label>
                                        <textarea name="adres" class="form-control" rows="2" required><?= htmlspecialchars($firma['adres'] ?? '') ?></textarea>
                                    </div>
                                </div>

                                <?php if($my_role == 'admin' && $firma['bilgi_onay_durumu'] == 0): ?>
                                <div class="bg-light p-3 rounded-4 border shadow-sm">
                                    <label class="form-label fw-bold text-danger small mb-1"><i class="fas fa-key me-1"></i> İşlem Onayı İçin Şifreniz</label>
                                    <div class="input-group">
                                        <input type="password" name="dogrulama_sifresi" class="form-control border-danger" required placeholder="Giriş şifreniz">
                                        <button type="submit" class="btn btn-primary px-4 fw-bold">Kaydet ve Kilitle</button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </fieldset>
                        </form>

                        <?php elseif($activeTab == 'kullanicilar'): ?>
                        <?php if($my_role == 'admin'): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-users text-success me-2"></i> Ekip ve Alt Kullanıcılar</h5>
                            <button class="btn btn-success shadow-sm px-4 rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#modalUserEkle"><i class="fas fa-plus me-1"></i> Yeni Ekle</button>
                        </div>
                        <div class="table-responsive border rounded-4 bg-white shadow-sm overflow-hidden">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light text-muted small">
                                    <tr>
                                        <th class="ps-4 py-3 border-0">Personel Adı</th>
                                        <th class="border-0">E-Posta / K.Adı</th>
                                        <th class="border-0">Yetki Rolü</th>
                                        <th class="text-end pe-4 border-0">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($subUsers as $u): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($u['ad_soyad'] ?: 'İsimsiz') ?></span>
                                            <?php if($u['id'] == $user_id) echo '<span class="badge bg-primary ms-2 rounded-pill" style="font-size:10px;">Sen</span>'; ?>
                                        </td>
                                        <td>
                                            <div class="small fw-bold"><i class="fas fa-envelope text-muted me-1"></i> <?= htmlspecialchars($u['email']) ?></div>
                                            <div class="small text-muted">@<?= htmlspecialchars($u['kullanici_adi']) ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                                if($u['rol']=='admin') echo '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger rounded-pill px-3">Yönetici (Admin)</span>';
                                                elseif($u['rol']=='personel') echo '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary rounded-pill px-3">Personel</span>';
                                                elseif($u['rol']=='ajanda') echo '<span class="badge bg-info bg-opacity-10 text-info border border-info rounded-pill px-3">Sadece Ajanda</span>';
                                            ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <?php if($u['id'] != $user_id): ?>
                                            <a href="?tab=kullanicilar&sil_user=<?= $u['id'] ?>" onclick="return confirm('Kalıcı olarak silmek istediğinize emin misiniz?')" class="btn btn-sm btn-outline-danger rounded-circle"><i class="fas fa-trash"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <?php elseif($activeTab == 'profil'): ?>
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-user-edit text-dark me-2"></i> Kişisel Profilim</h5>
                        <div class="alert alert-info small border-0 shadow-sm rounded-3">
                            <i class="fas fa-lock me-1"></i> Güvenlik politikaları gereği profil bilgileriniz (Kullanıcı adı, Ad-Soyad, E-Posta) kilitlenmiştir. Değişiklik için kurucu yöneticiye veya destek birimine başvurunuz.
                        </div>
                        <form>
                            <fieldset disabled>
                                <div class="row g-4 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Sisteme Giriş E-Postası</label>
                                        <input type="email" class="form-control input-disabled-custom py-2" value="<?= htmlspecialchars($user['email']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Kullanıcı Adı</label>
                                        <input type="text" class="form-control input-disabled-custom py-2" value="<?= htmlspecialchars($user['kullanici_adi']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Ad Soyad</label>
                                        <input type="text" class="form-control input-disabled-custom py-2" value="<?= htmlspecialchars($user['ad_soyad']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Cep Telefonu</label>
                                        <input type="text" class="form-control input-disabled-custom py-2" value="<?= htmlspecialchars($user['telefon'] ?? '') ?>">
                                    </div>
                                </div>
                            </fieldset>
                        </form>

                        <?php elseif($activeTab == 'sifre'): ?>
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-shield-alt text-danger me-2"></i> Şifre ve Güvenlik</h5>
                        <form method="POST">
                            <input type="hidden" name="update_sifre" value="1">
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-muted">Mevcut Şifreniz</label>
                                <input type="password" name="eski_sifre" class="form-control py-3 rounded-3" required placeholder="Şu an kullandığınız şifre">
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Yeni Şifre</label>
                                    <input type="password" name="yeni_sifre" class="form-control py-3 rounded-3" required minlength="8" placeholder="En az 8 karakter">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Yeni Şifre (Tekrar)</label>
                                    <input type="password" name="yeni_sifre_tekrar" class="form-control py-3 rounded-3" required minlength="8" placeholder="Yeni şifrenizi doğrulayın">
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-danger px-5 py-2 rounded-pill shadow-sm fw-bold"><i class="fas fa-key me-2"></i>Şifremi Değiştir</button>
                            </div>
                        </form>

                        <?php elseif($activeTab == 'teklif_sozlesme' && $my_role == 'admin'): ?>
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-file-contract text-info me-2"></i> Teklif ve Sözleşme Şablonları</h5>
                        <form method="POST">
                            <input type="hidden" name="update_teklif_sozlesme" value="1">
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-primary"><i class="fas fa-file-invoice me-1"></i> Teklif Şartları (Fatura/Teklif Üst Yazısı)</label>
                                <textarea name="teklif_sartlari" class="form-control border-primary" rows="4"><?= htmlspecialchars($firma['teklif_sartlari'] ?? '') ?></textarea>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-primary"><i class="fas fa-info-circle me-1"></i> Teklif Alt Bilgi (Footer / Banka İban)</label>
                                <input type="text" name="teklif_alt_bilgi" class="form-control border-primary" value="<?= htmlspecialchars($firma['teklif_alt_bilgi'] ?? '') ?>" placeholder="Bizi tercih ettiğiniz için teşekkürler. TR...">
                            </div>
                            <hr class="my-4">
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-warning"><i class="fas fa-signature me-1"></i> Sözleşme Maddeleri (Varsayılan Taslak)</label>
                                <div class="form-text mb-2">Yeni bir sözleşme oluştururken bu maddeler otomatik olarak kağıda dökülecektir.</div>
                                <textarea name="sozlesme_maddeleri" class="form-control font-monospace border-warning" style="font-size: 0.85rem;" rows="10"><?= htmlspecialchars($firma['sozlesme_maddeleri'] ?? '') ?></textarea>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-info text-white px-5 py-2 rounded-pill shadow-sm fw-bold"><i class="fas fa-save me-2"></i>Tüm Taslakları Kaydet</button>
                            </div>
                        </form>

                        <?php elseif($activeTab == 'vade' && $my_role == 'admin'): ?>
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-percentage text-warning me-2"></i> Taksit ve Vade Farkı Oranları</h5>
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <p class="text-muted small">Açık hesaplarda müşteriye taksit (vade) yaparken sistemin anaparanın üzerine ekleyeceği otomatik faiz oranlarıdır.</p>
                                <form method="POST">
                                    <input type="hidden" name="vade_farki_kaydet" value="1">
                                    <div id="vade_listesi">
                                        <?php 
                                        if(empty($vade_oranlari)) {
                                            echo '<div class="row g-2 mb-2 vade-satir"><div class="col-5"><div class="input-group"><input type="number" name="taksit_sayisi[]" class="form-control" placeholder="Örn: 3"><span class="input-group-text bg-white">Taksit</span></div></div><div class="col-5"><div class="input-group"><span class="input-group-text bg-white">%</span><input type="number" step="0.01" name="vade_orani[]" class="form-control" placeholder="10"></div></div><div class="col-2"><button type="button" class="btn btn-outline-danger w-100" onclick="satirSil(this)"><i class="fas fa-trash"></i></button></div></div>';
                                        } else {
                                            foreach($vade_oranlari as $taksit => $oran) {
                                                echo '<div class="row g-2 mb-2 vade-satir"><div class="col-5"><div class="input-group"><input type="number" name="taksit_sayisi[]" class="form-control" value="'.htmlspecialchars($taksit).'"><span class="input-group-text bg-white">Ay/Taksit</span></div></div><div class="col-5"><div class="input-group"><span class="input-group-text bg-white">%</span><input type="number" step="0.01" name="vade_orani[]" class="form-control" value="'.htmlspecialchars($oran).'"></div></div><div class="col-2"><button type="button" class="btn btn-outline-danger w-100" onclick="satirSil(this)"><i class="fas fa-trash"></i></button></div></div>';
                                            }
                                        }
                                        ?>
                                    </div>
                                    <button type="button" class="btn btn-light btn-sm w-100 mb-3 fw-bold border" onclick="yeniVadeSatiri()"><i class="fas fa-plus me-1 text-primary"></i> Yeni Taksit Kuralı Ekle</button>
                                    <button type="submit" class="btn btn-warning text-dark w-100 fw-bold py-2 rounded-pill shadow-sm"><i class="fas fa-save me-1"></i> Oranları Kaydet</button>
                                </form>
                            </div>
                            <div class="col-lg-6">
                                <div class="bg-light p-4 rounded-4 border">
                                    <h6 class="fw-bold"><i class="fas fa-info-circle text-primary me-2"></i> Nasıl Çalışır?</h6>
                                    <p class="small text-muted mb-0">Eğer "3 Taksit" için "%10" oranı belirlerseniz; Müşteri sayfasında 10.000 TL açık borcu <b>"Sihirbaz ile 3'e böl"</b> dediğinizde, sistem toplam borcu 11.000 TL'ye çıkarır ve 3 ayrı 3.666 TL'lik vade tarihi oluşturur.</p>
                                </div>
                            </div>
                        </div>

                        <?php elseif($activeTab == 'sablonlar' && $my_role == 'admin'): ?>
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-comment-alt text-success me-2"></i> İletişim (SMS / WP) Şablonları</h5>
                        <div class="row g-4">
                            <div class="col-lg-5">
                                <div class="bg-light border rounded-4 p-4">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-pen-nib text-primary me-2"></i>Yeni Şablon Oluştur</h6>
                                    <form method="POST">
                                        <input type="hidden" name="sablon_ekle" value="1">
                                        <div class="mb-3">
                                            <input type="text" name="baslik" class="form-control fw-bold" required placeholder="Şablon Adı (Örn: Albüm Hazır)">
                                        </div>
                                        <div class="mb-3">
                                            <select name="tur" id="mesajTuru" class="form-select fw-bold" onchange="charCountCheck()">
                                                <option value="wp">WhatsApp (Otomatik Onaylı)</option>
                                                <option value="sms">SMS (Sistem Onayına Tabi)</option>
                                            </select>
                                            <div class="form-text text-danger small mt-1" id="smsInfo" style="display:none;"><i class="fas fa-shield-alt"></i> SMS şablonları güvenlik nedeniyle inceleme sonrası onaylanır.</div>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <label class="form-label small fw-bold text-muted mb-0">Mesaj İçeriği</label>
                                                <span class="sms-char-counter text-success" id="charCount">0 Karakter</span>
                                            </div>
                                            <textarea name="icerik" id="mesajIcerik" class="form-control" rows="5" required onkeyup="charCountCheck()" placeholder="Sayın [musteri_adi]..."></textarea>
                                            <div class="mt-2 d-flex flex-wrap gap-1">
                                                <span class="badge bg-secondary cursor-pointer py-2 px-2" onclick="insertCode('[musteri_adi]')">[musteri_adi]</span>
                                                <span class="badge bg-secondary cursor-pointer py-2 px-2" onclick="insertCode('[kalan_borc]')">[kalan_borc]</span>
                                                <span class="badge bg-secondary cursor-pointer py-2 px-2" onclick="insertCode('[firma_adi]')">[firma_adi]</span>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill shadow-sm"><i class="fas fa-plus me-1"></i> Listeye Ekle</button>
                                    </form>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <?php if(empty($sablonlar)): ?>
                                    <div class="text-center text-muted p-5 bg-light rounded-4 border"><i class="far fa-comments fa-3x mb-3 opacity-25"></i><br>Kayıtlı şablon yok.</div>
                                <?php else: ?>
                                    <div class="row g-3">
                                        <?php foreach($sablonlar as $s): ?>
                                        <div class="col-md-6">
                                            <div class="card border border-light shadow-sm h-100 position-relative rounded-4">
                                                <div class="card-body p-3 pb-4">
                                                    <div class="d-flex justify-content-between mb-2">
                                                        <span class="fw-bold text-dark text-truncate"><?= htmlspecialchars($s['baslik']) ?></span>
                                                        <span class="badge <?= $s['tur'] == 'wp' ? 'bg-success' : 'bg-info text-dark' ?>"><i class="fab fa-<?= $s['tur'] == 'wp' ? 'whatsapp' : 'sms' ?>"></i> <?= strtoupper($s['tur']) ?></span>
                                                    </div>
                                                    <div class="small text-muted mb-2 fst-italic" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;"><?= htmlspecialchars($s['icerik']) ?></div>
                                                    
                                                    <?php if($s['tur'] == 'sms'): ?>
                                                        <?php if($s['durum'] == 'onay_bekliyor'): ?><span class="badge bg-warning text-dark small"><i class="fas fa-clock"></i> Onay Bekliyor</span>
                                                        <?php elseif($s['durum'] == 'reddedildi'): ?><span class="badge bg-danger small"><i class="fas fa-times"></i> Reddedildi</span>
                                                        <?php else: ?><span class="badge bg-success small"><i class="fas fa-check-circle"></i> Onaylı</span><?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <a href="?tab=sablonlar&del_sablon=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger border-0 position-absolute bottom-0 end-0 m-2" onclick="return confirm('Silmek istiyor musunuz?')"><i class="fas fa-trash"></i></a>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php elseif($activeTab == 'etiketler' && $my_role == 'admin'): ?>
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-tags text-danger me-2"></i> Müşteri Etiketleri (CRM Tags)</h5>
                        <div class="row g-4">
                            <div class="col-lg-4">
                                <div class="bg-light border rounded-4 p-4">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-plus text-primary me-2"></i>Yeni Etiket</h6>
                                    <form method="POST">
                                        <input type="hidden" name="etiket_ekle" value="1">
                                        <div class="mb-3">
                                            <input type="text" name="etiket_adi" class="form-control fw-bold" required placeholder="Örn: VIP, Acil, Sorunlu">
                                        </div>
                                        <div class="mb-4">
                                            <label class="form-label small fw-bold text-muted">Renk Seçimi</label>
                                            <input type="color" name="renk" class="form-control form-control-color w-100" value="#4e73df">
                                        </div>
                                        <button type="submit" class="btn btn-danger w-100 fw-bold rounded-pill shadow-sm"><i class="fas fa-save me-1"></i> Oluştur</button>
                                    </form>
                                </div>
                            </div>
                            <div class="col-lg-8">
                                <p class="text-muted small">Oluşturduğunuz etiketleri müşteri detay sayfalarında kişilere atayarak onları renkli bir şekilde kategorize edebilirsiniz.</p>
                                <div class="p-4 border rounded-4 bg-white d-flex flex-wrap gap-2 shadow-sm">
                                    <?php if(empty($etiketler)): ?>
                                        <span class="text-muted small w-100 text-center py-3">Etiket havuzunuz boş.</span>
                                    <?php else: ?>
                                        <?php foreach($etiketler as $e): ?>
                                            <div class="tag-badge shadow-sm" style="background-color: <?= $e['renk'] ?>;">
                                                <i class="fas fa-tag me-1"></i> <?= htmlspecialchars($e['etiket_adi']) ?>
                                                <a href="?tab=etiketler&del_etiket=<?= $e['id'] ?>" class="text-white ms-2" onclick="return confirm('Bu etiket tüm müşterilerden de silinecektir. Emin misiniz?')" style="opacity:0.7;"><i class="fas fa-times"></i></a>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if($my_role == 'admin'): ?>
    <div class="modal fade" id="modalUserEkle" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="add_user" value="1">
                    <div class="modal-header bg-success text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>Yeni Ekip Üyesi</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <div class="alert alert-info small py-2 mb-4 shadow-sm border-0">
                            Personelinize <b>E-Posta</b> ve <b>Şifre</b> bilgilerini ileterek sisteme giriş yapmasını sağlayabilirsiniz.
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Ad Soyad <span class="text-danger">*</span></label>
                            <input type="text" name="u_adsoyad" class="form-control fw-bold" required placeholder="Personel Adı">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Giriş E-Posta Adresi <span class="text-danger">*</span></label>
                            <input type="email" name="u_email" class="form-control" required placeholder="personel@firma.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Geçici Şifre <span class="text-danger">*</span></label>
                            <input type="text" name="u_sifre" class="form-control fw-bold text-danger" value="<?= rand(100000, 999999) ?>" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-primary">Yetki / Panel Rolü</label>
                            <select name="u_rol" class="form-select fw-bold border-primary">
                                <option value="personel">Personel (Sınırlı Gösterim)</option>
                                <option value="ajanda">Sadece Ajanda (Takvim Görebilir)</option>
                                <option value="admin">Yönetici (Tam Yetki)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 bg-white py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm rounded-pill"><i class="fas fa-check me-1"></i> Kullanıcıyı Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include 'partials/footer_yonetim.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Vade Sihirbazı Satır Ekle/Sil
        function yeniVadeSatiri() {
            let html = '<div class="row g-2 mb-2 vade-satir"><div class="col-5"><div class="input-group"><input type="number" name="taksit_sayisi[]" class="form-control" placeholder="Örn: 3"><span class="input-group-text bg-white">Ay/Taksit</span></div></div><div class="col-5"><div class="input-group"><span class="input-group-text bg-white">%</span><input type="number" step="0.01" name="vade_orani[]" class="form-control" placeholder="10"></div></div><div class="col-2"><button type="button" class="btn btn-outline-danger w-100" onclick="satirSil(this)"><i class="fas fa-trash"></i></button></div></div>';
            document.getElementById('vade_listesi').insertAdjacentHTML('beforeend', html);
        }
        function satirSil(btn) {
            btn.closest('.vade-satir').remove();
        }

        // SMS Karakter Sayacı
        function charCountCheck() {
            let turEl = document.getElementById('mesajTuru');
            if(!turEl) return;
            let tur = turEl.value;
            let txt = document.getElementById('mesajIcerik').value;
            let info = document.getElementById('smsInfo');
            let counter = document.getElementById('charCount');
            
            if(tur === 'sms') {
                info.style.display = 'block';
                let len = txt.length;
                let hak = Math.ceil(len / 130);
                if(hak === 0) hak = 1;
                
                counter.innerText = len + " Karakter (" + hak + " SMS Kredisi Harcar)";
                if(len > 130) counter.className = "sms-char-counter sms-warning";
                else counter.className = "sms-char-counter text-success";
            } else {
                info.style.display = 'none';
                counter.innerText = txt.length + " Karakter (WhatsApp Sınırsız)";
                counter.className = "sms-char-counter text-success";
            }
        }

        // Şablona Kısa Kod Ekleme
        function insertCode(code) {
            let textarea = document.getElementById('mesajIcerik');
            let cursorPos = textarea.selectionStart;
            let v = textarea.value;
            textarea.value = v.substring(0, cursorPos) + code + v.substring(cursorPos);
            textarea.focus();
            charCountCheck();
        }
    </script>
</body>
</html>