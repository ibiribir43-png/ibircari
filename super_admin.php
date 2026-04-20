<?php
// Hata Raporlama (Geliştirme aşamasında açık, canlıda kapatılabilir)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'baglanti.php';

// --- GÜVENLİK ---
if (!isset($_SESSION['kullanici_id']) || $_SESSION['rol'] != 'super_admin') {
    header("Location: index.php"); exit;
}

$admin_id = $_SESSION['kullanici_id'];
$mesaj = ""; $mesajTuru = "";
$mail_from = "noreply@ibircari.xyz"; 
$site_title = "ibiR Core";

// --- YARDIMCI FONKSİYONLAR ---

// 1. Admin Şifre Doğrulama
function adminDogrula($db, $uid, $pass) {
    $sorgu = $db->prepare("SELECT sifre FROM yoneticiler WHERE id = ?");
    $sorgu->execute([$uid]);
    // MD5 kullanıyorsan:
    return ($sorgu->fetchColumn() === md5($pass));
}

// 2. Gerçek E-Posta Gönderme (Standart Headerlar)
function sendMail($to, $subject, $message) {
    global $mail_from, $site_title;
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: \"$site_title\" <$mail_from>" . "\r\n";
    $headers .= "Reply-To: $mail_from" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // HTML Şablonu
    $htmlContent = '
    <html>
    <body style="font-family: sans-serif; background: #f4f4f4; padding: 20px;">
        <div style="max-width: 500px; margin: auto; background: #fff; padding: 20px; border-radius: 8px;">
            <h3 style="color: #333;">'.$site_title.'</h3>
            <hr style="border:0; border-top:1px solid #eee;">
            <div style="font-size: 15px; color: #555; line-height: 1.5;">
                '.$message.'
            </div>
        </div>
    </body>
    </html>';

    // Mail gönderimi (Hata bastırma operatörü @ ile)
    return @mail($to, $subject, $htmlContent, $headers);
}

// 3. Tarih Hesaplama
function sureEkle($mevcutTarih, $eklenecekAy, $eklenecekGun = 0) {
    // Null kontrolü
    $baslangic = ($mevcutTarih && $mevcutTarih > date('Y-m-d')) ? $mevcutTarih : date('Y-m-d');
    $date = new DateTime($baslangic);
    if($eklenecekAy > 0) $date->modify("+$eklenecekAy month");
    if($eklenecekGun > 0) $date->modify("+$eklenecekGun day");
    return $date->format('Y-m-d');
}

try {
    // ---------------- İŞLEMLER (POST) ----------------

    // 1. 2FA KOD GÖNDERME (SİLME İŞLEMİ İÇİN)
    if (isset($_POST['kod_gonder'])) {
        $hedef_firma = $_POST['silinecek_firma_id'];
        $kod = rand(100000, 999999);
        $_SESSION['2fa_code'] = $kod;
        $_SESSION['2fa_target'] = $hedef_firma;
        $_SESSION['2fa_expire'] = time() + 300; // 5 dk
        
        // Admin mailini bul
        $adminMailSorgu = $db->prepare("SELECT email FROM yoneticiler WHERE id = ?");
        $adminMailSorgu->execute([$admin_id]);
        $adminEmail = $adminMailSorgu->fetchColumn();

        if(sendMail($adminEmail, "Güvenlik Doğrulama Kodu", "<p>Firma silme işlemi için doğrulama kodunuz:</p><h1 style='letter-spacing:5px;'>$kod</h1>")) {
            $mesaj = "Doğrulama kodu e-posta adresinize ($adminEmail) gönderildi."; 
            $mesajTuru = "info";
        } else {
            $mesaj = "Mail gönderilemedi! Sunucu ayarlarını kontrol edin. (Test Kod: $kod)"; 
            $mesajTuru = "warning";
        }
        $open2FAModal = true;
    }

    // 2. KALICI SİLME (2FA ONAYLI)
    if (isset($_POST['kalici_sil_onayla'])) {
        if (isset($_SESSION['2fa_code']) && $_SESSION['2fa_code'] == $_POST['dogrulama_kodu'] && time() < $_SESSION['2fa_expire']) {
            $fid = $_SESSION['2fa_target'];
            $db->beginTransaction();
            try {
                // Bağlı tüm verileri temizle
                $tables = ['sistem_loglari', 'musteri_dosyalar', 'hareketler', 'musteriler', 'yoneticiler', 'firma_odemeler', 'destek_talepleri'];
                foreach($tables as $tbl) {
                    try {
                        $col = 'firma_id';
                        $db->prepare("DELETE FROM $tbl WHERE $col=?")->execute([$fid]);
                    } catch(Exception $e) {} // Tablo yoksa devam et
                }
                $db->prepare("DELETE FROM firmalar WHERE id=?")->execute([$fid]);
                
                $db->commit();
                $mesaj = "Firma ve tüm verileri kalıcı olarak silindi."; $mesajTuru = "success";
                unset($_SESSION['2fa_code']);
            } catch(Exception $e) { $db->rollBack(); $mesaj = "Hata: " . $e->getMessage(); $mesajTuru = "danger"; }
        } else { $mesaj = "Hatalı veya süresi dolmuş kod!"; $mesajTuru = "danger"; }
    }

    // 3. ŞİFRE SIFIRLAMA (ADMİN TARAFINDAN)
    if (isset($_POST['sifre_sifirla_admin'])) {
        $uid = $_POST['user_id'];
        $userMail = $db->query("SELECT email FROM yoneticiler WHERE id=$uid")->fetchColumn();
        
        $yeni_sifre = rand(100000,999999); // 6 haneli random şifre
        $yeni_sifre_hash = md5($yeni_sifre);
        
        $db->prepare("UPDATE yoneticiler SET sifre = ? WHERE id = ?")->execute([$yeni_sifre_hash, $uid]);
        
        // Mail Gönder
        $mailGovde = "<p>Hesap şifreniz yönetici tarafından sıfırlandı.</p><p>Yeni Şifreniz: <strong>$yeni_sifre</strong></p><p>Giriş yaptıktan sonra şifrenizi değiştirmeyi unutmayın.</p>";
        sendMail($userMail, "Şifreniz Sıfırlandı", $mailGovde);
        
        $mesaj = "Şifre sıfırlandı ve kullanıcıya ($userMail) mail atıldı. Yeni Şifre: <strong>$yeni_sifre</strong>";
        $mesajTuru = "success";
        $openProfileID = $_POST['firma_id'];
    }

    // 4. PERSONEL EKLEME
    if (isset($_POST['personel_ekle_admin'])) {
        $fid = $_POST['firma_id'];
        $email = $_POST['email'];
        
        $chk = $db->prepare("SELECT id FROM yoneticiler WHERE email = ?");
        $chk->execute([$email]);
        if($chk->rowCount() > 0) {
            $mesaj = "Bu e-posta adresi zaten kullanımda!"; $mesajTuru = "danger";
        } else {
            $sifre = $_POST['sifre'];
            $db->prepare("INSERT INTO yoneticiler (firma_id, kullanici_adi, email, sifre, rol, email_onayli) VALUES (?, ?, ?, ?, ?, 1)")
               ->execute([$fid, $_POST['kadi'], $email, md5($sifre), $_POST['rol']]);
            
            // Bilgi Maili
            sendMail($email, "Hesabınız Oluşturuldu", "<p>Sisteme kaydınız yapıldı.</p><p>Kullanıcı Adı: $_POST[kadi]<br>Şifre: $sifre</p>");
            
            $mesaj = "Kullanıcı eklendi ve bilgi maili gönderildi."; $mesajTuru = "success";
        }
        $openProfileID = $fid;
    }

    // 5. FİNANSAL İŞLEM (ÖDEME EKLEME)
    if (isset($_POST['odeme_ekle'])) {
        $fid = $_POST['firma_id'];
        
        // Firma ID Kontrolü (Güvenlik Önlemi)
        if(empty($fid) || $fid == 0) {
             $mesaj = "Hata: Firma ID'si alınamadı. İşlem iptal edildi."; 
             $mesajTuru = "danger";
        } else {
            $tutar = $_POST['tutar'];
            // Virgül varsa nokta yapalım (100,50 -> 100.50)
            $tutar = str_replace(',', '.', $tutar);
            
            $db->prepare("INSERT INTO firma_odemeler (firma_id, tutar, odeme_turu, aciklama) VALUES (?, ?, ?, ?)")
               ->execute([$fid, $tutar, $_POST['odeme_turu'], $_POST['aciklama']]);
            
            // Süre Uzatma Opsiyonu Seçildiyse
            if(isset($_POST['sure_uzat_opsiyon'])) {
                $mevcutSorgu = $db->prepare("SELECT abonelik_bitis FROM firmalar WHERE id = ?");
                $mevcutSorgu->execute([$fid]);
                $mevcut = $mevcutSorgu->fetchColumn();
                
                $yeni = sureEkle($mevcut, (int)$_POST['ek_ay']);
                $db->prepare("UPDATE firmalar SET abonelik_bitis=?, durum=1 WHERE id=?")->execute([$yeni, $fid]);
            }
            
            $mesaj = "Ödeme kaydı başarıyla eklendi."; $mesajTuru = "success";
            $openProfileID = $fid;
        }
    }

    // 6. TEKLİF AYARLARI (MEVCUT SÜTUNLAR)
    if (isset($_POST['teklif_ayar_guncelle'])) {
        $fid = $_POST['firma_id'];
        $db->prepare("UPDATE firmalar SET teklif_sartlari=?, teklif_alt_bilgi=? WHERE id=?")
           ->execute([$_POST['teklif_sartlari'], $_POST['teklif_alt_bilgi'], $fid]);
        $mesaj = "Firmanın teklif şablonu güncellendi."; $mesajTuru = "success";
        $openProfileID = $fid;
    }

    // 7. TOPLU BİLDİRİM GÖNDERME
    if (isset($_POST['bildirim_gonder'])) {
        $hedef = $_POST['hedef_kitle']; // all, 1month, 1week
        $icerik = $_POST['icerik'];
        $konu = $_POST['konu'];
        
        $sql = "SELECT f.firma_adi, y.email FROM firmalar f JOIN yoneticiler y ON f.id = y.firma_id WHERE f.durum = 1 AND y.rol = 'admin' AND f.id != 'IBIR-4247-ADMIN'";
        
        if($hedef == '1month') $sql .= " AND f.abonelik_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        if($hedef == '1week') $sql .= " AND f.abonelik_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        
        $alicilar = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $gonderilen = 0;
        
        foreach($alicilar as $alici) {
            $kisiselMesaj = str_replace("{firma}", $alici['firma_adi'], $icerik);
            if(sendMail($alici['email'], $konu, nl2br($kisiselMesaj))) {
                $gonderilen++;
            }
        }
        $mesaj = "$gonderilen firmaya e-posta gönderildi."; $mesajTuru = "success";
    }

    // 8. DUYURU İŞLEMLERİ
    if (isset($_POST['duyuru_ekle'])) {
        $db->prepare("INSERT INTO admin_duyurular (mesaj, tip) VALUES (?, ?)")->execute([$_POST['duyuru_metni'], $_POST['duyuru_tipi']]);
        $mesaj = "Duyuru başarıyla yayınlandı."; $mesajTuru = "success";
    }

    // Duyuru Silme
    if (isset($_GET['duyuru_sil'])) {
        $db->prepare("DELETE FROM admin_duyurular WHERE id=?")->execute([$_GET['duyuru_sil']]);
        header("Location: super_admin.php#tab-duyuru"); exit;
    }

    // 9. SÜRE VE DURUM (LİSTEDEN)
    if (isset($_POST['sure_uzat_auto']) && adminDogrula($db, $admin_id, $_POST['admin_sifre'])) {
        $yeni_tarih = sureEkle($_POST['mevcut_bitis'], (int)$_POST['ek_sure_ay'], (int)$_POST['ek_sure_gun_manuel']);
        $db->prepare("UPDATE firmalar SET abonelik_bitis=?, durum=? WHERE id=?")->execute([$yeni_tarih, ($yeni_tarih > date('Y-m-d')?1:0), $_POST['firma_id']]);
        $mesaj = "Süre uzatıldı. Yeni Bitiş: " . date("d.m.Y", strtotime($yeni_tarih)); $mesajTuru = "success";
    }
    if (isset($_POST['durum_degistir'])) {
        $db->prepare("UPDATE firmalar SET durum=? WHERE id=?")->execute([$_POST['yeni_durum'], $_POST['firma_id']]);
        $mesaj = "Firma durumu güncellendi."; $mesajTuru = "info";
    }
    if (isset($_POST['email_onayla_manuel'])) {
        $db->prepare("UPDATE yoneticiler SET email_onayli = 1 WHERE firma_id = ? AND rol = 'admin'")->execute([$_POST['firma_id']]);
        $mesaj = "E-Posta onayı tamamlandı."; $mesajTuru = "success";
    }

    // 3. FİRMA BİLGİLERİNİ GÜNCELLEME (YENİ)
    if (isset($_POST['firma_guncelle'])) {
        $fid = $_POST['firma_id'];
        $sql = "UPDATE firmalar SET firma_adi=?, yetkili_ad_soyad=?, telefon=?, adres=?, vergi_no=?, admin_ozel_notu=? WHERE id=?";
        $db->prepare($sql)->execute([
            $_POST['firma_adi'], $_POST['yetkili'], $_POST['telefon'], 
            $_POST['adres'], $_POST['vergi_no'], $_POST['admin_not'], $fid
        ]);
        $mesaj = "Firma bilgileri güncellendi."; $mesajTuru = "success";
        $openProfileID = $fid;
    }

    // 4. ÖDEME SİLME
    if (isset($_POST['odeme_sil'])) {
        $oid = $_POST['odeme_id'];
        $fid = $_POST['firma_id']; // Güvenlik ve geri dönüş için
        $db->prepare("DELETE FROM firma_odemeler WHERE id=?")->execute([$oid]);
        $mesaj = "Ödeme kaydı silindi."; $mesajTuru = "warning";
        $openProfileID = $fid;
    }

    // --- VERİ ÇEKME ---
    $filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'all'; 
    $log_limit = isset($_GET['log_limit']) ? (int)$_GET['log_limit'] : 50;
    
    // Loglar
    $logSQL = "SELECT l.*, y.kullanici_adi, f.firma_adi FROM sistem_loglari l LEFT JOIN yoneticiler y ON l.kullanici_id = y.id LEFT JOIN firmalar f ON l.firma_id = f.id";
    if(isset($_GET['filter_firma'])) $logSQL .= " WHERE l.firma_id = '{$_GET['filter_firma']}' ";
    $logSQL .= " ORDER BY l.tarih DESC LIMIT $log_limit";
    $loglar = $db->query($logSQL)->fetchAll(PDO::FETCH_ASSOC);

    // Firmalar (Null Coalescing ile hata önleme eklendi)
    $sqlFirmalar = "
        SELECT f.*, 
        (SELECT COUNT(*) FROM yoneticiler WHERE firma_id = f.id) as user_count,
        (SELECT COUNT(*) FROM musteriler WHERE firma_id = f.id) as musteri_count,
        (SELECT MAX(tarih) FROM sistem_loglari WHERE firma_id = f.id) as son_islem_tarihi,
        (SELECT email FROM yoneticiler WHERE firma_id = f.id AND rol = 'admin' LIMIT 1) as admin_email,
        (SELECT email_onayli FROM yoneticiler WHERE firma_id = f.id AND rol = 'admin' LIMIT 1) as email_onayli
        FROM firmalar f 
        WHERE f.id != 'IBIR-4247-ADMIN' 
        ORDER BY f.kayit_tarihi DESC
    ";
    $tumFirmalar = $db->query($sqlFirmalar)->fetchAll(PDO::FETCH_ASSOC);

    // İstatistikler (Hatalı değişken düzeltildi)
    $toplamFirma = count($tumFirmalar);
    $aktifFirmaSayisi = 0; $pasifFirmaSayisi = 0; $bitmekUzere = 0; $onaysizFirmaSayisi = 0; $toplamMusteri = 0;
    
    foreach($tumFirmalar as $tf) {
        if($tf['durum'] == 1) $aktifFirmaSayisi++; else $pasifFirmaSayisi++;
        if(isset($tf['email_onayli']) && $tf['email_onayli'] == 0) $onaysizFirmaSayisi++;
        
        $musteriSayisi = isset($tf['musteri_count']) ? (int)$tf['musteri_count'] : 0;
        $toplamMusteri += $musteriSayisi;

        if (isset($tf['abonelik_bitis']) && $tf['abonelik_bitis']) {
            $bitisTarihi = new DateTime($tf['abonelik_bitis']);
            $bugun = new DateTime();
            $kalanGun = $bugun->diff($bitisTarihi)->days;
            if($tf['durum'] == 1 && $bitisTarihi > $bugun && $kalanGun < 30) $bitmekUzere++;
        }
    }

    $duyurular = $db->query("SELECT * FROM admin_duyurular ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // --- DETAYLI PROFİL VERİSİ (MODAL) ---
    $show_profile = false; $profile_data = [];
    $target_fid = isset($_GET['view_profile']) ? $_GET['view_profile'] : (isset($openProfileID) ? $openProfileID : null);

    if($target_fid) {
        $show_profile = true;
        foreach($tumFirmalar as $tf) { if($tf['id'] == $target_fid) { $profile_data['info'] = $tf; break; } }
        
        if (isset($profile_data['info'])) {
            // Kullanıcılar
            $stmtUsers = $db->prepare("SELECT * FROM yoneticiler WHERE firma_id = ?"); $stmtUsers->execute([$target_fid]);
            $profile_data['users'] = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

            // Müşteriler
            $stmtMust = $db->prepare("SELECT * FROM musteriler WHERE firma_id = ? ORDER BY id DESC LIMIT 20"); $stmtMust->execute([$target_fid]);
            $profile_data['musteriler'] = $stmtMust->fetchAll(PDO::FETCH_ASSOC);

            // Ödemeler (Hata vermemesi için try-catch)
            try {
                $stmtOde = $db->prepare("SELECT * FROM firma_odemeler WHERE firma_id = ? ORDER BY tarih DESC");
                $stmtOde->execute([$target_fid]);
                $profile_data['odemeler'] = $stmtOde->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e) { $profile_data['odemeler'] = []; }

            // Finansal Özet (Tahmini)
            $stmtFin = $db->prepare("SELECT 
                SUM(CASE WHEN islem_turu='satis' THEN toplam_tutar ELSE 0 END) as toplam_satis,
                SUM(CASE WHEN islem_turu='tahsilat' THEN toplam_tutar ELSE 0 END) as toplam_tahsilat
                FROM hareketler WHERE firma_id = ?");
            $stmtFin->execute([$target_fid]);
            $profile_data['finans'] = $stmtFin->fetch(PDO::FETCH_ASSOC);

            // Destek Talepleri (Firmaya Özel)
            try {
                $stmtDestek = $db->prepare("SELECT * FROM destek_talepleri WHERE firma_id = ? ORDER BY tarih DESC");
                $stmtDestek->execute([$target_fid]);
                $profile_data['destek'] = $stmtDestek->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e) { $profile_data['destek'] = []; }
        } else {
            $show_profile = false; 
        }
    }

} catch (Exception $ex) { die('<div class="alert alert-danger m-5">Sistem Hatası: '.$ex->getMessage().'</div>'); }
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>ibiR Core | Yönetim Paneli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4e73df; --secondary: #858796; --success: #1cc88a; --info: #36b9cc; --warning: #f6c23e; --danger: #e74a3b; --dark: #5a5c69; }
        body { background-color: #f3f4f6; font-family: 'Segoe UI', sans-serif; font-size: 0.9rem; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; border-left: 5px solid var(--primary); box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: 0.3s; text-decoration: none; color: inherit; display: block; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .stat-title { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--primary); margin-bottom: 5px; }
        .stat-value { font-size: 1.8rem; font-weight: 800; color: #333; }
        
        .table-custom thead th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; color: #8898aa; background-color: #f8f9fc; border-bottom: 1px solid #e3e6f0; }
        .table-custom td { vertical-align: middle; border-bottom: 1px solid #f0f0f0; padding: 12px; }
        
        /* Modal Profil (Sadeleştirilmiş XL) */
        .modal-xl { max-width: 1140px; }
        .profile-header { background-color: white; border-bottom: 1px solid #e3e6f0; padding: 20px; display: flex; align-items: center; justify-content: space-between; }
        
        .nav-tabs-profile .nav-link { border: none; color: #666; font-weight: 600; padding: 15px 20px; border-bottom: 3px solid transparent; }
        .nav-tabs-profile .nav-link.active { color: var(--primary); border-bottom: 3px solid var(--primary); background: transparent; }
        .nav-tabs-profile .nav-link:hover { color: var(--primary); }
        
        .info-group { margin-bottom: 15px; }
        .info-group label { font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 3px; }
        .info-group div { font-weight: 600; color: #333; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand navbar-dark bg-dark px-4 shadow-sm sticky-top">
        <a class="navbar-brand fw-bold" href="super_admin.php"><i class="fas fa-rocket me-2"></i>ibiR Yönetim</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="super_admin.php"><i class="fas fa-home me-1"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link text-warning" href="super_yedek.php?hedef_firma=ALL" target="_blank"><i class="fas fa-database me-1"></i> Yedekle</a></li>
            </ul>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalBildirim"><i class="fas fa-envelope me-1"></i> Toplu Bildirim</button>
                <a href="cikis.php" class="btn btn-outline-danger btn-sm rounded-circle"><i class="fas fa-power-off"></i></a>
            </div>
        </div>
    </nav>

    <div class="container-fluid p-4">
        
        <?php if($mesaj): ?>
            <div class="alert alert-<?php echo $mesajTuru; ?> shadow-sm mb-4 border-0 border-start border-5 border-<?php echo $mesajTuru; ?> alert-dismissible fade show">
                <?php echo $mesaj; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- İSTATİSTİKLER -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6"><a href="?filter_status=active" class="stat-card" style="border-left-color: var(--success);"><div class="stat-title text-success">Aktif Firmalar</div><div class="stat-value"><?php echo $aktifFirmaSayisi; ?></div><div class="small text-muted mt-2"><i class="fas fa-check-circle"></i> Sistemde aktif çalışan</div></a></div>
            <div class="col-xl-3 col-md-6"><a href="?filter_status=passive" class="stat-card" style="border-left-color: var(--warning);"><div class="stat-title text-warning">Riskli / Bitiyor</div><div class="stat-value"><?php echo $bitmekUzere; ?></div><div class="small text-muted mt-2"><i class="fas fa-clock"></i> 30 günden az kalan</div></a></div>
            <div class="col-xl-3 col-md-6"><a href="?filter_status=unverified" class="stat-card" style="border-left-color: var(--danger);"><div class="stat-title text-danger">Onay Bekleyen</div><div class="stat-value"><?php echo $onaysizFirmaSayisi; ?></div><div class="small text-muted mt-2"><i class="fas fa-envelope"></i> E-Posta onayı yok</div></a></div>
            <div class="col-xl-3 col-md-6"><div class="stat-card" style="border-left-color: var(--info);"><div class="stat-title text-info">Toplam Müşteri</div><div class="stat-value"><?php echo number_format((float)$toplamMusteri); ?></div><div class="small text-muted mt-2"><i class="fas fa-users"></i> Tüm firmaların toplamı</div></div></div>
        </div>

        <!-- ANA PANO -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-0">
                <ul class="nav nav-tabs nav-tabs-profile card-header-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-firmalar"><i class="fas fa-building me-2"></i>Firma Listesi</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-destek"><i class="fas fa-headset me-2"></i>Destek Merkezi</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-duyuru"><i class="fas fa-bullhorn me-2"></i>Duyurular</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cop"><i class="fas fa-trash me-2"></i>Çöp Kutusu (<?php echo $pasifFirmaSayisi; ?>)</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-log"><i class="fas fa-list me-2"></i>Loglar</button></li>
                </ul>
            </div>
            <div class="card-body p-0">
                <div class="tab-content">
                    
                    <!-- FİRMALAR -->
                    <div class="tab-pane fade show active" id="tab-firmalar">
                        <div class="table-responsive">
                            <table class="table table-custom table-hover align-middle mb-0">
                                <thead><tr><th class="ps-4">Firma Adı</th><th>Yetkili</th><th>İletişim / Vergi</th><th>Durum</th><th>Bitiş Tarihi</th><th class="text-end pe-4">İşlem</th></tr></thead>
                                <tbody>
                                    <?php foreach($tumFirmalar as $f): if($f['durum']==0) continue; ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light rounded p-2 me-3 text-primary fw-bold border"><?php echo strtoupper(substr($f['firma_adi'] ?? '?', 0, 2)); ?></div>
                                                <div>
                                                    <a href="?view_profile=<?php echo $f['id']; ?>" class="fw-bold text-dark text-decoration-none"><?php echo $f['firma_adi'] ?? 'İsimsiz Firma'; ?></a>
                                                    <div class="small text-muted">ID: <?php echo $f['id']; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo $f['yetkili_ad_soyad'] ?? '-'; ?></div>
                                            <?php if(isset($f['email_onayli']) && $f['email_onayli']): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success"><i class="fas fa-check"></i> Onaylı</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning bg-opacity-10 text-warning"><i class="fas fa-clock"></i> Onaysız</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small"><i class="fas fa-phone me-1 text-muted"></i> <?php echo $f['telefon'] ?? '-'; ?></div>
                                            <div class="small text-muted">VN: <?php echo $f['vergi_no'] ?: '-'; ?></div>
                                        </td>
                                        <td><span class="badge bg-success">Aktif</span></td>
                                        <td>
                                            <?php 
                                                $bitisStr = $f['abonelik_bitis'] ?? null;
                                                if ($bitisStr) {
                                                    $bitis = new DateTime($bitisStr);
                                                    $renk = ($bitis < new DateTime()) ? 'text-danger' : 'text-success';
                                                    echo '<div class="'.$renk.' fw-bold">'.date("d.m.Y", strtotime($bitisStr)).'</div>';
                                                } else { echo '<span class="text-muted">-</span>'; }
                                            ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="?view_profile=<?php echo $f['id']; ?>" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm"><i class="fas fa-cog me-1"></i> Yönet</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- DESTEK -->
                    <div class="tab-pane fade" id="tab-destek">
                        <div class="p-5 text-center">
                            <i class="fas fa-headset fa-3x text-muted mb-3 opacity-50"></i>
                            <h5>Destek Merkezi</h5>
                            <p class="text-muted">Talepleri yönetmek için destek paneline gidin.</p>
                            <a href="destekler.php" class="btn btn-primary px-4">Destek Paneline Git <i class="fas fa-arrow-right ms-2"></i></a>
                        </div>
                    </div>

                    <!-- DİĞER SEKMELER (DUYURU, ÇÖP, LOG) -->
                    <!-- DUYURULAR SEKMESİ -->
<div class="tab-pane fade" id="tab-duyuru">
    <div class="row g-0">
        <!-- Duyuru Listesi -->
        <div class="col-md-8 p-4 border-end">
            <h6 class="fw-bold mb-3"><i class="fas fa-list me-2 text-primary"></i>Aktif Duyurular</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle table-hover">
                    <thead class="bg-light">
                        <tr>
                            <th width="100">Tür</th>
                            <th>Mesaj</th>
                            <th class="text-end" width="80">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($duyurular) > 0): ?>
                            <?php foreach($duyurular as $d): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?php echo $d['tip']; ?> w-100">
                                        <?php echo strtoupper($d['tip']); ?>
                                    </span>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($d['mesaj']); ?></td>
                                <td class="text-end">
                                    <a href="?duyuru_sil=<?php echo $d['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger border-0" 
                                       onclick="return confirm('Bu duyuruyu kaldırmak istediğinize emin misiniz?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center py-5 text-muted italic">
                                    <i class="fas fa-bell-slash fa-2x mb-2 opacity-25"></i><br>
                                    Yayında olan bir duyuru bulunmuyor.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Yeni Duyuru Ekleme Formu -->
        <div class="col-md-4 p-4 bg-light">
            <h6 class="fw-bold mb-3"><i class="fas fa-plus-circle me-2 text-success"></i>Yeni Duyuru Yayınla</h6>
            <form method="POST" class="card p-3 border-0 shadow-sm">
                <input type="hidden" name="duyuru_ekle" value="1">
                <div class="mb-3">
                    <label class="small fw-bold mb-1">Duyuru Metni</label>
                    <textarea name="duyuru_metni" class="form-control" rows="4" placeholder="Tüm firma panellerinde görünecek mesaj..." required></textarea>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold mb-1">Vurgu Rengi (Tip)</label>
                    <select name="duyuru_tipi" class="form-select">
                        <option value="info">Mavi (Bilgi Notu)</option>
                        <option value="warning">Sarı (Uyarı/Hatırlatma)</option>
                        <option value="danger">Kırmızı (Kritik Duyuru)</option>
                        <option value="success">Yeşil (Güncelleme/Haber)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-100 shadow-sm">
                    <i class="fas fa-paper-plane me-2"></i>Duyuruyu Yayınla
                </button>
            </form>
            <div class="mt-3 p-2 bg-white rounded border small text-muted">
                <i class="fas fa-info-circle me-1 text-info"></i> 
                Yayınlanan duyurular, tüm firmaların yönetim panelinde en üst kısımda (banner olarak) görünür.
            </div>
        </div>
    </div>
</div>
                    <div class="tab-pane fade" id="tab-cop">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light"><tr><th class="ps-4">Firma</th><th>Durum</th><th class="text-end pe-4">İşlem</th></tr></thead>
                                <tbody>
                                    <?php foreach($tumFirmalar as $f): if($f['durum']==1) continue; ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-decoration-line-through text-muted"><?php echo $f['firma_adi'] ?? '-'; ?></div>
                                            <div class="small text-muted">ID: <?php echo $f['id']; ?></div>
                                        </td>
                                        <td><span class="badge bg-secondary">Pasif / Silinmiş</span></td>
                                        <td class="text-end pe-4">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="durum_degistir" value="1">
                                                <input type="hidden" name="yeni_durum" value="1">
                                                <input type="hidden" name="firma_id" value="<?php echo $f['id']; ?>">
                                                <button class="btn btn-sm btn-success"><i class="fas fa-undo"></i> Aktif Et</button>
                                            </form>
                                            <button class="btn btn-sm btn-danger ms-2" onclick="kaliciSilBaslat('<?php echo $f['id']; ?>', '<?php echo htmlspecialchars($f['firma_adi']); ?>')">
                                                <i class="fas fa-trash-alt"></i> Sil
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-log">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped small mb-0">
                                <thead class="table-dark"><tr><th>Tarih</th><th>Firma</th><th>Kullanıcı</th><th>İşlem</th><th>Detay</th></tr></thead>
                                <tbody>
                                    <?php foreach($loglar as $l): ?>
                                    <tr>
                                        <td><?php echo date("d.m H:i", strtotime($l['tarih'] ?? 'now')); ?></td>
                                        <td><?php echo htmlspecialchars($l['firma_adi'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($l['kullanici_adi'] ?? '-'); ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($l['islem'] ?? '-'); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($l['detay'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: FİRMA YÖNETİM KARTI (FULL DÜZELTİLMİŞ) -->
    <?php if($show_profile && isset($profile_data['info'])): $p = $profile_data['info']; ?>
    <div class="modal fade show" id="modalProfile" tabindex="-1" style="display:block; background:rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-scrollable modal-xl">
            <div class="modal-content border-0 shadow-lg bg-light">
                
                <!-- BAŞLIK -->
                <div class="profile-header">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary text-white rounded p-3 me-3 h2 mb-0"><?php echo strtoupper(substr($p['firma_adi'] ?? '?', 0, 1)); ?></div>
                        <div>
                            <h4 class="mb-0 fw-bold"><?php echo htmlspecialchars($p['firma_adi'] ?? ''); ?></h4>
                            <div class="text-muted small">
                                <span class="me-3 badge bg-white text-dark border">ID: <?php echo $p['id']; ?></span>
                                <span class="me-3">Vergi: <?php echo $p['vergi_no'] ?: '-'; ?></span>
                                <span class="badge <?php echo $p['durum']==1?'bg-success':'bg-danger'; ?>"><?php echo $p['durum']==1?'AKTİF':'PASIF'; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-dark btn-sm" onclick="window.location.href='super_admin.php'"><i class="fas fa-times me-1"></i> Kapat</button>
                    </div>
                </div>
                
                <div class="modal-body p-0">
                    <div class="row g-0">
                        <!-- SOL MENÜ -->
                        <div class="col-md-3 bg-white border-end">
                            <div class="p-3">
                                <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist">
                                    <!-- ID'ler v-pills-... olarak standartlaştırıldı -->
                                    <button class="nav-link active text-start mb-1" data-bs-toggle="pill" data-bs-target="#v-pills-ozet"><i class="fas fa-info-circle me-2 w-20"></i> Genel Özet</button>
                                    <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#v-pills-duzenle"><i class="fas fa-pen me-2 w-20"></i> Bilgileri Düzenle</button>
                                    <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#v-pills-finans"><i class="fas fa-wallet me-2 w-20"></i> Finans & Ödeme</button>
                                    <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#v-pills-ekip"><i class="fas fa-users me-2 w-20"></i> Ekip & Kullanıcılar</button>
                                    <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#v-pills-destek"><i class="fas fa-headset me-2 w-20"></i> Destek Talepleri</button>
                                    <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#v-pills-ayarlar"><i class="fas fa-cogs me-2 w-20"></i> Teklif Ayarları</button>
                                    <hr>
                                    <button class="nav-link text-start text-danger mb-1" onclick="if(confirm('Emin misiniz?')) kaliciSilBaslat('<?php echo $p['id']; ?>','<?php echo htmlspecialchars($p['firma_adi']); ?>')"><i class="fas fa-trash me-2 w-20"></i> Firmayı Sil</button>
                                </div>
                            </div>
                        </div>

                        <!-- SAĞ İÇERİK -->
                        <div class="col-md-9 bg-light">
                            <div class="tab-content p-4" id="v-pills-tabContent">
                                
                                <!-- 1. ÖZET -->
                                <div class="tab-pane fade show active" id="v-pills-ozet">
                                    <h6 class="fw-bold text-muted mb-3">FİRMA BİLGİLERİ</h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="card p-3 border-0 shadow-sm h-100">
                                                <div class="text-muted small">YETKİLİ KİŞİ</div>
                                                <div class="fw-bold"><?php echo $p['yetkili_ad_soyad']; ?></div>
                                                <div class="small mt-2"><i class="fas fa-phone me-1"></i> <?php echo $p['telefon']; ?></div>
                                                <div class="small"><i class="fas fa-envelope me-1"></i> <?php echo $p['admin_email']; ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card p-3 border-0 shadow-sm h-100">
                                                <div class="text-muted small">ABONELİK DURUMU</div>
                                                <div class="fw-bold text-primary fs-5"><?php echo date("d.m.Y", strtotime($p['abonelik_bitis'])); ?></div>
                                                <div class="mt-2">
                                                    <button class="btn btn-sm btn-outline-success" onclick="openSureModal('<?php echo $p['id']; ?>','<?php echo $p['abonelik_bitis']; ?>')">+ Süre Ekle</button>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Durum değişecek?');">
                                                        <input type="hidden" name="durum_degistir" value="1"><input type="hidden" name="yeni_durum" value="<?php echo $p['durum']==1?0:1; ?>"><input type="hidden" name="firma_id" value="<?php echo $p['id']; ?>"><input type="hidden" name="is_modal" value="1">
                                                        <button class="btn btn-sm btn-light border"><?php echo $p['durum']==1?'Dondur':'Aktif Et'; ?></button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="card p-3 border-0 shadow-sm bg-warning bg-opacity-10">
                                                <div class="text-muted small text-dark">ADMİN ÖZEL NOTU</div>
                                                <p class="mb-0 small"><?php echo $p['admin_ozel_notu'] ?: 'Not girilmemiş.'; ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 2. DÜZENLEME -->
                                <div class="tab-pane fade" id="v-pills-duzenle">
                                    <h6 class="fw-bold mb-3">FİRMA BİLGİLERİNİ GÜNCELLE</h6>
                                    <form method="POST" class="card p-4 border-0 shadow-sm">
                                        <input type="hidden" name="firma_guncelle" value="1">
                                        <input type="hidden" name="firma_id" value="<?php echo $p['id']; ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6"><label class="small fw-bold">Firma Adı</label><input type="text" name="firma_adi" class="form-control" value="<?php echo $p['firma_adi']; ?>"></div>
                                            <div class="col-md-6"><label class="small fw-bold">Yetkili</label><input type="text" name="yetkili" class="form-control" value="<?php echo $p['yetkili_ad_soyad']; ?>"></div>
                                            <div class="col-md-6"><label class="small fw-bold">Telefon</label><input type="text" name="telefon" class="form-control" value="<?php echo $p['telefon']; ?>"></div>
                                            <div class="col-md-6"><label class="small fw-bold">Vergi No</label><input type="text" name="vergi_no" class="form-control" value="<?php echo $p['vergi_no']; ?>"></div>
                                            <div class="col-12"><label class="small fw-bold">Adres</label><textarea name="adres" class="form-control" rows="2"><?php echo $p['adres']; ?></textarea></div>
                                            <div class="col-12"><label class="small fw-bold text-danger">Admin Özel Notu</label><textarea name="admin_not" class="form-control" rows="2" placeholder="Sadece adminler görür..."><?php echo $p['admin_ozel_notu']; ?></textarea></div>
                                            <div class="col-12 text-end"><button class="btn btn-primary">Kaydet</button></div>
                                        </div>
                                    </form>
                                </div>

                                <!-- 3. FİNANS (DÜZELTİLEN KISIM) -->
                                <div class="tab-pane fade" id="v-pills-finans">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card border-0 shadow-sm h-100 p-3">
                                                <div class="small text-muted">TOPLAM SATIŞ (Cari)</div>
                                                <div class="h4 fw-bold text-success"><?php echo number_format($profile_data['finans']['toplam_satis'] ?? 0, 2); ?> ₺</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card border-0 shadow-sm h-100 p-3">
                                                <div class="small text-muted">TOPLAM TAHSİLAT (Cari)</div>
                                                <div class="h4 fw-bold text-primary"><?php echo number_format($profile_data['finans']['toplam_tahsilat'] ?? 0, 2); ?> ₺</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-7">
                                            <h6 class="fw-bold mb-3">Ödeme Geçmişi (Aidat)</h6>
                                            <div class="card border-0 shadow-sm p-0 overflow-hidden">
                                                <table class="table table-sm table-striped mb-0">
                                                    <thead class="bg-light"><tr><th>Tarih</th><th>Tutar</th><th>Yöntem</th><th>Açıklama</th><th></th></tr></thead>
                                                    <tbody>
                                                        <?php 
                                                        $odemeVar = false;
                                                        if(!empty($profile_data['odemeler'])):
                                                            foreach($profile_data['odemeler'] as $ode): 
                                                                // ID KONTROLÜ (Başkasının ödemesini gösterme)
                                                                if($ode['firma_id'] != $p['id']) continue;
                                                                $odemeVar = true;
                                                        ?>
                                                        <tr>
                                                            <td><?php echo date("d.m.Y", strtotime($ode['tarih'])); ?></td>
                                                            <td class="fw-bold text-success"><?php echo number_format($ode['tutar'], 2); ?> TL</td>
                                                            <td><?php echo htmlspecialchars($ode['odeme_turu']); ?></td>
                                                            <td><?php echo htmlspecialchars($ode['aciklama']); ?></td>
                                                            <td class="text-end">
                                                                <form method="POST" onsubmit="return confirm('Bu ödeme kaydını silmek istediğinize emin misiniz?');" class="m-0">
                                                                    <input type="hidden" name="odeme_sil" value="1">
                                                                    <input type="hidden" name="odeme_id" value="<?php echo $ode['id']; ?>">
                                                                    <input type="hidden" name="firma_id" value="<?php echo $p['id']; ?>">
                                                                    <button class="btn btn-sm text-danger p-0 border-0"><i class="fas fa-trash"></i></button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; endif; ?>
                                                        <?php if(!$odemeVar): ?><tr><td colspan="5" class="text-center text-muted py-3 small">Ödeme kaydı bulunamadı.</td></tr><?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-md-5">
                                            <h6 class="fw-bold mb-3">Yeni Ödeme Ekle</h6>
                                            <form method="POST" class="card border-0 shadow-sm p-3">
                                                <input type="hidden" name="odeme_ekle" value="1">
                                                
                                                <!-- KRİTİK: Doğru firma ID'sini buraya basıyoruz -->
                                                <input type="hidden" name="firma_id" value="<?php echo $p['id']; ?>">
                                                
                                                <div class="mb-2"><input type="number" step="0.01" name="tutar" class="form-control" placeholder="Tutar (TL)" required></div>
                                                <div class="mb-2">
                                                    <select name="odeme_turu" class="form-select">
                                                        <option>Banka Havalesi</option><option>Kredi Kartı</option><option>Nakit</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3"><input type="text" name="aciklama" class="form-control" placeholder="Açıklama (Örn: Yıllık Lisans)"></div>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="sure_uzat_opsiyon" value="1" id="autoExt">
                                                    <label class="form-check-label small" for="autoExt">Süreyi de uzat</label>
                                                </div>
                                                <div id="autoExtDiv" style="display:none;" class="mb-3">
                                                    <select name="ek_ay" class="form-select form-select-sm"><option value="1">+1 Ay</option><option value="12" selected>+1 Yıl</option></select>
                                                </div>
                                                <button class="btn btn-success w-100">Kaydet</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- 4. EKİP -->
                                <div class="tab-pane fade" id="v-pills-ekip">
                                    <div class="row g-4">
                                        <div class="col-md-8">
                                            <h6 class="fw-bold mb-3 text-secondary">Kullanıcı Listesi</h6>
                                            <div class="table-responsive border rounded">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead class="bg-light"><tr><th>Kullanıcı</th><th>E-Posta</th><th>Rol</th><th>İşlem</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach($profile_data['users'] as $u): ?>
                                                        <tr>
                                                            <td class="fw-bold"><?php echo $u['kullanici_adi']; ?></td>
                                                            <td class="small"><?php echo $u['email']; ?></td>
                                                            <td><span class="badge bg-secondary text-light"><?php echo $u['rol']; ?></span></td>
                                                            <td>
                                                                <form method="POST" onsubmit="return confirm('Şifre sıfırlanıp mail atılacak. Emin misiniz?');" style="margin:0;">
                                                                    <input type="hidden" name="sifre_sifirla_admin" value="1">
                                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                                    <input type="hidden" name="firma_id" value="<?php echo $p['id']; ?>">
                                                                    <button class="btn btn-sm btn-outline-warning py-0" style="font-size:11px;">Şifre Sıfırla</button>
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
                                                <h6 class="fw-bold mb-3">Yeni Kullanıcı</h6>
                                                <form method="POST">
                                                    <input type="hidden" name="personel_ekle_admin" value="1">
                                                    <input type="hidden" name="firma_id" value="<?php echo $p['id']; ?>">
                                                    <div class="mb-2"><input type="text" name="kadi" class="form-control" placeholder="Kullanıcı Adı" required></div>
                                                    <div class="mb-2"><input type="email" name="email" class="form-control" placeholder="E-Posta" required></div>
                                                    <div class="mb-2"><input type="password" name="sifre" class="form-control" placeholder="Şifre" required></div>
                                                    <div class="mb-3">
                                                        <select name="rol" class="form-select">
                                                            <option value="personel">Personel</option>
                                                            <option value="admin">Yönetici</option>
                                                        </select>
                                                    </div>
                                                    <button class="btn btn-primary w-100">Oluştur</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 5. DESTEK -->
                                <div class="tab-pane fade" id="v-pills-destek">
                                    <h6 class="fw-bold mb-3">Firma Destek Talepleri</h6>
                                    <div class="card border-0 shadow-sm p-3">
                                        <?php if(empty($profile_data['destek'])): ?>
                                            <p class="text-muted text-center mb-0">Talebi yok.</p>
                                        <?php else: ?>
                                            <?php foreach($profile_data['destek'] as $dt): ?>
                                                <div class="border-bottom pb-3 mb-3">
                                                    <div class="d-flex justify-content-between">
                                                        <strong><?php echo htmlspecialchars($dt['konu']); ?></strong>
                                                        <span class="badge bg-<?php echo $dt['durum']=='yanitlandi'?'success':'warning'; ?>"><?php echo $dt['durum']; ?></span>
                                                    </div>
                                                    <p class="small text-muted mb-2"><?php echo htmlspecialchars($dt['mesaj']); ?></p>
                                                    
                                                    <?php if($dt['cevap']): ?>
                                                        <div class="bg-light p-2 rounded small border-start border-3 border-primary">
                                                            <strong>Cevap:</strong> <?php echo $dt['cevap']; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <form method="POST" class="mt-2">
                                                            <input type="hidden" name="destek_yanitla" value="1">
                                                            <input type="hidden" name="talep_id" value="<?php echo $dt['id']; ?>">
                                                            <input type="hidden" name="firma_id" value="<?php echo $p['id']; ?>">
                                                            <div class="input-group input-group-sm">
                                                                <input type="text" name="cevap" class="form-control" placeholder="Yanıtınız...">
                                                                <button class="btn btn-primary">Gönder</button>
                                                            </div>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- 6. AYARLAR -->
                                <div class="tab-pane fade" id="v-pills-ayarlar">
                                    <h6 class="fw-bold mb-3">Teklif & Şablon Ayarları</h6>
                                    <form method="POST" class="card border-0 shadow-sm p-3">
                                        <input type="hidden" name="teklif_ayar_guncelle" value="1">
                                        <input type="hidden" name="firma_id" value="<?php echo $p['id']; ?>">
                                        <div class="mb-3">
                                            <label class="small fw-bold">Teklif Şartları (Üst)</label>
                                            <textarea name="teklif_sartlari" class="form-control" rows="4"><?php echo $p['teklif_sartlari'] ?? ''; ?></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="small fw-bold">Teklif Alt Bilgi</label>
                                            <textarea name="teklif_alt_bilgi" class="form-control" rows="4"><?php echo $p['teklif_alt_bilgi'] ?? ''; ?></textarea>
                                        </div>
                                        <div class="text-end"><button class="btn btn-primary">Güncelle</button></div>
                                    </form>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2FA & SÜRE MODALLARI -->
    <div class="modal fade" id="modal2FA" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST"><input type="hidden" name="kalici_sil_onayla" value="1"><div class="modal-header bg-danger text-white"><h5 class="modal-title">Güvenlik Kodu</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><p>E-Postanıza gelen kodu giriniz:</p><input type="text" name="dogrulama_kodu" class="form-control text-center fs-2" required></div><div class="modal-footer"><button class="btn btn-danger w-100">Silmeyi Onayla</button></div></form></div></div></div>
    <form method="POST" id="form2FABaslat"><input type="hidden" name="kod_gonder" value="1"><input type="hidden" name="silinecek_firma_id" id="sil_hedef_id"></form>
    
    <div class="modal fade" id="modalSure" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST"><input type="hidden" name="sure_uzat_auto" value="1"><input type="hidden" name="is_modal" value="1"><input type="hidden" name="firma_id" id="sure_fid"><input type="hidden" name="mevcut_bitis" id="sure_mevcut"><div class="modal-header bg-success text-white"><h5 class="modal-title">Süre Uzat</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="btn-group w-100 mb-3"><input type="radio" class="btn-check" name="ek_sure_ay" value="1" id="m1" checked onclick="document.getElementById('manualDay').style.display='none'"><label class="btn btn-outline-success" for="m1">+1 Ay</label><input type="radio" class="btn-check" name="ek_sure_ay" value="12" id="m12" onclick="document.getElementById('manualDay').style.display='none'"><label class="btn btn-outline-success" for="m12">+1 Yıl</label><input type="radio" class="btn-check" name="ek_sure_ay" value="0" id="m0" onclick="document.getElementById('manualDay').style.display='block'"><label class="btn btn-outline-secondary" for="m0">Manuel</label></div><div id="manualDay" style="display:none;" class="mb-3"><input type="number" name="ek_sure_gun_manuel" class="form-control" placeholder="Gün Sayısı"></div><label>Admin Şifresi:</label><input type="password" name="admin_sifre" class="form-control" required></div><div class="modal-footer"><button class="btn btn-success w-100">Onayla</button></div></form></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openSureModal(id, mevcut) {
            document.getElementById('sure_fid').value = id;
            document.getElementById('sure_mevcut').value = mevcut;
            new bootstrap.Modal(document.getElementById('modalSure')).show();
        }
        function kaliciSilBaslat(id, isim) {
            if(confirm(isim + " firması KALICI olarak silinecek. Kod gönderilsin mi?")) {
                document.getElementById('sil_hedef_id').value = id;
                document.getElementById('form2FABaslat').submit();
            }
        }
        
        const autoExt = document.getElementById('autoExt');
        if(autoExt) {
            autoExt.addEventListener('change', function() {
                document.getElementById('autoExtDiv').style.display = this.checked ? 'block' : 'none';
            });
        }

        <?php if(isset($open2FAModal) && $open2FAModal): ?>
            new bootstrap.Modal(document.getElementById('modal2FA')).show();
        <?php endif; ?>
    </script>
</body>
</html>