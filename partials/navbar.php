<?php
// partials/navbar.php

// Aktif sayfayı belirle
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- YÖNETİM NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-yonetim sticky-top">
    <div class="container">
        
        <!-- Marka ve Karşılama Alanı (Alt satıra sorunsuz geçmesi için d-block ve text-wrap eklendi) -->
        <a class="navbar-brand d-flex align-items-start" href="anasayfa.php" style="max-width: 65%;">
            <i class="fas fa-layer-group me-2 text-primary mt-1"></i>
            <div class="d-flex flex-column" style="line-height: 1.2;">
                <!-- text-wrap sayesinde uzun isimler aşağı iner -->
                <span class="fw-bold text-wrap" style="font-size: 1.1rem; word-break: break-word;">
                    <?php echo htmlspecialchars($firma_adi ?? 'Firma Paneli'); ?>
                </span>
        </div>
        </a>
        
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarYonetim">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarYonetim">
            <!-- Sol Menü -->
            <ul class="navbar-nav me-auto mt-2 mt-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'anasayfa.php' ? 'active' : ''; ?>" href="anasayfa.php">
                        <i class="fas fa-home me-1"></i>Ana Sayfa
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'musteriler.php' ? 'active' : ''; ?>" href="musteriler.php">
                        <i class="fas fa-users me-1"></i>Müşteriler
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'musteri_ekle.php' ? 'active' : ''; ?>" href="musteri_ekle.php">
                        <i class="fas fa-user-plus me-1"></i>Müşteri Ekle
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'raporlar.php' ? 'active' : ''; ?>" href="raporlar.php">
                        <i class="fas fa-chart-bar me-1"></i>Raporlar
                    </a>
                </li>
                
                <!-- Dropdown Menü (Sadece admin/yönetici için) -->
                <?php if(in_array($rol ?? '', ['admin', 'super_admin', 'yonetici'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-cog me-1"></i>Araçlar
                    </a>
                    <ul class="dropdown-menu border-0 shadow-sm mt-2">
                        <li><a class="dropdown-item py-2" href="teklif_olustur.php"><i class="fas fa-file-signature me-2 text-primary"></i>Teklif Oluştur</a></li>
                        <li><a class="dropdown-item py-2" href="teklifler.php"><i class="fas fa-folder-open me-2 text-warning"></i>Teklif Listesi</a></li>
                        <li><a class="dropdown-item py-2" href="takvim.php"><i class="fas fa-calendar-check me-2 text-success"></i>İş Takvimi</a></li>
                        <li><a class="dropdown-item py-2" href="borclar.php"><i class="fas fa-money-bill-wave me-2 text-danger"></i>Borç Yönetimi</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2" href="hizmetler.php"><i class="fas fa-tags me-2 text-info"></i>Hizmet Kataloğu</a></li>
                        <li><a class="dropdown-item py-2" href="yedek.php"><i class="fas fa-database me-2 text-secondary"></i>Veritabanı Yedek</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            
            <!-- Sağ Menü (Kullanıcı Bilgileri) -->
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <?php if(($rol ?? '') == 'super_admin'): ?>
                <li class="nav-item me-lg-2 mb-2 mb-lg-0">
                    <a class="nav-link text-danger fw-bold bg-danger bg-opacity-10 rounded px-3" href="super_admin.php">
                        <i class="fas fa-crown me-1"></i>Süper Admin
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- DROPDOWN KAPANMA SORUNUNU ÇÖZEN EKLENTİ (aria-expanded ve m-0) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center p-0 mt-3 mt-lg-0" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="me-2 text-end d-none d-lg-block">
                            <strong class="small d-block mb-0 lh-1"><?php echo htmlspecialchars($kullanici_adi ?? ''); ?></strong>
                            <small class="text-muted" style="font-size: 0.7rem;"><?php echo ucfirst($rol ?? ''); ?></small>
                        </div>
                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-primary border" style="width: 40px; height: 40px;">
                            <i class="fas fa-user"></i>
                        </div>
                    </a>
                    <!-- mt-0 eklendi ki butonla menü arasında boşluk kalmasın, fare araya girince kapanmasın -->
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm mt-0" style="margin-top: -5px !important;">
                        <li>
                            <a class="dropdown-item py-2" href="hesabim.php?tab=abonelik">
                                <i class="fas fa-box me-2 text-primary"></i>Abonelik & Limit
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2" href="hesabim.php?tab=profil">
                                <i class="fas fa-user-cog me-2 text-secondary"></i>Profilim
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2" href="destek.php">
                                <i class="fas fa-headset me-2 text-info"></i>Destek Talebi
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger fw-bold py-2" href="cikis.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Çıkış Yap
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>