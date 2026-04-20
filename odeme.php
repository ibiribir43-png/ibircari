<?php
// Geliştirme aşaması için hataları göster
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['kullanici_id'];
$firma_id = $_SESSION['firma_id'];

// --- SİSTEM AYARLARINI ÇEK ---
$ayarlar_sorgu = $db->query("SELECT ayar_adi, ayar_degeri FROM sistem_ayarlari")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- PAPARA API BİLGİLERİ (Veritabanından) ---
$papara_api_key = trim($ayarlar_sorgu['papara_api_key'] ?? ''); 
$is_test_mode = ($ayarlar_sorgu['papara_test_mode'] ?? '1') == '1';
$site_url = rtrim($ayarlar_sorgu['site_url'] ?? "https://" . $_SERVER['HTTP_HOST'], '/'); 

// Papara VPOS API Base URL'leri 
$papara_base_url = $is_test_mode ? "https://merchant-api.test.papara.com" : "https://merchant-api.papara.com";

// Firma, Mevcut Paket ve Cüzdan bilgilerini çek
$firma = $db->query("
    SELECT f.*, p.fiyat as mevcut_paket_fiyat 
    FROM firmalar f 
    LEFT JOIN paketler p ON f.paket_id = p.id 
    WHERE f.id='$firma_id'
")->fetch(PDO::FETCH_ASSOC);

// 2. GELEN VERİLERİ AL (GET veya POST)
$tur = $_REQUEST['tur'] ?? ''; 
$satin_alinan_id = (int)($_REQUEST['id'] ?? 0);
$ay = (int)($_REQUEST['ay'] ?? 1); 

// Güvenlik: Sadece izin verilen aylar gelsin
if (!in_array($ay, [1, 3, 6, 12])) {
    $ay = 1;
}

if (!$tur || !$satin_alinan_id) {
    die("Geçersiz işlem. Lütfen mağazaya dönüp tekrar deneyin.");
}

// --- FİYAT VE İNDİRİM HESAPLAMALARI ---
$urun_adi = ""; 
$birim_fiyat = 0; 
$indirim_orani = 0;

// İndirim Oranları Matematiği (Burayı mağaza ile aynı oranda ayarlıyoruz)
if ($ay == 3) $indirim_orani = 10;
elseif ($ay == 6) $indirim_orani = 15;
elseif ($ay == 12) $indirim_orani = 25;

// 3. ÜRÜN BİLGİLERİNİ ÇEK
if ($tur == 'paket') {
    $paket = $db->query("SELECT * FROM paketler WHERE id = $satin_alinan_id AND durum = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$paket) die("Paket bulunamadı veya pasif durumda.");
    // Ekranda müşteriye kaç aylık aldığını göster
    $urun_adi = "Abonelik: " . $paket['paket_adi'] . " ($ay Aylık)";
    $birim_fiyat = $paket['fiyat'];
} elseif ($tur == 'ek_hizmet') {
    $hizmet = $db->query("SELECT * FROM ek_hizmetler WHERE id = $satin_alinan_id AND durum = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$hizmet) die("Ek hizmet bulunamadı veya pasif durumda.");
    // Ek hizmetlerde süre 1 ay olarak gelir, fiyatları katlanmaz.
    $urun_adi = "Ek Hizmet: " . $hizmet['baslik'];
    $birim_fiyat = $hizmet['fiyat'];
    $ay = 1; 
    $indirim_orani = 0;
} else {
    die("Geçersiz işlem türü.");
}

// Tutar Hesaplama (Birim Fiyat * Ay - İndirim)
$indirimsiz_toplam = $birim_fiyat * $ay;
$indirim_tutari = $indirimsiz_toplam * ($indirim_orani / 100);
$paket_fiyati_indirimli = $indirimsiz_toplam - $indirim_tutari;

// --- 🚀 SAAS FINANS & PRORATION MATEMATİĞİ ---
$is_upgrade = ($tur == 'paket' && $firma['paket_id'] != $satin_alinan_id && $firma['paket_id'] > 0);
$kalan_gun_iadesi = 0;
$cuzdana_gidecek_bakiye = 0;
$cuzdandan_kullanilan = 0;
$cuzdan_mevcut = (float)($firma['cuzdan_bakiyesi'] ?? 0);

if ($is_upgrade && !empty($firma['abonelik_bitis']) && strtotime($firma['abonelik_bitis']) > time()) {
    $kalan_gun = floor((strtotime($firma['abonelik_bitis']) - time()) / 86400);
    $gunluk_ucret = $firma['mevcut_paket_fiyat'] / 30;
    $kalan_gun_iadesi = round($kalan_gun * $gunluk_ucret, 2);
}

// Net Ödenecek Hesaplama
$ara_toplam = $paket_fiyati_indirimli - $kalan_gun_iadesi;

if ($ara_toplam < 0) {
    // Yeni paket eskisinden ucuzsa (Downgrade), artan para cüzdana eklenecek
    $cuzdana_gidecek_bakiye = abs($ara_toplam);
    $ara_toplam = 0;
}

// Eğer adamın cüzdanında bakiye varsa, otomatik faturadan düş
if ($ara_toplam > 0 && $cuzdan_mevcut > 0) {
    if ($cuzdan_mevcut >= $ara_toplam) {
        $cuzdandan_kullanilan = $ara_toplam;
        $ara_toplam = 0;
    } else {
        $cuzdandan_kullanilan = $cuzdan_mevcut;
        $ara_toplam -= $cuzdan_mevcut;
    }
}

$net_odenecek = round($ara_toplam, 2);
$fiyat = $net_odenecek; // Taksit hesaplamaları vs için

// Veritabanına kaydederken Papara Webhook'un süreyi anlaması için Tip ve Ayı birleştiriyoruz
$kayit_tipi = $tur . '-' . $ay;

// =========================================================================
// AJAX: KART BİLGİSİ GİRİLDİKÇE TAKSİT SEÇENEKLERİNİ PAPARA'DAN GETİR
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['islem']) && $_POST['islem'] == 'taksit_getir') {
    header('Content-Type: application/json');
    $bin = substr(str_replace([' ', '-'], '', $_POST['bin'] ?? ''), 0, 8);
    
    if (strlen($bin) >= 6) {
        
        /* -----------------------------------------------------------------
           DEMO MODU: FAKE (SAHTE) TAKSİT VERİSİ (Arayüzü test etmek için)
           ----------------------------------------------------------------- */
        $fake_response = [
            "succeeded" => true,
            "data" => [
                "cardBrandName" => "Bonus",
                "cardBrandLogoUrl" => "https://cdn.papara.com/pf/card_brands/bonus.svg",
                "cardNetwork" => "Visa",
                "installmentDetails" => [
                    [
                        "installment" => 1,
                        "installmentAmount" => (float)$fiyat,
                        "finalAmount" => (float)$fiyat,
                        "isInterestChargeApplied" => false
                    ],
                    [
                        "installment" => 3,
                        "installmentAmount" => round((float)$fiyat * 1.05 / 3, 2),
                        "finalAmount" => round((float)$fiyat * 1.05, 2),
                        "isInterestChargeApplied" => true
                    ],
                    [
                        "installment" => 6,
                        "installmentAmount" => round((float)$fiyat * 1.10 / 6, 2),
                        "finalAmount" => round((float)$fiyat * 1.10, 2),
                        "isInterestChargeApplied" => true
                    ]
                ]
            ]
        ];
        echo json_encode($fake_response);
        exit;
        
        /* GERÇEK PAPARA KODLARI (Sanal POS onaylanınca burayı açarsın)
        $req_data = ["OrderId" => "BIN-".time(), "Amount" => (float)$fiyat, "Currency" => "TRY", "CardBin" => $bin];
        $ch_bin = curl_init();
        curl_setopt($ch_bin, CURLOPT_URL, $papara_base_url . "/v1/vpos/installment-options");
        curl_setopt($ch_bin, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch_bin, CURLOPT_POST, 1);
        curl_setopt($ch_bin, CURLOPT_POSTFIELDS, json_encode($req_data));
        curl_setopt($ch_bin, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); 
        curl_setopt($ch_bin, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch_bin, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch_bin, CURLOPT_HTTPHEADER, array('ApiKey: ' . $papara_api_key, 'Content-Type: application/json', 'Accept: application/json'));
        $bin_result = curl_exec($ch_bin);
        curl_close($ch_bin);
        echo $bin_result; 
        exit;
        */
    }
    echo json_encode(['error' => 'Geçersiz BIN numarası.']);
    exit;
}
// =========================================================================

$api_hatasi = ""; 

// =========================================================================
// ASIL ÖDEME İŞLEMİ (POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['islem_onay'])) {
    
    $siparis_kodu = "IBR-" . time() . rand(100, 999); 
    
    // Tutar var ama form yollanmışsa (0 TL değilse CC bilgilerini al)
    if ($net_odenecek > 0) {
        $cc_isim = trim($_POST['cc_isim']);
        $cc_no = str_replace([' ', '-'], '', $_POST['cc_no']); 
        $cc_ay = str_pad($_POST['cc_ay'], 2, "0", STR_PAD_LEFT);
        $cc_yil = str_pad($_POST['cc_yil'], 4, "20", STR_PAD_LEFT);
        $cc_cvv = trim($_POST['cc_cvv']);
        $installment = isset($_POST['taksit']) ? (int)$_POST['taksit'] : 1;
        $final_amount = isset($_POST['final_amount']) ? (float)$_POST['final_amount'] : (float)$net_odenecek;
    } else {
        // Tutar 0 TL ise kredi kartı gerekmez
        $installment = 1;
        $final_amount = 0;
        $cc_no = ""; // Boş gönderilir
    }

    // Veritabanına yaz
    $stmt = $db->prepare("INSERT INTO abonelik_siparisleri (siparis_kodu, firma_id, kullanici_id, satin_alinan_tip, satin_alinan_id, tutar, durum, kullanilan_cuzdan, iade_edilen_tutar) VALUES (?, ?, ?, ?, ?, ?, 'bekliyor', ?, ?)");
    $stmt->execute([$siparis_kodu, $firma_id, $user_id, $kayit_tipi, $satin_alinan_id, $final_amount, $cuzdandan_kullanilan, $kalan_gun_iadesi]);
    
    /* -----------------------------------------------------------------
       DEMO MODU VEYA SIFIR TUTAR ONAYI (Webhook'a gitmeden)
       ----------------------------------------------------------------- */
    try {
        $db->beginTransaction();

        $pos_islem_id = "DEMO-POS-" . rand(100000, 999999); 
        
        // Siparişi anında "basarili" yap
        $db->query("UPDATE abonelik_siparisleri SET durum = 'basarili', pos_islem_id = '$pos_islem_id', tamamlanma_tarihi = NOW() WHERE siparis_kodu = '$siparis_kodu'");

        // Cüzdan kullanıldıysa düş, eklenecek varsa ekle
        if ($cuzdandan_kullanilan > 0) {
            $db->query("UPDATE firmalar SET cuzdan_bakiyesi = cuzdan_bakiyesi - $cuzdandan_kullanilan WHERE id = '$firma_id'");
        }
        if ($cuzdana_gidecek_bakiye > 0) {
            $db->query("UPDATE firmalar SET cuzdan_bakiyesi = cuzdan_bakiyesi + $cuzdana_gidecek_bakiye WHERE id = '$firma_id'");
        }

        $tip_parcalari = explode('-', $kayit_tipi);
        $tip = $tip_parcalari[0]; 
        $alinan_ay = isset($tip_parcalari[1]) ? (int)$tip_parcalari[1] : 1;

        if ($tip == 'paket' || $tip == 'ana_paket') {
            $paket = $db->query("SELECT * FROM paketler WHERE id = $satin_alinan_id")->fetch(PDO::FETCH_ASSOC);
            if ($paket) {
                $aylik_sms = (int)$paket['sms_limiti'];
                $eklenecek_gun = $alinan_ay * 30; 
                
                // Upgrade ise bitiş tarihi eskinin üstüne DEĞİL, bugünden itibaren eklenir
                if ($is_upgrade) {
                    $yeni_bitis = "DATE_ADD(CURDATE(), INTERVAL $eklenecek_gun DAY)"; 
                } else {
                    // Renewal ise eskinin üstüne eklenir
                    if (!empty($firma['abonelik_bitis']) && strtotime($firma['abonelik_bitis']) > time()) {
                        $yeni_bitis = "DATE_ADD('{$firma['abonelik_bitis']}', INTERVAL $eklenecek_gun DAY)";
                    } else {
                        $yeni_bitis = "DATE_ADD(CURDATE(), INTERVAL $eklenecek_gun DAY)"; 
                    }
                }
                
                $db->query("UPDATE firmalar SET 
                            paket_id = $satin_alinan_id, 
                            aylik_sms_limiti = $aylik_sms,
                            abonelik_bitis = $yeni_bitis,
                            son_abonelik_baslangic = CURDATE()
                            WHERE id = '$firma_id'");
            }
        } elseif ($tip == 'ek_hizmet') {
            $hizmet = $db->query("SELECT * FROM ek_hizmetler WHERE id = $satin_alinan_id")->fetch(PDO::FETCH_ASSOC);
            if ($hizmet) {
                $deger = (int)$hizmet['deger'];
                if ($hizmet['tip'] == 'sms') {
                    $db->query("UPDATE firmalar SET ek_sms_bakiyesi = ek_sms_bakiyesi + $deger WHERE id = '$firma_id'");
                } elseif ($hizmet['tip'] == 'depolama') {
                    $db->query("UPDATE firmalar SET ek_depolama_alani = ek_depolama_alani + $deger WHERE id = '$firma_id'");
                } elseif ($hizmet['tip'] == 'trafik') {
                    $db->query("UPDATE firmalar SET ek_trafik_limiti = ek_trafik_limiti + $deger WHERE id = '$firma_id'");
                }
            }
        }

        $db->commit();
        
        header("Location: odeme_sonuc.php?siparis=" . $siparis_kodu);
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        $api_hatasi = "İşlem tamamlanamadı. " . $e->getMessage();
    }
    /* ----------------------------------------------------------------- */

    /* === GERÇEK PAPARA KODLARI (Sadece $net_odenecek > 0 ise devreye girecek şekilde yoruma alındı) ===
    if ($net_odenecek > 0) {
        $client_ip = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];

        $request_data = [
            "OrderId" => $siparis_kodu,
            "Amount" => (float)$net_odenecek,
            "FinalAmount" => (float)$final_amount,
            "Currency" => "TRY",
            "Installment" => $installment,
            "CardNumber" => $cc_no,
            "ExpireYear" => $cc_yil,
            "ExpireMonth" => $cc_ay,
            "Cvv" => $cc_cvv,
            "CardHolderName" => $cc_isim,
            "ClientIP" => $client_ip,
            "CallbackUrl" => $site_url . "/odeme_sonuc.php?siparis=" . $siparis_kodu, 
            "NotificationUrl" => $site_url . "/papara_callback.php", 
            "FailNotificationUrl" => $site_url . "/odeme_sonuc.php?siparis=" . $siparis_kodu
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $papara_base_url . "/v1/vpos/3dsecure"); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); 

        curl_setopt($ch, CURLOPT_HTTPHEADER, array('ApiKey: ' . $papara_api_key, 'Content-Type: application/json', 'Accept: application/json'));

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            $api_hatasi = "Sunucu Hatası: Papara servisine ulaşılamadı. <br><b>cURL Hatası:</b> " . htmlspecialchars($curl_error);
        } else {
            $response = json_decode($result, true);
            if ($http_status == 200 && isset($response['succeeded']) && $response['succeeded'] === true) {
                if (!empty($response['data'])) {
                    echo $response['data'];
                    exit;
                } else {
                    $api_hatasi = "3D Secure sayfası Papara'dan alınamadı.";
                }
            } else {
                $hata_mesaj = $response['error']['message'] ?? 'Bilinmeyen bir hata oluştu.';
                $hata_kodu = $response['error']['code'] ?? $http_status;
                $api_hatasi = "<b>Ödeme Reddedildi (Hata $hata_kodu):</b> " . htmlspecialchars($hata_mesaj);
            }
        }
    }
    === GERÇEK PAPARA KODLARI BİTİŞİ === */
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fatura Özeti & Güvenli Ödeme (Demo)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .payment-card {
            max-width: 600px;
            margin: 40px auto;
            background: #fff;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-top: 5px solid #0d6efd;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
            border-color: #0d6efd;
        }
        .receipt-box {
            background-color: #f8f9fa;
            border: 1px dashed #dee2e6;
            border-radius: 10px;
            padding: 20px;
            font-family: monospace;
            font-size: 14px;
        }
        .receipt-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .receipt-total {
            font-size: 18px;
            font-weight: bold;
            border-top: 1px dashed #dee2e6;
            padding-top: 10px;
            margin-top: 10px;
            color: #0d6efd;
        }
        .bank-logo-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .demo-badge {
            background-color: #ffc107;
            color: #000;
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            margin-bottom: 15px;
            display: inline-block;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="payment-card">
        
        <div class="text-center">
            <div class="demo-badge"><i class="fas fa-flask me-1"></i> DEMO MODU AKTİF - Karttan Ücret Çekilmez</div>
        </div>

        <div class="text-center mb-4">
            <h4 class="fw-bold text-dark"><i class="fas fa-file-invoice-dollar text-primary me-2"></i> Fatura Özeti</h4>
            <p class="text-muted small">Alacağınız hizmetlerin ve uygulanan indirimlerin dökümü.</p>
        </div>

        <?php if(!empty($api_hatasi)): ?>
            <div class="alert alert-danger shadow-sm small py-2 mb-4">
                <i class="fas fa-exclamation-circle me-1"></i> <?= $api_hatasi ?>
            </div>
        <?php endif; ?>

        <!-- ŞEFFAF FATURA DÖKÜMÜ -->
        <div class="receipt-box mb-4">
            <div class="receipt-line">
                <span><?= htmlspecialchars($urun_adi) ?></span>
                <span><?= number_format($indirimsiz_toplam, 2) ?> ₺</span>
            </div>
            
            <?php if($indirim_orani > 0): ?>
            <div class="receipt-line text-success">
                <span><i class="fas fa-tags me-1"></i> Çoklu Ay İndirimi (%<?= $indirim_orani ?>)</span>
                <span>- <?= number_format($indirim_tutari, 2) ?> ₺</span>
            </div>
            <?php endif; ?>
            
            <?php if($kalan_gun_iadesi > 0): ?>
            <div class="receipt-line text-success">
                <span><i class="fas fa-undo me-1"></i> Mevcut Paket İadesi (<?= $kalan_gun ?> Gün)</span>
                <span>- <?= number_format($kalan_gun_iadesi, 2) ?> ₺</span>
            </div>
            <?php endif; ?>

            <?php if($cuzdandan_kullanilan > 0): ?>
            <div class="receipt-line text-info">
                <span><i class="fas fa-wallet me-1"></i> Cüzdan Bakiyesi Kullanımı</span>
                <span>- <?= number_format($cuzdandan_kullanilan, 2) ?> ₺</span>
            </div>
            <?php endif; ?>

            <?php if($cuzdana_gidecek_bakiye > 0): ?>
            <div class="receipt-line text-warning fw-bold mt-2 pt-2 border-top border-warning">
                <span><i class="fas fa-gift me-1"></i> Cüzdanınıza Eklenecek İade Farkı</span>
                <span>+ <?= number_format($cuzdana_gidecek_bakiye, 2) ?> ₺</span>
            </div>
            <?php endif; ?>

            <div class="receipt-line receipt-total">
                <span>NET ÖDENECEK TUTAR</span>
                <span><?= number_format($net_odenecek, 2) ?> ₺</span>
            </div>
        </div>

        <!-- KREDİ KARTI FORMU / ONAY BUTONU -->
        <form method="POST" id="paymentForm">
            <input type="hidden" name="islem_onay" value="1">
            <input type="hidden" name="tur" value="<?= htmlspecialchars($tur) ?>">
            <input type="hidden" name="id" value="<?= htmlspecialchars($satin_alinan_id) ?>">
            <input type="hidden" name="ay" value="<?= $ay ?>">
            
            <input type="hidden" name="final_amount" id="final_amount" value="<?= $net_odenecek ?>">

            <?php if($net_odenecek <= 0): ?>
                <!-- TUTAR SIFIRSA KART İSTEME DİREKT ONAYLA -->
                <div class="alert alert-success text-center py-3 fw-bold border-0 shadow-sm">
                    <i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>
                    Bu işlem için ekstra bir ödeme yapmanız gerekmemektedir. Mevcut iade krediniz veya cüzdan bakiyeniz yeterlidir.
                </div>
                <button type="submit" class="btn btn-success w-100 py-3 fw-bold shadow-sm rounded-pill" onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin me-2\'></i>Onaylanıyor...';">
                    <i class="fas fa-bolt me-2"></i> Ücretsiz Olarak Yükselt
                </button>
            <?php else: ?>
                <!-- TUTAR VARSA KREDİ KARTI FORMU ÇIKAR -->
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Kart Üzerindeki İsim Soyisim</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fas fa-user text-muted"></i></span>
                        <input type="text" name="cc_isim" class="form-control" placeholder="Örn: AHMET YILMAZ" required autocomplete="cc-name">
                    </div>
                </div>

                <div class="mb-3">
                    <div class="bank-logo-container mb-1">
                        <label class="form-label fw-bold small text-muted mb-0">Kredi/Banka Kartı Numarası</label>
                        <img id="banka_logo" src="" alt="Banka" style="height: 20px; display: none;">
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i id="card_icon" class="far fa-credit-card text-muted"></i></span>
                        <input type="text" name="cc_no" id="cc_no" class="form-control" placeholder="0000 0000 0000 0000" maxlength="19" required autocomplete="cc-number">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold small text-muted">Son Kullanma Tarihi</label>
                        <div class="d-flex gap-2">
                            <select name="cc_ay" class="form-select" required autocomplete="cc-exp-month">
                                <option value="">Ay</option>
                                <?php for($i=1; $i<=12; $i++): ?>
                                    <option value="<?= $i ?>"><?= str_pad($i, 2, '0', STR_PAD_LEFT) ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="cc_yil" class="form-select" required autocomplete="cc-exp-year">
                                <option value="">Yıl</option>
                                <?php $currentYear = date('y'); for($i=$currentYear; $i<=$currentYear+15; $i++): ?>
                                    <option value="<?= "20".$i ?>"><?= "20".$i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small text-muted">Güvenlik Kodu (CVV)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-lock text-muted"></i></span>
                            <input type="password" name="cc_cvv" class="form-control" placeholder="***" maxlength="4" required autocomplete="cc-csc">
                        </div>
                    </div>
                </div>

                <!-- DİNAMİK TAKSİT ALANI -->
                <div class="mb-4" id="taksit_alani" style="display: none; background: #eef2f7; padding: 15px; border-radius: 8px; border: 1px solid #dce4ec;">
                    <label class="form-label fw-bold small text-primary mb-2"><i class="fas fa-percentage me-1"></i> Taksit Seçenekleri</label>
                    <select name="taksit" id="taksit_secimi" class="form-select shadow-sm border-primary">
                        <option value="1" data-final="<?= $net_odenecek ?>">Tek Çekim (<?= number_format($net_odenecek, 2) ?> ₺)</option>
                    </select>
                </div>

                <button type="submit" id="submit_btn" class="btn btn-primary w-100 py-3 fw-bold shadow-sm rounded-pill" onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin me-2\'></i>Onaylanıyor...';">
                    <i class="fas fa-lock me-2"></i> <span id="btn_tutar"><?= number_format($net_odenecek, 2) ?> ₺</span> ÖDEMEYİ ONAYLA (DEMO)
                </button>
            <?php endif; ?>
            
            <div class="text-center mt-3">
                <a href="magaza.php" class="text-decoration-none text-muted small"><i class="fas fa-arrow-left me-1"></i> İptal Et ve Geri Dön</a>
            </div>
            
            <?php if($net_odenecek > 0): ?>
            <div class="text-center mt-4 opacity-50">
                <i class="fab fa-cc-visa fa-2x mx-1"></i>
                <i class="fab fa-cc-mastercard fa-2x mx-1"></i>
                <img src="https://cdn.papara.com/web/logo/papara-logo-dark.svg" alt="Papara" style="height: 20px; margin-bottom: 8px; margin-left: 5px;">
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
    const asilFiyat = <?= $net_odenecek ?>;
    
    document.getElementById('cc_no')?.addEventListener('input', function (e) {
        var target = e.target;
        var rawValue = target.value.replace(/\D/g, ''); 
        var input = rawValue.substring(0, 16);
        target.value = input != '' ? input.match(/.{1,4}/g).join(' ') : ''; 
        
        if (rawValue.length >= 6) {
            var bin = rawValue.substring(0, 8);
            if (window.lastBin !== bin) {
                window.lastBin = bin;
                taksitleriGetir(bin);
            }
        } else {
            window.lastBin = '';
            document.getElementById('taksit_alani').style.display = 'none';
            document.getElementById('banka_logo').style.display = 'none';
            document.getElementById('card_icon').className = 'far fa-credit-card text-muted';
            
            document.getElementById('final_amount').value = asilFiyat;
            document.getElementById('btn_tutar').innerText = asilFiyat.toFixed(2) + ' ₺';
        }
    });

    function taksitleriGetir(bin) {
        var formData = new FormData();
        formData.append('islem', 'taksit_getir');
        formData.append('bin', bin);
        formData.append('tur', '<?= htmlspecialchars($tur) ?>');
        formData.append('id', '<?= htmlspecialchars($satin_alinan_id) ?>');
        formData.append('ay', '<?= htmlspecialchars($ay) ?>');

        fetch('odeme.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.succeeded && data.data) {
                if(data.data.cardBrandLogoUrl) {
                    document.getElementById('banka_logo').src = data.data.cardBrandLogoUrl;
                    document.getElementById('banka_logo').style.display = 'block';
                }
                if(data.data.cardNetwork === 'Visa') {
                    document.getElementById('card_icon').className = 'fab fa-cc-visa text-primary';
                } else if(data.data.cardNetwork === 'Mastercard') {
                    document.getElementById('card_icon').className = 'fab fa-cc-mastercard text-danger';
                }

                if (data.data.installmentDetails && data.data.installmentDetails.length > 0) {
                    var select = document.getElementById('taksit_secimi');
                    select.innerHTML = ''; 

                    data.data.installmentDetails.forEach(function(inst) {
                        var option = document.createElement('option');
                        option.value = inst.installment;
                        option.dataset.final = inst.finalAmount; 
                        
                        var text = inst.installment === 1 ? 'Tek Çekim' : inst.installment + ' Taksit';
                        if (inst.installment > 1) {
                            text += ' (' + inst.installmentAmount.toFixed(2) + ' ₺ x ' + inst.installment + ' Ay)';
                        }
                        if (inst.isInterestChargeApplied) {
                            text += ' - Toplam: ' + inst.finalAmount.toFixed(2) + ' ₺';
                        }
                        
                        option.text = text;
                        select.appendChild(option);
                    });
                    
                    document.getElementById('taksit_alani').style.display = 'block';
                    select.dispatchEvent(new Event('change')); 
                } else {
                    document.getElementById('taksit_alani').style.display = 'none';
                    document.getElementById('final_amount').value = asilFiyat;
                    document.getElementById('btn_tutar').innerText = asilFiyat.toFixed(2) + ' ₺';
                }
            }
        })
        .catch(err => console.log('Taksit API Hatası: ', err));
    }

    document.getElementById('taksit_secimi')?.addEventListener('change', function(e) {
        var selectedOption = this.options[this.selectedIndex];
        var finalAmt = parseFloat(selectedOption.dataset.final);
        
        document.getElementById('final_amount').value = finalAmt;
        document.getElementById('btn_tutar').innerText = finalAmt.toFixed(2) + ' ₺';
    });
</script>

</body>
</html>