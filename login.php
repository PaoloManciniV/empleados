<?php
// ============================================================
// login.php
// Correcciones aplicadas:
// - htmlspecialchars() en echo $error para prevenir XSS
// - Protección anti-fuerza bruta: máx 5 intentos por IP + Usuario
//   en ventana de 15 minutos (evita bloqueo masivo en oficina)
// ============================================================

include('config.php');
$error = "";

// ── Anti-fuerza bruta (Ajustado para Agro-Costa) ─────────────
$user_input = $_POST['usuario'] ?? '';
$ip_address = $_SERVER['REMOTE_ADDR'];

// Usamos MD5 de IP + Usuario para que la llave sea única por persona en la misma oficina
$ip_key   = 'lk_' . md5($ip_address . $user_input);
$intentos = $_SESSION[$ip_key] ?? ['count' => 0, 'since' => time()];

// Reiniciar contador si ya pasaron 15 minutos
if (time() - $intentos['since'] > 900) {
    $intentos = ['count' => 0, 'since' => time()];
    $_SESSION[$ip_key] = $intentos;
}

$bloqueado = ($intentos['count'] >= 5);

if ($bloqueado) {
    $espera = ceil((900 - (time() - $intentos['since'])) / 60);
    $error  = "Demasiados intentos fallidos. Espera {$espera} minuto(s) e intenta de nuevo.";
}
// ────────────────────────────────────────────────────────────

if (!$bloqueado && $_SERVER["REQUEST_METHOD"] == "POST") {
    $usuario  = $_POST['usuario'];
    $password = $_POST['password'];

    $stmt = $db->prepare("SELECT * FROM usuarios WHERE username = ? OR correo = ?");
    $stmt->execute([$usuario, $usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        // Login exitoso: limpiar contador de intentos de este usuario
        unset($_SESSION[$ip_key]);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['rol']     = $user['rol'];
        $_SESSION['nombre']  = $user['nombre_completo'];
        $_SESSION['correo']  = $user['correo'];

        // Regenerar ID de sesión para prevenir session fixation
        session_regenerate_id(true);

        header("Location: index.php");
        exit();
    } else {
        // Login fallido: sumar intento
        $intentos['count']++;
        $_SESSION[$ip_key] = $intentos;

        $restantes = max(0, 5 - $intentos['count']);
        $error = $restantes > 0
            ? "Credenciales incorrectas. Te quedan {$restantes} intento(s)."
            : "Demasiados intentos fallidos. Espera 15 minutos e intenta de nuevo.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AgroCosta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #F7931E 0%, #FFCD00 100%);
            height: 100vh;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            width: 100%; max-width: 380px; padding: 2.5rem;
            border-radius: 12px; background: white;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            border-bottom: 5px solid #000;
        }
        .logo-container { text-align: center; margin-bottom: 20px; }
        .logo-container img { max-width: 180px; height: auto; }
        .btn-dark-cat {
            background-color: #000; border: none; color: #FFCD00;
            font-weight: bold; padding: 10px; transition: 0.3s;
        }
        .btn-dark-cat:hover { background-color: #333; color: #fff; }
        .btn-dark-cat:disabled { background-color: #666; color: #aaa; cursor: not-allowed; }
        .form-control:focus { border-color: #F7931E; box-shadow: 0 0 0 0.25rem rgba(247, 147, 30, 0.2); }
        .link-olvido { color: #555; font-size: 0.85rem; font-weight: 600; text-decoration: none; transition: 0.3s; }
        .link-olvido:hover { color: #000; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-container">
            <img src="logo.png" alt="AgroCosta">
        </div>
        <h6 class="text-center mb-4 fw-bold text-dark">ACCESO A EMPLEADOS</h6>
        <?php if ($error): ?>
            <div class="alert alert-danger py-1 small text-center">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold">Usuario</label>
                <input type="text" name="usuario" class="form-control" placeholder="Nombre de usuario"
                       value="<?php echo htmlspecialchars($user_input, ENT_QUOTES, 'UTF-8'); ?>"
                       <?php echo $bloqueado ? 'disabled' : ''; ?> required>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-bold">Contraseña</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••"
                       <?php echo $bloqueado ? 'disabled' : ''; ?> required>
                <div class="text-end mt-2">
                    <a href="recuperar.php" class="link-olvido">¿Olvidaste tu contraseña?</a>
                </div>
            </div>
            <button type="submit" class="btn btn-dark-cat w-100" <?php echo $bloqueado ? 'disabled' : ''; ?>>
                INGRESAR
            </button>
        </form>
    </div>
</body>
</html>
