<?php
session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['kullanici_id'];
$firma_id = $_SESSION['firma_id'];

// 2. FİRMA BİLGİLERİNİ ÇEK
$firma = $db->query("
    SELECT f.*, p.paket_adi, p.fiyat as mevcut_paket_fiyat 
    FROM firmalar f 
    LEFT JOIN paketler p ON f.paket_id = p.id 
    WHERE f.id='$firma_id'
")->fetch(PDO::FETCH_ASSOC);

// --- KALAN GÜN İADESİ HESAPLAMA (PRORATION) ---
$kalan_gun = 0;
$iade_kredisi = 0;
$cuzdan_bakiyesi = (float)($firma['cuzdan_bakiyesi'] ?? 0);

if (!empty($firma['abonelik_bitis']) && strtotime($firma['abonelik_bitis']) > time()) {
    $bitis_timestamp = strtotime($firma['abonelik_bitis']);
    $kalan_gun = floor(($bitis_timestamp - time()) / 86400);
    
    if ($firma['paket_id'] > 0 && $firma['mevcut_paket_fiyat'] > 0) {
        $gunluk_ucret = $firma['mevcut_paket_fiyat'] / 30;
        $iade_kredisi = $kalan_gun * $gunluk_ucret;
    }
}
// ----------------------------------------------

// 3. PAKETLERİ ÇEK (Sadece aktif olanlar ve deneme/trial olmayanlar)
$paketler = $db->query("SELECT * FROM paketler WHERE durum = 1 AND is_trial = 0 ORDER BY fiyat ASC")->fetchAll(PDO::FETCH_ASSOC);

// 4. EK HİZMETLERİ ÇEK VE GRUPLA
$tum_ek_hizmetler = $db->query("SELECT * FROM ek_hizmetler WHERE durum = 1 ORDER BY fiyat ASC")->fetchAll(PDO::FETCH_ASSOC);
$ek_sms = [];
$ek_depolama = [];
$ek_trafik = [];

foreach ($tum_ek_hizmetler as $h) {
    if ($h['tip'] == 'sms') $ek_sms[] = $h;
    elseif ($h['tip'] == 'depolama') $ek_depolama[] = $h;
    elseif ($h['tip'] == 'trafik') $ek_trafik[] = $h;
}

$page_title = "Mağaza & Paket Yükseltme";

// Özellikleri parçalamak için yardımcı fonksiyon
function ozellikListesi($ozellik_metni) {
    if (empty($ozellik_metni)) return [];
    $satirlar = explode("\n", $ozellik_metni);
    return array_filter(array_map('trim', $satirlar));
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | <?= htmlspecialchars($firma['firma_adi'] ?? '') ?></title>
    
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .pricing-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-radius: 15px;
            overflow: hidden;
        }
        .pricing-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 1rem 3rem rgba(0,0,0,.175)!important;
        }
        .pricing-header {
            padding: 2rem 1rem;
            text-align: center;
        }
        .pricing-price {
            font-size: 2.5rem;
            font-weight: 800;
        }
        .nav-pills .nav-link {
            border-radius: 50px;
            padding: 10px 25px;
            font-weight: 600;
            color: #495057;
            transition: 0.3s all ease;
        }
        .nav-pills .nav-link.active {
            box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3);
            background-color: #0d6efd;
            color: white !important;
        }
        .ek-hizmet-card {
            border-radius: 12px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
            height: 100%;
        }
        .ek-hizmet-card:hover {
            border-color: #0d6efd;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,.05);
        }
        .btn-buy {
            border-radius: 50px;
            font-weight: bold;
            padding: 10px 20px;
            transition: 0.3s all ease;
        }
        .sure-btn { transition: 0.2s; }
        .eski-fiyat { text-decoration: line-through; color: #adb5bd; font-size: 1.2rem; margin-right: 10px; display: none; }
        .iade-badge { font-size: 0.85rem; padding: 8px 15px; border-radius: 50px; background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; display: inline-block; margin-bottom: 15px; }
    </style>
</head>
<body class="yonetim-body bg-light">

    <?php include 'partials/navbar.php'; ?>

    <div class="container pb-5 mt-4">
        
        <!-- ÜST BİLGİ VE CÜZDAN KARTI -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 bg-white overflow-hidden">
                    <div class="card-body p-4 d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <div class="mb-3 mb-md-0">
                            <h4 class="fw-bold mb-1"><i class="fas fa-store text-primary me-2"></i> Mağaza ve Hizmetler</h4>
                            <p class="text-muted mb-0 small">Paket yükseltirken eski paketinizin kalan günleri faturanızdan otomatik düşülür.</p>
                        </div>
                        <div class="d-flex gap-3 text-end">
                            <?php if($cuzdan_bakiyesi > 0): ?>
                            <div class="bg-success bg-opacity-10 p-3 rounded-3 border border-success border-opacity-25">
                                <span class="text-success small d-block fw-bold"><i class="fas fa-wallet me-1"></i> Cüzdan Bakiyesi</span>
                                <span class="fw-bold text-success h5 mb-0"><?= number_format($cuzdan_bakiyesi, 2) ?> ₺</span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="bg-light p-3 rounded-3 border">
                                <span class="text-muted small d-block">Mevcut Aboneliğiniz:</span>
                                <span class="fw-bold text-dark h5 mb-0"><?= htmlspecialchars($firma['paket_adi'] ?? 'Paket Bulunamadı') ?></span>
                                <?php if($firma['abonelik_bitis']): ?>
                                    <span class="badge bg-<?= strtotime($firma['abonelik_bitis']) > time() ? 'primary' : 'danger' ?> ms-2">
                                        Bitiş: <?= date('d.m.Y', strtotime($firma['abonelik_bitis'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEKMELER (TABS) -->
        <ul class="nav nav-pills mb-4 justify-content-center bg-white p-2 rounded-pill shadow-sm" id="magazaTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="paketler-tab" data-bs-toggle="pill" data-bs-target="#paketler" type="button" role="tab"><i class="fas fa-box me-1"></i> Paket Yenile / Yükselt</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="sms-tab" data-bs-toggle="pill" data-bs-target="#sms" type="button" role="tab"><i class="fas fa-sms me-1 text-success"></i> Ek SMS Al</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="depo-tab" data-bs-toggle="pill" data-bs-target="#depo" type="button" role="tab"><i class="fas fa-hdd me-1 text-info"></i> Ek Depolama</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="trafik-tab" data-bs-toggle="pill" data-bs-target="#trafik" type="button" role="tab"><i class="fas fa-wifi me-1 text-warning"></i> Ek Trafik / Kota</button>
            </li>
        </ul>

        <!-- SEKME İÇERİKLERİ -->
        <div class="tab-content" id="magazaTabsContent">
            
            <!-- 1. PAKETLER -->
            <div class="tab-pane fade show active" id="paketler" role="tabpanel">
                
                <?php if($iade_kredisi > 0): ?>
                <div class="text-center">
                    <div class="iade-badge shadow-sm">
                        <i class="fas fa-sync-alt me-1"></i> Paket yükseltmelerinde, mevcut paketinizin kalan <b><?= $kalan_gun ?> günü</b> için faturanıza <b><?= number_format($iade_kredisi, 2) ?> ₺</b> indirim yansıtılacaktır.
                    </div>
                </div>
                <?php endif; ?>

                <!-- DİNAMİK SÜRE SEÇİCİ -->
                <div class="row justify-content-center mb-5">
                    <div class="col-md-10 text-center">
                        <div class="bg-white p-2 rounded-pill shadow-sm d-inline-flex border overflow-auto" style="max-width: 100%;">
                            <button class="btn btn-primary rounded-pill px-4 sure-btn fw-bold" onclick="degistirSure(1, 0, this)">1 Ay</button>
                            <button class="btn btn-light text-dark rounded-pill px-4 sure-btn fw-bold" onclick="degistirSure(3, 10, this)">3 Ay <span class="badge bg-danger ms-1">-%10</span></button>
                            <button class="btn btn-light text-dark rounded-pill px-4 sure-btn fw-bold" onclick="degistirSure(6, 15, this)">6 Ay <span class="badge bg-danger ms-1">-%15</span></button>
                            <button class="btn btn-light text-dark rounded-pill px-4 sure-btn fw-bold" onclick="degistirSure(12, 25, this)">1 Yıl <span class="badge bg-danger ms-1">-%25</span></button>
                        </div>
                        <div class="small text-muted mt-2"><i class="fas fa-magic text-warning me-1"></i> Uzun dönemli alımlarda net indirimler uygulanır.</div>
                    </div>
                </div>

                <div class="row g-4 justify-content-center">
                    <?php if(empty($paketler)): ?>
                        <div class="col-12 text-center py-5 text-muted">Satışa sunulmuş paket bulunmamaktadır.</div>
                    <?php endif; ?>
                    
                    <?php foreach($paketler as $p): ?>
                    <div class="col-lg-4 col-md-6">
                        <div class="card pricing-card h-100 border-0 shadow-sm <?= ($firma['paket_id'] == $p['id']) ? 'border border-primary border-3' : '' ?>">
                            <?php if($firma['paket_id'] == $p['id']): ?>
                                <div class="bg-primary text-white text-center py-1 fw-bold small">Şu Anki Paketiniz</div>
                            <?php endif; ?>
                            
                            <div class="pricing-header bg-light border-bottom">
                                <h4 class="fw-bold text-dark mb-3"><?= htmlspecialchars($p['paket_adi']) ?></h4>
                                <div class="text-primary pricing-price mb-0">
                                    <span class="eski-fiyat" id="eski_fiyat_<?= $p['id'] ?>"></span>
                                    <span class="fiyat-gosterge" id="fiyat_<?= $p['id'] ?>" data-base="<?= $p['fiyat'] ?>"><?= number_format($p['fiyat'], 2) ?></span>
                                    <span class="h5 text-muted fw-normal">₺</span>
                                </div>
                                <div class="text-muted small mt-1" id="sure_metin_<?= $p['id'] ?>">Toplam (1 Aylık)</div>
                            </div>
                            
                            <div class="card-body p-4 d-flex flex-column">
                                <ul class="list-unstyled mb-4">
                                    <li class="mb-3 border-bottom pb-2">
                                        <i class="fas fa-check text-success me-2"></i> 
                                        <b><?= $p['musteri_limiti'] == 0 ? 'Sınırsız' : number_format($p['musteri_limiti']) ?></b> Müşteri/Cari
                                    </li>
                                    <li class="mb-3 border-bottom pb-2">
                                        <i class="fas fa-check text-success me-2"></i> 
                                        <b><?= $p['kullanici_limiti'] == 0 ? 'Sınırsız' : number_format($p['kullanici_limiti']) ?></b> Personel
                                    </li>
                                    <li class="mb-3 border-bottom pb-2">
                                        <i class="fas fa-check text-success me-2"></i> 
                                        <b><?= $p['depolama_limiti'] == 0 ? 'Sınırsız' : number_format($p['depolama_limiti']) ?> MB</b> Depolama
                                    </li>
                                    <li class="mb-3 border-bottom pb-2">
                                        <i class="fas fa-check text-success me-2"></i> 
                                        <b><?= empty($p['sms_limiti']) ? '0' : number_format($p['sms_limiti']) ?></b> Aylık SMS Limiti
                                    </li>
                                    
                                    <?php foreach(ozellikListesi($p['ozellikler']) as $ozellik): ?>
                                        <li class="mb-3 text-muted small"><i class="fas fa-plus text-primary me-2"></i> <?= htmlspecialchars($ozellik) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                
                                <div class="text-center mt-auto">
                                    <a href="odeme.php?tur=paket&id=<?= $p['id'] ?>&ay=1" 
                                       id="btn_paket_<?= $p['id'] ?>"
                                       onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin me-2\'></i>Yönlendiriliyor...'; this.classList.add('disabled');" 
                                       class="btn <?= ($firma['paket_id'] == $p['id']) ? 'btn-outline-primary' : 'btn-primary' ?> w-100 fw-bold rounded-pill py-2">
                                        <?= ($firma['paket_id'] == $p['id']) ? 'Süreyi Uzat / Yenile' : 'Bu Paketi Seç' ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 2. EKSTRA SMS -->
            <div class="tab-pane fade" id="sms" role="tabpanel">
                <div class="row g-3">
                    <div class="col-12 mb-2">
                        <div class="alert alert-success border-0 shadow-sm small">
                            <i class="fas fa-info-circle me-2"></i> Satın aldığınız ek SMS bakiyeleri aylık olarak sıfırlanmaz, <b>siz kullanana kadar hesabınızda kalıcıdır.</b>
                        </div>
                    </div>
                    <?php if(empty($ek_sms)): ?>
                        <div class="col-12 text-center py-4 text-muted">Satışta ek SMS paketi bulunmuyor.</div>
                    <?php endif; ?>
                    
                    <?php foreach($ek_sms as $sms): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="ek-hizmet-card bg-white p-4 text-center d-flex flex-column">
                            <div class="display-6 text-success mb-3"><i class="fas fa-sms"></i></div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($sms['baslik']) ?></h6>
                            <p class="text-muted small mb-3">Hesabınıza <b><?= number_format($sms['deger']) ?> Adet</b> Kontör SMS eklenir.</p>
                            <h4 class="fw-bold text-dark mb-3 mt-auto"><?= number_format($sms['fiyat'], 2) ?> ₺</h4>
                            <a href="odeme.php?tur=ek_hizmet&id=<?= $sms['id'] ?>&ay=1" 
                               onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin me-2\'></i>Yönlendiriliyor...'; this.classList.add('disabled');" 
                               class="btn btn-outline-success fw-bold rounded-pill w-100 mt-auto">HEMEN SATIN AL</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 3. EKSTRA DEPOLAMA -->
            <div class="tab-pane fade" id="depo" role="tabpanel">
                <div class="row g-3">
                    <div class="col-12 mb-2">
                        <div class="alert alert-info border-0 shadow-sm small">
                            <i class="fas fa-hdd me-2"></i> Ek depolama alanları, <b>mevcut aboneliğinizin süresi boyunca</b> paketinize tanımlanır. Süre bitiminde paketinizle birlikte pasifleşir.
                        </div>
                    </div>
                    <?php if(empty($ek_depolama)): ?>
                        <div class="col-12 text-center py-4 text-muted">Satışta ek depolama paketi bulunmuyor.</div>
                    <?php endif; ?>
                    
                    <?php foreach($ek_depolama as $depo): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="ek-hizmet-card bg-white p-4 text-center d-flex flex-column border-info border-opacity-25">
                            <div class="display-6 text-info mb-3"><i class="fas fa-cloud-upload-alt"></i></div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($depo['baslik']) ?></h6>
                            <p class="text-muted small mb-3">Mevcut aboneliğiniz boyunca <b>+ <?= number_format($depo['deger']) ?> MB</b> (<?= round($depo['deger']/1024,1) ?> GB) ek alan.</p>
                            <h4 class="fw-bold text-dark mb-3 mt-auto"><?= number_format($depo['fiyat'], 2) ?> ₺</h4>
                            <a href="odeme.php?tur=ek_hizmet&id=<?= $depo['id'] ?>&ay=1" 
                               onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin me-2\'></i>Yönlendiriliyor...'; this.classList.add('disabled');" 
                               class="btn btn-outline-info fw-bold rounded-pill w-100 mt-auto">HEMEN SATIN AL</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 4. EKSTRA TRAFİK -->
            <div class="tab-pane fade" id="trafik" role="tabpanel">
                <div class="row g-3">
                    <div class="col-12 mb-2">
                        <div class="alert alert-warning text-dark border-0 shadow-sm small">
                            <i class="fas fa-exclamation-triangle me-2"></i> Ekstra Trafik paketleri <b>içinde bulunduğunuz ay sonuna kadar</b> geçerlidir. Her ayın 1'inde trafikler sıfırlanır.
                        </div>
                    </div>
                    <?php if(empty($ek_trafik)): ?>
                        <div class="col-12 text-center py-4 text-muted">Satışta ek trafik paketi bulunmuyor.</div>
                    <?php endif; ?>
                    
                    <?php foreach($ek_trafik as $trafik): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="ek-hizmet-card bg-white p-4 text-center d-flex flex-column border-warning border-opacity-25">
                            <div class="display-6 text-warning mb-3"><i class="fas fa-wifi"></i></div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($trafik['baslik']) ?></h6>
                            <p class="text-muted small mb-3">Bu ay için geçerli <b>+ <?= number_format($trafik['deger']) ?> MB</b> (<?= round($trafik['deger']/1024,1) ?> GB) ek kota.</p>
                            <h4 class="fw-bold text-dark mb-3 mt-auto"><?= number_format($trafik['fiyat'], 2) ?> ₺</h4>
                            <a href="odeme.php?tur=ek_hizmet&id=<?= $trafik['id'] ?>&ay=1" 
                               onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin me-2\'></i>Yönlendiriliyor...'; this.classList.add('disabled');" 
                               class="btn btn-outline-warning text-dark fw-bold rounded-pill w-100 mt-auto">HEMEN SATIN AL</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div> <!-- /Tab Content -->

    </div>

    <?php include 'partials/footer_yonetim.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- İNDİRİM HESAPLAMA SCRİPTİ -->
    <script>
        function degistirSure(ay, indirimYuzde, btnElement) {
            // Butonların aktiflik durumunu değiştir
            document.querySelectorAll('.sure-btn').forEach(btn => {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-light', 'text-dark');
            });
            btnElement.classList.remove('btn-light', 'text-dark');
            btnElement.classList.add('btn-primary');

            // Tüm paket kartlarını gez ve fiyatları güncelle
            document.querySelectorAll('.fiyat-gosterge').forEach(el => {
                let baseFiyat = parseFloat(el.getAttribute('data-base'));
                let paketId = el.id.split('_')[1];
                
                // İndirimsiz toplam ve İndirimli toplam hesapla
                let toplamIndirimsiz = baseFiyat * ay;
                let indirimliTutar = toplamIndirimsiz - (toplamIndirimsiz * (indirimYuzde / 100));

                // Ekrana yazdır
                el.innerText = indirimliTutar.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                let eskiFiyatEl = document.getElementById('eski_fiyat_' + paketId);
                let sureMetinEl = document.getElementById('sure_metin_' + paketId);
                let satinAlBtn = document.getElementById('btn_paket_' + paketId);

                // İndirim varsa üstü çizili fiyatı göster
                if (indirimYuzde > 0) {
                    eskiFiyatEl.style.display = 'inline-block';
                    eskiFiyatEl.innerText = toplamIndirimsiz.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                } else {
                    eskiFiyatEl.style.display = 'none';
                }

                // Alt metni güncelle (Örn: Toplam (3 Aylık))
                sureMetinEl.innerText = 'Toplam (' + ay + ' Aylık)';

                // Satın al butonunun linkindeki ?ay=X değerini güncelle
                let currentHref = satinAlBtn.getAttribute('href');
                currentHref = currentHref.replace(/&ay=\d+/, '&ay=' + ay);
                satinAlBtn.setAttribute('href', currentHref);
            });
        }
    </script>
</body>
</html>