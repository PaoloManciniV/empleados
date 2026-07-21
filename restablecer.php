<?php
// ============================================================
// restablecer.php
// Correcciones aplicadas:
// - Eliminado session_start() propio (config.php lo maneja)
// - Mínimo de contraseña subido a 8 caracteres (validado en PHP)
// - htmlspecialchars() en echo $msg
// ============================================================

date_default_timezone_set('America/Bogota');
include('config.php');

$token       = $_GET['token'] ?? '';
$msg         = "";
$mostrarForm = false;

if ($token) {
    $now = date("Y-m-d H:i:s");
    
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE reset_token = ? AND reset_expire > ?");
    $stmt->execute([$token, $now]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $mostrarForm = true;
        
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $pass1 = $_POST['p1'];
            $pass2 = $_POST['p2'];

            // Validar mínimo de contraseña en PHP
            if (strlen($pass1) < 8) {
                $msg = "<div class='alert alert-danger text-center'>La contraseña debe tener al menos 8 caracteres.</div>";
            } elseif ($pass1 === $pass2) {
                $hash = password_hash($pass1, PASSWORD_DEFAULT);
                $upd  = $db->prepare("UPDATE usuarios SET password = ?, reset_token = NULL, reset_expire = NULL WHERE id = ?");
                $upd->execute([$hash, $user['id']]);
                
                $msg         = "<div class='alert alert-success text-center'>¡Contraseña actualizada!<br><a href='login.php' class='btn btn-dark mt-2 btn-sm'>INICIAR SESIÓN</a></div>";
                $mostrarForm = false;
            } else {
                $msg = "<div class='alert alert-danger text-center'>Las contraseñas no coinciden.</div>";
            }
        }
    } else {
        $msg = "<div class='alert alert-danger text-center'><strong>Enlace inválido o expirado.</strong><br>Vuelve a solicitar el cambio en 'Olvidé mi contraseña'.</div>";
    }
} else {
    $msg = "<div class='alert alert-warning text-center'>No hay token de seguridad.</div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Clave | Agro-Costa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#1A1A1A; height:100vh; display:flex; align-items:center; justify-content:center;">
    <div class="card p-4 shadow-lg" style="width:100%; max-width:400px; border-radius:20px; border-top:6px solid #FFCD00;">
        <h4 class="text-center fw-bold mb-3">NUEVA CONTRASEÑA</h4>
        <?php echo $msg; ?>
        
        <?php if ($mostrarForm): ?>
        <form method="POST">
            <div class="mb-3">
                <label class="fw-bold small text-muted">DIGITA LA NUEVA CLAVE</label>
                <input type="password" name="p1" class="form-control" required minlength="8">
            </div>
            <div class="mb-4">
                <label class="fw-bold small text-muted">CONFIRMA LA CLAVE</label>
                <input type="password" name="p2" class="form-control" required minlength="8">
            </div>
            <button type="submit" class="btn w-100 fw-bold py-2" style="background:#FFCD00; color:#000;">CAMBIAR CONTRASEÑA</button>
        </form>
        <?php endif; ?>
        
        <div class="text-center mt-3">
            <a href="login.php" class="text-dark small fw-bold text-decoration-none">Ir al Login</a>
        </div>
    </div>
</body>
</html>
