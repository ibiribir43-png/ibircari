<?php
session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
require_once 'partials/security_check.php';

// Güvenlik: GET/POST verilerini temizle
$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

// Sayfa başlığı
$page_title = "Raporlar ve İstatistikler";
$firma_id = $_SESSION['firma_id'];

// Ekstra JS dosyaları (Chart.js için)
$extra_js = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'];

// 1. GENEL İSTATİSTİKLER (AYRIŞTIRILMIŞ VERSİYON)
$sorguOzet = $db->prepare("
    SELECT 
        (SELECT COALESCE(SUM(toplam_tutar),0) FROM hareketler WHERE islem_turu = 'satis' AND firma_id = ? AND vade_tarihi >= CURDATE()) as gelecek_satis,
        (SELECT COALESCE(SUM(toplam_tutar),0) FROM hareketler WHERE islem_turu = 'tahsilat' AND firma_id = ?) as toplam_tahsilat
");
$sorguOzet->execute([$firma_id, $firma_id]);
$ozet = $sorguOzet->fetch(PDO::FETCH_ASSOC);

// Gelecek Ciro: Sadece gelecek tarihli işler
$gelecek_ciro = $ozet['gelecek_satis'];

// Vadesi geçmiş alacakları ve Borçluları hesapla
$sorguTumBorclular = $db->prepare("
    SELECT m.id, m.ad_soyad, m.telefon, m.url_token,
    (
        (SELECT COALESCE(SUM(toplam_tutar),0) FROM hareketler WHERE musteri_id = m.id AND islem_turu='satis' AND firma_id = ? AND vade_tarihi < CURDATE()) - 
        (SELECT COALESCE(SUM(toplam_tutar),0) FROM hareketler WHERE musteri_id = m.id AND islem_turu='tahsilat' AND firma_id = ?)
    ) as guncel_bakiye
    FROM musteriler m
    WHERE m.durum = 1 AND m.silindi = 0 AND m.firma_id = ?
    HAVING guncel_bakiye > 0
    ORDER BY guncel_bakiye DESC
");
$sorguTumBorclular->execute([$firma_id, $firma_id, $firma_id]);
$tumBorclular = $sorguTumBorclular->fetchAll(PDO::FETCH_ASSOC);

$vadesi_gecmis_alacak = 0;
foreach ($tumBorclular as $b) {
    $vadesi_gecmis_alacak += $b['guncel_bakiye'];
}

// 2. YAKLAŞAN ÇEKİMLER / HİZMETLER (Gelecek İşler)
$sorguAjanda = $db->prepare("
    SELECT h.*, m.ad_soyad, m.telefon, m.url_token
    FROM hareketler h 
    JOIN musteriler m ON h.musteri_id = m.id 
    WHERE h.firma_id = ? 
    AND h.islem_turu = 'satis' 
    AND h.vade_tarihi >= CURDATE() 
    ORDER BY h.vade_tarihi ASC 
    LIMIT 10
");
$sorguAjanda->execute([$firma_id]);
$gelecekIsler = $sorguAjanda->fetchAll(PDO::FETCH_ASSOC);

// 3. EN ÇOK BORCU OLAN MÜŞTERİLER (Genel Bakiye - Vadesi Gelen/Gelmeyen Fark Etmez)
$sorguBorclular = $db->prepare("
    SELECT m.id, m.ad_soyad, m.telefon, m.url_token,
    (
        (SELECT COALESCE(SUM(toplam_tutar),0) FROM hareketler WHERE musteri_id = m.id AND islem_turu='satis' AND firma_id = ?) - 
        (SELECT COALESCE(SUM(toplam_tutar),0) FROM hareketler WHERE musteri_id = m.id AND islem_turu='tahsilat' AND firma_id = ?)
    ) as guncel_bakiye
    FROM musteriler m
    WHERE m.durum = 1 AND m.silindi = 0 AND m.firma_id = ?
    HAVING guncel_bakiye > 0
    ORDER BY guncel_bakiye DESC
    LIMIT 10
");
$sorguBorclular->execute([$firma_id, $firma_id, $firma_id]);
$borclular = $sorguBorclular->fetchAll(PDO::FETCH_ASSOC);

// 4. GRAFİK VERİLERİ (Son 6 Ay Trendi)
$aylar = [];
$satislar = [];
$tahsilatlar = [];

for ($i = 5; $i >= 0; $i--) {
    $tarihBas = date("Y-m-01", strtotime("-$i months"));
    $tarihBit = date("Y-m-t", strtotime("-$i months"));
    $ayAdi = date("M Y", strtotime("-$i months"));
    
    $sorguAySatis = $db->prepare("SELECT COALESCE(SUM(toplam_tutar),0) FROM hareketler WHERE islem_turu='satis' AND firma_id = ? AND islem_tarihi BETWEEN ? AND ?");
    $sorguAySatis->execute([$firma_id, $tarihBas . " 00:00:00", $tarihBit . " 23:59:59"]);
    
    $sorguAyTahsilat = $db->prepare("SELECT COALESCE(SUM(toplam_tutar),0) FROM hareketler WHERE islem_turu='tahsilat' AND firma_id = ? AND islem_tarihi BETWEEN ? AND ?");
    $sorguAyTahsilat->execute([$firma_id, $tarihBas . " 00:00:00", $tarihBit . " 23:59:59"]);
    
    $aylar[] = $ayAdi;
    $satislar[] = (float)$sorguAySatis->fetchColumn();
    $tahsilatlar[] = (float)$sorguAyTahsilat->fetchColumn();
}

// JSON verilerini hazırla
$jsAylar = json_encode($aylar, JSON_UNESCAPED_UNICODE);
$jsSatislar = json_encode($satislar);
$jsTahsilatlar = json_encode($tahsilatlar);

// 5. YENİ: BU AYIN ÖDEME YÖNTEMLERİ (Pasta Grafik İçin)
$buAyBaslangic = date('Y-m-01 00:00:00');
$buAyBitis = date('Y-m-t 23:59:59');

$odemeYontemleri = $db->prepare("
    SELECT odeme_turu, SUM(toplam_tutar) as toplam 
    FROM hareketler 
    WHERE firma_id = ? AND islem_turu = 'tahsilat' AND islem_tarihi BETWEEN ? AND ?
    GROUP BY odeme_turu
");
$odemeYontemleri->execute([$firma_id, $buAyBaslangic, $buAyBitis]);
$odemeGruplari = $odemeYontemleri->fetchAll(PDO::FETCH_ASSOC);

$nakit_toplam = 0; $kk_toplam = 0; $havale_toplam = 0;
$buAyGelenToplam = 0;

foreach ($odemeGruplari as $grup) {
    if ($grup['odeme_turu'] == 0) $nakit_toplam = (float)$grup['toplam'];
    if ($grup['odeme_turu'] == 1) $kk_toplam = (float)$grup['toplam'];
    if ($grup['odeme_turu'] == 2) $havale_toplam = (float)$grup['toplam'];
    $buAyGelenToplam += (float)$grup['toplam'];
}

// Sayfaya özel inline CSS
$inline_css = '
    .card-header { font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
    .bg-gradient-primary { background: linear-gradient(45deg, #4e73df, #224abe); color:white; }
    .bg-gradient-success { background: linear-gradient(45deg, #1cc88a, #13855c); color:white; }
    .bg-gradient-warning { background: linear-gradient(45deg, #f6c23e, #dda20a); color:white; }
    .bg-gradient-danger { background: linear-gradient(45deg, #e74a3b, #be2617); color:white; }
    .bg-gradient-info { background: linear-gradient(45deg, #36b9cc, #258391); color:white; }
    .clickable-card { transition: transform 0.2s, box-shadow 0.2s; cursor: pointer; }
    .clickable-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(231, 74, 59, 0.4) !important; }
    @media (max-width: 768px) {
        .container-yonetim { padding-left: 10px; padding-right: 10px; }
        h3 { font-size: 1.5rem; }
    }
';

$inline_js = '
    function initChart() {
        if (typeof Chart === "undefined") {
            setTimeout(initChart, 500); return;
        }
        
        // 1. TREND GRAFİĞİ (Bar Chart)
        const ctx = document.getElementById("ciroGrafigi");
        if (ctx) {
            new Chart(ctx, {
                type: "bar",
                data: {
                    labels: ' . $jsAylar . ',
                    datasets: [
                        { label: "Yapılan İş / Satış", data: ' . $jsSatislar . ', backgroundColor: "rgba(78, 115, 223, 0.8)", borderColor: "rgba(78, 115, 223, 1)", borderWidth: 1 },
                        { label: "Tahsilat / Kasa", data: ' . $jsTahsilatlar . ', backgroundColor: "rgba(28, 200, 138, 0.8)", borderColor: "rgba(28, 200, 138, 1)", borderWidth: 1 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { 
                        legend: { position: "top" },
                        tooltip: { callbacks: { label: function(context) { return context.dataset.label + ": " + context.parsed.y.toLocaleString("tr-TR") + " ₺"; } } }
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return value.toLocaleString("tr-TR") + " ₺"; } } } }
                }
            });
        }

        // 2. ÖDEME YÖNTEMLERİ (Pasta Grafik)
        const paymentCtx = document.getElementById("paymentChart");
        ' . ($buAyGelenToplam > 0 ? '
        if (paymentCtx) {
            new Chart(paymentCtx, {
                type: "doughnut",
                data: {
                    labels: ["Nakit", "Kredi Kartı", "Havale / EFT"],
                    datasets: [{
                        data: [' . $nakit_toplam . ', ' . $kk_toplam . ', ' . $havale_toplam . '],
                        backgroundColor: ["#1cc88a", "#4e73df", "#f6c23e"],
                        hoverBackgroundColor: ["#17a673", "#2e59d9", "#dda20a"],
                        borderWidth: 2, borderColor: "#ffffff"
                    }]
                },
                options: {
                    maintainAspectRatio: false, responsive: true, cutout: "70%",
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function(context) { return " " + context.parsed.toLocaleString("tr-TR") + " ₺"; } } }
                    }
                }
            });
        }
        ' : '') . '
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initChart);
    } else {
        initChart();
    }
';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | <?php echo htmlspecialchars($firma_adi ?? 'ibiR Cari'); ?></title>
    
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style><?php echo $inline_css; ?></style>
</head>
<body class="yonetim-body bg-light">

<?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim pb-5 mt-4">

        <div class="row mb-4 align-items-center">
            <div class="col-md-6"><h3 class="text-secondary mb-0 fw-bold"><i class="fas fa-chart-line me-2 text-primary"></i>Finansal Durum ve Ajanda</h3></div>
            <div class="col-md-6 text-end d-none d-md-block">
                <button class="btn btn-outline-dark btn-sm shadow-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Yazdır</button>
            </div>
        </div>

        <!-- Üst Özet Kartlar -->
        <div class="row mb-4 g-4">
            
            <!-- KART 1: Vadesi Dolmuş / Net Alacak (TIKLANABİLİR EKLENDİ) -->
            <div class="col-md-4">
                <div class="card bg-gradient-danger border-0 shadow-sm h-100 py-2 clickable-card" data-bs-toggle="modal" data-bs-target="#vadesiGecmisModal" title="Detayları görmek için tıklayın">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="text-xs font-weight-bold text-uppercase" style="opacity:0.9">Vadesi Dolmuş Alacaklar</div>
                            <i class="fas fa-exclamation-triangle fa-lg" style="opacity:0.6"></i>
                        </div>
                        <div class="h3 mb-0 font-weight-bold"><?php echo number_format($vadesi_gecmis_alacak, 2, ',', '.'); ?> ₺</div>
                        <div class="small mt-2" style="opacity:0.8">
                            <i class="fas fa-hand-pointer me-1"></i> Kimlerin borcu var? (Tıklayın)
                        </div>
                    </div>
                </div>
            </div>

            <!-- KART 2: Gelecek Ciro / Beklenen -->
            <div class="col-md-4">
                <div class="card bg-gradient-info border-0 shadow-sm h-100 py-2">
                    <div class="card-body">
                         <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="text-xs font-weight-bold text-uppercase" style="opacity:0.9">Planlanan / Gelecek Gelir</div>
                            <i class="fas fa-calendar-alt fa-lg" style="opacity:0.6"></i>
                        </div>
                        <div class="h3 mb-0 font-weight-bold"><?php echo number_format($gelecek_ciro, 2, ',', '.'); ?> ₺</div>
                        <div class="small mt-2" style="opacity:0.8">
                            <i class="fas fa-arrow-right me-1"></i> Henüz günü gelmemiş işler
                        </div>
                    </div>
                </div>
            </div>

            <!-- KART 3: Kasa / Toplam Tahsilat -->
            <div class="col-md-4">
                <div class="card bg-gradient-success border-0 shadow-sm h-100 py-2">
                    <div class="card-body">
                         <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="text-xs font-weight-bold text-uppercase" style="opacity:0.9">Toplam Kasa / Tahsilat</div>
                            <i class="fas fa-wallet fa-lg" style="opacity:0.6"></i>
                        </div>
                        <div class="h3 mb-0 font-weight-bold"><?php echo number_format($ozet['toplam_tahsilat'], 2, ',', '.'); ?> ₺</div>
                         <div class="small mt-2" style="opacity:0.8">
                            <i class="fas fa-check-circle me-1"></i> Cebine giren net para
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            
            <!-- SOL SÜTUN: Grafik -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 h-100 rounded-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-chart-area me-2"></i>Aylık Gelir / Gider Trendi (Son 6 Ay)</h6>
                    </div>
                    <div class="card-body">
                        <div style="position: relative; height: 300px; width: 100%;">
                            <canvas id="ciroGrafigi"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- SAĞ SÜTUN: Ödeme Yöntemleri -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 h-100 rounded-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-chart-pie me-2"></i>Bu Ayki Ödeme Türleri</h6>
                    </div>
                    <div class="card-body d-flex flex-column align-items-center justify-content-center">
                        <?php if($buAyGelenToplam > 0): ?>
                            <div style="position: relative; width: 200px; height: 200px;">
                                <canvas id="paymentChart"></canvas>
                            </div>
                            <div class="mt-4 w-100 px-3">
                                <div class="d-flex justify-content-between small mb-2 border-bottom pb-1">
                                    <span><i class="fas fa-circle text-success me-1"></i> Nakit</span>
                                    <span class="fw-bold"><?php echo number_format($nakit_toplam, 2, ',', '.'); ?> ₺</span>
                                </div>
                                <div class="d-flex justify-content-between small mb-2 border-bottom pb-1">
                                    <span><i class="fas fa-circle text-primary me-1"></i> Kredi Kartı</span>
                                    <span class="fw-bold"><?php echo number_format($kk_toplam, 2, ',', '.'); ?> ₺</span>
                                </div>
                                <div class="d-flex justify-content-between small">
                                    <span><i class="fas fa-circle text-warning me-1"></i> Havale / EFT</span>
                                    <span class="fw-bold"><?php echo number_format($havale_toplam, 2, ',', '.'); ?> ₺</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-muted">
                                <i class="fas fa-box-open fa-3x mb-3 opacity-25"></i>
                                <p class="mb-0">Bu ay henüz tahsilat yapılmamış.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <div class="row g-4">
            
            <!-- Yaklaşan Çekimler (Ajanda) -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-info"><i class="fas fa-calendar-alt me-2"></i>Gelecek İşler / Planlanan</h6>
                        <span class="badge bg-info text-white">Sadece Vadesi Gelmemişler</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Hizmet Tarihi</th>
                                        <th>Müşteri</th>
                                        <th>İşin Adı</th>
                                        <th class="text-end pe-3">Tutar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($gelecekIsler) > 0): ?>
                                        <?php foreach($gelecekIsler as $is): ?>
                                            <tr>
                                                <td class="fw-bold text-primary ps-3">
                                                    <?php echo date("d.m.Y", strtotime($is['vade_tarihi'])); ?>
                                                    <?php 
                                                    $fark = (strtotime($is['vade_tarihi']) - time()) / (60 * 60 * 24);
                                                    if($fark < 2 && $fark >= 0) echo '<span class="badge bg-warning text-dark ms-1">Yakın!</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="musteri_detay.php?t=<?php echo htmlspecialchars($is['url_token'] ?? ''); ?>" class="text-decoration-none text-dark fw-bold">
                                                        <?php echo htmlspecialchars($is['ad_soyad']); ?>
                                                    </a>
                                                    <div class="small text-muted"><i class="fas fa-phone fa-sm me-1"></i><?php echo htmlspecialchars($is['telefon']); ?></div>
                                                </td>
                                                <td class="text-muted"><?php echo htmlspecialchars($is['urun_aciklama']); ?></td>
                                                <td class="text-end pe-3 fw-semibold text-dark"><?php echo number_format($is['toplam_tutar'], 2, ',', '.'); ?> ₺</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">Yakın tarihte planlanmış çekim yok.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- En Çok Borcu Olanlar Listesi -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="m-0 font-weight-bold text-danger"><i class="fas fa-users me-2"></i>En Çok Borcu Olanlar</h6>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php if(count($borclular) > 0): ?>
                                <?php foreach($borclular as $b): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                                        <div>
                                            <a href="musteri_detay.php?t=<?php echo htmlspecialchars($b['url_token'] ?? ''); ?>" class="fw-bold text-dark text-decoration-none">
                                                <?php echo htmlspecialchars($b['ad_soyad']); ?>
                                            </a>
                                            <div class="small text-muted"><i class="fas fa-phone fa-sm me-1"></i><?php echo htmlspecialchars($b['telefon']); ?></div>
                                        </div>
                                        <span class="badge bg-danger rounded-pill fs-6 shadow-sm">
                                            <?php echo number_format($b['guncel_bakiye'], 2, ',', '.'); ?> ₺
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="list-group-item text-center text-muted py-5">
                                    <i class="fas fa-check-circle fa-2x text-success opacity-50 mb-2"></i><br>Harika! Kimsenin borcu yok.
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="card-footer text-center bg-light border-0">
                        <a href="musteriler.php" class="small text-primary fw-bold text-decoration-none">Tüm Müşterileri Gör <i class="fas fa-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- YENİ MODAL: VADESİ GEÇMİŞ BORÇLULAR LİSTESİ -->
    <div class="modal fade" id="vadesiGecmisModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-danger text-white border-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Vadesi Geçmiş (Geciken) Alacaklar</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light sticky-top">
                                <tr>
                                    <th class="ps-4">Müşteri Adı</th>
                                    <th>İletişim</th>
                                    <th class="text-end">Geciken Borç</th>
                                    <th class="text-end pe-4">Profil</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($tumBorclular) > 0): ?>
                                    <?php foreach($tumBorclular as $gB): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark"><?php echo htmlspecialchars($gB['ad_soyad']); ?></td>
                                            <td>
                                                <a href="tel:<?php echo htmlspecialchars($gB['telefon']); ?>" class="text-decoration-none text-muted small">
                                                    <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($gB['telefon']); ?>
                                                </a>
                                            </td>
                                            <td class="text-end fw-bold text-danger fs-6">
                                                <?php echo number_format($gB['guncel_bakiye'], 2, ',', '.'); ?> ₺
                                            </td>
                                            <td class="text-end pe-4">
                                                <a href="musteri_detay.php?t=<?php echo htmlspecialchars($gB['url_token'] ?? ''); ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                    Git <i class="fas fa-arrow-right ms-1"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-5 text-muted">
                                            <i class="fas fa-laugh-beam fa-3x text-success opacity-50 mb-3"></i><br>
                                            Şu an için vadesi geçmiş (geciken) hiçbir alacağınız bulunmuyor!
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <?php if(count($tumBorclular) > 0): ?>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="2" class="ps-4 text-end fw-bold text-uppercase">GENEL TOPLAM:</td>
                                    <td class="text-end fw-bold text-danger fs-5"><?php echo number_format($vadesi_gecmis_alacak, 2, ',', '.'); ?> ₺</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- TOAST CONTAINER -->
    <div id="toast-container-yonetim"></div>

<script>
    <?php echo $inline_js; ?>
</script>

<?php require_once 'partials/footer_yonetim.php'; ?>
</body>
</html>