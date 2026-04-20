<?php
require 'baglanti.php';

// Güvenlik
if (!isset($_SESSION['kullanici_id'])) {
    header("Location: index.php");
    exit;
}

// Geri Yükleme İşlemi (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['geri_yukle_id'])) {
    $db->prepare("UPDATE musteriler SET silindi = 0 WHERE id = ?")->execute([$_POST['geri_yukle_id']]);
    header("Location: cop_kutusu.php");
    exit;
}

// Kalıcı Silme (POST) - İstersen bu özelliği de koyabilirsin
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kalici_sil_id'])) {
    $db->prepare("DELETE FROM musteriler WHERE id = ?")->execute([$_POST['kalici_sil_id']]);
    header("Location: cop_kutusu.php");
    exit;
}

// Silinenleri Çek
$silinenler = $db->query("SELECT * FROM musteriler WHERE silindi = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Çöp Kutusu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">

    <!-- Navbar (Aynısı) -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand" href="anasayfa.php"><i class="fas fa-wallet text-primary me-2"></i>ibiR Wedding Müşteri Cari Takip</a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="anasayfa.php">Ana Sayfa</a></li>
                    <li class="nav-item"><a class="nav-link" href="musteriler.php">Müşteriler</a></li>
                    <li class="nav-item"><a class="nav-link" href="musteri_ekle.php">Müşteri Ekle</a></li>
                    <li class="nav-item"><a class="nav-link text-dark active" href="cop_kutusu.php">Silinenler</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <h3 class="text-danger mb-4"><i class="fas fa-trash-alt me-2"></i>Çöp Kutusu (Silinen Müşteriler)</h3>

        <?php if(count($silinenler) == 0): ?>
            <div class="alert alert-success text-center py-5">
                <h4><i class="fas fa-check-circle me-2"></i>Çöp Kutusu Boş</h4>
                <p>Silinmiş müşteri bulunmuyor.</p>
                <a href="musteriler.php" class="btn btn-primary">Müşteri Listesine Dön</a>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Ad Soyad</th>
                                <th>Telefon</th>
                                <th>Adres</th>
                                <th class="text-end pe-4">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($silinenler as $s): ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?php echo $s['ad_soyad']; ?></td>
                                    <td><?php echo $s['telefon']; ?></td>
                                    <td><?php echo $s['adres']; ?></td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group">
                                            <a href="musteri_detay.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-info">İncele</a>
                                            
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="geri_yukle_id" value="<?php echo $s['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-undo me-1"></i>Geri Yükle</button>
                                            </form>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bu müşteriyi ve tüm verilerini KALICI olarak silmek istiyor musunuz? Geri alınamaz!');">
                                                <input type="hidden" name="kalici_sil_id" value="<?php echo $s['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger ms-1"><i class="fas fa-times"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>