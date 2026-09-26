<?php
session_start();
// Hancurkan semua data session browser
session_unset();
session_destroy();

// Tendang kembali ke halaman login
header("Location: login.php");
exit;
?>