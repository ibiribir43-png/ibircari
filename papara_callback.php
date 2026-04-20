<?php
// Bu sayfaya sadece Papara arka plandan erişir. Oturum (session) kontrolü YAPILMAZ!
require 'baglanti.php';

// Papara'dan gelen JSON verisini alıyoruz
$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

// Geçersiz istekse işlemi durdur
if (!$data) {
    http_response_code(400);
    die("Bad Request: Payload missing.");
}

$siparis_kodu = $data['referenceId'] ?? '';
$status = $data['status'] ?? 0; // Papara'da ödeme başarılıysa status 1 döner.
$pos_islem_id = $data['id'] ?? ''; // Papara'nın kendi sistemindeki işlem numarası

if (empty($siparis_kodu)) {
    http_response_code(400);
    die("Bad Request: Reference ID missing.");
}

// 1. Siparişi Veritabanından Bul
$siparis = $db->query("SELECT * FROM abonelik_siparisleri WHERE siparis_kodu = '$siparis_kodu'")->fetch(PDO::FETCH_ASSOC);

if (!$siparis) {
    http_response_code(404);
    die("Order not found.");
}

// 2. Sipariş daha önce işlenmiş mi kontrol et (Mükerrer işlemi önle)
if ($siparis['durum'] != 'bekliyor') {
    http_response_code(200);
    die("Order already processed.");
}

// 3. Ödeme Başarılı İse Limitleri Yükselt (status == 1)
if ($status == 1) {
    $firma_id = $siparis['firma_id'];
    $satin_alinan_id = $siparis['satin_alinan_id'];
    
    // YENİ: Sipariş tipini ve satın alınan ayı ayrıştırıyoruz (Örn: "paket-6" -> tip: paket, ay: 6)
    $tip_parcalari = explode('-', $siparis['satin_alinan_tip']);
    $tip = $tip_parcalari[0]; // 'paket' veya 'ek_hizmet'
    $alinan_ay = isset($tip_parcalari[1]) ? (int)$tip_parcalari[1] : 1;

    try {
        // İşlemleri garantilemek için Transaction başlatıyoruz
        $db->beginTransaction();

        // Sipariş durumunu 'basarili' olarak güncelle
        $db->query("UPDATE abonelik_siparisleri SET durum = 'basarili', pos_islem_id = '$pos_islem_id', tamamlanma_tarihi = NOW() WHERE id = " . $siparis['id']);

        if ($tip == 'paket' || $tip == 'ana_paket') {
            // ANA PAKET ALINDIYSA
            $paket = $db->query("SELECT * FROM paketler WHERE id = $satin_alinan_id")->fetch(PDO::FETCH_ASSOC);
            if ($paket) {
                $aylik_sms = (int)$paket['sms_limiti'];
                $eklenecek_gun = $alinan_ay * 30; // Kaç aylık alındıysa (Örn: 6 Ay = 180 Gün)
                
                // Mevcut bitiş tarihini kontrol et, süre ekle
                $firma = $db->query("SELECT abonelik_bitis FROM firmalar WHERE id = '$firma_id'")->fetch(PDO::FETCH_ASSOC);
                
                $yeni_bitis = "DATE_ADD(CURDATE(), INTERVAL $eklenecek_gun DAY)"; 
                if (!empty($firma['abonelik_bitis']) && strtotime($firma['abonelik_bitis']) > time()) {
                    // Paketi henüz bitmemişse (erken yenileme yaptıysa), kalan sürenin üstüne ekle
                    $yeni_bitis = "DATE_ADD('{$firma['abonelik_bitis']}', INTERVAL $eklenecek_gun DAY)";
                }
                
                $db->query("UPDATE firmalar SET 
                            paket_id = $satin_alinan_id, 
                            aylik_sms_limiti = $aylik_sms,
                            abonelik_bitis = $yeni_bitis,
                            son_abonelik_baslangic = CURDATE()
                            WHERE id = '$firma_id'");
            }
        } elseif ($tip == 'ek_hizmet') {
            // EK HİZMET ALINDIYSA (Ekstra SMS, Depolama, Trafik)
            $hizmet = $db->query("SELECT * FROM ek_hizmetler WHERE id = $satin_alinan_id")->fetch(PDO::FETCH_ASSOC);
            if ($hizmet) {
                $deger = (int)$hizmet['deger'];
                if ($hizmet['tip'] == 'sms') {
                    // Kontör SMS (Sürekli kalır)
                    $db->query("UPDATE firmalar SET ek_sms_bakiyesi = ek_sms_bakiyesi + $deger WHERE id = '$firma_id'");
                } elseif ($hizmet['tip'] == 'depolama') {
                    // Ek Depolama (Paket süresi boyunca kalır)
                    $db->query("UPDATE firmalar SET ek_depolama_alani = ek_depolama_alani + $deger WHERE id = '$firma_id'");
                } elseif ($hizmet['tip'] == 'trafik') {
                    // Ek Trafik (O ayın sonuna kadar)
                    $db->query("UPDATE firmalar SET ek_trafik_limiti = ek_trafik_limiti + $deger WHERE id = '$firma_id'");
                }
            }
        }

        // Hata yoksa işlemi veritabanına kalıcı olarak yaz
        $db->commit();
        http_response_code(200);
        echo "OK"; // Papara 200 OK mesajı bekler
        
    } catch (Exception $e) {
        $db->rollBack(); // Hata olursa işlemleri geri al
        http_response_code(500);
        die("Database Error.");
    }

} else {
    // Ödeme başarısız veya müşteri kartından çekilemediyse
    $db->query("UPDATE abonelik_siparisleri SET durum = 'hata', pos_islem_id = '$pos_islem_id', hata_mesaji = 'Papara Status: $status' WHERE id = " . $siparis['id']);
    http_response_code(200);
    echo "OK";
}
?>