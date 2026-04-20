<?php
// Hata Gösterimi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ (Sadece Süper Admin)
if (!isset($_SESSION['kullanici_id']) || $_SESSION['rol'] != 'super_admin') {
    die("<div class='container p-5 text-center'><h3>⛔ Yetkisiz Erişim</h3><p>Bu sayfaya sadece yöneticiler erişebilir.</p><a href='index.php' class='btn btn-primary'>Giriş Yap</a></div>");
}

$admin_id = $_SESSION['kullanici_id'];
$aktif_talep_id = isset($_GET['tid']) ? (int)$_GET['tid'] : 0;

// --- İŞLEMLER (POST) ---

// 1. CANLI DESTEK DURUMUNU DEĞİŞTİR
if (isset($_POST['canli_destek_toggle'])) {
    $yeni_durum = $_POST['yeni_durum']; // 'aktif' veya 'kapali'
    $db->prepare("UPDATE sistem_ayarlari SET ayar_degeri = ? WHERE ayar_adi = 'canli_destek'")->execute([$yeni_durum]);
    header("Location: destekler.php" . ($aktif_talep_id ? "?tid=$aktif_talep_id" : "")); 
    exit;
}

// 2. MESAJ GÖNDER
if (isset($_POST['mesaj_gonder']) && $aktif_talep_id > 0) {
    $mesaj = trim($_POST['mesaj']);
    if (!empty($mesaj)) {
        // Mesajı Kaydet
        $stmt = $db->prepare("INSERT INTO destek_mesajlari (talep_id, gonderen_id, gonderen_tipi, mesaj, tarih, okundu) VALUES (?, ?, 'admin', ?, NOW(), 0)");
        $stmt->execute([$aktif_talep_id, $admin_id, $mesaj]);

        // Talebin durumunu ve tarihini güncelle (Yanıtlandı yap)
        try {
            $db->prepare("UPDATE destek_talepleri SET durum = 'yanitlandi', son_islem_tarihi = NOW() WHERE id = ?")->execute([$aktif_talep_id]);
        } catch (PDOException $e) {
            $db->prepare("UPDATE destek_talepleri SET durum = 'yanitlandi' WHERE id = ?")->execute([$aktif_talep_id]);
        }
        
        header("Location: destekler.php?tid=$aktif_talep_id");
        exit;
    }
}

// 3. TALEBİ KAPAT
if (isset($_POST['talebi_kapat']) && $aktif_talep_id > 0) {
    $db->prepare("UPDATE destek_talepleri SET durum = 'kapali' WHERE id = ?")->execute([$aktif_talep_id]);
    header("Location: destekler.php"); // Listeye dön (veya aynı sayfada kalabilirsin: ?tid=$aktif_talep_id)
    exit;
}

// 4. TALEBİ TEKRAR AÇ (YENİ)
if (isset($_POST['talebi_ac']) && $aktif_talep_id > 0) {
    // Durumu 'bekliyor' veya 'yanitlandi' yapabiliriz. Admin açtığı için 'yanitlandi' mantıklı olabilir veya işlem beklediği için 'bekliyor'.
    // Genelde tekrar açılınca 'bekliyor' yapılır.
    try {
        $db->prepare("UPDATE destek_talepleri SET durum = 'bekliyor', son_islem_tarihi = NOW() WHERE id = ?")->execute([$aktif_talep_id]);
    } catch (PDOException $e) {
        $db->prepare("UPDATE destek_talepleri SET durum = 'bekliyor' WHERE id = ?")->execute([$aktif_talep_id]);
    }
    
    // Sistem mesajı ekle (Opsiyonel)
    $sysMsg = "--- Talep Yönetici Tarafından Tekrar Açıldı ---";
    $db->prepare("INSERT INTO destek_mesajlari (talep_id, gonderen_id, gonderen_tipi, mesaj, tarih, okundu) VALUES (?, ?, 'admin', ?, NOW(), 1)")->execute([$aktif_talep_id, $admin_id, $sysMsg]);

    header("Location: destekler.php?tid=$aktif_talep_id");
    exit;
}

// --- VERİ ÇEKME ---

// Canlı Destek Durumu
$canliDestekDurumu = $db->query("SELECT ayar_degeri FROM sistem_ayarlari WHERE ayar_adi = 'canli_destek'")->fetchColumn();

// Sol Menü: Talepler Listesi (HEPSİ - KAPALILAR DAHİL)
// Sıralama: Önce Durumu (Açıklar Üstte), Sonra Tarih (Yeniler Üstte)
$sqlTalepler = "
    SELECT t.*, f.firma_adi, 
    (SELECT COUNT(*) FROM destek_mesajlari WHERE talep_id = t.id AND okundu = 0 AND gonderen_tipi = 'firma') as okunmamis_sayisi,
    (SELECT mesaj FROM destek_mesajlari WHERE talep_id = t.id ORDER BY id DESC LIMIT 1) as son_mesaj
    FROM destek_talepleri t 
    JOIN firmalar f ON t.firma_id = f.id 
    ORDER BY FIELD(t.durum, 'bekliyor', 'yanitlandi', 'kapali'), t.id DESC 
";
// Not: FIELD() fonksiyonu ile özel sıralama yapıyoruz. Bekleyenler en üstte, sonra yanıtlananlar, en altta kapalılar.
// Eğer son_islem_tarihi sütunu varsa t.id DESC yerine t.son_islem_tarihi DESC kullanabilirsin.

$talepler = $db->query($sqlTalepler)->fetchAll(PDO::FETCH_ASSOC);

// Sağ Taraf: Seçili Talebin Mesajları
$mesajlar = [];
$seciliTalep = null;

if ($aktif_talep_id > 0) {
    // Talep Bilgisi
    $stmtT = $db->prepare("SELECT t.*, f.firma_adi, f.yetkili_ad_soyad FROM destek_talepleri t JOIN firmalar f ON t.firma_id = f.id WHERE t.id = ?");
    $stmtT->execute([$aktif_talep_id]);
    $seciliTalep = $stmtT->fetch(PDO::FETCH_ASSOC);

    if ($seciliTalep) {
        // Mesajları Çek
        $stmtM = $db->prepare("SELECT * FROM destek_mesajlari WHERE talep_id = ? ORDER BY tarih ASC");
        $stmtM->execute([$aktif_talep_id]);
        $mesajlar = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        // Admin gördüğü için firmanın attığı mesajları 'okundu' yap
        $db->prepare("UPDATE destek_mesajlari SET okundu = 1 WHERE talep_id = ? AND gonderen_tipi = 'firma'")->execute([$aktif_talep_id]);
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Destek Merkezi | Süper Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; height: 100vh; overflow: hidden; }
        .main-container { height: calc(100vh - 70px); display: flex; }
        
        /* SOL LİSTE */
        .sidebar-tickets { width: 350px; background: white; border-right: 1px solid #e3e6f0; overflow-y: auto; }
        .ticket-item { padding: 15px; border-bottom: 1px solid #f1f1f1; cursor: pointer; transition: 0.2s; text-decoration: none; display: block; color: inherit; }
        .ticket-item:hover, .ticket-item.active { background-color: #f8f9fc; border-left: 4px solid #4e73df; }
        .ticket-avatar { width: 45px; height: 45px; background: #eef2ff; color: #4e73df; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem; }
        
        /* Kapalı Talep Stili */
        .ticket-item.kapali { opacity: 0.6; background-color: #fcfcfc; }
        .ticket-item.kapali:hover { opacity: 1; }
        .ticket-item.kapali .text-dark { color: #888 !important; text-decoration: line-through; }
        
        /* SAĞ SOHBET */
        .chat-area { flex: 1; display: flex; flex-direction: column; background: #f8f9fc; }
        .chat-header { padding: 15px 25px; background: white; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center; }
        .chat-messages { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
        
        /* MESAJ BALONLARI */
        .message-bubble { max-width: 70%; padding: 12px 18px; border-radius: 15px; position: relative; font-size: 0.95rem; line-height: 1.5; }
        
        /* Admin (Benim Mesajım) - Sağda */
        .message-admin { align-self: flex-end; background-color: #4e73df; color: white; border-bottom-right-radius: 2px; }
        .message-admin .msg-time { color: rgba(255,255,255,0.7); text-align: right; }
        
        /* Firma (Karşı Mesaj) - Solda */
        .message-firma { align-self: flex-start; background-color: white; color: #333; border: 1px solid #e3e6f0; border-bottom-left-radius: 2px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .message-firma .msg-time { color: #888; }
        
        .msg-time { font-size: 0.7rem; margin-top: 5px; display: block; }

        /* INPUT ALANI */
        .chat-input-area { padding: 20px; background: white; border-top: 1px solid #e3e6f0; }
        .chat-input { background: #f1f3f9; border: none; border-radius: 25px; padding: 12px 20px; resize: none; overflow: hidden; }
        .chat-input:focus { background: white; box-shadow: 0 0 0 2px rgba(78,115,223,0.2); outline: none; }
        
        /* Canlı Destek Switch */
        .switch-label { font-weight: bold; font-size: 0.9rem; }
    </style>
</head>
<body>

    <!-- ÜST BAR -->
    <nav class="navbar navbar-dark bg-dark px-4 shadow-sm" style="height: 70px;">
        <div class="d-flex align-items-center gap-3">
            <a href="super_admin.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left"></i> Panele Dön</a>
            <span class="navbar-brand fw-bold mb-0 h1"><i class="fas fa-headset me-2 text-warning"></i>Destek Merkezi</span>
        </div>
        
        <!-- Canlı Destek Anahtarı -->
        <form method="POST" class="d-flex align-items-center bg-white rounded-pill px-3 py-1">
            <input type="hidden" name="canli_destek_toggle" value="1">
            <span class="text-dark small me-2 fw-bold"><i class="fas fa-circle <?php echo $canliDestekDurumu=='aktif'?'text-success':'text-danger'; ?> me-1"></i>Canlı Destek:</span>
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" name="yeni_durum" value="<?php echo $canliDestekDurumu=='aktif'?'kapali':'aktif'; ?>" onchange="this.form.submit()" <?php echo $canliDestekDurumu=='aktif'?'checked':''; ?>>
                <label class="form-check-label switch-label <?php echo $canliDestekDurumu=='aktif'?'text-success':'text-muted'; ?>">
                    <?php echo $canliDestekDurumu=='aktif' ? 'AÇIK' : 'KAPALI'; ?>
                </label>
            </div>
        </form>
    </nav>

    <div class="main-container">
        
        <!-- SOL LİSTE: TALEPLER -->
        <div class="sidebar-tickets">
            <div class="p-3 bg-light border-bottom fw-bold text-secondary sticky-top">
                <i class="fas fa-inbox me-2"></i>Tüm Talepler
            </div>
            
            <?php if(count($talepler) > 0): ?>
                <?php foreach($talepler as $t): 
                    $isKapali = ($t['durum'] == 'kapali');
                ?>
                    <a href="?tid=<?php echo $t['id']; ?>" class="ticket-item <?php echo $aktif_talep_id == $t['id'] ? 'active' : ''; ?> <?php echo $isKapali ? 'kapali' : ''; ?>">
                        <div class="d-flex align-items-center">
                            <div class="ticket-avatar me-3">
                                <?php echo strtoupper(substr($t['firma_adi'] ?? '?', 0, 1)); ?>
                                <?php if($t['okunmamis_sayisi'] > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        <?php echo $t['okunmamis_sayisi']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 overflow-hidden">
                                <div class="d-flex justify-content-between">
                                    <h6 class="mb-0 text-truncate fw-bold text-dark">
                                        <?php if($isKapali): ?><i class="fas fa-lock me-1 text-muted"></i><?php endif; ?>
                                        <?php echo $t['firma_adi']; ?>
                                    </h6>
                                    <small class="text-muted" style="font-size: 11px;">
                                        <?php 
                                        $tarihGoster = isset($t['son_islem_tarihi']) ? $t['son_islem_tarihi'] : ($t['tarih'] ?? null);
                                        echo $tarihGoster ? date("H:i", strtotime($tarihGoster)) : '-'; 
                                        ?>
                                    </small>
                                </div>
                                <div class="text-primary small fw-bold mb-1">#<?php echo $t['konu']; ?></div>
                                <div class="text-muted small text-truncate">
                                    <?php echo $t['son_mesaj'] ? htmlspecialchars($t['son_mesaj']) : 'Görüşme başlatıldı...'; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-check-circle fa-3x mb-3 opacity-25"></i>
                    <p>Bekleyen destek talebi yok.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- SAĞ TARAF: SOHBET ALANI -->
        <div class="chat-area">
            <?php if ($seciliTalep): ?>
                <!-- Sohbet Başlığı -->
                <div class="chat-header">
                    <div class="d-flex align-items-center">
                        <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width:40px; height:40px;">
                            <?php echo strtoupper(substr($seciliTalep['firma_adi'] ?? '?', 0, 2)); ?>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold"><?php echo $seciliTalep['firma_adi']; ?></h5>
                            <small class="text-muted">Yetkili: <?php echo $seciliTalep['yetkili_ad_soyad']; ?> | Konu: <?php echo $seciliTalep['konu']; ?></small>
                        </div>
                    </div>
                    <div>
                        <?php if($seciliTalep['durum'] == 'kapali'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="talebi_ac" value="1">
                                <button class="btn btn-sm btn-outline-success"><i class="fas fa-unlock me-2"></i>Tekrar Aç</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Bu destek talebini kapatmak istediğinize emin misiniz?');">
                                <input type="hidden" name="talebi_kapat" value="1">
                                <button class="btn btn-outline-danger btn-sm"><i class="fas fa-check me-2"></i>Konuyu Kapat</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Mesajlar -->
                <div class="chat-messages" id="messageContainer">
                    <!-- Sistem Mesajı -->
                    <div class="text-center my-3">
                        <span class="badge bg-light text-secondary border">
                            Destek Talebi Oluşturuldu: 
                            <?php 
                            $olusturma = isset($seciliTalep['olusturma_tarihi']) ? $seciliTalep['olusturma_tarihi'] : ($seciliTalep['tarih'] ?? null);
                            echo $olusturma ? date("d.m.Y H:i", strtotime($olusturma)) : '-'; 
                            ?>
                        </span>
                    </div>

                    <?php foreach($mesajlar as $m): 
                        $isMe = ($m['gonderen_tipi'] == 'admin');
                    ?>
                        <div class="message-bubble <?php echo $isMe ? 'message-admin' : 'message-firma'; ?>">
                            <?php echo nl2br(htmlspecialchars($m['mesaj'])); ?>
                            <span class="msg-time">
                                <?php echo date("H:i", strtotime($m['tarih'])); ?>
                                <?php if($isMe): ?>
                                    <i class="fas fa-check<?php echo $m['okundu'] ? '-double text-info' : ''; ?>"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if($seciliTalep['durum'] == 'kapali'): ?>
                        <div class="text-center my-3">
                            <span class="badge bg-danger">Bu talep kapatılmıştır.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Mesaj Yazma Alanı -->
                <div class="chat-input-area" style="<?php echo $seciliTalep['durum'] == 'kapali' ? 'display:none;' : ''; ?>">
                    <form method="POST" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="mesaj_gonder" value="1">
                        <button type="button" class="btn btn-light rounded-circle text-muted"><i class="fas fa-paperclip"></i></button>
                        <textarea name="mesaj" class="form-control chat-input" rows="1" placeholder="Yanıtınızı yazın..." required autofocus></textarea>
                        <button type="submit" class="btn btn-primary rounded-circle" style="width: 50px; height: 50px;"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>

                <script>
                    // Sayfa yüklendiğinde en alta kaydır
                    var msgContainer = document.getElementById("messageContainer");
                    msgContainer.scrollTop = msgContainer.scrollHeight;
                </script>

            <?php else: ?>
                <!-- Boş Durum -->
                <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
                    <i class="fas fa-comments fa-5x mb-4 text-secondary opacity-25"></i>
                    <h4>Destek Talebi Seçin</h4>
                    <p>Mesajlaşmayı başlatmak için soldan bir talep seçin.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>