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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kasa_ekle'])) {
    $ad = trim($_POST['kasa_adi']);
    $tip = $_POST['kasa_tipi'];
    $birim = $_POST['para_birimi'];
    $acilis_bakiye = (float)str_replace(',', '.', $_POST['acilis_bakiyesi']);

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO finans_kasalar (firma_id, kasa_adi, kasa_tipi, para_birimi) VALUES (?, ?, ?, ?)")->execute([$firma_id, $ad, $tip, $birim]);
        $yeni_kasa_id = $db->lastInsertId();

        if ($acilis_bakiye > 0) {
            $db->prepare("INSERT INTO finans_kasa_hareketleri (firma_id, kasa_id, islem_turu, tutar, aciklama, baglanti_tipi) VALUES (?, ?, 'giris', ?, 'Açılış Bakiyesi', 'manuel')")->execute([$firma_id, $yeni_kasa_id, $acilis_bakiye]);
        }
        $db->commit();
        sistemLog($db, 'Finans', 'Kasa Eklendi', "$ad isimli kasa/banka hesabı eklendi.");
        header("Location: kasalar.php?msg=kasa_eklendi");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: kasalar.php?msg=hata");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['islem_ekle'])) {
    $k_id = (int)$_POST['kasa_id'];
    $tur = $_POST['islem_turu'];
    $tutar = (float)str_replace(',', '.', $_POST['tutar']);
    $aciklama = trim($_POST['aciklama']);

    $db->prepare("INSERT INTO finans_kasa_hareketleri (firma_id, kasa_id, islem_turu, tutar, aciklama, baglanti_tipi) VALUES (?, ?, ?, ?, ?, 'manuel')")->execute([$firma_id, $k_id, $tur, $tutar, $aciklama]);
    sistemLog($db, 'Finans', 'Manuel Kasa İşlemi', "Kasa ID: $k_id için $tutar ₺ $tur işlemi yapıldı.");
    header("Location: kasalar.php?msg=islem_basarili");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['virman_yap'])) {
    $cikis_kasa = (int)$_POST['cikis_kasa_id'];
    $giris_kasa = (int)$_POST['giris_kasa_id'];
    $tutar = (float)str_replace(',', '.', $_POST['virman_tutari']);
    $aciklama = trim($_POST['virman_aciklama']);

    if ($cikis_kasa === $giris_kasa || $tutar <= 0) {
        header("Location: kasalar.php?msg=virman_hata");
        exit;
    }

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO finans_kasa_hareketleri (firma_id, kasa_id, karsi_kasa_id, islem_turu, tutar, aciklama, baglanti_tipi) VALUES (?, ?, ?, 'virman_cikis', ?, ?, 'manuel')")->execute([$firma_id, $cikis_kasa, $giris_kasa, $tutar, $aciklama]);
        $db->prepare("INSERT INTO finans_kasa_hareketleri (firma_id, kasa_id, karsi_kasa_id, islem_turu, tutar, aciklama, baglanti_tipi) VALUES (?, ?, ?, 'virman_giris', ?, ?, 'manuel')")->execute([$firma_id, $giris_kasa, $cikis_kasa, $tutar, $aciklama]);
        $db->commit();
        sistemLog($db, 'Finans', 'Kasa Virmanı', "Kasa $cikis_kasa den Kasa $giris_kasa ye $tutar ₺ transfer edildi.");
        header("Location: kasalar.php?msg=virman_basarili");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: kasalar.php?msg=hata");
        exit;
    }
}

if (isset($_GET['kasa_sil'])) {
    $k_id = (int)$_GET['kasa_sil'];
    $kontrol = $db->prepare("SELECT COUNT(*) FROM finans_kasa_hareketleri WHERE kasa_id = ? AND firma_id = ?");
    $kontrol->execute([$k_id, $firma_id]);
    if ($kontrol->fetchColumn() > 1) {
        header("Location: kasalar.php?msg=kasa_dolu");
        exit;
    } else {
        $db->prepare("DELETE FROM finans_kasalar WHERE id = ? AND firma_id = ?")->execute([$k_id, $firma_id]);
        sistemLog($db, 'Finans', 'Kasa Silindi', "İşlem görmemiş kasa silindi.");
        header("Location: kasalar.php?msg=kasa_silindi");
        exit;
    }
}

if (isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if ($m == 'kasa_eklendi') { $mesaj = "Hesap başarıyla eklendi."; $mesajTuru = "success"; }
    elseif ($m == 'islem_basarili') { $mesaj = "Para giriş/çıkış işlemi başarıyla kaydedildi."; $mesajTuru = "success"; }
    elseif ($m == 'virman_basarili') { $mesaj = "Para transferi (Virman) başarıyla gerçekleşti."; $mesajTuru = "success"; }
    elseif ($m == 'virman_hata') { $mesaj = "Geçersiz virman işlemi. Tutarı ve kasaları kontrol edin."; $mesajTuru = "warning"; }
    elseif ($m == 'kasa_silindi') { $mesaj = "Hesap kalıcı olarak silindi."; $mesajTuru = "warning"; }
    elseif ($m == 'kasa_dolu') { $mesaj = "Bu hesapta hareket geçmişi olduğu için silinemez."; $mesajTuru = "danger"; }
    elseif ($m == 'hata') { $mesaj = "İşlem sırasında kritik bir hata oluştu."; $mesajTuru = "danger"; }
}

$sorguKasalar = $db->query("
    SELECT k.*, 
    COALESCE((SELECT SUM(tutar) FROM finans_kasa_hareketleri WHERE kasa_id = k.id AND (islem_turu = 'giris' OR islem_turu = 'virman_giris')), 0) as toplam_giren,
    COALESCE((SELECT SUM(tutar) FROM finans_kasa_hareketleri WHERE kasa_id = k.id AND (islem_turu = 'cikis' OR islem_turu = 'virman_cikis')), 0) as toplam_cikan
    FROM finans_kasalar k 
    WHERE k.firma_id = '$firma_id' ORDER BY k.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$genel_toplam = 0;
$kasa_listesi = [];
foreach ($sorguKasalar as $k) {
    $bakiye = $k['toplam_giren'] - $k['toplam_cikan'];
    $k['guncel_bakiye'] = $bakiye;
    $genel_toplam += $bakiye;
    $kasa_listesi[] = $k;
}

$hareketler = $db->query("
    SELECT h.*, k.kasa_adi, kk.kasa_adi as karsi_kasa_adi
    FROM finans_kasa_hareketleri h
    JOIN finans_kasalar k ON h.kasa_id = k.id
    LEFT JOIN finans_kasalar kk ON h.karsi_kasa_id = kk.id
    WHERE h.firma_id = '$firma_id'
    ORDER BY h.islem_tarihi DESC LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Kasa ve Banka Yönetimi";
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
        .kasa-card { border-radius: 15px; border: none; transition: transform 0.3s, box-shadow 0.3s; background: #fff; overflow: hidden; }
        .kasa-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .kasa-header { padding: 20px; border-bottom: 1px solid rgba(0,0,0,0.05); }
        .kasa-nakit { background: linear-gradient(135deg, #1cc88a, #13855c); color: #fff; }
        .kasa-banka { background: linear-gradient(135deg, #4e73df, #224abe); color: #fff; }
        .kasa-pos { background: linear-gradient(135deg, #f6c23e, #dda20a); color: #fff; }
        .icon-bg { position: absolute; right: -20px; bottom: -20px; font-size: 100px; opacity: 0.15; transform: rotate(-15deg); }
        .dt-check { transform: scale(1.3); cursor: pointer; }
    </style>
</head>
<body class="yonetim-body">
    <?php include 'partials/navbar.php'; ?>

    <div class="container-fluid pb-5 px-4 mt-4">
        
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h3 class="fw-bold text-gray-800 mb-1"><i class="fas fa-wallet text-success me-2"></i>Kasa ve Banka Yönetimi</h3>
            </div>
            <div class="col-md-6 text-end">
                <button class="btn btn-dark fw-bold shadow-sm rounded-pill px-4 me-2" data-bs-toggle="modal" data-bs-target="#islemModal"><i class="fas fa-exchange-alt me-2"></i>Manuel Giriş/Çıkış</button>
                <button class="btn btn-warning fw-bold shadow-sm rounded-pill px-4 text-dark" data-bs-toggle="modal" data-bs-target="#virmanModal"><i class="fas fa-random me-2"></i>Kasa Arası Virman</button>
            </div>
        </div>

        <?php if($mesaj): ?>
            <div class="alert alert-<?= $mesajTuru ?> alert-dismissible fade show shadow-sm border-0 rounded-3">
                <?= $mesaj ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-5">
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="kasa-card shadow-sm h-100 d-flex flex-column border border-2 border-primary border-dashed bg-light text-center" style="cursor:pointer;" data-bs-toggle="modal" data-bs-target="#kasaEkleModal">
                    <div class="card-body d-flex flex-column align-items-center justify-content-center p-4">
                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center mb-3" style="width:60px; height:60px;">
                            <i class="fas fa-plus fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-primary mb-1">Yeni Hesap Tanımla</h6>
                        <small class="text-muted">Kasa, Banka, Kredi Kartı (POS)</small>
                    </div>
                </div>
            </div>

            <?php foreach($kasa_listesi as $k): 
                $bg_class = $k['kasa_tipi'] == 'nakit' ? 'kasa-nakit' : ($k['kasa_tipi'] == 'banka' ? 'kasa-banka' : 'kasa-pos');
                $icon = $k['kasa_tipi'] == 'nakit' ? 'fa-money-bill-wave' : ($k['kasa_tipi'] == 'banka' ? 'fa-university' : 'fa-credit-card');
            ?>
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="kasa-card shadow-sm h-100 position-relative <?= $bg_class ?>">
                    <i class="fas <?= $icon ?> icon-bg"></i>
                    <div class="kasa-header d-flex justify-content-between align-items-center">
                        <div class="fw-bold text-truncate pe-2" style="font-size: 1.1rem;"><?= htmlspecialchars($k['kasa_adi']) ?></div>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-light bg-opacity-25 text-white border-0" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li><a class="dropdown-item fw-bold text-danger" href="#" onclick="kasaSil(<?= $k['id'] ?>)"><i class="fas fa-trash me-2"></i>Kalıcı Sil</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body p-4 position-relative z-1">
                        <div class="small text-white-50 fw-bold mb-1 text-uppercase">Güncel Bakiye</div>
                        <h3 class="fw-bold mb-3"><?= number_format($k['guncel_bakiye'], 2, ',', '.') ?> <?= $k['para_birimi'] ?></h3>
                        <div class="d-flex justify-content-between small text-white bg-black bg-opacity-10 rounded p-2">
                            <span><i class="fas fa-arrow-down text-success me-1"></i><?= number_format($k['toplam_giren'], 2, ',', '.') ?></span>
                            <span><i class="fas fa-arrow-up text-danger me-1"></i><?= number_format($k['toplam_cikan'], 2, ',', '.') ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <h5 class="m-0 fw-bold text-dark"><i class="fas fa-list text-primary me-2"></i>Kasa Hareketleri (Genel Ekstre)</h5>
                <h5 class="m-0 fw-bold text-success bg-success bg-opacity-10 px-3 py-1 rounded-pill border border-success border-opacity-25">Net Nakit: <?= number_format($genel_toplam, 2, ',', '.') ?> ₺</h5>
            </div>
            <div class="card-body p-4">
                <table id="ekstreTable" class="table table-hover align-middle w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Tarih</th>
                            <th>Hesap (Kasa)</th>
                            <th>İşlem Türü</th>
                            <th>Açıklama</th>
                            <th class="text-end">Giriş (₺)</th>
                            <th class="text-end">Çıkış (₺)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($hareketler as $h): 
                            $isGiris = ($h['islem_turu'] == 'giris' || $h['islem_turu'] == 'virman_giris');
                        ?>
                        <tr>
                            <td><span class="d-none"><?= $h['islem_tarihi'] ?></span><?= date('d.m.Y H:i', strtotime($h['islem_tarihi'])) ?></td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($h['kasa_adi']) ?></td>
                            <td>
                                <?php if($h['islem_turu'] == 'giris'): ?><span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-arrow-down me-1"></i>Giriş</span>
                                <?php elseif($h['islem_turu'] == 'cikis'): ?><span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="fas fa-arrow-up me-1"></i>Çıkış</span>
                                <?php elseif($h['islem_turu'] == 'virman_giris'): ?><span class="badge bg-warning bg-opacity-10 text-warning border border-warning text-dark"><i class="fas fa-exchange-alt me-1"></i>Virman (Gelen)</span>
                                <?php else: ?><span class="badge bg-warning bg-opacity-10 text-warning border border-warning text-dark"><i class="fas fa-exchange-alt me-1"></i>Virman (Giden)</span><?php endif; ?>
                            </td>
                            <td class="small text-muted fw-bold">
                                <?= htmlspecialchars($h['aciklama']) ?>
                                <?php if($h['karsi_kasa_id']) echo "<br><span class='text-primary'>(Karşı Kasa: {$h['karsi_kasa_adi']})</span>"; ?>
                            </td>
                            <td class="text-end fw-bold text-success"><?= $isGiris ? number_format($h['tutar'], 2, ',', '.') : '-' ?></td>
                            <td class="text-end fw-bold text-danger"><?= !$isGiris ? number_format($h['tutar'], 2, ',', '.') : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <div class="modal fade" id="kasaEkleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="kasa_ekle" value="1">
                    <div class="modal-header bg-light border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold text-primary"><i class="fas fa-plus-circle me-2"></i>Yeni Hesap Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-muted small">Hesap Adı</label>
                            <input type="text" name="kasa_adi" class="form-control fw-bold" required placeholder="Örn: Garanti İban veya Çekmece Nakit">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold text-muted small">Tipi</label>
                                <select name="kasa_tipi" class="form-select fw-bold">
                                    <option value="nakit">Nakit Kasa</option>
                                    <option value="banka">Banka Hesabı</option>
                                    <option value="pos">Kredi Kartı (POS)</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold text-muted small">Para Birimi</label>
                                <select name="para_birimi" class="form-select fw-bold">
                                    <option value="TRY">TRY (₺)</option>
                                    <option value="USD">USD ($)</option>
                                    <option value="EUR">EUR (€)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold text-muted small">Açılış Bakiyesi</label>
                            <input type="number" step="0.01" name="acilis_bakiyesi" class="form-control fw-bold" value="0" required>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                        <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill shadow-sm">Hesabı Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="islemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="islem_ekle" value="1">
                    <div class="modal-header bg-dark text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold"><i class="fas fa-exchange-alt me-2"></i>Manuel Para İşlemi</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-muted small">Hangi Kasa?</label>
                            <select name="kasa_id" class="form-select fw-bold" required>
                                <?php foreach($kasa_listesi as $k): ?>
                                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['kasa_adi']) ?> (<?= number_format($k['guncel_bakiye'], 2) ?> ₺)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold text-muted small">İşlem Yönü</label>
                                <select name="islem_turu" class="form-select fw-bold border-2" required>
                                    <option value="giris">Para Girişi (+)</option>
                                    <option value="cikis">Para Çıkışı (-)</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold text-muted small">Tutar</label>
                                <input type="number" step="0.01" name="tutar" class="form-control fw-bold border-2" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold text-muted small">İşlem Açıklaması</label>
                            <input type="text" name="aciklama" class="form-control" required placeholder="Örn: Kasaya dışarıdan nakit kondu">
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                        <button type="submit" class="btn btn-dark w-100 fw-bold rounded-pill shadow-sm">İşlemi Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="virmanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="virman_yap" value="1">
                    <div class="modal-header bg-warning border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold text-dark"><i class="fas fa-random me-2"></i>Kasa Arası Virman (Transfer)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-danger small">Paranın Çıkacağı Kasa (Kaynak)</label>
                            <select name="cikis_kasa_id" class="form-select fw-bold border-danger" required>
                                <?php foreach($kasa_listesi as $k): ?>
                                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['kasa_adi']) ?> (Bakiye: <?= number_format($k['guncel_bakiye'], 2) ?> ₺)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="text-center my-2 text-muted"><i class="fas fa-arrow-down fa-2x"></i></div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-success small">Paranın Gireceği Kasa (Hedef)</label>
                            <select name="giris_kasa_id" class="form-select fw-bold border-success" required>
                                <?php foreach($kasa_listesi as $k): ?>
                                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['kasa_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-muted small">Transfer Tutarı (₺)</label>
                            <input type="number" step="0.01" name="virman_tutari" class="form-control form-control-lg fw-bold text-center border-warning" required placeholder="0.00">
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold text-muted small">Açıklama (Opsiyonel)</label>
                            <input type="text" name="virman_aciklama" class="form-control" value="Kasa arası transfer">
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                        <button type="submit" class="btn btn-warning text-dark w-100 fw-bold rounded-pill shadow-sm">Transferi Başlat</button>
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

    <script>
        $(document).ready(function() {
            $('#ekstreTable').DataTable({
                language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" },
                pageLength: 50,
                order: [[0, "desc"]],
                dom: "<'row mb-3'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" + "<'row'<'col-sm-12'tr>>" + "<'row mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                buttons: [
                    { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-sm btn-success shadow-sm fw-bold me-1 rounded-pill px-3' },
                    { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-sm btn-danger shadow-sm fw-bold rounded-pill px-3' },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Yazdır', className: 'btn btn-sm btn-dark shadow-sm fw-bold ms-1 rounded-pill px-3' }
                ]
            });
        });

        function kasaSil(id) {
            Swal.fire({ title: 'Kasa Silinecek', text: "Hesap silme işlemi geri alınamaz. Eğer bu kasada geçmiş işlem varsa sistem silmeye izin vermeyecektir.", icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal' }).then((result) => {
                if (result.isConfirmed) window.location.href = `kasalar.php?kasa_sil=${id}`;
            });
        }
    </script>
</body>
</html>