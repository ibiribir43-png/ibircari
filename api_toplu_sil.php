<?php
session_start();
require_once 'baglanti.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * api_toplu_sil.php
 * Dosyaları toplu veya tekil olarak siler. 
 * Güvenlik için oturum (session) kontrolü yapılır, şifre sorulmaz.
 */

// 1. Oturum Kontrolü (Güvenlik)
if (!isset($_SESSION['kullanici_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Oturum süreniz dolmuş. Lütfen tekrar giriş yapın.']);
    exit;
}

// 2. Gelen Verileri Al ve Kontrol Et
$type = $_POST['type'] ?? '';
$musteri_id = isset($_POST['musteri_id']) ? (int)$_POST['musteri_id'] : 0;
$files = isset($_POST['files']) ? $_POST['files'] : [];

// Hata ayıklama için eksik olanı yakalayalım
if (empty($type) || empty($files) || $musteri_id === 0) {
    echo json_encode([
        'success' => false, 
        'message' => 'Eksik veri gönderildi!', 
        'debug' => ['type' => $type, 'musteri_id' => $musteri_id, 'file_count' => count($files)]
    ]);
    exit;
}

// 3. FİRMA ID BULMA
try {
    $m_sorgu = $db->prepare("SELECT firma_id FROM musteriler WHERE id = ?");
    $m_sorgu->execute([$musteri_id]);
    $firma_id = $m_sorgu->fetchColumn();
    
    if (!$firma_id) {
        echo json_encode(['success' => false, 'message' => 'Sistemde böyle bir müşteri kaydı bulunamadı.']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: Firma bilgisi alınamadı.']);
    exit;
}

// 4. DOSYALARI SİLME İŞLEMİ
$deletedCount = 0;
$failedCount = 0;

// Ana dizindeki uploads klasörüne göre yol (Bu dosya nerede duruyorsa ona göre ayarlanmalı)
// Eğer bu dosya ana dizindeyse __DIR__ . '/uploads/' mantıklıdır.
$base_dir = __DIR__ . "/uploads/";

foreach ($files as $file) {
    // Güvenlik: Dizin atlama (directory traversal) engellemek için sadece dosya adını al
    $clean_file = basename($file);
    $target_path = "";

    if ($type === 'foto') {
        $target_path = $base_dir . "albumler/" . $firma_id . "/" . $musteri_id . "/" . $clean_file;
    } elseif ($type === 'album') {
        $target_path = $base_dir . "haziralbumler/" . $firma_id . "/" . $musteri_id . "/" . $clean_file;
    } elseif ($type === 'video') {
        $target_path = $base_dir . "videolar/" . $firma_id . "/" . $musteri_id . "/" . $clean_file;
    }

    // Dosya varsa fiziksel olarak sil
    if (!empty($target_path) && file_exists($target_path)) {
        if (unlink($target_path)) {
            $deletedCount++;
        } else {
            $failedCount++;
        }
    }
}

// 5. Sonuç Döndür
if ($deletedCount > 0) {
    echo json_encode([
        'success' => true, 
        'message' => "Başarılı! {$deletedCount} dosya sunucudan kalıcı olarak temizlendi." . ($failedCount > 0 ? " ($failedCount dosya silinemedi)" : "")
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Hiçbir dosya silinemedi. Dosyalar bulunamadı veya sunucu izinleri yetersiz.'
    ]);
}
?>