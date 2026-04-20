<?php
session_start();
require 'baglanti.php';
require_once 'partials/security_check.php';

$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

$page_title = "Müşteri Detayı";
$hataMesaji = ""; 
$basariMesaji = "";
$id = 0; 
$firma_id = $_SESSION['firma_id'];
$kullanici_id = $_SESSION['kullanici_id'];

if (isset($_GET['t']) && !empty($_GET['t'])) {
    $token = trim($_GET['t']);
    $bul = $db->prepare("SELECT id FROM musteriler WHERE url_token = ? AND firma_id = ?");
    $bul->execute([$token, $firma_id]);
    $kayit = $bul->fetch(PDO::FETCH_ASSOC);
    
    if ($kayit) { $id = $kayit['id']; } 
    else { die("<div class='container mt-5 text-center'><h1>⛔</h1><h3>Geçersiz Bağlantı</h3></div>"); }
} else {
    header("Location: musteriler.php");
    exit;
}

$musteriIlk = $db->prepare("SELECT * FROM musteriler WHERE id = ?");
$musteriIlk->execute([$id]);
$musteriIlk = $musteriIlk->fetch(PDO::FETCH_ASSOC);

function sef_link($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', ' '], ['i', 'g', 'u', 's', 'o', 'c', '_'], $str);
    return preg_replace('/[^a-z0-9_]/', '', $str);
}

function yoneticiSifreDogrula($db, $kullanici_id, $girilen_sifre, $firma_id) {
    $sorgu = $db->prepare("SELECT sifre FROM yoneticiler WHERE id = ? AND firma_id = ?");
    $sorgu->execute([$kullanici_id, $firma_id]);
    $hash = $sorgu->fetchColumn();
    if($hash) { return (password_verify($girilen_sifre, $hash) || md5($girilen_sifre) === $hash); }
    return false;
}

// --- İŞLEMLER (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // GÜVENLİK: F12 açıklarına karşı tüm verileri temizle
    if (function_exists('sanitizeInput')) {
        $_POST = sanitizeInput($_POST);
    }
    
    if (isset($_FILES['dosya']) && $_FILES['dosya']['error'] == 0) {
        $izinliler = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'doc', 'xls', 'xlsx'];
        $dosyaAdi = $_FILES['dosya']['name'];
        $uzanti = strtolower(pathinfo($dosyaAdi, PATHINFO_EXTENSION));
        
        if (in_array($uzanti, $izinliler)) {
            $klasorAdi = sef_link($musteriIlk['ad_soyad']) . "_" . $id;
            $hedefKlasor = "uploads/" . $klasorAdi;
            if (!file_exists($hedefKlasor)) { @mkdir($hedefKlasor, 0777, true); }
            $yeniAd = "belge_" . rand(1000,9999) . "." . $uzanti;
            $hedefYol = $hedefKlasor . "/" . $yeniAd;
            if (move_uploaded_file($_FILES['dosya']['tmp_name'], $hedefYol)) {
                $db->prepare("INSERT INTO musteri_dosyalar (firma_id, musteri_id, dosya_yolu, dosya_adi) VALUES (?, ?, ?, ?)")->execute([$firma_id, $id, $hedefYol, htmlspecialchars($dosyaAdi, ENT_QUOTES)]);
                $_SESSION['success_message'] = "Dosya yüklendi.";
                if(function_exists('sistem_log_kaydet')) sistem_log_kaydet("Dosya Yüklendi", "Müşteriye ({$musteriIlk['ad_soyad']}) belge eklendi.", $firma_id, $kullanici_id);
            } else { 
                $_SESSION['error_message'] = "Yükleme hatası."; 
            }
        } else { 
            $_SESSION['error_message'] = "Geçersiz dosya türü."; 
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['dosya_sil_id'])) {
        $dosyaID = (int)$_POST['dosya_sil_id'];
        $dosyaBilgi = $db->prepare("SELECT * FROM musteri_dosyalar WHERE id=? AND firma_id=?");
        $dosyaBilgi->execute([$dosyaID, $firma_id]);
        $dosyaBilgi = $dosyaBilgi->fetch(PDO::FETCH_ASSOC);
        if ($dosyaBilgi) {
            if(file_exists($dosyaBilgi['dosya_yolu'])) { unlink($dosyaBilgi['dosya_yolu']); } 
            $db->prepare("DELETE FROM musteri_dosyalar WHERE id=?")->execute([$dosyaID]); 
            $_SESSION['success_message'] = "Dosya silindi.";
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['islem_ekle'])) {
        $islem_turu = $_POST['islem_turu']; 
        if (!in_array($islem_turu, ['satis', 'tahsilat'])) die("Geçersiz işlem.");

        $notlar = !empty($_POST['islem_notu']) ? $_POST['islem_notu'] : null; 
        $tarih = $_POST['tarih'];
        $hizmet_tarihi = !empty($_POST['hizmet_tarihi']) ? $_POST['hizmet_tarihi'] : null;
        $odeme_turu = isset($_POST['odeme_turu']) ? (int)$_POST['odeme_turu'] : 0;
        
        if ($islem_turu == 'tahsilat') {
            $toplam_tutar = (float)str_replace(',', '.', $_POST['tutar']); 
            $adet = 1; 
            $birim_fiyat = $toplam_tutar; 
            $iskonto = 0; 
            $kdv = 0;
            $odeme_isimleri = [0 => 'Nakit', 1 => 'Kredi Kartı', 2 => 'Havale / EFT'];
            $urun_aciklama = ($odeme_isimleri[$odeme_turu] ?? 'Nakit') . ' Tahsilatı';
        } else {
            $urun_aciklama = $_POST['urun_aciklama'];
            $adet = isset($_POST['adet']) ? (int)$_POST['adet'] : 1;
            $birim_fiyat = isset($_POST['birim_fiyat']) ? (float)str_replace(',', '.', $_POST['birim_fiyat']) : 0;
            $iskonto = isset($_POST['iskonto_orani']) ? (float)$_POST['iskonto_orani'] : 0;
            $kdv = isset($_POST['kdv_orani']) ? (float)$_POST['kdv_orani'] : 0;
            
            // Backend Matematik Koruması
            $ara_toplam = $adet * $birim_fiyat;
            $iskonto_tutar = $ara_toplam * ($iskonto / 100);
            $kdv_matrah = $ara_toplam - $iskonto_tutar;
            $kdv_tutar = $kdv_matrah * ($kdv / 100);
            $toplam_tutar = $kdv_matrah + $kdv_tutar;
            $odeme_turu = 0;
        }

        $ekle = $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, odeme_turu, urun_aciklama, notlar, adet, birim_fiyat, iskonto_orani, kdv_orani, toplam_tutar, islem_tarihi, vade_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ekle->execute([$firma_id, $id, $islem_turu, $odeme_turu, $urun_aciklama, $notlar, $adet, $birim_fiyat, $iskonto, $kdv, $toplam_tutar, $tarih, $hizmet_tarihi]);
        
        $_SESSION['success_message'] = "İşlem başarıyla eklendi.";
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['musteri_guncelle'])) {
        $ad_soyad = $_POST['ad_soyad'];
        $gelin_ad = $_POST['gelin_ad'] ?? '';
        $damat_ad = $_POST['damat_ad'] ?? '';

        // Banwords Koruması
        if (function_exists('checkBanwords') && (checkBanwords($ad_soyad) || checkBanwords($gelin_ad) || checkBanwords($damat_ad))) {
            $_SESSION['error_message'] = "Uygunsuz/yasaklı kelimeler tespit edildi. İşlem reddedildi!";
        } else {
            $telefon = preg_replace('/[^\d]/', '', $_POST['telefon'] ?? '');
            if (strlen($telefon) == 10 && substr($telefon, 0, 1) != '0') $telefon = '0' . $telefon;

            $anlasma = !empty($_POST['anlasma_tarihi']) ? $_POST['anlasma_tarihi'] : null;
            $guncelle = $db->prepare("UPDATE musteriler SET ad_soyad=?, gelin_ad=?, damat_ad=?, telefon=?, adres=?, tc_vergi_no=?, sozlesme_no=?, anlasma_tarihi=?, ozel_notlar=? WHERE id=? AND firma_id=?");
            $guncelle->execute([$ad_soyad, $gelin_ad, $damat_ad, $telefon, $_POST['adres'], $_POST['tc_vergi_no'], $_POST['sozlesme_no'], $anlasma, $_POST['ozel_notlar'], $id, $firma_id]);
            
            $_SESSION['success_message'] = "Müşteri bilgileri güncellendi.";
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['hareket_guncelle'])) {
        $hareket_id = (int)$_POST['hareket_id'];
        $chk = $db->prepare("SELECT id FROM hareketler WHERE id=? AND firma_id=?");
        $chk->execute([$hareket_id, $firma_id]);
        
        if($chk->fetch()) {
            $islem_turu = $_POST['islem_turu'];
            if (!in_array($islem_turu, ['satis', 'tahsilat'])) die("Geçersiz işlem.");

            $notlar = !empty($_POST['islem_notu']) ? $_POST['islem_notu'] : null; 
            $hizmet_tarihi = !empty($_POST['hizmet_tarihi']) ? $_POST['hizmet_tarihi'] : null;
            $odeme_turu = isset($_POST['odeme_turu']) ? (int)$_POST['odeme_turu'] : 0;
            
            if ($islem_turu == 'tahsilat') {
                $toplam_tutar = (float)str_replace(',', '.', $_POST['tutar']); 
                $adet = 1; 
                $birim_fiyat = $toplam_tutar; 
                $iskonto = 0; 
                $kdv = 0;
                $odeme_isimleri = [0 => 'Nakit', 1 => 'Kredi Kartı', 2 => 'Havale / EFT'];
                $urun_aciklama = ($odeme_isimleri[$odeme_turu] ?? 'Nakit') . ' Tahsilatı';
            } else {
                $urun_aciklama = $_POST['urun_aciklama'];
                $adet = (int)$_POST['adet']; 
                $birim_fiyat = (float)str_replace(',', '.', $_POST['birim_fiyat']); 
                $iskonto = (float)$_POST['iskonto_orani']; 
                $kdv = (float)$_POST['kdv_orani'];
                
                $ara_toplam = $adet * $birim_fiyat;
                $iskonto_tutar = $ara_toplam * ($iskonto / 100);
                $kdv_matrah = $ara_toplam - $iskonto_tutar;
                $kdv_tutar = $kdv_matrah * ($kdv / 100);
                $toplam_tutar = $kdv_matrah + $kdv_tutar;
                $odeme_turu = 0;
            }

            $h_guncelle = $db->prepare("UPDATE hareketler SET odeme_turu=?, urun_aciklama=?, notlar=?, vade_tarihi=?, adet=?, birim_fiyat=?, iskonto_orani=?, kdv_orani=?, toplam_tutar=? WHERE id=?");
            $h_guncelle->execute([$odeme_turu, $urun_aciklama, $notlar, $hizmet_tarihi, $adet, $birim_fiyat, $iskonto, $kdv, $toplam_tutar, $hareket_id]);
            
            $_SESSION['success_message'] = "İşlem güncellendi.";
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['yeni_durum'])) {
        $girilen_sifre = $_POST['guvenlik_sifresi']; 
        if (yoneticiSifreDogrula($db, $_SESSION['kullanici_id'], $girilen_sifre, $firma_id)) {
            $db->prepare("UPDATE musteriler SET durum = ? WHERE id = ? AND firma_id = ?")->execute([$_POST['yeni_durum'], $id, $firma_id]);
            $_SESSION['success_message'] = "Müşteri durumu değiştirildi.";
        } else { 
            $_SESSION['error_message'] = "HATA: Yönetici şifresi yanlış!"; 
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }
    
    if (isset($_POST['sil_id'])) {
        $sil_id = (int)$_POST['sil_id'];
        $db->prepare("DELETE FROM hareketler WHERE id = ? AND firma_id = ?")->execute([$sil_id, $firma_id]);
        $_SESSION['success_message'] = "Kayıt silindi.";
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['musteri_sil_soft'])) {
        $girilen_sifre = $_POST['guvenlik_sifresi'];
        if (yoneticiSifreDogrula($db, $_SESSION['kullanici_id'], $girilen_sifre, $firma_id)) {
            $db->prepare("UPDATE musteriler SET silindi = 1 WHERE id = ? AND firma_id = ?")->execute([$id, $firma_id]);
            $_SESSION['success_message'] = "Müşteri çöp kutusuna taşındı.";
            header("Location: musteriler.php"); exit;
        } else { 
            $_SESSION['error_message'] = "HATA: Yönetici şifresi yanlış!"; 
            header("Location: musteri_detay.php?t=$token"); exit;
        }
    }

    // --- GÜVENLİ SMS GÖNDERİMİ VE PAKET KONTROLÜ ---
    if (isset($_POST['sms_gonder_btn'])) {
        $hedef_hareket_id = (int)($_POST['hedef_hareket_id'] ?? 0);

        $bakiyeSorgu = $db->prepare("SELECT f.paket_id, f.aylik_sms_limiti, f.ek_sms_bakiyesi, f.kullanilan_sms_aylik, p.is_trial FROM firmalar f LEFT JOIN paketler p ON f.paket_id = p.id WHERE f.id = ?");
        $bakiyeSorgu->execute([$firma_id]);
        $firma_sms_veri = $bakiyeSorgu->fetch(PDO::FETCH_ASSOC);

        // Paket ID 1 veya Trial paketi ise SMS gönderimi kapalı
        if ($firma_sms_veri && ($firma_sms_veri['paket_id'] == 1 || $firma_sms_veri['is_trial'] == 1)) {
            $_SESSION['error_message'] = "Deneme veya Başlangıç sürümünde SMS özelliği kapalıdır. Paketinizi yükseltin.";
        } else {
            $aylik_limit = (int)$firma_sms_veri['aylik_sms_limiti'];
            $ek_bakiye = (int)$firma_sms_veri['ek_sms_bakiyesi'];
            $kullanilan_aylik = (int)$firma_sms_veri['kullanilan_sms_aylik'];
            
            if (($aylik_limit - $kullanilan_aylik + $ek_bakiye) > 0) {
                if ($hedef_hareket_id > 0) {
                    $hSorgu = $db->prepare("SELECT * FROM hareketler WHERE id = ? AND firma_id = ? AND musteri_id = ?");
                    $hSorgu->execute([$hedef_hareket_id, $firma_id, $id]);
                    $islem = $hSorgu->fetch(PDO::FETCH_ASSOC);
                    
                    if ($islem) {
                        // Mesajı sunucu üretir (F12 ile sahtecilik yapılamaz)
                        $gidecek_mesaj = "Sayın " . $musteriIlk['ad_soyad'] . ", ";
                        if ($islem['islem_turu'] == 'satis') {
                            $h_date = $islem['vade_tarihi'] ? date("d.m.Y", strtotime($islem['vade_tarihi'])) : date("d.m.Y", strtotime($islem['islem_tarihi']));
                            $gidecek_mesaj .= "$h_date tarihli {$islem['urun_aciklama']} rezervasyonunuz oluşturulmuştur. Tutar: " . number_format($islem['toplam_tutar'], 2, ',', '.') . " TL. Teşekkürler.";
                        } else {
                            $gidecek_mesaj .= date("d.m.Y", strtotime($islem['islem_tarihi'])) . " tarihinde " . number_format($islem['toplam_tutar'], 2, ',', '.') . " TL ödemeniz alındı. Teşekkürler.";
                        }
                        $gidecek_mesaj .= " - " . ($_SESSION['firma_adi'] ?? '');

                        $sms_sonuc = netgsm_sms_gonder($musteriIlk['telefon'], $gidecek_mesaj, $firma_id);

                        if ($sms_sonuc['status'] === true) {
                            $_SESSION['success_message'] = "SMS başarıyla gönderildi ve kotanızdan düşüldü.";
                        } else {
                            $_SESSION['error_message'] = "SMS Gönderilemedi: " . $sms_sonuc['message'];
                        }
                    } else {
                        $_SESSION['error_message'] = "Güvenlik ihlali: İlgili işlem bulunamadı.";
                    }
                } else {
                    $_SESSION['error_message'] = "Geçersiz işlem ID.";
                }
            } else {
                $_SESSION['error_message'] = "SMS Bakiyeniz Yetersiz! Lütfen paketinizi yükseltin.";
            }
        }
        header("Location: musteri_detay.php?t=$token"); 
        exit;
    }
}

// --- VERİLERİ ÇEK ---
$musteri = $musteriIlk;

$sozlesmeler = $db->prepare("SELECT * FROM sozlesmeler WHERE musteri_id=? AND firma_id=? ORDER BY id DESC");
$sozlesmeler->execute([$id, $firma_id]);
$sozlesmeler = $sozlesmeler->fetchAll(PDO::FETCH_ASSOC);

$hareketler = $db->prepare("SELECT *, COALESCE(vade_tarihi, islem_tarihi) as siralama_tarihi FROM hareketler WHERE musteri_id=? AND firma_id=? ORDER BY siralama_tarihi ASC, id ASC");
$hareketler->execute([$id, $firma_id]);
$hareketler = $hareketler->fetchAll(PDO::FETCH_ASSOC);

$dosyalar = $db->prepare("SELECT * FROM musteri_dosyalar WHERE musteri_id=? AND firma_id=? ORDER BY id DESC");
$dosyalar->execute([$id, $firma_id]);
$dosyalar = $dosyalar->fetchAll(PDO::FETCH_ASSOC);

$hizmetler = $db->prepare("SELECT * FROM urun_hizmetler WHERE durum=1 AND firma_id=? ORDER BY hizmet_adi ASC");
$hizmetler->execute([$firma_id]);
$hizmetler = $hizmetler->fetchAll(PDO::FETCH_ASSOC);

$toplamBorc = 0; $toplamTahsilat = 0;
foreach($hareketler as $h) {
    if ($h['islem_turu'] == 'satis') $toplamBorc += $h['toplam_tutar'];
    else $toplamTahsilat += $h['toplam_tutar'];
}
$bakiye = $toplamBorc - $toplamTahsilat;

$wp_no = preg_replace('/[^0-9]/', '', $musteri['telefon']);
if(substr($wp_no, 0, 1) == '0') $wp_no = '9' . $wp_no;
else if(strlen($wp_no) == 10) $wp_no = '90' . $wp_no;

$wp_msg_lines = ["Sayın *" . $musteri['ad_soyad'] . "*, hesap dökümünüz:", "----------------------------"];
foreach($hareketler as $h) {
    $tarih = $h['vade_tarihi'] ? date("d.m.Y", strtotime($h['vade_tarihi'])) : date("d.m.Y", strtotime($h['islem_tarihi']));
    $tutar = number_format($h['toplam_tutar'], 2, ',', '.');
    $icon = ($h['islem_turu'] == 'satis') ? "🗓️" : "✅";
    $suffix = ($h['islem_turu'] == 'satis') ? "(Hizmet)" : "(Ödeme)";
    $wp_msg_lines[] = "$icon $tarih | {$h['urun_aciklama']}: *$tutar TL* $suffix";
}
$wp_msg_lines[] = "----------------------------";
$wp_msg_lines[] = "💰 *KALAN: " . number_format($bakiye, 2, ',', '.') . " TL*";
$wp_msg_lines[] = "";
$wp_msg_lines[] = "*" . ($_SESSION['firma_adi'] ?? '') . "*";
$wp_genel_link = "https://wa.me/$wp_no?text=" . urlencode(implode("\n", $wp_msg_lines));

$inline_css = '
    .btn-action-lg { padding: 15px; border-radius: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 10px; transition: transform 0.2s; }
    .btn-action-lg:hover { transform: translateY(-2px); }
    .ozet-kutu-detay { border-radius: 12px; padding: 20px; color: white; height: 100%; box-shadow: 0 4px 15px rgba(0,0,0,0.1); position: relative; overflow: hidden; }
    .ozet-kutu-detay::after { content: ""; position: absolute; top: 0; right: 0; bottom: 0; left: 0; background: linear-gradient(rgba(255,255,255,0.1), rgba(255,255,255,0)); pointer-events: none; }
    .ozet-label { font-size: 0.85rem; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.5px; }
    .ozet-value { font-size: 1.8rem; font-weight: 800; margin-top: 5px; }
    .bg-gradient-satis { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); }
    .bg-gradient-tahsilat { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); }
    .bg-gradient-bakiye { background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%); }
    .bg-gradient-bakiye-pozitif { background: linear-gradient(135deg, #36b9cc 0%, #258391 100%); }
    .musteri-header-card { background: white; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; border-left: 5px solid #667eea; }
    .dosya-item { background: white; border: 1px solid #e3e6f0; border-radius: 8px; padding: 10px 15px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; transition: 0.2s; }
    .dosya-item:hover { background-color: #f8f9fa; }
    .timeline-icon { width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
    @media print {
        .no-print, .navbar, .ozet-kartlar-container, .col-lg-4, .btn, footer, .toast-container, .modal, .alert { display: none !important; }
        body { background-color: white !important; -webkit-print-color-adjust: exact; }
        .container-yonetim { padding: 0; max-width: 100%; margin: 0; }
        .musteri-header-card { box-shadow: none; border: 1px solid #ddd; margin-bottom: 20px; }
        .col-lg-8 { width: 100% !important; flex: 0 0 100% !important; max-width: 100% !important; }
        .card { border: none !important; box-shadow: none !important; }
        .card-header { background-color: white !important; border-bottom: 2px solid #000 !important; padding-left: 0; }
        .table { width: 100% !important; border-collapse: collapse !important; }
        .table th, .table td { padding: 8px 5px !important; font-size: 11pt; border-bottom: 1px solid #ddd; }
        .table thead th { border-bottom: 2px solid #000; color: #000; }
        .badge { border: 1px solid #000; color: #000 !important; background: transparent !important; }
    }
';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($musteri['ad_soyad']); ?> - Detay</title>
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style><?php echo $inline_css; ?></style>
</head>
<body class="yonetim-body">

    <?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim pb-5 mt-4">
        
        <div class="row mb-4 align-items-center no-print">
            <div class="col-md-6">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="musteriler.php"><i class="fas fa-users me-1"></i>Müşteriler</a></li>
                        <li class="breadcrumb-item active">Müşteri Detayı</li>
                    </ol>
                </nav>
            </div>
            <div class="col-md-6 text-end">
                <a href="musteriler.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Geri</a>
            </div>
        </div>

        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success shadow-sm no-print mb-4"><i class="fas fa-check-circle me-2"></i><?= $_SESSION['success_message'] ?></div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <?php if(isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger shadow-sm no-print mb-4"><i class="fas fa-exclamation-triangle me-2"></i><?= $_SESSION['error_message'] ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="musteri-header-card" style="<?php echo ($musteri['silindi']==1) ? 'opacity:0.6; pointer-events:none;' : ''; ?>">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center mb-2">
                        <h2 class="mb-0 fw-bold text-dark me-3">
                            <?php echo htmlspecialchars($musteri['ad_soyad']); ?>
                            <?php if($musteri['gelin_ad'] || $musteri['damat_ad']): ?>
                                <span class="d-block fs-6 text-muted mt-1"><i class="fas fa-heart text-danger small me-1"></i><?php echo htmlspecialchars($musteri['gelin_ad'] . " & " . $musteri['damat_ad']); ?></span>
                            <?php endif; ?>
                        </h2>
                        <span class="badge no-print <?php echo $musteri['durum'] == 1 ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $musteri['durum'] == 1 ? 'Aktif' : 'Pasif/Arşiv'; ?></span>
                        <button class="btn btn-sm btn-link text-primary ms-2 no-print" data-bs-toggle="modal" data-bs-target="#modalMusteriDuzenle"><i class="fas fa-pen"></i> Düzenle</button>
                    </div>
                    
                    <div class="d-flex flex-wrap gap-3 text-muted mb-2">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-phone me-2 text-primary"></i>
                            <a href="tel:<?php echo $musteri['telefon']; ?>" class="text-decoration-none text-dark fw-bold"><?php echo $musteri['telefon'] ?: 'Tel Yok'; ?></a>
                        </div>
                        <?php if($musteri['tc_vergi_no']): ?>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-file-invoice me-2 text-primary"></i><span>VN/TC: <?php echo $musteri['tc_vergi_no']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if($musteri['adres']): ?><div class="small text-muted"><i class="fas fa-map-marker-alt me-2 text-danger"></i><?php echo $musteri['adres']; ?></div><?php endif; ?>
                </div>
                
                <div class="col-lg-4 text-lg-end mt-4 mt-lg-0 no-print">
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-2">
                        <a href="<?php echo $wp_genel_link; ?>" target="_blank" class="btn btn-success" title="WhatsApp Döküm Gönder"><i class="fab fa-whatsapp me-1"></i>WP Döküm</a>
                        <a href="hesap_dokumu_yazdir.php?t=<?php echo $token; ?>" target="_blank" class="btn btn-dark"><i class="fas fa-print me-1"></i>Yazdır</a>
                        <a href="musteri_portal_yonetim.php?t=<?php echo $token; ?>" class="btn btn-primary"><i class="fas fa-external-link-alt me-1"></i>Portal</a>
                    </div>
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <div class="dropdown w-100 w-md-auto">
                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown"><i class="fas fa-cog me-1"></i>İşlemler</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if($musteri['durum'] == 1): ?>
                                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalArsivle"><i class="fas fa-archive me-2 text-warning"></i>Arşive Kaldır</a></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalAktifEt"><i class="fas fa-undo me-2 text-success"></i>Aktif Et</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#modalSilSoft"><i class="fas fa-trash me-2"></i>Müşteriyi Sil</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4 ozet-kartlar-container" style="<?php echo ($musteri['silindi']==1) ? 'opacity:0.6;' : ''; ?>">
            <div class="col-md-4 mb-3 mb-md-0"><div class="ozet-kutu-detay bg-gradient-satis"><div class="ozet-label">TOPLAM HİZMET / SATIŞ</div><div class="ozet-value"><?php echo number_format($toplamBorc, 2, ',', '.'); ?> ₺</div><i class="fas fa-camera fa-3x position-absolute" style="right: 20px; bottom: 20px; opacity: 0.2;"></i></div></div>
            <div class="col-md-4 mb-3 mb-md-0"><div class="ozet-kutu-detay bg-gradient-tahsilat"><div class="ozet-label">TOPLAM ALINAN</div><div class="ozet-value"><?php echo number_format($toplamTahsilat, 2, ',', '.'); ?> ₺</div><i class="fas fa-hand-holding-usd fa-3x position-absolute" style="right: 20px; bottom: 20px; opacity: 0.2;"></i></div></div>
            <div class="col-md-4"><div class="ozet-kutu-detay <?php echo $bakiye > 0 ? 'bg-gradient-bakiye' : 'bg-gradient-bakiye-pozitif'; ?>"><div class="ozet-label">KALAN BAKİYE</div><div class="ozet-value"><?php echo number_format($bakiye, 2, ',', '.'); ?> ₺</div><i class="fas fa-wallet fa-3x position-absolute" style="right: 20px; bottom: 20px; opacity: 0.2;"></i></div></div>
        </div>

        <div class="row" style="<?php echo ($musteri['silindi']==1) ? 'opacity:0.6; pointer-events:none;' : ''; ?>">
            <div class="col-lg-8">
                <div class="row mb-4 no-print">
                    <div class="col-6"><button class="btn btn-action-lg w-100 btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalSatis"><i class="fas fa-plus-circle fa-lg"></i><span>YENİ İŞLEM</span></button></div>
                    <div class="col-6"><button class="btn btn-action-lg w-100 btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTahsilat"><i class="fas fa-coins fa-lg"></i><span>TAHSİLAT AL</span></button></div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3"><h6 class="m-0 fw-bold text-secondary"><i class="fas fa-history me-2"></i>Hesap Hareketleri</h6></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary">
                                <tr>
                                    <th class="ps-3" width="110">İşlem Tar.</th>
                                    <th width="140">Hizmet Tarihi</th>
                                    <th>Açıklama / Hizmet</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="text-end pe-3 no-print" width="160">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($hareketler as $h): 
                                    $not = $h['notlar'] ?: '';
                                    $bugun = date("Y-m-d");
                                    $durum_etiketi = "";
                                    
                                    if($h['islem_turu'] == 'satis' && $h['vade_tarihi']) {
                                        if($h['vade_tarihi'] < $bugun) { $durum_etiketi = '<span class="badge bg-success bg-opacity-10 text-success border border-success px-2 py-1 ms-2"><i class="fas fa-check-double me-1"></i>Gerçekleşti</span>'; } 
                                        else { $durum_etiketi = '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning px-2 py-1 ms-2"><i class="fas fa-hourglass-half me-1"></i>Bekliyor</span>'; }
                                    }

                                    $odeme_metni = "";
                                    if ($h['islem_turu'] == 'tahsilat') {
                                        if ($h['odeme_turu'] == 1) $odeme_metni = " <span class='badge bg-info text-dark ms-1'><i class='fas fa-credit-card'></i> K.Kartı</span>";
                                        elseif ($h['odeme_turu'] == 2) $odeme_metni = " <span class='badge bg-secondary ms-1'><i class='fas fa-exchange-alt'></i> Havale</span>";
                                        else $odeme_metni = " <span class='badge bg-success ms-1'><i class='fas fa-money-bill-wave'></i> Nakit</span>";
                                    }

                                    $satir_mesaj = "Sayın " . $musteri['ad_soyad'] . ", ";
                                    if ($h['islem_turu'] == 'satis') {
                                        $h_date = $h['vade_tarihi'] ? date("d.m.Y", strtotime($h['vade_tarihi'])) : date("d.m.Y", strtotime($h['islem_tarihi']));
                                        $satir_mesaj .= "$h_date tarihli {$h['urun_aciklama']} rezervasyonunuz oluşturulmuştur. Tutar: " . number_format($h['toplam_tutar'], 2, ',', '.') . " TL. Teşekkürler.";
                                    } else {
                                        $satir_mesaj .= date("d.m.Y", strtotime($h['islem_tarihi'])) . " tarihinde " . number_format($h['toplam_tutar'], 2, ',', '.') . " TL ödemeniz alındı. Teşekkürler.";
                                    }
                                    $satir_mesaj .= " - " . ($_SESSION['firma_adi'] ?? '');
                                    $satir_link = "https://wa.me/$wp_no?text=" . urlencode($satir_mesaj);
                                ?>
                                <tr class="<?php echo $h['islem_turu']=='tahsilat' ? 'table-success' : ''; ?>" style="<?php echo $h['islem_turu']=='tahsilat' ? '--bs-table-bg-type:rgba(25,135,84,0.05)' : ''; ?>">
                                    <td class="ps-3 text-muted small"><?php echo date("d.m.Y", strtotime($h['islem_tarihi'])); ?></td>
                                    <td>
                                        <?php if($h['islem_turu'] == 'satis' && $h['vade_tarihi']): ?>
                                            <div class="fw-bold text-dark"><?php echo date("d.m.Y", strtotime($h['vade_tarihi'])); ?></div>
                                            <?php echo $durum_etiketi; ?>
                                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($h['urun_aciklama']); ?><?php echo $odeme_metni; ?></div>
                                        <?php if($not): ?><div class="small text-danger mt-1 fst-italic"><i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($not); ?></div><?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold <?php echo $h['islem_turu']=='tahsilat'?'text-success':'text-danger'; ?>">
                                        <?php echo ($h['islem_turu']=='tahsilat' ? '-' : '+') . number_format($h['toplam_tutar'], 2, ',', '.'); ?> ₺
                                    </td>
                                    <td class="text-end pe-3 no-print">
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?php echo $satir_link; ?>" target="_blank" class="btn btn-outline-success border-0" title="WP Bildir"><i class="fab fa-whatsapp"></i></a>
                                            <!-- GÜVENLİ SMS BUTONU: Sadece Hareket ID Gidiyor -->
                                            <button type="button" class="btn btn-outline-info border-0" onclick="smsModalAc(<?= $h['id'] ?>, '<?= htmlspecialchars($wp_no, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($satir_mesaj, ENT_QUOTES, 'UTF-8') ?>')" title="SMS Gönder"><i class="fas fa-comment-sms"></i></button>
                                            <a href="makbuz.php?id=<?php echo $h['id']; ?>&t=<?php echo $token; ?>" target="_blank" class="btn btn-outline-dark border-0" title="Yazdır"><i class="fas fa-print"></i></a>
                                            <button type="button" class="btn btn-outline-primary border-0" onclick="hareketDuzenle(this)" data-hareket='<?php echo htmlspecialchars(json_encode($h), ENT_QUOTES, 'UTF-8'); ?>' title="Düzenle"><i class="fas fa-pen"></i></button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Silmek istediğinize emin misiniz?');">
                                                <input type="hidden" name="sil_id" value="<?php echo $h['id']; ?>">
                                                <button class="btn btn-outline-danger border-0" title="Sil"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end py-3">GENEL BAKİYE:</td>
                                    <td class="text-end py-3 text-dark fs-5"><?php echo number_format($bakiye, 2, ',', '.'); ?> ₺</td>
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 no-print">
                <div class="card shadow-sm border-0 mb-4" style="background-color: #fffbf0; border-left: 4px solid #f6c23e !important;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold text-dark mb-0"><i class="fas fa-sticky-note me-2 text-warning"></i>Özel Notlar</h6>
                            <button class="btn btn-sm btn-link text-muted p-0" data-bs-toggle="modal" data-bs-target="#modalMusteriDuzenle">Düzenle</button>
                        </div>
                        <p class="small text-secondary mb-0" style="white-space: pre-line;"><?php echo $musteri['ozel_notlar'] ? htmlspecialchars($musteri['ozel_notlar']) : 'Henüz not eklenmemiş.'; ?></p>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3"><h6 class="m-0 fw-bold text-dark"><i class="fas fa-file-signature me-2 text-primary"></i>Sözleşme Geçmişi</h6></div>
                    <div class="card-body p-0">
                        <?php if(count($sozlesmeler) > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach($sozlesmeler as $sz): ?>
                                    <div class="list-group-item p-3 border-bottom">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-bold small"><?php echo htmlspecialchars($sz['sozlesme_no']); ?></div>
                                                <div class="text-muted" style="font-size: 0.75rem;"><?php echo date("d.m.Y", strtotime($sz['sozlesme_tarihi'])); ?></div>
                                            </div>
                                            <a href="sozlesme_olustur.php?t=<?php echo $token; ?>&print_id=<?php echo $sz['id']; ?>" target="_blank" class="btn btn-sm btn-light border"><i class="fas fa-print"></i></a>
                                        </div>
                                        <div class="mt-1 small fw-bold text-primary"><?php echo number_format($sz['toplam_tutar'], 2, ',', '.'); ?> ₺</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted small">Kayıtlı sözleşme bulunamadı.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-secondary"><i class="fas fa-folder-open me-2"></i>Dosyalar</h6>
                        <div class="d-flex gap-2">
                            <a href="sozlesme_olustur.php?t=<?php echo $token; ?>" class="btn btn-sm btn-outline-dark rounded-pill px-3"><i class="fas fa-file-contract me-1"></i>Sözleşme</a>
                            <button class="btn btn-sm btn-primary rounded-pill px-3" onclick="document.getElementById('dosyaInput').click()"><i class="fas fa-plus me-1"></i>Yükle</button>
                        </div>
                    </div>
                    <div class="card-body bg-light">
                        <form method="POST" enctype="multipart/form-data" id="dosyaForm" class="d-none">
                            <input type="file" name="dosya" id="dosyaInput" onchange="document.getElementById('dosyaForm').submit()">
                        </form>
                        <?php if(count($dosyalar) > 0): ?>
                            <div class="dosya-listesi">
                                <?php foreach($dosyalar as $d): ?>
                                    <div class="dosya-item">
                                        <a href="<?php echo htmlspecialchars($d['dosya_yolu']); ?>" target="_blank" class="text-decoration-none text-dark d-flex align-items-center text-truncate">
                                            <i class="fas fa-file-alt me-2 text-secondary"></i>
                                            <span class="small fw-bold text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($d['dosya_adi']); ?></span>
                                        </a>
                                        <form method="POST" class="m-0 p-0">
                                            <input type="hidden" name="dosya_sil_id" value="<?php echo $d['id']; ?>">
                                            <button type="submit" class="btn btn-sm text-danger p-0" onclick="return confirm('Sil?')"><i class="fas fa-times"></i></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted small"><i class="fas fa-cloud-upload-alt fa-2x mb-2 opacity-50"></i><br>Dosya yüklenmemiş.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: HİZMET EKLE -->
    <div class="modal fade modal-yonetim" id="modalSatis" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" class="form-yonetim">
                    <input type="hidden" name="islem_ekle" value="1">
                    <input type="hidden" name="islem_turu" value="satis">
                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-camera me-2"></i>Hizmet / Ürün Ekle</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">İşlem Tarihi</label><input type="datetime-local" name="tarih" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required></div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-primary">Hizmet / Çekim Günü <small class="text-muted fw-normal">(Ürün satışı vb. için boş bırakın)</small></label>
                            <input type="date" name="hizmet_tarihi" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Hizmet Seç (Veya Yaz)</label>
                            <input type="text" name="urun_aciklama" id="urun_input" class="form-control" list="hizmetListesi" placeholder="Hizmet adı..." required>
                            <datalist id="hizmetListesi"><?php foreach($hizmetler as $hz): ?><option value="<?php echo htmlspecialchars($hz['hizmet_adi']); ?>" data-fiyat="<?php echo htmlspecialchars($hz['varsayilan_fiyat']); ?>"><?php endforeach; ?></datalist>
                        </div>
                        <div class="mb-3"><label class="form-label text-danger">İşlem Notu</label><textarea name="islem_notu" class="form-control" rows="2"></textarea></div>
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label">Birim Fiyat</label><input type="number" step="0.01" name="birim_fiyat" id="fiyat_input" class="form-control" oninput="canliHesapla()" required></div>
                            <div class="col-6"><label class="form-label">Adet</label><input type="number" name="adet" id="adet_input" class="form-control" value="1" oninput="canliHesapla()" required></div>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-6"><label class="form-label">KDV</label><select name="kdv_orani" id="kdv_input" class="form-select" onchange="canliHesapla()"><option value="0">Dahil</option><option value="20">%20</option></select></div>
                            <div class="col-6"><label class="form-label">İskonto (%)</label><input type="number" name="iskonto_orani" id="iskonto_input" class="form-control" value="0" oninput="canliHesapla()"></div>
                        </div>

                        <!-- CANLI HESAPLAMA BİLGİ PANELİ -->
                        <div id="live_calc_box" class="alert alert-info p-3 mt-3 mb-0" style="display: none; border-radius: 10px;">
                            <div class="d-flex justify-content-between mb-1 text-muted small"><span>Ara Toplam:</span> <strong id="calc_ara">0.00 ₺</strong></div>
                            <div class="d-flex justify-content-between mb-1 text-danger small" id="calc_iskonto_row" style="display:none !important;"><span>İskonto:</span> <strong id="calc_iskonto">0.00 ₺</strong></div>
                            <div class="d-flex justify-content-between mb-1 text-muted small"><span>KDV Hariç Tutar:</span> <strong id="calc_matrah">0.00 ₺</strong></div>
                            <div class="d-flex justify-content-between mb-1 text-muted small"><span>KDV Tutarı:</span> <strong id="calc_kdv">0.00 ₺</strong></div>
                            <hr class="my-2 border-info opacity-25">
                            <div class="d-flex justify-content-between text-dark fs-6 mt-1"><span>Genel Toplam:</span> <strong id="calc_toplam">0.00 ₺</strong></div>
                        </div>

                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-yonetim-primary w-100">Kaydet</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- MODAL: TAHSİLAT EKLE -->
    <div class="modal fade modal-yonetim" id="modalTahsilat" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <form method="POST" class="form-yonetim">
                    <input type="hidden" name="islem_ekle" value="1">
                    <input type="hidden" name="islem_turu" value="tahsilat">
                    <div class="modal-header bg-success text-white border-0"><h5 class="modal-title fw-bold"><i class="fas fa-hand-holding-usd me-2"></i>Tahsilat Yap</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body p-4">
                        
                        <div class="mb-4 text-center">
                            <label class="form-label fw-bold text-success mb-1">Alınan Tutar (₺)</label>
                            <input type="number" step="0.01" name="tutar" class="form-control form-control-lg fw-bold border-success text-center text-success" style="font-size: 2.5rem; height: 70px; background-color: #f8fff9;" placeholder="0.00" required min="0" autofocus>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-muted small">Ödeme Türü</label>
                                <select name="odeme_turu" class="form-select bg-light fw-bold text-primary" required>
                                    <option value="0">Nakit</option>
                                    <option value="1">Kredi Kartı</option>
                                    <option value="2">Havale / EFT</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-muted small">Tarih</label>
                                <input type="datetime-local" name="tarih" class="form-control bg-light" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold text-muted small">İşlem Notu (İsteğe Bağlı)</label>
                            <textarea name="islem_notu" class="form-control bg-light" rows="2" placeholder="Tahsilatla ilgili eklemek istediğiniz notlar..."></textarea>
                        </div>

                    </div>
                    <div class="modal-footer bg-light border-0"><button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm">Tahsilatı Onayla</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: HAREKET DÜZENLEME -->
    <div class="modal fade modal-yonetim" id="modalHareketDuzenle" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <form method="POST" class="form-yonetim">
                    <input type="hidden" name="hareket_guncelle" value="1">
                    <input type="hidden" name="hareket_id" id="edit_hareket_id">
                    <input type="hidden" name="islem_turu" id="edit_islem_turu">
                    
                    <div class="modal-header bg-primary text-white border-0" id="editModalHeader">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>İşlemi Düzenle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        
                        <div id="tahsilat_tutar_alani" style="display:none;" class="mb-4 text-center">
                            <label class="form-label fw-bold text-success mb-1">Alınan Tutar (₺)</label>
                            <input type="number" step="0.01" name="tutar" id="edit_tutar" class="form-control form-control-lg fw-bold border-success text-center text-success" style="font-size: 2.5rem; height: 70px; background-color: #f8fff9;" placeholder="0.00" min="0">
                        </div>

                        <div id="satis_aciklama_alani" style="display:block;" class="mb-3">
                            <label class="form-label fw-bold text-muted small">Hizmet / Açıklama</label>
                            <input type="text" name="urun_aciklama" id="edit_urun_aciklama" class="form-control bg-light">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-muted small">İşlem Tarihi</label>
                            <input type="datetime-local" name="tarih" id="edit_tarih" class="form-control bg-light" readonly>
                        </div>
                        
                        <div id="satis_alanlari">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-primary small">Hizmet/Çekim Günü <span class="fw-normal text-muted">(Ürün vb. için boş)</span></label>
                                <input type="date" name="hizmet_tarihi" id="edit_hizmet_tarihi" class="form-control bg-light">
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6"><label class="form-label fw-bold text-muted small">Birim Fiyat</label><input type="number" step="0.01" name="birim_fiyat" id="edit_birim_fiyat" class="form-control bg-light"></div>
                                <div class="col-6"><label class="form-label fw-bold text-muted small">Adet</label><input type="number" name="adet" id="edit_adet" class="form-control bg-light"></div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6"><label class="form-label fw-bold text-muted small">KDV</label><select name="kdv_orani" id="edit_kdv_orani" class="form-select bg-light"><option value="0">Dahil</option><option value="20">%20</option></select></div>
                                <div class="col-6"><label class="form-label fw-bold text-muted small">İskonto</label><input type="number" name="iskonto_orani" id="edit_iskonto_orani" class="form-control bg-light"></div>
                            </div>
                        </div>
                        
                        <div id="tahsilat_alanlari" style="display:none;" class="mb-3">
                            <label class="form-label fw-bold text-muted small">Ödeme Türü</label>
                            <select name="odeme_turu" id="edit_odeme_turu" class="form-select bg-light fw-bold text-primary">
                                <option value="0">Nakit</option>
                                <option value="1">Kredi Kartı</option>
                                <option value="2">Havale / EFT</option>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold text-danger small">İşlem Notu</label>
                            <textarea name="islem_notu" id="edit_islem_notu" class="form-control bg-light" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm" id="edit_submit_btn">Kaydı Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: SİLME ONAY (SOFT DELETE) -->
    <div class="modal fade modal-yonetim" id="modalSilSoft" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST"><input type="hidden" name="musteri_sil_soft" value="1"><div class="modal-header bg-danger"><h5 class="modal-title">Müşteriyi Sil</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-warning border-0 shadow-sm"><strong>Bu işlem müşteriyi Çöp Kutusuna taşıyacaktır.</strong><br>Veriler kaybolmaz, geri yükleyebilirsiniz.</div><div class="mb-3"><label class="form-label fw-bold">Onay için Yönetici Şifresi:</label><input type="password" name="guvenlik_sifresi" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-danger fw-bold">Evet, Sil</button></div></form></div></div></div>

    <!-- MODAL: ARŞİVLEME -->
    <div class="modal fade modal-yonetim" id="modalArsivle" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST"><input type="hidden" name="yeni_durum" value="0"><div class="modal-header bg-secondary"><h5 class="modal-title">Arşive Kaldır</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>Müşteri pasif duruma getirilecek.</p><div class="mb-3"><label class="form-label">Yönetici Şifresi</label><input type="password" name="guvenlik_sifresi" class="form-control" required></div></div><div class="modal-footer"><button type="submit" class="btn btn-secondary w-100">Onayla</button></div></form></div></div></div>

    <!-- MODAL: AKTİF ETME -->
    <div class="modal fade modal-yonetim" id="modalAktifEt" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST"><input type="hidden" name="yeni_durum" value="1"><div class="modal-header bg-success"><h5 class="modal-title">Aktif Et</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>Müşteri tekrar aktif listeye alınacak.</p><div class="mb-3"><label class="form-label">Yönetici Şifresi</label><input type="password" name="guvenlik_sifresi" class="form-control" required></div></div><div class="modal-footer"><button type="submit" class="btn btn-success w-100">Aktif Et</button></div></form></div></div></div>
    
    <!-- MODAL: MÜŞTERİ BİLGİ DÜZENLE -->
    <div class="modal fade" id="modalMusteriDuzenle" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST"><input type="hidden" name="musteri_guncelle" value="1"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Bilgileri Düzenle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">Genel Tanım (Liste Adı)</label><input type="text" name="ad_soyad" class="form-control" value="<?php echo htmlspecialchars($musteri['ad_soyad']); ?>" required></div><div class="row g-2 mb-3"><div class="col-6"><label class="form-label text-danger fw-bold">Gelin Adı</label><input type="text" name="gelin_ad" class="form-control" value="<?php echo htmlspecialchars($musteri['gelin_ad'] ?? ''); ?>"></div><div class="col-6"><label class="form-label text-primary fw-bold">Damat Adı</label><input type="text" name="damat_ad" class="form-control" value="<?php echo htmlspecialchars($musteri['damat_ad'] ?? ''); ?>"></div></div><div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Telefon (0 olmadan)</label><input type="text" name="telefon" class="form-control" value="<?php echo htmlspecialchars(substr($musteri['telefon'], 1) ?? ''); ?>"></div><div class="col-6"><label class="form-label">Vergi/TC</label><input type="text" name="tc_vergi_no" class="form-control" value="<?php echo htmlspecialchars($musteri['tc_vergi_no']); ?>"></div></div><div class="mb-3"><label class="form-label">Adres</label><textarea name="adres" class="form-control" rows="2"><?php echo htmlspecialchars($musteri['adres']); ?></textarea></div><div class="row g-2 mb-3"><div class="col-6"><label class="form-label small">Sözleşme No</label><input type="text" name="sozlesme_no" class="form-control" value="<?php echo htmlspecialchars($musteri['sozlesme_no']); ?>"></div><div class="col-6"><label class="form-label small">Anlaşma Tarihi</label><input type="date" name="anlasma_tarihi" class="form-control" value="<?php echo $musteri['anlasma_tarihi']; ?>"></div></div><div class="mb-3"><label class="form-label">Özel Notlar</label><textarea name="ozel_notlar" class="form-control" rows="3"><?php echo htmlspecialchars($musteri['ozel_notlar']); ?></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-warning fw-bold">Güncelle</button></div></form></div></div></div>

    <!-- GÜVENLİ SMS GÖNDER ONAY POPUP'I -->
    <div class="modal fade" id="modalSmsGonder" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="sms_gonder_btn" value="1">
                    <input type="hidden" name="hedef_hareket_id" id="sms_gizli_hareket_id">
                    
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-comment-sms me-2"></i>SMS Gönderim Onayı</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Aşağıdaki mesaj <strong><span id="sms_goster_telefon" class="text-primary fs-5"></span></strong> numaralı telefona gönderilecektir.</p>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Gönderilecek Mesaj (Sistem Tarafından Üretilir):</label>
                            <textarea id="sms_goster_mesaj" class="form-control text-muted" rows="5" style="background-color: #e9ecef; font-size: 0.95rem; cursor: not-allowed;" disabled></textarea>
                            <div class="form-text small mt-1 text-danger"><i class="fas fa-shield-alt"></i> Güvenlik gereği mesaj içeriğine müdahale edilemez.</div>
                        </div>
                        <div class="alert alert-warning py-2 mb-0" style="font-size: 0.85rem;">
                            <i class="fas fa-info-circle me-1"></i> SMS gönderim işlemi hesabınızın bakiyesinden <b>1 adet</b> düşülecektir.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-info text-white fw-bold"><i class="fas fa-paper-plane me-2"></i>Gönder ve Kotadan Düş</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="toast-container-yonetim"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/yonetim.js"></script>
    
    <script>
        // KDV ve Tutar Canlı Hesaplama Sistemi
        window.canliHesapla = function() {
            let adet = parseFloat(document.getElementById("adet_input").value) || 0;
            let fiyat = parseFloat(document.getElementById("fiyat_input").value) || 0;
            let iskonto = parseFloat(document.getElementById("iskonto_input").value) || 0;
            let kdv = document.getElementById("kdv_input").value;

            let calcBox = document.getElementById("live_calc_box");
            
            // Eğer fiyat girilmemişse bilgi kutusunu gizle
            if (fiyat > 0) {
                calcBox.style.display = "block";
            } else {
                calcBox.style.display = "none";
                return;
            }

            let araToplam = adet * fiyat;
            let iskontoTutar = araToplam * (iskonto / 100);
            let netTutar = araToplam - iskontoTutar;

            let matrah = 0;
            let kdvTutar = 0;
            let genelToplam = 0;

            if (kdv === "0") {
                // "KDV Dahil" durumu (Sisteme sadece girilen rakam işlenir)
                // Bilgi amaçlı içinden KDV ayrıştırılıp ekrana basılır
                matrah = netTutar / 1.20;
                kdvTutar = netTutar - matrah;
                genelToplam = netTutar;
            } else {
                // "%20 KDV" durumu (Girilen fiyat KDV hariçtir, üstüne eklenir)
                matrah = netTutar;
                kdvTutar = matrah * 0.20;
                genelToplam = matrah + kdvTutar;
            }

            // HTML Elementlerini Güncelle
            document.getElementById("calc_ara").innerText = araToplam.toFixed(2) + " ₺";

            if (iskonto > 0) {
                document.getElementById("calc_iskonto_row").style.setProperty("display", "flex", "important");
                document.getElementById("calc_iskonto").innerText = "-" + iskontoTutar.toFixed(2) + " ₺";
            } else {
                document.getElementById("calc_iskonto_row").style.setProperty("display", "none", "important");
            }

            document.getElementById("calc_matrah").innerText = matrah.toFixed(2) + " ₺";
            document.getElementById("calc_kdv").innerText = kdvTutar.toFixed(2) + " ₺";
            document.getElementById("calc_toplam").innerText = genelToplam.toFixed(2) + " ₺";
        };

        document.addEventListener("DOMContentLoaded", function() {
            var urunInput = document.getElementById("urun_input");
            if (urunInput) {
                urunInput.addEventListener("input", function() {
                    var val = this.value;
                    var list = document.getElementById("hizmetListesi").options;
                    for (var i = 0; i < list.length; i++) {
                        if (list[i].value === val) {
                            var fiyatInput = document.getElementById("fiyat_input");
                            if(fiyatInput) { 
                                fiyatInput.value = list[i].getAttribute("data-fiyat"); 
                                if(typeof canliHesapla === 'function') canliHesapla(); // Veri otomatik dolunca hesaplamayı tetikle
                            }
                            break;
                        }
                    }
                });
            }
        });

        window.smsModalAc = function(hareket_id, telefon, mesaj) {
            document.getElementById("sms_gizli_hareket_id").value = hareket_id;
            document.getElementById("sms_goster_telefon").innerText = "+" + telefon; 
            document.getElementById("sms_goster_mesaj").value = mesaj;
            var modalEl = document.getElementById("modalSmsGonder");
            var myModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            myModal.show();
        };

        window.hareketDuzenle = function(btn) {
            var rawData = btn.getAttribute("data-hareket");
            if (!rawData) { alert("Veri okunamadı!"); return; }
            try { var data = JSON.parse(rawData); } catch(e) { return; }

            if(document.getElementById("edit_hareket_id")) document.getElementById("edit_hareket_id").value = data.id;
            if(document.getElementById("edit_islem_turu")) document.getElementById("edit_islem_turu").value = data.islem_turu;
            if(document.getElementById("edit_islem_notu")) document.getElementById("edit_islem_notu").value = data.notlar || "";
            if(document.getElementById("edit_hizmet_tarihi")) document.getElementById("edit_hizmet_tarihi").value = data.vade_tarihi ? data.vade_tarihi : ""; 
            if(data.islem_tarihi && document.getElementById("edit_tarih")) document.getElementById("edit_tarih").value = data.islem_tarihi.replace(" ", "T").substring(0, 16);

            var divSatis = document.getElementById("satis_alanlari");
            var divSatisAciklama = document.getElementById("satis_aciklama_alani");
            var divTahsilat = document.getElementById("tahsilat_alanlari");
            var divTahsilatTutar = document.getElementById("tahsilat_tutar_alani");
            var header = document.getElementById("editModalHeader");
            var submitBtn = document.getElementById("edit_submit_btn");

            if(data.islem_turu == "satis") {
                if(divSatis) divSatis.style.display = "block";
                if(divSatisAciklama) divSatisAciklama.style.display = "block";
                if(divTahsilat) divTahsilat.style.display = "none";
                if(divTahsilatTutar) divTahsilatTutar.style.display = "none";
                
                if(document.getElementById("edit_urun_aciklama")) document.getElementById("edit_urun_aciklama").value = data.urun_aciklama || "";
                if(document.getElementById("edit_birim_fiyat")) document.getElementById("edit_birim_fiyat").value = data.birim_fiyat;
                if(document.getElementById("edit_adet")) document.getElementById("edit_adet").value = data.adet;
                if(document.getElementById("edit_kdv_orani")) document.getElementById("edit_kdv_orani").value = data.kdv_orani;
                if(document.getElementById("edit_iskonto_orani")) document.getElementById("edit_iskonto_orani").value = data.iskonto_orani;
                
                header.className = "modal-header bg-primary text-white border-0";
                submitBtn.className = "btn btn-primary fw-bold rounded-pill px-4 shadow-sm";
            } else {
                if(divSatis) divSatis.style.display = "none";
                if(divSatisAciklama) divSatisAciklama.style.display = "none";
                if(divTahsilat) divTahsilat.style.display = "block";
                if(divTahsilatTutar) divTahsilatTutar.style.display = "block";
                
                if(document.getElementById("edit_tutar")) document.getElementById("edit_tutar").value = data.toplam_tutar;
                if(document.getElementById("edit_odeme_turu")) document.getElementById("edit_odeme_turu").value = data.odeme_turu || 0;
                
                header.className = "modal-header bg-success text-white border-0";
                submitBtn.className = "btn btn-success fw-bold rounded-pill px-4 shadow-sm";
            }
            var modalEl = document.getElementById("modalHareketDuzenle");
            if(modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
        };
        
        <?php if(isset($_SESSION['success_message'])): ?>
            if(typeof showYonetimToast === 'function') showYonetimToast("<?php echo addslashes($_SESSION['success_message']); ?>", "success");
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if(isset($_SESSION['error_message'])): ?>
            if(typeof showYonetimToast === 'function') showYonetimToast("<?php echo addslashes($_SESSION['error_message']); ?>", "danger");
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    </script>
    <?php require_once 'partials/footer_yonetim.php'; ?>
</body>
</html>