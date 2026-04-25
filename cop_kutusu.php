<?php
session_start();
require 'baglanti.php';

// functions.php'yi dahil ediyoruz (sistemLog fonksiyonu için)
require_once __DIR__ . '/ibir99ibir11/includes/functions.php';

// 1. GÜVENLİK KONTROLÜ
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['kullanici_id'];
$firma_id = $_SESSION['firma_id'];

// Roller 'yoneticiler' tablosundan geliyor (super_admin, admin, personel, ajanda)
$stmtRol = $db->prepare("SELECT rol FROM yoneticiler WHERE id = ?");
$stmtRol->execute([$user_id]);
$my_role = $stmtRol->fetchColumn();

// Sadece Admin ve Super Adminler kalıcı silme yapabilsin
if (!in_array($my_role, ['admin', 'super_admin'])) {
    die("<div style='padding:50px; text-align:center; font-family:sans-serif;'><h3>Yetkisiz Erişim!</h3><p>Bu sayfayı sadece firma yöneticileri görüntüleyebilir.</p><a href='index.php'>Ana Sayfaya Dön</a></div>");
}

$mesaj = "";
$mesajTuru = "";

// --- ŞİFRE DOĞRULAMA FONKSİYONU ---
function adminSifreDogrula($db, $uid, $pass) {
    $sorgu = $db->prepare("SELECT sifre FROM yoneticiler WHERE id = ?");
    $sorgu->execute([$uid]);
    $kayitli = $sorgu->fetchColumn();
    // Bcrypt/Argon2 id ve eski MD5 uyumluluğu
    return (password_verify($pass, $kayitli) || md5($pass) === $kayitli);
}

// ==========================================
// 1. GERİ YÜKLEME İŞLEMİ
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['geri_yukle_id'])) {
    $m_id = (int)$_POST['geri_yukle_id'];
    
    $kontrol = $db->prepare("SELECT ad_soyad FROM musteriler WHERE id = ? AND firma_id = ? AND silindi = 1");
    $kontrol->execute([$m_id, $firma_id]);
    $musteri = $kontrol->fetch(PDO::FETCH_ASSOC);

    if ($musteri) {
        $db->prepare("UPDATE musteriler SET silindi = 0 WHERE id = ?")->execute([$m_id]);
        $mesaj = "<b>" . htmlspecialchars($musteri['ad_soyad']) . "</b> adlı müşteri ve tüm verileri başarıyla geri yüklendi.";
        $mesajTuru = "success";
        
        // SİSTEME LOGLA
        sistemLog($db, 'Müşteri Yönetimi', 'Müşteri Geri Yüklendi', "{$musteri['ad_soyad']} adlı müşteri (ID: $m_id) çöp kutusundan çıkarıldı.");
    }
}

// ==========================================
// 2. KALICI SİLME İŞLEMİ (ŞİFRELİ SUDO MODE)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kalici_sil_id'])) {
    $m_id = (int)$_POST['kalici_sil_id'];
    $girilen_sifre = $_POST['admin_sifresi'];
    
    // Şifre Doğru Mu?
    if (adminSifreDogrula($db, $user_id, $girilen_sifre)) {
        
        $kontrol = $db->prepare("SELECT ad_soyad FROM musteriler WHERE id = ? AND firma_id = ? AND silindi = 1");
        $kontrol->execute([$m_id, $firma_id]);
        $musteri = $kontrol->fetch(PDO::FETCH_ASSOC);

        if ($musteri) {
            try {
                $db->beginTransaction();
                
                // 1. Fiziksel Dosyaları Sunucudan Sil (Disk alanını boşaltmak için)
                $dosyalar_sorgu = $db->prepare("SELECT dosya_yolu FROM musteri_dosyalar WHERE musteri_id = ?");
                $dosyalar_sorgu->execute([$m_id]);
                $silinen_dosya_sayisi = 0;
                
                foreach ($dosyalar_sorgu->fetchAll(PDO::FETCH_ASSOC) as $dosya) {
                    $tam_yol = __DIR__ . '/' . $dosya['dosya_yolu'];
                    if (file_exists($tam_yol) && is_file($tam_yol)) {
                        @unlink($tam_yol); // Dosyayı diskten yok et
                        $silinen_dosya_sayisi++;
                    }
                }

                // 2. Bağlı Tablolardaki Tüm Verileri Temizle
                // Not: 'hareketler' tablosunda ON DELETE CASCADE olduğu için otomatik silinir. Biz garantilemek için diğerlerini siliyoruz.
                $db->prepare("DELETE FROM takvim_etkinlikleri WHERE musteri_id = ?")->execute([$m_id]);
                $db->prepare("DELETE FROM sozlesmeler WHERE musteri_id = ?")->execute([$m_id]);
                $db->prepare("DELETE FROM musteriportal WHERE musteri_id = ?")->execute([$m_id]);
                $db->prepare("DELETE FROM musteri_aktivite WHERE musteri_id = ?")->execute([$m_id]);
                $db->prepare("DELETE FROM musteri_loglari WHERE musteri_id = ?")->execute([$m_id]);
                $db->prepare("DELETE FROM musteri_oturumlari WHERE musteri_id = ?")->execute([$m_id]);
                $db->prepare("DELETE FROM revizeler WHERE musteri_id = ?")->execute([$m_id]);
                
                // 3. Müşteriyi Tamamen Yok Et
                $db->prepare("DELETE FROM musteriler WHERE id = ? AND firma_id = ?")->execute([$m_id, $firma_id]);
                
                $db->commit();
                $mesaj = "<b>" . htmlspecialchars($musteri['ad_soyad']) . "</b> adlı müşteri ve ona ait ($silinen_dosya_sayisi adet) dosya dahil her şey <b>kalıcı olarak</b> sistemden silindi.";
                $mesajTuru = "success";
                
                // KRİTİK İŞLEMİ SİSTEME LOGLA
                sistemLog($db, 'Güvenlik/Kritik', 'Müşteri KALICI Silindi', "{$musteri['ad_soyad']} adlı müşteri, tüm geçmiş işlemleri ve $silinen_dosya_sayisi adet dosyasıyla birlikte yönetici şifresi doğrulanarak fiziksel olarak silindi.");

            } catch (Exception $e) {
                $db->rollBack();
                $mesaj = "Silme işlemi sırasında veritabanı hatası oluştu: " . $e->getMessage();
                $mesajTuru = "danger";
                sistemLog($db, 'Hata', 'Kalıcı Silme Başarısız', "Müşteri (ID: $m_id) silinirken SQL hatası: " . $e->getMessage());
            }
        }
    } else {
        $mesaj = "Hatalı yönetici şifresi! Güvenlik nedeniyle kalıcı silme işlemi iptal edildi.";
        $mesajTuru = "danger";
        sistemLog($db, 'Güvenlik İhlali', 'Hatalı Şifre Denemesi', "Kalıcı silme işlemi için hatalı yönetici şifresi girildi. (Hedef Müşteri ID: $m_id)");
    }
}

// --- LİSTEYİ ÇEK (Sadece silindi = 1 olanlar) ---
$silinenler = $db->query("
    SELECT m.id, m.ad_soyad, m.telefon, m.silindi,
           (SELECT COUNT(*) FROM hareketler h WHERE h.musteri_id = m.id) as islem_sayisi,
           (SELECT COUNT(*) FROM musteri_dosyalar d WHERE d.musteri_id = m.id) as dosya_sayisi
    FROM musteriler m 
    WHERE m.firma_id = '$firma_id' AND m.silindi = 1
    ORDER BY m.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Çöp Kutusu | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/yonetim.css">
    <style>
        .trash-header { background: #f8d7da; border-bottom: 2px solid #f5c2c7; color: #842029; }
        .sudo-modal { border-top: 5px solid #dc3545; }
    </style>
</head>
<body class="yonetim-body bg-light">

    <?php include 'partials/navbar.php'; ?>

    <div class="container pb-5 mt-4">
        
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-body trash-header p-4 d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <div class="mb-3 mb-md-0">
                            <h4 class="fw-bold mb-1"><i class="fas fa-trash-alt me-2"></i> Çöp Kutusu (Silinen Müşteriler)</h4>
                            <p class="mb-0 small">Buradaki müşteriler finansal raporlardan ve takvimden izole edilmiştir. Kalıcı olarak sildiğinizde veriler geri döndürülemez.</p>
                        </div>
                        <a href="musteriler.php" class="btn btn-outline-danger bg-white fw-bold"><i class="fas fa-users me-1"></i> Müşterilere Dön</a>
                    </div>
                </div>
            </div>
        </div>

        <?php if($mesaj): ?>
            <div class="alert alert-<?= $mesajTuru ?> alert-dismissible fade show shadow-sm border-0">
                <?= $mesaj ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Müşteri / Cari Adı</th>
                                <th>Telefon</th>
                                <th>Bağlı Veriler</th>
                                <th class="text-end pe-4">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($silinenler)): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success opacity-50"></i><br>Çöp kutusu tamamen boş.</td></tr>
                            <?php endif; ?>

                            <?php foreach($silinenler as $m): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-danger"><del><?= htmlspecialchars($m['ad_soyad']) ?></del></td>
                                <td><?= htmlspecialchars($m['telefon'] ?? '-') ?></td>
                                <td>
                                    <?php if($m['islem_sayisi'] > 0 || $m['dosya_sayisi'] > 0): ?>
                                        <?php if($m['islem_sayisi'] > 0): ?>
                                            <span class="badge bg-warning text-dark me-1"><i class="fas fa-receipt"></i> <?= $m['islem_sayisi'] ?> İşlem</span>
                                        <?php endif; ?>
                                        <?php if($m['dosya_sayisi'] > 0): ?>
                                            <span class="badge bg-info text-dark"><i class="fas fa-folder"></i> <?= $m['dosya_sayisi'] ?> Dosya</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Veri Yok</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Müşteriyi ve gizlenmiş tüm verilerini geri yüklemek istiyor musunuz?');">
                                        <input type="hidden" name="geri_yukle_id" value="<?= $m['id'] ?>">
                                        <button class="btn btn-sm btn-success fw-bold"><i class="fas fa-undo me-1"></i> Geri Yükle</button>
                                    </form>
                                    
                                    <button class="btn btn-sm btn-danger fw-bold ms-1" data-bs-toggle="modal" data-bs-target="#sudoModal<?= $m['id'] ?>">
                                        <i class="fas fa-radiation me-1"></i> Kalıcı Sil
                                    </button>
                                </td>
                            </tr>

                            <!-- SUDO MODE MODAL (Şifreli Kalıcı Silme) -->
                            <div class="modal fade" id="sudoModal<?= $m['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content sudo-modal shadow-lg">
                                        <form method="POST">
                                            <input type="hidden" name="kalici_sil_id" value="<?= $m['id'] ?>">
                                            <div class="modal-header bg-white border-0 pb-0">
                                                <h5 class="modal-title fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Kritik İşlem Uyarısı</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body text-start">
                                                <div class="alert alert-danger bg-opacity-10 border-0 mb-3">
                                                    <b><?= htmlspecialchars($m['ad_soyad']) ?></b> adlı müşteriyi kalıcı olarak silmek üzeresiniz. Bu işlem <b>geri alınamaz!</b>
                                                </div>
                                                
                                                <p class="small text-muted mb-2">Bu müşteriye ait aşağıdaki veriler fiziksel olarak yok edilecektir:</p>
                                                <ul class="small mb-4 text-dark fw-bold">
                                                    <li><i class="fas fa-file-invoice-dollar me-2 text-warning"></i> <?= $m['islem_sayisi'] ?> Adet Finansal Kayıt / Fatura</li>
                                                    <li><i class="fas fa-calendar-alt me-2 text-info"></i> Tüm Sözleşme ve Takvim Etkinlikleri</li>
                                                    <li><i class="fas fa-folder-open me-2 text-primary"></i> <?= $m['dosya_sayisi'] ?> Adet Fotoğraf ve Video (Sunucudan silinir)</li>
                                                </ul>

                                                <label class="form-label fw-bold text-dark small">İşlemi onaylamak için Yönetici Şifrenizi girin:</label>
                                                <div class="input-group">
                                                    <span class="input-group-text bg-white border-danger"><i class="fas fa-key text-danger"></i></span>
                                                    <input type="password" name="admin_sifresi" class="form-control border-danger" required placeholder="Şifreniz" autocomplete="off">
                                                </div>
                                            </div>
                                            <div class="modal-footer border-top-0 bg-light">
                                                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">İptal</button>
                                                <button type="submit" class="btn btn-danger px-4 fw-bold"><i class="fas fa-skull-crossbones me-1"></i> Tümünü Yok Et</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <?php include 'partials/footer_yonetim.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>