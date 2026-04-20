<?php
/**
 * api_portal.php - MERKEZİ OPERASYON SİSTEMİ
 * Konum: musteri.ibircari.xyz klasörü içinde.
 * Hedef: ibircari.xyz/uploads klasörü (Kardeş dizin).
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require_once 'baglanti.php';

if (!isset($_SESSION['musteri_auth'])) {
    echo json_encode(['status' => 'error', 'message' => 'Oturum kapalı.']);
    exit;
}

$m_id = $_SESSION['musteri_id'];
$f_id = $_SESSION['firma_id'];
$action = $_GET['action'] ?? '';

// baglanti.php içindeki $db'yi kullanıyoruz
$db_cari = $db; 

try {
    $db_secim = new PDO("mysql:host=localhost;dbname=ibi1e2ddingcom_secim;charset=utf8mb4", "ibi1e2ddingcom_ibiryenicaritakip011225", 'i1b2i3r443.');
    $db_secim->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Seçim veritabanı hatası!']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// ==========================================
// DİZİN YAPILANDIRMASI (KARDEŞ DİZİN AYARI)
// ==========================================
// 1. Fiziksel Yol: PHP'nin dosyaları taraması için.
// musteri.ibircari.xyz'den çıkıp ibircari.xyz klasörüne giriyoruz.
$physical_base = "../ibircari.xyz/uploads/albumler/" . $f_id . "/" . $m_id . "/";

// 2. URL Yolu: Tarayıcının resimleri çekmesi için (Public URL).
$url_base = "https://ibircari.xyz/uploads/albumler/" . $f_id . "/" . $m_id . "/";

if ($action === 'get_images') {
    $masterImageList = [];

    try {
        // Mevcut seçimleri Seçim DB'den al
        $s_sorgu = $db_secim->prepare("SELECT image_path, selection_type, note FROM user_selections WHERE cari_musteri_id = ?");
        $s_sorgu->execute([$m_id]);
        $selections = [];
        while($row = $s_sorgu->fetch(PDO::FETCH_ASSOC)) {
            $selections[$row['image_path']] = $row;
        }

        // Klasörü fiziksel yoldan tara
        if (is_dir($physical_base)) {
            $files = scandir($physical_base);
            foreach ($files as $file) {
                // Gizli dosyaları ve üst dizinleri atla
                if ($file == '.' || $file == '..') continue;

                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $sel_type = 0;
                    $note = "";
                    
                    if(isset($selections[$file])) {
                        $sel_type = (int)$selections[$file]['selection_type'];
                        $note = $selections[$file]['note'] ?? "";
                    }

                    $masterImageList[] = [
                        'path' => $url_base . $file, // gallery.js için tam URL
                        'filename' => $file,         // İşlemler için dosya adı
                        'selection_type' => $sel_type,
                        'note' => $note
                    ];
                }
            }
        } else {
            // Eğer klasör bulunamazsa hata değil boş liste döner, 
            // ama konsola klasör yolunu basarız (Debug için)
            error_log("Klasör bulunamadı: " . $physical_base);
        }

        echo json_encode([
            'status' => 'success', 
            'masterImageList' => $masterImageList
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// TOGGLE (Seçim/Favori) İŞLEMİ
if ($action === 'toggle') {
    // gallery.js hem image_path hem filename gönderir, biz filename'i (dosya adı) baz alıyoruz.
    $filename = $input['filename'] ?? basename($input['image_path']);
    $type_req = (int)($input['type'] ?? 1); // 1: Seçim, 2: Favori

    try {
        $check = $db_secim->prepare("SELECT id, selection_type FROM user_selections WHERE cari_musteri_id = ? AND image_path = ?");
        $check->execute([$m_id, $filename]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $current = (int)$row['selection_type'];
            // Toggle mantığı: 1: Seçili, 2: Favori, 3: Her ikisi
            if($type_req == 1) { // Seçim butonu
                $new = ($current == 1 || $current == 3) ? ($current == 3 ? 2 : 0) : ($current == 2 ? 3 : 1);
            } else { // Favori butonu
                $new = ($current == 2 || $current == 3) ? ($current == 3 ? 1 : 0) : ($current == 1 ? 3 : 2);
            }

            if($new == 0) {
                // Eğer not yoksa sil, varsa tipini 0 yap (Not kaybolmasın)
                $db_secim->prepare("DELETE FROM user_selections WHERE id = ? AND (note IS NULL OR note = '')")->execute([$row['id']]);
                $db_secim->prepare("UPDATE user_selections SET selection_type = 0 WHERE id = ? AND note > ''")->execute([$row['id']]);
            } else {
                $db_secim->prepare("UPDATE user_selections SET selection_type = ? WHERE id = ?")->execute([$new, $row['id']]);
            }
        } else {
            // Kayıt yoksa yeni ekle
            $db_secim->prepare("INSERT INTO user_selections (firma_id, cari_musteri_id, image_path, selection_type) VALUES (?, ?, ?, ?)")
                     ->execute([$f_id, $m_id, $filename, $type_req]);
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) { 
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
    }
    exit;
}

// NOT KAYDETME İŞLEMİ
if ($action === 'save_note') {
    $filename = $input['filename'] ?? basename($input['image_path']);
    $note = $input['note'] ?? '';

    try {
        $check = $db_secim->prepare("SELECT id FROM user_selections WHERE cari_musteri_id = ? AND image_path = ?");
        $check->execute([$m_id, $filename]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $db_secim->prepare("UPDATE user_selections SET note = ? WHERE id = ?")->execute([$note, $exists['id']]);
        } else {
            $db_secim->prepare("INSERT INTO user_selections (firma_id, cari_musteri_id, image_path, selection_type, note) VALUES (?, ?, ?, 0, ?)")
                     ->execute([$f_id, $m_id, $filename, $note]);
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// GENEL NOT KAYDETME
if ($action === 'save_general_note') {
    $note = $input['note'] ?? '';
    try {
        $check = $db_secim->prepare("SELECT id FROM user_general_notes WHERE cari_musteri_id = ?");
        $check->execute([$m_id]);
        $exists = $check->fetch();

        if ($exists) {
            $db_secim->prepare("UPDATE user_general_notes SET note = ?, updated_at = NOW() WHERE id = ?")->execute([$note, $exists['id']]);
        } else {
            $db_secim->prepare("INSERT INTO user_general_notes (firma_id, cari_musteri_id, note) VALUES (?, ?, ?)")
                     ->execute([$f_id, $m_id, $note]);
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}