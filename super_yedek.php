<?php
require 'baglanti.php';

// Güvenlik: SADECE SÜPER ADMİN
if (!isset($_SESSION['kullanici_id']) || $_SESSION['rol'] != 'super_admin') {
    die("Erişim Yetkiniz Yok!");
}

$mesaj = "";

// --- İŞLEM: YEDEK İNDİRME (OTOMATİK VEYA MANUEL) ---
// Eğer URL'den hedef firma geldiyse veya formdan 'hepsi' geldiyse başlat
if (isset($_GET['hedef_firma']) || isset($_POST['yedek_al'])) {
    
    $hedefID = $_GET['hedef_firma'] ?? 'ALL'; // ALL = Full Backup

    // Yedekleme Başlıkları
    $return = "-- ibiR Cari Takip - SUPER ADMIN BACKUP\n";
    $return .= "-- Hedef: " . ($hedefID == 'ALL' ? 'TÜM SİSTEM' : $hedefID) . "\n";
    $return .= "-- Tarih: " . date("d.m.Y H:i:s") . "\n\n";
    $return .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // Tabloları Bul
    $tables = [];
    $result = $db->query("SHOW TABLES");
    while($row = $result->fetch(PDO::FETCH_NUM)) { $tables[] = $row[0]; }

    foreach($tables as $table) {
        $whereSQL = "";
        
        // Eğer tek firma ise filtrele, yoksa hepsini al
        if ($hedefID != 'ALL') {
            // Tabloda firma_id var mı kontrolü
            $colsQuery = $db->query("SHOW COLUMNS FROM $table");
            $cols = $colsQuery->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('firma_id', $cols)) {
                $whereSQL = "WHERE firma_id = '$hedefID'";
            } elseif ($table == 'firmalar') {
                $whereSQL = "WHERE id = '$hedefID'";
            } elseif ($table == 'teklif_kalemleri') {
                $whereSQL = "WHERE teklif_id IN (SELECT id FROM teklifler WHERE firma_id = '$hedefID')";
            } else {
                // Firma verisi içermeyen tabloları tekil yedekte atla
                if ($table != 'yoneticiler') { 
                    $return .= "-- Tablo: $table (Atlandı)\n";
                    continue; 
                }
                // Yöneticiler tablosunda firma_id var, yukarıda yakalanır ama ekstra kontrol
            }
        }

        // Temizleme Komutu (Sadece Full Backup'ta TRUNCATE kullanırız)
        if ($hedefID == 'ALL') {
            $return .= "DROP TABLE IF EXISTS $table;\n";
            $row2 = $db->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM);
            $return .= $row2[1] . ";\n\n";
        } else {
            // Tekil firmada sadece DELETE
            if($whereSQL != "") $return .= "DELETE FROM $table $whereSQL;\n";
        }

        // Verileri Yaz
        $dataQuery = $db->query("SELECT * FROM $table $whereSQL");
        if ($dataQuery !== false) {
            $num_fields = $dataQuery->columnCount();
            while($row = $dataQuery->fetch(PDO::FETCH_NUM)) {
                $return .= "INSERT INTO $table VALUES(";
                for($j=0; $j < $num_fields; $j++) {
                    $row[$j] = addslashes($row[$j]);
                    $row[$j] = str_replace("\n","\\n",$row[$j]);
                    if (isset($row[$j])) { $return .= '"' . $row[$j] . '"'; } else { $return .= '""'; }
                    if ($j < ($num_fields-1)) { $return .= ','; }
                }
                $return .= ");\n";
            }
        }
        $return .= "\n";
    }
    
    $return .= "SET FOREIGN_KEY_CHECKS=1;";

    $dosyaAdi = 'SUPER_BACKUP_' . ($hedefID=='ALL'?'FULL':substr($hedefID,0,5)) . '_' . date("d-m-Y") . '.sql';
    header('Content-Type: application/octet-stream');
    header("Content-Transfer-Encoding: Binary"); 
    header("Content-disposition: attachment; filename=\"".$dosyaAdi."\""); 
    echo $return; 
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Süper Yedekleme</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white d-flex align-items-center justify-content-center" style="height: 100vh;">

    <div class="text-center">
        <h1 class="display-4 mb-4"><i class="fas fa-database"></i> Sistem Yedekleme</h1>
        <p class="lead mb-5">Süper Admin Yetkisiyle Tam Erişim</p>
        
        <form method="POST">
            <input type="hidden" name="yedek_al" value="1">
            <button class="btn btn-success btn-lg px-5 py-3 shadow">
                <i class="fas fa-download me-2"></i> TÜM SİSTEMİ YEDEKLE (FULL)
            </button>
        </form>
        
        <div class="mt-4">
            <a href="super_admin.php" class="btn btn-outline-light">Panele Dön</a>
        </div>
    </div>
    
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</body>
</html>