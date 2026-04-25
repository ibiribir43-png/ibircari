<?php
session_start();
require 'baglanti.php';

if (file_exists('partials/security_check.php')) {
    require_once 'partials/security_check.php';
} else {
    if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
        header("Location: index.php");
        exit;
    }
}

$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

$firma_id = $_SESSION['firma_id'];
$user_id = $_SESSION['kullanici_id'];
$mesaj = "";
$mesajTuru = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kategori_ekle'])) {
    $k_ad = trim($_POST['kategori_adi']);
    if (!empty($k_ad)) {
        $db->prepare("INSERT INTO urun_kategorileri (firma_id, kategori_adi, durum) VALUES (?, ?, 1)")->execute([$firma_id, $k_ad]);
        sistemLog($db, 'Katalog', 'Kategori Eklendi', "$k_ad isimli yeni kategori eklendi.");
        header("Location: urunler_hizmetler.php?tab=kategoriler&msg=k_eklendi");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kategori_duzenle'])) {
    $k_id = (int)$_POST['kategori_id'];
    $k_ad = trim($_POST['kategori_adi']);
    $db->prepare("UPDATE urun_kategorileri SET kategori_adi = ? WHERE id = ? AND firma_id = ?")->execute([$k_ad, $k_id, $firma_id]);
    header("Location: urunler_hizmetler.php?tab=kategoriler&msg=k_guncellendi");
    exit;
}

if (isset($_GET['k_sil'])) {
    $k_id = (int)$_GET['k_sil'];
    $db->prepare("UPDATE urun_hizmetler SET kategori_id = NULL WHERE kategori_id = ? AND firma_id = ?")->execute([$k_id, $firma_id]);
    $db->prepare("DELETE FROM urun_kategorileri WHERE id = ? AND firma_id = ?")->execute([$k_id, $firma_id]);
    sistemLog($db, 'Katalog', 'Kategori Silindi', "Bir kategori silindi.");
    header("Location: urunler_hizmetler.php?tab=kategoriler&msg=k_silindi");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['urun_ekle'])) {
    $tur = $_POST['tur'];
    $kategori_id = empty($_POST['kategori_id']) ? null : (int)$_POST['kategori_id'];
    $ad = trim($_POST['hizmet_adi']);
    $fiyat = (float)str_replace(',', '.', $_POST['varsayilan_fiyat']);
    $maliyet = (float)str_replace(',', '.', $_POST['maliyet_fiyati']);
    $kdv = (int)$_POST['kdv_orani'];
    $stok = $tur == 'urun' ? (int)$_POST['stok_miktari'] : 0;
    $kritik = $tur == 'urun' ? (int)$_POST['kritik_stok'] : 0;
    $barkod = $tur == 'urun' ? trim($_POST['barkod']) : null;

    $db->prepare("INSERT INTO urun_hizmetler (firma_id, kategori_id, tur, hizmet_adi, varsayilan_fiyat, maliyet_fiyati, stok_miktari, kritik_stok, barkod, kdv_orani, durum) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)")->execute([$firma_id, $kategori_id, $tur, $ad, $fiyat, $maliyet, $stok, $kritik, $barkod, $kdv]);
    sistemLog($db, 'Katalog', 'Kayıt Eklendi', "$ad eklendi.");
    header("Location: urunler_hizmetler.php?msg=u_eklendi");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['urun_duzenle'])) {
    $u_id = (int)$_POST['urun_id'];
    $tur = $_POST['tur'];
    $kategori_id = empty($_POST['kategori_id']) ? null : (int)$_POST['kategori_id'];
    $ad = trim($_POST['hizmet_adi']);
    $fiyat = (float)str_replace(',', '.', $_POST['varsayilan_fiyat']);
    $maliyet = (float)str_replace(',', '.', $_POST['maliyet_fiyati']);
    $kdv = (int)$_POST['kdv_orani'];
    $stok = $tur == 'urun' ? (int)$_POST['stok_miktari'] : 0;
    $kritik = $tur == 'urun' ? (int)$_POST['kritik_stok'] : 0;
    $barkod = $tur == 'urun' ? trim($_POST['barkod']) : null;

    $db->prepare("UPDATE urun_hizmetler SET kategori_id=?, tur=?, hizmet_adi=?, varsayilan_fiyat=?, maliyet_fiyati=?, stok_miktari=?, kritik_stok=?, barkod=?, kdv_orani=? WHERE id=? AND firma_id=?")->execute([$kategori_id, $tur, $ad, $fiyat, $maliyet, $stok, $kritik, $barkod, $kdv, $u_id, $firma_id]);
    sistemLog($db, 'Katalog', 'Kayıt Güncellendi', "$ad güncellendi.");
    header("Location: urunler_hizmetler.php?msg=u_guncellendi");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hizli_stok'])) {
    $u_id = (int)$_POST['stok_urun_id'];
    $adet = (int)$_POST['stok_adet'];
    $islem = $_POST['stok_islem'];
    
    if($islem == 'ekle') {
        $db->prepare("UPDATE urun_hizmetler SET stok_miktari = stok_miktari + ? WHERE id = ? AND firma_id = ?")->execute([$adet, $u_id, $firma_id]);
        sistemLog($db, 'Stok', 'Stok Girişi', "Ürün ID $u_id için $adet adet stok eklendi.");
    } else {
        $db->prepare("UPDATE urun_hizmetler SET stok_miktari = GREATEST(0, stok_miktari - ?) WHERE id = ? AND firma_id = ?")->execute([$adet, $u_id, $firma_id]);
        sistemLog($db, 'Stok', 'Stok Çıkışı', "Ürün ID $u_id için $adet adet stok düşüldü.");
    }
    header("Location: urunler_hizmetler.php?msg=s_guncellendi");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toplu_fiyat_guncelle'])) {
    $oran = (float)$_POST['fiyat_oran'];
    $k_id = $_POST['hedef_kategori'];
    $islem = $_POST['fiyat_islem'];
    $carpan = $islem == 'artir' ? (1 + ($oran / 100)) : (1 - ($oran / 100));

    if ($k_id === 'all') {
        $db->prepare("UPDATE urun_hizmetler SET varsayilan_fiyat = varsayilan_fiyat * ? WHERE firma_id = ?")->execute([$carpan, $firma_id]);
    } else {
        $db->prepare("UPDATE urun_hizmetler SET varsayilan_fiyat = varsayilan_fiyat * ? WHERE kategori_id = ? AND firma_id = ?")->execute([$carpan, (int)$k_id, $firma_id]);
    }
    sistemLog($db, 'Finans', 'Toplu Fiyat', "Kategori: $k_id, İşlem: $islem, Oran: %$oran");
    header("Location: urunler_hizmetler.php?tab=fiyatlar&msg=f_guncellendi");
    exit;
}

if (isset($_GET['u_sil'])) {
    $u_id = (int)$_GET['u_sil'];
    $db->prepare("DELETE FROM urun_hizmetler WHERE id = ? AND firma_id = ?")->execute([$u_id, $firma_id]);
    header("Location: urunler_hizmetler.php?msg=u_silindi");
    exit;
}

if (isset($_POST['toplu_sil']) && isset($_POST['secilenler'])) {
    $secilenler = json_decode($_POST['secilenler'], true);
    if(is_array($secilenler) && count($secilenler) > 0) {
        $inQuery = implode(',', array_fill(0, count($secilenler), '?'));
        $params = $secilenler;
        $params[] = $firma_id;
        $db->prepare("DELETE FROM urun_hizmetler WHERE id IN ($inQuery) AND firma_id = ?")->execute($params);
        echo json_encode(['status'=>'success']);
        exit;
    }
}

if(isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if($m=='u_eklendi') { $mesaj = "Kayıt eklendi."; $mesajTuru = "success"; }
    elseif($m=='u_guncellendi') { $mesaj = "Kayıt güncellendi."; $mesajTuru = "success"; }
    elseif($m=='u_silindi') { $mesaj = "Kayıt silindi."; $mesajTuru = "warning"; }
    elseif($m=='k_eklendi') { $mesaj = "Kategori oluşturuldu."; $mesajTuru = "success"; }
    elseif($m=='k_guncellendi') { $mesaj = "Kategori güncellendi."; $mesajTuru = "success"; }
    elseif($m=='k_silindi') { $mesaj = "Kategori silindi."; $mesajTuru = "warning"; }
    elseif($m=='s_guncellendi') { $mesaj = "Stok hareketi başarıyla işlendi."; $mesajTuru = "success"; }
    elseif($m=='f_guncellendi') { $mesaj = "Toplu fiyat güncellemesi tamamlandı."; $mesajTuru = "success"; }
}

$kategoriler = $db->query("SELECT * FROM urun_kategorileri WHERE firma_id = '$firma_id' ORDER BY kategori_adi ASC")->fetchAll(PDO::FETCH_ASSOC);

$urunler = $db->query("SELECT u.*, k.kategori_adi FROM urun_hizmetler u LEFT JOIN urun_kategorileri k ON u.kategori_id = k.id WHERE u.firma_id = '$firma_id' ORDER BY u.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$istatistikler = ['toplam' => count($urunler), 'urun_sayisi' => 0, 'hizmet_sayisi' => 0, 'kritik_stok' => 0, 'toplam_maliyet' => 0, 'toplam_satis_degeri' => 0];

foreach($urunler as $u) {
    if($u['tur'] == 'urun') {
        $istatistikler['urun_sayisi']++;
        if($u['stok_miktari'] <= $u['kritik_stok']) $istatistikler['kritik_stok']++;
        $istatistikler['toplam_maliyet'] += ($u['maliyet_fiyati'] * $u['stok_miktari']);
        $istatistikler['toplam_satis_degeri'] += ($u['varsayilan_fiyat'] * $u['stok_miktari']);
    } else {
        $istatistikler['hizmet_sayisi']++;
    }
}

$activeTab = $_GET['tab'] ?? 'urunler';
$page_title = "Ürün, Hizmet ve Stok Yönetimi";
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="css/yonetim.css">
    <style>
        body { background-color: #f8f9fc; }
        .stat-card { border: none; border-radius: 12px; box-shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.1); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .icon-circle { height: 3rem; width: 3rem; border-radius: 100%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .nav-tabs-custom { border-bottom: 2px solid #eaecf4; }
        .nav-tabs-custom .nav-link { border: none; color: #858796; font-weight: 600; padding: 1rem 1.5rem; border-bottom: 3px solid transparent; }
        .nav-tabs-custom .nav-link.active { color: #4e73df; border-bottom-color: #4e73df; background: transparent; }
        .nav-tabs-custom .nav-link:hover { color: #4e73df; background-color: rgba(78,115,223,0.05); }
        .dt-check { transform: scale(1.3); cursor: pointer; }
        table.dataTable tbody tr { transition: 0.2s; background-color: #fff; }
        table.dataTable tbody tr:hover { background-color: #f8f9fa; }
        .stock-critical { background-color: #fff5f5 !important; }
        .barcode-box { background: #fff; padding: 20px; border-radius: 10px; border: 2px dashed #ccc; text-align: center; }
    </style>
</head>
<body class="yonetim-body">
    <?php include 'partials/navbar.php'; ?>
    <div class="container-fluid pb-5 px-4 mt-4">
        
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h3 class="fw-bold text-gray-800 mb-1"><i class="fas fa-boxes text-primary me-2"></i>Katalog ve Stok Yönetimi</h3>
                <p class="text-muted small mb-0">Ürün, hizmet, barkod ve toplu fiyatlandırma merkezi.</p>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card border-start border-primary border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-primary text-uppercase mb-1">Toplam Hizmetler</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= $istatistikler['hizmet_sayisi'] ?></div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-primary bg-opacity-10 text-primary"><i class="fas fa-handshake"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card border-start border-success border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-success text-uppercase mb-1">Fiziksel Ürünler</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= $istatistikler['urun_sayisi'] ?></div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-success bg-opacity-10 text-success"><i class="fas fa-box-open"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card border-start border-danger border-4 h-100 py-2 <?= $istatistikler['kritik_stok'] > 0 ? 'bg-danger bg-opacity-10' : '' ?>">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-danger text-uppercase mb-1">Kritik Stok Uyarıları</div>
                                <div class="h4 mb-0 fw-bold text-danger"><?= $istatistikler['kritik_stok'] ?></div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-danger bg-opacity-10 text-danger"><i class="fas fa-exclamation-triangle"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card border-start border-info border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-info text-uppercase mb-1">Depo Perakende Değeri</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= number_format($istatistikler['toplam_satis_degeri'], 0, ',', '.') ?> ₺</div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-info bg-opacity-10 text-info"><i class="fas fa-coins"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if($mesaj): ?>
            <div class="alert alert-<?= $mesajTuru ?> alert-dismissible fade show shadow-sm border-0 rounded-3">
                <?= $mesaj ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <ul class="nav nav-tabs-custom bg-white" id="katalogTabs" role="tablist">
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'urunler' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#urunler"><i class="fas fa-list me-2"></i>Katalog & Stok</button></li>
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'kategoriler' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#kategoriler"><i class="fas fa-sitemap me-2"></i>Kategoriler</button></li>
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'fiyatlar' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#fiyatlar"><i class="fas fa-percent me-2"></i>Toplu Fiyat İşlemleri</button></li>
            </ul>

            <div class="tab-content bg-white p-4">
                
                <div class="tab-pane fade <?= $activeTab == 'urunler' ? 'show active' : '' ?>" id="urunler">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <button class="btn btn-danger fw-bold shadow-sm rounded-pill px-4" id="btnTopluSil" onclick="topluSil()" disabled><i class="fas fa-trash-alt me-2"></i>Seçilenleri Sil</button>
                        <button class="btn btn-primary fw-bold shadow-sm px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#urunEkleModal" onclick="resetForm()"><i class="fas fa-plus me-2"></i>Yeni Kayıt Ekle</button>
                    </div>
                    <div class="table-responsive">
                        <table id="katalogTable" class="table table-hover align-middle w-100">
                            <thead class="table-light">
                                <tr>
                                    <th width="30" class="text-center"><input type="checkbox" class="form-check-input dt-check" id="checkAll" onclick="toggleCheckAll(this)"></th>
                                    <th>Tanım</th>
                                    <th>Kategori</th>
                                    <th>Tür</th>
                                    <th>Stok</th>
                                    <th class="text-end">Maliyet</th>
                                    <th class="text-end">Satış Fiyatı</th>
                                    <th class="text-center">Kâr Marjı</th>
                                    <th class="text-end" data-orderable="false">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($urunler as $u): 
                                    $kar_tl = $u['varsayilan_fiyat'] - $u['maliyet_fiyati'];
                                    $kar_yuzde = $u['maliyet_fiyati'] > 0 ? ($kar_tl / $u['maliyet_fiyati']) * 100 : ($u['varsayilan_fiyat'] > 0 ? 100 : 0);
                                    $kar_renk = $kar_tl > 0 ? 'success' : ($kar_tl < 0 ? 'danger' : 'secondary');
                                    $stok_durum = $u['tur'] == 'urun' && $u['stok_miktari'] <= $u['kritik_stok'] ? 'stock-critical' : '';
                                ?>
                                <tr class="<?= $stok_durum ?>">
                                    <td class="text-center"><input type="checkbox" class="form-check-input dt-check row-check" value="<?= $u['id'] ?>" onchange="checkCount()"></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($u['hizmet_adi']) ?></div>
                                        <?php if($u['barkod']): ?>
                                            <div class="small text-primary mt-1" style="cursor:pointer;" onclick="showBarcode('<?= htmlspecialchars(addslashes($u['barkod'])) ?>', '<?= htmlspecialchars(addslashes($u['hizmet_adi'])) ?>')"><i class="fas fa-barcode me-1"></i><?= htmlspecialchars($u['barkod']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($u['kategori_adi'] ?? 'Kategorisiz') ?></span></td>
                                    <td><?= $u['tur'] == 'urun' ? '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary"><i class="fas fa-box me-1"></i>Ürün</span>' : '<span class="badge bg-info bg-opacity-10 text-info border border-info"><i class="fas fa-handshake me-1"></i>Hizmet</span>' ?></td>
                                    <td>
                                        <?php if($u['tur'] == 'urun'): ?>
                                            <div class="fw-bold <?= $u['stok_miktari'] <= $u['kritik_stok'] ? 'text-danger' : 'text-dark' ?>"><?= $u['stok_miktari'] ?> Adet</div>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-muted fw-bold"><?= number_format($u['maliyet_fiyati'], 2, ',', '.') ?> ₺</td>
                                    <td class="text-end text-dark fw-bold"><?= number_format($u['varsayilan_fiyat'], 2, ',', '.') ?> ₺<br><small class="text-muted" style="font-size:10px;">%<?= $u['kdv_orani'] ?> KDV</small></td>
                                    <td class="text-center"><span class="badge bg-<?= $kar_renk ?> bg-opacity-10 text-<?= $kar_renk ?> border border-<?= $kar_renk ?> px-2 py-1"><?= $kar_tl > 0 ? '+' : '' ?><?= number_format($kar_tl, 2, ',', '.') ?> ₺ (%<?= round($kar_yuzde) ?>)</span></td>
                                    <td class="text-end">
                                        <?php if($u['tur'] == 'urun'): ?>
                                            <button class="btn btn-sm btn-outline-success border-0" onclick="openHizliStok(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['hizmet_adi'])) ?>')" title="Hızlı Stok Ekle/Çıkar"><i class="fas fa-layer-group"></i></button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary border-0" onclick='editUrun(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Düzenle"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-sm btn-outline-danger border-0" onclick="deleteUrun(<?= $u['id'] ?>)" title="Sil"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade <?= $activeTab == 'kategoriler' ? 'show active' : '' ?>" id="kategoriler">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="table-responsive border rounded">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead class="bg-light"><tr><th class="ps-4">Kategori Adı</th><th class="text-end pe-4">İşlemler</th></tr></thead>
                                    <tbody>
                                        <?php if(empty($kategoriler)): ?><tr><td colspan="2" class="text-center py-4 text-muted">Kategori bulunamadı.</td></tr><?php endif; ?>
                                        <?php foreach($kategoriler as $k): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark"><i class="fas fa-folder text-warning me-2"></i><?= htmlspecialchars($k['kategori_adi']) ?></td>
                                            <td class="text-end pe-4">
                                                <button class="btn btn-sm btn-light border text-primary" onclick='editKategori(<?= $k['id'] ?>, "<?= htmlspecialchars(addslashes($k['kategori_adi'])) ?>")'><i class="fas fa-edit"></i></button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteKategori(<?= $k['id'] ?>)"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm bg-light rounded-4">
                                <div class="card-body p-4">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-plus-circle text-success me-2"></i>Kategori Ekle / Düzenle</h6>
                                    <form method="POST" id="kategoriForm">
                                        <input type="hidden" name="kategori_ekle" id="k_islem_type" value="1">
                                        <input type="hidden" name="kategori_id" id="k_id" value="">
                                        <div class="mb-3">
                                            <input type="text" name="kategori_adi" id="k_ad" class="form-control" required placeholder="Kategori Adı (Örn: Çerçeveler)">
                                        </div>
                                        <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill" id="k_btn">Kaydet</button>
                                        <button type="button" class="btn btn-secondary w-100 mt-2 fw-bold d-none rounded-pill" id="k_cancel" onclick="resetKategoriForm()">İptal Et</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= $activeTab == 'fiyatlar' ? 'show active' : '' ?>" id="fiyatlar">
                    <div class="row justify-content-center">
                        <div class="col-lg-6">
                            <div class="card border border-primary border-opacity-25 shadow-sm rounded-4 mt-3">
                                <div class="card-body p-5">
                                    <div class="text-center mb-4">
                                        <i class="fas fa-percentage fa-4x text-primary mb-3"></i>
                                        <h4 class="fw-bold text-dark">Toplu Fiyat Güncelleme</h4>
                                        <p class="text-muted small">Tüm ürünlere veya belirli bir kategoriye tek seferde yüzdelik zam veya indirim uygulayın.</p>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="toplu_fiyat_guncelle" value="1">
                                        <div class="mb-4">
                                            <label class="form-label fw-bold small text-muted">Hangi Kayıtlara Uygulansın?</label>
                                            <select name="hedef_kategori" class="form-select border-2 border-primary fw-bold" required>
                                                <option value="all">Katalogdaki TÜM Ürün ve Hizmetlere</option>
                                                <?php foreach($kategoriler as $k): ?>
                                                    <option value="<?= $k['id'] ?>">Sadece: <?= htmlspecialchars($k['kategori_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="row g-3 mb-4">
                                            <div class="col-6">
                                                <label class="form-label fw-bold small text-muted">İşlem Yönü</label>
                                                <select name="fiyat_islem" class="form-select fw-bold" required>
                                                    <option value="artir">Fiyatları Artır (Zam)</option>
                                                    <option value="dusur">Fiyatları Düşür (İndirim)</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label fw-bold small text-muted">Uygulanacak Oran</label>
                                                <div class="input-group">
                                                    <span class="input-group-text bg-white fw-bold">%</span>
                                                    <input type="number" step="0.01" name="fiyat_oran" class="form-control fw-bold" required placeholder="10">
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-pill shadow-sm" onclick="return confirm('Bu işlem geri alınamaz! Toplu fiyat güncellemesini başlatmak istediğinize emin misiniz?')"><i class="fas fa-bolt me-2"></i>Güncellemeyi Başlat</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="modal fade" id="urunEkleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" id="urunForm">
                    <input type="hidden" name="urun_ekle" id="u_islem_type" value="1">
                    <input type="hidden" name="urun_id" id="u_id" value="">
                    
                    <div class="modal-header bg-light border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold text-primary" id="u_modal_title"><i class="fas fa-box-open me-2"></i>Kataloğa Yeni Kayıt Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">Kayıt Türü</label>
                                <select name="tur" id="u_tur" class="form-select fw-bold" onchange="toggleStokFields()">
                                    <option value="hizmet">Hizmet (Stoksuz)</option>
                                    <option value="urun">Fiziksel Ürün (Stoklu)</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small fw-bold text-muted">Kategori</label>
                                <select name="kategori_id" id="u_kat" class="form-select">
                                    <option value="">Kategorisiz (Genel)</option>
                                    <?php foreach($kategoriler as $k): ?><option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['kategori_adi']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">Ürün / Hizmet Adı</label>
                                <input type="text" name="hizmet_adi" id="u_ad" class="form-control fw-bold text-dark" required placeholder="Satışta görünecek isim">
                            </div>
                            <div class="col-12"><hr class="my-2 border-light"></div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">Maliyet Fiyatı (Size Gelişi)</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="maliyet_fiyati" id="u_maliyet" class="form-control" value="0" required onkeyup="calcProfit()">
                                    <span class="input-group-text bg-white">₺</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">Satış Fiyatı</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="varsayilan_fiyat" id="u_fiyat" class="form-control border-primary text-primary fw-bold" required onkeyup="calcProfit()">
                                    <span class="input-group-text bg-white text-primary fw-bold">₺</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">Tahmini Kâr</label>
                                <div class="form-control bg-light text-success fw-bold text-center border-0" id="u_kar_gosterge">0.00 ₺ (%0)</div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-muted">KDV Oranı (%)</label>
                                <select name="kdv_orani" id="u_kdv" class="form-select">
                                    <option value="0">KDV Dahil Değil (%0)</option>
                                    <option value="1">%1 KDV</option>
                                    <option value="10">%10 KDV</option>
                                    <option value="20">%20 KDV</option>
                                </select>
                            </div>
                            <div id="stokAlanlari" class="col-12 row g-3 m-0 p-0" style="display:none;">
                                <div class="col-12"><hr class="my-2 border-light"></div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted">Mevcut Stok Miktarı</label>
                                    <input type="number" name="stok_miktari" id="u_stok" class="form-control" value="0">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted">Kritik Stok Uyarısı</label>
                                    <input type="number" name="kritik_stok" id="u_kritik" class="form-control" value="5">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted">Barkod / SKU</label>
                                    <input type="text" name="barkod" id="u_barkod" class="form-control" placeholder="Örn: 8690123456789">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary px-4 fw-bold rounded-pill" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm rounded-pill" id="u_btn"><i class="fas fa-save me-1"></i>Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="hizliStokModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="hizli_stok" value="1">
                    <input type="hidden" name="stok_urun_id" id="hizli_stok_id">
                    <div class="modal-header bg-white border-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold text-dark" id="hizli_stok_title">Stok Güncelle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 text-center">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-muted small">İşlem Türü</label>
                            <select name="stok_islem" class="form-select fw-bold border-2 text-center" required>
                                <option value="ekle">Stok Ekle (+)</option>
                                <option value="cikar">Stok Düş (-)</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold text-muted small">Adet</label>
                            <input type="number" name="stok_adet" class="form-control form-control-lg fw-bold text-center border-2" value="1" min="1" required>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-secondary w-100 rounded-pill fw-bold" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold mt-2 shadow-sm">Uygula</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="barkodModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header bg-white border-0 pb-0 pt-4 px-4">
                    <h6 class="modal-title fw-bold text-dark text-truncate" id="barkodTitle">Barkod</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="barcode-box mb-3" id="printArea">
                        <svg id="barcodeSvg"></svg>
                    </div>
                    <button type="button" class="btn btn-dark w-100 fw-bold rounded-pill shadow-sm" onclick="printBarcode()"><i class="fas fa-print me-2"></i>Yazdır</button>
                </div>
            </div>
        </div>
    </div>

    <?php include 'partials/footer_yonetim.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#katalogTable').DataTable({
                language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" },
                pageLength: 25,
                columnDefs: [ { orderable: false, targets: [0, 8] } ],
                dom: "<'row mb-3'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" + "<'row'<'col-sm-12'tr>>" + "<'row mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                buttons: [
                    { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-sm btn-success shadow-sm fw-bold me-1 rounded-pill px-3', exportOptions: { columns: [1, 2, 3, 4, 5, 6, 7] } },
                    { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-sm btn-danger shadow-sm fw-bold rounded-pill px-3', exportOptions: { columns: [1, 2, 3, 4, 5, 6, 7] } }
                ]
            });
        });

        function toggleStokFields() {
            let tur = document.getElementById('u_tur').value;
            let stokAlani = document.getElementById('stokAlanlari');
            if(tur === 'urun') {
                stokAlani.style.display = 'flex';
                document.getElementById('u_stok').required = true;
                document.getElementById('u_kritik').required = true;
            } else {
                stokAlani.style.display = 'none';
                document.getElementById('u_stok').required = false;
                document.getElementById('u_kritik').required = false;
            }
        }

        function calcProfit() {
            let maliyet = parseFloat(document.getElementById('u_maliyet').value) || 0;
            let fiyat = parseFloat(document.getElementById('u_fiyat').value) || 0;
            let kar = fiyat - maliyet;
            let karYuzde = maliyet > 0 ? (kar / maliyet) * 100 : (fiyat > 0 ? 100 : 0);
            let gosterge = document.getElementById('u_kar_gosterge');
            gosterge.innerHTML = `${kar > 0 ? '+' : ''}${kar.toFixed(2)} ₺ (%${Math.round(karYuzde)})`;
            gosterge.className = `form-control fw-bold text-center border-0 ${kar > 0 ? 'bg-success bg-opacity-10 text-success' : (kar < 0 ? 'bg-danger bg-opacity-10 text-danger' : 'bg-light text-muted')}`;
        }

        function resetForm() {
            document.getElementById('u_islem_type').name = "urun_ekle";
            document.getElementById('u_id').value = "";
            document.getElementById('u_modal_title').innerHTML = '<i class="fas fa-box-open me-2"></i>Kataloğa Yeni Kayıt Ekle';
            document.getElementById('urunForm').reset();
            document.getElementById('u_tur').value = 'hizmet';
            toggleStokFields();
            calcProfit();
        }

        function editUrun(u) {
            document.getElementById('u_islem_type').name = "urun_duzenle";
            document.getElementById('u_id').value = u.id;
            document.getElementById('u_modal_title').innerHTML = '<i class="fas fa-edit me-2"></i>Kayıt Düzenle';
            document.getElementById('u_tur').value = u.tur;
            document.getElementById('u_kat').value = u.kategori_id || '';
            document.getElementById('u_ad').value = u.hizmet_adi;
            document.getElementById('u_maliyet').value = u.maliyet_fiyati;
            document.getElementById('u_fiyat').value = u.varsayilan_fiyat;
            document.getElementById('u_kdv').value = u.kdv_orani;
            document.getElementById('u_stok').value = u.stok_miktari;
            document.getElementById('u_kritik').value = u.kritik_stok;
            document.getElementById('u_barkod').value = u.barkod || '';
            toggleStokFields();
            calcProfit();
            new bootstrap.Modal(document.getElementById('urunEkleModal')).show();
        }

        function editKategori(id, ad) {
            document.getElementById('k_islem_type').name = "kategori_duzenle";
            document.getElementById('k_id').value = id;
            document.getElementById('k_ad').value = ad;
            document.getElementById('k_cancel').classList.remove('d-none');
        }

        function resetKategoriForm() {
            document.getElementById('kategoriForm').reset();
            document.getElementById('k_islem_type').name = "kategori_ekle";
            document.getElementById('k_id').value = "";
            document.getElementById('k_cancel').classList.add('d-none');
        }

        function deleteKategori(id) {
            Swal.fire({ title: 'Emin misiniz?', text: "Bu kategori silinecek. İçindeki ürünler 'Kategorisiz' durumuna düşecek.", icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal' }).then((result) => {
                if (result.isConfirmed) window.location.href = `urunler_hizmetler.php?k_sil=${id}`;
            });
        }

        function deleteUrun(id) {
            Swal.fire({ title: 'Emin misiniz?', text: "Bu kaydı siliyorsunuz.", icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal' }).then((result) => {
                if (result.isConfirmed) window.location.href = `urunler_hizmetler.php?u_sil=${id}`;
            });
        }

        function toggleCheckAll(source) {
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = source.checked);
            checkCount();
        }

        function checkCount() {
            const count = document.querySelectorAll('.row-check:checked').length;
            const btn = document.getElementById('btnTopluSil');
            if(count > 0) { btn.disabled = false; btn.innerHTML = `<i class="fas fa-trash-alt me-2"></i>Seçilenleri Sil (${count})`; } 
            else { btn.disabled = true; btn.innerHTML = `<i class="fas fa-trash-alt me-2"></i>Seçilenleri Sil`; document.getElementById('checkAll').checked = false; }
        }

        function topluSil() {
            const checked = document.querySelectorAll('.row-check:checked');
            if(checked.length === 0) return;
            let ids = []; checked.forEach(c => ids.push(c.value));
            Swal.fire({ title: 'Toplu Silme', text: `${ids.length} adet kaydı siliyorsunuz.`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, Hepsini Sil', cancelButtonText: 'İptal' }).then((result) => {
                if (result.isConfirmed) {
                    $.post('urunler_hizmetler.php', {toplu_sil: 1, secilenler: JSON.stringify(ids)}, function(res) {
                        location.href = "urunler_hizmetler.php?msg=u_silindi";
                    });
                }
            });
        }

        function openHizliStok(id, isim) {
            document.getElementById('hizli_stok_id').value = id;
            document.getElementById('hizli_stok_title').innerText = isim;
            new bootstrap.Modal(document.getElementById('hizliStokModal')).show();
        }

        function showBarcode(code, isim) {
            document.getElementById('barkodTitle').innerText = isim;
            JsBarcode("#barcodeSvg", code, { format: "CODE128", width: 2, height: 80, displayValue: true });
            new bootstrap.Modal(document.getElementById('barkodModal')).show();
        }

        function printBarcode() {
            let prtContent = document.getElementById("printArea");
            let WinPrint = window.open('', '', 'left=0,top=0,width=400,height=300,toolbar=0,scrollbars=0,status=0');
            WinPrint.document.write(`<html><head><title>Barkod Yazdir</title></head><body style="margin:0;padding:20px;text-align:center;">${prtContent.innerHTML}</body></html>`);
            WinPrint.document.close();
            WinPrint.focus();
            setTimeout(function() { WinPrint.print(); WinPrint.close(); }, 500);
        }
    </script>
</body>
</html>