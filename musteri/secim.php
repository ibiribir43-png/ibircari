<?php
/**
 * DOSYA ADI: musteri/secim.php
 * AÇIKLAMA: Gelişmiş Görüntüleyici Arayüzü (Lively & Mobil Focus Mode Eklentili)
 */
require_once 'baglanti.php';

if (!isset($_SESSION['musteri_auth']) || $_SESSION['musteri_auth'] !== true) {
    header("Location: index.php");
    exit;
}

$m_id = $_SESSION['musteri_id'];
$db_cari = $db; 

// --- İŞ AKIŞI OTOMATİK GÜNCELLEME (AJAX İLE TETİKLENİR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['is_akisi_guncelle']) && $_POST['is_akisi_guncelle'] == 'bitir') {
    // 2: Müşteri Seçimi Bitirdi (Senin yeni ID sistemine göre)
    $db_cari->prepare("UPDATE musteriler SET workflow_status = 2 WHERE id = ?")->execute([$m_id]);
    echo "ok";
    exit;
}

$sorgu = $db_cari->prepare("SELECT ad_soyad, selection_limit FROM musteriler WHERE id = ?");
$sorgu->execute([$m_id]);
$m_bilgi = $sorgu->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Fotoğraf Seçimi | ibiR Wedding</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Az önce kaydettiğimiz düzeltilmiş CSS dosyamız -->
    <link rel="stylesheet" href="assets/css/gallery.css">
</head>
<body>

    <!-- Header Alanı -->
    <header class="gallery-header">
        <div class="logo">ibiR <span>Wedding</span></div>
        
        <div class="user-menu">
            <button class="user-menu-button" id="user-menu-btn">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($m_bilgi['ad_soyad']); ?></span>
                <i class="fas fa-caret-down"></i>
            </button>
            <div class="user-menu-dropdown" id="user-menu-dropdown">
                <a href="dashboard.php"><i class="fas fa-th-large"></i> Panelim</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Çıkış Yap</a>
            </div>
        </div>

        <!-- Mobil Sepet Butonu (ID'si fixlendi) -->
        <button class="cart-toggle" id="mobile-cart-toggle-btn">
            <i class="fas fa-shopping-basket"></i>
            <span id="cart-badge">0</span>
        </button>
    </header>

    <div class="gallery-layout">
        
        <!-- Ana Görüntüleyici Bölümü -->
        <main class="main-viewer" id="main-viewer">
            <div class="viewer-area">
                <div id="viewer-placeholder" class="viewer-placeholder">
                    <div class="text-center">
                        <i class="fas fa-image fa-4x mb-3 text-muted"></i>
                        <p>Görüntülemek için bir fotoğraf seçin</p>
                    </div>
                </div>
                <img id="main-image" src="" alt="" style="display:none;">
                
                <button class="lightbox-nav prev" id="lightbox-prev" style="display:none;"><i class="fas fa-chevron-left"></i></button>
                <button class="lightbox-nav next" id="lightbox-next" style="display:none;"><i class="fas fa-chevron-right"></i></button>

                <!-- YENİ EKLENEN: Mobil Focus Modu Şeffaf Butonları -->
                <div class="mobile-focus-controls">
                    <button class="focus-btn" id="f-sel"><i class="fas fa-check"></i></button>
                    <button class="focus-btn" id="f-fav"><i class="fas fa-heart"></i></button>
                </div>
            </div>

            <!-- Resim Altı Kontroller (Masaüstü için) -->
            <div class="main-controls" id="main-controls" style="display:none;">
                <div class="main-filename" id="main-filename">dosya_adi.jpg</div>
                <div class="d-flex gap-2">
                    <button class="action-btn" id="main-select-btn">
                        <i class="fas fa-check-circle"></i> <span>Seç</span>
                    </button>
                    <button class="action-btn" id="main-fav-btn">
                        <i class="fas fa-heart"></i> <span>Favori</span>
                    </button>
                    <button class="action-btn" id="main-note-btn">
                        <i class="fas fa-comment-alt"></i> <span>Not Ekle</span>
                    </button>
                </div>
            </div>

            <!-- Not Editörü (Gizli) -->
            <div class="note-editor" id="main-note-editor" style="display:none;">
                <h4>Fotoğraf Notu</h4>
                <textarea id="main-note-textarea" rows="3" placeholder="Bu fotoğraf için özel isteklerinizi buraya yazın..."></textarea>
                <div class="note-actions">
                    <button class="btn btn-secondary" id="main-note-cancel-btn">İptal</button>
                    <button class="btn btn-primary" id="main-note-save-btn">Kaydet</button>
                </div>
            </div>
        </main>

        <!-- Sağ Sepet Paneli -->
        <aside class="cart-sidebar" id="cart-sidebar">
            <div class="cart-header">
                <h3>Seçilenler <span id="cart-count">(0)</span></h3>
                <div class="d-flex align-items-center gap-2">
                    <!-- YENİ: Mobilde Sepetin En Tepesinde Duran Hızlı Bitir Butonu -->
                    <button class="btn btn-sm d-md-none fw-bold shadow-sm" id="mobile-quick-complete-btn" style="background: var(--primary-color); color: #fff; border: none; padding: 6px 12px;" disabled>
                        <i class="fas fa-paper-plane"></i> Bitir
                    </button>
                    <button class="cart-close" id="cart-close-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
            
            <div class="cart-items-container" id="cart-items-container">
                <div class="cart-empty-msg" id="cart-empty-msg">
                    <i class="fas fa-folder-open"></i>
                    <p>Henüz seçim yapmadınız.</p>
                </div>
            </div>

            <div class="cart-footer">
                <button class="btn btn-primary" id="complete-selection-btn" disabled>
                    <i class="fas fa-paper-plane"></i> Seçimi Tamamla
                </button>
            </div>
        </aside>

        <!-- Alt Film Şeridi Bölümü -->
        <footer class="filmstrip-wrapper">
            <div class="filmstrip-controls">
                <div class="filter-group">
                    <button class="filter-btn active" id="filter-all" data-filter="all">Tümü</button>
                    <button class="filter-btn" id="filter-selected" data-filter="selected">Seçilenler</button>
                    <button class="filter-btn" id="filter-favorited" data-filter="favorited">Favoriler</button>
                </div>
                <div class="filmstrip-info">
                    <span id="filmstrip-counter">0 / 0</span>
                </div>
            </div>
            <div class="filmstrip-container" id="filmstrip-container">
                <div class="filmstrip-track" id="filmstrip-track">
                    <!-- JS Sanal Kaydırma ile burayı dolduracak -->
                </div>
                <div class="filmstrip-loading" id="filmstrip-loading" style="display:none;">
                    <i class="fas fa-spinner fa-spin"></i> Yükleniyor...
                </div>
            </div>
        </footer>
    </div>

    <!-- Onay Modalı -->
    <div class="modal-overlay" id="confirm-modal" style="display:none;">
        <div class="confirm-modal-content">
            <button class="modal-close-btn" id="confirm-close-btn">&times;</button>
            <h3>Seçimi Tamamla</h3>
            <p>Seçtiğiniz fotoğraflar stüdyoya iletilecektir. Bu işlemden sonra seçimlerinizi değiştiremezsiniz.</p>
            
            <div class="confirm-summary">
                <div><span id="confirm-count-selected">0</span><span>Seçilen</span></div>
                <div><span id="confirm-count-favorited">0</span><span>Favori</span></div>
            </div>

            <div class="form-group">
                <label>Genel Notunuz (Opsiyonel)</label>
                <textarea id="confirm-general-note" rows="3" placeholder="Albüm tasarımı ile ilgili genel notlarınız..."></textarea>
            </div>

            <button class="btn btn-primary btn-submit" id="confirm-submit-btn">
                Seçimleri Gönder ve Bitir
            </button>
        </div>
    </div>
    
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        // Kullanıcı Menüsü Aç/Kapa
        const btn = document.getElementById("user-menu-btn");
        const dropdown = document.getElementById("user-menu-dropdown");

        btn.addEventListener("click", function (e) {
            e.stopPropagation();
            dropdown.classList.toggle("active");
        });

        document.addEventListener("click", function () {
            dropdown.classList.remove("active");
        });

        // MOBİL SEPET AÇMA / KAPAMA FİXİ (Toggle)
        const mobileCartBtn = document.getElementById("mobile-cart-toggle-btn");
        const cartSidebar = document.getElementById("cart-sidebar");

        if (mobileCartBtn && cartSidebar) {
            mobileCartBtn.addEventListener("click", function (e) {
                e.stopPropagation();
                cartSidebar.classList.toggle("open");
            });
        }

        // MOBİL HIZLI "BİTİR" BUTONU MANTIĞI
        const quickCompleteBtn = document.getElementById('mobile-quick-complete-btn');
        const originalCompleteBtn = document.getElementById('complete-selection-btn');
        const confirmModal = document.getElementById('confirm-modal');

        if (quickCompleteBtn && confirmModal) {
            // Tıklandığında sepeti kapat ve onay modalını aç
            quickCompleteBtn.addEventListener('click', function() {
                cartSidebar.classList.remove('open');
                confirmModal.style.display = 'flex';
            });

            // Orijinal butonun aktiflik (disabled) durumunu izle ve mobil butona kopyala
            if (originalCompleteBtn) {
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.attributeName === 'disabled') {
                            quickCompleteBtn.disabled = originalCompleteBtn.disabled;
                        }
                    });
                });
                observer.observe(originalCompleteBtn, { attributes: true });
            }
        }
        
        // --- GÜVENLİK GÜNCELLEMESİ: Seçimi Bitir Tıklandığında DB'de İş Akışını Güncelle ---
        const confirmSubmitBtn = document.getElementById('confirm-submit-btn');
        if(confirmSubmitBtn) {
            confirmSubmitBtn.addEventListener('click', function() {
                let fd = new FormData();
                fd.append('is_akisi_guncelle', 'bitir');
                
                fetch('secim.php', {
                    method: 'POST',
                    body: fd
                }).then(res => res.text())
                  .then(data => console.log('İş akışı güncellendi: ' + data))
                  .catch(err => console.error(err));
            });
        }
    });
    </script>

    <!-- JS Motorumuz -->
    <script src="assets/js/gallery.js"></script>
</body>
</html>