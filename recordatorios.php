<?php
// ============================================================
// recordatorios.php
// ------------------------------------------------------------
// Se ejecuta por CRON (recomendado: cada hora, o al menos 1 vez
// al día después de las 8:00 AM hora Colombia).
//
// Envía:
//   • Permisos NORMALES (no vacaciones):
//       - Día completo  -> aviso el día del permiso a las 8:00 AM
//       - Por horas     -> aviso 1 hora antes de la hora de inicio
//     (control con la columna recordatorio_enviado = 0/1)
//
//   • VACACIONES (aviso múltiple, control con tabla recordatorios_log):
//       - 7 días antes del inicio
//       - 3 días antes del inicio
//       - El mismo día del inicio a las 8:00 AM
//     Se avisa al JEFE + nómina + alba + sst + EL PROPIO EMPLEADO.
//
// Incluye el "Detalle del permiso excepcional" cuando aplica.
//
// Requisitos previos (ejecutar migracion_permisos.sql una vez):
//   - Columna  solicitudes.detalle_permiso
//   - Tabla    recordatorios_log
// ============================================================

date_default_timezone_set('America/Bogota');
require 'config.php';
require 'mailer.php';

use PHPMailer\PHPMailer\Exception;

try {
    $db->exec("SET time_zone = '-05:00';");
} catch (Exception $e) {
    error_log("recordatorios.php - error DB timezone: " . $e->getMessage());
    die("Error DB");
}

$ahora = date('Y-m-d H:i:s');
$hoy   = date('Y-m-d');
$horaActual = (int)date('H');   // hora del día 0-23

// Correos internos fijos que siempre reciben copia
$COPIAS_INTERNAS = [
    $_ENV['MAIL_CC'] ?? 'nomina@agro-costa.com',
    'alba@agro-costa.com',
    'sst@agro-costa.com',
];

echo "<h2>Ejecutando Recordatorios... ($ahora)</h2>";

// Traemos las solicitudes aprobadas cuyo permiso aún no ha terminado.
// Hacemos JOIN con usuarios para obtener el correo del empleado (para
// avisarle a él en el caso de vacaciones). Usamos LEFT JOIN por si la
// cédula no coincide, para no perder la solicitud.
$sql = "SELECT s.*, u.correo AS correo_empleado
        FROM solicitudes s
        LEFT JOIN usuarios u ON s.cedula = u.cedula
        WHERE s.estado = 'Aprobado'
          AND s.fecha_fin >= ?";

$stmt = $db->prepare($sql);
$stmt->execute([$hoy]);
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$enviados = 0;

foreach ($solicitudes as $sol) {

    $esVacaciones = (mb_strtolower(trim($sol['motivo'])) === 'vacaciones');

    if ($esVacaciones) {
        // ----- LÓGICA DE VACACIONES (avisos múltiples) -----
        $enviados += procesarVacaciones($db, $sol, $hoy, $horaActual, $COPIAS_INTERNAS);
    } else {
        // ----- LÓGICA NORMAL (un solo aviso, flag recordatorio_enviado) -----
        if ((int)$sol['recordatorio_enviado'] === 1) {
            continue; // ya se avisó
        }

        $enviar = false;
        $tipo   = "";

        if ($sol['hora_inicio'] == '00:00:00' || empty($sol['hora_inicio'])) {
            // Día completo -> el día del permiso a partir de las 8:00 AM
            if ($sol['fecha_inicio'] == $hoy && $horaActual >= 8) {
                $enviar = true;
                $tipo   = "Día Completo (Aviso 8:00 AM)";
            }
        } else {
            // Por horas -> 1 hora antes de la hora de inicio
            if ($sol['fecha_inicio'] == $hoy) {
                $inicio_permiso     = strtotime($sol['fecha_inicio'] . ' ' . $sol['hora_inicio']);
                $tiempo_ahora       = strtotime($ahora);
                $diferencia_minutos = ($inicio_permiso - $tiempo_ahora) / 60;

                if ($diferencia_minutos > 0 && $diferencia_minutos <= 65) {
                    $enviar = true;
                    $tipo   = "Por Horas (Falta 1 hora)";
                }
            }
        }

        if ($enviar) {
            $ok = enviarRecordatorio($sol, $tipo, false, $COPIAS_INTERNAS);
            if ($ok) {
                $upd = $db->prepare("UPDATE solicitudes SET recordatorio_enviado = 1 WHERE id = ?");
                $upd->execute([$sol['id']]);
                $enviados++;
                echo "&#9989; Recordatorio enviado para ID {$sol['id']} ($tipo)<br>";
            }
        }
    }
}

echo "<hr>Total enviados: $enviados";


// ============================================================
// FUNCIONES
// ============================================================

/**
 * Procesa los avisos de VACACIONES para una solicitud.
 * Envía (si corresponde) el aviso de 7 días, 3 días y del mismo día,
 * registrando cada uno en recordatorios_log para no repetirlo.
 * Devuelve cuántos correos envió.
 */
function procesarVacaciones($db, $sol, $hoy, $horaActual, $copias) {
    $enviadosLocal = 0;

    // Días que faltan (positivo = futuro, 0 = hoy, negativo = ya pasó el inicio)
    $ini  = new DateTime($sol['fecha_inicio']);
    $hoyD = new DateTime($hoy);
    $diasFaltan = (int)$hoyD->diff($ini)->format('%r%a');

    // Definimos qué avisos aplican HOY.
    // Usamos "<=" para que, si el permiso se aprobó tarde (con menos días
    // de anticipación), el aviso no se pierda: se manda en la corrida más
    // cercana. La tabla recordatorios_log garantiza que solo salga UNA vez.
    $avisosAplican = [];

    if ($diasFaltan >= 1) {
        // Aún faltan días para el inicio
        if ($diasFaltan <= 7) {
            $avisosAplican[] = ['7dias', "Vacaciones: faltan 7 días"];
        }
        if ($diasFaltan <= 3) {
            $avisosAplican[] = ['3dias', "Vacaciones: faltan 3 días"];
        }
    } elseif ($diasFaltan === 0) {
        // Es hoy: mandamos el aviso del día a partir de las 8:00 AM.
        // (Los de 7 y 3 días ya deberían haber salido en días previos;
        //  si nunca salieron, no tiene sentido mandarlos el mismo día.)
        if ($horaActual >= 8) {
            $avisosAplican[] = ['dia', "Vacaciones: comienzan HOY"];
        }
    }

    foreach ($avisosAplican as $av) {
        list($tipoAviso, $etiqueta) = $av;

        // ¿Ya se envió este aviso para esta solicitud?
        $chk = $db->prepare("SELECT 1 FROM recordatorios_log WHERE solicitud_id = ? AND tipo_aviso = ?");
        $chk->execute([$sol['id'], $tipoAviso]);
        if ($chk->fetchColumn()) {
            continue; // ya enviado, no repetir
        }

        $ok = enviarRecordatorio($sol, $etiqueta, true, $copias);

        if ($ok) {
            // Registramos el envío. El UNIQUE (solicitud_id, tipo_aviso)
            // evita duplicados aunque dos ejecuciones coincidan.
            try {
                $ins = $db->prepare("INSERT INTO recordatorios_log (solicitud_id, tipo_aviso) VALUES (?, ?)");
                $ins->execute([$sol['id'], $tipoAviso]);
            } catch (Exception $e) {
                error_log("recordatorios.php - log duplicado ID {$sol['id']} $tipoAviso: " . $e->getMessage());
            }
            $enviadosLocal++;
            echo "&#9989; Aviso de VACACIONES enviado para ID {$sol['id']} ($etiqueta)<br>";
        }
    }

    return $enviadosLocal;
}


/**
 * Envía el correo de recordatorio.
 * $incluirEmpleado = true agrega el correo del propio empleado como
 * destinatario (usado en vacaciones).
 * Devuelve true si se envió correctamente.
 */
function enviarRecordatorio($sol, $tipo_aviso, $incluirEmpleado, $copias) {
    try {
        $mail = crearMailer();
        $mail->setFrom(
            $_ENV['MAIL_USER'] ?? 'permisos-agrocosta@zohomail.com',
            'Agro-Costa Alertas'
        );

        // Destinatario principal: el jefe
        if (!empty($sol['correo_jefe'])) {
            $mail->addAddress($sol['correo_jefe']);
        }

        // Copias internas fijas
        foreach ($copias as $c) {
            if (!empty($c)) { $mail->addAddress($c); }
        }

        // En vacaciones, también avisamos al propio empleado
        if ($incluirEmpleado && !empty($sol['correo_empleado'])) {
            $mail->addAddress($sol['correo_empleado']);
        }

        $mail->isHTML(true);

        $e_empleado = htmlspecialchars($sol['empleado'], ENT_QUOTES, 'UTF-8');
        $e_motivo   = htmlspecialchars($sol['motivo'],   ENT_QUOTES, 'UTF-8');
        $e_tipo     = htmlspecialchars($tipo_aviso,      ENT_QUOTES, 'UTF-8');

        $es_dia_completo = ($sol['hora_inicio'] == '00:00:00' || empty($sol['hora_inicio']));
        $horario_txt = $es_dia_completo
                       ? "Todo el día"
                       : htmlspecialchars(date("g:i A", strtotime($sol['hora_inicio'])) . " a " . date("g:i A", strtotime($sol['hora_fin'])), ENT_QUOTES, 'UTF-8');

        // Rango de fechas (útil para vacaciones que abarcan varios días)
        $e_desde = htmlspecialchars($sol['fecha_inicio'], ENT_QUOTES, 'UTF-8');
        $e_hasta = htmlspecialchars($sol['fecha_fin'],    ENT_QUOTES, 'UTF-8');
        $rango_fechas = ($sol['fecha_inicio'] === $sol['fecha_fin'])
                        ? $e_desde
                        : "Del $e_desde al $e_hasta";

        // Detalle del permiso excepcional (si aplica)
        $bloque_detalle = '';
        if (!empty($sol['detalle_permiso'])) {
            $e_detalle = htmlspecialchars($sol['detalle_permiso'], ENT_QUOTES, 'UTF-8');
            $bloque_detalle = "
                <div style='background-color:#FFF7CC; border:2px solid #FFCD00; border-radius:10px; padding:12px; margin-top:12px;'>
                    <strong style='color:#8a6d00; font-size:12px; text-transform:uppercase;'>&#9888; Detalle del permiso excepcional:</strong><br>
                    <span style='color:#000; font-weight:600;'>$e_detalle</span>
                </div>";
        }

        $mail->Subject = "RECORDATORIO: $e_tipo — $e_empleado";

        $mail->Body = "
            <div style='background-color: #f8f9fa; padding: 20px; font-family: sans-serif;'>
                <div style='max-width: 500px; margin: 0 auto; background-color: #ffffff; border: 1px solid #FFCD00; border-radius: 10px; overflow: hidden;'>
                    <div style='background-color: #FFCD00; padding: 15px; text-align: center;'>
                        <strong style='font-size: 18px;'>RECORDATORIO AUTOMATICO</strong>
                    </div>
                    <div style='padding: 20px;'>
                        <p style='color: #333; font-weight:bold; font-size:15px;'>$e_tipo</p>
                        <p style='color: #555;'>Detalle del permiso:</p>
                        <ul style='line-height: 1.8;'>
                            <li><strong>Empleado:</strong> $e_empleado</li>
                            <li><strong>Motivo:</strong> $e_motivo</li>
                            <li><strong>Fechas:</strong> $rango_fechas</li>
                            <li><strong>Horario:</strong> $horario_txt</li>
                        </ul>
                        $bloque_detalle
                        <p style='font-size: 12px; color: #999; margin-top: 20px; text-align: center;'>
                            Este correo es solo informativo. No requiere respuesta.
                        </p>
                    </div>
                </div>
            </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("recordatorios.php - fallo correo ID {$sol['id']}: " . $e->getMessage());
        echo "Error al enviar recordatorio para ID {$sol['id']}: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
        return false;
    }
}
?>
