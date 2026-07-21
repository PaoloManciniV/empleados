<?php
// ============================================================
// cambiar_clave.php
// Correcciones aplicadas:
// - Eliminado session_start() propio (config.php lo maneja)
// - Validación CSRF
// - Mínimo de contraseña subido a 8 caracteres (validado en PHP)
// ============================================================

include('config.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {

    // --- VALIDACIÓN CSRF ---
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: token inválido. Vuelve atrás e intenta de nuevo.");
    }

    $actual    = $_POST['clave_actual'];
    $nueva     = $_POST['clave_nueva'];
    $confirmar = $_POST['clave_confirmar'];

    if ($nueva !== $confirmar) {
        echo "<script>alert('Las contraseñas nuevas no coinciden.'); window.location.href='index.php';</script>";
        exit();
    }

    // Validar longitud mínima en PHP (no solo en el HTML)
    if (strlen($nueva) < 8) {
        echo "<script>alert('La nueva contraseña debe tener al menos 8 caracteres.'); window.location.href='index.php';</script>";
        exit();
    }

    $stmt = $db->prepare("SELECT password FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (password_verify($actual, $user['password'])) {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $upd  = $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
        $upd->execute([$hash, $_SESSION['user_id']]);
        echo "<script>alert('Contraseña actualizada correctamente.'); window.location.href='index.php';</script>";
    } else {
        echo "<script>alert('La contraseña actual es incorrecta.'); window.location.href='index.php';</script>";
    }

} else {
    header("Location: index.php");
    exit();
}
?>
