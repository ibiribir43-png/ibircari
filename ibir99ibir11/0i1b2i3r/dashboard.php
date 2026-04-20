<?php
require_once '../templates/header.php';
global $pdo;

// 1. İstatistikleri Çek
$statFirmalar = $pdo->query("SELECT COUNT(*) FROM firmalar")->fetchColumn();
$statAktifFirmalar = $pdo->query("SELECT COUNT(*) FROM firmalar WHERE durum = 1")->fetchColumn();
$statMusteriler = $pdo->query("SELECT COUNT(*) FROM musteriler")->fetchColumn();
$statBugunKayit = $pdo->query("SELECT COUNT(*) FROM firmalar WHERE DATE(kayit_tarihi) = CURDATE()")->fetchColumn();

// 2. Son 5 Firma
$sonFirmalar = $pdo->query("SELECT id, firma_adi, yetkili_ad_soyad, kayit_tarihi, durum FROM firmalar ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// 3. Sistem Logları (Son İşlemler)
$sonLoglar = $pdo->query("
    SELECT l.tarih, l.islem, l.detay, y.kullanici_adi, f.firma_adi 
    FROM sistem_loglari l 
    LEFT JOIN yoneticiler y ON l.kullanici_id = y.id 
    LEFT JOIN firmalar f ON l.firma_id = f.id 
    ORDER BY l.tarih DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Sistem Genel Bakış</h1>
    <a href="raporlar.php" class="btn btn-sm btn-primary shadow-sm"><i class="fas fa-download fa-sm text-white-50"></i> Rapor İndir</a>
</div>

<!-- İstatistik Kartları -->
<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-title">Toplam Firma</div>
                    <div class="stat-value"><?= number_format($statFirmalar) ?></div>
                </div>
                <i class="fas fa-building fa-2x text-gray-300" style="opacity: 0.3;"></i>
            </div>
            <div class="mt-2 text-success small"><i class="fas fa-arrow-up"></i> <?= $statBugunKayit ?> firma bugün kayıt oldu</div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-title">Aktif Firmalar</div>
                    <div class="stat-value"><?= number_format($statAktifFirmalar) ?></div>
                </div>
                <i class="fas fa-check-circle fa-2x text-gray-300" style="opacity: 0.3;"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-title">Toplam Müşteri (Son Kullanıcı)</div>
                    <div class="stat-value"><?= number_format($statMusteriler) ?></div>
                </div>
                <i class="fas fa-users fa-2x text-gray-300" style="opacity: 0.3;"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-title">Sistem Durumu</div>
                    <div class="stat-value text-success">Sağlıklı</div>
                </div>
                <i class="fas fa-server fa-2x text-gray-300" style="opacity: 0.3;"></i>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Son Eklenen Firmalar -->
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-building me-1"></i> Son Kayıt Olan Firmalar</span>
                <a href="firmalar.php" class="btn btn-sm btn-outline-primary">Tümünü Gör</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Firma Adı</th>
                                <th>Yetkili</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($sonFirmalar as $f): ?>
                            <tr>
                                <td class="fw-bold"><?= e($f['firma_adi']) ?></td>
                                <td><?= e($f['yetkili_ad_soyad']) ?></td>
                                <td>
                                    <?php if($f['durum'] == 1): ?>
                                        <span class="badge bg-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Pasif</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($sonFirmalar)): ?>
                                <tr><td colspan="3" class="text-center text-muted">Kayıt bulunamadı.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Son İşlemler (Loglar) -->
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list me-1"></i> Son Yapılan İşlemler</span>
                <a href="raporlar.php?tab=loglar" class="btn btn-sm btn-outline-primary">Tüm Loglar</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 small">
                        <tbody>
                            <?php foreach($sonLoglar as $l): ?>
                            <tr>
                                <td style="width: 15%;" class="text-muted"><?= date('H:i', strtotime($l['tarih'])) ?></td>
                                <td>
                                    <span class="fw-bold text-dark"><?= e($l['islem']) ?></span>
                                    <br>
                                    <span class="text-muted"><?= e($l['detay']) ?></span>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-light text-dark border"><?= e($l['kullanici_adi'] ?? 'Sistem') ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($sonLoglar)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">Log bulunamadı.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>