<?php
session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
require_once 'partials/security_check.php';

$page_title = "Ana Sayfa";
$firma_id = $_SESSION['firma_id'];

// --- 1. HIZLI TAHSİLAT İŞLEMİ (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hizli_tahsilat'])) {
    $musteri_id = $_POST['musteri_id'];
    $tutar = $_POST['tutar'];
    $tarih = $_POST['tarih'];
    $islem_notu = $_POST['islem_notu'] ?? null;
    
    // Ödeme türü id'si (0: Nakit, 1: Kredi Kartı, 2: Havale/EFT)
    $odeme_turu = isset($_POST['odeme_turu']) ? (int)$_POST['odeme_turu'] : 0; 
    
    // Otomatik başlık oluşturucu (Açıklama inputu kaldırıldığı için)
    $odeme_isimleri = [0 => 'Nakit', 1 => 'Kredi Kartı', 2 => 'Havale / EFT'];
    $otomatik_aciklama = $odeme_isimleri[$odeme_turu] . ' Tahsilatı';

    if ($musteri_id && $tutar > 0) {
        $ekle = $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, odeme_turu, urun_aciklama, notlar, adet, birim_fiyat, toplam_tutar, islem_tarihi) VALUES (?, ?, 'tahsilat', ?, ?, ?, 1, ?, ?, ?)");
        $ekle->execute([$firma_id, $musteri_id, $odeme_turu, $otomatik_aciklama, $islem_notu, $tutar, $tutar, $tarih]);
        
        $_SESSION['flash'] = ['tip' => 'success', 'mesaj' => 'Tahsilat başarıyla işlendi!'];
        header("Location: anasayfa.php");
        exit;
    }
}

// --- 2. HIZLI İŞ (TAKVİM) EKLEME İŞLEMİ (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hizli_is_ekle'])) {
    $is_tipi = $_POST['is_tipi']; 
    $musteri_id = $_POST['is_musteri_id'] ?? null;
    $baslik = trim($_POST['is_baslik'] ?? '');
    $tarih = $_POST['is_tarihi'];
    $aciklama = trim($_POST['is_aciklama'] ?? '');

    if ($is_tipi == 'musteri' && !empty($musteri_id)) {
        $m_sorgu = $db->prepare("SELECT ad_soyad FROM musteriler WHERE id = ? AND firma_id = ?");
        $m_sorgu->execute([$musteri_id, $firma_id]);
        $m_ad = $m_sorgu->fetchColumn();
        $baslik = $m_ad ? $m_ad . ' - Randevu/Çekim' : 'Müşteri İşi';
    }

    if (empty($baslik)) { $baslik = 'Yeni İş/Randevu'; }

    try {
        $renk = '#36b9cc'; 
        $ekle = $db->prepare("INSERT INTO takvim_etkinlikleri (firma_id, baslik, baslangic_tarihi, bitis_tarihi, aciklama, renk) VALUES (?, ?, ?, ?, ?, ?)");
        $ekle->execute([$firma_id, $baslik, $tarih, $tarih, $aciklama, $renk]);
        
        $_SESSION['flash'] = ['tip' => 'success', 'mesaj' => 'İş, takviminize başarıyla eklendi!'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['tip' => 'danger', 'mesaj' => 'Takvime eklenirken hata oluştu: ' . $e->getMessage()];
    }
    
    header("Location: anasayfa.php");
    exit;
}

// 1. Toplam Müşteri Sayısı
$sorguMusteri = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE durum = 1 AND silindi = 0 AND firma_id = ?");
$sorguMusteri->execute([$firma_id]);
$toplamMusteri = $sorguMusteri->fetchColumn();

// 2. Hızlı İşlemler İçin Müşteri Listesi
$sorguTumMusteriler = $db->prepare("SELECT id, ad_soyad FROM musteriler WHERE durum = 1 AND silindi = 0 AND firma_id = ? ORDER BY ad_soyad ASC");
$sorguTumMusteriler->execute([$firma_id]);
$tumMusteriler = $sorguTumMusteriler->fetchAll(PDO::FETCH_ASSOC);

// 3. Yaklaşan İşler / Özet Ajanda 
$sorguAjanda = $db->prepare("
    SELECT m.ad_soyad, m.url_token, h.urun_aciklama, h.vade_tarihi 
    FROM hareketler h 
    JOIN musteriler m ON h.musteri_id = m.id 
    WHERE h.firma_id = ? AND h.islem_turu = 'satis' AND h.vade_tarihi >= CURDATE() 
    ORDER BY h.vade_tarihi ASC LIMIT 3
");
$sorguAjanda->execute([$firma_id]);
$ajandaOzet = $sorguAjanda->fetchAll(PDO::FETCH_ASSOC);

// 4. SİSTEM DUYURULARI
try {
    $duyuruSorgu = $db->query("SELECT * FROM admin_duyurular WHERE aktif = 1 ORDER BY tarih DESC LIMIT 3");
    $duyurular = $duyuruSorgu ? $duyuruSorgu->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $duyurular = [];
}

$inline_css = '
    .ozet-kutu { border: none; border-radius: 12px; color: white; padding: 20px; height: 100%; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
    .ozet-kutu:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.12); }
    .bg-gradient-primary { background: linear-gradient(135deg, var(--yonetim-primary) 0%, var(--yonetim-secondary) 100%); }
    .bg-gradient-warning { background: linear-gradient(135deg, #ffb347 0%, #ffcc33 100%); color: #333; }
    .ozet-icon { font-size: 2.5rem; opacity: 0.8; margin-bottom: 15px; }
    .ozet-title { font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9; margin-bottom: 10px; font-weight: 600; }
    .ozet-value { font-size: 2.2rem; font-weight: 800; margin-bottom: 5px; line-height: 1; }
    .ozet-subtext { font-size: 0.85rem; opacity: 0.9; margin-bottom: 0; }
    
    .hizli-btn { border-radius: 12px; padding: 20px 15px; font-size: 1rem; font-weight: 600; border: 1px solid var(--yonetim-border); box-shadow: 0 3px 8px rgba(0,0,0,0.05); transition: all 0.3s ease; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 12px; background: var(--yonetim-card-bg); color: var(--yonetim-text); text-decoration: none; margin-bottom: 20px; }
    .hizli-btn:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); color: var(--yonetim-primary); border-color: var(--yonetim-border); text-decoration: none; }
    .hizli-btn i { font-size: 2.2rem; display: block; margin-bottom: 8px; }
    .hizli-btn .btn-text { font-size: 0.95rem; font-weight: 600; }
    .hizli-btn-primary i { color: var(--yonetim-primary); }
    .hizli-btn-success i { color: var(--yonetim-success); }
    .hizli-btn-warning i { color: var(--yonetim-warning); }
    .hizli-btn-info i { color: var(--yonetim-info); }
    .hizli-btn-info .btn-text { color: var(--yonetim-info); }
    .hizli-btn-danger i { color: var(--yonetim-danger); }
    .hizli-btn-dark i { color: var(--yonetim-secondary); }
    
    .ajanda-card { border: none; border-radius: 12px; background: var(--yonetim-card-bg); box-shadow: 0 4px 12px rgba(0,0,0,0.08); height: 100%; overflow: hidden; }
    .ajanda-header { background: linear-gradient(135deg, var(--yonetim-primary) 0%, var(--yonetim-secondary) 100%); color: white; padding: 15px 20px; border-radius: 12px 12px 0 0; }
    .ajanda-header h6 { margin: 0; font-weight: 600; font-size: 1rem; }
    .ajanda-body { padding: 20px; max-height: 300px; overflow-y: auto; }
    .ajanda-item { border-left: 3px solid var(--yonetim-primary); background: var(--yonetim-bg); padding: 12px; margin-bottom: 10px; border-radius: 0 6px 6px 0; transition: all 0.2s; position: relative; }
    .ajanda-item:hover { background: #e9ecef; transform: translateX(3px); }
    .ajanda-item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
    .ajanda-item-name { font-weight: 600; color: var(--yonetim-secondary); font-size: 0.95rem; }
    .ajanda-item-date { background: var(--yonetim-primary); color: white; padding: 3px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; }
    .ajanda-item-desc { color: var(--yonetim-text-light); font-size: 0.9rem; margin-bottom: 0; line-height: 1.4; }
    
    .duyuru-alert { border: none; border-radius: 10px; padding: 15px; margin-bottom: 15px; box-shadow: 0 3px 8px rgba(0,0,0,0.05); }
    .duyuru-alert i { font-size: 1.2rem; margin-right: 10px; }
    .section-title { font-size: 1.1rem; color: var(--yonetim-secondary); font-weight: 700; margin: 25px 0 15px 0; padding-bottom: 8px; border-bottom: 2px solid var(--yonetim-border); }
    
    @media (max-width: 768px) {
        .ozet-kutu { padding: 15px; margin-bottom: 15px; }
        .ozet-value { font-size: 1.8rem; }
        .hizli-btn { padding: 15px 10px; margin-bottom: 15px; cursor: pointer; }
        .hizli-btn i { font-size: 1.8rem; }
        .hizli-btn .btn-text { font-size: 0.9rem; }
        .ajanda-body { padding: 15px; max-height: 250px; }
    }
    @media (max-width: 576px) { .hizli-btn { padding: 12px 8px; } .hizli-btn i { font-size: 1.6rem; } .ozet-kutu { padding: 12px; } }
';

$inline_js = '
    document.addEventListener("DOMContentLoaded", function() {
        const tahsilatModal = document.getElementById("modalHizliTahsilat");
        if (tahsilatModal) {
            tahsilatModal.addEventListener("shown.bs.modal", function() {
                const tutarInput = this.querySelector("input[name=\'tutar\']");
                if (tutarInput) tutarInput.focus();
            });
        }
        
        document.querySelectorAll(".ajanda-item").forEach(item => {
            item.style.cursor = "pointer";
            item.addEventListener("click", function() {
                const token = this.getAttribute("data-token");
                const customerName = this.querySelector(".ajanda-item-name").textContent;
                if(token) {
                    if(typeof showYonetimToast === "function") showYonetimToast(customerName + " işi detayına yönlendiriliyorsunuz...", "info");
                    setTimeout(() => {
                        window.location.href = "musteri_detay.php?t=" + token;
                    }, 500);
                }
            });
        });
        
        document.querySelectorAll(".hizli-btn").forEach(btn => {
            btn.addEventListener("mouseenter", function() {
                const icon = this.querySelector("i");
                if (icon) {
                    icon.style.transform = "scale(1.2) rotate(5deg)";
                    icon.style.transition = "transform 0.3s ease";
                }
            });
            btn.addEventListener("mouseleave", function() {
                const icon = this.querySelector("i");
                if (icon) {
                    icon.style.transform = "scale(1) rotate(0deg)";
                    icon.style.transition = "transform 0.3s ease";
                }
            });
        });
    });
    
    function submitTahsilatForm() {
        const form = document.getElementById("tahsilatForm");
        if (form) {
            const tutar = form.querySelector("input[name=\'tutar\']").value;
            if (tutar && parseFloat(tutar) > 0) {
                if(typeof showYonetimToast === "function") showYonetimToast("Tahsilat işlemi başlatılıyor...", "info");
                return true;
            } else {
                if(typeof showYonetimToast === "function") showYonetimToast("Lütfen geçerli bir tutar girin!", "warning");
                return false;
            }
        }
        return false;
    }

    function toggleIsTipi() {
        const isMusteri = document.getElementById("tipMusteri").checked;
        const musteriAlani = document.getElementById("musteriSecimAlani");
        const bagimsizAlani = document.getElementById("bagimsizIsAlani");
        const musteriSelect = document.querySelector("select[name=\'is_musteri_id\']");
        const baslikInput = document.querySelector("input[name=\'is_baslik\']");

        if (isMusteri) {
            musteriAlani.style.display = "block";
            bagimsizAlani.style.display = "none";
            musteriSelect.required = true;
            baslikInput.required = false;
        } else {
            musteriAlani.style.display = "none";
            bagimsizAlani.style.display = "block";
            musteriSelect.required = false;
            baslikInput.required = true;
        }
    }

    function submitIsForm() {
        const isMusteri = document.getElementById("tipMusteri").checked;
        const musteriSelect = document.querySelector("select[name=\'is_musteri_id\']").value;
        const baslikInput = document.querySelector("input[name=\'is_baslik\']").value;

        if (isMusteri && !musteriSelect) {
            if(typeof showYonetimToast === "function") showYonetimToast("Lütfen bir müşteri seçin!", "warning");
            return false;
        }
        if (!isMusteri && !baslikInput.trim()) {
            if(typeof showYonetimToast === "function") showYonetimToast("Lütfen iş başlığını girin!", "warning");
            return false;
        }
        if(typeof showYonetimToast === "function") showYonetimToast("Takvime ekleniyor...", "info");
        return true;
    }
';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | <?php echo htmlspecialchars($firma_adi ?? 'ibiR Cari'); ?></title>
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style><?php echo $inline_css; ?></style>
</head>
<body class="yonetim-body">

<?php include 'partials/navbar.php'; ?>

<div class="container container-yonetim mt-4">
    
    <!-- İŞLEM SONUCU MESAJLARI (FLASH) -->
    <?php if(isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['tip'] ?> alert-dismissible fade show shadow-sm mb-4 border-0 rounded-3">
            <i class="fas <?= $_SESSION['flash']['tip'] == 'success' ? 'fa-check-circle' : 'fa-info-circle' ?> me-2"></i>
            <?= $_SESSION['flash']['mesaj'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- DUYURU ALANI -->
    <?php if(!empty($duyurular)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <?php foreach($duyurular as $d): ?>
                    <div class="alert alert-<?php echo $d['tip']; ?> duyuru-alert alert-auto-hide d-flex align-items-center" role="alert">
                        <i class="fas fa-bullhorn fa-lg me-3"></i>
                        <div class="flex-grow-1">
                            <strong class="d-block">SİSTEM DUYURUSU:</strong>
                            <span class="d-block"><?php echo $d['mesaj']; ?></span>
                            <small class="text-muted mt-1 d-block">
                                <i class="far fa-clock me-1"></i>
                                <?php echo date("d.m.Y H:i", strtotime($d['tarih'])); ?>
                            </small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
        
    <!-- HIZLI İŞLEMLER (SADECE MOBİLDE GÖRÜNÜR: d-block d-md-none) -->
    <div class="d-block d-md-none">
        <h6 class="section-title">
            <i class="fas fa-bolt me-2 text-warning"></i>Hızlı İşlemler
        </h6>
        <div class="row mb-4 g-2">
            <div class="col-6">
                <a href="musteri_ekle.php" class="hizli-btn hizli-btn-primary">
                    <i class="fas fa-user-plus"></i>
                    <span class="btn-text">Müşteri Ekle</span>
                </a>
            </div>
            <div class="col-6">
                <a href="musteriler.php" class="hizli-btn hizli-btn-success">
                    <i class="fas fa-users"></i>
                    <span class="btn-text">Müşteriler</span>
                </a>
            </div>
            <div class="col-6">
                <button type="button" class="hizli-btn hizli-btn-warning w-100 border-0 bg-white" data-bs-toggle="modal" data-bs-target="#modalHizliTahsilat">
                    <i class="fas fa-hand-holding-usd text-warning"></i>
                    <span class="btn-text text-dark">Hızlı Tahsilat</span>        
                </button>
            </div>
            <div class="col-6">
                <!-- İş Ekle Butonu Artık Modal Açıyor -->
                <button type="button" class="hizli-btn hizli-btn-info w-100 border-0 bg-white" data-bs-toggle="modal" data-bs-target="#modalHizliIs">
                    <i class="fas fa-calendar-plus text-info"></i>
                    <span class="btn-text text-dark">İş / Etkinlik Ekle</span>        
                </button>
            </div>
        </div>
    </div>
    
    <!-- YÖNETİM ARAÇLARI (SADECE MOBİLDE GÖRÜNÜR: d-block d-md-none) -->
    <div class="d-block d-md-none">
        <h6 class="section-title">
            <i class="fas fa-tools me-2 text-primary"></i>Yönetim Araçları
        </h6>
        <div class="row mb-4 g-2">
            <div class="col-6">
                <a href="teklif_olustur.php" class="hizli-btn hizli-btn-dark">
                    <i class="fas fa-file-signature"></i>
                    <span class="btn-text">Teklif Oluştur</span>
                </a>
            </div>
            <div class="col-6">
                <a href="teklifler.php" class="hizli-btn hizli-btn-dark">
                    <i class="fas fa-folder-open"></i>
                    <span class="btn-text">Teklif Listesi</span>
                </a>
            </div>
            <div class="col-6">
                <a href="takvim.php" class="hizli-btn hizli-btn-dark">
                    <i class="fas fa-calendar-check"></i>
                    <span class="btn-text">İş Takvimi</span>
                </a>
            </div>
            <div class="col-6">
                <a href="borclar.php" class="hizli-btn hizli-btn-danger">
                    <i class="fas fa-money-bill-wave"></i>
                    <span class="btn-text">Borç Yönetimi</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- HIZLI EYLEMLER (SADECE MASAÜSTÜ) -->
    <div class="d-none d-md-block mb-4">
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-warning fw-bold shadow-sm text-dark" data-bs-toggle="modal" data-bs-target="#modalHizliTahsilat">
                <i class="fas fa-hand-holding-usd me-2"></i>Hızlı Tahsilat
            </button>
            <button type="button" class="btn btn-info text-white fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalHizliIs">
                <i class="fas fa-calendar-plus me-2"></i>Takvime İş Ekle
            </button>
        </div>
    </div>

    <!-- ÖZET KARTLAR & AJANDA (HEM MOBİL HEM MASAÜSTÜ) -->
    <div class="row mb-5">
        <!-- Aktif Müşteri Sayısı -->
        <div class="col-md-4">
            <a href="musteriler.php" class="text-decoration-none">
                <div class="ozet-kutu bg-gradient-primary">
                    <div class="text-center">
                        <i class="fas fa-users ozet-icon"></i>
                        <div class="ozet-title">Aktif Müşteriler</div>
                        <div class="ozet-value customer-count"><?php echo $toplamMusteri; ?></div>
                        <div class="ozet-subtext">Toplam kayıtlı müşteri</div>
                    </div>
                </div>
            </a>
        </div>
        
        <!-- Raporlar Kısayolu -->
        <div class="col-md-4">
            <a href="raporlar.php" class="text-decoration-none">
                <div class="ozet-kutu bg-gradient-warning">
                    <div class="text-center">
                        <i class="fas fa-chart-pie ozet-icon"></i>
                        <div class="ozet-title">Finansal Durum</div>
                        <div class="ozet-value"><i class="fas fa-chart-line"></i></div>
                        <div class="ozet-subtext">Detaylı raporları görüntüle</div>
                    </div>
                </div>
            </a>
        </div>
        
        <!-- Yaklaşan İşler -->
        <div class="col-md-4">
            <div class="ajanda-card">
                <div class="ajanda-header">
                    <h6><i class="fas fa-calendar-alt me-2"></i>Yaklaşan İşler</h6>
                </div>
                <div class="ajanda-body">
                    <?php if(count($ajandaOzet) > 0): ?>
                        <?php foreach($ajandaOzet as $is): ?>
                            <div class="ajanda-item shadow-sm" data-token="<?php echo htmlspecialchars($is['url_token'] ?? ''); ?>">
                                <div class="ajanda-item-header">
                                    <span class="ajanda-item-name"><?php echo htmlspecialchars($is['ad_soyad']); ?></span>
                                    <span class="ajanda-item-date"><?php echo date("d.m", strtotime($is['vade_tarihi'])); ?></span>
                                </div>
                                <p class="ajanda-item-desc"><?php echo htmlspecialchars($is['urun_aciklama']); ?></p>
                            </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <a href="takvim.php" class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-4">
                                <i class="fas fa-calendar me-1"></i>Tümünü Gör
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="far fa-calendar fa-2x mb-3 d-block opacity-50"></i>
                            <p class="mb-0 small">Yakın tarihte planlı iş yok.</p>
                            <button type="button" class="btn btn-sm btn-primary mt-3 rounded-pill fw-bold px-3 d-md-none" data-bs-toggle="modal" data-bs-target="#modalHizliIs">
                                <i class="fas fa-plus me-1"></i>Takvime İş Ekle
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: HIZLI TAHSİLAT -->
<div class="modal fade" id="modalHizliTahsilat" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" id="tahsilatForm" onsubmit="return submitTahsilatForm()">
                <input type="hidden" name="hizli_tahsilat" value="1">
                <div class="modal-header bg-warning text-dark border-0">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-hand-holding-usd me-2"></i>Hızlı Tahsilat Yap
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    
                    <!-- TUTAR GİRİŞİ EN ÜSTTE VE BÜYÜK -->
                    <div class="mb-4 text-center">
                        <label class="form-label fw-bold text-success mb-1">Alınan Tutar (₺)</label>
                        <input type="number" step="0.01" name="tutar" class="form-control form-control-lg fw-bold border-success text-center text-success" style="font-size: 2.5rem; height: 70px; background-color: #f8fff9;" placeholder="0.00" required min="0" autofocus>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Hangi Müşteriden?</label>
                        <select name="musteri_id" class="form-select bg-light fw-semibold" required>
                            <option value="">Müşteri Seçiniz...</option>
                            <?php foreach($tumMusteriler as $tm): ?>
                                <option value="<?php echo $tm['id']; ?>"><?php echo htmlspecialchars($tm['ad_soyad']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small">Ödeme Türü</label>
                            <select name="odeme_turu" class="form-select bg-light fw-bold text-primary" required>
                                <option value="0">Nakit</option>
                                <option value="1">Kredi Kartı</option>
                                <option value="2">Havale / EFT</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small">İşlem Tarihi</label>
                            <input type="datetime-local" name="tarih" class="form-control bg-light" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold text-muted small">İşlem Notu (İsteğe Bağlı)</label>
                        <textarea name="islem_notu" class="form-control bg-light" rows="2" placeholder="Tahsilatla ilgili eklemek istediğiniz notlar..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm">
                        <i class="fas fa-check me-2"></i>Tahsilatı Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: HIZLI İŞ / RANDEVU EKLE -->
<div class="modal fade" id="modalHizliIs" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" id="isForm" onsubmit="return submitIsForm()">
                <input type="hidden" name="hizli_is_ekle" value="1">
                <div class="modal-header bg-info text-white border-0">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-calendar-plus me-2"></i>Takvime İş Ekle
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted small">İşin Türü</label>
                        <div class="d-flex gap-3 bg-light p-2 rounded border">
                            <div class="form-check flex-fill">
                                <input class="form-check-input" type="radio" name="is_tipi" id="tipMusteri" value="musteri" checked onchange="toggleIsTipi()">
                                <label class="form-check-label fw-bold small" for="tipMusteri">Kayıtlı Müşteri</label>
                            </div>
                            <div class="form-check flex-fill">
                                <input class="form-check-input" type="radio" name="is_tipi" id="tipBagimsiz" value="bagimsiz" onchange="toggleIsTipi()">
                                <label class="form-check-label fw-bold small" for="tipBagimsiz">Bağımsız İş</label>
                            </div>
                        </div>
                    </div>

                    <!-- MÜŞTERİ SEÇİM ALANI -->
                    <div class="mb-3" id="musteriSecimAlani">
                        <label class="form-label fw-bold text-muted small">Hangi Müşteriye Ait?</label>
                        <select name="is_musteri_id" class="form-select bg-light" required>
                            <option value="">Kayıtlı Müşteriyi Seçin...</option>
                            <?php foreach($tumMusteriler as $tm): ?>
                                <option value="<?php echo $tm['id']; ?>"><?php echo htmlspecialchars($tm['ad_soyad']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- BAĞIMSIZ BAŞLIK ALANI -->
                    <div class="mb-3" id="bagimsizIsAlani" style="display:none;">
                        <label class="form-label fw-bold text-muted small">İş Başlığı (Müşteri / Kurum Adı)</label>
                        <input type="text" name="is_baslik" class="form-control bg-light" placeholder="Örn: X Kurumu Tanıtım Çekimi">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Tarih ve Saat</label>
                        <input type="datetime-local" name="is_tarihi" class="form-control bg-light" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold text-muted small">Detay / Açıklama</label>
                        <textarea name="is_aciklama" class="form-control bg-light" rows="3" placeholder="Nerede çekilecek, kaçta buluşulacak, özel notlar vb."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-info text-white fw-bold rounded-pill px-4 shadow-sm">
                        <i class="fas fa-check me-2"></i>Takvime Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toast-container-yonetim"></div>

<script>
<?php echo $inline_js; ?>
</script>

<?php require_once 'partials/footer_yonetim.php'; ?>