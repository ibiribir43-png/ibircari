<?php
require 'baglanti.php';

// Güvenlik: Giriş yoksa veya Firma ID yoksa at
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: index.php");
    exit;
}

$firma_id = $_SESSION['firma_id'];
$firma_adi = $_SESSION['firma_adi'] ?? 'Borç Yönetimi';

// 1. Toplam Tedarikçi Sayısı (Sadece Bu Firmanın ve Silinmemiş)
$sorguTedarikci = $db->prepare("SELECT COUNT(*) FROM tedarikciler WHERE durum = 1 AND silindi = 0 AND firma_id = ?");
$sorguTedarikci->execute([$firma_id]);
$toplamTedarikci = $sorguTedarikci->fetchColumn();

// 2. Toplam Borç Durumu (Sadece Bu Firmanın)
// (Alışlar - Ödemeler = Kalan Borç)
$sorguBorc = $db->prepare("
    SELECT 
    (SELECT COALESCE(SUM(toplam_tutar),0) FROM tedarikci_hareketler WHERE islem_turu = 'alis' AND firma_id = ?) -
    (SELECT COALESCE(SUM(toplam_tutar),0) FROM tedarikci_hareketler WHERE islem_turu = 'odeme' AND firma_id = ?) 
    as guncel_borc
");
$sorguBorc->execute([$firma_id, $firma_id]);
$borcDurumu = $sorguBorc->fetch(PDO::FETCH_ASSOC);
$toplamBorcumuz = $borcDurumu['guncel_borc'];
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Borç Yönetimi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .ozet-kutu { border: none; border-radius: 15px; color: white; padding: 20px; height: 100%; transition: transform 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .ozet-kutu:hover { transform: translateY(-5px); }
        
        .bg-gradient-danger { background: linear-gradient(45deg, #e74a3b, #be2617); }
        .bg-gradient-info { background: linear-gradient(45deg, #36b9cc, #258391); }
        
        .hizli-btn { border-radius: 15px; padding: 25px; font-size: 1.1rem; font-weight: 600; border: 1px solid #eee; box-shadow: 0 2px 5px rgba(0,0,0,0.05); transition: all 0.3s; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 10px; background: white; color: #555; text-decoration: none; }
        .hizli-btn:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); color: #2c3e50; border-color: #ddd; }
        
        @media (max-width: 768px) {
            .hizli-btn { padding: 15px; font-size: 1rem; }
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="anasayfa.php">
                <i class="fas fa-layer-group text-primary me-2"></i><?php echo htmlspecialchars($firma_adi); ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="anasayfa.php">Ana Sayfa</a></li>
                    <li class="nav-item"><a class="nav-link active fw-bold text-danger" href="borclar.php">Borç Yönetimi</a></li>
                    <li class="nav-item"><a class="nav-link" href="cikis.php">Çıkış</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container pb-5">
        
        <div class="row mb-4">
            <div class="col-12">
                <h4 class="text-secondary mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Tedarikçi ve Borç Paneli</h4>
            </div>
        </div>

        <!-- ÖZET KARTLAR -->
        <div class="row mb-5">
            <div class="col-md-6 mb-3">
                <div class="card ozet-kutu bg-gradient-danger">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-2 opacity-75">TOPLAM PİYASA BORCU</h6>
                            <h2 class="m-0 fw-bold"><?php echo number_format($toplamBorcumuz, 2, ',', '.'); ?> ₺</h2>
                        </div>
                        <i class="fas fa-arrow-down fa-3x opacity-25"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card ozet-kutu bg-gradient-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-2 opacity-75">KAYITLI TEDARİKÇİ</h6>
                            <h2 class="m-0 fw-bold"><?php echo $toplamTedarikci; ?></h2>
                        </div>
                        <i class="fas fa-truck-loading fa-3x opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- HIZLI İŞLEMLER -->
        <h5 class="mb-3 text-secondary border-bottom pb-2">Hızlı İşlemler</h5>
        <div class="row mb-5">
            <div class="col-md-4 mb-3">
                <!-- ARTIK MODAL İLE AÇILACAĞI İÇİN LİSTEYE GİDİP ORADAN EKLESİN -->
                <a href="tedarikciler.php" class="hizli-btn text-danger">
                    <i class="fas fa-user-plus fa-2x"></i>
                    <span>Alacaklı Ekle / Yönet</span>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="tedarikciler.php" class="hizli-btn text-primary">
                    <i class="fas fa-list fa-2x"></i>
                    <span>Alacaklı Listesi</span>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="anasayfa.php" class="hizli-btn text-secondary">
                    <i class="fas fa-home fa-2x"></i>
                    <span>Ana Menüye Dön</span>
                </a>
            </div>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>