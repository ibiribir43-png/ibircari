<?php
session_start();
require 'baglanti.php';

// --- GÜVENLİK KONTROLÜ ---
// 1. Token ile erişim (takvim.php'den export için)
// 2. Session ile erişim (direkt link için)

$firma_id = 0;
$firma_adi = "Bilinmeyen Firma";

// Yöntem 1: Token ile erişim
if (isset($_GET['firma_token'])) {
    $token = $_GET['firma_token'];
    
    // Token'dan firma bilgilerini çek
    $sorgu = $db->prepare("SELECT id, firma_adi FROM firmalar WHERE MD5(CONCAT(id, firma_adi)) = ?");
    $sorgu->execute([$token]);
    $firma = $sorgu->fetch(PDO::FETCH_ASSOC);
    
    if ($firma) {
        $firma_id = $firma['id'];
        $firma_adi = $firma['firma_adi'];
    } else {
        // Geçersiz token
        header('HTTP/1.1 403 Forbidden');
        die("Geçersiz erişim token'ı!");
    }
}
// Yöntem 2: Session ile erişim
elseif (isset($_SESSION['firma_id'])) {
    $firma_id = $_SESSION['firma_id'];
    $firma_adi = $_SESSION['firma_adi'] ?? 'Firma';
}
// Yöntem 3: Hiçbiri yoksa erişim engelle
else {
    header('HTTP/1.1 403 Forbidden');
    die("Erişim izniniz yok! Lütfen giriş yapın.");
}

// --- GOOGLE'I ZORLAMAK İÇİN ÖNBELLEK ENGELLEME BAŞLIKLARI ---
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]/', '_', $firma_adi) . '_takvim.ics"');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Sunucu Adını Otomatik Al
$serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';

// iCal Başlangıç
echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//" . $firma_adi . "//Hizmet Takvimi//TR\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:" . $firma_adi . " - İş Takvimi\r\n";
echo "X-WR-TIMEZONE:Europe/Istanbul\r\n";
echo "X-PUBLISHED-TTL:PT15M\r\n"; 

// --- TAKVİM.PHP İLE BİREBİR AYNI SORGULAR (Hatalı Sorgu Kaldırıldı) ---

// 1. Satış / Çekim İşlemleri (m.silindi = 0 eklendi)
$sql1 = "SELECT h.id, h.urun_aciklama, h.vade_tarihi as tarih, m.ad_soyad, m.telefon, m.adres 
         FROM hareketler h 
         JOIN musteriler m ON h.musteri_id = m.id 
         WHERE h.islem_turu='satis' AND h.vade_tarihi IS NOT NULL AND h.firma_id = ? AND m.silindi = 0";
$q1 = $db->prepare($sql1);
$q1->execute([$firma_id]);
while ($r = $q1->fetch(PDO::FETCH_ASSOC)) {
    yazEtkinlik('islem_'.$r['id'], 'Çekim: ' . $r['ad_soyad'] . ' - ' . $r['urun_aciklama'], $r['tarih'], $r['tarih'], $r['ad_soyad'], $r['telefon'], $r['adres'], $r['urun_aciklama']);
}

// 2. Serbest Takvim Etkinlikleri (Müşteri atanmışsa silinmemiş olma şartı eklendi)
$sql2 = "SELECT t.*, m.ad_soyad, m.telefon, m.adres FROM takvim_etkinlikleri t 
         LEFT JOIN musteriler m ON t.musteri_id = m.id 
         WHERE t.firma_id = ? AND (t.musteri_id IS NULL OR t.musteri_id = 0 OR m.silindi = 0)";
$q2 = $db->prepare($sql2);
$q2->execute([$firma_id]);
while ($r = $q2->fetch(PDO::FETCH_ASSOC)) {
    $baslik = $r['baslik'] . ($r['ad_soyad'] ? ' (' . $r['ad_soyad'] . ')' : '');
    $bitis = $r['bitis_tarihi'] ? $r['bitis_tarihi'] : $r['baslangic_tarihi'];
    $musteriAdi = $r['ad_soyad'] ?? 'Bağımsız';
    $tel = $r['telefon'] ?? '-';
    $adres = $r['adres'] ?? '';
    
    yazEtkinlik('serbest_'.$r['id'], 'Etkinlik: ' . $baslik, $r['baslangic_tarihi'], $bitis, $musteriAdi, $tel, $adres, $r['aciklama']);
}

echo "END:VCALENDAR";

// --- YARDIMCI FONKSİYON ---
function yazEtkinlik($uid, $baslik, $baslangic, $bitis, $musteri, $tel, $konum, $aciklama) {
    global $serverName, $firma_adi;
    
    // Tarih Formatlama
    $tamGun = (date('H:i:s', strtotime($baslangic)) == '00:00:00' && date('H:i:s', strtotime($bitis)) == '00:00:00');
    
    if($tamGun) {
        // Tam gün etkinlikleri
        $dtStartProp = ";VALUE=DATE:" . date("Ymd", strtotime($baslangic));
        $dtEndProp = ";VALUE=DATE:" . date("Ymd", strtotime($baslangic . ' +1 day')); 
    } else {
        // Saatli etkinlikler
        $dtStartProp = ":" . date("Ymd\THis", strtotime($baslangic));
        $dtEndProp = ":" . date("Ymd\THis", strtotime($bitis));
    }

    // iCal için özel karakterleri escape et
    $baslik = str_replace([",", ";"], ["\\,", "\\;"], strip_tags($baslik));
    $desc = "Firma: " . $firma_adi . "\\nMüşteri: " . $musteri . "\\nTel: " . $tel . "\\nNot: " . str_replace(["\r", "\n"], ["\\n", " "], strip_tags($aciklama));
    $loc = str_replace(["\r", "\n", ",", ";"], [" ", " ", "\\,", "\\;"], strip_tags($konum));
    $uniqueID = $uid . "@" . $serverName . "_" . preg_replace('/[^a-zA-Z0-9]/', '', $firma_adi);
    
    // UTC Zaman Damgası
    $dtStamp = gmdate("Ymd\THis\Z");

    echo "BEGIN:VEVENT\r\n";
    echo "UID:$uniqueID\r\n";
    echo "DTSTAMP:$dtStamp\r\n";
    echo "DTSTART$dtStartProp\r\n";
    echo "DTEND$dtEndProp\r\n";
    echo "SUMMARY:$baslik\r\n";
    echo "DESCRIPTION:$desc\r\n";
    if(!empty($loc) && $loc != ', ') {
        echo "LOCATION:$loc\r\n";
    }
    echo "STATUS:CONFIRMED\r\n";
    echo "END:VEVENT\r\n";
}