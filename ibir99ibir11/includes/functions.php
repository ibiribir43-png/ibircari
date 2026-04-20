<?php
// 1. ADIM: PHPMailer Sınıflarını En Üstte Tanımlıyoruz
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Admin oturum kontrolü
function adminGirisKontrol(){
    if(!isset($_SESSION['admin_id'])){
        header("Location: login.php");
        exit;
    }
}

// Güvenli çıktı için htmlspecialchars kısayolu
function e($string){
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Flash mesaj gösterimi (Yönlendirmelerde mesaj taşımak için)
function setFlash($mesaj, $tip = 'success'){
    $_SESSION['flash'] = ['mesaj'=>$mesaj,'tip'=>$tip];
}

function getFlash(){
    if(isset($_SESSION['flash'])){
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Süre Ekleme Fonksiyonu
function sureEkle($mevcutTarih, $eklenecekAy, $eklenecekGun = 0) {
    $baslangic = ($mevcutTarih && $mevcutTarih > date('Y-m-d')) ? $mevcutTarih : date('Y-m-d');
    $date = new DateTime($baslangic);
    if($eklenecekAy > 0) $date->modify("+$eklenecekAy month");
    if($eklenecekGun > 0) $date->modify("+$eklenecekGun day");
    return $date->format('Y-m-d');
}

// YENİ: Merkezi Log Kaydetme Fonksiyonu
function sistem_log_kaydet($islem, $detay, $firma_id = null, $kullanici_id = null) {
    global $pdo, $db;
    $conn = $pdo ?? $db;
    if (!$conn) return false;

    // Eğer parametre olarak gelmemişse oturumdan al (Client tarafında)
    if ($firma_id === null && isset($_SESSION['firma_id'])) {
        $firma_id = $_SESSION['firma_id'];
    }
    if ($kullanici_id === null && isset($_SESSION['kullanici_id'])) {
        $kullanici_id = $_SESSION['kullanici_id'];
    }
    // Eğer Admin panelindeyse (Süper Admin)
    if ($kullanici_id === null && isset($_SESSION['admin_id'])) {
        $kullanici_id = $_SESSION['admin_id'];
        $firma_id = 'IBIR-4247-ADMIN';
    }

    $ip_adresi = $_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';

    try {
        $stmt = $conn->prepare("INSERT INTO sistem_loglari (firma_id, kullanici_id, islem, detay, ip_adresi) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$firma_id, $kullanici_id, $islem, $detay, $ip_adresi]);
        return true;
    } catch (Exception $e) {
        return false; // Log hatası ana akışı bozmasın
    }
}

// =================================================================================
// MERKEZİ GÜVENLİK KATMANI (TÜM SİSTEMDE GEÇERLİ)
// =================================================================================

/**
 * 1. XSS VE HTML INJECTION TEMİZLEYİCİ
 * Formlardan (POST/GET) gelen her türlü veriyi temizler.
 * Eğer Dizi (Array) gelirse, içindeki her elemanı tek tek temizler.
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
        return $data;
    }
    $data = trim($data);
    $data = strip_tags($data); // HTML etiketlerini yok eder
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); // Tırnakları zararsız hale getirir
    return $data;
}

/**
 * 2. YASAKLI KELİMELER (BANWORDS) LİSTESİ - DİNAMİK VERİTABANI BAĞLANTILI
 * Veritabanındaki sistem_ayarlari tablosundan 'sec_banwords' değerini çeker.
 * Eğer orada yoksa veya DB hatası varsa varsayılan listeyi kullanır.
 */
function getBanwordsList() {
    global $pdo, $db;
    $conn = $pdo ?? $db;
    
    // Çökmeye karşı varsayılan (Default) liste
    $default_words = [
        'amk', 'aq', 'sik', 'piç', 'yavşak', 'orospu', 'oç', 'göt', 
        'sürtük', 'kahpe', 'fuck', 'shit', 'bitch', 'asshole', 'şerefsiz', 'ibne'
    ];

    if ($conn) {
        try {
            $stmt = $conn->prepare("SELECT ayar_degeri FROM sistem_ayarlari WHERE ayar_adi = 'sec_banwords'");
            $stmt->execute();
            $result = $stmt->fetchColumn();
            
            if ($result) {
                // Virgülle ayrılmış metni diziye çevir, boşlukları temizle
                $words = array_map('trim', explode(',', $result));
                // Boş olan elemanları diziden çıkart
                return array_filter($words);
            }
        } catch (Exception $e) {
            // Veritabanı hatası olursa sessizce varsayılan listeye dön
        }
    }
    
    return $default_words;
}

/**
 * 3. KÜFÜR / YASAKLI KELİME KONTROLCÜSÜ
 * Bir metin içinde banwords listesindeki kelimelerden biri var mı diye bakar.
 * Varsa TRUE (Küfür var), yoksa FALSE (Temiz) döner.
 */
function checkBanwords($text) {
    $banwords = getBanwordsList();
    // Metni küçük harfe çevir ve aradaki boşluk/nokta gibi kaçış karakterlerini sil
    $lower_text = mb_strtolower(str_replace([' ', '.', ',', '-', '_', '@', '1', '0'], '', $text), 'UTF-8');
    
    foreach ($banwords as $word) {
        if (strpos($lower_text, $word) !== false) {
            return true; // Yasaklı kelime bulundu!
        }
    }
    return false; // Metin temiz
}

// =================================================================================

// NetGSM REST API (v2) SMS Gönderme Fonksiyonu (YENİ 4 KOVALI SAAS MANTIĞI)
function netgsm_sms_gonder($telefon, $mesaj, $firma_id = null) {
    global $pdo, $db; 
    
    $conn = $pdo ?? $db;
    if (!$conn) return ['status' => false, 'message' => 'Veritabanı bağlantısı bulunamadı!'];

    // Eğer firma ID verilmediyse session'dan al
    if ($firma_id === null && isset($_SESSION['firma_id'])) {
        $firma_id = $_SESSION['firma_id'];
    }
    
    $stmt = $conn->query("SELECT ayar_adi, ayar_degeri FROM sistem_ayarlari WHERE ayar_adi IN ('sms_api_key', 'sms_api_secret', 'sms_api_header')");
    $ayarlar = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $username = $ayarlar['sms_api_key'] ?? '';
    $password = $ayarlar['sms_api_secret'] ?? '';
    $header   = $ayarlar['sms_api_header'] ?? '';
    
    if(empty($username) || empty($password) || empty($header)) {
        return ['status' => false, 'message' => 'SMS API ayarları eksik.'];
    }

    $telefon = preg_replace('/[^0-9]/', '', $telefon);
    if (substr($telefon, 0, 1) == '0' && strlen($telefon) == 11) $telefon = substr($telefon, 1);
    else if (substr($telefon, 0, 2) == '90' && strlen($telefon) == 12) $telefon = substr($telefon, 2);
    
    $data = [
        "msgheader" => $header,
        "messages"  => [["msg" => $mesaj, "no"  => $telefon]],
        "encoding"  => "TR", "iysfilter" => "0", "partnercode" => ""
    ];

    $ch = curl_init("https://api.netgsm.com.tr/sms/rest/v2/send");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($username . ':' . $password)]);
    
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['status' => false, 'message' => 'Sunucuya bağlanılamadı.'];

    $response = json_decode($result, true);
    $responseCode = isset($response['code']) ? (string)$response['code'] : '';

    if ($responseCode === "00") {
        
        // --- BAŞARILI GÖNDERİM! ŞİMDİ KOTADAN DÜŞELİM ---
        if ($firma_id && $firma_id !== 'IBIR-4247-ADMIN') {
            $f_sorgu = $conn->prepare("SELECT aylik_sms_limiti, ek_sms_bakiyesi, kullanilan_sms_aylik FROM firmalar WHERE id = ?");
            $f_sorgu->execute([$firma_id]);
            $fData = $f_sorgu->fetch(PDO::FETCH_ASSOC);

            if ($fData) {
                $aylik_limit = (int)$fData['aylik_sms_limiti'];
                $ek_bakiye = (int)$fData['ek_sms_bakiyesi'];
                $kullanilan_aylik = (int)$fData['kullanilan_sms_aylik'];

                $yeni_kullanilan = $kullanilan_aylik + 1;
                $yeni_ek = $ek_bakiye;

                // Eğer paket limitini aştıysa, faturayı (Ek Bakiyeyi) 1 düşür!
                if ($yeni_kullanilan > $aylik_limit) {
                    $yeni_ek = max(0, $ek_bakiye - 1);
                }

                // Hem aylık kullanımı hem toplam kullanımı artır, gerekiyorsa ek bakiyeyi düş
                $conn->prepare("UPDATE firmalar SET kullanilan_sms_aylik = ?, kullanilan_sms_toplam = kullanilan_sms_toplam + 1, ek_sms_bakiyesi = ? WHERE id = ?")
                     ->execute([$yeni_kullanilan, $yeni_ek, $firma_id]);
            }
        }
        
        return ['status' => true, 'message' => 'Başarılı. JobID: ' . ($response['jobid'] ?? 'Bilinmiyor')];
    } else {
        return ['status' => false, 'message' => 'API Hatası: ' . $responseCode];
    }
}

// Sistem SMTP Mail Gönderme Fonksiyonu (PHPMailer)
function sistem_mail_gonder($kime, $konu, $mesaj) {
    global $pdo, $db; 
    
    $conn = null;
    if (isset($pdo) && $pdo !== null) {
        $conn = $pdo;
    } elseif (isset($db) && $db !== null) {
        $conn = $db;
    } else {
        return ['status' => false, 'message' => 'Veritabanı bağlantısı bulunamadı! (Mail Fonksiyonu)'];
    }

    $phpmailer_yolu = __DIR__ . '/../../PHPMailer/src/'; 
    
    if(!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        if(file_exists($phpmailer_yolu . 'Exception.php')) {
            require_once $phpmailer_yolu . 'Exception.php';
            require_once $phpmailer_yolu . 'PHPMailer.php';
            require_once $phpmailer_yolu . 'SMTP.php';
        } elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
        } else {
             return ['status' => false, 'message' => 'PHPMailer dosyaları belirtilen dizinde bulunamadı! (' . $phpmailer_yolu . ')'];
        }
    }

    $stmt = $conn->query("SELECT ayar_adi, ayar_degeri FROM sistem_ayarlari WHERE ayar_adi LIKE 'smtp_%'");
    $ayarlar = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if(empty($ayarlar['smtp_host'])) {
        return ['status' => false, 'message' => 'Sistem ayarlarında SMTP Host bilgisi eksik.'];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $ayarlar['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $ayarlar['smtp_email'] ?? '';
        $mail->Password   = $ayarlar['smtp_sifre'] ?? '';
        
        $port = (int)($ayarlar['smtp_port'] ?? 465);
        $mail->SMTPSecure = ($port === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        
        $mail->CharSet    = 'UTF-8';

        $gonderen_adi = $ayarlar['smtp_gonderen_adi'] ?? 'ibiR Sistem';
        $mail->setFrom($mail->Username, $gonderen_adi);
        $mail->addAddress($kime);

        $mail->isHTML(true);
        $mail->Subject = $konu;
        $mail->Body    = $mesaj;

        $mail->send();
        return ['status' => true, 'message' => 'Mail başarıyla gönderildi.'];
        
    } catch (Exception $e) {
        return ['status' => false, 'message' => 'SMTP Hatası: ' . $mail->ErrorInfo];
    }
}
?>