<?php
// header_yonetim.php
// Sadece giriş yapmış kullanıcılar için

// Giriş kontrolü - Zorunlu
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: index.php");
    exit;
}

// Kullanıcı bilgilerini al
$kullanici_adi = $_SESSION['ad_soyad'];
$firma_adi = $_SESSION['firma_adi'] ?? 'Firma Paneli';
$rol = $_SESSION['rol'];
$firma_id = $_SESSION['firma_id'];
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($sayfaBasligi) ? $sayfaBasligi . ' - ' . htmlspecialchars($firma_adi) : htmlspecialchars($firma_adi) . ' - Yönetim Paneli'; ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- ANA CSS (Tüm sayfalarda ortak) -->
    <link rel="stylesheet" href="main.css">
    
    <!-- YÖNETİM PANELİ ÖZEL CSS -->
    <style>
        /* YÖNETİM PANELİ TEMEL STİLLERİ */
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Yönetim Navbar */
        .navbar-yonetim {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .navbar-yonetim .navbar-brand {
            color: var(--primary-color) !important;
            font-weight: 700;
            font-size: 1.4rem;
        }
        
        .navbar-yonetim .nav-link {
            color: #555 !important;
            font-weight: 500;
            font-size: 0.95rem;
            margin: 0 5px;
            padding: 8px 12px !important;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .navbar-yonetim .nav-link:hover,
        .navbar-yonetim .nav-link.active {
            background-color: rgba(118, 75, 162, 0.1);
            color: var(--primary-color) !important;
        }
        
        .navbar-yonetim .nav-link.active {
            font-weight: 600;
        }
        
        /* Dropdown */
        .dropdown-menu {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-radius: 10px;
            padding: 5px 0;
        }
        
        .dropdown-item {
            padding: 8px 15px;
            font-size: 0.9rem;
            color: #444;
            transition: all 0.2s;
        }
        
        .dropdown-item:hover {
            background-color: rgba(118, 75, 162, 0.1);
            color: var(--primary-color);
        }
        
        /* Container için özel stil */
        .container-yonetim {
            max-width: 1400px;
            padding-top: 20px;
            padding-bottom: 50px;
        }
        
        /* Sayfaya özel CSS eklemek için */
        <?php if(isset($inlineCSS)): ?>
        <?php echo $inlineCSS; ?>
        <?php endif; ?>
    </style>
</head>
<body>

    <!-- YÖNETİM NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-yonetim sticky-top">
        <div class="container">
            <a class="navbar-brand" href="anasayfa.php">
                <i class="fas fa-layer-group me-2"></i><?php echo htmlspecialchars($firma_adi); ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarYonetim">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarYonetim">
                <!-- Sol Menü -->
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'anasayfa.php' ? 'active' : ''; ?>" href="anasayfa.php">
                            <i class="fas fa-home me-1"></i>Ana Sayfa
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'musteriler.php' ? 'active' : ''; ?>" href="musteriler.php">
                            <i class="fas fa-users me-1"></i>Müşteriler
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'musteri_ekle.php' ? 'active' : ''; ?>" href="musteri_ekle.php">
                            <i class="fas fa-user-plus me-1"></i>Müşteri Ekle
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'raporlar.php' ? 'active' : ''; ?>" href="raporlar.php">
                            <i class="fas fa-chart-bar me-1"></i>Raporlar
                        </a>
                    </li>
                    
                    <!-- Dropdown Menü (Ekstra Özellikler) -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cog me-1"></i>Diğer
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="teklif_olustur.php"><i class="fas fa-file-signature me-2"></i>Teklif Oluştur</a></li>
                            <li><a class="dropdown-item" href="teklifler.php"><i class="fas fa-folder-open me-2"></i>Teklif Listesi</a></li>
                            <li><a class="dropdown-item" href="takvim.php"><i class="fas fa-calendar-check me-2"></i>İş Takvimi</a></li>
                            <li><a class="dropdown-item" href="borclar.php"><i class="fas fa-money-bill-wave me-2"></i>Borç Yönetimi</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="hizmetler.php"><i class="fas fa-tags me-2"></i>Hizmet Kataloğu</a></li>
                            <li><a class="dropdown-item" href="yedek.php"><i class="fas fa-database me-2"></i>Veritabanı Yedek</a></li>
                        </ul>
                    </li>
                </ul>
                
                <!-- Sağ Menü (Kullanıcı Bilgileri) -->
                <ul class="navbar-nav ms-auto">
                    <?php if($rol == 'super_admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link text-danger fw-bold" href="super_admin.php">
                            <i class="fas fa-crown me-1"></i>Süper Admin
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <div class="me-2">
                                <strong class="small d-block mb-0"><?php echo htmlspecialchars($kullanici_adi); ?></strong>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($firma_adi); ?></small>
                            </div>
                            <i class="fas fa-user-circle fa-lg"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="hesabim.php">
                                    <i class="fas fa-user-cog me-2"></i>Hesabım
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="profil.php">
                                    <i class="fas fa-id-card me-2"></i>Profilim
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger fw-bold" href="cikis.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Çıkış Yap
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>