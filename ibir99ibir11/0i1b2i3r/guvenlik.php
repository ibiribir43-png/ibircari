<?php
require_once '../templates/header.php';
global $pdo;

// Güvenlik sayfasına sadece "super_admin" rolündekiler girebilir
$admin_id = $_SESSION['admin_id'] ?? 0;
$stmtYetki = $pdo->prepare("SELECT rol FROM yoneticiler WHERE id = ?");
$stmtYetki->execute([$admin_id]);
$adminYetki = $stmtYetki->fetchColumn();

if($adminYetki !== 'super_admin') {
    setFlash("Güvenlik ayarlarına erişim yetkiniz yok!", "danger");
    header("Location: dashboard.php");
    exit;
}

// --- İŞLEMLER (POST) ---

// 1. Güvenlik Ayarlarını Kaydet
if(isset($_POST['guvenlik_kaydet'])) {
    // YENİ: sec_banwords eklendi
    $ayar_isimleri = [
        'sec_login_limit', 'sec_session_timeout', 'sec_ssl_required', 'sec_blocked_ips', 'sec_banwords'
    ];

    try {
        $pdo->beginTransaction();
        
        foreach($ayar_isimleri as $ayar) {
            if(isset($_POST[$ayar])) {
                $deger = trim($_POST[$ayar]);
                $stmt = $pdo->prepare("INSERT INTO sistem_ayarlari (ayar_adi, ayar_degeri) VALUES (?, ?) ON DUPLICATE KEY UPDATE ayar_degeri = ?");
                $stmt->execute([$ayar, $deger, $deger]);
            }
        }

        // İşlemi Logla
        $pdo->prepare("INSERT INTO sistem_loglari (kullanici_id, islem, detay, ip_adresi) VALUES (?, 'Güvenlik Ayarları', 'Güvenlik politikaları, IP listesi ve yasaklı kelimeler güncellendi.', ?)")
            ->execute([$admin_id, $_SERVER['REMOTE_ADDR']]);

        $pdo->commit();
        setFlash("Güvenlik ayarları başarıyla kaydedildi.", "success");
    } catch(Exception $e) {
        $pdo->rollBack();
        setFlash("Kayıt sırasında hata oluştu: " . $e->getMessage(), "danger");
    }
    header("Location: guvenlik.php?tab=ayarlar");
    exit;
}

// 2. Yeni API Anahtarı Oluşturma
if(isset($_POST['api_key_olustur'])) {
    $api_adi = trim($_POST['api_adi']);
    $api_key = bin2hex(random_bytes(16)); // 32 karakterlik rastgele güvenli token
    
    try {
        // API keyleri sistem ayarlarında JSON olarak tutabiliriz (basitlik için)
        $mevcutKeysSorgu = $pdo->query("SELECT ayar_degeri FROM sistem_ayarlari WHERE ayar_adi = 'sec_api_keys'")->fetchColumn();
        $keys = $mevcutKeysSorgu ? json_decode($mevcutKeysSorgu, true) : [];
        
        $keys[$api_key] = [
            'isim' => $api_adi,
            'olusturma' => date('Y-m-d H:i:s'),
            'aktif' => 1
        ];
        
        $json_keys = json_encode($keys);
        $stmt = $pdo->prepare("INSERT INTO sistem_ayarlari (ayar_adi, ayar_degeri) VALUES ('sec_api_keys', ?) ON DUPLICATE KEY UPDATE ayar_degeri = ?");
        $stmt->execute([$json_keys, $json_keys]);
        
        setFlash("Yeni API anahtarı oluşturuldu.", "success");
    } catch(Exception $e) {
        setFlash("API Anahtarı oluşturulamadı: " . $e->getMessage(), "danger");
    }
    header("Location: guvenlik.php?tab=api");
    exit;
}

// 3. API Anahtarı İptal Etme (Silme)
if(isset($_POST['api_key_sil'])) {
    $silinecek_key = $_POST['silinecek_key'];
    
    $mevcutKeysSorgu = $pdo->query("SELECT ayar_degeri FROM sistem_ayarlari WHERE ayar_adi = 'sec_api_keys'")->fetchColumn();
    $keys = $mevcutKeysSorgu ? json_decode($mevcutKeysSorgu, true) : [];
    
    if(isset($keys[$silinecek_key])) {
        unset($keys[$silinecek_key]);
        $json_keys = json_encode($keys);
        $pdo->prepare("UPDATE sistem_ayarlari SET ayar_degeri = ? WHERE ayar_adi = 'sec_api_keys'")->execute([$json_keys]);
        setFlash("API anahtarı iptal edildi.", "warning");
    }
    header("Location: guvenlik.php?tab=api");
    exit;
}

// --- VERİ ÇEKME ---
$ayarlarDB = [];
try {
    $query = $pdo->query("SELECT ayar_adi, ayar_degeri FROM sistem_ayarlari");
    if($query) $ayarlarDB = $query->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(Exception $e) {}

function getAyar($key, $default = '') {
    global $ayarlarDB;
    return $ayarlarDB[$key] ?? $default;
}

// Güvenlik Loglarını Çek (Giriş hataları, güvenlik güncellemeleri vb.)
$guvenlikLoglari = $pdo->query("
    SELECT l.*, y.kullanici_adi 
    FROM sistem_loglari l 
    LEFT JOIN yoneticiler y ON l.kullanici_id = y.id 
    WHERE l.islem LIKE '%Giriş%' OR l.islem LIKE '%Güvenlik%' OR l.islem LIKE '%API%'
    ORDER BY l.tarih DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// API Keyleri Decode Et
$apiKeys = json_decode(getAyar('sec_api_keys', '{}'), true);

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'ayarlar';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Sistem Güvenliği</h1>
</div>

<div class="row">
    <!-- Sol Menü (Tabs) -->
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-2">
                <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                    <a class="nav-link <?= $activeTab == 'ayarlar' ? 'active' : '' ?>" href="?tab=ayarlar">
                        <i class="fas fa-shield-alt fa-fw me-2"></i> Güvenlik Ayarları & IP
                    </a>
                    <a class="nav-link <?= $activeTab == 'api' ? 'active' : '' ?>" href="?tab=api">
                        <i class="fas fa-key fa-fw me-2"></i> API Token Yönetimi
                    </a>
                    <a class="nav-link <?= $activeTab == 'loglar' ? 'active' : '' ?>" href="?tab=loglar">
                        <i class="fas fa-user-secret fa-fw me-2"></i> Güvenlik Logları
                    </a>
                </div>
            </div>
        </div>
        
        <div class="alert alert-warning mt-3 small shadow-sm border-0">
            <i class="fas fa-exclamation-triangle me-1"></i> Bu sayfadaki ayarlar sistemin erişilebilirliğini doğrudan etkiler. Yanlış IP engellemeleri sisteme girişinizi kapatabilir.
        </div>
    </div>

    <!-- Sağ İçerik -->
    <div class="col-md-9">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-4">
                
                <?php if ($activeTab == 'ayarlar'): ?>
                <!-- 1. GÜVENLİK AYARLARI -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Güvenlik Politikaları & Firewall</h5>
                <form method="POST">
                    <input type="hidden" name="guvenlik_kaydet" value="1">
                    
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <h6 class="fw-bold small text-dark"><i class="fas fa-lock text-primary me-2"></i> Brute Force Koruması</h6>
                                <hr class="my-2">
                                <label class="form-label small">Hatalı Giriş Deneme Limiti</label>
                                <select name="sec_login_limit" class="form-select">
                                    <option value="3" <?= getAyar('sec_login_limit') == '3' ? 'selected' : '' ?>>3 Deneme (Sıkı Güvenlik)</option>
                                    <option value="5" <?= getAyar('sec_login_limit', '5') == '5' ? 'selected' : '' ?>>5 Deneme (Önerilen)</option>
                                    <option value="10" <?= getAyar('sec_login_limit') == '10' ? 'selected' : '' ?>>10 Deneme</option>
                                    <option value="0" <?= getAyar('sec_login_limit') == '0' ? 'selected' : '' ?>>Limitsiz (Önerilmez)</option>
                                </select>
                                <div class="form-text small mt-1">Belirtilen sayı kadar hatalı şifre girildiğinde hesap 15 dakika kilitlenir.</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <h6 class="fw-bold small text-dark"><i class="fas fa-clock text-warning me-2"></i> Session (Oturum) Güvenliği</h6>
                                <hr class="my-2">
                                <label class="form-label small">Boşta Bekleme Süresi (Dakika)</label>
                                <input type="number" name="sec_session_timeout" class="form-control" value="<?= e(getAyar('sec_session_timeout', '60')) ?>" min="5" max="1440">
                                <div class="form-text small mt-1">Kullanıcı işlem yapmazsa oturum otomatik sonlanır.</div>
                            </div>
                        </div>

                        <!-- YENİ: YASAKLI KELİMELER (BANWORDS) -->
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold small text-dark mb-0"><i class="fas fa-language text-info me-2"></i> Yasaklı Kelime Filtresi (Banwords)</h6>
                                </div>
                                <hr class="my-2">
                                <textarea name="sec_banwords" class="form-control font-monospace" rows="3" placeholder="amk, aq, küfür1, küfür2"><?= e(getAyar('sec_banwords', 'amk, aq, sik, piç, yavşak, orospu, oç, göt, sürtük, kahpe, fuck, shit, bitch, asshole, şerefsiz, ibne')) ?></textarea>
                                <div class="form-text small mt-1 text-info"><i class="fas fa-info-circle"></i> Kelimeleri aralarına <b>virgül (,)</b> koyarak yazın. Müşteri eklerken bu kelimeleri içeren kayıtlar sistem tarafından otomatik olarak engellenir.</div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold small text-dark mb-0"><i class="fas fa-ban text-danger me-2"></i> IP Engelleme (Kara Liste)</h6>
                                </div>
                                <hr class="my-2">
                                <textarea name="sec_blocked_ips" class="form-control font-monospace" rows="4" placeholder="192.168.1.1&#10;10.0.0.5"><?= e(getAyar('sec_blocked_ips')) ?></textarea>
                                <div class="form-text small mt-1">Sisteme erişmesini istemediğiniz IP adreslerini her satıra bir tane gelecek şekilde yazın. Kendi IP adresinizi (<b><?= $_SERVER['REMOTE_ADDR'] ?></b>) engellememeye dikkat edin!</div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch border p-3 rounded bg-light border-primary">
                                <input class="form-check-input ms-1" type="checkbox" name="sec_ssl_required" id="sslReq" value="1" <?= getAyar('sec_ssl_required', '1') == '1' ? 'checked' : '' ?> style="transform: scale(1.3);">
                                <label class="form-check-label fw-bold ms-3" for="sslReq">SSL Zorunluluğu (HTTPS Yönlendirmesi)</label>
                                <div class="small text-muted ms-3 mt-1">Tüm trafiği güvenli bağlantıya zorlar. Sunucunuzda SSL sertifikası kurulu olmalıdır.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Ayarları Kaydet</button>
                    </div>
                </form>

                <?php elseif ($activeTab == 'api'): ?>
                <!-- 2. API YÖNETİMİ -->
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-4">
                    <h5 class="fw-bold text-primary mb-0">API Token & Anahtar Yönetimi</h5>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#yeniApiModal"><i class="fas fa-plus me-1"></i> Yeni Token Üret</button>
                </div>

                <div class="alert alert-info small border-0 shadow-sm">
                    Sisteminizin dışarıya açılan servislerini (Mobil uygulama, webhook vb.) kullanmak için API anahtarları oluşturabilirsiniz. Anahtarlarınızı kimseyle paylaşmayın.
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>API Anahtarı / Token</th>
                                <th>Tanım / Uygulama Adı</th>
                                <th>Oluşturulma</th>
                                <th class="text-end">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiKeys as $key => $data): ?>
                            <tr>
                                <td class="font-monospace text-primary fw-bold" style="font-size: 0.85rem;">
                                    <?= e($key) ?>
                                    <button class="btn btn-sm btn-link text-muted p-0 ms-2" onclick="navigator.clipboard.writeText('<?= $key ?>'); alert('Kopyalandı!');"><i class="fas fa-copy"></i></button>
                                </td>
                                <td><?= e($data['isim']) ?></td>
                                <td class="text-muted small"><?= date('d.m.Y H:i', strtotime($data['olusturma'])) ?></td>
                                <td class="text-end">
                                    <form method="POST" onsubmit="return confirm('Bu API anahtarını iptal ederseniz, bağlı uygulamalar çalışmayı durdurur. Emin misiniz?');">
                                        <input type="hidden" name="api_key_sil" value="1">
                                        <input type="hidden" name="silinecek_key" value="<?= $key ?>">
                                        <button class="btn btn-sm btn-danger"><i class="fas fa-trash-alt"></i> İptal Et</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($apiKeys)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Oluşturulmuş aktif API anahtarı bulunmuyor.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Yeni API Modal -->
                <div class="modal fade" id="yeniApiModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="api_key_olustur" value="1">
                                <div class="modal-header bg-success text-white">
                                    <h5 class="modal-title">Yeni API Token Üret</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Uygulama / Tanım Adı</label>
                                        <input type="text" name="api_adi" class="form-control" placeholder="Örn: Mobil Uygulama Servisi" required>
                                        <div class="form-text mt-1">Bu anahtarın nerede kullanıldığını hatırlamanız içindir.</div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                    <button type="submit" class="btn btn-success">Anahtarı Üret</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php elseif ($activeTab == 'loglar'): ?>
                <!-- 3. GÜVENLİK LOGLARI -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Yetkisiz Erişim & Güvenlik Olayları</h5>
                
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="bg-dark text-white sticky-top">
                            <tr>
                                <th class="ps-3">Tarih</th>
                                <th>Olay (İşlem Türü)</th>
                                <th>Açıklama</th>
                                <th>Kullanıcı</th>
                                <th>IP Adresi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($guvenlikLoglari as $log): ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= date('d.m.Y H:i:s', strtotime($log['tarih'])) ?></td>
                                <td>
                                    <?php if(strpos($log['islem'], 'Hatalı') !== false): ?>
                                        <span class="badge bg-danger"><i class="fas fa-times-circle"></i> <?= e($log['islem']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> <?= e($log['islem']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($log['detay']) ?></td>
                                <td class="fw-bold"><?= e($log['kullanici_adi'] ?? 'Bilinmiyor') ?></td>
                                <td><span class="font-monospace"><?= e($log['ip_adresi'] ?? '-') ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($guvenlikLoglari)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success opacity-50"></i><br>Kayıtlı güvenlik ihlali veya log bulunmuyor. Sistem güvende.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>