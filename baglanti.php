<?php
/**
 * baglanti.php
 * Veritabanı bağlantıları ve merkezi fonksiyonlar.
 * DİKKAT: Bu dosya hem Cari DB hem de Seçim DB bağlantılarını yönetir.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Veritabanı Bilgileri
$host     = "localhost";
$user     = "ibi1e2ddingcom_ibiryenicaritakip011225";
$pass     = 'i1b2i3r443.'; // Tek tırnak kullanımı doğru
$db_name  = "ibi1e2ddingcom_yenicaritakip011225";
$secim_db = "ibi1e2ddingcom_secim";

try {
    // 1. ANA CARİ VERİTABANI BAĞLANTISI ($db)
    $db = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. SEÇİM VERİTABANI BAĞLANTISI ($db_secim)
    // Seçim verileri (favoriler, notlar vb.) bu veritabanında tutuluyor.
    $db_secim = new PDO("mysql:host=$host;dbname=$secim_db;charset=utf8mb4", $user, $pass);
    $db_secim->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    // Hata durumunda kullanıcıya teknik detay göstermeden mesaj veriyoruz.
    die("Veritabanı bağlantı hatası! Lütfen sistem yöneticisine bildirin.");
}

/**
 * MERKEZİ LOG FONKSİYONU
 * Sistemde yapılan kritik işlemleri 'sistem_loglari' tablosuna kaydeder.
 */
function logKaydet($db, $islem, $detay = "") {
    try {
        $uid = $_SESSION['kullanici_id'] ?? 0;       // Giriş yapılmamışsa 0
        $fid = $_SESSION['firma_id'] ?? 'SISTEM';    // Firma yoksa SISTEM
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $db->prepare(
            "INSERT INTO sistem_loglari (kullanici_id, firma_id, islem, detay, ip_adresi) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$uid, $fid, $islem, $detay, $ip]);
    } catch (Exception $e) {
        // Log hatası sistemi durdurmasın diye sadece error_log'a yazıyoruz.
        error_log("Log kaydetme hatası: " . $e->getMessage());
    }
}

/**
 * GÜVENLİK KONTROLÜ
 * Sadece giriş yapmış müşterilerin sayfaya erişmesini sağlar.
 */
function musteriAuthCheck() {
    if (!isset($_SESSION['musteri_auth']) || $_SESSION['musteri_auth'] !== true) {
        header("Location: index.php?error=auth");
        exit;
    }
}
?>