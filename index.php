<?php
require 'baglanti.php';

// --- ZATEN GİRİŞ YAPMIŞSA YÖNLENDİRME ---
if (isset($_SESSION['kullanici_id'])) {
    $rol = $_SESSION['rol'];
    
    // EĞER SÜPER ADMİN İSE ÖZEL PANELE GİTSİN
    if ($rol == 'super_admin') {
        header("Location: ibir99ibir11/0i1b2i3r/dashboard.php");
    } elseif ($rol == 'ajanda') {
        header("Location: takvim.php");
    } else {
        // Diğerleri (Firma Sahibi & Personel) Ana Sayfaya
        header("Location: anasayfa.php");
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ibiR Cari | Fotoğraf Stüdyoları İçin İş Yönetimi</title>
    
    <!-- BOOTSTRAP 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- FONT AWESOME -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- GOOGLE FONTS -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    
    <!-- ANA CSS DOSYASI -->
    <link rel="stylesheet" href="main.css">
    
    <!-- SAYFAYA ÖZEL CSS -->
    <style>
        /* Özel Hero Section Stilleri */
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 160px 0 100px 0;
            position: relative;
            overflow: hidden;
            clip-path: polygon(0 0, 100% 0, 100% 90%, 0 100%);
        }
        
        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
        }
        
        .hero-subtitle {
            font-size: 1.25rem;
            opacity: 0.9;
            margin-bottom: 2.5rem;
            font-weight: 300;
            line-height: 1.6;
        }
        
        /* Özel Butonlar */
        .btn-hero-primary {
            background-color: white;
            color: #764ba2;
            font-weight: 800;
            padding: 18px 45px;
            border-radius: 50px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            font-size: 1.1rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-hero-primary:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.3);
            color: #5e3c85;
        }
        
        .btn-hero-secondary {
            border: 2px solid rgba(255,255,255,0.8);
            color: white;
            font-weight: 700;
            padding: 16px 40px;
            border-radius: 50px;
            background: transparent;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-hero-secondary:hover {
            background-color: white;
            color: #764ba2;
            border-color: white;
        }
        
        /* Özellikler Bölümü */
        .features-section {
            background-color: #f9fbff;
            padding: 100px 0;
        }
        
        /* Fiyatlandırma Bölümü */
        .pricing-section {
            padding: 100px 0;
        }
        
        /* İletişim Bölümü */
        .contact-section {
            background-color: white;
            padding: 80px 0;
            border-top: 1px solid #eee;
        }
        
        /* İllüstrasyon */
        .dashboard-illustration {
            filter: drop-shadow(0 25px 50px rgba(0,0,0,0.3));
            transform: perspective(1000px) rotateY(-10deg) translateZ(20px);
            transition: transform 0.5s ease;
            max-width: 100%;
            height: auto;
        }
        
        .dashboard-illustration:hover {
            transform: perspective(1000px) rotateY(-5deg) translateZ(30px);
        }
        
        /* Animasyonlar */
        .fade-in-up {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s ease, transform 0.8s ease;
        }
        
        .fade-in-up.visible {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Özel Badge */
        .badge-new {
            background: linear-gradient(45deg, #ff6b6b, #ff8e53);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
            vertical-align: middle;
        }
        
        /* Scroll Animasyonu */
        .scroll-indicator {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255,255,255,0.7);
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {transform: translateY(0) translateX(-50%);}
            40% {transform: translateY(-20px) translateX(-50%);}
            60% {transform: translateY(-10px) translateX(-50%);}
        }
        
        /* Kayıt Ol Butonu Özel */
        .btn-register {
            background: linear-gradient(45deg, #ff6b6b, #ff8e53);
            color: white;
            font-weight: 800;
            padding: 18px 45px;
            border-radius: 50px;
            border: none;
            box-shadow: 0 10px 30px rgba(255,107,107,0.3);
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-register:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(255,107,107,0.4);
            color: white;
            background: linear-gradient(45deg, #ff5252, #ff7b39);
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-layer-group me-2"></i>ibiR Cari
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="#ozellikler">Özellikler</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#fiyatlar">Paketler</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#iletisim">İletişim</a>
                    </li>
                    <li class="nav-item ms-3">
                        <a href="login.php" class="btn btn-outline-dark rounded-pill px-4 fw-bold border-2">
                            <i class="fas fa-sign-in-alt me-2"></i>Giriş Yap
                        </a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="kayit_ol.php" class="btn btn-register py-2 px-4" style="padding: 10px 20px !important;">
                            <i class="fas fa-rocket me-2"></i>Ücretsiz Dene
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="hero-section d-flex align-items-center">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 pt-5 pt-lg-0 fade-in-up">
                    <h1 class="hero-title">
                        Stüdyo İşlemlerinizi Buluta Taşıyın<br>
                        <span class="text-warning">Sadece Çekime Odaklanın</span>
                    </h1>
                    <p class="hero-subtitle">
                        Müşteri cari takibi, teklif hazırlama, online fotoğraf seçimi, klip teslimatı ve randevu ajandası. 
                        Fotoğraf stüdyoları için ihtiyacınız olan her şey ibiR Cari ile tek bir platformda.
                    </p>
                    <div class="d-flex flex-column flex-sm-row gap-3 mb-4">
                        <a href="kayit_ol.php" class="btn btn-hero-primary" style="text-decoration: none;">
                            <i class="fas fa-rocket"></i>
                            7 Gün Ücretsiz Dene
                        </a>
                        <button class="btn btn-hero-secondary" onclick="scrollToSection('ozellikler')">
                            <i class="fas fa-info-circle"></i>
                            Nasıl Çalışır?
                        </button>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-3 text-white-50">
                        <div class="d-flex align-items-center gap-1">
                            <i class="fas fa-check-circle text-success"></i>
                            <span>Kredi kartı gerekmez</span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <i class="fas fa-check-circle text-success"></i>
                            <span>Anında kurulum</span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <i class="fas fa-check-circle text-success"></i>
                            <span>7/24 destek</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 d-none d-lg-block text-center fade-in-up">
                    <img src="https://cdn-icons-png.flaticon.com/512/9420/9420266.png" 
                         alt="Dashboard" 
                         class="dashboard-illustration">
                </div>
            </div>
        </div>
        
        <!-- Scroll Indicator -->
        <div class="scroll-indicator d-none d-md-block">
            <i class="fas fa-chevron-down fa-2x"></i>
        </div>
    </section>

    <!-- ÖZELLİKLER BÖLÜMÜ -->
    <section id="ozellikler" class="features-section">
        <div class="container">
            <div class="section-title fade-in-up text-center mb-5">
                <h2>Neden ibiR Cari?</h2>
                <p class="mb-0 text-muted">Karmaşık muhasebe programlarını ve USB bellek taşıma dertlerini unutun. İşinize odaklanmanız için tasarlanmış profesyonel arayüz.</p>
            </div>
            
            <!-- SATIR 1 -->
            <div class="row g-4">
                <div class="col-md-4 fade-in-up">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-primary mb-3">
                            <i class="fas fa-users fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">Müşteri & Cari Takip</h4>
                        <p class="text-muted mb-0">
                            Müşterilerinizin borç/alacak durumunu anlık görün. Kimin ne zaman ödeme yapacağını asla unutmayın. WhatsApp / Sms ile tek tıkla bakiye ve ödeme bildirin.
                        </p>
                    </div>
                </div>
                <div class="col-md-4 fade-in-up" style="animation-delay: 0.2s;">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-success mb-3">
                            <i class="fas fa-images fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">Online Seçim & Medya</h4>
                        <p class="text-muted mb-0">
                            Müşterileriniz evlerinden fotoğraflarını seçsin. Hazırladığınız sinematik klipleri ve hazır albümleri güvenle yükleyin, tek tıkla indirsinler.
                        </p>
                    </div>
                </div>
                <div class="col-md-4 fade-in-up" style="animation-delay: 0.4s;">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-warning mb-3">
                            <i class="fas fa-calendar-check fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">Akıllı Ajanda</h4>
                        <p class="text-muted mb-0">
                            Düğün, çekim ve montaj ve planlı iş tarihlerinizi takvimde görün. Google Takvim entegrasyonu ile işlerinizi cebinizden takip edin.
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- SATIR 2 -->
            <div class="row g-4 mt-1">
                <div class="col-md-4 fade-in-up" style="animation-delay: 0.2s;">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-danger mb-3">
                            <i class="fas fa-file-invoice fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">Teklif & Makbuz Robotu</h4>
                        <p class="text-muted mb-0">
                            Profesyonel fiyat tekliflerini saniyeler içinde hazırlayın. Tahsilat yaptığınızda şık ve kurumsal makbuzunuzu anında yazdırın ya da pdf gönderin.
                        </p>
                    </div>
                </div>
                <div class="col-md-4 fade-in-up" style="animation-delay: 0.4s;">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-info mb-3">
                            <i class="fas fa-chart-line fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">Detaylı Raporlar</h4>
                        <p class="text-muted mb-0">
                            Gelir-gider raporları, müşteri analizleri, en çok satan ürünler ve daha fazlası. İşinizi büyütmek için ihtiyacınız olan tüm veriler.
                        </p>
                    </div>
                </div>
                <div class="col-md-4 fade-in-up" style="animation-delay: 0.6s;">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-secondary mb-3">
                            <i class="fas fa-file-signature fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">Dijital Sözleşmeler</h4>
                        <p class="text-muted mb-0">
                            Müşteri kayıt esnasında hizmet ve çekim sözleşmelerinizi hızla oluşturun, arşivleyin ve müşteri portalı üzerinden erişilebilir kılın.
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- SATIR 3 -->
            <div class="row g-4 mt-1 justify-content-center">
                <div class="col-md-4 fade-in-up" style="animation-delay: 0.4s;">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-primary mb-3">
                            <i class="fas fa-mobile-alt fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">Mobil Uyumlu</h4>
                        <p class="text-muted mb-0">
                            Telefonunuzdan veya tabletinizden kolayca erişin. İnternet olduğu her yerde işlerinizi takip edin ve yönetin.
                        </p>
                    </div>
                </div>
                <div class="col-md-4 fade-in-up" style="animation-delay: 0.6s;">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-success mb-3">
                            <i class="fab fa-whatsapp fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">WhatsApp Entegrasyonu</h4>
                        <p class="text-muted mb-0">
                            Seçim galerisi linkini, albüm onay durumunu veya borç hatırlatmalarını sistem içerisinden tek tıkla şık bir WhatsApp mesajı olarak iletin.
                        </p>
                    </div>
                </div>
                <div class="col-md-4 fade-in-up" style="animation-delay: 0.8s;">
                    <div class="feature-card h-100 bg-white p-4 rounded shadow-sm text-center">
                        <div class="feature-icon text-dark mb-3">
                            <i class="fas fa-shield-alt fa-3x"></i>
                        </div>
                        <h4 class="feature-title fw-bold">Güvenli & Yedekli</h4>
                        <p class="text-muted mb-0">
                            Verileriniz 256-bit SSL ile şifrelenir ve günlük yedeklenir. KVKK uyumludur. Verileriniz güvende.
                        </p>
                    </div>
                </div>
            </div>
            
        </div>
    </section>

    <!-- FİYATLANDIRMA BÖLÜMÜ -->
    <section id="fiyatlar" class="pricing-section bg-light">
        <div class="container">
            <div class="section-title fade-in-up text-center mb-5">
                <h2>Size Uygun Paketler</h2>
                <p class="mb-0 text-muted">Taahhüt yok, gizli ücret yok. İşiniz büyüdükçe paketinizi yükseltin.</p>
            </div>
            
            <div class="row g-4 justify-content-center">
                <!-- Paket 1: Esnaf -->
                <div class="col-lg-4 col-md-6 fade-in-up">
                    <div class="pricing-card h-100 d-flex flex-column bg-white p-5 rounded shadow-sm border text-center">
                        <div class="mb-4">
                            <h5 class="fw-bold text-muted text-uppercase">Freelance Paketi</h5>
                            <div class="price-tag fs-1 fw-bold text-dark my-3">
                                0₺ <span class="fs-6 text-muted fw-normal">/ Ay</span>
                            </div>
                            <p class="text-muted small">Bireysel fotoğrafçılar için başlangıç</p>
                        </div>
                        
                        <ul class="feature-list flex-grow-1 list-unstyled text-start mb-4">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> 10 Çekim/Müşteri Kaydı</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Temel Cari Takip</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Online Fotoğraf Seçimi</li>
                            <li class="text-muted mb-2"><i class="fas fa-times text-danger me-2"></i> Teklif Hazırlama</li>
                            <li class="text-muted mb-2"><i class="fas fa-times text-danger me-2"></i> Video & Klip Yükleme</li>
                            <li class="text-muted mb-2"><i class="fas fa-times text-danger me-2"></i> WhatsApp Bildirim</li>
                        </ul>
                        
                        <a href="kayit_ol.php" class="btn btn-outline-primary w-100 rounded-pill py-3 fw-bold mt-auto">
                            Ücretsiz Başla
                        </a>
                    </div>
                </div>
                
                <!-- Paket 2: Kobi (Popüler) -->
                <div class="col-lg-4 col-md-6 fade-in-up" style="animation-delay: 0.2s;">
                    <div class="pricing-card popular h-100 d-flex flex-column bg-white p-5 rounded shadow border-primary border-2 text-center position-relative">
                        <div class="badge-popular bg-primary text-white position-absolute top-0 start-50 translate-middle-x py-1 px-3 rounded-bottom fw-bold" style="font-size: 0.8rem;">EN ÇOK TERCİH EDİLEN</div>
                        
                        <div class="mb-4 mt-3">
                            <h5 class="fw-bold text-primary text-uppercase">Stüdyo Paketi</h5>
                            <div class="price-tag fs-1 fw-bold text-dark my-3">
                                299₺ <span class="fs-6 text-muted fw-normal">/ Ay</span>
                            </div>
                            <p class="text-muted small">Aktif stüdyolar için ideal</p>
                        </div>
                        
                        <ul class="feature-list flex-grow-1 list-unstyled text-start mb-4">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <strong>Sınırsız</strong> Müşteri</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Gelişmiş Cari & Sözleşme</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Teklif & Makbuz Modülü</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Google Takvim Entegrasyonu</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> WhatsApp Bildirimleri</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Klip ve Albüm Teslimatı</li>
                        </ul>
                        
                        <button class="btn btn-primary shadow w-100 rounded-pill py-3 fw-bold mt-auto" onclick="selectPackage('kobi')">
                            Hemen Satın Al
                        </button>
                    </div>
                </div>
                
                <!-- Paket 3: Holding -->
                <div class="col-lg-4 col-md-6 fade-in-up" style="animation-delay: 0.4s;">
                    <div class="pricing-card h-100 d-flex flex-column bg-white p-5 rounded shadow-sm border text-center">
                        <div class="mb-4">
                            <h5 class="fw-bold text-muted text-uppercase">Ajans Paketi</h5>
                            <div class="price-tag fs-1 fw-bold text-dark my-3">
                                999₺ <span class="fs-6 text-muted fw-normal">/ Ay</span>
                            </div>
                            <p class="text-muted small">Büyük prodüksiyon ekipleri için</p>
                        </div>
                        
                        <ul class="feature-list flex-grow-1 list-unstyled text-start mb-4">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Her Şey Sınırsız</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Çoklu Kullanıcı (Ekip)</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Özel Veritabanı Yedeği</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> 7/24 VIP Destek</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Özel Alan Adı Kullanımı</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> API Erişimi</li>
                        </ul>
                        
                        <a href="kayit_ol.php?paket=holding" class="btn btn-outline-dark w-100 rounded-pill py-3 fw-bold mt-auto">
                            İletişime Geç
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Not -->
            <div class="text-center mt-5 fade-in-up" style="animation-delay: 0.6s;">
                <p class="text-muted mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Tüm paketlerde 7 gün ücretsiz deneme imkanı. İstediğiniz zaman iptal edebilirsiniz.
                </p>
            </div>
        </div>
    </section>

    <!-- İLETİŞİM BÖLÜMÜ -->
    <section id="iletisim" class="contact-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 fade-in-up">
                    <h3 class="fw-bold mb-4">Bize Ulaşın</h3>
                    <p class="text-muted mb-4">
                        Sistemi stüdyonuza entegre ederken yardıma mı ihtiyacınız var? Veya özel bir geliştirme mi istiyorsunuz? 
                        Bize her zaman yazabilirsiniz.
                    </p>
                    
                    <div class="d-flex align-items-center mb-4">
                        <div class="icon-container bg-light p-3 rounded-circle me-3 text-primary">
                            <i class="fas fa-map-marker-alt fa-lg"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0">Ofis Adresi</h6>
                            <small class="text-muted">Durak Mahallesi Ekizler Sk, No: 42, Tavşanlı / Kütahya</small>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-center mb-4">
                        <div class="icon-container bg-light p-3 rounded-circle me-3 text-primary">
                            <i class="fas fa-envelope fa-lg"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0">E-Posta</h6>
                            <small class="text-muted">destek@ibircari.com</small>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-center">
                        <div class="icon-container bg-light p-3 rounded-circle me-3 text-primary">
                            <i class="fas fa-phone-alt fa-lg"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0">Telefon & WhatsApp</h6>
                            <small class="text-muted">+90 553 950 66 96</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6 mt-4 mt-lg-0 fade-in-up" style="animation-delay: 0.2s;">
                    <div class="card border-0 shadow-lg h-100">
                        <div class="card-body p-5">
                            <h5 class="fw-bold mb-4">Hızlı Mesaj Gönder</h5>
                            <form id="contactForm">
                                <div class="mb-3">
                                    <label class="form-label">Adınız Soyadınız</label>
                                    <input type="text" class="form-control" placeholder="Adınız Soyadınız" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">E-Posta Adresiniz</label>
                                    <input type="email" class="form-control" placeholder="ornek@email.com" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Mesajınız</label>
                                    <textarea class="form-control" rows="4" placeholder="Mesajınızı buraya yazın..." required></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 py-3 fw-bold" onclick="sendContactForm(event)">
                                    <i class="fas fa-paper-plane me-2"></i>Mesajı Gönder
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="bg-dark text-white pt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h4 class="fw-bold mb-3">
                        <i class="fas fa-layer-group me-2 text-primary"></i>ibiR Cari Takip
                    </h4>
                    <p class="text-white-50 small">
                        Türkiye'nin en hızlı büyüyen, fotoğraf stüdyolarına ve esnafa özel bulut tabanlı cari takip ve iş yönetim platformu.
                    </p>
                    <div class="d-flex gap-3 mt-3">
                        <a href="#" class="text-white-50 text-decoration-none social-icon"><i class="fab fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-white-50 text-decoration-none social-icon"><i class="fab fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-white-50 text-decoration-none social-icon"><i class="fab fa-twitter fa-lg"></i></a>
                        <a href="#" class="text-white-50 text-decoration-none social-icon"><i class="fab fa-linkedin fa-lg"></i></a>
                    </div>
                </div>
                
                <div class="col-md-2 mb-4">
                    <h5 class="mb-3">Ürün</h5>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="#ozellikler" class="text-white-50 text-decoration-none">Özellikler</a></li>
                        <li class="mb-2"><a href="#fiyatlar" class="text-white-50 text-decoration-none">Fiyatlandırma</a></li>
                        <li class="mb-2"><a href="#" class="text-white-50 text-decoration-none">Güncellemeler</a></li>
                        <li class="mb-2"><a href="#" class="text-white-50 text-decoration-none">SSS</a></li>
                    </ul>
                </div>
                
                <div class="col-md-2 mb-4">
                    <h5 class="mb-3">Kurumsal</h5>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="hakkimizda.php" class="text-white-50 text-decoration-none">Hakkımızda</a></li>
                        <li class="mb-2"><a href="kullanim_sartlari.php" class="text-white-50 text-decoration-none">Kullanım Şartları</a></li>
                        <li class="mb-2"><a href="gizlilik_politikasi.php" class="text-white-50 text-decoration-none">Gizlilik Politikası</a></li>
                        <li class="mb-2"><a href="#" class="text-white-50 text-decoration-none">KVKK Bilgi Formu</a></li>
                    </ul>
                </div>
                
                <div class="col-md-4 mb-4">
                    <h5 class="mb-3">Bülten</h5>
                    <p class="text-white-50 small">
                        Yeniliklerden haberdar olmak için e-postanızı bırakın.
                    </p>
                    <div class="input-group mb-3">
                        <input type="email" class="form-control border-0" placeholder="E-Posta Adresiniz">
                        <button class="btn btn-primary" onclick="subscribeNewsletter()">Abone Ol</button>
                    </div>
                    <p class="text-white-50 small mb-0">
                        <i class="fas fa-lock me-1"></i> Bilgileriniz güvende. Asla spam göndermiyoruz.
                    </p>
                </div>
            </div>
            
            <div class="border-top border-secondary pt-4 mt-4 pb-4 text-center small text-white-50">
                <div class="row align-items-center">
                    <div class="col-md-6 text-md-start mb-3 mb-md-0">
                        &copy; <?php echo date('Y'); ?> ibiR Cari Takip Platformu
                    </div>
                    <div class="col-md-6 text-md-end">
                        Tüm hakları saklıdır.
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- BOOTSTRAP JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- ANA JS DOSYASI -->
    <script src="main.js"></script>
    
    <!-- SAYFAYA ÖZEL JS -->
    <script>
        // Sayfa yüklendiğinde çalışacak kodlar
        document.addEventListener('DOMContentLoaded', function() {
            // Scroll animasyonu
            const fadeElements = document.querySelectorAll('.fade-in-up');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                    }
                });
            }, { threshold: 0.1 });
            
            fadeElements.forEach(el => observer.observe(el));
            
            // Paket seçimi
            window.selectPackage = function(packageType) {
                // Modal kullanımını kaldırdık, Toast gösteriliyor (Eğer main.js'de tanımlıysa)
                if(typeof showToast === "function"){
                     showToast('"' + packageType.toUpperCase() + '" paketi seçildi. Yönlendiriliyorsunuz...', 'info');
                } else {
                     alert('"' + packageType.toUpperCase() + '" paketi seçildi. Yönlendiriliyorsunuz...');
                }
                
                setTimeout(() => {
                    window.location.href = 'kayit_ol.php?paket=' + packageType;
                }, 1500);
            };
            
            // Bülten aboneliği
            window.subscribeNewsletter = function() {
                const emailInput = document.querySelector('input[type="email"]');
                if (emailInput && emailInput.value) {
                    if (validateEmail(emailInput.value)) {
                        if(typeof showToast === "function") showToast('Bülten aboneliğiniz başarıyla oluşturuldu!', 'success');
                        else alert('Bülten aboneliğiniz başarıyla oluşturuldu!');
                        emailInput.value = '';
                    } else {
                        if(typeof showToast === "function") showToast('Lütfen geçerli bir e-posta adresi girin.', 'warning');
                        else alert('Lütfen geçerli bir e-posta adresi girin.');
                    }
                } else {
                    if(typeof showToast === "function") showToast('Lütfen e-posta adresinizi girin.', 'warning');
                    else alert('Lütfen e-posta adresinizi girin.');
                }
            };
            
            // İletişim formu
            window.sendContactForm = function(e) {
                e.preventDefault();
                const form = document.getElementById('contactForm');
                if (form.checkValidity()) {
                    if(typeof showToast === "function") showToast('Mesajınız başarıyla gönderildi. En kısa sürede dönüş yapacağız.', 'success');
                    else alert('Mesajınız başarıyla gönderildi. En kısa sürede dönüş yapacağız.');
                    form.reset();
                } else {
                    if(typeof showToast === "function") showToast('Lütfen tüm alanları doğru şekilde doldurun.', 'warning');
                    else alert('Lütfen tüm alanları doğru şekilde doldurun.');
                }
            };
            
            // Scroll to section
            window.scrollToSection = function(sectionId) {
                const element = document.getElementById(sectionId);
                if (element) {
                    window.scrollTo({
                        top: element.offsetTop - 80,
                        behavior: 'smooth'
                    });
                }
            };
            
            // Email doğrulama fonksiyonu
            function validateEmail(email) {
                const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return re.test(email);
            }
            
            // Navbar scroll efekti
            window.addEventListener('scroll', function() {
                const navbar = document.querySelector('.navbar');
                if (window.scrollY > 100) {
                    navbar.classList.add('shadow-sm', 'bg-white');
                    // Text renklerini karanlık yapmak için
                    document.querySelectorAll('.nav-link').forEach(el => el.classList.add('text-dark'));
                    document.querySelector('.navbar-brand').classList.add('text-dark');
                } else {
                    navbar.classList.remove('shadow-sm', 'bg-white');
                    // Text renklerini açık yapmak için
                    document.querySelectorAll('.nav-link').forEach(el => el.classList.remove('text-dark'));
                    document.querySelector('.navbar-brand').classList.remove('text-dark');
                }
            });
            
            // Dashboard illüstrasyon hover efekti
            const illustration = document.querySelector('.dashboard-illustration');
            if (illustration) {
                illustration.addEventListener('mouseenter', function() {
                    this.style.transform = 'perspective(1000px) rotateY(-5deg) translateZ(30px) scale(1.05)';
                });
                
                illustration.addEventListener('mouseleave', function() {
                    this.style.transform = 'perspective(1000px) rotateY(-10deg) translateZ(20px) scale(1)';
                });
            }
            
            // Social icon hover efekti
            const socialIcons = document.querySelectorAll('.social-icon');
            socialIcons.forEach(icon => {
                icon.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.transition = 'transform 0.3s ease';
                    this.style.color = '#fff'; // Hover'da beyaz yap
                });
                
                icon.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.color = ''; // Eski rengine dön
                });
            });
        });
    </script>

</body>
</html>