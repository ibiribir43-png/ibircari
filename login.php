<?php
session_start();
require_once 'baglanti.php';

// Zaten giriş yapmışsa yönlendir
if (isset($_SESSION['kullanici_id'])) {
    $rol = $_SESSION['rol'];
    if ($rol == 'super_admin' || $_SESSION['firma_id'] == 'IBIR-4247-ADMIN') { header("Location: ibir99ibir11/0i1b2i3r/dashboard.php"); } 
    elseif ($rol == 'ajanda') { header("Location: takvim.php"); } 
    else { header("Location: anasayfa.php"); }
    exit;
}

// --- CLOUDFLARE TURNSTILE AYARLARI ---
$turnstile_site_key = '0x4AAAAAACoCk2oQVfMv2qCZ'; // KENDİ SİTE ANAHTARINI GİR
$turnstile_secret_key = '0x4AAAAAACoCkxvfSwpqTTTB_glN0RJi6pY'; 

$hata = "";

// GARANTİLİ LOG FONKSİYONU (Bu dosya için özel)
function girisLogKaydet($db, $islem, $detay, $firma_id = null, $kullanici_id = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';
        $stmt = $db->prepare("INSERT INTO sistem_loglari (firma_id, kullanici_id, islem, detay, ip_adresi) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$firma_id, $kullanici_id, $islem, $detay, $ip]);
    } catch (Exception $e) {
        // Hata verse bile sistemi çökertme
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $giris_bilgisi = trim($_POST['giris_bilgisi']); 
    $sifre_girilen = $_POST['sifre'];
    
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
        $hata = "Lütfen robot olmadığınızı doğrulayın.";
        girisLogKaydet($db, "Giriş Engellendi", "Robot Testi Başarısız: $giris_bilgisi");
    } else {
        // 2. Kullanıcıyı Bul (Email, Kullanıcı Adı veya Telefon ile)
        $sorgu = $db->prepare("SELECT * FROM yoneticiler WHERE email = ? OR kullanici_adi = ? OR telefon = ?");
        $sorgu->execute([$giris_bilgisi, $giris_bilgisi, $giris_bilgisi]);
        $kullanici = $sorgu->fetch(PDO::FETCH_ASSOC);

        if ($kullanici) {
            $sifre_dogru_mu = false;
            $sifre_guncelle_gerekir_mi = false;
            $zayif_sifre_mi = false;

            // ŞİFRE GÜVENLİK (KARMAŞIKLIK) KONTROLÜ (Geçiş için MD5'leri engellemek istersek)
            $sifre_guvenli_mi = (
                strlen($sifre_girilen) >= 8 &&
                preg_match("#[0-9]+#", $sifre_girilen) &&
                preg_match("#[a-z]+#", $sifre_girilen) &&
                preg_match("#[A-Z]+#", $sifre_girilen) &&
                preg_match("#\W+#", $sifre_girilen)
            );

            // A) YENİ SİSTEM (Argon2id veya Bcrypt) İLE KONTROL
            if (password_verify($sifre_girilen, $kullanici['sifre'])) {
                $sifre_dogru_mu = true;
                $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
                if (password_needs_rehash($kullanici['sifre'], $algo)) {
                    $sifre_guncelle_gerekir_mi = true;
                }
            } 
            // B) ESKİ SİSTEM (MD5) İLE KONTROL
            elseif (md5($sifre_girilen) === $kullanici['sifre']) {
                $sifre_dogru_mu = true;
                
                // Güçlü şifre değilse değiştirmeye zorla (Şu anlık MD5 olanların hepsini şifrelemeye taşıyoruz)
                if ($sifre_guvenli_mi || true) { // İstersen || true diyerek zorunluluğu esnetebilirsin, ben esnetiyorum herkes girebilsin diye
                    $sifre_guncelle_gerekir_mi = true;
                } else {
                    $zayif_sifre_mi = true;
                    $sifre_dogru_mu = false; 
                }
            }

            if ($zayif_sifre_mi) {
                $hata = "Güvenliğiniz için şifreleme altyapımızı güncelledik. Mevcut şifreniz güvenlik standartlarımızı (Büyük/Küçük harf, Rakam ve İşaret) karşılamıyor. Lütfen <b>'Şifremi Unuttum'</b> kısmından şifrenizi yenileyin.";
                girisLogKaydet($db, "Zayıf Şifre Engeli", "Kullanıcı eski MD5 şifresiyle girmeye çalıştı ancak şifre kurallara uymadığı için engellendi.", $kullanici['firma_id'], $kullanici['id']);
            }
            elseif ($sifre_dogru_mu) {
                
                // SESSİZCE ŞİFRE YÜKSELTME
                if ($sifre_guncelle_gerekir_mi) {
                    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
                    $yeni_hash = password_hash($sifre_girilen, $algo);
                    $db->prepare("UPDATE yoneticiler SET sifre = ? WHERE id = ?")->execute([$yeni_hash, $kullanici['id']]);
                    girisLogKaydet($db, "Güvenlik Yükseltmesi", "Kullanıcının şifresi başarıyla Argon2id algoritmasına yükseltildi.", $kullanici['firma_id'], $kullanici['id']);
                }

                // 3. Email Onay Kontrolü
                if ($kullanici['email_onayli'] == 0) {
                    $hata = "Hesabınız henüz doğrulanmamış! Lütfen e-postanızı kontrol edin.";
                    girisLogKaydet($db, "Giriş Başarısız", "Onaysız Hesap ile giriş denemesi.", $kullanici['firma_id'], $kullanici['id']);
                } else {
                    // 4. Firma Durumu ve Abonelik Kontrolü
                    $firmaSorgu = $db->prepare("SELECT durum, abonelik_bitis, firma_adi FROM firmalar WHERE id = ?");
                    $firmaSorgu->execute([$kullanici['firma_id']]);
                    $firma = $firmaSorgu->fetch(PDO::FETCH_ASSOC);

                    $girisIzni = true;
                    
                    if ($kullanici['rol'] != 'super_admin' && $kullanici['firma_id'] != 'IBIR-4247-ADMIN') {
                        if (!$firma) {
                            $girisIzni = false;
                            $hata = "Bağlı olduğunuz firma sistemde bulunamadı.";
                        } elseif ($firma['durum'] == 0) {
                            $girisIzni = false;
                            $hata = "Firma hesabınız dondurulmuştur. Lütfen sistem yöneticisiyle iletişime geçin.";
                        } elseif ($firma['abonelik_bitis'] && $firma['abonelik_bitis'] < date('Y-m-d')) {
                            $girisIzni = false;
                            $hata = "Abonelik süreniz dolmuştur! Sisteme erişmek için lütfen paketinizi yenileyin.";
                            girisLogKaydet($db, "Giriş Engellendi", "Abonelik süresi dolduğu için giriş reddedildi.", $kullanici['firma_id'], $kullanici['id']);
                        }
                    }

                    // 5. GİRİŞ BAŞARILI
                    if ($girisIzni) {
                        $_SESSION['kullanici_id'] = $kullanici['id'];
                        $_SESSION['ad_soyad'] = $kullanici['ad_soyad'] ?: $kullanici['kullanici_adi'];
                        $_SESSION['rol'] = $kullanici['rol'];
                        $_SESSION['firma_id'] = $kullanici['firma_id'];
                        $_SESSION['firma_adi'] = $firma['firma_adi'] ?? 'Yönetim';

                        // BURASI LOGUN GARANTİ YAZILDIĞI YERDİR (Hangi kullanıcı veya alt kullanıcı girerse girsin yazar)
                        girisLogKaydet($db, "Sisteme Giriş", "Kullanıcı panele başarıyla giriş yaptı.", $_SESSION['firma_id'], $_SESSION['kullanici_id']);

                        if ($kullanici['rol'] == 'super_admin' || $kullanici['firma_id'] == 'IBIR-4247-ADMIN') { 
                            header("Location: ibir99ibir11/0i1b2i3r/dashboard.php"); 
                        }
                        elseif ($kullanici['rol'] == 'ajanda') { 
                            header("Location: takvim.php"); 
                        }
                        else { 
                            header("Location: anasayfa.php"); 
                        }
                        exit;
                    }
                }
            } else {
                $hata = "Şifreniz hatalı!";
                girisLogKaydet($db, "Hatalı Şifre", "Yanlış şifre girildi.", $kullanici['firma_id'] ?? null, $kullanici['id'] ?? null);
            }
        } else {
            $hata = "Bu e-posta, telefon veya kullanıcı adı ile kayıtlı hesap bulunamadı!";
            girisLogKaydet($db, "Bilinmeyen Kullanıcı", "Kayıtsız bir hesap ile ($giris_bilgisi) giriş denenmesi yapıldı.");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sisteme Giriş Yap | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    <style>
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh; display: flex; align-items: center; }
        .login-card { border: none; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); overflow: hidden; background: #fff; }
        .login-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 20px; text-align: center; color: white; }
        .login-icon { font-size: 3rem; margin-bottom: 15px; text-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .form-control { padding: 12px 20px; border-radius: 10px; border: 1px solid #e0e0e0; background-color: #f8f9fa; }
        .form-control:focus { box-shadow: none; border-color: #667eea; background-color: #fff; }
        .input-group-text { border-radius: 10px 0 0 10px; border: 1px solid #e0e0e0; background-color: #f8f9fa; }
        .btn-login { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: 600; padding: 12px; border-radius: 10px; border: none; transition: all 0.3s; }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4); color: white; }
        .back-link { position: absolute; top: 20px; left: 20px; color: white; text-decoration: none; font-weight: 500; transition: 0.2s; z-index: 10; }
        .back-link:hover { color: #f8f9fa; transform: translateX(-5px); }
        .turnstile-container { display: flex; justify-content: center; margin-bottom: 1rem; }
    </style>
</head>
<body>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-5">
                <div class="login-card position-relative">
                    
                    <a href="index.php" class="back-link"><i class="fas fa-arrow-left me-2"></i>Ana Sayfa</a>
                    
                    <div class="login-header">
                        <i class="fas fa-layer-group login-icon"></i>
                        <h3 class="fw-bold mb-0">ibiR Cari Platformu</h3>
                        <p class="text-white-50 mb-0 mt-1">Firma & Personel Girişi</p>
                    </div>
                    
                    <div class="card-body p-4 p-md-5">
                        
                        <h5 class="fw-bold text-center mb-4 text-dark">Sisteme Giriş Yap</h5>
                        
                        <?php if($hata): ?>
                            <div class="alert alert-danger py-3 text-center small mb-4 fw-semibold border-0 rounded-3" style="background-color: #ffe5e5; color: #d63031;">
                                <i class="fas fa-exclamation-triangle me-2 d-block fa-2x mb-2"></i><?php echo $hata; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted ms-1">E-Posta, Telefon veya Kullanıcı Adı</label>
                                <div class="input-group">
                                    <span class="input-group-text border-end-0"><i class="fas fa-user text-muted"></i></span>
                                    <input type="text" name="giris_bilgisi" class="form-control border-start-0 ps-0" placeholder="Kayıtlı bilginiz..." required value="<?= htmlspecialchars($_POST['giris_bilgisi'] ?? '') ?>">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center ms-1 mb-1">
                                    <label class="form-label small fw-bold text-muted mb-0">Şifre</label>
                                    <a href="sifremi_unuttum.php" class="text-primary small text-decoration-none fw-semibold">Şifremi Unuttum</a>
                                </div>
                                <div class="input-group">
                                    <span class="input-group-text border-end-0"><i class="fas fa-lock text-muted"></i></span>
                                    <input type="password" name="sifre" id="sifreInput" class="form-control border-start-0 border-end-0 ps-0" placeholder="••••••••" required>
                                    <button class="btn border border-start-0 bg-light text-muted" type="button" onclick="togglePassword()">
                                        <i class="fas fa-eye" id="eyeIcon"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="turnstile-container">
                                <div class="cf-turnstile" data-sitekey="<?= $turnstile_site_key ?>" data-theme="light"></div>
                            </div>
                            
                            <button type="submit" class="btn btn-login w-100 shadow-sm mt-2">
                                <i class="fas fa-sign-in-alt me-2"></i> Giriş Yap
                            </button>
                            
                        </form>
                        
                        <div class="text-center mt-4 pt-3 border-top">
                            <span class="text-muted small">Henüz hesabınız yok mu?</span>
                            <a href="kayit_ol.php" class="text-primary fw-bold small text-decoration-none ms-1">Hemen Kayıt Ol</a>
                        </div>
                        
                    </div>
                </div>
                
                <div class="text-center mt-4 text-muted small">
                    &copy; <?= date('Y') ?> ibiR Cari. Tüm hakları saklıdır.
                </div>
                
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            var passInput = document.getElementById("sifreInput");
            var eyeIcon = document.getElementById("eyeIcon");
            if (passInput.type === "password") {
                passInput.type = "text";
                eyeIcon.classList.remove("fa-eye");
                eyeIcon.classList.add("fa-eye-slash");
            } else {
                passInput.type = "password";
                eyeIcon.classList.remove("fa-eye-slash");
                eyeIcon.classList.add("fa-eye");
            }
        }
    </script>
</body>
</html>