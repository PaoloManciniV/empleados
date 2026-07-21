<?php
// ============================================================
// logout.php
// Sin cambios de lógica — config.php ya inicia la sesión.
// ============================================================

include('config.php');
session_destroy();
header("Location: login.php");
exit();
?>
