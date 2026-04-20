<?php
require_once '../templates/header.php';
global $pdo;

// --- İŞLEMLER (POST/GET) ---

// 1. Yeni Sistem Yöneticisi (Admin) Ekleme
if(isset($_POST['admin_ekle'])) {
    $kullanici_adi = trim($_POST['kullanici_adi']);
    $ad_soyad = trim($_POST['ad_soyad']);
    $email = trim($_POST['email']);
    $telefon = trim($_POST['telefon']);
    // Not: login.php dosyan password_verify kullandığı için password_hash kullanıyoruz.
    $sifre = password_hash(trim($_POST['sifre']), PASSWORD_DEFAULT); 
    $rol = $_POST['rol']; // super_admin veya admin

    // Email kontrolü (Aynı email var mı?)
    $check = $pdo->prepare("SELECT id FROM yoneticiler WHERE email = ?");
    $check->execute([$email]);
    
    if($check->rowCount() > 0) {
        setFlash("Bu e-posta adresi sistemde zaten kayıtlı!", "danger");
    } else {
        $stmt = $pdo->prepare("INSERT INTO yoneticiler (firma_id, kullanici_adi, ad_soyad, email, sifre, telefon, rol, email_onayli) VALUES ('IBIR-4247-ADMIN', ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$kullanici_adi, $ad_soyad, $email, $sifre, $telefon, $rol]);
        setFlash("Yeni sistem yöneticisi başarıyla eklendi.", "success");
    }
    header("Location: kullanicilar.php");
    exit;
}

// 2. Admin Bilgilerini Güncelleme
if(isset($_POST['admin_duzenle'])) {
    $admin_id = (int)$_POST['admin_id'];
    $kullanici_adi = trim($_POST['kullanici_adi']);
    $ad_soyad = trim($_POST['ad_soyad']);
    $email = trim($_POST['email']);
    $telefon = trim($_POST['telefon']);
    $rol = $_POST['rol'];

    // E-posta benzersizliği kontrolü (Kendisi hariç)
    $check = $pdo->prepare("SELECT id FROM yoneticiler WHERE email = ? AND id != ?");
    $check->execute([$email, $admin_id]);
    
    if($check->rowCount() > 0) {
        setFlash("Bu e-posta adresi başka bir kullanıcı tarafından kullanılıyor!", "danger");
    } else {
        if(!empty($_POST['sifre'])) {
            // Şifre de değişecekse
            $sifre = password_hash(trim($_POST['sifre']), PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE yoneticiler SET kullanici_adi=?, ad_soyad=?, email=?, telefon=?, rol=?, sifre=? WHERE id=?");
            $stmt->execute([$kullanici_adi, $ad_soyad, $email, $telefon, $rol, $sifre, $admin_id]);
        } else {
            // Şifre değişmeyecekse
            $stmt = $pdo->prepare("UPDATE yoneticiler SET kullanici_adi=?, ad_soyad=?, email=?, telefon=?, rol=? WHERE id=?");
            $stmt->execute([$kullanici_adi, $ad_soyad, $email, $telefon, $rol, $admin_id]);
        }
        setFlash("Yönetici bilgileri güncellendi.", "success");
    }
    header("Location: kullanicilar.php");
    exit;
}

// 3. Admin Silme
if(isset($_POST['admin_sil'])) {
    $silinecek_id = (int)$_POST['silinecek_id'];
    
    // Kendini silmesini engelle
    if($silinecek_id == $_SESSION['admin_id']) {
        setFlash("Kendi hesabınızı silemezsiniz!", "danger");
    } else {
        $pdo->prepare("DELETE FROM yoneticiler WHERE id=? AND firma_id='IBIR-4247-ADMIN'")->execute([$silinecek_id]);
        setFlash("Yönetici hesabı silindi.", "warning");
    }
    header("Location: kullanicilar.php");
    exit;
}

// --- VERİ ÇEKME ---
// Sadece Sistem Yöneticilerini (firma_id = IBIR-4247-ADMIN) çekiyoruz
$adminler = $pdo->query("SELECT * FROM yoneticiler WHERE firma_id = 'IBIR-4247-ADMIN' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Sistem Kullanıcıları</h1>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#yeniAdminModal">
        <i class="fas fa-user-plus fa-sm text-white-50"></i> Yeni Yönetici Ekle
    </button>
</div>

<!-- Bilgi Kartı -->
<div class="row mb-4">
    <div class="col-xl-4 col-md-6">
        <div class="card border-left-info shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Toplam Sistem Yöneticisi</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($adminler) ?> Kişi</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-users-cog fa-2x text-gray-300" style="opacity: 0.3;"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-shield-alt me-1"></i> Yetkili Hesaplar (Süper Adminler)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Kullanıcı (Ad Soyad)</th>
                        <th>İletişim Bilgileri</th>
                        <th>Rol / Yetki</th>
                        <th>Durum</th>
                        <th class="text-end pe-4">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($adminler as $a): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold me-3 shadow-sm" style="width: 40px; height: 40px;">
                                    <?= strtoupper(substr(e($a['kullanici_adi']), 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark"><?= e($a['ad_soyad'] ?: $a['kullanici_adi']) ?></div>
                                    <div class="small text-muted">@<?= e($a['kullanici_adi']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="small"><i class="fas fa-envelope text-muted me-1"></i> <?= e($a['email']) ?></div>
                            <div class="small"><i class="fas fa-phone text-muted me-1"></i> <?= e($a['telefon'] ?: 'Belirtilmemiş') ?></div>
                        </td>
                        <td>
                            <?php if($a['rol'] == 'super_admin'): ?>
                                <span class="badge bg-danger"><i class="fas fa-star me-1"></i> Süper Admin</span>
                            <?php else: ?>
                                <span class="badge bg-primary">Moderatör</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($a['email_onayli'] == 1): ?>
                                <span class="text-success small fw-bold"><i class="fas fa-check-circle me-1"></i> Aktif</span>
                            <?php else: ?>
                                <span class="text-warning small fw-bold"><i class="fas fa-clock me-1"></i> Onay Bekliyor</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <?php if($a['id'] == $_SESSION['admin_id']): ?>
                                <span class="badge bg-light text-dark border me-2">Senin Hesabın</span>
                            <?php endif; ?>
                            
                            <!-- Düzenle Butonu -->
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#duzenleAdminModal<?= $a['id'] ?>"><i class="fas fa-edit"></i></button>
                            
                            <!-- Sil Butonu (Kendini Silemez) -->
                            <?php if($a['id'] != $_SESSION['admin_id']): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Bu sistem yöneticisini silmek istediğinize emin misiniz?');">
                                <input type="hidden" name="admin_sil" value="1">
                                <input type="hidden" name="silinecek_id" value="<?= $a['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- ADMİN DÜZENLE MODAL -->
                    <div class="modal fade" id="duzenleAdminModal<?= $a['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="admin_duzenle" value="1">
                                    <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                                    <div class="modal-header bg-light">
                                        <h5 class="modal-title">Yöneticiyi Düzenle: <?= e($a['kullanici_adi']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold small">Kullanıcı Adı</label>
                                                <input type="text" name="kullanici_adi" class="form-control" value="<?= e($a['kullanici_adi']) ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold small">Ad Soyad</label>
                                                <input type="text" name="ad_soyad" class="form-control" value="<?= e($a['ad_soyad']) ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold small">E-Posta Adresi</label>
                                                <input type="email" name="email" class="form-control" value="<?= e($a['email']) ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold small">Telefon</label>
                                                <input type="text" name="telefon" class="form-control" value="<?= e($a['telefon']) ?>">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label fw-bold small">Yetki Rolü</label>
                                                <select name="rol" class="form-select">
                                                    <option value="super_admin" <?= $a['rol'] == 'super_admin' ? 'selected' : '' ?>>Süper Admin (Tam Yetki)</option>
                                                    <option value="admin" <?= $a['rol'] == 'admin' ? 'selected' : '' ?>>Moderatör (Sınırlı Yetki)</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <hr class="my-2">
                                                <label class="form-label fw-bold small text-danger">Şifreyi Değiştir (İsteğe Bağlı)</label>
                                                <input type="password" name="sifre" class="form-control" placeholder="Değiştirmek istemiyorsanız boş bırakın">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                        <button type="submit" class="btn btn-primary">Değişiklikleri Kaydet</button>
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

<!-- YENİ YÖNETİCİ EKLE MODAL -->
<div class="modal fade" id="yeniAdminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="admin_ekle" value="1">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Yeni Sistem Yöneticisi Ekle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Kullanıcı Adı <span class="text-danger">*</span></label>
                            <input type="text" name="kullanici_adi" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Ad Soyad</label>
                            <input type="text" name="ad_soyad" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">E-Posta Adresi <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Telefon</label>
                            <input type="text" name="telefon" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Geçici Şifre <span class="text-danger">*</span></label>
                            <input type="password" name="sifre" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Yetki Rolü</label>
                            <select name="rol" class="form-select">
                                <option value="super_admin">Süper Admin (Tam Yetki)</option>
                                <option value="admin">Moderatör (Sınırlı Yetki)</option>
                            </select>
                            <div class="small text-muted mt-1">Süper Admin tüm ayarları görebilir, moderatörler ayar sayfalarına erişemez.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Yöneticiyi Oluştur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>