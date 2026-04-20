<?php
require 'baglanti.php';

// Güvenlik
if (!isset($_SESSION['kullanici_id']) || !isset($_GET['id'])) {
    die("Yetkisiz işlem.");
}

$hareket_id = intval($_GET['id']);
$firma_id = $_SESSION['firma_id'];

// Firma Bilgilerini Çek (Başlık ve Alt Kısım İçin)
$firmaSorgu = $db->prepare("SELECT firma_adi, telefon, adres, il, ilce FROM firmalar WHERE id = ?");
$firmaSorgu->execute([$firma_id]);
$firma = $firmaSorgu->fetch(PDO::FETCH_ASSOC);
$firmaAdi = $firma['firma_adi'] ?? 'Firma Adı';
$telefonno = $firma['telefon'] ?? '';
$telefonFormatted = formatTelefon($telefonno);

// Hareket ve Müşteri Bilgisini Çek
$sorgu = $db->prepare("
    SELECT h.*, m.ad_soyad, m.musteri_no, m.telefon, m.adres, m.tc_vergi_no 
    FROM hareketler h 
    JOIN musteriler m ON h.musteri_id = m.id 
    WHERE h.id = ? AND h.firma_id = ?
");
$sorgu->execute([$hareket_id, $firma_id]);
$veri = $sorgu->fetch(PDO::FETCH_ASSOC);

if (!$veri) { die("Kayıt bulunamadı."); }

// --- DİNAMİK METİNLER ---
if ($veri['islem_turu'] == 'satis') {
    $belgeBasligi = "REZERVASYON FİŞİ"; // Satış/Hizmet ekleme ise
    $tutarEtiketi = "İŞLEM TUTARI";
    $aciklamaEtiketi = "Hizmet / Ürün";
} else {
    $belgeBasligi = "TAHSİLAT MAKBUZU"; // Tahsilat ise
    $tutarEtiketi = "TAHSİL EDİLEN";
    $aciklamaEtiketi = "İşlem Açıklaması";
}

// Telefon formatlama fonksiyonu
function formatTelefon($numara) {
    // Sadece rakamları al
    $numara = preg_replace('/\D/', '', $numara);

    // 11 haneli numara mı kontrol et
    if(strlen($numara) == 11) {
        return substr($numara,0,1) . ' ' .
               substr($numara,1,3) . ' ' .
               substr($numara,4,3) . ' ' .
               substr($numara,7,2) . ' ' .
               substr($numara,9,2);
    }

    // Eğer 10 haneli numara (bazen başında 0 yok) ise
    if(strlen($numara) == 10) {
        return '0 ' .
               substr($numara,0,3) . ' ' .
               substr($numara,3,3) . ' ' .
               substr($numara,6,2) . ' ' .
               substr($numara,8,2);
    }

    // Diğer durumlarda olduğu gibi döndür
    return $numara;
}

// --- YAZIYLA PARA ÇEVİRME FONKSİYONU ---
function yaziyla($sayi) {
    $sayi = str_replace(",",".",$sayi);
    $kurus = explode(".", number_format($sayi, 2, ".", ""));
    $lira = $kurus[0];
    $kurus = $kurus[1];

    $birler = ["", "Bir", "İki", "Üç", "Dört", "Beş", "Altı", "Yedi", "Sekiz", "Dokuz"];
    $onlar = ["", "On", "Yirmi", "Otuz", "Kırk", "Elli", "Altmış", "Yetmiş", "Seksen", "Doksan"];
    $binler = ["", "Bin", "Milyon", "Milyar"];

    $sonuc = "";
    
    // Lira kısmı
    $basamak = strlen($lira);
    $grupSayisi = ceil($basamak / 3);
    
    $lira = str_pad($lira, $grupSayisi * 3, "0", STR_PAD_LEFT);
    
    for ($i = 0; $i < $grupSayisi; $i++) {
        $grupDegeri = (int)substr($lira, $i * 3, 3);
        if ($grupDegeri > 0) {
            $yuzler = floor($grupDegeri / 100);
            $onluk = floor(($grupDegeri % 100) / 10);
            $birlik = $grupDegeri % 10;
            
            $grupYazi = "";
            if ($yuzler > 0) $grupYazi .= ($yuzler > 1 ? $birler[$yuzler] : "") . "Yüz";
            if ($onluk > 0) $grupYazi .= $onlar[$onluk];
            if ($birlik > 0) {
                if ($basamak > 3 && $basamak < 7 && $birlik == 1 && $grupDegeri == 1) {
                    // "BirBin" dememek için
                } else {
                    $grupYazi .= $birler[$birlik];
                }
            }
            
            $sonuc .= $grupYazi . $binler[$grupSayisi - $i - 1];
        }
    }
    
    if ($sonuc == "") $sonuc = "Sıfır";
    $sonuc .= " Türk Lirası";

    // Kuruş kısmı
    if ($kurus > 0) {
        $k_onluk = floor($kurus / 10);
        $k_birlik = $kurus % 10;
        $sonuc .= ", " . $onlar[$k_onluk] . $birler[$k_birlik] . " Kuruş";
    }

    return $sonuc . "dır.";
}

$yaziylaTutar = yaziyla($veri['toplam_tutar']);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Makbuz Yazdır</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap');
        
        body { 
            background-color: #525659; 
            font-family: 'Roboto', sans-serif;
        }

        /* KÂĞIT TASARIMI */
        .page {
            background: white;
            display: block;
            margin: 20px auto;
            padding: 30px;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
            position: relative;
            /* Varsayılan A5 Görünümü için genişlik */
            width: 148mm; 
            min-height: 210mm;
        }

        /* İÇERİK STİLLERİ */
        .makbuz-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .makbuz-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .firma-adi {
            font-size: 1rem;
            font-weight: 400;
            margin-top: 5px;
            color: #333;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        .info-label { font-weight: bold; color: #555; }
        .info-value { font-weight: 600; color: #000; text-align: right; }

        .musteri-box {
            background-color: #f9f9f9;
            border: 1px solid #eee;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
        }
        .musteri-title {
            font-size: 0.8rem;
            color: #777;
            text-transform: uppercase;
            font-weight: bold;
            margin-bottom: 2px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 1px;
        }

        .hizmet-box {
            border: 2px solid #ca1212;
            padding: 15px;
            text-align: center;
            margin: 10x 0;
            background: #fff;
            border-radius: 5px;
        }
        

        .tutar-box {
            border: 2px solid #000000;
            padding: 15px;
            text-align: center;
            margin: 10px 0;
            background: #fff;
            border-radius: 5px;
        }
        .tutar-label { font-size: 0.9rem; font-weight: bold; color: #555; }
        .tutar-val { font-size: 1.5rem; font-weight: 800; color: #000; margin: 5px 0; }
        .tutar-text { font-style: italic; font-size: 0.85rem; color: #666; }

        .footer-area {
            margin-top: 40px;
            text-align: center;
            border-top: 1px dashed #ccc;
            padding-top: 20px;
        }
        .footer-firma { font-weight: bold; font-size: 1.1rem; margin-bottom: 5px; }
        .footer-msg { font-size: 0.9rem; color: #555; }

        /* YAZDIRMA AYARLARI */
        @media print {
            body { background: none; }
            .no-print { display: none !important; }
            .page { 
                box-shadow: none; 
                margin: 0; 
                padding: 15px; 
                width: 100% !important; 
                height: auto !important; 
            }
            
            /* A5 Modu Seçilirse */
            .a5-mode {
                width: 148mm !important;
                height: 210mm !important;
            }
        }
    </style>
</head>
<body>

    <!-- KONTROL PANELİ (Yazıcıda Gizlenir) -->
    <div class="container text-center mt-3 mb-3 no-print">
        <div class="bg-white p-3 rounded shadow-sm d-inline-block">
            <button onclick="yazdir('a4')" class="btn btn-dark me-2">
                <i class="fas fa-print me-2"></i>Normal Yazdır
            </button>
            <button onclick="yazdir('a5')" class="btn btn-outline-dark me-2">
                <i class="fas fa-file-alt me-2"></i>A5 (Yarım) Yazdır
            </button>
            <button onclick="window.close()" class="btn btn-secondary">Kapat</button>
        </div>
    </div>

    <!-- MAKBUZ KAĞIDI -->
    <div id="makbuzSayfasi" class="page">
        
        <!-- BAŞLIK -->
        <div class="makbuz-header">
            <h1 class="makbuz-title"><?php echo $belgeBasligi; ?></h1>
            <div class="firma-adi"><?php echo htmlspecialchars($firmaAdi); ?></div>
        </div>

        <!-- TARİH BİLGİSİ -->
        <div class="info-row">
            <span class="info-label">Tarih:</span>
            <span class="info-value"><?php echo date("d.m.Y", strtotime($veri['islem_tarihi'])); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Saat:</span>
            <span class="info-value"><?php echo date("H:i", strtotime($veri['islem_tarihi'])); ?></span>
        </div>

        <!-- MÜŞTERİ BİLGİSİ -->
        <div class="musteri-box">
            <div class="musteri-title">SAYIN</div>
            <div class="fs-5 fw-bold mb-1"><?php echo htmlspecialchars($veri['ad_soyad']); ?></div>
            
        </div>

        <!-- İŞLEM DETAYI -->
        <div class="hizmet-box">
            <strong class="d-block text-muted small text-uppercase mb-1"><?php echo $aciklamaEtiketi; ?>:</strong>
            <div class="border-bottom pb-2 fw-bold">
                <?php echo htmlspecialchars($veri['urun_aciklama']); ?>
            </div>
            <?php if($veri['vade_tarihi']): ?>
            <div class="mt-1 small text-muted">
                Rezervasyon Tarihi: <?php echo date("d.m.Y", strtotime($veri['vade_tarihi'])); ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- TUTAR -->
<div class="tutar-box">
    <div class="tutar-label"><?php echo $tutarEtiketi; ?></div>
    <div class="tutar-val"><?php echo number_format($veri['toplam_tutar'], 2, ',', '.'); ?> ₺</div>
    <div class="tutar-text">(Yalnız: <?php echo $yaziylaTutar; ?>)</div>
</div>

<!-- ÖZEL MESAJ / NOT BOX DIŞINDA -->
<div class="mt-2 fw-semibold text-center text-muted" style="font-size: 0.9rem;">
    <?php 
    if($veri['islem_turu'] == 'satis') {
        echo "Yukarıdaki yazılı bilgiler dahilinde rezervasyonunuz oluşturulmuştur.";
        if(!empty($veri['not'])) {
            echo "<br><small>Not: " . htmlspecialchars($veri['not']) . "</small>";
        }
    } else {
        echo "Yukarıdaki bilgiler dahilinde ödemeniz alınmıştır.";
    }
    ?>
</div>

        
        <!-- FOOTER / İMZA YERİNE -->
        <div class="footer-area">
            <div class="footer-firma"><?php echo htmlspecialchars($firmaAdi); ?></div>
             <div class="footer-firma"><?php echo htmlspecialchars($telefonFormatted); ?></div>
            <div class="footer-msg">Teşekkür Ederiz.</div>
            
            <!-- Sistem İmzası (İsteğe Bağlı) -->
            <div class="mt-4 pt-2 border-top text-muted" style="font-size: 10px;">
                Bu belge <?php echo date('d.m.Y H:i'); ?> tarihinde ibiR Cari sistemi üzerinden üretilmiştir. <br>
                Belgenin geçerliliği ve gerekli sorumluluk, sistemi kullanan firmaya aittir.
            </div>
        </div>

    </div>

    <script>
        function yazdir(tip) {
            var sayfa = document.getElementById('makbuzSayfasi');
            
            // Önce temizle
            sayfa.classList.remove('a5-mode');
            
            if(tip === 'a5') {
                sayfa.classList.add('a5-mode');
                // Yazıcı ayarlarını tetiklemek için CSS @page kuralını dinamik değiştirebiliriz ama 
                // tarayıcılar genelde kullanıcı seçimine bırakır. Biz görünümü A5 boyutuna zorluyoruz.
            } else {
                // A4 / Normal mod için genişliği serbest bırak veya A4 ayarla
                sayfa.style.width = '100%';
                sayfa.style.maxWidth = '800px';
            }
            
            window.print();
            
            // Yazdırdıktan sonra stilleri eski haline (varsayılan A5 önizleme) getirebiliriz
            if(tip !== 'a5') {
                sayfa.style.width = '148mm'; // Eski haline döndür
            }
        }
    </script>
</body>
</html>