<?php
session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) { 
    header("Location: login.php"); 
    exit; 
}

$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

$firma_id = $_SESSION['firma_id'];
$user_id = $_SESSION['kullanici_id'];
$mesaj = ""; $mesajTuru = "";

// 2. YETKİ KONTROLÜ
$stmtRol = $db->prepare("SELECT rol FROM yoneticiler WHERE id = ?");
$stmtRol->execute([$user_id]);
if (!in_array($stmtRol->fetchColumn(), ['admin', 'super_admin'])) {
    die("<!DOCTYPE html><html lang='tr'><head><meta charset='UTF-8'><title>Yetkisiz Erişim</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center' style='height: 100vh;'><div class='text-center p-5 bg-white shadow rounded-4' style='max-width:500px;'><h3 class='fw-bold text-dark'>Yetkisiz Erişim!</h3><a href='index.php' class='btn btn-primary px-4 rounded-pill fw-bold mt-2'>Ana Sayfaya Dön</a></div></body></html>");
}

// Varsayılan kasa tespiti
$varsayilan_kasa_id = 0;
try {
    $varsayilan_kasa_sorgu = $db->query("SELECT id FROM finans_kasalar WHERE firma_id = '$firma_id' ORDER BY id ASC LIMIT 1");
    if ($varsayilan_kasa_sorgu) {
        $varsayilan_kasa_id = $varsayilan_kasa_sorgu->fetchColumn() ?: 0;
    }
} catch(Exception $e) {}

// ---------------------------------------------------------
// İŞLEMLER (POST/GET)
// ---------------------------------------------------------

// Personel Ekle/Düzenle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['personel_kaydet'])) {
    $p_id = !empty($_POST['personel_id']) ? (int)$_POST['personel_id'] : 0;
    
    $ad = trim($_POST['ad_soyad']);
    $tel = trim($_POST['telefon']);
    $tc = trim($_POST['tc_no']);
    $gorev = trim($_POST['gorevi']);
    $cihazlar = trim($_POST['cihazlar']);
    $yetkinlik = (int)$_POST['yetkinlik_seviyesi'];
    $calisma_tipi = $_POST['calisma_tipi'];
    $maas = (float)str_replace(',', '.', $_POST['sabit_maas']);
    $is_basi = (float)str_replace(',', '.', $_POST['is_basi_ucret']);
    $tarih = $_POST['ise_baslama_tarihi'];
    $durum = isset($_POST['durum']) ? (int)$_POST['durum'] : 1;

    // Fotoğraf Yükleme İşlemi
    $foto_yolu = null;
    if (isset($_FILES['foto_dosya']) && $_FILES['foto_dosya']['error'] == 0) {
        $upload_dir = 'uploads/personel/' . $firma_id . '/';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
        $uzanti = pathinfo($_FILES['foto_dosya']['name'], PATHINFO_EXTENSION);
        $dosya_adi = 'personel_' . time() . '_' . rand(100,999) . '.' . $uzanti;
        if (move_uploaded_file($_FILES['foto_dosya']['tmp_name'], $upload_dir . $dosya_adi)) {
            $foto_yolu = $upload_dir . $dosya_adi;
        }
    }

    if ($p_id > 0) {
        // Güncelleme
        if ($foto_yolu) {
            $db->prepare("UPDATE personeller SET ad_soyad=?, telefon=?, tc_no=?, gorevi=?, cihazlar=?, yetkinlik_seviyesi=?, calisma_tipi=?, sabit_maas=?, is_basi_ucret=?, ise_baslama_tarihi=?, durum=?, foto_yolu=? WHERE id=? AND firma_id=?")
               ->execute([$ad, $tel, $tc, $gorev, $cihazlar, $yetkinlik, $calisma_tipi, $maas, $is_basi, $tarih, $durum, $foto_yolu, $p_id, $firma_id]);
        } else {
            $db->prepare("UPDATE personeller SET ad_soyad=?, telefon=?, tc_no=?, gorevi=?, cihazlar=?, yetkinlik_seviyesi=?, calisma_tipi=?, sabit_maas=?, is_basi_ucret=?, ise_baslama_tarihi=?, durum=? WHERE id=? AND firma_id=?")
               ->execute([$ad, $tel, $tc, $gorev, $cihazlar, $yetkinlik, $calisma_tipi, $maas, $is_basi, $tarih, $durum, $p_id, $firma_id]);
        }
        header("Location: personel.php?msg=p_guncellendi"); exit;
    } else {
        // Yeni Kayıt
        $db->prepare("INSERT INTO personeller (firma_id, foto_yolu, ad_soyad, telefon, tc_no, gorevi, cihazlar, yetkinlik_seviyesi, calisma_tipi, sabit_maas, is_basi_ucret, ise_baslama_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$firma_id, $foto_yolu, $ad, $tel, $tc, $gorev, $cihazlar, $yetkinlik, $calisma_tipi, $maas, $is_basi, $tarih]);
        header("Location: personel.php?msg=p_eklendi"); exit;
    }
}

// Personel Sil (Soft Delete)
if (isset($_GET['p_sil'])) {
    $db->prepare("UPDATE personeller SET silindi = 1 WHERE id = ? AND firma_id = ?")->execute([(int)$_GET['p_sil'], $firma_id]);
    header("Location: personel.php?msg=p_silindi"); exit;
}

// Finansal Hareket Ekle / Düzenle (DÜZELTME ÖZELLİĞİ EKLENDİ)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hareket_kaydet'])) {
    $h_id = !empty($_POST['hareket_id']) ? (int)$_POST['hareket_id'] : 0;
    
    $p_id = (int)$_POST['personel_id'];
    $islem_turu = $_POST['islem_turu'];
    $tutar = (float)str_replace(',', '.', $_POST['tutar']);
    $islem_tarihi = $_POST['islem_tarihi'];
    $aciklama = trim($_POST['aciklama']);
    $kasa_id = !empty($_POST['kasa_id']) ? (int)$_POST['kasa_id'] : $varsayilan_kasa_id;

    try {
        $db->beginTransaction();
        
        // EĞER DÜZENLEME İSE: Önce eski kaydı ve ksadaki etkisini sil (Temiz bir sayfa aç)
        if ($h_id > 0) {
            $eski = $db->prepare("SELECT * FROM personel_hareketleri WHERE id = ? AND firma_id = ?");
            $eski->execute([$h_id, $firma_id]);
            $eski_data = $eski->fetch(PDO::FETCH_ASSOC);
            
            if ($eski_data) {
                if ($eski_data['islem_turu'] == 'maas_odeme' || $eski_data['islem_turu'] == 'avans_odeme') {
                    $db->prepare("DELETE FROM finans_kasa_hareketleri WHERE baglanti_tipi = 'maas' AND baglanti_id = ? AND firma_id = ?")->execute([$h_id, $firma_id]);
                }
                $db->prepare("DELETE FROM personel_hareketleri WHERE id = ? AND firma_id = ?")->execute([$h_id, $firma_id]);
            }
        }

        // Yeni veya Düzenlenmiş kaydı INSERT et
        $db->prepare("INSERT INTO personel_hareketleri (firma_id, personel_id, kasa_id, islem_turu, tutar, aciklama, islem_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$firma_id, $p_id, $islem_turu == 'maas_odeme' || $islem_turu == 'avans_odeme' ? $kasa_id : null, $islem_turu, $tutar, $aciklama, $islem_tarihi]);
        $yeni_hareket_id = $db->lastInsertId();

        // Eğer şirketten para çıkıyorsa Kasadan düş
        if ($islem_turu == 'maas_odeme' || $islem_turu == 'avans_odeme') {
            $p_info = $db->query("SELECT ad_soyad FROM personeller WHERE id = $p_id")->fetch(PDO::FETCH_ASSOC);
            $ad_soyad = $p_info ? $p_info['ad_soyad'] : 'Bilinmeyen Personel';
            $kasa_aciklama = "Personel Ödemesi: " . $ad_soyad . " (" . ($islem_turu == 'maas_odeme' ? 'Maaş' : 'Avans') . ") - " . $aciklama;
            
            $db->prepare("INSERT INTO finans_kasa_hareketleri (firma_id, kasa_id, islem_turu, tutar, aciklama, baglanti_tipi, baglanti_id) VALUES (?, ?, 'cikis', ?, ?, 'maas', ?)")
               ->execute([$firma_id, $kasa_id, $tutar, $kasa_aciklama, $yeni_hareket_id]);
        }

        $db->commit();
        header("Location: personel.php?tab=hareketler&msg=islem_basarili"); exit;
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: personel.php?tab=hareketler&msg=hata"); exit;
    }
}

// Finansal Hareket Sil
if (isset($_GET['islem_sil'])) {
    $h_id = (int)$_GET['islem_sil'];
    try {
        $db->beginTransaction();
        $islem = $db->prepare("SELECT * FROM personel_hareketleri WHERE id = ? AND firma_id = ?");
        $islem->execute([$h_id, $firma_id]);
        $h_data = $islem->fetch(PDO::FETCH_ASSOC);

        if($h_data) {
            if ($h_data['islem_turu'] == 'maas_odeme' || $h_data['islem_turu'] == 'avans_odeme') {
                $db->prepare("DELETE FROM finans_kasa_hareketleri WHERE baglanti_tipi = 'maas' AND baglanti_id = ? AND firma_id = ?")->execute([$h_id, $firma_id]);
            }
            $db->prepare("DELETE FROM personel_hareketleri WHERE id = ? AND firma_id = ?")->execute([$h_id, $firma_id]);
            $db->commit();
            header("Location: personel.php?tab=hareketler&msg=islem_silindi"); exit;
        }
    } catch (Exception $e) { $db->rollBack(); header("Location: personel.php?msg=hata"); exit; }
}

// İzin Ekleme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['izin_ekle'])) {
    $p_id = (int)$_POST['personel_id'];
    $baslangic = $_POST['baslangic_tarihi'];
    $bitis = $_POST['bitis_tarihi'];
    $sebep = trim($_POST['sebep']);
    
    $db->prepare("INSERT INTO personel_izinler (firma_id, personel_id, baslangic_tarihi, bitis_tarihi, sebep) VALUES (?, ?, ?, ?, ?)")
       ->execute([$firma_id, $p_id, $baslangic, $bitis, $sebep]);
    header("Location: personel.php?tab=izinler&msg=izin_eklendi"); exit;
}

if (isset($_GET['izin_sil'])) {
    $db->prepare("DELETE FROM personel_izinler WHERE id = ? AND firma_id = ?")->execute([(int)$_GET['izin_sil'], $firma_id]);
    header("Location: personel.php?tab=izinler&msg=izin_silindi"); exit;
}

// Mesaj Yönetimi
if(isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if($m=='p_eklendi') { $mesaj = "Personel sisteme eklendi."; $mesajTuru = "success"; }
    elseif($m=='p_guncellendi') { $mesaj = "Personel bilgileri güncellendi."; $mesajTuru = "success"; }
    elseif($m=='p_silindi') { $mesaj = "Personel kaydı silindi."; $mesajTuru = "warning"; }
    elseif($m=='islem_basarili') { $mesaj = "Finansal hareket başarıyla kaydedildi/güncellendi."; $mesajTuru = "success"; }
    elseif($m=='islem_silindi') { $mesaj = "Kayıt iptal edildi, kasadan düşülen tutar iade edildi."; $mesajTuru = "warning"; }
    elseif($m=='izin_eklendi') { $mesaj = "İzin kaydı eklendi."; $mesajTuru = "success"; }
    elseif($m=='izin_silindi') { $mesaj = "İzin kaydı iptal edildi."; $mesajTuru = "warning"; }
    elseif($m=='hata') { $mesaj = "Kritik bir hata oluştu!"; $mesajTuru = "danger"; }
}

// ---------------------------------------------------------
// VERİ ÇEKME İŞLEMLERİ
// ---------------------------------------------------------
$ay_baslangic = date('Y-m-01');
$ay_bitis = date('Y-m-t');

$personeller = [];
$hareketler = [];
$kasalar = [];
$izinler = [];

try {
    $p_sorgu = $db->query("
        SELECT p.*,
        COALESCE((SELECT SUM(tutar) FROM personel_hareketleri WHERE personel_id = p.id AND islem_turu IN ('maas_hakedis', 'is_hakedis', 'prim_hakedis')), 0) as toplam_hakedis,
        COALESCE((SELECT SUM(tutar) FROM personel_hareketleri WHERE personel_id = p.id AND islem_turu IN ('maas_odeme', 'avans_odeme')), 0) as toplam_odenen,
        (SELECT COUNT(*) FROM personel_hareketleri WHERE personel_id = p.id AND islem_turu = 'is_hakedis') as is_sayisi
        FROM personeller p 
        WHERE p.firma_id = '$firma_id' AND p.silindi = 0 
        ORDER BY p.durum DESC, p.ad_soyad ASC
    ");
    if($p_sorgu) $personeller = $p_sorgu->fetchAll(PDO::FETCH_ASSOC);

    $h_sorgu = $db->query("
        SELECT h.*, p.ad_soyad, ks.kasa_adi
        FROM personel_hareketleri h
        JOIN personeller p ON h.personel_id = p.id
        LEFT JOIN finans_kasalar ks ON h.kasa_id = ks.id
        WHERE h.firma_id = '$firma_id'
        ORDER BY h.islem_tarihi DESC, h.id DESC
    ");
    if($h_sorgu) $hareketler = $h_sorgu->fetchAll(PDO::FETCH_ASSOC);

    $k_sorgu = $db->query("SELECT id, kasa_adi, para_birimi FROM finans_kasalar WHERE firma_id = '$firma_id' ORDER BY id ASC");
    if($k_sorgu) $kasalar = $k_sorgu->fetchAll(PDO::FETCH_ASSOC);
    
    $i_sorgu = $db->query("
        SELECT i.*, p.ad_soyad, p.foto_yolu 
        FROM personel_izinler i 
        JOIN personeller p ON i.personel_id = p.id 
        WHERE i.firma_id = '$firma_id' 
        ORDER BY i.baslangic_tarihi DESC
    ");
    if($i_sorgu) $izinler = $i_sorgu->fetchAll(PDO::FETCH_ASSOC);
    
} catch(Exception $e) {
    $mesaj = "Veritabanı tabloları eksik! Lütfen SQL güncellemesini çalıştırın.";
    $mesajTuru = "danger";
}

$istatistikler = ['toplam_personel' => count($personeller), 'icerideki_bakiye' => 0, 'bu_ay_odenen' => 0, 'bugun_izinli' => 0];
$bugun = date('Y-m-d');

foreach($izinler as $iz) {
    if ($bugun >= $iz['baslangic_tarihi'] && $bugun <= $iz['bitis_tarihi']) {
        $istatistikler['bugun_izinli']++;
    }
}

foreach($personeller as &$p) {
    $bakiye = $p['toplam_hakedis'] - $p['toplam_odenen'];
    $p['guncel_bakiye'] = $bakiye;
    if($bakiye > 0) $istatistikler['icerideki_bakiye'] += $bakiye;
}
unset($p);

try {
    $ist_sorgu = $db->query("SELECT COALESCE(SUM(tutar),0) FROM personel_hareketleri WHERE firma_id = '$firma_id' AND islem_turu IN ('maas_odeme', 'avans_odeme') AND islem_tarihi BETWEEN '$ay_baslangic' AND '$ay_bitis'");
    if($ist_sorgu) $istatistikler['bu_ay_odenen'] = $ist_sorgu->fetchColumn();
} catch(Exception $e) {}

// Grafikler İçin Veri
$trend_veri = $db->query("SELECT MONTH(islem_tarihi) as ay, SUM(tutar) as top FROM personel_hareketleri WHERE firma_id = '$firma_id' AND islem_turu IN ('maas_odeme', 'avans_odeme') AND YEAR(islem_tarihi) = YEAR(CURDATE()) GROUP BY MONTH(islem_tarihi) ORDER BY ay ASC")->fetchAll(PDO::FETCH_ASSOC);
$aylar_tr = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
$ag_labels = []; $ag_data = [];
for($i=1; $i<=12; $i++) {
    $ag_labels[] = $aylar_tr[$i];
    $bulundu = false;
    foreach($trend_veri as $av) { if($av['ay'] == $i) { $ag_data[] = $av['top']; $bulundu = true; break; } }
    if(!$bulundu) $ag_data[] = 0;
}

$activeTab = $_GET['tab'] ?? 'personeller';
$page_title = "İnsan Kaynakları & Personel";
$personellerJSON = json_encode($personeller, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
$hareketlerJSON = json_encode($hareketler, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="css/yonetim.css">
    <style>
        body { background-color: #f8f9fc; }
        .stat-card { border: none; border-radius: 12px; box-shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.1); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .icon-circle { height: 3rem; width: 3rem; border-radius: 100%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .nav-tabs-custom { border-bottom: 2px solid #eaecf4; }
        .nav-tabs-custom .nav-link { border: none; color: #858796; font-weight: 600; padding: 1rem 1.5rem; border-bottom: 3px solid transparent; }
        .nav-tabs-custom .nav-link.active { color: #4e73df; border-bottom-color: #4e73df; background: transparent; }
        table.dataTable tbody tr { transition: 0.2s; background-color: #fff; }
        table.dataTable tbody tr:hover { background-color: #f8f9fa; }
        
        /* Personel Avatar ve Grid Tasarımı */
        .avatar-circle { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #e9ecef; }
        .avatar-lg { width: 80px; height: 80px; }
        .personel-card { border: 1px solid #e9ecef; border-radius: 15px; background: #fff; transition: 0.3s; position: relative; overflow: hidden; }
        .personel-card:hover { box-shadow: 0 10px 25px rgba(0,0,0,0.08); border-color: #4e73df; transform: translateY(-5px); }
        .star-rating i { color: #ffc107; font-size: 12px; }
        .star-rating i.empty { color: #e9ecef; }
        .bg-pattern { background-image: radial-gradient(#4e73df 1px, transparent 1px); background-size: 10px 10px; background-color: #f8f9fc; opacity: 0.3; position: absolute; top:0; left:0; right:0; height:80px; z-index:0; }
    </style>
</head>
<body class="yonetim-body">
    <?php include 'partials/navbar.php'; ?>
    <div class="container-fluid pb-5 px-4 mt-4">
        
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h3 class="fw-bold text-gray-800 mb-1"><i class="fas fa-users-cog text-primary me-2"></i>İnsan Kaynakları (HR)</h3>
                <p class="text-muted small mb-0">Personel takibi, izinler, finansal hakedişler ve performans analizleri.</p>
            </div>
            <div class="col-md-6 text-end">
                <button class="btn btn-dark fw-bold shadow-sm px-4 rounded-pill me-2" data-bs-toggle="modal" data-bs-target="#hareketModal" onclick="resetHareketForm()"><i class="fas fa-lira-sign me-2"></i>Finans İşlemi</button>
                <button class="btn btn-primary fw-bold shadow-sm px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#personelModal" onclick="resetPersonelForm()"><i class="fas fa-plus me-2"></i>Personel Ekle</button>
            </div>
        </div>

        <?php if($mesaj): ?>
            <div class="alert alert-<?= $mesajTuru ?> alert-dismissible fade show shadow-sm border-0 rounded-3">
                <?= $mesaj ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card border-start border-primary border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-primary text-uppercase mb-1">Kayıtlı Çalışan</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= $istatistikler['toplam_personel'] ?> Kişi</div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-primary bg-opacity-10 text-primary"><i class="fas fa-users"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card border-start border-warning border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-warning text-uppercase mb-1">Bugün İzinli / Yok</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= $istatistikler['bugun_izinli'] ?> Kişi</div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-warning bg-opacity-10 text-warning"><i class="fas fa-umbrella-beach"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card border-start border-danger border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-danger text-uppercase mb-1">İçeride Kalan Alacak</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= number_format($istatistikler['icerideki_bakiye'], 2, ',', '.') ?> ₺</div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-danger bg-opacity-10 text-danger"><i class="fas fa-hand-holding-usd"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card border-start border-success border-4 h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs fw-bold text-success text-uppercase mb-1">Bu Ay Ödenen</div>
                                <div class="h4 mb-0 fw-bold text-gray-800"><?= number_format($istatistikler['bu_ay_odenen'], 2, ',', '.') ?> ₺</div>
                            </div>
                            <div class="col-auto"><div class="icon-circle bg-success bg-opacity-10 text-success"><i class="fas fa-money-bill-wave"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <ul class="nav nav-tabs-custom bg-white" role="tablist">
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'personeller' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#personellerTab"><i class="fas fa-users me-2"></i>Personeller</button></li>
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'izinler' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#izinlerTab"><i class="fas fa-calendar-times me-2"></i>İzin Yönetimi</button></li>
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'hareketler' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#hareketlerTab"><i class="fas fa-lira-sign me-2"></i>Maaş & Finans</button></li>
                <li class="nav-item"><button class="nav-link <?= $activeTab == 'analiz' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#analizTab"><i class="fas fa-chart-line me-2"></i>İK Analiz</button></li>
            </ul>

            <div class="tab-content bg-white p-4">
                
                <!-- 1. PERSONEL LİSTESİ -->
                <div class="tab-pane fade <?= $activeTab == 'personeller' ? 'show active' : '' ?>" id="personellerTab">
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold m-0">Kayıtlı Çalışanlar</h6>
                        <div class="btn-group shadow-sm">
                            <button class="btn btn-outline-secondary active" id="btnListView" onclick="toggleView('list')"><i class="fas fa-list"></i> Tablo</button>
                            <button class="btn btn-outline-secondary" id="btnGridView" onclick="toggleView('grid')"><i class="fas fa-th-large"></i> Kart (Grid)</button>
                        </div>
                    </div>

                    <!-- TABLO GÖRÜNÜMÜ -->
                    <div id="listViewArea">
                        <div class="table-responsive">
                            <table id="personelTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Personel</th>
                                        <th>Görev & Tipi</th>
                                        <th>Ekipmanlar</th>
                                        <th class="text-end">Hakediş Özeti</th>
                                        <th class="text-end">Güncel Bakiye</th>
                                        <th class="text-center">Durum</th>
                                        <th class="text-end" data-orderable="false">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($personeller as $p): ?>
                                    <tr <?= $p['durum'] == 0 ? 'class="opacity-50"' : '' ?>>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php if($p['foto_yolu']): ?>
                                                    <img src="<?= htmlspecialchars($p['foto_yolu']) ?>" class="avatar-circle me-3">
                                                <?php else: ?>
                                                    <div class="avatar-circle me-3 bg-light d-flex align-items-center justify-content-center text-primary fw-bold fs-4"><?= mb_strtoupper(mb_substr($p['ad_soyad'], 0, 1)) ?></div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($p['ad_soyad']) ?></div>
                                                    <div class="small text-muted"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($p['telefon'] ?? '-') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border mb-1"><?= htmlspecialchars($p['gorevi']) ?></span>
                                            <div class="star-rating mb-1">
                                                <?php for($i=1; $i<=5; $i++) echo $i<=$p['yetkinlik_seviyesi'] ? '<i class="fas fa-star"></i>' : '<i class="fas fa-star empty"></i>'; ?>
                                            </div>
                                            <?php if($p['calisma_tipi'] == 'is_basi'): ?>
                                                <span class="badge bg-success">İş Başı: <?= number_format($p['is_basi_ucret'], 2) ?> ₺</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">Sabit Maaş: <?= number_format($p['sabit_maas'], 2) ?> ₺</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small fw-bold text-muted text-wrap" style="max-width: 150px;"><?= htmlspecialchars($p['cihazlar'] ?? 'Ekipman belirtilmemiş') ?></div>
                                        </td>
                                        <td class="text-end small">
                                            <div class="text-secondary fw-bold">+ <?= number_format($p['toplam_hakedis'], 2) ?> ₺</div>
                                            <div class="text-success fw-bold">- <?= number_format($p['toplam_odenen'], 2) ?> ₺</div>
                                        </td>
                                        <td class="text-end fw-bold fs-6 <?= $p['guncel_bakiye'] > 0 ? 'text-danger' : ($p['guncel_bakiye'] < 0 ? 'text-success' : 'text-dark') ?>">
                                            <?= number_format($p['guncel_bakiye'], 2, ',', '.') ?> ₺
                                        </td>
                                        <td class="text-center">
                                            <?= $p['durum'] == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Ayrıldı</span>' ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-dark" onclick="openHareketModal(<?= $p['id'] ?>)" title="Para Ekle/Öde"><i class="fas fa-lira-sign"></i></button>
                                            <button class="btn btn-sm btn-outline-primary" onclick='editPersonel(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Düzenle"><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deletePersonel(<?= $p['id'] ?>)" title="Sil"><i class="fas fa-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- KART (GRID) GÖRÜNÜMÜ -->
                    <div id="gridViewArea" style="display:none;">
                        <div class="row g-4">
                            <?php foreach($personeller as $p): ?>
                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <div class="personel-card text-center pb-3 h-100 <?= $p['durum'] == 0 ? 'opacity-50' : '' ?>">
                                    <div class="bg-pattern"></div>
                                    <div class="position-relative pt-4 z-1">
                                        <?php if($p['foto_yolu']): ?>
                                            <img src="<?= htmlspecialchars($p['foto_yolu']) ?>" class="avatar-circle avatar-lg mb-3 shadow-sm bg-white p-1">
                                        <?php else: ?>
                                            <div class="avatar-circle avatar-lg mx-auto mb-3 bg-white d-flex align-items-center justify-content-center text-primary fw-bold display-6 shadow-sm"><?= mb_strtoupper(mb_substr($p['ad_soyad'], 0, 1)) ?></div>
                                        <?php endif; ?>
                                        
                                        <h5 class="fw-bold text-dark mb-1 px-2 text-truncate"><?= htmlspecialchars($p['ad_soyad']) ?></h5>
                                        <div class="badge bg-light text-dark border mb-2"><?= htmlspecialchars($p['gorevi']) ?></div>
                                        <div class="star-rating mb-3">
                                            <?php for($i=1; $i<=5; $i++) echo $i<=$p['yetkinlik_seviyesi'] ? '<i class="fas fa-star"></i>' : '<i class="fas fa-star empty"></i>'; ?>
                                        </div>
                                    </div>
                                    <div class="px-3 text-start small mb-3 border-top border-bottom py-2 bg-light">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted fw-bold">Tipi:</span>
                                            <span class="fw-bold"><?= $p['calisma_tipi'] == 'sabit' ? 'Sabit Maaşlı' : 'İş Başı / Serbest' ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted fw-bold">İletişim:</span>
                                            <span class="fw-bold"><?= htmlspecialchars($p['telefon'] ?? '-') ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between text-truncate" title="<?= htmlspecialchars($p['cihazlar']) ?>">
                                            <span class="text-muted fw-bold">Cihaz:</span>
                                            <span class="fw-bold text-end" style="max-width: 120px;"><?= htmlspecialchars($p['cihazlar'] ?? '-') ?></span>
                                        </div>
                                    </div>
                                    <div class="px-3 d-flex justify-content-between align-items-center mb-3">
                                        <span class="text-muted small fw-bold">İçerideki Bakiye:</span>
                                        <h5 class="fw-bold mb-0 <?= $p['guncel_bakiye'] > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($p['guncel_bakiye'], 2, ',', '.') ?> ₺</h5>
                                    </div>
                                    <div class="px-3 d-flex gap-2 justify-content-center">
                                        <button class="btn btn-sm btn-dark w-100 fw-bold rounded-pill" onclick="openHareketModal(<?= $p['id'] ?>)"><i class="fas fa-lira-sign"></i> Finans</button>
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick='editPersonel(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

                <!-- 2. İZİN YÖNETİMİ -->
                <div class="tab-pane fade <?= $activeTab == 'izinler' ? 'show active' : '' ?>" id="izinlerTab">
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Personel</th>
                                            <th>Başlangıç</th>
                                            <th>Bitiş (Dahil)</th>
                                            <th>Açıklama / Sebep</th>
                                            <th class="text-center">Durum</th>
                                            <th class="text-end">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($izinler)): ?><tr><td colspan="6" class="text-center text-muted py-4">Sistemde kayıtlı izin bulunamadı.</td></tr><?php endif; ?>
                                        <?php foreach($izinler as $iz): 
                                            $b_date = strtotime($iz['baslangic_tarihi']);
                                            $e_date = strtotime($iz['bitis_tarihi']);
                                            $gun = round(($e_date - $b_date) / 86400) + 1;
                                            
                                            $durum_renk = '';
                                            if (date('Y-m-d') > $iz['bitis_tarihi']) $durum_renk = 'bg-secondary text-white'; // Geçti
                                            elseif (date('Y-m-d') >= $iz['baslangic_tarihi']) $durum_renk = 'bg-warning text-dark'; // Şu an izinde
                                            else $durum_renk = 'bg-info text-white'; // Gelecek
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if($iz['foto_yolu']): ?><img src="<?= htmlspecialchars($iz['foto_yolu']) ?>" class="avatar-circle me-2" style="width:30px; height:30px;"><?php endif; ?>
                                                    <span class="fw-bold text-dark"><?= htmlspecialchars($iz['ad_soyad']) ?></span>
                                                </div>
                                            </td>
                                            <td class="fw-bold"><?= date('d.m.Y', $b_date) ?></td>
                                            <td class="fw-bold"><?= date('d.m.Y', $e_date) ?> <span class="badge bg-light text-dark border ms-1"><?= $gun ?> Gün</span></td>
                                            <td class="small text-muted fw-bold"><?= htmlspecialchars($iz['sebep']) ?></td>
                                            <td class="text-center"><span class="badge <?= $durum_renk ?>"><?= date('Y-m-d') > $iz['bitis_tarihi'] ? 'Tamamlandı' : (date('Y-m-d') >= $iz['baslangic_tarihi'] ? 'Şu An İzinde' : 'Yaklaşan İzin') ?></span></td>
                                            <td class="text-end">
                                                <a href="?izin_sil=<?= $iz['id'] ?>" class="btn btn-sm btn-outline-danger border-0" onclick="return confirm('Bu izni silmek istediğinize emin misiniz?')"><i class="fas fa-trash"></i></a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card bg-light border-0 shadow-sm rounded-4">
                                <div class="card-body p-4">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-calendar-plus text-primary me-2"></i>Yeni İzin / Rapor Ekle</h6>
                                    <form method="POST">
                                        <input type="hidden" name="izin_ekle" value="1">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">Personel Seçin</label>
                                            <select name="personel_id" class="form-select fw-bold" required>
                                                <?php foreach($personeller as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ad_soyad']) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="row g-2 mb-3">
                                            <div class="col-6">
                                                <label class="form-label small fw-bold text-muted">Başlangıç Tarihi</label>
                                                <input type="date" name="baslangic_tarihi" class="form-control" required value="<?= date('Y-m-d') ?>">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small fw-bold text-muted">Bitiş Tarihi</label>
                                                <input type="date" name="bitis_tarihi" class="form-control" required value="<?= date('Y-m-d') ?>">
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">Açıklama (Hastalık, Yıllık İzin vb.)</label>
                                            <input type="text" name="sebep" class="form-control" required>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill shadow-sm"><i class="fas fa-save me-1"></i> İzni Kaydet</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. FİNANSAL HAREKETLER (DÜZELTME ÖZELLİKLİ) -->
                <div class="tab-pane fade <?= $activeTab == 'hareketler' ? 'show active' : '' ?>" id="hareketlerTab">
                    <div class="alert alert-info small border-0 shadow-sm">
                        <i class="fas fa-info-circle me-1"></i> Yanlış girdiğiniz bir ödemeyi veya hakedişi <b>Düzenle</b> butonuna basarak saniyeler içinde onarabilirsiniz. Sistem kasayı otomatik düzeltir.
                    </div>
                    <div class="table-responsive">
                        <table id="hareketlerTable" class="table table-hover align-middle w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Tarih</th>
                                    <th>Personel</th>
                                    <th>İşlem Türü</th>
                                    <th>Açıklama / Kasa</th>
                                    <th class="text-end">Tutar (₺)</th>
                                    <th class="text-end" data-orderable="false">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($hareketler as $h): ?>
                                <tr>
                                    <td data-order="<?= date('Ymd', strtotime($h['islem_tarihi'])) ?>"><span class="fw-bold text-dark"><?= date('d.m.Y', strtotime($h['islem_tarihi'])) ?></span></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($h['ad_soyad']) ?></td>
                                    <td>
                                        <?php if($h['islem_turu'] == 'maas_hakedis'): ?><span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="fas fa-plus-circle me-1"></i>Sabit Maaş (Bize Borç)</span>
                                        <?php elseif($h['islem_turu'] == 'is_hakedis'): ?><span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="fas fa-camera me-1"></i>İş Başı Ücreti (Bize Borç)</span>
                                        <?php elseif($h['islem_turu'] == 'prim_hakedis'): ?><span class="badge bg-warning bg-opacity-10 text-warning text-dark border border-warning"><i class="fas fa-gift me-1"></i>Prim/Mesai Eklendi</span>
                                        <?php elseif($h['islem_turu'] == 'maas_odeme'): ?><span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-check-circle me-1"></i>Maaş Ödemesi Yapıldı</span>
                                        <?php else: ?><span class="badge bg-primary bg-opacity-10 text-primary border border-primary"><i class="fas fa-money-bill-wave me-1"></i>Avans Verildi</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small fw-bold text-muted"><?= htmlspecialchars($h['aciklama']) ?></div>
                                        <?php if($h['kasa_adi']): ?><div class="small text-primary"><i class="fas fa-wallet me-1"></i>Çıkış: <?= htmlspecialchars($h['kasa_adi']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold <?= ($h['islem_turu']=='maas_odeme' || $h['islem_turu']=='avans_odeme') ? 'text-success' : 'text-danger' ?>">
                                        <?= number_format($h['tutar'], 2, ',', '.') ?> ₺
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary border-0" onclick='editHareket(<?= json_encode($h, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Düzelt"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-sm btn-outline-danger border-0" onclick="deleteHareket(<?= $h['id'] ?>)" title="İptal Et"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 4. İK ANALİZLERİ (GRAFİKLER) -->
                <div class="tab-pane fade <?= $activeTab == 'analiz' ? 'show active' : '' ?>" id="analizTab">
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="card border border-light shadow-sm h-100 rounded-4">
                                <div class="card-body p-4 text-center">
                                    <h6 class="fw-bold text-dark mb-4"><i class="fas fa-chart-area text-primary me-2"></i>Aylık Personel Ödemeleri Trendi (<?= date('Y') ?>)</h6>
                                    <div style="height: 350px; position: relative;">
                                        <canvas id="ikTrendChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card border-light shadow-sm h-100 rounded-4 p-4 text-center d-flex flex-column align-items-center justify-content-center bg-primary bg-opacity-10">
                                <i class="fas fa-chart-pie fa-4x text-primary mb-3 opacity-50"></i>
                                <h5 class="fw-bold text-dark mb-2">İK Modülü Aktif!</h5>
                                <p class="text-muted small">Gelecek sürümlerde performans dağılım grafikleri eklenecektir.</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- PERSONEL EKLE/DÜZENLE MODAL -->
    <div class="modal fade" id="personelModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" id="personelForm" enctype="multipart/form-data">
                    <input type="hidden" name="personel_kaydet" value="1">
                    <input type="hidden" name="personel_id" id="p_id" value="">
                    <div class="modal-header bg-primary text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold" id="p_modal_title"><i class="fas fa-user-plus me-2"></i>Yeni Personel Kaydı</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <div class="row g-3 bg-white p-3 rounded-4 shadow-sm border mb-3">
                            <div class="col-md-3 text-center">
                                <div class="position-relative d-inline-block" style="cursor:pointer;" onclick="document.getElementById('fotoUpload').click()">
                                    <img id="fotoPreview" src="https://via.placeholder.com/100x100?text=+" class="rounded-circle shadow-sm border border-2 border-primary" style="width:100px; height:100px; object-fit:cover;">
                                    <span class="position-absolute bottom-0 end-0 bg-primary text-white rounded-circle p-1 shadow" style="font-size:10px;"><i class="fas fa-camera"></i></span>
                                </div>
                                <input type="file" name="foto_dosya" id="fotoUpload" accept="image/*" class="d-none" onchange="previewImage(this)">
                                <small class="d-block mt-2 text-muted fw-bold">Fotoğraf Ekle</small>
                            </div>
                            <div class="col-md-9 row g-3 m-0">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Ad Soyad <span class="text-danger">*</span></label>
                                    <input type="text" name="ad_soyad" id="p_ad" class="form-control fw-bold text-dark" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Görev / Ünvan</label>
                                    <input type="text" name="gorevi" id="p_gorev" class="form-control" placeholder="Örn: Kameraman, Asistan">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Yetkinlik (Yıldız)</label>
                                    <select name="yetkinlik_seviyesi" id="p_yetkinlik" class="form-select fw-bold text-warning">
                                        <option value="1">⭐ (1 Yıldız)</option><option value="2">⭐⭐ (2 Yıldız)</option><option value="3" selected>⭐⭐⭐ (3 Yıldız)</option><option value="4">⭐⭐⭐⭐ (4 Yıldız)</option><option value="5">⭐⭐⭐⭐⭐ (5 Yıldız Uzman)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Kullandığı Cihaz / Ekipman</label>
                                    <input type="text" name="cihazlar" id="p_cihazlar" class="form-control" placeholder="Örn: Sony A7III, DJI Mavic 3">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 bg-white p-3 rounded-4 shadow-sm border">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Çalışma Tipi <span class="text-danger">*</span></label>
                                <select name="calisma_tipi" id="p_calisma_tipi" class="form-select fw-bold border-dark" onchange="toggleCalismaTipi()">
                                    <option value="sabit">Sabit Maaşlı (Aylık)</option>
                                    <option value="is_basi">İş Başı / Part-Time (Serbest)</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6" id="maas_alani">
                                <label class="form-label small fw-bold text-muted">Sabit Maaş (Aylık)</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="sabit_maas" id="p_maas" class="form-control fw-bold text-primary border-primary" value="0">
                                    <span class="input-group-text bg-white text-primary">₺</span>
                                </div>
                            </div>
                            
                            <div class="col-md-6" id="is_basi_alani" style="display:none;">
                                <label class="form-label small fw-bold text-muted">İş Başı Ücreti (Yevmiye)</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="is_basi_ucret" id="p_is_basi" class="form-control fw-bold text-success border-success" value="0">
                                    <span class="input-group-text bg-white text-success">₺</span>
                                </div>
                            </div>

                            <div class="col-12"><hr class="my-1 border-light"></div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">İşe Başlama Tarihi</label>
                                <input type="date" name="ise_baslama_tarihi" id="p_tarih" class="form-control fw-bold" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">Telefon</label>
                                <input type="text" name="telefon" id="p_tel" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">TC Kimlik No</label>
                                <input type="text" name="tc_no" id="p_tc" class="form-control">
                            </div>
                            
                            <div class="col-md-12" id="durumWrapper" style="display:none;">
                                <label class="form-label small fw-bold text-muted">Çalışma Durumu</label>
                                <select name="durum" id="p_durum" class="form-select fw-bold">
                                    <option value="1">Şu An Çalışıyor</option>
                                    <option value="0">İşten Ayrıldı</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 bg-white py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary px-4 fw-bold rounded-pill" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm rounded-pill"><i class="fas fa-save me-1"></i>Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- FİNANS HAREKET MODAL (MAAŞ/AVANS/PRİM/İŞ BAŞI VE DÜZELTME) -->
    <div class="modal fade" id="hareketModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" id="hareketForm">
                    <input type="hidden" name="hareket_kaydet" value="1">
                    <input type="hidden" name="hareket_id" id="h_id" value=""> <!-- Edit için -->
                    
                    <div class="modal-header bg-dark text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold" id="h_modal_title"><i class="fas fa-lira-sign me-2"></i>Personel Finans İşlemi</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <div class="row g-3 bg-white p-3 rounded-4 shadow-sm border mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Hangi Personel?</label>
                                <select name="personel_id" id="h_personel" class="form-select fw-bold" required onchange="fillHakedisTutar()">
                                    <option value="">Seçiniz...</option>
                                    <?php foreach($personeller as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ad_soyad']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">İşlem Türü</label>
                                <select name="islem_turu" id="h_tur" class="form-select fw-bold border-dark" required onchange="toggleKasaVeTutar()">
                                    <option value="">Seçiniz...</option>
                                    <optgroup label="Şirket Borçlanır (Personel Alacaklı)">
                                        <option value="is_hakedis">İş Başı / Yevmiye Ekle (+ Borç)</option>
                                        <option value="maas_hakedis">Sabit Maaş Hakedişi Ekle (+ Borç)</option>
                                        <option value="prim_hakedis">Prim / Mesai Ekle (+ Borç)</option>
                                    </optgroup>
                                    <optgroup label="Şirket Ödeme Yapar (Kasadan Çıkar)">
                                        <option value="maas_odeme">Maaş/Yevmiye Ödemesi Yap (- Borç)</option>
                                        <option value="avans_odeme">Avans Ver (- Borç)</option>
                                    </optgroup>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-12" id="kasaSecimAlani" style="display:none;">
                                <label class="form-label small fw-bold text-danger"><i class="fas fa-wallet me-1"></i>Para Hangi Kasadan Çıkacak?</label>
                                <select name="kasa_id" id="h_kasa" class="form-select fw-bold border-danger">
                                    <?php foreach($kasalar as $k): ?><option value="<?= $k['id'] ?>" <?= $k['id'] == $varsayilan_kasa_id ? 'selected' : '' ?>><?= htmlspecialchars($k['kasa_adi']) ?></option><?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Tutar</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="tutar" id="h_tutar" class="form-control fw-bold fs-5 text-dark" required placeholder="0.00">
                                    <span class="input-group-text bg-white fw-bold">₺</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">İşlem Tarihi</label>
                                <input type="date" name="islem_tarihi" id="h_tarih" class="form-control fw-bold" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">Açıklama (Hangi İş, Hangi Ay?)</label>
                                <input type="text" name="aciklama" id="h_aciklama" class="form-control" required placeholder="Örn: Ekim Ayı Maaşı, Ahmet'in Düğünü Çekimi...">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 bg-white py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary px-4 fw-bold rounded-pill" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-dark px-5 fw-bold shadow-sm rounded-pill"><i class="fas fa-save me-1"></i>İşlemi Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include 'partials/footer_yonetim.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        const personellerData = <?= $personellerJSON ?>;
        const hareketlerData = <?= $hareketlerJSON ?>;

        $(document).ready(function() {
            $('#personelTable').DataTable({ language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" }, pageLength: 25, columnDefs: [ { orderable: false, targets: [6] } ] });
            $('#hareketlerTable').DataTable({ language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json" }, pageLength: 25, order: [[0, "desc"]], columnDefs: [ { orderable: false, targets: [5] } ] });

            // CHART.JS LINE GRAPH
            const ctx = document.getElementById('ikTrendChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($ag_labels) ?>,
                    datasets: [{
                        label: "Kasadan Çıkan Ödeme (₺)",
                        data: <?= json_encode($ag_data) ?>,
                        backgroundColor: "rgba(78, 115, 223, 0.1)",
                        borderColor: "rgba(78, 115, 223, 1)",
                        borderWidth: 2, pointRadius: 4, fill: true, tension: 0.4
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        });

        // VIEW TOGGLE (Grid vs List)
        function toggleView(mode) {
            if(mode === 'list') {
                document.getElementById('listViewArea').style.display = 'block';
                document.getElementById('gridViewArea').style.display = 'none';
                document.getElementById('btnListView').classList.add('active');
                document.getElementById('btnGridView').classList.remove('active');
            } else {
                document.getElementById('listViewArea').style.display = 'none';
                document.getElementById('gridViewArea').style.display = 'block';
                document.getElementById('btnListView').classList.remove('active');
                document.getElementById('btnGridView').classList.add('active');
            }
        }

        // FOTO ÖNİZLEME
        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) { document.getElementById('fotoPreview').src = e.target.result; }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // PERSONEL MODAL
        function toggleCalismaTipi() {
            let tip = document.getElementById('p_calisma_tipi').value;
            if(tip === 'sabit') {
                document.getElementById('maas_alani').style.display = 'block';
                document.getElementById('is_basi_alani').style.display = 'none';
            } else {
                document.getElementById('maas_alani').style.display = 'none';
                document.getElementById('is_basi_alani').style.display = 'block';
            }
        }

        function resetPersonelForm() {
            document.getElementById('p_id').value = "";
            document.getElementById('p_modal_title').innerHTML = '<i class="fas fa-user-plus me-2"></i>Yeni Personel Kaydı';
            document.getElementById('personelForm').reset();
            document.getElementById('durumWrapper').style.display = 'none';
            document.getElementById('fotoPreview').src = "https://via.placeholder.com/100x100?text=+";
            toggleCalismaTipi();
        }

        function editPersonel(p) {
            document.getElementById('p_id').value = p.id;
            document.getElementById('p_modal_title').innerHTML = '<i class="fas fa-edit me-2"></i>Personel Düzenle';
            document.getElementById('p_ad').value = p.ad_soyad;
            document.getElementById('p_tel').value = p.telefon;
            document.getElementById('p_tc').value = p.tc_no;
            document.getElementById('p_gorev').value = p.gorevi;
            document.getElementById('p_cihazlar').value = p.cihazlar || '';
            document.getElementById('p_yetkinlik').value = p.yetkinlik_seviyesi || 3;
            document.getElementById('p_calisma_tipi').value = p.calisma_tipi || 'sabit';
            document.getElementById('p_maas').value = p.sabit_maas;
            document.getElementById('p_is_basi').value = p.is_basi_ucret;
            document.getElementById('p_tarih').value = p.ise_baslama_tarihi;
            document.getElementById('durumWrapper').style.display = 'block';
            document.getElementById('p_durum').value = p.durum;
            
            if(p.foto_yolu) document.getElementById('fotoPreview').src = p.foto_yolu;
            else document.getElementById('fotoPreview').src = "https://via.placeholder.com/100x100?text=+";
            
            toggleCalismaTipi();
            new bootstrap.Modal(document.getElementById('personelModal')).show();
        }

        // HAREKET MODAL (YENİ VE DÜZENLE)
        function resetHareketForm() {
            document.getElementById('hareketForm').reset();
            document.getElementById('h_id').value = "";
            document.getElementById('h_personel').value = "";
            document.getElementById('h_modal_title').innerHTML = '<i class="fas fa-lira-sign me-2"></i>Personel Finans İşlemi';
            toggleKasaVeTutar();
        }

        function openHareketModal(id) {
            resetHareketForm();
            if(id) document.getElementById('h_personel').value = id;
            new bootstrap.Modal(document.getElementById('hareketModal')).show();
        }
        
        function editHareket(h) {
            document.getElementById('hareketForm').reset();
            document.getElementById('h_id').value = h.id;
            document.getElementById('h_modal_title').innerHTML = '<i class="fas fa-edit me-2"></i>Finansal İşlemi Düzelt';
            document.getElementById('h_personel').value = h.personel_id;
            document.getElementById('h_tur').value = h.islem_turu;
            document.getElementById('h_tutar').value = h.tutar;
            document.getElementById('h_tarih').value = h.islem_tarihi;
            document.getElementById('h_aciklama').value = h.aciklama;
            
            toggleKasaVeTutar();
            if(h.kasa_id) document.getElementById('h_kasa').value = h.kasa_id;
            
            new bootstrap.Modal(document.getElementById('hareketModal')).show();
        }

        function fillHakedisTutar() {
            let p_id = document.getElementById('h_personel').value;
            let tur = document.getElementById('h_tur').value;
            let tutarInput = document.getElementById('h_tutar');
            let isEdit = document.getElementById('h_id').value !== "";
            
            // Eğer düzenleme modundaysa otomatik fiyat doldurmayı atla, çünkü eski tutarı yazdık
            if(isEdit) return; 
            
            if(p_id && tur === 'is_hakedis') {
                let p = personellerData.find(x => x.id == p_id);
                if(p && p.calisma_tipi === 'is_basi') tutarInput.value = p.is_basi_ucret;
            } else if (p_id && tur === 'maas_hakedis') {
                let p = personellerData.find(x => x.id == p_id);
                if(p && p.calisma_tipi === 'sabit') tutarInput.value = p.sabit_maas;
            }
        }

        function toggleKasaVeTutar() {
            let tur = document.getElementById('h_tur').value;
            let kasaAlan = document.getElementById('kasaSecimAlani');
            let hKasa = document.getElementById('h_kasa');
            
            if(tur === 'maas_odeme' || tur === 'avans_odeme') {
                kasaAlan.style.display = 'block';
                hKasa.required = true;
            } else {
                kasaAlan.style.display = 'none';
                hKasa.required = false;
            }
            fillHakedisTutar();
        }

        function deletePersonel(id) {
            Swal.fire({ title: 'Emin misiniz?', text: "Bu personel sistemden silinecek.", icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal' }).then((result) => {
                if (result.isConfirmed) window.location.href = `personel.php?p_sil=${id}`;
            });
        }

        function deleteHareket(id) {
            Swal.fire({ title: 'İşlem İptal Edilecek!', text: "Bu finansal kayıt silinecek ve gerekiyorsa kasa iadesi yapılacak.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Evet, İptal Et', cancelButtonText: 'Vazgeç' }).then((result) => {
                if (result.isConfirmed) window.location.href = `personel.php?islem_sil=${id}`;
            });
        }
    </script>
</body>
</html>