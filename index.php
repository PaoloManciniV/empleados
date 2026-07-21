<?php 
// ============================================================
// index.php
// Correcciones aplicadas:
// - htmlspecialchars() en todos los echo de datos de BD/sesión
// - Generación de token CSRF para el formulario de solicitud
// - Filtro de nombre cambiado de = a LIKE para búsqueda flexible
// - Opción "Otros permisos" con casilla de texto libre
// - paleta amarillo cat, fondo blanco, letras negras - paolo
// - cabeceras de columna en forma de pildora ovalada - paolo
// - contadores de pendientes/aprobadas como botones de filtro (solo gestion) - paolo
// - navbar con fondo blanco, logo y botones naranjas - paolo
// ============================================================

include('config.php'); 
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmtUser = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_data = $stmtUser->fetch(PDO::FETCH_ASSOC);

$mi_rol    = $_SESSION['rol'];
$mi_cedula = $user_data['cedula']; 
$mi_nombre = $_SESSION['nombre'];
$mi_correo = $user_data['correo'];

// --- 1. LÓGICA DE MODO DE VISTA ---
$modo_vista = 'gestion'; 
if ($mi_rol == 'empleado') {
    $modo_vista = 'empleado';
} elseif (isset($_GET['modo']) && $_GET['modo'] == 'empleado') {
    $modo_vista = 'empleado';
}

// --- 2. LÓGICA DE FILTROS SQL ---
// Filtros disponibles para TODAS las columnas del panel de gestión.
$where_panel  = "WHERE 1=1";
$params_panel = [];
$filtro_cedula  = $_GET['cedula']        ?? "";
$filtro_fecha   = $_GET['fecha']         ?? "";   // compatibilidad con enlaces antiguos
$filtro_nombre  = $_GET['nombre_buscar'] ?? "";
$filtro_desde   = $_GET['desde']         ?? "";
$filtro_hasta   = $_GET['hasta']         ?? "";
$filtro_estado  = $_GET['estado']        ?? "";
$filtro_motivo  = $_GET['motivo_f']      ?? "";
$filtro_jefe    = $_GET['jefe']          ?? "";
$filtro_gestor  = $_GET['gestor']        ?? "";
$filtro_soporte = $_GET['soporte_f']     ?? "";
$orden_col      = $_GET['orden']         ?? "";
$orden_dir      = strtolower($_GET['dir'] ?? 'desc');

// El filtro antiguo de fecha única sigue funcionando: equivale a desde=hasta
if (!empty($filtro_fecha) && empty($filtro_desde) && empty($filtro_hasta)) {
    $filtro_desde = $filtro_fecha;
    $filtro_hasta = $filtro_fecha;
}

if ($modo_vista == 'gestion') {
    if ($mi_rol == 'jefe') {
        $where_panel    .= " AND s.correo_jefe = ?";
        $params_panel[]  = $mi_correo;
    }
    if (!empty($filtro_cedula)) {
        $where_panel    .= " AND s.cedula LIKE ?";
        $params_panel[]  = "%$filtro_cedula%";
    }
    if (!empty($filtro_desde)) {
        $where_panel    .= " AND s.fecha_inicio >= ?";
        $params_panel[]  = $filtro_desde;
    }
    if (!empty($filtro_hasta)) {
        $where_panel    .= " AND s.fecha_inicio <= ?";
        $params_panel[]  = $filtro_hasta;
    }
    // CORRECCIÓN: usar LIKE en lugar de = para búsqueda flexible por nombre
    if (!empty($filtro_nombre)) {
        $where_panel    .= " AND s.empleado LIKE ?";
        $params_panel[]  = "%$filtro_nombre%";
    }
    if (!empty($filtro_estado)) {
        $where_panel    .= " AND s.estado = ?";
        $params_panel[]  = $filtro_estado;
    }
    if (!empty($filtro_motivo)) {
        $where_panel    .= " AND s.motivo = ?";
        $params_panel[]  = $filtro_motivo;
    }
    // El filtro por jefe solo aplica para admin (el jefe ya ve solo lo suyo)
    if (!empty($filtro_jefe) && $mi_rol == 'admin') {
        $where_panel    .= " AND s.correo_jefe = ?";
        $params_panel[]  = $filtro_jefe;
    }
    if (!empty($filtro_gestor)) {
        $where_panel    .= " AND s.usuario_gestor = ?";
        $params_panel[]  = $filtro_gestor;
    }
    if ($filtro_soporte === 'con') {
        $where_panel    .= " AND s.archivo_soporte IS NOT NULL AND s.archivo_soporte <> ''";
    } elseif ($filtro_soporte === 'sin') {
        $where_panel    .= " AND (s.archivo_soporte IS NULL OR s.archivo_soporte = '')";
    }
}

// --- ORDENAMIENTO por columna (lista blanca, nunca directo del GET) ---
$mapa_orden = [
    'colaborador' => 's.empleado',
    'cedula'      => 's.cedula',
    'motivo'      => 's.motivo',
    'soportes'    => "(s.archivo_soporte IS NOT NULL AND s.archivo_soporte <> '')",
    'fecha'       => 's.fecha_inicio',
    'estado'      => 's.estado',
    'enviado'     => 's.correo_jefe',
    'gestor'      => 's.usuario_gestor',
];
$orden_dir_sql = ($orden_dir === 'asc') ? 'ASC' : 'DESC';
$orden_sql     = isset($mapa_orden[$orden_col])
               ? $mapa_orden[$orden_col] . " " . $orden_dir_sql . ", s.id DESC"
               : "s.id DESC";

/**
 * Construye el enlace de ordenamiento de un encabezado de columna,
 * conservando todos los filtros activos.
 */
function link_orden($col, $etiqueta) {
    global $orden_col, $orden_dir;
    $qs = $_GET;
    $es_actual = ($orden_col === $col);
    $qs['orden'] = $col;
    $qs['dir']   = ($es_actual && $orden_dir === 'asc') ? 'desc' : 'asc';
    // flecha oscura para que se vea sobre la pildora amarilla - paolo
    $flecha = !$es_actual
            ? "<i class='fas fa-sort ms-1' style='opacity:0.35;'></i>"
            : ($orden_dir === 'asc'
                ? "<i class='fas fa-sort-up ms-1' style='color:#1A1A1A;'></i>"
                : "<i class='fas fa-sort-down ms-1' style='color:#1A1A1A;'></i>");
    $url = 'index.php?' . htmlspecialchars(http_build_query($qs), ENT_QUOTES, 'UTF-8');
    // el texto va dentro de una pildora ovalada - paolo
    return "<a href=\"$url\" style=\"color:inherit; text-decoration:none;\" title=\"Ordenar por esta columna\"><span class=\"th-pill\">$etiqueta$flecha</span></a>";
}

// --- 3. LISTA DE EMPLEADOS ---
$empleados_list = [];
if ($modo_vista == 'gestion') {
    if ($mi_rol == 'admin') {
        $empleados_list = $db->query("SELECT DISTINCT nombre_completo FROM usuarios WHERE rol = 'empleado' ORDER BY nombre_completo ASC")->fetchAll();
    } elseif ($mi_rol == 'jefe') {
        $stmtLista = $db->prepare("SELECT DISTINCT empleado as nombre_completo FROM solicitudes WHERE correo_jefe = ? ORDER BY empleado ASC");
        $stmtLista->execute([$mi_correo]);
        $empleados_list = $stmtLista->fetchAll();
    }
}

// --- 3b. LISTAS PARA LOS FILTROS DE COLUMNA ---
// Cada lista se limita a lo que el usuario puede ver (el jefe solo su equipo).
$lista_motivos = $lista_estados = $lista_jefes = $lista_gestores = [];
if ($modo_vista == 'gestion') {
    $scope_sql    = ($mi_rol == 'jefe') ? " AND correo_jefe = ?" : "";
    $scope_params = ($mi_rol == 'jefe') ? [$mi_correo] : [];

    $q = $db->prepare("SELECT DISTINCT motivo FROM solicitudes WHERE motivo IS NOT NULL AND motivo <> '' $scope_sql ORDER BY motivo ASC");
    $q->execute($scope_params);
    $lista_motivos = $q->fetchAll(PDO::FETCH_COLUMN);

    $q = $db->prepare("SELECT DISTINCT estado FROM solicitudes WHERE estado IS NOT NULL AND estado <> '' $scope_sql ORDER BY estado ASC");
    $q->execute($scope_params);
    $lista_estados = $q->fetchAll(PDO::FETCH_COLUMN);

    if ($mi_rol == 'admin') {
        $lista_jefes = $db->query("SELECT DISTINCT correo_jefe FROM solicitudes WHERE correo_jefe IS NOT NULL AND correo_jefe <> '' ORDER BY correo_jefe ASC")->fetchAll(PDO::FETCH_COLUMN);
    }

    $q = $db->prepare("SELECT DISTINCT usuario_gestor FROM solicitudes WHERE usuario_gestor IS NOT NULL AND usuario_gestor <> '' $scope_sql ORDER BY usuario_gestor ASC");
    $q->execute($scope_params);
    $lista_gestores = $q->fetchAll(PDO::FETCH_COLUMN);
}

// --- 3c. CONTADORES DE ESTADO (pendientes / aprobadas) - paolo ---
// solo en modo gestion (jefe/admin); respetan el alcance del panel
// IMPORTANTE: los contadores NO deben depender del filtro de estado,
// asi que rearmamos el WHERE con todos los filtros MENOS el de estado,
// para que al filtrar por "Pendiente" el conteo de "Aprobado" no de 0 - paolo
$cont_pendientes = 0;
$cont_aprobadas  = 0;
if ($modo_vista == 'gestion') {
    $where_cont  = "WHERE 1=1";
    $params_cont = [];

    if ($mi_rol == 'jefe') {
        $where_cont    .= " AND s.correo_jefe = ?";
        $params_cont[]  = $mi_correo;
    }
    if (!empty($filtro_cedula)) {
        $where_cont    .= " AND s.cedula LIKE ?";
        $params_cont[]  = "%$filtro_cedula%";
    }
    if (!empty($filtro_desde)) {
        $where_cont    .= " AND s.fecha_inicio >= ?";
        $params_cont[]  = $filtro_desde;
    }
    if (!empty($filtro_hasta)) {
        $where_cont    .= " AND s.fecha_inicio <= ?";
        $params_cont[]  = $filtro_hasta;
    }
    if (!empty($filtro_nombre)) {
        $where_cont    .= " AND s.empleado LIKE ?";
        $params_cont[]  = "%$filtro_nombre%";
    }
    // ojo: aqui NO va el filtro de estado a proposito - paolo
    if (!empty($filtro_motivo)) {
        $where_cont    .= " AND s.motivo = ?";
        $params_cont[]  = $filtro_motivo;
    }
    if (!empty($filtro_jefe) && $mi_rol == 'admin') {
        $where_cont    .= " AND s.correo_jefe = ?";
        $params_cont[]  = $filtro_jefe;
    }
    if (!empty($filtro_gestor)) {
        $where_cont    .= " AND s.usuario_gestor = ?";
        $params_cont[]  = $filtro_gestor;
    }
    if ($filtro_soporte === 'con') {
        $where_cont    .= " AND s.archivo_soporte IS NOT NULL AND s.archivo_soporte <> ''";
    } elseif ($filtro_soporte === 'sin') {
        $where_cont    .= " AND (s.archivo_soporte IS NULL OR s.archivo_soporte = '')";
    }

    $q = $db->prepare("SELECT estado, COUNT(*) c FROM solicitudes s $where_cont GROUP BY estado");
    $q->execute($params_cont);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['estado'] === 'Pendiente') { $cont_pendientes = (int)$r['c']; }
        if ($r['estado'] === 'Aprobado')  { $cont_aprobadas  = (int)$r['c']; }
    }
}
function obtenerOpcionesHoras() {
    $opciones = []; $periodos = ['AM', 'PM'];
    foreach ($periodos as $p) {
        for ($h = 0; $h < 12; $h++) {
            $horaDisplay = ($h == 0) ? 12 : $h; 
            for ($m = 0; $m < 60; $m += 10) {
                $minutos    = str_pad($m, 2, '0', STR_PAD_LEFT);
                $opciones[] = "$horaDisplay:$minutos $p";
            }
        }
    }
    return $opciones;
}
$listadoHoras = obtenerOpcionesHoras();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agro-Costa | Gestión de Permisos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <style>
        /* paleta amarillo cat, fondo blanco, letras negras - paolo */
        :root {
            --cat-yellow: #FFCD11;        /* amarillo cat principal */
            --cat-yellow-dark: #F7931E;   /* naranja cat para botones y detalles */
            --cat-yellow-soft: #FFE066;   /* amarillo claro */
            --cat-black: #1A1A1A;
            --page-bg: #ffffff;           /* fondo blanco - paolo */
            --soft-gray: #6b6b6b;
        }
        /* fondo blanco plano, sin amarillo - paolo */
        body {
            background-color: #ffffff;
            font-family: -apple-system, sans-serif;
            color: var(--cat-black);
        }
        /* navbar con fondo blanco y borde inferior naranja - paolo */
        .navbar {
            background: #ffffff !important;
            border-bottom: 3px solid var(--cat-yellow-dark);
            box-shadow: 0 4px 18px rgba(0,0,0,0.08);
        }
        .navbar-brand { font-weight: 800; color: var(--cat-black) !important; letter-spacing: 0.5px; }
        /* texto de la cabecera en negro sobre fondo blanco - paolo */
        .navbar .text-white { color: var(--cat-black) !important; }
        /* boton naranja para la barra (reemplaza los antiguos botones blancos) - paolo */
        .btn-nav-naranja {
            background-color: var(--cat-yellow-dark);
            color: #fff !important;
            border: none;
        }
        .btn-nav-naranja:hover { background-color: #e07d0a; color: #fff; }
        /* tarjetas con degradado blanco hacia amarillo muy suave - paolo */
        .card-apple {
            background: linear-gradient(150deg, #ffffff 0%, #FFFDF5 60%, #FFF4C2 100%);
            border: 1px solid #FFE38A;
            border-radius: 22px;
            box-shadow: 0 10px 34px rgba(247,147,30,0.10);
            overflow: hidden;
        }
        /* boton principal en degradado amarillo con letra negra - paolo */
        .btn-cat {
            background: linear-gradient(90deg, var(--cat-yellow-dark) 0%, var(--cat-yellow) 100%);
            color: var(--cat-black);
            border-radius: 12px;
            font-weight: 700;
            border: none;
            padding: 10px 24px;
            transition: 0.3s;
            box-shadow: 0 4px 12px rgba(247,147,30,0.25);
        }
        .btn-cat:hover {
            background: linear-gradient(90deg, var(--cat-yellow) 0%, var(--cat-yellow-soft) 100%);
            color: #000;
        }
        .form-control, .form-select, .select2-container--bootstrap-5 .select2-selection {
            border-radius: 12px !important;
            border: 1px solid #F0D890 !important;
            background-color: #fffdf7 !important;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--cat-yellow-dark) !important;
            box-shadow: 0 0 0 0.25rem rgba(247,147,30,0.15) !important;
        }
        /* cabeceras de columna en forma de pildora ovalada - paolo */
        .table thead th {
            background-color: transparent;   /* la barra corrida se quita - paolo */
            padding: 10px 6px;
            border: none;
            font-size: 0.72rem;
            text-transform: uppercase;
            font-weight: 700;
        }
        /* la pildora es el contenido interno de cada th - paolo */
        .table thead th .th-pill {
            display: inline-block;
            background-color: var(--cat-yellow);
            color: var(--cat-black);
            padding: 8px 16px;
            border-radius: 999px;                       /* forma ovalada - paolo */
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            white-space: nowrap;
        }
        .table td { padding: 18px; border-top: 1px solid #F3E9C4; font-size: 0.85rem; color: var(--cat-black); }
        .badge-status { border-radius: 8px; padding: 6px 12px; font-weight: 600; }
        label { font-weight: 600; font-size: 0.75rem; color: var(--soft-gray); margin-bottom: 5px; margin-left: 5px; }

        /* contadores de estado como botones de filtro - paolo */
        .contador-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            border-radius: 16px;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,0,0,0.10);
            text-decoration: none;                 /* es un enlace <a> - paolo */
            border: 3px solid transparent;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .contador-chip:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
        .contador-chip .num { font-size: 1.6rem; line-height: 1; }
        .contador-chip .txt { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .chip-pendiente { background-color: var(--cat-yellow); color: var(--cat-black); }
        .chip-aprobado  { background-color: #34C759; color: #fff; }
        /* marca el contador activo cuando el filtro de estado coincide - paolo */
        .contador-chip.activo { border-color: #1A1A1A; }

        /* ── RESPONSIVE MOBILE ── */
        @media (max-width: 767px) {
            .container-fluid { padding-left: 16px !important; padding-right: 16px !important; }
            .navbar .container-fluid { padding-left: 16px !important; padding-right: 16px !important; }

            /* Ocultar tabla normal en móvil */
            .table-responsive table thead { display: none; }
            .table-responsive table, 
            .table-responsive tbody, 
            .table-responsive tr, 
            .table-responsive td { display: block; width: 100%; }

            .table-responsive tr {
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 2px 12px rgba(0,0,0,0.06);
                margin-bottom: 14px;
                padding: 14px 16px;
                border: 1px solid #FFE38A;
            }
            .table-responsive td {
                padding: 5px 0 !important;
                border: none !important;
                font-size: 0.82rem;
            }
            .table-responsive td::before {
                content: attr(data-label);
                display: block;
                font-size: 0.65rem;
                font-weight: 700;
                color: var(--soft-gray);
                text-transform: uppercase;
                letter-spacing: 0.04em;
                margin-bottom: 2px;
            }
            .table-responsive td[data-label=""]::before { display: none; }
            .table-responsive td.td-action {
                text-align: left !important;
                padding-top: 10px !important;
                border-top: 1px solid #F3E9C4 !important;
                margin-top: 6px;
            }
        }
    </style>
</head>
<body>

<nav class="navbar sticky-top mb-4 py-3">
    <div class="container-fluid px-5">
        <a class="navbar-brand d-flex align-items-center" href="#">
            <img src="logo.png" alt="Agro-Costa" style="height:84px; width:auto;">
        </a>
        
        <div class="d-flex align-items-center">
            <span class="small me-3 d-none d-md-block">
                Hola, <strong><?php echo htmlspecialchars($mi_nombre, ENT_QUOTES, 'UTF-8'); ?></strong>
                (<?php echo htmlspecialchars(ucfirst($mi_rol), ENT_QUOTES, 'UTF-8'); ?>)
            </span>
            
            <?php if ($mi_rol == 'admin'): ?>
                <a href="dashboard.php" class="btn btn-sm btn-nav-naranja fw-bold me-2" style="border-radius: 10px;">
                    <i class="fas fa-chart-pie me-1"></i> DASHBOARD
                </a>
            <?php endif; ?>

            <?php if ($mi_rol != 'empleado'): ?>
                <?php if ($modo_vista == 'gestion'): ?>
                    <a href="index.php?modo=empleado" class="btn btn-sm btn-nav-naranja fw-bold me-2" style="border-radius: 10px;">
                        <i class="fas fa-user-edit me-1"></i> MI PORTAL EMPLEADO
                    </a>
                <?php else: ?>
                    <a href="index.php" class="btn btn-sm btn-nav-naranja fw-bold me-2" style="border-radius: 10px;">
                        <i class="fas fa-chart-line me-1"></i> VOLVER A GESTIÓN
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <button type="button" class="btn btn-sm me-2" style="border-radius:10px; background-color:#1A1A1A; color:#fff;" data-bs-toggle="modal" data-bs-target="#modalClave">
                <i class="fas fa-key"></i> Clave
            </button>
            <a href="logout.php" class="btn btn-sm px-3" style="border-radius: 10px; background-color:#C62828; color:#fff;">SALIR</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-5">
    
    <div class="mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <?php if ($modo_vista == 'empleado'): ?>
                <h4 class="fw-bold mb-1"><i class="fas fa-user-circle me-2" style="color:var(--cat-yellow-dark);"></i> Mis Solicitudes Personales</h4>
                <p class="text-muted small mb-0">Aquí puedes crear y ver el estado de tus propios permisos.</p>
            <?php else: ?>
                <h4 class="fw-bold mb-1"><i class="fas fa-users-cog me-2" style="color:var(--cat-yellow-dark);"></i> Panel de Gestión</h4>
                <p class="text-muted small mb-0">Administra las solicitudes de tu equipo.</p>
            <?php endif; ?>
        </div>
        <?php if ($modo_vista == 'gestion'):
            // arma la URL de cada contador conservando los filtros activos - paolo
            $qs_pend = $_GET; $qs_pend['estado'] = 'Pendiente';
            $qs_aprob = $_GET; $qs_aprob['estado'] = 'Aprobado';
            $url_pend  = 'index.php?' . htmlspecialchars(http_build_query($qs_pend),  ENT_QUOTES, 'UTF-8');
            $url_aprob = 'index.php?' . htmlspecialchars(http_build_query($qs_aprob), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="d-flex gap-3">
            <a href="<?php echo $url_pend; ?>" class="contador-chip chip-pendiente <?php echo ($filtro_estado === 'Pendiente') ? 'activo' : ''; ?>" title="Ver solo pendientes">
                <span class="num"><?php echo (int)$cont_pendientes; ?></span>
                <span class="txt">Pendientes</span>
            </a>
            <a href="<?php echo $url_aprob; ?>" class="contador-chip chip-aprobado <?php echo ($filtro_estado === 'Aprobado') ? 'activo' : ''; ?>" title="Ver solo aprobadas">
                <span class="num"><?php echo (int)$cont_aprobadas; ?></span>
                <span class="txt">Aprobadas</span>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        
        <?php if ($modo_vista == 'empleado'): ?>
        <div class="col-lg-4">
            <div class="card card-apple p-4">
                <h5 class="fw-bold mb-4">Nueva Solicitud</h5>
                <form action="procesar.php" method="POST" enctype="multipart/form-data" onsubmit="return validarArchivos()">
                    <!-- Token CSRF -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="cedula"   value="<?php echo htmlspecialchars($mi_cedula,             ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="empleado" value="<?php echo htmlspecialchars($mi_nombre,             ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="cargo"    value="<?php echo htmlspecialchars($user_data['cargo'],    ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="mb-3">
                        <label>TIPO DE PERMISO</label>
                        <select name="motivo" id="selectMotivo" class="form-select" onchange="validarRequerido()" required>
                            <option value="">Seleccionar...</option>
                            <option value="Permiso Remunerado">Permiso Remunerado</option>
                            <option value="Cita Médica">Cita Médica (Soporte Obligatorio)</option>
                            <option value="Compensatorio">Compensatorio (Soporte Obligatorio)</option>
                            <option value="Obligaciones Escolares">Obligaciones Escolares (Soporte Obligatorio)</option>  
                            <option value="Jurado de votacion">Jurado de votacion (Soporte Obligatorio)</option>
                            <option value="Citación Judicial - Administrativo">Citación Judicial - Administrativo (Soporte Obligatorio)</option>
                            <option value="Licencia por Luto">Licencia por Luto (Soporte Obligatorio)</option>
                            <option value="Día de la Familia">Día de la Familia</option>
                            <option value="Día del cumpleaños">Día del cumpleaños</option>
                            <option value="Vacaciones">Vacaciones</option>
                            <option value="Licencia No Remunerada">Licencia No Remunerada</option>
                            <option value="Calamidad Doméstica">Calamidad Doméstica</option>
                            <option value="Otros">Permiso excepcional</option>
                        </select>
                    </div>

                    <!-- Campo de texto libre para "Permiso excepcional" -->
                    <!-- CORRECCIÓN: ahora tiene name="detalle_permiso" para que SÍ se envíe al backend
                         y se resalta en amarillo para que el empleado no lo pase por alto. -->
                    <div class="mb-3" id="contenedorOtro" style="display:none;">
                        <label class="fw-bold" style="color:#8a6d00;">
                            &#9888; ESPECIFIQUE EL PERMISO <span style="color:#c00;">*</span>
                        </label>
                        <input type="text" name="detalle_permiso" id="otroPermiso"
                               class="form-control"
                               style="background-color:#FFF7CC; border:2px solid #FFCD11; font-weight:600;"
                               placeholder="Escriba aquí el tipo de permiso..." maxlength="255">
                        <small class="d-block mt-1" style="color:#8a6d00;">
                            Este texto se enviará junto con la solicitud a Recursos Humanos.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label>JEFE (CORREO)</label>
                        <input type="email" name="correo_jefe" class="form-control" placeholder="jefe@agro-costa.com" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label>FECHA INICIO</label><input type="date" name="fecha_inicio" class="form-control" required></div>
                        <div class="col-6"><label>FECHA FIN</label><input type="date" name="fecha_fin" class="form-control" required></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label>HORA INICIO</label>
                            <select name="hora_inicio" class="form-select select2-time">
                                <option value="">Día completo</option>
                                <?php foreach ($listadoHoras as $hora): ?>
                                    <option value="<?php echo htmlspecialchars($hora, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($hora, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label>HORA FIN</label>
                            <select name="hora_fin" class="form-select select2-time">
                                <option value="">Día completo</option>
                                <?php foreach ($listadoHoras as $hora): ?>
                                    <option value="<?php echo htmlspecialchars($hora, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($hora, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>NOTAS</label>
                        <textarea name="notas" class="form-control" rows="2" placeholder="Opcional..."></textarea>
                    </div>
                    
                    <div class="mb-4">
                        <label>SOPORTES (HASTA 5 ARCHIVOS)</label>
                        <div class="p-3 rounded" style="background-color:#FFFBEA; border:1px solid #FFE38A;">
                            <div class="mb-2"><input type="file" name="soporte[]" class="form-control form-control-sm input-soporte"></div>
                            <div class="mb-2"><input type="file" name="soporte[]" class="form-control form-control-sm input-soporte"></div>
                            <div class="mb-2"><input type="file" name="soporte[]" class="form-control form-control-sm input-soporte"></div>
                            <div class="mb-2"><input type="file" name="soporte[]" class="form-control form-control-sm input-soporte"></div>
                            <div class="mb-2"><input type="file" name="soporte[]" class="form-control form-control-sm input-soporte"></div>
                        </div>
                        <span id="asterisco" class="text-danger small fw-bold" style="display:none;">* Soporte Requerido</span>
                        <div id="resumenArchivos" class="mt-2 small text-muted"></div>
                    </div>

                    <button type="submit" class="btn btn-cat w-100 py-3">ENVIAR SOLICITUD</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?php echo ($modo_vista == 'empleado') ? 'col-lg-8' : 'col-12'; ?>">
            
            <?php if ($modo_vista == 'gestion'): ?>
            <div class="card card-apple p-4 mb-4">
                <form method="GET" id="formFiltros" class="row g-3 align-items-end">
                    <?php if (!empty($orden_col)): ?>
                        <!-- Conserva el ordenamiento activo al filtrar -->
                        <input type="hidden" name="orden" value="<?php echo htmlspecialchars($orden_col, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="dir"   value="<?php echo htmlspecialchars($orden_dir, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                    <div class="col-md-3">
                        <label>COLABORADOR</label>
                        <select name="nombre_buscar" id="selNombre" class="form-select select2">
                            <option value="">Todos</option>
                            <?php foreach ($empleados_list as $e): ?>
                                <option value="<?php echo htmlspecialchars($e['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php if ($filtro_nombre == $e['nombre_completo']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($e['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>CÉDULA</label>
                        <input type="text" name="cedula" class="form-control" value="<?php echo htmlspecialchars($filtro_cedula, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label>MOTIVO</label>
                        <select name="motivo_f" class="form-select" onchange="this.form.submit()">
                            <option value="">Todos</option>
                            <?php foreach ($lista_motivos as $m): ?>
                                <option value="<?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($filtro_motivo === $m) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>ESTADO</label>
                        <select name="estado" class="form-select" onchange="this.form.submit()">
                            <option value="">Todos</option>
                            <?php foreach ($lista_estados as $es): ?>
                                <option value="<?php echo htmlspecialchars($es, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($filtro_estado === $es) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($es, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>SOPORTES</label>
                        <select name="soporte_f" class="form-select" onchange="this.form.submit()">
                            <option value="">Todos</option>
                            <option value="con" <?php if ($filtro_soporte === 'con') echo 'selected'; ?>>Con soporte</option>
                            <option value="sin" <?php if ($filtro_soporte === 'sin') echo 'selected'; ?>>Sin soporte</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label>DESDE (F. INICIO)</label>
                        <input type="date" name="desde" class="form-control" value="<?php echo htmlspecialchars($filtro_desde, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label>HASTA (F. INICIO)</label>
                        <input type="date" name="hasta" class="form-control" value="<?php echo htmlspecialchars($filtro_hasta, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <?php if ($mi_rol == 'admin'): ?>
                    <div class="col-md-3">
                        <label>ENVIADO A (JEFE)</label>
                        <select name="jefe" class="form-select" onchange="this.form.submit()">
                            <option value="">Todos</option>
                            <?php foreach ($lista_jefes as $j): ?>
                                <option value="<?php echo htmlspecialchars($j, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($filtro_jefe === $j) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($j, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <label>GESTOR</label>
                        <select name="gestor" class="form-select" onchange="this.form.submit()">
                            <option value="">Todos</option>
                            <?php foreach ($lista_gestores as $g): ?>
                                <option value="<?php echo htmlspecialchars($g, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($filtro_gestor === $g) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($g, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="<?php echo ($mi_rol == 'admin') ? 'col-md-3' : 'col-md-6'; ?> d-flex gap-2">
                        <button type="submit" class="btn btn-cat flex-fill">BUSCAR</button>
                        <a href="index.php" class="btn border flex-fill text-center d-flex align-items-center justify-content-center fw-bold" style="border-radius:12px; background-color:#fff; color:#111;">LIMPIAR</a>
                        <?php if ($mi_rol == 'admin'): ?>
                            <a href="exportar.php?<?php echo htmlspecialchars(http_build_query($_GET), ENT_QUOTES, 'UTF-8'); ?>" class="btn d-flex align-items-center justify-content-center" style="border-radius:12px; min-width: 50px; background-color:#1E7E34; color:#fff;" title="Exportar a Excel (respeta los filtros aplicados)">
                                <i class="fas fa-file-excel"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="card card-apple p-4">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <?php if ($modo_vista == 'gestion'): ?>
                                    <th><?php echo link_orden('colaborador', 'Colaborador'); ?></th>
                                    <th><?php echo link_orden('cedula', 'Cédula'); ?></th>
                                    <th><?php echo link_orden('motivo', 'Motivo'); ?></th>
                                    <th class="text-center" style="width: 25%;"><?php echo link_orden('soportes', 'Soportes'); ?></th>
                                    <th><?php echo link_orden('fecha', 'Fecha / Horario'); ?></th>
                                    <th><?php echo link_orden('estado', 'Estado'); ?></th>
                                    <th><?php echo link_orden('enviado', 'Enviado a'); ?></th>
                                    <th><?php echo link_orden('gestor', 'Gestor'); ?></th>
                                <?php else: ?>
                                    <th><span class="th-pill">Motivo</span></th>
                                    <th class="text-center" style="width: 25%;"><span class="th-pill">Soportes</span></th>
                                    <th><span class="th-pill">Fecha / Horario</span></th>
                                    <th><span class="th-pill">Estado</span></th>
                                    <th><span class="th-pill">Enviado a</span></th>
                                    <th><span class="th-pill">Gestor</span></th>
                                <?php endif; ?>
                                <th class="text-end"><span class="th-pill">Acción</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_final = "SELECT s.* FROM solicitudes s ";

                            if ($modo_vista == 'empleado') {
                                $stmt = $db->prepare($sql_final . "WHERE s.cedula = ? ORDER BY s.id DESC");
                                $stmt->execute([$mi_cedula]);
                            } else {
                                $stmt = $db->prepare($sql_final . "$where_panel ORDER BY $orden_sql");
                                $stmt->execute($params_panel);
                            }

                            $filas_resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <?php if ($modo_vista == 'gestion'): ?>
                            <tr>
                                <td colspan="9" style="border-top:none; padding: 0 18px 10px 18px;">
                                    <span class="small text-muted fw-bold">
                                        <i class="fas fa-filter me-1" style="color:var(--cat-yellow-dark);"></i>
                                        <?php echo count($filas_resultado); ?> solicitud(es) encontrada(s)
                                    </span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($filas_resultado as $row):
                                $color = ($row['estado']=='Aprobado') ? '#34C759' : (($row['estado']=='Rechazado') ? '#FF3B30' : '#FFCD11');
                                $txt   = ($row['estado']=='Pendiente') ? '#000' : '#fff';
                                $h_ini_val   = trim($row['hora_inicio']);
                                $horario_txt = (empty($h_ini_val) || $h_ini_val == '00:00:00' || $h_ini_val == '0:00') ? "Día completo" : $row['hora_inicio']." - ".$row['hora_fin'];
                                
                                $ip_audit    = htmlspecialchars($row['ip_aprobacion']   ?? 'No registrada',  ENT_QUOTES, 'UTF-8');
                                $disp_audit  = htmlspecialchars($row['info_dispositivo'] ?? 'No registrado', ENT_QUOTES, 'UTF-8');
                                $fecha_audit = htmlspecialchars($row['fecha_gestion']    ?? 'No registrada', ENT_QUOTES, 'UTF-8');
                                $fecha_envio = htmlspecialchars($row['fecha_solicitud']  ?? 'No registrada', ENT_QUOTES, 'UTF-8');
                                $correo_jefe_dest = htmlspecialchars($row['correo_jefe'] ?? 'No registrado', ENT_QUOTES, 'UTF-8');
                                
                                $archivos = [];
                                if (!empty($row['archivo_soporte'])) {
                                    $archivos = explode(',', $row['archivo_soporte']);
                                }
                            ?>
                            <tr>
                                <?php if ($modo_vista == 'gestion'): ?> 
                                    <td data-label="Colaborador" class="fw-bold"><?php echo htmlspecialchars($row['empleado'], ENT_QUOTES, 'UTF-8'); ?></td> 
                                    <td data-label="Cédula" class="text-secondary fw-bold"><?php echo htmlspecialchars($row['cedula'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <td data-label="Motivo" class="small fw-medium">
                                    <?php echo htmlspecialchars($row['motivo'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($row['detalle_permiso'])): ?>
                                        <span class="d-inline-block mt-1 px-2 py-1"
                                              style="background-color:#FFF7CC; border:1px solid #FFCD11; border-radius:6px; color:#8a6d00; font-size:0.75rem;">
                                            &#9888; <?php echo htmlspecialchars($row['detalle_permiso'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Soportes" class="small">
                                    <?php if (count($archivos) > 0): ?>
                                        <?php foreach ($archivos as $archivo): 
                                            $archivo_full  = trim($archivo);
                                            $partes        = explode('__', $archivo_full);
                                            $nombre_bonito = htmlspecialchars(end($partes), ENT_QUOTES, 'UTF-8');
                                        ?>
                                            <a href="uploads/<?php echo rawurlencode($archivo_full); ?>" target="_blank" class="text-decoration-none d-block mb-1 text-truncate" style="max-width: 250px;" title="<?php echo $nombre_bonito; ?>">
                                                <i class="fas fa-paperclip text-secondary"></i> <?php echo $nombre_bonito; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php else: ?> 
                                        <span class="text-muted">-</span> 
                                    <?php endif; ?>
                                </td>
                                <td data-label="Fecha / Horario" class="small">
                                    <strong><?php echo htmlspecialchars($row['fecha_inicio'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    al
                                    <strong><?php echo htmlspecialchars($row['fecha_fin'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                    <span class="text-muted"><?php echo htmlspecialchars($horario_txt, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td data-label="Estado">
                                    <span class="badge badge-status" style="background-color:<?php echo $color; ?>; color:<?php echo $txt; ?>;">
                                        <?php echo htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td data-label="Enviado a" class="small text-muted">
                                    <i class="fas fa-envelope me-1"></i><?php echo $correo_jefe_dest; ?>
                                </td>
                                <td data-label="Gestor" class="small text-muted">
                                    <?php 
                                        if (!empty($row['usuario_gestor'])) {
                                            echo '<i class="fas fa-user-check"></i> ' . htmlspecialchars($row['usuario_gestor'], ENT_QUOTES, 'UTF-8');
                                        } elseif ($row['estado'] != 'Pendiente') {
                                            echo 'Sistema';
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </td>
                                <td data-label="" class="text-end td-action">
                                    <?php if ($modo_vista == 'gestion'): ?>
                                        <?php if ($row['estado'] == 'Pendiente'): ?>
                                            <a href="gestionar.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-cat btn-sm py-1 px-3">GESTIONAR</a>
                                        <?php elseif (!empty($row['ip_aprobacion'])): ?>
                                            <button class="btn btn-sm" style="border:1px solid #1A1A1A; color:#1A1A1A; background:#fff;"
                                                    onclick='verAuditoria("<?php echo $ip_audit; ?>", "<?php echo $disp_audit; ?>", "<?php echo htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8'); ?>", "<?php echo $fecha_audit; ?>", "<?php echo $correo_jefe_dest; ?>", "<?php echo $fecha_envio; ?>")'
                                                    title="Ver Auditoría">
                                                <i class="fas fa-fingerprint"></i>
                                            </button>
                                        <?php else: ?> 
                                            <i class="fas fa-check-double text-muted small"></i> 
                                        <?php endif; ?>
                                    
                                    <?php elseif ($modo_vista == 'empleado' && $row['estado'] == 'Pendiente'): ?>
                                        <form method="POST" action="eliminar.php" style="display:inline;"
                                              onsubmit="return confirm('¿Estás seguro de que quieres eliminar esta solicitud?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="id"         value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar Solicitud">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <i class="fas fa-lock text-muted small"></i>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Cambiar Clave -->
<div class="modal fade" id="modalClave" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 20px; border:none; overflow:hidden;">
            <div class="modal-header" style="background: linear-gradient(90deg, var(--cat-yellow-dark) 0%, var(--cat-yellow) 100%); color:#1A1A1A; border-bottom: 4px solid #1A1A1A;">
                <h5 class="modal-title fw-bold">Cambiar Contraseña</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form action="cambiar_clave.php" method="POST">
                    <!-- Token CSRF -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-3"><label class="fw-bold small text-muted">CONTRASEÑA ACTUAL</label><input type="password" name="clave_actual" class="form-control" required></div>
                    <div class="mb-3"><label class="fw-bold small text-muted">NUEVA CONTRASEÑA</label><input type="password" name="clave_nueva" class="form-control" required minlength="8"></div>
                    <div class="mb-4"><label class="fw-bold small text-muted">CONFIRMAR NUEVA</label><input type="password" name="clave_confirmar" class="form-control" required minlength="8"></div>
                    <button type="submit" class="btn btn-cat w-100 fw-bold">ACTUALIZAR</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Auditoría -->
<div class="modal fade" id="modalAudit" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border:none; overflow:hidden;">
            <div class="modal-header" style="background: linear-gradient(90deg, var(--cat-yellow-dark) 0%, var(--cat-yellow) 100%); color: #1A1A1A; border-bottom: 4px solid #1A1A1A;">
                <h5 class="modal-title fw-bold"><i class="fas fa-shield-alt me-2"></i> AUDITORÍA </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="row mb-4">
                    <div class="col-6">
                         <p class="mb-1 fw-bold text-muted small">ENVIADO A (JEFE)</p>
                         <div class="p-2 rounded small text-break" style="background-color:#FFFBEA; border:1px solid #FFE38A;">
                            <i class="fas fa-envelope text-secondary me-1"></i> <span id="auditDestino" class="fw-bold"></span>
                         </div>
                    </div>
                    <div class="col-6">
                         <p class="mb-1 fw-bold text-muted small">FECHA DE ENVÍO</p>
                         <div class="p-2 rounded small" style="background-color:#FFFBEA; border:1px solid #FFE38A;">
                            <i class="fas fa-calendar-alt text-secondary me-1"></i> <span id="auditFechaEnvio" class="fw-bold"></span>
                         </div>
                    </div>
                </div>
                <hr>
                <p class="mb-1 fw-bold text-muted small">ESTADO FINAL</p>
                <h3 id="auditEstado" class="fw-bold mb-4"></h3>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 rounded h-100" style="background-color:#FFFBEA; border:1px solid #FFE38A;">
                            <i class="fas fa-network-wired fa-lg mb-2" style="color:var(--cat-yellow-dark);"></i>
                            <p class="mb-0 small fw-bold text-muted">IP APROBACIÓN</p>
                            <span id="auditIP" class="fw-bold text-dark"></span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded h-100" style="background-color:#FFFBEA; border:1px solid #FFE38A;">
                            <i class="fas fa-clock fa-lg mb-2" style="color:var(--cat-yellow-dark);"></i>
                            <p class="mb-0 small fw-bold text-muted">FECHA APROBACIÓN</p>
                            <span id="auditFecha" class="fw-bold text-dark" style="font-size: 0.8rem;"></span>
                        </div>
                    </div>
                </div>
                <div class="mt-3 p-3 rounded" style="background-color:#FFFBEA; border:1px solid #FFE38A;">
                    <i class="fas fa-laptop fa-lg mb-2" style="color:var(--cat-yellow-dark);"></i>
                    <p class="mb-0 small fw-bold text-muted">DISPOSITIVO GESTOR</p>
                    <h5 id="auditDispBonito" class="fw-bold text-dark mb-1"></h5>
                    <small id="auditDispRaw" class="text-muted" style="font-size: 0.65rem;"></small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('#selNombre, .select2-time').select2({ theme: 'bootstrap-5' });
        $('.input-soporte').on('change', function() { actualizarResumen(); });

        // Al enviar el filtro de colaborador con select2, aplicar de una vez
        $('#selNombre').on('change', function() {
            var f = document.getElementById('formFiltros');
            if (f) f.submit();
        });

        // La fecha fin nunca puede quedar antes de la fecha inicio
        var inpIni = document.querySelector('input[name="fecha_inicio"]');
        var inpFin = document.querySelector('input[name="fecha_fin"]');
        if (inpIni && inpFin) {
            inpIni.addEventListener('change', function() {
                inpFin.min = inpIni.value;
                if (inpFin.value && inpFin.value < inpIni.value) {
                    inpFin.value = inpIni.value;
                }
            });
        }
    });

    function validarRequerido() {
        var m   = document.getElementById('selectMotivo').value;
        var req = ['Cita Médica', 'Compensatorio', 'Obligaciones Escolares', 'Citación Judicial - Administrativo', 'Licencia por Luto'];
        var isReq = req.includes(m);
        document.getElementById('asterisco').style.display = isReq ? 'block' : 'none';

        // Mostrar u ocultar el campo de texto cuando se elige "Otros permisos"
        var contenedorOtro = document.getElementById('contenedorOtro');
        var otroInput      = document.getElementById('otroPermiso');
        if (m === 'Otros') {
            contenedorOtro.style.display = 'block';
        } else {
            contenedorOtro.style.display = 'none';
            otroInput.value = '';
        }
    }

    function actualizarResumen() {
        var inputs = document.querySelectorAll('.input-soporte');
        var lista  = document.getElementById('resumenArchivos');
        lista.innerHTML = ""; 
        var ul = document.createElement('ul'); ul.style.listStyleType = "none"; ul.style.paddingLeft = "0";
        var hayArchivos = false;
        inputs.forEach(function(input, index) {
            if (input.files && input.files[0]) {
                hayArchivos = true;
                var file = input.files[0];
                var li   = document.createElement('li');
                li.innerHTML = '<span class="badge bg-secondary me-2">#' + (index+1) + '</span>'
                             + '<i class="fas fa-paperclip me-1" style="color:#F7931E;"></i> '
                             + file.name
                             + ' <span class="text-secondary small">(' + (file.size/1024/1024).toFixed(2) + ' MB)</span>';
                ul.appendChild(li);
            }
        });
        if (hayArchivos) { lista.appendChild(ul); }
    }

    function validarArchivos() {
        // --- VALIDACIÓN DE FECHAS: la fecha fin no puede ser anterior a la de inicio ---
        var fIni = document.querySelector('input[name="fecha_inicio"]').value;
        var fFin = document.querySelector('input[name="fecha_fin"]').value;
        if (fIni && fFin && fFin < fIni) {
            alert("La FECHA FIN (" + fFin + ") no puede ser anterior a la FECHA INICIO (" + fIni + ").\n\nPor favor corrige las fechas.");
            return false;
        }

        var inputs = document.querySelectorAll('.input-soporte');
        var alguno = false;
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].files && inputs[i].files[0]) {
                alguno = true;
                if (inputs[i].files[0].size > 26214400) {
                    alert("El archivo en la casilla #" + (i+1) + " pesa más de 25MB.");
                    return false;
                }
            }
        }
        var selMotivo = document.getElementById('selectMotivo');
        var m   = selMotivo.value;
        var req = ['Cita Médica', 'Compensatorio', 'Obligaciones Escolares', 'Citación Judicial - Administrativo', 'Licencia por Luto'];
        if (req.includes(m) && !alguno) {
            alert("Para este motivo es OBLIGATORIO subir al menos un soporte.");
            return false;
        }

        // Validar que el permiso excepcional tenga su detalle escrito.
        // IMPORTANTE: ya NO sobreescribimos el valor del <select>.
        // El motivo viaja como 'Otros' (el backend lo guarda como
        // "Permiso excepcional") y el texto escrito viaja por su propio
        // campo name="detalle_permiso", así nunca se pierde.
        if (m === 'Otros') {
            var otroTexto = document.getElementById('otroPermiso').value.trim();
            if (otroTexto === '') {
                alert("Escribe el tipo de permiso en la casilla resaltada en amarillo.");
                document.getElementById('otroPermiso').focus();
                return false;
            }
        }

        return true;
    }

    function analizarDispositivo(ua) {
        var tipo = "PC"; var icono = "fa-desktop"; 
        if (/iPhone/i.test(ua))       { tipo = "Celular (iPhone)";   icono = "fa-mobile-alt"; }
        else if (/Android/i.test(ua)) { tipo = "Celular (Android)";  icono = "fa-mobile-alt"; }
        else if (/iPad/i.test(ua) || /Tablet/i.test(ua)) { tipo = "Tablet"; icono = "fa-tablet-alt"; }
        else if (/Mobile/i.test(ua))  { tipo = "Celular Genérico";   icono = "fa-mobile-alt"; } 
        else {
            var nombre = "PC / Laptop";
            if (/Windows/i.test(ua))   nombre = "PC Windows";
            else if (/Macintosh/i.test(ua)) nombre = "Mac";
            else if (/Linux/i.test(ua))     nombre = "Linux PC";
            tipo = nombre;
        }
        var browser = "";
        if (/Edg/i.test(ua))                               browser = "Edge";
        else if (/Chrome/i.test(ua) && !/Edg/i.test(ua))  browser = "Chrome";
        else if (/Safari/i.test(ua) && !/Chrome/i.test(ua)) browser = "Safari";
        else if (/Firefox/i.test(ua))                      browser = "Firefox";
        return { texto: tipo + (browser ? " - " + browser : ""), icono: icono };
    }

    function verAuditoria(ip, dispositivo, estado, fecha, destinatario, fechaEnvio) {
        document.getElementById('auditIP').innerText        = ip;
        document.getElementById('auditFecha').innerText     = fecha       ? fecha       : 'Sin registro';
        document.getElementById('auditDispRaw').innerText   = dispositivo;
        document.getElementById('auditDestino').innerText   = destinatario ? destinatario : 'No registrado';
        document.getElementById('auditFechaEnvio').innerText = fechaEnvio  ? fechaEnvio  : 'No registrada';

        var elEstado      = document.getElementById('auditEstado');
        elEstado.innerText    = estado;
        elEstado.style.color  = (estado === 'Aprobado') ? '#34C759' : '#FF3B30';

        var info = analizarDispositivo(dispositivo);
        document.getElementById('auditDispBonito').innerText = info.texto;
        
        var iconoContainer = document.querySelector('#modalAudit .fa-laptop, #modalAudit .fa-mobile-alt, #modalAudit .fa-tablet-alt, #modalAudit .fa-desktop');
        if (iconoContainer) { iconoContainer.className = "fas " + info.icono + " fa-2x mb-2"; iconoContainer.style.color = "#F7931E"; }

        var myModal = new bootstrap.Modal(document.getElementById('modalAudit'));
        myModal.show();
    }
</script>
</body>
</html>
