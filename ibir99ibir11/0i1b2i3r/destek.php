<?php
require_once '../templates/header.php';
global $pdo;

// Merkezi log fonksiyonumuzu dahil ediyoruz (Eğer config'den gelmiyorsa)
$functions_path = __DIR__ . '/../includes/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

$aktif_talep_id = isset($_GET['tid']) ? (int)$_GET['tid'] : 0;

// --- İŞLEMLER (POST) ---

// 1. Destek Mesajı Gönderme (Admin)
if (isset($_POST['admin_mesaj_gonder']) && $aktif_talep_id > 0) {
    $mesaj = trim($_POST['mesaj']);
    if($mesaj) {
        $stmt = $pdo->prepare("INSERT INTO destek_mesajlari (talep_id, gonderen_id, gonderen_tipi, mesaj, tarih, okundu) VALUES (?, ?, 'admin', ?, NOW(), 0)");
        $stmt->execute([$aktif_talep_id, $_SESSION['admin_id'], $mesaj]);
        
        $pdo->prepare("UPDATE destek_talepleri SET durum = 'yanitlandi', son_islem_tarihi = NOW() WHERE id = ?")->execute([$aktif_talep_id]);
        
        if (function_exists('sistem_log_kaydet')) sistem_log_kaydet("Destek Yanıtı", "Süper Admin bir destek talebine yanıt verdi (Talep ID: $aktif_talep_id).", 'IBIR-4247-ADMIN', $_SESSION['admin_id']);
        
        header("Location: destek.php?tid=$aktif_talep_id");
        exit;
    }
}

// 2. Talep Kapatma
if (isset($_POST['talep_kapat']) && $aktif_talep_id > 0) {
    $pdo->prepare("UPDATE destek_talepleri SET durum = 'kapali', son_islem_tarihi = NOW() WHERE id = ?")->execute([$aktif_talep_id]);
    setFlash("Destek talebi başarıyla kapatıldı.", "success");
    header("Location: destek.php?tid=$aktif_talep_id");
    exit;
}

// --- VERİ ÇEKME ---

// Sol Menü: Tüm Firmaların Talepleri
$stmtTalepler = $pdo->query("
    SELECT t.*, f.firma_adi,
    (SELECT COUNT(*) FROM destek_mesajlari WHERE talep_id = t.id AND okundu = 0 AND gonderen_tipi = 'firma') as okunmamis,
    (SELECT mesaj FROM destek_mesajlari WHERE talep_id = t.id ORDER BY id DESC LIMIT 1) as son_mesaj
    FROM destek_talepleri t 
    JOIN firmalar f ON t.firma_id = f.id
    ORDER BY t.son_islem_tarihi DESC
");
$talepler = $stmtTalepler->fetchAll(PDO::FETCH_ASSOC);

// Sağ Taraf: Seçili Talep ve Mesajları
$mesajlar = [];
$seciliTalep = null;

if($aktif_talep_id > 0) {
    $stmtT = $pdo->prepare("SELECT t.*, f.firma_adi FROM destek_talepleri t JOIN firmalar f ON t.firma_id = f.id WHERE t.id = ?");
    $stmtT->execute([$aktif_talep_id]);
    $seciliTalep = $stmtT->fetch(PDO::FETCH_ASSOC);
    
    if($seciliTalep) {
        $stmtM = $pdo->prepare("
            SELECT m.*, 
            IF(m.gonderen_tipi='admin', (SELECT ad_soyad FROM yoneticiler WHERE id=m.gonderen_id), (SELECT ad_soyad FROM yoneticiler WHERE id=m.gonderen_id)) as gonderen_adi 
            FROM destek_mesajlari m 
            WHERE m.talep_id = ? ORDER BY m.tarih ASC
        ");
        $stmtM->execute([$aktif_talep_id]);
        $mesajlar = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        
        // Firmadan gelen mesajları okundu işaretle
        $pdo->prepare("UPDATE destek_mesajlari SET okundu = 1 WHERE talep_id = ? AND gonderen_tipi = 'firma'")->execute([$aktif_talep_id]);
    }
}

// CSS Stilleri
$inline_css = '
    .chat-layout { display: flex; height: calc(100vh - 180px); background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15); border: 1px solid #e3e6f0; }
    .chat-sidebar { width: 350px; background: #f8f9fc; border-right: 1px solid #e3e6f0; display: flex; flex-direction: column; }
    .chat-main { flex: 1; display: flex; flex-direction: column; background: #fff; position: relative; }
    
    .ticket-item { padding: 15px; border-bottom: 1px solid #eaecf4; cursor: pointer; transition: 0.2s; text-decoration: none; color: inherit; display: block; position: relative; }
    .ticket-item:hover, .ticket-item.active { background: #fff; border-left: 4px solid #4e73df; }
    .ticket-item.active { background: #f1f3f9; }
    .badge-count { position: absolute; right: 15px; top: 25px; background: #e74a3b; color: white; border-radius: 50%; width: 22px; height: 22px; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; font-weight: bold;}

    .msg-container { flex: 1; padding: 20px; overflow-y: auto; background: #f4f6f8; display: flex; flex-direction: column; gap: 12px; }
    .msg-bubble { max-width: 75%; padding: 12px 18px; border-radius: 15px; font-size: 0.95rem; line-height: 1.5; position: relative; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    
    /* ADMİN (BEN) - Sağ */
    .msg-me { align-self: flex-end; background: #e3ebf7; color: #1a3c87; border-bottom-right-radius: 4px; }
    .msg-me .meta { font-size: 0.75rem; color: #4e73df; text-align: right; margin-top: 6px; font-weight: 500;}
    
    /* FİRMA (KARŞI) - Sol */
    .msg-other { align-self: flex-start; background: #fff; color: #333; border-bottom-left-radius: 4px; border: 1px solid #eaecf4; }
    .msg-other .meta { font-size: 0.75rem; color: #858796; margin-top: 6px; font-weight: 500;}
    
    .chat-input-box { padding: 20px; background: #fff; border-top: 1px solid #eaecf4; }
    
    @media (max-width: 768px) {
        .chat-layout { flex-direction: column; height: auto; }
        .chat-sidebar { width: 100%; height: 300px; border-right: none; border-bottom: 1px solid #e3e6f0; }
        .chat-main { height: 500px; }
    }
';
?>

<style><?= $inline_css ?></style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Merkezi Destek Paneli</h1>
</div>

<div class="chat-layout">
    
    <!-- SOL MENÜ: TALEPLER -->
    <div class="chat-sidebar">
        <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold text-primary"><i class="fas fa-inbox me-2"></i>Gelen Talepler</h6>
        </div>
        <div class="p-2 border-bottom bg-light">
            <input type="text" class="form-control form-control-sm" placeholder="Firma veya konu ara..." id="ticketSearch">
        </div>
        <div class="flex-grow-1 overflow-auto" id="ticketList">
            <?php if(count($talepler) > 0): ?>
                <?php foreach($talepler as $t): ?>
                <a href="?tid=<?= $t['id'] ?>" class="ticket-item <?= $aktif_talep_id == $t['id'] ? 'active' : '' ?>">
                    <div class="d-flex justify-content-between mb-1 align-items-center pe-4">
                        <strong class="text-dark text-truncate" style="max-width: 100%;">#<?= e($t['konu']) ?></strong>
                    </div>
                    <div class="small fw-bold text-primary mb-1"><i class="fas fa-building me-1"></i> <?= e($t['firma_adi']) ?></div>
                    <div class="small text-muted text-truncate pe-4">
                        <?= $t['son_mesaj'] ? e($t['son_mesaj']) : 'Mesaj yok...' ?>
                    </div>
                    <div class="mt-2 d-flex justify-content-between align-items-center">
                        <span class="badge bg-<?= $t['durum']=='yanitlandi'?'success':($t['durum']=='kapali'?'secondary':'warning text-dark') ?> rounded-pill" style="font-size: 0.65rem;">
                            <?= strtoupper($t['durum']) ?>
                        </span>
                        <small class="text-muted" style="font-size:10px;">
                            <i class="far fa-clock"></i> <?= date("d.m H:i", strtotime($t['son_islem_tarihi'])) ?>
                        </small>
                    </div>
                    <?php if($t['okunmamis'] > 0): ?>
                        <span class="badge-count shadow-sm"><?= $t['okunmamis'] ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center p-5 text-muted small">
                    <i class="fas fa-check-circle fa-3x mb-3 text-success opacity-50"></i><br>
                    Tüm talepler yanıtlanmış. Bekleyen destek talebi yok!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SAĞ ALAN: MESAJLAŞMA -->
    <div class="chat-main">
        <?php if($seciliTalep): ?>
            <!-- Chat Header -->
            <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center shadow-sm" style="z-index: 10;">
                <div>
                    <h5 class="mb-1 fw-bold text-dark"><?= e($seciliTalep['konu']) ?></h5>
                    <div class="small text-muted">
                        <span class="fw-bold text-primary me-2"><i class="fas fa-building me-1"></i> <?= e($seciliTalep['firma_adi']) ?></span>
                        <span class="me-2">|</span>
                        <span>Talep ID: #<?= $seciliTalep['id'] ?></span>
                    </div>
                </div>
                <div>
                    <?php if($seciliTalep['durum'] != 'kapali'): ?>
                        <form method="POST" style="margin:0;" onsubmit="return confirm('Bu sorunun çözüldüğünü onaylıyor musunuz? Talep kapatılacaktır.');">
                            <input type="hidden" name="talep_kapat" value="1">
                            <button type="submit" class="btn btn-sm btn-danger shadow-sm"><i class="fas fa-lock me-1"></i> Talebi Kapat</button>
                        </form>
                    <?php else: ?>
                        <span class="badge bg-secondary px-3 py-2 fs-6"><i class="fas fa-lock me-1"></i> ÇÖZÜLDÜ (KAPALI)</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Mesajlar -->
            <div class="msg-container" id="msgBox">
                <div class="text-center my-3">
                    <span class="badge bg-white text-secondary border fw-normal py-2 px-3 shadow-sm rounded-pill">
                        Talep Oluşturulma: <?= date("d.m.Y H:i", strtotime($seciliTalep['tarih'])) ?>
                    </span>
                </div>
                
                <?php foreach($mesajlar as $m): 
                    $benim = ($m['gonderen_tipi'] == 'admin');
                ?>
                    <div class="msg-bubble <?= $benim ? 'msg-me' : 'msg-other' ?>">
                        <div class="small fw-bold <?= $benim ? 'text-primary' : 'text-dark' ?> mb-1">
                            <i class="fas <?= $benim ? 'fa-user-shield' : 'fa-user' ?> me-1"></i> 
                            <?= $benim ? 'Süper Admin (Sen)' : e($m['gonderen_adi'] ?? 'Firma Yetkilisi') ?>
                        </div>
                        
                        <?= nl2br(e($m['mesaj'])) ?>
                        
                        <div class="meta">
                            <?= date("d.m.Y H:i", strtotime($m['tarih'])) ?>
                            <?php if($benim): ?>
                                <i class="fas fa-check<?= $m['okundu'] ? '-double text-success' : '' ?> ms-1"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if($seciliTalep['durum'] == 'kapali'): ?>
                    <div class="text-center my-4">
                        <span class="badge bg-light text-muted border px-3 py-2 rounded-pill"><i class="fas fa-info-circle me-1"></i> Bu destek talebi kapatıldığı için yeni mesaj gönderilemez.</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Yazma Alanı -->
            <?php if($seciliTalep['durum'] != 'kapali'): ?>
            <div class="chat-input-box">
                <form method="POST" class="d-flex gap-3">
                    <input type="hidden" name="admin_mesaj_gonder" value="1">
                    <input type="text" name="mesaj" class="form-control form-control-lg rounded-pill bg-light border-0 px-4" placeholder="Firmaya yanıtınızı yazın..." autocomplete="off" required autofocus>
                    <button class="btn btn-primary rounded-circle shadow" style="width: 50px; height: 50px; flex-shrink: 0;"><i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
            <?php endif; ?>

            <script>
                // Sayfa açıldığında mesajların en altına kaydır
                var msgBox = document.getElementById("msgBox");
                if(msgBox) msgBox.scrollTop = msgBox.scrollHeight;
            </script>

        <?php else: ?>
            <!-- Boş Durum -->
            <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted bg-light">
                <div class="bg-white p-4 rounded-circle shadow-sm mb-4">
                    <i class="fas fa-headset fa-4x text-primary opacity-75"></i>
                </div>
                <h4 class="fw-bold text-dark">Merkezi Destek Paneli</h4>
                <p>Sol taraftaki listeden bir firmanın talebini seçerek yazışmaya başlayın.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Basit Arama Filtresi
document.getElementById('ticketSearch')?.addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let items = document.querySelectorAll('#ticketList .ticket-item');
    items.forEach(item => {
        let text = item.innerText.toLowerCase();
        item.style.display = text.includes(filter) ? '' : 'none';
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>