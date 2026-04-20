<?php
require 'baglanti.php';

// Güvenlik
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: index.php");
    exit;
}

$firma_id = $_SESSION['firma_id'];
$firma_adi = $_SESSION['firma_adi'] ?? 'Firma Paneli';

// --- YENİ TEDARİKÇİ EKLEME (TOKENLİ) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tedarikci_ekle'])) {
    $firma_adi_post = $_POST['firma_adi'];
    $yetkili = $_POST['yetkili_kisi'];
    $tel = $_POST['telefon'];
    $adres = $_POST['adres'];
    $vergi = $_POST['vergi_no'];
    $iban = $_POST['iban'];
    $not = $_POST['ozel_notlar'];

    // Token Üret
    $token = md5(uniqid(rand(), true));

    $sorgu = $db->prepare("INSERT INTO tedarikciler (firma_id, url_token, firma_adi, yetkili_kisi, telefon, adres, vergi_no, iban, ozel_notlar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $sonuc = $sorgu->execute([$firma_id, $token, $firma_adi_post, $yetkili, $tel, $adres, $vergi, $iban, $not]);

    if ($sonuc) {
        // Direkt detay sayfasına yönlendir (Token ile)
        header("Location: tedarikci_detay.php?t=$token"); 
        exit;
    }
}

// Arama ve Listeleme
$arama = isset($_GET['q']) ? trim($_GET['q']) : '';

// SORGUNUN KALBİ: Sadece bu firmanın (firma_id) ve silinmemiş tedarikçileri
$sql = "SELECT * FROM tedarikciler WHERE firma_id = :firma_id AND silindi = 0";

if ($arama) {
    $sql .= " AND (firma_adi LIKE :q OR yetkili_kisi LIKE :q OR telefon LIKE :q)";
}

$sql .= " ORDER BY id DESC";

$sorgu = $db->prepare($sql);
$params = [':firma_id' => $firma_id];
if ($arama) $params[':q'] = "%$arama%";

$sorgu->execute($params);
$liste = $sorgu->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Alacaklılar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> 
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } 
        .table-hover tbody tr:hover { background-color: #ffe5e5; transition: 0.2s; }
        .btn-rounded { border-radius: 50px; padding-left: 20px; padding-right: 20px; }
        
        @media (max-width: 768px) {
            .btn-mobil-full { width: 100%; margin-bottom: 5px; border-radius: 10px; }
            h3 { font-size: 1.4rem; }
            .d-none-mobile { display: none; } 
            .container { padding-left: 10px; padding-right: 10px; }
        }
    </style>
</head>
<body class="bg-light">
    
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-danger" href="borclar.php"><i class="fas fa-wallet me-2"></i><?php echo htmlspecialchars($firma_adi); ?></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="nav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="anasayfa.php">Ana Sayfa</a></li>
                    <li class="nav-item"><a class="nav-link active text-danger fw-bold" href="borclar.php">Borçlar</a></li>
                    <li class="nav-item"><a class="nav-link" href="musteri_ekle.php">Müşteri Ekle</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container pb-5">
        
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
            <h3 class="mb-2 mb-md-0 text-secondary"><i class="fas fa-truck-loading me-2"></i>Alacaklı Listesi</h3>
            <button class="btn btn-danger w-auto btn-rounded shadow-sm btn-mobil-full" data-bs-toggle="modal" data-bs-target="#modalTedarikciEkle">
                <i class="fas fa-plus me-1"></i> Yeni Alacaklı Ekle
            </button>
        </div>

        <form class="mb-3 d-flex gap-2">
            <input type="text" name="q" class="form-control shadow-sm" placeholder="Firma, Yetkili veya Telefon Ara..." value="<?php echo htmlspecialchars($arama); ?>">
            <button class="btn btn-secondary shadow-sm w-auto px-4" type="submit"><i class="fas fa-search"></i></button>
            <?php if($arama): ?><a href="tedarikciler.php" class="btn btn-light border shadow-sm">Sıfırla</a><?php endif; ?>
        </form>

        <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="min-width:600px;">
                    <thead class="table-danger text-nowrap">
                        <tr>
                            <th class="ps-4 py-3">Firma / İsim</th>
                            <th>Yetkili</th>
                            <th class="d-none-mobile">Telefon</th>
                            <th class="text-end pe-4">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($liste) > 0): ?>
                            <?php foreach($liste as $t): ?>
                            <tr>
                                <td class="ps-4 py-3">
                                    <div class="fw-bold text-dark"><?php echo $t['firma_adi']; ?></div>
                                    <?php if($t['vergi_no']): ?><small class="text-muted" style="font-size:0.8rem">VN: <?php echo $t['vergi_no']; ?></small><?php endif; ?>
                                </td>
                                <td><?php echo $t['yetkili_kisi']; ?></td>
                                <td class="d-none-mobile">
                                    <a href="tel:<?php echo $t['telefon']; ?>" class="text-dark text-decoration-none fw-bold">
                                        <i class="fas fa-phone-alt me-1 text-muted"></i><?php echo $t['telefon'] ?: '-'; ?>
                                    </a>
                                </td>
                                <td class="text-end pe-4">
                                    <!-- GÜNCELLEME: Link ID yerine TOKEN (t) ile gidiyor -->
                                    <a href="tedarikci_detay.php?t=<?php echo $t['url_token']; ?>" class="btn btn-sm btn-danger btn-rounded shadow-sm btn-mobil-full">
                                        <i class="fas fa-folder-open me-1"></i> Hesap / Detay
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>Kayıtlı alacaklı bulunamadı.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL: YENİ TEDARİKÇİ EKLE -->
    <div class="modal fade" id="modalTedarikciEkle" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="tedarikci_ekle" value="1">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Yeni Alacaklı Ekle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="fw-bold">Firma Adı / Şahıs Adı <span class="text-danger">*</span></label>
                            <input type="text" name="firma_adi" class="form-control" required placeholder="Örn: ABC Elektronik">
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-6"><label>Yetkili Kişi</label><input type="text" name="yetkili_kisi" class="form-control"></div>
                            <div class="col-6"><label>Telefon</label><input type="text" name="telefon" class="form-control"></div>
                        </div>
                        
                        <div class="mb-3"><label>Adres</label><textarea name="adres" class="form-control" rows="2"></textarea></div>
                        
                        <div class="row mb-3">
                            <div class="col-6"><label>Vergi No</label><input type="text" name="vergi_no" class="form-control"></div>
                            <div class="col-6"><label>IBAN</label><input type="text" name="iban" class="form-control" placeholder="TR..."></div>
                        </div>
                        <div class="mb-3"><label>Özel Notlar</label><textarea name="ozel_notlar" class="form-control" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger fw-bold px-4">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>