<?php
// partials/security_check.php
// TÜM YÖNETİM SAYFALARINDA İLK ÇAĞRILACAK

if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: index.php");
    exit;
}

// Kullanıcı bilgilerini global değişkenlere ata
$kullanici_adi = $_SESSION['ad_soyad'] ?? 'Kullanıcı';
$firma_adi = $_SESSION['firma_adi'] ?? 'Firma Paneli';
$rol = $_SESSION['rol'] ?? 'kullanici';
$firma_id = $_SESSION['firma_id'] ?? 0;
?>