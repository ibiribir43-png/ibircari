<?php
session_start();
require 'baglanti.php';
require_once __DIR__ . '/ibir99ibir11/includes/functions.php';

if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['kullanici_id'];
$firma_id = $_SESSION['firma_id'];

$stmtRol = $db->prepare("SELECT rol FROM yoneticiler WHERE id = ?");
$stmtRol->execute([$user_id]);
$my_role = $stmtRol->fetchColumn();

if (!in_array($my_role, ['admin', 'super_admin'])) {
    die("<!DOCTYPE html><html lang='tr'><head><meta charset='UTF-8'><title>Yetkisiz Erişim</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center' style='height: 100vh;'><div class='text-center p-5 bg-white shadow rounded-4' style='max-width:500px;'><h3 class='fw-bold text-dark'>Yetkisiz Erişim!</h3><a href='index.php' class='btn btn-primary px-4 rounded-pill fw-bold mt-2'>Ana Sayfaya Dön</a></div></body></html>");
}

function adminSifreDogrula($db, $uid, $pass) {
    $sorgu = $db->prepare("SELECT sifre FROM yoneticiler WHERE id = ?");
    $sorgu->execute([$uid]);
    $kayitli = $sorgu->fetchColumn();
    return (password_verify($pass, $kayitli) || md5($pass) === $kayitli);
}

function formatBytes($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getFileIconData($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $video_exts = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    $doc_exts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'rar'];
    
    if (in_array($ext, $video_exts)) return ['icon' => 'fa-file-video', 'color' => 'text-danger', 'type' => 'Video'];
    if (in_array($ext, $image_exts)) return ['icon' => 'fa-image', 'color' => 'text-primary', 'type' => 'Görsel'];
    if (in_array($ext, $doc_exts)) return ['icon' => 'fa-file-alt', 'color' => 'text-success', 'type' => 'Belge'];
    return ['icon' => 'fa-file', 'color' => 'text-secondary', 'type' => 'Dosya'];
}

$islem_sonuc = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['silinecek_veriler']) && isset($_POST['admin_sifresi'])) {
    $silinecekler_json = $_POST['silinecek_veriler']; 
    $girilen_sifre = $_POST['admin_sifresi'];
    
    if (adminSifreDogrula($db, $user_id, $girilen_sifre)) {
        $yollar = json_decode($silinecekler_json, true);
        $silinen_adet = 0;
        $kurtarilan_bayt = 0;
        
        if (is_array($yollar) && count($yollar) > 0) {
            foreach ($yollar as $silinecek_yol) {
                if (strpos($silinecek_yol, "uploads/") === 0 && strpos($silinecek_yol, "/$firma_id/") !== false) {
                    $tam_yol = __DIR__ . '/' . $silinecek_yol;
                    if (file_exists($tam_yol) && is_file($tam_yol)) {
                        $boyut = filesize($tam_yol);
                        $kurtarilan_bayt += $boyut;
                        @unlink($tam_yol);
                        $db->prepare("DELETE FROM musteri_dosyalar WHERE dosya_yolu = ?")->execute([$silinecek_yol]);
                        $silinen_adet++;
                    }
                }
            }
            if ($silinen_adet > 0) {
                $islem_sonuc = ['status' => 'success', 'msg' => "$silinen_adet adet dosya silindi. " . formatBytes($kurtarilan_bayt) . " alan açıldı."];
                sistemLog($db, 'Depolama', 'Toplu Dosya Silindi', "$silinen_adet dosya (" . formatBytes($kurtarilan_bayt) . ") silindi.");
            } else {
                $islem_sonuc = ['status' => 'warning', 'msg' => "Dosyalar fiziksel olarak bulunamadı."];
            }
        }
    } else {
        $islem_sonuc = ['status' => 'error', 'msg' => "Hatalı yönetici şifresi!"];
        sistemLog($db, 'Güvenlik', 'Hatalı Şifre', "Depolama silme işleminde hatalı şifre girildi.");
    }
}

$klasorler = [];
$tum_dosyalar = [];
$istatistikler = ['toplam_bayt' => 0, 'video_bayt' => 0, 'foto_bayt' => 0, 'cop_dosya_bayt' => 0, 'toplam_dosya_sayisi' => 0, 'klasor_sayisi' => 0];
$base_upload_dir = __DIR__ . '/uploads/';
$folders_to_check = ['albumler' => 'Fotoğraf', 'haziralbumler' => 'Hazır Albüm', 'videoklipler' => 'Klip', 'videolar' => 'Video'];

$musteri_sorgu = $db->query("SELECT id, ad_soyad, silindi FROM musteriler WHERE firma_id = '$firma_id'")->fetchAll(PDO::FETCH_ASSOC);
$musteriler = [];
foreach($musteri_sorgu as $m) $musteriler[$m['id']] = $m;

foreach ($folders_to_check as $folder => $tur_adi) {
    $firma_folder = $base_upload_dir . $folder . '/' . $firma_id;
    if (is_dir($firma_folder)) {
        $musteri_klasorleri = array_diff(scandir($firma_folder), ['..', '.']);
        foreach ($musteri_klasorleri as $m_id) {
            $hedef_klasor = $firma_folder . '/' . $m_id;
            if (is_dir($hedef_klasor)) {
                if (!isset($klasorler[$m_id])) {
                    $m_durum = isset($musteriler[$m_id]) ? $musteriler[$m_id]['silindi'] : 1; 
                    $klasorler[$m_id] = [
                        'm_id' => $m_id,
                        'ad_soyad' => isset($musteriler[$m_id]) ? $musteriler[$m_id]['ad_soyad'] : "Bilinmeyen (ID: $m_id)",
                        'musteri_durum' => $m_durum,
                        'toplam_bayt' => 0,
                        'dosya_sayisi' => 0,
                        'dosyalar' => []
                    ];
                    $istatistikler['klasor_sayisi']++;
                }

                $files = array_diff(scandir($hedef_klasor), ['..', '.']);
                foreach ($files as $file) {
                    $tam_yol = $hedef_klasor . '/' . $file;
                    if (is_file($tam_yol)) {
                        $boyut = filesize($tam_yol);
                        $zaman = filemtime($tam_yol);
                        $rel_path = "uploads/$folder/$firma_id/$m_id/$file";
                        
                        $istatistikler['toplam_bayt'] += $boyut;
                        $istatistikler['toplam_dosya_sayisi']++;
                        $klasorler[$m_id]['toplam_bayt'] += $boyut;
                        $klasorler[$m_id]['dosya_sayisi']++;
                        
                        $iconData = getFileIconData($file);
                        if ($iconData['type'] == 'Video') $istatistikler['video_bayt'] += $boyut;
                        if ($iconData['type'] == 'Görsel') $istatistikler['foto_bayt'] += $boyut;
                        if ($klasorler[$m_id]['musteri_durum'] == 1) $istatistikler['cop_dosya_bayt'] += $boyut;

                        $dosyaObjesi = [
                            'id' => md5($tam_yol),
                            'dosya_adi' => $file,
                            'dosya_yolu' => $rel_path,
                            'fiziksel_boyut' => $boyut,
                            'formatli_boyut' => formatBytes($boyut),
                            'ad_soyad' => $klasorler[$m_id]['ad_soyad'],
                            'musteri_durum' => $klasorler[$m_id]['musteri_durum'],
                            'tur_adi' => $tur_adi,
                            'file_type_name' => $iconData['type'],
                            'icon_class' => $iconData['icon'] . ' ' . $iconData['color'],
                            'yukleme_tarihi_ham' => $zaman,
                            'yukleme_tarihi' => date('d.m.Y H:i', $zaman)
                        ];

                        $klasorler[$m_id]['dosyalar'][] = $dosyaObjesi;
                        $tum_dosyalar[] = $dosyaObjesi;
                    }
                }
                if ($klasorler[$m_id]['dosya_sayisi'] == 0) {
                    unset($klasorler[$m_id]);
                    $istatistikler['klasor_sayisi']--;
                }
            }
        }
    }
}

$klasorler_temiz = array_values($klasorler);
usort($klasorler_temiz, function($a, $b) { return $b['toplam_bayt'] <=> $a['toplam_bayt']; });
usort($tum_dosyalar, function($a, $b) { return $b['yukleme_tarihi_ham'] <=> $a['yukleme_tarihi_ham']; });

$klasorler_json = json_encode($klasorler_temiz, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
$islem_sonuc_json = json_encode($islem_sonuc, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Depolama Yöneticisi | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="css/yonetim.css">
    <style>
        body { background-color: #f4f6f9; }
        .disk-header { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; border-radius: 15px; }
        .stat-card { transition: transform 0.2s, box-shadow 0.2s; border-radius: 12px; border: none; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.08)!important; }
        .stat-icon { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 24px; }
        .custom-tabs .nav-link { color: #6c757d; font-weight: 600; border: none; padding: 12px 25px; border-bottom: 3px solid transparent; transition: all 0.3s; }
        .custom-tabs .nav-link:hover { color: #0d6efd; background-color: rgba(13, 110, 253, 0.05); }
        .custom-tabs .nav-link.active { color: #0d6efd; border-bottom: 3px solid #0d6efd; background-color: transparent; }
        .folder-icon { font-size: 38px; color: #ffc107; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .folder-row { transition: all 0.2s ease; border-radius: 10px; margin-bottom: 10px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.02); cursor: pointer; }
        .folder-row:hover { background-color: #f8f9fa; transform: scale(1.01); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .file-card { background: #fff; border-radius: 10px; padding: 15px; transition: 0.2s; border: 1px solid #e9ecef; position: relative; }
        .file-card:hover { box-shadow: 0 5px 15px rgba(0,0,0,0.08); border-color: #0d6efd; }
        .custom-check { transform: scale(1.3); cursor: pointer; }
        .drive-breadcrumb { background: #fff; border: 1px solid #e9ecef; border-radius: 10px; padding: 12px 20px; display: flex; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .breadcrumb-btn { background: none; border: none; padding: 5px 15px; border-radius: 5px; font-weight: 600; color: #495057; transition: 0.2s; }
        .breadcrumb-btn:hover { background: #e9ecef; color: #000; }
        .breadcrumb-sep { color: #adb5bd; margin: 0 10px; }
        .toolbar { background: #fff; border-radius: 10px; padding: 15px; margin-bottom: 15px; border: 1px solid #e9ecef; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
        table.dataTable tbody tr { background-color: #fff; }
        .sudo-modal { border-top: 5px solid #dc3545; }
        .view-btn.active { background-color: #e9ecef; color: #0d6efd; }
    </style>
</head>
<body class="yonetim-body">

    <?php include 'partials/navbar.php'; ?>

    <div class="container-fluid pb-5 px-4 mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="card disk-header border-0 shadow-sm p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <div class="mb-3 mb-md-0">
                            <h3 class="fw-bold mb-1"><i class="fab fa-google-drive me-2 text-warning"></i> Bulut Depolama Yöneticisi</h3>
                            <p class="mb-0 text-white-50">Tüm dosyalarınızı profesyonelce yönetin.</p>
                        </div>
                        <div class="text-end bg-white bg-opacity-10 p-3 rounded-4 border border-light border-opacity-25" style="backdrop-filter: blur(10px);">
                            <span class="text-white-50 small d-block fw-bold text-uppercase">Toplam Kullanılan Disk Alanı</span>
                            <span class="fw-bold text-white mb-0" style="font-size: 28px;"><?= formatBytes($istatistikler['toplam_bayt']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-8">
                <div class="row g-3 h-100">
                    <div class="col-md-4">
                        <div class="card stat-card shadow-sm h-100">
                            <div class="card-body p-3 d-flex align-items-center">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="fas fa-image"></i></div>
                                <div>
                                    <h6 class="text-muted fw-bold mb-1 small">Fotoğraf / Görsel</h6>
                                    <h4 class="fw-bold mb-0"><?= formatBytes($istatistikler['foto_bayt']) ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card shadow-sm h-100">
                            <div class="card-body p-3 d-flex align-items-center">
                                <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3"><i class="fas fa-video"></i></div>
                                <div>
                                    <h6 class="text-muted fw-bold mb-1 small">Video / Klip</h6>
                                    <h4 class="fw-bold mb-0"><?= formatBytes($istatistikler['video_bayt']) ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card shadow-sm h-100 <?= $istatistikler['cop_dosya_bayt'] > 0 ? 'border border-danger border-2' : '' ?>">
                            <div class="card-body p-3 d-flex align-items-center">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3"><i class="fas fa-trash-alt"></i></div>
                                <div>
                                    <h6 class="text-muted fw-bold mb-1 small">Silinmiş Müşteri Çöpü</h6>
                                    <h4 class="fw-bold mb-0 text-danger"><?= formatBytes($istatistikler['cop_dosya_bayt']) ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-4 h-100">
                    <div class="card-body p-3 d-flex align-items-center justify-content-center">
                        <div style="height: 100px; width: 100%; position: relative;">
                            <canvas id="diskChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ul class="nav custom-tabs mb-4 bg-white shadow-sm rounded-4 px-3 pt-2" id="diskTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="klasor-tab" data-bs-toggle="tab" data-bs-target="#klasor-icerik" type="button" role="tab" onclick="resetHistory()"><i class="fas fa-folder-tree me-2"></i> Klasör Görünümü</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="liste-tab" data-bs-toggle="tab" data-bs-target="#liste-icerik" type="button" role="tab" onclick="resetHistory()"><i class="fas fa-list-ul me-2"></i> Tüm Dosyalar (Karma Liste)</button>
            </li>
        </ul>

        <div class="tab-content" id="diskTabsContent">

            <div class="tab-pane fade show active" id="klasor-icerik" role="tabpanel">
                <div class="drive-breadcrumb mb-3" id="breadcrumbContainer" style="display: none;">
                    <button class="breadcrumb-btn" onclick="navigateRoot()"><i class="fas fa-hdd text-primary me-2"></i> Ana Dizin</button>
                    <span class="breadcrumb-sep"><i class="fas fa-chevron-right fs-7"></i></span>
                    <span class="fw-bold text-dark px-3 py-1 bg-light rounded-pill" id="breadcrumbCurrentFolder">Müşteri Adı</span>
                </div>

                <div id="folderGridContainer">
                    <div class="toolbar d-flex justify-content-between align-items-center mb-3">
                        <h5 class="m-0 fw-bold text-dark"><i class="fas fa-users text-primary me-2"></i>Müşteri Klasörleri</h5>
                        <div class="input-group w-auto">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" class="form-control border-start-0" id="folderSearch" placeholder="Cari Ara..." onkeyup="filterFolders()">
                        </div>
                    </div>
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-body p-2">
                            <div class="table-responsive">
                                <table class="table table-borderless align-middle mb-0" id="folderTable">
                                    <thead class="border-bottom">
                                        <tr>
                                            <th class="ps-4 text-muted small text-uppercase">Klasör (Cari Adı)</th>
                                            <th class="text-muted small text-uppercase">Durum</th>
                                            <th class="text-muted small text-uppercase text-center">İçerik</th>
                                            <th class="text-muted small text-uppercase">Toplam Boyut</th>
                                            <th class="text-end pe-4 text-muted small text-uppercase">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="folderTbody">
                                        <?php if(empty($klasorler_temiz)): ?>
                                        <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-folder-open fa-3x mb-3 opacity-25"></i><br>Klasör yok.</td></tr>
                                        <?php endif; ?>
                                        <?php foreach($klasorler_temiz as $k): ?>
                                        <tr class="folder-row" data-name="<?= mb_strtolower($k['ad_soyad']) ?>" onclick="navigateFolder('<?= $k['m_id'] ?>', '<?= htmlspecialchars(addslashes($k['ad_soyad'])) ?>')">
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-folder folder-icon me-3"></i>
                                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($k['ad_soyad']) ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if($k['musteri_durum'] == 1): ?>
                                                    <span class="badge bg-danger px-3 py-2 rounded-pill shadow-sm"><i class="fas fa-trash me-1"></i> Silinmiş Cari</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success px-3 py-2 rounded-pill"><i class="fas fa-check-circle me-1"></i> Aktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><span class="badge bg-secondary rounded-pill px-3"><?= $k['dosya_sayisi'] ?> Dosya</span></td>
                                            <td><span class="fw-bold text-primary fs-6"><?= formatBytes($k['toplam_bayt']) ?></span></td>
                                            <td class="text-end pe-4">
                                                <button type="button" class="btn btn-sm btn-light border fw-bold text-primary px-3 rounded-pill"><i class="fas fa-folder-open me-1"></i> İçine Gir</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="folderFilesContainer" style="display: none;">
                    <div class="toolbar d-flex flex-wrap gap-3 align-items-center">
                        <div class="d-flex align-items-center me-auto">
                            <input type="checkbox" class="form-check-input custom-check me-2" id="folderSelectAll" onclick="toggleAllFiles('folder', this)">
                            <label class="form-check-label fw-bold text-dark user-select-none" for="folderSelectAll">Tümünü Seç</label>
                            <span id="folderSelectedBadge" class="badge bg-primary ms-3" style="display:none;">0 Seçildi</span>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <div class="btn-group shadow-sm me-2">
                                <button type="button" class="btn btn-light border view-btn active" id="btnListView" onclick="setViewMode('list')"><i class="fas fa-list"></i></button>
                                <button type="button" class="btn btn-light border view-btn" id="btnGridView" onclick="setViewMode('grid')"><i class="fas fa-th-large"></i></button>
                            </div>
                            <div class="btn-group shadow-sm">
                                <button type="button" class="btn btn-light border fw-bold text-secondary" onclick="sortActiveFiles('date')"><i class="fas fa-calendar-alt"></i></button>
                                <button type="button" class="btn btn-light border fw-bold text-secondary" onclick="sortActiveFiles('size')"><i class="fas fa-weight-hanging"></i></button>
                                <button type="button" class="btn btn-light border fw-bold text-secondary" onclick="sortActiveFiles('name')"><i class="fas fa-sort-alpha-down"></i></button>
                            </div>
                            <button type="button" class="btn btn-danger fw-bold shadow-sm px-4" id="btnFolderBulkDelete" onclick="prepareBulkDelete('folder')" disabled><i class="fas fa-trash-alt me-2"></i> Sil</button>
                        </div>
                    </div>

                    <div id="filesViewArea"></div>
                </div>
            </div>

            <div class="tab-pane fade" id="liste-icerik" role="tabpanel">
                <div class="toolbar d-flex flex-wrap gap-3 align-items-center bg-white">
                    <div class="d-flex align-items-center me-auto">
                        <button type="button" class="btn btn-danger fw-bold shadow-sm px-4" id="btnDtBulkDelete" onclick="prepareBulkDelete('dt')" disabled><i class="fas fa-trash-alt me-2"></i> Seçilenleri Yok Et</button>
                        <span id="dtSelectedBadge" class="badge bg-danger ms-3 fs-6" style="display:none;">0 Dosya Seçildi</span>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-body p-3 p-md-4">
                        <table id="allFilesTable" class="table table-hover table-striped align-middle w-100">
                            <thead class="table-light">
                                <tr>
                                    <th width="30" class="text-center" data-orderable="false"><input type="checkbox" class="form-check-input custom-check" id="dtSelectAll" onclick="toggleAllFiles('dt', this)"></th>
                                    <th>Dosya Adı</th>
                                    <th>Cari / Müşteri</th>
                                    <th>Kategori</th>
                                    <th>Boyut</th>
                                    <th>Yüklenme Tarihi</th>
                                    <th class="text-end" data-orderable="false">İşlem</th>
                                    <th style="display:none;">ByteSize</th> 
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($tum_dosyalar as $d): ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input custom-check dt-checkbox" value="<?= htmlspecialchars($d['dosya_yolu']) ?>" onchange="updateDtSelectionCount()">
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas <?= $d['icon_class'] ?> fs-5 me-2 w-20px text-center"></i>
                                            <div class="text-break fw-bold text-dark" style="max-width: 250px;"><?= htmlspecialchars($d['dosya_adi']) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($d['musteri_durum'] == 1): ?>
                                            <span class="text-danger fw-bold"><i class="fas fa-trash small"></i> <?= htmlspecialchars($d['ad_soyad']) ?></span>
                                        <?php else: ?>
                                            <span class="text-dark fw-bold"><?= htmlspecialchars($d['ad_soyad']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= $d['tur_adi'] ?></span></td>
                                    <td data-order="<?= $d['fiziksel_boyut'] ?>"><span class="badge bg-secondary"><?= $d['formatli_boyut'] ?></span></td>
                                    <td data-order="<?= $d['yukleme_tarihi_ham'] ?>"><?= $d['yukleme_tarihi'] ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-light border text-primary" onclick="openPreviewModal('<?= htmlspecialchars($d['dosya_yolu']) ?>', '<?= $d['file_type_name'] ?>', '<?= htmlspecialchars(addslashes($d['dosya_adi'])) ?>')" title="Gözat"><i class="fas fa-eye"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="prepareSingleDelete('<?= htmlspecialchars($d['dosya_yolu']) ?>', '<?= htmlspecialchars(addslashes($d['dosya_adi'])) ?>', '<?= $d['formatli_boyut'] ?>')" title="Sil"><i class="fas fa-trash"></i></button>
                                    </td>
                                    <td style="display:none;"><?= $d['fiziksel_boyut'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="sudoModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content sudo-modal shadow-lg border-0 rounded-4">
                <form method="POST">
                    <input type="hidden" name="silinecek_veriler" id="silinecek_veriler_input">
                    <div class="modal-header bg-white border-0 pb-0 pt-4 px-4">
                        <h4 class="modal-title fw-bold text-danger"><i class="fas fa-radiation-alt me-2"></i>Kritik Silme İşlemi</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-start p-4">
                        <div class="alert alert-danger bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-3 mb-4" id="sudoModalMessage"></div>
                        <div class="bg-light p-3 rounded-3 border">
                            <label class="form-label fw-bold text-dark small mb-2">Onaylamak için Yönetici Şifrenizi girin:</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-danger"><i class="fas fa-key text-danger"></i></span>
                                <input type="password" name="admin_sifresi" class="form-control border-danger py-2" required placeholder="Sistem giriş şifreniz" autocomplete="off">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 bg-light p-3 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary px-4 fw-bold rounded-pill" data-bs-dismiss="modal">İptal Et</button>
                        <button type="submit" class="btn btn-danger px-4 fw-bold shadow-sm rounded-pill"><i class="fas fa-fire me-1"></i> Kalıcı Olarak Yok Et</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content bg-dark border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header border-0 pb-0 text-white z-3 position-absolute w-100" style="background: linear-gradient(to bottom, rgba(0,0,0,0.8), transparent);">
                    <h6 class="modal-title fw-bold text-truncate pe-4" id="previewModalTitle">Dosya Önizleme</h6>
                    <div class="ms-auto">
                        <button type="button" class="btn btn-sm btn-light bg-white bg-opacity-25 text-white border-0 me-2" onclick="copyFileLink()" id="btnCopyLink"><i class="fas fa-link"></i> Linki Kopyala</button>
                        <a href="#" id="btnDownloadFile" download class="btn btn-sm btn-primary border-0 me-3"><i class="fas fa-download"></i> İndir</a>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-0 text-center d-flex align-items-center justify-content-center" style="min-height: 500px; background: #000;" id="previewContainerBox">
                    <div id="previewContent" class="w-100 h-100 d-flex align-items-center justify-content-center"></div>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const klasorlerData = <?= $klasorler_json ?>; 
        const islemSonuc = <?= $islem_sonuc_json ?>;
        let activeFiles = []; 
        let sortStates = { size: false, date: false, name: false }; 
        let viewMode = 'list'; 
        let currentUrlFile = '';

        if(islemSonuc) {
            Swal.fire({icon: islemSonuc.status, title: islemSonuc.status === 'success' ? 'Başarılı' : 'Hata', text: islemSonuc.msg, confirmButtonColor: '#0d6efd'});
        }

        $(document.ready)(function() {
            $('#allFilesTable').DataTable({
                language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" },
                pageLength: 25,
                order: [[5, "desc"]], 
                columnDefs: [ { orderable: false, targets: [0, 6] }, { orderData: [7], targets: [4] } ],
                dom: "<'row mb-3'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" + "<'row'<'col-sm-12'tr>>" + "<'row mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                buttons: [
                    { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-sm btn-success shadow-sm fw-bold', exportOptions: { columns: [1, 2, 3, 4, 5] } },
                    { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-sm btn-danger shadow-sm fw-bold', exportOptions: { columns: [1, 2, 3, 4, 5] } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Yazdır', className: 'btn btn-sm btn-secondary shadow-sm fw-bold', exportOptions: { columns: [1, 2, 3, 4, 5] } }
                ]
            });
            drawDiskChart();
        });

        function drawDiskChart() {
            const ctx = document.getElementById('diskChart').getContext('2d');
            const data = [<?= $istatistikler['foto_bayt'] ?>, <?= $istatistikler['video_bayt'] ?>, <?= $istatistikler['cop_dosya_bayt'] ?>];
            if(data[0]===0 && data[1]===0 && data[2]===0) data[0]=1;
            new Chart(ctx, {
                type: 'doughnut',
                data: { labels: ['Fotoğraf', 'Video', 'Çöp'], datasets: [{ data: data, backgroundColor: ['#0d6efd', '#dc3545', '#ffc107'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return ' ' + c.label; } } } }, cutout: '75%' }
            });
        }

        window.addEventListener('popstate', function(event) {
            if (event.state && event.state.folder) {
                executeFolderView(event.state.folder, event.state.name);
            } else {
                executeRootView();
            }
        });

        function resetHistory() {
            history.pushState(null, '', location.pathname);
            executeRootView();
        }

        function filterFolders() {
            const val = document.getElementById('folderSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.folder-row');
            rows.forEach(r => { r.style.display = r.getAttribute('data-name').includes(val) ? '' : 'none'; });
        }

        function navigateRoot() {
            history.pushState({folder: null}, '', location.pathname);
            executeRootView();
        }

        function navigateFolder(m_id, ad_soyad) {
            history.pushState({folder: m_id, name: ad_soyad}, '', '?folder=' + m_id);
            executeFolderView(m_id, ad_soyad);
        }

        function executeRootView() {
            document.getElementById('folderFilesContainer').style.display = 'none';
            document.getElementById('folderGridContainer').style.display = 'block';
            document.getElementById('breadcrumbContainer').style.display = 'none';
            document.getElementById('folderSelectAll').checked = false;
            updateFolderSelectionCount();
        }

        function executeFolderView(m_id, ad_soyad) {
            document.getElementById('folderGridContainer').style.display = 'none';
            document.getElementById('folderFilesContainer').style.display = 'block';
            document.getElementById('breadcrumbContainer').style.display = 'flex';
            document.getElementById('breadcrumbCurrentFolder').innerText = ad_soyad;
            let klasor = klasorlerData.find(k => k.m_id === m_id);
            activeFiles = klasor ? klasor.dosyalar : [];
            sortActiveFiles('date', true); 
            document.getElementById('folderSelectAll').checked = false;
            updateFolderSelectionCount();
        }

        function setViewMode(mode) {
            viewMode = mode;
            document.getElementById('btnListView').classList.toggle('active', mode === 'list');
            document.getElementById('btnGridView').classList.toggle('active', mode === 'grid');
            renderActiveFiles();
        }

        function renderActiveFiles() {
            const container = document.getElementById('filesViewArea');
            container.innerHTML = ''; 

            if(!activeFiles || activeFiles.length === 0) {
                container.innerHTML = '<div class="card shadow-sm border-0 rounded-4"><div class="card-body text-center py-5"><i class="fas fa-wind fa-3x mb-3 opacity-25"></i><br><span class="text-muted">Bu klasörde gösterilecek dosya yok.</span></div></div>';
                return;
            }

            if (viewMode === 'list') {
                let html = `<div class="card shadow-sm border-0 rounded-4"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="bg-light"><tr><th width="50" class="ps-4"></th><th>Dosya Adı & Türü</th><th>Fiziksel Boyut</th><th>Yüklenme Zamanı</th><th class="text-end pe-4">İşlemler</th></tr></thead><tbody>`;
                activeFiles.forEach(file => {
                    html += `<tr>
                        <td class="ps-4"><input type="checkbox" class="form-check-input custom-check folder-checkbox" value="${file.dosya_yolu}" onchange="updateFolderSelectionCount()"></td>
                        <td><div class="d-flex align-items-center"><i class="fas ${file.icon_class} fs-4 me-3 w-20px text-center"></i><div><div class="fw-bold text-dark text-break" style="max-width: 250px;">${file.dosya_adi}</div><small class="text-muted fw-bold">${file.tur_adi}</small></div></div></td>
                        <td><span class="badge bg-secondary">${file.formatli_boyut}</span></td>
                        <td><div class="small text-muted">${file.yukleme_tarihi}</div></td>
                        <td class="text-end pe-4">
                            <button type="button" class="btn btn-sm btn-light border text-primary me-1" onclick="openPreviewModal('${file.dosya_yolu}', '${file.file_type_name}', '${file.dosya_adi.replace(/'/g, "\\'")}')"><i class="fas fa-eye"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="prepareSingleDelete('${file.dosya_yolu}', '${file.dosya_adi.replace(/'/g, "\\'")}', '${file.formatli_boyut}')"><i class="fas fa-trash"></i></button>
                        </td></tr>`;
                });
                html += `</tbody></table></div></div></div>`;
                container.innerHTML = html;
            } else {
                let html = `<div class="row g-3">`;
                activeFiles.forEach(file => {
                    html += `<div class="col-md-3 col-sm-6"><div class="file-card h-100 d-flex flex-column text-center">
                        <div class="position-absolute top-0 start-0 m-2"><input type="checkbox" class="form-check-input custom-check folder-checkbox" value="${file.dosya_yolu}" onchange="updateFolderSelectionCount()"></div>
                        <i class="fas ${file.icon_class} fa-3x my-3 mx-auto"></i>
                        <div class="fw-bold text-dark text-truncate px-2 mb-1" title="${file.dosya_adi}">${file.dosya_adi}</div>
                        <div class="small text-muted mb-3">${file.formatli_boyut}</div>
                        <div class="mt-auto d-flex justify-content-center gap-2">
                            <button type="button" class="btn btn-sm btn-light border w-50" onclick="openPreviewModal('${file.dosya_yolu}', '${file.file_type_name}', '${file.dosya_adi.replace(/'/g, "\\'")}')"><i class="fas fa-eye text-primary"></i></button>
                            <button type="button" class="btn btn-sm btn-light border w-50" onclick="prepareSingleDelete('${file.dosya_yolu}', '${file.dosya_adi.replace(/'/g, "\\'")}', '${file.formatli_boyut}')"><i class="fas fa-trash text-danger"></i></button>
                        </div></div></div>`;
                });
                html += `</div>`;
                container.innerHTML = html;
            }
        }

        function sortActiveFiles(type, forceDesc = false) {
            sortStates[type] = forceDesc ? false : !sortStates[type]; 
            const isAsc = sortStates[type];
            activeFiles.sort((a, b) => {
                if(type === 'size') return isAsc ? a.fiziksel_boyut - b.fiziksel_boyut : b.fiziksel_boyut - a.fiziksel_boyut;
                if(type === 'date') return isAsc ? a.yukleme_tarihi_ham - b.yukleme_tarihi_ham : b.yukleme_tarihi_ham - a.yukleme_tarihi_ham;
                if(type === 'name') return isAsc ? a.dosya_adi.localeCompare(b.dosya_adi) : b.dosya_adi.localeCompare(a.dosya_adi);
            });
            renderActiveFiles();
        }

        function toggleAllFiles(type, source) {
            const selector = type === 'folder' ? '.folder-checkbox' : '.dt-checkbox';
            document.querySelectorAll(selector).forEach(cb => cb.checked = source.checked);
            type === 'folder' ? updateFolderSelectionCount() : updateDtSelectionCount();
        }

        function updateFolderSelectionCount() {
            const count = document.querySelectorAll('.folder-checkbox:checked').length;
            const badge = document.getElementById('folderSelectedBadge');
            const btnDelete = document.getElementById('btnFolderBulkDelete');
            if(count > 0) { badge.style.display = 'inline-block'; badge.innerText = count + " Seçildi"; btnDelete.disabled = false; } 
            else { badge.style.display = 'none'; btnDelete.disabled = true; }
        }
        
        function updateDtSelectionCount() {
            const count = document.querySelectorAll('.dt-checkbox:checked').length;
            const badge = document.getElementById('dtSelectedBadge');
            const btnDelete = document.getElementById('btnDtBulkDelete');
            if(count > 0) { badge.style.display = 'inline-block'; badge.innerText = count + " Seçildi"; btnDelete.disabled = false; } 
            else { badge.style.display = 'none'; btnDelete.disabled = true; }
        }

        function prepareSingleDelete(yol, isim, boyut) {
            document.getElementById('sudoModalMessage').innerHTML = `<h5 class='fw-bold mb-2'>${isim}</h5>Siliyorsunuz...<br>Bu işlem sunucuda <b>${boyut}</b> alan açacaktır.`;
            document.getElementById('silinecek_veriler_input').value = JSON.stringify([yol]);
            new bootstrap.Modal(document.getElementById('sudoModal')).show();
        }

        function prepareBulkDelete(type) {
            const checkboxes = document.querySelectorAll(type === 'folder' ? '.folder-checkbox:checked' : '.dt-checkbox:checked');
            if(checkboxes.length === 0) return;
            let yollar = []; checkboxes.forEach(cb => yollar.push(cb.value));
            document.getElementById('sudoModalMessage').innerHTML = `<h5 class='fw-bold mb-2'>Toplu Silme Onayı</h5>Seçtiğiniz <b>${yollar.length} adet dosyayı</b> toplu olarak siliyorsunuz.<br>Sistemde geri döndürülemez bir alan açılacaktır.`;
            document.getElementById('silinecek_veriler_input').value = JSON.stringify(yollar);
            new bootstrap.Modal(document.getElementById('sudoModal')).show();
        }

        function openPreviewModal(url, type, name) {
            const contentDiv = document.getElementById('previewContent');
            document.getElementById('previewModalTitle').innerText = name;
            currentUrlFile = window.location.origin + '/' + url;
            document.getElementById('btnDownloadFile').href = url;
            document.getElementById('btnCopyLink').innerHTML = '<i class="fas fa-link"></i> Linki Kopyala';
            
            contentDiv.innerHTML = '<div class="spinner-border text-light"></div>'; 
            new bootstrap.Modal(document.getElementById('previewModal')).show();
            
            setTimeout(() => {
                if (type === 'Video') contentDiv.innerHTML = `<video src="${url}" controls autoplay class="w-100 h-100" style="max-height:80vh; object-fit:contain;"></video>`;
                else if (type === 'Görsel') contentDiv.innerHTML = `<img src="${url}" class="w-100 h-100" style="max-height:80vh; object-fit:contain;">`;
                else contentDiv.innerHTML = `<div class="p-5 text-white"><i class="fas fa-file-alt fa-4x mb-3 text-secondary"></i><br>Önizleme desteklenmiyor.</div>`;
            }, 300);
            
            document.getElementById('previewModal').addEventListener('hidden.bs.modal', function () { contentDiv.innerHTML = ''; }, { once: true });
        }

        function copyFileLink() {
            navigator.clipboard.writeText(currentUrlFile).then(() => {
                document.getElementById('btnCopyLink').innerHTML = '<i class="fas fa-check"></i> Kopyalandı!';
            });
        }
    </script>
</body>
</html>