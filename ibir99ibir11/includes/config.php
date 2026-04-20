<?php
session_start();

// Veritabanı Ayarları
$DB_HOST = 'localhost';
$DB_NAME = 'ibi1e2ddingcom_yenicaritakip011225';
$DB_USER = 'ibi1e2ddingcom_ibiryenicaritakip011225';
$DB_PASS = 'i1b2i3r443.';


try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e){
    die("Veritabanına bağlanılamadı: " . $e->getMessage());
}

// Fonksiyonları dahil et
require_once __DIR__ . '/functions.php';
?>