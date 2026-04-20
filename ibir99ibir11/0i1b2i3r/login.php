<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

// Zaten giriş yapılmışsa dashboard'a yönlendir
if(isset($_SESSION['admin_id'])){
    header("Location: dashboard.php");
    exit;
}

// Giriş işlemi
$hata = '';
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $kullanici = trim($_POST['kullanici']); // Artık hem email hem kullanıcı adı alıyor
    $sifre = $_POST['sifre'];

    // KRİTİK GÜVENLİK GÜNCELLEMESİ: 
    // Parantez kullanımı çok önemlidir. Ayrıca sadece "IBIR-4247-ADMIN" (Süper Adminler) bu panele girebilir!
    $stmt = $pdo->prepare("SELECT * FROM yoneticiler WHERE (email = :kullanici OR kullanici_adi = :kullanici) AND firma_id = 'IBIR-4247-ADMIN' LIMIT 1");
    $stmt->execute(['kullanici' => $kullanici]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if($admin) {
        // Kullanıcı bulundu, şifrenin boş gelmediğinden emin ol ve kontrolü yap
        if(!empty($sifre) && (password_verify($sifre, $admin['sifre']) || md5($sifre) === $admin['sifre'])){
            // Başarılı giriş
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_adi'] = $admin['ad_soyad'] ?: $admin['kullanici_adi'];
            $_SESSION['rol'] = $admin['rol'];
            
            header("Location: dashboard.php");
            exit;
        } else {
            $hata = 'Şifreniz hatalı. Lütfen tekrar deneyin.';
        }
    } else {
        $hata = 'Bu e-posta veya kullanıcı adı ile kayıtlı bir yetkili hesap bulunamadı.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Girişi | ibiR Core</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; font-family: 'Segoe UI', sans-serif;}
        .login-card { width: 100%; max-width: 400px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: none; }
        .login-header { background: #4e73df; color: white; text-align: center; padding: 30px 20px; border-radius: 15px 15px 0 0; }
        .login-header i { font-size: 3rem; margin-bottom: 10px; }
    </style>
</head>
<body>

<div class="card login-card">
    <div class="login-header">
        <i class="fas fa-rocket"></i>
        <h4 class="mb-0 fw-bold">ibiR Core Yönetim</h4>
    </div>
    <div class="card-body p-4">
        <?php if($hata): ?>
            <div class="alert alert-danger shadow-sm border-0"><i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($hata); ?></div>
        <?php endif; ?>
        
        <?php 
        // Çıkış yapıldığında veya başka bir sayfadan yönlendirildiğinde gelen flash mesajı varsa göster
        if(isset($_SESSION['flash'])) {
            echo '<div class="alert alert-'.$_SESSION['flash']['tip'].' shadow-sm border-0">'.$_SESSION['flash']['mesaj'].'</div>';
            unset($_SESSION['flash']);
        }
        ?>

        <form method="post">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">E-Posta veya Kullanıcı Adı</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span>
                    <input type="text" name="kullanici" class="form-control" placeholder="admin@site.com veya admin" required autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-bold text-muted">Şifre</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" name="sifre" class="form-control" placeholder="••••••••" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="fas fa-sign-in-alt me-2"></i> Sisteme Giriş Yap</button>
        </form>
    </div>
</div>

</body>
</html>