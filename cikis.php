<?php
session_start();
session_destroy(); // Oturumu öldür
header("Location: index.php"); // Giriş kapısına geri gönder
exit;
?>