<?php
require_once '../templates/header.php';
global $pdo;

// --- İŞLEMLER (POST/GET) ---

// 1. Yeni Firma Ekleme (Madde 11)
if(isset($_POST['yeni_firma_ekle'])) {
    $firma_id = 'IBIR-' . rand(1000, 9999) . '-' . strtoupper(substr(md5(time()), 0, 4));
    $firma_adi = trim($_POST['firma_adi']);
    $yetkili = trim($_POST['yetkili']);
    $telefon = trim($_POST['telefon']);
    $email = trim($_POST['email']);
    $sifre = md5(trim($_POST['sifre']));

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO firmalar (id, firma_adi, yetkili_ad_soyad, telefon, durum, kayit_tarihi) VALUES (?, ?, ?, ?, 1, NOW())");
        $stmt->execute([$firma_id, $firma_adi, $yetkili, $telefon]);

        $stmt2 = $pdo->prepare("INSERT INTO yoneticiler (firma_id, kullanici_adi, email, sifre, rol, email_onayli) VALUES (?, ?, ?, ?, 'admin', 1)");
        $stmt2->execute([$firma_id, $yetkili, $email, $sifre]);

        $pdo->commit();
        setFlash("Yeni firma başarıyla eklendi.", "success");
    } catch(Exception $e) {
        $pdo->rollBack();
        setFlash("Hata: " . $e->getMessage(), "danger");
    }
    header("Location: firmalar.php");
    exit;
}

// 2. Firma Durum Değiştir (Aktif/Pasif)
if(isset($_GET['durum_degistir']) && isset($_GET['id'])) {
    $id = trim($_GET['id']);
    $yeniDurum = (int)$_GET['durum_degistir'];
    $stmt = $pdo->prepare("UPDATE firmalar SET durum = ? WHERE id = ?");
    $stmt->execute([$yeniDurum, $id]);
    setFlash("Firma durumu başarıyla güncellendi.", "success");
    header("Location: firmalar.php");
    exit;
}

// 3. Kalıcı Silme İçin 2FA Kodu Gönderme (E-Posta Yoluyla Varsayılan)
if(isset($_POST['kod_gonder'])) {
    $hedef_id = trim($_POST['silinecek_id']);
    $kod = rand(100000, 999999);
    $_SESSION['2fa_code'] = $kod;
    $_SESSION['2fa_target'] = $hedef_id;
    $_SESSION['2fa_expire'] = time() + 300; // 5 dk geçerlilik
    
    $adminMail = $pdo->query("SELECT email FROM yoneticiler WHERE id = '{$_SESSION['admin_id']}'")->fetchColumn();
    
    $mesaj = "Firma kalıcı silme işlemi için güvenlik kodunuz: <h2 style='letter-spacing: 5px;'>$kod</h2>";
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    @mail($adminMail, "Firma Silme Onayı - ibiR Core", $mesaj, $headers);
    
    setFlash("Doğrulama kodu <b>$adminMail</b> adresine gönderildi. <i>(Test Kodu: $kod)</i>", "info");
    $open2FAModal = true;
}

// 3.5. Kalıcı Silme İçin 2FA Kodu Gönderme (SMS Yoluyla)
if(isset($_POST['sms_gonder'])) {
    $hedef_id = trim($_POST['silinecek_id']);
    
    // Eğer önceden bir kod üretildiyse (aynı oturumdaysa) onu kullan, yoksa yeni üret
    $kod = isset($_SESSION['2fa_code']) ? $_SESSION['2fa_code'] : rand(100000, 999999);
    
    $_SESSION['2fa_code'] = $kod;
    $_SESSION['2fa_target'] = $hedef_id;
    $_SESSION['2fa_expire'] = time() + 300;
    
    $hedef_telefon = "05539506696"; // Belirttiğin SMS Numarası
    $mesaj = "ibiR Core Sistem - Firma kalici silme islemi icin onay kodunuz: " . $kod;
    
    // NetGSM ile SMS Gönder (functions.php'deki fonksiyonu çağırıyoruz)
    $sms_sonuc = netgsm_sms_gonder($hedef_telefon, $mesaj);
    
    if($sms_sonuc['status'] === true) {
        setFlash("Doğrulama kodu <b>$hedef_telefon</b> numarasına SMS olarak başarıyla iletildi.", "success");
    } else {
        // Eğer API ayarları bozuksa veya limit bittiyse ekranda hatayı gösterip test kodunu basıyoruz (geliştirme kolaylığı için)
        setFlash("SMS Gönderilemedi: " . $sms_sonuc['message'] . " <br><i class='text-dark'>Geçici Test Kodu: $kod</i>", "danger");
    }
    
    $open2FAModal = true;
}

// 4. 2FA Onayı ve Yedekli Kalıcı Silme
if(isset($_POST['kalici_sil_onayla'])) {
    if(isset($_SESSION['2fa_code']) && $_SESSION['2fa_code'] == $_POST['dogrulama_kodu'] && time() < $_SESSION['2fa_expire']) {
        $fid = $_SESSION['2fa_target'];
        
        // --- ADIM 1: SİLMEDEN ÖNCE OTOMATİK VERİTABANI YEDEĞİ AL ---
        $backup_dir = __DIR__ . '/../backups/';
        if (!is_dir($backup_dir)) { @mkdir($backup_dir, 0755, true); }
        
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) { $tables[] = $row[0]; }
        
        $sqlScript = "-- FİRMA SİLME İŞLEMİ ÖNCESİ OTOMATİK GÜVENLİK YEDEĞİ\n-- Tarih: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n";
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rowCount = $stmt->rowCount();
            if ($rowCount > 0) {
                $sqlScript .= "INSERT INTO `$table` VALUES\n";
                $counter = 0;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $counter++;
                    $vals = [];
                    foreach ($row as $val) {
                        $vals[] = is_null($val) ? "NULL" : $pdo->quote($val);
                    }
                    $sqlScript .= "(" . implode(", ", $vals) . ")" . ($counter < $rowCount ? ",\n" : ";\n\n");
                }
            }
        }
        $sqlScript .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $backup_file = 'silme_oncesi_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($backup_dir . $backup_file, $sqlScript);

        // --- ADIM 2: FİRMA VE BAĞLI VERİLERİNİ SİL ---
        $tablesToDelete = ['sistem_loglari', 'yoneticiler', 'firma_odemeler', 'destek_talepleri', 'urun_hizmetler', 'teklifler', 'tedarikciler', 'takvim_etkinlikleri', 'sozlesmeler', 'musteriler', 'firmalar'];
        
        foreach($tablesToDelete as $tbl) {
            try {
                $col = ($tbl == 'firmalar') ? 'id' : 'firma_id';
                $pdo->prepare("DELETE FROM $tbl WHERE $col=?")->execute([$fid]);
            } catch(Exception $e) {}
        }
        
        unset($_SESSION['2fa_code']);
        
        // --- ADIM 3: BAŞARI MESAJI VE İNDİRME LİNKİ ---
        setFlash("Firma ve tüm verileri kalıcı olarak silindi.<br><br><b>Güvenlik Yedeği Oluşturuldu:</b> <a href='sistem.php?indir=1&dosya=$backup_file' class='btn btn-sm btn-light text-dark fw-bold shadow-sm'><i class='fas fa-download'></i> Yedeği İndir</a>", "success");
        
    } else {
        setFlash("Hatalı veya süresi dolmuş doğrulama kodu!", "danger");
    }
    header("Location: firmalar.php");
    exit;
}

// --- LİSTELEME ---
$sql = "
    SELECT f.*, 
    (SELECT COUNT(*) FROM yoneticiler WHERE firma_id = f.id) as user_count,
    (SELECT email FROM yoneticiler WHERE firma_id = f.id AND rol = 'admin' LIMIT 1) as admin_email
    FROM firmalar f 
    WHERE f.id != 'IBIR-4247-ADMIN' 
    ORDER BY f.kayit_tarihi DESC
";
$firmalar = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Firma Yönetimi</h1>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#yeniFirmaModal">
        <i class="fas fa-plus fa-sm text-white-50"></i> Yeni Firma Ekle
    </button>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Tüm Firmalar</h6>
        <div class="input-group" style="width: 300px;">
            <input type="text" class="form-control form-control-sm" placeholder="Firma Ara..." id="tableSearch">
            <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-search"></i></button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="firmalarTable">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">ID / Firma Adı</th>
                        <th>Yetkili İletişim</th>
                        <th>Kullanıcı</th>
                        <th>Abonelik Bitiş</th>
                        <th>Durum</th>
                        <th class="text-end pe-4">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($firmalar as $f): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark"><?= e($f['firma_adi']) ?></div>
                            <div class="small text-muted">ID: <?= e($f['id']) ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= e($f['yetkili_ad_soyad']) ?></div>
                            <div class="small text-muted"><i class="fas fa-envelope"></i> <?= e($f['admin_email']) ?></div>
                            <div class="small text-muted"><i class="fas fa-phone"></i> <?= e($f['telefon']) ?></div>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= $f['user_count'] ?> Kullanıcı</span>
                        </td>
                        <td>
                            <?php 
                                $bitis = $f['abonelik_bitis'] ? date('d.m.Y', strtotime($f['abonelik_bitis'])) : '-';
                                $renk = (strtotime($f['abonelik_bitis']) < time()) ? 'text-danger' : 'text-success';
                            ?>
                            <div class="fw-bold <?= $renk ?>"><?= $bitis ?></div>
                        </td>
                        <td>
                            <?php if($f['durum'] == 1): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">Pasif</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    Aksiyon
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow">
                                    <li><h6 class="dropdown-header">Firma İşlemleri</h6></li>
                                    <li><a class="dropdown-item" href="firma_detay.php?id=<?= $f['id'] ?>"><i class="fas fa-edit fa-fw text-primary me-2"></i> Profili Yönet / Düzenle</a></li>
                                    <li><a class="dropdown-item" href="firma_detay.php?id=<?= $f['id'] ?>&tab=ayarlar"><i class="fas fa-box fa-fw text-info me-2"></i> Paket Değiştir</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="alert('Login As özelliği altyapı olarak daha sonra eklenecektir.');"><i class="fas fa-sign-in-alt fa-fw text-secondary me-2"></i> Panele Giriş Yap (Login As)</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php if($f['durum'] == 1): ?>
                                        <li><a class="dropdown-item text-warning" href="?durum_degistir=0&id=<?= $f['id'] ?>"><i class="fas fa-pause-circle fa-fw me-2"></i> Pasife Al</a></li>
                                    <?php else: ?>
                                        <li><a class="dropdown-item text-success" href="?durum_degistir=1&id=<?= $f['id'] ?>"><i class="fas fa-play-circle fa-fw me-2"></i> Aktife Al</a></li>
                                    <?php endif; ?>
                                    <li>
                                        <form method="POST" onsubmit="return confirm('Silme onayı için e-postanıza güvenlik kodu gönderilecektir. Devam edilsin mi?');">
                                            <input type="hidden" name="kod_gonder" value="1">
                                            <input type="hidden" name="silinecek_id" value="<?= $f['id'] ?>">
                                            <button type="submit" class="dropdown-item text-danger"><i class="fas fa-trash fa-fw me-2"></i> Kalıcı Olarak Sil</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 2FA SİLME ONAY MODALI (E-Posta & SMS Seçenekli) -->
<div class="modal fade" id="modal2FA" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Güvenlik Onayı</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                <p class="mb-3">Firma silme işlemi kritik bir eylemdir. Lütfen size iletilen 6 haneli doğrulama kodunu giriniz.</p>
                
                <!-- Onay Kodu Formu -->
                <form method="POST" id="verifyForm">
                    <input type="hidden" name="kalici_sil_onayla" value="1">
                    <input type="text" name="dogrulama_kodu" class="form-control text-center fs-2 fw-bold mx-auto mb-3" style="max-width: 200px; letter-spacing: 5px;" placeholder="000000" required autofocus autocomplete="off">
                </form>

                <!-- SMS ile Gönder Formu -->
                <form method="POST" id="smsForm">
                    <input type="hidden" name="sms_gonder" value="1">
                    <input type="hidden" name="silinecek_id" value="<?= $_SESSION['2fa_target'] ?? '' ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sms text-primary me-1"></i> Kodu SMS ile Gönder (05539506696)</button>
                </form>

                <p class="small text-muted mt-3 mb-0">Silme işleminden hemen önce otomatik veritabanı yedeği oluşturulacaktır.</p>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal Et</button>
                <!-- Onay formu için submit butonu -->
                <button type="submit" form="verifyForm" class="btn btn-danger">Doğrula ve Kalıcı Olarak Sil</button>
            </div>
        </div>
    </div>
</div>

<!-- YENİ FİRMA EKLE MODAL -->
<div class="modal fade" id="yeniFirmaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="yeni_firma_ekle" value="1">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Yeni Firma Ekle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Firma Adı</label>
                            <input type="text" name="firma_adi" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Yetkili Ad Soyad</label>
                            <input type="text" name="yetkili" class="form-control" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">İletişim (Telefon)</label>
                            <input type="text" name="telefon" class="form-control" required>
                        </div>
                        <div class="col-12"><hr></div>
                        <h6 class="fw-bold text-muted mb-0">Firma Yönetici (Admin) Bilgileri</h6>
                        <div class="col-md-6">
                            <label class="form-label">E-Posta Adresi</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Giriş Şifresi</label>
                            <input type="password" name="sifre" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Firmayı Oluştur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Arama Filtresi
document.getElementById('tableSearch')?.addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let rows = document.querySelectorAll('#firmalarTable tbody tr');
    rows.forEach(row => {
        let text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
});

// Eğer 2FA kodu gönderildiyse Modalı otomatik aç
<?php if(isset($open2FAModal) && $open2FAModal): ?>
document.addEventListener('DOMContentLoaded', function() {
    var myModal = new bootstrap.Modal(document.getElementById('modal2FA'));
    myModal.show();
});
<?php endif; ?>
</script>

<?php require_once '../templates/footer.php'; ?>