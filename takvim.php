<?php
session_start();
require 'baglanti.php';

// Hata gösterimini aç (Geliştirme aşamasında)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. GÜVENLİK KONTROLÜ
require_once 'partials/security_check.php';

// Sayfa başlığı
$page_title = "İş Takvimi ve Ajanda";

// --- SENKRONİZASYON LİNKLERİNİ OLUŞTUR ---
// Protokol (http/https) ve Domain algılama
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
$path = dirname($_SERVER['PHP_SELF']);
// Firma Token (ical.php ile aynı mantık)
$calToken = md5($firma_id . ($firma_adi ?? ''));

// Linkler
$baseIcalUrl = $protocol . "://" . $domain . $path . "/ical.php?firma_token=" . $calToken;
$googleSyncUrl = "https://calendar.google.com/calendar/render?cid=" . urlencode(str_replace('https://', 'http://', $baseIcalUrl)); 
$webcalUrl = str_replace(['http://', 'https://'], 'webcal://', $baseIcalUrl);

// --- 1. YENİ ETKİNLİK KAYDETME ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['etkinlik_ekle'])) {
    $baslik = $_POST['baslik'];
    $baslangic = $_POST['baslangic'];
    $bitis = !empty($_POST['bitis']) ? $_POST['bitis'] : date('Y-m-d H:i:s', strtotime($baslangic . ' +1 hour'));
    $aciklama = $_POST['aciklama'];
    $musteri_id = ($rol == 'admin' && !empty($_POST['musteri_id'])) ? $_POST['musteri_id'] : null;

    $ekle = $db->prepare("INSERT INTO takvim_etkinlikleri (firma_id, baslik, baslangic_tarihi, bitis_tarihi, aciklama, musteri_id) VALUES (?, ?, ?, ?, ?, ?)");
    $ekle->execute([$firma_id, $baslik, $baslangic, $bitis, $aciklama, $musteri_id]);
    
    $_SESSION['success_message'] = "Etkinlik takvime işlendi.";
    header("Location: takvim.php");
    exit;
}

// --- 2. ETKİNLİK GÜNCELLEME ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['etkinlik_guncelle'])) {
    $id = $_POST['etkinlik_id'];
    $baslik = $_POST['baslik'];
    $baslangic = $_POST['baslangic'];
    $bitis = !empty($_POST['bitis']) ? $_POST['bitis'] : date('Y-m-d H:i:s', strtotime($baslangic . ' +1 hour'));
    $aciklama = $_POST['aciklama'];
    
    if ($rol == 'admin') {
        $musteri_id = !empty($_POST['musteri_id']) ? $_POST['musteri_id'] : null;
        $guncelle = $db->prepare("UPDATE takvim_etkinlikleri SET baslik=?, baslangic_tarihi=?, bitis_tarihi=?, aciklama=?, musteri_id=? WHERE id=? AND firma_id=?");
        $guncelle->execute([$baslik, $baslangic, $bitis, $aciklama, $musteri_id, $id, $firma_id]);
    } else {
        $guncelle = $db->prepare("UPDATE takvim_etkinlikleri SET baslik=?, baslangic_tarihi=?, bitis_tarihi=?, aciklama=? WHERE id=? AND firma_id=?");
        $guncelle->execute([$baslik, $baslangic, $bitis, $aciklama, $id, $firma_id]);
    }
    
    $_SESSION['success_message'] = "Etkinlik güncellendi.";
    header("Location: takvim.php");
    exit;
}

// --- 3. ETKİNLİK SİLME ---
if (isset($_POST['etkinlik_sil_id'])) {
    $sil = $db->prepare("DELETE FROM takvim_etkinlikleri WHERE id = ? AND firma_id = ?");
    $sil->execute([$_POST['etkinlik_sil_id'], $firma_id]);
    
    $_SESSION['success_message'] = "Etkinlik silindi.";
    header("Location: takvim.php");
    exit;
}

// --- MÜŞTERİ LİSTESİ ---
$musteriler = [];
if ($rol == 'admin') {
    $mSorgu = $db->prepare("SELECT id, ad_soyad FROM musteriler WHERE durum = 1 AND silindi = 0 AND firma_id = ? ORDER BY ad_soyad ASC");
    $mSorgu->execute([$firma_id]);
    $musteriler = $mSorgu->fetchAll(PDO::FETCH_ASSOC);
}

// --- TÜM VERİLERİ ÇEK ---
$etkinlikler = [];
$toplam_etkinlik = 0;

// B. Satış İşlemleri (Google Blue - Blueberry) - SİLİNMEMİŞ MÜŞTERİLER İÇİN
$s2 = $db->prepare("SELECT h.id, h.urun_aciklama, h.vade_tarihi, m.ad_soyad, m.telefon, m.id as m_id FROM hareketler h JOIN musteriler m ON h.musteri_id = m.id WHERE h.islem_turu = 'satis' AND h.vade_tarihi IS NOT NULL AND h.firma_id = ? AND m.silindi = 0");
$s2->execute([$firma_id]);
while($row = $s2->fetch(PDO::FETCH_ASSOC)) {
    $etkinlikler[] = [
        'id' => 'islem_' . $row['id'],
        'title' => '📸 ' . $row['ad_soyad'] . ' - ' . $row['urun_aciklama'],
        'start' => $row['vade_tarihi'],
        'color' => '#3f51b5', // Google Calendar Blueberry
        'className' => 'fc-event-photo',
        'url' => ($rol == 'admin' || $rol == 'personel') ? 'musteri_detay.php?id=' . $row['m_id'] : '#',
        'extendedProps' => ['tur' => 'islem', 'detay' => $row['urun_aciklama'], 'tel' => $row['telefon'], 'editable' => false]
    ];
}

// C. Serbest Etkinlikler (Google Green - Sage) - SİLİNMEMİŞ MÜŞTERİLER İÇİN
$s3 = $db->prepare("SELECT t.*, m.ad_soyad, m.telefon FROM takvim_etkinlikleri t LEFT JOIN musteriler m ON t.musteri_id = m.id WHERE t.firma_id = ? AND (t.musteri_id IS NULL OR t.musteri_id = 0 OR m.silindi = 0)");
$s3->execute([$firma_id]);
while($row = $s3->fetch(PDO::FETCH_ASSOC)) {
    $baslik = $row['musteri_id'] ? ($row['baslik'] . ' (' . $row['ad_soyad'] . ')') : $row['baslik'];
    $etkinlikler[] = [
        'id' => 'serbest_' . $row['id'],
        'title' => $baslik,
        'start' => $row['baslangic_tarihi'],
        'end' => $row['bitis_tarihi'],
        'color' => '#0b8043', // Google Calendar Basil Green
        'className' => 'fc-event-custom',
        'extendedProps' => [
            'tur' => 'serbest',
            'db_id' => $row['id'],
            'baslik_raw' => $row['baslik'],
            'aciklama' => $row['aciklama'],
            'musteri_id' => $row['musteri_id'],
            'tel' => $row['telefon'] ?? '-',
            'editable' => true
        ]
    ];
}
$toplam_etkinlik = count($etkinlikler);
$jsonEtkinlikler = json_encode($etkinlikler, JSON_UNESCAPED_UNICODE);

// TOAST JS MESAJINI HAZIRLA (HATASIZ KISIM)
$toast_js = "";
if (isset($_SESSION['success_message'])) {
    $msj = addslashes($_SESSION['success_message']);
    $toast_js = "showToast('{$msj}', 'success');";
    unset($_SESSION['success_message']); // Hata verdiren HTML etiketleri yerine PHP içinde güvenle temizledik.
}

// --- CSS (GOOGLE CALENDAR STYLE) ---
$inline_css = "
    :root {
        --fc-border-color: #e0e0e0;
        --fc-button-bg-color: #fff;
        --fc-button-border-color: #dadce0;
        --fc-button-text-color: #3c4043;
        --fc-button-hover-bg-color: #f1f3f4;
        --fc-button-hover-border-color: #dadce0;
        --fc-button-active-bg-color: #e8f0fe;
        --fc-button-active-border-color: #dadce0;
        --fc-button-active-text-color: #1967d2;
        --fc-event-bg-color: #3788d8;
        --fc-event-border-color: #3788d8;
        --fc-today-bg-color: transparent;
    }
    
    /* Genel Düzen */
    .takvim-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(60,64,67,0.3);
        padding: 20px;
        min-height: 800px;
    }

    /* FullCalendar Özelleştirme - Google Style */
    .fc {
        font-family: 'Google Sans', Roboto, Arial, sans-serif;
    }
    
    .fc .fc-toolbar-title {
        font-size: 1.375rem;
        font-weight: 400;
        color: #3c4043;
    }
    
    .fc .fc-button {
        border-radius: 4px;
        font-weight: 500;
        text-transform: none;
        padding: 0.4rem 0.8rem;
        box-shadow: none !important;
    }
    
    .fc .fc-button-primary:not(:disabled).fc-button-active, 
    .fc .fc-button-primary:not(:disabled):active {
        background-color: var(--fc-button-active-bg-color);
        border-color: var(--fc-button-active-border-color);
        color: var(--fc-button-active-text-color);
    }
    
    /* Gün Başlıkları */
    .fc-col-header-cell-cushion {
        color: #70757a;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        padding-top: 8px;
        padding-bottom: 8px;
    }
    
    /* Gün Hücreleri */
    .fc-daygrid-day-number {
        color: #3c4043;
        font-size: 12px;
        font-weight: 500;
        padding: 8px;
        text-decoration: none !important;
    }
    
    .fc-day-today .fc-daygrid-day-number {
        background-color: #1a73e8;
        color: white;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: 4px;
        margin-left: 4px;
    }
    
    /* Etkinlikler */
    .fc-event {
        border-radius: 4px;
        border: none;
        padding: 2px 4px;
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 2px;
        cursor: pointer;
    }
    
    /* Sync Dropdown */
    .sync-btn {
        background-color: #fff;
        border: 1px solid #dadce0;
        color: #3c4043;
        border-radius: 24px;
        padding: 8px 24px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: background 0.2s;
    }
    
    .sync-btn:hover {
        background-color: #f1f3f4;
        color: #3c4043;
    }
    
    .sync-dropdown-menu {
        border: none;
        box-shadow: 0 2px 6px rgba(60,64,67,0.15);
        border-radius: 8px;
        padding: 8px 0;
        min-width: 280px;
    }
    
    .sync-item {
        padding: 10px 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: #3c4043;
        font-size: 14px;
        cursor: pointer;
    }
    
    .sync-item:hover {
        background-color: #f1f3f4;
    }
    
    .sync-icon-box {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8f9fa;
    }

    /* FAB Button (Mobil Ekleme) */
    .fab-btn {
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background-color: #fff;
        box-shadow: 0 4px 5px 0 rgba(0,0,0,0.14), 0 1px 10px 0 rgba(0,0,0,0.12), 0 2px 4px -1px rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 999;
        transition: transform 0.2s;
    }
    
    .fab-btn:hover {
        transform: scale(1.05);
    }
    
    .fab-icon {
        width: 30px;
        height: 30px;
    }

    /* Modal Google Style */
    .modal-content {
        border-radius: 8px;
        border: none;
        box-shadow: 0 24px 38px 3px rgba(0,0,0,0.14), 0 9px 46px 8px rgba(0,0,0,0.12), 0 11px 15px -7px rgba(0,0,0,0.2);
    }
    .modal-header {
        border-bottom: none;
        padding: 8px 24px;
        background: #f1f3f4;
        border-radius: 8px 8px 0 0;
    }
    .modal-footer {
        border-top: none;
        padding: 16px 24px;
    }
";

// JS CDN
$extra_js = [
    'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.15/index.global.min.js',
];

$inline_js = '
    // Senkronizasyon Fonksiyonları
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            showToast("Bağlantı kopyalandı!", "success");
        }, function(err) {
            showToast("Kopyalama başarısız", "error");
        });
    }

    // Google Takvim Popup Açma
    function openGoogleCalendar() {
        const url = "' . $googleSyncUrl . '";
        window.open(url, "_blank", "width=800,height=600");
    }

    // Takvim Başlatma
    document.addEventListener("DOMContentLoaded", function() {
        var calendarEl = document.getElementById("calendar");
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: "dayGridMonth",
            locale: "tr",
            firstDay: 1, // Pazartesi
            headerToolbar: {
                left: "prev,next today",
                center: "title",
                right: "dayGridMonth,timeGridWeek,timeGridDay,listMonth"
            },
            buttonText: {
                today: "Bugün",
                month: "Ay",
                week: "Hafta",
                day: "Gün",
                list: "Ajanda"
            },
            events: ' . $jsonEtkinlikler . ',
            navLinks: true, // güne tıklayıp o güne gitme
            editable: false,
            dayMaxEvents: true, // çok fazla etkinlik varsa "+more" göster
            
            eventClick: function(info) {
                // Etkinlik detayları
                var props = info.event.extendedProps;
                
                // URL varsa git (Müşteri Detay vs)
                if (info.event.url && info.event.url !== "#") {
                    // Tarayıcı varsayılanını engelleme, direkt git
                    return; 
                }
                
                info.jsEvent.preventDefault();
                
                // Eğer serbest etkinlik ve düzenlenebilir ise
                if (props.tur === "serbest" && props.editable) {
                    document.getElementById("edit_id").value = props.db_id;
                    document.getElementById("edit_baslik").value = props.baslik_raw;
                    document.getElementById("edit_aciklama").value = props.aciklama;
                    
                    // Tarih formatı (ISO string kesme)
                    var start = info.event.start ? new Date(info.event.start.getTime() - (info.event.start.getTimezoneOffset() * 60000)).toISOString().slice(0,16) : "";
                    var end = info.event.end ? new Date(info.event.end.getTime() - (info.event.end.getTimezoneOffset() * 60000)).toISOString().slice(0,16) : start;
                    
                    document.getElementById("edit_baslangic").value = start;
                    document.getElementById("edit_bitis").value = end;
                    
                    if(document.getElementById("edit_musteri_id")) {
                        document.getElementById("edit_musteri_id").value = props.musteri_id || "";
                    }
                    
                    var myModal = new bootstrap.Modal(document.getElementById("modalDetay"));
                    myModal.show();
                } else {
                    // Sadece görüntüle (Alert veya Modal)
                    showToast(info.event.title + (props.tel ? " Tel: " + props.tel : ""), "info");
                }
            },
            
            dateClick: function(info) {
                // Yeni Etkinlik
                var tarih = info.dateStr + "T09:00";
                document.getElementById("inpBaslangic").value = tarih;
                var myModal = new bootstrap.Modal(document.getElementById("modalEkle"));
                myModal.show();
            }
        });
        
        calendar.render();
    });
    
    // Silme Onayı
    function etkinlikSil() {
        if(confirm("Bu etkinliği silmek istediğinize emin misiniz?")) {
            var id = document.getElementById("edit_id").value;
            var form = document.createElement("form");
            form.method = "POST";
            var input = document.createElement("input");
            input.type = "hidden";
            input.name = "etkinlik_sil_id";
            input.value = id;
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    ' . $toast_js . '
';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title; ?> | <?php echo htmlspecialchars($firma_adi); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/yonetim.css">
    
    <style>
        <?php echo $inline_css; ?>
    </style>
</head>
<body class="yonetim-body">

    <?php include 'partials/navbar.php'; ?>

    <div class="container-yonetim">
        
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1 class="h4 mb-0 text-dark"><i class="fas fa-calendar-check me-2 text-primary"></i>İş Takvimi</h1>
            
            <div class="dropdown">
                <button class="btn sync-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/a/a5/Google_Calendar_icon_%282020%29.svg" width="20" alt="G">
                    <span>Takvimi Senkronize Et</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end sync-dropdown-menu">
                    <li><h6 class="dropdown-header">Otomatik Senkronizasyon</h6></li>
                    <li>
                        <a class="dropdown-item sync-item" href="#" onclick="openGoogleCalendar()">
                            <div class="sync-icon-box text-danger"><i class="fab fa-google"></i></div>
                            <div>
                                <div class="fw-bold">Google Takvim'e Ekle</div>
                                <small class="text-muted">Otomatik güncellenir</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item sync-item" href="<?php echo $webcalUrl; ?>">
                            <div class="sync-icon-box text-primary"><i class="fab fa-apple"></i></div>
                            <div>
                                <div class="fw-bold">Apple / Outlook</div>
                                <small class="text-muted">Webcal aboneliği</small>
                            </div>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Manuel İşlem</h6></li>
                    <li>
                        <button class="dropdown-item sync-item" onclick="copyToClipboard('<?php echo $baseIcalUrl; ?>')">
                            <div class="sync-icon-box text-secondary"><i class="fas fa-link"></i></div>
                            <div>Bağlantıyı Kopyala</div>
                        </button>
                    </li>
                    <li>
                        <a class="dropdown-item sync-item" href="<?php echo $baseIcalUrl; ?>">
                            <div class="sync-icon-box text-success"><i class="fas fa-file-download"></i></div>
                            <div>Dosya Olarak İndir (.ics)</div>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="takvim-container">
            <div id="calendar"></div>
        </div>

    </div>

    <div class="fab-btn no-print" data-bs-toggle="modal" data-bs-target="#modalEkle" title="Yeni Etkinlik">
        <svg class="fab-icon" viewBox="0 0 24 24">
            <path fill="none" d="M0 0h24v24H0z"/>
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" fill="#ea4335"/> <path d="M0 0h24v24H0z" fill="none"/>
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" fill="url(#grad1)"/>
        </svg>
        <img src="https://www.gstatic.com/images/icons/material/system/2x/add_color_24dp.png" alt="+" width="36">
    </div>

    <div class="modal fade" id="modalEkle" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="etkinlik_ekle" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title text-secondary">Etkinlik Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <input type="text" name="baslik" class="form-control form-control-lg border-0 border-bottom rounded-0 ps-0" placeholder="Başlık ekleyin" required style="font-size: 1.5rem;">
                        </div>
                        
                        <div class="row mb-3 align-items-center">
                            <div class="col-1"><i class="far fa-clock text-muted"></i></div>
                            <div class="col">
                                <div class="row g-2">
                                    <div class="col-6"><input type="datetime-local" name="baslangic" id="inpBaslangic" class="form-control border-0 bg-light" required></div>
                                    <div class="col-6"><input type="datetime-local" name="bitis" class="form-control border-0 bg-light"></div>
                                </div>
                            </div>
                        </div>

                        <?php if($rol == 'admin'): ?>
                        <div class="row mb-3 align-items-center">
                            <div class="col-1"><i class="far fa-user text-muted"></i></div>
                            <div class="col">
                                <select name="musteri_id" class="form-select border-0 bg-light">
                                    <option value="">-- Müşteri Seçimi (İsteğe Bağlı) --</option>
                                    <?php foreach($musteriler as $mus): ?>
                                    <option value="<?php echo $mus['id']; ?>"><?php echo htmlspecialchars($mus['ad_soyad']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row mb-3">
                            <div class="col-1"><i class="fas fa-align-left text-muted mt-2"></i></div>
                            <div class="col">
                                <textarea name="aciklama" class="form-control border-0 bg-light" rows="3" placeholder="Açıklama ekleyin"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary px-4">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalDetay" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="etkinlik_guncelle" value="1">
                    <input type="hidden" name="etkinlik_id" id="edit_id">
                    
                    <div class="modal-header">
                        <button type="button" class="btn btn-light btn-sm text-danger me-auto" onclick="etkinlikSil()" title="Sil"><i class="fas fa-trash-alt"></i></button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <input type="text" name="baslik" id="edit_baslik" class="form-control form-control-lg border-0 border-bottom rounded-0 ps-0" required>
                        </div>
                        
                        <div class="row mb-3 align-items-center">
                            <div class="col-1"><i class="far fa-clock text-muted"></i></div>
                            <div class="col">
                                <div class="row g-2">
                                    <div class="col-6"><input type="datetime-local" name="baslangic" id="edit_baslangic" class="form-control border-0 bg-light" required></div>
                                    <div class="col-6"><input type="datetime-local" name="bitis" id="edit_bitis" class="form-control border-0 bg-light"></div>
                                </div>
                            </div>
                        </div>

                        <?php if($rol == 'admin'): ?>
                        <div class="row mb-3 align-items-center">
                            <div class="col-1"><i class="far fa-user text-muted"></i></div>
                            <div class="col">
                                <select name="musteri_id" id="edit_musteri_id" class="form-select border-0 bg-light">
                                    <option value="">-- Müşteri Seçimi --</option>
                                    <?php foreach($musteriler as $mus): ?>
                                    <option value="<?php echo $mus['id']; ?>"><?php echo htmlspecialchars($mus['ad_soyad']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row mb-3">
                            <div class="col-1"><i class="fas fa-align-left text-muted mt-2"></i></div>
                            <div class="col">
                                <textarea name="aciklama" id="edit_aciklama" class="form-control border-0 bg-light" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary px-4">Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="toast-container-yonetim"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/yonetim.js"></script>
    <?php foreach($extra_js as $js) echo "<script src='$js'></script>"; ?>
    <script><?php echo $inline_js; ?></script>

<?php require_once 'partials/footer_yonetim.php'; ?>
</body>
</html>