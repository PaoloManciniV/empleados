<?php
// ============================================================
// aprobar.php
// Correcciones aplicadas:
// - Validación CSRF
// - Usa crearMailer() centralizado (mailer.php)
// - error_log() en catch de correo (ya no silencioso)
// - htmlspecialchars en datos del correo HTML
// ============================================================

date_default_timezone_set('America/Bogota');
include('config.php');
include('mailer.php');

use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id'])) { die("Acceso denegado."); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // --- VALIDACIÓN CSRF ---
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: token inválido. Vuelve atrás e intenta de nuevo.");
    }

    $id     = (int)$_POST['id'];
    $accion = $_POST['accion'];
    $obs    = $_POST['observacion_jefe'];

    // 1. OBTENER LA SOLICITUD
    $stmt = $db->prepare("SELECT s.*, u.correo as correo_user FROM solicitudes s JOIN usuarios u ON s.cedula = u.cedula WHERE s.id = ?");
    $stmt->execute([$id]);
    $sol = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sol) { die("Error: Solicitud no encontrada."); }

    // CANDADO ANTI-SOBREESCRITURA
    if ($sol['estado'] != 'Pendiente') {
        $gestor_previo = htmlspecialchars($sol['usuario_gestor'] ?? 'Otro administrador', ENT_QUOTES, 'UTF-8');
        $fecha_previa  = htmlspecialchars($sol['fecha_gestion']  ?? 'recientemente',      ENT_QUOTES, 'UTF-8');
        $estado_actual = htmlspecialchars($sol['estado'],                                 ENT_QUOTES, 'UTF-8');
        echo "<script>
            alert('ACCION BLOQUEADA:\\n\\nEsta solicitud YA FUE GESTIONADA anteriormente.\\n\\nEstado actual: {$estado_actual}\\nGestor: $gestor_previo\\nFecha: $fecha_previa\\n\\nNo se pueden realizar cambios sobre una solicitud cerrada.'); 
            window.location.href='index.php';
        </script>";
        exit();
    }

    // 2. VERIFICAR PERMISOS
    $soy_el_jefe = ($_SESSION['correo'] == $sol['correo_jefe']);
    $soy_admin   = ($_SESSION['rol']    == 'admin');

    if (!$soy_el_jefe && !$soy_admin) {
        die("SEGURIDAD: No tienes permiso para gestionar esta solicitud.");
    }

    // DATOS DE AUDITORÍA
    $ip_cliente    = $_SERVER['REMOTE_ADDR'];
    $dispositivo   = $_SERVER['HTTP_USER_AGENT'];
    $ahora         = date("Y-m-d H:i:s"); 
    $quien_gestiona = $_SESSION['nombre']; 

    // 3. ACTUALIZAR BD
    $upd = $db->prepare("UPDATE solicitudes SET estado = ?, observacion_jefe = ?, ip_aprobacion = ?, info_dispositivo = ?, fecha_gestion = ?, usuario_gestor = ? WHERE id = ?");
    $upd->execute([$accion, $obs, $ip_cliente, $dispositivo, $ahora, $quien_gestiona, $id]);

    // 4. ENVÍO DE CORREO
    $accent         = ($accion == 'Aprobado') ? '#34C759' : '#FF3B30';
    $e_empleado     = htmlspecialchars($sol['empleado'],      ENT_QUOTES, 'UTF-8');
    $e_motivo       = htmlspecialchars($sol['motivo'],        ENT_QUOTES, 'UTF-8');
    $e_accion       = htmlspecialchars($accion,               ENT_QUOTES, 'UTF-8');
    $e_obs          = htmlspecialchars($obs ?: 'Ninguna',     ENT_QUOTES, 'UTF-8');
    $e_gestor       = htmlspecialchars($quien_gestiona,       ENT_QUOTES, 'UTF-8');

    // Detalle del permiso excepcional (si aplica), resaltado en amarillo.
    $bloque_detalle = '';
    if (!empty($sol['detalle_permiso'])) {
        $e_detalle = htmlspecialchars($sol['detalle_permiso'], ENT_QUOTES, 'UTF-8');
        $bloque_detalle = "
            <div style='background-color:#FFF7CC; border:2px solid #FFCD00; border-radius:12px; padding:15px; margin-top:15px;'>
                <strong style='color:#8a6d00; text-transform:uppercase; font-size:12px;'>&#9888; Detalle del permiso excepcional:</strong><br>
                <span style='color:#000; font-weight:600;'>$e_detalle</span>
            </div>";
    }

    try {
        $mail = crearMailer();
        $mail->addAddress($sol['correo_user']);
        $mail->addCC($_ENV['MAIL_CC'] ?? 'nomina@agro-costa.com');
        $mail->addCC('alba@agro-costa.com');
        $mail->addCC('sst@agro-costa.com');
        $mail->isHTML(true);
        $mail->Subject = "RESPUESTA PERMISO: {$e_motivo} ($e_accion)";
        $mail->Body = "
            <div style='background-color: #F5F5F7; padding: 40px; font-family: sans-serif; color: #111;'>
                <div style='max-width: 500px; margin: 0 auto; background-color: #ffffff; border-radius: 24px; overflow: hidden; border: 1px solid #d2d2d7;'>
                    <div style='background-color: #111111; padding: 30px; text-align: center; border-bottom: 5px solid #FFCD00;'>
                        <h2 style='margin: 0; color: #FFCD00; font-size: 18px; text-transform: uppercase;'>AGRO-COSTA RRHH</h2>
                    </div>
                    <div style='padding: 40px;'>
                        <p>Hola <strong>$e_empleado</strong>,</p>
                        <div style='background-color: #F5F5F7; padding: 25px; border-radius: 20px; border-left: 6px solid $accent;'>
                            <p><strong>MOTIVO:</strong> $e_motivo</p>
                            $bloque_detalle
                            <p style='margin-top:15px;'><strong>ESTADO:</strong> <span style='color: $accent; font-weight:bold;'>$e_accion</span></p>
                            <p><strong>OBSERVACIONES:</strong><br><em>$e_obs</em></p>
                            <hr>
                            <p style='font-size:12px; color:#777;'>Gestionado por: <strong>$e_gestor</strong></p>
                        </div>
                    </div>
                </div>
            </div>";
        
        $mail->send();

    } catch (Exception $e) {
        // Registra el fallo del correo, no lo silencia
        error_log("aprobar.php - fallo envío correo ID $id: " . $e->getMessage());
    }

    echo "<script>alert('Solicitud procesada correctamente.'); window.location.href='index.php';</script>";
}
?>
