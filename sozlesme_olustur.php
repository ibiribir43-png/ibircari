<?php
session_start();
require 'baglanti.php';
require_once 'partials/security_check.php';

// Hata raporlamayı açalım (Hata bitene kadar açık kalsın)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 1. VERİ ÇEKME ---
if (isset($_GET['t']) && !empty($_GET['t'])) {
    $token = $_GET['t'];
    $bul = $db->prepare("SELECT * FROM musteriler WHERE url_token = ? AND firma_id = ?");
    $bul->execute([$token, $firma_id]);
    $musteri = $bul->fetch(PDO::FETCH_ASSOC);
    if (!$musteri) { die("Geçersiz müşteri."); }
    $id = $musteri['id'];
} else { header("Location: musteriler.php"); exit; }

// Firma Bilgileri ve Sözleşme Maddeleri
$firma_sorgu = $db->prepare("SELECT * FROM firmalar WHERE id = ?");
$firma_sorgu->execute([$firma_id]);
$firma_bilgi = $firma_sorgu->fetch(PDO::FETCH_ASSOC);

// Yazdırma Modu (Eski sözleşmeyi görüntüleme)
$print_data = null;
if (isset($_GET['print_id'])) {
    $s_id = $_GET['print_id'];
    $s_sorgu = $db->prepare("SELECT * FROM sozlesmeler WHERE id = ? AND firma_id = ?");
    $s_sorgu->execute([$s_id, $firma_id]);
    $print_data = $s_sorgu->fetch(PDO::FETCH_ASSOC);
}

// Müşterinin Mevcut Hareketleri (Forma ilk girişte gelsin)
$hareketler_sorgu = $db->prepare("SELECT * FROM hareketler WHERE musteri_id = ? AND firma_id = ? AND islem_turu = 'satis' ORDER BY vade_tarihi ASC");
$hareketler_sorgu->execute([$id, $firma_id]);
$mevcut_hareketler = $hareketler_sorgu->fetchAll(PDO::FETCH_ASSOC);

// --- 2. KAYIT İŞLEMİ ---
$basari = false;
$sozlesme_no = "";
$db_hata = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sozlesme_kaydet'])) {
    try {
        // Benzersiz numara
        $sozlesme_no = $firma_id . "-" . $id . "-" . date('Ymd') . "-" . rand(100, 999);
        
        // Satırları dinamik topla
        $kalemler = [];
        if (isset($_POST['h_baslik'])) {
            for ($i = 0; $i < count($_POST['h_baslik']); $i++) {
                if (!empty($_POST['h_baslik'][$i])) {
                    $kalemler[] = [
                        'baslik' => $_POST['h_baslik'][$i],
                        'tarih'  => $_POST['h_tarih'][$i],
                        'tutar'  => $_POST['h_tutar'][$i]
                    ];
                }
            }
        }

        $toplam_tutar = floatval($_POST['ucret_toplam'] ?? 0);
        $kaparo = floatval($_POST['ucret_kaparo'] ?? 0);
        $kalan = $toplam_tutar - $kaparo;

        // INSERT işlemi
        $kaydet = $db->prepare("INSERT INTO sozlesmeler 
            (firma_id, musteri_id, sozlesme_no, gelin_ad, damat_ad, paket_kalemleri, toplam_tutar, kaparo, kalan_bakiye, sozlesme_tarihi, odeme_detay, sozlesme_maddeleri) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $sonuc = $kaydet->execute([
            $firma_id, $id, $sozlesme_no, 
            $_POST['gelin_ad'], $_POST['damat_ad'], 
            json_encode($kalemler, JSON_UNESCAPED_UNICODE),
            $toplam_tutar, $kaparo, $kalan,
            $_POST['tarih_sozlesme'],
            $_POST['odeme_detay'],
            $_POST['sozlesme_maddeleri']
        ]);

        if ($sonuc) {
            // Müşterinin ana kartındaki Gelin/Damat adlarını da güncelleyelim ki sistemde kalsın
            $db->prepare("UPDATE musteriler SET sozlesme_no = ?, gelin_ad = ?, damat_ad = ? WHERE id = ? AND firma_id = ?")
               ->execute([$sozlesme_no, $_POST['gelin_ad'], $_POST['damat_ad'], $id, $firma_id]);
            $basari = true;
        }
    } catch (PDOException $e) {
        $db_hata = "Veritabanı Hatası: " . $e->getMessage();
    }
}

function tr_tarih($tarih) { return $tarih ? date("d.m.Y", strtotime($tarih)) : "---"; }
function para($tutar) { return number_format((float)$tutar, 2, ',', '.') . ' ₺'; }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Sözleşme Hazırla | <?php echo htmlspecialchars($musteri['ad_soyad']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, sans-serif; }
        .no-print-area { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .contract-a4 {
            background: white; width: 210mm; min-height: 297mm; padding: 15mm 20mm; margin: 20px auto;
            box-shadow: 0 0 20px rgba(0,0,0,0.15); font-size: 9.5pt; color: #1a1a1a; line-height: 1.4;
        }
        .kaşe-kutu { border: 2px solid #333; padding: 12px; text-align: center; font-weight: bold; border-radius: 4px; }
        .signature-line { border-top: 1px solid #000; padding-top: 8px; width: 40%; text-align: center; font-weight: bold; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; margin: 0; }
            .contract-a4 { box-shadow: none; margin: 0; width: 100%; border:none; }
        }
    </style>
</head>
<body>

<div class="container py-4 no-print">
    <div class="no-print-area">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0 fw-bold text-dark"><i class="fas fa-edit me-2"></i>Sözleşmeyi Özelleştir</h4>
            <a href="musteri_detay.php?t=<?php echo $token; ?>" class="btn btn-outline-secondary btn-sm">Geri Dön</a>
        </div>

        <?php if($db_hata): ?>
            <div class="alert alert-danger">
                <strong>HATA!</strong> Veritabanı işlemi sırasında bir sorun oluştu. <br>
                <code><?php echo $db_hata; ?></code>
            </div>
        <?php endif; ?>

        <?php if(!$basari && !$print_data): ?>
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Sözleşme Tarihi</label>
                    <input type="date" name="tarih_sozlesme" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Müşteri (TC Sahibi)</label>
                    <input type="text" class="form-control bg-light" value="<?php echo $musteri['ad_soyad']; ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label text-danger fw-bold">Gelin Adı</label>
                    <input type="text" name="gelin_ad" class="form-control" value="<?php echo $musteri['gelin_ad']; ?>" placeholder="Gelin...">
                </div>
                <div class="col-md-3">
                    <label class="form-label text-primary fw-bold">Damat Adı</label>
                    <input type="text" name="damat_ad" class="form-control" value="<?php echo $musteri['damat_ad']; ?>" placeholder="Damat...">
                </div>

                <div class="col-12 mt-4">
                    <h6 class="fw-bold border-bottom pb-2">Hizmet Kalemleri (Düzenlenebilir)</h6>
                    <table class="table table-sm" id="hizmetTable">
                        <thead class="table-light">
                            <tr>
                                <th>Hizmet Açıklaması</th>
                                <th width="140">Tarih</th>
                                <th width="140">Tutar</th>
                                <th width="40"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($mevcut_hareketler as $h): ?>
                            <tr>
                                <td><input type="text" name="h_baslik[]" class="form-control form-control-sm" value="<?php echo $h['urun_aciklama']; ?>"></td>
                                <td><input type="text" name="h_tarih[]" class="form-control form-control-sm" value="<?php echo $h['vade_tarihi']; ?>" placeholder="YYYY-AA-GG"></td>
                                <td><input type="number" step="0.01" name="h_tutar[]" class="form-control form-control-sm h-tutar" value="<?php echo $h['toplam_tutar']; ?>" oninput="hesapla()"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); hesapla()"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-sm btn-dark" onclick="satirEkle()"><i class="fas fa-plus me-1"></i>Yeni Hizmet Satırı Ekle</button>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Ödeme Yöntemi / Taksit Detayı</label>
                    <input type="text" name="odeme_detay" class="form-control" placeholder="Örn: Nakit, 3 Taksit vb.">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Toplam Bedel</label>
                    <input type="number" step="0.01" name="ucret_toplam" id="u_toplam" class="form-control fw-bold text-success" value="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold text-danger">Kaparo (Ödenen)</label>
                    <input type="number" step="0.01" name="ucret_kaparo" id="u_kaparo" class="form-control" value="0" oninput="hesapla()">
                </div>
                
                <div class="col-12 mt-3 text-end">
                    <div class="small fw-bold text-muted">HESAPLANAN KALAN BAKİYE</div>
                    <h3 class="text-danger fw-bold mb-0" id="kalan_text">0,00 ₺</h3>
                </div>

                <div class="col-12 mt-3">
                    <label class="form-label fw-bold text-secondary">Sözleşme Maddeleri (Firmaya Özel)</label>
                    <textarea name="sozlesme_maddeleri" class="form-control" rows="8"><?php echo $firma_bilgi['sozlesme_maddeleri'] ?: "Lütfen firma ayarlarından sözleşme maddelerinizi ekleyin."; ?></textarea>
                </div>
            </div>
            <div class="text-center mt-4">
                <button type="submit" name="sozlesme_kaydet" class="btn btn-primary btn-lg px-5 shadow">Kaydet ve Sözleşmeyi Göster</button>
            </div>
        </form>
        <?php else: ?>
            <div class="alert alert-success text-center">
                <h5><i class="fas fa-check-circle me-2"></i> Sözleşme Hazır!</h5>
                <div class="btn-group mt-2">
                    <button onclick="window.print()" class="btn btn-dark btn-lg"><i class="fas fa-print me-2"></i> Yazdır / PDF</button>
                    <a href="musteri_detay.php?t=<?php echo $token; ?>" class="btn btn-outline-dark btn-lg">Geri Dön</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ================= A4 TASARIMI ================= -->
<?php 
$s = $print_data ? $print_data : ($_POST ?: null);
if ($s || $basari): 
    $s_gelin = $basari ? $_POST['gelin_ad'] : ($print_data ? $print_data['gelin_ad'] : $musteri['gelin_ad']);
    $s_damat = $basari ? $_POST['damat_ad'] : ($print_data ? $print_data['damat_ad'] : $musteri['damat_ad']);
    
    if($basari){
        $s_kalemler = $kalemler;
    } elseif($print_data){
        $s_kalemler = json_decode($print_data['paket_kalemleri'], true);
    } else {
        $s_kalemler = $mevcut_hareketler;
    }
    
    $s_toplam = $basari ? $_POST['ucret_toplam'] : ($print_data ? $print_data['toplam_tutar'] : 0);
    $s_kaparo = $basari ? $_POST['ucret_kaparo'] : ($print_data ? $print_data['kaparo'] : 0);
    $s_maddeler = $basari ? $_POST['sozlesme_maddeleri'] : ($print_data ? $print_data['sozlesme_maddeleri'] : $firma_bilgi['sozlesme_maddeleri']);
    $s_odeme = $basari ? $_POST['odeme_detay'] : ($print_data ? $print_data['odeme_detay'] : '---');
    $s_tarih = $basari ? $_POST['tarih_sozlesme'] : ($print_data ? $print_data['sozlesme_tarihi'] : date('Y-m-d'));
?>
<div class="contract-a4">
    <div class="text-center border-bottom pb-3 mb-4">
        <h4 class="fw-bold mb-1">HİZMET SATIŞ SÖZLEŞMESİ</h4>
        <div class="small text-muted">No: <?php echo $basari ? $sozlesme_no : ($print_data ? $print_data['sozlesme_no'] : 'TASLAK'); ?></div>
    </div>

    <div class="row g-4">
        <!-- Firma Kaşe (Sadece Unvan ve Bilgiler) -->
        <div class="col-6">
            <div class="kaşe-kutu" style="min-height: 140px; display: flex; flex-direction: column; justify-content: center;">
                <div class="mb-2 text-uppercase"><?php echo $firma_bilgi['firma_adi']; ?></div>
                <div style="font-weight: normal; font-size: 8.5pt;">
                    <?php echo $firma_bilgi['adres']; ?><br>
                    <?php echo $firma_bilgi['vergi_dairesi']; ?> V.D. | Vergi No: <?php echo $firma_bilgi['vergi_no']; ?><br>
                    Tel: <?php echo $firma_bilgi['telefon']; ?>
                </div>
            </div>
        </div>
        
        <!-- Hizmet Alan (Yerleşim İsteklerine Göre Güncellendi) -->
        <div class="col-6">
            <div class="p-3 border rounded" style="min-height: 140px; display: flex; flex-direction: column; justify-content: center;">
                <div class="fw-bold text-decoration-underline mb-2">HİZMET ALAN (MÜŞTERİ)</div>
                <div class="mb-1 small">
                    <strong>TC:</strong> <?php echo $musteri['tc_vergi_no']; ?> | <strong>Tel:</strong> <?php echo $musteri['telefon']; ?>
                </div>
                <div class="mb-1">
                    <strong>Müşteri:</strong> <?php echo mb_strtoupper($musteri['ad_soyad'], 'UTF-8'); ?>
                    <?php if($s_gelin || $s_damat): ?>
                         : <?php echo $s_gelin; ?> & <?php echo $s_damat; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Hizmet Tablosu -->
    <table class="table table-bordered table-sm mt-4" style="font-size: 9pt;">
        <thead class="table-light">
            <tr>
                <th>Hizmet / Paket Tanımı</th>
                <th width="110" class="text-center">Planlanan Tarih</th>
                <th width="110" class="text-end">Tutar</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($s_kalemler as $sk): ?>
            <tr>
                <td><?php echo $sk['baslik'] ?? $sk['urun_aciklama']; ?></td>
                <td class="text-center"><?php echo tr_tarih($sk['tarih'] ?? $sk['vade_tarihi']); ?></td>
                <td class="text-end"><?php echo para($sk['tutar'] ?? $sk['toplam_tutar']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="fw-bold">
            <tr><td colspan="2" class="text-end">TOPLAM BEDEL:</td><td class="text-end"><?php echo para($s_toplam); ?></td></tr>
            <tr><td colspan="2" class="text-end">ALINAN KAPARO:</td><td class="text-end"><?php echo para($s_kaparo); ?></td></tr>
            <tr class="text-danger"><td colspan="2" class="text-end">KALAN BAKİYE:</td><td class="text-end"><?php echo para($s_toplam - $s_kaparo); ?></td></tr>
        </tfoot>
    </table>

    <div class="mb-3 small">
        <strong>Ödeme / Taksit Detayı:</strong> <?php echo $s_odeme; ?>
        <span class="float-end"><strong>Sözleşme Tarihi:</strong> <?php echo tr_tarih($s_tarih); ?></span>
    </div>

    <!-- Firma Maddeleri -->
    <div style="font-size: 8.5pt; text-align: justify; white-space: pre-line; border-top: 1px solid #eee; padding-top: 10px;">
        <?php echo $s_maddeler; ?>
    </div>

    <!-- İmza Alanı (İsteklere Göre Güncellendi) -->
    <div class="d-flex justify-content-between mt-5 pt-4">
        <div class="signature-line">
            FİRMA KAŞE / İMZA<br>
            <div style="font-weight: normal; font-size: 8pt; margin-top: 5px;">
                <?php echo $firma_bilgi['firma_adi']; ?><br>
                <?php echo $firma_bilgi['vergi_no']; ?>
            </div>
        </div>
        <div class="signature-line">
            MÜŞTERİ<br>
            <small><?php echo mb_strtoupper($musteri['ad_soyad'], 'UTF-8'); ?></small>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    function satirEkle() {
        const tbody = document.querySelector('#hizmetTable tbody');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="h_baslik[]" class="form-control form-control-sm" placeholder="Ek hizmet adı..."></td>
            <td><input type="date" name="h_tarih[]" class="form-control form-control-sm"></td>
            <td><input type="number" step="0.01" name="h_tutar[]" class="form-control form-control-sm h-tutar" oninput="hesapla()"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); hesapla()"><i class="fas fa-times"></i></button></td>
        `;
        tbody.appendChild(tr);
    }

    function hesapla() {
        let toplam = 0;
        document.querySelectorAll('.h-tutar').forEach(input => { 
            toplam += parseFloat(input.value || 0); 
        });
        document.getElementById('u_toplam').value = toplam.toFixed(2);
        const kaparo = parseFloat(document.getElementById('u_kaparo').value || 0);
        const kalan = toplam - kaparo;
        document.getElementById('kalan_text').innerText = kalan.toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ₺';
    }
    window.onload = hesapla;
</script>

</body>
</html>