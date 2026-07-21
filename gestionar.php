<?php
// ============================================================
// gestionar.php
// Correcciones aplicadas:
// - Token CSRF inyectado en el formulario de acción
// - htmlspecialchars() en todos los echo de datos de BD
// ============================================================

include('config.php');

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("Location: login.php");
    exit();
}

$id_solicitud = isset($_GET['id']) ? (int)$_GET['id'] : null;
if (!$id_solicitud) { die("Error: Faltan datos."); }

$stmt = $db->prepare("SELECT * FROM solicitudes WHERE id = ?");
$stmt->execute([$id_solicitud]);
$solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$solicitud) { die("Solicitud no encontrada."); }

$soy_el_jefe = ($_SESSION['correo'] == $solicitud['correo_jefe']);
$soy_admin   = ($_SESSION['rol']    == 'admin');

if (!$soy_el_jefe && !$soy_admin) {
    $correo_jefe_safe = htmlspecialchars($solicitud['correo_jefe'], ENT_QUOTES, 'UTF-8');
    die("<div style='padding:50px; text-align:center; font-family:sans-serif;'>
            <h1>ACCESO DENEGADO</h1>
            <p>No tienes permisos para gestionar esta solicitud.</p>
            <p>Esta solicitud pertenece al jefe: <strong>$correo_jefe_safe</strong></p>
            <a href='index.php'>Volver al inicio</a>
         </div>");
}

// Generar token CSRF si no existe (puede llegar aquí sin pasar por index.php)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Solicitud | Agro-Costa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #F5F5F7; }
        .card-custom { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); overflow: hidden; }
        .header-top { background: #111; color: #FFCD00; padding: 20px; text-align: center; border-bottom: 4px solid #FFCD00; }
        .label-dato { font-size: 0.8rem; font-weight: bold; color: #888; text-transform: uppercase; letter-spacing: 1px; }
        .valor-dato { font-size: 1.1rem; font-weight: 500; color: #111; margin-bottom: 15px; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-5">

<div class="container" style="max-width: 600px;">
    <div class="card card-custom">
        <div class="header-top">
            <h4 class="m-0 fw-bold">GESTIONAR SOLICITUD #<?php echo (int)$solicitud['id']; ?></h4>
        </div>
        <div class="card-body p-4">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="label-dato">Empleado</div>
                    <div class="valor-dato"><?php echo htmlspecialchars($solicitud['empleado'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="label-dato">Cédula</div>
                    <div class="valor-dato"><?php echo htmlspecialchars($solicitud['cedula'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>

            <hr class="text-muted">

            <div class="mb-3">
                <div class="label-dato">Motivo</div>
                <div class="valor-dato"><?php echo htmlspecialchars($solicitud['motivo'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <?php if (!empty($solicitud['detalle_permiso'])): ?>
            <div class="mb-3 p-3" style="background-color:#FFF7CC; border:2px solid #FFCD00; border-radius:12px;">
                <div class="label-dato" style="color:#8a6d00;">&#9888; Detalle del permiso excepcional</div>
                <div class="valor-dato" style="margin-bottom:0;"><?php echo htmlspecialchars($solicitud['detalle_permiso'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-6">
                    <div class="label-dato">Desde</div>
                    <div class="valor-dato"><?php echo htmlspecialchars($solicitud['fecha_inicio'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="col-6">
                    <div class="label-dato">Hasta</div>
                    <div class="valor-dato"><?php echo htmlspecialchars($solicitud['fecha_fin'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
            
            <div class="mb-3">
                <div class="label-dato">Horario</div>
                <div class="valor-dato">
                    <?php 
                    echo ($solicitud['hora_inicio'] == '00:00:00' || empty($solicitud['hora_inicio'])) 
                        ? 'Día completo' 
                        : htmlspecialchars(date('g:i A', strtotime($solicitud['hora_inicio'])) . " - " . date('g:i A', strtotime($solicitud['hora_fin'])), ENT_QUOTES, 'UTF-8'); 
                    ?>
                </div>
            </div>

            <?php if ($solicitud['archivo_soporte']): ?>
            <div class="mb-4 text-center">
                <a href="uploads/<?php echo rawurlencode($solicitud['archivo_soporte']); ?>" target="_blank" class="btn btn-outline-dark btn-sm">
                    <i class="fas fa-paperclip"></i> Ver Soporte Adjunto
                </a>
            </div>
            <?php endif; ?>

            <form action="aprobar.php" method="POST">
                <!-- Token CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$solicitud['id']; ?>">
                
                <div class="mb-3">
                    <label class="fw-bold small mb-1">Observaciones (Opcional)</label>
                    <textarea name="observacion_jefe" class="form-control" rows="2" placeholder="Escribe un comentario..."></textarea>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" name="accion" value="Aprobado"  class="btn btn-success fw-bold py-2">&#9989; APROBAR PERMISO</button>
                    <button type="submit" name="accion" value="Rechazado" class="btn btn-danger  fw-bold py-2">&#10060; RECHAZAR PERMISO</button>
                </div>
            </form>
            
            <div class="text-center mt-3">
                <a href="index.php" class="text-muted small text-decoration-none">Volver al panel</a>
            </div>

        </div>
    </div>
</div>

</body>
</html>
