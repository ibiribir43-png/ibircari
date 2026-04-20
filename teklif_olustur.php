<?php
session_start();
require 'baglanti.php';

// 1. GÜVENLİK KONTROLÜ
require_once 'partials/security_check.php';

// Sayfa başlığı
$page_title = "Yeni Teklif Oluştur";
$firma_id = $_SESSION['firma_id']; // session'dan firma_id'yi al

// Firma Ayarlarını Çek
$firmaAyar = $db->prepare("SELECT firma_adi, teklif_sartlari, teklif_alt_bilgi FROM firmalar WHERE id = ?");
$firmaAyar->execute([$firma_id]);
$varsayilan = $firmaAyar->fetch(PDO::FETCH_ASSOC);

$firmaAdi = $varsayilan['firma_adi'] ?? 'Firma Adı';
$defaultSartlar = $varsayilan['teklif_sartlari'] ?? "1. Fiyatlara KDV dahil değildir.\n2. Teklif 15 gün geçerlidir.";
$defaultFooter = $varsayilan['teklif_alt_bilgi'] ?? $firmaAdi;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $musteri_adi = $_POST['musteri_adi'];
    $telefon     = $_POST['telefon'] ?? '';
    $konu        = $_POST['konu_baslik'];
    $tarih       = $_POST['tarih'];
    $gecerlilik  = $_POST['gecerlilik'];
    $ozel_sartlar = $_POST['ozel_sartlar'];
    $ozel_alt_bilgi = $_POST['ozel_alt_bilgi'];
    
    // --- KALEMLERİ PAKETLE (JSON) ---
    $kalemler_dizisi = [];
    $genelToplam = 0;
    
    $adlar = $_POST['kalem_adi'];
    $adetler = $_POST['kalem_adet'];
    $fiyatlar = $_POST['kalem_fiyat'];

    for($i=0; $i < count($adlar); $i++) {
        if(!empty($adlar[$i])) {
            $fiyat = $fiyatlar[$i] !== "" ? (float)$fiyatlar[$i] : 0;
            $adet = $adetler[$i] !== "" ? (int)$adetler[$i] : 1;
            
            $satirToplam = $adet * $fiyat;
            $genelToplam += $satirToplam;
            
            // Pakete ekle
            $kalemler_dizisi[] = [
                'aciklama' => $adlar[$i],
                'adet' => $adet,
                'birim_fiyat' => $fiyat,
                'toplam_fiyat' => $satirToplam
            ];
        }
    }
    
    // Diziyi veritabanına sığacak metne çevir (JSON)
    $kalemler_json = json_encode($kalemler_dizisi, JSON_UNESCAPED_UNICODE);
    $token = md5(uniqid(rand(), true));

    // TEK SEFERDE KAYIT (Telefon alanı eklendi)
    $sorgu = $db->prepare("INSERT INTO teklifler (firma_id, url_token, musteri_adi, telefon, konu_baslik, tarih, gecerlilik_gun, toplam_tutar, kalemler, ozel_sartlar, ozel_alt_bilgi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $sorgu->execute([$firma_id, $token, $musteri_adi, $telefon, $konu, $tarih, $gecerlilik, $genelToplam, $kalemler_json, $ozel_sartlar, $ozel_alt_bilgi]);
    
    // Başarı mesajı
    $_SESSION['success_message'] = "Teklif başarıyla oluşturuldu!";
    header("Location: teklif_detay.php?t=$token");
    exit;
}

// Sayfaya özel CSS
$inline_css = '
    .teklif-editor-container {
        background: #f8f9fa;
        min-height: 100vh;
        padding: 20px 0;
    }
    .teklif-kagit {
        background: white;
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        padding: 20mm;
        box-shadow: 0 5px 30px rgba(0,0,0,0.1);
        position: relative;
        border-radius: 8px;
    }
    .teklif-baslik {
        border-bottom: 3px solid #4e73df;
        padding-bottom: 20px;
        margin-bottom: 30px;
    }
    .form-control-plaintext { 
        border-bottom: 1px dashed #ddd;
        padding: 5px;
        border-radius: 0;
        background: transparent;
        transition: all 0.3s;
    }
    .form-control-plaintext:focus {
        border-bottom: 2px solid #4e73df;
        outline: none;
        background-color: rgba(78, 115, 223, 0.05);
    }
    .satir-sil-btn { 
        opacity: 0.3;
        transition: 0.3s;
        cursor: pointer;
        color: #e74a3b;
    }
    .kalem-satir:hover .satir-sil-btn { 
        opacity: 1;
    }
    .btn-ekle {
        background: linear-gradient(45deg, #1cc88a, #13855c);
        color: white;
        font-weight: bold;
    }
    .btn-kaydet {
        background: linear-gradient(45deg, #4e73df, #224abe);
        color: white;
        font-weight: bold;
        transition: transform 0.2s;
    }
    .btn-kaydet:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(78, 115, 223, 0.4);
        color: white;
    }
    .btn-listeye-don {
        background: linear-gradient(45deg, #6c757d, #5a6268);
        color: white;
    }
    .table-header-bg {
        background: linear-gradient(45deg, #4e73df, #224abe);
        color: white;
    }
    .table-header-bg th {
        border: none !important;
        font-weight: 500;
    }
    @media print {
        .no-print { display: none !important; }
        .teklif-kagit { 
            box-shadow: none !important;
            margin: 0 !important;
            padding: 15mm !important;
            width: auto !important;
        }
        body { background: white !important; }
    }
    @media (max-width: 768px) {
        .teklif-kagit {
            width: 95%;
            padding: 15px;
            margin: 10px auto;
        }
        .container-yonetim { padding: 10px; }
    }
';

// Sayfaya özel JS
$inline_js = '
    function msjGoster(mesaj, tip) {
        if(typeof showYonetimToast === "function") {
            showYonetimToast(mesaj, tip);
        } else if(typeof showToast === "function") {
            showToast(mesaj, tip);
        } else {
            alert(mesaj);
        }
    }

    function satirEkle() {
        const container = document.getElementById("kalemler-container");
        const tr = document.createElement("tr");
        tr.className = "kalem-satir";
        tr.innerHTML = `
            <td><input type="text" name="kalem_adi[]" class="form-control border-0" placeholder="Hizmet adı..." required></td>
            <td><input type="number" name="kalem_adet[]" class="form-control border-0 text-center" value="1" min="1" required></td>
            <td><input type="number" step="0.01" name="kalem_fiyat[]" class="form-control border-0 text-end" placeholder="0.00" min="0"></td>
            <td class="text-center align-middle no-print">
                <i class="fas fa-times satir-sil-btn" onclick="satirSil(this)" title="Satırı Sil" style="font-size: 1.2rem;"></i>
            </td>
        `;
        container.appendChild(tr);
        
        // Yeni eklenen satıra odaklan
        const inputs = tr.querySelectorAll("input");
        if (inputs[0]) inputs[0].focus();
    }
    
    function satirSil(btn) {
        const rows = document.querySelectorAll(".kalem-satir");
        if (rows.length > 1) {
            const tr = btn.closest("tr");
            tr.classList.add("animate__animated", "animate__fadeOut");
            setTimeout(() => {
                tr.remove();
                toplamHesapla();
            }, 300);
        } else {
            msjGoster("En az bir satır olmalı!", "warning");
        }
    }
    
    function toplamHesapla() {
        let toplam = 0;
        document.querySelectorAll(".kalem-satir").forEach(row => {
            const adet = parseFloat(row.querySelector("[name=\'kalem_adet[]\']").value) || 0;
            const fiyatInput = row.querySelector("[name=\'kalem_fiyat[]\']").value;
            const fiyat = fiyatInput === "" ? 0 : parseFloat(fiyatInput); 
            
            toplam += adet * fiyat;
        });
        
        const toplamElement = document.getElementById("genel-toplam");
        if (toplamElement) {
            toplamElement.textContent = toplam.toLocaleString("tr-TR", {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
    }
    
    document.addEventListener("DOMContentLoaded", function() {
        document.getElementById("kalemler-container").addEventListener("input", function(e) {
            if (e.target.name === "kalem_adet[]" || e.target.name === "kalem_fiyat[]") {
                toplamHesapla();
            }
        });
        
        setTimeout(toplamHesapla, 100);
        
        const tarihInput = document.querySelector("input[name=\'tarih\']");
        if (tarihInput && !tarihInput.value) {
            const today = new Date().toISOString().split("T")[0];
            tarihInput.value = today;
        }
    });
    
    document.querySelector("form").addEventListener("submit", function(e) {
        let valid = true;
        let emptyRows = 0;
        
        document.querySelectorAll(".kalem-satir").forEach((row, index) => {
            const ad = row.querySelector("[name=\'kalem_adi[]\']").value.trim();
            const fiyatVal = row.querySelector("[name=\'kalem_fiyat[]\']").value;
            
            if (!ad && fiyatVal !== "") {
                valid = false;
                msjGoster((index + 1) + ". satırda hizmet adı boş bırakılamaz!", "danger");
            }
            
            if (!ad && fiyatVal === "") {
                emptyRows++;
            }
        });
        
        if (!valid) {
            e.preventDefault();
            return;
        }
        
        const totalRows = document.querySelectorAll(".kalem-satir").length;
        if (emptyRows === totalRows) {
            e.preventDefault();
            msjGoster("Lütfen teklife en az bir hizmet/ürün kalemi ekleyin!", "warning");
            return;
        }
        
        const submitBtn = document.getElementById("kaydet_btn");
        if (submitBtn) {
            submitBtn.disabled = true;
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = \'<i class="fas fa-spinner fa-spin me-2"></i>Kaydediliyor...\';
            
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            }, 5000);
        }
    });
';

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | <?php echo htmlspecialchars($firmaAdi); ?></title>
    
    <link rel="stylesheet" href="css/yonetim.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        <?php echo $inline_css; ?>
    </style>
</head>
<body class="yonetim-body">

<?php include 'partials/navbar.php'; ?>

<div class="container-yonetim pb-5 mt-4">
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h3 class="text-secondary mb-0"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Yeni Teklif Oluştur</h3>
            <p class="text-muted mb-0">Müşterilerinize şık ve profesyonel bir teklif hazırlayın.</p>
        </div>
        <div class="col-md-6 text-end">
            <div class="btn-group no-print" role="group">
                <a href="teklifler.php" class="btn btn-listeye-don">
                    <i class="fas fa-arrow-left me-2"></i>Tekliflere Dön
                </a>
            </div>
        </div>
    </div>
    
    <div class="teklif-editor-container rounded">
        <form method="POST" id="teklifForm">
            <div class="teklif-kagit">
                
                <!-- Üst Bilgi -->
                <div class="row teklif-baslik align-items-center">
                    <div class="col-8">
                        <h4 class="fw-bold mb-0 text-uppercase text-primary"><?php echo htmlspecialchars($firmaAdi); ?></h4>
                        <small class="text-muted">Hizmet Fiyat Teklifi</small>
                    </div>
                    <div class="col-4 text-end">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="small text-muted fw-bold">TEKLİF TARİHİ</label>
                                <input type="date" name="tarih" class="form-control form-control-sm text-end" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-12 mt-2">
                                <label class="small text-muted fw-bold">GEÇERLİLİK SÜRESİ</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="gecerlilik" class="form-control text-end" value="15" min="1" required>
                                    <span class="input-group-text">gün</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Müşteri Bilgileri -->
                <div class="mb-5">
                    <div class="row mb-3">
                        <div class="col-md-7 mb-3 mb-md-0">
                            <label class="small text-muted text-uppercase fw-bold">SAYIN</label>
                            <input type="text" name="musteri_adi" class="form-control form-control-lg fw-bold border-bottom" 
                                   placeholder="Müşteri adını veya firma ismini buraya yazın..." required>
                        </div>
                        <div class="col-md-5">
                            <label class="small text-muted text-uppercase fw-bold">TELEFON <span class="text-lowercase fw-normal">(Opsiyonel)</span></label>
                            <input type="text" name="telefon" class="form-control form-control-lg fw-bold border-bottom" 
                                   placeholder="Örn: 0555...">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <label class="small text-muted fw-bold">TEKLİF KONUSU</label>
                            <input type="text" name="konu_baslik" class="form-control form-control-lg border-bottom fst-italic" 
                                   placeholder="Örn: 2025 Düğün Çekim Paketi, Kurumsal Ürün Çekimi vb." required>
                        </div>
                    </div>
                </div>

                <!-- Kalemler Tablosu -->
                <div class="table-responsive mb-4">
                    <table class="table table-hover">
                        <thead class="table-header-bg">
                            <tr>
                                <th class="w-50 ps-3">HİZMET / ÜRÜN AÇIKLAMASI</th>
                                <th class="text-center" width="100">MİKTAR</th>
                                <th class="text-end" width="150">BİRİM FİYAT (₺)</th>
                                <th width="50" class="text-center no-print"><i class="fas fa-cog"></i></th>
                            </tr>
                        </thead>
                        <tbody id="kalemler-container">
                            <tr class="kalem-satir">
                                <td>
                                    <input type="text" name="kalem_adi[]" class="form-control border-0" 
                                           placeholder="Hizmet adı (örn: Dış Mekan Çekimi)..." required>
                                </td>
                                <td>
                                    <input type="number" name="kalem_adet[]" class="form-control border-0 text-center" 
                                           value="1" min="1" required>
                                </td>
                                <td>
                                    <input type="number" step="0.01" name="kalem_fiyat[]" class="form-control border-0 text-end" 
                                           placeholder="0.00" min="0">
                                </td>
                                <td class="text-center align-middle no-print">
                                    <i class="fas fa-times satir-sil-btn" onclick="satirSil(this)" 
                                       title="Satırı Sil" style="font-size: 1.2rem;"></i>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="border-0 pt-3 no-print">
                                    <button type="button" class="btn btn-ekle w-100 py-2" onclick="satirEkle()">
                                        <i class="fas fa-plus-circle me-2"></i>YENİ SATIR EKLE
                                    </button>
                                </td>
                            </tr>
                            <tr class="border-top-3">
                                <td colspan="2" class="text-end fw-bold pt-3 fs-5">GENEL TOPLAM:</td>
                                <td class="text-end fw-bold pt-3 fs-4 text-primary">
                                    <span id="genel-toplam">0.00</span> ₺
                                </td>
                                <td class="no-print"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Teklif Şartları -->
                <div class="mt-5">
                    <label class="small text-muted fw-bold text-uppercase mb-2">TEKLİF ŞARTLARI</label>
                    <textarea name="ozel_sartlar" class="form-control border bg-light p-3" 
                              rows="6" style="font-size: 0.95rem;"><?php echo htmlspecialchars($defaultSartlar); ?></textarea>
                </div>

                <!-- Alt Bilgi -->
                <div class="mt-5 text-center">
                    <label class="small text-muted mb-2">ALT BİLGİ / İLETİŞİM</label>
                    <input type="text" name="ozel_alt_bilgi" class="form-control border-0 text-center text-muted fw-bold" 
                           value="<?php echo htmlspecialchars($defaultFooter); ?>">
                </div>

                <!-- Kullanıcı Notu -->
                <div class="mt-4 p-3 bg-light border rounded small no-print">
                    <i class="fas fa-lightbulb text-warning me-2"></i>
                    <strong>İpucu:</strong> Teklifi kaydettikten sonra sistem size bir bağlantı verecek. Bu bağlantıyı müşterinize atabilir veya teklifi doğrudan PDF olarak çıktısını alabilirsiniz.
                </div>

                <!-- KAYDET BUTONU -->
                <div class="mt-4 pt-4 border-top no-print text-center">
                    <button type="submit" id="kaydet_btn" class="btn btn-kaydet w-100 py-3 fs-5 rounded-pill shadow-sm">
                        <i class="fas fa-check-circle me-2"></i> Teklifi Kaydet ve Oluştur
                    </button>
                </div>
                
            </div>
        </form>
    </div>
</div>

<div id="toast-container-yonetim"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    <?php echo $inline_js; ?>
</script>

<?php require_once 'partials/footer_yonetim.php'; ?>
</body>
</html>