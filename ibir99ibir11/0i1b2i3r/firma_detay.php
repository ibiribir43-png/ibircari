<?php
require_once '../templates/header.php';
global $pdo;

// Merkezi log fonksiyonumuzu dahil ediyoruz (Eğer config'den gelmiyorsa)
$functions_path = __DIR__ . '/../includes/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

// GET ile Firma ID'sini al
$firma_id = isset($_GET['id']) ? trim($_GET['id']) : '';

if(empty($firma_id)) {
    setFlash("Geçersiz firma ID'si.", "danger");
    header("Location: firmalar.php");
    exit;
}

// --- VERİ ÇEKME (İşlemlerden önce firmanın mevcut halini bilelim) ---
// YENİ EKLENEN SÜTUNLAR DA DAHİL EDİLDİ (bilgi_onay_durumu, ilk_abonelik_tarihi, son_abonelik_baslangic, aylik_trafik_kullanimi vb.)
$stmt = $pdo->prepare("
    SELECT f.*, p.paket_adi, p.sms_limiti as paket_sms_limiti, p.depolama_limiti as paket_depolama_limiti 
    FROM firmalar f 
    LEFT JOIN paketler p ON f.paket_id = p.id 
    WHERE f.id = ?
");
$stmt->execute([$firma_id]);
$firma = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$firma) {
    setFlash("Firma sistemde bulunamadı.", "danger");
    header("Location: firmalar.php");
    exit;
}

// --- İŞLEMLER (POST) ---

// 1. KOTA VE LİMİT YÖNETİMİ (YENİ EFSANE MODÜL)
if (isset($_POST['manuel_kota_guncelle'])) {
    $ek_depolama = (int)$_POST['ek_depolama_alani'];
    $ek_trafik = (int)$_POST['ek_trafik_limiti'];
    $ek_sure = (int)$_POST['ek_sure_gun'];
    $anlik_kesinti = isset($_POST['anlik_kesinti']) ? 1 : 0;
    
    try {
        $pdo->prepare("UPDATE firmalar SET ek_depolama_alani = ?, ek_trafik_limiti = ?, ek_sure_gun = ?, anlik_kesinti = ? WHERE id = ?")
            ->execute([$ek_depolama, $ek_trafik, $ek_sure, $anlik_kesinti, $firma_id]);
            
        if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Kota ve Limit Güncellemesi", "Süper Admin tarafından firmanın depolama (+$ek_depolama MB), trafik (+$ek_trafik MB) ve tolerans ($ek_sure Gün) ayarları güncellendi. Anlık Kesinti: $anlik_kesinti", $firma_id, $_SESSION['admin_id']);
        
        setFlash("Firmanın kota ve limit ayarları başarıyla güncellendi.", "success");
    } catch(Exception $e) {
        setFlash("Kota güncellenemedi. Hata: " . $e->getMessage(), "danger");
    }
    header("Location: firma_detay.php?id=" . $firma_id . "&tab=ozet");
    exit;
}

// 2. Firma Bilgileri, Paket ve Abonelik Tarihi Güncelleme
if (isset($_POST['firma_guncelle'])) {
    $paket_id = empty($_POST['paket_id']) ? null : (int)$_POST['paket_id'];
    $abonelik_bitis = empty($_POST['abonelik_bitis']) ? null : $_POST['abonelik_bitis'];
    $sms_yenile = isset($_POST['sms_yenile']) ? 1 : 0; 

    $sql = "UPDATE firmalar SET firma_adi=?, yetkili_ad_soyad=?, telefon=?, adres=?, vergi_no=?, admin_ozel_notu=?, paket_id=?, abonelik_bitis=? WHERE id=?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $_POST['firma_adi'], $_POST['yetkili'], $_POST['telefon'], 
        $_POST['adres'], $_POST['vergi_no'], $_POST['admin_not'], 
        $paket_id, $abonelik_bitis, $firma_id
    ]);

    // Eğer "Paket SMS limitini firmaya aktar" işaretlendiyse
    if ($sms_yenile && $paket_id) {
        $yeniPaket = $pdo->prepare("SELECT sms_limiti FROM paketler WHERE id = ?");
        $yeniPaket->execute([$paket_id]);
        $yeniSms = $yeniPaket->fetchColumn();
        
        $pdo->prepare("UPDATE firmalar SET aylik_sms_limiti = ? WHERE id = ?")->execute([$yeniSms, $firma_id]);
    }

    if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Admin İşlemi", "Süper Admin firma bilgilerini ve paket durumunu güncelledi.", $firma_id, $_SESSION['admin_id']);
    
    setFlash("Firma bilgileri başarıyla güncellendi.", "success");
    header("Location: firma_detay.php?id=" . $firma_id . "&tab=duzenle");
    exit;
}

// 3. Firma Durum Değiştir (Aktif/Pasif)
if(isset($_POST['durum_degistir'])) {
    $yeniDurum = (int)$_POST['yeni_durum'];
    $pdo->prepare("UPDATE firmalar SET durum = ? WHERE id = ?")->execute([$yeniDurum, $firma_id]);
    
    $durumMetni = $yeniDurum == 1 ? "Aktif" : "Pasif/Dondurulmuş";
    if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Admin İşlemi", "Süper Admin firma durumunu değiştirdi: $durumMetni", $firma_id, $_SESSION['admin_id']);

    setFlash("Firma durumu başarıyla güncellendi.", "success");
    header("Location: firma_detay.php?id=" . $firma_id);
    exit;
}

// 4. Yeni Kullanıcı / Personel Ekleme (ARGON2ID UYUMLU)
if (isset($_POST['personel_ekle'])) {
    $email = trim($_POST['email']);
    $chk = $pdo->prepare("SELECT id FROM yoneticiler WHERE email = ?");
    $chk->execute([$email]);
    
    if($chk->rowCount() > 0) {
        setFlash("Bu e-posta adresi zaten kullanımda!", "danger");
    } else {
        $sifre_ham = trim($_POST['sifre']);
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $sifre_hash = password_hash($sifre_ham, $algo);
        
        $pdo->prepare("INSERT INTO yoneticiler (firma_id, kullanici_adi, email, sifre, rol, email_onayli) VALUES (?, ?, ?, ?, ?, 1)")
            ->execute([$firma_id, $_POST['kadi'], $email, $sifre_hash, $_POST['rol']]);
            
        if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Admin İşlemi", "Süper Admin firmaya yeni bir kullanıcı/personel ekledi: $email", $firma_id, $_SESSION['admin_id']);
        
        setFlash("Yeni kullanıcı eklendi.", "success");
    }
    header("Location: firma_detay.php?id=" . $firma_id . "&tab=kullanicilar");
    exit;
}

// 5. Kullanıcıya Şifre Sıfırlama MAİLİ Gönderme
if (isset($_POST['sifre_sifirla_mail'])) {
    $uid = $_POST['user_id'];
    $userMail = $_POST['user_email'];
    
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE yoneticiler SET sifre_sifirlama_kodu = ?, sifre_sifirlama_tarihi = NOW() WHERE id = ?")->execute([$token, $uid]);
    
    $reset_link = "https://" . $_SERVER['HTTP_HOST'] . "/sifre_sifirla.php?kod=" . $token . "&email=" . urlencode($userMail);
    $mesaj = "Hesabınız için şifre sıfırlama talebinde bulunulmuştur.<br><br>Aşağıdaki bağlantıya tıklayarak yeni şifrenizi belirleyebilirsiniz:<br><a href='{$reset_link}'>{$reset_link}</a>";
    
    if(function_exists('sistem_mail_gonder')) {
        $mail_sonuc = sistem_mail_gonder($userMail, "Şifre Sıfırlama Talebi", $mesaj);
        if($mail_sonuc['status']) {
            setFlash("Şifre sıfırlama bağlantısı <b>$userMail</b> adresine e-posta olarak gönderildi.", "success");
            if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Admin İşlemi", "Süper Admin firmadaki bir kullanıcıya şifre sıfırlama bağlantısı gönderdi.", $firma_id, $_SESSION['admin_id']);
        } else {
            setFlash("Mail gönderilemedi! Hata: " . $mail_sonuc['message'], "danger");
        }
    } else {
        @mail($userMail, "Şifre Sıfırlama Talebi", $mesaj, "Content-type: text/html; charset=utf-8\r\n");
        setFlash("Şifre sıfırlama bağlantısı gönderildi (Varsayılan fonksiyon).", "info");
    }
    
    header("Location: firma_detay.php?id=" . $firma_id . "&tab=kullanicilar");
    exit;
}

// 6. Ödeme Ekleme & Süre Uzatma
if (isset($_POST['odeme_ekle'])) {
    $tutar = str_replace(',', '.', $_POST['tutar']);
    
    $pdo->prepare("INSERT INTO firma_odemeler (firma_id, tutar, odeme_turu, aciklama) VALUES (?, ?, ?, ?)")
        ->execute([$firma_id, $tutar, $_POST['odeme_turu'], $_POST['aciklama']]);
    
    if(isset($_POST['sure_uzat_opsiyon'])) {
        $mevcutSorgu = $pdo->prepare("SELECT abonelik_bitis, ilk_abonelik_tarihi FROM firmalar WHERE id = ?");
        $mevcutSorgu->execute([$firma_id]);
        $mevcutFirmaVeri = $mevcutSorgu->fetch(PDO::FETCH_ASSOC);
        
        $mevcut = $mevcutFirmaVeri['abonelik_bitis'];
        $yeni = sureEkle($mevcut, (int)$_POST['ek_ay']);
        
        $ilk_abonelik = empty($mevcutFirmaVeri['ilk_abonelik_tarihi']) ? date('Y-m-d') : $mevcutFirmaVeri['ilk_abonelik_tarihi'];
        
        $pdo->prepare("UPDATE firmalar SET abonelik_bitis=?, durum=1, son_abonelik_baslangic=CURDATE(), ilk_abonelik_tarihi=? WHERE id=?")
            ->execute([$yeni, $ilk_abonelik, $firma_id]);
            
        $detayLog = "Ödeme eklendi, abonelik süresi uzatıldı ve abonelik tarihçesi güncellendi.";
    } else {
        $detayLog = "Sadece ödeme kaydı girildi.";
    }
    
    if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Finans İşlemi", "Süper Admin $tutar ₺ tutarında ödeme kaydı oluşturdu. $detayLog", $firma_id, $_SESSION['admin_id']);
    
    setFlash("Ödeme kaydı eklendi.", "success");
    header("Location: firma_detay.php?id=" . $firma_id . "&tab=finans");
    exit;
}

// 7. Teklif / Şablon Ayarları Güncelleme
if (isset($_POST['teklif_ayar_guncelle'])) {
    $pdo->prepare("UPDATE firmalar SET teklif_sartlari=?, teklif_alt_bilgi=? WHERE id=?")
        ->execute([$_POST['teklif_sartlari'], $_POST['teklif_alt_bilgi'], $firma_id]);
    setFlash("Teklif ayarları güncellendi.", "success");
    header("Location: firma_detay.php?id=" . $firma_id . "&tab=ayarlar");
    exit;
}

// 8. Manuel Ek SMS Bakiyesi Düzenleme
if (isset($_POST['manuel_sms_ekle'])) {
    $yeni_bakiye = (int)$_POST['ek_sms_bakiyesi'];
    $pdo->prepare("UPDATE firmalar SET ek_sms_bakiyesi = ? WHERE id = ?")->execute([$yeni_bakiye, $firma_id]);
    
    if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("SMS Bakiyesi", "Süper Admin tarafından firmanın ek SMS bakiyesi manuel olarak $yeni_bakiye adet olarak güncellendi.", $firma_id, $_SESSION['admin_id']);
    
    setFlash("Firmanın ek SMS bakiyesi başarıyla güncellendi.", "success");
    header("Location: firma_detay.php?id=" . $firma_id . "&tab=ozet");
    exit;
}

// 9. BİLGİ ONAY DURUMU GÜNCELLEME
if (isset($_POST['onay_durumu_guncelle'])) {
    $yeni_durum = (int)$_POST['yeni_onay_durumu'];
    $pdo->prepare("UPDATE firmalar SET bilgi_onay_durumu = ? WHERE id = ?")->execute([$yeni_durum, $firma_id]);
    
    $durumMetni = $yeni_durum == 2 ? "Onaylandı" : ($yeni_durum == 1 ? "Onay Bekliyor" : "Eksik Bilgi (Kısıtlı)");
    if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Admin İşlemi", "Süper Admin firma profil onay durumunu değiştirdi: $durumMetni", $firma_id, $_SESSION['admin_id']);
    
    setFlash("Firmanın profil onay durumu başarıyla güncellendi.", "success");
    header("Location: firma_detay.php?id=" . $firma_id . "&tab=ozet");
    exit;
}

// 10. YENİ EKLENDİ: DESTEK MESAJI GÖNDERME (ADMİN)
if (isset($_POST['admin_mesaj_gonder'])) {
    $aktif_talep_id = (int)$_POST['talep_id'];
    $mesaj = trim($_POST['mesaj']);
    if($mesaj && $aktif_talep_id > 0) {
        $stmt = $pdo->prepare("INSERT INTO destek_mesajlari (talep_id, gonderen_id, gonderen_tipi, mesaj, tarih, okundu) VALUES (?, ?, 'admin', ?, NOW(), 0)");
        $stmt->execute([$aktif_talep_id, $_SESSION['admin_id'], $mesaj]);
        
        $pdo->prepare("UPDATE destek_talepleri SET durum = 'yanitlandi', son_islem_tarihi = NOW() WHERE id = ?")->execute([$aktif_talep_id]);
        
        if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Destek Yanıtı", "Süper Admin bir destek talebine yanıt verdi (Talep ID: $aktif_talep_id).", $firma_id, $_SESSION['admin_id']);
        
        header("Location: firma_detay.php?id=$firma_id&tab=destek&tid=$aktif_talep_id");
        exit;
    }
}

// 11. YENİ EKLENDİ: TALEP KAPATMA
if (isset($_POST['talep_kapat'])) {
    $aktif_talep_id = (int)$_POST['talep_id'];
    if($aktif_talep_id > 0) {
        $pdo->prepare("UPDATE destek_talepleri SET durum = 'kapali', son_islem_tarihi = NOW() WHERE id = ?")->execute([$aktif_talep_id]);
        setFlash("Destek talebi başarıyla kapatıldı.", "success");
        header("Location: firma_detay.php?id=$firma_id&tab=destek&tid=$aktif_talep_id");
        exit;
    }
}

// --- VERİ ÇEKME (Alt tablolar) ---
$paketler = [];
try { $paketler = $pdo->query("SELECT id, paket_adi FROM paketler WHERE durum = 1")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

$kullanicilar = $pdo->prepare("SELECT * FROM yoneticiler WHERE firma_id = ?");
$kullanicilar->execute([$firma_id]);
$kullanicilar = $kullanicilar->fetchAll(PDO::FETCH_ASSOC);

$odemeler = $pdo->prepare("SELECT * FROM firma_odemeler WHERE firma_id = ? ORDER BY tarih DESC");
$odemeler->execute([$firma_id]);
$odemeler = $odemeler->fetchAll(PDO::FETCH_ASSOC);

$musteri_sayisi = $pdo->prepare("SELECT COUNT(*) FROM musteriler WHERE firma_id = ? AND silindi = 0");
$musteri_sayisi->execute([$firma_id]);
$musteri_sayisi = $musteri_sayisi->fetchColumn() ?: 0;

$islem_sayisi = $pdo->prepare("SELECT COUNT(*) FROM hareketler WHERE firma_id = ?");
$islem_sayisi->execute([$firma_id]);
$islem_sayisi = $islem_sayisi->fetchColumn() ?: 0;

$firma_loglari = $pdo->prepare("
    SELECT l.*, y.kullanici_adi 
    FROM sistem_loglari l 
    LEFT JOIN yoneticiler y ON l.kullanici_id = y.id 
    WHERE l.firma_id = ? 
    ORDER BY l.tarih DESC LIMIT 50
");
$firma_loglari->execute([$firma_id]);
$firma_loglari = $firma_loglari->fetchAll(PDO::FETCH_ASSOC);

// --- DESTEK TALEPLERİ ÇEKME (Destek Sekmesi İçin) ---
$aktif_talep_id = isset($_GET['tid']) ? (int)$_GET['tid'] : 0;
$unreadQuery = $pdo->prepare("SELECT COUNT(*) FROM destek_mesajlari m JOIN destek_talepleri t ON m.talep_id = t.id WHERE t.firma_id = ? AND m.gonderen_tipi = 'firma' AND m.okundu = 0");
$unreadQuery->execute([$firma_id]);
$toplamOkunmamis = $unreadQuery->fetchColumn() ?: 0;

$talepler = [];
$mesajlar = [];
$seciliTalep = null;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'ozet';

if($activeTab == 'destek') {
    $stmtTalepler = $pdo->prepare("
        SELECT t.*, 
        (SELECT COUNT(*) FROM destek_mesajlari WHERE talep_id = t.id AND okundu = 0 AND gonderen_tipi = 'firma') as okunmamis,
        (SELECT mesaj FROM destek_mesajlari WHERE talep_id = t.id ORDER BY id DESC LIMIT 1) as son_mesaj
        FROM destek_talepleri t 
        WHERE t.firma_id = ? 
        ORDER BY t.son_islem_tarihi DESC
    ");
    $stmtTalepler->execute([$firma_id]);
    $talepler = $stmtTalepler->fetchAll(PDO::FETCH_ASSOC);

    if($aktif_talep_id > 0) {
        $stmtT = $pdo->prepare("SELECT * FROM destek_talepleri WHERE id = ? AND firma_id = ?");
        $stmtT->execute([$aktif_talep_id, $firma_id]);
        $seciliTalep = $stmtT->fetch(PDO::FETCH_ASSOC);
        
        if($seciliTalep) {
            $stmtM = $pdo->prepare("
                SELECT m.*, y.ad_soyad as kullanici_adi 
                FROM destek_mesajlari m 
                LEFT JOIN yoneticiler y ON m.gonderen_id = y.id 
                WHERE m.talep_id = ? ORDER BY m.tarih ASC
            ");
            $stmtM->execute([$aktif_talep_id]);
            $mesajlar = $stmtM->fetchAll(PDO::FETCH_ASSOC);
            
            $pdo->prepare("UPDATE destek_mesajlari SET okundu = 1 WHERE talep_id = ? AND gonderen_tipi = 'firma'")->execute([$aktif_talep_id]);
            $toplamOkunmamis = 0; 
        }
    }
}

// --- DEPOLAMA (STORAGE) HESAPLAMA MANTIĞI ---
function getDirectorySize($path) {
    $bytestotal = 0;
    $path = realpath($path);
    if($path !== false && $path != '' && file_exists($path)){
        try {
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object){
                $bytestotal += $object->getSize();
            }
        } catch(Exception $e) {}
    }
    return $bytestotal;
}

$base_upload_dir = __DIR__ . '/../../uploads/';
$folders_to_check = ['albumler', 'haziralbumler', 'videoklipler', 'videolar'];
$kullanilan_bayt = 0;

foreach ($folders_to_check as $folder) {
    $firma_folder = $base_upload_dir . $folder . '/' . $firma_id;
    if (is_dir($firma_folder)) {
        $kullanilan_bayt += getDirectorySize($firma_folder);
    }
}

$kullanilan_mb = $kullanilan_bayt / 1048576;
$gosterim_depolama = $kullanilan_mb >= 1024 ? round($kullanilan_mb / 1024, 2) . ' GB' : round($kullanilan_mb, 2) . ' MB';

$paket_depolama = (int)($firma['paket_depolama_limiti'] ?? 0);
$ek_depolama_alani = (int)($firma['ek_depolama_alani'] ?? 0);
$toplam_depolama_mb = $paket_depolama + $ek_depolama_alani;
$toplam_depolama_gosterim = $toplam_depolama_mb >= 1024 ? round($toplam_depolama_mb / 1024, 2) . ' GB' : $toplam_depolama_mb . ' MB';

// --- TRAFİK (BANDWIDTH) HESAPLAMA ---
$aylik_trafik_bayt = (int)($firma['aylik_trafik_kullanimi'] ?? 0);
$aylik_trafik_mb = $aylik_trafik_bayt / 1048576;
$gosterim_trafik = $aylik_trafik_mb >= 1024 ? round($aylik_trafik_mb / 1024, 2) . ' GB' : round($aylik_trafik_mb, 2) . ' MB';

$ek_trafik_limiti = (int)($firma['ek_trafik_limiti'] ?? 0);
$toplam_trafik_mb = ($paket_depolama * 10) + $ek_trafik_limiti; 
$toplam_trafik_gosterim = $toplam_trafik_mb >= 1024 ? round($toplam_trafik_mb / 1024, 2) . ' GB' : $toplam_trafik_mb . ' MB';

$trafik_yuzde = $toplam_trafik_mb > 0 ? min(100, ($aylik_trafik_mb / $toplam_trafik_mb) * 100) : 0;
$trafik_renk = $trafik_yuzde > 90 ? 'bg-danger' : ($trafik_yuzde > 75 ? 'bg-warning' : 'bg-primary');

$trafik_durum_metni = '<span class="badge bg-success">Trafik Normal</span>';
$trafik_asim_tarihi = $firma['trafik_asim_tarihi'] ?? null;
$anlik_kesinti = (int)($firma['anlik_kesinti'] ?? 0);
$ek_sure_gun = (int)($firma['ek_sure_gun'] ?? 3);

if ($aylik_trafik_mb >= $toplam_trafik_mb && $toplam_trafik_mb > 0) {
    if ($anlik_kesinti == 1) {
        $trafik_durum_metni = '<span class="badge bg-dark text-white shadow-sm"><i class="fas fa-ban me-1"></i> ŞALTER İNDİRİLDİ (KESİNTİ)</span>';
    } elseif ($trafik_asim_tarihi) {
        $bitis_tarihi = strtotime($trafik_asim_tarihi . " + $ek_sure_gun days");
        $kalan_saat = ceil(($bitis_tarihi - time()) / 3600);
        if ($kalan_saat > 0) {
            $trafik_durum_metni = '<span class="badge bg-warning text-dark shadow-sm"><i class="fas fa-hourglass-half me-1"></i> Tolerans Süresi (Kalan: '.$kalan_saat.' Saat)</span>';
        } else {
            $trafik_durum_metni = '<span class="badge bg-danger shadow-sm"><i class="fas fa-lock me-1"></i> SÜRE DOLDU - LİMİT KİLİTLİ</span>';
        }
    } else {
        $trafik_durum_metni = '<span class="badge bg-danger">Limit Aşıldı</span>';
    }
}

// ONAY ROZETİ VE RENK AYARLAMALARI (ADMİN İÇİN)
$onay_renk = 'warning';
$onay_ikon = 'exclamation-circle';
$onay_metin = 'Bilgiler Eksik';
if ($firma['bilgi_onay_durumu'] == 1) { 
    $onay_renk = 'info'; 
    $onay_ikon = 'clock'; 
    $onay_metin = 'Onay Bekliyor'; 
} elseif ($firma['bilgi_onay_durumu'] == 2) { 
    $onay_renk = 'success'; 
    $onay_ikon = 'check-circle'; 
    $onay_metin = 'Onaylı Firma'; 
}

// CHAT İÇİN EK CSS
$inline_css = '
    .chat-layout { display: flex; height: 600px; background: #fff; border-radius: 10px; overflow: hidden; border: 1px solid #e3e6f0; }
    .chat-sidebar { width: 320px; background: #f8f9fc; border-right: 1px solid #e3e6f0; display: flex; flex-direction: column; }
    .chat-main { flex: 1; display: flex; flex-direction: column; background: #fff; position: relative; }
    .ticket-item { padding: 15px; border-bottom: 1px solid #eaecf4; cursor: pointer; transition: 0.2s; text-decoration: none; color: inherit; display: block; position: relative; }
    .ticket-item:hover, .ticket-item.active { background: #fff; border-left: 4px solid #4e73df; }
    .ticket-item.active { background: #f1f3f9; }
    .badge-count { position: absolute; right: 10px; top: 15px; background: #e74a3b; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; }
    .msg-container { flex: 1; padding: 20px; overflow-y: auto; background: #f4f6f8; display: flex; flex-direction: column; gap: 10px; }
    .msg-bubble { max-width: 75%; padding: 10px 15px; border-radius: 15px; font-size: 0.95rem; line-height: 1.5; position: relative; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    .msg-me { align-self: flex-end; background: #e3ebf7; color: #1a3c87; border-bottom-right-radius: 2px; }
    .msg-me .meta { font-size: 0.7rem; color: #4e73df; text-align: right; opacity: 0.8; margin-top: 4px; }
    .msg-other { align-self: flex-start; background: #fff; color: #333; border-bottom-left-radius: 2px; border: 1px solid #eaecf4; }
    .msg-other .meta { font-size: 0.7rem; color: #858796; margin-top: 4px; }
    .chat-input-box { padding: 15px; background: #fff; border-top: 1px solid #eaecf4; }
    @media (max-width: 768px) { .chat-layout { flex-direction: column; height: auto; } .chat-sidebar { width: 100%; height: 300px; } .chat-main { height: 500px; } }
';
?>

<style><?= $inline_css ?></style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">
        <a href="firmalar.php" class="text-secondary text-decoration-none"><i class="fas fa-arrow-left fs-5 me-2"></i></a>
        Firma Profili: <?= e($firma['firma_adi']) ?>
    </h1>
    <div class="d-flex align-items-center">
        <!-- ONAY DURUMU ROZETİ -->
        <span class="badge bg-<?= $onay_renk ?> fs-6 px-3 py-2 me-2" title="Firma Bilgi Onay Durumu">
            <i class="fas fa-<?= $onay_ikon ?> me-1"></i> <?= $onay_metin ?>
        </span>

        <!-- AKTİF / PASİF ROZETİ VE BUTONU -->
        <span class="badge <?= $firma['durum'] == 1 ? 'bg-success' : 'bg-danger' ?> fs-6 px-3 py-2 me-3">
            <?= $firma['durum'] == 1 ? 'AKTİF' : 'PASİF' ?>
        </span>
        
        <form method="POST" class="d-inline" onsubmit="return confirm('Firma durumu değiştirilecek. Emin misiniz?');">
            <input type="hidden" name="durum_degistir" value="1">
            <input type="hidden" name="yeni_durum" value="<?= $firma['durum'] == 1 ? 0 : 1 ?>">
            <button type="submit" class="btn btn-sm btn-<?= $firma['durum'] == 1 ? 'warning text-dark' : 'success' ?> me-2 fw-bold shadow-sm">
                <i class="fas fa-<?= $firma['durum'] == 1 ? 'pause' : 'play' ?> me-1"></i> <?= $firma['durum'] == 1 ? 'Dondur' : 'Aktif Et' ?>
            </button>
        </form>

        <button class="btn btn-sm btn-outline-secondary bg-white shadow-sm" onclick="alert('Login As özelliği altyapı olarak eklenecektir.');"><i class="fas fa-sign-in-alt me-1"></i> Panele Giriş</button>
    </div>
</div>

<div class="row">
    <!-- Sol Menü (Tabs) -->
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-2">
                <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                    <a class="nav-link <?= $activeTab == 'ozet' ? 'active' : '' ?>" href="?id=<?= e($firma_id) ?>&tab=ozet">
                        <i class="fas fa-info-circle fa-fw me-2"></i> Genel Özet
                    </a>
                    <a class="nav-link <?= $activeTab == 'duzenle' ? 'active' : '' ?>" href="?id=<?= e($firma_id) ?>&tab=duzenle">
                        <i class="fas fa-edit fa-fw me-2"></i> Bilgileri Düzenle
                    </a>
                    <a class="nav-link <?= $activeTab == 'istatistik' ? 'active' : '' ?>" href="?id=<?= e($firma_id) ?>&tab=istatistik">
                        <i class="fas fa-chart-bar fa-fw me-2"></i> İstatistik & Loglar
                    </a>
                    <a class="nav-link <?= $activeTab == 'finans' ? 'active' : '' ?>" href="?id=<?= e($firma_id) ?>&tab=finans">
                        <i class="fas fa-wallet fa-fw me-2"></i> Finans & Ödeme
                    </a>
                    <a class="nav-link <?= $activeTab == 'kullanicilar' ? 'active' : '' ?>" href="?id=<?= e($firma_id) ?>&tab=kullanicilar">
                        <i class="fas fa-users fa-fw me-2"></i> Kullanıcılar (<?= count($kullanicilar) ?>)
                    </a>
                    <a class="nav-link <?= $activeTab == 'ayarlar' ? 'active' : '' ?>" href="?id=<?= e($firma_id) ?>&tab=ayarlar">
                        <i class="fas fa-cogs fa-fw me-2"></i> Sistem Ayarları
                    </a>
                    <!-- DESTEK TALEPLERİ SEKMESİ -->
                    <a class="nav-link text-primary fw-bold mt-2 <?= $activeTab == 'destek' ? 'active text-white' : '' ?>" href="?id=<?= e($firma_id) ?>&tab=destek" style="border: 1px dashed #4e73df;">
                        <i class="fas fa-headset fa-fw me-2"></i> Destek Talepleri
                        <?php if($toplamOkunmamis > 0): ?>
                            <span class="badge bg-danger rounded-pill float-end mt-1"><?= $toplamOkunmamis ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Sağ İçerik -->
    <div class="col-md-9">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                
                <?php if($activeTab == 'ozet'): ?>
                <!-- 1. GENEL ÖZET VE LİMİTLER -->
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-4">
                    <h5 class="fw-bold text-primary mb-0">Genel Özet ve Limit Yönetimi</h5>
                    <button class="btn btn-sm btn-dark shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#kotaLimitDuzenleModal">
                        <i class="fas fa-sliders-h me-1"></i> Kotaya Müdahale Et
                    </button>
                </div>
                
                <div class="row g-3 mb-4">
                    <!-- DEPOLAMA (DİSK) -->
                    <div class="col-md-3">
                        <div class="border p-3 rounded text-center position-relative h-100 bg-light">
                            <i class="fas fa-hdd fa-2x text-info mb-2"></i>
                            <div class="small fw-bold text-muted">DİSK ALANI</div>
                            <h5 class="fw-bold mb-0 mt-1"><?= $gosterim_depolama ?></h5>
                            <span class="fs-6 fw-normal text-muted">Limit: <?= $toplam_depolama_gosterim ?></span>
                            <?php if($ek_depolama_alani > 0): ?>
                                <span class="badge bg-info mt-1 d-block">+<?= $ek_depolama_alani ?> MB Ek Alan</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- VERİ TRAFİĞİ (YENİ) -->
                    <div class="col-md-3">
                        <div class="border p-3 rounded text-center position-relative h-100 <?= $trafik_renk ?> bg-opacity-10">
                            <i class="fas fa-wifi fa-2x text-primary mb-2"></i>
                            <div class="small fw-bold text-muted">AYLIK TRAFİK (GB)</div>
                            <h5 class="fw-bold mb-0 mt-1"><?= $gosterim_trafik ?></h5>
                            <span class="fs-6 fw-normal text-muted">Limit: <?= $toplam_trafik_gosterim ?></span>
                            <div class="mt-2"><?= $trafik_durum_metni ?></div>
                        </div>
                    </div>
                    <!-- SMS BAKİYESİ (4 KOVALI SİSTEM) -->
                    <div class="col-md-3">
                        <div class="border p-3 rounded text-center h-100 bg-light position-relative">
                            <i class="fas fa-sms fa-2x text-warning mb-2"></i>
                            <div class="small fw-bold text-muted">SMS KULLANIMI</div>
                            <h5 class="fw-bold mb-0 mt-1 text-dark">
                                <?= (int)($firma['kullanilan_sms_aylik'] ?? 0) ?>
                                <span class="fs-6 text-muted fw-normal"> / <?= (int)($firma['aylik_sms_limiti'] ?? 0) ?></span>
                            </h5>
                            <span class="badge bg-warning text-dark mt-1 d-block">+<?= (int)($firma['ek_sms_bakiyesi'] ?? 0) ?> Ek Bakiye</span>
                            
                            <!-- SMS Düzenleme Butonu -->
                            <button class="btn btn-sm btn-light border position-absolute top-0 end-0 m-1 py-0 px-2" data-bs-toggle="modal" data-bs-target="#smsDuzenleModal" title="Ek SMS Yükle"><i class="fas fa-plus small text-muted"></i></button>
                        </div>
                    </div>
                    <!-- MÜŞTERİ SAYISI -->
                    <div class="col-md-3">
                        <div class="border p-3 rounded text-center h-100 bg-light">
                            <i class="fas fa-users fa-2x text-success mb-2"></i>
                            <div class="small fw-bold text-muted">MÜŞTERİ KAYDI</div>
                            <h5 class="fw-bold mb-0 mt-1 text-success"><?= number_format($musteri_sayisi) ?></h5>
                            <span class="fs-6 fw-normal text-muted">Limit: <?= $firma['musteri_limiti'] > 0 ? number_format($firma['musteri_limiti']) : 'Sınırsız' ?></span>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="bg-light p-3 rounded h-100 border">
                            <h6 class="text-muted small fw-bold">YETKİLİ BİLGİSİ</h6>
                            <div class="fs-5 fw-bold text-dark"><?= e($firma['yetkili_ad_soyad']) ?></div>
                            <div class="mt-2 text-secondary"><i class="fas fa-phone fa-fw me-1"></i> <?= e($firma['telefon']) ?></div>
                            <div class="text-secondary"><i class="fas fa-id-card fa-fw me-1"></i> Vergi No: <?= e($firma['vergi_no'] ?: 'Belirtilmemiş') ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="bg-light p-3 rounded h-100 border text-center">
                            <h6 class="text-muted small fw-bold text-uppercase">Mevcut Paket & Abonelik</h6>
                            <div class="badge bg-primary fs-6 mt-1 mb-2"><?= e($firma['paket_adi'] ?: 'Paket Atanmamış') ?></div>
                            <div class="fs-4 fw-bold <?= (strtotime($firma['abonelik_bitis']) < time()) ? 'text-danger' : 'text-success' ?>">
                                <?= $firma['abonelik_bitis'] ? date('d.m.Y', strtotime($firma['abonelik_bitis'])) : 'Süresiz' ?>
                            </div>
                            <div class="d-flex justify-content-between text-muted mt-2 pt-2 border-top" style="font-size: 11px;">
                                <span>İlk Kayıt: <b><?= $firma['ilk_abonelik_tarihi'] ? date('d.m.Y', strtotime($firma['ilk_abonelik_tarihi'])) : '-' ?></b></span>
                                <span>Son Yenileme: <b><?= $firma['son_abonelik_baslangic'] ? date('d.m.Y', strtotime($firma['son_abonelik_baslangic'])) : '-' ?></b></span>
                            </div>
                        </div>
                    </div>

                    <!-- FİRMA ONAY MEKANİZMASI KONTROL ALANI -->
                    <div class="col-12 mt-1">
                        <div class="border border-<?= $onay_renk ?> p-3 rounded bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="fw-bold text-<?= $onay_renk ?> mb-1"><i class="fas fa-<?= $onay_ikon ?>"></i> Firma Bilgi Onay Durumu</h6>
                                    <p class="small text-muted mb-0">Firmanın sistem özelliklerini tam yetkiyle kullanabilmesi için "Onaylı Firma" statüsünde olması gerekir.</p>
                                </div>
                                <form method="POST" class="d-flex align-items-center">
                                    <input type="hidden" name="onay_durumu_guncelle" value="1">
                                    <select name="yeni_onay_durumu" class="form-select form-select-sm border-<?= $onay_renk ?> me-2 fw-bold" style="width: 170px;">
                                        <option value="0" <?= $firma['bilgi_onay_durumu'] == 0 ? 'selected' : '' ?>>Bilgiler Eksik</option>
                                        <option value="1" <?= $firma['bilgi_onay_durumu'] == 1 ? 'selected' : '' ?>>Onay Bekliyor</option>
                                        <option value="2" <?= $firma['bilgi_onay_durumu'] == 2 ? 'selected' : '' ?>>Onaylandı (Tam Yetki)</option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-<?= $onay_renk ?> fw-bold">Güncelle</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- YENİ EFSANE MODAL: KOTA VE LİMİT DÜZENLEME -->
                <div class="modal fade" id="kotaLimitDuzenleModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="manuel_kota_guncelle" value="1">
                                <div class="modal-header bg-dark text-white">
                                    <h6 class="modal-title fw-bold"><i class="fas fa-sliders-h me-2"></i>Kota ve Limit Yönetimi</h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    
                                    <div class="alert alert-info border-0 small">
                                        <i class="fas fa-info-circle me-1"></i> Bu alandan firmaya özel ek alan (disk), ek trafik (bandwidth) ve tolerans sürelerini ayarlayabilirsiniz.
                                    </div>

                                    <h6 class="fw-bold text-primary border-bottom pb-1"><i class="fas fa-hdd me-1"></i> Depolama (Disk) Ayarı</h6>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-muted">Ek Depolama Alanı (MB)</label>
                                        <div class="input-group">
                                            <input type="number" name="ek_depolama_alani" class="form-control fw-bold" value="<?= $ek_depolama_alani ?>">
                                            <span class="input-group-text fw-bold">MB</span>
                                        </div>
                                        <div class="form-text small">Paket limitine ilave edilecek kalıcı alan. (1 GB = 1024 MB)</div>
                                    </div>

                                    <h6 class="fw-bold text-danger border-bottom pb-1 mt-4"><i class="fas fa-wifi me-1"></i> Aylık Trafik (Bandwidth) Ayarı</h6>
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Ek Trafik Kotası (MB)</label>
                                            <div class="input-group">
                                                <input type="number" name="ek_trafik_limiti" class="form-control" value="<?= $ek_trafik_limiti ?>">
                                                <span class="input-group-text fw-bold">MB</span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Tolerans Süresi (Gün)</label>
                                            <div class="input-group">
                                                <input type="number" name="ek_sure_gun" class="form-control" value="<?= $ek_sure_gun ?>" min="0">
                                                <span class="input-group-text fw-bold">Gün</span>
                                            </div>
                                        </div>
                                        <div class="col-12 mt-1">
                                            <div class="form-text small">Kotayı aştığında müşteriye tanınacak ek süre (Örn: 3 gün).</div>
                                        </div>
                                    </div>

                                    <div class="form-check form-switch border p-3 rounded bg-danger bg-opacity-10 border-danger">
                                        <input class="form-check-input ms-1" type="checkbox" name="anlik_kesinti" id="anlikKesintiCheck" style="transform: scale(1.3);" <?= $anlik_kesinti == 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold ms-3 text-danger" for="anlikKesintiCheck">Anlık Kesinti (Şalteri İndir)</label>
                                        <div class="small text-danger ms-3 mt-1 fw-semibold">Bu seçenek aktifse, firma trafik kotasını aştığı saniye sistemi durdurulur (Tolerans süresi tanınmaz).</div>
                                    </div>

                                </div>
                                <div class="modal-footer bg-light">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                    <button type="submit" class="btn btn-dark fw-bold">Ayarları Kaydet</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Manuel Ek SMS Düzenleme Modal -->
                <div class="modal fade" id="smsDuzenleModal" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="manuel_sms_ekle" value="1">
                                <div class="modal-header bg-warning text-dark">
                                    <h6 class="modal-title fw-bold">Ek SMS Bakiyesi Tanımla</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-center">
                                    <label class="form-label small fw-bold">Firmanın EK SMS Bakiyesi</label>
                                    <input type="number" name="ek_sms_bakiyesi" class="form-control text-center fs-3 fw-bold" value="<?= (int)($firma['ek_sms_bakiyesi'] ?? 0) ?>" required>
                                    <div class="form-text small mt-2">Firma ek paket aldıysa bu alanı güncelleyin. Bu bakiye her ay devredecektir.</div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-warning text-dark fw-bold w-100">Ek Bakiyeyi Güncelle</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php elseif($activeTab == 'duzenle'): ?>
                <!-- 2. BİLGİLERİ DÜZENLE -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Firma Bilgilerini ve Paketini Güncelle</h5>
                <form method="POST">
                    <input type="hidden" name="firma_guncelle" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Firma Adı</label>
                            <input type="text" name="firma_adi" class="form-control" value="<?= e($firma['firma_adi']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Yetkili Ad Soyad</label>
                            <input type="text" name="yetkili" class="form-control" value="<?= e($firma['yetkili_ad_soyad']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">İletişim Numarası</label>
                            <input type="text" name="telefon" class="form-control" value="<?= e($firma['telefon']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Vergi Numarası / TC</label>
                            <input type="text" name="vergi_no" class="form-control" value="<?= e($firma['vergi_no']) ?>">
                        </div>
                        
                        <div class="col-12"><hr class="my-2"></div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-primary">Sistem Paketi Tanımla</label>
                            <select name="paket_id" class="form-select border-primary">
                                <option value="">-- Paket Seçilmedi --</option>
                                <?php foreach($paketler as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $firma['paket_id'] == $p['id'] ? 'selected' : '' ?>><?= e($p['paket_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-check mt-2">
                                <input class="form-check-input border-primary" type="checkbox" name="sms_yenile" value="1" id="smsYenileCheck" checked>
                                <label class="form-check-label small fw-bold text-muted" for="smsYenileCheck">
                                    Firmanın AYLIK SMS limitini yeni paketin limitine göre güncelle.
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-primary">Abonelik Bitiş Tarihi</label>
                            <input type="date" name="abonelik_bitis" class="form-control border-primary" value="<?= e($firma['abonelik_bitis']) ?>">
                            <div class="form-text small mt-1">Süresi dolan firmalar sisteme giriş yapamaz.</div>
                        </div>

                        <div class="col-12"><hr class="my-2"></div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Açık Adres</label>
                            <textarea name="adres" class="form-control" rows="2"><?= e($firma['adres']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold text-danger">Admin Özel Notu</label>
                            <textarea name="admin_not" class="form-control bg-light" rows="3" placeholder="Sadece yöneticiler görebilir..."><?= e($firma['admin_ozel_notu']) ?></textarea>
                        </div>
                        <div class="col-12 text-end mt-4">
                            <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Değişiklikleri Kaydet</button>
                        </div>
                    </div>
                </form>

                <?php elseif($activeTab == 'istatistik'): ?>
                <!-- 3. İSTATİSTİKLER VE LOGLAR -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Firmaya Ait İstatistikler & Log Kayıtları</h5>
                
                <h6 class="fw-bold mb-3"><i class="fas fa-list text-muted me-2"></i>Son 50 Sistem Kaydı (Log)</h6>
                <div class="table-responsive border rounded" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-striped align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="bg-dark text-white sticky-top">
                            <tr>
                                <th class="ps-3">Tarih</th>
                                <th>İşlem / Aksiyon</th>
                                <th>Kullanıcı</th>
                                <th>IP Adresi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($firma_loglari as $log): ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= date('d.m H:i', strtotime($log['tarih'])) ?></td>
                                <td class="fw-semibold">
                                    <?= e($log['islem']) ?>
                                    <div class="small text-muted fw-normal"><?= e($log['detay']) ?></div>
                                </td>
                                <td>
                                    <?php if(empty($log['kullanici_adi'])): ?>
                                        <span class="badge bg-danger">Süper Admin</span>
                                    <?php else: ?>
                                        <?= e($log['kullanici_adi']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><span class="font-monospace"><?= e($log['ip_adresi'] ?? '-') ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($firma_loglari)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">Firmaya ait herhangi bir log bulunamadı.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php elseif($activeTab == 'finans'): ?>
                <!-- 4. FİNANS VE ÖDEME -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Finans ve Ödeme Kayıtları</h5>
                <div class="row">
                    <div class="col-md-8">
                        <div class="table-responsive border rounded">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-2">Tarih</th>
                                        <th>Tutar</th>
                                        <th>Yöntem</th>
                                        <th>Açıklama</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($odemeler as $ode): ?>
                                    <tr>
                                        <td class="ps-2"><?= date("d.m.Y", strtotime($ode['tarih'])) ?></td>
                                        <td class="fw-bold text-success"><?= number_format($ode['tutar'], 2) ?> ₺</td>
                                        <td><?= e($ode['odeme_turu']) ?></td>
                                        <td class="small text-muted"><?= e($ode['aciklama']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($odemeler)): ?>
                                        <tr><td colspan="4" class="text-center py-3 text-muted">Ödeme kaydı bulunmamaktadır.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="bg-light p-3 rounded border border-success">
                            <h6 class="fw-bold mb-3 text-success"><i class="fas fa-plus-circle me-1"></i> Yeni Ödeme Alındı İşle</h6>
                            <form method="POST">
                                <input type="hidden" name="odeme_ekle" value="1">
                                <div class="mb-2">
                                    <input type="number" step="0.01" name="tutar" class="form-control form-control-sm" placeholder="Tutar (₺)" required>
                                </div>
                                <div class="mb-2">
                                    <select name="odeme_turu" class="form-select form-select-sm">
                                        <option>Banka Havalesi / EFT</option>
                                        <option>Kredi Kartı</option>
                                        <option>Nakit</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <input type="text" name="aciklama" class="form-control form-control-sm" placeholder="Açıklama (Örn: 1 Yıllık Abonelik Uzatma)">
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="sure_uzat_opsiyon" value="1" id="sureUzatCB" checked onchange="document.getElementById('sureAyar').style.display = this.checked ? 'block' : 'none';">
                                    <label class="form-check-label small fw-bold text-primary" for="sureUzatCB">Abonelik süresini otomatik uzat</label>
                                </div>
                                <div id="sureAyar" class="mb-3">
                                    <select name="ek_ay" class="form-select form-select-sm border-primary">
                                        <option value="1">+1 Ay Ekle</option>
                                        <option value="6">+6 Ay Ekle</option>
                                        <option value="12" selected>+1 Yıl (12 Ay) Ekle</option>
                                    </select>
                                    <div class="form-text small" style="font-size: 11px;">Eklenecek süre, mevcut bitiş tarihinin üstüne ilave edilir. Ayrıca firmanın "Son Yenileme Tarihi" otomatik güncellenir.</div>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-bold">Ödemeyi Kaydet & İşle</button>
                            </form>
                        </div>
                    </div>
                </div>

                <?php elseif($activeTab == 'kullanicilar'): ?>
                <!-- 5. KULLANICILAR -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Ekip ve Kullanıcılar</h5>
                <div class="row">
                    <div class="col-md-8">
                        <div class="table-responsive border rounded">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-2">Kullanıcı Adı</th>
                                        <th>E-Posta</th>
                                        <th>Rol</th>
                                        <th class="text-end pe-2">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($kullanicilar as $u): ?>
                                    <tr>
                                        <td class="fw-bold ps-2"><?= e($u['kullanici_adi']) ?></td>
                                        <td class="small"><?= e($u['email']) ?></td>
                                        <td>
                                            <?php 
                                                if($u['rol']=='admin') echo '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger">Firma Yetkilisi</span>';
                                                elseif($u['rol']=='personel') echo '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary">Personel</span>';
                                                elseif($u['rol']=='ajanda') echo '<span class="badge bg-info bg-opacity-10 text-info border border-info">Ajanda</span>';
                                            ?>
                                        </td>
                                        <td class="text-end pe-2">
                                            <form method="POST" onsubmit="return confirm('Kullanıcıya şifre sıfırlama bağlantısı içeren bir e-posta gönderilecek. Onaylıyor musunuz?');" style="margin:0;">
                                                <input type="hidden" name="sifre_sifirla_mail" value="1">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <input type="hidden" name="user_email" value="<?= $u['email'] ?>">
                                                <button class="btn btn-sm btn-outline-info" title="Sıfırlama Maili Gönder"><i class="fas fa-key me-1"></i>Sıfırla</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="bg-light p-3 rounded border">
                            <h6 class="fw-bold mb-3">Yeni Kullanıcı Ekle</h6>
                            <form method="POST">
                                <input type="hidden" name="personel_ekle" value="1">
                                <div class="mb-2"><input type="text" name="kadi" class="form-control form-control-sm" placeholder="Kullanıcı Adı" required></div>
                                <div class="mb-2"><input type="email" name="email" class="form-control form-control-sm" placeholder="E-Posta" required></div>
                                <div class="mb-2"><input type="password" name="sifre" class="form-control form-control-sm" placeholder="Giriş Şifresi" required></div>
                                <div class="mb-3">
                                    <select name="rol" class="form-select form-select-sm">
                                        <option value="personel">Personel</option>
                                        <option value="ajanda">Sadece Ajanda</option>
                                        <option value="admin">Firma Yöneticisi</option>
                                    </select>
                                </div>
                                <button class="btn btn-primary btn-sm w-100">Kullanıcıyı Oluştur</button>
                            </form>
                        </div>
                    </div>
                </div>

                <?php elseif($activeTab == 'ayarlar'): ?>
                <!-- 6. AYARLAR -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Firmaya Özel Sistem Ayarları</h5>
                <form method="POST">
                    <input type="hidden" name="teklif_ayar_guncelle" value="1">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Teklif Şartları Şablonu (Üst Kısım)</label>
                        <textarea name="teklif_sartlari" class="form-control" rows="5"><?= e($firma['teklif_sartlari'] ?? '') ?></textarea>
                        <div class="form-text">Müşteri firması teklif oluştururken otomatik olarak bu metin eklenir.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold small">Teklif Alt Bilgi (Banka Detayları vb.)</label>
                        <textarea name="teklif_alt_bilgi" class="form-control" rows="5"><?= e($firma['teklif_alt_bilgi'] ?? '') ?></textarea>
                    </div>
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary px-4">Ayarları Kaydet</button>
                    </div>
                </form>
                
                <?php elseif($activeTab == 'destek'): ?>
                <!-- 7. DESTEK TALEPLERİ -->
                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Firma ile Destek Yazışmaları</h5>
                
                <div class="chat-layout border">
                    <!-- Sol Menü: Talepler -->
                    <div class="chat-sidebar">
                        <div class="p-3 border-bottom bg-light">
                            <input type="text" class="form-control form-control-sm" placeholder="Talep ara..." id="ticketSearch">
                        </div>
                        <div class="flex-grow-1 overflow-auto" id="ticketList">
                            <?php if(count($talepler) > 0): ?>
                                <?php foreach($talepler as $t): ?>
                                <a href="?id=<?= $firma_id ?>&tab=destek&tid=<?= $t['id'] ?>" class="ticket-item <?= $aktif_talep_id == $t['id'] ? 'active' : '' ?>">
                                    <div class="d-flex justify-content-between mb-1">
                                        <strong class="text-dark text-truncate" style="max-width: 70%;">#<?= e($t['konu']) ?></strong>
                                        <small class="text-muted" style="font-size:11px;">
                                            <?php 
                                            $tarihGoster = $t['tarih'] ?? $t['son_islem_tarihi'];
                                            echo $tarihGoster ? date("d.m H:i", strtotime($tarihGoster)) : '-'; 
                                            ?>
                                        </small>
                                    </div>
                                    <div class="small text-muted text-truncate">
                                        <?= $t['son_mesaj'] ? e($t['son_mesaj']) : 'Mesaj yok...' ?>
                                    </div>
                                    <?php if($t['okunmamis'] > 0): ?>
                                        <span class="badge-count"><?= $t['okunmamis'] ?></span>
                                    <?php endif; ?>
                                </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center p-5 text-muted small">
                                    <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>
                                    Bu firmaya ait destek talebi bulunmuyor.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Sağ Alan: Mesajlaşma -->
                    <div class="chat-main">
                        <?php if($seciliTalep): ?>
                            <!-- Chat Header -->
                            <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center shadow-sm" style="z-index: 10;">
                                <div>
                                    <h6 class="mb-0 fw-bold"><?= e($seciliTalep['konu']) ?></h6>
                                    <span class="badge bg-<?= $seciliTalep['durum']=='yanitlandi'?'success':($seciliTalep['durum']=='kapali'?'secondary':'warning') ?> small">
                                        <?= ucfirst($seciliTalep['durum']) ?>
                                    </span>
                                    <small class="text-muted ms-2">Talep ID: #<?= $seciliTalep['id'] ?></small>
                                </div>
                                <div>
                                    <?php if($seciliTalep['durum'] != 'kapali'): ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Bu talebi çözüldü olarak işaretleyip kapatmak istediğinize emin misiniz?');">
                                            <input type="hidden" name="talep_kapat" value="1">
                                            <input type="hidden" name="talep_id" value="<?= $seciliTalep['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-times me-1"></i> Talebi Kapat</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-danger small fw-bold"><i class="fas fa-lock me-1"></i> Konu Kapalı</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Mesajlar -->
                            <div class="msg-container" id="msgBox">
                                <div class="text-center my-3">
                                    <span class="badge bg-light text-secondary border fw-normal">
                                        Talep Oluşturuldu: 
                                        <?= date("d.m.Y H:i", strtotime($seciliTalep['tarih'] ?? $seciliTalep['son_islem_tarihi'])) ?>
                                    </span>
                                </div>
                                
                                <?php foreach($mesajlar as $m): 
                                    $benim = ($m['gonderen_tipi'] == 'admin');
                                ?>
                                    <div class="msg-bubble <?= $benim ? 'msg-me' : 'msg-other' ?>">
                                        <?php if(!$benim): ?>
                                            <div class="small fw-bold text-dark mb-1"><i class="fas fa-user me-1"></i> <?= e($m['kullanici_adi'] ?? 'Firma Yetkilisi') ?></div>
                                        <?php endif; ?>
                                        
                                        <?= nl2br(e($m['mesaj'])) ?>
                                        
                                        <div class="meta">
                                            <?= date("H:i", strtotime($m['tarih'])) ?>
                                            <?php if($benim): ?>
                                                <i class="fas fa-check<?= $m['okundu'] ? '-double' : '' ?>"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if($seciliTalep['durum'] == 'kapali'): ?>
                                    <div class="text-center my-4">
                                        <span class="badge bg-secondary">Bu destek talebi kapatılmıştır.</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Yazma Alanı -->
                            <?php if($seciliTalep['durum'] != 'kapali'): ?>
                            <div class="chat-input-box">
                                <form method="POST" class="d-flex gap-2">
                                    <input type="hidden" name="admin_mesaj_gonder" value="1">
                                    <input type="hidden" name="talep_id" value="<?= $seciliTalep['id'] ?>">
                                    <input type="text" name="mesaj" class="form-control rounded-pill bg-light border-0 px-4" placeholder="Firmaya yanıt yazın..." autocomplete="off" required>
                                    <button class="btn btn-primary rounded-circle" style="width: 45px; height: 45px;"><i class="fas fa-paper-plane"></i></button>
                                </form>
                            </div>
                            <?php endif; ?>

                            <script>
                                var msgBox = document.getElementById("msgBox");
                                if(msgBox) msgBox.scrollTop = msgBox.scrollHeight;
                            </script>

                        <?php else: ?>
                            <!-- Boş Durum -->
                            <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
                                <i class="fas fa-headset fa-4x mb-3 text-gray-300"></i>
                                <h5>Firma Destek Paneli</h5>
                                <p class="small">Sol menüden bir talep seçerek firma ile yazışabilirsiniz.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <script>
                document.getElementById('ticketSearch')?.addEventListener('keyup', function() {
                    let filter = this.value.toLowerCase();
                    let items = document.querySelectorAll('#ticketList .ticket-item');
                    items.forEach(item => {
                        let text = item.innerText.toLowerCase();
                        item.style.display = text.includes(filter) ? '' : 'none';
                    });
                });
                </script>
                
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>