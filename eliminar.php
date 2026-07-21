<?php
// ============================================================
// eliminar.php
// Correcciones aplicadas:
// - Eliminado session_start() propio (config.php lo maneja)
// - Uso de (int) para el ID
// - Cambiado de GET a POST con validación de token CSRF
//   (evita que un enlace externo borre solicitudes)
// ============================================================

include('config.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Solo acepta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

// --- VALIDACIÓN CSRF ---
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Error de seguridad: token inválido. Vuelve atrás e intenta de nuevo.");
}

if (isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    $stmt = $db->prepare("SELECT * FROM solicitudes WHERE id = ?");
    $stmt->execute([$id]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($solicitud) {
        $stmtUser = $db->prepare("SELECT cedula FROM usuarios WHERE id = ?");
        $stmtUser->execute([$_SESSION['user_id']]);
        $mi_usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($solicitud['cedula'] == $mi_usuario['cedula'] && $solicitud['estado'] == 'Pendiente') {

            // Borrar archivos físicos
            if (!empty($solicitud['archivo_soporte'])) {
                $archivos = explode(',', $solicitud['archivo_soporte']);
                foreach ($archivos as $archivo) {
                    $ruta = "uploads/" . trim($archivo);
                    if (file_exists($ruta)) {
                        unlink($ruta);
                    }
                }
            }

            // borra tambien los recordatorios asociados para no dejar filas huerfanas - paolo
            $delLog = $db->prepare("DELETE FROM recordatorios_log WHERE solicitud_id = ?");
            $delLog->execute([$id]);

            $del = $db->prepare("DELETE FROM solicitudes WHERE id = ?");
            $del->execute([$id]);

            echo "<script>alert('Solicitud eliminada correctamente.'); window.location.href='index.php';</script>";
        } else {
            echo "<script>alert('No puedes eliminar esta solicitud (Ya fue gestionada o no es tuya).'); window.location.href='index.php';</script>";
        }
    } else {
        header("Location: index.php");
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}
?>
