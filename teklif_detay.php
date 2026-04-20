<?php
session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
require_once 'partials/security_check.php';

// Sayfa başlığı
$page_title = "Teklif Detayı";
$firma_id = $_SESSION['firma_id'];

// FİRMA BİLGİLERİNİ ÇEK
$firmaBilgi = $db->prepare("SELECT firma_adi, teklif_sartlari, teklif_alt_bilgi FROM firmalar WHERE id = ?");
$firmaBilgi->execute([$firma_id]);
$firma = $firmaBilgi->fetch(PDO::FETCH_ASSOC);

// TOKEN İLE TEKLİFİ ÇEK
if (isset($_GET['t']) && !empty($_GET['t'])) {
    $token = $_GET['t'];
    $sorgu = $db->prepare("SELECT * FROM teklifler WHERE url_token = ? AND firma_id = ?");
    $sorgu->execute([$token, $firma_id]);
    $teklif = $sorgu->fetch(PDO::FETCH_ASSOC);

    if (!$teklif) { 
        $_SESSION['error_message'] = "Teklif bulunamadı!";
        header("Location: teklifler.php"); 
        exit;
    }
    
    // Kalemleri JSON'dan Çöz
    $kalemler = !empty($teklif['kalemler']) ? json_decode($teklif['kalemler'], true) : [];
} else { 
    header("Location: teklifler.php"); 
    exit; 
}

// ---------------------------------------------------------
// POST İŞLEMLERİ (DURUM GÜNCELLEME VE MÜŞTERİYE ÇEVİRME)
// ---------------------------------------------------------

// 1. DURUM GÜNCELLEME
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['durum_guncelle'])) {
    $yeni_durum = $_POST['yeni_durum'];
    $guncelle = $db->prepare("UPDATE teklifler SET durum=? WHERE id=? AND firma_id=?");
    $guncelle->execute([$yeni_durum, $teklif['id'], $firma_id]);
    
    $_SESSION['success_message'] = "Teklif durumu başarıyla güncellendi!";
    header("Location: teklif_detay.php?t=" . $token); 
    exit;
}

// 2. MÜŞTERİYE ÇEVİRME
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['teklifi_musteriye_cevir'])) {
    $yeni_ad_soyad = $_POST['ad_soyad'];
    $telefon_post = $_POST['telefon'] ?? '';
    $ozel_not = $_POST['ozel_notlar'] ?? '';

    // Firma Adından Kısaltma ve Müşteri No
    $firma_kisa = $_SESSION['firma_adi'] ?? 'Firma'; 
    $kelimeler = explode(" ", $firma_kisa);
    $firmaKodu = "";
    foreach ($kelimeler as $k) { $firmaKodu .= mb_substr($k, 0, 1, "UTF-8"); }
    $firmaKodu = mb_strtoupper($firmaKodu, "UTF-8");
    $firmaKodu = str_replace(['Ç', 'Ğ', 'İ', 'Ö', 'Ş', 'Ü'], ['C', 'G', 'I', 'O', 'S', 'U'], $firmaKodu);
    $firmaKodu = preg_replace('/[^A-Z0-9]/', '', $firmaKodu);
    if (empty($firmaKodu)) { $firmaKodu = "MST"; }

    $sorguSonNo = $db->prepare("SELECT musteri_no FROM musteriler WHERE firma_id = ? ORDER BY id DESC LIMIT 1");
    $sorguSonNo->execute([$firma_id]);
    $sonMusteriNo = $sorguSonNo->fetchColumn();

    $yeniSira = 1;
    if ($sonMusteriNo) {
        $parcalar = explode('-', $sonMusteriNo);
        $yeniSira = intval(end($parcalar)) + 1;
    }
    $gercek_musteri_no = $firmaKodu . "-" . date("Ymd") . "-" . str_pad($yeniSira, 3, '0', STR_PAD_LEFT);

    // Müşteriyi Ekle
    $yeni_token = md5(uniqid(rand(), true));
    $ekleMusteri = $db->prepare("INSERT INTO musteriler (firma_id, url_token, musteri_no, ad_soyad, telefon, ozel_notlar) VALUES (?, ?, ?, ?, ?, ?)");
    $ekleMusteri->execute([$firma_id, $yeni_token, $gercek_musteri_no, $yeni_ad_soyad, $telefon_post, $ozel_not]);
    $yeni_musteri_id = $db->lastInsertId();

    // Hareketleri (Kalemleri) Ekle
    if (is_array($kalemler)) {
        $ekleHareket = $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, odeme_turu, urun_aciklama, adet, birim_fiyat, iskonto_orani, kdv_orani, toplam_tutar, islem_tarihi) VALUES (?, ?, 'satis', 0, ?, ?, ?, 0, 0, ?, ?)");
        $islem_tarihi = date('Y-m-d H:i:s');
        foreach ($kalemler as $k) {
            $ekleHareket->execute([$firma_id, $yeni_musteri_id, $k['aciklama'], $k['adet'], $k['birim_fiyat'], $k['toplam_fiyat'], $islem_tarihi]);
        }
    }

    $_SESSION['success_message'] = "Teklif başarıyla müşteriye dönüştürüldü ve hesabı oluşturuldu!";
    header("Location: musteri_detay.php?t=" . $yeni_token);
    exit;
}
// ---------------------------------------------------------

// AKILLI METİNLER VE SATIR ARASI BOŞLUK TEMİZLEME
$raw_sartlar = !empty($teklif['ozel_sartlar']) ? $teklif['ozel_sartlar'] : ($firma['teklif_sartlari'] ?? '');
// Birden fazla alt satıra geçişleri tek satıra indirger, ardından html olarak çok daha bitişik yazdırabilmek için div'e sararız.
$sartlar_lines = explode("\n", preg_replace("/[\r\n]+/", "\n", trim($raw_sartlar)));
$sartlar_html = "";
foreach($sartlar_lines as $line) {
    $line = trim($line);
    if(!empty($line)) {
        $sartlar_html .= "<div class='sart-satir'>" . htmlspecialchars($line) . "</div>";
    }
}

$gosterilecek_footer = !empty($teklif['ozel_alt_bilgi']) ? $teklif['ozel_alt_bilgi'] : ($firma['teklif_alt_bilgi'] ?? $firma['firma_adi']);

// Durum badge'i için class
$durum_class = '';
$durum_text = '';
switch($teklif['durum']) {
    case 'beklemede':
        $durum_class = 'status-beklemede';
        $durum_text = 'Beklemede';
        break;
    case 'onaylandi':
        $durum_class = 'status-onaylandi';
        $durum_text = 'Onaylandı';
        break;
    case 'reddedildi':
        $durum_class = 'status-reddedildi';
        $durum_text = 'Reddedildi';
        break;
    default:
        $durum_class = 'status-beklemede';
        $durum_text = $teklif['durum'];
}

// Sayfaya özel CSS
$inline_css = '
    .teklif-detay-container {
        background: #f8f9fa;
        min-height: 100vh;
        padding: 20px 0;
    }
    .action-bar {
        background: white;
        border-radius: 8px;
        padding: 15px 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        border: 1px solid #e3e6f0;
        margin: 0 auto 20px auto;
        max-width: 210mm; /* Teklif kağıdıyla aynı genişlik */
    }
    .teklif-kagit {
        background: white;
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        padding: 20mm;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        position: relative;
        border-radius: 8px;
        border: 1px solid #eaeaea;
    }
    .teklif-baslik {
        border-bottom: 3px solid #4e73df;
        padding-bottom: 20px;
        margin-bottom: 30px;
        position: relative;
    }
    .teklif-baslik::after {
        content: "";
        position: absolute;
        bottom: -3px;
        left: 0;
        width: 100px;
        height: 3px;
        background: linear-gradient(90deg, #4e73df, #224abe);
    }
    .status-badge {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 600;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-beklemede { 
        background: linear-gradient(45deg, #f6c23e, #f8d94c);
        color: #856404;
        box-shadow: 0 3px 10px rgba(246, 194, 62, 0.3);
    }
    .status-onaylandi { 
        background: linear-gradient(45deg, #1cc88a, #20c997);
        color: #0f5132;
        box-shadow: 0 3px 10px rgba(28, 200, 138, 0.3);
    }
    .status-reddedildi { 
        background: linear-gradient(45deg, #e74a3b, #f06548);
        color: #fff;
        box-shadow: 0 3px 10px rgba(231, 74, 59, 0.3);
    }
    .table-teklif thead th {
        background: linear-gradient(45deg, #4e73df, #224abe);
        color: white;
        border: none;
        font-weight: 500;
        padding: 15px 10px;
    }
    .table-teklif tbody tr:nth-child(even) {
        background-color: #f8f9fa;
    }
    .table-teklif tbody tr:hover {
        background-color: #e9ecef;
        transition: background-color 0.3s;
    }
    .total-row {
        background: linear-gradient(45deg, #f8f9fa, #e9ecef) !important;
        font-weight: bold;
        border-top: 3px solid #4e73df;
    }
    .footer-not {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 2px dashed #dee2e6;
        font-size: 0.9rem;
        color: #555;
    }
    .sart-satir {
        margin-bottom: 4px;
        line-height: 1.4;
        color: #444;
    }
    .signature-area {
        border-top: 1px solid #dee2e6;
        padding-top: 20px;
        margin-top: 40px;
    }
    .signature-box {
        border: 2px dashed #dee2e6;
        height: 100px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #6c757d;
        font-style: italic;
    }
    .page-break-avoid {
        page-break-inside: avoid !important;
    }
    
    /* YAZDIRMA (PRINT) ÖZEL KODLARI - TEK SAYFAYA SIĞDIRMA */
    @media print {
        @page { size: A4; margin: 10mm; }
        html, body, .yonetim-body, .container-yonetim, .teklif-detay-container, .teklif-kagit { 
            display: block !important;
            height: auto !important;
            min-height: 0 !important;
            position: static !important;
            background: white !important; 
            margin: 0 !important; 
        }
        
        /* GİZLENECEKLER */
        .no-print, .navbar, nav, .mobile-bottom-nav, .app-bottom-nav, #toast-container-yonetim, .print-controls, .action-bar, .fixed-bottom, .bottom-menu, .floating-btn, .fab { 
            display: none !important; 
        }
        
        .container-yonetim { 
            padding: 0 !important; 
            max-width: 100% !important;
        }
        .teklif-detay-container { 
            padding: 0 !important; 
        }
        .teklif-kagit {
            box-shadow: none !important;
            border: none !important;
            padding: 0 !important; /* Marginler @page\'e verildiği için burada padding sıfırladık */
            width: 100% !important;
            max-width: 100% !important;
        }
        
        /* Font ve boşluk küçültmeleri */
        .teklif-baslik { margin-bottom: 10px !important; padding-bottom: 5px !important; }
        .mb-5 { margin-bottom: 10px !important; }
        .mt-4 { margin-top: 8px !important; }
        
        h3 { font-size: 16px !important; margin-bottom: 3px !important; }
        h4 { font-size: 14px !important; margin-bottom: 2px !important; }
        h5 { font-size: 11px !important; margin-bottom: 2px !important; }
        .fs-5 { font-size: 12px !important; }
        .fs-4 { font-size: 13px !important; }
        
        .table-teklif { margin-bottom: 8px !important; }
        .table-teklif th, .table-teklif td { 
            padding: 4px 5px !important; 
            font-size: 10px !important; 
        }
        
        .footer-not { 
            margin-top: 10px !important; 
            padding-top: 8px !important; 
        }
        .footer-not h6 { margin-bottom: 5px !important; font-size: 11px !important; }
        .sart-satir { margin-bottom: 2px !important; line-height: 1.2 !important; font-size: 9.5px !important; color: #000 !important; }
        
        .signature-area { 
            margin-top: 10px !important; 
            padding-top: 8px !important; 
        }
        .signature-box { 
            height: 45px !important;
            font-size: 9px !important; 
        }
        .invoice-footer {
            margin-top: 10px !important;
            padding-top: 5px !important;
            font-size: 9px !important;
        }
        .table-teklif thead th {
            background: #4e73df !important;
            color: white !important;
            -webkit-print-color-adjust: exact;
            color-adjust: exact;
        }
    }
    
    @media (max-width: 768px) {
        .teklif-kagit {
            width: 95%;
            padding: 15px;
            margin: 10px auto;
        }
        .action-bar {
            flex-direction: column;
            gap: 15px;
        }
    }
';

// Sayfaya özel JS
$inline_js = '
    function changeStatus(newStatus) {
        if (confirm("Teklif durumunu değiştirmek istediğinize emin misiniz?")) {
            document.getElementById("yeni_durum_input").value = newStatus;
            document.getElementById("statusForm").submit();
        }
    }

    function printTeklif() {
        window.print();
    }
    
    function downloadPDF() {
        showToast("PDF oluşturmak için yazdırma ekranında hedefi \'PDF Olarak Kaydet\' seçin.", "info");
        setTimeout(() => { window.print(); }, 2000);
    }
    
    function shareTeklif() {
        const url = window.location.href;
        if (navigator.share) {
            navigator.share({
                title: "Teklif: <?php echo addslashes($teklif[\'musteri_adi\']); ?>",
                text: "<?php echo addslashes($teklif[\'konu_baslik\']); ?> konulu teklifimiz.",
                url: url
            });
        } else {
            navigator.clipboard.writeText(url).then(() => {
                showToast("Link panoya kopyalandı!", "success");
            });
        }
    }
    
    function sendWhatsApp() {
        let wp_no = "<?php echo preg_replace(\'/[^0-9]/\', \'\', $teklif[\'telefon\'] ?? \'\'); ?>";
        if(wp_no.length > 0) {
            if(wp_no.charAt(0) === \'0\') wp_no = \'9\' + wp_no;
            else if(wp_no.length === 10) wp_no = \'90\' + wp_no;
        }
        
        const tutar = "<?php echo number_format($teklif[\'toplam_tutar\'], 2, \',\', \'.\'); ?>";
        const konu = "<?php echo addslashes($teklif[\'konu_baslik\']); ?>";
        const musteri = "<?php echo addslashes($teklif[\'musteri_adi\']); ?>";
        const firma = "<?php echo addslashes($firma[\'firma_adi\']); ?>";
        
        let text = "Sayın " + musteri + ",\\n*" + konu + "* konulu *" + tutar + " TL* tutarındaki teklifiniz hazırlanmıştır.\\n\\nFirma: " + firma;
        
        let url = "https://wa.me/";
        if(wp_no) url += wp_no;
        url += "?text=" + encodeURIComponent(text);
        
        window.open(url, \'_blank\');
    }
    
    function sendEmail() {
        const subject = encodeURIComponent("Teklif: <?php echo addslashes($teklif[\'konu_baslik\']); ?>");
        const body = encodeURIComponent("Sayın <?php echo addslashes($teklif[\'musteri_adi\']); ?>,\\n\\n<?php echo addslashes($teklif[\'konu_baslik\']); ?> konulu teklifimiz ektedir (veya PDF olarak indirebilirsiniz).\\n\\nSaygılarımızla,\\n<?php echo addslashes($firma[\'firma_adi\']); ?>");
        window.location.href = "mailto:?subject=" + subject + "&body=" + body;
    }
    
    function openMusteriyeCevirModal() {
        const modal = new bootstrap.Modal(document.getElementById("modalMusteriyeCevir"));
        modal.show();
    }
';

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title . " - " . htmlspecialchars($teklif['musteri_adi']); ?> | <?php echo htmlspecialchars($firma['firma_adi']); ?></title>
    
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        <?php echo $inline_css; ?>
    </style>
</head>
<body class="yonetim-body">

<?php include 'partials/navbar.php'; ?>

<!-- GİZLİ FORM: Durum Güncelleme İçin -->
<form method="POST" id="statusForm" style="display: none;">
    <input type="hidden" name="durum_guncelle" value="1">
    <input type="hidden" name="yeni_durum" id="yeni_durum_input" value="">
</form>

<div class="container-yonetim pb-5">
    
    <!-- Üst Bilgi ve Navigasyon -->
    <div class="row mb-4 align-items-center mt-3 no-print">
        <div class="col-md-6">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="teklifler.php"><i class="fas fa-file-signature me-1"></i>Teklifler</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Teklif Detayı</li>
                </ol>
            </nav>
            <h3 class="text-secondary mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Teklif Detayı</h3>
            <p class="text-muted mb-0">#<?php echo $teklif['id']; ?> • <?php echo date("d.m.Y", strtotime($teklif['tarih'])); ?></p>
        </div>
        <div class="col-md-6 text-end">
            <a href="teklifler.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-2"></i>Listeye Dön
            </a>
            <a href="teklif_olustur.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Yeni Teklif
            </a>
        </div>
    </div>
    
    <div class="teklif-detay-container pt-0">
        
        <!-- AKSİYON BAR (Teklifin hemen üstünde) -->
        <div class="action-bar no-print d-flex justify-content-between align-items-center">
            <div>
                <span class="text-muted small fw-bold me-2"><i class="fas fa-exchange-alt me-1"></i>DURUM:</span>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-warning <?php echo $teklif['durum'] == 'beklemede' ? 'active' : ''; ?>" onclick="changeStatus('beklemede')" title="Beklemede Yap">
                        <i class="fas fa-clock"></i>
                    </button>
                    <button type="button" class="btn btn-outline-success <?php echo $teklif['durum'] == 'onaylandi' ? 'active' : ''; ?>" onclick="changeStatus('onaylandi')" title="Onaylandı Yap">
                        <i class="fas fa-check"></i>
                    </button>
                    <button type="button" class="btn btn-outline-danger <?php echo $teklif['durum'] == 'reddedildi' ? 'active' : ''; ?>" onclick="changeStatus('reddedildi')" title="Reddedildi Yap">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <?php if($teklif['durum'] == 'onaylandi'): ?>
                <button class="btn btn-sm btn-primary ms-3" onclick="openMusteriyeCevirModal()">
                    <i class="fas fa-user-plus me-1"></i> Müşteriye Çevir
                </button>
                <?php endif; ?>
            </div>
            
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-success" onclick="sendWhatsApp()"><i class="fab fa-whatsapp me-1"></i> WP Gönder</button>
                <button class="btn btn-sm btn-info text-white" onclick="shareTeklif()"><i class="fas fa-share-alt"></i></button>
                <button class="btn btn-sm btn-secondary" onclick="sendEmail()"><i class="fas fa-envelope"></i></button>
                <button class="btn btn-sm btn-danger" onclick="downloadPDF()"><i class="fas fa-file-pdf"></i></button>
                <button class="btn btn-sm btn-dark" onclick="printTeklif()"><i class="fas fa-print me-1"></i> Yazdır</button>
            </div>
        </div>
        
        <!-- TEKLİF KAĞIDI -->
        <div class="teklif-kagit">
            
            <!-- Üst Başlık -->
            <div class="row teklif-baslik align-items-center">
                <div class="col-8">
                    <h4 class="fw-bold mb-0 text-uppercase text-primary"><?php echo htmlspecialchars($firma['firma_adi']); ?></h4>
                    <small class="text-muted">Hizmet Fiyat Teklifi</small>
                </div>
                <div class="col-4 text-end">
                    <h5 class="mb-1 text-primary">TEKLİF TARİHİ</h5>
                    <div class="fw-bold"><?php echo date("d.m.Y", strtotime($teklif['tarih'])); ?></div>
                    <small class="text-muted">Geçerlilik: <?php echo $teklif['gecerlilik_gun']; ?> gün</small>
                </div>
            </div>

            <!-- Durum Banneri SADECE EKRANDA GÖRÜNÜR -->
            <div class="row mb-4 no-print">
                <div class="col-12 text-end">
                    <span class="status-badge <?php echo $durum_class; ?>">
                        <i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i> <?php echo $durum_text; ?>
                    </span>
                </div>
            </div>

            <!-- Müşteri Bilgileri -->
            <div class="mb-5">
                <div class="mb-3">
                    <small class="text-muted text-uppercase fw-bold">SAYIN</small>
                    <h3 class="fw-bold text-dark"><?php echo htmlspecialchars($teklif['musteri_adi']); ?></h3>
                    <?php if(!empty($teklif['telefon'])): ?>
                        <div class="text-muted"><i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($teklif['telefon']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="mt-4">
                    <small class="text-muted fw-bold">TEKLİF KONUSU</small>
                    <div class="text-muted fst-italic fs-5"><?php echo htmlspecialchars($teklif['konu_baslik']); ?></div>
                </div>
            </div>

            <!-- Kalemler Tablosu -->
            <div class="table-responsive mb-4">
                <table class="table table-teklif table-bordered">
                    <thead>
                        <tr>
                            <th>HİZMET / ÜRÜN AÇIKLAMASI</th>
                            <th class="text-center" width="100">MİKTAR</th>
                            <th class="text-end" width="150">BİRİM FİYAT (₺)</th>
                            <th class="text-end" width="150">TUTAR (₺)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $alt_toplam = 0;
                        foreach($kalemler as $k): 
                            $alt_toplam += $k['toplam_fiyat'];
                        ?>
                        <tr>
                            <td class="fw-bold"><?php echo htmlspecialchars($k['aciklama']); ?></td>
                            <td class="text-center"><?php echo $k['adet']; ?></td>
                            <td class="text-end"><?php echo number_format($k['birim_fiyat'], 2, ',', '.'); ?> ₺</td>
                            <td class="text-end fw-bold"><?php echo number_format($k['toplam_fiyat'], 2, ',', '.'); ?> ₺</td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Toplam Satırı -->
                        <tr class="total-row">
                            <td colspan="3" class="text-end fw-bold fs-5">GENEL TOPLAM:</td>
                            <td class="text-end fw-bold fs-4 text-primary"><?php echo number_format($teklif['toplam_tutar'], 2, ',', '.'); ?> ₺</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Sayfa Sonuna Sabitlenen, Kopmayan Bölüm -->
            <div class="page-break-avoid">
                <!-- Teklif Şartları -->
                <div class="footer-not">
                    <h6 class="fw-bold text-uppercase text-primary mb-2">
                        <i class="fas fa-file-contract me-2"></i>TEKLİF ŞARTLARI
                    </h6>
                    <div class="ps-2"><?php echo $sartlar_html; ?></div>
                </div>
                
                <!-- İmza Alanları -->
                <div class="signature-area mt-4">
                    <div class="row">
                        <div class="col-6">
                            <p class="fw-bold text-center mb-3">HAZIRLAYAN</p>
                            <div class="signature-box text-center">
                                <span><?php echo htmlspecialchars($_SESSION['kullanici_adi'] ?? ''); ?><br>
                                <small class="fw-bold text-dark"><?php echo htmlspecialchars($firma['firma_adi']); ?></small></span>
                            </div>
                        </div>
                        <div class="col-6">
                            <p class="fw-bold text-center mb-3">MÜŞTERİ ONAYI</p>
                            <div class="signature-box text-center">
                                <span>İmza / Mühür<br>
                                <small>Tarih: _________________</small></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer (Teklif No vb. kaldırıldı, sadece özel alt bilgi kaldı) -->
                <footer class="text-center mt-4 pt-3 invoice-footer border-0">
                    <span class="text-muted fw-bold"><?php echo htmlspecialchars($gosterilecek_footer); ?></span>
                </footer>
            </div>
            
        </div>
    </div>
</div>

<!-- MODAL: MÜŞTERİYE ÇEVİR -->
<div class="modal fade" id="modalMusteriyeCevir" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="teklifi_musteriye_cevir" value="1">
                <input type="hidden" name="teklif_id" value="<?php echo $teklif['id']; ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Teklifi Müşteriye Çevir</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="alert alert-info small mb-4">
                        <i class="fas fa-info-circle me-1"></i> Bu teklifteki hizmetler otomatik olarak bu müşteriye <strong>işlem (borç)</strong> olarak eklenecektir.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Müşteri / Firma Adı</label>
                        <input type="text" name="ad_soyad" class="form-control" value="<?php echo htmlspecialchars($teklif['musteri_adi']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Telefon Numarası <span class="text-secondary fw-normal">(Opsiyonel)</span></label>
                        <input type="text" name="telefon" class="form-control" value="<?php echo htmlspecialchars($teklif['telefon'] ?? ''); ?>" placeholder="0555...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Özel Not <span class="text-secondary fw-normal">(Opsiyonel)</span></label>
                        <textarea name="ozel_notlar" class="form-control" rows="2">Teklif Konusu: <?php echo htmlspecialchars($teklif['konu_baslik']); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4"><i class="fas fa-check me-1"></i> Müşteriyi Oluştur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toast-container-yonetim"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/yonetim.js"></script>
<script>
    <?php echo $inline_js; ?>
    
    document.addEventListener("DOMContentLoaded", function() {
        <?php if(isset($_SESSION['success_message'])): ?>
            if(typeof showYonetimToast === 'function') showYonetimToast("<?php echo addslashes($_SESSION['success_message']); ?>", "success");
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
    });
</script>

<?php require_once 'partials/footer_yonetim.php'; ?>
</body>
</html>