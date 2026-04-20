<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hakkımızda - ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #fff; }
        
        /* Navbar */
        .navbar { padding: 20px 0; transition: all 0.3s; background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); }
        .navbar-brand { font-weight: 800; font-size: 1.6rem; color: #2c3e50 !important; letter-spacing: -0.5px; }
        .nav-link { font-weight: 600; color: #555 !important; margin: 0 10px; font-size: 0.95rem; }
        .nav-link:hover { color: #667eea !important; }
        
        /* Hero Section */
        .about-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 140px 0 80px 0;
            clip-path: polygon(0 0, 100% 0, 100% 90%, 0 100%);
        }
        .hero-title { font-size: 3rem; font-weight: 800; line-height: 1.1; margin-bottom: 25px; }
        
        /* Content Sections */
        .section-padding { padding: 100px 0; }
        .section-title { font-weight: 800; color: #2c3e50; margin-bottom: 40px; position: relative; }
        .section-title:after { content: ''; position: absolute; bottom: -15px; left: 0; width: 80px; height: 4px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 2px; }
        .team-card { border: none; border-radius: 20px; padding: 30px; background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); transition: 0.3s; }
        .team-card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .team-img { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; margin: 0 auto 20px; border: 5px solid #f8f9fa; }
        
        /* Footer */
        footer { background-color: #2c3e50; color: white; padding: 80px 0 40px 0; }
        footer h5 { font-weight: 700; margin-bottom: 20px; }
        footer a { color: #a0a0a0; text-decoration: none; transition: 0.3s; display: block; margin-bottom: 10px; }
        footer a:hover { color: white; padding-left: 5px; }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-layer-group me-2"></i>ibiR Cari</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="index.php">Ana Sayfa</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#ozellikler">Özellikler</a></li>
                    <li class="nav-item"><a class="nav-link active" href="hakkimizda.php">Hakkımızda</a></li>
                    <li class="nav-item"><a class="nav-link" href="kullanim_sartlari.php">Kullanım Şartları</a></li>
                    <li class="nav-item"><a class="nav-link" href="gizlilik_politikasi.php">Gizlilik</a></li>
                    <li class="nav-item ms-2">
                        <button class="btn btn-outline-dark rounded-pill px-4 fw-bold border-2" data-bs-toggle="modal" data-bs-target="#loginModal">Giriş Yap</button>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="kayit_ol.php" class="btn btn-primary rounded-pill px-4 fw-bold text-white shadow-sm" style="background: #764ba2; border:none;">Ücretsiz Dene</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="about-hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="hero-title">Hakkımızda</h1>
                    <p class="lead">2015 yılından beri işletmelerin dijital dönüşümüne liderlik ediyor, basit ve etkili çözümler sunuyoruz.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- MİSYON & VİZYON -->
    <section class="section-padding">
        <div class="container">
            <div class="row">
                <div class="col-lg-6 mb-5 mb-lg-0">
                    <h2 class="section-title">Misyonumuz</h2>
                    <p class="lead mb-4">Küçük ve orta ölçekli işletmelerin bürokratik iş yükünü azaltmak, onların daha verimli çalışmasını sağlamak ve Türkiye ekonomisine katkıda bulunmak.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3"><i class="fas fa-check-circle text-success me-2"></i> <strong>Basitlik:</strong> Karmaşık olmayan, herkesin kullanabileceği arayüz</li>
                        <li class="mb-3"><i class="fas fa-check-circle text-success me-2"></i> <strong>Erişilebilirlik:</strong> Bulut tabanlı, her yerden ulaşılabilir</li>
                        <li class="mb-3"><i class="fas fa-check-circle text-success me-2"></i> <strong>Güvenilirlik:</strong> %99.9 uptime ve güvenli veri saklama</li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <h2 class="section-title">Vizyonumuz</h2>
                    <p class="lead mb-4">2028 yılına kadar Türkiye'nin en çok tercih edilen 3 iş yönetim platformundan biri olmak ve 50.000+ aktif kullanıcıya ulaşmak.</p>
                    <div class="bg-light p-4 rounded-4 mb-4">
                        <h5 class="fw-bold">Neden Bizi Tercih Etmelisiniz?</h5>
                        <p class="mb-0">Çünkü biz sadece yazılım satmıyoruz, işletmenizin dijital partneri olmak istiyoruz. 7/24 destek, ücretsiz eğitimler ve sürekli güncellenen özellikler ile yanınızdayız.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- EKİBİMİZ -->
    <section class="section-padding bg-light">
        <div class="container">
            <h2 class="text-center section-title mb-5">Kurucu Ekibimiz</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="team-card text-center">
                        <img src="https://randomuser.me/api/portraits/men/32.jpg" class="team-img" alt="Kurucu">
                        <h4 class="fw-bold">Ahmet Yılmaz</h4>
                        <p class="text-primary fw-semibold">Kurucu & CEO</p>
                        <p>15 yıllık yazılım geliştirme deneyimi. 2015'te ibiR Cari fikrini hayata geçirdi.</p>
                        <div class="d-flex justify-content-center gap-3">
                            <a href="#"><i class="fab fa-linkedin text-muted"></i></a>
                            <a href="#"><i class="fab fa-twitter text-muted"></i></a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="team-card text-center">
                        <img src="https://randomuser.me/api/portraits/women/44.jpg" class="team-img" alt="Kurucu">
                        <h4 class="fw-bold">Zeynep Kaya</h4>
                        <p class="text-primary fw-semibold">Kurucu & CTO</p>
                        <p>Bulut bilişim ve güvenlik uzmanı. Sistem altyapısı ve güvenliği sorumlusu.</p>
                        <div class="d-flex justify-content-center gap-3">
                            <a href="#"><i class="fab fa-linkedin text-muted"></i></a>
                            <a href="#"><i class="fab fa-github text-muted"></i></a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="team-card text-center">
                        <img src="https://randomuser.me/api/portraits/men/67.jpg" class="team-img" alt="Kurucu">
                        <h4 class="fw-bold">Mehmet Demir</h4>
                        <p class="text-primary fw-semibold">Kurucu & Satış Direktörü</p>
                        <p>10 yıllık SaaS satış deneyimi. 500+ işletmeye dijital dönüşümde liderlik etti.</p>
                        <div class="d-flex justify-content-center gap-3">
                            <a href="#"><i class="fab fa-linkedin text-muted"></i></a>
                            <a href="#"><i class="fab fa-instagram text-muted"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- İSTATİSTİKLER -->
    <section class="section-padding">
        <div class="container">
            <h2 class="text-center section-title mb-5">Rakamlarla ibiR Cari</h2>
            <div class="row text-center">
                <div class="col-md-3 mb-4">
                    <div class="display-4 fw-bold text-primary">2.500+</div>
                    <p class="text-muted">Aktif Kullanıcı</p>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="display-4 fw-bold text-primary">45+</div>
                    <p class="text-muted">Şehirde Hizmet</p>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="display-4 fw-bold text-primary">%99.9</div>
                    <p class="text-muted">Sistem Uptime</p>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="display-4 fw-bold text-primary">7/24</div>
                    <p class="text-muted">Teknik Destek</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h4 class="fw-bold mb-3"><i class="fas fa-layer-group me-2"></i>ibiR Cari Takip</h4>
                    <p class="text-white-50 small">Türkiye'nin en hızlı büyüyen, esnaf dostu bulut tabanlı cari takip ve iş yönetim platformu.</p>
                    <div class="d-flex gap-3 mt-3">
                        <a href="#" class="text-white"><i class="fab fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-twitter fa-lg"></i></a>
                    </div>
                </div>
                <div class="col-md-2 mb-4">
                    <h5>Ürün</h5>
                    <ul class="list-unstyled small text-white-50">
                        <li><a href="index.php#ozellikler">Özellikler</a></li>
                        <li><a href="index.php#fiyatlar">Fiyatlandırma</a></li>
                        <li><a href="#">Güncellemeler</a></li>
                    </ul>
                </div>
                <div class="col-md-2 mb-4">
                    <h5>Kurumsal</h5>
                    <ul class="list-unstyled small text-white-50">
                        <li><a href="hakkimizda.php">Hakkımızda</a></li>
                        <li><a href="kullanim_sartlari.php">Kullanım Şartları</a></li>
                        <li><a href="gizlilik_politikasi.php">Gizlilik Politikası</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-4">
                    <h5>Bülten</h5>
                    <p class="text-white-50 small">Yeniliklerden haberdar olmak için e-postanızı bırakın.</p>
                    <div class="input-group">
                        <input type="text" class="form-control border-0" placeholder="E-Posta Adresiniz">
                        <button class="btn btn-primary">Abone Ol</button>
                    </div>
                </div>
            </div>
            <div class="border-top border-secondary pt-4 mt-4 text-center small text-white-50">
                &copy; <?php echo date('Y'); ?> ibiR Cari Takip Platformu Tüm hakları saklıdır.
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>