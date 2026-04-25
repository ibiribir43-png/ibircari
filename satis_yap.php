<?php
session_start();
require 'baglanti.php';

if (file_exists('partials/security_check.php')) {
    require_once 'partials/security_check.php';
} else {
    if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
        header("Location: index.php");
        exit;
    }
}

$functions_path = __DIR__ . '/ibir99ibir11/includes/functions.php';
if (file_exists($functions_path)) { require_once $functions_path; }

$firma_id = $_SESSION['firma_id'];
$user_id = $_SESSION['kullanici_id'];
$mesaj = "";
$mesajTuru = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hizli_musteri_ekle'])) {
    $ad_soyad = trim($_POST['m_ad_soyad']);
    $telefon = trim($_POST['m_telefon']);
    $tc_no = trim($_POST['m_tc_no']);
    $musteri_no = "CARI" . time() . rand(10,99);
    $url_token = md5(uniqid(mt_rand(), true));

    $db->prepare("INSERT INTO musteriler (firma_id, musteri_no, ad_soyad, telefon, tc_vergi_no, url_token, durum) VALUES (?, ?, ?, ?, ?, ?, 1)")->execute([$firma_id, $musteri_no, $ad_soyad, $telefon, $tc_no, $url_token]);
    sistemLog($db, 'Müşteri', 'Hızlı Müşteri Eklendi', "$ad_soyad POS ekranından eklendi.");
    header("Location: satis_yap.php?msg=musteri_eklendi");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['satis_tamamla'])) {
    $musteri_id = (int)$_POST['musteri_id'];
    $kasa_id = (int)$_POST['kasa_id'];
    $odenen_tutar = (float)str_replace(',', '.', $_POST['odenen_tutar']);
    $sepet_json = $_POST['sepet_data'];
    $vade_tarihi = !empty($_POST['vade_tarihi']) ? $_POST['vade_tarihi'] : null;
    
    $sepet = json_decode($sepet_json, true);
    
    if($musteri_id > 0 && is_array($sepet) && count($sepet) > 0) {
        try {
            $db->beginTransaction();
            
            $genel_toplam = 0;
            $ozet_isimler = [];
            foreach($sepet as $item) {
                $genel_toplam += ($item['fiyat'] * $item['adet']);
                $ozet_isimler[] = $item['adet'] . "x " . $item['isim'];
            }
            $ozet_metin = implode(", ", $ozet_isimler);

            $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, urun_aciklama, toplam_tutar, islem_tarihi, vade_tarihi, islem_durumu) VALUES (?, ?, 'satis', ?, ?, NOW(), ?, 'tamamlandi')")->execute([$firma_id, $musteri_id, $ozet_metin, $genel_toplam, $vade_tarihi]);
            $hareket_id = $db->lastInsertId();

            $stmt_kalem = $db->prepare("INSERT INTO hareket_kalemleri (firma_id, hareket_id, urun_id, paket_id, kalem_adi, adet, birim_fiyat, satir_toplam) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_stok = $db->prepare("UPDATE urun_hizmetler SET stok_miktari = GREATEST(0, stok_miktari - ?) WHERE id = ? AND tur = 'urun'");
            
            foreach($sepet as $item) {
                $u_id = $item['tur'] == 'urun' || $item['tur'] == 'hizmet' ? $item['id'] : null;
                $p_id = $item['tur'] == 'paket' ? $item['id'] : null;
                $s_toplam = $item['fiyat'] * $item['adet'];
                
                $stmt_kalem->execute([$firma_id, $hareket_id, $u_id, $p_id, $item['isim'], $item['adet'], $item['fiyat'], $s_toplam]);
                
                if($item['tur'] == 'urun') {
                    $stmt_stok->execute([$item['adet'], $u_id]);
                }
            }

            if($odenen_tutar > 0) {
                $db->prepare("INSERT INTO hareketler (firma_id, musteri_id, islem_turu, urun_aciklama, toplam_tutar, islem_tarihi, islem_durumu) VALUES (?, ?, 'tahsilat', ?, ?, NOW(), 'tamamlandi')")->execute([$firma_id, $musteri_id, "Peşinat / Tahsilat: $ozet_metin", $odenen_tutar]);
                
                if($kasa_id > 0) {
                    $kasaBilgi = $db->query("SELECT kasa_tipi FROM finans_kasalar WHERE id = $kasa_id")->fetch(PDO::FETCH_ASSOC);
                    $odeme_turu = 0;
                    if($kasaBilgi) {
                        if($kasaBilgi['kasa_tipi'] == 'banka') $odeme_turu = 2;
                        if($kasaBilgi['kasa_tipi'] == 'pos') $odeme_turu = 1;
                    }
                    $db->prepare("UPDATE hareketler SET odeme_turu = ? WHERE id = ?")->execute([$odeme_turu, $db->lastInsertId()]);
                    $db->prepare("INSERT INTO finans_kasa_hareketleri (firma_id, kasa_id, islem_turu, tutar, aciklama, baglanti_tipi, baglanti_id) VALUES (?, ?, 'giris', ?, ?, 'tahsilat', ?)")->execute([$firma_id, $kasa_id, $odenen_tutar, "Satış Tahsilatı (Satış ID: $hareket_id)", $hareket_id]);
                }
            }

            $db->commit();
            sistemLog($db, 'Finans', 'Yeni Satış (POS)', "Müşteri ID $musteri_id ye $genel_toplam ₺ tutarında satış yapıldı. Alınan: $odenen_tutar ₺");
            header("Location: satis_yap.php?msg=satis_ok");
            exit;
        } catch(Exception $e) {
            $db->rollBack();
            header("Location: satis_yap.php?msg=hata");
            exit;
        }
    } else {
        header("Location: satis_yap.php?msg=bos_sepet");
        exit;
    }
}

if(isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if($m=='satis_ok') { $mesaj = "Satış başarıyla tamamlandı ve stoklar güncellendi."; $mesajTuru = "success"; }
    elseif($m=='musteri_eklendi') { $mesaj = "Müşteri başarıyla eklendi."; $mesajTuru = "success"; }
    elseif($m=='hata') { $mesaj = "Kritik bir veritabanı hatası oluştu!"; $mesajTuru = "danger"; }
    elseif($m=='bos_sepet') { $mesaj = "Müşteri seçilmedi veya sepet boş!"; $mesajTuru = "warning"; }
}

$urunler = $db->query("SELECT id, hizmet_adi, tur, varsayilan_fiyat, stok_miktari, barkod FROM urun_hizmetler WHERE firma_id = '$firma_id' AND durum = 1 ORDER BY hizmet_adi ASC")->fetchAll(PDO::FETCH_ASSOC);
$paketler = $db->query("SELECT id, paket_adi, satis_fiyati FROM firma_paketleri WHERE firma_id = '$firma_id' AND durum = 1 ORDER BY paket_adi ASC")->fetchAll(PDO::FETCH_ASSOC);
$musteriler = $db->query("SELECT id, ad_soyad, telefon FROM musteriler WHERE firma_id = '$firma_id' AND silindi = 0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$kasalar = $db->query("SELECT id, kasa_adi, para_birimi FROM finans_kasalar WHERE firma_id = '$firma_id' AND durum = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$katalogJSON = json_encode(['urunler' => $urunler, 'paketler' => $paketler], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

$page_title = "Hızlı Satış & POS";
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="css/yonetim.css">
    <style>
        body { background-color: #eef2f7; overflow-x: hidden; }
        .pos-container { height: calc(100vh - 100px); display: flex; flex-direction: column; }
        .basket-panel { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; flex-direction: column; height: 100%; border: 1px solid #e0e6ed; }
        .catalog-panel { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; flex-direction: column; height: 100%; border: 1px solid #e0e6ed; }
        .basket-items { flex: 1; overflow-y: auto; padding: 10px; background: #f8f9fa; }
        .basket-totals { background: #1e293b; color: white; padding: 20px; border-radius: 0 0 12px 12px; }
        .catalog-items { flex: 1; overflow-y: auto; padding: 15px; }
        .item-card { border: 1px solid #e0e6ed; border-radius: 8px; padding: 12px; margin-bottom: 10px; cursor: pointer; transition: 0.15s; background: #fff; display: flex; justify-content: space-between; align-items: center; }
        .item-card:active { transform: scale(0.98); }
        .item-card:hover { border-color: #0d6efd; box-shadow: 0 4px 10px rgba(13,110,253,0.1); }
        .barcode-input { font-size: 1.2rem; letter-spacing: 2px; text-align: center; }
        .select2-container--bootstrap-5 .select2-selection { height: 45px; line-height: 45px; font-weight: bold; border-radius: 8px; border: 2px solid #e0e6ed; }
        .qty-btn { width: 30px; height: 30px; padding: 0; display: flex; align-items: center; justify-content: center; border-radius: 5px; font-weight: bold; }
        .cart-row { background: #fff; border-radius: 8px; padding: 10px; margin-bottom: 8px; border: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    </style>
</head>
<body class="yonetim-body">
    <?php include 'partials/navbar.php'; ?>

    <div class="container-fluid px-4 mt-3 pb-4">
        
        <?php if($mesaj): ?>
            <div class="alert alert-<?= $mesajTuru ?> alert-dismissible fade show shadow-sm border-0 rounded-3">
                <?= $mesaj ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 pos-container">
            
            <div class="col-lg-5 col-xl-4 h-100">
                <div class="basket-panel">
                    <div class="p-3 border-bottom bg-light rounded-top-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="fw-bold text-dark mb-0"><i class="fas fa-user-circle text-primary me-1"></i>Müşteri Seçimi</label>
                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#hizliMusteriModal"><i class="fas fa-plus"></i> Ekle</button>
                        </div>
                        <select id="musteriSelect" class="form-select w-100">
                            <option value="">-- Müşteri Arayın veya Seçin --</option>
                            <?php foreach($musteriler as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['ad_soyad']) ?> (<?= htmlspecialchars($m['telefon']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="basket-items" id="basketArea"></div>

                    <div class="basket-totals">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-white-50">Ara Toplam:</span>
                            <span class="fw-bold" id="araToplamText">0,00 ₺</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-end border-top border-secondary pt-3 mb-4">
                            <span class="text-white fw-bold fs-5">GENEL TOPLAM:</span>
                            <span class="fw-bold text-success fs-2" id="genelToplamText">0,00 ₺</span>
                        </div>
                        
                        <button class="btn btn-success w-100 py-3 fw-bold fs-5 rounded-pill shadow" onclick="odemeAl()">
                            <i class="fas fa-cash-register me-2"></i> ÖDEME AL & TAMAMLA
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-7 col-xl-8 h-100">
                <div class="catalog-panel">
                    <div class="p-3 border-bottom bg-light rounded-top-3">
                        <div class="input-group input-group-lg shadow-sm">
                            <span class="input-group-text bg-white border-primary border-end-0 text-primary"><i class="fas fa-barcode"></i></span>
                            <input type="text" id="searchInput" class="form-control border-primary border-start-0 barcode-input" placeholder="Barkod Okutun veya Ürün/Paket Arayın..." autofocus onkeyup="searchKatalog(event)">
                        </div>
                    </div>
                    
                    <ul class="nav nav-tabs px-3 pt-2 bg-light border-bottom-0" id="catTabs">
                        <li class="nav-item"><button class="nav-link active fw-bold text-dark" data-bs-toggle="tab" data-bs-target="#tab-tumu">Tümü</button></li>
                        <li class="nav-item"><button class="nav-link fw-bold text-primary" data-bs-toggle="tab" data-bs-target="#tab-urunler"><i class="fas fa-box"></i> Ürün & Hizmetler</button></li>
                        <li class="nav-item"><button class="nav-link fw-bold text-success" data-bs-toggle="tab" data-bs-target="#tab-paketler"><i class="fas fa-layer-group"></i> Paketler</button></li>
                    </ul>

                    <div class="catalog-items tab-content bg-white" id="catalogArea">
                        <div class="tab-pane fade show active" id="tab-tumu">
                            <div class="row g-2" id="gridTumu"></div>
                        </div>
                        <div class="tab-pane fade" id="tab-urunler">
                            <div class="row g-2" id="gridUrunler"></div>
                        </div>
                        <div class="tab-pane fade" id="tab-paketler">
                            <div class="row g-2" id="gridPaketler"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade" id="odemeModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" id="satisForm">
                    <input type="hidden" name="satis_tamamla" value="1">
                    <input type="hidden" name="musteri_id" id="formMusteriId">
                    <input type="hidden" name="sepet_data" id="formSepetData">
                    
                    <div class="modal-header bg-success text-white border-0 pt-4 px-4">
                        <h4 class="modal-title fw-bold"><i class="fas fa-cash-register me-2"></i>Tahsilat ve Onay</h4>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <div class="text-center mb-4">
                            <div class="small text-muted fw-bold text-uppercase mb-1">Ödenecek Toplam Tutar</div>
                            <div class="display-5 fw-bold text-dark" id="modalToplamTutar">0,00 ₺</div>
                        </div>
                        
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <label class="form-label fw-bold text-dark small mb-2">Müşteriden Alınan Peşinat (₺)</label>
                                <input type="number" step="0.01" name="odenen_tutar" id="odenenTutarInput" class="form-control form-control-lg fw-bold text-success border-success text-center" value="0" onclick="this.select()" onkeyup="kalanHesapla()">
                                <div class="d-flex justify-content-between mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-success rounded-pill fw-bold" onclick="tamaminiOde()">Tamamını Ödedi</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill fw-bold" onclick="hicOdemedi()">Açık Hesap (0 ₺)</button>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm mb-3" id="kalanTutarCard" style="display:none;">
                            <div class="card-body bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded text-center">
                                <div class="small fw-bold text-danger mb-1">Müşterinin Kalan Borcu</div>
                                <h4 class="fw-bold text-danger mb-0" id="kalanTutarText">0,00 ₺</h4>
                            </div>
                            <div class="px-3 pb-3 pt-0">
                                <label class="form-label fw-bold text-muted small">Bu Borcun Vadesi Ne Zaman?</label>
                                <input type="date" name="vade_tarihi" class="form-control border-danger fw-bold">
                            </div>
                        </div>

                        <div id="kasaSecimAlani">
                            <label class="form-label fw-bold text-dark small mt-2">Para Hangi Kasaya Girecek?</label>
                            <select name="kasa_id" id="kasaSelect" class="form-select form-select-lg fw-bold">
                                <?php foreach($kasalar as $k): ?>
                                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['kasa_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 bg-white p-3 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary px-4 fw-bold rounded-pill" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm rounded-pill fs-5"><i class="fas fa-check me-2"></i>Satışı Bitir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="hizliMusteriModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <input type="hidden" name="hizli_musteri_ekle" value="1">
                    <div class="modal-header bg-primary text-white border-0">
                        <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>Yeni Müşteri</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Ad Soyad</label>
                            <input type="text" name="m_ad_soyad" class="form-control fw-bold" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Telefon</label>
                            <input type="text" name="m_telefon" class="form-control">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">TC / Vergi No</label>
                            <input type="text" name="m_tc_no" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light rounded-bottom-4">
                        <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill shadow-sm">Kaydet ve Seç</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include 'partials/footer_yonetim.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        const dbData = <?= $katalogJSON ?>;
        let sepet = [];
        let globalToplam = 0;

        $(document).ready(function() {
            $('#musteriSelect').select2({ theme: 'bootstrap-5', placeholder: "-- Müşteri Arayın veya Seçin --" });
            renderCatalog();
            renderSepet();
            document.getElementById('searchInput').focus();
        });

        function renderCatalog(filterText = '') {
            let htAll = '', htUrun = '', htPaket = '';
            let sText = filterText.toLowerCase();

            dbData.urunler.forEach(u => {
                if(sText && !u.hizmet_adi.toLowerCase().includes(sText) && (!u.barkod || !u.barkod.includes(sText))) return;
                
                let icon = u.tur === 'urun' ? '<i class="fas fa-box text-primary fs-4"></i>' : '<i class="fas fa-handshake text-info fs-4"></i>';
                let stokBilgi = u.tur === 'urun' ? `<small class="text-muted d-block mt-1">Stok: <b>${u.stok_miktari}</b></small>` : '';
                
                let html = `<div class="col-md-6 col-lg-4 col-xl-3"><div class="item-card flex-column align-items-start h-100" onclick="sepeteEkle('urun', ${u.id})">
                    <div class="d-flex justify-content-between w-100 mb-2">${icon}<span class="badge bg-light text-dark border">${u.varsayilan_fiyat} ₺</span></div>
                    <div class="fw-bold text-dark lh-sm" style="font-size:14px;">${u.hizmet_adi}</div>
                    ${stokBilgi}
                </div></div>`;
                htAll += html; htUrun += html;
            });

            dbData.paketler.forEach(p => {
                if(sText && !p.paket_adi.toLowerCase().includes(sText)) return;
                
                let html = `<div class="col-md-6 col-lg-4 col-xl-3"><div class="item-card flex-column align-items-start h-100 bg-success bg-opacity-10 border-success" onclick="sepeteEkle('paket', ${p.id})">
                    <div class="d-flex justify-content-between w-100 mb-2"><i class="fas fa-layer-group text-success fs-4"></i><span class="badge bg-success">${p.satis_fiyati} ₺</span></div>
                    <div class="fw-bold text-dark lh-sm" style="font-size:14px;">${p.paket_adi}</div>
                </div></div>`;
                htAll += html; htPaket += html;
            });

            document.getElementById('gridTumu').innerHTML = htAll;
            document.getElementById('gridUrunler').innerHTML = htUrun;
            document.getElementById('gridPaketler').innerHTML = htPaket;
        }

        function searchKatalog(e) {
            let val = e.target.value.trim();
            if(e.key === 'Enter' && val !== '') {
                let found = dbData.urunler.find(u => u.barkod === val);
                if(found) { sepeteEkle('urun', found.id); e.target.value = ''; renderCatalog(); return; }
            }
            renderCatalog(val);
        }

        function sepeteEkle(tur, id) {
            let item = null;
            if(tur === 'urun') {
                let u = dbData.urunler.find(x => x.id == id);
                if(u.tur === 'urun' && u.stok_miktari <= 0) { Swal.fire('Stok Yok!', `${u.hizmet_adi} stokta tükenmiş.`, 'error'); return; }
                item = { key: 'u_'+id, id: u.id, tur: 'urun', isim: u.hizmet_adi, fiyat: parseFloat(u.varsayilan_fiyat), adet: 1 };
            } else {
                let p = dbData.paketler.find(x => x.id == id);
                item = { key: 'p_'+id, id: p.id, tur: 'paket', isim: p.paket_adi, fiyat: parseFloat(p.satis_fiyati), adet: 1 };
            }

            let exist = sepet.find(x => x.key === item.key);
            if(exist) exist.adet++; else sepet.push(item);
            
            document.getElementById('searchInput').value = '';
            document.getElementById('searchInput').focus();
            renderCatalog();
            renderSepet();
        }

        function miktarDegistir(key, miktar) {
            let index = sepet.findIndex(x => x.key === key);
            if(index > -1) {
                sepet[index].adet += miktar;
                if(sepet[index].adet <= 0) sepet.splice(index, 1);
                renderSepet();
            }
        }

        function sepettenSil(key) {
            let index = sepet.findIndex(x => x.key === key);
            if(index > -1) { sepet.splice(index, 1); renderSepet(); }
        }

        function renderSepet() {
            let area = document.getElementById('basketArea');
            globalToplam = 0;
            area.innerHTML = '';

            if(sepet.length === 0) {
                area.innerHTML = '<div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted opacity-50"><i class="fas fa-shopping-cart fa-4x mb-3"></i><h5>Sepetiniz Boş</h5></div>';
            } else {
                sepet.forEach(i => {
                    let total = i.adet * i.fiyat;
                    globalToplam += total;
                    area.innerHTML += `
                    <div class="cart-row d-flex justify-content-between align-items-center">
                        <div style="flex:1;">
                            <div class="fw-bold text-dark text-truncate" style="font-size:14px; max-width:200px;">${i.isim}</div>
                            <div class="small text-muted">${i.fiyat.toLocaleString('tr-TR')} ₺</div>
                        </div>
                        <div class="d-flex align-items-center mx-2 bg-light border rounded">
                            <button class="btn btn-light btn-sm text-danger border-0 qty-btn" onclick="miktarDegistir('${i.key}', -1)"><i class="fas fa-minus"></i></button>
                            <span class="fw-bold px-2">${i.adet}</span>
                            <button class="btn btn-light btn-sm text-success border-0 qty-btn" onclick="miktarDegistir('${i.key}', 1)"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="text-end" style="width: 80px;">
                            <div class="fw-bold text-dark">${total.toLocaleString('tr-TR')} ₺</div>
                        </div>
                        <button class="btn btn-sm text-danger ms-2" onclick="sepettenSil('${i.key}')"><i class="fas fa-times"></i></button>
                    </div>`;
                });
            }
            
            document.getElementById('araToplamText').innerText = globalToplam.toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺';
            document.getElementById('genelToplamText').innerText = globalToplam.toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺';
        }

        function odemeAl() {
            let mid = document.getElementById('musteriSelect').value;
            if(!mid) { Swal.fire('Hata!', 'Lütfen önce müşteri seçiniz.', 'warning'); return; }
            if(sepet.length === 0) { Swal.fire('Hata!', 'Sepetiniz boş.', 'warning'); return; }

            document.getElementById('formMusteriId').value = mid;
            document.getElementById('formSepetData').value = JSON.stringify(sepet);
            document.getElementById('modalToplamTutar').innerText = globalToplam.toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺';
            document.getElementById('odenenTutarInput').value = globalToplam;
            
            kalanHesapla();
            new bootstrap.Modal(document.getElementById('odemeModal')).show();
        }

        function tamaminiOde() {
            document.getElementById('odenenTutarInput').value = globalToplam;
            kalanHesapla();
        }

        function hicOdemedi() {
            document.getElementById('odenenTutarInput').value = 0;
            kalanHesapla();
        }

        function kalanHesapla() {
            let odenen = parseFloat(document.getElementById('odenenTutarInput').value) || 0;
            let kalan = globalToplam - odenen;
            let kCard = document.getElementById('kalanTutarCard');
            let kText = document.getElementById('kalanTutarText');
            let kasaAlan = document.getElementById('kasaSecimAlani');

            if(kalan > 0) {
                kCard.style.display = 'block';
                kText.innerText = kalan.toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺';
            } else {
                kCard.style.display = 'none';
            }

            if(odenen <= 0) {
                kasaAlan.style.display = 'none';
            } else {
                kasaAlan.style.display = 'block';
            }
        }
    </script>
</body>
</html>