<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanım Şartları - ibiR Cari</title>
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
        .terms-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 140px 0 80px 0;
            clip-path: polygon(0 0, 100% 0, 100% 90%, 0 100%);
        }
        .hero-title { font-size: 3rem; font-weight: 800; line-height: 1.1; margin-bottom: 25px; }
        
        /* Content */
        .section-padding { padding: 100px 0; }
        .section-title { font-weight: 800; color: #2c3e50; margin-bottom: 30px; position: relative; }
        .section-title:after { content: ''; position: absolute; bottom: -10px; left: 0; width: 60px; height: 4px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 2px; }
        .terms-card { border: none; border-radius: 15px; padding: 30px; background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .terms-card h4 { color: #2c3e50; margin-bottom: 20px; font-weight: 700; }
        .highlight-box { background: linear-gradient(135deg, #f8f9ff 0%, #f0f2ff 100%); border-left: 4px solid #667eea; padding: 20px; border-radius: 0 10px 10px 0; margin: 30px 0; }
        
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
                    <li class="nav-item"><a class="nav-link" href="hakkimizda.php">Hakkımızda</a></li>
                    <li class="nav-item"><a class="nav-link active" href="kullanim_sartlari.php">Kullanım Şartları</a></li>
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
    <section class="terms-hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="hero-title">Kullanım Şartları</h1>
                    <p class="lead">ibiR Cari platformunu kullanmadan önce lütfen bu şartları dikkatlice okuyunuz.</p>
                    <p class="small">Son güncelleme: <?php echo date('d.m.Y'); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- İÇERİK -->
    <section class="section-padding">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    
                    <div class="terms-card">
                        <h4>1. Kabul ve Değişiklikler</h4>
                        <p>ibiR Cari platformunu ("Platform") kullanarak, aşağıda belirtilen şartları kabul etmiş sayılırsınız. Şirketimiz, ihtiyaç duyulması halinde bu şartlarda değişiklik yapma hakkını saklı tutar. Değişiklikler platform üzerinden veya e-posta yoluyla duyurulacaktır.</p>
                    </div>
                    
                    <div class="terms-card">
                        <h4>2. Hesap Sorumluluğu</h4>
                        <p>Kullanıcı, hesap bilgilerinin gizliliğinden ve güvenliğinden kendisi sorumludur. Hesabınız üzerinden yapılan tüm işlemlerden siz sorumlusunuz. Şüpheli bir durumda hemen bizimle iletişime geçiniz.</p>
                        <div class="highlight-box">
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>Önemli Uyarı</h5>
                            <p class="mb-0">Hesap bilgilerinizi üçüncü şahıslarla paylaşmayınız. Şifrenizi düzenli olarak değiştirmeniz önerilir.</p>
                        </div>
                    </div>
                    
                    <div class="terms-card">
                        <h4>3. Hizmet Kapsamı ve Sınırlamalar</h4>
                        <p>Platform, işletmeler için cari takip, teklif hazırlama, ajanda yönetimi ve temel muhasebe işlemleri sunar. Ancak:</p>
                        <ul>
                            <li>Platform profesyonel muhasebe yazılımı değildir</li>
                            <li>Vergi beyannameleri hazırlamaz</li>
                            <li>Yasal defter tutma zorunluluğunu ortadan kaldırmaz</li>
                            <li>Resmi belge (fatura, irsaliye) düzenleme yetkisine sahip değildir</li>
                        </ul>
                    </div>
                    
                    <div class="terms-card">
                        <h4>4. Abonelik ve Ödemeler</h4>
                        <p>Ücretsiz deneme süresi 14 gündür. Bu sürenin sonunda:</p>
                        <ul>
                            <li>Ücretsiz paketi seçebilirsiniz (sınırlı özellikler)</li>
                            <li>Ücretli paketlere geçiş yapabilirsiniz</li>
                            <li>Hesabınızı dondurup daha sonra aktifleştirebilirsiniz</li>
                        </ul>
                        <p>Ödemeler yıllık veya aylık olarak yapılabilir. İade politikamız hakkında bilgi almak için lütfen destek ekibimizle iletişime geçin.</p>
                    </div>
                    
                    <div class="terms-card">
                        <h4>5. Kullanım Kuralları</h4>
                        <p>Platformu kullanırken:</p>
                        <ul>
                            <li>Yasa dışı faaliyetlerde bulunamazsınız</li>
                            <li>Sistemi aşırı yükleyecek otomatik botlar kullanamazsınız</li>
                            <li>Başka kullanıcıları rahatsız edici içerik paylaşamazsınız</li>
                            <li>Platformun altyapısına zarar verecek girişimlerde bulunamazsınız</li>
                        </ul>
                        <p>Bu kuralları ihlal etmeniz durumunda hesabınız askıya alınabilir veya tamamen kapatılabilir.</p>
                    </div>
                    
                    <div class="terms-card">
                        <h4>6. Fikri Mülkiyet</h4>
                        <p>Platformda bulunan tüm yazılım kodları, arayüz tasarımları, logolar, markalar ve içerikler ibiR Cari'ye aittir. İzinsiz kopyalanması, dağıtılması veya kullanılması yasaktır.</p>
                    </div>
                    
                    <div class="terms-card">
                        <h4>7. Sorumluluk Reddi</h4>
                        <p>Platform "olduğu gibi" sunulmaktadır. Aşağıdaki durumlardan sorumlu değiliz:</p>
                        <ul>
                            <li>Veri kayıpları (düzenli yedek almanız önerilir)</li>
                            <li>İnternet kesintilerinden kaynaklanan erişim problemleri</li>
                            <li>Kullanıcı hatalarından kaynaklanan veri bozulmaları</li>
                            <li>Üçüncü taraf entegrasyonlarındaki problemler</li>
                        </ul>
                    </div>
                    
                    <div class="terms-card">
                        <h4>8. Sonlandırma</h4>
                        <p>Hesabınızı istediğiniz zaman sonlandırabilirsiniz. Hesap sonlandırıldığında:</p>
                        <ul>
                            <li>Tüm verileriniz 30 gün içinde kalıcı olarak silinir</li>
                            <li>Aboneliğiniz iptal edilir</li>
                            <li>Önceden ödenmiş ücretler iade edilmez</li>
                        </ul>
                    </div>
                    
                    <div class="terms-card">
                        <h4>9. Uyuşmazlık Çözümü</h4>
                        <p>Bu sözleşmeden doğan uyuşmazlıklarda öncelikle dostane çözüm aranacak, çözülememesi durumunda Kütahya Mahkemeleri yetkilidir.</p>
                    </div>
                    
                    <div class="alert alert-info mt-5">
                        <h5><i class="fas fa-question-circle me-2"></i> Sorularınız mı var?</h5>
                        <p class="mb-0">Kullanım şartları hakkında daha fazla bilgi almak için <a href="#iletisim" class="fw-bold">iletişim</a> sayfamızdan bize ulaşabilirsiniz.</p>
                    </div>
                    
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