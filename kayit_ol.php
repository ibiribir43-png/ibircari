<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'baglanti.php';

// Fonksiyonları dahil ediyoruz (Loglama ve Mail için)
$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

// PHPMailer yolunu eğer functions.php içinde hata verirse diye garantiye alalım
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$hata = "";
$basari = "";

// --- CLOUDFLARE TURNSTILE AYARLARI ---
// TODO: Canlıya alırken kendi Cloudflare anahtarlarınla değiştir!
$turnstile_site_key = '0x4AAAAAACoCk2oQVfMv2qCZ'; // Test amaçlıdır (Her zaman başarılı geçer)
$turnstile_secret_key = '0x4AAAAAACoCkxvfSwpqTTTB_glN0RJi6pY';

// --- ÖZEL FİRMA ID ÜRETME ---
function ozelFirmaIDUret($firmaAdi, $ad, $soyad) {
    $fHarf = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $firmaAdi), 0, 1));
    $aHarf = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $ad), 0, 1));
    $sHarf = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $soyad), 0, 1));
    $rastgeleHarf = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 3);
    $rastgeleSayi = rand(100, 999);
    return $fHarf . "-" . $aHarf . $sHarf . "-" . $rastgeleHarf . $rastgeleSayi;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. Cloudflare Turnstile Doğrulaması
    $token = $_POST['cf-turnstile-response'] ?? '';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret' => $turnstile_secret_key,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $turnstile_response = curl_exec($ch);
    curl_close($ch);
    
    $turnstile_data = json_decode($turnstile_response);
    
    if (!$turnstile_data || !$turnstile_data->success) {
        $hata = "Lütfen robot olmadığınızı (Güvenlik Testi) doğrulayın.";
    }

    // Form alanları kontrolü
    if(!$hata) {
        $required = ['firma_adi', 'ad', 'soyad', 'email', 'email_tekrar', 'telefon', 'sifre', 'sifre_tekrar'];
        foreach($required as $field) {
            if(!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                $hata = "Lütfen tüm alanları doldurun!";
                break;
            }
        }
    }
    
    if(!$hata) {
        $firma_adi = trim($_POST['firma_adi']);
        $ad = trim($_POST['ad']);
        $soyad = trim($_POST['soyad']);
        $yetkili_tam = $ad . " " . $soyad;
        
        $email = strtolower(trim($_POST['email']));
        $email_tekrar = strtolower(trim($_POST['email_tekrar']));
        
        if(empty($email)) { $hata = "Email adresi boş olamaz!"; }
        elseif(strpos($email, '@') === false) { $hata = "Email adresinde @ işareti olmalı!"; }
        elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) { $hata = "Geçersiz e-posta formatı!"; }
        elseif(strlen($email) < 6) { $hata = "E-posta çok kısa!"; }
        elseif($email !== $email_tekrar) { $hata = "E-posta adresleri birbiriyle uyuşmuyor!"; }
        
        $telefon = preg_replace('/\D/', '', trim($_POST['telefon']));
        if(!$hata && strlen($telefon) != 11) { $hata = "Telefon numarası 11 haneli olmalıdır! (05XXXXXXXXX)"; }
        elseif(!$hata && substr($telefon, 0, 2) != '05') { $hata = "Telefon numarası 05 ile başlamalıdır!"; }
        
        $sifre = $_POST['sifre'];
        $sifre_tekrar = $_POST['sifre_tekrar'];
        
        // --- ŞİFRE GÜVENLİK KONTROLLERİ ---
        if(!$hata) {
            if($sifre !== $sifre_tekrar) { 
                $hata = "Girdiğiniz şifreler birbiriyle eşleşmiyor!"; 
            } elseif(strlen($sifre) < 8) { 
                $hata = "Şifreniz en az 8 karakter olmalıdır!"; 
            } elseif(!preg_match("#[0-9]+#", $sifre)) {
                $hata = "Şifreniz en az bir rakam içermelidir!";
            } elseif(!preg_match("#[a-z]+#", $sifre)) {
                $hata = "Şifreniz en az bir küçük harf içermelidir!";
            } elseif(!preg_match("#[A-Z]+#", $sifre)) {
                $hata = "Şifreniz en az bir büyük harf içermelidir!";
            } elseif(!preg_match("#\W+#", $sifre)) {
                $hata = "Şifreniz en az bir özel karakter (!, @, #, $, vb.) içermelidir!";
            }
        }

        if(!$hata) {
            try {
                // Email Mükerrer Kontrolü (yoneticiler)
                $kontrol = $db->prepare("SELECT id FROM yoneticiler WHERE email = ?");
                $kontrol->execute([$email]);
                
                if ($kontrol->rowCount() > 0) {
                    $hata = "Bu e-posta adresi zaten sistemde kayıtlı!";
                } else {
                    // Email Mükerrer Kontrolü (firmalar)
                    $kontrolFirma = $db->prepare("SELECT id FROM firmalar WHERE email = ?");
                    $kontrolFirma->execute([$email]);
                    
                    if ($kontrolFirma->rowCount() > 0) {
                        $hata = "Bu e-posta adresi zaten bir firmaya kayıtlı!";
                    } else {
                        // Telefon Mükerrer Kontrolü
                        $kontrol2 = $db->prepare("SELECT id FROM yoneticiler WHERE telefon = ?");
                        $kontrol2->execute([$telefon]);
                        
                        if ($kontrol2->rowCount() > 0) {
                            $hata = "Bu telefon numarası zaten sistemde kayıtlı!";
                        } else {
                            $db->beginTransaction();

                            // Doğrulama Kodu 
                            $onayKodu = bin2hex(random_bytes(16));
                            $yeniFirmaID = ozelFirmaIDUret($firma_adi, $ad, $soyad);
                            
                            // 7 GÜN DENEME SÜRESİ
                            $bitis = date('Y-m-d', strtotime('+7 days'));

                            // DİNAMİK PAKET VE SMS LİMİTİ ÇEKİMİ
                            $paketSorgu = $db->query("SELECT id, sms_limiti FROM paketler WHERE is_trial = 1 LIMIT 1");
                            $trialPaket = $paketSorgu->fetch(PDO::FETCH_ASSOC);

                            $baslangicPaketId = $trialPaket ? $trialPaket['id'] : 1;
                            $baslangicSmsBakiyesi = $trialPaket ? (int)$trialPaket['sms_limiti'] : 0;

                            // FİRMA KAYDI (sms_bakiyesi YERİNE aylik_sms_limiti KULLANILIYOR VE son_abonelik_baslangic EKLENDİ)
                            $sorguFirma = $db->prepare("INSERT INTO firmalar (id, firma_adi, yetkili_ad_soyad, email, telefon, abonelik_bitis, paket_id, aylik_sms_limiti, son_abonelik_baslangic) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())");
                            $sorguFirma->execute([$yeniFirmaID, $firma_adi, $yetkili_tam, $email, $telefon, $bitis, $baslangicPaketId, $baslangicSmsBakiyesi]);

                            // YÖNETİCİ KAYDI - YENİ NESİL ŞİFRELEME (Argon2id)
                            $kadi = explode('@', $email)[0];
                            
                            // Şifreleme algoritması olarak Argon2id kullanıyoruz (Sunucu PHP sürümü 7.3+ olmalıdır)
                            // Eğer sunucu desteklemiyorsa fallback olarak PASSWORD_DEFAULT (Bcrypt) kullanır.
                            $sifreHash = password_hash($sifre, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);

                            $sorguYonetici = $db->prepare("
                                INSERT INTO yoneticiler 
                                (firma_id, kullanici_adi, email, sifre, ad_soyad, rol, telefon, email_onayli, email_onay_kodu) 
                                VALUES (?, ?, ?, ?, ?, 'admin', ?, 0, ?)
                            ");
                            $sorguYonetici->execute([$yeniFirmaID, $kadi, $email, $sifreHash, $yetkili_tam, $telefon, $onayKodu]);
                            
                            $yeniYoneticiId = $db->lastInsertId();

                            $db->commit();
                            
                            // LOG KAYDI: Yeni Firma Kaydı
                            if (function_exists('sistem_log_kaydet')) {
                                sistem_log_kaydet("Yeni Firma Kayıt", "Sistem üzerinden 7 günlük deneme sürümüyle kayıt olundu. Atanan SMS: $baslangicSmsBakiyesi", $yeniFirmaID, $yeniYoneticiId);
                            }

                            $basari = "Üyelik başarıyla oluşturuldu! Lütfen e-posta adresinizi kontrol edin. ✅";

                            // E-POSTA DOĞRULAMA MAİLİ GÖNDERİMİ
                            $siteUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
                            $link = "$siteUrl/dogrula.php?email=" . urlencode($email) . "&kod=$onayKodu";

                            $konu = 'Hesap Onayı - ibiR Cari';
                            $icerik = "Merhaba $yetkili_tam,<br><br>Sisteme başarıyla kayıt oldunuz ve <b>7 Günlük Deneme Paketiniz</b> aktif edildi! Paketinize tanımlı <b>$baslangicSmsBakiyesi adet hediye SMS</b> bakiyeniz bulunmaktadır.<br><br>Hesabınızı doğrulamak ve giriş yapmak için aşağıdaki bağlantıya tıklayın:<br><br><a href='$link' style='padding:10px 15px; background-color:#4e73df; color:white; text-decoration:none; border-radius:5px;'>Hesabımı Doğrula</a><br><br>Link 24 saat geçerlidir.";

                            if (function_exists('sistem_mail_gonder')) {
                                $mail_sonuc = sistem_mail_gonder($email, $konu, $icerik);
                                if ($mail_sonuc['status']) {
                                    $basari .= "<br>Onay maili gönderildi! Lütfen gereksiz (spam) kutunuzu da kontrol edin. 📧";
                                } else {
                                    $basari .= "<br>Mail gönderilemedi, Sistem yöneticisine başvurun: " . $mail_sonuc['message'];
                                }
                            } else {
                                $basari .= "<br>🚧 Mail fonksiyonu bulunamadı. Geliştirici Doğrulama Linki: <a href='$link'>Hesabı Doğrula</a>";
                            }
                        }
                    }
                }

            } catch (Exception $e) {
                if(isset($db)) { $db->rollBack(); }
                error_log("Kayıt hatası: " . $e->getMessage());
                $hata = "Kayıt sırasında bir hata oluştu: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayıt Ol - ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    
    <!-- CLOUDFLARE TURNSTILE SCRIPT -->
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        
        /* Navbar */
        .navbar { padding: 15px 0; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .navbar-brand { font-weight: 800; font-size: 1.5rem; color: #2c3e50 !important; }
        .nav-link { font-weight: 600; color: #555 !important; margin: 0 10px; }
        .nav-link:hover { color: #667eea !important; }

        /* Register Section */
        .register-section { padding: 80px 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
        .register-card { background: white; border-radius: 20px; padding: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .form-control { padding: 12px; background-color: #f8f9fa; border: 1px solid #eee; border-radius: 10px; font-size: 0.95rem; }
        .form-control:focus { box-shadow: 0 0 0 4px rgba(118, 75, 162, 0.1); border-color: #764ba2; background-color: #fff; }
        .btn-register { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; color: white; padding: 14px; font-weight: bold; width: 100%; border-radius: 10px; font-size: 1.1rem; transition: 0.3s; }
        .btn-register:hover { opacity: 0.9; transform: translateY(-3px); color: white; box-shadow: 0 10px 20px rgba(118, 75, 162, 0.3); }
        
        /* Footer */
        footer { background-color: #2c3e50; color: white; padding: 60px 0 30px 0; margin-top: 0; }
        footer a { color: #a0a0a0; text-decoration: none; transition: 0.3s; }
        footer a:hover { color: white; }
        
        .policy-content { max-height: 400px; overflow-y: auto; font-size: 0.85rem; line-height: 1.6; color: #555; text-align: justify; padding-right: 10px; }
        
        /* Hata mesajı */
        .alert-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ff4757 100%); color: white; border: none; }
        .alert-success { background: linear-gradient(135deg, #51cf66 0%, #2ecc71 100%); color: white; border: none; }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-layer-group me-2 text-primary"></i>ibiR Cari</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="index.php">Ana Sayfa</a></li>
                    <li class="nav-item ms-2">
                        <a href="login.php" class="btn btn-outline-dark rounded-pill px-4 fw-bold border-2">Giriş Yap</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- REGISTER SECTION -->
    <section class="register-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 col-xl-6">
                    
                    <div class="register-card mt-5">
                        <div class="text-center mb-4">
                            <h3 class="fw-bold text-dark">Hemen Başlayın 🚀</h3>
                            <p class="text-muted small">Kredi kartı gerekmez. 7 gün ücretsiz deneme.</p>
                        </div>

                        <?php if($hata): ?>
                            <div class="alert alert-danger text-center shadow-sm border-0 mb-4 py-3 rounded-3">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($hata); ?>
                            </div>
                        <?php endif; ?>

                        <?php if($basari): ?>
                            <div class="alert alert-success text-center shadow-sm border-0 mb-4 py-3 rounded-3">
                                <i class="fas fa-check-circle me-2 fa-2x d-block mb-2 text-white"></i>
                                <?php echo $basari; ?>
                            </div>
                            <div class="text-center mt-4">
                                <a href="login.php" class="btn btn-outline-primary rounded-pill px-5 fw-bold">Giriş Ekranına Git</a>
                            </div>
                        <?php else: ?>

                        <form method="POST" id="kayitForm" novalidate>
                            <!-- FİRMA BİLGİLERİ -->
                            <h6 class="text-secondary border-bottom pb-2 mb-3 text-uppercase small fw-bold">Firma Bilgileri</h6>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Firma Adı / Ünvanı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="firma_adi" value="<?= htmlspecialchars($_POST['firma_adi'] ?? '') ?>" required placeholder="Örn: Fotoğraf Stüdyom">
                            </div>

                            <!-- YETKİLİ BİLGİLERİ -->
                            <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4 text-uppercase small fw-bold">Yetkili Bilgileri</h6>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Ad <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="ad" value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>" required placeholder="Ahmet">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Soyad <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="soyad" value="<?= htmlspecialchars($_POST['soyad'] ?? '') ?>" required placeholder="Yılmaz">
                                </div>
                            </div>

                            <div class="row mb-2">
                                <div class="col-md-6">
                                     <label class="form-label small fw-bold text-muted">E-Posta Adresi <span class="text-danger">*</span></label>
                                     <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required placeholder="ornek@mail.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">E-Posta (Tekrar) <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" name="email_tekrar" value="<?= htmlspecialchars($_POST['email_tekrar'] ?? '') ?>" required placeholder="ornek@mail.com">
                                </div>
                            </div>
                            <div class="form-text text-end mb-3 text-primary small"><i class="fas fa-info-circle me-1"></i> Doğrulama kodu bu adrese gönderilecektir.</div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Telefon Numarası <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0"><i class="fas fa-phone text-muted"></i></span>
                                    <input type="tel" class="form-control border-start-0 ps-0" name="telefon" value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>" required 
                                           placeholder="05XXXXXXXXX (11 haneli)">
                                </div>
                                <div class="form-text text-end text-primary small">Örnek: 05551234567 (11 haneli, 05 ile başlamalı)</div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Şifre <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" class="form-control border-end-0" id="sifre" name="sifre" required placeholder="Yeni Şifreniz">
                                        <button class="btn btn-light border border-start-0" type="button" onclick="togglePassword('sifre', 'eye1')">
                                            <i class="fas fa-eye text-muted" id="eye1"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Şifre Tekrar <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" class="form-control border-end-0" id="sifre_tekrar" name="sifre_tekrar" required placeholder="Şifreyi Tekrarla">
                                        <button class="btn btn-light border border-start-0" type="button" onclick="togglePassword('sifre_tekrar', 'eye2')">
                                            <i class="fas fa-eye text-muted" id="eye2"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4 small text-muted border p-2 rounded bg-white">
                                <span class="fw-bold d-block mb-1"><i class="fas fa-shield-alt text-primary"></i> Şifre Kuralları:</span>
                                <ul class="mb-0 ps-3" style="font-size: 0.8rem;">
                                    <li>En az 8 karakter uzunluğunda olmalı.</li>
                                    <li>En az 1 büyük harf ve 1 küçük harf içermeli.</li>
                                    <li>En az 1 rakam içermeli.</li>
                                    <li>En az 1 özel karakter (!, @, #, $, vb.) içermeli.</li>
                                </ul>
                            </div>

                            <!-- CLOUDFLARE TURNSTILE ROBOT TESTİ -->
                            <div class="d-flex justify-content-center mb-3">
                                <div class="cf-turnstile" data-sitekey="<?= $turnstile_site_key ?>" data-theme="light"></div>
                            </div>

                            <div class="form-check mb-4 small">
                                <input class="form-check-input" type="checkbox" value="" id="sozlesme" required>
                                <label class="form-check-label text-muted" for="sozlesme">
                                    <a href="#" class="text-decoration-none fw-bold" data-bs-toggle="modal" data-bs-target="#modalKullanim">Kullanım koşullarını</a> ve 
                                    <a href="#" class="text-decoration-none fw-bold" data-bs-toggle="modal" data-bs-target="#modalGizlilik">gizlilik politikasını</a> okudum, kabul ediyorum.
                                </label>
                            </div>

                            <button type="submit" class="btn btn-register mb-3 shadow">Hesabımı Oluştur</button>
                            
                            <div class="text-center">
                                <span class="text-muted small">Zaten bir hesabınız var mı?</span> 
                                <a href="login.php" class="text-decoration-none fw-bold text-primary small">Giriş Yap</a>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h4 class="fw-bold mb-3">ibiR Cari</h4>
                    <p class="text-white-50 small">Türkiye'nin en hızlı büyüyen, esnaf dostu bulut tabanlı cari takip ve iş yönetim platformu.</p>
                </div>
                <div class="col-md-2 mb-4">
                    <h6 class="fw-bold text-white mb-3">Hızlı Linkler</h6>
                    <ul class="list-unstyled small text-white-50">
                        <li><a href="index.php">Ana Sayfa</a></li>
                        <li><a href="index.php#ozellikler">Özellikler</a></li>
                        <li><a href="index.php#fiyatlar">Paketler</a></li>
                    </ul>
                </div>
                <div class="col-md-2 mb-4">
                    <h6 class="fw-bold text-white mb-3">Yasal</h6>
                    <ul class="list-unstyled small text-white-50">
                        <li><a href="#" data-bs-toggle="modal" data-bs-target="#modalKullanim">Kullanım Şartları</a></li>
                        <li><a href="#" data-bs-toggle="modal" data-bs-target="#modalGizlilik">Gizlilik Politikası</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-4">
                    <h6 class="fw-bold text-white mb-3">İletişim</h6>
                    <ul class="list-unstyled small text-white-50">
                        <li>destek@ibircari.xyz</li>
                        <li>0850 123 45 67</li>
                    </ul>
                </div>
            </div>
            <div class="border-top border-secondary pt-4 mt-4 text-center small text-white-50">
                &copy; <?php echo date('Y'); ?> ibiR Yazılım A.Ş. Tüm hakları saklıdır.
            </div>
        </div>
    </footer>

    <!-- MODAL: KULLANIM KOŞULLARI -->
    <div class="modal fade" id="modalKullanim" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Kullanım Koşulları</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body policy-content">
                    <p><strong>1. Giriş ve Kabul</strong><br>Bu web sitesine erişerek...</p>
                    <p>Son Güncelleme: <?php echo date('d.m.Y'); ?></p>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary w-100" data-bs-dismiss="modal">Okudum</button></div>
            </div>
        </div>
    </div>

    <!-- MODAL: GİZLİLİK POLİTİKASI -->
    <div class="modal fade" id="modalGizlilik" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Gizlilik Politikası</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body policy-content">
                    <p><strong>1. Veri Toplama</strong><br>Kayıt işlemi sırasında...</p>
                    <p>Son Güncelleme: <?php echo date('d.m.Y'); ?></p>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary w-100" data-bs-dismiss="modal">Okudum</button></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Şifre Göster/Gizle
        function togglePassword(inputId, iconId) {
            var input = document.getElementById(inputId);
            var icon = document.getElementById(iconId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }

        // Form gönderiminde butonu disable et
        document.getElementById('kayitForm').addEventListener('submit', function(e) {
            if(!document.getElementById('sozlesme').checked) return;
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Kayıt Yapılıyor...';
        });
    </script>
</body>
</html>