<?php
ob_start(); 
require_once 'baglanti.php';
require_once 'partials/security_check.php';

$functions_path = __DIR__ . '/../includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

function formatBytes($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';
    $base = log($bytes, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');   
    return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
}

function getDirectorySize($path) {
    $bytestotal = 0;
    $path = realpath($path);
    if($path !== false && $path != '' && file_exists($path)){
        try {
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object){
                $bytestotal += $object->getSize();
            }
        } catch(Exception $e) {}
    }
    return $bytestotal;
}

// OTOMATİK İŞ AKIŞI
if (isset($_GET['oto_akim']) && isset($_GET['m_id'])) {
    $yeni_akim = (int)$_GET['oto_akim'];
    $o_m_id = (int)$_GET['m_id'];
    $o_f_id = $_SESSION['firma_id'];
    
    $db->prepare("UPDATE musteriler SET workflow_status = ? WHERE id = ? AND firma_id = ?")->execute([$yeni_akim, $o_m_id, $o_f_id]);
    
    // YENİ: Eğer yeni albüm yüklendiyse (akim 4), bekleyen revizeleri otomatik kapat
    if ($yeni_akim >= 4) {
        $db->prepare("UPDATE revizeler SET durum = 1 WHERE musteri_id = ? AND durum = 0")->execute([$o_m_id]);
    }
    exit;
}

$token = $_GET['t'] ?? $_POST['token_hidden'] ?? '';
if (empty($token)) die("Erişim Anahtarı Geçersiz!");

// 1. MANUEL AYAR GÜNCELLEME (İş Akışı Değişimi vb.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_ayarlari_kaydet'])) {
    try {
        $m_id_post    = (int)($_POST['m_id_hidden_settings'] ?? 0);
        $new_min      = (int)($_POST['min_secim'] ?? 0);
        $new_max      = (int)($_POST['max_secim'] ?? 0);
        $new_status   = (int)($_POST['portal_status'] ?? 0);
        $new_workflow = (int)($_POST['workflow_status'] ?? 0);

        $db->prepare("UPDATE musteriler SET min_secim = ?, max_secim = ?, portal_status = ?, workflow_status = ? WHERE id = ?")
           ->execute([$new_min, $new_max, $new_status, $new_workflow, $m_id_post]);
        
        // YENİ: Manuel olarak Albüm Onayı veya ilerisine alınırsa revizeleri otomatik kapat
        if ($new_workflow >= 4) {
            $db->prepare("UPDATE revizeler SET durum = 1 WHERE musteri_id = ? AND durum = 0")->execute([$m_id_post]);
        }
        
        ob_clean();
        header("Location: musteri_portal_yonetim.php?t=$token&status=success"); 
        exit;
    } catch (Exception $e) {
        ob_clean();
        header("Location: musteri_portal_yonetim.php?t=$token&status=error&msg=" . urlencode($e->getMessage())); 
        exit;
    }
}

// 2. REVİZE TAMAMLAMA İŞLEMİ (Butonla)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revize_tamamla'])) {
    try {
        $r_id = (int)$_POST['revize_id'];
        $m_id_post = (int)$_POST['m_id_hidden_settings_revize'];
        
        $db->prepare("UPDATE revizeler SET durum = 1 WHERE id = ? AND musteri_id = ?")->execute([$r_id, $m_id_post]);
        $db->prepare("UPDATE musteriler SET workflow_status = 3 WHERE id = ?")->execute([$m_id_post]);
        
        ob_clean();
        header("Location: musteri_portal_yonetim.php?t=$token&status=success"); exit;
    } catch (Exception $e) {
        ob_clean();
        header("Location: musteri_portal_yonetim.php?t=$token&status=error&msg=" . urlencode($e->getMessage())); exit;
    }
}

// Müşteri Verilerini Çek
$m_sorgu = $db->prepare("SELECT * FROM musteriler WHERE url_token = ? AND silindi = 0");
$m_sorgu->execute([$token]);
$musteri = $m_sorgu->fetch(PDO::FETCH_ASSOC);
if (!$musteri) die("Müşteri bulunamadı.");

$portal_data = $musteri; 
$musteri_id = (int)$musteri['id'];
$firma_id = $musteri['firma_id'];
$wf_status = (int)($musteri['workflow_status'] ?? 0);

// Revizeleri Çek
$revizeler = [];
try {
    $rev_q = $db->prepare("SELECT * FROM revizeler WHERE musteri_id = ? AND firma_id = ? ORDER BY created_at DESC");
    $rev_q->execute([$musteri_id, $firma_id]);
    $revizeler = $rev_q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$bekleyen_revizeler = array_filter($revizeler, function($r) { return $r['durum'] == 0; });

// Limit Kontrolleri
$f_limit_sorgu = $db->prepare("SELECT f.ek_depolama_alani, p.depolama_limiti FROM firmalar f LEFT JOIN paketler p ON f.paket_id = p.id WHERE f.id = ?");
$f_limit_sorgu->execute([$firma_id]);
$f_limitler = $f_limit_sorgu->fetch(PDO::FETCH_ASSOC);

$total_storage_mb = (int)($f_limitler['depolama_limiti'] ?? 0) + (int)($f_limitler['ek_depolama_alani'] ?? 0);
$total_storage_bytes = $total_storage_mb * 1048576;

$base_upload_path = rtrim(__DIR__, '/') . '/uploads';
$firm_total_used = 0;
foreach (['albumler', 'haziralbumler', 'videolar'] as $fld) {
    $firm_folder = $base_upload_path . "/" . $fld . "/" . $firma_id . "/";
    if (is_dir($firm_folder)) { $firm_total_used += getDirectorySize($firm_folder); }
}

$kalan_bayt = max(0, $total_storage_bytes - $firm_total_used);
$yuzde_kullanim = $total_storage_bytes > 0 ? ($firm_total_used / $total_storage_bytes) * 100 : 0;
$depo_renk = $yuzde_kullanim > 80 ? 'danger' : 'primary';

$last_login_text = "Henüz giriş yapmadı";
try {
    $log_q = $db->prepare("SELECT last_login FROM musteriportal WHERE firma_id = ? AND musteri_id = ? ORDER BY last_login DESC LIMIT 1");
    $log_q->execute([$firma_id, $musteri_id]);
    if ($log_res = $log_q->fetchColumn()) $last_login_text = date('d.m.Y H:i', strtotime($log_res));
} catch (Exception $e) {}

// YOLLAR
$upload_dir = $base_upload_path . "/albumler/" . $firma_id . "/" . $musteri_id . "/";
$album_dir  = $base_upload_path . "/haziralbumler/" . $firma_id . "/" . $musteri_id . "/";
$video_dir  = $base_upload_path . "/videolar/" . $firma_id . "/" . $musteri_id . "/";

$upload_url = "uploads/albumler/" . $firma_id . "/" . $musteri_id . "/";
$album_url  = "uploads/haziralbumler/" . $firma_id . "/" . $musteri_id . "/";
$video_url  = "uploads/videolar/" . $firma_id . "/" . $musteri_id . "/";

$selections = [];
$secim_conn = isset($db_secim) ? $db_secim : $db;
try {
    $s_detay = $secim_conn->prepare("SELECT * FROM user_selections WHERE cari_musteri_id = ? ORDER BY image_path ASC");
    $s_detay->execute([$musteri_id]);
    $selections = $s_detay->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

function getMediaFiles($dir, $extensions) {
    $results = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($file != '.' && $file != '..' && in_array($ext, $extensions)) {
                $results[] = ['name' => $file, 'size' => filesize($dir . $file)];
            }
        }
    }
    return $results;
}

$fotograflar = getMediaFiles($upload_dir, ['jpg', 'jpeg', 'png', 'webp']);
$hazir_albumler = getMediaFiles($album_dir, ['jpg', 'jpeg', 'png', 'webp']);
$yuklenen_videolar = getMediaFiles($video_dir, ['mp4', 'mov', 'webm', 'avi', 'mkv']);

$sayfaBasligi = "Portal Yönetimi: " . $musteri['ad_soyad'];
include 'partials/header_yonetim.php';
?>

<link href="https://releases.transloadit.com/uppy/v3.3.1/uppy.min.css" rel="stylesheet">
<style>
.preview-wrapper { cursor: pointer; position: relative; }
.hover-large-preview { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 500px; max-height: 500px; z-index: 9999; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 3px solid white; border-radius: 8px; object-fit: contain; background: #000; }
.preview-wrapper:hover .hover-large-preview { display: block; }
</style>

<div class="container py-4">

    <?php if($total_storage_mb > 0): ?>
    <div class="card border-<?= $depo_renk ?> mb-4 shadow-sm">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-dark"><i class="fas fa-hdd text-<?= $depo_renk ?> me-2"></i>Firma Genel Depolama Kotası</span>
                <span class="badge bg-<?= $depo_renk ?> fs-6"><?= formatBytes($firm_total_used) ?> / <?= formatBytes($total_storage_bytes) ?> (<?= number_format($yuzde_kullanim, 1) ?>%)</span>
            </div>
            <div class="progress" style="height: 10px;">
                <div class="progress-bar bg-<?= $depo_renk ?>" style="width: <?= $yuzde_kullanim ?>%"></div>
            </div>
            <?php if($yuzde_kullanim > 80): ?>
                <small class="text-danger fw-bold mt-2 d-block"><i class="fas fa-exclamation-triangle"></i> Kotanız dolmak üzere!</small>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if(isset($_GET['status']) && $_GET['status'] === 'success'): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4"><i class="fas fa-check-circle me-2"></i> İşlem Başarıyla Kaydedildi!</div>
    <?php endif; ?>
    
    <div class="row mb-4 align-items-center">
        <div class="col-lg-8">
            <h2 class="fw-bold mb-1 text-primary"><?php echo htmlspecialchars($portal_data['ad_soyad']); ?></h2>
            <?php if(!empty($portal_data['gelin_ad']) || !empty($portal_data['damat_ad'])): ?>
                <div class="mb-1 text-muted"><i class="fas fa-heart text-danger small me-1"></i><?php echo htmlspecialchars(trim($portal_data['gelin_ad'] . ' & ' . $portal_data['damat_ad'], ' &')); ?></div>
            <?php endif; ?>
            <div class="mb-2 text-muted small"><i class="fas fa-clock text-warning me-1"></i>Portala Son Giriş: <strong><?php echo $last_login_text; ?></strong></div>
        </div>
        <div class="col-lg-4 text-lg-end">
            <a href="musteri_detay.php?t=<?php echo htmlspecialchars($token); ?>" class="btn btn-outline-primary shadow-sm"><i class="fas fa-arrow-left me-2"></i>Detaylara Dön</a>
        </div>
    </div>

    <?php if (!empty($bekleyen_revizeler)): ?>
        <?php $br = reset($bekleyen_revizeler); // Array'in ilk (en yeni) elemanını alır ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4" style="background: linear-gradient(135deg, #fffbeb, #fef3c7); border-left: 6px solid #f59e0b !important;">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h5 class="fw-bold mb-2" style="color: #b45309;"><i class="fas fa-exclamation-triangle me-2"></i>Müşteri Yeni Revize Talep Etti!</h5>
                    <div class="small text-muted mb-2"><i class="far fa-clock me-1"></i>Talep Zamanı: <strong><?= date('d.m.Y H:i', strtotime($br['created_at'])) ?></strong></div>
                    <div class="p-3 rounded-3 bg-white shadow-sm border border-warning" style="color: #451a03; font-weight: 500; font-size: 0.95rem;">
                        <?= nl2br(htmlspecialchars($br['notlar'])) ?>
                    </div>
                </div>
                <div class="text-md-end">
                    <form method="POST">
                        <input type="hidden" name="m_id_hidden_settings_revize" value="<?= $musteri_id ?>">
                        <input type="hidden" name="revize_id" value="<?= $br['id'] ?>">
                        <button type="submit" name="revize_tamamla" class="btn btn-warning fw-bold py-2 px-4 shadow-sm" style="color: #451a03;">
                            <i class="fas fa-check-circle me-2"></i>Revizeyi Tamamla & Tasarıma Dön
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4 text-center">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-primary text-white" data-bs-toggle="modal" data-bs-target="#fileListModal" style="cursor:pointer;">
                <div class="card-body py-4"><h3 class="mb-0"><?php echo count($fotograflar); ?></h3><small class="opacity-75 text-uppercase fw-bold">Yüklü Fotoğraf</small></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-success text-white" data-bs-toggle="modal" data-bs-target="#selectionModal" style="cursor:pointer;">
                <div class="card-body py-4"><h3 class="mb-0"><?php echo count(array_filter($selections, function($s){return (($s['selection_type'] ?? 0) == 1 || ($s['selection_type'] ?? 0) == 3);})); ?></h3><small class="opacity-75 text-uppercase fw-bold">Seçilenler</small></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-secondary text-white" data-bs-toggle="modal" data-bs-target="#albumModal" style="cursor:pointer;">
                <div class="card-body py-4"><h3 class="mb-0"><?php echo count($hazir_albumler); ?></h3><small class="opacity-75 text-uppercase fw-bold">Hazır Albüm</small></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-dark text-white" data-bs-toggle="modal" data-bs-target="#videoModal" style="cursor:pointer;">
                <div class="card-body py-4"><h3 class="mb-0"><?php echo count($yuklenen_videolar); ?></h3><small class="opacity-75 text-uppercase fw-bold">Videolar</small></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="fw-bold mb-4"><i class="fas fa-tasks me-2 text-primary"></i>Süreç ve Portal Ayarları</h5>
                <form action="musteri_portal_yonetim.php?t=<?php echo htmlspecialchars($token); ?>" method="POST">
                    <input type="hidden" name="form_ayarlari_kaydet" value="1">
                    <input type="hidden" name="m_id_hidden_settings" value="<?php echo $musteri_id; ?>">
                    
                    <?php if (!empty($revizeler)): ?>
                    <div class="mb-3">
                        <button type="button" class="btn btn-outline-secondary w-100 fw-bold border-2" data-bs-toggle="modal" data-bs-target="#revizeGecmisModal">
                            <i class="fas fa-history me-2"></i>Revize Geçmişini Gör (<?= count($revizeler) ?>)
                        </button>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">İŞ AKIŞ DURUMU</label>
                        <select name="workflow_status" class="form-select border-2 border-primary fw-bold text-dark">
                            <option value="0" <?php echo ($wf_status == 0) ? 'selected' : ''; ?>>Fotoğraf Bekleniyor</option>
                            <option value="1" <?php echo ($wf_status == 1) ? 'selected' : ''; ?>>Müşteri Seçiminde</option>
                            <option value="2" <?php echo ($wf_status == 2) ? 'selected' : ''; ?>>Müşteri Seçimi Bitirdi</option>
                            <option value="3" <?php echo ($wf_status == 3) ? 'selected' : ''; ?>>Tasarım / Revize Yüklemesi Bekleniyor</option>
                            <option value="4" <?php echo ($wf_status == 4) ? 'selected' : ''; ?>>Albüm Onayında</option>
                            <option value="5" <?php echo ($wf_status == 5) ? 'selected' : ''; ?>>Baskı Aşamasında</option>
                            <option value="6" <?php echo ($wf_status == 6) ? 'selected' : ''; ?>>Müşteriye Teslim Edildi</option>
                            <option value="7" <?php echo ($wf_status == 7) ? 'selected' : ''; ?>>Revize Talep Edildi</option>
                        </select>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">MİN. SEÇİM (0=Yok)</label>
                            <input type="number" name="min_secim" class="form-control" value="<?php echo (int)($musteri['min_secim']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">MAX. SEÇİM (0=Sınırsız)</label>
                            <input type="number" name="max_secim" class="form-control" value="<?php echo (int)($musteri['max_secim']); ?>">
                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label small fw-bold d-block text-muted">PORTAL ERİŞİMİ</label>
                            <div class="btn-group w-100">
                                <input type="radio" class="btn-check" name="portal_status" id="p1" value="1" <?php echo ($musteri['portal_status'] == 1) ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-success fw-bold" for="p1">Açık</label>
                                <input type="radio" class="btn-check" name="portal_status" id="p0" value="0" <?php echo ($musteri['portal_status'] == 0) ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-danger fw-bold" for="p0">Kapalı</label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-3 shadow-sm"><i class="fas fa-save me-2"></i>AYARLARI KAYDET</button>
                </form>

                <hr class="my-4">
                <h6 class="fw-bold small mb-2 text-muted text-uppercase">Paylaşım ve Erişim</h6>
                <div class="input-group mb-3">
                    <input type="text" id="pLink" class="form-control form-control-sm bg-light" readonly value="https://musteri.ibircari.xyz/?token=<?php echo htmlspecialchars((string)$token); ?>">
                    <button type="button" class="btn btn-dark btn-sm" onclick="window.copyPortalLink()"><i class="fas fa-copy"></i></button>
                </div>
                <div class="d-grid">
                    <button type="button" class="btn btn-success fw-bold py-2 shadow-sm" onclick="window.sendWhatsApp()">
                        <i class="fab fa-whatsapp me-2 fa-lg"></i>WhatsApp İle Gönder
                    </button>
                </div>
                <div id="copyMsg" class="small text-success text-center fw-bold mt-2" style="display:none;">Bağlantı kopyalandı!</div>
            </div>
        </div>

        <div class="col-lg-7">
            
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="fw-bold mb-4"><i class="fas fa-images me-2 text-primary"></i>Müşteri Seçimi İçin Fotoğraf Yükle</h5>
                <form id="uploadForm">
                    <input type="hidden" name="m_id" id="m_id_hidden" value="<?php echo $musteri_id; ?>">
                    <div class="p-4 mb-3 text-center border-2 border-dashed border-primary bg-light rounded-4" style="cursor:pointer;" onclick="document.getElementById('fInp').click()">
                        <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-2"></i>
                        <p class="mb-0 fw-bold text-dark">Fotoğrafları Seçmek İçin Tıklayın</p>
                        <input type="file" id="fInp" name="photos[]" multiple hidden onchange="window.handleFileSelect()">
                    </div>
                    <div id="fileInfo" class="small text-center my-2 text-primary fw-bold"></div>
                    <div class="progress" id="pDiv" style="height: 20px; display:none;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success fw-bold" id="pBar" style="width: 0%">0%</div>
                    </div>
                    <button type="button" class="btn btn-primary w-100 fw-bold py-2 shadow-sm mt-2" onclick="window.startSequentialUpload()" id="upBtn" disabled>Yüklemeyi Başlat</button>
                </form>
            </div>

            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="fw-bold mb-4"><i class="fas fa-book-open me-2 text-success"></i>Tasarımı Biten Albümleri Yükle</h5>
                <form id="albumUploadForm">
                    <input type="hidden" name="m_id" id="album_m_id" value="<?php echo $musteri_id; ?>">
                    <div class="p-4 mb-3 text-center border-2 border-dashed border-success bg-light rounded-4" onclick="document.getElementById('albumInp').click()" style="cursor:pointer;">
                        <i class="fas fa-layer-group fa-3x text-success mb-2"></i>
                        <p class="mb-0 fw-bold text-dark">Hazır Albüm Seçmek İçin Tıklayın</p>
                        <input type="file" id="albumInp" name="albums[]" multiple hidden>
                    </div>
                    <div id="albumInfo" class="small text-center my-2 text-success fw-bold"></div>
                    <div class="progress" id="albumPDiv" style="height: 20px; display:none;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success fw-bold" id="albumPBar" style="width: 0%">0%</div>
                    </div>
                    <button type="button" class="btn btn-success w-100 fw-bold py-2 shadow-sm mt-2" id="albumUpBtn" disabled>Albümü Yükle</button>
                </form>
            </div>

            <div class="card border-0 shadow-sm p-4 bg-dark text-white">
                <h5 class="fw-bold mb-3"><i class="fas fa-video me-2 text-info"></i>Yüksek Boyutlu Video Yükleme (TUS)</h5>
                <div id="uppy-dashboard" class="w-100 text-dark"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="revizeGecmisModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light border-0 py-3">
                <h5 class="fw-bold m-0 text-dark"><i class="fas fa-history me-2 text-warning"></i>Revize Geçmişi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <?php if (empty($revizeler)): ?>
                    <div class="text-center text-muted py-4">Kayıtlı revize talebi bulunmuyor.</div>
                <?php else: ?>
                    <div class="timeline-wrapper">
                        <?php foreach ($revizeler as $r): ?>
                            <div class="card border-0 shadow-sm mb-3 <?= $r['durum'] == 1 ? 'border-start border-4 border-success' : 'border-start border-4 border-warning' ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge <?= $r['durum'] == 1 ? 'bg-success' : 'bg-warning text-dark' ?>">
                                            <?= $r['durum'] == 1 ? '<i class="fas fa-check me-1"></i>Tamamlandı' : '<i class="fas fa-clock me-1"></i>Bekliyor' ?>
                                        </span>
                                        <small class="text-muted fw-bold"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></small>
                                    </div>
                                    <p class="mb-0 text-dark" style="font-size: 0.95rem;"><?= nl2br(htmlspecialchars($r['notlar'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="selectionModal"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header bg-light border-0 py-3"><h5 class="fw-bold m-0 text-primary"><i class="fas fa-check-double me-2"></i>Seçim Detayları</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><div class="table-responsive" style="max-height: 400px;"><table class="table table-hover align-middle" id="selectionTable"><thead class="table-light sticky-top"><tr><th>Dosya Adı</th><th width="140">Durum</th></tr></thead><tbody><?php if(empty($selections)): ?><tr><td colspan="2" class="text-center py-4">Seçim yok.</td></tr><?php else: ?><?php foreach($selections as $s): ?><tr><td class="fw-bold small"><?php echo htmlspecialchars((string)$s['image_path']); ?></td><td><?php $stype = (int)($s['selection_type'] ?? 0); if($stype == 1 || $stype == 3) echo '<span class="badge bg-success me-1">SEÇİLDİ</span>'; if($stype == 2 || $stype == 3) echo '<span class="badge bg-warning text-dark">FAVORİ</span>'; ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div><div class="modal-footer bg-light border-0"><button class="btn btn-dark fw-bold" onclick="window.exportTxt()"><i class="fas fa-download me-2"></i>Kodu İndir</button></div></div></div></div>

<div class="modal fade" id="fileListModal"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content border-0 shadow-lg"><div class="modal-header bg-light border-0 py-3 align-items-center"><h5 class="fw-bold m-0 text-primary"><i class="fas fa-folder-open me-2"></i>Fotoğraflar</h5><div class="ms-auto me-3"><button type="button" class="btn btn-danger btn-sm shadow-sm fw-bold" onclick="window.bulkDelete('foto')">Seçilenleri Sil</button></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="bg-light border-bottom p-2 px-3 d-flex align-items-center"><input class="form-check-input me-2" type="checkbox" id="checkAllFoto" onclick="window.toggleAll('foto', this)"><label class="form-check-label small fw-bold" for="checkAllFoto">Tümünü Seç</label></div><div style="max-height: 450px; overflow-y: auto;"><?php if(empty($fotograflar)): ?><div class="p-5 text-center text-muted">Boş.</div><?php else: ?><?php foreach($fotograflar as $foto): ?><?php $fPath = $upload_url . $foto['name']; ?><div class="d-flex justify-content-between align-items-center p-2 border-bottom item-row"><div class="d-flex align-items-center gap-3"><input class="form-check-input bulk-check-foto" type="checkbox" value="<?php echo htmlspecialchars($foto['name']); ?>"><div class="preview-wrapper"><img src="<?php echo $fPath; ?>" width="40" height="40" class="rounded object-fit-cover border"><img src="<?php echo $fPath; ?>" class="hover-large-preview"></div><span class="fw-medium small"><?php echo htmlspecialchars($foto['name']); ?></span></div><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="window.singleDelete('foto', '<?php echo htmlspecialchars($foto['name'], ENT_QUOTES); ?>')"><i class="fas fa-trash"></i></button></div><?php endforeach; ?><?php endif; ?></div></div></div></div></div>

<div class="modal fade" id="albumModal"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header bg-light border-0 py-3 align-items-center"><h5 class="fw-bold m-0 text-primary"><i class="fas fa-folder-open me-2"></i>Albümler</h5><div class="ms-auto me-3"><button type="button" class="btn btn-danger btn-sm shadow-sm fw-bold" onclick="window.bulkDelete('album')">Seçilenleri Sil</button></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="bg-light border-bottom p-2 px-3 d-flex align-items-center"><input class="form-check-input me-2" type="checkbox" id="checkAllAlbum" onclick="window.toggleAll('album', this)"><label class="form-check-label small fw-bold" for="checkAllAlbum">Tümünü Seç</label></div><div style="max-height: 450px; overflow-y:auto;"><?php if(empty($hazir_albumler)): ?><div class="p-5 text-center text-muted">Boş.</div><?php else: ?><?php foreach($hazir_albumler as $file): ?><?php $aPath = $album_url . $file['name']; ?><div class="d-flex justify-content-between align-items-center p-2 border-bottom item-row"><div class="d-flex align-items-center gap-3"><input class="form-check-input bulk-check-album" type="checkbox" value="<?php echo htmlspecialchars($file['name']); ?>"><div class="preview-wrapper"><img src="<?php echo $aPath; ?>" width="40" height="40" class="rounded object-fit-cover border"><img src="<?php echo $aPath; ?>" class="hover-large-preview"></div><span class="fw-medium small"><?php echo htmlspecialchars($file['name']); ?></span></div><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="window.singleDelete('album', '<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')"><i class="fas fa-trash"></i></button></div><?php endforeach; ?><?php endif; ?></div></div></div></div></div>

<div class="modal fade" id="videoModal"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header bg-light border-0 py-3 align-items-center"><h5 class="fw-bold m-0 text-primary"><i class="fas fa-video me-2"></i>Videolar</h5><div class="ms-auto me-3"><button type="button" class="btn btn-danger btn-sm shadow-sm fw-bold" onclick="window.bulkDelete('video')">Seçilenleri Sil</button></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="bg-light border-bottom p-2 px-3 d-flex align-items-center"><input class="form-check-input me-2" type="checkbox" id="checkAllVideo" onclick="window.toggleAll('video', this)"><label class="form-check-label small fw-bold" for="checkAllVideo">Tümünü Seç</label></div><div style="max-height: 450px; overflow-y:auto;"><?php if(empty($yuklenen_videolar)): ?><div class="p-5 text-center text-muted">Boş.</div><?php else: ?><?php foreach($yuklenen_videolar as $file): ?><?php $vPath = $video_url . $file['name']; ?><div class="d-flex justify-content-between align-items-center p-2 px-3 border-bottom item-row"><div class="d-flex align-items-center gap-3"><input class="form-check-input bulk-check-video" type="checkbox" value="<?php echo htmlspecialchars($file['name']); ?>"><span class="fw-medium small"><?php echo htmlspecialchars($file['name']); ?></span></div><div class="d-flex gap-2"><button class="btn btn-sm btn-primary" onclick="window.playVideo('<?php echo $vPath; ?>')"><i class="fas fa-play"></i></button><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="window.singleDelete('video', '<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')"><i class="fas fa-trash"></i></button></div></div><?php endforeach; ?><?php endif; ?></div></div></div></div></div>

<div class="modal fade" id="videoPlayModal" style="z-index: 1060;"><div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content bg-transparent border-0 shadow-none"><div class="modal-header border-0 pb-0 justify-content-end"><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="filter: invert(1);"></button></div><div class="modal-body p-0 text-center"><video id="globalVideoPlayer" controls style="max-width: 100%; max-height: 80vh; border-radius: 8px; box-shadow: 0 0 30px rgba(0,0,0,0.8);"></video></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> 

<script>
    const KALAN_KOTA_BAYT = <?php echo $kalan_bayt; ?>;
    
    // Sunucudaki mevcut dosya isimleri
    const mevcutFotograflar = <?php echo json_encode(array_column($fotograflar, 'name')); ?>;
    const mevcutAlbumler = <?php echo json_encode(array_column($hazir_albumler, 'name')); ?>;
    const mevcutVideolar = <?php echo json_encode(array_column($yuklenen_videolar, 'name')); ?>;
    
    // PHP'deki guvenliFisyaAdi fonksiyonunun JS versiyonu
    function cleanFileNameJS(name) {
        let trMap = {'ğ':'g','Ğ':'G','ç':'c','Ç':'C','ş':'s','Ş':'S','ü':'u','Ü':'U','ö':'o','Ö':'O','ı':'i','İ':'I'};
        let clean = name.replace(/[ğĞçÇşŞüÜöÖıİ]/g, m => trMap[m]);
        clean = clean.replace(/[ \(\)\[\]\{\}\&\%\+\=]/g, '_');
        return clean.replace(/[^a-zA-Z0-9_.-]/g, '');
    }

    // SAYFADAN AYRILMA (UPLOAD KOPMASI) UYARISI
    let isUploading = false;
    window.addEventListener('beforeunload', function (e) {
        if (isUploading) {
            e.preventDefault();
            e.returnValue = 'Yükleme işlemi devam ediyor. Sayfadan ayrılırsanız işlem iptal olur!';
        }
    });
</script>

<script type="module">
  import { Uppy, Dashboard, Tus } from "https://releases.transloadit.com/uppy/v3.3.1/uppy.min.mjs"
  
  const uppy = new Uppy({
      debug: false, autoProceed: false, allowMultipleUploadBatches: true,
      restrictions: { 
          allowedFileTypes: ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
          maxTotalFileSize: KALAN_KOTA_BAYT
      }
  });

  uppy.use(Dashboard, {
      inline: true, target: '#uppy-dashboard', proudlyDisplayPoweredByUppy: false,
      theme: 'dark', width: '100%', height: 350, showProgressDetails: true,
      note: 'MP4, MOV, AVI formatlarında yüksek boyutlu video yükleyebilirsiniz.'
  });

  uppy.use(Tus, { endpoint: 'api_videoklipupload.php', retryDelays: [0, 1000, 3000, 5000], chunkSize: 15 * 1024 * 1024, limit: 1 });

  uppy.on('file-added', (file) => {
      // VİDEO İÇİN ÜZERİNE YAZMA UYARISI
      let safeName = cleanFileNameJS(file.name);
      if (mevcutVideolar.includes(safeName)) {
          Swal.fire({
              title: 'Bu video zaten var!', 
              text: `'${file.name}' isimli video sunucuda mevcut. Üzerine yazmak istiyor musunuz?`, 
              icon: 'warning', 
              showCancelButton: true, 
              confirmButtonText: 'Evet, Üzerine Yaz', 
              cancelButtonText: 'İptal Et'
          }).then((result) => {
              if (!result.isConfirmed) {
                  uppy.removeFile(file.id); // İptal ederse listeden çıkar
              }
          });
      }
      uppy.setFileMeta(file.id, { musteri_id: '<?php echo $musteri_id; ?>', firma_id: '<?php echo $firma_id; ?>', original_name: file.name });
  });

  uppy.on('upload', () => { isUploading = true; }); // Yükleme başladı, çıkışı engelle
  uppy.on('complete', (result) => {
      isUploading = false; // Yükleme bitti, çıkışa izin ver
      if(result.successful.length > 0) {
          Swal.fire('Başarılı', result.successful.length + ' video yüklendi!', 'success').then(() => { location.reload(); });
      }
  });
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const modals = ['#fileListModal', '#albumModal', '#videoModal'];
    modals.forEach(function(s) {
        const m = document.querySelector(s);
        if (m) m.addEventListener('hidden.bs.modal', function () { this.querySelectorAll('.form-check-input').forEach(cb => cb.checked = false); });
    });
});

window.toggleAll = function(type, source) { document.querySelectorAll('.bulk-check-' + type).forEach(cb => cb.checked = source.checked); };

window.playVideo = function(url) {
    const player = document.getElementById('globalVideoPlayer');
    player.src = url; player.play();
    new bootstrap.Modal(document.getElementById('videoPlayModal')).show();
    document.getElementById('videoPlayModal').addEventListener('hidden.bs.modal', () => { player.pause(); player.src = ''; }, {once: true});
};

window.bulkDelete = async function(type) {
    const checkboxes = document.querySelectorAll('.bulk-check-' + type + ':checked');
    if(checkboxes.length === 0) return Swal.fire('Uyarı', 'Seçim yapmadınız.', 'warning');
    const files = Array.from(checkboxes).map(cb => cb.value);

    const res = await Swal.fire({ title: 'Emin misiniz?', text: 'Kalıcı olarak silinecek!', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sil', cancelButtonText: 'İptal' });
    if (!res.isConfirmed) return;

    const fd = new FormData();
    fd.append('type', type); fd.append('musteri_id', '<?php echo $musteri_id; ?>');
    files.forEach(f => fd.append('files[]', f));

    try {
        const req = await fetch('api_toplu_sil.php', { method: 'POST', body: fd });
        const json = await req.json();
        if(json.success) Swal.fire('Başarılı', json.message, 'success').then(() => location.reload());
        else Swal.fire('Hata', json.message, 'error');
    } catch(e) { Swal.fire('Hata', 'Sunucu hatası.', 'error'); }
};

window.singleDelete = async function(type, filename) {
    const res = await Swal.fire({ title: 'Emin misiniz?', text: 'Kalıcı silinecek!', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sil' });
    if (!res.isConfirmed) return;

    const fd = new FormData();
    fd.append('type', type); fd.append('musteri_id', '<?php echo $musteri_id; ?>'); fd.append('files[]', filename); 

    try {
        const req = await fetch('api_toplu_sil.php', { method: 'POST', body: fd });
        const json = await req.json();
        if(json.success) Swal.fire('Başarılı', "Silindi.", 'success').then(() => location.reload());
        else Swal.fire('Hata', json.message, 'error');
    } catch(e) { Swal.fire('Hata', 'Sunucu hatası.', 'error'); }
};

window.sendWhatsApp = function() {
    let phone = "<?php echo (string)$musteri['telefon']; ?>".replace(/[^0-9]/g, '');
    if(phone.startsWith('0')) phone = phone.substring(1);
    if(!phone.startsWith('90')) phone = "90" + phone;
    const msg = `Merhaba <?php echo addslashes((string)$musteri['ad_soyad']); ?>, portala erişim linkiniz:\n\nBağlantı: ${document.getElementById("pLink").value}`;
    window.open(`https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(msg)}`, '_blank');
};

window.copyPortalLink = function() {
    const el = document.getElementById("pLink"); el.select(); document.execCommand("copy");
    const msg = document.getElementById("copyMsg"); msg.style.display = 'block';
    setTimeout(() => { msg.style.display = 'none'; }, 2000);
};

window.handleFileSelect = function() {
    const inp = document.getElementById('fInp');
    let totalSize = 0;
    for(let f of inp.files) totalSize += f.size;
    
    if (totalSize > KALAN_KOTA_BAYT) {
        Swal.fire('Kota Yetersiz!', 'Seçtiğiniz dosyaların boyutu kalan depolama kotanızı aşıyor.', 'error');
        inp.value = '';
        document.getElementById('upBtn').disabled = true;
        document.getElementById('fileInfo').innerText = '';
        return;
    }
    
    document.getElementById('fileInfo').innerText = inp.files.length + " fotoğraf hazır.";
    document.getElementById('upBtn').disabled = inp.files.length === 0;
};

// FOTOĞRAF YÜKLEME BAŞLAT
window.startSequentialUpload = async function() {
    const inp = document.getElementById('fInp');
    const files = inp.files;
    
    // YENİ ÖZELLİK: Kopya Kontrolü
    let hasDuplicate = false;
    for(let f of files) {
        if (mevcutFotograflar.includes(cleanFileNameJS(f.name))) {
            hasDuplicate = true; break;
        }
    }
    
    if (hasDuplicate) {
        const confirm = await Swal.fire({
            title: 'Bu dosyalar zaten var!',
            text: 'Seçtiğiniz fotoğrafların bazıları sunucuda zaten mevcut. Üzerine yazılsın mı?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet, Üzerine Yaz',
            cancelButtonText: 'İptal Et'
        });
        if (!confirm.isConfirmed) {
            return; // Kullanıcı iptal etti
        }
    }

    const pBar = document.getElementById('pBar');
    const m_id = document.getElementById('m_id_hidden').value;

    document.getElementById('upBtn').disabled = true;
    document.getElementById('pDiv').style.display = 'block';
    
    isUploading = true; // Sayfadan çıkışı engelle

    for(let i = 0; i < files.length; i++) {
        const fd = new FormData();
        fd.append('m_id', m_id); fd.append('photos[]', files[i]);
        pBar.innerText = `Yükleniyor (${i+1}/${files.length})`;
        
        try {
            await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.upload.onprogress = (e) => { if(e.lengthComputable) pBar.style.width = Math.round((e.loaded/e.total)*100)+'%'; };
                xhr.onload = () => { try { JSON.parse(xhr.responseText).status === 'success' ? resolve() : reject(); } catch(e) { reject(); } };
                xhr.onerror = () => reject();
                xhr.open("POST", "api_upload.php"); xhr.send(fd);
            });
        } catch(err) { alert("Hata oluştu."); isUploading = false; return; }
    }
    
    isUploading = false; // Yükleme bitti, çıkış serbest
    pBar.innerText = "İş Akışı Güncelleniyor...";
    await fetch(`musteri_portal_yonetim.php?oto_akim=1&m_id=${m_id}`);
    location.reload();
};

document.getElementById('albumInp')?.addEventListener('change', function() {
    let totalSize = 0;
    for(let f of this.files) totalSize += f.size;
    
    if (totalSize > KALAN_KOTA_BAYT) {
        Swal.fire('Kota Yetersiz!', 'Albüm boyutu kalan kotanızı aşıyor.', 'error');
        this.value = '';
        document.getElementById('albumUpBtn').disabled = true;
        document.getElementById('albumInfo').innerText = '';
        return;
    }
    
    document.getElementById('albumInfo').innerText = this.files.length + " fotoğraf hazır.";
    document.getElementById('albumUpBtn').disabled = this.files.length === 0;
});

// ALBÜM YÜKLEME BAŞLAT
document.getElementById('albumUpBtn')?.addEventListener('click', async function() {
    const files = document.getElementById('albumInp').files;
    
    // YENİ ÖZELLİK: Kopya Kontrolü (Revize)
    let hasDuplicate = false;
    for(let f of files) {
        if (mevcutAlbumler.includes(cleanFileNameJS(f.name))) {
            hasDuplicate = true; break;
        }
    }
    
    if (hasDuplicate) {
        const confirm = await Swal.fire({
            title: 'Bu dosyalar zaten var!',
            text: 'Seçtiğiniz albüm sayfaları sunucuda mevcut. Eğer REVİZE yüklüyorsanız "Üzerine Yaz" diyerek işlemi tamamlayabilirsiniz.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Evet, Üzerine Yaz',
            cancelButtonText: 'İptal Et'
        });
        if (!confirm.isConfirmed) {
            return; // İptal edildi
        }
    }

    const pBar = document.getElementById('albumPBar');
    const m_id = document.getElementById('album_m_id').value;

    this.disabled = true;
    document.getElementById('albumPDiv').style.display = 'block';
    
    isUploading = true; // Sayfadan çıkışı engelle

    for(let i = 0; i < files.length; i++) {
        const fd = new FormData();
        fd.append('m_id', m_id); fd.append('albums[]', files[i]); 
        pBar.innerText = `Albüm Yükleniyor (${i+1}/${files.length})`;
        
        try {
            await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.upload.onprogress = (e) => { if(e.lengthComputable) pBar.style.width = Math.round((e.loaded/e.total)*100)+'%'; };
                xhr.onload = () => { try { JSON.parse(xhr.responseText).status === 'success' ? resolve() : reject(); } catch(e) { reject(); } };
                xhr.onerror = () => reject();
                xhr.open("POST", "api_album_upload.php"); xhr.send(fd);
            });
        } catch(err) { alert("Hata."); isUploading = false; return; }
    }
    
    isUploading = false; // Yükleme bitti
    pBar.innerText = "İş Akışı Güncelleniyor...";
    await fetch(`musteri_portal_yonetim.php?oto_akim=4&m_id=${m_id}`);
    location.reload();
});

window.exportTxt = function() {
    const rows = document.querySelectorAll('#selectionTable tbody tr');
    let text = "";
    rows.forEach(r => {
        const name = r.cells[0]?.innerText.trim();
        if(name && !name.includes("Henüz")) { let clean = name.substring(4, 8); if(clean) text += clean + " "; }
    });
    if(!text) return alert("Seçim bulunamadı.");
    const blob = new Blob([text.trim()], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = window.URL.createObjectURL(blob);
    a.download = "fotograf_kodlari.txt";
    a.click();
};
</script>
<?php include 'partials/footer_yonetim.php'; ?>

işte güncel kod bu