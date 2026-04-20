<?php
/**
 * musteri.ibircari.xyz/baglanti.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = "localhost";
$user = "ibi1e2ddingcom_ibiryenicaritakip011225";
$pass = 'i1b2i3r443.';

try {
    // 1. ANA CARI DB (Senin sisteminde $db olarak geçiyor)
    $db = new PDO("mysql:host=$host;dbname=ibi1e2ddingcom_yenicaritakip011225;charset=utf8mb4", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. SEÇİM DB (İstatistikler için)
    $db_secim = new PDO("mysql:host=$host;dbname=ibi1e2ddingcom_secim;charset=utf8mb4", $user, $pass);
    $db_secim->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Bağlantı Hatası: " . $e->getMessage());
}

// Güvenlik Fonksiyonu (Döngüye girmemesi için index.php'ye sadece auth yoksa yönlendirir)
function musteriAuthCheck() {
    if (!isset($_SESSION['musteri_auth']) || $_SESSION['musteri_auth'] !== true) {
        header("Location: index.php?error=auth");
        exit;
    }
}
?>