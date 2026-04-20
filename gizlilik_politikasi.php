<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gizlilik Politikası - ibiR Cari</title>
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
        .privacy-hero {
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
        .privacy-card { border: none; border-radius: 15px; padding: 30px; background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .privacy-card h4 { color: #2c3e50; margin-bottom: 20px; font-weight: 700; }
        .data-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .data-table th, .data-table td { border: 1px solid #eee; padding: 12px; text-align: left; }
        .data-table th { background-color: #f8f9fa; font-weight: 600; }
        .info-box { background: #e8f4ff; border-radius: 10px; padding: 20px; margin: 20px 0; }
        
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
                    <li class="nav-item"><a class="nav-link" href="kullanim_sartlari.php">Kullanım Şartları</a></li>
                    <li class="nav-item"><a class="nav-link active" href="gizlilik_politikasi.php">Gizlilik</a></li>
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
    <section class="privacy-hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="hero-title">Gizlilik Politikası</h1>
                    <p class="lead">Verilerinizin güvenliği bizim için önceliktir. Kişisel bilgileriniz nasıl korunuyor, öğrenin.</p>
                    <p class="small">Son güncelleme: <?php echo date('d.m.Y'); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- İÇERİK -->
    <section class="section-padding">
        <div class="container">
            <div class="row">
                <div class="col-lg-10 mx-auto">
                    
                    <div class="privacy-card">
                        <h4>1. Giriş</h4>
                        <p>ibiR Cari ("biz", "bizim" veya "firmamız") olarak, kullanıcılarımızın gizliliğini önemsiyoruz. Bu Gizlilik Politikası, ibiR Cari platformunu ("Platform") kullanırken kişisel bilgilerinizin nasıl toplandığını, kullanıldığını, paylaşıldığını ve korunduğunu açıklar.</p>
                        <div class="info-box">
                            <h5><i class="fas fa-shield-alt me-2"></i> KVKK Uyumluluğu</h5>
                            <p class="mb-0">Firmamız, 6698 sayılı Kişisel Verilerin Korunması Kanunu'na (KVKK) tam uyum sağlamaktadır. Veri İşleme Envanteri'ni düzenli olarak güncellemekteyiz.</p>
                        </div>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>2. Toplanan Bilgiler</h4>
                        <p>Platformumuzu kullanırken aşağıdaki bilgileri topluyoruz:</p>
                        
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Bilgi Türü</th>
                                    <th>Örnekler</th>
                                    <th>Toplama Amacı</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Kimlik Bilgileri</strong></td>
                                    <td>Ad, soyad, TCKN, vergi numarası</td>
                                    <td>Fatura kesme, resmi işlemler</td>
                                </tr>
                                <tr>
                                    <td><strong>İletişim Bilgileri</strong></td>
                                    <td>E-posta, telefon, adres</td>
                                    <td>Destek, bildirimler, güncellemeler</td>
                                </tr>
                                <tr>
                                    <td><strong>İş Bilgileri</strong></td>
                                    <td>Firma adı, sektör, çalışan sayısı</td>
                                    <td>Hizmet kişiselleştirme</td>
                                </tr>
                                <tr>
                                    <td><strong>Kullanım Verileri</strong></td>
                                    <td>IP adresi, tarayıcı, cihaz bilgisi</td>
                                    <td>Güvenlik, analiz, geliştirme</td>
                                </tr>
                                <tr>
                                    <td><strong>Mali Veriler</strong></td>
                                    <td>Ödeme bilgileri, abonelik geçmişi</td>
                                    <td>Fatura kesme, ödeme işlemleri</td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <p class="mt-3"><strong>Not:</strong> Ödeme bilgileriniz (kredi kartı numaraları vb.) doğrudan bize ulaşmaz. Ödeme işlemleri SSL şifrelemesi ile korunan üçüncü taraf ödeme sağlayıcılar (İyzico, Stripe) üzerinden yapılır.</p>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>3. Verilerin Kullanım Amaçları</h4>
                        <p>Topladığımız verileri aşağıdaki amaçlarla kullanıyoruz:</p>
                        <ul>
                            <li><strong>Hizmet Sağlama:</strong> Platformun tüm özelliklerini sunabilmek</li>
                            <li><strong>Güvenlik:</strong> Hesabınızı yetkisiz erişimden korumak</li>
                            <li><strong>İletişim:</strong> Önemli güncellemeler, destek mesajları</li>
                            <li><strong>Geliştirme:</strong> Platformu iyileştirmek, yeni özellikler eklemek</li>
                            <li><strong>Yasal Yükümlülükler:</strong> Vergi, muhasebe ve diğer yasal gereklilikler</li>
                        </ul>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>4. Veri Paylaşımı</h4>
                        <p>Kişisel verilerinizi aşağıdaki durumlar dışında üçüncü şahıslarla paylaşmıyoruz:</p>
                        <ul>
                            <li><strong>Yasal Zorunluluk:</strong> Mahkeme kararı veya yasal gereklilik durumunda</li>
                            <li><strong>Hizmet Sağlayıcılar:</strong> Hosting, ödeme işlemleri, e-posta gönderimi için (sadece gerekli minimum veri)</li>
                            <li><strong>İzinli Paylaşım:</strong> Açık izninizle ve sınırlı amaçlarla</li>
                        </ul>
                        <p>Tüm hizmet sağlayıcılarımızla KVKK uyumluluk sözleşmeleri imzalanmıştır.</p>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>5. Veri Güvenliği</h4>
                        <p>Verilerinizi korumak için aşağıdaki önlemleri alıyoruz:</p>
                        <ul>
                            <li><strong>SSL Şifreleme:</strong> Tüm veri transferleri 256-bit SSL ile şifrelenir</li>
                            <li><strong>Veri Tabanı Güvenliği:</strong> Veritabanlarımız güvenli sunucularda, firewall koruması altında</li>
                            <li><strong>Düzenli Yedekleme:</strong> Veriler günlük olarak yedeklenir</li>
                            <li><strong>Erişim Kontrolü:</strong> Verilere sadece yetkili personel erişebilir</li>
                            <li><strong>Pentest:</strong> Düzenli güvenlik testleri yapılır</li>
                        </ul>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>6. Veri Saklama Süreleri</h4>
                        <p>Verilerinizi yasal zorunluluklar ve hizmet süresi boyunca saklıyoruz:</p>
                        <ul>
                            <li><strong>Üyelik verileri:</strong> Hesap aktif olduğu sürece + 2 yıl</li>
                            <li><strong>Fatura bilgileri:</strong> 10 yıl (yasal zorunluluk)</li>
                            <li><strong>Kullanım verileri:</strong> 3 yıl</li>
                            <li><strong>Destek talepleri:</strong> 5 yıl</li>
                        </ul>
                        <p>Hesabınızı kapattığınızda, verileriniz 30 gün içinde silinir (yasal saklama zorunluluğu olanlar hariç).</p>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>7. Çerezler (Cookies)</h4>
                        <p>Platformumuz aşağıdaki çerez türlerini kullanır:</p>
                        <ul>
                            <li><strong>Zorunlu Çerezler:</strong> Platformun çalışması için gerekli</li>
                            <li><strong>Performans Çerezleri:</strong> Kullanım analizi için</li>
                            <li><strong>Fonksiyonel Çerezler:</strong> Tercihlerinizi hatırlamak için</li>
                        </ul>
                        <p>Tarayıcınızın ayarlarından çerezleri kontrol edebilirsiniz, ancak bu platformun bazı özelliklerinin çalışmamasına neden olabilir.</p>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>8. Haklarınız (KVKK 11. Madde)</h4>
                        <p>6698 sayılı Kanun'un 11. maddesi uyarınca, aşağıdaki haklara sahipsiniz:</p>
                        <ol>
                            <li>Kişisel verilerinizin işlenip işlenmediğini öğrenme</li>
                            <li>İşlenmişse buna ilişkin bilgi talep etme</li>
                            <li>İşlenme amacını ve bunların amacına uygun kullanılıp kullanılmadığını öğrenme</li>
                            <li>Yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme</li>
                            <li>Eksik veya yanlış işlenmiş olması halinde düzeltilmesini isteme</li>
                            <li>Kanunun 7. maddesinde öngörülen şartlar çerçevesinde silinmesini veya yok edilmesini isteme</li>
                            <li>Düzeltilme, silinme veya yok edilme kapsamında aktarıldığı üçüncü kişilere bildirilmesini isteme</li>
                            <li>İşlenen verilerin münhasıran otomatik sistemler vasıtasıyla analiz edilmesi suretiyle aleyhinize bir sonucun ortaya çıkmasına itiraz etme</li>
                            <li>İhlal nedeniyle zarara uğramanız halinde zararın giderilmesini talep etme</li>
                        </ol>
                        <p>Haklarınızı kullanmak için <strong>destek@ibircari.com</strong> adresine talebinizi iletebilirsiniz. Talebiniz en geç 30 gün içinde değerlendirilecektir.</p>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>9. Çocuk Gizliliği</h4>
                        <p>Platformumuz 18 yaşından küçük kişiler tarafından kullanılmamalıdır. Bilmeden 18 yaşından küçük birinin kişisel verisini toplarsak, bu verileri derhal sileriz.</p>
                    </div>
                    
                    <div class="privacy-card">
                        <h4>10. Değişiklikler ve İletişim</h4>
                        <p>Bu gizlilik politikasını zaman zaman güncelleyebiliriz. Değişiklikler platform üzerinden duyurulacaktır. Politika ile ilgili sorularınız için:</p>
                        <p><strong>Veri Sorumlusu:</strong> ibiR Cari Yazılım Ltd. Şti.<br>
                        <strong>Adres:</strong> Durak Mahallesi Ekizler Sk, No: 42, Tavşanlı / Kütahya<br>
                        <strong>E-posta:</strong> destek@ibircari.com<br>
                        <strong>Telefon:</strong> +90 553 950 66 96</p>
                    </div>
                    
                    <div class="alert alert-success mt-5">
                        <h5><i class="fas fa-check-circle me-2"></i> KVKK Bilgi Formu</h5>
                        <p class="mb-0">KVKK Aydınlatma Metni ve Açık Rıza Formu için lütfen <a href="#" class="fw-bold">tıklayınız</a>.</p>
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