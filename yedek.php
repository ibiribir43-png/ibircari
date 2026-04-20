<?php
require 'baglanti.php';

// Güvenlik
if (!isset($_SESSION['kullanici_id']) || !isset($_SESSION['firma_id'])) {
    header("Location: index.php");
    exit;
}

$firma_id = $_SESSION['firma_id'];
$mesaj = "";
$mesajTuru = "";

// --- İŞLEM 1: YEDEK ALMA (DİNAMİK VE AKILLI) ---
if (isset($_POST['islem']) && $_POST['islem'] == 'yedek_al') {
    $gelen_sifre = md5($_POST['admin_sifre']);
    
    $stmt = $db->prepare("SELECT * FROM yoneticiler WHERE id = ? AND firma_id = ?");
    $stmt->execute([$_SESSION['kullanici_id'], $firma_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || $gelen_sifre !== $admin['sifre']) {
        $mesaj = "Hatalı şifre! Yedek alma işlemi iptal edildi.";
        $mesajTuru = "danger";
    } else {
        // --- YEDEKLEME BAŞLIYOR ---
        $return = "-- ibiR Cari Takip - Dinamik Yedek Dosyası\n";
        $return .= "-- Firma ID: " . $firma_id . "\n";
        $return .= "-- Tarih: " . date("d.m.Y H:i:s") . "\n\n";
        $return .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        // 1. Veritabanındaki TÜM tabloları otomatik bul (Elle yazmak yok!)
        $allTables = [];
        $result = $db->query("SHOW TABLES");
        while($row = $result->fetch(PDO::FETCH_NUM)) {
            $allTables[] = $row[0];
        }

        foreach($allTables as $table) {
            // 2. Bu tablonun sütunlarını kontrol et (firma_id var mı?)
            $columnsQuery = $db->query("SHOW COLUMNS FROM $table");
            $columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);
            
            $whereSQL = "";

            // --- AKILLI KARAR MEKANİZMASI ---
            if (in_array('firma_id', $columns)) {
                // Tabloda firma_id sütunu var -> Sadece bizimkileri al
                $whereSQL = "WHERE firma_id = '$firma_id'";
            } 
            elseif ($table == 'firmalar') {
                // Firmalar tablosunda firma_id yok ama ID sütunu bizim firma ID'mizdir
                $whereSQL = "WHERE id = '$firma_id'";
            }
            else {
                // Ne firma_id var, ne de firmalar tablosu.
                // Bu tabloyu pas geçiyoruz (Güvenlik ve İzolasyon)
                $return .= "-- Tablo: $table (Firma verisi içermediği için atlandı)\n\n";
                continue; 
            }

            // 3. Temizleme Komutu (Restore ederken çakışmayı önler)
            // Firmalar tablosunu silmiyoruz, diğerlerini siliyoruz
            if ($table != 'firmalar') {
                 $return .= "-- Tablo Temizleniyor: $table\n";
                 $return .= "DELETE FROM $table $whereSQL;\n";
            }

            // 4. Verileri Çek ve Yaz
            try {
                $dataQuery = $db->query("SELECT * FROM $table $whereSQL");
                
                if ($dataQuery !== false) {
                    $num_fields = $dataQuery->columnCount();
                    
                    while($row = $dataQuery->fetch(PDO::FETCH_NUM)) {
                        // Firmalar tablosu için INSERT IGNORE (Çakışma olmasın)
                        $insertCmd = ($table == 'firmalar') ? "INSERT IGNORE INTO" : "INSERT INTO";
                        
                        $return .= "$insertCmd $table VALUES(";
                        for($j=0; $j < $num_fields; $j++) {
                            $row[$j] = addslashes($row[$j]);
                            // Satır sonlarını temizle
                            $row[$j] = str_replace("\n","\\n",$row[$j]);
                            if (isset($row[$j])) { $return .= '"' . $row[$j] . '"'; } else { $return .= '""'; }
                            if ($j < ($num_fields-1)) { $return .= ','; }
                        }
                        $return .= ");\n";
                    }
                }
            } catch (Exception $e) {
                $return .= "-- HATA: $table tablosu okunurken sorun oluştu.\n";
            }
            $return .= "\n";
        }
        
        $return .= "SET FOREIGN_KEY_CHECKS=1;";

        $dosyaAdi = 'yedek_' . date("d-m-Y") . '_' . substr(md5(time()), 0, 5) . '.sql';
        header('Content-Type: application/octet-stream');
        header("Content-Transfer-Encoding: Binary"); 
        header("Content-disposition: attachment; filename=\"".$dosyaAdi."\""); 
        echo $return; 
        exit;
    }
}

// --- İŞLEM 2: GERİ YÜKLEME (FOPEN ile Bellek Dostu) ---
if (isset($_POST['islem']) && $_POST['islem'] == 'geri_yukle') {
    $gelen_sifre = md5($_POST['admin_sifre']);
    
    $stmt = $db->prepare("SELECT * FROM yoneticiler WHERE id = ? AND firma_id = ?");
    $stmt->execute([$_SESSION['kullanici_id'], $firma_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || $gelen_sifre !== $admin['sifre']) {
        $mesaj = "Hatalı şifre! Geri yükleme yapılamadı.";
        $mesajTuru = "danger";
    } elseif ($_FILES['sql_dosyasi']['error'] > 0) {
        $mesaj = "Dosya yüklenirken hata oluştu.";
        $mesajTuru = "danger";
    } else {
        $dosyaAdi = $_FILES['sql_dosyasi']['name'];
        $uzanti = strtolower(pathinfo($dosyaAdi, PATHINFO_EXTENSION));
        
        if($uzanti != "sql") {
            $mesaj = "Sadece .sql uzantılı yedek dosyaları yüklenebilir.";
            $mesajTuru = "danger";
        } else {
            // Dosyayı satır satır oku
            $handle = fopen($_FILES['sql_dosyasi']['tmp_name'], "r");
            $sorgu = '';
            
            ini_set('memory_limit', '512M');
            set_time_limit(0);

            if ($handle) {
                try {
                    $db->query("SET FOREIGN_KEY_CHECKS=0");

                    while (($satir = fgets($handle)) !== false) {
                        if (substr($satir, 0, 2) == '--' || trim($satir) == '') continue;

                        $sorgu .= $satir;
                        // Noktalı virgül görünce sorguyu çalıştır
                        if (substr(trim($satir), -1, 1) == ';') {
                            $db->query($sorgu);
                            $sorgu = '';
                        }
                    }
                    
                    $db->query("SET FOREIGN_KEY_CHECKS=1");
                    
                    $mesaj = "Veritabanı yedeği başarıyla geri yüklendi! Verileriniz güncellendi.";
                    $mesajTuru = "success";

                } catch (PDOException $e) {
                    $mesaj = "Geri yükleme hatası: " . $e->getMessage();
                    $mesajTuru = "danger";
                }
                fclose($handle);
            } else {
                $mesaj = "Dosya okunamadı.";
                $mesajTuru = "danger";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yedekleme Merkezi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { background-color: #f8f9fa; }</style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="anasayfa.php"><i class="fas fa-wallet text-primary me-2"></i>Cari Takip</a>
            <div class="ms-auto">
                <a href="anasayfa.php" class="btn btn-sm btn-outline-secondary">Ana Menü</a>
            </div>
        </div>
    </nav>

    <div class="container">
        
        <h3 class="text-secondary mb-4"><i class="fas fa-database me-2"></i>Veri Güvenlik Merkezi</h3>

        <?php if($mesaj): ?>
            <div class="alert alert-<?php echo $mesajTuru; ?> alert-dismissible fade show">
                <?php echo $mesaj; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            
            <!-- YEDEK AL -->
            <div class="col-md-6 mb-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-success text-white"><h5 class="mb-0"><i class="fas fa-download me-2"></i>Verilerimi İndir</h5></div>
                    <div class="card-body">
                        <p class="text-muted">
                            Mevcut sistemin tam yedeğini bilgisayarınıza <strong>.sql</strong> dosyası olarak indirir.
                        </p>
                        <hr>
                        <form method="POST">
                            <input type="hidden" name="islem" value="yedek_al">
                            <div class="mb-3">
                                <label class="fw-bold">Onay Şifresi:</label>
                                <input type="password" name="admin_sifre" class="form-control" required placeholder="******">
                            </div>
                            <button type="submit" class="btn btn-success w-100 btn-lg"><i class="fas fa-file-download me-2"></i>Yedeği İndir</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- GERİ YÜKLE KODLARI BURAYA GELECEK-->
           
    </div>
    
    <?php if($mesaj && $mesajTuru == 'success'): ?>
    <script>window.onload = function() { alert("<?php echo $mesaj; ?>"); }</script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>