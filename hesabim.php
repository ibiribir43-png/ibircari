<?php
session_start();
require 'baglanti.php';

// --- FONKSİYONLARI DAHİL ET ---
$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

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
    } catch (Exception $e) {
        // Hata durumunda sistemi çökertme
    }
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
$page_title = "Hesap Ayarları";

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

// --- YARDIMCI FONKSİYON: ŞİFRE DOĞRULAMA ---
function sifreDogruMu($db, $uid, $pass) {
    $sorgu = $db->prepare("SELECT sifre FROM yoneticiler WHERE id = ?");
    $sorgu->execute([$uid]);
    $kayitli = $sorgu->fetchColumn();
    return (password_verify($pass, $kayitli) || md5($pass) === $kayitli);
}

// ------------------- İŞLEMLER (POST) -------------------

// 1. EKSİK FİRMA BİLGİLERİNİ GİRME VE ONAYA GÖNDERME (Sadece Admin)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_firma'])) {
    if ($my_role == 'admin') {
        if($firma['bilgi_onay_durumu'] > 0) {
            $mesaj = "Firma bilgileriniz kilitlenmiştir. Değişiklik yapmak için sistem yöneticisine başvurunuz.";
            $mesajTuru = "warning";
        } else {
            $dogrulama = $_POST['dogrulama_sifresi'];
            
            if (sifreDogruMu($db, $user_id, $dogrulama)) {
                $guncelle = $db->prepare("UPDATE firmalar SET sektor=?, adres=?, il=?, ilce=?, vergi_dairesi=?, vergi_no=?, bilgi_onay_durumu=1 WHERE id=?");
                $sonuc = $guncelle->execute([
                    $_POST['sektor'], $_POST['adres'], $_POST['il'], 
                    $_POST['ilce'], $_POST['vergi_dairesi'], $_POST['vergi_no'], 
                    $firma_id
                ]);
                
                if ($sonuc) {
                    $mesaj = "Eksik firma bilgileriniz başarıyla kaydedildi ve onaya gönderildi. Bu alanlar artık kilitlenmiştir.";
                    $mesajTuru = "success";
                    
                    $firma['sektor'] = $_POST['sektor'];
                    $firma['adres'] = $_POST['adres'];
                    $firma['il'] = $_POST['il'];
                    $firma['ilce'] = $_POST['ilce'];
                    $firma['vergi_dairesi'] = $_POST['vergi_dairesi'];
                    $firma['vergi_no'] = $_POST['vergi_no'];
                    $firma['bilgi_onay_durumu'] = 1;
                    
                    logKaydetLocal($db, "Firma Bilgisi Tamamlama", "Firma adres ve vergi bilgilerini doldurup kilitledi.", $firma_id, $user_id);
                }
            } else {
                $mesaj = "Güvenlik şifresi hatalı! İşlem yapılamadı.";
                $mesajTuru = "danger";
            }
        }
    } else {
        $mesaj = "Bu işlemi yapmaya yetkiniz yok.";
        $mesajTuru = "danger";
    }
}

// 2. TEKLİF AYARLARINI GÜNCELLE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_teklif_ayar'])) {
    if ($my_role == 'admin') {
        $sartlar = $_POST['teklif_sartlari'];
        $footer = $_POST['teklif_alt_bilgi'];
        
        $guncelle = $db->prepare("UPDATE firmalar SET teklif_sartlari=?, teklif_alt_bilgi=? WHERE id=?");
        $sonuc = $guncelle->execute([$sartlar, $footer, $firma_id]);

        if ($sonuc) {
            $mesaj = "Teklif ayarları başarıyla kaydedildi.";
            $mesajTuru = "success";
            $firma['teklif_sartlari'] = $sartlar;
            $firma['teklif_alt_bilgi'] = $footer;
            
            logKaydetLocal($db, "Teklif Ayarı", "Teklif şablonu güncellendi.", $firma_id, $user_id);
        }
    }
}

// 3. SÖZLEŞME AYARLARINI GÜNCELLE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_sozlesme_ayar'])) {
    if ($my_role == 'admin') {
        $guncelle = $db->prepare("UPDATE firmalar SET sozlesme_maddeleri=? WHERE id=?");
        $sonuc = $guncelle->execute([$_POST['sozlesme_maddeleri'], $firma_id]);
        if ($sonuc) {
            $mesaj = "Sözleşme şablonunuz başarıyla güncellendi.";
            $mesajTuru = "success";
            $firma['sozlesme_maddeleri'] = $_POST['sozlesme_maddeleri'];
            
            logKaydetLocal($db, "Sözleşme Ayarı", "Sözleşme şablonu güncellendi.", $firma_id, $user_id);
        }
    }
}

// 4. PROFİL GÜNCELLEME İPTAL EDİLDİ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profil'])) {
    $mesaj = "Güvenlik politikaları gereği profil bilgilerinizi (İsim, Telefon, E-Posta) buradan değiştiremezsiniz. Lütfen destek talebi oluşturunuz.";
    $mesajTuru = "warning";
}

// 5. ŞİFRE DEĞİŞTİR
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_sifre'])) {
    $eski = $_POST['eski_sifre'];
    $yeni = $_POST['yeni_sifre'];
    $yeni_tekrar = $_POST['yeni_sifre_tekrar'];

    if (sifreDogruMu($db, $user_id, $eski)) {
        if ($yeni === $yeni_tekrar) {
            if (strlen($yeni) < 8) {
                $mesaj = "Yeni şifreniz en az 8 karakter olmalıdır.";
                $mesajTuru = "warning";
            } else {
                $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
                $yeni_hash = password_hash($yeni, $algo);
                
                $db->prepare("UPDATE yoneticiler SET sifre=? WHERE id=?")->execute([$yeni_hash, $user_id]);
                $mesaj = "Şifreniz başarıyla değiştirildi.";
                $mesajTuru = "success";
                
                logKaydetLocal($db, "Şifre Değişikliği", "Kullanıcı kendi şifresini değiştirdi.", $firma_id, $user_id);
            }
        } else {
            $mesaj = "Yeni şifreler birbiriyle uyuşmuyor.";
            $mesajTuru = "danger";
        }
    } else {
        $mesaj = "Mevcut şifrenizi yanlış girdiniz.";
        $mesajTuru = "danger";
    }
}

// 6. YENİ ALT KULLANICI EKLE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    if ($my_role == 'admin') {
        $k_limit = (int)$firma['kullanici_limiti'];
        if ($k_limit > 0 && $kullaniciSayisi >= $k_limit) {
            $mesaj = "Paketinizin kullanıcı ekleme sınırına ($k_limit) ulaştınız!";
            $mesajTuru = "warning";
        } else {
            $u_adsoyad = trim($_POST['u_adsoyad']);
            $u_email = strtolower(trim($_POST['u_email']));
            $u_kadi = explode('@', $u_email)[0] . rand(10,99); 
            $u_sifre = trim($_POST['u_sifre']);
            $u_rol = $_POST['u_rol'];

            $chk = $db->prepare("SELECT id FROM yoneticiler WHERE email=?");
            $chk->execute([$u_email]);
            
            if ($chk->rowCount() > 0) {
                $mesaj = "Bu e-posta adresi zaten kullanılıyor.";
                $mesajTuru = "danger";
            } else {
                $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
                $u_hash = password_hash($u_sifre, $algo);

                $ekle = $db->prepare("INSERT INTO yoneticiler (firma_id, kullanici_adi, email, sifre, ad_soyad, rol, email_onayli) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $ekle->execute([$firma_id, $u_kadi, $u_email, $u_hash, $u_adsoyad, $u_rol]);
                
                $mesaj = "Yeni kullanıcı başarıyla eklendi.";
                $mesajTuru = "success";
                
                logKaydetLocal($db, "Kullanıcı Eklendi", "$u_adsoyad ($u_email) isimli yeni bir personel hesabı oluşturuldu.", $firma_id, $user_id);
                
                $subUsers = $db->query("SELECT * FROM yoneticiler WHERE firma_id='$firma_id' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                $kullaniciSayisi = count($subUsers);
            }
        }
    }
}

// 7. KULLANICI SİL
if (isset($_GET['sil_user']) && $my_role == 'admin') {
    $sil_id = intval($_GET['sil_user']);
    if ($sil_id != $user_id) {
        $silinecekSorgu = $db->prepare("SELECT ad_soyad, email FROM yoneticiler WHERE id=? AND firma_id=?");
        $silinecekSorgu->execute([$sil_id, $firma_id]);
        $silinecekKisi = $silinecekSorgu->fetch(PDO::FETCH_ASSOC);
        
        if ($silinecekKisi) {
            $db->prepare("DELETE FROM yoneticiler WHERE id=? AND firma_id=?")->execute([$sil_id, $firma_id]);
            logKaydetLocal($db, "Kullanıcı Silindi", $silinecekKisi['ad_soyad'] . " (" . $silinecekKisi['email'] . ") isimli personelin hesabı silindi.", $firma_id, $user_id);
        }
        
        header("Location: hesabim.php?tab=kullanicilar"); 
        exit;
    }
}

// Inline CSS
$inline_css = '
    .list-group-item.active { background-color: #2c3e50; border-color: #2c3e50; color: white !important;}
    .card { border-radius: 15px; border:none; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1); }
    .table-hover tbody tr:hover { background-color: #eef2f7; transition: 0.2s; }
    .btn-rounded { border-radius: 50px; }
    .btn-primary { background-color: #4e73df; border-color: #4e73df; }
    .btn-primary:hover { background-color: #2e59d9; border-color: #2653d4; }
    .limit-bar-bg { height: 10px; background-color: #e9ecef; border-radius: 5px; overflow: hidden; margin-top: 5px; }
    .limit-bar-fill { height: 100%; transition: width 0.5s ease; border-radius: 5px; }
    .input-disabled-custom { background-color: #e9ecef; opacity: 1; cursor: not-allowed; }
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
    
    <style>
        <?php echo $inline_css; ?>
    </style>
</head>
<body class="yonetim-body">

    <?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim pb-5">
        
        <!-- ÜST BAŞLIK -->
        <div class="row mb-4 align-items-center mt-4">
            <div class="col-md-6">
                <h3 class="text-secondary mb-0"><i class="fas fa-user-cog me-2"></i>Hesap Ayarları & Limitler</h3>
            </div>
            <div class="col-md-6 text-end">
                <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Ana Sayfa</a>
            </div>
        </div>

        <!-- UYARI MESAJLARI -->
        <?php if($mesaj): ?>
            <div class="alert alert-<?php echo $mesajTuru; ?> alert-dismissible fade show shadow-sm border-0">
                <?php echo $mesaj; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- SOL MENÜ -->
            <div class="col-md-3 mb-4">
                
                <!-- KULLANICI KARTI -->
                <div class="card shadow-sm mb-3">
                    <div class="card-body text-center p-4">
                        <div class="display-4 text-secondary mb-3"><i class="fas fa-user-circle"></i></div>
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($user['ad_soyad'] ?: $user['kullanici_adi']); ?></h5>
                        <small class="text-muted d-block mb-3">@<?php echo htmlspecialchars($user['kullanici_adi']); ?></small>
                        
                        <div class="badge bg-light text-dark border p-2 mb-2 w-100">
                            <?php echo htmlspecialchars($firma_adi); ?>
                        </div>
                        
                        <div class="d-flex justify-content-between text-start small mt-3">
                            <span class="text-muted">Rol:</span>
                            <span class="fw-bold text-primary"><?php echo ucfirst($my_role); ?></span>
                        </div>
                        <div class="d-flex justify-content-between text-start small">
                            <span class="text-muted">Firma ID:</span>
                            <span class="fw-bold"><?php echo $firma['id']; ?></span>
                        </div>
                        <div class="d-flex justify-content-between text-start small mt-1 border-top pt-1">
                            <span class="text-muted">Profil Durumu:</span>
                            <?= $onayRozeti ?>
                        </div>
                    </div>
                </div>

                <!-- MENÜ LİNKLERİ -->
                <div class="list-group shadow-sm">
                    <a href="?tab=abonelik" class="list-group-item list-group-item-action <?= $activeTab == 'abonelik' ? 'active' : '' ?>">
                        <i class="fas fa-box me-2 <?= $activeTab == 'abonelik' ? 'text-white' : 'text-primary' ?>"></i> Abonelik & Limitler
                    </a>
                    
                    <a href="?tab=firma" class="list-group-item list-group-item-action <?= $activeTab == 'firma' ? 'active' : '' ?>">
                        <i class="fas fa-building me-2 <?= $activeTab == 'firma' ? 'text-white' : 'text-secondary' ?>"></i> Firma Bilgileri
                        <?php if($firma['bilgi_onay_durumu'] == 0): ?><i class="fas fa-exclamation-circle text-warning float-end mt-1"></i><?php endif; ?>
                    </a>
                    
                    <a href="?tab=teklifayar" class="list-group-item list-group-item-action <?= $activeTab == 'teklifayar' ? 'active' : '' ?>">
                        <i class="fas fa-file-invoice me-2 <?= $activeTab == 'teklifayar' ? 'text-white' : 'text-info' ?>"></i> Teklif Ayarları
                    </a>
                    <a href="?tab=sozlesmeayar" class="list-group-item list-group-item-action <?= $activeTab == 'sozlesmeayar' ? 'active' : '' ?>">
                        <i class="fas fa-file-contract me-2 <?= $activeTab == 'sozlesmeayar' ? 'text-white' : 'text-warning' ?>"></i> Sözleşme Ayarları
                    </a>

                    <?php if($my_role == 'admin'): ?>
                    <a href="?tab=kullanicilar" class="list-group-item list-group-item-action <?= $activeTab == 'kullanicilar' ? 'active' : '' ?>">
                        <i class="fas fa-users me-2 <?= $activeTab == 'kullanicilar' ? 'text-white' : 'text-success' ?>"></i> Ekip / Alt Kullanıcılar
                    </a>
                    <?php endif; ?>

                    <a href="?tab=profil" class="list-group-item list-group-item-action <?= $activeTab == 'profil' ? 'active' : '' ?>">
                        <i class="fas fa-user-edit me-2 <?= $activeTab == 'profil' ? 'text-white' : 'text-dark' ?>"></i> Kişisel Profilim
                    </a>
                    <a href="?tab=sifre" class="list-group-item list-group-item-action <?= $activeTab == 'sifre' ? 'active' : '' ?>">
                        <i class="fas fa-lock me-2 <?= $activeTab == 'sifre' ? 'text-white' : 'text-danger' ?>"></i> Şifre Değiştir
                    </a>
                    <a href="cikis.php" class="list-group-item list-group-item-action text-danger">
                        <i class="fas fa-sign-out-alt me-2"></i> Çıkış Yap
                    </a>
                </div>
            </div>

            <!-- SAĞ İÇERİK -->
            <div class="col-md-9">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        
                        <?php if($activeTab == 'abonelik'): ?>
                        <!-- 0. ABONELİK VE LİMİTLER -->
                        <h5 class="fw-bold mb-4 text-primary border-bottom pb-2"><i class="fas fa-box text-primary me-2"></i>Abonelik Durumu ve Kullanım Limitleri</h5>
                        
                        <?php if($firma['bilgi_onay_durumu'] < 2): ?>
                            <div class="alert alert-warning shadow-sm border-0 small">
                                <i class="fas fa-exclamation-triangle me-2"></i> <strong>Hesabınız Kısıtlıdır!</strong> Mağaza üzerinden satın alma yapabilmek, aboneliğinizi uzatmak ve SMS hizmetini kullanabilmek için <u>Firma Bilgileri</u> sekmesindeki resmi evrak bilgilerinizi doldurmalı ve yönetici onayından geçmelisiniz.
                            </div>
                        <?php endif; ?>

                        <div class="row g-4 mb-4">
                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-light h-100">
                                    <span class="text-muted small fw-bold d-block mb-1">KULLANILAN PAKET</span>
                                    <h5 class="fw-bold text-primary mb-0"><?= htmlspecialchars($firma['paket_adi'] ?? 'Paket Yok') ?></h5>
                                    
                                    <?php if($my_role == 'admin'): ?>
                                        <?php if($firma['bilgi_onay_durumu'] == 2): ?>
                                            <a href="magaza.php" class="btn btn-sm btn-primary mt-2 shadow-sm fw-bold"><i class="fas fa-shopping-cart me-1"></i> Paket Yenile / Yükselt</a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-secondary mt-2 opacity-50 fw-bold shadow-sm" disabled><i class="fas fa-lock me-1"></i> Paket İşlemleri Kısıtlı</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-light h-100">
                                    <span class="text-muted small fw-bold d-block mb-1">ABONELİK BİTİŞ TARİHİ</span>
                                    <?php 
                                    $bitis = strtotime($firma['abonelik_bitis'] ?? '');
                                    $kalan_gun = ceil(($bitis - time()) / (60 * 60 * 24));
                                    $renk = $kalan_gun <= 3 ? 'text-danger' : ($kalan_gun <= 10 ? 'text-warning' : 'text-success');
                                    ?>
                                    <h5 class="fw-bold <?= $renk ?> mb-0"><?= !empty($firma['abonelik_bitis']) ? date('d.m.Y', $bitis) : '-' ?></h5>
                                    <span class="small <?= $renk ?> fw-bold"><i class="fas fa-clock me-1"></i> <?= $kalan_gun > 0 ? "$kalan_gun gün kaldı" : "Süresi Doldu" ?></span>
                                    
                                    <div class="d-flex justify-content-between text-muted mt-2 pt-2 border-top" style="font-size: 11px;">
                                        <span>İlk Kayıt: <b><?= $firma['ilk_abonelik_tarihi'] ? date('d.m.Y', strtotime($firma['ilk_abonelik_tarihi'])) : '-' ?></b></span>
                                        <span>Son Yenileme: <b><?= $firma['son_abonelik_baslangic'] ? date('d.m.Y', strtotime($firma['son_abonelik_baslangic'])) : '-' ?></b></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <h6 class="fw-bold mb-3 text-secondary">Kullanım Limitleriniz</h6>

                        <!-- 1. DEPOLAMA LİMİTİ (STORAGE) -->
                        <?php 
                            $paket_depolama = (int)($firma['depolama_limiti'] ?? 0);
                            $ek_depolama = (int)($firma['ek_depolama_alani'] ?? 0);
                            $toplam_depolama_mb = $paket_depolama + $ek_depolama;
                            
                            $depolama_yuzde = $toplam_depolama_mb > 0 ? min(100, ($kullanilan_mb / $toplam_depolama_mb) * 100) : 0;
                            $d_renk = $depolama_yuzde > 90 ? 'bg-danger' : 'bg-info';
                            
                            $toplam_gosterim = 'Sınırsız';
                            if ($toplam_depolama_mb > 0) {
                                $toplam_gosterim = $toplam_depolama_mb >= 1024 ? round($toplam_depolama_mb / 1024, 2) . ' GB' : $toplam_depolama_mb . ' MB';
                            }
                        ?>
                        <div class="mb-4 border p-3 rounded bg-white shadow-xs">
                            <div class="d-flex justify-content-between align-items-end">
                                <span class="fw-bold small text-muted"><i class="fas fa-hdd text-info me-1"></i> Depolama Alanı (Disk Kullanımı)</span>
                                <span class="small fw-bold">
                                    <?= $gosterim_boyut ?> / <?= $toplam_gosterim ?>
                                </span>
                            </div>
                            <?php if($toplam_depolama_mb > 0): ?>
                            <div class="limit-bar-bg mb-2">
                                <div class="limit-bar-fill <?= $d_renk ?>" style="width: <?= $depolama_yuzde ?>%;"></div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div class="small text-muted" style="font-size: 11px;">
                                    Paket: <?= $paket_depolama ?> MB 
                                    <?php if($ek_depolama > 0): ?>| <span class="text-info fw-bold">Ekstra: <?= $ek_depolama ?> MB</span><?php endif; ?>
                                </div>
                                <?php if($my_role == 'admin'): ?>
                                    <?php if($firma['bilgi_onay_durumu'] == 2): ?>
                                        <a href="magaza.php" class="btn btn-xs btn-outline-info py-0 px-2 fw-bold" style="font-size:10px;">EK ALAN AL</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 2. AYLIK TRAFİK LİMİTİ (BANDWIDTH) -->
                        <?php 
                            $aylik_trafik_bayt = (int)($firma['aylik_trafik_kullanimi'] ?? 0);
                            $aylik_trafik_mb = $aylik_trafik_bayt / 1048576;
                            
                            $gosterim_trafik = $aylik_trafik_mb >= 1024 ? round($aylik_trafik_mb / 1024, 2) . ' GB' : round($aylik_trafik_mb, 2) . ' MB';

                            $ek_trafik = (int)($firma['ek_trafik_limiti'] ?? 0);
                            // Trafik Limiti = (Depolama x 10) + Ekstra Satın Alınan
                            $toplam_trafik_mb = ($paket_depolama * 10) + $ek_trafik;
                            $toplam_trafik_gosterim = $toplam_trafik_mb >= 1024 ? round($toplam_trafik_mb / 1024, 2) . ' GB' : $toplam_trafik_mb . ' MB';
                            
                            $trafik_yuzde = $toplam_trafik_mb > 0 ? min(100, ($aylik_trafik_mb / $toplam_trafik_mb) * 100) : 0;
                            $t_renk = $trafik_yuzde > 90 ? 'bg-danger' : ($trafik_yuzde > 75 ? 'bg-warning' : 'bg-primary');
                        ?>
                        <div class="mb-4 border p-3 rounded bg-light border-primary border-opacity-25 shadow-xs">
                            <div class="d-flex justify-content-between align-items-end mb-1">
                                <span class="fw-bold small text-muted"><i class="fas fa-wifi text-primary me-1"></i> Aylık Veri Trafiği</span>
                                <span class="small fw-bold">
                                    <?= $gosterim_trafik ?> / <?= $toplam_trafik_gosterim ?>
                                </span>
                            </div>
                            <?php if($toplam_trafik_mb > 0): ?>
                            <div class="limit-bar-bg mb-2">
                                <div class="limit-bar-fill <?= $t_renk ?>" style="width: <?= $trafik_yuzde ?>%;"></div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="small text-muted" style="font-size: 11px;">
                                    Her ay sonunda otomatik sıfırlanır.
                                    <?php if($ek_trafik > 0): ?>| <span class="text-primary fw-bold">Ek Kota: <?= $ek_trafik ?> MB</span><?php endif; ?>
                                </div>
                                <?php if($my_role == 'admin' && $firma['bilgi_onay_durumu'] == 2): ?>
                                    <a href="magaza.php" class="btn btn-xs btn-outline-primary py-0 px-2 fw-bold" style="font-size:10px;">EK KOTA AL</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 3. SMS LİMİTİ -->
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
                        <div class="mb-4 border p-3 rounded bg-white shadow-xs">
                            <div class="d-flex justify-content-between align-items-end">
                                <span class="fw-bold small text-muted"><i class="fas fa-sms text-success me-1"></i> SMS Kullanımı & Bakiyesi</span>
                                <span class="small fw-bold">
                                    Toplam Kullanılabilir: <?= number_format($toplam_kullanilabilir) ?>
                                </span>
                            </div>
                            <div class="limit-bar-bg mb-2">
                                <div class="limit-bar-fill <?= $sms_renk ?>" style="width: <?= $sms_yuzde ?>%;"></div>
                            </div>
                            <div class="d-flex justify-content-between text-muted" style="font-size: 11px;">
                                <span>Paket Hak: <?= number_format($aylik_sms) ?> (Kalan: <?= number_format($ayliktan_kalan) ?>)</span>
                                <span>Devreden Ek: <?= number_format($ek_sms) ?></span>
                            </div>
                            <?php if($my_role == 'admin' && $firma['bilgi_onay_durumu'] == 2): ?>
                                <div class="text-end mt-2">
                                    <a href="magaza.php" class="btn btn-xs btn-outline-success py-0 px-2 fw-bold" style="font-size:10px;">EK SMS AL</a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- 4. Müşteri Limiti -->
                        <?php 
                            $toplam_musteri = (int)($firma['musteri_limiti'] ?? 0);
                            $musteri_yuzde = $toplam_musteri > 0 ? min(100, ($aktifMusteri / $toplam_musteri) * 100) : 0;
                            $m_renk = $musteri_yuzde > 90 ? 'bg-danger' : 'bg-secondary';
                        ?>
                        <div class="mb-4 px-1">
                            <div class="d-flex justify-content-between align-items-end">
                                <span class="fw-bold small text-muted"><i class="fas fa-users text-secondary me-1"></i> Müşteri / Cari Limiti</span>
                                <span class="small fw-bold"><?= number_format($aktifMusteri) ?> / <?= $toplam_musteri > 0 ? number_format($toplam_musteri) : 'Sınırsız' ?></span>
                            </div>
                            <?php if($toplam_musteri > 0): ?>
                            <div class="limit-bar-bg">
                                <div class="limit-bar-fill <?= $m_renk ?>" style="width: <?= $musteri_yuzde ?>%;"></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- 5. Kullanıcı Ekip Limiti -->
                        <?php 
                            $toplam_ekip = (int)($firma['kullanici_limiti'] ?? 0);
                            $ekip_yuzde = $toplam_ekip > 0 ? min(100, ($kullaniciSayisi / $toplam_ekip) * 100) : 0;
                        ?>
                        <div class="mb-2 px-1">
                            <div class="d-flex justify-content-between align-items-end">
                                <span class="fw-bold small text-muted"><i class="fas fa-user-shield text-warning me-1"></i> Ekip/Personel Limiti</span>
                                <span class="small fw-bold"><?= number_format($kullaniciSayisi) ?> / <?= $toplam_ekip > 0 ? number_format($toplam_ekip) : 'Sınırsız' ?></span>
                            </div>
                            <?php if($toplam_ekip > 0): ?>
                            <div class="limit-bar-bg">
                                <div class="limit-bar-fill bg-warning" style="width: <?= $ekip_yuzde ?>%;"></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php elseif($activeTab == 'firma'): ?>
                        <!-- 1. FİRMA BİLGİLERİ -->
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-building text-secondary me-2"></i> Firma Kimlik Bilgileri</h5>
                            <?= $onayRozeti ?>
                        </div>
                        
                        <?php if($firma['bilgi_onay_durumu'] == 0 && $my_role == 'admin'): ?>
                            <div class="alert alert-warning small border-0 shadow-sm">
                                <i class="fas fa-exclamation-triangle me-1"></i> <b>Lütfen Dikkat:</b> Sistemi tam yetkili kullanabilmek için aşağıdaki resmi firma bilgilerinizi eksiksiz doldurmalısınız. Bilgiler kaydedildikten sonra <b>onaya gidecek ve kilitlenecektir</b>.
                            </div>
                        <?php elseif($firma['bilgi_onay_durumu'] == 1): ?>
                            <div class="alert alert-info small border-0 shadow-sm">
                                <i class="fas fa-hourglass-half me-1"></i> Bilgileriniz sistem yöneticisi tarafından incelenmek üzere <b>onay bekliyor</b>. Bu süreçte bilgiler kilitlenmiştir.
                            </div>
                        <?php elseif($firma['bilgi_onay_durumu'] == 2): ?>
                            <div class="alert alert-success small border-0 shadow-sm">
                                <i class="fas fa-check-circle me-1"></i> Firma bilgileriniz onaylanmıştır. Artık tüm paket ve SMS hizmetlerinden yararlanabilirsiniz. Bilgi değişikliği için destek talebi oluşturun.
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <input type="hidden" name="update_firma" value="1">
                            <fieldset <?= ($my_role != 'admin' || $firma['bilgi_onay_durumu'] > 0) ? 'disabled' : '' ?>>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-8">
                                        <label class="form-label small fw-bold text-muted">Firma Ünvanı <i class="fas fa-lock text-danger ms-1" title="Değiştirilemez"></i></label>
                                        <input type="text" class="form-control input-disabled-custom fw-bold" value="<?= htmlspecialchars($firma['firma_adi']) ?>" disabled>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">Sektör</label>
                                        <input type="text" name="sektor" class="form-control" value="<?= htmlspecialchars($firma['sektor'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">İş/Kayıt Telefonu <i class="fas fa-lock text-danger ms-1" title="Değiştirilemez"></i></label>
                                        <input type="text" class="form-control input-disabled-custom" value="<?= htmlspecialchars($firma['telefon'] ?? '') ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Yetkili Kişi <i class="fas fa-lock text-danger ms-1" title="Değiştirilemez"></i></label>
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
                                <div class="bg-light p-3 rounded border shadow-sm">
                                    <label class="form-label fw-bold text-danger small mb-1"><i class="fas fa-key me-1"></i> İşlem Onayı İçin Mevcut Şifreniz</label>
                                    <div class="input-group">
                                        <input type="password" name="dogrulama_sifresi" class="form-control border-danger" required placeholder="Onay şifreniz">
                                        <button type="submit" class="btn btn-primary px-4 fw-bold">Bilgileri Kaydet ve Kilitle</button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </fieldset>
                        </form>

                        <?php elseif($activeTab == 'teklifayar'): ?>
                        <!-- 2. TEKLİF AYARLARI -->
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-file-invoice text-info me-2"></i> Teklif Şablon Ayarları</h5>
                        <form method="POST">
                            <input type="hidden" name="update_teklif_ayar" value="1">
                            <fieldset <?= ($my_role != 'admin') ? 'disabled' : '' ?>>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small">Teklif Şartları ve Koşulları</label>
                                    <div class="form-text mb-2">Müşteriye gönderilen tekliflerin üst kısmında yer alacak varsayılan açıklamadır.</div>
                                    <textarea name="teklif_sartlari" class="form-control" rows="6"><?= htmlspecialchars($firma['teklif_sartlari'] ?? '') ?></textarea>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold small">Teklif Alt Bilgi (Footer)</label>
                                    <div class="form-text mb-2">Banka hesap bilgilerinizi veya teşekkür mesajınızı buraya yazabilirsiniz.</div>
                                    <input type="text" name="teklif_alt_bilgi" class="form-control" value="<?= htmlspecialchars($firma['teklif_alt_bilgi'] ?? '') ?>" placeholder="Örn: Bizi tercih ettiğiniz için teşekkür ederiz. İBAN: TR...">
                                </div>
                                <?php if($my_role == 'admin'): ?>
                                    <button type="submit" class="btn btn-primary px-4 shadow-sm"><i class="fas fa-save me-2"></i>Şablonu Kaydet</button>
                                <?php else: ?>
                                    <div class="alert alert-warning py-2 small shadow-sm"><i class="fas fa-lock me-2"></i>Bu ayarları sadece firma yöneticisi değiştirebilir.</div>
                                <?php endif; ?>
                            </fieldset>
                        </form>

                        <?php elseif($activeTab == 'sozlesmeayar'): ?>
                        <!-- 3. SÖZLEŞME ŞABLONU -->
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-file-contract text-warning me-2"></i> Sözleşme Maddeleri</h5>
                        </div>
                        <div class="alert alert-info small border-0 shadow-sm">
                            <i class="fas fa-info-circle me-1"></i> Burada belirlediğiniz sözleşme maddeleri, yeni bir sözleşme oluştururken varsayılan (otomatik) olarak taslağa eklenecektir.
                        </div>
                        <form method="POST">
                            <input type="hidden" name="update_sozlesme_ayar" value="1">
                            <fieldset <?= ($my_role != 'admin') ? 'disabled' : '' ?>>
                                <div class="mb-4">
                                    <textarea name="sozlesme_maddeleri" class="form-control font-monospace" style="font-size: 0.85rem;" rows="15" placeholder="MADDE 1: Taraflar...&#10;MADDE 2: Hizmet Bedeli..."><?= htmlspecialchars($firma['sozlesme_maddeleri'] ?? '') ?></textarea>
                                </div>
                                <?php if($my_role == 'admin'): ?>
                                    <button type="submit" class="btn btn-primary px-4 shadow-sm"><i class="fas fa-save me-2"></i>Sözleşme Taslağını Kaydet</button>
                                <?php else: ?>
                                    <div class="alert alert-warning py-2 small shadow-sm"><i class="fas fa-lock me-2"></i>Sözleşme maddelerini sadece firma yöneticisi değiştirebilir.</div>
                                <?php endif; ?>
                            </fieldset>
                        </form>

                        <?php elseif($activeTab == 'kullanicilar'): ?>
                        <!-- 4. EKİP YÖNETİMİ -->
                        <?php if($my_role == 'admin'): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-users text-success me-2"></i> Ekip ve Alt Kullanıcılar</h5>
                            <button class="btn btn-sm btn-success shadow-sm px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#modalUserEkle"><i class="fas fa-plus me-1"></i> Yeni Personel Ekle</button>
                        </div>
                        
                        <div class="table-responsive border rounded bg-white">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light text-muted small">
                                    <tr>
                                        <th class="ps-3 border-0">Personel Adı</th>
                                        <th class="border-0">Giriş E-Postası / K.Adı</th>
                                        <th class="border-0">Yetki Rolü</th>
                                        <th class="text-end pe-3 border-0">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($subUsers as $u): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($u['ad_soyad'] ?: 'İsimsiz') ?></span>
                                            <?php if($u['id'] == $user_id) echo '<span class="badge bg-primary ms-2 rounded-pill" style="font-size:10px;">Sen</span>'; ?>
                                        </td>
                                        <td>
                                            <div class="small fw-bold"><i class="fas fa-envelope text-muted me-1"></i> <?= htmlspecialchars($u['email']) ?></div>
                                            <div class="small text-muted">@<?= htmlspecialchars($u['kullanici_adi']) ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                                if($u['rol']=='admin') echo '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger">Yönetici (Admin)</span>';
                                                elseif($u['rol']=='personel') echo '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary">Personel</span>';
                                                elseif($u['rol']=='ajanda') echo '<span class="badge bg-info bg-opacity-10 text-info border border-info">Ajanda (Kısıtlı)</span>';
                                            ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <?php if($u['id'] != $user_id): ?>
                                            <a href="?tab=kullanicilar&sil_user=<?= $u['id'] ?>" onclick="return confirm('Bu personelin sisteme erişimini kalıcı olarak silmek istediğinize emin misiniz?')" class="btn btn-sm btn-outline-danger"><i class="fas fa-user-minus"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <?php elseif($activeTab == 'profil'): ?>
                        <!-- 5. KİŞİSEL PROFİLİM -->
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-user-edit text-dark me-2"></i> Kişisel Profilim</h5>
                        <div class="alert alert-info small border-0 shadow-sm">
                            <i class="fas fa-lock me-1"></i> Güvenlik politikaları gereği profil bilgileriniz (Kullanıcı adı, Ad-Soyad, E-Posta, Telefon) kilitlenmiştir. Değişiklik için destek talebi oluşturunuz.
                        </div>
                        <form>
                            <fieldset disabled>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Sisteme Giriş E-Postası</label>
                                        <input type="email" class="form-control input-disabled-custom" value="<?= htmlspecialchars($user['email']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Kullanıcı Adı</label>
                                        <input type="text" class="form-control input-disabled-custom" value="<?= htmlspecialchars($user['kullanici_adi']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Ad Soyad</label>
                                        <input type="text" class="form-control input-disabled-custom" value="<?= htmlspecialchars($user['ad_soyad']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Cep Telefonu</label>
                                        <input type="text" class="form-control input-disabled-custom" value="<?= htmlspecialchars($user['telefon'] ?? '') ?>">
                                    </div>
                                </div>
                            </fieldset>
                        </form>

                        <?php elseif($activeTab == 'sifre'): ?>
                        <!-- 6. GÜVENLİK VE ŞİFRE -->
                        <h5 class="fw-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-shield-alt text-danger me-2"></i> Şifre ve Güvenlik</h5>
                        <form method="POST">
                            <input type="hidden" name="update_sifre" value="1">
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-muted">Mevcut Şifreniz</label>
                                <input type="password" name="eski_sifre" class="form-control shadow-xs" required placeholder="Şu an kullandığınız şifre">
                            </div>
                            
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Yeni Şifre</label>
                                    <input type="password" name="yeni_sifre" class="form-control shadow-xs" required minlength="8" placeholder="En az 8 karakter">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Yeni Şifre (Tekrar)</label>
                                    <input type="password" name="yeni_sifre_tekrar" class="form-control shadow-xs" required minlength="8" placeholder="Yeni şifrenizi doğrulayın">
                                </div>
                            </div>
                            
                            <div class="alert alert-light border small text-muted py-2 mb-4 shadow-sm">
                                <i class="fas fa-info-circle text-primary me-1"></i> Yeni şifreniz <b>en az 8 karakter</b> uzunluğunda olmalı; <b>harf ve rakam</b> içermelidir.
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-danger px-4 shadow-sm fw-bold"><i class="fas fa-key me-2"></i>Şifremi Değiştir</button>
                            </div>
                        </form>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: YENİ PERSONEL EKLE -->
    <?php if($my_role == 'admin'): ?>
    <div class="modal fade" id="modalUserEkle" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow">
                <form method="POST">
                    <input type="hidden" name="add_user" value="1">
                    <div class="modal-header bg-success text-white border-0">
                        <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>Yeni Ekip Üyesi</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info small py-2 mb-4 shadow-sm">
                            Personelinize e-posta ve şifre bilgilerini ileterek sisteme giriş yapmasını sağlayabilirsiniz.
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Ad Soyad <span class="text-danger">*</span></label>
                            <input type="text" name="u_adsoyad" class="form-control bg-light shadow-xs" required placeholder="Personel Adı">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Giriş E-Posta Adresi <span class="text-danger">*</span></label>
                            <input type="email" name="u_email" class="form-control bg-light shadow-xs" required placeholder="personel@firma.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Geçici Şifre <span class="text-danger">*</span></label>
                            <input type="text" name="u_sifre" class="form-control bg-light shadow-xs" value="<?= rand(100000, 999999) ?>" required>
                            <div class="form-text small">Otomatik üretilmiştir, isterseniz değiştirebilirsiniz.</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-primary">Yetki / Panel Rolü</label>
                            <select name="u_rol" class="form-select bg-light shadow-xs">
                                <option value="personel">Personel (Müşteri & Kasa Görebilir)</option>
                                <option value="ajanda">Sadece Ajanda (Takvim Görebilir)</option>
                                <option value="admin">Yönetici (Tam Yetki)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-secondary text-dark bg-white" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">Kullanıcıyı Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include 'partials/footer_yonetim.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>