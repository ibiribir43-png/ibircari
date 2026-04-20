<?php
// Doğru config dosyasını çağır (Oturumu başlatmak için gerekli)
require_once __DIR__ . '/../includes/config.php';

// Bütün oturum değişkenlerini temizle
$_SESSION = array();

// Oturumu tamamen yok et
session_destroy();

// Çıkış yapıldı mesajını gösterebilmek için yeni ve temiz bir oturum başlat
session_start();
$_SESSION['flash'] = [
    'tip' => 'info',
    'mesaj' => 'Sistemden başarıyla çıkış yaptınız. Güvenliğiniz için tarayıcınızı kapatabilirsiniz.'
];

// Giriş sayfasına yönlendir
header("Location: login.php");
exit;