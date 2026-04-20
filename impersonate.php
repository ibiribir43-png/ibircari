<?php
require 'baglanti.php';

// GÜVENLİK: SADECE SÜPER ADMİN BU SAYFAYI ÇALIŞTIRABİLİR
if (!isset($_SESSION['kullanici_id']) || $_SESSION['rol'] != 'super_admin') {
    die("<h1>⛔ YETKİSİZ ERİŞİM</h1><p>Bu işlemi sadece Süper Admin yapabilir.</p>");
}

if (isset($_GET['fid'])) {
    $target_firma_id = $_GET['fid'];

    // 1. Hedef Firmanın "Admin" rolündeki yöneticisini bul
    $sorgu = $db->prepare("SELECT * FROM yoneticiler WHERE firma_id = ? AND rol = 'admin' LIMIT 1");
    $sorgu->execute([$target_firma_id]);
    $hedefUser = $sorgu->fetch(PDO::FETCH_ASSOC);

    if ($hedefUser) {
        // 2. Mevcut Süper Admin oturumunu temizle (ama tarayıcıyı kapatma)
        // (İstersen eski oturumu saklayıp 'Geri Dön' butonu yapabiliriz ama şimdilik basit tutalım)
        
        // 3. Yeni firmanın oturum bilgilerini yaz
        $_SESSION['kullanici_id'] = $hedefUser['id'];
        $_SESSION['ad_soyad'] = $hedefUser['ad_soyad'];
        $_SESSION['rol'] = $hedefUser['rol']; // 'admin' olacak
        $_SESSION['firma_id'] = $hedefUser['firma_id'];
        
        // Firma adını da alalım (görsellik için)
        $fSorgu = $db->prepare("SELECT firma_adi FROM firmalar WHERE id = ?");
        $fSorgu->execute([$target_firma_id]);
        $_SESSION['firma_adi'] = $fSorgu->fetchColumn();

        // 4. O firmanın ana sayfasına fırlat
        header("Location: anasayfa.php");
        exit;
    } else {
        die("HATA: Bu firmanın yönetici hesabı bulunamadı.");
    }
} else {
    header("Location: super_admin.php");
}
?>