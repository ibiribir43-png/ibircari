<?php
/**
 * logout.php
 * Portal oturumunu sonlandırır.
 */
session_start();
session_destroy();
header("Location: portal.php");
exit;
?>