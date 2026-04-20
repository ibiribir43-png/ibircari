<?php
// Hata Gösterimi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
if (!isset($_SESSION['kullanici_id'])) {
    header("Location: index.php");
    exit;
}

$kullanici_id = $_SESSION['kullanici_id'];
$aktif_talep_id = isset($_GET['tid']) ? (int)$_GET['tid'] : 0;

// --- KRİTİK DÜZELTME: FİRMA ID'Yİ GÜVENLİ ÇEKME ---
// Session'a güvenmek yerine veritabanından taze çekiyoruz.
$stmtFid = $db->prepare("SELECT firma_id FROM yoneticiler WHERE id = ?");
$stmtFid->execute([$kullanici_id]);
$db_firma_id = $stmtFid->fetchColumn();

// Eğer veritabanından bir değer döndüyse onu kullan, yoksa session'dakini al (Yedek)
$firma_id = !empty($db_firma_id) ? $db_firma_id : ($_SESSION['firma_id'] ?? 0);

// Firma ID hala 0 veya boşsa işlemi durdur (Veri bütünlüğü için)
if(empty($firma_id)) {
    die("<div class='container p-5 text-center alert alert-danger'>Hata: Firma kimliğiniz doğrulanamadı. Lütfen çıkış yapıp tekrar girin.</div>");
}
// ----------------------------------------------------

// --- EKSİK DEĞİŞKENLERİ TAMAMLAMA (NAVBAR İÇİN) ---
$stmtUser = $db->prepare("SELECT kullanici_adi, rol FROM yoneticiler WHERE id = ?");
$stmtUser->execute([$kullanici_id]);
$currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

$kullanici_adi = $currentUser['kullanici_adi'] ?? 'Kullanıcı';
$rol = $currentUser['rol'] ?? 'personel';

$stmtFirma = $db->prepare("SELECT firma_adi FROM firmalar WHERE id = ?");
$stmtFirma->execute([$firma_id]);
$currentFirma = $stmtFirma->fetch(PDO::FETCH_ASSOC);

$firma_adi = $currentFirma['firma_adi'] ?? 'Firma Adı Yok';
// ---------------------------------------------------

// Sayfa Başlığı
$page_title = "Destek Merkezi";

// --- İŞLEMLER (POST) ---

// 1. YENİ TALEP OLUŞTUR
if (isset($_POST['yeni_talep'])) {
    $konu = trim($_POST['konu']);
    $ilk_mesaj = trim($_POST['mesaj']);
    
    if($konu && $ilk_mesaj) {
        $db->beginTransaction();
        try {
            // Talebi oluştur (Senin verdiğin SQL yapısına tam uyumlu)
            // Sütunlar: firma_id, kullanici_id, konu, mesaj, oncelik, durum, tarih, son_islem_tarihi
            $stmt = $db->prepare("INSERT INTO destek_talepleri (firma_id, kullanici_id, konu, mesaj, oncelik, durum, tarih, son_islem_tarihi) VALUES (?, ?, ?, ?, 'orta', 'bekliyor', NOW(), NOW())");
            $stmt->execute([$firma_id, $kullanici_id, $konu, $ilk_mesaj]);
            
            $yeni_id = $db->lastInsertId();
            
            // İlk mesajı mesajlar tablosuna da ekle
            // Sütunlar: talep_id, gonderen_id, gonderen_tipi, mesaj, tarih, okundu
            $stmt2 = $db->prepare("INSERT INTO destek_mesajlari (talep_id, gonderen_id, gonderen_tipi, mesaj, tarih, okundu) VALUES (?, ?, 'firma', ?, NOW(), 0)");
            $stmt2->execute([$yeni_id, $kullanici_id, $ilk_mesaj]);
            
            $db->commit();
            header("Location: destek.php?tid=$yeni_id"); exit;
        } catch(Exception $e) {
            $db->rollBack();
            die("Veritabanı Hatası: " . $e->getMessage());
        }
    }
}

// 2. MESAJ GÖNDER
if (isset($_POST['mesaj_gonder']) && $aktif_talep_id > 0) {
    $mesaj = trim($_POST['mesaj']);
    if($mesaj) {
        // Güvenlik: Talep gerçekten bu firmaya mı ait?
        $chk = $db->prepare("SELECT id FROM destek_talepleri WHERE id = ? AND firma_id = ?");
        $chk->execute([$aktif_talep_id, $firma_id]);
        
        if($chk->fetch()) {
            // Mesajı Kaydet
            $stmt = $db->prepare("INSERT INTO destek_mesajlari (talep_id, gonderen_id, gonderen_tipi, mesaj, tarih, okundu) VALUES (?, ?, 'firma', ?, NOW(), 0)");
            $stmt->execute([$aktif_talep_id, $kullanici_id, $mesaj]);
            
            // Talebi güncelle
            $db->prepare("UPDATE destek_talepleri SET durum = 'bekliyor', son_islem_tarihi = NOW() WHERE id = ?")->execute([$aktif_talep_id]);
            
            header("Location: destek.php?tid=$aktif_talep_id"); exit;
        }
    }
}

// --- VERİ ÇEKME ---

// Canlı Destek Durumu
$canliDestekDurumu = $db->query("SELECT ayar_degeri FROM sistem_ayarlari WHERE ayar_adi = 'canli_destek'")->fetchColumn();
$isOnline = ($canliDestekDurumu == 'aktif');

// Sol Menü: Taleplerim (SADECE BU FİRMAYA AİT OLANLAR)
$sqlTalepler = "
    SELECT t.*, 
    (SELECT COUNT(*) FROM destek_mesajlari WHERE talep_id = t.id AND okundu = 0 AND gonderen_tipi = 'admin') as okunmamis,
    (SELECT mesaj FROM destek_mesajlari WHERE talep_id = t.id ORDER BY id DESC LIMIT 1) as son_mesaj
    FROM destek_talepleri t 
    WHERE t.firma_id = ? 
    ORDER BY t.son_islem_tarihi DESC
";
$stmt = $db->prepare($sqlTalepler);
$stmt->execute([$firma_id]);
$talepler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sağ Taraf: Seçili Talep ve Mesajları
$mesajlar = [];
$seciliTalep = null;

if($aktif_talep_id > 0) {
    // Sadece kendi firmasına aitse çek (Güvenlik)
    $stmtT = $db->prepare("SELECT * FROM destek_talepleri WHERE id = ? AND firma_id = ?");
    $stmtT->execute([$aktif_talep_id, $firma_id]);
    $seciliTalep = $stmtT->fetch(PDO::FETCH_ASSOC);
    
    if($seciliTalep) {
        // Talebe ait mesajları çek
        $stmtM = $db->prepare("SELECT * FROM destek_mesajlari WHERE talep_id = ? ORDER BY tarih ASC");
        $stmtM->execute([$aktif_talep_id]);
        $mesajlar = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        
        // Kullanıcı mesajları gördüğü için, Admin'den gelen mesajları 'okundu' yap
        $db->prepare("UPDATE destek_mesajlari SET okundu = 1 WHERE talep_id = ? AND gonderen_tipi = 'admin'")->execute([$aktif_talep_id]);
    }
}

// CSS
$inline_css = '
    .chat-layout { display: flex; height: calc(100vh - 140px); background: #fff; border-radius: 15px; overflow: hidden; box-shadow: 0 5px 25px rgba(0,0,0,0.05); }
    .chat-sidebar { width: 320px; background: #f8f9fa; border-right: 1px solid #eee; display: flex; flex-direction: column; }
    .chat-main { flex: 1; display: flex; flex-direction: column; background: #fff; position: relative; }
    
    /* Liste Öğeleri */
    .ticket-item { padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: 0.2s; text-decoration: none; color: inherit; display: block; position: relative; }
    .ticket-item:hover, .ticket-item.active { background: #fff; border-left: 4px solid #0d6efd; }
    .ticket-item.active { background: #eef5ff; }
    .badge-count { position: absolute; right: 10px; top: 15px; background: #dc3545; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; }

    /* Mesaj Alanı */
    .msg-container { flex: 1; padding: 20px; overflow-y: auto; background: #f4f6f8; display: flex; flex-direction: column; gap: 10px; }
    .msg-bubble { max-width: 75%; padding: 10px 15px; border-radius: 15px; font-size: 0.95rem; line-height: 1.5; position: relative; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    
    /* Firma (Ben) - Sağ */
    .msg-me { align-self: flex-end; background: #d1e7dd; color: #146c43; border-bottom-right-radius: 2px; }
    .msg-me .meta { font-size: 0.7rem; color: #0f5132; text-align: right; opacity: 0.7; margin-top: 4px; }
    
    /* Admin (Karşı) - Sol */
    .msg-other { align-self: flex-start; background: #fff; color: #333; border-bottom-left-radius: 2px; }
    .msg-other .meta { font-size: 0.7rem; color: #999; margin-top: 4px; }

    .chat-input-box { padding: 15px; background: #fff; border-top: 1px solid #eee; }
    .status-badge { font-size: 0.8rem; padding: 5px 12px; border-radius: 20px; display: inline-flex; align-items: center; gap: 5px; }
    .status-online { background: #d1e7dd; color: #0f5132; }
    .status-offline { background: #f8d7da; color: #842029; }
    
    @media (max-width: 768px) {
        .chat-layout { flex-direction: column; height: auto; }
        .chat-sidebar { width: 100%; height: 300px; }
        .chat-main { height: 500px; }
    }
';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | ibiR Cari</title>
    
    <!-- YÖNETİM CSS -->
    <link rel="stylesheet" href="css/yonetim.css">
    
    <!-- BOOTSTRAP & FONTAWESOME -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        <?php echo $inline_css; ?>
    </style>
</head>
<body class="yonetim-body">

    <!-- NAVBAR -->
    <?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim pb-5">
        
        <!-- BAŞLIK ALANI -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="mb-0 fw-bold text-secondary"><i class="fas fa-headset me-2"></i>Destek Merkezi</h3>
                <small class="text-muted">Sorularınız ve talepleriniz için buradayız.</small>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Canlı Destek Durumu -->
                <?php if($isOnline): ?>
                    <div class="status-badge status-online border border-success">
                        <span class="spinner-grow spinner-grow-sm" role="status"></span> Canlı Destek Çevrimiçi
                    </div>
                <?php else: ?>
                    <div class="status-badge status-offline border border-danger">
                        <i class="fas fa-moon"></i> Destek Çevrimdışı (Mesaj Bırakın)
                    </div>
                <?php endif; ?>
                
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalYeniTalep">
                    <i class="fas fa-plus me-2"></i>Yeni Destek Talebi
                </button>
            </div>
        </div>

        <!-- CHAT LAYOUT -->
        <div class="chat-layout border">
            
            <!-- SOL MENÜ: TALEPLER -->
            <div class="chat-sidebar">
                <div class="p-3 border-bottom bg-light">
                    <input type="text" class="form-control form-control-sm" placeholder="Talep ara...">
                </div>
                <div class="flex-grow-1 overflow-auto">
                    <?php if(count($talepler) > 0): ?>
                        <?php foreach($talepler as $t): ?>
                        <a href="?tid=<?php echo $t['id']; ?>" class="ticket-item <?php echo $aktif_talep_id == $t['id'] ? 'active' : ''; ?>">
                            <div class="d-flex justify-content-between mb-1">
                                <strong class="text-dark text-truncate" style="max-width: 70%;">#<?php echo htmlspecialchars($t['konu']); ?></strong>
                                <small class="text-muted" style="font-size:11px;">
                                    <?php 
                                    // Tarih Gösterimi (Düzeltildi)
                                    // tablonuzda 'tarih' sütunu var, onu kullanıyoruz.
                                    $tarihGoster = $t['tarih'] ?? $t['son_islem_tarihi'];
                                    echo $tarihGoster ? date("d.m H:i", strtotime($tarihGoster)) : '-'; 
                                    ?>
                                </small>
                            </div>
                            <div class="small text-muted text-truncate">
                                <?php echo $t['son_mesaj'] ? htmlspecialchars($t['son_mesaj']) : 'Mesaj yok...'; ?>
                            </div>
                            <?php if($t['okunmamis'] > 0): ?>
                                <span class="badge-count"><?php echo $t['okunmamis']; ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center p-5 text-muted small">
                            <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>
                            Henüz destek talebiniz bulunmuyor.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SAĞ ALAN: MESAJLAŞMA -->
            <div class="chat-main">
                <?php if($seciliTalep): ?>
                    <!-- Chat Header -->
                    <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($seciliTalep['konu']); ?></h6>
                            <span class="badge bg-<?php echo $seciliTalep['durum']=='yanitlandi'?'success':($seciliTalep['durum']=='kapali'?'secondary':'warning'); ?> small">
                                <?php echo ucfirst($seciliTalep['durum']); ?>
                            </span>
                            <small class="text-muted ms-2">Talep ID: #<?php echo $seciliTalep['id']; ?></small>
                        </div>
                        <?php if($seciliTalep['durum'] == 'kapali'): ?>
                            <span class="text-danger small fw-bold"><i class="fas fa-lock me-1"></i>Konu Kapalı</span>
                        <?php endif; ?>
                    </div>

                    <!-- Mesajlar -->
                    <div class="msg-container" id="msgBox">
                        <div class="text-center my-3">
                            <span class="badge bg-light text-secondary border fw-normal">
                                Talep Oluşturuldu: 
                                <?php 
                                    $tarihBaslangic = $seciliTalep['tarih'] ?? $seciliTalep['son_islem_tarihi'];
                                    echo date("d.m.Y H:i", strtotime($tarihBaslangic)); 
                                ?>
                            </span>
                        </div>
                        
                        <?php foreach($mesajlar as $m): 
                            $benim = ($m['gonderen_tipi'] == 'firma');
                        ?>
                            <div class="msg-bubble <?php echo $benim ? 'msg-me' : 'msg-other'; ?>">
                                <?php echo nl2br(htmlspecialchars($m['mesaj'])); ?>
                                <div class="meta">
                                    <?php echo date("H:i", strtotime($m['tarih'])); ?>
                                    <?php if($benim): ?>
                                        <i class="fas fa-check<?php echo $m['okundu']?'-double':''; ?>"></i>
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
                            <input type="hidden" name="mesaj_gonder" value="1">
                            <input type="text" name="mesaj" class="form-control rounded-pill" placeholder="Mesajınızı yazın..." autocomplete="off" required>
                            <button class="btn btn-primary rounded-circle" style="width: 45px; height: 45px;"><i class="fas fa-paper-plane"></i></button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <script>
                        // Otomatik aşağı kaydır
                        var msgBox = document.getElementById("msgBox");
                        if(msgBox) msgBox.scrollTop = msgBox.scrollHeight;
                    </script>

                <?php else: ?>
                    <!-- Boş Durum -->
                    <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
                        <img src="https://cdn-icons-png.flaticon.com/512/3063/3063822.png" width="80" class="mb-3 opacity-50">
                        <h5>Bir talep seçin veya yeni oluşturun.</h5>
                        <p class="small">Sol menüden geçmiş taleplerinize ulaşabilirsiniz.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL: YENİ TALEP -->
    <div class="modal fade" id="modalYeniTalep" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="yeni_talep" value="1">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Yeni Destek Talebi</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Konu Başlığı</label>
                            <select name="konu" class="form-select" required>
                                <option value="">Seçiniz...</option>
                                <option>Teknik Sorun</option>
                                <option>Ödeme / Abonelik</option>
                                <option>Öneri / İstek</option>
                                <option>Diğer</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Mesajınız</label>
                            <textarea name="mesaj" class="form-control" rows="5" placeholder="Sorununuzu detaylı anlatınız..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i> Talebi Gönder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<!-- TOAST CONTAINER -->
    <div id="toast-container-yonetim"></div>

<?php
// FOOTER'I ÇAĞIR
// $extra_js değişkeni Chart.js'i otomatik ekleyecek
require_once 'partials/footer_yonetim.php';
?>