<?php
require_once 'musteri_header.php';
$m_id = $_SESSION['musteri_id'];

$stmt = $db->prepare("SELECT f.id, f.firma_adi FROM musteriler m JOIN firmalar f ON m.firma_id = f.id WHERE m.id = ?");
$stmt->execute([$m_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo "f.id değeri: " . $row['id'] . "<br>";
echo "musteri_id: " . $m_id . "<br>";

$yol = __DIR__ . '/../ibircari.xyz/uploads/haziralbumler/' . $row['id'] . '/' . $m_id . '/';
echo "Yol: $yol<br>";
echo "Var mı: " . (is_dir($yol) ? '✅ VAR' : '❌ YOK') . "<br>";