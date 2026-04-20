<?php
// Olası hataları gizle (Cron çalışırken gereksiz çıktı vermesin)
ini_set('display_errors', 0);
error_reporting(0);

// --- GÜVENLİK KONTROLÜ ---
// Bu dosyayı tarayıcıdan tetiklemek istersen url sonuna ?key=IBIR_GIZLI_CRON_2024 eklemelisin.
// Sunucu (CLI) üzerinden çalışıyorsa şifre sormaz.
$gizli_anahtar = "IBIR_GIZLI_CRON_2024"; 

if (php_sapi_name() !== 'cli' && (!isset($_GET['key']) || $_GET['key'] !== $gizli_anahtar)) {
    http_response_code(403);
    die("Erişim reddedildi. Yetkisiz işlem.");
}

// Bağlantı dosyasını bir üst dizinden çağırıyoruz
require_once dirname(__DIR__) . '/baglanti.php';

try {
    // İşlemi garantiye almak için Transaction başlat
    $db->beginTransaction();
    
    // 1. SÜRESİ DOLAN FİRMALARI PASİFE AL
    // Abonelik bitiş tarihi bugünden küçük olan firmaları dondur
    $pasif_sorgu = "UPDATE firmalar SET durum = 0 WHERE durum = 1 AND abonelik_bitis < CURDATE()";
    $stmt1 = $db->prepare($pasif_sorgu);
    $stmt1->execute();
    $pasif_edilen = $stmt1->rowCount();

    // 2. AKTİF FİRMALARIN YENİ AY KULLANIMINI SIFIRLA (SAAS MANTIĞI)
    // Sadece "kullanilan_sms_aylik" değerini sıfırlıyoruz. 
    // "ek_sms_bakiyesi" (parayla alınan) ve "aylik_sms_limiti" (paketten gelen hak) KORUNUYOR!
    $sifirla_sorgu = "UPDATE firmalar SET kullanilan_sms_aylik = 0 WHERE durum = 1 AND abonelik_bitis >= CURDATE()";
    $stmt2 = $db->prepare($sifirla_sorgu);
    $stmt2->execute();
    $sifirlanan_firma = $stmt2->rowCount();
    
    $db->commit();
    
    // --- İŞLEMİ LOGLAMA ---
    $ip = '127.0.0.1 (CRON/SYSTEM)';
    $detay = "Aylık SMS Yenileme (Yeni Sistem): $sifirlanan_firma firmanın aylık SMS sayacı sıfırlandı (Ek bakiyeler korundu). $pasif_edilen firma donduruldu.";
    
    $logSorgu = $db->prepare("INSERT INTO sistem_loglari (firma_id, kullanici_id, islem, detay, ip_adresi) VALUES ('SYSTEM', NULL, 'Aylık Bakım & SMS Sıfırlama', ?, ?)");
    $logSorgu->execute([$detay, $ip]);
    
    // İşlem bittiğinde başarılı çıktısı ver
    echo "Başarılı: $sifirlanan_firma firmanın yeni ay SMS kullanımı sıfırlandı. Tarih: " . date('Y-m-d H:i:s');
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Hatayı logla
    $hata_detay = "Cron Job çalışırken hata oluştu: " . $e->getMessage();
    $logSorgu = $db->prepare("INSERT INTO sistem_loglari (firma_id, kullanici_id, islem, detay, ip_adresi) VALUES ('SYSTEM', NULL, 'Cron Hatası', ?, '127.0.0.1')");
    $logSorgu->execute([$hata_detay]);
    
    echo "Hata: İşlem tamamlanamadı.";
}
?>