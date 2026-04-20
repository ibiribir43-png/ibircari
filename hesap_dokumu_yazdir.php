<?php
session_start();
require 'baglanti.php';
require_once 'partials/security_check.php';

if (!isset($_GET['t']) || empty($_GET['t'])) {
    die("Geçersiz işlem.");
}

$token = trim($_GET['t']);
$firma_id = $_SESSION['firma_id'];

// Müşteri Bilgilerini Çek
$bul = $db->prepare("SELECT * FROM musteriler WHERE url_token = ? AND firma_id = ?");
$bul->execute([$token, $firma_id]);
$musteri = $bul->fetch(PDO::FETCH_ASSOC);

if (!$musteri) {
    die("Müşteri bulunamadı.");
}

// Firma Bilgilerini Çek (Header / Footer için)
$firmaSorgu = $db->prepare("SELECT * FROM firmalar WHERE id = ?");
$firmaSorgu->execute([$firma_id]);
$firma = $firmaSorgu->fetch(PDO::FETCH_ASSOC);
$firmaAdi = $firma ? htmlspecialchars($firma['firma_adi']) : ($_SESSION['firma_adi'] ?? 'Firma Bilgisi');
$firmaTelefon = $firma ? htmlspecialchars($firma['telefon'] ?? '') : '';
$firmaAdres = $firma ? htmlspecialchars($firma['adres'] ?? '') : '';

// Hareketleri Çek
$hareketler = $db->prepare("SELECT *, COALESCE(vade_tarihi, islem_tarihi) as siralama_tarihi FROM hareketler WHERE musteri_id=? AND firma_id=? ORDER BY siralama_tarihi ASC, id ASC");
$hareketler->execute([$musteri['id'], $firma_id]);
$hareketler = $hareketler->fetchAll(PDO::FETCH_ASSOC);

$toplamBorc = 0; 
$toplamTahsilat = 0;

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hesap Dökümü - <?php echo htmlspecialchars($musteri['ad_soyad']); ?></title>
    <!-- İkonlar için FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { 
            box-sizing: border-box; 
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important; 
        }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            color: #333; 
            font-size: 14px; 
            background: #e9ecef; 
            margin: 0;
            padding: 20px;
        }
        .print-wrapper { 
            max-width: 800px; 
            margin: 0 auto; 
            padding: 40px; 
            background: #fff; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1); 
            min-height: 297mm; /* A4 height yaklaşık */
            position: relative;
            border-radius: 8px;
        }
        
        /* HEADER BÖLÜMÜ */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            border-bottom: 4px solid #4e73df; 
            padding-bottom: 15px; 
            margin-bottom: 25px; 
        }
        .doc-title { font-size: 26px; color: #4e73df; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; }
        .doc-date { font-size: 13px; color: #5a5c69; background: #f8f9fc; padding: 6px 12px; border-radius: 6px; border: 1px solid #eaecf4; }
        
        /* MÜŞTERİ BİLGİ KUTUSU */
        .customer-box { 
            background: #f8f9fc; 
            padding: 20px 25px; 
            border-radius: 10px; 
            margin-bottom: 25px; 
            border: 1px solid #e3e6f0;
            border-left: 6px solid #4e73df;
        }
        .customer-name { font-size: 19px; font-weight: bold; margin-bottom: 12px; color: #3a3b45; border-bottom: 1px solid #eaecf4; padding-bottom: 10px;}
        .customer-detail { 
            font-size: 13px; 
            color: #5a5c69; 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 12px 20px;
        }
        .customer-detail .full-width { grid-column: 1 / -1; }
        .icon-box { width: 20px; text-align: center; margin-right: 6px; color: #4e73df; }
        .icon-heart { color: #e74a3b; }
        
        /* TABLO TASARIMI */
        .table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 25px; border-radius: 8px; overflow: hidden; border: 1px solid #e3e6f0; }
        .table th, .table td { padding: 12px 15px; border-bottom: 1px solid #e3e6f0; text-align: left; }
        .table th { background-color: #4e73df; color: #ffffff; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
        .table tbody tr:nth-child(even) { background-color: #f8f9fc; }
        .table tbody tr:last-child td { border-bottom: none; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }
        .islem-icon { width: 20px; text-align: center; margin-right: 8px; border-radius: 50%; padding: 4px; font-size: 11px; }
        .icon-satis { background: #eaecf4; color: #4e73df; }
        .icon-tahsilat { background: #e3fbed; color: #1cc88a; }
        
        /* ALT BÖLÜM (TOPLAMLAR) */
        .bottom-section {
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
            margin-bottom: 40px;
        }
        
        /* TOPLAMLAR KUTUSU */
        .totals-box { 
            width: 50%; 
            padding: 20px; 
            border: 2px solid #eaecf4; 
            border-radius: 10px; 
            background: #ffffff; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }
        .totals-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; color: #5a5c69; }
        .totals-row.grand-total { 
            font-weight: 800; 
            font-size: 18px; 
            border-top: 2px dashed #eaecf4; 
            padding-top: 15px; 
            margin-top: 5px; 
            color: #3a3b45; 
            align-items: center;
        }
        .bakiye-pozitif { background-color: #fdf3f2; padding: 5px 10px; border-radius: 5px; color: #e74a3b !important; }
        .bakiye-negatif { background-color: #f0fdf4; padding: 5px 10px; border-radius: 5px; color: #1cc88a !important; }
        
        /* FOOTER (FİRMA BİLGİLERİ BURADA) */
        .footer { 
            text-align: center; 
            font-size: 12px; 
            color: #858796; 
            border-top: 2px solid #eaecf4; 
            padding-top: 20px;
            position: absolute;
            bottom: 40px;
            left: 40px;
            right: 40px;
        }
        .footer-company-name { font-size: 16px; font-weight: 800; color: #4e73df; margin-bottom: 5px; }
        .footer-company-details { margin-bottom: 12px; color: #5a5c69; }
        .footer-company-details i { color: #b7b9cc; margin: 0 4px; }
        .footer-disclaimer { font-size: 11px; color: #b7b9cc; }
        
        .text-danger { color: #e74a3b !important; }
        .text-success { color: #1cc88a !important; }
        .text-primary { color: #4e73df !important; }
        
        /* YAZDIRMA BUTONLARI */
        .no-print-area { text-align: center; margin-bottom: 25px; }
        .btn-print { background: #4e73df; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: bold; transition: 0.2s; box-shadow: 0 2px 5px rgba(78, 115, 223, 0.4); }
        .btn-print:hover { background: #2e59d9; transform: translateY(-2px); }
        .btn-close-print { background: #858796; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: bold; margin-left: 10px; transition: 0.2s;}
        .btn-close-print:hover { background: #60616f; }

        /* YAZICI AYARLARI */
        @media print {
            @page { size: A4; margin: 5mm 10mm; }
            html, body { background: #fff; margin: 0; padding: 0; width: 100%; height: 100%; }
            .print-wrapper { box-shadow: none; margin: 0; padding: 0; border: none; border-radius: 0; width: 100%; max-width: 100%; min-height: auto; position: static; }
            .footer { position: static; margin-top: 60px; padding-bottom: 20px; }
            .no-print-area { display: none !important; }
            .customer-box, .bottom-section { page-break-inside: avoid; }
        }
    </style>
</head>
<body onload="setTimeout(function(){ window.print(); }, 800);">

    <div class="no-print-area">
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print me-2"></i> Çıktı Al / PDF Kaydet</button>
        <button class="btn-close-print" onclick="window.close()"><i class="fas fa-times me-2"></i> Kapat</button>
    </div>

    <div class="print-wrapper">
        
        <!-- ÜST BİLGİ (HEADER) -->
        <div class="header">
            <div class="doc-title"><i class="fas fa-file-invoice" style="margin-right: 8px;"></i>HESAP DÖKÜMÜ</div>
            <div class="doc-date"><i class="far fa-calendar-alt" style="margin-right: 5px;"></i>Tarih: <strong><?php echo date('d.m.Y'); ?></strong></div>
        </div>

        <!-- MÜŞTERİ BİLGİLERİ -->
        <div class="customer-box">
            <div class="customer-name">
                <i class="fas fa-user-circle" style="color: #4e73df; margin-right: 8px; font-size: 22px; vertical-align: middle;"></i> 
                Sayın <?php echo htmlspecialchars($musteri['ad_soyad']); ?>
            </div>
            <div class="customer-detail">
                <?php if($musteri['gelin_ad'] || $musteri['damat_ad']): ?>
                    <div><i class="fas fa-heart icon-box icon-heart"></i> <strong>Çift:</strong> <?php echo htmlspecialchars($musteri['gelin_ad'] . " & " . $musteri['damat_ad']); ?></div>
                <?php endif; ?>
                
                <?php if($musteri['telefon']): ?>
                    <div><i class="fas fa-mobile-alt icon-box"></i> <strong>Telefon:</strong> <?php echo htmlspecialchars($musteri['telefon']); ?></div>
                <?php endif; ?>
                
                <?php if($musteri['tc_vergi_no']): ?>
                    <div><i class="fas fa-id-card icon-box"></i> <strong>VN/TC:</strong> <?php echo htmlspecialchars($musteri['tc_vergi_no']); ?></div>
                <?php endif; ?>
                
                <?php if($musteri['adres']): ?>
                    <div class="full-width"><i class="fas fa-map-marked-alt icon-box text-danger"></i> <strong>Adres:</strong> <?php echo htmlspecialchars($musteri['adres']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- HAREKETLER TABLOSU -->
        <table class="table">
            <thead>
                <tr>
                    <th width="5%" class="text-center">#</th>
                    <th width="15%">İşlem Kayıt Tarihi</th>
                    <th width="15%">Hizmet Tarihi</th>
                    <th>Açıklama / Detay</th>
                    <th width="20%" class="text-right">Tutar</th>
                </tr>
            </thead>
            <tbody>
                <?php if(count($hareketler) > 0): ?>
                    <?php 
                    $sayac = 1;
                    foreach($hareketler as $h): 
                        if ($h['islem_turu'] == 'satis') {
                            $toplamBorc += $h['toplam_tutar'];
                            $isaret = '';
                            $satir_icon = '<i class="fas fa-camera islem-icon icon-satis"></i>';
                        } else {
                            $toplamTahsilat += $h['toplam_tutar'];
                            $isaret = '-';
                            $satir_icon = '<i class="fas fa-hand-holding-usd islem-icon icon-tahsilat"></i>';
                        }
                    ?>
                    <tr>
                        <td class="text-center text-muted" style="font-size: 12px;"><?php echo $sayac++; ?></td>
                        <td><span style="color:#5a5c69; font-size: 13px;"><?php echo date("d.m.Y", strtotime($h['islem_tarihi'])); ?></span></td>
                        <td>
                            <?php 
                                if($h['islem_turu'] == 'satis' && $h['vade_tarihi']) {
                                    echo '<strong style="color:#3a3b45;">' . date("d.m.Y", strtotime($h['vade_tarihi'])) . '</strong>';
                                } else {
                                    echo '<span style="color:#d1d3e2;">-</span>';
                                }
                            ?>
                        </td>
                        <td>
                            <?php echo $satir_icon; ?> <strong style="color:#3a3b45;"><?php echo htmlspecialchars($h['urun_aciklama']); ?></strong>
                            <?php if($h['notlar']): ?>
                                <div style="color: #858796; font-size: 12px; margin-top: 4px; padding-left: 28px;">
                                    <i class="fas fa-info-circle text-primary" style="font-size: 10px;"></i> <?php echo htmlspecialchars($h['notlar']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right" style="font-weight: 600; <?php echo $h['islem_turu'] == 'tahsilat' ? 'color: #1cc88a;' : 'color: #3a3b45;'; ?>">
                            <?php echo $isaret . number_format($h['toplam_tutar'], 2, ',', '.'); ?> ₺
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center" style="padding: 40px; color: #858796;">
                            <i class="fas fa-folder-open mb-2" style="font-size: 24px; color: #d1d3e2; display:block;"></i>
                            Bu müşteriye ait hesap hareketi bulunmamaktadır.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ALT BÖLÜM: TOPLAMLAR -->
        <?php $bakiye = $toplamBorc - $toplamTahsilat; ?>
        <div class="bottom-section">

            <div class="totals-box">
                <div class="totals-row">
                    <span><i class="fas fa-receipt" style="color: #d1d3e2; margin-right: 5px;"></i> Toplam Hizmet Tutarı:</span>
                    <span style="font-weight: 600;"><?php echo number_format($toplamBorc, 2, ',', '.'); ?> ₺</span>
                </div>
                <div class="totals-row">
                    <span><i class="fas fa-coins" style="color: #d1d3e2; margin-right: 5px;"></i> Toplam Alınan Tutar:</span>
                    <span style="font-weight: 600;"><?php echo number_format($toplamTahsilat, 2, ',', '.'); ?> ₺</span>
                </div>
                <div class="totals-row grand-total">
                    <span style="text-transform: uppercase; font-size: 15px;">KALAN BAKİYE:</span>
                    <span class="<?php echo $bakiye > 0 ? 'bakiye-pozitif' : 'bakiye-negatif'; ?>">
                        <?php echo number_format($bakiye, 2, ',', '.'); ?> ₺
                    </span>
                </div>
            </div>
            
        </div>
        
        <div class="text-center" style="color: #4e73df; font-weight: bold; margin-bottom: 20px;">
            <i class="fas fa-handshake" style="margin-right: 5px;"></i> Bizi tercih ettiğiniz için teşekkür ederiz.
        </div>

        <!-- ALT BİLGİ (FOOTER) - FİRMA BİLGİLERİ -->
        <div class="footer">
            <div class="footer-company-name"><?php echo $firmaAdi; ?></div>
            <div class="footer-company-details">
                <?php if($firmaTelefon): ?>
                    <i class="fas fa-phone-alt"></i> <?php echo $firmaTelefon; ?>
                <?php endif; ?>
                
                <?php if($firmaTelefon && $firmaAdres): ?>
                    <span style="color:#d1d3e2; margin: 0 10px;">|</span>
                <?php endif; ?>
                
                <?php if($firmaAdres): ?>
                    <i class="fas fa-map-marker-alt"></i> <?php echo $firmaAdres; ?>
                <?php endif; ?>
            </div>
            
            <div class="footer-disclaimer">
                Bu belge sistem üzerinden <?php echo date('d.m.Y H:i'); ?> tarihinde oluşturulmuştur. <br>
                Hesap mutabakatı için bilgi amaçlıdır, resmi mali belge veya fatura yerine geçmez.
            </div>
        </div>

    </div>

</body>
</html>