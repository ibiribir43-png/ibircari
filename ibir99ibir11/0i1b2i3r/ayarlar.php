<?php
// Geliştirme aşaması için hataları göster (500 hatasının sebebini görmek için)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../templates/header.php';
global $pdo;

// PDO Bağlantısı var mı kontrolü (Config'den gelmiyorsa uyar)
if (!isset($pdo) || !$pdo) {
    die("<div class='alert alert-danger m-4'>Kritik Hata: Veritabanı bağlantısı (PDO) kurulamadı! Lütfen config.php dosyanızı kontrol edin.</div>");
}

// Sadece "super_admin" rolündekiler bu sayfaya girebilsin (Güvenlik Önlemi)
$admin_id = $_SESSION['admin_id'] ?? 0;
$stmtYetki = $pdo->prepare("SELECT rol FROM yoneticiler WHERE id = ?");
$stmtYetki->execute([$admin_id]);
$adminYetki = $stmtYetki->fetchColumn();

if($adminYetki !== 'super_admin') {
    setFlash("Bu sayfayı görüntüleme yetkiniz yok!", "danger");
    header("Location: dashboard.php");
    exit;
}

// --- İŞLEMLER (POST) ---
if(isset($_POST['ayarlar_kaydet'])) {
    
    // Checkbox değerleri POST edilmediği için varsayılan olarak "0" atıyoruz, işaretliyse "1" alacak
    $_POST['papara_test_mode'] = isset($_POST['papara_test_mode']) ? '1' : '0';
    
    // Site URL'sinin sonundaki slash'ı (/) hataları önlemek için kaldırıyoruz
    if (isset($_POST['site_url'])) {
        $_POST['site_url'] = rtrim(trim($_POST['site_url']), '/');
    }

    // Güncellenecek standart metin/ayar anahtarları (PAPARA AYARLARI EKLENDİ)
    $ayar_isimleri = [
        'site_baslik', 'site_aciklama', 'site_anahtar_kelimeler', 'footer_text',
        'smtp_host', 'smtp_port', 'smtp_email', 'smtp_sifre', 'smtp_gonderen_adi',
        'sms_api_key', 'sms_api_secret', 'sms_api_header', 'whatsapp_api_token',
        'bakim_modu', 'varsayilan_para_birimi',
        'papara_merchant_id', 'papara_api_key', 'papara_test_mode', 'site_url' // <-- YENİ EKLENENLER
    ];

    try {
        $pdo->beginTransaction();
        
        foreach($ayar_isimleri as $ayar) {
            if(isset($_POST[$ayar])) {
                $deger = trim($_POST[$ayar]);
                // Eğer ayar varsa güncelle, yoksa ekle (ON DUPLICATE KEY UPDATE)
                $stmt = $pdo->prepare("INSERT INTO sistem_ayarlari (ayar_adi, ayar_degeri) VALUES (?, ?) ON DUPLICATE KEY UPDATE ayar_degeri = ?");
                $stmt->execute([$ayar, $deger, $deger]);
            }
        }

        // --- DOSYA YÜKLEME (Logo & Favicon) ---
        $upload_dir = '../assets/img/';
        
        // Klasör yoksa oluştur
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0777, true);
        }

        // Logo Yükleme
        if(isset($_FILES['logo_dosya']) && $_FILES['logo_dosya']['error'] == 0) {
            $logo_isim = 'logo_' . time() . '.' . pathinfo($_FILES['logo_dosya']['name'], PATHINFO_EXTENSION);
            if(move_uploaded_file($_FILES['logo_dosya']['tmp_name'], $upload_dir . $logo_isim)) {
                $stmt = $pdo->prepare("INSERT INTO sistem_ayarlari (ayar_adi, ayar_degeri) VALUES ('site_logo', ?) ON DUPLICATE KEY UPDATE ayar_degeri = ?");
                $stmt->execute([$logo_isim, $logo_isim]);
            }
        }

        // Favicon Yükleme
        if(isset($_FILES['favicon_dosya']) && $_FILES['favicon_dosya']['error'] == 0) {
            $fav_isim = 'favicon_' . time() . '.' . pathinfo($_FILES['favicon_dosya']['name'], PATHINFO_EXTENSION);
            if(move_uploaded_file($_FILES['favicon_dosya']['tmp_name'], $upload_dir . $fav_isim)) {
                $stmt = $pdo->prepare("INSERT INTO sistem_ayarlari (ayar_adi, ayar_degeri) VALUES ('site_favicon', ?) ON DUPLICATE KEY UPDATE ayar_degeri = ?");
                $stmt->execute([$fav_isim, $fav_isim]);
            }
        }

        $pdo->commit();
        setFlash("Sistem ayarları başarıyla kaydedildi.", "success");
    } catch(Exception $e) {
        $pdo->rollBack();
        setFlash("Kayıt sırasında hata oluştu: " . $e->getMessage(), "danger");
    }
    
    header("Location: ayarlar.php");
    exit;
}

// --- VERİ ÇEKME ---
// Güvenli veri çekme (500 hatasını engellemek için try-catch)
$ayarlarDB = [];
try {
    $query = $pdo->query("SELECT ayar_adi, ayar_degeri FROM sistem_ayarlari");
    if($query) {
        $ayarlarDB = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch(Exception $e) {
    // Tablo henüz yoksa veya hata varsa dizi boş kalır
}

if (!is_array($ayarlarDB)) {
    $ayarlarDB = [];
}

// Eğer veritabanında henüz ayar yoksa boş dönmemesi için varsayılan değer fonksiyonu
function getAyar($key, $default = '') {
    global $ayarlarDB;
    return $ayarlarDB[$key] ?? $default;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Sistem Ayarları</h1>
</div>

<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="ayarlar_kaydet" value="1">
    
    <div class="row">
        <!-- Sol Sekmeler -->
        <div class="col-md-3 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-2">
                    <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                        <button class="nav-link active text-start mb-1" id="v-pills-genel-tab" data-bs-toggle="pill" data-bs-target="#v-pills-genel" type="button" role="tab"><i class="fas fa-cog fa-fw me-2"></i> Genel Ayarlar</button>
                        <button class="nav-link text-start mb-1" id="v-pills-gorsel-tab" data-bs-toggle="pill" data-bs-target="#v-pills-gorsel" type="button" role="tab"><i class="fas fa-paint-brush fa-fw me-2"></i> Görünüm & Logo</button>
                        <button class="nav-link text-start mb-1" id="v-pills-smtp-tab" data-bs-toggle="pill" data-bs-target="#v-pills-smtp" type="button" role="tab"><i class="fas fa-envelope fa-fw me-2"></i> SMTP E-Posta</button>
                        <button class="nav-link text-start mb-1" id="v-pills-api-tab" data-bs-toggle="pill" data-bs-target="#v-pills-api" type="button" role="tab"><i class="fas fa-plug fa-fw me-2"></i> API & Entegrasyon</button>
                    </div>
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-primary w-100 shadow-sm py-2 fw-bold"><i class="fas fa-save me-1"></i> Tüm Ayarları Kaydet</button>
            </div>
        </div>

        <!-- Sağ İçerik -->
        <div class="col-md-9">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4">
                    <div class="tab-content" id="v-pills-tabContent">
                        
                        <!-- 1. GENEL AYARLAR -->
                        <div class="tab-pane fade show active" id="v-pills-genel" role="tabpanel">
                            <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Genel Sistem Ayarları</h5>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Site / Sistem Adı</label>
                                    <input type="text" name="site_baslik" class="form-control" value="<?= e(getAyar('site_baslik', 'ibiR Core CRM')) ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Site Açıklaması (Meta Description)</label>
                                    <textarea name="site_aciklama" class="form-control" rows="2"><?= e(getAyar('site_aciklama')) ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Para Birimi</label>
                                    <select name="varsayilan_para_birimi" class="form-select">
                                        <option value="TL" <?= getAyar('varsayilan_para_birimi') == 'TL' ? 'selected' : '' ?>>Türk Lirası (₺)</option>
                                        <option value="USD" <?= getAyar('varsayilan_para_birimi') == 'USD' ? 'selected' : '' ?>>Amerikan Doları ($)</option>
                                        <option value="EUR" <?= getAyar('varsayilan_para_birimi') == 'EUR' ? 'selected' : '' ?>>Euro (€)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Sistem Durumu (Bakım Modu)</label>
                                    <select name="bakim_modu" class="form-select <?= getAyar('bakim_modu') == '1' ? 'border-danger text-danger' : '' ?>">
                                        <option value="0" <?= getAyar('bakim_modu') == '0' ? 'selected' : '' ?>>Sistem Aktif</option>
                                        <option value="1" <?= getAyar('bakim_modu') == '1' ? 'selected' : '' ?>>Bakım Modu (Müşteriler Giremez)</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Footer (Alt Bilgi) Metni</label>
                                    <input type="text" name="footer_text" class="form-control" value="<?= e(getAyar('footer_text', 'Tüm Hakları Saklıdır.')) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- 2. GÖRÜNÜM & LOGO -->
                        <div class="tab-pane fade" id="v-pills-gorsel" role="tabpanel">
                            <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Görünüm ve Marka Ayarları</h5>
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="border rounded p-3 text-center bg-light">
                                        <h6 class="fw-bold small text-muted mb-3">Sistem Logosu (Giriş/Panel)</h6>
                                        <?php if(getAyar('site_logo')): ?>
                                            <img src="../assets/img/<?= getAyar('site_logo') ?>" alt="Logo" class="img-fluid mb-3" style="max-height: 80px;">
                                        <?php else: ?>
                                            <div class="mb-3 text-muted"><i class="fas fa-image fa-3x mb-2 opacity-50"></i><br>Logo Yüklü Değil</div>
                                        <?php endif; ?>
                                        <input type="file" name="logo_dosya" class="form-control form-control-sm" accept="image/png, image/jpeg, image/svg+xml">
                                        <small class="text-muted d-block mt-1">Önerilen: Yatay, Şeffaf PNG</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded p-3 text-center bg-light">
                                        <h6 class="fw-bold small text-muted mb-3">Favicon (Sekme İkonu)</h6>
                                        <?php if(getAyar('site_favicon')): ?>
                                            <img src="../assets/img/<?= getAyar('site_favicon') ?>" alt="Favicon" class="img-fluid mb-3" style="max-height: 48px; border-radius: 8px;">
                                        <?php else: ?>
                                            <div class="mb-3 text-muted"><i class="fas fa-cube fa-3x mb-2 opacity-50"></i><br>Favicon Yüklü Değil</div>
                                        <?php endif; ?>
                                        <input type="file" name="favicon_dosya" class="form-control form-control-sm" accept="image/png, image/x-icon, image/jpeg">
                                        <small class="text-muted d-block mt-1">Önerilen: 32x32 Kare PNG/ICO</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. SMTP E-POSTA -->
                        <div class="tab-pane fade" id="v-pills-smtp" role="tabpanel">
                            <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">SMTP Mail Gönderim Ayarları</h5>
                            <div class="alert alert-info small border-0 shadow-sm">
                                <i class="fas fa-info-circle me-1"></i> Sistemin şifre sıfırlama, bildirim ve teklif e-postalarını gönderebilmesi için doğru SMTP bilgilerini girmelisiniz.
                            </div>
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small">SMTP Sunucusu (Host)</label>
                                    <input type="text" name="smtp_host" class="form-control" value="<?= e(getAyar('smtp_host')) ?>" placeholder="mail.siteadresiniz.com">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">SMTP Port</label>
                                    <input type="number" name="smtp_port" class="form-control" value="<?= e(getAyar('smtp_port', '465')) ?>" placeholder="465 veya 587">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">E-Posta Adresi (Kullanıcı Adı)</label>
                                    <input type="email" name="smtp_email" class="form-control" value="<?= e(getAyar('smtp_email')) ?>" placeholder="noreply@siteadresiniz.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">E-Posta Şifresi</label>
                                    <input type="password" name="smtp_sifre" class="form-control" value="<?= e(getAyar('smtp_sifre')) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Gönderici Adı (Görünen İsim)</label>
                                    <input type="text" name="smtp_gonderen_adi" class="form-control" value="<?= e(getAyar('smtp_gonderen_adi', 'ibiR Sistem')) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- 4. API & ENTEGRASYON -->
                        <div class="tab-pane fade" id="v-pills-api" role="tabpanel">
                            <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Üçüncü Parti API Entegrasyonları</h5>
                            <div class="row g-4">
                                <!-- SMS API -->
                                <div class="col-12">
                                    <div class="border rounded p-3 bg-light">
                                        <h6 class="fw-bold text-dark"><i class="fas fa-sms text-primary me-2"></i> SMS Entegrasyonu (NetGSM / İletişim Makinesi vb.)</h6>
                                        <hr class="my-2">
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted">API Key / Kullanıcı Adı</label>
                                                <input type="text" name="sms_api_key" class="form-control form-control-sm" value="<?= e(getAyar('sms_api_key')) ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted">API Secret / Şifre</label>
                                                <input type="password" name="sms_api_secret" class="form-control form-control-sm" value="<?= e(getAyar('sms_api_secret')) ?>">
                                            </div>
                                            <div class="col-md-12 mt-2">
                                                <label class="form-label small fw-bold text-muted">SMS Başlığı (Originator)</label>
                                                <input type="text" name="sms_api_header" class="form-control form-control-sm" value="<?= e(getAyar('sms_api_header')) ?>" placeholder="Örn: CLUBE MEDYA veya 850303XXXX">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- PAPARA SANAL POS API -->
                                <div class="col-12 mt-4">
                                    <div class="border rounded p-3 bg-light border-primary border-2 border-top">
                                        <h6 class="fw-bold text-primary"><i class="fas fa-credit-card me-2"></i> Papara Sanal POS (B2B) Ayarları</h6>
                                        <hr class="my-2">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted">Merchant ID (Üye İşyeri No) <span class="text-danger">*</span></label>
                                                <input type="text" name="papara_merchant_id" class="form-control form-control-sm" value="<?= e(getAyar('papara_merchant_id')) ?>" placeholder="Örn: 987654321">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted">Papara API Key <span class="text-danger">*</span></label>
                                                <input type="password" name="papara_api_key" class="form-control form-control-sm" value="<?= e(getAyar('papara_api_key')) ?>" placeholder="Gizli API Anahtarı">
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold text-muted">Sistem Kök URL (Callback/Dönüş için) <span class="text-danger">*</span></label>
                                                <input type="url" name="site_url" class="form-control form-control-sm" value="<?= e(getAyar('site_url', 'https://' . $_SERVER['HTTP_HOST'])) ?>" placeholder="https://www.siteadresiniz.com">
                                                <small class="text-muted d-block mt-1" style="font-size: 11px;">Papara ödeme sonrasında sitenize dönebilmesi için gereklidir (Sonunda slash / olmamalıdır).</small>
                                            </div>
                                            <div class="col-md-12">
                                                <div class="form-check form-switch mt-2 border p-2 rounded bg-white">
                                                    <input class="form-check-input ms-1" type="checkbox" name="papara_test_mode" id="paparaTestMode" <?= getAyar('papara_test_mode', '1') == '1' ? 'checked' : '' ?>>
                                                    <label class="form-check-label small fw-bold text-warning ms-2" for="paparaTestMode">
                                                        <i class="fas fa-flask"></i> Test Modu Aktif (Gerçek bakiye çekilmez)
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require_once '../templates/footer.php'; ?>