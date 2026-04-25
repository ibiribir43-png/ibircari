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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['paket_kaydet'])) {
    $paket_adi = trim($_POST['paket_adi']);
    $aciklama = trim($_POST['aciklama']);
    $satis_fiyati = (float)str_replace(',', '.', $_POST['satis_fiyati']);
    
    $urunler = isset($_POST['urun_id']) ? $_POST['urun_id'] : [];
    $adetler = isset($_POST['adet']) ? $_POST['adet'] : [];
    
    if (empty($paket_adi) || empty($urunler)) {
        header("Location: dinamik_paketler.php?msg=bos");
        exit;
    }

    try {
        $db->beginTransaction();

        $toplam_maliyet = 0;
        foreach($urunler as $key => $u_id) {
            $adet = (int)$adetler[$key];
            $u_sorgu = $db->prepare("SELECT maliyet_fiyati FROM urun_hizmetler WHERE id = ? AND firma_id = ?");
            $u_sorgu->execute([$u_id, $firma_id]);
            $u_veri = $u_sorgu->fetch(PDO::FETCH_ASSOC);
            if($u_veri) {
                $toplam_maliyet += ($u_veri['maliyet_fiyati'] * $adet);
            }
        }

        $stmt = $db->prepare("INSERT INTO firma_paketleri (firma_id, paket_adi, aciklama, toplam_maliyet, satis_fiyati, durum) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$firma_id, $paket_adi, $aciklama, $toplam_maliyet, $satis_fiyati]);
        $yeni_paket_id = $db->lastInsertId();

        $stmt_icerik = $db->prepare("INSERT INTO firma_paket_icerikleri (paket_id, urun_id, adet) VALUES (?, ?, ?)");
        foreach($urunler as $key => $u_id) {
            $adet = (int)$adetler[$key];
            if($adet > 0) {
                $stmt_icerik->execute([$yeni_paket_id, $u_id, $adet]);
            }
        }

        $db->commit();
        sistemLog($db, 'Katalog', 'Paket Oluşturuldu', "$paket_adi isimli dinamik paket oluşturuldu.");
        header("Location: dinamik_paketler.php?msg=success");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: dinamik_paketler.php?msg=error");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['paket_sil'])) {
    $p_id = (int)$_POST['silinecek_paket_id'];
    $db->prepare("DELETE FROM firma_paketleri WHERE id = ? AND firma_id = ?")->execute([$p_id, $firma_id]);
    sistemLog($db, 'Katalog', 'Paket Silindi', "Bir paket sistemden silindi.");
    header("Location: dinamik_paketler.php?msg=deleted");
    exit;
}

$mevcut_urunler = $db->query("SELECT * FROM urun_hizmetler WHERE firma_id = '$firma_id' ORDER BY hizmet_adi ASC")->fetchAll(PDO::FETCH_ASSOC);

$paketler = $db->query("SELECT * FROM firma_paketleri WHERE firma_id = '$firma_id' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$paket_icerikleri = $db->query("SELECT i.*, u.hizmet_adi, u.tur, u.varsayilan_fiyat FROM firma_paket_icerikleri i JOIN firma_paketleri p ON i.paket_id = p.id JOIN urun_hizmetler u ON i.urun_id = u.id WHERE p.firma_id = '$firma_id'")->fetchAll(PDO::FETCH_ASSOC);

$icerikler = [];
foreach($paket_icerikleri as $ic) {
    $icerikler[$ic['paket_id']][] = $ic;
}

$mesaj = "";
$mesajTuru = "";
if(isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if($m=='success') { $mesaj = "Paket başarıyla oluşturuldu."; $mesajTuru = "success"; }
    elseif($m=='deleted') { $mesaj = "Paket başarıyla silindi."; $mesajTuru = "warning"; }
    elseif($m=='bos') { $mesaj = "Paket adı ve içerik boş olamaz!"; $mesajTuru = "danger"; }
    elseif($m=='error') { $mesaj = "Bir hata oluştu."; $mesajTuru = "danger"; }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dinamik Paket Oluşturucu | ibiR Cari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/yonetim.css">
    <style>
        body { background-color: #f4f6f9; }
        .paket-card { border: none; border-radius: 15px; transition: transform 0.3s, box-shadow 0.3s; background: #fff; }
        .paket-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .paket-header { background: linear-gradient(45deg, #1e3c72, #2a5298); color: #fff; border-radius: 15px 15px 0 0; padding: 20px; }
        .urun-list-box { max-height: 400px; overflow-y: auto; }
        .urun-item { border: 1px solid #eee; border-radius: 8px; padding: 10px; margin-bottom: 10px; transition: 0.2s; cursor: pointer; }
        .urun-item:hover { border-color: #0d6efd; background: #f8f9fa; }
        .sepet-table th { font-size: 12px; text-transform: uppercase; color: #6c757d; }
        .profit-badge { font-size: 14px; padding: 8px 12px; border-radius: 8px; }
        .avantaj-badge { background-color: #ffefc2; color: #b47a00; border: 1px solid #ffe399; }
    </style>
</head>
<body class="yonetim-body">

    <?php include 'partials/navbar.php'; ?>

    <div class="container-fluid pb-5 px-4 mt-4">
        
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h3 class="fw-bold text-dark mb-1"><i class="fas fa-layer-group text-primary me-2"></i> Dinamik Paket Yönetimi</h3>
                <p class="text-muted small mb-0">Ürün ve hizmetlerinizi birleştirerek müşterilerinize avantajlı setler sunun.</p>
            </div>
            <div class="col-md-6 text-end">
                <button class="btn btn-primary fw-bold shadow-sm px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#paketOlusturModal" onclick="resetBuilder()"><i class="fas fa-plus me-2"></i>Yeni Paket Oluştur</button>
            </div>
        </div>

        <?php if($mesaj): ?>
            <div class="alert alert-<?= $mesajTuru ?> alert-dismissible fade show shadow-sm border-0 rounded-4">
                <?= $mesaj ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <?php if(empty($paketler)): ?>
                <div class="col-12 text-center py-5">
                    <i class="fas fa-box-open fa-4x text-muted opacity-25 mb-3"></i>
                    <h5 class="text-muted fw-bold">Henüz hiç paket oluşturmadınız.</h5>
                    <p class="text-muted small">Faturalandırmayı hızlandırmak için hemen bir paket oluşturun.</p>
                </div>
            <?php endif; ?>

            <?php foreach($paketler as $p): 
                $p_id = $p['id'];
                $icerik = $icerikler[$p_id] ?? [];
                
                $tek_tek_toplam = 0;
                foreach($icerik as $ic) {
                    $tek_tek_toplam += ($ic['varsayilan_fiyat'] * $ic['adet']);
                }
                
                $musteri_kazanci = $tek_tek_toplam - $p['satis_fiyati'];
                $kar_tl = $p['satis_fiyati'] - $p['toplam_maliyet'];
                $kar_yuzde = $p['toplam_maliyet'] > 0 ? ($kar_tl / $p['toplam_maliyet']) * 100 : 100;
            ?>
            <div class="col-xl-4 col-lg-6">
                <div class="paket-card shadow-sm h-100 d-flex flex-column">
                    <div class="paket-header d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="fw-bold mb-1 text-truncate" style="max-width: 250px;"><?= htmlspecialchars($p['paket_adi']) ?></h5>
                            <span class="badge bg-white text-primary bg-opacity-25 border border-white border-opacity-50"><i class="fas fa-boxes me-1"></i><?= count($icerik) ?> Kalem İçerik</span>
                        </div>
                        <h4 class="fw-bold mb-0 text-white"><?= number_format($p['satis_fiyati'], 2, ',', '.') ?> ₺</h4>
                    </div>
                    
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="mb-3 text-muted small" style="min-height: 40px;">
                            <?= htmlspecialchars($p['aciklama'] ?: 'Açıklama girilmemiş.') ?>
                        </div>

                        <ul class="list-group list-group-flush mb-4 small">
                            <?php foreach(array_slice($icerik, 0, 4) as $ic): ?>
                                <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center bg-transparent border-light">
                                    <span><i class="fas fa-check text-success me-2"></i><?= htmlspecialchars($ic['hizmet_adi']) ?></span>
                                    <span class="badge bg-light text-dark border"><?= $ic['adet'] ?> Adet</span>
                                </li>
                            <?php endforeach; ?>
                            <?php if(count($icerik) > 4): ?>
                                <li class="list-group-item px-0 py-2 text-center text-primary bg-transparent border-light fw-bold small">
                                    + <?= count($icerik) - 4 ?> kalem daha...
                                </li>
                            <?php endif; ?>
                        </ul>
                        
                        <div class="mt-auto">
                            <?php if($musteri_kazanci > 0): ?>
                                <div class="avantaj-badge fw-bold text-center py-2 mb-3 rounded-3 shadow-sm">
                                    <i class="fas fa-gift me-1"></i> Paket Avantajı: Müşteri <?= number_format($musteri_kazanci, 0, ',', '.') ?> ₺ Kârda!
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center border-top pt-3">
                                <div class="text-start">
                                    <div class="small text-muted fw-bold mb-1">Maliyet: <?= number_format($p['toplam_maliyet'], 2, ',', '.') ?> ₺</div>
                                    <div class="small fw-bold <?= $kar_tl > 0 ? 'text-success' : 'text-danger' ?>">
                                        Kâr: <?= $kar_tl > 0 ? '+' : '' ?><?= number_format($kar_tl, 2, ',', '.') ?> ₺ (%<?= round($kar_yuzde) ?>)
                                    </div>
                                </div>
                                <div>
                                    <form method="POST" onsubmit="return confirm('Bu paketi silmek istediğinize emin misiniz? Fatura kesilmiş geçmiş işlemleri etkilemez.');">
                                        <input type="hidden" name="paket_sil" value="1">
                                        <input type="hidden" name="silinecek_paket_id" value="<?= $p['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm fw-bold rounded-pill px-3"><i class="fas fa-trash me-1"></i>Sil</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- YENİ PAKET OLUŞTURMA MODALI (DEVASA EKRAN) -->
    <div class="modal fade" id="paketOlusturModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <form method="POST" id="paketForm">
                    <input type="hidden" name="paket_kaydet" value="1">
                    
                    <div class="modal-header bg-primary text-white border-0 py-3 px-4">
                        <h4 class="modal-title fw-bold"><i class="fas fa-magic me-2"></i>Dinamik Paket Oluşturucu</h4>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body p-0 bg-light">
                        <div class="row g-0 h-100">
                            
                            <!-- SOL: PAKET DETAYLARI VE SEPET -->
                            <div class="col-lg-7 p-4 bg-white border-end">
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">1. Paket Bilgileri</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-8">
                                        <label class="form-label fw-bold small text-muted">Paket Adı <span class="text-danger">*</span></label>
                                        <input type="text" name="paket_adi" class="form-control fw-bold text-dark" required placeholder="Örn: VIP Düğün Paketi">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-muted">Satış Fiyatı (₺) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="satis_fiyati" id="paketSatisFiyati" class="form-control fw-bold text-primary border-primary" required value="0" onkeyup="hesapla()">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold small text-muted">Açıklama / İçerik Özeti</label>
                                        <textarea name="aciklama" class="form-control" rows="2" placeholder="Paket detaylarını buraya yazabilirsiniz..."></textarea>
                                    </div>
                                </div>

                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">2. Paket İçeriği (Sepet)</h6>
                                <div class="table-responsive" style="min-height: 200px;">
                                    <table class="table table-hover align-middle sepet-table" id="sepetTable">
                                        <thead>
                                            <tr>
                                                <th width="50%">Ürün/Hizmet</th>
                                                <th width="15%" class="text-center">Adet</th>
                                                <th width="25%" class="text-end">Birim B.Fiyatı</th>
                                                <th width="10%"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="sepetGove">
                                            <tr id="bosSepetRow"><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-shopping-basket fa-2x mb-2 opacity-25"></i><br>Sağ panelden pakete ürün ekleyin.</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="bg-light p-3 rounded-3 border mt-3">
                                    <div class="row text-center">
                                        <div class="col-4 border-end">
                                            <div class="small fw-bold text-muted mb-1">Toplam Maliyet</div>
                                            <h5 class="fw-bold text-danger mb-0" id="hesapMaliyet">0.00 ₺</h5>
                                        </div>
                                        <div class="col-4 border-end">
                                            <div class="small fw-bold text-muted mb-1">Tek Tek Liste Fiyatı</div>
                                            <h5 class="fw-bold text-secondary mb-0" id="hesapListeFiyati"><del>0.00 ₺</del></h5>
                                        </div>
                                        <div class="col-4">
                                            <div class="small fw-bold text-muted mb-1">Tahmini Net Kâr</div>
                                            <h5 class="fw-bold text-success mb-0" id="hesapKar">0.00 ₺</h5>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SAĞ: KATALOGDAN ÜRÜN SEÇME -->
                            <div class="col-lg-5 p-4">
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Kataloğunuzdan Seçin</h6>
                                <div class="input-group mb-3 shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" class="form-control border-start-0" id="urunAra" placeholder="Ürün veya hizmet ara..." onkeyup="filtreleUrun()">
                                </div>

                                <div class="urun-list-box pe-2" id="urunListesi">
                                    <?php foreach($mevcut_urunler as $u): ?>
                                    <div class="urun-item bg-white shadow-sm" data-isim="<?= mb_strtolower($u['hizmet_adi']) ?>" onclick="sepeteEkle(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['hizmet_adi'])) ?>', <?= $u['varsayilan_fiyat'] ?>, <?= $u['maliyet_fiyati'] ?>)">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold text-dark" style="font-size: 14px;"><?= htmlspecialchars($u['hizmet_adi']) ?></div>
                                                <small class="text-muted"><i class="fas <?= $u['tur']=='urun'?'fa-box':'fa-handshake' ?> me-1"></i><?= $u['tur']=='urun'?'Ürün':'Hizmet' ?></small>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold text-primary"><?= number_format($u['varsayilan_fiyat'], 2, ',', '.') ?> ₺</div>
                                                <button type="button" class="btn btn-sm btn-light border text-success mt-1 rounded-pill" style="font-size:10px;"><i class="fas fa-plus me-1"></i>Ekle</button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        </div>
                    </div>
                    
                    <div class="modal-footer bg-white border-top py-3 px-4 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary px-4 fw-bold rounded-pill" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm rounded-pill"><i class="fas fa-save me-2"></i>Paketi Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include 'partials/footer_yonetim.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let sepet = {}; // { id: {isim, fiyat, maliyet, adet} }

        function filtreleUrun() {
            let val = document.getElementById('urunAra').value.toLowerCase();
            let items = document.querySelectorAll('.urun-item');
            items.forEach(item => {
                item.style.display = item.getAttribute('data-isim').includes(val) ? '' : 'none';
            });
        }

        function sepeteEkle(id, isim, fiyat, maliyet) {
            if(sepet[id]) {
                sepet[id].adet += 1;
            } else {
                sepet[id] = { id: id, isim: isim, fiyat: parseFloat(fiyat), maliyet: parseFloat(maliyet), adet: 1 };
            }
            sepetiCiz();
        }

        function sepetAdetDegistir(id, miktar) {
            if(sepet[id]) {
                sepet[id].adet += miktar;
                if(sepet[id].adet <= 0) delete sepet[id];
                sepetiCiz();
            }
        }

        function sepettenSil(id) {
            delete sepet[id];
            sepetiCiz();
        }

        function sepetiCiz() {
            let tbody = document.getElementById('sepetGove');
            tbody.innerHTML = '';

            let urunSayisi = Object.keys(sepet).length;
            if(urunSayisi === 0) {
                tbody.innerHTML = '<tr id="bosSepetRow"><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-shopping-basket fa-2x mb-2 opacity-25"></i><br>Sağ panelden pakete ürün ekleyin.</td></tr>';
            } else {
                for (let id in sepet) {
                    let u = sepet[id];
                    let tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="fw-bold text-dark small">
                            ${u.isim}
                            <input type="hidden" name="urun_id[]" value="${u.id}">
                            <input type="hidden" name="adet[]" value="${u.adet}">
                        </td>
                        <td class="text-center">
                            <div class="input-group input-group-sm">
                                <button type="button" class="btn btn-outline-secondary" onclick="sepetAdetDegistir(${u.id}, -1)">-</button>
                                <input type="text" class="form-control text-center fw-bold px-0" value="${u.adet}" readonly>
                                <button type="button" class="btn btn-outline-secondary" onclick="sepetAdetDegistir(${u.id}, 1)">+</button>
                            </div>
                        </td>
                        <td class="text-end fw-bold text-muted small">${u.fiyat.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2})} ₺</td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="sepettenSil(${u.id})"><i class="fas fa-times"></i></button></td>
                    `;
                    tbody.appendChild(tr);
                }
            }
            hesapla();
        }

        function hesapla() {
            let toplamMaliyet = 0;
            let listeFiyati = 0;

            for (let id in sepet) {
                let u = sepet[id];
                toplamMaliyet += (u.maliyet * u.adet);
                listeFiyati += (u.fiyat * u.adet);
            }

            let girilenSatisFiyati = parseFloat(document.getElementById('paketSatisFiyati').value) || 0;
            
            // Eğer satış fiyatı 0 ise otomatik liste fiyatını öner ve yaz
            if(girilenSatisFiyati === 0 && listeFiyati > 0) {
                girilenSatisFiyati = listeFiyati;
                document.getElementById('paketSatisFiyati').value = listeFiyati;
            }

            let netKar = girilenSatisFiyati - toplamMaliyet;

            document.getElementById('hesapMaliyet').innerText = toplamMaliyet.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ₺';
            document.getElementById('hesapListeFiyati').innerHTML = '<del>' + listeFiyati.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ₺</del>';
            
            let karEl = document.getElementById('hesapKar');
            karEl.innerText = (netKar > 0 ? '+' : '') + netKar.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ₺';
            karEl.className = `fw-bold mb-0 ${netKar > 0 ? 'text-success' : (netKar < 0 ? 'text-danger' : 'text-muted')}`;
        }

        function resetBuilder() {
            sepet = {};
            document.getElementById('paketForm').reset();
            sepetiCiz();
        }
    </script>
</body>
</html>