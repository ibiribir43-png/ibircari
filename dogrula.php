<?php
require 'baglanti.php';

$mesaj = "";
$durum = "bekleniyor"; // basarili, hatali

if (isset($_GET['email']) && isset($_GET['kod'])) {
    $email = $_GET['email'];
    $kod = $_GET['kod'];

    // Bu email ve koda sahip, henüz onaylanmamış bir kullanıcı var mı?
    $sorgu = $db->prepare("SELECT * FROM yoneticiler WHERE email = ? AND email_onay_kodu = ? AND email_onayli = 0");
    $sorgu->execute([$email, $kod]);
    $kullanici = $sorgu->fetch(PDO::FETCH_ASSOC);

    if ($kullanici) {
        // Eşleşme bulundu! Hesabı onayla.
        $onayla = $db->prepare("UPDATE yoneticiler SET email_onayli = 1, email_onay_kodu = NULL WHERE id = ?");
        $onayla->execute([$kullanici['id']]);
        
        $durum = "basarili";
        $mesaj = "Harika! Hesabınız başarıyla doğrulandı. Artık giriş yapabilirsiniz.";
    } else {
        $durum = "hatali";
        $mesaj = "Bu doğrulama bağlantısı geçersiz veya hesap zaten onaylanmış.";
    }
} else {
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doğrulama - ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card-verify { width: 100%; max-width: 450px; padding: 3rem; background: white; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); text-align: center; }
        .icon-box { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; font-size: 2.5rem; }
        .icon-success { background-color: #d1e7dd; color: #198754; }
        .icon-danger { background-color: #f8d7da; color: #dc3545; }
        .btn-home { background-color: #2c3e50; color: white; border-radius: 50px; padding: 12px 30px; font-weight: bold; text-decoration: none; display: inline-block; margin-top: 20px; transition: 0.3s; }
        .btn-home:hover { background-color: #1a252f; color: white; transform: translateY(-2px); }
    </style>
</head>
<body>

    <div class="card-verify">
        <?php if($durum == 'basarili'): ?>
            <div class="icon-box icon-success">
                <i class="fas fa-check"></i>
            </div>
            <h3 class="fw-bold text-success mb-3">Tebrikler! 🎉</h3>
            <p class="text-muted mb-4"><?php echo $mesaj; ?></p>
            <a href="index.php" class="btn-home">Giriş Yap</a>
        <?php else: ?>
            <div class="icon-box icon-danger">
                <i class="fas fa-times"></i>
            </div>
            <h3 class="fw-bold text-danger mb-3">Hata Oluştu!</h3>
            <p class="text-muted mb-4"><?php echo $mesaj; ?></p>
            <a href="index.php" class="btn btn-outline-dark rounded-pill px-4">Ana Sayfaya Dön</a>
        <?php endif; ?>
    </div>

</body>
</html>