<?php
session_start();
require 'baglanti.php';

if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) { header("Location: login.php"); exit; }

$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

$firma_id = $_SESSION['firma_id'];
$user_id = $_SESSION['kullanici_id'];
$mesaj = ""; $mesajTuru = "";

// Sistemdeki ilk kasayı varsayılan olarak bulalım
$varsayilan_kasa_sorgu = $db->query("SELECT id FROM finans_kasalar WHERE firma_id = '$firma_id' ORDER BY id ASC LIMIT 1");
$varsayilan_kasa_id = $varsayilan_kasa_sorgu->fetchColumn() ?: 0;

// ---------------------------------------------------------
// 1. OTOMATİK GİDER (ABONELİK) MOTORU (SESSİZ ÇALIŞIR)
// ---------------------------------------------------------
try {
    $bugun = date('Y-m-d');
    $otoSorgu = $db->query("SELECT * FROM finans_duzenli_giderler WHERE firma_id = '$firma_id' AND durum = 1 AND sonraki_islem_tarihi <= '$bugun'");
    $abonelikler = $otoSorgu->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($abonelikler as $ab) {
        $ab_id = $ab['id'];
        $aciklama = "Otomatik Abonelik/Düzenli Gider: " . $ab['gider_adi'];
        
        $db->prepare("INSERT INTO finans_giderler (firma_id, kategori_id, kasa_id, tutar, net_tutar, aciklama, islem_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$firma_id, $ab['kategori_id'], $ab['kasa_id'], $ab['tutar'], $ab['tutar'], $aciklama, $bugun]);
        $yeni_gider_id = $db->lastInsertId();

        $db->prepare("INSERT INTO finans_kasa_hareketleri (firma_id, kasa_id, islem_turu, tutar, aciklama, baglanti_tipi, baglanti_id) VALUES (?, ?, 'cikis', ?, ?, 'gider', ?)")
           ->execute([$firma_id, $ab['kasa_id'], $ab['tutar'], $aciklama, $yeni_gider_id]);

        $yeni_tarih = $ab['tekrar_periyodu'] == 'aylik' ? date('Y-m-d', strtotime('+1 month', strtotime($ab['sonraki_islem_tarihi']))) : date('Y-m-d', strtotime('+1 year', strtotime($ab['sonraki_islem_tarihi'])));
        $db->prepare("UPDATE finans_duzenli_giderler SET sonraki_islem_tarihi = ? WHERE id = ?")->execute([$yeni_tarih, $ab_id]);
        sistemLog($db, 'Otomasyon', 'Düzenli Gider İşlendi', "{$ab['gider_adi']} otomatik kasadan düşüldü.");
    }
} catch(Exception $e) {}

// ---------------------------------------------------------
// 2. MANUEL İŞLEMLER (POST/GET)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kategori_ekle'])) {
    $k_ad = trim($_POST['kategori_adi']);
    $butce = (float)str_replace(',', '.', $_POST['aylik_butce']);
    if (!empty($k_ad)) {
        $db->prepare("INSERT INTO gider_kategorileri (firma_id, kategori_adi, aylik_butce) VALUES (?, ?, ?)")->execute([$firma_id, $k_ad, $butce]);
        header("Location: giderler.php?tab=kategoriler&msg=k_eklendi"); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['abonelik_ekle'])) {
    $k_id = (int)$_POST['kategori_id'];
    $kasa_id = !empty($_POST['kasa_id']) ? (int)$_POST['kasa_id'] : $varsayilan_kasa_id;
    $g_ad = trim($_POST['gider_adi']);
    $tutar = (float)str_replace(',', '.', $_POST['tutar']);
    $periyot = $_POST['tekrar_periyodu'];
    $ilk_tarih = $_POST['sonraki_islem_tarihi'];

    $db->prepare("INSERT INTO finans_duzenli_giderler (firma_id, kategori_id, kasa_id, gider_adi, tutar, tekrar_periyodu, sonraki_islem_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?)")
       ->execute([$firma_id, $k_id, $kasa_id, $g_ad, $tutar, $periyot, $ilk_tarih]);
    sistemLog($db, 'Otomasyon', 'Abonelik Kuruldu', "$g_ad eklendi.");
    header("Location: giderler.php?tab=abonelikler&msg=ab_eklendi"); exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['gider_ekle'])) {
    $kategori_id = (int)$_POST['kategori_id'];
    $kasa_id = !empty($_POST['kasa_id']) ? (int)$_POST['kasa_id'] : $varsayilan_kasa_id;
    $tarih = $_POST['islem_tarihi'];
    $fis_no = trim($_POST['fis_fatura_no']);
    $aciklama = trim($_POST['aciklama']);
    
    $tutar = (float)str_replace(',', '.', $_POST['tutar']);
    $kdv_orani = (int)$_POST['kdv_orani'];
    
    $net_tutar = $tutar / (1 + ($kdv_orani / 100));
    $kdv_tutari = $tutar - $net_tutar;

    $belge_yolu = null;
    if (isset($_FILES['belge']) && $_FILES['belge']['error'] == 0) {
        $upload_dir = 'uploads/giderler/' . $firma_id . '/';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
        $uzanti = pathinfo($_FILES['belge']['name'], PATHINFO_EXTENSION);
        $dosya_adi = 'gider_' . time() . '_' . rand(100,999) . '.' . $uzanti;
        if (move_uploaded_file($_FILES['belge']['tmp_name'], $upload_dir . $dosya_adi)) $belge_yolu = $upload_dir . $dosya_adi;
    }

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO finans_giderler (firma_id, kategori_id, kasa_id, tutar, kdv_orani, kdv_tutari, net_tutar, fis_fatura_no, aciklama, belge_yolu, islem_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$firma_id, $kategori_id, $kasa_id, $tutar, $kdv_orani, $kdv_tutari, $net_tutar, $fis_no, $aciklama, $belge_yolu, $tarih]);
        $gider_id = $db->lastInsertId();

        $kasa_aciklama = "Gider Çıkışı: " . $aciklama;
        $db->prepare("INSERT INTO finans_kasa_hareketleri (firma_id, kasa_id, islem_turu, tutar, aciklama, baglanti_tipi, baglanti_id) VALUES (?, ?, 'cikis', ?, ?, 'gider', ?)")
           ->execute([$firma_id, $kasa_id, $tutar, $kasa_aciklama, $gider_id]);

        $db->commit();
        sistemLog($db, 'Finans', 'Gider Eklendi', "$tutar ₺ kasadan düşüldü.");
        header("Location: giderler.php?msg=g_eklendi"); exit;
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: giderler.php?msg=hata"); exit;
    }
}

if (isset($_GET['g_sil'])) {
    $g_id = (int)$_GET['g_sil'];
    try {
        $db->beginTransaction();
        $gider = $db->prepare("SELECT belge_yolu FROM finans_giderler WHERE id = ? AND firma_id = ?");
        $gider->execute([$g_id, $firma_id]);
        $gd = $gider->fetch(PDO::FETCH_ASSOC);

        if($gd) {
            if($gd['belge_yolu'] && file_exists(__DIR__ . '/' . $gd['belge_yolu'])) @unlink(__DIR__ . '/' . $gd['belge_yolu']);
            $db->prepare("DELETE FROM finans_kasa_hareketleri WHERE baglanti_tipi = 'gider' AND baglanti_id = ? AND firma_id = ?")->execute([$g_id, $firma_id]);
            $db->prepare("DELETE FROM finans_giderler WHERE id = ? AND firma_id = ?")->execute([$g_id, $firma_id]);
            $db->commit();
            header("Location: giderler.php?msg=g_silindi"); exit;
        }
    } catch (Exception $e) { $db->rollBack(); header("Location: giderler.php?msg=hata"); exit; }
}

if (isset($_GET['ab_sil'])) {
    $db->prepare("DELETE FROM finans_duzenli_giderler WHERE id = ? AND firma_id = ?")->execute([(int)$_GET['ab_sil'], $firma_id]);
    header("Location: giderler.php?tab=abonelikler&msg=ab_silindi"); exit;
}

if(isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if($m=='g_eklendi') { $mesaj = "Gider kaydedildi ve kasadan düşüldü."; $mesajTuru = "success"; }
    elseif($m=='g_silindi') { $mesaj = "Gider iptal edildi, tutar kasaya iade edildi."; $mesajTuru = "warning"; }
    elseif($m=='k_eklendi') { $mesaj = "Kategori oluşturuldu."; $mesajTuru = "success"; }
    elseif($m=='ab_eklendi') { $mesaj = "Abonelik başarıyla oluşturuldu."; $mesajTuru = "success"; }
    elseif($m=='ab_silindi') { $mesaj = "Düzenli ödeme talimatı iptal edildi."; $mesajTuru = "warning"; }
    elseif($m=='hata') { $mesaj = "Kritik bir hata oluştu!"; $mesajTuru = "danger"; }
}

// ---------------------------------------------------------
// 3. VERİLERİ ÇEK VE ANALİZ ET
// ---------------------------------------------------------
$ay_baslangic = date('Y-m-01');
$ay_bitis = date('Y-m-t');

$kategoriler = $db->query("
    SELECT k.*, 
    COALESCE((SELECT SUM(tutar) FROM finans_giderler WHERE kategori_id = k.id AND islem_tarihi BETWEEN '$ay_baslangic' AND '$ay_bitis'), 0) as bu_ay_harcanan
    FROM gider_kategorileri k WHERE k.firma_id = '$firma_id' ORDER BY k.kategori_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$butce_asimlari = [];
foreach($kategoriler as $k) {
    if($k['aylik_butce'] > 0 && $k['bu_ay_harcanan'] > $k['aylik_butce']) {
        $butce_asimlari[] = $k;
    }
}

$abonelik_listesi = $db->query("SELECT d.*, k.kategori_adi FROM finans_duzenli_giderler d JOIN gider_kategorileri k ON d.kategori_id = k.id WHERE d.firma_id = '$firma_id' ORDER BY d.sonraki_islem_tarihi ASC")->fetchAll(PDO::FETCH_ASSOC);

$giderler = $db->query("
    SELECT g.*, k.kategori_adi
    FROM finans_giderler g
    JOIN gider_kategorileri k ON g.kategori_id = k.id
    WHERE g.firma_id = '$firma_id'
    ORDER BY g.islem_tarihi DESC, g.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$istatistikler = [
    'bu_ay_toplam' => $db->query("SELECT COALESCE(SUM(tutar),0) FROM finans_giderler WHERE firma_id = '$firma_id' AND islem_tarihi BETWEEN '$ay_baslangic' AND '$ay_bitis'")->fetchColumn(),
    'bu_ay_kdv' => $db->query("SELECT COALESCE(SUM(kdv_tutari),0) FROM finans_giderler WHERE firma_id = '$firma_id' AND islem_tarihi BETWEEN '$ay_baslangic' AND '$ay_bitis'")->fetchColumn(),
];

// --- GRAFİK VERİLERİ ---
$cat_grafik_veri = $db->query("SELECT k.kategori_adi, SUM(g.tutar) as top FROM finans_giderler g JOIN gider_kategorileri k ON g.kategori_id = k.id WHERE g.firma_id = '$firma_id' GROUP BY g.kategori_id ORDER BY top DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$aylik_trend_veri = $db->query("SELECT MONTH(islem_tarihi) as ay, SUM(tutar) as top FROM finans_giderler WHERE firma_id = '$firma_id' AND YEAR(islem_tarihi) = YEAR(CURDATE()) GROUP BY MONTH(islem_tarihi) ORDER BY ay ASC")->fetchAll(PDO::FETCH_ASSOC);

$cg_labels = []; $cg_data = [];
foreach($cat_grafik_veri as $cv) { $cg_labels[] = $cv['kategori_adi']; $cg_data[] = $cv['top']; }

$aylar_tr = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
$ag_labels = []; $ag_data = [];
for($i=1; $i<=12; $i++) {
    $ag_labels[] = $aylar_tr[$i];
    $bulundu = false;
    foreach($aylik_trend_veri as $av) { if($av['ay'] == $i) { $ag_data[] = $av['top']; $bulundu = true; break; } }
    if(!$bulundu) $ag_data[] = 0;
}

$activeTab = $_GET['tab'] ?? 'giderler';
$page_title = "Gider, Bütçe ve Abonelik Yönetimi";
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
        .nav-tabs-custom .nav-link.active { color: #e74a3b; border-bottom-color: #e74a3b; background: transparent; }
        table.dataTable tbody tr { transition: 0.2s; background-color: #fff; }
        table.dataTable tbody tr:hover { background-color: #f8f9fa; }
        
        /* Tesseract OCR Alanı */
        .ocr-zone { border: 2px dashed #e74a3b; border-radius: 12px; padding: 20px; text-align: center; background: #fff5f5; cursor: pointer; transition: 0.3s; }
        .ocr-zone:hover { background: #ffe5e5; }
        .scanner-line { width: 100%; height: 3px; background: #e74a3b; position: absolute; top: 0; left: 0; box-shadow: 0 0 10px #e74a3b; display: none; animation: scan 2s linear infinite; }
        @keyframes scan { 0% { top: 0; } 50% { top: 100%; } 100% { top: 0; } }
    </style>
</head>
<body class="yonetim-body">
    <?php include 'partials/navbar.php'; ?>
    <div class="container-fluid pb-5 px-4 mt-4">
        
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h3 class="fw-bold text-gray-800 mb-1"><i class="fas fa-file-invoice-dollar text-danger me-2"></i>Gider ve Masraf Yönetimi</h3>
                <p class="text-muted small mb-0">Yapay Zeka ile fiş okuma ve bütçe alarmları merkezi.</p>
            </div>
            <div class="col-md-6 text-end">
                <button class="btn btn-danger fw-bold shadow-sm px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#giderEkleModal"><i class="fas fa-magic me-2"></i>Yapay Zeka İle Gider Ekle</button>
            </div>
        </div>

        <?php if(!empty($butce_asimlari)): ?>
            <div class="alert alert-danger shadow-sm border-danger border-2 rounded-4 mb-4 d-flex align-items-center">
                <i class="fas fa-siren-on fa-3x me-4 text-danger fast-spin"></i>
                <div>
                    <h5 class="fw-bold mb-1 text-danger">KIRMIZI ALARM: Bütçe Aşımı Tespit Edildi!</h5>
                    <p class="mb-0 small">Aşağıdaki kategorilerde bu ay için belirlediğiniz limiti aştınız:</p>
                    <ul class="mb-0 mt-2 fw-bold">
                        <?php foreach($butce_asimlari as $b): ?>
                            <li><?= htmlspecialchars($b['kategori_adi']) ?> (Limit: <?= number_format($b['aylik_butce']) ?> ₺ / Harcanan: <?= number_format($b['bu_ay_harcanan']) ?> ₺)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if($mesaj): ?>
            <div class="alert alert-<?= $mesajTuru ?> alert-dismissible fade show shadow-sm border-0 rounded-3">
                <?= $mesaj ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-xl-4 col-md-6">
                <div class="card stat-card border-start border-danger border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-danger text-uppercase mb-1">Bu Ayki Net Gider</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= number_format($istatistikler['bu_ay_toplam'], 2, ',', '.') ?> ₺</div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-danger bg-opacity-10 text-danger"><i class="fas fa-arrow-down"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-4 col-md-6">
                <div class="card stat-card border-start border-primary border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-primary text-uppercase mb-1">Devlete Ödenecek KDV İndirimi</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= number_format($istatistikler['bu_ay_kdv'], 2, ',', '.') ?> ₺</div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-primary bg-opacity-10 text-primary"><i class="fas fa-percent"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-md-12">
                <div class="card stat-card border-start border-info border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-info text-uppercase mb-1">Aktif Abonelikler (Oto. Gider)</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= count($abonelik_listesi) ?> Adet</div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-info bg-opacity-10 text-info"><i class="fas fa-robot"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <ul class="nav nav-tabs-custom bg-white" role="tablist">
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'giderler' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#giderlerTab"><i class="fas fa-list me-2"></i>Gider Geçmişi</button></li>
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'analiz' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#analizTab"><i class="fas fa-chart-pie me-2"></i>Gider Analizi (Grafikler)</button></li>
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'kategoriler' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#kategorilerTab"><i class="fas fa-tags me-2"></i>Bütçe & Kategoriler</button></li>
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'abonelikler' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#aboneliklerTab"><i class="fas fa-sync-alt me-2"></i>Abonelikler</button></li>
            </ul>

            <div class="tab-content bg-white p-4">
                
                <!-- 1. GİDER LİSTESİ -->
                <div class="tab-pane fade <?= $activeTab == 'giderler' ? 'show active' : '' ?>" id="giderlerTab">
                    <div class="table-responsive">
                        <table id="giderlerTable" class="table table-hover align-middle w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Tarih</th>
                                    <th>Kategori</th>
                                    <th>Açıklama / Fiş No</th>
                                    <th class="text-center">Belge</th>
                                    <th class="text-end">Tutar (KDV Dahil)</th>
                                    <th class="text-end" data-orderable="false">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($giderler as $g): ?>
                                <tr>
                                    <td data-order="<?= date('Ymd', strtotime($g['islem_tarihi'])) ?>"><span class="fw-bold text-dark"><?= date('d.m.Y', strtotime($g['islem_tarihi'])) ?></span></td>
                                    <td><span class="badge bg-danger bg-opacity-10 text-danger border border-danger mb-1"><?= htmlspecialchars($g['kategori_adi']) ?></span></td>
                                    <td>
                                        <div class="small text-muted fw-bold"><?= htmlspecialchars($g['aciklama']) ?></div>
                                        <?php if($g['fis_fatura_no']): ?><div class="small text-primary"><i class="fas fa-receipt me-1"></i> Fiş No: <?= htmlspecialchars($g['fis_fatura_no']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if($g['belge_yolu']): ?>
                                            <a href="<?= $g['belge_yolu'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill"><i class="fas fa-paperclip"></i> Göster</a>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-danger fw-bold fs-6">
                                        -<?= number_format($g['tutar'], 2, ',', '.') ?> ₺
                                        <?php if($g['kdv_orani'] > 0): ?><br><small class="text-muted" style="font-size:10px;">İçinde %<?= $g['kdv_orani'] ?> KDV var</small><?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-danger border-0" onclick="deleteGider(<?= $g['id'] ?>)"><i class="fas fa-trash"></i> İptal</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2. ANALİZ GRAFİKLERİ -->
                <div class="tab-pane fade <?= $activeTab == 'analiz' ? 'show active' : '' ?>" id="analizTab">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="card border border-light shadow-sm h-100 rounded-4">
                                <div class="card-body p-4 text-center">
                                    <h6 class="fw-bold text-dark mb-4"><i class="fas fa-chart-pie text-warning me-2"></i>Gider Dağılımı (Kategori Bazlı)</h6>
                                    <div style="height: 300px; position: relative;">
                                        <canvas id="catPieChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card border border-light shadow-sm h-100 rounded-4">
                                <div class="card-body p-4 text-center">
                                    <h6 class="fw-bold text-dark mb-4"><i class="fas fa-chart-bar text-primary me-2"></i>Aylık Gider Trendi (<?= date('Y') ?>)</h6>
                                    <div style="height: 300px; position: relative;">
                                        <canvas id="monthBarChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. BÜTÇE VE KATEGORİLER -->
                <div class="tab-pane fade <?= $activeTab == 'kategoriler' ? 'show active' : '' ?>" id="kategorilerTab">
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <h6 class="fw-bold text-dark mb-3"><i class="fas fa-chart-line text-primary me-2"></i>Bu Ayki Bütçe Durumu</h6>
                            <div class="row g-3">
                                <?php foreach($kategoriler as $k): 
                                    if($k['aylik_butce'] > 0) {
                                        $yuzde = min(100, ($k['bu_ay_harcanan'] / $k['aylik_butce']) * 100);
                                        $renk = $yuzde > 90 ? 'bg-danger' : ($yuzde > 75 ? 'bg-warning' : 'bg-success');
                                    } else {
                                        $yuzde = 0; $renk = 'bg-secondary';
                                    }
                                ?>
                                <div class="col-md-6">
                                    <div class="border rounded p-3 position-relative">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="fw-bold text-dark small"><?= htmlspecialchars($k['kategori_adi']) ?></span>
                                            <span class="fw-bold small <?= $yuzde > 90 ? 'text-danger' : 'text-muted' ?>">
                                                <?= number_format($k['bu_ay_harcanan']) ?> / <?= $k['aylik_butce']>0 ? number_format($k['aylik_butce']).' ₺' : 'Limitsiz' ?>
                                            </span>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar <?= $renk ?>" style="width: <?= $yuzde ?>%"></div>
                                        </div>
                                        <button class="btn btn-sm btn-light border-0 position-absolute top-0 end-0 m-1 text-danger" onclick="deleteKategori(<?= $k['id'] ?>)"><i class="fas fa-times"></i></button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm bg-light rounded-4">
                                <div class="card-body p-4">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-plus-circle text-danger me-2"></i>Yeni Kategori ve Bütçe Belirle</h6>
                                    <form method="POST">
                                        <input type="hidden" name="kategori_ekle" value="1">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold">Kategori Adı</label>
                                            <input type="text" name="kategori_adi" class="form-control border-danger" required placeholder="Örn: Yemek, Yakıt">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold">Aylık Bütçe Sınırı (₺)</label>
                                            <input type="number" step="0.01" name="aylik_butce" class="form-control" value="0">
                                            <div class="form-text" style="font-size:11px;">0 bırakırsanız limit koyulmaz. Sınır aşılırsa uyarı verir.</div>
                                        </div>
                                        <button type="submit" class="btn btn-danger w-100 fw-bold rounded-pill shadow-sm">Kategoriyi Kaydet</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. OTOMATİK ABONELİKLER (CRON) -->
                <div class="tab-pane fade <?= $activeTab == 'abonelikler' ? 'show active' : '' ?>" id="aboneliklerTab">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="alert alert-info small shadow-sm border-0 mb-4">
                                <i class="fas fa-robot me-2"></i> <b>Otomasyon Motoru Aktif:</b> Aşağıdaki listede bulunan giderler, günü geldiğinde siz sisteme girdiğiniz an arka planda <b>otomatik olarak kasadan düşülür</b> ve bir sonraki ödeme periyoduna ayarlanır. (Kira, Yazılım, Aidat vb. için mükemmeldir).
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Gider (Abonelik) Adı</th>
                                            <th>Tutar</th>
                                            <th>Periyot</th>
                                            <th>Sonraki Çekim Tarihi</th>
                                            <th class="text-end">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($abonelik_listesi)): ?><tr><td colspan="5" class="text-center py-4 text-muted">Aktif aboneliğiniz bulunmuyor.</td></tr><?php endif; ?>
                                        <?php foreach($abonelik_listesi as $ab): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($ab['gider_adi']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($ab['kategori_adi']) ?></small>
                                            </td>
                                            <td class="fw-bold text-danger"><?= number_format($ab['tutar'], 2) ?> ₺</td>
                                            <td><span class="badge bg-primary rounded-pill"><?= $ab['tekrar_periyodu'] == 'aylik' ? 'Her Ay' : 'Her Yıl' ?></span></td>
                                            <td><span class="fw-bold text-dark"><?= date('d.m.Y', strtotime($ab['sonraki_islem_tarihi'])) ?></span></td>
                                            <td class="text-end"><a href="?ab_sil=<?= $ab['id'] ?>" class="btn btn-sm btn-outline-danger border-0" onclick="return confirm('Bu otomatik ödemeyi iptal etmek istiyor musunuz?')"><i class="fas fa-times"></i> Durdur</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm bg-light rounded-4">
                                <div class="card-body p-4">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-sync-alt text-primary me-2"></i>Yeni Otomatik Ödeme Ekle</h6>
                                    <form method="POST">
                                        <input type="hidden" name="abonelik_ekle" value="1">
                                        <!-- Varsayılan Kasa Gizli Input -->
                                        <input type="hidden" name="kasa_id" value="<?= $varsayilan_kasa_id ?>">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">Abonelik Adı</label>
                                            <input type="text" name="gider_adi" class="form-control fw-bold" required placeholder="Örn: Dükkan Kirası">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">Kategori</label>
                                            <select name="kategori_id" class="form-select" required>
                                                <?php foreach($kategoriler as $k): ?><option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['kategori_adi']) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="row g-2 mb-4">
                                            <div class="col-6">
                                                <label class="form-label small fw-bold text-muted">Tutar (TL)</label>
                                                <input type="number" step="0.01" name="tutar" class="form-control text-danger fw-bold" required placeholder="0.00">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small fw-bold text-muted">Periyot</label>
                                                <select name="tekrar_periyodu" class="form-select fw-bold text-primary">
                                                    <option value="aylik">Her Ay</option><option value="yillik">Her Yıl</option>
                                                </select>
                                            </div>
                                            <div class="col-12 mt-2">
                                                <label class="form-label small fw-bold text-muted">İlk Çekim Ne Zaman Başlasın?</label>
                                                <input type="date" name="sonraki_islem_tarihi" class="form-control" required value="<?= date('Y-m-d', strtotime('+1 month')) ?>">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill shadow-sm"><i class="fas fa-robot me-1"></i> Robotu Başlat</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- HIZLI & TEMİZ GİDER EKLEME MODALI -->
    <div class="modal fade" id="giderEkleModal" tabindex="-1">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" enctype="multipart/form-data" id="giderEkleForm">
                    <input type="hidden" name="gider_ekle" value="1">
                    <!-- Varsayılan Kasa Gizli Olarak Gidiyor -->
                    <input type="hidden" name="kasa_id" value="<?= $varsayilan_kasa_id ?>">
                    
                    <div class="modal-header bg-danger text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold"><i class="fas fa-minus-circle me-2"></i>Hızlı Gider İşle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted">İşlem Tarihi</label>
                                <input type="date" name="islem_tarihi" class="form-control fw-bold" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted">Kategori</label>
                                <select name="kategori_id" class="form-select fw-bold border-danger" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($kategoriler as $k): ?><option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['kategori_adi']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-8">
                                <label class="form-label small fw-bold text-muted">Toplam Tutar</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="tutar" id="mainTutar" class="form-control border-danger text-danger fw-bold fs-5" required placeholder="0.00">
                                    <span class="input-group-text bg-white text-danger fw-bold">₺</span>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-bold text-muted">KDV (%)</label>
                                <select name="kdv_orani" class="form-select">
                                    <option value="0">Yok</option><option value="1">%1</option><option value="10">%10</option><option value="20">%20</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Açıklama / Masraf Detayı</label>
                            <input type="text" name="aciklama" id="aciklamaInput" class="form-control fw-bold" required placeholder="Örn: Elektrik faturası...">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Fiş/Fatura No (Opsiyonel)</label>
                            <input type="text" name="fis_fatura_no" class="form-control" placeholder="GIB2026...">
                        </div>

                        <!-- YAPAY ZEKA GÖRSEL ALANI KÜÇÜLTÜLDÜ -->
                        <div class="border rounded-3 p-3 bg-white text-center position-relative" style="cursor:pointer;" onclick="document.getElementById('aiImageInput').click()">
                            <i class="fas fa-camera fa-2x text-primary mb-2" id="ocrIcon"></i>
                            <h6 class="fw-bold small text-dark mb-0" id="ocrTitle">📸 Fiş Tara (Yapay Zeka)</h6>
                            <small class="text-muted d-block mt-1" id="ocrSubtitle" style="font-size:11px;">Tutar otomatik okunur.</small>
                            <input type="file" name="belge" id="aiImageInput" accept="image/*" style="display: none;" onchange="processOCR(this)">
                            
                            <img id="ocrPreview" src="" class="img-fluid mt-2 rounded shadow-sm d-none" style="max-height: 100px; width: 100%; object-fit: cover;">
                            <div id="ocrStatus" class="alert alert-info small fw-bold mt-2 mb-0 d-none py-1"><i class="fas fa-spinner fa-spin me-2"></i>Okunuyor...</div>
                        </div>

                    </div>
                    <div class="modal-footer border-top-0 bg-white py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary px-4 fw-bold rounded-pill" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger px-4 fw-bold shadow-sm rounded-pill"><i class="fas fa-check-circle me-1"></i>Kaydet</button>
                    </div>
                </form>
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
    <!-- OCR YAPAY ZEKA KÜTÜPHANESİ -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        $(document).ready(function() {
            $('#giderlerTable').DataTable({
                language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" },
                pageLength: 25,
                order: [[0, "desc"]],
                columnDefs: [ { orderable: false, targets: [3, 5] } ],
                dom: "<'row mb-3'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" + "<'row'<'col-sm-12'tr>>" + "<'row mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                buttons: [ { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-sm btn-success shadow-sm fw-bold rounded-pill px-3', exportOptions: { columns: [0, 1, 2, 4] } } ]
            });

            // MODAL TEMİZLEME İŞLEMİ (KAPATINCA SIFIRLA)
            $('#giderEkleModal').on('hidden.bs.modal', function () {
                $('#giderEkleForm')[0].reset();
                $('#ocrPreview').addClass('d-none').attr('src', '');
                $('#ocrStatus').addClass('d-none');
                $('#ocrIcon').show();
                $('#ocrTitle').show();
                $('#ocrSubtitle').show();
            });

            // CHART.JS GRAFİKLERİ
            const pCtx = document.getElementById('catPieChart').getContext('2d');
            new Chart(pCtx, {
                type: 'doughnut',
                data: { labels: <?= json_encode($cg_labels) ?>, datasets: [{ data: <?= json_encode($cg_data) ?>, backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796', '#5a5c69', '#f8f9fc', '#d1d3e2', '#e3e6f0'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'right' } } }
            });

            const bCtx = document.getElementById('monthBarChart').getContext('2d');
            new Chart(bCtx, {
                type: 'bar',
                data: { labels: <?= json_encode($ag_labels) ?>, datasets: [{ label: "Gider (₺)", data: <?= json_encode($ag_data) ?>, backgroundColor: '#e74a3b', borderRadius: 6 }] },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
            });
        });

        function deleteKategori(id) {
            Swal.fire({ title: 'Emin misiniz?', text: "Bu kategori silinecek.", icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal' }).then((result) => {
                if (result.isConfirmed) window.location.href = `giderler.php?k_sil=${id}`;
            });
        }

        function deleteGider(id) {
            Swal.fire({ title: 'Gider İptal Edilecek!', text: "Bu gider tamamen silinecek ve tutar ilgili kasaya iade edilecek.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Evet, İptal Et', cancelButtonText: 'Vazgeç' }).then((result) => {
                if (result.isConfirmed) window.location.href = `giderler.php?g_sil=${id}`;
            });
        }

        // YAPAY ZEKA (OCR) İLE FİŞ OKUMA
        function processOCR(input) {
            if (input.files && input.files[0]) {
                const img = input.files[0];
                document.getElementById('ocrPreview').src = URL.createObjectURL(img);
                document.getElementById('ocrPreview').classList.remove('d-none');
                document.getElementById('ocrStatus').classList.remove('d-none');
                
                $('#ocrIcon').hide(); $('#ocrTitle').hide(); $('#ocrSubtitle').hide();

                Tesseract.recognize(img, 'tur', { logger: m => console.log(m) }).then(({ data: { text } }) => {
                    document.getElementById('ocrStatus').classList.add('d-none');
                    document.getElementById('aciklamaInput').value = "Otomatik Fiş Okuma";
                    
                    let rawText = text.toUpperCase();
                    let maxVal = 0;
                    
                    const regex = /\b\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})\b/g;
                    let matches = rawText.match(regex);
                    
                    if(matches) {
                        matches.forEach(m => {
                            let numStr = m.replace(/[^0-9,.]/g, '');
                            let lastComma = numStr.lastIndexOf(',');
                            let lastDot = numStr.lastIndexOf('.');
                            let lastSep = Math.max(lastComma, lastDot);
                            if(lastSep > -1) {
                                numStr = numStr.substring(0, lastSep).replace(/[,.]/g, '') + '.' + numStr.substring(lastSep + 1);
                            }
                            let val = parseFloat(numStr);
                            if(!isNaN(val) && val > maxVal) maxVal = val;
                        });
                    }
                    
                    if(maxVal > 0) {
                        document.getElementById('mainTutar').value = maxVal;
                        Swal.fire({icon: 'success', title: 'Okundu', text: `Tutar tespit edildi: ${maxVal} ₺`, timer: 2000, showConfirmButton: false});
                    } else {
                        Swal.fire({icon: 'warning', title: 'Bulunamadı', text: 'Tutar net değil, manuel girin.'});
                    }
                    
                }).catch(err => {
                    document.getElementById('ocrStatus').classList.add('d-none');
                    Swal.fire({icon: 'error', title: 'Hata', text: 'Yapay zeka motoruna ulaşılamadı.'});
                });
            }
        }
    </script>
</body>
</html>