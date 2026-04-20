<?php
session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
require_once 'partials/security_check.php';

// Sayfa başlığı
$page_title = "Teklifler";
$firma_id = $_SESSION['firma_id'];

// AJAX İSTEKLERİ İÇİN ERKEN ÇIKIŞ
if (isset($_GET['get_detay_token'])) {
    $token = $_GET['get_detay_token'];
    $sorgu = $db->prepare("SELECT * FROM teklifler WHERE url_token=? AND firma_id=?");
    $sorgu->execute([$token, $firma_id]);
    $teklif = $sorgu->fetch(PDO::FETCH_ASSOC);
    
    if($teklif) {
        $kalemler = !empty($teklif['kalemler']) ? json_decode($teklif['kalemler'], true) : [];
        header('Content-Type: application/json');
        echo json_encode(['teklif' => $teklif, 'kalemler' => $kalemler]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Teklif bulunamadı']);
    }
    exit; 
}

// SİLME İŞLEMİ (Veritabanından kalıcı siler)
if (isset($_GET['sil_token'])) {
    $token = $_GET['sil_token'];
    $db->prepare("DELETE FROM teklifler WHERE url_token=? AND firma_id=?")->execute([$token, $firma_id]);
    $_SESSION['success_message'] = "Teklif başarıyla veritabanından silindi!";
    header("Location: teklifler.php"); 
    exit;
}

// HIZLI DURUM GÜNCELLEME İŞLEMİ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['teklif_durum_guncelle'])) {
    $token = $_POST['teklif_token'];
    $yeni_durum = $_POST['yeni_durum'];
    
    $sorgu = $db->prepare("UPDATE teklifler SET durum=? WHERE url_token=? AND firma_id=?");
    $sorgu->execute([$yeni_durum, $token, $firma_id]);
    
    $_SESSION['success_message'] = "Teklif durumu başarıyla güncellendi!";
    header("Location: teklifler.php"); 
    exit;
}

// TEKLİFİ MÜŞTERİYE ÇEVİRME İŞLEMİ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['teklifi_musteriye_cevir'])) {
    $teklif_id = $_POST['teklif_id'];
    $yeni_ad_soyad = $_POST['ad_soyad'];
    $telefon = $_POST['telefon'] ?? '';
    $ozel_not = $_POST['ozel_notlar'] ?? '';

    // İlgili teklifi bul
    $sorgu = $db->prepare("SELECT * FROM teklifler WHERE id=? AND firma_id=?");
    $sorgu->execute([$teklif_id, $firma_id]);
    $teklif = $sorgu->fetch(PDO::FETCH_ASSOC);

    if ($teklif) {
        
        // --- FİRMA ADINDAN KISALTMA OLUŞTURMA VE MÜŞTERİ NO ÜRETME ---
        $firma_adi = $_SESSION['firma_adi'] ?? 'Firma Paneli'; 
        $kelimeler = explode(" ", $firma_adi);
        $firmaKodu = "";
        foreach ($kelimeler as $k) {
            $firmaKodu .= mb_substr($k, 0, 1, "UTF-8");
        }
        $firmaKodu = mb_strtoupper($firmaKodu, "UTF-8");
        $firmaKodu = str_replace(['Ç', 'Ğ', 'İ', 'Ö', 'Ş', 'Ü'], ['C', 'G', 'I', 'O', 'S', 'U'], $firmaKodu);
        $firmaKodu = preg_replace('/[^A-Z0-9]/', '', $firmaKodu);
        if (empty($firmaKodu)) { $firmaKodu = "MST"; }

        $sorguSonNo = $db->prepare("SELECT musteri_no FROM musteriler WHERE firma_id = ? ORDER BY id DESC LIMIT 1");
        $sorguSonNo->execute([$firma_id]);
        $sonMusteriNo = $sorguSonNo->fetchColumn();

        if ($sonMusteriNo) {
            $parcalar = explode('-', $sonMusteriNo);
            $sonSira = intval(end($parcalar)); 
            $yeniSira = $sonSira + 1;
        } else {
            $yeniSira = 1;
        }

        $gercek_musteri_no = $firmaKodu . "-" . date("Ymd") . "-" . str_pad($yeniSira, 3, '0', STR_PAD_LEFT);
        // --------------------------------------------------------------

        // 1. Yeni Müşteri Oluştur (Token ve Müşteri No atayarak)
        $yeni_token = md5(uniqid(rand(), true));
        $ekleMusteri = $db->prepare("INSERT INTO musteriler (firma_id, url_token, musteri_no, ad_soyad, telefon, ozel_notlar) VALUES (?, ?, ?, ?, ?, ?)");
        $ekleMusteri->execute([$firma_id, $yeni_token, $gercek_musteri_no, $yeni_ad_soyad, $telefon, $ozel_not]);
        $yeni_musteri_id = $db->lastInsertId();

        // 2. Teklifteki Kalemleri 'hareketler' (İşlem/Satış) olarak müşteriye ekle
        $kalemler = json_decode($teklif['kalemler'], true);
        if (is_array($kalemler)) {
            // islem_turu='satis' varsayıyoruz.
            $ekleHareket = $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, odeme_turu, urun_aciklama, adet, birim_fiyat, iskonto_orani, kdv_orani, toplam_tutar, islem_tarihi) VALUES (?, ?, 'satis', 0, ?, ?, ?, 0, 0, ?, ?)");
            $tarih = date('Y-m-d H:i:s');
            
            foreach ($kalemler as $k) {
                $ekleHareket->execute([
                    $firma_id,
                    $yeni_musteri_id,
                    $k['aciklama'],
                    $k['adet'],
                    $k['birim_fiyat'],
                    $k['toplam_fiyat'],
                    $tarih
                ]);
            }
        }

        // Başarılı olduğunda yeni oluşturulan Müşteri Detay sayfasına yönlendir
        $_SESSION['success_message'] = "Teklif başarıyla müşteriye dönüştürüldü ve tüm hizmet kalemleri hesaba eklendi!";
        header("Location: musteri_detay.php?t=" . $yeni_token);
        exit;
    }
}

// GENEL GÜNCELLEME İŞLEMİ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['teklif_guncelle'])) {
    $id = $_POST['teklif_id'];
    
    // Sadece bu firmanın teklifini güncelle
    $chk = $db->prepare("SELECT id FROM teklifler WHERE id=? AND firma_id=?");
    $chk->execute([$id, $firma_id]);
    
    if ($chk->rowCount() > 0) {
        $musteri_adi = $_POST['musteri_adi'];
        $telefon     = $_POST['telefon'] ?? '';
        $konu        = $_POST['konu_baslik'];
        $tarih       = $_POST['tarih'];
        $gecerlilik  = $_POST['gecerlilik'];
        $durum       = $_POST['durum'];
        $ozel_sartlar = $_POST['ozel_sartlar'];
        $ozel_alt_bilgi = $_POST['ozel_alt_bilgi'];
        
        // Kalemleri Paketle
        $kalem_adlari = $_POST['kalem_adi'];
        $kalem_adetleri = $_POST['kalem_adet'];
        $kalem_fiyatlari = $_POST['kalem_fiyat'];
        
        $kalemler_dizisi = [];
        $genelToplam = 0;
        
        for($i=0; $i < count($kalem_adlari); $i++) {
            if(!empty($kalem_adlari[$i])) {
                $satirToplam = $kalem_adetleri[$i] * $kalem_fiyatlari[$i];
                $genelToplam += $satirToplam;
                $kalemler_dizisi[] = [
                    'aciklama' => $kalem_adlari[$i],
                    'adet' => $kalem_adetleri[$i],
                    'birim_fiyat' => $kalem_fiyatlari[$i],
                    'toplam_fiyat' => $satirToplam
                ];
            }
        }
        $kalemler_json = json_encode($kalemler_dizisi, JSON_UNESCAPED_UNICODE);

        // Tek Seferde Güncelle (telefon eklendi)
        $sorgu = $db->prepare("UPDATE teklifler SET musteri_adi=?, telefon=?, konu_baslik=?, tarih=?, gecerlilik_gun=?, toplam_tutar=?, durum=?, ozel_sartlar=?, ozel_alt_bilgi=?, kalemler=? WHERE id=?");
        $sorgu->execute([$musteri_adi, $telefon, $konu, $tarih, $gecerlilik, $genelToplam, $durum, $ozel_sartlar, $ozel_alt_bilgi, $kalemler_json, $id]);
        
        $_SESSION['success_message'] = "Teklif başarıyla güncellendi!";
    }
    header("Location: teklifler.php"); 
    exit;
}

// LİSTELEME - Varsayılan sorgu
$sql = "SELECT * FROM teklifler WHERE firma_id = ?";
$params = [$firma_id];

// Arama filtresi
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

if (!empty($search)) {
    $sql .= " AND (musteri_adi LIKE ? OR konu_baslik LIKE ? OR telefon LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($status_filter !== 'all') {
    $sql .= " AND durum = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY id DESC";

$teklifler = $db->prepare($sql);
$teklifler->execute($params);
$teklifler = $teklifler->fetchAll(PDO::FETCH_ASSOC);

// İstatistikler
$beklemede_sayisi = 0;
$onaylandi_sayisi = 0;
$toplam_tutar = 0;

foreach($teklifler as $t) {
    if($t['durum'] === 'beklemede') $beklemede_sayisi++;
    if($t['durum'] === 'onaylandi') $onaylandi_sayisi++;
    $toplam_tutar += $t['toplam_tutar'];
}

// Sayfaya özel CSS
$inline_css = '
    .status-badge {
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
        display: inline-block;
    }
    .status-beklemede { background-color: #fef3cd; color: #856404; }
    .status-onaylandi { background-color: #d1e7dd; color: #0f5132; }
    .status-reddedildi { background-color: #f8d7da; color: #721c24; }
    
    .teklif-table tbody tr:hover { 
        background-color: #f8f9fa;
        transition: background-color 0.3s;
    }
    
    .action-btns .btn { 
        opacity: 0.8; 
        transition: all 0.3s; 
        padding: 5px 10px;
    }
    .action-btns .btn:hover { 
        opacity: 1; 
        transform: translateY(-2px);
    }
    
    .teklif-stats-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        border-left: 4px solid #4e73df;
        transition: transform 0.3s;
    }
    .teklif-stats-card:hover {
        transform: translateY(-5px);
    }
    
    .teklif-stats-card.beklemede { border-left-color: #f6c23e; }
    .teklif-stats-card.onaylandi { border-left-color: #1cc88a; }
    .teklif-stats-card.tutar { border-left-color: #4e73df; }
    
    .icon-circle {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    
    .empty-state {
        padding: 60px 20px;
        text-align: center;
        color: #6c757d;
    }
    .empty-state i {
        font-size: 4rem;
        margin-bottom: 20px;
        opacity: 0.3;
    }
    
    @media (max-width: 768px) {
        .teklif-table thead { display: none; }
        .teklif-table tbody tr {
            display: block;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
        }
        .teklif-table tbody td {
            display: block;
            text-align: right;
            padding: 10px 0;
            border: none;
        }
        .teklif-table tbody td::before {
            content: attr(data-label);
            float: left;
            font-weight: bold;
            color: #6c757d;
        }
        .action-btns {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 15px;
        }
    }
';

// Sayfaya özel JS
$inline_js = '
    function msjGoster(mesaj, tip) {
        if(typeof showYonetimToast === "function") {
            showYonetimToast(mesaj, tip);
        } else if(typeof showToast === "function") {
            showToast(mesaj, tip);
        } else {
            alert(mesaj);
        }
    }

    function teklifDuzenle(token) {
        document.getElementById("edit_kalemler_container").innerHTML = \'<tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Yükleniyor...</p></td></tr>\';
        
        fetch(\'teklifler.php?get_detay_token=\' + token)
            .then(response => {
                if (!response.ok) {
                    throw new Error(\'Network response was not ok\');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    msjGoster(data.error, "error");
                    return;
                }
                
                const t = data.teklif;
                const k = data.kalemler;
                
                document.getElementById("edit_id").value = t.id;
                document.getElementById("edit_musteri").value = t.musteri_adi;
                document.getElementById("edit_telefon").value = t.telefon || "";
                document.getElementById("edit_konu").value = t.konu_baslik;
                document.getElementById("edit_tarih").value = t.tarih;
                document.getElementById("edit_gecerlilik").value = t.gecerlilik_gun;
                document.getElementById("edit_durum").value = t.durum;
                document.getElementById("edit_ozel_sartlar").value = t.ozel_sartlar;
                document.getElementById("edit_ozel_alt_bilgi").value = t.ozel_alt_bilgi;
                
                let html = "";
                if(Array.isArray(k) && k.length > 0) {
                    k.forEach(item => { 
                        html += getSatirHtml(item.aciklama, item.adet, item.birim_fiyat); 
                    });
                } else {
                    html = getSatirHtml("", 1, "");
                }
                document.getElementById("edit_kalemler_container").innerHTML = html;
                
                const myModal = new bootstrap.Modal(document.getElementById("modalTeklifDuzenle"));
                myModal.show();
                
                setTimeout(calculateTotal, 100);
            })
            .catch(error => {
                console.error("Hata:", error);
                msjGoster("Teklif verileri yüklenirken hata oluştu!", "error");
            });
    }
    
    function hizliDurumDegistir(token, currentStatus) {
        document.getElementById("hizli_durum_token").value = token;
        document.getElementById("hizli_durum_select").value = currentStatus;
        const modal = new bootstrap.Modal(document.getElementById("modalHizliDurum"));
        modal.show();
    }
    
    // Telefon bilgisini de alarak musteriye_cevir modalını tetikleme
    function musteriyeCevir(id, musteriAdi, konu, telefon) {
        document.getElementById("cevir_teklif_id").value = id;
        document.getElementById("cevir_ad_soyad").value = musteriAdi;
        document.getElementById("cevir_telefon").value = telefon || "";
        document.getElementById("cevir_notlar").value = "Teklif Konusu: " + konu;
        const modal = new bootstrap.Modal(document.getElementById("modalMusteriyeCevir"));
        modal.show();
    }
    
    function satirEkle() {
        const container = document.getElementById("edit_kalemler_container");
        container.insertAdjacentHTML("beforeend", getSatirHtml("", 1, ""));
    }
    
    function editSatirSil(btn) {
        const rows = document.querySelectorAll("#edit_kalemler_container .kalem-satir");
        if (rows.length > 1) {
            btn.closest("tr").remove();
            calculateTotal();
        } else {
            msjGoster("En az bir satır kalmalı!", "warning");
        }
    }
    
    function getSatirHtml(ad, adet, fiyat) {
        return \'<tr class="kalem-satir">\' +
               \'<td><input type="text" name="kalem_adi[]" class="form-control form-control-sm" value="\' + (ad || \'\') + \'" required></td>\' +
               \'<td><input type="number" name="kalem_adet[]" class="form-control form-control-sm text-center" value="\' + (adet || 1) + \'" min="1" required oninput="calculateTotal()"></td>\' +
               \'<td><input type="number" step="0.01" name="kalem_fiyat[]" class="form-control form-control-sm text-end" value="\' + (fiyat !== undefined ? fiyat : \'\') + \'" required oninput="calculateTotal()"></td>\' +
               \'<td class="text-center align-middle"><i class="fas fa-times text-danger" onclick="editSatirSil(this)" style="cursor:pointer;"></i></td>\' +
               \'</tr>\';
    }
    
    function calculateTotal() {
        let total = 0;
        document.querySelectorAll("#edit_kalemler_container .kalem-satir").forEach(row => {
            const adet = parseFloat(row.querySelector("[name=\'kalem_adet[]\']").value) || 0;
            const fiyat = parseFloat(row.querySelector("[name=\'kalem_fiyat[]\']").value) || 0;
            total += adet * fiyat;
        });
        
        const totalElement = document.getElementById("edit_toplam_tutar");
        if (totalElement) {
            totalElement.textContent = total.toLocaleString("tr-TR", {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
    }
    
    function confirmDelete(token, musteriAdi) {
        if (confirm("\'" + musteriAdi + "\' için oluşturduğunuz teklifi KESİNLİKLE silmek istediğinize emin misiniz? (Bu işlem geri alınamaz)")) {
            window.location.href = "teklifler.php?sil_token=" + token;
        }
    }
    
    function applyFilters() {
        const search = document.getElementById("searchInput").value;
        const status = document.getElementById("statusFilter").value;
        
        let url = "teklifler.php?";
        if (search) url += "search=" + encodeURIComponent(search) + "&";
        if (status !== "all") url += "status=" + status;
        
        if (url.endsWith("&") || url.endsWith("?")) {
            url = url.slice(0, -1);
        }
        window.location.href = url;
    }
    
    document.addEventListener("DOMContentLoaded", function() {
        const searchInput = document.getElementById("searchInput");
        if (searchInput) {
            searchInput.addEventListener("keypress", function(e) {
                if (e.key === "Enter") {
                    applyFilters();
                }
            });
        }
    });
';

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | <?php echo htmlspecialchars($_SESSION['firma_adi'] ?? 'Yönetim Paneli'); ?></title>
    
    <!-- YÖNETİM CSS -->
    <link rel="stylesheet" href="css/yonetim.css">
    
    <!-- BOOTSTRAP & FONTAWESOME -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- SAYFAYA ÖZEL INLINE CSS -->
    <style>
        <?php echo $inline_css; ?>
    </style>
</head>
<body class="yonetim-body">

<!-- NAVBAR -->
<?php include 'partials/navbar.php'; ?>

<div class="container-yonetim pb-5">
    
    <!-- Üst Bilgi ve Butonlar -->
    <div class="row mb-4 align-items-center mt-4">
        <div class="col-md-6">
            <h3 class="text-secondary mb-0"><i class="fas fa-file-signature me-2"></i>Teklifler</h3>
            <p class="text-muted mb-0">Oluşturulan tüm tekliflerin listesi</p>
        </div>
        <div class="col-md-6 text-end">
            <div class="btn-group" role="group">
                <a href="teklif_olustur.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i>Yeni Teklif Oluştur
                </a>
            </div>
        </div>
    </div>
    
    <!-- İstatistik Kartları -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="teklif-stats-card beklemede">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Beklemede</h6>
                        <h3 class="mb-0 text-warning"><?php echo $beklemede_sayisi; ?></h3>
                    </div>
                    <div class="icon-circle bg-warning text-white">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="teklif-stats-card onaylandi">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Onaylandı</h6>
                        <h3 class="mb-0 text-success"><?php echo $onaylandi_sayisi; ?></h3>
                    </div>
                    <div class="icon-circle bg-success text-white">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="teklif-stats-card tutar">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Toplam Tutar</h6>
                        <h3 class="mb-0 text-primary"><?php echo number_format($toplam_tutar, 2, ',', '.'); ?> ₺</h3>
                    </div>
                    <div class="icon-circle bg-primary text-white">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtre Kartı -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchInput" class="form-control" 
                               placeholder="Müşteri adı veya konu ara..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-outline-primary" type="button" onclick="applyFilters()">
                            <i class="fas fa-filter me-1"></i>Filtrele
                        </button>
                    </div>
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="statusFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Tüm Durumlar</option>
                        <option value="beklemede" <?php echo $status_filter === 'beklemede' ? 'selected' : ''; ?>>Beklemede</option>
                        <option value="onaylandi" <?php echo $status_filter === 'onaylandi' ? 'selected' : ''; ?>>Onaylandı</option>
                        <option value="reddedildi" <?php echo $status_filter === 'reddedildi' ? 'selected' : ''; ?>>Reddedildi</option>
                    </select>
                </div>
                <div class="col-md-3 text-end">
                    <div class="text-muted mt-2">
                        <i class="fas fa-chart-bar me-1"></i>
                        Gösterilen: <strong><?php echo count($teklifler); ?></strong> teklif
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Teklifler Tablosu -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body p-0">
            <?php if(count($teklifler) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 teklif-table">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Tarih</th>
                                <th>Müşteri / Firma</th>
                                <th>Konu</th>
                                <th>Tutar</th>
                                <th>Durum</th>
                                <th class="text-end pe-4">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($teklifler as $t): ?>
                            <tr>
                                <td class="ps-4" data-label="Tarih">
                                    <div class="fw-bold"><?php echo date("d.m.Y", strtotime($t['tarih'])); ?></div>
                                    <small class="text-muted"><?php echo $t['gecerlilik_gun']; ?> gün geçerli</small>
                                </td>
                                <td data-label="Müşteri">
                                    <div class="fw-bold">
                                        <?php echo htmlspecialchars($t['musteri_adi']); ?>
                                        <?php if(!empty($t['telefon'])): ?>
                                            <div class="small text-muted fw-normal"><i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($t['telefon']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">#<?php echo $t['id']; ?></small>
                                </td>
                                <td data-label="Konu"><?php echo htmlspecialchars($t['konu_baslik']); ?></td>
                                <td data-label="Tutar">
                                    <div class="fw-bold text-primary">
                                        <?php echo number_format($t['toplam_tutar'], 2, ',', '.'); ?> ₺
                                    </div>
                                </td>
                                <td data-label="Durum">
                                    <?php 
                                    $status_class = '';
                                    $status_text = '';
                                    switch($t['durum']) {
                                        case 'beklemede':
                                            $status_class = 'status-beklemede';
                                            $status_text = 'Beklemede';
                                            break;
                                        case 'onaylandi':
                                            $status_class = 'status-onaylandi';
                                            $status_text = 'Onaylandı';
                                            break;
                                        case 'reddedildi':
                                            $status_class = 'status-reddedildi';
                                            $status_text = 'Reddedildi';
                                            break;
                                        default:
                                            $status_class = 'status-beklemede';
                                            $status_text = $t['durum'];
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4 action-btns" data-label="İşlem">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <!-- WHATSAPP BUTONU -->
                                        <?php if(!empty($t['telefon'])): 
                                            $wp_no = preg_replace('/[^0-9]/', '', $t['telefon']);
                                            if(substr($wp_no, 0, 1) == '0') $wp_no = '9' . $wp_no;
                                            else if(strlen($wp_no) == 10) $wp_no = '90' . $wp_no;
                                            
                                            // Dinamik Domain Linki Alma
                                            $domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                                            $dizin = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                                            $teklif_linki = $domain . $dizin . "/teklif_detay.php?t=" . $t['url_token'];
                                            
                                            $wp_msg = "Sayın " . $t['musteri_adi'] . ",\n*" . $t['konu_baslik'] . "* konulu *" . number_format($t['toplam_tutar'], 2, ',', '.') . " TL* tutarındaki teklifiniz hazırlanmıştır.\n\nDetaylar için:\n" . $teklif_linki;
                                        ?>
                                        <a href="https://wa.me/<?php echo $wp_no; ?>?text=<?php echo urlencode($wp_msg); ?>" 
                                           target="_blank" class="btn btn-outline-success" title="WhatsApp ile Teklifi Gönder">
                                            <i class="fab fa-whatsapp"></i>
                                        </a>
                                        <?php endif; ?>

                                        <!-- ONAYLANAN TEKLİFLER İÇİN MÜŞTERİYE ÇEVİR BUTONU -->
                                        <?php if($t['durum'] == 'onaylandi'): ?>
                                        <button type="button" class="btn btn-outline-primary" 
                                                onclick="musteriyeCevir('<?php echo $t['id']; ?>', '<?php echo addslashes($t['musteri_adi']); ?>', '<?php echo addslashes($t['konu_baslik']); ?>', '<?php echo addslashes($t['telefon'] ?? ''); ?>')" 
                                                title="Bu Teklifi Müşteriye Dönüştür">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                        <?php endif; ?>

                                        <a href="teklif_detay.php?t=<?php echo $t['url_token']; ?>" 
                                           class="btn btn-outline-info" title="Görüntüle">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-secondary" 
                                                onclick="hizliDurumDegistir('<?php echo $t['url_token']; ?>', '<?php echo $t['durum']; ?>')" title="Durumu Değiştir">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-warning" 
                                                onclick="teklifDuzenle('<?php echo $t['url_token']; ?>')" title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" 
                                                onclick="confirmDelete('<?php echo $t['url_token']; ?>', '<?php echo addslashes($t['musteri_adi']); ?>')" 
                                                title="Sil">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <h4 class="mb-3">Sonuç Bulunamadı</h4>
                    <p class="text-muted mb-4">Bu kriterlere uygun teklif bulunmuyor veya henüz hiç oluşturmadınız.</p>
                    <a href="teklif_olustur.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-plus-circle me-2"></i>Yeni Teklif Oluştur
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL: HIZLI DURUM DEĞİŞTİR -->
<div class="modal fade" id="modalHizliDurum" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="teklif_durum_guncelle" value="1">
                <input type="hidden" name="teklif_token" id="hizli_durum_token">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-sync-alt me-2"></i>Durum Değiştir</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <label class="form-label fw-bold text-muted small mb-2">Yeni Durumu Seçin</label>
                    <select name="yeni_durum" id="hizli_durum_select" class="form-select form-select-lg">
                        <option value="beklemede">Beklemede</option>
                        <option value="onaylandi">Onaylandı</option>
                        <option value="reddedildi">Reddedildi</option>
                    </select>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success fw-bold px-4">Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- YENİ MODAL: MÜŞTERİYE ÇEVİR -->
<div class="modal fade" id="modalMusteriyeCevir" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="teklifi_musteriye_cevir" value="1">
                <input type="hidden" name="teklif_id" id="cevir_teklif_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Teklifi Müşteriye Çevir</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="alert alert-info small mb-4">
                        <i class="fas fa-info-circle me-1"></i> Teklifteki hizmet kalemleri otomatik olarak bu müşteriye <strong>işlem (borç)</strong> olarak eklenecektir. Adres, T.C. gibi diğer detayları bir sonraki sayfada doldurabileceksiniz.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Müşteri / Firma Adı</label>
                        <input type="text" name="ad_soyad" id="cevir_ad_soyad" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Telefon Numarası <span class="text-secondary fw-normal">(Opsiyonel)</span></label>
                        <input type="text" name="telefon" id="cevir_telefon" class="form-control" placeholder="0555...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Özel Not <span class="text-secondary fw-normal">(Opsiyonel)</span></label>
                        <textarea name="ozel_notlar" id="cevir_notlar" class="form-control" rows="2"></textarea>
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

<!-- MODAL: TEKLİF DÜZENLE -->
<div class="modal fade" id="modalTeklifDuzenle" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editTeklifForm">
                <input type="hidden" name="teklif_guncelle" value="1">
                <input type="hidden" name="teklif_id" id="edit_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Teklif Düzenle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div class="row mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-bold">Müşteri Adı</label>
                            <input type="text" name="musteri_adi" id="edit_musteri" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Telefon</label>
                            <input type="text" name="telefon" id="edit_telefon" class="form-control" placeholder="0555...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Tarih</label>
                            <input type="date" name="tarih" id="edit_tarih" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Konu</label>
                        <input type="text" name="konu_baslik" id="edit_konu" class="form-control" required>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>HİZMET</th>
                                    <th width="100">ADET</th>
                                    <th width="150">FİYAT (₺)</th>
                                    <th width="50"></th>
                                </tr>
                            </thead>
                            <tbody id="edit_kalemler_container"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="border-0 pt-3">
                                        <button type="button" class="btn btn-outline-primary w-100" onclick="satirEkle()">
                                            <i class="fas fa-plus me-2"></i>Yeni Satır Ekle
                                        </button>
                                    </td>
                                </tr>
                                <tr class="border-top">
                                    <td colspan="2" class="text-end fw-bold pt-3">TOPLAM:</td>
                                    <td class="text-end fw-bold pt-3 fs-5 text-primary">
                                        <span id="edit_toplam_tutar">0.00</span> ₺
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Geçerlilik (gün)</label>
                            <input type="number" name="gecerlilik" id="edit_gecerlilik" class="form-control" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Durum</label>
                            <select name="durum" id="edit_durum" class="form-select">
                                <option value="beklemede">Beklemede</option>
                                <option value="onaylandi">Onaylandı</option>
                                <option value="reddedildi">Reddedildi</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Şartlar</label>
                        <textarea name="ozel_sartlar" id="edit_ozel_sartlar" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Alt Bilgi</label>
                        <input type="text" name="ozel_alt_bilgi" id="edit_ozel_alt_bilgi" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-save me-2"></i>Değişiklikleri Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toast-container-yonetim"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    <?php echo $inline_js; ?>
    
    // Mesaj Kontrolünü düzgünce HTML alanında başlatıyoruz
    document.addEventListener("DOMContentLoaded", function() {
        <?php if(isset($_SESSION['success_message'])): ?>
            msjGoster("<?php echo addslashes($_SESSION['success_message']); ?>", "success");
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
    });
</script>

<?php
// FOOTER'I ÇAĞIR
require_once 'partials/footer_yonetim.php';
?>