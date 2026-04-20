<?php
session_start();
require 'baglanti.php';
require_once 'partials/security_check.php';

// Sayfa başlığı
$page_title = "Müşteriler";
$firma_id = $_SESSION['firma_id'];

// --- MÜŞTERİ LİMİTİ KONTROLÜ (SaaS Entegrasyonu) ---
$limitSorgu = $db->prepare("SELECT p.musteri_limiti FROM firmalar f JOIN paketler p ON f.paket_id = p.id WHERE f.id = ?");
$limitSorgu->execute([$firma_id]);
$musteri_limiti = (int)$limitSorgu->fetchColumn();

$aktifMusteriSorgu = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE firma_id = ? AND silindi = 0");
$aktifMusteriSorgu->execute([$firma_id]);
$aktif_musteri = (int)$aktifMusteriSorgu->fetchColumn();

$limit_doldu = ($musteri_limiti > 0 && $aktif_musteri >= $musteri_limiti);

// Arama Kelimesi
$arama = isset($_GET['q']) ? trim($_GET['q']) : '';

// --- SIRALAMA MANTIĞI ---
$izin_verilenler = ['ad_soyad', 'musteri_no', 'kayit_tarihi'];
$sirala = isset($_GET['sirala']) && in_array($_GET['sirala'], $izin_verilenler) ? $_GET['sirala'] : 'kayit_tarihi';
$yon = isset($_GET['yon']) && $_GET['yon'] == 'ASC' ? 'ASC' : 'DESC';
$yeni_yon = ($yon == 'ASC') ? 'DESC' : 'ASC';

// SORGUNUN KALBİ
$sql = "SELECT * FROM musteriler WHERE firma_id = :firma_id AND silindi = 0";

if ($arama) {
    $sql .= " AND (ad_soyad LIKE :arama OR telefon LIKE :arama OR musteri_no LIKE :arama OR tc_vergi_no LIKE :arama OR sozlesme_no LIKE :arama)";
}

if ($sirala == 'kayit_tarihi') {
    $sql .= " ORDER BY durum DESC, kayit_tarihi DESC"; 
} else {
    $sql .= " ORDER BY $sirala $yon"; 
}

$sorgu = $db->prepare($sql);
$params = [':firma_id' => $firma_id];
if ($arama) { $params[':arama'] = "%$arama%"; }
$sorgu->execute($params);
$musteriler = $sorgu->fetchAll(PDO::FETCH_ASSOC);

// Sayfaya özel inline CSS
$inline_css = '
    body { background-color: #f8f9fa; }
    .table-hover tbody tr:hover { background-color: #eef2f7; transition: 0.2s; }
    .musteri-pasif { background-color: #fcfcfc; opacity: 0.6; }
    .btn-rounded { border-radius: 50px; padding-left: 20px; padding-right: 20px; }
    .badge-soft-success { background-color: rgba(25, 135, 84, 0.1); color: #198754; }
    .badge-soft-secondary { background-color: rgba(108, 117, 125, 0.1); color: #6c757d; }
    th a { color: inherit; text-decoration: none; display: block; }
    th a:hover { color: #0d6efd; }
    @media (max-width: 768px) {
        .btn-mobil-full { width: 100%; border-radius: 10px; margin-bottom: 5px; }
        h3 { font-size: 1.4rem; }
        .container { padding-left: 10px; padding-right: 10px; }
    }
';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | <?php echo htmlspecialchars($firma_adi ?? 'ibiR Cari'); ?></title>
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style><?php echo $inline_css; ?></style>
</head>
<body class="yonetim-body">

<?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim pb-5">
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-6 mb-2 mb-md-0">
            <h3 class="text-secondary mb-0"><i class="fas fa-users me-2"></i>Müşteri Listesi</h3>
        </div>
        <div class="col-md-6">
            <form action="" method="GET" class="d-flex gap-2">
                <input type="text" name="q" class="form-control shadow-sm" placeholder="Ad, Telefon, Vergi No..." value="<?php echo htmlspecialchars($arama); ?>">
                <button class="btn btn-primary shadow-sm px-4" type="submit"><i class="fas fa-search"></i></button>
                <?php if($arama): ?><a href="musteriler.php" class="btn btn-secondary shadow-sm">Sıfırla</a><?php endif; ?>
            </form>
        </div>
    </div>

        <?php if($limit_doldu): ?>
        <div class="alert alert-danger shadow-sm border-0 d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
                <strong>Paket Limitine Ulaştınız!</strong> Mevcut paketinizin izin verdiği maksimum müşteri sayısına (<?= $musteri_limiti ?>) ulaştınız. Yeni müşteri ekleyemezsiniz.
            </div>
            <a href="hesabim.php" class="btn btn-sm btn-danger rounded-pill fw-bold">Paketimi Yükselt</a>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="min-width: 650px;">
                        <thead class="bg-light text-secondary">
                            <tr>
                                <th class="ps-4 py-3">
                                    <a href="?sirala=musteri_no&yon=<?php echo ($sirala == 'musteri_no' ? $yeni_yon : 'DESC'); ?>&q=<?php echo htmlspecialchars($arama); ?>">
                                        Müşteri No <?php if($sirala == 'musteri_no') echo ($yon == 'ASC' ? '▲' : '▼'); ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?sirala=ad_soyad&yon=<?php echo ($sirala == 'ad_soyad' ? $yeni_yon : 'ASC'); ?>&q=<?php echo htmlspecialchars($arama); ?>">
                                        Ad Soyad / Firma <?php if($sirala == 'ad_soyad') echo ($yon == 'ASC' ? '▲' : '▼'); ?>
                                    </a>
                                </th>
                                <th class="d-none d-md-table-cell">Telefon</th>
                                <th>Durum</th>
                                <th class="text-end pe-4">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($musteriler) > 0): ?>
                                <?php foreach($musteriler as $m): ?>
                                    <tr class="<?php echo $m['durum'] == 0 ? 'musteri-pasif' : ''; ?>">
                                        
                                        <td class="ps-4 text-muted small fw-bold" style="font-family: monospace; font-size: 0.95rem;">
                                            <?php 
                                            $ham_no = $m['musteri_no'];
                                            if(preg_match('/^([A-Z0-9]+)-(\d{4})(\d{2})(\d{2})-(\d+)$/', $ham_no, $p)) {
                                                echo '<span class="d-none d-md-inline">' . $p[1] . '-' . $p[4] . ' ' . $p[3] . ' ' . substr($p[2], 2) . '-</span>' . $p[5];
                                            } elseif(preg_match('/^([A-Z0-9]+)-(\d{2})(\d{2})(\d{2})-(\d+)$/', $ham_no, $p)) {
                                                echo '<span class="d-none d-md-inline">' . $p[1] . '-' . $p[2] . ' ' . $p[3] . ' ' . $p[4] . '-</span>' . $p[5];
                                            } else {
                                                echo $ham_no;
                                            }
                                            ?>
                                        </td>
                                        
                                        <td class="py-3">
                                            <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($m['ad_soyad']); ?></div>
                                            <?php if($m['sozlesme_no']): ?>
                                                <small class="text-muted" style="font-size: 0.8rem;"><i class="fas fa-file-contract me-1"></i><?php echo htmlspecialchars($m['sozlesme_no']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td class="d-none d-md-table-cell">
                                            <a href="tel:<?php echo htmlspecialchars($m['telefon']); ?>" class="text-decoration-none text-dark fw-bold">
                                                <i class="fas fa-phone-alt me-1 text-muted"></i><?php echo htmlspecialchars($m['telefon']) ?: '-'; ?>
                                            </a>
                                        </td>
                                        
                                        <td>
                                            <?php if($m['durum'] == 1): ?>
                                                <span class="badge badge-soft-success rounded-pill px-3 py-2">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge badge-soft-secondary rounded-pill px-3 py-2">Pasif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="musteri_detay.php?t=<?php echo htmlspecialchars($m['url_token']); ?>" class="btn btn-primary btn-sm btn-rounded shadow-sm btn-mobil-full">
                                                <i class="fas fa-folder-open me-1"></i> Detay
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>
                                        Kayıtlı müşteri bulunamadı.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="mt-4 text-end mb-5">
            <?php if($limit_doldu): ?>
                <button class="btn btn-secondary btn-rounded shadow px-4 py-2 btn-mobil-full opacity-50" disabled>
                    <i class="fas fa-lock me-2"></i>Limit Doldu
                </button>
            <?php else: ?>
                <a href="musteri_ekle.php" class="btn btn-success btn-rounded shadow px-4 py-2 btn-mobil-full">
                    <i class="fas fa-user-plus me-2"></i>Yeni Müşteri Ekle
                </a>
            <?php endif; ?>
        </div>

        </div>

    <div id="toast-container-yonetim"></div>

<?php require_once 'partials/footer_yonetim.php'; ?>