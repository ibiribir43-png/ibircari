<?php
session_start();
require 'baglanti.php';
require_once 'partials/security_check.php';

// Log ve Güvenlik fonksiyonlarını dahil et
$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

// ROL KONTROLÜ
if ($_SESSION['rol'] == 'ajanda') { 
    header("Location: yetkisiz.php");
    exit;
}

$firma_id = $_SESSION['firma_id'];
$firma_adi = $_SESSION['firma_adi'] ?? 'Firma Paneli'; 
$mesaj = ""; 
$mesajTuru = "";

// --- MÜŞTERİ LİMİTİ KONTROLÜ ---
$limitSorgu = $db->prepare("SELECT p.musteri_limiti FROM firmalar f JOIN paketler p ON f.paket_id = p.id WHERE f.id = ?");
$limitSorgu->execute([$firma_id]);
$musteri_limiti = (int)$limitSorgu->fetchColumn();

$aktifMusteriSorgu = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE firma_id = ? AND silindi = 0");
$aktifMusteriSorgu->execute([$firma_id]);
$aktif_musteri = (int)$aktifMusteriSorgu->fetchColumn();

$limit_doldu = ($musteri_limiti > 0 && $aktif_musteri >= $musteri_limiti);

// --- FİRMA ADINDAN KISALTMA OLUŞTURMA ---
$kelimeler = explode(" ", $firma_adi);
$firmaKodu = "";
foreach ($kelimeler as $k) {
    $firmaKodu .= mb_substr($k, 0, 1, "UTF-8");
}
$firmaKodu = mb_strtoupper($firmaKodu, "UTF-8");
$firmaKodu = str_replace(['Ç', 'Ğ', 'İ', 'Ö', 'Ş', 'Ü'], ['C', 'G', 'I', 'O', 'S', 'U'], $firmaKodu);
$firmaKodu = preg_replace('/[^A-Z0-9]/', '', $firmaKodu);
if (empty($firmaKodu)) { $firmaKodu = "MST"; }

// --- GÖRSEL OLARAK GÖSTERİLECEK OTOMATİK MÜŞTERİ NO (GÜVENLİK: Sadece ekranda gösterilir) ---
$sorguSonNo = $db->prepare("SELECT musteri_no FROM musteriler WHERE firma_id = ? ORDER BY id DESC LIMIT 1");
$sorguSonNo->execute([$firma_id]);
$sonMusteriNo = $sorguSonNo->fetchColumn();

if ($sonMusteriNo) {
    $parcalar = explode('-', $sonMusteriNo);
    $sonSira = intval(end($parcalar)); 
    $yeniSira = $sonSira + 1;
} else {
    $yeniSira = 1;
}

$otomatikNo = $firmaKodu . "-" . date("Ymd") . "-" . str_pad($yeniSira, 3, '0', STR_PAD_LEFT);

// === FORM GÖNDERİLDİ Mİ? ===
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Backend Limit Koruması
    if ($limit_doldu) {
        $mesaj = "Paket limitinize (Maksimum $musteri_limiti Müşteri) ulaştığınız için yeni kayıt oluşturamazsınız!";
        $mesajTuru = "danger";
    } else {
        
        // 1. GÜVENLİK: Tüm POST verisini zararlı kodlardan (XSS) temizle
        if (function_exists('sanitizeInput')) {
            $_POST = sanitizeInput($_POST);
        }

        $ad_soyad = trim($_POST['ad_soyad'] ?? '');
        $telefon = $_POST['telefon'] ?? '';
        $adres = trim($_POST['adres'] ?? '');
        $tc_vergi_no = $_POST['tc_vergi_no'] ?? '';
        $sozlesme_no = $_POST['sozlesme_no'] ?? '';
        $anlasma_tarihi = !empty($_POST['anlasma_tarihi']) ? $_POST['anlasma_tarihi'] : date('Y-m-d');
        $ozel_notlar = trim($_POST['ozel_notlar'] ?? '');

        // 2. GÜVENLİK: Banwords (Küfür/Argo) Kontrolü
        if (function_exists('checkBanwords') && checkBanwords($ad_soyad)) {
            $mesaj = "Girdiğiniz firma/müşteri isminde uygunsuz kelimeler tespit edildi. İşlem reddedildi!";
            $mesajTuru = "danger";
            if(function_exists('sistem_log_kaydet')) sistem_log_kaydet("Güvenlik: Uygunsuz İçerik", "Müşteri eklenirken yasaklı kelime kullanıldı. İsim: $ad_soyad", $firma_id, $_SESSION['kullanici_id']);
        }
        // İsim Boş mu?
        elseif (empty($ad_soyad)) {
            $mesaj = "Ad Soyad veya Firma Adı boş bırakılamaz!";
            $mesajTuru = "danger";
        }
        else {
            // 3. YENİ TELEFON MANTIĞI: (Başında 0 olmadan alınır, DB'ye 0 eklenerek kaydedilir)
            $telefon = preg_replace('/[^\d]/', '', $telefon); // Sadece rakamları bırak
            
            // Eğer kullanıcı yanlışlıkla başına 0 yazdıysa, onu da uçuralım
            if (substr($telefon, 0, 1) === '0') {
                $telefon = substr($telefon, 1);
            }
            
            if (strlen($telefon) > 0) {
                if (strlen($telefon) != 10 || substr($telefon, 0, 1) !== '5') {
                    $mesaj = "Lütfen telefon numarasını başında sıfır OLMADAN, 10 haneli olarak giriniz (Örn: 555 123 45 67).";
                    $mesajTuru = "danger";
                } else {
                    // Veritabanına kaydetmek üzere başına 0 ekliyoruz.
                    $telefon_db = '0' . $telefon; 
                }
            } else {
                $telefon_db = ''; // Telefon boş girildiyse boş bırak
            }

            // Hata Yoksa Kayıt İşlemine Geç
            if (empty($mesaj)) {
                
                // 4. GÜVENLİK (F12 AÇIĞI): Müşteri numarasını formdan almak yerine, SUNUCUDA tekrar üretiyoruz.
                // Böylece F12 ile form içindeki gizli input değiştirilse bile sahtekarlık yapılamaz.
                $sorguSonNo = $db->prepare("SELECT musteri_no FROM musteriler WHERE firma_id = ? ORDER BY id DESC LIMIT 1");
                $sorguSonNo->execute([$firma_id]);
                $sonMusteriNo = $sorguSonNo->fetchColumn();

                if ($sonMusteriNo) {
                    $parcalar = explode('-', $sonMusteriNo);
                    $sonSira = intval(end($parcalar)); 
                    $yeniSira = $sonSira + 1;
                } else {
                    $yeniSira = 1;
                }
                
                // Kesinlikle güvenli müşteri numarası
                $gercek_musteri_no = $firmaKodu . "-" . date("Ymd") . "-" . str_pad($yeniSira, 3, '0', STR_PAD_LEFT);

                // Token Üret
                $token = md5(uniqid(rand(), true) . $firma_id);

                try {
                    $sorgu = $db->prepare("INSERT INTO musteriler 
                        (firma_id, url_token, musteri_no, ad_soyad, telefon, adres, tc_vergi_no, sozlesme_no, anlasma_tarihi, ozel_notlar, durum, silindi) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)");

                    $sonuc = $sorgu->execute([
                        $firma_id, $token, $gercek_musteri_no, $ad_soyad, $telefon_db, $adres, 
                        $tc_vergi_no, $sozlesme_no, $anlasma_tarihi, $ozel_notlar
                    ]);

                    if ($sonuc) {
                        if(function_exists('sistem_log_kaydet')) {
                            sistem_log_kaydet("Yeni Müşteri Eklendi", "Müşteri No: $gercek_musteri_no | İsim: $ad_soyad sisteme kaydedildi.", $firma_id, $_SESSION['kullanici_id']);
                        }

                        $_SESSION['success_message'] = "Müşteri başarıyla eklendi!";
                        $_SESSION['musteri_token'] = $token;
                        
                        header("Location: musteri_detay.php?t=$token");
                        exit;
                    }
                } catch (PDOException $e) {
                    $mesaj = "Hata: Sistem veritabanına kaydedilemedi.";
                    $mesajTuru = "danger";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Yeni Müşteri Ekle | <?php echo htmlspecialchars($firma_adi); ?></title>
    
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .auto-number-badge { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 5px 10px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; }
        .form-section { background: #f8f9fa; border-radius: 10px; padding: 20px; margin-bottom: 25px; border-left: 4px solid var(--yonetim-primary); }
        .phone-input { font-family: 'Courier New', monospace; font-weight: 500; letter-spacing: 0.5px; }
    </style>
</head>

<body class="yonetim-body">

    <?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0 text-primary-yonetim"><i class="fas fa-user-plus me-2"></i>Yeni Müşteri</h1>
                        <p class="text-muted mb-0">Müşteri kartı oluştur ve yönetim paneline ekle</p>
                    </div>
                    <a href="musteriler.php" class="btn btn-yonetim-outline-primary d-none d-md-block">
                        <i class="fas fa-arrow-left me-2"></i>Müşteri Listesi
                    </a>
                </div>
                
                <?php if($limit_doldu && empty($mesaj)): ?>
                    <div class="alert alert-danger fade-in mb-4 border-0 shadow-sm" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-lock me-3 fa-2x"></i>
                            <div><strong>Müşteri Limiti Doldu!</strong> Paketinizdeki limit aşıldığı için yeni müşteri ekleyemezsiniz. İşleme devam etmek için paketinizi yükseltin.</div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if($mesaj): ?>
                    <div class="alert alert-yonetim alert-<?php echo $mesajTuru; ?> fade-in mb-4" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas <?php echo $mesajTuru == 'danger' ? 'fa-exclamation-triangle' : 'fa-info-circle'; ?> me-3 fa-lg"></i>
                            <div><?php echo $mesaj; ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card-yonetim fade-in" style="<?= $limit_doldu ? 'opacity: 0.6; pointer-events: none;' : '' ?>">
                    <div class="card-header">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-id-card me-2"></i>
                            <span>Müşteri Bilgileri</span>
                            <span class="auto-number-badge ms-3" title="Müşteri kaydedildiğinde sistem tarafından güvenle üretilecektir."><i class="fas fa-shield-alt me-1"></i>Güvenli Üretim: <?php echo $otomatikNo; ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="form-yonetim" id="musteriForm">
                            <!-- GİZLİ MÜŞTERİ NO İNPUTU GÜVENLİK GEREĞİ TAMAMEN SİLİNMİŞTİR. (F12 İLE DEĞİŞTİRİLEMEZ) -->
                            
                            <!-- KİMLİK BİLGİLERİ -->
                            <div class="form-section">
                                <h5 class="section-title mb-4"><i class="fas fa-user-circle me-2 text-primary-yonetim"></i>Kimlik Bilgileri</h5>
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <label class="form-label fw-bold form-group-required">Ad Soyad / Firma Adı</label>
                                        <input type="text" name="ad_soyad" class="form-control" required placeholder="Örn: Ahmet Yılmaz veya ABC Ltd. Şti." value="<?= htmlspecialchars($_POST['ad_soyad'] ?? '') ?>" autofocus>
                                        <div class="form-text">Müşterinin tam adı veya firma ünvanı</div>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Telefon <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text fw-bold text-dark">+90</span>
                                            <!-- Yeni Formatta Placeholder: 555 123 44 55 -->
                                            <input type="text" name="telefon" id="telefonInput" class="form-control phone-input" placeholder="5xx xxx xx xx" maxlength="13" value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>">
                                        </div>
                                        <div class="form-text small"><i class="fas fa-info-circle me-1"></i>Lütfen başında <b>Sıfır (0) olmadan</b> 10 haneli olarak giriniz.</div>
                                        <div id="telefonUyari" class="text-danger small mt-1" style="display: none;"><i class="fas fa-exclamation-triangle me-1"></i>Başında 0 olmadan tam 10 hane girmelisiniz!</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">TC / Vergi No</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                            <input type="text" name="tc_vergi_no" class="form-control" placeholder="11 veya 10 haneli numara" value="<?= htmlspecialchars($_POST['tc_vergi_no'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Adres</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                        <textarea name="adres" class="form-control" rows="2" placeholder="Açık adres bilgisi..."><?= htmlspecialchars($_POST['adres'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- SÖZLEŞME DETAYLARI -->
                            <div class="form-section">
                                <h5 class="section-title mb-4"><i class="fas fa-file-contract me-2 text-primary-yonetim"></i>Sözleşme Detayları</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Sözleşme No</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-file-signature"></i></span>
                                            <input type="text" name="sozlesme_no" class="form-control" placeholder="Varsa sözleşme numarası" value="<?= htmlspecialchars($_POST['sozlesme_no'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Anlaşma Tarihi</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-calendar-check"></i></span>
                                            <input type="date" name="anlasma_tarihi" class="form-control" value="<?= htmlspecialchars($_POST['anlasma_tarihi'] ?? date('Y-m-d')) ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Özel Notlar</label>
                                    <div class="input-group">
                                        <span class="input-group-text align-items-start"><i class="fas fa-sticky-note"></i></span>
                                        <textarea name="ozel_notlar" class="form-control" rows="4" placeholder="Müşteriyle ilgili önemli notlar, tercihler, özel durumlar..."><?= htmlspecialchars($_POST['ozel_notlar'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- FORM BUTONLARI -->
                            <div class="d-flex justify-content-between mt-5 pt-4 border-top d-grid-mobile">
                                <div>
                                    <a href="anasayfa.php" class="btn btn-yonetim-secondary btn-mobile"><i class="fas fa-times me-2"></i>İptal</a>
                                    <a href="musteriler.php" class="btn btn-yonetim-outline-primary btn-mobile ms-md-2 d-inline-block mt-2 mt-md-0"><i class="fas fa-list me-2"></i>Listeye Dön</a>
                                </div>
                                <button type="submit" class="btn btn-yonetim-primary px-5 btn-mobile mt-2 mt-md-0" <?= $limit_doldu ? 'disabled' : '' ?>>
                                    <i class="fas fa-shield-alt me-2"></i>Güvenli Kaydet
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="toast-container-yonetim"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/yonetim.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const telefonInput = document.getElementById('telefonInput');
        const telefonUyari = document.getElementById('telefonUyari');
        
        function formatPhoneNumber(value) {
            let numbers = value.replace(/\D/g, '');
            
            // Eğer kullanıcı başına 0 yazarsa hemen siliyoruz
            if (numbers.length > 0 && numbers.charAt(0) === '0') {
                numbers = numbers.substring(1);
            }
            
            // Maksimum 10 hane kalacak (0 hariç)
            if (numbers.length > 10) numbers = numbers.substring(0, 10);
            if (numbers.length === 0) return '';
            
            // Formatlama: 555 123 44 55
            let formatted = numbers.substring(0, 3);
            if (numbers.length > 3) {
                formatted += ' ' + numbers.substring(3, 6);
                if (numbers.length > 6) {
                    formatted += ' ' + numbers.substring(6, 8);
                    if (numbers.length > 8) {
                        formatted += ' ' + numbers.substring(8, 10);
                    }
                }
            }
            return formatted;
        }
        
        telefonInput.addEventListener('input', function(e) {
            const cursorPos = e.target.selectionStart;
            const oldValue = e.target.value;
            
            const formatted = formatPhoneNumber(oldValue);
            e.target.value = formatted;
            
            // Uyarı gösterimi (Eksikse veya 5 ile başlamıyorsa)
            const numbers = formatted.replace(/\D/g, '');
            if (numbers.length > 0 && (numbers.length < 10 || numbers.charAt(0) !== '5')) {
                telefonUyari.style.display = 'block';
            } else {
                telefonUyari.style.display = 'none';
            }
        });
        
        telefonInput.addEventListener('blur', function() {
            const numbers = this.value.replace(/\D/g, '');
            if (numbers.length === 0) return;
            
            if (numbers.length < 10 || numbers.charAt(0) !== '5') {
                telefonUyari.style.display = 'block';
                if(typeof showYonetimToast === 'function') showYonetimToast('Telefon numarası 5 ile başlamalı ve 10 haneli olmalıdır!', 'warning');
            } else {
                telefonUyari.style.display = 'none';
            }
            this.value = formatPhoneNumber(numbers);
        });
        
        document.getElementById('musteriForm').addEventListener('submit', function(e) {
            const numbers = telefonInput.value.replace(/\D/g, '');
            
            if (numbers.length > 0 && (numbers.length < 10 || numbers.charAt(0) !== '5')) {
                e.preventDefault();
                if(typeof showYonetimToast === 'function') showYonetimToast('Lütfen geçerli bir telefon numarası giriniz (0 olmadan)!', 'danger');
                telefonInput.focus();
                telefonUyari.style.display = 'block';
                return;
            }
            
            // Post edilirken sadece rakamlar gitsin
            telefonInput.value = numbers;
        });
        
        const adSoyadInput = document.querySelector('input[name="ad_soyad"]');
        if (adSoyadInput && !adSoyadInput.value) adSoyadInput.focus();
    });
    </script>
    <?php require_once 'partials/footer_yonetim.php'; ?>
</body>
</html>