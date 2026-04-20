<?php
session_start();
require_once 'baglanti.php';

// Fonksiyonları dahil ediyoruz (Loglama için)
$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

$mesaj = "";
$mesajTuru = "";
$kod_gecerli = false;

// --- CLOUDFLARE TURNSTILE AYARLARI ---
$turnstile_site_key = '0x4AAAAAACoCk2oQVfMv2qCZ'; // KENDİ SİTE ANAHTARINI GİR
$turnstile_secret_key = '0x4AAAAAACoCkxvfSwpqTTTB_glN0RJi6pY';

// 1. Link Kontrolü
if (isset($_GET['kod']) && isset($_GET['email'])) {
    $kod = trim($_GET['kod']);
    $email = trim($_GET['email']);

    $sorgu = $db->prepare("SELECT id, firma_id, kullanici_adi, sifre_sifirlama_tarihi FROM yoneticiler WHERE email = ? AND sifre_sifirlama_kodu = ?");
    $sorgu->execute([$email, $kod]);
    $kullanici = $sorgu->fetch(PDO::FETCH_ASSOC);

    if ($kullanici) {
        $talep_tarihi = strtotime($kullanici['sifre_sifirlama_tarihi']);
        $simdi = time();
        if (($simdi - $talep_tarihi) > (24 * 60 * 60)) {
             $mesaj = "Bu sıfırlama bağlantısının süresi (24 Saat) dolmuş. Lütfen yeni bir talep oluşturun.";
             $mesajTuru = "warning";
             $db->prepare("UPDATE yoneticiler SET sifre_sifirlama_kodu = NULL WHERE id = ?")->execute([$kullanici['id']]);
        } else {
            $kod_gecerli = true;
        }
    } else {
        $mesaj = "Bu sıfırlama bağlantısı geçersiz, daha önce kullanılmış veya hatalı.";
        $mesajTuru = "danger";
    }
} else {
    header("Location: index.php");
    exit;
}

// 2. Şifre Değiştirme İşlemi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $kod_gecerli) {
    $yeni_sifre = $_POST['yeni_sifre'];
    $yeni_tekrar = $_POST['yeni_tekrar'];
    
    // Cloudflare Kontrolü
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

    // --- ŞİFRE GÜVENLİK KONTROLLERİ ---
    if (!$turnstile_data || !$turnstile_data->success) {
         $mesaj = "Robot doğrulaması başarısız. Lütfen tekrar deneyin.";
         $mesajTuru = "danger";
    } 
    elseif ($yeni_sifre !== $yeni_tekrar) {
        $mesaj = "Girdiğiniz şifreler birbiriyle uyuşmuyor.";
        $mesajTuru = "warning";
    }
    elseif (strlen($yeni_sifre) < 8) {
        $mesaj = "Şifreniz en az 8 karakter uzunluğunda olmalıdır.";
        $mesajTuru = "warning";
    }
    elseif (!preg_match("#[0-9]+#", $yeni_sifre)) {
        $mesaj = "Şifreniz en az bir rakam içermelidir.";
        $mesajTuru = "warning";
    }
    elseif (!preg_match("#[a-z]+#", $yeni_sifre)) {
        $mesaj = "Şifreniz en az bir küçük harf içermelidir.";
        $mesajTuru = "warning";
    }
    elseif (!preg_match("#[A-Z]+#", $yeni_sifre)) {
        $mesaj = "Şifreniz en az bir büyük harf içermelidir.";
        $mesajTuru = "warning";
    }
    elseif (!preg_match("#\W+#", $yeni_sifre)) {
        $mesaj = "Şifreniz en az bir özel karakter (!, @, #, $, vb.) içermelidir.";
        $mesajTuru = "warning";
    } 
    else {
        // Şifre güvenli, ARGON2ID ile şifrele
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $yeni_hash = password_hash($yeni_sifre, $algo);
        
        $guncelle = $db->prepare("UPDATE yoneticiler SET sifre = ?, sifre_sifirlama_kodu = NULL WHERE id = ?");
        $guncelle->execute([$yeni_hash, $kullanici['id']]);
        
        if (function_exists('sistem_log_kaydet')) {
            sistem_log_kaydet("Şifre Değişikliği", "Kullanıcı e-posta linki üzerinden şifresini yeni güvenlik kurallarıyla güncelledi.", $kullanici['firma_id'], $kullanici['id']);
        }
        
        $mesaj = "Harika! Şifreniz başarıyla güncellendi.";
        $mesajTuru = "success";
        $kod_gecerli = false; 
        
        header("refresh:3;url=login.php");
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Şifre Belirle | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; display: flex; flex-direction: column; min-height: 100vh; }
        .navbar { padding: 15px 0; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .navbar-brand { font-weight: 800; font-size: 1.5rem; color: #2c3e50 !important; }
        .main-content { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 15px; margin-top: 60px; }
        .reset-card { background: white; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.05); width: 100%; max-width: 450px; overflow: hidden; border: 1px solid #edf2f9; }
        .reset-header { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); padding: 30px 20px; text-align: center; color: white; }
        .reset-icon { font-size: 2.5rem; margin-bottom: 10px; }
        .form-control { padding: 12px 15px; border-radius: 10px; background-color: #f8f9fa; border: 1px solid #e9ecef; }
        .form-control:focus { box-shadow: 0 0 0 3px rgba(28, 200, 138, 0.2); border-color: #1cc88a; background-color: #fff; }
        .btn-submit { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); color: white; font-weight: 600; padding: 12px; border-radius: 10px; border: none; transition: all 0.3s; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(28, 200, 138, 0.3); color: white; }
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
        <div class="reset-card fade-in-up">
            
            <div class="reset-header">
                <i class="fas fa-key reset-icon"></i>
                <h4 class="fw-bold mb-0">Yeni Şifre Belirle</h4>
                <p class="text-white-50 small mb-0 mt-2">Lütfen yeni ve güvenli bir şifre oluşturun.</p>
            </div>
            
            <div class="card-body p-4 p-md-4">
                
                <?php if($mesaj): ?>
                    <div class="alert alert-<?= $mesajTuru ?> py-3 text-center small fw-semibold border-0 rounded-3 shadow-sm mb-4">
                        <?php if($mesajTuru == 'danger'): ?><i class="fas fa-times-circle me-2 fa-2x d-block mb-2"></i>
                        <?php elseif($mesajTuru == 'success'): ?><i class="fas fa-check-circle me-2 fa-2x d-block mb-2"></i>
                        <?php else: ?><i class="fas fa-exclamation-triangle me-2 fa-2x d-block mb-2"></i><?php endif; ?>
                        <?= $mesaj ?>
                        
                        <?php if($mesajTuru == 'success'): ?>
                            <div class="mt-3 small text-muted">Giriş sayfasına yönlendiriliyorsunuz...</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if($kod_gecerli): ?>
                <form method="POST" id="resetForm">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted ms-1 mb-1">Yeni Şifre</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0 bg-light"><i class="fas fa-lock text-muted"></i></span>
                            <input type="password" name="yeni_sifre" id="yeni_sifre" class="form-control border-start-0 border-end-0 ps-0" required placeholder="Yeni şifreniz">
                            <button class="btn border border-start-0 bg-light text-muted" type="button" onclick="togglePassword('yeni_sifre', 'eye1')">
                                <i class="fas fa-eye" id="eye1"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted ms-1">Yeni Şifre (Tekrar)</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0 bg-light"><i class="fas fa-lock text-muted"></i></span>
                            <input type="password" name="yeni_tekrar" id="yeni_tekrar" class="form-control border-start-0 border-end-0 ps-0" required placeholder="Şifrenizi doğrulayın">
                            <button class="btn border border-start-0 bg-light text-muted" type="button" onclick="togglePassword('yeni_tekrar', 'eye2')">
                                <i class="fas fa-eye" id="eye2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Şifre Kuralları Bilgi Kutusu -->
                    <div class="mb-4 small text-muted border p-2 rounded bg-light">
                        <span class="fw-bold d-block mb-1"><i class="fas fa-shield-alt text-success"></i> Şifre Kuralları:</span>
                        <ul class="mb-0 ps-3" style="font-size: 0.8rem;">
                            <li>En az 8 karakter uzunluğunda olmalı.</li>
                            <li>Büyük harf ve küçük harf içermeli.</li>
                            <li>En az 1 rakam içermeli.</li>
                            <li>En az 1 özel karakter (!, @, # vb.) içermeli.</li>
                        </ul>
                    </div>

                    <div class="turnstile-container">
                        <div class="cf-turnstile" data-sitekey="<?= $turnstile_site_key ?>" data-theme="light"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-submit w-100 shadow-sm mt-1" id="submitBtn">
                        <i class="fas fa-save me-2"></i> Şifreyi Kaydet
                    </button>
                    
                </form>
                <?php elseif($mesajTuru != 'success'): ?>
                    <div class="text-center mt-2">
                        <a href="sifremi_unuttum.php" class="btn btn-outline-secondary fw-bold rounded-pill px-4">Yeniden Link İste</a>
                    </div>
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
        function togglePassword(inputId, iconId) {
            var passInput = document.getElementById(inputId);
            var eyeIcon = document.getElementById(iconId);
            if (passInput.type === "password") {
                passInput.type = "text";
                eyeIcon.classList.replace("fa-eye", "fa-eye-slash");
            } else {
                passInput.type = "password";
                eyeIcon.classList.replace("fa-eye-slash", "fa-eye");
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('resetForm');
            if(form) {
                form.addEventListener('submit', function() {
                    const btn = document.getElementById('submitBtn');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> İşleniyor...';
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