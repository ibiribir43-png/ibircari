<?php
// Hata göstermeyi açıyoruz (Sistemdeki 500 hatalarının sebebini ekrana basar)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Config dosyasını YENİ YOLDAN çağırıyoruz
$configFile = __DIR__ . '/../includes/config.php';

if (!file_exists($configFile)) {
    die("<h1>Kritik Hata!</h1><p><b>config.php</b> dosyası bulunamadı. Lütfen <b>includes/config.php</b> yolunda dosyanın olduğundan emin olun.</p>");
}

require_once $configFile;

// Eğer admin zaten giriş yapmışsa dashboard'a yönlendir
if(isset($_SESSION['admin_id'])){
    header("Location: dashboard.php");
    exit;
} else {
    header("Location: login.php");
    exit;
}

// Giriş denemesi
$hata = '';
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email = $_POST['email'];
    $sifre = $_POST['sifre'];

    $stmt = $pdo->prepare("SELECT * FROM yoneticiler WHERE email = :email LIMIT 1");
    $stmt->execute(['email'=>$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if($admin && password_verify($sifre, $admin['sifre'])){
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_adi'] = $admin['ad_soyad'] ?? $admin['kullanici_adi'];
        header("Location: dashboard.php");
        exit;
    } else {
        $hata = "Email veya şifre hatalı!";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Girişi</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/admin.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container d-flex justify-content-center align-items-center" style="height:100vh;">
    <div class="card p-4 shadow" style="width: 100%; max-width: 400px;">
        <h3 class="card-title mb-3 text-center">Admin Girişi</h3>

        <?php if($hata): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($hata); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="mb-3">
                <input type="email" name="email" class="form-control" placeholder="Email" required>
            </div>
            <div class="mb-3">
                <input type="password" name="sifre" class="form-control" placeholder="Şifre" required>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Giriş Yap</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>