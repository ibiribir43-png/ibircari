<?php
require 'baglanti.php';

// Güvenlik
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: index.php");
    exit;
}

$firma_id = $_SESSION['firma_id'];
$hataMesaji = "";
$basariMesaji = "";
$id = 0; // Gerçek ID'yi token'dan bulacağız

// --- GÜVENLİK VE KİMLİK TESPİTİ (TOKEN) ---
if (isset($_GET['t']) && !empty($_GET['t'])) {
    $token = $_GET['t'];
    // Token kontrolü + Firma İzolasyonu
    $tedarikciIlk = $db->prepare("SELECT * FROM tedarikciler WHERE url_token = ? AND firma_id = ?");
    $tedarikciIlk->execute([$token, $firma_id]);
    $tedarikci = $tedarikciIlk->fetch(PDO::FETCH_ASSOC);

    if ($tedarikci) {
        $id = $tedarikci['id']; // Gerçek ID bulundu
    } else {
        die("<div class='container mt-5 text-center'><h1>⛔</h1><h3>Erişim Reddedildi</h3><p>Bu kayıt size ait değil veya silinmiş.</p><a href='tedarikciler.php' class='btn btn-primary mt-3'>Listeye Dön</a></div>"); 
    }
} else {
    header("Location: tedarikciler.php");
    exit;
}

// ------------------- İŞLEMLER (POST) -------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. YENİ İŞLEM EKLEME (ALIŞ veya ÖDEME)
    if (isset($_POST['islem_ekle'])) {
        $tur = $_POST['islem_turu']; 
        $aciklama = $_POST['aciklama'];
        $tarih = $_POST['tarih'];
        $vade = !empty($_POST['vade_tarihi']) ? $_POST['vade_tarihi'] : null;
        $tutar = $_POST['tutar'];
        
        $ekle = $db->prepare("INSERT INTO tedarikci_hareketler (firma_id, tedarikci_id, islem_turu, aciklama, toplam_tutar, islem_tarihi, vade_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ekle->execute([$firma_id, $id, $tur, $aciklama, $tutar, $tarih, $vade]);
        
        header("Location: tedarikci_detay.php?t=$token");
        exit;
    }

    // 2. TEDARİKÇİ BİLGİLERİNİ GÜNCELLE
    if (isset($_POST['tedarikci_guncelle'])) {
        $guncelle = $db->prepare("UPDATE tedarikciler SET firma_adi=?, yetkili_kisi=?, telefon=?, adres=?, vergi_no=?, iban=?, ozel_notlar=? WHERE id=? AND firma_id=?");
        $guncelle->execute([$_POST['firma_adi'], $_POST['yetkili_kisi'], $_POST['telefon'], $_POST['adres'], $_POST['vergi_no'], $_POST['iban'], $_POST['ozel_notlar'], $id, $firma_id]);
        header("Location: tedarikci_detay.php?t=$token");
        exit;
    }

    // 3. HAREKET GÜNCELLEME
    if (isset($_POST['hareket_guncelle'])) {
        $hareket_id = $_POST['hareket_id'];
        $aciklama = $_POST['aciklama'];
        $vade = !empty($_POST['vade_tarihi']) ? $_POST['vade_tarihi'] : null;
        $tutar = $_POST['tutar'];

        $h_guncelle = $db->prepare("UPDATE tedarikci_hareketler SET aciklama=?, vade_tarihi=?, toplam_tutar=? WHERE id=? AND firma_id=?");
        $h_guncelle->execute([$aciklama, $vade, $tutar, $hareket_id, $firma_id]);
        
        header("Location: tedarikci_detay.php?t=$token");
        exit;
    }

    // 4. DURUM DEĞİŞTİRME (ARŞİVLEME) - ŞİFRELİ
    if (isset($_POST['yeni_durum'])) {
        $girilen_sifre = md5($_POST['guvenlik_sifresi']); 
        $adminID = $_SESSION['kullanici_id'];
        
        $sorguAdmin = $db->prepare("SELECT * FROM yoneticiler WHERE id = ? AND sifre = ? AND firma_id = ?");
        $sorguAdmin->execute([$adminID, $girilen_sifre, $firma_id]);
        
        if ($sorguAdmin->rowCount() > 0) {
            $guncelle = $db->prepare("UPDATE tedarikciler SET durum = ? WHERE id = ? AND firma_id = ?");
            $guncelle->execute([$_POST['yeni_durum'], $id, $firma_id]);
            header("Location: tedarikci_detay.php?t=$token");
            exit;
        } else {
            $hataMesaji = "HATA: Yönetici şifresi yanlış girildi!";
        }
    }
    
    // 5. KAYIT SİLME (HAREKET)
    if (isset($_POST['sil_id'])) {
        $sil = $db->prepare("DELETE FROM tedarikci_hareketler WHERE id = ? AND firma_id = ?");
        $sil->execute([$_POST['sil_id'], $firma_id]);
        header("Location: tedarikci_detay.php?t=$token");
        exit;
    }

    // 6. TEDARİKÇİYİ "ÇÖP KUTUSUNA" TAŞIMA (SİLME)
    if (isset($_POST['tedarikci_sil_soft'])) {
        $girilen_sifre = md5($_POST['guvenlik_sifresi']); 
        $adminID = $_SESSION['kullanici_id'];
        
        $sorguAdmin = $db->prepare("SELECT * FROM yoneticiler WHERE id = ? AND sifre = ? AND firma_id = ?");
        $sorguAdmin->execute([$adminID, $girilen_sifre, $firma_id]);
        
        if ($sorguAdmin->rowCount() > 0) {
            $sil = $db->prepare("UPDATE tedarikciler SET silindi = 1 WHERE id = ? AND firma_id = ?");
            $sil->execute([$id, $firma_id]);
            header("Location: tedarikciler.php"); 
            exit;
        } else {
            $hataMesaji = "HATA: Yönetici şifresi yanlış! İşlem iptal edildi.";
        }
    }

    // 7. TEDARİKÇİYİ GERİ YÜKLEME
    if (isset($_POST['tedarikci_geri_yukle'])) {
        $geri = $db->prepare("UPDATE tedarikciler SET silindi = 0 WHERE id = ? AND firma_id = ?");
        $geri->execute([$id, $firma_id]);
        $basariMesaji = "Tedarikçi başarıyla geri yüklendi.";
        $tedarikci['silindi'] = 0; 
    }
}

// --- VERİLERİ ÇEK ---
// Tedarikçiyi zaten yukarıda çekmiştik

$hareketler = $db->prepare("SELECT * FROM tedarikci_hareketler WHERE tedarikci_id = ? AND firma_id = ? ORDER BY islem_tarihi ASC, id ASC"); 
$hareketler->execute([$id, $firma_id]); 
$hareketler = $hareketler->fetchAll(PDO::FETCH_ASSOC);

$toplamAlis = 0; 
$toplamOdeme = 0;
foreach($hareketler as $h) {
    if ($h['islem_turu'] == 'alis') $toplamAlis += $h['toplam_tutar']; 
    else $toplamOdeme += $h['toplam_tutar'];
}
$kalanBorc = $toplamAlis - $toplamOdeme;
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Hesap Detayı - <?php echo $tedarikci['firma_adi']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .bg-gradient-danger { background: linear-gradient(45deg, #e74a3b, #be2617); color: white; }
        .bg-gradient-success { background: linear-gradient(45deg, #1cc88a, #13855c); color: white; }
        .bg-gradient-dark { background: linear-gradient(45deg, #5a5c69, #373840); color: white; }
        .bg-gradient-warning { background: linear-gradient(45deg, #f6c23e, #dda20a); color: white; }

        .ozet-kutu { border: none; border-radius: 15px; padding: 20px; color: white; box-shadow: 0 4px 15px rgba(0,0,0,0.15); transition: transform 0.2s; }
        .ozet-kutu:hover { transform: translateY(-5px); }
        .ozet-baslik { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; margin-bottom: 5px; }
        .ozet-deger { font-size: 1.5rem; font-weight: bold; }

        .btn-islem { border-radius: 15px; padding: 20px; font-size: 1.1rem; font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: none; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px; height: 100%; color: white; }
        .btn-islem:hover { transform: scale(1.02); box-shadow: 0 8px 15px rgba(0,0,0,0.15); }
        .btn-islem-alis { background: linear-gradient(45deg, #e74a3b, #c0392b); }
        .btn-islem-odeme { background: linear-gradient(45deg, #1cc88a, #17a673); }

        @media (max-width: 768px) {
            .ozet-deger { font-size: 1.2rem; }
            .btn-islem { padding: 15px; font-size: 1rem; }
            .container { padding: 10px; }
            .no-print { display: block; }
        }

        .print-summary { display: none; }
        @media print {
            .no-print { display: none !important; }
            .card { border: none !important; shadow: none !important; background: none !important; }
            body { background-color: white !important; font-size: 12px; }
            .container { max-width: 100% !important; padding: 0 !important; }
            .ozet-kartlar-container { display: none !important; }
            .print-summary { display: flex !important; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; font-weight: bold; font-size: 14px; }
        }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4 no-print">
        <div class="container-fluid">
            <a class="navbar-brand text-danger" href="borclar.php"><i class="fas fa-wallet me-2"></i>Borç Yönetimi</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="nav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="anasayfa.php">Ana Sayfa</a></li>
                    <li class="nav-item"><a class="nav-link" href="tedarikciler.php">Alacaklılar</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container pb-5">
        
        <?php if($hataMesaji): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm no-print">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $hataMesaji; ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if($basariMesaji): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm no-print">
                <i class="fas fa-check-circle me-2"></i><?php echo $basariMesaji; ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- SİLİNMİŞ UYARISI -->
        <?php if($tedarikci['silindi'] == 1): ?>
            <div class="alert alert-danger text-center shadow-lg border-2 border-danger no-print">
                <h4 class="fw-bold"><i class="fas fa-trash-alt me-2"></i>BU TEDARİKÇİ SİLİNMİŞTİR!</h4>
                <p>Şu an çöp kutusunda. İşlem yapabilmek için geri yüklemelisiniz.</p>
                <form method="POST">
                    <input type="hidden" name="tedarikci_geri_yukle" value="1">
                    <button type="submit" class="btn btn-success fw-bold px-4"><i class="fas fa-undo me-2"></i>Geri Yükle</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Tedarikçi Kartı -->
        <div class="card shadow border-0 mb-4" style="border-radius: 15px; <?php echo ($tedarikci['silindi']==1) ? 'opacity:0.6; pointer-events:none;' : ''; ?>">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center mb-2">
                            <h2 class="mb-0 fw-bold text-dark me-3"><?php echo $tedarikci['firma_adi']; ?></h2>
                            <button class="btn btn-light text-primary shadow-sm rounded-circle no-print" data-bs-toggle="modal" data-bs-target="#modalTedarikciDuzenle"><i class="fas fa-pen"></i></button>
                        </div>
                        <div class="text-muted">
                            <span class="me-3 fw-bold"><?php echo $tedarikci['yetkili_kisi']; ?></span>
                            <span><i class="fas fa-phone me-1"></i> <a href="tel:<?php echo $tedarikci['telefon']; ?>" class="text-decoration-none text-dark fw-bold"><?php echo $tedarikci['telefon']; ?></a></span>
                        </div>
                        <?php if($tedarikci['iban']): ?>
                            <div class="mt-2 text-danger fw-bold p-2 bg-danger bg-opacity-10 rounded d-inline-block">
                                IBAN: <?php echo $tedarikci['iban']; ?>
                            </div>
                        <?php endif; ?>
                        <div class="small text-muted mt-2"><i class="fas fa-map-marker-alt me-1"></i> <?php echo $tedarikci['adres']; ?></div>
                    </div>
                    
                    <div class="col-md-4 text-md-end mt-3 mt-md-0 no-print d-flex flex-wrap gap-2 justify-content-md-end">
                        <button onclick="window.print()" class="btn btn-dark shadow-sm flex-fill flex-md-grow-0"><i class="fas fa-print me-2"></i>Ekstre</button>
                        
                        <?php if($tedarikci['durum'] == 1): ?>
                            <button class="btn btn-outline-secondary flex-fill flex-md-grow-0" data-bs-toggle="modal" data-bs-target="#modalArsivle"><i class="fas fa-archive me-1"></i>Arşivle</button>
                        <?php else: ?>
                            <button class="btn btn-outline-success flex-fill flex-md-grow-0" data-bs-toggle="modal" data-bs-target="#modalAktifEt"><i class="fas fa-undo me-1"></i>Aktif Et</button>
                        <?php endif; ?>
                        
                        <button class="btn btn-danger flex-fill flex-md-grow-0" data-bs-toggle="modal" data-bs-target="#modalSilSoft"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- BASKI İÇİN ÖZET -->
        <div class="print-summary">
            <div>TOPLAM ALIM: <?php echo number_format($toplamAlis, 2, ',', '.'); ?> ₺</div>
            <div>TOPLAM ÖDEME: <?php echo number_format($toplamOdeme, 2, ',', '.'); ?> ₺</div>
            <div>KALAN BORÇ: <?php echo number_format($kalanBorc, 2, ',', '.'); ?> ₺</div>
        </div>

        <!-- EKRAN İÇİN RENKLİ KARTLAR -->
        <div class="row mb-4 ozet-kartlar-container" style="<?php echo ($tedarikci['silindi']==1) ? 'opacity:0.6;' : ''; ?>">
            <div class="col-md-4 col-4 mb-2">
                <div class="ozet-kutu bg-gradient-danger h-100 d-flex flex-column justify-content-center text-center">
                    <div class="ozet-baslik">TOPLAM BORÇ (ALIŞ)</div>
                    <div class="ozet-deger"><?php echo number_format($toplamAlis, 0, ',', '.'); ?> ₺</div>
                </div>
            </div>
            <div class="col-md-4 col-4 mb-2">
                <div class="ozet-kutu bg-gradient-success h-100 d-flex flex-column justify-content-center text-center">
                    <div class="ozet-baslik">YAPILAN ÖDEME</div>
                    <div class="ozet-deger"><?php echo number_format($toplamOdeme, 0, ',', '.'); ?> ₺</div>
                </div>
            </div>
            <div class="col-md-4 col-4 mb-2">
                <div class="ozet-kutu bg-gradient-dark h-100 d-flex flex-column justify-content-center text-center">
                    <div class="ozet-baslik">KALAN BAKİYE</div>
                    <div class="ozet-deger"><?php echo number_format($kalanBorc, 0, ',', '.'); ?> ₺</div>
                </div>
            </div>
        </div>

        <!-- Büyük İşlem Butonları -->
        <div class="row mb-4 no-print g-3" style="<?php echo ($tedarikci['silindi']==1) ? 'display:none;' : ''; ?>">
            <div class="col-6">
                <button class="btn-islem btn-islem-alis w-100" data-bs-toggle="modal" data-bs-target="#modalAlis">
                    <i class="fas fa-shopping-cart fa-2x"></i> <span>BORÇ EKLE (ALIŞ)</span>
                </button>
            </div>
            <div class="col-6">
                <button class="btn-islem btn-islem-odeme w-100" data-bs-toggle="modal" data-bs-target="#modalOdeme">
                    <i class="fas fa-hand-holding-usd fa-2x"></i> <span>ÖDEME YAP</span>
                </button>
            </div>
        </div>

        <!-- Özel Notlar -->
        <div class="card shadow-sm border-0 mb-4 no-print" style="background-color: #fffbf0; border-left: 5px solid #f6c23e !important;">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <strong class="text-dark"><i class="fas fa-sticky-note me-2 text-warning"></i>Özel Notlar</strong>
                    <button class="btn btn-sm btn-link text-dark text-decoration-none" data-bs-toggle="modal" data-bs-target="#modalTedarikciDuzenle"><i class="fas fa-pen me-1"></i>Düzenle</button>
                </div>
                <p class="mb-0 mt-2 text-secondary"><?php echo $tedarikci['ozel_notlar'] ? nl2br($tedarikci['ozel_notlar']) : 'Henüz not eklenmemiş...'; ?></p>
            </div>
        </div>

        <!-- Hareketler Tablosu -->
        <div class="card shadow border-0 rounded-3 overflow-hidden" style="<?php echo ($tedarikci['silindi']==1) ? 'opacity:0.6;' : ''; ?>">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-secondary"><i class="fas fa-list-alt me-2"></i>Hesap Hareketleri</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle" style="min-width: 650px;">
                    <thead class="bg-light text-secondary">
                        <tr>
                            <th width="120" class="ps-3">Tarih</th>
                            <th>Açıklama</th>
                            <th class="text-end">Tutar</th>
                            <th class="text-center no-print" width="100">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($hareketler as $h): ?>
                        <tr>
                            <td class="ps-3 text-muted small"><?php echo date("d.m.Y", strtotime($h['islem_tarihi'])); ?></td>
                            <td>
                                <span class="fw-bold text-dark"><?php echo $h['aciklama']; ?></span>
                                <?php if($h['islem_turu'] == 'alis'): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger ms-1">Alış</span>
                                    <?php if($h['vade_tarihi']): ?><br><small class="text-muted">Vade: <?php echo date("d.m.Y", strtotime($h['vade_tarihi'])); ?></small><?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-success bg-opacity-10 text-success ms-1">Ödeme</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold <?php echo $h['islem_turu']=='odeme'?'text-success':'text-danger'; ?>">
                                <?php echo number_format($h['toplam_tutar'], 2, ',', '.'); ?> ₺
                            </td>
                            <td class="text-center no-print">
                                <?php if($tedarikci['silindi'] == 0): ?>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-light border text-primary" onclick='hareketDuzenle(<?php echo json_encode($h); ?>)'><i class="fas fa-pen"></i></button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Bu kaydı silmek istediğine emin misin?');">
                                        <input type="hidden" name="sil_id" value="<?php echo $h['id']; ?>">
                                        <button class="btn btn-sm btn-light border text-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end py-3">GENEL TOPLAM:</td>
                            <td class="text-end py-3 text-dark fs-5"><?php echo number_format($kalanBorc, 2, ',', '.'); ?> ₺</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>

    <!-- MODALLAR -->

    <!-- 1. MODAL: SİLME ONAY -->
    <div class="modal fade" id="modalSilSoft" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="tedarikci_sil_soft" value="1">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Tedarikçiyi Sil</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <strong>Bu işlem tedarikçiyi Çöp Kutusuna taşıyacaktır.</strong><br>
                            Veriler kaybolmaz, istediğiniz zaman geri yükleyebilirsiniz.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Onaylamak için Yönetici Şifresi:</label>
                            <input type="password" name="guvenlik_sifresi" class="form-control" placeholder="******" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-danger fw-bold">Evet, Sil</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. MODAL: ARŞİVLEME -->
    <div class="modal fade" id="modalArsivle" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="yeni_durum" value="0">
                    <div class="modal-header bg-secondary text-white"><h5 class="modal-title">Arşive Kaldır</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p>Pasif duruma getirmek üzeresiniz.</p>
                        <input type="password" name="guvenlik_sifresi" class="form-control" placeholder="Yönetici Şifresi" required>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-secondary">Onayla</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- 3. MODAL: AKTİF ETME -->
    <div class="modal fade" id="modalAktifEt" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="yeni_durum" value="1">
                    <div class="modal-header bg-success text-white"><h5 class="modal-title">Aktif Et</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p>Tekrar aktif listeye alınacak.</p>
                        <input type="password" name="guvenlik_sifresi" class="form-control" placeholder="Yönetici Şifresi" required>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-success">Aktif Et</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- 4. MODAL: BORÇ EKLE (ALIŞ) -->
    <div class="modal fade" id="modalAlis" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="islem_ekle" value="1">
                    <input type="hidden" name="islem_turu" value="alis">
                    <div class="modal-header bg-danger text-white"><h5 class="modal-title">Mal / Hizmet Alışı Ekle</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label>Tarih</label><input type="datetime-local" name="tarih" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
                        <div class="mb-3"><label>Açıklama</label><input type="text" name="aciklama" class="form-control" required placeholder="Örn: 2 Adet Lens Alımı"></div>
                        <div class="mb-3"><label>Tutar (TL)</label><input type="number" step="0.01" name="tutar" class="form-control" required></div>
                        <div class="mb-3"><label>Ödeme Vadesi (Opsiyonel)</label><input type="date" name="vade_tarihi" class="form-control"></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-danger">Borçlandır</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- 5. MODAL: ÖDEME YAP -->
    <div class="modal fade" id="modalOdeme" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="islem_ekle" value="1">
                    <input type="hidden" name="islem_turu" value="odeme">
                    <div class="modal-header bg-success text-white"><h5 class="modal-title">Ödeme Yap</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label>Tarih</label><input type="datetime-local" name="tarih" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
                        <div class="mb-3"><label>Açıklama</label><input type="text" name="aciklama" class="form-control" value="Nakit Ödeme" required></div>
                        <div class="mb-3"><label>Ödenen Tutar (TL)</label><input type="number" step="0.01" name="tutar" class="form-control" required></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-success">Ödemeyi Kaydet</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- 6. MODAL: TEDARİKÇİ DÜZENLE -->
    <div class="modal fade" id="modalTedarikciDuzenle" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="tedarikci_guncelle" value="1">
                    <div class="modal-header bg-warning text-dark"><h5 class="modal-title">Bilgileri Düzenle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label>Firma Adı</label><input type="text" name="firma_adi" class="form-control" value="<?php echo $tedarikci['firma_adi']; ?>" required></div>
                        <div class="row mb-3"><div class="col-6"><label>Yetkili</label><input type="text" name="yetkili_kisi" class="form-control" value="<?php echo $tedarikci['yetkili_kisi']; ?>"></div><div class="col-6"><label>Telefon</label><input type="text" name="telefon" class="form-control" value="<?php echo $tedarikci['telefon']; ?>"></div></div>
                        <div class="mb-3"><label>Adres</label><textarea name="adres" class="form-control"><?php echo $tedarikci['adres']; ?></textarea></div>
                        <div class="row mb-3"><div class="col-6"><label>Vergi No</label><input type="text" name="vergi_no" class="form-control" value="<?php echo $tedarikci['vergi_no']; ?>"></div><div class="col-6"><label>IBAN</label><input type="text" name="iban" class="form-control" value="<?php echo $tedarikci['iban']; ?>"></div></div>
                        <div class="mb-3"><label>Notlar</label><textarea name="ozel_notlar" class="form-control"><?php echo $tedarikci['ozel_notlar']; ?></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-warning">Güncelle</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- 7. MODAL: HAREKET DÜZENLE -->
    <div class="modal fade" id="modalHareketDuzenle" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="hareket_guncelle" value="1">
                    <input type="hidden" name="hareket_id" id="edit_hareket_id">
                    
                    <div class="modal-header bg-primary text-white"><h5 class="modal-title">İşlemi Düzenle</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label>İşlem Tarihi (Kilitli)</label><input type="text" id="edit_tarih" class="form-control bg-light" readonly></div>
                        <div class="mb-3"><label>Açıklama</label><input type="text" name="aciklama" id="edit_aciklama" class="form-control" required></div>
                        
                        <div id="alis_alanlari">
                            <div class="mb-3"><label>Ödeme Vadesi</label><input type="date" name="vade_tarihi" id="edit_vade" class="form-control"></div>
                        </div>
                        
                        <div class="mb-3"><label>Tutar (TL)</label><input type="number" step="0.01" name="tutar" id="edit_tutar" class="form-control" required></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-primary">Kaydı Güncelle</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function hareketDuzenle(data) {
            document.getElementById('edit_hareket_id').value = data.id;
            document.getElementById('edit_aciklama').value = data.aciklama;
            document.getElementById('edit_tutar').value = data.toplam_tutar;
            
            // Tarih Formatlama
            let dt = new Date(data.islem_tarihi);
            dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
            document.getElementById('edit_tarih').value = dt.toISOString().slice(0,16).replace('T', ' ');

            if(data.islem_turu == 'alis') {
                document.getElementById('alis_alanlari').style.display = 'block';
                document.getElementById('edit_vade').value = data.vade_tarihi ? data.vade_tarihi : '';
            } else {
                document.getElementById('alis_alanlari').style.display = 'none';
            }

            var myModal = new bootstrap.Modal(document.getElementById('modalHareketDuzenle'));
            myModal.show();
        }
    </script>
</body>
</html>