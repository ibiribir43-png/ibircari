<?php
/**
 * musteri.ibircari.xyz/index.php
 */
require_once 'baglanti.php';

// URL'den token gelmiş mi?
$token_get = isset($_GET['token']) ? $_GET['token'] : null;

// Eğer kullanıcı zaten giriş yapmışsa ve URL'de YENİ bir token yoksa dashboard'a gönder
if (isset($_SESSION['musteri_auth']) && $_SESSION['musteri_auth'] === true && !$token_get) {
    header("Location: dashboard.php");
    exit;
}

if ($token_get) {
    // Veritabanında bu token'ı arayalım
    $sorgu = $db->prepare("SELECT * FROM musteriler WHERE url_token = ? AND silindi = 0");
    $sorgu->execute([$token_get]);
    $musteri = $sorgu->fetch(PDO::FETCH_ASSOC);

    if ($musteri) {
        if ($musteri['portal_status'] == 0) {
            $hata = "Bu portal erişime kapalıdır.";
        } else {
            // OTURUMU BAŞLAT
            $_SESSION['musteri_auth'] = true;
            $_SESSION['musteri_id']   = $musteri['id'];
            $_SESSION['firma_id']     = $musteri['firma_id'];
            $_SESSION['musteri_ad']   = $musteri['ad_soyad'];
            $_SESSION['musteri_token']= $token_get;

            header("Location: dashboard.php");
            exit;
        }
    } else {
        $hata = "Geçersiz portal bağlantısı! (Kod: 404)";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Müşteri Girişi | ibiR Wedding</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #121212; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-box { background: #1e1e1e; padding: 40px; border-radius: 20px; border: 1px solid #333; max-width: 400px; width: 100%; text-align: center; }
    </style>
</head>
<body>
    <div class="login-box shadow-lg">
        <h3 class="mb-3">Müşteri Portalı</h3>
        <?php if(isset($hata)): ?>
            <div class="alert alert-danger"><?php echo $hata; ?></div>
        <?php else: ?>
            <p class="text-muted small">Lütfen size iletilen özel bağlantı ile giriş yapın.</p>
            <div class="spinner-border text-primary my-3"></div>
            <p class="small">Kimliğiniz doğrulanıyor...</p>
        <?php endif; ?>
    </div>
</body>
</html>