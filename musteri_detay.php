<?php
session_start();
require 'baglanti.php';
require_once 'partials/security_check.php';

$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

$page_title = "Müşteri CRM & Detay";
$hataMesaji = ""; 
$basariMesaji = "";
$id = 0; 
$firma_id = $_SESSION['firma_id'];
$kullanici_id = $_SESSION['kullanici_id'];

if (isset($_GET['t']) && !empty($_GET['t'])) {
    $token = trim($_GET['t']);
    $bul = $db->prepare("SELECT id FROM musteriler WHERE url_token = ? AND firma_id = ?");
    $bul->execute([$token, $firma_id]);
    $kayit = $bul->fetch(PDO::FETCH_ASSOC);
    
    if ($kayit) { $id = $kayit['id']; } 
    else { die("<div class='container mt-5 text-center'><h1>⛔</h1><h3>Geçersiz Bağlantı veya Yetkisiz Erişim</h3></div>"); }
} else {
    header("Location: musteriler.php");
    exit;
}

$musteriIlk = $db->prepare("SELECT * FROM musteriler WHERE id = ?");
$musteriIlk->execute([$id]);
$musteriIlk = $musteriIlk->fetch(PDO::FETCH_ASSOC);

function sef_link($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', ' '], ['i', 'g', 'u', 's', 'o', 'c', '_'], $str);
    return preg_replace('/[^a-z0-9_]/', '', $str);
}

function yoneticiSifreDogrula($db, $kullanici_id, $girilen_sifre, $firma_id) {
    $sorgu = $db->prepare("SELECT sifre FROM yoneticiler WHERE id = ? AND firma_id = ?");
    $sorgu->execute([$kullanici_id, $firma_id]);
    $hash = $sorgu->fetchColumn();
    if($hash) { return (password_verify($girilen_sifre, $hash) || md5($girilen_sifre) === $hash); }
    return false;
}

// Firma Vade Oranlarını Çek (Sihirbaz İçin)
$firmaSorgu = $db->prepare("SELECT vade_farki_oranlari FROM firmalar WHERE id = ?");
$firmaSorgu->execute([$firma_id]);
$vadeOranlariJson = $firmaSorgu->fetchColumn();
$vadeOranlari = !empty($vadeOranlariJson) ? json_decode($vadeOranlariJson, true) : [];

// --- İŞLEMLER (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (function_exists('sanitizeInput')) {
        // $_POST = sanitizeInput($_POST); Rich Text editör html gönderdiği için genel temizlik yapmıyoruz, manual yapacağız
    }
    
    // DOSYA YÜKLEME (Fatura / Evrak vs)
    if (isset($_FILES['dosya']) && $_FILES['dosya']['error'] == 0) {
        $izinliler = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'doc', 'xls', 'xlsx'];
        $dosyaAdi = $_FILES['dosya']['name'];
        $uzanti = strtolower(pathinfo($dosyaAdi, PATHINFO_EXTENSION));
        
        if (in_array($uzanti, $izinliler)) {
            $klasorAdi = sef_link($musteriIlk['ad_soyad']) . "_" . $id;
            $hedefKlasor = "uploads/" . $klasorAdi;
            if (!file_exists($hedefKlasor)) { @mkdir($hedefKlasor, 0777, true); }
            $yeniAd = "belge_" . rand(1000,9999) . "." . $uzanti;
            $hedefYol = $hedefKlasor . "/" . $yeniAd;
            if (move_uploaded_file($_FILES['dosya']['tmp_name'], $hedefYol)) {
                $db->prepare("INSERT INTO musteri_dosyalar (firma_id, musteri_id, dosya_yolu, dosya_adi) VALUES (?, ?, ?, ?)")->execute([$firma_id, $id, $hedefYol, htmlspecialchars($dosyaAdi, ENT_QUOTES)]);
                $_SESSION['success_message'] = "Evrak/Dosya başarıyla yüklendi.";
                if(function_exists('sistemLog')) sistemLog($db, 'Müşteri CRM', 'Dosya Yüklendi', "Müşteriye belge eklendi.");
            } else { 
                $_SESSION['error_message'] = "Yükleme hatası."; 
            }
        } else { 
            $_SESSION['error_message'] = "Geçersiz dosya türü."; 
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['dosya_sil_id'])) {
        $dosyaID = (int)$_POST['dosya_sil_id'];
        $dosyaBilgi = $db->prepare("SELECT * FROM musteri_dosyalar WHERE id=? AND firma_id=?");
        $dosyaBilgi->execute([$dosyaID, $firma_id]);
        $dosyaBilgi = $dosyaBilgi->fetch(PDO::FETCH_ASSOC);
        if ($dosyaBilgi) {
            if(file_exists($dosyaBilgi['dosya_yolu'])) { unlink($dosyaBilgi['dosya_yolu']); } 
            $db->prepare("DELETE FROM musteri_dosyalar WHERE id=?")->execute([$dosyaID]); 
            $_SESSION['success_message'] = "Dosya silindi.";
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    // YENİ İŞLEM (TAHSİLAT VEYA SATIŞ + VADE SİHİRBAZI)
    if (isset($_POST['islem_ekle'])) {
        $islem_turu = $_POST['islem_turu']; 
        if (!in_array($islem_turu, ['satis', 'tahsilat'])) die("Geçersiz işlem.");

        $notlar = !empty($_POST['islem_notu']) ? $_POST['islem_notu'] : null; 
        $tarih = $_POST['tarih'];
        $hizmet_tarihi = !empty($_POST['hizmet_tarihi']) ? $_POST['hizmet_tarihi'] : null;
        $odeme_turu = isset($_POST['odeme_turu']) ? (int)$_POST['odeme_turu'] : 0;
        
        if ($islem_turu == 'tahsilat') {
            $toplam_tutar = (float)str_replace(',', '.', $_POST['tutar']); 
            $adet = 1; 
            $birim_fiyat = $toplam_tutar; 
            $iskonto = 0; 
            $kdv = 0;
            $odeme_isimleri = [0 => 'Nakit', 1 => 'Kredi Kartı', 2 => 'Havale / EFT'];
            $urun_aciklama = ($odeme_isimleri[$odeme_turu] ?? 'Nakit') . ' Tahsilatı';
            
            $ekle = $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, odeme_turu, urun_aciklama, notlar, adet, birim_fiyat, iskonto_orani, kdv_orani, toplam_tutar, islem_tarihi, vade_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ekle->execute([$firma_id, $id, $islem_turu, $odeme_turu, $urun_aciklama, $notlar, $adet, $birim_fiyat, $iskonto, $kdv, $toplam_tutar, $tarih, $hizmet_tarihi]);
        } else {
            // SATIŞ KISMI (Vade Sihirbazı Aktif)
            $urun_aciklama = $_POST['urun_aciklama'];
            $adet = isset($_POST['adet']) ? (int)$_POST['adet'] : 1;
            $birim_fiyat = isset($_POST['birim_fiyat']) ? (float)str_replace(',', '.', $_POST['birim_fiyat']) : 0;
            $iskonto = isset($_POST['iskonto_orani']) ? (float)$_POST['iskonto_orani'] : 0;
            $kdv = isset($_POST['kdv_orani']) ? (float)$_POST['kdv_orani'] : 0;
            
            $ara_toplam = $adet * $birim_fiyat;
            $iskonto_tutar = $ara_toplam * ($iskonto / 100);
            $kdv_matrah = $ara_toplam - $iskonto_tutar;
            $kdv_tutar = $kdv_matrah * ($kdv / 100);
            $toplam_tutar_pesin = $kdv_matrah + $kdv_tutar; // İndirimli ve KDV'li Peşin Fiyat
            
            $taksit_sayisi = isset($_POST['taksit_sayisi']) ? (int)$_POST['taksit_sayisi'] : 1;
            
            if ($taksit_sayisi > 1) {
                // Taksitli Satış (Vade Farkı Ekleme)
                $vade_orani = isset($vadeOranlari[$taksit_sayisi]) ? (float)$vadeOranlari[$taksit_sayisi] : 0;
                $toplam_tutar_vade_farkli = $toplam_tutar_pesin * (1 + ($vade_orani / 100));
                $aylik_taksit_tutari = round($toplam_tutar_vade_farkli / $taksit_sayisi, 2);
                
                // Son taksitteki küsurat hatalarını önlemek için
                $kalan_toplam = $toplam_tutar_vade_farkli;
                
                $ekle = $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, odeme_turu, urun_aciklama, notlar, adet, birim_fiyat, iskonto_orani, kdv_orani, toplam_tutar, islem_tarihi, vade_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                for ($i = 1; $i <= $taksit_sayisi; $i++) {
                    $su_anki_tutar = ($i == $taksit_sayisi) ? $kalan_toplam : $aylik_taksit_tutari;
                    $kalan_toplam -= $su_anki_tutar;
                    
                    $vade_tarihi = date('Y-m-d', strtotime("+$i months", strtotime($hizmet_tarihi ?: $tarih)));
                    $taksit_aciklamasi = $urun_aciklama . " ($taksit_sayisi Taksit - $i. Taksit)";
                    
                    $ekle->execute([$firma_id, $id, $islem_turu, 0, $taksit_aciklamasi, $notlar, 1, $su_anki_tutar, 0, 0, $su_anki_tutar, $tarih, $vade_tarihi]);
                }
                $_SESSION['success_message'] = "Satış, vade sihirbazı kullanılarak $taksit_sayisi taksit halinde ($toplam_tutar_vade_farkli ₺) kaydedildi.";
            } else {
                // Peşin / Tek Çekim
                $ekle = $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, odeme_turu, urun_aciklama, notlar, adet, birim_fiyat, iskonto_orani, kdv_orani, toplam_tutar, islem_tarihi, vade_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ekle->execute([$firma_id, $id, $islem_turu, 0, $urun_aciklama, $notlar, $adet, $birim_fiyat, $iskonto, $kdv, $toplam_tutar_pesin, $tarih, $hizmet_tarihi]);
                $_SESSION['success_message'] = "Satış başarıyla eklendi.";
            }
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    // MÜŞTERİ GÜNCELLE VE RICH TEXT NOT EKLENTİSİ
    if (isset($_POST['musteri_guncelle'])) {
        $ad_soyad = $_POST['ad_soyad'];
        $gelin_ad = $_POST['gelin_ad'] ?? '';
        $damat_ad = $_POST['damat_ad'] ?? '';
        $ozel_notlar_html = $_POST['ozel_notlar_html'] ?? ''; // Rich Text Editör Verisi

        // Banwords Koruması
        if (function_exists('checkBanwords') && (checkBanwords($ad_soyad) || checkBanwords($gelin_ad) || checkBanwords($damat_ad) || checkBanwords(strip_tags($ozel_notlar_html)))) {
            $_SESSION['error_message'] = "Uygunsuz/yasaklı kelimeler tespit edildi. İşlem reddedildi!";
        } else {
            $telefon = preg_replace('/[^\d]/', '', $_POST['telefon'] ?? '');
            if (strlen($telefon) == 10 && substr($telefon, 0, 1) != '0') $telefon = '0' . $telefon;

            $anlasma = !empty($_POST['anlasma_tarihi']) ? $_POST['anlasma_tarihi'] : null;
            $guncelle = $db->prepare("UPDATE musteriler SET ad_soyad=?, gelin_ad=?, damat_ad=?, telefon=?, adres=?, tc_vergi_no=?, sozlesme_no=?, anlasma_tarihi=?, ozel_notlar=?, ozel_notlar_html=? WHERE id=? AND firma_id=?");
            $guncelle->execute([$ad_soyad, $gelin_ad, $damat_ad, $telefon, $_POST['adres'], $_POST['tc_vergi_no'], $_POST['sozlesme_no'], $anlasma, strip_tags($ozel_notlar_html), $ozel_notlar_html, $id, $firma_id]);
            
            $_SESSION['success_message'] = "Müşteri bilgileri ve özel notlar güncellendi.";
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    // ETİKETLERİ GÜNCELLE
    if (isset($_POST['etiketleri_kaydet'])) {
        $db->prepare("DELETE FROM musteri_etiket_baglanti WHERE musteri_id = ?")->execute([$id]);
        if (!empty($_POST['etiketler'])) {
            $stmt_et = $db->prepare("INSERT INTO musteri_etiket_baglanti (musteri_id, etiket_id) VALUES (?, ?)");
            foreach ($_POST['etiketler'] as $e_id) {
                $stmt_et->execute([$id, (int)$e_id]);
            }
        }
        $_SESSION['success_message'] = "Müşteri etiketleri başarıyla güncellendi.";
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    // PERSONEL GÖREVİ ATA
    if (isset($_POST['gorev_ekle'])) {
        $p_id = (int)$_POST['personel_id'];
        $baslik = trim($_POST['gorev_basligi']);
        $aciklama = trim($_POST['gorev_aciklama']);
        $son_tarih = !empty($_POST['son_tarih']) ? $_POST['son_tarih'] : null;

        $db->prepare("INSERT INTO gorevler (firma_id, musteri_id, personel_id, gorev_basligi, aciklama, son_tarih) VALUES (?, ?, ?, ?, ?, ?)")->execute([$firma_id, $id, $p_id, $baslik, $aciklama, $son_tarih]);
        $_SESSION['success_message'] = "Görev başarıyla personele atandı.";
        header("Location: musteri_detay.php?t=$token"); exit;
    }
    
    // GÖREV SİL / DURUM DEĞİŞTİR
    if (isset($_POST['gorev_tamamla'])) {
        $db->prepare("UPDATE gorevler SET durum = 'tamamlandi' WHERE id = ? AND firma_id = ?")->execute([(int)$_POST['gorev_id'], $firma_id]);
        header("Location: musteri_detay.php?t=$token"); exit;
    }
    if (isset($_POST['gorev_sil'])) {
        $db->prepare("DELETE FROM gorevler WHERE id = ? AND firma_id = ?")->execute([(int)$_POST['gorev_id'], $firma_id]);
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['hareket_guncelle'])) {
        $hareket_id = (int)$_POST['hareket_id'];
        $chk = $db->prepare("SELECT id FROM hareketler WHERE id=? AND firma_id=?");
        $chk->execute([$hareket_id, $firma_id]);
        
        if($chk->fetch()) {
            $islem_turu = $_POST['islem_turu'];
            if (!in_array($islem_turu, ['satis', 'tahsilat'])) die("Geçersiz işlem.");

            $notlar = !empty($_POST['islem_notu']) ? $_POST['islem_notu'] : null; 
            $hizmet_tarihi = !empty($_POST['hizmet_tarihi']) ? $_POST['hizmet_tarihi'] : null;
            $odeme_turu = isset($_POST['odeme_turu']) ? (int)$_POST['odeme_turu'] : 0;
            
            if ($islem_turu == 'tahsilat') {
                $toplam_tutar = (float)str_replace(',', '.', $_POST['tutar']); 
                $adet = 1; $birim_fiyat = $toplam_tutar; $iskonto = 0; $kdv = 0;
                $odeme_isimleri = [0 => 'Nakit', 1 => 'Kredi Kartı', 2 => 'Havale / EFT'];
                $urun_aciklama = ($odeme_isimleri[$odeme_turu] ?? 'Nakit') . ' Tahsilatı';
            } else {
                $urun_aciklama = $_POST['urun_aciklama'];
                $adet = (int)$_POST['adet']; 
                $birim_fiyat = (float)str_replace(',', '.', $_POST['birim_fiyat']); 
                $iskonto = (float)$_POST['iskonto_orani']; 
                $kdv = (float)$_POST['kdv_orani'];
                
                $ara_toplam = $adet * $birim_fiyat;
                $iskonto_tutar = $ara_toplam * ($iskonto / 100);
                $kdv_matrah = $ara_toplam - $iskonto_tutar;
                $kdv_tutar = $kdv_matrah * ($kdv / 100);
                $toplam_tutar = $kdv_matrah + $kdv_tutar;
                $odeme_turu = 0;
            }

            $h_guncelle = $db->prepare("UPDATE hareketler SET odeme_turu=?, urun_aciklama=?, notlar=?, vade_tarihi=?, adet=?, birim_fiyat=?, iskonto_orani=?, kdv_orani=?, toplam_tutar=? WHERE id=?");
            $h_guncelle->execute([$odeme_turu, $urun_aciklama, $notlar, $hizmet_tarihi, $adet, $birim_fiyat, $iskonto, $kdv, $toplam_tutar, $hareket_id]);
            
            $_SESSION['success_message'] = "İşlem başarıyla güncellendi.";
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['yeni_durum'])) {
        $girilen_sifre = $_POST['guvenlik_sifresi']; 
        if (yoneticiSifreDogrula($db, $_SESSION['kullanici_id'], $girilen_sifre, $firma_id)) {
            $db->prepare("UPDATE musteriler SET durum = ? WHERE id = ? AND firma_id = ?")->execute([$_POST['yeni_durum'], $id, $firma_id]);
            $_SESSION['success_message'] = "Müşteri arşive/aktife taşındı.";
        } else { 
            $_SESSION['error_message'] = "HATA: Yönetici şifresi yanlış!"; 
        }
        header("Location: musteri_detay.php?t=$token"); exit;
    }
    
    if (isset($_POST['sil_id'])) {
        $sil_id = (int)$_POST['sil_id'];
        $db->prepare("DELETE FROM hareketler WHERE id = ? AND firma_id = ?")->execute([$sil_id, $firma_id]);
        $_SESSION['success_message'] = "Kayıt silindi.";
        header("Location: musteri_detay.php?t=$token"); exit;
    }

    if (isset($_POST['musteri_sil_soft'])) {
        $girilen_sifre = $_POST['guvenlik_sifresi'];
        if (yoneticiSifreDogrula($db, $_SESSION['kullanici_id'], $girilen_sifre, $firma_id)) {
            $db->prepare("UPDATE musteriler SET silindi = 1 WHERE id = ? AND firma_id = ?")->execute([$id, $firma_id]);
            $_SESSION['success_message'] = "Müşteri çöp kutusuna taşındı.";
            header("Location: musteriler.php"); exit;
        } else { 
            $_SESSION['error_message'] = "HATA: Yönetici şifresi yanlış!"; 
            header("Location: musteri_detay.php?t=$token"); exit;
        }
    }

    // --- GÜVENLİ SMS GÖNDERİMİ (Otomatik + Şablonlar) ---
    if (isset($_POST['sms_gonder_btn'])) {
        $hedef_hareket_id = (int)($_POST['hedef_hareket_id'] ?? 0);
        $sablon_mesaj = $_POST['sms_sablon_icerik'] ?? ''; // Eğer şablon seçilmişse
        
        $bakiyeSorgu = $db->prepare("SELECT f.paket_id, f.aylik_sms_limiti, f.ek_sms_bakiyesi, f.kullanilan_sms_aylik, p.is_trial FROM firmalar f LEFT JOIN paketler p ON f.paket_id = p.id WHERE f.id = ?");
        $bakiyeSorgu->execute([$firma_id]);
        $firma_sms_veri = $bakiyeSorgu->fetch(PDO::FETCH_ASSOC);

        if ($firma_sms_veri && ($firma_sms_veri['paket_id'] == 1 || $firma_sms_veri['is_trial'] == 1)) {
            $_SESSION['error_message'] = "Deneme veya Başlangıç sürümünde SMS özelliği kapalıdır. Paketinizi yükseltin.";
        } else {
            $aylik_limit = (int)$firma_sms_veri['aylik_sms_limiti'];
            $ek_bakiye = (int)$firma_sms_veri['ek_sms_bakiyesi'];
            $kullanilan_aylik = (int)$firma_sms_veri['kullanilan_sms_aylik'];
            
            if (($aylik_limit - $kullanilan_aylik + $ek_bakiye) > 0) {
                $gidecek_mesaj = "";
                
                // ŞABLON MU SEÇİLDİ YOKSA OTOMATİK SİSTEM MESAJI MI?
                if (!empty($sablon_mesaj)) {
                    $gidecek_mesaj = $sablon_mesaj;
                } elseif ($hedef_hareket_id > 0) {
                    $hSorgu = $db->prepare("SELECT * FROM hareketler WHERE id = ? AND firma_id = ? AND musteri_id = ?");
                    $hSorgu->execute([$hedef_hareket_id, $firma_id, $id]);
                    $islem = $hSorgu->fetch(PDO::FETCH_ASSOC);
                    
                    if ($islem) {
                        $gidecek_mesaj = "Sayın " . $musteriIlk['ad_soyad'] . ", ";
                        if ($islem['islem_turu'] == 'satis') {
                            $h_date = $islem['vade_tarihi'] ? date("d.m.Y", strtotime($islem['vade_tarihi'])) : date("d.m.Y", strtotime($islem['islem_tarihi']));
                            $gidecek_mesaj .= "$h_date tarihli {$islem['urun_aciklama']} kaydiniz alinmistir. Tutar: " . number_format($islem['toplam_tutar'], 2, ',', '.') . " TL. Tesekkurler.";
                        } else {
                            $gidecek_mesaj .= date("d.m.Y", strtotime($islem['islem_tarihi'])) . " tarihinde " . number_format($islem['toplam_tutar'], 2, ',', '.') . " TL odemeniz alindi. Tesekkurler.";
                        }
                        $gidecek_mesaj .= " - " . ($_SESSION['firma_adi'] ?? '');
                    }
                }

                if (!empty($gidecek_mesaj)) {
                    $sms_sonuc = netgsm_sms_gonder($musteriIlk['telefon'], $gidecek_mesaj, $firma_id);
                    if ($sms_sonuc['status'] === true) {
                        $_SESSION['success_message'] = "SMS başarıyla gönderildi ve kotanızdan düşüldü.";
                    } else {
                        $_SESSION['error_message'] = "SMS Gönderilemedi: " . $sms_sonuc['message'];
                    }
                } else {
                    $_SESSION['error_message'] = "Gönderilecek mesaj bulunamadı.";
                }
            } else {
                $_SESSION['error_message'] = "SMS Bakiyeniz Yetersiz! Lütfen paketinizi yükseltin veya mağazadan ek kontör alın.";
            }
        }
        header("Location: musteri_detay.php?t=$token"); 
        exit;
    }
}

// --- VERİLERİ ÇEK ---
$musteri = $musteriIlk;

// Etiketler
$m_etiketler = $db->prepare("SELECT e.id, e.etiket_adi, e.renk FROM musteri_etiket_baglanti b JOIN musteri_etiketleri e ON b.etiket_id = e.id WHERE b.musteri_id = ?");
$m_etiketler->execute([$id]);
$m_etiketler = $m_etiketler->fetchAll(PDO::FETCH_ASSOC);

$tum_etiketler = $db->prepare("SELECT * FROM musteri_etiketleri WHERE firma_id = ?");
$tum_etiketler->execute([$firma_id]);
$tum_etiketler = $tum_etiketler->fetchAll(PDO::FETCH_ASSOC);

// İletişim Şablonları (Firma Ayarlarından Çekilir)
$sablonlar = $db->prepare("SELECT * FROM iletisim_sablonlari WHERE firma_id = ? AND durum = 'onaylandi' ORDER BY baslik ASC");
$sablonlar->execute([$firma_id]);
$sablonlar = $sablonlar->fetchAll(PDO::FETCH_ASSOC);

// Personeller (Görev Ataması için)
$personeller = $db->prepare("SELECT id, ad_soyad FROM personeller WHERE firma_id = ? AND silindi = 0");
$personeller->execute([$firma_id]);
$personeller = $personeller->fetchAll(PDO::FETCH_ASSOC);

// Görevler
$gorevler = $db->prepare("SELECT g.*, p.ad_soyad as personel_adi FROM gorevler g JOIN personeller p ON g.personel_id = p.id WHERE g.musteri_id = ? ORDER BY g.durum ASC, g.son_tarih ASC");
$gorevler->execute([$id]);
$gorevler = $gorevler->fetchAll(PDO::FETCH_ASSOC);

$sozlesmeler = $db->prepare("SELECT * FROM sozlesmeler WHERE musteri_id=? AND firma_id=? ORDER BY id DESC");
$sozlesmeler->execute([$id, $firma_id]);
$sozlesmeler = $sozlesmeler->fetchAll(PDO::FETCH_ASSOC);

$hareketler = $db->prepare("SELECT *, COALESCE(vade_tarihi, islem_tarihi) as siralama_tarihi FROM hareketler WHERE musteri_id=? AND firma_id=? ORDER BY siralama_tarihi ASC, id ASC");
$hareketler->execute([$id, $firma_id]);
$hareketler = $hareketler->fetchAll(PDO::FETCH_ASSOC);

$dosyalar = $db->prepare("SELECT * FROM musteri_dosyalar WHERE musteri_id=? AND firma_id=? ORDER BY id DESC");
$dosyalar->execute([$id, $firma_id]);
$dosyalar = $dosyalar->fetchAll(PDO::FETCH_ASSOC);

$hizmetler = $db->prepare("SELECT * FROM urun_hizmetler WHERE durum=1 AND firma_id=? ORDER BY hizmet_adi ASC");
$hizmetler->execute([$firma_id]);
$hizmetler = $hizmetler->fetchAll(PDO::FETCH_ASSOC);

// Portala son giriş bilgisi
$last_login_text = "Henüz giriş yapmadı";
try {
    $log_q = $db->prepare("SELECT last_login FROM musteriportal WHERE firma_id = ? AND musteri_id = ? ORDER BY last_login DESC LIMIT 1");
    $log_q->execute([$firma_id, $id]);
    if ($log_res = $log_q->fetchColumn()) $last_login_text = date('d.m.Y H:i', strtotime($log_res));
} catch (Exception $e) {}

// TERK EDİLMİŞ SEPET (SEÇİM) RADARI
$sepet_terk_uyarisi = false;
if ($musteri['workflow_status'] == 1 && $last_login_text != "Henüz giriş yapmadı") {
    $son_giris_ts = strtotime($last_login_text);
    $gecen_saat = (time() - $son_giris_ts) / 3600;
    if ($gecen_saat > 24) {
        $sepet_terk_uyarisi = floor($gecen_saat / 24); // Kaç gündür girmiyor
    }
}

// Bakiye ve LTV Hesabı
$toplamBorc = 0; $toplamTahsilat = 0;
foreach($hareketler as $h) {
    if ($h['islem_turu'] == 'satis') $toplamBorc += $h['toplam_tutar'];
    else $toplamTahsilat += $h['toplam_tutar'];
}
$bakiye = $toplamBorc - $toplamTahsilat;

// WP No Formatlama
$wp_no = preg_replace('/[^0-9]/', '', $musteri['telefon']);
if(substr($wp_no, 0, 1) == '0') $wp_no = '9' . $wp_no;
else if(strlen($wp_no) == 10) $wp_no = '90' . $wp_no;

$wp_msg_lines = ["Sayın *" . $musteri['ad_soyad'] . "*, hesap dökümünüz:", "----------------------------"];
foreach($hareketler as $h) {
    $tarih = $h['vade_tarihi'] ? date("d.m.Y", strtotime($h['vade_tarihi'])) : date("d.m.Y", strtotime($h['islem_tarihi']));
    $tutar = number_format($h['toplam_tutar'], 2, ',', '.');
    $icon = ($h['islem_turu'] == 'satis') ? "🗓️" : "✅";
    $suffix = ($h['islem_turu'] == 'satis') ? "(Hizmet)" : "(Ödeme)";
    $wp_msg_lines[] = "$icon $tarih | {$h['urun_aciklama']}: *$tutar TL* $suffix";
}
$wp_msg_lines[] = "----------------------------";
$wp_msg_lines[] = "💰 *KALAN BAKİYE: " . number_format($bakiye, 2, ',', '.') . " TL*";
$wp_msg_lines[] = "";
$wp_msg_lines[] = "*" . ($_SESSION['firma_adi'] ?? '') . "*";
$wp_genel_link = "https://wa.me/$wp_no?text=" . urlencode(implode("\n", $wp_msg_lines));

$inline_css = '
    .btn-action-lg { padding: 15px; border-radius: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 10px; transition: transform 0.2s; }
    .btn-action-lg:hover { transform: translateY(-2px); }
    .ozet-kutu-detay { border-radius: 12px; padding: 20px; color: white; height: 100%; box-shadow: 0 4px 15px rgba(0,0,0,0.1); position: relative; overflow: hidden; }
    .ozet-kutu-detay::after { content: ""; position: absolute; top: 0; right: 0; bottom: 0; left: 0; background: linear-gradient(rgba(255,255,255,0.1), rgba(255,255,255,0)); pointer-events: none; }
    .ozet-label { font-size: 0.85rem; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.5px; }
    .ozet-value { font-size: 1.8rem; font-weight: 800; margin-top: 5px; }
    .bg-gradient-satis { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); }
    .bg-gradient-tahsilat { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); }
    .bg-gradient-bakiye { background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%); }
    .bg-gradient-bakiye-pozitif { background: linear-gradient(135deg, #36b9cc 0%, #258391 100%); }
    .musteri-header-card { background: white; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; border-left: 5px solid #667eea; }
    .dosya-item { background: white; border: 1px solid #e3e6f0; border-radius: 8px; padding: 10px 15px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; transition: 0.2s; }
    .dosya-item:hover { background-color: #f8f9fa; }
    .tag-badge { padding: 4px 12px; border-radius: 20px; color: white; font-weight: bold; font-size: 0.75rem; display: inline-block; margin-right: 5px; }
    .ql-editor { min-height: 150px; background: white; border-radius: 0 0 10px 10px; }
    .ql-toolbar { border-radius: 10px 10px 0 0; background: #f8f9fa; }
    @media print {
        .no-print, .navbar, .ozet-kartlar-container, .col-lg-4, .btn, footer, .toast-container, .modal, .alert { display: none !important; }
        body { background-color: white !important; -webkit-print-color-adjust: exact; }
        .container-yonetim { padding: 0; max-width: 100%; margin: 0; }
        .musteri-header-card { box-shadow: none; border: 1px solid #ddd; margin-bottom: 20px; border-left: none; }
        .col-lg-8 { width: 100% !important; flex: 0 0 100% !important; max-width: 100% !important; }
        .card { border: none !important; box-shadow: none !important; }
        .card-header { background-color: white !important; border-bottom: 2px solid #000 !important; padding-left: 0; }
        .table { width: 100% !important; border-collapse: collapse !important; }
        .table th, .table td { padding: 8px 5px !important; font-size: 11pt; border-bottom: 1px solid #ddd; }
        .table thead th { border-bottom: 2px solid #000; color: #000; }
        .badge { border: 1px solid #000; color: #000 !important; background: transparent !important; }
    }
';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($musteri['ad_soyad']); ?> - CRM & Detay</title>
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Quill.js Rich Text Editor CSS -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style><?php echo $inline_css; ?></style>
</head>
<body class="yonetim-body">

    <?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim pb-5 mt-4">
        
        <div class="row mb-4 align-items-center no-print">
            <div class="col-md-6">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0 fw-bold">
                        <li class="breadcrumb-item"><a href="musteriler.php" class="text-decoration-none text-muted"><i class="fas fa-users me-1"></i>Müşteriler</a></li>
                        <li class="breadcrumb-item active text-primary">Müşteri CRM & Detay</li>
                    </ol>
                </nav>
            </div>
            <div class="col-md-6 text-end">
                <a href="musteriler.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"><i class="fas fa-arrow-left me-1"></i>Geri</a>
            </div>
        </div>

        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success shadow-sm no-print rounded-4 border-0 mb-4"><i class="fas fa-check-circle me-2"></i><?= $_SESSION['success_message'] ?></div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <?php if(isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger shadow-sm no-print rounded-4 border-0 mb-4"><i class="fas fa-exclamation-triangle me-2"></i><?= $_SESSION['error_message'] ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if($sepet_terk_uyarisi !== false): ?>
            <div class="alert alert-danger border-0 border-start border-4 border-danger shadow-sm rounded-4 mb-4 d-flex align-items-center">
                <i class="fas fa-siren-on fa-3x text-danger fast-spin me-4"></i>
                <div>
                    <h5 class="fw-bold text-danger mb-1">Müşteri Seçimi Terk Etmiş Olabilir!</h5>
                    <p class="mb-0">Müşteriniz "Seçim Aşamasında" olmasına rağmen <b><?= $sepet_terk_uyarisi ?> gündür</b> portala giriş yapmadı. Kendisine bir hatırlatma (WhatsApp/SMS) göndermeyi düşünebilirsiniz.</p>
                </div>
            </div>
        <?php endif; ?>

        <div class="musteri-header-card" style="<?php echo ($musteri['silindi']==1) ? 'opacity:0.6; pointer-events:none;' : ''; ?>">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <div class="d-flex align-items-center mb-2 flex-wrap gap-2">
                        <h2 class="mb-0 fw-bold text-dark me-2">
                            <?php echo htmlspecialchars($musteri['ad_soyad']); ?>
                        </h2>
                        <span class="badge no-print <?php echo $musteri['durum'] == 1 ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $musteri['durum'] == 1 ? 'Aktif' : 'Arşiv'; ?></span>
                        <button class="btn btn-sm btn-light border text-primary ms-2 no-print rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#modalMusteriDuzenle"><i class="fas fa-pen me-1"></i> Düzenle</button>
                    </div>
                    
                    <?php if($musteri['gelin_ad'] || $musteri['damat_ad']): ?>
                        <div class="fs-6 text-muted mb-2 fw-bold"><i class="fas fa-heart text-danger small me-1"></i><?php echo htmlspecialchars($musteri['gelin_ad'] . " & " . $musteri['damat_ad']); ?></div>
                    <?php endif; ?>
                    
                    <!-- RENKLİ ETİKETLER BÖLÜMÜ -->
                    <div class="mb-3 d-flex flex-wrap gap-1 align-items-center">
                        <?php foreach($m_etiketler as $et): ?>
                            <span class="tag-badge shadow-sm" style="background-color: <?= $et['renk'] ?>;"><i class="fas fa-tag me-1"></i> <?= htmlspecialchars($et['etiket_adi']) ?></span>
                        <?php endforeach; ?>
                        <button class="btn btn-sm btn-link text-muted p-0 ms-2" data-bs-toggle="modal" data-bs-target="#modalEtiketYonetimi"><i class="fas fa-plus-circle"></i> Etiket Ekle</button>
                    </div>
                    
                    <div class="d-flex flex-wrap gap-4 text-muted mb-2 small fw-bold">
                        <div class="d-flex align-items-center"><i class="fas fa-phone fa-lg me-2 text-primary"></i> <a href="tel:<?php echo $musteri['telefon']; ?>" class="text-decoration-none text-dark"><?php echo $musteri['telefon'] ?: 'Yok'; ?></a></div>
                        <?php if($musteri['tc_vergi_no']): ?><div class="d-flex align-items-center"><i class="fas fa-id-card fa-lg me-2 text-primary"></i><span>TC/VN: <?php echo $musteri['tc_vergi_no']; ?></span></div><?php endif; ?>
                        <div class="d-flex align-items-center"><i class="fas fa-clock fa-lg me-2 text-warning"></i><span>Son Portal Girişi: <?php echo $last_login_text; ?></span></div>
                    </div>
                    <?php if($musteri['adres']): ?><div class="small text-muted mt-2"><i class="fas fa-map-marker-alt me-2 text-danger"></i><?php echo htmlspecialchars($musteri['adres']); ?></div><?php endif; ?>
                </div>
                
                <div class="col-lg-5 text-lg-end mt-4 mt-lg-0 no-print">
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-2">
                        <button class="btn btn-info text-white shadow-sm fw-bold rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalSablonGonder"><i class="fas fa-paper-plane me-1"></i>Şablon İletişim</button>
                        <a href="<?php echo $wp_genel_link; ?>" target="_blank" class="btn btn-success shadow-sm fw-bold rounded-pill px-3" title="WhatsApp Döküm Gönder"><i class="fab fa-whatsapp me-1"></i>WP Döküm</a>
                        <a href="musteri_portal_yonetim.php?t=<?php echo $token; ?>" class="btn btn-primary shadow-sm fw-bold rounded-pill px-3"><i class="fas fa-external-link-alt me-1"></i>Portal Yönetimi</a>
                    </div>
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <div class="dropdown w-100 w-md-auto">
                            <button class="btn btn-outline-secondary dropdown-toggle w-100 rounded-pill fw-bold" type="button" data-bs-toggle="dropdown"><i class="fas fa-cog me-1"></i>Diğer İşlemler</button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-4">
                                <li><a class="dropdown-item" href="hesap_dokumu_yazdir.php?t=<?php echo $token; ?>" target="_blank"><i class="fas fa-print me-2 text-dark"></i>Dökümü Yazdır</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if($musteri['durum'] == 1): ?>
                                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalArsivle"><i class="fas fa-archive me-2 text-warning"></i>Arşive Kaldır</a></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalAktifEt"><i class="fas fa-undo me-2 text-success"></i>Aktif Et</a></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#modalSilSoft"><i class="fas fa-trash me-2"></i>Müşteriyi Sil</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4 ozet-kartlar-container" style="<?php echo ($musteri['silindi']==1) ? 'opacity:0.6;' : ''; ?>">
            <div class="col-md-4 mb-3 mb-md-0"><div class="ozet-kutu-detay bg-gradient-satis"><div class="ozet-label">Yaşam Boyu Değer (LTV)</div><div class="ozet-value"><?php echo number_format($toplamBorc, 2, ',', '.'); ?> ₺</div><i class="fas fa-chart-line fa-3x position-absolute" style="right: 20px; bottom: 20px; opacity: 0.2;"></i></div></div>
            <div class="col-md-4 mb-3 mb-md-0"><div class="ozet-kutu-detay bg-gradient-tahsilat"><div class="ozet-label">Toplam Tahsil Edilen</div><div class="ozet-value"><?php echo number_format($toplamTahsilat, 2, ',', '.'); ?> ₺</div><i class="fas fa-hand-holding-usd fa-3x position-absolute" style="right: 20px; bottom: 20px; opacity: 0.2;"></i></div></div>
            <div class="col-md-4"><div class="ozet-kutu-detay <?php echo $bakiye > 0 ? 'bg-gradient-bakiye' : 'bg-gradient-bakiye-pozitif'; ?>"><div class="ozet-label">Kalan / Açık Bakiye</div><div class="ozet-value"><?php echo number_format($bakiye, 2, ',', '.'); ?> ₺</div><i class="fas fa-wallet fa-3x position-absolute" style="right: 20px; bottom: 20px; opacity: 0.2;"></i></div></div>
        </div>

        <div class="row" style="<?php echo ($musteri['silindi']==1) ? 'opacity:0.6; pointer-events:none;' : ''; ?>">
            
            <div class="col-lg-8">
                <div class="row mb-4 no-print">
                    <div class="col-6"><button class="btn btn-action-lg w-100 btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalSatis"><i class="fas fa-plus-circle fa-lg"></i><span>YENİ İŞLEM / SATIŞ</span></button></div>
                    <div class="col-6"><button class="btn btn-action-lg w-100 btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTahsilat"><i class="fas fa-coins fa-lg"></i><span>TAHSİLAT AL</span></button></div>
                </div>

                <div class="card shadow-sm border-0 mb-4 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3"><h6 class="m-0 fw-bold text-secondary"><i class="fas fa-history me-2"></i>Finansal Hesap Hareketleri</h6></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary">
                                <tr>
                                    <th class="ps-3" width="110">İşlem Tar.</th>
                                    <th width="140">Vade/Hizmet</th>
                                    <th>Açıklama / Hizmet</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="text-end pe-3 no-print" width="160">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($hareketler)): ?><tr><td colspan="5" class="text-center py-5 text-muted">Kayıtlı hesap hareketi yok.</td></tr><?php endif; ?>
                                <?php foreach($hareketler as $h): 
                                    $not = $h['notlar'] ?: '';
                                    $bugun = date("Y-m-d");
                                    $durum_etiketi = "";
                                    
                                    if($h['islem_turu'] == 'satis' && $h['vade_tarihi']) {
                                        if($h['vade_tarihi'] < $bugun) { $durum_etiketi = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 mt-1 d-inline-block"><i class="fas fa-exclamation-circle me-1"></i>Gecikmiş</span>'; } 
                                        else { $durum_etiketi = '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning px-2 py-1 mt-1 d-inline-block"><i class="fas fa-hourglass-half me-1"></i>Bekliyor</span>'; }
                                    }

                                    $odeme_metni = "";
                                    if ($h['islem_turu'] == 'tahsilat') {
                                        if ($h['odeme_turu'] == 1) $odeme_metni = " <span class='badge bg-info text-dark ms-1'><i class='fas fa-credit-card'></i> K.Kartı</span>";
                                        elseif ($h['odeme_turu'] == 2) $odeme_metni = " <span class='badge bg-secondary ms-1'><i class='fas fa-exchange-alt'></i> Havale</span>";
                                        else $odeme_metni = " <span class='badge bg-success ms-1'><i class='fas fa-money-bill-wave'></i> Nakit</span>";
                                    }

                                    $satir_mesaj = "Sayın " . $musteri['ad_soyad'] . ", ";
                                    if ($h['islem_turu'] == 'satis') {
                                        $h_date = $h['vade_tarihi'] ? date("d.m.Y", strtotime($h['vade_tarihi'])) : date("d.m.Y", strtotime($h['islem_tarihi']));
                                        $satir_mesaj .= "$h_date tarihli {$h['urun_aciklama']} rezervasyonunuz oluşturulmuştur. Tutar: " . number_format($h['toplam_tutar'], 2, ',', '.') . " TL. Teşekkürler.";
                                    } else {
                                        $satir_mesaj .= date("d.m.Y", strtotime($h['islem_tarihi'])) . " tarihinde " . number_format($h['toplam_tutar'], 2, ',', '.') . " TL ödemeniz alındı. Teşekkürler.";
                                    }
                                    $satir_mesaj .= " - " . ($_SESSION['firma_adi'] ?? '');
                                ?>
                                <tr class="<?php echo $h['islem_turu']=='tahsilat' ? 'table-success' : ''; ?>" style="<?php echo $h['islem_turu']=='tahsilat' ? '--bs-table-bg-type:rgba(25,135,84,0.05)' : ''; ?>">
                                    <td class="ps-3 text-muted small fw-bold"><?php echo date("d.m.Y", strtotime($h['islem_tarihi'])); ?></td>
                                    <td>
                                        <?php if($h['islem_turu'] == 'satis' && $h['vade_tarihi']): ?>
                                            <div class="fw-bold text-dark"><?php echo date("d.m.Y", strtotime($h['vade_tarihi'])); ?></div>
                                            <?php echo $durum_etiketi; ?>
                                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($h['urun_aciklama']); ?><?php echo $odeme_metni; ?></div>
                                        <?php if($not): ?><div class="small text-danger mt-1 fst-italic"><i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($not); ?></div><?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold fs-6 <?php echo $h['islem_turu']=='tahsilat'?'text-success':'text-danger'; ?>">
                                        <?php echo ($h['islem_turu']=='tahsilat' ? '-' : '+') . number_format($h['toplam_tutar'], 2, ',', '.'); ?> ₺
                                    </td>
                                    <td class="text-end pe-3 no-print">
                                        <div class="btn-group btn-group-sm shadow-sm rounded-pill overflow-hidden">
                                            <button type="button" class="btn btn-light text-info border-end" onclick="smsModalAc(<?= $h['id'] ?>, '<?= htmlspecialchars($wp_no, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($satir_mesaj, ENT_QUOTES, 'UTF-8') ?>')" title="SMS Gönder"><i class="fas fa-comment-sms"></i></button>
                                            <button type="button" class="btn btn-light text-primary border-end" onclick="hareketDuzenle(this)" data-hareket='<?php echo htmlspecialchars(json_encode($h), ENT_QUOTES, 'UTF-8'); ?>' title="Düzenle"><i class="fas fa-pen"></i></button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Silmek istediğinize emin misiniz?');">
                                                <input type="hidden" name="sil_id" value="<?php echo $h['id']; ?>">
                                                <button class="btn btn-light text-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end py-3">GENEL BAKİYE:</td>
                                    <td class="text-end py-3 text-dark fs-5"><?php echo number_format($bakiye, 2, ',', '.'); ?> ₺</td>
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- YENİ: PERSONEL GÖREV ATAMASI (TASK MANAGEMENT) -->
                <div class="card shadow-sm border-0 mb-4 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-secondary"><i class="fas fa-tasks me-2"></i>Ekip Görevleri / İş Takibi</h6>
                        <button class="btn btn-sm btn-outline-primary rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#modalGorevEkle"><i class="fas fa-plus me-1"></i> Görev Ata</button>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php if(empty($gorevler)): ?>
                                <div class="text-center py-4 text-muted small">Bu müşteriye atanmış bir personel görevi yok.</div>
                            <?php endif; ?>
                            <?php foreach($gorevler as $g): ?>
                                <div class="list-group-item p-3 <?= $g['durum'] == 'tamamlandi' ? 'bg-light opacity-75' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-bold text-dark d-flex align-items-center gap-2 mb-1">
                                                <?php if($g['durum'] == 'tamamlandi'): ?>
                                                    <i class="fas fa-check-circle text-success fs-5"></i>
                                                <?php else: ?>
                                                    <i class="far fa-circle text-muted fs-5"></i>
                                                <?php endif; ?>
                                                <span class="<?= $g['durum'] == 'tamamlandi' ? 'text-decoration-line-through' : '' ?>"><?= htmlspecialchars($g['gorev_basligi']) ?></span>
                                            </div>
                                            <div class="small text-muted ms-4 ps-1"><?= htmlspecialchars($g['aciklama']) ?></div>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary mb-1"><i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($g['personel_adi']) ?></span>
                                            <?php if($g['son_tarih']): ?>
                                                <div class="small text-danger fw-bold"><i class="fas fa-clock me-1"></i> <?= date('d.m.Y', strtotime($g['son_tarih'])) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-end ms-4 ps-1">
                                        <?php if($g['durum'] != 'tamamlandi'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="gorev_tamamla" value="1"><input type="hidden" name="gorev_id" value="<?= $g['id'] ?>">
                                            <button class="btn btn-sm btn-success rounded-pill px-3 shadow-sm fw-bold">Tamamlandı Yap</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Görevi silmek istiyor musunuz?')">
                                            <input type="hidden" name="gorev_sil" value="1"><input type="hidden" name="gorev_id" value="<?= $g['id'] ?>">
                                            <button class="btn btn-sm btn-link text-danger text-decoration-none">Sil</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>
            
            <div class="col-lg-4 no-print">
                <!-- YENİ: ZENGİN METİN (RICH TEXT) NOT DEFTERİ -->
                <div class="card shadow-sm border-0 mb-4 rounded-4 overflow-hidden" style="background-color: #fffbf0; border-top: 4px solid #f6c23e !important;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-dark mb-0"><i class="fas fa-sticky-note me-2 text-warning"></i>Özel Not Defteri</h6>
                            <button class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNotDefteri"><i class="fas fa-pen me-1"></i> Düzenle</button>
                        </div>
                        <div class="p-3 bg-white rounded-3 border border-warning border-opacity-25" style="min-height: 100px; font-size:0.9rem; color:#444;">
                            <?php if(!empty($musteri['ozel_notlar_html'])): ?>
                                <?= $musteri['ozel_notlar_html'] ?>
                            <?php elseif(!empty($musteri['ozel_notlar'])): ?>
                                <?= nl2br(htmlspecialchars($musteri['ozel_notlar'])) ?>
                            <?php else: ?>
                                <span class="text-muted fst-italic">Henüz not eklenmemiş. Yazmak için düzenle butonuna basın.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4 rounded-4">
                    <div class="card-header bg-white py-3"><h6 class="m-0 fw-bold text-dark"><i class="fas fa-file-signature me-2 text-primary"></i>Sözleşme Geçmişi</h6></div>
                    <div class="card-body p-0">
                        <?php if(count($sozlesmeler) > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach($sozlesmeler as $sz): ?>
                                    <div class="list-group-item p-3 border-bottom">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-bold small"><?php echo htmlspecialchars($sz['sozlesme_no']); ?></div>
                                                <div class="text-muted" style="font-size: 0.75rem;"><?php echo date("d.m.Y", strtotime($sz['sozlesme_tarihi'])); ?></div>
                                            </div>
                                            <a href="sozlesme_olustur.php?t=<?php echo $token; ?>&print_id=<?php echo $sz['id']; ?>" target="_blank" class="btn btn-sm btn-light border rounded-circle"><i class="fas fa-print text-primary"></i></a>
                                        </div>
                                        <div class="mt-1 small fw-bold text-primary"><?php echo number_format($sz['toplam_tutar'], 2, ',', '.'); ?> ₺</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted small">Kayıtlı sözleşme bulunamadı.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- EVRAK VE DOSYA YÜKLEME KISMI -->
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-secondary"><i class="fas fa-folder-open me-2"></i>Evrak ve Dosyalar</h6>
                        <div class="d-flex gap-2">
                            <a href="sozlesme_olustur.php?t=<?php echo $token; ?>" class="btn btn-sm btn-outline-dark rounded-pill px-3 fw-bold"><i class="fas fa-file-contract me-1"></i>Sözleşme</a>
                            <button class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" onclick="document.getElementById('dosyaInput').click()"><i class="fas fa-upload me-1"></i>Evrak Yükle</button>
                        </div>
                    </div>
                    <div class="card-body bg-light">
                        <div class="alert alert-info small py-2 mb-3 border-0 shadow-sm"><i class="fas fa-info-circle me-1"></i> PDF fatura veya dekont gibi belgeleri buraya yükleyebilirsiniz.</div>
                        <form method="POST" enctype="multipart/form-data" id="dosyaForm" class="d-none">
                            <input type="file" name="dosya" id="dosyaInput" onchange="document.getElementById('dosyaForm').submit()" accept=".pdf,.doc,.docx,.jpg,.png">
                        </form>
                        <?php if(count($dosyalar) > 0): ?>
                            <div class="dosya-listesi">
                                <?php foreach($dosyalar as $d): ?>
                                    <div class="dosya-item shadow-sm border-0 mb-2">
                                        <a href="<?php echo htmlspecialchars($d['dosya_yolu']); ?>" target="_blank" class="text-decoration-none text-dark d-flex align-items-center text-truncate">
                                            <i class="fas fa-file-pdf fa-lg me-3 text-danger"></i>
                                            <span class="small fw-bold text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($d['dosya_adi']); ?></span>
                                        </a>
                                        <form method="POST" class="m-0 p-0">
                                            <input type="hidden" name="dosya_sil_id" value="<?php echo $d['id']; ?>">
                                            <button type="submit" class="btn btn-sm text-danger p-0" onclick="return confirm('Silmek istiyor musunuz?')"><i class="fas fa-times-circle fs-5"></i></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted small"><i class="fas fa-cloud-upload-alt fa-3x mb-2 opacity-25"></i><br>Dosya yüklenmemiş.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: HİZMET/SATIŞ EKLE (VADE SİHİRBAZI EKLİ) -->
    <div class="modal fade modal-yonetim" id="modalSatis" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <form method="POST" class="form-yonetim">
                    <input type="hidden" name="islem_ekle" value="1">
                    <input type="hidden" name="islem_turu" value="satis">
                    <div class="modal-header bg-primary text-white border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-cart-plus me-2"></i>Yeni Satış / İşlem Ekle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <div class="row g-3 bg-white p-3 rounded-4 shadow-sm border mb-3">
                            <div class="col-md-6"><label class="form-label small fw-bold text-muted">İşlem (Kayıt) Tarihi</label><input type="datetime-local" name="tarih" class="form-control fw-bold" value="<?php echo date('Y-m-d\TH:i'); ?>" required></div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-primary">Hizmet / Çekim Günü <span class="fw-normal text-muted">(Opsiyonel)</span></label>
                                <input type="date" name="hizmet_tarihi" class="form-control border-primary fw-bold text-primary">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">Hizmet / Ürün Adı Seç</label>
                                <input type="text" name="urun_aciklama" id="urun_input" class="form-control fw-bold" list="hizmetListesi" placeholder="Hizmet adı yazın veya seçin..." required>
                                <datalist id="hizmetListesi"><?php foreach($hizmetler as $hz): ?><option value="<?php echo htmlspecialchars($hz['hizmet_adi']); ?>" data-fiyat="<?php echo htmlspecialchars($hz['varsayilan_fiyat']); ?>"><?php endforeach; ?></datalist>
                            </div>
                        </div>

                        <div class="row g-3 bg-white p-3 rounded-4 shadow-sm border mb-3">
                            <div class="col-6"><label class="form-label small fw-bold text-muted">Birim Fiyat (₺)</label><input type="number" step="0.01" name="birim_fiyat" id="fiyat_input" class="form-control fw-bold fs-5 text-dark" oninput="canliHesapla()" required></div>
                            <div class="col-6"><label class="form-label small fw-bold text-muted">Adet</label><input type="number" name="adet" id="adet_input" class="form-control fw-bold fs-5" value="1" oninput="canliHesapla()" required></div>
                            
                            <!-- YENİ: VADE SİHİRBAZI -->
                            <div class="col-12 mt-3">
                                <div class="p-3 bg-warning bg-opacity-10 border border-warning border-opacity-50 rounded-3">
                                    <label class="form-label small fw-bold text-warning text-dark"><i class="fas fa-magic me-1"></i> Ödeme Planı (Vade/Taksit Sihirbazı)</label>
                                    <select name="taksit_sayisi" id="taksit_input" class="form-select fw-bold border-warning shadow-sm" onchange="canliHesapla()">
                                        <option value="1">Peşin / Tek Çekim (Vade Farksız)</option>
                                        <?php foreach($vadeOranlari as $taksit => $oran): ?>
                                            <option value="<?= $taksit ?>" data-oran="<?= $oran ?>"><?= $taksit ?> Taksit / Ay (Vade Farkı: %<?= $oran ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small mt-1">Seçtiğiniz taksit sayısına göre vade farkı eklenir ve borç aylara bölünür.</div>
                                </div>
                            </div>
                            
                            <div class="col-6 mt-3"><label class="form-label small fw-bold text-muted">KDV (%)</label><select name="kdv_orani" id="kdv_input" class="form-select" onchange="canliHesapla()"><option value="0">Dahil/Yok</option><option value="1">%1</option><option value="10">%10</option><option value="20">%20</option></select></div>
                            <div class="col-6 mt-3"><label class="form-label small fw-bold text-muted">İskonto / İndirim (%)</label><input type="number" name="iskonto_orani" id="iskonto_input" class="form-control" value="0" oninput="canliHesapla()"></div>
                        </div>

                        <!-- CANLI HESAPLAMA PANELİ -->
                        <div id="live_calc_box" class="card border-info shadow-sm" style="display: none;">
                            <div class="card-body bg-light">
                                <div class="d-flex justify-content-between mb-1 text-muted small"><span>Ara Toplam:</span> <strong id="calc_ara">0.00 ₺</strong></div>
                                <div class="d-flex justify-content-between mb-1 text-danger small" id="calc_iskonto_row" style="display:none !important;"><span>İskonto:</span> <strong id="calc_iskonto">0.00 ₺</strong></div>
                                <div class="d-flex justify-content-between mb-1 text-muted small"><span>KDV Hariç Tutar:</span> <strong id="calc_matrah">0.00 ₺</strong></div>
                                <div class="d-flex justify-content-between mb-1 text-muted small"><span>KDV Tutarı:</span> <strong id="calc_kdv">0.00 ₺</strong></div>
                                
                                <div id="vade_farki_row" style="display:none;">
                                    <hr class="my-2 opacity-25">
                                    <div class="d-flex justify-content-between mb-1 text-warning small fw-bold"><span>Vade Farkı Eklenen Tutar:</span> <strong id="calc_vade_ekli">0.00 ₺</strong></div>
                                    <div class="d-flex justify-content-between mb-1 text-primary small fw-bold"><span>Aylık Ödeme (Taksit):</span> <strong id="calc_taksit">0.00 ₺</strong></div>
                                </div>

                                <hr class="my-2 border-info opacity-50">
                                <div class="d-flex justify-content-between text-dark fs-5 fw-bold"><span>TOPLAM BORÇ:</span> <strong id="calc_toplam" class="text-primary">0.00 ₺</strong></div>
                            </div>
                        </div>

                        <div class="mt-3"><label class="form-label text-danger small fw-bold">İşlem Özel Notu (Görünmez)</label><textarea name="islem_notu" class="form-control border-danger" rows="2" placeholder="Örn: Kaparo daha sonra alınacak..."></textarea></div>
                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4"><button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm"><i class="fas fa-check me-2"></i>Satışı Onayla</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- MODAL: TAHSİLAT EKLE -->
    <div class="modal fade modal-yonetim" id="modalTahsilat" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" class="form-yonetim">
                    <input type="hidden" name="islem_ekle" value="1">
                    <input type="hidden" name="islem_turu" value="tahsilat">
                    <div class="modal-header bg-success text-white border-0 py-3 px-4"><h5 class="modal-title fw-bold"><i class="fas fa-hand-holding-usd me-2"></i>Tahsilat Yap / Para Al</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body p-4 bg-light">
                        
                        <div class="mb-4 text-center bg-white p-4 rounded-4 shadow-sm border border-success border-opacity-25">
                            <label class="form-label fw-bold text-success mb-2 small">Müşteriden Alınan Tutar (₺)</label>
                            <input type="number" step="0.01" name="tutar" class="form-control form-control-lg fw-bold border-success text-center text-success" style="font-size: 3rem; height: 80px; background-color: #f8fff9;" placeholder="0.00" required min="0" autofocus>
                        </div>

                        <div class="row g-3 bg-white p-3 rounded-4 shadow-sm border mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-muted small">Ödeme Türü</label>
                                <select name="odeme_turu" class="form-select fw-bold text-primary border-primary" required>
                                    <option value="0">Nakit / Elden</option>
                                    <option value="1">Kredi Kartı / POS</option>
                                    <option value="2">Banka Havale / EFT</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-muted small">Tarih</label>
                                <input type="datetime-local" name="tarih" class="form-control fw-bold" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold text-muted small">İşlem Notu / Açıklama</label>
                            <textarea name="islem_notu" class="form-control shadow-sm" rows="2" placeholder="Örn: Geri kalan 2000 TL haftaya verilecek..."></textarea>
                        </div>

                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4"><button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-success fw-bold rounded-pill px-5 shadow-sm"><i class="fas fa-coins me-2"></i>Tahsilatı Kaydet</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: HAREKET DÜZENLEME -->
    <div class="modal fade modal-yonetim" id="modalHareketDuzenle" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" class="form-yonetim">
                    <input type="hidden" name="hareket_guncelle" value="1">
                    <input type="hidden" name="hareket_id" id="edit_hareket_id">
                    <input type="hidden" name="islem_turu" id="edit_islem_turu">
                    
                    <div class="modal-header bg-primary text-white border-0 py-3 px-4" id="editModalHeader">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>İşlemi Düzenle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        
                        <div id="tahsilat_tutar_alani" style="display:none;" class="mb-4 text-center">
                            <label class="form-label fw-bold text-success mb-1 small">Alınan Tutar (₺)</label>
                            <input type="number" step="0.01" name="tutar" id="edit_tutar" class="form-control form-control-lg fw-bold border-success text-center text-success" style="font-size: 2.5rem; height: 70px; background-color: #f8fff9;" placeholder="0.00" min="0">
                        </div>

                        <div id="satis_aciklama_alani" style="display:block;" class="mb-3">
                            <label class="form-label fw-bold text-muted small">Hizmet / Açıklama</label>
                            <input type="text" name="urun_aciklama" id="edit_urun_aciklama" class="form-control fw-bold">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-muted small">İşlem Tarihi</label>
                            <input type="datetime-local" name="tarih" id="edit_tarih" class="form-control fw-bold" readonly>
                        </div>
                        
                        <div id="satis_alanlari" class="bg-white p-3 rounded-3 shadow-sm border mb-3">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-primary small">Vade / Hizmet Günü <span class="fw-normal text-muted">(Ürün vb. için boş)</span></label>
                                <input type="date" name="hizmet_tarihi" id="edit_hizmet_tarihi" class="form-control">
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6"><label class="form-label fw-bold text-muted small">Birim Fiyat</label><input type="number" step="0.01" name="birim_fiyat" id="edit_birim_fiyat" class="form-control"></div>
                                <div class="col-6"><label class="form-label fw-bold text-muted small">Adet</label><input type="number" name="adet" id="edit_adet" class="form-control"></div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6"><label class="form-label fw-bold text-muted small">KDV (%)</label><select name="kdv_orani" id="edit_kdv_orani" class="form-select"><option value="0">Yok</option><option value="1">%1</option><option value="10">%10</option><option value="20">%20</option></select></div>
                                <div class="col-6"><label class="form-label fw-bold text-muted small">İskonto (%)</label><input type="number" name="iskonto_orani" id="edit_iskonto_orani" class="form-control"></div>
                            </div>
                        </div>
                        
                        <div id="tahsilat_alanlari" style="display:none;" class="mb-3">
                            <label class="form-label fw-bold text-muted small">Ödeme Türü</label>
                            <select name="odeme_turu" id="edit_odeme_turu" class="form-select fw-bold text-primary border-primary">
                                <option value="0">Nakit</option>
                                <option value="1">Kredi Kartı</option>
                                <option value="2">Havale / EFT</option>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold text-danger small">Özel İşlem Notu</label>
                            <textarea name="islem_notu" id="edit_islem_notu" class="form-control border-danger" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary fw-bold rounded-pill px-5 shadow-sm" id="edit_submit_btn"><i class="fas fa-save me-1"></i>Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- YENİ MODAL: ZENGİN METİN NOT DEFTERİ (QUILL.JS) -->
    <div class="modal fade" id="modalNotDefteri" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" id="formRichText">
                    <input type="hidden" name="musteri_guncelle" value="1">
                    <input type="hidden" name="ad_soyad" value="<?= htmlspecialchars($musteri['ad_soyad']) ?>">
                    <input type="hidden" name="telefon" value="<?= htmlspecialchars($musteri['telefon'] ?? '') ?>">
                    <input type="hidden" name="tc_vergi_no" value="<?= htmlspecialchars($musteri['tc_vergi_no'] ?? '') ?>">
                    <input type="hidden" name="sozlesme_no" value="<?= htmlspecialchars($musteri['sozlesme_no'] ?? '') ?>">
                    <input type="hidden" name="adres" value="<?= htmlspecialchars($musteri['adres'] ?? '') ?>">
                    <input type="hidden" name="anlasma_tarihi" value="<?= htmlspecialchars($musteri['anlasma_tarihi'] ?? '') ?>">
                    <input type="hidden" name="ozel_notlar_html" id="ozel_notlar_html_input" value="">
                    
                    <div class="modal-header bg-warning border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold text-dark"><i class="fas fa-sticky-note me-2"></i>Müşteri Not Defteri</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <div class="alert alert-warning small border-0 shadow-sm py-2 mb-3"><i class="fas fa-info-circle me-1"></i> Bu alana yazdığınız notlar sadece sistem kullanıcıları tarafından görülebilir, müşteriye yansımaz.</div>
                        
                        <!-- QUILL EDITOR CONTAINER -->
                        <div id="editor-container" class="shadow-sm">
                            <?= $musteri['ozel_notlar_html'] ?? nl2br(htmlspecialchars($musteri['ozel_notlar'])) ?>
                        </div>

                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Kapat</button>
                        <button type="button" class="btn btn-warning text-dark fw-bold rounded-pill px-5 shadow-sm" onclick="saveRichText()"><i class="fas fa-save me-2"></i>Notları Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- YENİ MODAL: ETİKET YÖNETİMİ -->
    <div class="modal fade" id="modalEtiketYonetimi" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="etiketleri_kaydet" value="1">
                    <div class="modal-header bg-dark text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold"><i class="fas fa-tags me-2"></i>Müşteri Etiketleri Seçimi</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light text-center">
                        <p class="text-muted small mb-4">Bu müşteriye atamak istediğiniz etiketleri işaretleyin.</p>
                        
                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                            <?php 
                            $mevcut_et_idler = array_column($m_etiketler, 'id');
                            foreach($tum_etiketler as $te): 
                                $checked = in_array($te['id'], $mevcut_et_idler) ? 'checked' : '';
                            ?>
                                <input type="checkbox" class="btn-check" name="etiketler[]" id="et_<?= $te['id'] ?>" value="<?= $te['id'] ?>" <?= $checked ?> autocomplete="off">
                                <label class="btn btn-outline-dark rounded-pill fw-bold shadow-sm" for="et_<?= $te['id'] ?>" style="border-color: <?= $te['renk'] ?>;">
                                    <i class="fas fa-tag me-1" style="color: <?= $te['renk'] ?>;"></i> <?= htmlspecialchars($te['etiket_adi']) ?>
                                </label>
                            <?php endforeach; ?>
                            <?php if(empty($tum_etiketler)): ?>
                                <div class="alert alert-warning small w-100 border-0">Sistemde hiç etiket yok. Lütfen Ayarlar > Müşteri Etiketleri bölümünden etiket oluşturun.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4">
                        <button type="submit" class="btn btn-dark w-100 fw-bold rounded-pill py-2 shadow-sm"><i class="fas fa-save me-2"></i>Etiketleri Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- YENİ MODAL: PERSONEL GÖREV ATA -->
    <div class="modal fade" id="modalGorevEkle" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="gorev_ekle" value="1">
                    <div class="modal-header bg-primary text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold"><i class="fas fa-user-tag me-2"></i>Personel Görevlendir</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Hangi Personele Atanacak?</label>
                            <select name="personel_id" class="form-select fw-bold border-primary" required>
                                <option value="">Personel Seçin...</option>
                                <?php foreach($personeller as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ad_soyad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Görev Başlığı</label>
                            <input type="text" name="gorev_basligi" class="form-control fw-bold text-dark" required placeholder="Örn: Albüm Tasarımı, Dış Çekim Kurgusu">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Son Teslim Tarihi (Deadline)</label>
                            <input type="date" name="son_tarih" class="form-control text-danger fw-bold">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Görev Açıklaması / Detaylar</label>
                            <textarea name="gorev_aciklama" class="form-control" rows="3" placeholder="Lütfen renkleri canlı tutalım, müşteri şöyle istiyor..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary fw-bold rounded-pill px-5 shadow-sm"><i class="fas fa-paper-plane me-1"></i>Görevi Ata</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: SMS/WP ŞABLON GÖNDERİCİ -->
    <div class="modal fade" id="modalSablonGonder" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="sms_gonder_btn" value="1">
                    <div class="modal-header bg-info text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold"><i class="fas fa-comments me-2"></i>Hazır Mesaj & Şablon Gönder</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <div class="alert alert-info small py-2 border-0 shadow-sm"><i class="fas fa-info-circle me-1"></i> İletişim şablonlarınızı <b>Ayarlar > İletişim Şablonları</b> sayfasından ekleyebilirsiniz.</div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Şablon Seçin</label>
                            <select class="form-select fw-bold border-info" id="sablonSecici" onchange="sablonDoldur()">
                                <option value="">-- Listeden Şablon Seçin --</option>
                                <?php foreach($sablonlar as $s): ?>
                                    <?php
                                        $sb_icerik = str_replace(
                                            ['[musteri_adi]', '[kalan_borc]', '[firma_adi]'], 
                                            [$musteri['ad_soyad'], number_format($bakiye, 2), $_SESSION['firma_adi'] ?? ''], 
                                            $s['icerik']
                                        );
                                    ?>
                                    <option value="<?= htmlspecialchars($sb_icerik) ?>" data-tur="<?= $s['tur'] ?>">
                                        [<?= strtoupper($s['tur']) ?>] <?= htmlspecialchars($s['baslik']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Gidecek Mesajın Son Hali</label>
                            <textarea name="sms_sablon_icerik" id="sablonOnizleme" class="form-control bg-white" rows="6" readonly placeholder="Şablon seçtiğinizde burası dolacaktır..."></textarea>
                        </div>
                        <div class="alert alert-warning small py-2 mb-0 d-none" id="smsUyari"><i class="fas fa-shield-alt me-1"></i> Bu mesaj <b>SMS</b> olarak gönderilecektir ve kotanızdan düşecektir.</div>
                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Kapat</button>
                        <button type="button" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm d-none" id="btnWpGonder" onclick="sablonWpGonder()"><i class="fab fa-whatsapp me-2"></i>WP İle Gönder</button>
                        <button type="submit" class="btn btn-info text-white fw-bold rounded-pill px-4 shadow-sm d-none" id="btnSmsGonder"><i class="fas fa-comment-sms me-2"></i>SMS Olarak Fırlat</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- GÜVENLİ SMS GÖNDER ONAY POPUP'I (Hareket satırlarından tetiklenen) -->
    <div class="modal fade" id="modalSmsGonder" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="sms_gonder_btn" value="1">
                    <input type="hidden" name="hedef_hareket_id" id="sms_gizli_hareket_id">
                    
                    <div class="modal-header bg-info text-white border-0 py-3 px-4">
                        <h5 class="modal-title fw-bold"><i class="fas fa-comment-sms me-2"></i>Otomatik İşlem SMS'i Gönder</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <p class="mb-2 text-center">Aşağıdaki sistem mesajı <strong><span id="sms_goster_telefon" class="text-primary fs-5"></span></strong> numarasına gönderilecektir.</p>
                        <div class="mb-3">
                            <textarea id="sms_goster_mesaj" class="form-control text-muted fw-bold text-center" rows="5" style="background-color: #e9ecef; cursor: not-allowed; border: 2px dashed #0dcaf0;" disabled></textarea>
                            <div class="form-text small mt-2 text-danger text-center"><i class="fas fa-shield-alt"></i> Güvenlik gereği otomatik sistem mesajlarına müdahale edilemez.</div>
                        </div>
                        <div class="alert alert-warning py-2 mb-0 text-center shadow-sm border-0 small">
                            <i class="fas fa-coins me-1"></i> Bu işlem SMS bakiyenizden <b>1 adet</b> düşecektir.
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-info text-white fw-bold rounded-pill px-5 shadow-sm"><i class="fas fa-paper-plane me-2"></i>Gönder ve Kotadan Düş</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- DİĞER MODALLAR (SİL, ARŞİVLE, BİLGİ DÜZENLE) GİZLENDİ, YER İŞGAL ETMESİN -->
    <div class="modal fade modal-yonetim" id="modalSilSoft" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow-lg rounded-4"><form method="POST"><input type="hidden" name="musteri_sil_soft" value="1"><div class="modal-header bg-danger text-white border-0"><h5 class="modal-title fw-bold"><i class="fas fa-trash me-2"></i>Müşteriyi Sil</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light p-4"><div class="alert alert-warning border-0 shadow-sm small"><strong>Bu işlem müşteriyi Çöp Kutusuna taşıyacaktır.</strong><br>Kalıcı olarak silinmez, Çöp Kutusundan geri yükleyebilirsiniz.</div><div class="mb-1"><label class="form-label fw-bold small text-muted">Onay için Yönetici Şifresi:</label><input type="password" name="guvenlik_sifresi" class="form-control fw-bold border-danger" required></div></div><div class="modal-footer bg-white border-0"><button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">Evet, Çöpe At</button></div></form></div></div></div>
    <div class="modal fade modal-yonetim" id="modalArsivle" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow-lg rounded-4"><form method="POST"><input type="hidden" name="yeni_durum" value="0"><div class="modal-header bg-warning text-dark border-0"><h5 class="modal-title fw-bold"><i class="fas fa-archive me-2"></i>Arşive Kaldır</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light p-4"><p class="text-muted small fw-bold text-center">Bu müşteri pasif duruma getirilecek ve arşive kaldırılacaktır.</p><div class="mb-1"><label class="form-label fw-bold small text-muted">Yönetici Şifresi</label><input type="password" name="guvenlik_sifresi" class="form-control fw-bold border-warning" required></div></div><div class="modal-footer bg-white border-0"><button type="submit" class="btn btn-warning fw-bold w-100 rounded-pill shadow-sm">Onayla ve Arşivle</button></div></form></div></div></div>
    <div class="modal fade modal-yonetim" id="modalAktifEt" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow-lg rounded-4"><form method="POST"><input type="hidden" name="yeni_durum" value="1"><div class="modal-header bg-success text-white border-0"><h5 class="modal-title fw-bold"><i class="fas fa-undo me-2"></i>Aktif Et</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light p-4"><p class="text-muted small fw-bold text-center">Bu müşteri arşivi bırakıp tekrar aktif listeye alınacaktır.</p><div class="mb-1"><label class="form-label fw-bold small text-muted">Yönetici Şifresi</label><input type="password" name="guvenlik_sifresi" class="form-control fw-bold border-success" required></div></div><div class="modal-footer bg-white border-0"><button type="submit" class="btn btn-success fw-bold w-100 rounded-pill shadow-sm">Tekrar Aktif Et</button></div></form></div></div></div>
    
    <div class="modal fade" id="modalMusteriDuzenle" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content border-0 shadow-lg rounded-4"><form method="POST"><input type="hidden" name="musteri_guncelle" value="1"><div class="modal-header bg-primary text-white border-0 py-3 px-4"><h5 class="modal-title fw-bold"><i class="fas fa-user-edit me-2"></i>Müşteri Bilgilerini Düzenle</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-4 bg-light"><div class="row g-3 bg-white p-3 rounded-4 shadow-sm border"><div class="col-md-12"><label class="form-label fw-bold small text-muted">Genel Tanım / Liste Adı</label><input type="text" name="ad_soyad" class="form-control fw-bold text-dark" value="<?php echo htmlspecialchars($musteri['ad_soyad']); ?>" required></div><div class="col-md-6"><label class="form-label fw-bold small text-danger">Gelin Adı</label><input type="text" name="gelin_ad" class="form-control" value="<?php echo htmlspecialchars($musteri['gelin_ad'] ?? ''); ?>"></div><div class="col-md-6"><label class="form-label fw-bold small text-primary">Damat Adı</label><input type="text" name="damat_ad" class="form-control" value="<?php echo htmlspecialchars($musteri['damat_ad'] ?? ''); ?>"></div><div class="col-12"><hr class="my-1 border-light"></div><div class="col-md-6"><label class="form-label fw-bold small text-muted">Telefon (Başta 0 olmadan)</label><input type="text" name="telefon" class="form-control fw-bold" value="<?php echo htmlspecialchars(substr($musteri['telefon'], 1) ?? ''); ?>"></div><div class="col-md-6"><label class="form-label fw-bold small text-muted">Vergi / TC No</label><input type="text" name="tc_vergi_no" class="form-control" value="<?php echo htmlspecialchars($musteri['tc_vergi_no'] ?? ''); ?>"></div><div class="col-12"><label class="form-label fw-bold small text-muted">Açık Adres</label><textarea name="adres" class="form-control" rows="2"><?php echo htmlspecialchars($musteri['adres'] ?? ''); ?></textarea></div><div class="col-12"><hr class="my-1 border-light"></div><div class="col-md-6"><label class="form-label fw-bold small text-muted">Sözleşme No</label><input type="text" name="sozlesme_no" class="form-control" value="<?php echo htmlspecialchars($musteri['sozlesme_no'] ?? ''); ?>"></div><div class="col-md-6"><label class="form-label fw-bold small text-muted">Anlaşma Tarihi</label><input type="date" name="anlasma_tarihi" class="form-control" value="<?php echo htmlspecialchars($musteri['anlasma_tarihi'] ?? ''); ?>"></div></div></div><div class="modal-footer bg-white border-top-0 py-3 px-4 rounded-bottom-4"><button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary fw-bold rounded-pill px-5 shadow-sm"><i class="fas fa-save me-1"></i>Güncelle</button></div></form></div></div></div>

    <div id="toast-container-yonetim"></div>

    <!-- SCRIPT DOSYALARI -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/yonetim.js"></script>
    <!-- Quill JS -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    
    <script>
        // -------------------------------------------------------------
        // QUILL.JS (RICH TEXT NOT DEFTERİ) BAŞLATMA
        // -------------------------------------------------------------
        var quill = new Quill('#editor-container', {
            theme: 'snow',
            placeholder: 'Müşteri hakkında özel notlar, iptal sebepleri veya ek istekleri buraya yazın...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline', 'strike'], 
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    [{ 'color': [] }, { 'background': [] }], 
                    ['clean'] 
                ]
            }
        });

        function saveRichText() {
            var html = document.querySelector('#editor-container .ql-editor').innerHTML;
            document.getElementById('ozel_notlar_html_input').value = html;
            document.getElementById('formRichText').submit();
        }

        // -------------------------------------------------------------
        // VADE SİHİRBAZI & CANLI HESAPLAMA SİSTEMİ
        // -------------------------------------------------------------
        const vadeOranlari = <?= json_encode($vadeOranlari) ?>;

        window.canliHesapla = function() {
            let adet = parseFloat(document.getElementById("adet_input").value) || 0;
            let fiyat = parseFloat(document.getElementById("fiyat_input").value) || 0;
            let iskonto = parseFloat(document.getElementById("iskonto_input").value) || 0;
            let kdv = document.getElementById("kdv_input").value;
            let taksitSelect = document.getElementById("taksit_input");
            let taksitSayisi = parseInt(taksitSelect.value) || 1;

            let calcBox = document.getElementById("live_calc_box");
            
            if (fiyat > 0) { calcBox.style.display = "block"; } 
            else { calcBox.style.display = "none"; return; }

            let araToplam = adet * fiyat;
            let iskontoTutar = araToplam * (iskonto / 100);
            let netTutar = araToplam - iskontoTutar;
            let matrah = 0, kdvTutar = 0, pesinGenelToplam = 0;

            if (kdv === "0") {
                matrah = netTutar / 1.20;
                kdvTutar = netTutar - matrah;
                pesinGenelToplam = netTutar;
            } else {
                matrah = netTutar;
                kdvTutar = matrah * 0.20;
                pesinGenelToplam = matrah + kdvTutar;
            }

            // VADE FARKI HESABI
            let finalBorc = pesinGenelToplam;
            let aylikTaksit = pesinGenelToplam;
            let vadeFarkiTutari = 0;
            let vadeRow = document.getElementById("vade_farki_row");

            if (taksitSayisi > 1) {
                let vadeYuzde = parseFloat(taksitSelect.options[taksitSelect.selectedIndex].getAttribute('data-oran')) || 0;
                vadeFarkiTutari = pesinGenelToplam * (vadeYuzde / 100);
                finalBorc = pesinGenelToplam + vadeFarkiTutari;
                aylikTaksit = finalBorc / taksitSayisi;
                
                vadeRow.style.display = "block";
                document.getElementById("calc_vade_ekli").innerText = "+" + vadeFarkiTutari.toFixed(2) + " ₺";
                document.getElementById("calc_taksit").innerText = aylikTaksit.toFixed(2) + " ₺ x " + taksitSayisi + " Ay";
            } else {
                vadeRow.style.display = "none";
            }

            // HTML Elementlerini Güncelle
            document.getElementById("calc_ara").innerText = araToplam.toFixed(2) + " ₺";

            if (iskonto > 0) {
                document.getElementById("calc_iskonto_row").style.setProperty("display", "flex", "important");
                document.getElementById("calc_iskonto").innerText = "-" + iskontoTutar.toFixed(2) + " ₺";
            } else {
                document.getElementById("calc_iskonto_row").style.setProperty("display", "none", "important");
            }

            document.getElementById("calc_matrah").innerText = matrah.toFixed(2) + " ₺";
            document.getElementById("calc_kdv").innerText = kdvTutar.toFixed(2) + " ₺";
            document.getElementById("calc_toplam").innerText = finalBorc.toFixed(2) + " ₺";
        };

        // Hizmet Seçilince Fiyatı Otomatik Çekme
        document.addEventListener("DOMContentLoaded", function() {
            var urunInput = document.getElementById("urun_input");
            if (urunInput) {
                urunInput.addEventListener("input", function() {
                    var val = this.value;
                    var list = document.getElementById("hizmetListesi").options;
                    for (var i = 0; i < list.length; i++) {
                        if (list[i].value === val) {
                            var fiyatInput = document.getElementById("fiyat_input");
                            if(fiyatInput) { 
                                fiyatInput.value = list[i].getAttribute("data-fiyat"); 
                                if(typeof canliHesapla === 'function') canliHesapla(); 
                            }
                            break;
                        }
                    }
                });
            }
        });

        // -------------------------------------------------------------
        // DİĞER MODAL VE YARDIMCI SCRİPTLER
        // -------------------------------------------------------------
        window.smsModalAc = function(hareket_id, telefon, mesaj) {
            document.getElementById("sms_gizli_hareket_id").value = hareket_id;
            document.getElementById("sms_goster_telefon").innerText = "+" + telefon; 
            document.getElementById("sms_goster_mesaj").value = mesaj;
            var modalEl = document.getElementById("modalSmsGonder");
            var myModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            myModal.show();
        };

        function sablonDoldur() {
            var s = document.getElementById('sablonSecici');
            var txt = document.getElementById('sablonOnizleme');
            var wpBtn = document.getElementById('btnWpGonder');
            var smsBtn = document.getElementById('btnSmsGonder');
            var smsUyari = document.getElementById('smsUyari');
            
            var selectedOption = s.options[s.selectedIndex];
            
            if (s.value === '') {
                txt.value = '';
                wpBtn.classList.add('d-none');
                smsBtn.classList.add('d-none');
                smsUyari.classList.add('d-none');
            } else {
                txt.value = s.value;
                var tur = selectedOption.getAttribute('data-tur');
                
                if (tur === 'wp') {
                    wpBtn.classList.remove('d-none');
                    smsBtn.classList.add('d-none');
                    smsUyari.classList.add('d-none');
                } else {
                    smsBtn.classList.remove('d-none');
                    wpBtn.classList.add('d-none');
                    smsUyari.classList.remove('d-none');
                }
            }
        }
        
        function sablonWpGonder() {
            var msj = document.getElementById('sablonOnizleme').value;
            var phone = "<?= $wp_no ?>";
            window.open(`https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(msj)}`, '_blank');
        }

        window.hareketDuzenle = function(btn) {
            var rawData = btn.getAttribute("data-hareket");
            if (!rawData) { alert("Veri okunamadı!"); return; }
            try { var data = JSON.parse(rawData); } catch(e) { return; }

            if(document.getElementById("edit_hareket_id")) document.getElementById("edit_hareket_id").value = data.id;
            if(document.getElementById("edit_islem_turu")) document.getElementById("edit_islem_turu").value = data.islem_turu;
            if(document.getElementById("edit_islem_notu")) document.getElementById("edit_islem_notu").value = data.notlar || "";
            if(document.getElementById("edit_hizmet_tarihi")) document.getElementById("edit_hizmet_tarihi").value = data.vade_tarihi ? data.vade_tarihi : ""; 
            if(data.islem_tarihi && document.getElementById("edit_tarih")) document.getElementById("edit_tarih").value = data.islem_tarihi.replace(" ", "T").substring(0, 16);

            var divSatis = document.getElementById("satis_alanlari");
            var divSatisAciklama = document.getElementById("satis_aciklama_alani");
            var divTahsilat = document.getElementById("tahsilat_alanlari");
            var divTahsilatTutar = document.getElementById("tahsilat_tutar_alani");
            var header = document.getElementById("editModalHeader");
            var submitBtn = document.getElementById("edit_submit_btn");

            if(data.islem_turu == "satis") {
                if(divSatis) divSatis.style.display = "block";
                if(divSatisAciklama) divSatisAciklama.style.display = "block";
                if(divTahsilat) divTahsilat.style.display = "none";
                if(divTahsilatTutar) divTahsilatTutar.style.display = "none";
                
                if(document.getElementById("edit_urun_aciklama")) document.getElementById("edit_urun_aciklama").value = data.urun_aciklama || "";
                if(document.getElementById("edit_birim_fiyat")) document.getElementById("edit_birim_fiyat").value = data.birim_fiyat;
                if(document.getElementById("edit_adet")) document.getElementById("edit_adet").value = data.adet;
                if(document.getElementById("edit_kdv_orani")) document.getElementById("edit_kdv_orani").value = data.kdv_orani;
                if(document.getElementById("edit_iskonto_orani")) document.getElementById("edit_iskonto_orani").value = data.iskonto_orani;
                
                header.className = "modal-header bg-primary text-white border-0 py-3 px-4";
                submitBtn.className = "btn btn-primary fw-bold rounded-pill px-5 shadow-sm";
            } else {
                if(divSatis) divSatis.style.display = "none";
                if(divSatisAciklama) divSatisAciklama.style.display = "none";
                if(divTahsilat) divTahsilat.style.display = "block";
                if(divTahsilatTutar) divTahsilatTutar.style.display = "block";
                
                if(document.getElementById("edit_tutar")) document.getElementById("edit_tutar").value = data.toplam_tutar;
                if(document.getElementById("edit_odeme_turu")) document.getElementById("edit_odeme_turu").value = data.odeme_turu || 0;
                
                header.className = "modal-header bg-success text-white border-0 py-3 px-4";
                submitBtn.className = "btn btn-success fw-bold rounded-pill px-5 shadow-sm";
            }
            var modalEl = document.getElementById("modalHareketDuzenle");
            if(modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
        };
    </script>
</body>
</html>