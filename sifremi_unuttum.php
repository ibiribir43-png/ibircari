<?php
// Olası hataları ekranda görebilmek için hata gösterimini açıyoruz
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'baglanti.php';

// Fonksiyonları dahil ediyoruz
$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

$mesaj = "";
$mesajTuru = "";
$islem_basarili = false;

// --- CLOUDFLARE TURNSTILE AYARLARI ---
$turnstile_site_key = '0x4AAAAAACoCk2oQVfMv2qCZ'; // KENDİ SİTE ANAHTARINI GİR
$turnstile_secret_key = '0x4AAAAAACoCkxvfSwpqTTTB_glN0RJi6pY';

// Rastgele şifre üretme fonksiyonu (SMS için, burada da güvenlik kurallarına uygun bir geçici şifre üretelim)
function rastgeleSifreUret($uzunluk = 8) {
    $kucukler = 'abcdefghijklmnopqrstuvwxyz';
    $buyukler = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $rakamlar = '0123456789';
    $ozeller = '!@#$*';
    
    // Her türden en az bir karakter garanti olsun
    $sifre = $kucukler[rand(0, 25)] . $buyukler[rand(0, 25)] . $rakamlar[rand(0, 9)] . $ozeller[rand(0, 4)];
    $tum_karakterler = $kucukler . $buyukler . $rakamlar . $ozeller;
    
    for($i = 4; $i < $uzunluk; $i++) {
        $sifre .= $tum_karakterler[rand(0, strlen($tum_karakterler) - 1)];
    }
    
    return str_shuffle($sifre);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $giris_bilgisi = trim($_POST['giris_bilgisi']);
    $sifirlama_yontemi = $_POST['sifirlama_yontemi'] ?? 'email';

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
        $mesaj = "Lütfen robot olmadığınızı doğrulayın.";
        $mesajTuru = "danger";
        if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Güvenlik Testi", "Şifre sıfırlama denemesinde robot testi geçilemedi. Bilgi: $giris_bilgisi");
    } else {
        try {
            $kontrol = $db->prepare("SELECT id, firma_id, ad_soyad, kullanici_adi, email, telefon, son_sms_tarihi FROM yoneticiler WHERE email = ? OR telefon = ? OR kullanici_adi = ?");
            $kontrol->execute([$giris_bilgisi, $giris_bilgisi, $giris_bilgisi]);
            $kullanici = $kontrol->fetch(PDO::FETCH_ASSOC);

            if ($kullanici) {
                $tarih_simdi = date('Y-m-d H:i:s');

                if ($sifirlama_yontemi === 'sms') {
                    if (empty($kullanici['telefon'])) {
                        $mesaj = "Hesabınıza kayıtlı bir telefon numarası bulunamadı. Lütfen e-posta yöntemini kullanın.";
                        $mesajTuru = "warning";
                    } else {
                        // 5 Dakika Limiti Kontrolü
                        $son_sms = strtotime($kullanici['son_sms_tarihi'] ?? '2000-01-01');
                        $simdi = time();
                        $fark_dakika = floor(($simdi - $son_sms) / 60);

                        if ($fark_dakika < 5) {
                            $kalan_sure = 5 - $fark_dakika;
                            $mesaj = "Yeni bir SMS istemeden önce lütfen <b>$kalan_sure dakika</b> bekleyin.";
                            $mesajTuru = "warning";
                            if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("SMS Limiti", "Kullanıcı süre dolmadan SMS istedi.", $kullanici['firma_id'], $kullanici['id']);
                        } else {
                            // Şifre Üret ve Veritabanını Güncelle
                            $yeni_sifre = rastgeleSifreUret();
                            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
                            $yeni_sifre_hash = password_hash($yeni_sifre, $algo);

                            $guncelle = $db->prepare("UPDATE yoneticiler SET sifre = ?, son_sms_tarihi = ?, sifre_sifirlama_kodu = NULL WHERE id = ?");
                            $guncelle->execute([$yeni_sifre_hash, $tarih_simdi, $kullanici['id']]);

                            $sms_mesaj = "Sayin " . $kullanici['ad_soyad'] . ", ibiR Cari sistemine giris icin gecici sifreniz: " . $yeni_sifre . " Lutfen giris yaptiktan sonra sifrenizi degistiriniz.";
                            
                            // SMS Gönderme
                            if (function_exists('netgsm_sms_gonder')) {
                                $sms_sonuc = netgsm_sms_gonder($kullanici['telefon'], $sms_mesaj);
                                if ($sms_sonuc['status'] === true) {
                                    $mesaj = "Geçici şifreniz <b>" . htmlspecialchars(substr($kullanici['telefon'], 0, 4) . "***" . substr($kullanici['telefon'], -2)) . "</b> numaralı telefonunuza SMS olarak gönderildi.";
                                    $mesajTuru = "success";
                                    $islem_basarili = true;
                                    if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Şifre Sıfırlama (SMS)", "Kullanıcıya SMS ile yeni şifre gönderildi.", $kullanici['firma_id'], $kullanici['id']);
                                } else {
                                    $mesaj = "SMS gönderilemedi. Hata: " . htmlspecialchars($sms_sonuc['message']);
                                    $mesajTuru = "danger";
                                    if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("SMS Hatası", "Sıfırlama SMS'i gönderilemedi. Sebep: " . $sms_sonuc['message'], $kullanici['firma_id'], $kullanici['id']);
                                }
                            } else {
                                 $mesaj = "SMS altyapısı bulunamadı. (Test Modu - Yeni Şifre: <b>$yeni_sifre</b>)";
                                 $mesajTuru = "success";
                                 $islem_basarili = true;
                            }
                        }
                    }

                } else {
                    // E-POSTA İLE SIFIRLAMA LİNKİ GÖNDERME
                    $kod = md5(uniqid(rand(), true));
                    
                    $guncelle = $db->prepare("UPDATE yoneticiler SET sifre_sifirlama_kodu = ?, sifre_sifirlama_tarihi = ? WHERE id = ?");
                    $guncelle->execute([$kod, $tarih_simdi, $kullanici['id']]);
                    
                    $siteUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
                    $link = "$siteUrl/sifre_sifirla.php?kod=$kod&email=" . urlencode($kullanici['email']);
                    
                    $konu = "Şifre Sıfırlama Talebi - ibiR Cari";
                    $icerik = "Merhaba " . htmlspecialchars($kullanici['ad_soyad']) . ",<br><br>Şifrenizi sıfırlamak için lütfen aşağıdaki bağlantıya tıklayın:<br><br><a href='$link' style='padding: 10px 20px; background: #4e73df; color: white; text-decoration: none; border-radius: 5px; display: inline-block;'>Şifremi Sıfırla</a><br><br>Bu bağlantı 24 saat geçerlidir.";
                    
                    if (function_exists('sistem_mail_gonder')) {
                        $mail_sonuc = sistem_mail_gonder($kullanici['email'], $konu, $icerik);
                        if ($mail_sonuc['status']) {
                            $mesaj = "Şifre sıfırlama bağlantısı <b>" . htmlspecialchars(substr($kullanici['email'], 0, 3) . "***@" . explode('@', $kullanici['email'])[1]) . "</b> adresine e-posta olarak gönderildi.";
                            $mesajTuru = "success";
                            $islem_basarili = true;
                            if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Şifre Sıfırlama (Mail)", "Kullanıcıya şifre sıfırlama bağlantısı iletildi.", $kullanici['firma_id'], $kullanici['id']);
                        } else {
                             $mesaj = "E-posta gönderilemedi. Hata: " . htmlspecialchars($mail_sonuc['message']);
                             $mesajTuru = "danger";
                             if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Mail Hatası", "Sıfırlama maili gönderilemedi. Sebep: " . $mail_sonuc['message'], $kullanici['firma_id'], $kullanici['id']);
                        }
                    } else {
                        @mail($kullanici['email'], $konu, strip_tags($icerik), "From: noreply@ibircari.xyz\r\nContent-type: text/html; charset=utf-8");
                        $mesaj = "Sıfırlama linki e-posta adresinize gönderildi.<br><br><strong>🚧 Geliştirici Modu Linki:</strong> <a href='$link' class='text-dark'>Şifreyi Sıfırla</a>";
                        $mesajTuru = "success";
                        $islem_basarili = true;
                    }
                }
            } else {
                $mesaj = "Sistemde <b>" . htmlspecialchars($giris_bilgisi) . "</b> (Kullanıcı adı, telefon veya e-posta) ile eşleşen bir üyelik bulunamadı.";
                $mesajTuru = "danger";
                if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Hatalı Sıfırlama Talebi", "Böyle bir hesap bulunamadı: $giris_bilgisi");
            }
        } catch (PDOException $e) {
            $mesaj = "Sistem Veritabanı Hatası: " . $e->getMessage();
            $mesajTuru = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifremi Unuttum | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; display: flex; flex-direction: column; min-height: 100vh; }
        .navbar { padding: 15px 0; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .navbar-brand { font-weight: 800; font-size: 1.5rem; color: #2c3e50 !important; }
        
        .main-content { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 15px; margin-top: 60px; }
        
        .forgot-card { background: white; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.05); width: 100%; max-width: 450px; overflow: hidden; border: 1px solid #edf2f9; }
        .forgot-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 20px; text-align: center; color: white; }
        .forgot-icon { font-size: 2.5rem; margin-bottom: 10px; }
        
        .form-control, .form-select { padding: 12px 15px; border-radius: 10px; background-color: #f8f9fa; border: 1px solid #e9ecef; }
        .form-control:focus, .form-select:focus { box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2); border-color: #667eea; background-color: #fff; }
        
        .btn-submit { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: 600; padding: 12px; border-radius: 10px; border: none; transition: all 0.3s; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3); color: white; }
        
        .turnstile-container { display: flex; justify-content: center; margin-bottom: 1rem; }
        footer { background-color: #2c3e50; color: white; padding: 20px 0; margin-top: auto; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-layer-group me-2 text-primary"></i>ibiR Cari</a>
            <div class="ms-auto">
                <a href="login.php" class="btn btn-outline-dark rounded-pill px-4 fw-bold border-2 btn-sm"><i class="fas fa-sign-in-alt me-2"></i>Giriş Yap</a>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <div class="forgot-card fade-in-up">
            
            <div class="forgot-header">
                <i class="fas fa-unlock-alt forgot-icon"></i>
                <h4 class="fw-bold mb-0">Şifremi Unuttum</h4>
                <p class="text-white-50 small mb-0 mt-2">Hesabınızı kurtarmak için bilgilerinizi girin.</p>
            </div>
            
            <div class="card-body p-4 p-md-4">
                
                <?php if($mesaj): ?>
                    <div class="alert alert-<?= $mesajTuru ?> py-3 text-center small fw-semibold border-0 rounded-3 shadow-sm mb-4">
                        <?php if($mesajTuru == 'danger'): ?><i class="fas fa-exclamation-triangle me-2"></i>
                        <?php elseif($mesajTuru == 'success'): ?><i class="fas fa-check-circle me-2"></i>
                        <?php else: ?><i class="fas fa-info-circle me-2"></i><?php endif; ?>
                        <?= $mesaj ?>
                    </div>
                <?php endif; ?>

                <?php if($islem_basarili): ?>
                    <div class="text-center mt-3">
                        <p class="text-muted small">Eğer e-posta yöntemini seçtiyseniz gereksiz (spam) kutusunu kontrol etmeyi unutmayın.</p>
                        <a href="login.php" class="btn btn-outline-primary fw-bold px-4 rounded-pill mt-2">Giriş Ekranına Dön</a>
                        
                        <div class="mt-4 pt-3 border-top">
                            <p class="small text-muted mb-2">E-posta/SMS ulaşmadı mı?</p>
                            <a href="sifremi_unuttum.php" class="text-decoration-none fw-bold small text-secondary" id="retryLink" style="pointer-events: none; opacity: 0.5;">
                                <i class="fas fa-redo me-1"></i> Tekrar Gönder (<span id="timer">60</span> sn)
                            </a>
                        </div>
                    </div>
                    
                    <script>
                        let timeLeft = 60;
                        const timerSpan = document.getElementById('timer');
                        const retryLink = document.getElementById('retryLink');
                        
                        const countdown = setInterval(() => {
                            timeLeft--;
                            timerSpan.textContent = timeLeft;
                            if (timeLeft <= 0) {
                                clearInterval(countdown);
                                retryLink.style.pointerEvents = 'auto';
                                retryLink.style.opacity = '1';
                                retryLink.classList.remove('text-secondary');
                                retryLink.classList.add('text-primary');
                                retryLink.innerHTML = '<i class="fas fa-redo me-1"></i> Tekrar Gönder';
                            }
                        }, 1000);
                    </script>

                <?php else: ?>
                <form method="POST" id="forgotForm">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted ms-1">E-Posta, Telefon veya Kullanıcı Adı</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0 bg-light"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" name="giris_bilgisi" class="form-control border-start-0 ps-0" required placeholder="Sistemde kayıtlı bilginiz" value="<?= htmlspecialchars($_POST['giris_bilgisi'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted ms-1">Sıfırlama Yöntemi</label>
                        <select name="sifirlama_yontemi" class="form-select fw-semibold text-dark">
                            <option value="email" <?= (isset($_POST['sifirlama_yontemi']) && $_POST['sifirlama_yontemi'] == 'email') ? 'selected' : '' ?>>📧 E-Posta ile Link Gönder</option>
                            <option value="sms" <?= (isset($_POST['sifirlama_yontemi']) && $_POST['sifirlama_yontemi'] == 'sms') ? 'selected' : '' ?>>📱 SMS ile Geçici Şifre Gönder</option>
                        </select>
                        <div class="form-text small text-muted mt-1">SMS seçeneği için hesabınızda telefon numaranızın kayıtlı olması gereklidir.</div>
                    </div>

                    <div class="turnstile-container">
                        <div class="cf-turnstile" data-sitekey="<?= $turnstile_site_key ?>" data-theme="light"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-submit w-100 shadow-sm mt-1" id="submitBtn">
                        <i class="fas fa-paper-plane me-2"></i> Şifremi Sıfırla
                    </button>
                    
                    <div class="text-center mt-4 pt-3 border-top">
                        <a href="login.php" class="text-secondary fw-bold small text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i> Giriş Ekranına Dön
                        </a>
                    </div>
                </form>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <footer>
        <div class="container text-center small text-white-50">
            &copy; <?= date('Y') ?> ibiR Cari Platformu. Tüm hakları saklıdır.
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('forgotForm');
            if(form) {
                form.addEventListener('submit', function() {
                    const btn = document.getElementById('submitBtn');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Lütfen Bekleyin...';
                });
            }
            
            const el = document.querySelector('.fade-in-up');
            if(el) {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                setTimeout(() => {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, 100);
            }
        });
    </script>
</body>
</html>