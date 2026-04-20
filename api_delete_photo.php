<?php
/**
 * api_delete_photo.php
 * Adminin yönetim panelinden fotoğrafları silmesini sağlar.
 * baglanti.php içindeki $db değişkenine tam uyumlu hale getirildi.
 */

// Hata ayıklama modunu açalım
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'baglanti.php';

// Değişken Eşleştirme (Senin baglanti.php'deki $db'yi kullanıyoruz)
if (isset($db)) {
    $db_cari = $db;
} else {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Bağlantı bulunamadı']);
    exit;
}

/**
 * SEÇİM VERİTABANI BAĞLANTISI
 * Silinen fotoğrafın Seçim DB'deki (user_selections) kayıtlarını da temizlemek için lazım.
 */
try {
    $db_secim = new PDO("mysql:host=localhost;dbname=ibi1e2ddingcom_secim;charset=utf8mb4", "ibi1e2ddingcom_ibiryenicaritakip011225", 'i1b2i3r443.');
    $db_secim->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Seçim DB'ye bağlanamazsa bile dosya silme devam edebilir, hata bastırmayalım
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $m_id = isset($_POST['m_id']) ? (int)$_POST['m_id'] : 0;
    $photo_name = isset($_POST['photo_name']) ? $_POST['photo_name'] : '';

    if ($m_id <= 0 || empty($photo_name)) {
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz parametreler']);
        exit;
    }

    // Müşterinin firma_id bilgisini alalım ki klasör yolunu bulalım
    $sorgu = $db_cari->prepare("SELECT firma_id FROM musteriler WHERE id = ?");
    $sorgu->execute([$m_id]);
    $m = $sorgu->fetch(PDO::FETCH_ASSOC);

    if ($m) {
        $file_path = "uploads/albumler/" . $m['firma_id'] . "/" . $m_id . "/" . $photo_name;
        
        // 1. Dosyayı sunucudan sil
        if (file_exists($file_path)) {
            unlink($file_path);
        }

        // 2. Seçim DB'den (user_selections) bu fotoğrafla ilgili tüm kayıtları temizle
        if (isset($db_secim)) {
            try {
                $del = $db_secim->prepare("DELETE FROM user_selections WHERE cari_musteri_id = ? AND image_path = ?");
                $del->execute([$m_id, $photo_name]);
            } catch (Exception $e) {
                // Sessizce devam et
            }
        }

        echo json_encode(['status' => 'success']);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Müşteri bulunamadı']);
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Geçersiz istek']);
?>