<?php
// ============================================================
// procesar.php
// Correcciones aplicadas:
// - Validación CSRF
// - Validación server-side de tipo MIME y extensión de archivos
// - Usa crearMailer() centralizado (mailer.php)
// - htmlspecialchars en datos reflejados al correo
// ============================================================

date_default_timezone_set('America/Bogota');
include('config.php');
include('mailer.php');

use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // --- VALIDACIÓN CSRF ---
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: token inválido. Vuelve atrás e intenta de nuevo.");
    }

    // CAPTURA DE DATOS
    $empleado    = $_POST['empleado'];
    $cedula      = $_POST['cedula'];
    $correo_jefe = $_POST['correo_jefe'];
    $motivo      = $_POST['motivo'];
    $cargo       = $_POST['cargo'];
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_fin    = $_POST['fecha_fin'];
    $notas        = $_POST['notas'] ?? '';

    // --- VALIDACIÓN DE FECHAS (server-side) ---
    // La fecha fin no puede ser anterior a la fecha inicio.
    if (!empty($fecha_inicio) && !empty($fecha_fin) && $fecha_fin < $fecha_inicio) {
        die("Error en las fechas: la fecha fin ($fecha_fin) no puede ser anterior a la fecha inicio ($fecha_inicio). Vuelve atrás y corrige las fechas de tu solicitud.");
    }

    // --- PERMISO EXCEPCIONAL ---
    // Cuando el motivo llega como "Otros", el motivo real es "Permiso excepcional"
    // y el texto que escribió el empleado viaja en el campo 'detalle_permiso'.
    // Antes este texto se perdía porque el input no tenía "name".
    $detalle_permiso = trim($_POST['detalle_permiso'] ?? '');
    if ($motivo === 'Otros') {
        $motivo = 'Permiso excepcional';
        // Blindaje server-side: si por algún motivo llegó vacío, lo marcamos
        if ($detalle_permiso === '') {
            $detalle_permiso = '(No especificado)';
        }
    } else {
        // Para cualquier otro motivo, no guardamos detalle
        $detalle_permiso = '';
    }

    $hora_inicio_raw = $_POST['hora_inicio'] ?? '';
    $hora_fin_raw    = $_POST['hora_fin'] ?? '';
    $h_ini = (!empty($hora_inicio_raw)) ? date("H:i:s", strtotime($hora_inicio_raw)) : '00:00:00';
    $h_fin = (!empty($hora_fin_raw))    ? date("H:i:s", strtotime($hora_fin_raw))    : '00:00:00';

    // --- PERMISO RECURRENTE (horario academico) - paolo ---
    // el horario real va por dia en la tabla solicitud_recurrencia, no en la
    // solicitud. armamos aqui la lista de dias validando en servidor.
    $es_recurrente     = ($motivo === 'Permiso Recurrente');
    $dias_recurrentes  = []; // cada item: [dia_semana, hora_ini(H:i:s), hora_fin(H:i:s)]
    if ($es_recurrente) {
        // en recurrente el horario de la solicitud queda en 00:00:00 (dia completo neutro)
        $h_ini = '00:00:00';
        $h_fin = '00:00:00';

        $dias_post = $_POST['rec_dia']      ?? [];
        $ini_post  = $_POST['rec_hora_ini'] ?? [];
        $fin_post  = $_POST['rec_hora_fin'] ?? [];

        foreach ($dias_post as $num => $marcado) {
            $num = (int)$num;
            if ($num < 1 || $num > 7) { continue; } // solo 1..7 (lun..dom)
            $hi_raw = $ini_post[$num] ?? '';
            $hf_raw = $fin_post[$num] ?? '';
            if ($hi_raw === '' || $hf_raw === '') {
                die("Error: en el permiso recurrente todos los dias marcados deben tener hora inicio y fin. Vuelve atras y completa.");
            }
            $hi = date("H:i:s", strtotime($hi_raw));
            $hf = date("H:i:s", strtotime($hf_raw));
            if ($hf <= $hi) {
                die("Error: en el permiso recurrente la hora fin debe ser posterior a la hora inicio en cada dia. Vuelve atras y corrige.");
            }
            $dias_recurrentes[] = [$num, $hi, $hf];
        }

        if (empty($dias_recurrentes)) {
            die("Error: para un permiso recurrente debes marcar al menos un dia de la semana con su horario. Vuelve atras e intenta de nuevo.");
        }
    }

    $ahora_envio = date("Y-m-d H:i:s");

    // --- PROCESAMIENTO DE ARCHIVOS CON VALIDACIÓN SERVER-SIDE ---
    $extensiones_permitidas = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    $mimes_permitidos = [
        'image/jpeg', 'image/png', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    $nombres_guardados   = [];
    $html_adjuntos_email = "";

    if (isset($_FILES['soporte']) && count($_FILES['soporte']['name']) > 0) {
        $total = count($_FILES['soporte']['name']);
        for ($i = 0; $i < $total; $i++) {
            if ($_FILES['soporte']['error'][$i] == 0 && !empty($_FILES['soporte']['name'][$i])) {
                $nombre_original = $_FILES['soporte']['name'][$i];
                $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

                // Validar extensión
                if (!in_array($ext, $extensiones_permitidas)) {
                    die("Archivo no permitido: solo se aceptan JPG, PNG, PDF, DOC, DOCX, XLS, XLSX.");
                }

                // Validar tipo MIME real del archivo (no lo que dice el navegador)
                $finfo     = new finfo(FILEINFO_MIME_TYPE);
                $mime_real = $finfo->file($_FILES['soporte']['tmp_name'][$i]);
                if (!in_array($mime_real, $mimes_permitidos)) {
                    die("El tipo de archivo no está permitido (tipo detectado: $mime_real).");
                }

                // Validar tamaño (25 MB máx)
                if ($_FILES['soporte']['size'][$i] > 26214400) {
                    die("El archivo #" . ($i+1) . " supera el límite de 25 MB.");
                }

                $nuevo_nombre  = uniqid() . "__" . $nombre_original;
                $ruta_destino  = "uploads/" . $nuevo_nombre;
                
                if (move_uploaded_file($_FILES['soporte']['tmp_name'][$i], $ruta_destino)) {
                    $nombres_guardados[]  = $nuevo_nombre;
                    $url_codificada       = ($_ENV['APP_URL'] ?? 'https://agro-costa.com/empleados') . "/uploads/" . rawurlencode($nuevo_nombre);
                    $nombre_safe          = htmlspecialchars($nombre_original, ENT_QUOTES, 'UTF-8');
                    $html_adjuntos_email .= "<div style='margin-top:5px;'><a href='$url_codificada' style='color: #FFCD00; text-decoration:none;'>&#128206; $nombre_safe</a></div>";
                }
            }
        }
    }

    $string_archivos_bd = implode(',', $nombres_guardados);
    if (empty($html_adjuntos_email)) {
        $html_adjuntos_email = "<span style='color:#777;'>Sin soportes adjuntos.</span>";
    }

    try {
        $sql = "INSERT INTO solicitudes (empleado, cedula, cargo, motivo, detalle_permiso, fecha_inicio, fecha_fin, hora_inicio, hora_fin, archivo_soporte, correo_jefe, notas, fecha_solicitud, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente')";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $empleado, $cedula, $cargo, $motivo, $detalle_permiso, $fecha_inicio, $fecha_fin, $h_ini, $h_fin,
            $string_archivos_bd, $correo_jefe, $notas, $ahora_envio
        ]);
        
        $id  = $db->lastInsertId();
        $url = ($_ENV['APP_URL'] ?? 'https://agro-costa.com/empleados') . "/gestionar.php?id=" . $id;

        // --- guardar los dias del permiso recurrente y armar su bloque para el correo - paolo ---
        $bloque_recurrente = '';
        if ($es_recurrente && !empty($dias_recurrentes)) {
            $nombres_dias = [1=>'Lunes',2=>'Martes',3=>'Miercoles',4=>'Jueves',5=>'Viernes',6=>'Sabado',7=>'Domingo'];
            $insRec = $db->prepare("INSERT INTO solicitud_recurrencia (solicitud_id, dia_semana, hora_inicio, hora_fin) VALUES (?, ?, ?, ?)");
            $filas_html = '';
            foreach ($dias_recurrentes as $dr) {
                list($dnum, $dhi, $dhf) = $dr;
                $insRec->execute([$id, $dnum, $dhi, $dhf]);
                $dia_nom = $nombres_dias[$dnum] ?? '';
                $hi12 = htmlspecialchars(date('g:i A', strtotime($dhi)), ENT_QUOTES, 'UTF-8');
                $hf12 = htmlspecialchars(date('g:i A', strtotime($dhf)), ENT_QUOTES, 'UTF-8');
                $filas_html .= "<li><strong>$dia_nom:</strong> $hi12 a $hf12</li>";
            }
            $bloque_recurrente = "
                <div style='background-color:#FFF7CC; border:2px solid #FFCD00; border-radius:10px; padding:15px; margin-top:15px;'>
                    <strong style='color:#8a6d00; text-transform:uppercase; font-size:13px;'>&#128197; Dias y horarios del permiso recurrente:</strong>
                    <ul style='margin:8px 0 0 0; color:#000;'>$filas_html</ul>
                </div>";
        }

        // Sanitizar para el correo HTML
        $e_empleado     = htmlspecialchars($empleado,        ENT_QUOTES, 'UTF-8');
        $e_cargo        = htmlspecialchars($cargo,           ENT_QUOTES, 'UTF-8');
        $e_motivo       = htmlspecialchars($motivo,          ENT_QUOTES, 'UTF-8');
        $e_detalle      = htmlspecialchars($detalle_permiso, ENT_QUOTES, 'UTF-8');

        // Bloque resaltado en amarillo con el detalle del permiso excepcional.
        // Solo aparece si hay un detalle (es decir, si fue "Permiso excepcional").
        $bloque_detalle = '';
        if (!empty($detalle_permiso)) {
            $bloque_detalle = "
                <div style='background-color:#FFF7CC; border:2px solid #FFCD00; border-radius:10px; padding:15px; margin-top:15px;'>
                    <strong style='color:#8a6d00; text-transform:uppercase; font-size:13px;'>&#9888; Detalle del permiso excepcional:</strong><br>
                    <span style='color:#000; font-weight:600; font-size:15px;'>$e_detalle</span>
                </div>";
        }
        $e_fecha_inicio = htmlspecialchars($fecha_inicio,    ENT_QUOTES, 'UTF-8');
        $e_fecha_fin    = htmlspecialchars($fecha_fin,       ENT_QUOTES, 'UTF-8');
        $e_notas        = htmlspecialchars($notas ?: 'Sin notas', ENT_QUOTES, 'UTF-8');
        $e_hora_ini     = htmlspecialchars($hora_inicio_raw ?: 'Día completo', ENT_QUOTES, 'UTF-8');
        $e_hora_fin     = htmlspecialchars($hora_fin_raw    ?: 'Día completo', ENT_QUOTES, 'UTF-8');

        // en recurrente el horario no va en una sola linea (va por dia en el bloque) - paolo
        $linea_horario = $es_recurrente
            ? "<p><strong>Horario:</strong> ver dias y horarios abajo</p>"
            : "<p><strong>Horario:</strong> $e_hora_ini a $e_hora_fin</p>";

        $mail = crearMailer();
        $mail->addAddress($correo_jefe);
        $mail->addCC($_ENV['MAIL_CC'] ?? 'nomina@agro-costa.com');
        $mail->addCC('alba@agro-costa.com');
        $mail->addCC('sst@agro-costa.com');

        // en permiso recurrente tambien notificamos al propio empleado - paolo
        if ($es_recurrente) {
            $stmtCorreoEmp = $db->prepare("SELECT correo FROM usuarios WHERE cedula = ? LIMIT 1");
            $stmtCorreoEmp->execute([$cedula]);
            $correo_empleado = $stmtCorreoEmp->fetchColumn();
            if (!empty($correo_empleado)) {
                $mail->addCC($correo_empleado);
            }
        }

        $mail->isHTML(true);
        $mail->Subject = "NUEVA SOLICITUD: $e_empleado ($e_motivo)";
        $mail->Body = "
            <div style='background-color: #f4f4f4; padding: 20px; font-family: sans-serif;'>
                <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 2px solid #FFCD00; border-radius: 20px; overflow: hidden;'>
                    <div style='background-color: #FFCD00; color: #000; padding: 25px; text-align: center;'>
                        <h2 style='margin: 0; text-transform: uppercase; font-weight: 800;'>AGRO-COSTA: Nueva Solicitud</h2>
                    </div>
                    <div style='padding: 30px; color: #000000; line-height: 1.6;'>
                        <p style='font-size: 16px;'>El empleado <strong>$e_empleado</strong> ha solicitado un permiso:</p>
                        <hr style='border: 0; border-top: 1px solid #dddddd;'>
                        <p><strong>Cargo:</strong> $e_cargo</p>
                        <p><strong>Motivo:</strong> $e_motivo</p>
                        $bloque_detalle
                        $bloque_recurrente
                        <p><strong>Fechas:</strong> Del $e_fecha_inicio al $e_fecha_fin</p>
                        $linea_horario
                        <p><strong>Notas:</strong> <em>$e_notas</em></p>
                        <div style='background: #f4f4f4; padding: 15px; border-radius: 10px; margin-top: 15px; border: 1px solid #eeeeee;'>
                            <strong style='color: #000000;'>Soportes Adjuntos:</strong><br>
                            $html_adjuntos_email
                        </div>
                        <div style='text-align: center; margin-top: 40px;'>
                            <a href='$url' style='background-color: #FFCD00; color: #000; padding: 18px 30px; text-decoration: none; border-radius: 10px; font-weight: bold; display: inline-block;'>GESTIONAR SOLICITUD</a>
                        </div>
                    </div>
                </div>
            </div>";
        
        $mail->send();
        header("Location: index.php?enviado=1");
        exit();

    } catch (Exception $e) {
        // Registra el error real, muestra mensaje genérico
        error_log("procesar.php error: " . $e->getMessage());
        die("Ocurrió un error al procesar la solicitud. Por favor intenta más tarde.");
    }
}
?>
