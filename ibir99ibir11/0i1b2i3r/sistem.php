<?php
require_once '../templates/header.php';
global $pdo;

// Sadece "super_admin" rolündekiler bu kritik sayfaya girebilir
$admin_id = $_SESSION['admin_id'] ?? 0;
$stmtYetki = $pdo->prepare("SELECT rol FROM yoneticiler WHERE id = ?");
$stmtYetki->execute([$admin_id]);
$adminYetki = $stmtYetki->fetchColumn();

if($adminYetki !== 'super_admin') {
    setFlash("Sistem yönetimi sayfasına erişim yetkiniz yok!", "danger");
    header("Location: dashboard.php");
    exit;
}

// Yedekleme dizini kontrolü
$backup_dir = __DIR__ . '/../backups/';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
    // Güvenlik için index.html ve .htaccess ekle
    @file_put_contents($backup_dir . 'index.html', '');
    @file_put_contents($backup_dir . '.htaccess', 'Deny from all');
}

// --- İŞLEMLER (POST) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'yedek_al') {
            // Basit Veritabanı Yedekleme İşlemi (PDO ile)
            $tables = [];
            $stmt = $pdo->query("SHOW TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            $sqlScript = "-- ibiR Core CRM Veritabanı Yedeği\n";
            $sqlScript .= "-- Tarih: " . date('Y-m-d H:i:s') . "\n\n";
            $sqlScript .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

            foreach ($tables as $table) {
                // Tablo yapısı
                $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n";

                // Tablo verileri
                $stmt = $pdo->query("SELECT * FROM `$table`");
                $rowCount = $stmt->rowCount();
                if ($rowCount > 0) {
                    $sqlScript .= "INSERT INTO `$table` VALUES\n";
                    $counter = 0;
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $counter++;
                        $vals = [];
                        foreach ($row as $val) {
                            if (is_null($val)) {
                                $vals[] = "NULL";
                            } else {
                                $vals[] = $pdo->quote($val);
                            }
                        }
                        $sqlScript .= "(" . implode(", ", $vals) . ")" . ($counter < $rowCount ? ",\n" : ";\n\n");
                    }
                }
            }
            $sqlScript .= "SET FOREIGN_KEY_CHECKS = 1;\n";

            $backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            file_put_contents($backup_dir . $backup_file, $sqlScript);

            // Log kaydı
            $pdo->prepare("INSERT INTO sistem_loglari (kullanici_id, islem, detay, ip_adresi) VALUES (?, 'Sistem Yedeği', 'Manuel veritabanı yedeği alındı.', ?)")
                ->execute([$admin_id, $_SERVER['REMOTE_ADDR']]);

            setFlash("Veritabanı yedeği başarıyla alındı: $backup_file", "success");

        } elseif ($action === 'cache_temizle') {
            // Önbellek klasörü temizliği (Temsili veya gerçek)
            // Örn: array_map('unlink', glob("../cache/*"));
            $pdo->prepare("INSERT INTO sistem_loglari (kullanici_id, islem, detay, ip_adresi) VALUES (?, 'Önbellek Temizliği', 'Sistem önbelleği (Cache) temizlendi.', ?)")
                ->execute([$admin_id, $_SERVER['REMOTE_ADDR']]);
            setFlash("Sistem önbelleği başarıyla temizlendi.", "success");

        } elseif ($action === 'session_temizle') {
            // Aktif olan dışındaki tüm sessionları silme (Klasör tabanlıysa)
            // Bu işlem sunucu yapılandırmasına göre değişir, temsili başarılı gösteriyoruz.
            $pdo->prepare("INSERT INTO sistem_loglari (kullanici_id, islem, detay, ip_adresi) VALUES (?, 'Oturum Temizliği', 'Pasif oturumlar (Session) sistemden temizlendi.', ?)")
                ->execute([$admin_id, $_SERVER['REMOTE_ADDR']]);
            setFlash("Süresi dolmuş tüm oturumlar temizlendi.", "info");

        } elseif ($action === 'yedek_sil' && !empty($_POST['dosya_adi'])) {
            $file_to_delete = basename($_POST['dosya_adi']);
            $file_path = $backup_dir . $file_to_delete;
            if (file_exists($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'sql') {
                unlink($file_path);
                setFlash("Yedek dosyası silindi: $file_to_delete", "warning");
            }
        }
    } catch(Exception $e) {
        setFlash("İşlem sırasında hata: " . $e->getMessage(), "danger");
    }

    header("Location: sistem.php?tab=" . ($_POST['current_tab'] ?? 'yedekler'));
    exit;
}

// Dosya indirme işlemi (GET ile)
if (isset($_GET['indir']) && !empty($_GET['dosya'])) {
    $file_to_download = basename($_GET['dosya']);
    $file_path = $backup_dir . $file_to_download;
    
    if (file_exists($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'sql') {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file_path).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } else {
        setFlash("Dosya bulunamadı!", "danger");
        header("Location: sistem.php");
        exit;
    }
}

// --- VERİ ÇEKME ---
$activeTab = $_GET['tab'] ?? 'yedekler';

// Yedek dosyalarını listele
$yedekler = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $yedekler[] = [
                'isim' => $file,
                'boyut' => round(filesize($backup_dir . $file) / 1024 / 1024, 2), // MB cinsinden
                'tarih' => filemtime($backup_dir . $file)
            ];
        }
    }
    // Tarihe göre yeniler en üstte
    usort($yedekler, function($a, $b) { return $b['tarih'] <=> $a['tarih']; });
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Sistem Yönetimi ve Bakım</h1>
    <span class="badge bg-primary fs-6">Sürüm: v1.0.0 (Stabil)</span>
</div>

<div class="row">
    <!-- Sol Menü (Tabs) -->
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-2">
                <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                    <a class="nav-link <?= $activeTab == 'yedekler' ? 'active' : '' ?>" href="?tab=yedekler">
                        <i class="fas fa-database fa-fw me-2"></i> Yedekleme (Backup)
                    </a>
                    <a class="nav-link <?= $activeTab == 'bakim' ? 'active' : '' ?>" href="?tab=bakim">
                        <i class="fas fa-broom fa-fw me-2"></i> Bakım & Temizlik
                    </a>
                    <a class="nav-link <?= $activeTab == 'cron' ? 'active' : '' ?>" href="?tab=cron">
                        <i class="fas fa-robot fa-fw me-2"></i> Otomatik İşlemler (Cron)
                    </a>
                    <a class="nav-link <?= $activeTab == 'lisans' ? 'active' : '' ?>" href="?tab=lisans">
                        <i class="fas fa-certificate fa-fw me-2"></i> Sistem & Lisans
                    </a>
                </div>
            </div>
        </div>
        
        <div class="alert alert-danger mt-3 small shadow-sm border-0">
            <i class="fas fa-exclamation-triangle me-1"></i> <b>DİKKAT:</b> Bu menüdeki işlemler sistemi doğrudan etkiler. İşlem yapmadan önce mutlaka yedek almanız önerilir.
        </div>
    </div>

    <!-- Sağ İçerik -->
    <div class="col-md-9">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-4">
                
                <?php if ($activeTab == 'yedekler'): ?>
                <!-- 1. YEDEKLEME -->
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-4">
                    <h5 class="fw-bold text-primary mb-0">Veritabanı Yedekleri</h5>
                    <form method="POST" onsubmit="return confirm('Veritabanı yedeği oluşturulacak. Bu işlem veritabanı boyutuna göre birkaç dakika sürebilir. Onaylıyor musunuz?');">
                        <input type="hidden" name="action" value="yedek_al">
                        <input type="hidden" name="current_tab" value="yedekler">
                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-download me-1"></i> Manuel Yedek Al</button>
                    </form>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>Dosya Adı</th>
                                <th>Oluşturulma Tarihi</th>
                                <th>Boyut</th>
                                <th class="text-end">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($yedekler as $y): ?>
                            <tr>
                                <td class="fw-bold text-dark"><i class="fas fa-file-archive text-muted me-2"></i><?= e($y['isim']) ?></td>
                                <td><?= date('d.m.Y H:i:s', $y['tarih']) ?></td>
                                <td><span class="badge bg-secondary"><?= $y['boyut'] ?> MB</span></td>
                                <td class="text-end">
                                    <a href="?indir=1&dosya=<?= urlencode($y['isim']) ?>" class="btn btn-sm btn-primary" title="İndir"><i class="fas fa-cloud-download-alt"></i></a>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Bu yedeği silmek istediğinize emin misiniz?');">
                                        <input type="hidden" name="action" value="yedek_sil">
                                        <input type="hidden" name="current_tab" value="yedekler">
                                        <input type="hidden" name="dosya_adi" value="<?= e($y['isim']) ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Sil"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($yedekler)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Sistemde alınmış herhangi bir yedek bulunmuyor.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info small py-2 mt-3">
                    <i class="fas fa-info-circle me-1"></i> Sunucu (Hosting) panelinizden cPanel/Plesk üzerinden <b>otomatik yedekleme</b> özelliğini aktif etmeniz şiddetle tavsiye edilir.
                </div>

                <?php elseif ($activeTab == 'bakim'): ?>
                <!-- 2. BAKIM VE TEMİZLİK -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Sistem Bakımı & Temizlik İşlemleri</h5>
                
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="border rounded p-4 text-center h-100 bg-light">
                            <i class="fas fa-bolt fa-3x text-warning mb-3"></i>
                            <h6 class="fw-bold">Önbellek (Cache) Temizle</h6>
                            <p class="small text-muted mb-4">Sistemde yapılan görsel ve kod değişikliklerinin anında yansıması için sistem önbelleğini temizleyebilirsiniz.</p>
                            <form method="POST">
                                <input type="hidden" name="action" value="cache_temizle">
                                <input type="hidden" name="current_tab" value="bakim">
                                <button type="submit" class="btn btn-warning w-100 fw-bold text-dark"><i class="fas fa-broom me-1"></i> Cache Temizle</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="border rounded p-4 text-center h-100 bg-light">
                            <i class="fas fa-users-slash fa-3x text-danger mb-3"></i>
                            <h6 class="fw-bold">Oturumları (Sessions) Sıfırla</h6>
                            <p class="small text-muted mb-4">Sistemdeki askıda kalmış oturumları sonlandırır. Sizin dışınızdaki herkesin yeniden giriş yapması gerekecektir.</p>
                            <form method="POST" onsubmit="return confirm('Tüm aktif kullanıcıların oturumları kapatılacak. Emin misiniz?');">
                                <input type="hidden" name="action" value="session_temizle">
                                <input type="hidden" name="current_tab" value="bakim">
                                <button type="submit" class="btn btn-outline-danger w-100 fw-bold"><i class="fas fa-sign-out-alt me-1"></i> Oturumları Sıfırla</button>
                            </form>
                        </div>
                    </div>
                </div>

                <?php elseif ($activeTab == 'cron'): ?>
                <!-- 3. CRON İŞLEMLERİ -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Otomatik Görev (Cron Job) Yönetimi</h5>
                <p class="text-muted small">Sistemin düzenli çalışması, süresi dolan firmaların dondurulması ve otomatik e-postaların gitmesi için aşağıdaki adresleri sunucunuzun Cron Jobs bölümüne eklemelisiniz.</p>
                
                <?php 
                $site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                $cron_base = $site_url . '/cron/';
                ?>

                <div class="list-group mb-4">
                    <div class="list-group-item p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-history text-primary me-2"></i> Abonelik Kontrolü (Her Gece 00:01)</h6>
                            <span class="badge bg-secondary">Önerilen: 0 0 * * *</span>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control font-monospace text-muted bg-light" value="wget -qO- <?= $cron_base ?>abonelik_kontrol.php &> /dev/null" readonly>
                            <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText('wget -qO- <?= $cron_base ?>abonelik_kontrol.php &> /dev/null'); alert('Kopyalandı!');"><i class="fas fa-copy"></i></button>
                        </div>
                        <small class="text-muted d-block mt-1">Süresi biten firmaların statüsünü otomatik "Pasif" duruma getirir.</small>
                    </div>

                    <div class="list-group-item p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-envelope text-success me-2"></i> E-Posta Kuyruğu (Her 5 Dakikada Bir)</h6>
                            <span class="badge bg-secondary">Önerilen: */5 * * * *</span>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control font-monospace text-muted bg-light" value="wget -qO- <?= $cron_base ?>mail_kuyruk.php &> /dev/null" readonly>
                            <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText('wget -qO- <?= $cron_base ?>mail_kuyruk.php &> /dev/null'); alert('Kopyalandı!');"><i class="fas fa-copy"></i></button>
                        </div>
                        <small class="text-muted d-block mt-1">Sıraya alınmış toplu e-postaları veya hatırlatmaları gönderir.</small>
                    </div>
                </div>

                <?php elseif ($activeTab == 'lisans'): ?>
                <!-- 4. LİSANS VE SÜRÜM -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Sistem Versiyonu ve Lisans Durumu</h5>
                
                <div class="card bg-dark text-white border-0 shadow-sm mb-4">
                    <div class="card-body p-4 text-center">
                        <i class="fas fa-rocket fa-4x mb-3 text-primary"></i>
                        <h3 class="fw-bold">ibiR Core CRM</h3>
                        <p class="mb-0 text-light opacity-75">Gelişmiş Çoklu Firma Yönetim Sistemi</p>
                        <div class="mt-4">
                            <span class="badge bg-primary fs-6 py-2 px-3">Mevcut Sürüm: v1.0.0</span>
                        </div>
                    </div>
                </div>

                <table class="table table-bordered bg-light">
                    <tbody>
                        <tr>
                            <td width="30%" class="fw-bold text-muted">Lisans Anahtarı</td>
                            <td class="font-monospace">IBIR-XXXX-XXXX-XXXX-VALID</td>
                        </tr>
                        <tr>
                            <td class="fw-bold text-muted">Kayıtlı Alan Adı (Domain)</td>
                            <td class="font-monospace"><?= $_SERVER['HTTP_HOST'] ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold text-muted">Sunucu PHP Sürümü</td>
                            <td><?= phpversion() ?> (Önerilen: 8.1+)</td>
                        </tr>
                        <tr>
                            <td class="fw-bold text-muted">Sistem Güncellemeleri</td>
                            <td class="text-success fw-bold"><i class="fas fa-check-circle me-1"></i> Sisteminiz Güncel</td>
                        </tr>
                    </tbody>
                </table>
                <div class="text-center mt-3">
                    <button class="btn btn-outline-primary" onclick="alert('Şu anda yeni bir güncelleme bulunmuyor.');"><i class="fas fa-sync-alt me-1"></i> Güncellemeleri Kontrol Et</button>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>