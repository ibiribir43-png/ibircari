<?php
session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
if (file_exists('partials/security_check.php')) {
    require_once 'partials/security_check.php';
} else {
    // Güvenlik dosyası yoksa manuel kontrol
    if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
        header("Location: index.php");
        exit;
    }
}

$firma_id = $_SESSION['firma_id'];
$mesaj = "";
$mesajTuru = "";

// --- İŞLEMLER ---

// 1. Yeni Hizmet Ekle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hizmet_ekle'])) {
    $ad = trim($_POST['hizmet_adi']);
    $fiyat = $_POST['fiyat'];
    
    // Fiyat formatını düzelt (virgül varsa nokta yap)
    $fiyat = str_replace(',', '.', $fiyat);

    if(!empty($ad) && is_numeric($fiyat)) {
        // Durum sütunu varsa 1 olarak ekle, yoksa varsayılan
        try {
            $db->prepare("INSERT INTO urun_hizmetler (firma_id, hizmet_adi, varsayilan_fiyat, durum) VALUES (?, ?, ?, 1)")->execute([$firma_id, $ad, $fiyat]);
        } catch (PDOException $e) {
            // Durum sütunu yoksa alternatif
            $db->prepare("INSERT INTO urun_hizmetler (firma_id, hizmet_adi, varsayilan_fiyat) VALUES (?, ?, ?)")->execute([$firma_id, $ad, $fiyat]);
        }
        
        $mesaj = "Hizmet başarıyla eklendi.";
        $mesajTuru = "success";
    } else {
        $mesaj = "Lütfen geçerli bir isim ve fiyat giriniz.";
        $mesajTuru = "danger";
    }
}

// 2. Silme İşlemi
if (isset($_GET['sil'])) {
    $sil_id = intval($_GET['sil']);
    $db->prepare("DELETE FROM urun_hizmetler WHERE id = ? AND firma_id = ?")->execute([$sil_id, $firma_id]);
    
    // URL'den parametreyi temizlemek için yönlendirme (Refresh edince tekrar silmeye çalışmasın)
    header("Location: hizmetler.php?msg=silindi"); 
    exit;
}

// Silme mesajı kontrolü (Redirect sonrası)
if(isset($_GET['msg']) && $_GET['msg'] == 'silindi') {
    $mesaj = "Hizmet başarıyla silindi.";
    $mesajTuru = "warning";
}

// 3. Listeleme
// Hata almamak için try-catch ile çekiyoruz (Tablo yoksa veya sütun eksikse)
try {
    $sql = "SELECT * FROM urun_hizmetler WHERE firma_id = ? ORDER BY hizmet_adi ASC";
    // Eğer durum sütunu varsa sadece aktifleri çekmek isteyebilirsin:
    // $sql = "SELECT * FROM urun_hizmetler WHERE firma_id = ? AND durum = 1 ORDER BY hizmet_adi ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$firma_id]);
    $hizmetler = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $hizmetler = [];
    $mesaj = "Veritabanı hatası: " . $e->getMessage();
    $mesajTuru = "danger";
}

// Sayfa Başlığı (Navbar için gerekli olabilir)
$page_title = "Hizmet Kataloğu";
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | ibiR Cari</title>
    
    <!-- YÖNETİM CSS -->
    <link rel="stylesheet" href="css/yonetim.css">
    
    <!-- BOOTSTRAP & FONTAWESOME -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .card { border: none; border-radius: 15px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1); }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
        .btn-circle { width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        
        /* Sağ tarafı sabitleme (Masaüstü için) */
        @media (min-width: 992px) {
            .sticky-panel { position: sticky; top: 20px; }
        }
    </style>
</head>
<body class="yonetim-body">

    <!-- NAVBAR -->
    <?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim pb-5">
        
        <!-- BAŞLIK -->
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h3 class="text-secondary mb-0"><i class="fas fa-tags me-2"></i>Hizmet & Ürün Kataloğu</h3>
            </div>
            <div class="col-md-6 text-end">
                <a href="anasayfa.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Ana Sayfa</a>
            </div>
        </div>

        <!-- BİLDİRİM -->
        <?php if($mesaj): ?>
            <div class="alert alert-<?php echo $mesajTuru; ?> alert-dismissible fade show shadow-sm">
                <?php echo $mesaj; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            
            <!-- SOL: LİSTE -->
            <div class="col-lg-8 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold text-primary">Kayıtlı Hizmetler / Paketler</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary small">
                                <tr>
                                    <th class="ps-4">Hizmet Adı</th>
                                    <th class="text-end">Varsayılan Fiyat</th>
                                    <th class="text-end pe-4">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($hizmetler) > 0): ?>
                                    <?php foreach($hizmetler as $h): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($h['hizmet_adi']); ?></div>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-success bg-opacity-10 text-success fs-6 border border-success border-opacity-25">
                                                <?php echo number_format($h['varsayilan_fiyat'], 2, ',', '.'); ?> ₺
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="?sil=<?php echo $h['id']; ?>" 
                                               onclick="return confirm('Bu hizmeti silmek istediğinize emin misiniz?')" 
                                               class="btn btn-sm btn-outline-danger btn-circle ms-auto" 
                                               title="Sil">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center py-5 text-muted">
                                            <i class="fas fa-box-open fa-3x mb-3 opacity-25"></i><br>
                                            Henüz kayıtlı bir hizmet veya ürününüz yok.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- SAĞ: EKLEME FORMU -->
            <div class="col-lg-4">
                <div class="sticky-panel">
                    <div class="card shadow-sm border-0 bg-primary text-white">
                        <div class="card-header bg-transparent border-0 pt-4 pb-0">
                            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Yeni Ekle</h5>
                        </div>
                        <div class="card-body">
                            <p class="small opacity-75 mb-3">Teklif oluştururken ve işlem girerken hızlıca seçebileceğiniz hizmetleri buradan tanımlayın.</p>
                            
                            <form method="POST" class="bg-white p-3 rounded text-dark shadow">
                                <input type="hidden" name="hizmet_ekle" value="1">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Hizmet / Paket Adı</label>
                                    <input type="text" name="hizmet_adi" class="form-control" required placeholder="Örn: Gelinlik Kiralama">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Varsayılan Fiyat (TL)</label>
                                    <input type="number" step="0.01" name="fiyat" class="form-control" required placeholder="0.00">
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100 fw-bold">
                                    <i class="fas fa-save me-2"></i>Kaydet
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- İpucu Kartı -->
                    <div class="card mt-3 bg-white border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-lightbulb text-warning fa-lg"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h6 class="fw-bold small mb-1">İpucu</h6>
                                    <p class="small text-muted mb-0">
                                        Buraya eklediğiniz hizmetler, "Müşteri İşlemleri" ve "Teklif Oluştur" ekranlarında otomatik tamamlama listesinde çıkar.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php require_once 'partials/footer_yonetim.php'; ?>
</body>
</html>