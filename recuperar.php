<?php
// ============================================================
// recuperar.php
// Correcciones aplicadas:
// - Usa crearMailer() centralizado (mailer.php)
// - htmlspecialchars() en echo $msg (aunque es HTML propio,
//   se mantiene la práctica defensiva)
// ============================================================

date_default_timezone_set('America/Bogota');
include('config.php');
include('mailer.php');

use PHPMailer\PHPMailer\Exception;

$msg = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $correo = $_POST['correo'];
    
    $stmt = $db->prepare("SELECT id, nombre_completo FROM usuarios WHERE correo = ?");
    $stmt->execute([$correo]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $token  = bin2hex(random_bytes(50));
        $expire = date("Y-m-d H:i:s", strtotime('+1 hour'));

        $upd = $db->prepare("UPDATE usuarios SET reset_token = ?, reset_expire = ? WHERE id = ?");
        $upd->execute([$token, $expire, $user['id']]);

        $mail = crearMailer();
        try {
            $mail->setFrom(
                $_ENV['MAIL_USER'] ?? 'permisos-agrocosta@zohomail.com',
                'Seguridad Agro-Costa'
            );
            $mail->addAddress($correo);
            $mail->isHTML(true);
            $mail->Subject = "Restablecer Contraseña - Agro-Costa";
            
            $link        = ($_ENV['APP_URL'] ?? 'https://agro-costa.com/empleados') . "/restablecer.php?token=" . $token;
            $nombre_safe = htmlspecialchars($user['nombre_completo'], ENT_QUOTES, 'UTF-8');

            $mail->Body = "
            <div style='font-family: sans-serif; background-color: #f4f4f4; padding: 20px;'>
                <div style='max-width: 500px; margin: 0 auto; background: #fff; border-radius: 10px; overflow: hidden; border-top: 5px solid #FFCD00;'>
                    <div style='padding: 30px; text-align: center;'>
                        <h3 style='color: #000;'>Hola, $nombre_safe</h3>
                        <p style='color: #555;'>Solicitud de cambio de clave recibida.</p>
                        <br>
                        <a href='$link' style='background-color: #FFCD00; color: #000; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>RESTABLECER AHORA</a>
                        <br><br>
                        <p style='color: #999; font-size: 12px;'>El enlace expira en 1 hora.</p>
                    </div>
                </div>
            </div>";
            
            $mail->send();
            $msg = "<div class='alert alert-success fw-bold text-center'>¡Enviado! Revisa tu correo.</div>";

        } catch (Exception $e) {
            error_log("recuperar.php - fallo envío correo: " . $e->getMessage());
            $msg = "<div class='alert alert-danger text-center'>Error al enviar correo. Intenta más tarde.</div>";
        }
    } else {
        $msg = "<div class='alert alert-danger text-center'>Ese correo no está registrado.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#1A1A1A; height:100vh; display:flex; align-items:center; justify-content:center;">
    <div class="card p-4 shadow-lg" style="width:100%; max-width:400px; border-radius:20px; border-top:6px solid #FFCD00;">
        <h4 class="text-center fw-bold mb-4">RECUPERAR ACCESO</h4>
        <?php echo $msg; ?>
        <form method="POST">
            <div class="mb-3">
                <label class="fw-bold small text-muted">CORREO ELECTRÓNICO</label>
                <input type="email" name="correo" class="form-control" required>
            </div>
            <button type="submit" class="btn w-100 fw-bold py-2" style="background:#FFCD00; color:#000;">ENVIAR ENLACE</button>
            <div class="text-center mt-4">
                <a href="login.php" class="text-secondary small text-decoration-none fw-bold">Volver al Login</a>
            </div>
        </form>
    </div>
</body>
</html>
