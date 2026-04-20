<?php
require_once '../templates/header.php';
global $pdo;

// Sadece super_admin ve admin girebilir
$admin_id = $_SESSION['admin_id'] ?? 0;

// --- İSTATİSTİK VERİLERİNİ HAZIRLAMA ---

// 1. Genel İstatistikler
$toplamFirma = $pdo->query("SELECT COUNT(*) FROM firmalar")->fetchColumn();
$aktifFirma = $pdo->query("SELECT COUNT(*) FROM firmalar WHERE durum = 1")->fetchColumn();
$toplamMusteri = $pdo->query("SELECT COUNT(*) FROM musteriler")->fetchColumn();

// 2. Platform Geliri (firma_odemeler tablosundan)
$toplamGelir = $pdo->query("SELECT SUM(tutar) FROM firma_odemeler")->fetchColumn() ?: 0;
$buAyGelir = $pdo->query("SELECT SUM(tutar) FROM firma_odemeler WHERE MONTH(tarih) = MONTH(CURRENT_DATE()) AND YEAR(tarih) = YEAR(CURRENT_DATE())")->fetchColumn() ?: 0;

// 3. Firma Büyüme Grafiği İçin Son 6 Ayın Verileri
$aylar = [];
$firmaArtis = [];
for ($i = 5; $i >= 0; $i--) {
    $ay_adi = date('M', strtotime("-$i months")); // Örn: Jan, Feb
    $ay_no = date('m', strtotime("-$i months"));
    $yil_no = date('Y', strtotime("-$i months"));
    
    $aylar[] = $ay_adi;
    
    // O ayki yeni kayıtları say
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM firmalar WHERE MONTH(kayit_tarihi) = ? AND YEAR(kayit_tarihi) = ?");
    $stmt->execute([$ay_no, $yil_no]);
    $firmaArtis[] = $stmt->fetchColumn();
}

// 4. En Aktif Firmalar (Son 30 gündeki log sayısına göre)
$aktifFirmalarSql = "
    SELECT f.firma_adi, COUNT(l.id) as islem_sayisi 
    FROM sistem_loglari l
    JOIN firmalar f ON l.firma_id = f.id
    WHERE l.tarih >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY l.firma_id
    ORDER BY islem_sayisi DESC
    LIMIT 5
";
$enAktifFirmalar = $pdo->query($aktifFirmalarSql)->fetchAll(PDO::FETCH_ASSOC);

// 5. Son Yapılan 50 Sistem İşlemi (Loglar)
$sonLoglar = $pdo->query("
    SELECT l.*, f.firma_adi, y.kullanici_adi 
    FROM sistem_loglari l
    LEFT JOIN firmalar f ON l.firma_id = f.id
    LEFT JOIN yoneticiler y ON l.kullanici_id = y.id
    ORDER BY l.tarih DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!-- Chart.js Kütüphanesi -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Sistem Raporları ve İstatistikler</h1>
    <button class="btn btn-primary btn-sm shadow-sm" onclick="window.print()"><i class="fas fa-print me-1"></i> Raporu Yazdır</button>
</div>

<!-- Özet Kartları -->
<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card border-left-primary shadow-sm h-100 py-2 border-0" style="border-left: 4px solid #4e73df;">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1 small">Toplam Ciro (Platform)</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($toplamGelir, 2) ?> ₺</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-lira-sign fa-2x text-gray-300 opacity-50"></i></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6">
        <div class="card border-left-success shadow-sm h-100 py-2 border-0" style="border-left: 4px solid #1cc88a;">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1 small">Bu Ayki Gelir</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">+<?= number_format($buAyGelir, 2) ?> ₺</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-chart-line fa-2x text-gray-300 opacity-50"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card border-left-info shadow-sm h-100 py-2 border-0" style="border-left: 4px solid #36b9cc;">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1 small">Aktif / Toplam Firma</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $aktifFirma ?> / <?= $toplamFirma ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-building fa-2x text-gray-300 opacity-50"></i></div>
                </div>
                <div class="progress mt-2" style="height: 5px;">
                    <?php $yuzde = $toplamFirma > 0 ? ($aktifFirma/$toplamFirma)*100 : 0; ?>
                    <div class="progress-bar bg-info" role="progressbar" style="width: <?= $yuzde ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card border-left-warning shadow-sm h-100 py-2 border-0" style="border-left: 4px solid #f6c23e;">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1 small">Sistemdeki Tüm Müşteriler</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($toplamMusteri) ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300 opacity-50"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- GRAFİK ALANI -->
    <div class="col-lg-8 mb-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 font-weight-bold text-primary">Firma Büyüme Grafiği (Son 6 Ay)</h6>
            </div>
            <div class="card-body">
                <div class="chart-area" style="height: 300px;">
                    <canvas id="firmaArtisGrafigi"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- EN AKTİF FİRMALAR -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">En Aktif Firmalar (Son 30 Gün)</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if(empty($enAktifFirmalar)): ?>
                        <li class="list-group-item text-muted text-center py-4">Henüz yeterli aktivite yok.</li>
                    <?php else: ?>
                        <?php foreach($enAktifFirmalar as $index => $af): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                                <div>
                                    <span class="badge bg-<?= $index == 0 ? 'warning' : 'secondary' ?> rounded-circle me-2"><?= $index + 1 ?></span>
                                    <span class="fw-bold"><?= e($af['firma_adi'] ?? 'Bilinmeyen Firma') ?></span>
                                </div>
                                <span class="badge bg-primary rounded-pill"><?= $af['islem_sayisi'] ?> İşlem</span>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- DETAYLI SİSTEM LOGLARI -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list-alt me-1"></i> Sistem İşlem Logları (Son 50 Kayıt)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm table-striped table-hover align-middle mb-0" style="font-size: 0.85rem;">
                <thead class="bg-light sticky-top">
                    <tr>
                        <th class="ps-3">Tarih</th>
                        <th>Kullanıcı (Admin)</th>
                        <th>İlgili Firma</th>
                        <th>İşlem Türü</th>
                        <th>Detay / IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($sonLoglar as $log): ?>
                    <tr>
                        <td class="ps-3 text-muted"><?= date('d.m.Y H:i', strtotime($log['tarih'])) ?></td>
                        <td class="fw-bold"><?= e($log['kullanici_adi'] ?? 'Sistem') ?></td>
                        <td><?= e($log['firma_adi'] ?? 'Genel Sistem İşlemi') ?></td>
                        <td><span class="badge bg-info text-dark"><?= e($log['islem']) ?></span></td>
                        <td>
                            <?= e($log['detay']) ?><br>
                            <small class="text-muted"><i class="fas fa-network-wired me-1"></i> <?= e($log['ip_adresi'] ?? '-') ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($sonLoglar)): ?>
                        <tr><td colspan="5" class="text-center py-3 text-muted">Kayıtlı sistem logu bulunamadı.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Grafik JS Ayarları -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    var ctx = document.getElementById("firmaArtisGrafigi");
    if (ctx) {
        var myLineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($aylar) ?>,
                datasets: [{
                    label: "Yeni Firma Kaydı",
                    lineTension: 0.3,
                    backgroundColor: "rgba(78, 115, 223, 0.05)",
                    borderColor: "rgba(78, 115, 223, 1)",
                    pointRadius: 3,
                    pointBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointBorderColor: "rgba(78, 115, 223, 1)",
                    pointHoverRadius: 3,
                    pointHoverBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointHoverBorderColor: "rgba(78, 115, 223, 1)",
                    pointHitRadius: 10,
                    pointBorderWidth: 2,
                    data: <?= json_encode($firmaArtis) ?>,
                    fill: true
                }],
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false, drawBorder: false } },
                    y: { 
                        beginAtZero: true,
                        ticks: { stepSize: 1 },
                        grid: { color: "rgb(234, 236, 244)", zeroLineColor: "rgb(234, 236, 244)", drawBorder: false, borderDash: [2], zeroLineBorderDash: [2] }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>