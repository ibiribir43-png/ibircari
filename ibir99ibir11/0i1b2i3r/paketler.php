<?php
require_once '../templates/header.php';
global $pdo;

// --- İŞLEMLER (POST/GET) ---

// ==========================================
// 1. ANA PAKET İŞLEMLERİ
// ==========================================
if(isset($_POST['paket_ekle'])) {
    $paket_adi = trim($_POST['paket_adi']);
    $fiyat = str_replace(',', '.', $_POST['fiyat']); 
    $musteri_limiti = (int)$_POST['musteri_limiti'];
    $kullanici_limiti = (int)$_POST['kullanici_limiti'];
    $depolama_limiti = (int)$_POST['depolama_limiti'];
    $sms_limiti = (int)$_POST['sms_limiti']; 
    $is_trial = isset($_POST['is_trial']) ? 1 : 0;
    $ozellikler = trim($_POST['ozellikler']);

    try {
        $stmt = $pdo->prepare("INSERT INTO paketler (paket_adi, fiyat, musteri_limiti, kullanici_limiti, depolama_limiti, sms_limiti, ozellikler, is_trial, durum) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$paket_adi, $fiyat, $musteri_limiti, $kullanici_limiti, $depolama_limiti, $sms_limiti, $ozellikler, $is_trial]);
        setFlash("Yeni paket başarıyla oluşturuldu.", "success");
    } catch(Exception $e) {
        setFlash("Hata: " . $e->getMessage(), "danger");
    }
    header("Location: paketler.php?tab=ana_paketler");
    exit;
}

if(isset($_POST['paket_duzenle'])) {
    $paket_id = (int)$_POST['paket_id'];
    $paket_adi = trim($_POST['paket_adi']);
    $fiyat = str_replace(',', '.', $_POST['fiyat']);
    $musteri_limiti = (int)$_POST['musteri_limiti'];
    $kullanici_limiti = (int)$_POST['kullanici_limiti'];
    $depolama_limiti = (int)$_POST['depolama_limiti'];
    $sms_limiti = (int)$_POST['sms_limiti'];
    $is_trial = isset($_POST['is_trial']) ? 1 : 0;
    $ozellikler = trim($_POST['ozellikler']);

    try {
        $stmt = $pdo->prepare("UPDATE paketler SET paket_adi=?, fiyat=?, musteri_limiti=?, kullanici_limiti=?, depolama_limiti=?, sms_limiti=?, ozellikler=?, is_trial=? WHERE id=?");
        $stmt->execute([$paket_adi, $fiyat, $musteri_limiti, $kullanici_limiti, $depolama_limiti, $sms_limiti, $ozellikler, $is_trial, $paket_id]);
        setFlash("Paket bilgileri güncellendi.", "success");
    } catch(Exception $e) {
        setFlash("Hata: " . $e->getMessage(), "danger");
    }
    header("Location: paketler.php?tab=ana_paketler");
    exit;
}

if(isset($_GET['durum_degistir']) && isset($_GET['id']) && isset($_GET['tur']) && $_GET['tur'] == 'paket') {
    $id = (int)$_GET['id'];
    $yeniDurum = (int)$_GET['durum_degistir'];
    $pdo->prepare("UPDATE paketler SET durum = ? WHERE id = ?")->execute([$yeniDurum, $id]);
    setFlash("Paket durumu güncellendi.", "info");
    header("Location: paketler.php?tab=ana_paketler");
    exit;
}

if(isset($_POST['paket_sil'])) {
    $id = (int)$_POST['silinecek_id'];
    $firmaKontrol = $pdo->prepare("SELECT COUNT(*) FROM firmalar WHERE paket_id = ?");
    $firmaKontrol->execute([$id]);
    $kullananSayisi = $firmaKontrol->fetchColumn();

    if ($kullananSayisi > 0) {
        setFlash("Bu paket şu anda $kullananSayisi firma tarafından kullanılıyor. Silmek yerine pasife alabilirsiniz.", "warning");
    } else {
        $pdo->prepare("DELETE FROM paketler WHERE id=?")->execute([$id]);
        setFlash("Paket başarıyla silindi.", "danger");
    }
    header("Location: paketler.php?tab=ana_paketler");
    exit;
}


// ==========================================
// 2. EK HİZMET İŞLEMLERİ (SMS, Depolama, Trafik)
// ==========================================
if(isset($_POST['ek_hizmet_ekle'])) {
    $tip = $_POST['tip']; // 'sms', 'depolama', 'trafik'
    $baslik = trim($_POST['baslik']);
    $fiyat = str_replace(',', '.', $_POST['fiyat']);
    $deger = (int)$_POST['deger']; // SMS adedi veya MB cinsinden kota

    try {
        $stmt = $pdo->prepare("INSERT INTO ek_hizmetler (tip, baslik, deger, fiyat, durum) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$tip, $baslik, $deger, $fiyat]);
        setFlash("Yeni ek hizmet başarıyla eklendi.", "success");
    } catch(Exception $e) {
        setFlash("Hata: " . $e->getMessage(), "danger");
    }
    header("Location: paketler.php?tab=" . $tip);
    exit;
}

if(isset($_POST['ek_hizmet_duzenle'])) {
    $hizmet_id = (int)$_POST['hizmet_id'];
    $tip = $_POST['tip'];
    $baslik = trim($_POST['baslik']);
    $fiyat = str_replace(',', '.', $_POST['fiyat']);
    $deger = (int)$_POST['deger'];

    try {
        $stmt = $pdo->prepare("UPDATE ek_hizmetler SET baslik=?, deger=?, fiyat=? WHERE id=?");
        $stmt->execute([$baslik, $deger, $fiyat, $hizmet_id]);
        setFlash("Ek hizmet güncellendi.", "success");
    } catch(Exception $e) {
        setFlash("Hata: " . $e->getMessage(), "danger");
    }
    header("Location: paketler.php?tab=" . $tip);
    exit;
}

if(isset($_GET['durum_degistir']) && isset($_GET['id']) && isset($_GET['tur']) && $_GET['tur'] == 'ek_hizmet') {
    $id = (int)$_GET['id'];
    $yeniDurum = (int)$_GET['durum_degistir'];
    $aktif_tab = isset($_GET['tab']) ? $_GET['tab'] : 'ana_paketler';

    $pdo->prepare("UPDATE ek_hizmetler SET durum = ? WHERE id = ?")->execute([$yeniDurum, $id]);
    setFlash("Hizmet durumu güncellendi.", "info");
    header("Location: paketler.php?tab=" . $aktif_tab);
    exit;
}

if(isset($_POST['ek_hizmet_sil'])) {
    $id = (int)$_POST['silinecek_hizmet_id'];
    $tip = $_POST['donus_tipi'];
    
    $pdo->prepare("DELETE FROM ek_hizmetler WHERE id=?")->execute([$id]);
    setFlash("Ek hizmet başarıyla silindi.", "danger");
    header("Location: paketler.php?tab=" . $tip);
    exit;
}

// --- VERİ ÇEKME ---
$paketler = [];
$ek_sms_paketleri = [];
$ek_depolama_paketleri = [];
$ek_trafik_paketleri = [];

try {
    $paketler = $pdo->query("SELECT * FROM paketler ORDER BY is_trial DESC, fiyat ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Ek Hizmetleri Çekme
    $tum_ek_hizmetler = $pdo->query("SELECT * FROM ek_hizmetler ORDER BY deger ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($tum_ek_hizmetler as $h) {
        if($h['tip'] == 'sms') $ek_sms_paketleri[] = $h;
        elseif($h['tip'] == 'depolama') $ek_depolama_paketleri[] = $h;
        elseif($h['tip'] == 'trafik') $ek_trafik_paketleri[] = $h;
    }
} catch(Exception $e) {}

// Hangi tab açık kalsın?
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'ana_paketler';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Paketler ve Ekstra Hizmetler (Mağaza Yönetimi)</h1>
</div>

<!-- Bilgi Kartları -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-left-primary shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Ana Paketler</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($paketler) ?> Adet</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-box fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-left-success shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Ek SMS Paketleri</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($ek_sms_paketleri) ?> Adet</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-sms fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-left-info shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Ek Depolama Pkt.</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($ek_depolama_paketleri) ?> Adet</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-hdd fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-left-warning shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Ek Trafik Pkt.</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($ek_trafik_paketleri) ?> Adet</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-wifi fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SEKME BAŞLIKLARI -->
<ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-bold <?= $activeTab == 'ana_paketler' ? 'active' : '' ?>" id="ana-tab" data-bs-toggle="tab" data-bs-target="#ana" type="button" role="tab"><i class="fas fa-box me-1"></i> Ana Abonelik Paketleri</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-bold <?= $activeTab == 'sms' ? 'active text-success' : 'text-success' ?>" id="sms-tab" data-bs-toggle="tab" data-bs-target="#sms" type="button" role="tab"><i class="fas fa-sms me-1"></i> Ekstra SMS</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-bold <?= $activeTab == 'depolama' ? 'active text-info' : 'text-info' ?>" id="depo-tab" data-bs-toggle="tab" data-bs-target="#depo" type="button" role="tab"><i class="fas fa-hdd me-1"></i> Ekstra Depolama (GB)</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-bold <?= $activeTab == 'trafik' ? 'active text-warning' : 'text-warning' ?>" id="trafik-tab" data-bs-toggle="tab" data-bs-target="#trafik" type="button" role="tab"><i class="fas fa-wifi me-1"></i> Ekstra Trafik (Kota)</button>
  </li>
</ul>

<!-- SEKME İÇERİKLERİ -->
<div class="tab-content" id="myTabContent">

    <!-- 1. TAB: ANA PAKETLER -->
    <div class="tab-pane fade <?= $activeTab == 'ana_paketler' ? 'show active' : '' ?>" id="ana" role="tabpanel">
        <div class="card shadow-sm mb-4 border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">Sistemdeki Ana Abonelik Paketleri</h6>
                <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#yeniPaketModal">
                    <i class="fas fa-plus fa-sm text-white-50"></i> Yeni Paket Ekle
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Paket Adı</th>
                                <th>Fiyat (Aylık)</th>
                                <th>Müşteri Lmt.</th>
                                <th>Kullanıcı Lmt.</th>
                                <th>Depolama</th>
                                <th>SMS Lmt.</th>
                                <th>Durum</th>
                                <th class="text-end pe-4">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($paketler)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">Paket bulunamadı.</td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach($paketler as $p): ?>
                            <tr>
                                <td class="ps-4 fw-bold">
                                    <?= htmlspecialchars($p['paket_adi']) ?>
                                    <?php if($p['is_trial'] == 1): ?><span class="badge bg-info ms-1 small">Deneme</span><?php endif; ?>
                                </td>
                                <td><span class="text-success fw-bold"><?= number_format($p['fiyat'], 2) ?> ₺</span></td>
                                <td><?= $p['musteri_limiti'] == 0 ? '<span class="badge bg-dark">Sınırsız</span>' : number_format($p['musteri_limiti']) ?></td>
                                <td><?= $p['kullanici_limiti'] == 0 ? '<span class="badge bg-dark">Sınırsız</span>' : number_format($p['kullanici_limiti']) ?></td>
                                <td><?= $p['depolama_limiti'] == 0 ? '<span class="badge bg-dark">Sınırsız</span>' : number_format($p['depolama_limiti']) . ' MB' ?></td>
                                <td><?= isset($p['sms_limiti']) && $p['sms_limiti'] == 0 ? '<span class="badge bg-secondary">Yok</span>' : number_format((int)($p['sms_limiti'] ?? 0)) ?></td>
                                <td>
                                    <?php if($p['durum'] == 1): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">Pasif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if($p['durum'] == 1): ?>
                                        <a href="?durum_degistir=0&id=<?= $p['id'] ?>&tur=paket" class="btn btn-sm btn-outline-warning" title="Pasife Al"><i class="fas fa-pause"></i></a>
                                    <?php else: ?>
                                        <a href="?durum_degistir=1&id=<?= $p['id'] ?>&tur=paket" class="btn btn-sm btn-outline-success" title="Aktife Al"><i class="fas fa-play"></i></a>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#duzenleModal<?= $p['id'] ?>" title="Düzenle"><i class="fas fa-edit"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Paketi silmek istediğinize emin misiniz?');">
                                        <input type="hidden" name="paket_sil" value="1">
                                        <input type="hidden" name="silinecek_id" value="<?= $p['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Sil"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ANA PAKET DÜZENLE MODALLARI (Tablo Dışında) -->
        <?php foreach($paketler as $p): ?>
        <div class="modal fade" id="duzenleModal<?= $p['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content shadow-lg border-0">
                    <form method="POST">
                        <input type="hidden" name="paket_duzenle" value="1">
                        <input type="hidden" name="paket_id" value="<?= $p['id'] ?>">
                        <div class="modal-header bg-light">
                            <h5 class="modal-title fw-bold"><i class="fas fa-edit text-primary me-2"></i>Paketi Düzenle: <?= htmlspecialchars($p['paket_adi']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small text-muted">Paket Adı</label>
                                    <input type="text" name="paket_adi" class="form-control" value="<?= htmlspecialchars($p['paket_adi']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-muted">Aylık Fiyat (₺)</label>
                                    <input type="number" step="0.01" name="fiyat" class="form-control" value="<?= $p['fiyat'] ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small text-muted">Müşteri Limiti</label>
                                    <input type="number" name="musteri_limiti" class="form-control" value="<?= $p['musteri_limiti'] ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small text-muted">Kullanıcı Limiti</label>
                                    <input type="number" name="kullanici_limiti" class="form-control" value="<?= $p['kullanici_limiti'] ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small text-muted">Depolama (MB)</label>
                                    <input type="number" name="depolama_limiti" class="form-control" value="<?= $p['depolama_limiti'] ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small text-primary">Aylık SMS Limiti</label>
                                    <input type="number" name="sms_limiti" class="form-control border-primary" value="<?= $p['sms_limiti'] ?? 0 ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small text-muted">Paket Özellikleri</label>
                                    <textarea name="ozellikler" class="form-control" rows="3"><?= htmlspecialchars($p['ozellikler']) ?></textarea>
                                </div>
                                <div class="col-12 mt-3">
                                    <div class="form-check form-switch border p-2 rounded bg-light">
                                        <input class="form-check-input ms-1" type="checkbox" name="is_trial" id="isTrialDuzenle<?= $p['id'] ?>" <?= $p['is_trial'] == 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold ms-2" for="isTrialDuzenle<?= $p['id'] ?>">Bu bir Deneme (Trial) paketidir.</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">İptal</button>
                            <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>


    <!-- 2. TAB: EKSTRA SMS -->
    <div class="tab-pane fade <?= $activeTab == 'sms' ? 'show active' : '' ?>" id="sms" role="tabpanel">
        <div class="card shadow-sm mb-4 border-0 border-top border-success border-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-success">Ekstra SMS Satış Paketleri</h6>
                <button class="btn btn-sm btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalEkSmsEkle">
                    <i class="fas fa-plus fa-sm text-white-50"></i> Yeni SMS Paketi Ekle
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Paket Başlığı</th>
                                <th>SMS Adedi</th>
                                <th>Fiyat</th>
                                <th>Durum</th>
                                <th class="text-end pe-4">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($ek_sms_paketleri as $sms): ?>
                            <tr>
                                <td class="ps-4 fw-bold"><?= htmlspecialchars($sms['baslik']) ?></td>
                                <td><span class="badge bg-secondary"><?= number_format($sms['deger']) ?> Adet SMS</span></td>
                                <td><span class="text-success fw-bold"><?= number_format($sms['fiyat'], 2) ?> ₺</span></td>
                                <td>
                                    <?= $sms['durum'] == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>' ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="?durum_degistir=<?= $sms['durum']==1?0:1 ?>&id=<?= $sms['id'] ?>&tur=ek_hizmet&tab=sms" class="btn btn-sm btn-outline-<?= $sms['durum']==1?'warning':'success' ?>"><i class="fas fa-<?= $sms['durum']==1?'pause':'play' ?>"></i></a>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEkDuzenle<?= $sms['id'] ?>"><i class="fas fa-edit"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Silmek istediğinize emin misiniz?');">
                                        <input type="hidden" name="ek_hizmet_sil" value="1">
                                        <input type="hidden" name="silinecek_hizmet_id" value="<?= $sms['id'] ?>">
                                        <input type="hidden" name="donus_tipi" value="sms">
                                        <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SMS DÜZENLE MODALLARI (Tablo Dışında) -->
        <?php foreach($ek_sms_paketleri as $sms): ?>
        <div class="modal fade" id="modalEkDuzenle<?= $sms['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content shadow-lg border-0">
                    <form method="POST" action="paketler.php">
                        <input type="hidden" name="ek_hizmet_duzenle" value="1">
                        <input type="hidden" name="hizmet_id" value="<?= $sms['id'] ?>">
                        <input type="hidden" name="tip" value="<?= $sms['tip'] ?>">
                        <div class="modal-header bg-light border-bottom-0">
                            <h5 class="modal-title fw-bold"><i class="fas fa-edit text-primary me-2"></i>Düzenle</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-start">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Vitrin Başlığı</label>
                                <input type="text" name="baslik" class="form-control" value="<?= htmlspecialchars($sms['baslik']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Verilecek Değer (Adet)</label>
                                <input type="number" name="deger" class="form-control" value="<?= htmlspecialchars($sms['deger']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Fiyat (₺)</label>
                                <input type="number" step="0.01" name="fiyat" class="form-control" value="<?= htmlspecialchars($sms['fiyat']) ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer border-top-0 bg-light">
                            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">İptal</button>
                            <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>


    <!-- 3. TAB: EKSTRA DEPOLAMA -->
    <div class="tab-pane fade <?= $activeTab == 'depolama' ? 'show active' : '' ?>" id="depo" role="tabpanel">
        <div class="card shadow-sm mb-4 border-0 border-top border-info border-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-info">Ekstra Depolama (Disk Alanı) Paketleri</h6>
                <button class="btn btn-sm btn-info text-white shadow-sm" data-bs-toggle="modal" data-bs-target="#modalEkDepolamaEkle">
                    <i class="fas fa-plus fa-sm text-white-50"></i> Yeni Depolama Alanı Ekle
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Paket Başlığı</th>
                                <th>Verilecek Alan (MB)</th>
                                <th>Fiyat</th>
                                <th>Durum</th>
                                <th class="text-end pe-4">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($ek_depolama_paketleri as $depo): ?>
                            <tr>
                                <td class="ps-4 fw-bold"><?= htmlspecialchars($depo['baslik']) ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= number_format($depo['deger']) ?> MB</span>
                                    <small class="text-muted ms-1">(<?= round($depo['deger']/1024, 1) ?> GB)</small>
                                </td>
                                <td><span class="text-success fw-bold"><?= number_format($depo['fiyat'], 2) ?> ₺</span></td>
                                <td>
                                    <?= $depo['durum'] == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>' ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="?durum_degistir=<?= $depo['durum']==1?0:1 ?>&id=<?= $depo['id'] ?>&tur=ek_hizmet&tab=depolama" class="btn btn-sm btn-outline-<?= $depo['durum']==1?'warning':'success' ?>"><i class="fas fa-<?= $depo['durum']==1?'pause':'play' ?>"></i></a>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEkDuzenle<?= $depo['id'] ?>"><i class="fas fa-edit"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Silmek istediğinize emin misiniz?');">
                                        <input type="hidden" name="ek_hizmet_sil" value="1">
                                        <input type="hidden" name="silinecek_hizmet_id" value="<?= $depo['id'] ?>">
                                        <input type="hidden" name="donus_tipi" value="depolama">
                                        <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- DEPOLAMA DÜZENLE MODALLARI (Tablo Dışında) -->
        <?php foreach($ek_depolama_paketleri as $depo): ?>
        <div class="modal fade" id="modalEkDuzenle<?= $depo['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content shadow-lg border-0">
                    <form method="POST" action="paketler.php">
                        <input type="hidden" name="ek_hizmet_duzenle" value="1">
                        <input type="hidden" name="hizmet_id" value="<?= $depo['id'] ?>">
                        <input type="hidden" name="tip" value="<?= $depo['tip'] ?>">
                        <div class="modal-header bg-light border-bottom-0">
                            <h5 class="modal-title fw-bold"><i class="fas fa-edit text-primary me-2"></i>Düzenle</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-start">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Vitrin Başlığı</label>
                                <input type="text" name="baslik" class="form-control" value="<?= htmlspecialchars($depo['baslik']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Verilecek Değer (MB)</label>
                                <input type="number" name="deger" class="form-control" value="<?= htmlspecialchars($depo['deger']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Fiyat (₺)</label>
                                <input type="number" step="0.01" name="fiyat" class="form-control" value="<?= htmlspecialchars($depo['fiyat']) ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer border-top-0 bg-light">
                            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">İptal</button>
                            <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>


    <!-- 4. TAB: EKSTRA TRAFİK -->
    <div class="tab-pane fade <?= $activeTab == 'trafik' ? 'show active' : '' ?>" id="trafik" role="tabpanel">
        <div class="card shadow-sm mb-4 border-0 border-top border-warning border-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-warning">Ekstra Trafik (Bandwidth) Paketleri</h6>
                <button class="btn btn-sm btn-warning shadow-sm text-dark" data-bs-toggle="modal" data-bs-target="#modalEkTrafikEkle">
                    <i class="fas fa-plus fa-sm"></i> Yeni Trafik Paketi Ekle
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Paket Başlığı</th>
                                <th>Verilecek Trafik (MB)</th>
                                <th>Fiyat</th>
                                <th>Durum</th>
                                <th class="text-end pe-4">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($ek_trafik_paketleri as $trafik): ?>
                            <tr>
                                <td class="ps-4 fw-bold"><?= htmlspecialchars($trafik['baslik']) ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= number_format($trafik['deger']) ?> MB</span>
                                    <small class="text-muted ms-1">(<?= round($trafik['deger']/1024, 1) ?> GB)</small>
                                </td>
                                <td><span class="text-success fw-bold"><?= number_format($trafik['fiyat'], 2) ?> ₺</span></td>
                                <td>
                                    <?= $trafik['durum'] == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>' ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="?durum_degistir=<?= $trafik['durum']==1?0:1 ?>&id=<?= $trafik['id'] ?>&tur=ek_hizmet&tab=trafik" class="btn btn-sm btn-outline-<?= $trafik['durum']==1?'warning':'success' ?>"><i class="fas fa-<?= $trafik['durum']==1?'pause':'play' ?>"></i></a>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEkDuzenle<?= $trafik['id'] ?>"><i class="fas fa-edit"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Silmek istediğinize emin misiniz?');">
                                        <input type="hidden" name="ek_hizmet_sil" value="1">
                                        <input type="hidden" name="silinecek_hizmet_id" value="<?= $trafik['id'] ?>">
                                        <input type="hidden" name="donus_tipi" value="trafik">
                                        <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TRAFİK DÜZENLE MODALLARI (Tablo Dışında) -->
        <?php foreach($ek_trafik_paketleri as $trafik): ?>
        <div class="modal fade" id="modalEkDuzenle<?= $trafik['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content shadow-lg border-0">
                    <form method="POST" action="paketler.php">
                        <input type="hidden" name="ek_hizmet_duzenle" value="1">
                        <input type="hidden" name="hizmet_id" value="<?= $trafik['id'] ?>">
                        <input type="hidden" name="tip" value="<?= $trafik['tip'] ?>">
                        <div class="modal-header bg-light border-bottom-0">
                            <h5 class="modal-title fw-bold"><i class="fas fa-edit text-primary me-2"></i>Düzenle</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-start">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Vitrin Başlığı</label>
                                <input type="text" name="baslik" class="form-control" value="<?= htmlspecialchars($trafik['baslik']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Verilecek Değer (MB)</label>
                                <input type="number" name="deger" class="form-control" value="<?= htmlspecialchars($trafik['deger']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">Fiyat (₺)</label>
                                <input type="number" step="0.01" name="fiyat" class="form-control" value="<?= htmlspecialchars($trafik['fiyat']) ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer border-top-0 bg-light">
                            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">İptal</button>
                            <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- ================= EKLENME MODALLARI (SABİT) ================= -->

<!-- ANA PAKET EKLE MODAL -->
<div class="modal fade" id="yeniPaketModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="paket_ekle" value="1">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Yeni Ana Paket Oluştur</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Paket Adı <span class="text-danger">*</span></label>
                            <input type="text" name="paket_adi" class="form-control" placeholder="Örn: Profesyonel Paket" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Aylık Fiyat (₺) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="fiyat" class="form-control" placeholder="Örn: 499.90" required>
                        </div>
                        
                        <div class="col-12"><hr class="my-1"></div>
                        <h6 class="fw-bold text-muted mb-0">Limit Tanımlamaları</h6>

                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Müşteri/Cari Limiti</label>
                            <input type="number" name="musteri_limiti" class="form-control" value="0" required>
                            <small class="text-muted" style="font-size: 11px;">0 = Sınırsız Müşteri</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Kullanıcı Limiti</label>
                            <input type="number" name="kullanici_limiti" class="form-control" value="0" required>
                            <small class="text-muted" style="font-size: 11px;">0 = Sınırsız Kullanıcı</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Depolama (MB)</label>
                            <input type="number" name="depolama_limiti" class="form-control" value="0" required>
                            <small class="text-muted" style="font-size: 11px;">0 = Sınırsız / 1024 = 1 GB</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-primary">Aylık SMS Limiti</label>
                            <input type="number" name="sms_limiti" class="form-control border-primary" value="0" required>
                            <small class="text-muted" style="font-size: 11px;">0 = SMS Yok</small>
                        </div>
                        
                        <div class="col-12 mt-3">
                            <label class="form-label fw-bold">Paket Özellikleri</label>
                            <textarea name="ozellikler" class="form-control" rows="3" placeholder="Sınırsız fatura kesimi&#10;7/24 Destek&#10;Gelişmiş Raporlar..."></textarea>
                        </div>
                        
                        <div class="col-12 mt-3">
                            <div class="form-check form-switch border p-3 rounded bg-light border-info">
                                <input class="form-check-input ms-1" type="checkbox" name="is_trial" id="isTrialEkle" style="transform: scale(1.3);">
                                <label class="form-check-label fw-bold ms-3" for="isTrialEkle">Bu bir Ücretsiz Deneme (Trial) paketidir.</label>
                                <div class="small text-muted ms-3 mt-1">Sisteme ilk kayıt olan firmalara varsayılan olarak bu paket atanabilir.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i> Paketi Oluştur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EK SMS EKLE MODAL -->
<div class="modal fade" id="modalEkSmsEkle" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-top border-success border-3">
            <form method="POST">
                <input type="hidden" name="ek_hizmet_ekle" value="1">
                <input type="hidden" name="tip" value="sms">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sms text-success me-2"></i> Yeni SMS Paketi Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Vitrin Başlığı (Örn: 1000 Ekstra SMS)</label>
                        <input type="text" name="baslik" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Kaç Adet SMS Verilecek?</label>
                        <input type="number" name="deger" class="form-control" required placeholder="1000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Fiyat (₺)</label>
                        <input type="number" step="0.01" name="fiyat" class="form-control" required placeholder="80.00">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success">Oluştur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EK DEPOLAMA EKLE MODAL -->
<div class="modal fade" id="modalEkDepolamaEkle" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-top border-info border-3">
            <form method="POST">
                <input type="hidden" name="ek_hizmet_ekle" value="1">
                <input type="hidden" name="tip" value="depolama">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-hdd text-info me-2"></i> Yeni Depolama Alanı Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Vitrin Başlığı (Örn: 5 GB Ek Disk)</label>
                        <input type="text" name="baslik" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Kaç MB Verilecek? (1 GB = 1024 MB)</label>
                        <input type="number" name="deger" class="form-control" required placeholder="5120">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Fiyat (₺)</label>
                        <input type="number" step="0.01" name="fiyat" class="form-control" required placeholder="150.00">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-info text-white">Oluştur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EK TRAFİK EKLE MODAL -->
<div class="modal fade" id="modalEkTrafikEkle" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-top border-warning border-3">
            <form method="POST">
                <input type="hidden" name="ek_hizmet_ekle" value="1">
                <input type="hidden" name="tip" value="trafik">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-wifi text-warning me-2"></i> Yeni Trafik Paketi Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Vitrin Başlığı (Örn: 50 GB Ek Kota)</label>
                        <input type="text" name="baslik" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Kaç MB Verilecek? (1 GB = 1024 MB)</label>
                        <input type="number" name="deger" class="form-control" required placeholder="51200">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Fiyat (₺)</label>
                        <input type="number" step="0.01" name="fiyat" class="form-control" required placeholder="90.00">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-warning text-dark">Oluştur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>