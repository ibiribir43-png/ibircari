<?php
/**
 * DOSYA ADI: musteri_header.php
 * AÇIKLAMA: Müşteri portalı dinamik üst menü (Lively Tema Uyumu)
 */
require_once 'baglanti.php';

// Güvenlik: Oturum yoksa index'e gönder
if (!isset($_SESSION['musteri_auth'])) {
    header("Location: index.php");
    exit;
}

$m_id = $_SESSION['musteri_id'];

// Firma ve Müşteri Bilgilerini Tek Sorguda (LEFT JOIN ile) Çek
// (Senin SQL dump verilerindeki 'logo_yolu' ve 'email' sütunlarına %100 uyumludur)
try {
    $info_sorgu = $db->prepare("
        SELECT m.*, f.firma_adi, f.logo_yolu, f.telefon as firma_tel, f.email as firma_mail, f.adres as firma_adres 
        FROM musteriler m 
        LEFT JOIN firmalar f ON m.firma_id = f.id 
        WHERE m.id = ?
    ");
    $info_sorgu->execute([$m_id]);
    $portal_data = $info_sorgu->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    // Firma tablosunda sorun olursa sayfa çökmesin, sadece müşteriyi çek
    $info_sorgu = $db->prepare("SELECT * FROM musteriler WHERE id = ?");
    $info_sorgu->execute([$m_id]);
    $portal_data = $info_sorgu->fetch(PDO::FETCH_ASSOC);
    $portal_data['firma_adi'] = "ibiR Wedding";
}

if (!$portal_data) { die("Erişim yetkiniz bulunmuyor."); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($portal_data['firma_adi'] ?? 'Portal'); ?> | Müşteri Paneli</title>
    
    <!-- Bootstrap 5 & FontAwesome -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Az önce oluşturduğumuz Canlı Temamız -->
    <link rel="stylesheet" href="assets/css/portal_lively.css">
</head>
<body>

<nav class="navbar navbar-light nav-portal sticky-top">
    <div class="container">

        <a class="navbar-brand d-flex align-items-center flex-wrap" href="dashboard.php">
            
            <?php if(!empty($portal_data['logo_yolu'])): ?>
                <img src="../ibircari.xyz/uploads/logos/<?php echo htmlspecialchars($portal_data['logo_yolu']); ?>" height="40" class="me-3 mb-1" onerror="this.style.display='none'">
            <?php endif; ?>

            <span class="firma-adi">
                <?php echo htmlspecialchars($portal_data['firma_adi'] ?? 'Portal'); ?>
            </span>

        </a>
        </div>

        </div>

    </div>
</nav>