<?php
// partials/footer_yonetim.php
// YÖNETİM PANELİ SAYFALARI İÇİN FOOTER VE SCRİPTLER
?>

    <!-- YÖNETİM PANELİ FOOTER (Masaüstünde Görünür) -->
    <footer class="mt-5 py-4 border-top d-none d-md-block bg-white">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="mb-0 text-muted small">
                        <i class="fas fa-copyright me-1"></i> <?php echo date('Y'); ?> 
                        <strong><?php echo htmlspecialchars($firma_adi ?? 'Firma Paneli'); ?></strong>
                        <span class="ms-2 badge bg-light text-dark border">ibiR Cari - Müşteri Takip Platformu</span>
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0 text-muted small">
                        <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($kullanici_adi ?? ''); ?>
                        <span class="mx-2">|</span>
                        <i class="fas fa-user-tag me-1"></i> <?php echo ucfirst($rol ?? ''); ?>
                        <span class="mx-2">|</span>
                        <i class="fas fa-clock me-1"></i> <?php echo date('d.m.Y H:i'); ?>
                    </p>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- MOBİL ALT MENÜ (APP MANTIĞI - Sadece Mobilde Görünür) -->
    <nav class="mobile-bottom-nav d-md-none">
        <a href="anasayfa.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'anasayfa.php' ? 'active' : '' ?>">
            <i class="fas fa-home"></i>
            <span>Ana Sayfa</span>
        </a>
        <a href="musteriler.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'musteriler.php' ? 'active' : '' ?>">
            <i class="fas fa-users"></i>
            <span>Müşteriler</span>
        </a>
        <!-- Ortadaki Ekle Butonu -->
        <div class="nav-item-center">
            <a href="musteri_ekle.php" class="btn-center shadow-lg">
                <i class="fas fa-plus"></i>
            </a>
        </div>
        <a href="raporlar.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'raporlar.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-pie"></i>
            <span>Raporlar</span>
        </a>
        <a href="hesabim.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'hesabim.php' ? 'active' : '' ?>">
            <i class="fas fa-user-circle"></i>
            <span>Hesabım</span>
        </a>
    </nav>

    <style>
        /* Mobil Alt Menü Stilleri */
        .mobile-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #ffffff;
            box-shadow: 0 -2px 15px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 65px;
            z-index: 1050;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
            padding: 0 10px;
        }
        
        .mobile-bottom-nav .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #a0a5ba;
            text-decoration: none;
            flex: 1;
            transition: all 0.3s;
            font-size: 0.7rem;
            gap: 3px;
        }
        
        .mobile-bottom-nav .nav-item i {
            font-size: 1.2rem;
            margin-bottom: 2px;
        }
        
        .mobile-bottom-nav .nav-item.active {
            color: #4e73df;
            font-weight: 700;
        }
        
        .mobile-bottom-nav .nav-item.active i {
            transform: translateY(-3px);
        }
        
        .mobile-bottom-nav .nav-item-center {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            top: -20px;
        }
        
        .mobile-bottom-nav .btn-center {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.5rem;
            text-decoration: none;
            border: 5px solid #f8f9fa;
            transition: transform 0.2s;
        }
        
        .mobile-bottom-nav .btn-center:active {
            transform: scale(0.95);
        }
        
        /* Mobil cihazlarda alt taraftan boşluk bırak ki menü içeriklerin üstüne binmesin */
        @media (max-width: 768px) {
            body {
                padding-bottom: 85px !important;
            }
        }
    </style>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- YÖNETİM JS (js/yonetim.js'ten yükleyeceğiz) -->
    <script src="js/yonetim.js"></script>

    <!-- ANA JS (Eğer main.js dosyan varsa) -->
    <?php if(file_exists('main.js')): ?>
    <script src="main.js"></script>
    <?php endif; ?>

    <!-- Sayfaya özel ek JS dosyaları -->
    <?php if(isset($extra_js) && is_array($extra_js)): ?>
        <?php foreach($extra_js as $js_file): ?>
            <script src="<?php echo $js_file; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- YÖNETİM PANELİ ÖZEL JS VE YARDIMCI FONKSİYONLAR -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Navbar aktif link kontrolü
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.navbar-yonetim .nav-link').forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                }
            });
            
            // Dropdown hover efekti (Masaüstü)
            if(window.innerWidth > 768) {
                const dropdowns = document.querySelectorAll('.dropdown');
                dropdowns.forEach(dropdown => {
                    dropdown.addEventListener('mouseenter', function() {
                        this.querySelector('.dropdown-toggle').classList.add('show');
                        this.querySelector('.dropdown-menu').classList.add('show');
                    });
                    
                    dropdown.addEventListener('mouseleave', function() {
                        this.querySelector('.dropdown-toggle').classList.remove('show');
                        this.querySelector('.dropdown-menu').classList.remove('show');
                    });
                });
            }
            
            // Auto-dismiss alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (alert.classList.contains('alert-auto-hide')) {
                    setTimeout(() => {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }, 5000);
                }
            });
            
            // Form submit butonlarını disable et
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        submitBtn.disabled = true;
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>İşleniyor...';
                        submitBtn.dataset.originalText = originalText;
                        
                        // 30 saniye sonra butonu tekrar aktif et (güvenlik için)
                        setTimeout(() => {
                            if (submitBtn.disabled) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = submitBtn.dataset.originalText;
                                showToast('İşlem çok uzun sürüyor. Lütfen tekrar deneyin.', 'warning');
                            }
                        }, 30000);
                    }
                });
            });
            
            // Tarih input'larına bugünün tarihini otomatik ekle
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                if (!input.value && input.name !== 'hizmet_tarihi') {
                    input.value = new Date().toISOString().split('T')[0];
                }
            });
        });
        
        // Hızlı arama fonksiyonu
        function quickSearch() {
            const searchTerm = document.getElementById('quickSearchInput')?.value;
            if (searchTerm && searchTerm.length >= 2) {
                window.location.href = `musteriler.php?q=${encodeURIComponent(searchTerm)}`;
            }
        }
        
        // Yazdır fonksiyonu
        function printPage(sectionId = null) {
            if (sectionId) {
                const element = document.getElementById(sectionId);
                if (element) {
                    const originalContent = document.body.innerHTML;
                    document.body.innerHTML = element.innerHTML;
                    window.print();
                    document.body.innerHTML = originalContent;
                    location.reload();
                }
            } else {
                window.print();
            }
        }
        
        // Toast bildirim fonksiyonu (Global)
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container-yonetim');
            if (!container) return;
            
            const toastId = 'toast-' + Date.now();
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0 mb-2`;
            toast.id = toastId;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            
            let iconClass = 'fa-info-circle';
            if (type === 'success') iconClass = 'fa-check-circle';
            else if (type === 'danger') iconClass = 'fa-times-circle';
            else if (type === 'warning') iconClass = 'fa-exclamation-triangle';

            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body fw-bold">
                        <i class="fas ${iconClass} me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            `;
            
            container.appendChild(toast);
            const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
            bsToast.show();
            
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }
        
        // Global Flash Message Trigger
        <?php if(isset($_SESSION['flash'])): ?>
            showToast("<?php echo addslashes($_SESSION['flash']['mesaj']); ?>", "<?php echo $_SESSION['flash']['tip']; ?>");
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>
    </script>
</body>
</html>