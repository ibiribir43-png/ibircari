<?php
session_start();
require 'baglanti.php';

// Güvenlik
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: login.php");
    exit;
}

$siparis_kodu = $_GET['siparis'] ?? '';

// Sipariş kodu yoksa mağazaya geri at
if (empty($siparis_kodu)) {
    header("Location: magaza.php");
    exit;
}

$firma_id = $_SESSION['firma_id'];

// Siparişi veritabanından çek ve sadece bu firmaya ait olduğuna emin ol
$siparis = $db->prepare("SELECT * FROM abonelik_siparisleri WHERE siparis_kodu = ? AND firma_id = ?");
$siparis->execute([$siparis_kodu, $firma_id]);
$s = $siparis->fetch(PDO::FETCH_ASSOC);

if (!$s) {
    die("Sipariş bulunamadı veya bu siparişi görüntüleme yetkiniz yok.");
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ödeme Sonucu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .result-card { max-width: 500px; width: 100%; border-radius: 15px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.08); padding: 40px 30px; text-align: center; background: #fff; }
        .icon-circle { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 35px; }
        .icon-success { background-color: #d1e7dd; color: #198754; }
        .icon-pending { background-color: #fff3cd; color: #ffc107; }
        .icon-error { background-color: #f8d7da; color: #dc3545; }
    </style>
</head>
<body>

    <div class="result-card">
        
        <?php if ($s['durum'] == 'basarili'): ?>
            <div class="icon-circle icon-success"><i class="fas fa-check"></i></div>
            <h3 class="fw-bold text-success mb-3">Ödeme Başarılı!</h3>
            <p class="text-muted mb-4">Teşekkür ederiz. Satın alma işleminiz onaylandı ve hesabınıza / limitlerinize başarıyla tanımlandı.</p>
        
        <?php elseif ($s['durum'] == 'bekliyor'): ?>
            <div class="icon-circle icon-pending"><i class="fas fa-hourglass-half"></i></div>
            <h3 class="fw-bold text-warning mb-3">İşlem Bekleniyor...</h3>
            <p class="text-muted mb-4">Ödemeniz şu anda banka onayında veya sisteme yansıması 1-2 dakika sürebilir. Birazdan limitlerinizi kontrol edebilirsiniz.</p>
        
        <?php else: ?>
            <div class="icon-circle icon-error"><i class="fas fa-times"></i></div>
            <h3 class="fw-bold text-danger mb-3">Ödeme Başarısız!</h3>
            <p class="text-muted mb-4">İşleminiz sırasında bir hata oluştu veya ödeme reddedildi. Lütfen bakiyenizi kontrol edip tekrar deneyin.</p>
        <?php endif; ?>
        
        <div class="bg-light p-3 rounded mb-4 text-start small border">
            <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">Sipariş Kodu:</span>
                <span class="fw-bold"><?= htmlspecialchars($s['siparis_kodu']) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">Ödenen Tutar:</span>
                <span class="fw-bold"><?= number_format($s['tutar'], 2) ?> ₺</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-muted">Tarih:</span>
                <span class="fw-bold"><?= date('d.m.Y H:i', strtotime($s['tarih'])) ?></span>
            </div>
        </div>
        
        <a href="magaza.php" class="btn btn-primary w-100 py-2 fw-bold"><i class="fas fa-store me-2"></i>Mağazaya Dön</a>
        <!-- Bu kısım, kullanıcıyı senin sistemindeki asıl limitlerini gördüğü hesabı ayarlarına atar (ilk gönderdiğin sayfa) -->
        <a href="hesabim.php?tab=abonelik" class="btn btn-outline-secondary w-100 py-2 fw-bold mt-2"><i class="fas fa-box me-2"></i>Limitlerimi Kontrol Et</a>
    </div>

</body>
</html>