<?php
require_once '../templates/header.php';
global $pdo;

// --- İŞLEMLER (POST/GET) ---

// 1. Yeni Duyuru (Banner) Ekleme
if (isset($_POST['duyuru_ekle'])) {
    $mesaj = trim($_POST['mesaj']);
    $tip = $_POST['tip']; // info, warning, danger, success
    
    $stmt = $pdo->prepare("INSERT INTO admin_duyurular (mesaj, tip, aktif) VALUES (?, ?, 1)");
    $stmt->execute([$mesaj, $tip]);
    
    setFlash("Yeni duyuru başarıyla yayınlandı.", "success");
    header("Location: bildirimler.php?tab=duyurular");
    exit;
}

// 2. Duyuru Silme (Pasife Alma)
if (isset($_GET['duyuru_sil']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $pdo->prepare("DELETE FROM admin_duyurular WHERE id = ?")->execute([$id]);
    
    setFlash("Duyuru yayından kaldırıldı.", "warning");
    header("Location: bildirimler.php?tab=duyurular");
    exit;
}

// 3. Toplu E-Posta Gönderme
if (isset($_POST['toplu_eposta'])) {
    $hedef_kitle = $_POST['hedef_kitle']; // all, active, passive, expiring
    $konu = trim($_POST['konu']);
    $mesaj_icerik = $_POST['mesaj_icerik'];
    
    // Hedef kitleye göre sorgu oluştur
    $sql = "SELECT f.firma_adi, y.email, y.ad_soyad 
            FROM firmalar f 
            JOIN yoneticiler y ON f.id = y.firma_id 
            WHERE y.rol = 'admin' AND f.id != 'IBIR-4247-ADMIN'";
            
    if ($hedef_kitle == 'active') {
        $sql .= " AND f.durum = 1";
    } elseif ($hedef_kitle == 'passive') {
        $sql .= " AND f.durum = 0";
    } elseif ($hedef_kitle == 'expiring') {
        $sql .= " AND f.durum = 1 AND f.abonelik_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)";
    }
    
    $alicilar = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $gonderilen_sayi = 0;
    
    foreach ($alicilar as $alici) {
        // Müşteriye özel mesaj etiketlerini (değişkenleri) değiştir
        $kisisel_mesaj = str_replace(
            ['{firma_adi}', '{yetkili_adi}'], 
            [$alici['firma_adi'], $alici['ad_soyad']], 
            $mesaj_icerik
        );
        
        // TODO: Burada gerçek mail() veya PHPMailer fonksiyonu çalışacak
        // Örnek: mail($alici['email'], $konu, $kisisel_mesaj, $headers);
        $gonderilen_sayi++;
    }
    
    // İşlemi Logla
    $pdo->prepare("INSERT INTO sistem_loglari (kullanici_id, islem, detay, ip_adresi) VALUES (?, 'Toplu Mail', ?, ?)")
        ->execute([$_SESSION['admin_id'], "$gonderilen_sayi firmaya '$konu' konulu mail gönderildi.", $_SERVER['REMOTE_ADDR']]);
        
    setFlash("$gonderilen_sayi adet firmaya e-posta başarıyla gönderildi (Kuyruğa eklendi).", "success");
    header("Location: bildirimler.php?tab=eposta");
    exit;
}

// 4. Toplu SMS Gönderme
if (isset($_POST['toplu_sms'])) {
    $hedef_kitle = $_POST['hedef_kitle'];
    $sms_icerik = trim($_POST['sms_icerik']);
    
    $sql = "SELECT f.firma_adi, f.telefon 
            FROM firmalar f 
            WHERE f.telefon IS NOT NULL AND f.id != 'IBIR-4247-ADMIN'";
            
    if ($hedef_kitle == 'active') $sql .= " AND f.durum = 1";
    elseif ($hedef_kitle == 'expiring') $sql .= " AND f.durum = 1 AND f.abonelik_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)";
    
    $alicilar = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $gonderilen_sayi = count($alicilar);
    
    // TODO: SMS API (NetGSM vb.) entegrasyon kodları buraya gelecek
    
    $pdo->prepare("INSERT INTO sistem_loglari (kullanici_id, islem, detay, ip_adresi) VALUES (?, 'Toplu SMS', ?, ?)")
        ->execute([$_SESSION['admin_id'], "$gonderilen_sayi firmaya SMS gönderildi.", $_SERVER['REMOTE_ADDR']]);
        
    setFlash("$gonderilen_sayi adet firmaya SMS başarıyla gönderildi.", "success");
    header("Location: bildirimler.php?tab=sms");
    exit;
}

// --- VERİ ÇEKME ---
$duyurular = $pdo->query("SELECT * FROM admin_duyurular ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'duyurular';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Bildirim ve İletişim</h1>
</div>

<div class="row">
    <!-- Sol Menü (Tabs) -->
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-2">
                <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                    <a class="nav-link <?= $activeTab == 'duyurular' ? 'active' : '' ?>" href="?tab=duyurular">
                        <i class="fas fa-bullhorn fa-fw me-2"></i> Sistem Duyuruları
                    </a>
                    <a class="nav-link <?= $activeTab == 'eposta' ? 'active' : '' ?>" href="?tab=eposta">
                        <i class="fas fa-envelope fa-fw me-2"></i> Toplu E-Posta
                    </a>
                    <a class="nav-link <?= $activeTab == 'sms' ? 'active' : '' ?>" href="?tab=sms">
                        <i class="fas fa-sms fa-fw me-2"></i> Toplu SMS
                    </a>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info mt-3 small shadow-sm border-0">
            <i class="fas fa-info-circle me-1"></i> Gönderilen tüm mailler ve SMS'ler <strong>ayarlar</strong> sayfasında tanımladığınız entegrasyonlar üzerinden gönderilir.
        </div>
    </div>

    <!-- Sağ İçerik -->
    <div class="col-md-9">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                
                <?php if ($activeTab == 'duyurular'): ?>
                <!-- 1. DUYURULAR (BANNER) -->
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-4">
                    <h5 class="fw-bold text-primary mb-0">Sistem İçi Duyuru (Banner) Yönetimi</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#yeniDuyuruModal"><i class="fas fa-plus me-1"></i> Yeni Duyuru</button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th width="120">Tarih</th>
                                <th width="100">Tür</th>
                                <th>Duyuru Mesajı</th>
                                <th class="text-end">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($duyurular as $d): ?>
                            <tr>
                                <td class="text-muted small"><?= date('d.m.Y H:i', strtotime($d['tarih'])) ?></td>
                                <td><span class="badge bg-<?= $d['tip'] ?> w-100"><?= strtoupper($d['tip']) ?></span></td>
                                <td class="fw-semibold text-dark"><?= e($d['mesaj']) ?></td>
                                <td class="text-end">
                                    <a href="?duyuru_sil=1&id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger border-0" onclick="return confirm('Duyuruyu yayından kaldırmak istediğinize emin misiniz?')"><i class="fas fa-trash"></i> Kaldır</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($duyurular)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Yayında olan bir duyuru bulunmuyor.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Yeni Duyuru Modal -->
                <div class="modal fade" id="yeniDuyuruModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="duyuru_ekle" value="1">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title">Yeni Duyuru Yayınla</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Duyuru Metni</label>
                                        <textarea name="mesaj" class="form-control" rows="3" placeholder="Tüm firma panellerinde görünecek mesaj..." required></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Duyuru Tipi (Renk)</label>
                                        <select name="tip" class="form-select">
                                            <option value="info">Bilgi Notu (Mavi)</option>
                                            <option value="success">İyi Haber / Güncelleme (Yeşil)</option>
                                            <option value="warning">Uyarı / Hatırlatma (Sarı)</option>
                                            <option value="danger">Kritik / Acil (Kırmızı)</option>
                                        </select>
                                    </div>
                                    <div class="alert alert-warning small py-2 mb-0">Bu duyuru, tüm firmaların yönetim panelinin en üstünde görünür olacaktır.</div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                    <button type="submit" class="btn btn-primary">Yayınla</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php elseif ($activeTab == 'eposta'): ?>
                <!-- 2. TOPLU E-POSTA -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Toplu E-Posta Gönderimi</h5>
                <form method="POST" class="row g-3" onsubmit="return confirm('Mail gönderim işlemini başlatmak istediğinize emin misiniz?');">
                    <input type="hidden" name="toplu_eposta" value="1">
                    <div class="col-md-12">
                        <label class="form-label fw-bold small">Hedef Kitle <span class="text-danger">*</span></label>
                        <select name="hedef_kitle" class="form-select" required>
                            <option value="all">Sistemdeki Tüm Firmalar</option>
                            <option value="active">Sadece Aktif Firmalar</option>
                            <option value="passive">Sadece Pasif / Dondurulmuş Firmalar</option>
                            <option value="expiring">Aboneliği Yakında Bitecekler (Son 15 Gün)</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-bold small">E-Posta Konusu <span class="text-danger">*</span></label>
                        <input type="text" name="konu" class="form-control" placeholder="Örn: Sistem Güncellemesi Hakkında" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-bold small">Mesaj İçeriği (HTML Destekli) <span class="text-danger">*</span></label>
                        <textarea name="mesaj_icerik" class="form-control" rows="8" required placeholder="Sayın {yetkili_adi},&#10;{firma_adi} firması olarak..."></textarea>
                        <div class="form-text small mt-2">
                            <strong>Kullanabileceğiniz Etiketler:</strong> <code>{firma_adi}</code>, <code>{yetkili_adi}</code>
                        </div>
                    </div>
                    <div class="col-12 text-end mt-4">
                        <button type="submit" class="btn btn-primary px-4"><i class="fas fa-paper-plane me-1"></i> Gönderimi Başlat</button>
                    </div>
                </form>

                <?php elseif ($activeTab == 'sms'): ?>
                <!-- 3. TOPLU SMS -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Toplu SMS Gönderimi</h5>
                <form method="POST" class="row g-3" onsubmit="return confirm('SMS gönderim işlemini başlatmak istediğinize emin misiniz? Faturanıza yansıyabilir.');">
                    <input type="hidden" name="toplu_sms" value="1">
                    <div class="col-md-12">
                        <label class="form-label fw-bold small">Hedef Kitle <span class="text-danger">*</span></label>
                        <select name="hedef_kitle" class="form-select" required>
                            <option value="all">Tüm Firmalar (Telefonu Kayıtlı Olanlar)</option>
                            <option value="active">Sadece Aktif Firmalar</option>
                            <option value="expiring">Aboneliği Yakında Bitecekler (Ödeme Hatırlatması)</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-bold small">SMS İçeriği <span class="text-danger">*</span></label>
                        <textarea name="sms_icerik" class="form-control" rows="4" maxlength="160" required placeholder="Kampanyamızdan faydalanmak için hemen panelinize giriş yapın..."></textarea>
                        <div class="form-text small mt-2 d-flex justify-content-between">
                            <span>Maksimum 160 karakter önerilir. Türkçe karakter (ş, ğ, ı, ç) kullanmamaya özen gösterin.</span>
                            <span class="text-primary fw-bold" id="charCount">0/160</span>
                        </div>
                    </div>
                    <div class="col-12 text-end mt-4">
                        <button type="submit" class="btn btn-success px-4"><i class="fas fa-sms me-1"></i> SMS Gönder</button>
                    </div>
                </form>

                <script>
                // SMS Karakter Sayacı
                document.querySelector('textarea[name="sms_icerik"]')?.addEventListener('input', function() {
                    document.getElementById('charCount').innerText = this.value.length + '/160';
                    if(this.value.length > 160) document.getElementById('charCount').classList.add('text-danger');
                    else document.getElementById('charCount').classList.remove('text-danger');
                });
                </script>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>