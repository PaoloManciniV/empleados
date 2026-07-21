<?php
// ============================================================
// dashboard.php — Panel de estadísticas (SOLO ADMIN)
// ------------------------------------------------------------
// Muestra: totales por estado, tasa de aprobación, tiempo de
// respuesta, días aprobados, distribución por tipo de permiso,
// por jefe, por empleado, serie temporal (día / mes / año) y
// distribución por día de la semana.
// Todos los bloques obedecen la misma fila de filtros superior.
// ============================================================

date_default_timezone_set('America/Bogota');
include('config.php');

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (($_SESSION['rol'] ?? '') !== 'admin') {
    die("<div style='padding:50px; text-align:center; font-family:sans-serif;'>
            <h1>ACCESO DENEGADO</h1>
            <p>El dashboard de estadísticas es exclusivo para administradores.</p>
            <a href='index.php'>Volver al inicio</a>
         </div>");
}

$mi_nombre = $_SESSION['nombre'];

// ── FILTROS (una sola fila; todos los bloques los obedecen) ──
$hay_filtros_get = isset($_GET['desde']) || isset($_GET['hasta']) || isset($_GET['motivo_f'])
                || isset($_GET['jefe'])  || isset($_GET['estado']);

if ($hay_filtros_get) {
    $f_desde  = $_GET['desde']    ?? '';
    $f_hasta  = $_GET['hasta']    ?? '';
} else {
    // Primer ingreso sin filtros: por defecto el AÑO ACTUAL
    $f_desde  = date('Y') . '-01-01';
    $f_hasta  = date('Y-m-d');
}
$f_motivo = $_GET['motivo_f'] ?? '';
$f_jefe   = $_GET['jefe']     ?? '';
$f_estado = $_GET['estado']   ?? '';

$w = "WHERE 1=1";
$p = [];
if (!empty($f_desde))  { $w .= " AND s.fecha_inicio >= ?"; $p[] = $f_desde; }
if (!empty($f_hasta))  { $w .= " AND s.fecha_inicio <= ?"; $p[] = $f_hasta; }
if (!empty($f_motivo)) { $w .= " AND s.motivo = ?";        $p[] = $f_motivo; }
if (!empty($f_jefe))   { $w .= " AND s.correo_jefe = ?";   $p[] = $f_jefe; }
if (!empty($f_estado)) { $w .= " AND s.estado = ?";        $p[] = $f_estado; }

function consultar($db, $sql, $params) {
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── 1. TOTALES POR ESTADO ──
$por_estado = consultar($db, "SELECT estado, COUNT(*) c FROM solicitudes s $w GROUP BY estado", $p);
$tot = ['Aprobado' => 0, 'Pendiente' => 0, 'Rechazado' => 0];
$total_solicitudes = 0;
foreach ($por_estado as $r) {
    $total_solicitudes += (int)$r['c'];
    if (isset($tot[$r['estado']])) { $tot[$r['estado']] = (int)$r['c']; }
}
$gestionadas    = $tot['Aprobado'] + $tot['Rechazado'];
$tasa_aprobacion = $gestionadas > 0 ? round($tot['Aprobado'] * 100 / $gestionadas) : null;

// ── 2. POR TIPO DE PERMISO (motivo) ──
$por_motivo = consultar($db, "
    SELECT motivo, COUNT(*) total,
           SUM(estado='Aprobado')  a,
           SUM(estado='Pendiente') pe,
           SUM(estado='Rechazado') r
    FROM solicitudes s $w
    GROUP BY motivo ORDER BY total DESC", $p);

// ── 3. POR JEFE ──
$por_jefe = consultar($db, "
    SELECT correo_jefe, COUNT(*) total,
           SUM(estado='Aprobado')  a,
           SUM(estado='Pendiente') pe,
           SUM(estado='Rechazado') r
    FROM solicitudes s $w
    GROUP BY correo_jefe ORDER BY total DESC", $p);

// Mapa correo→nombre para mostrar el nombre del jefe (sin JOIN para evitar
// duplicados: en usuarios puede haber correos repetidos)
$mapa_nombres = [];
foreach ($db->query("SELECT correo, nombre_completo FROM usuarios WHERE correo IS NOT NULL AND correo <> ''") as $u) {
    $k = mb_strtolower(trim($u['correo']));
    if (!isset($mapa_nombres[$k])) { $mapa_nombres[$k] = $u['nombre_completo']; }
}

// ── 4. TOP 10 EMPLEADOS ──
// Agrupado SOLO por cédula: si el mismo empleado aparece con variantes del
// nombre (mayúsculas, nombre corto), cuenta como una sola persona.
$por_empleado = consultar($db, "
    SELECT MAX(empleado) empleado, cedula, COUNT(*) total,
           SUM(estado='Aprobado')  a,
           SUM(estado='Pendiente') pe,
           SUM(estado='Rechazado') r,
           SUM(CASE WHEN estado='Aprobado' THEN GREATEST(DATEDIFF(fecha_fin, fecha_inicio)+1, 1) ELSE 0 END) dias_aprobados
    FROM solicitudes s $w
    GROUP BY cedula ORDER BY total DESC LIMIT 10", $p);

// ── 5. SERIES TEMPORALES (día / mes / año) sobre fecha de inicio ──
$serie_dia  = consultar($db, "SELECT DATE_FORMAT(fecha_inicio, '%Y-%m-%d') k, COUNT(*) c FROM solicitudes s $w GROUP BY k ORDER BY k", $p);
$serie_mes  = consultar($db, "SELECT DATE_FORMAT(fecha_inicio, '%Y-%m') k, COUNT(*) c FROM solicitudes s $w GROUP BY k ORDER BY k", $p);
$serie_anio = consultar($db, "SELECT DATE_FORMAT(fecha_inicio, '%Y') k, COUNT(*) c FROM solicitudes s $w GROUP BY k ORDER BY k", $p);

/**
 * Rellena con ceros los períodos sin solicitudes, para que la línea de
 * tiempo sea honesta: un mes sin permisos debe verse en 0, no desaparecer.
 */
function rellenar_serie($filas, $formato, $intervalo, $desde, $hasta) {
    if (empty($filas) ) { return ['labels' => [], 'valores' => []]; }
    $mapa = [];
    foreach ($filas as $f) { $mapa[$f['k']] = (int)$f['c']; }
    $claves = array_keys($mapa);
    // Límites: el rango filtrado si existe; si no, del primer al último dato
    $ini_str = !empty($desde) ? $desde : $claves[0];
    $fin_str = !empty($hasta) ? $hasta : end($claves);
    try {
        $ini = new DateTime(substr($ini_str, 0, 10) ?: $ini_str);
        $fin = new DateTime(substr($fin_str, 0, 10) ?: $fin_str);
        // Normalizar al inicio del período (mes o año) para el formato dado
        if ($formato === 'Y-m') { $ini->modify('first day of this month'); $fin->modify('first day of this month'); }
        if ($formato === 'Y')   { $ini->setDate((int)$ini->format('Y'), 1, 1); $fin->setDate((int)$fin->format('Y'), 1, 1); }
    } catch (Exception $e) {
        return ['labels' => array_keys($mapa), 'valores' => array_values($mapa)];
    }
    $labels = []; $valores = [];
    $seguridad = 0;
    while ($ini <= $fin && $seguridad < 4000) {
        $k = $ini->format($formato);
        $labels[]  = $k;
        $valores[] = $mapa[$k] ?? 0;
        $ini->modify($intervalo);
        $seguridad++;
    }
    return ['labels' => $labels, 'valores' => $valores];
}

$serie_dia_full  = rellenar_serie($serie_dia,  'Y-m-d', '+1 day',   $f_desde, $f_hasta);
$serie_mes_full  = rellenar_serie($serie_mes,  'Y-m',   '+1 month', $f_desde, $f_hasta);
$serie_anio_full = rellenar_serie($serie_anio, 'Y',     '+1 year',  $f_desde, $f_hasta);

// ── 6. POR DÍA DE LA SEMANA ──
$dias_semana_nombres = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
$por_dia_semana_raw  = consultar($db, "SELECT WEEKDAY(fecha_inicio) d, COUNT(*) c FROM solicitudes s $w GROUP BY d ORDER BY d", $p);
$por_dia_semana = array_fill(0, 7, 0);
foreach ($por_dia_semana_raw as $r) { $por_dia_semana[(int)$r['d']] = (int)$r['c']; }

// ── 7. KPIs ADICIONALES ──
$r = consultar($db, "SELECT AVG(TIMESTAMPDIFF(MINUTE, fecha_solicitud, fecha_gestion)) prom
                     FROM solicitudes s $w AND fecha_gestion IS NOT NULL AND fecha_solicitud IS NOT NULL", $p);
$prom_min = $r[0]['prom'] !== null ? (float)$r[0]['prom'] : null;
if ($prom_min === null)        { $tiempo_respuesta = '—'; }
elseif ($prom_min < 60)        { $tiempo_respuesta = round($prom_min) . ' min'; }
elseif ($prom_min < 2880)      { $tiempo_respuesta = round($prom_min / 60, 1) . ' h'; }
else                           { $tiempo_respuesta = round($prom_min / 1440, 1) . ' días'; }

$r = consultar($db, "SELECT COALESCE(SUM(GREATEST(DATEDIFF(fecha_fin, fecha_inicio)+1, 1)),0) dias
                     FROM solicitudes s $w AND estado = 'Aprobado'", $p);
$dias_aprobados = (int)$r[0]['dias'];

$motivo_top = count($por_motivo) > 0 ? $por_motivo[0]['motivo'] : '—';

// ── LISTAS PARA LOS SELECT DE FILTRO ──
$lista_motivos = $db->query("SELECT DISTINCT motivo FROM solicitudes WHERE motivo IS NOT NULL AND motivo <> '' ORDER BY motivo ASC")->fetchAll(PDO::FETCH_COLUMN);
$lista_jefes   = $db->query("SELECT DISTINCT correo_jefe FROM solicitudes WHERE correo_jefe IS NOT NULL AND correo_jefe <> '' ORDER BY correo_jefe ASC")->fetchAll(PDO::FETCH_COLUMN);
$lista_estados = $db->query("SELECT DISTINCT estado FROM solicitudes WHERE estado IS NOT NULL AND estado <> '' ORDER BY estado ASC")->fetchAll(PDO::FETCH_COLUMN);

// Descripción del período mostrado
if (empty($f_desde) && empty($f_hasta)) { $txt_periodo = "Todo el histórico"; }
else { $txt_periodo = "Del " . ($f_desde ?: 'inicio') . " al " . ($f_hasta ?: 'hoy'); }

// ── DATOS PARA LAS GRÁFICAS (JSON seguro) ──
$nombres_jefes_grafica = [];
foreach ($por_jefe as $j) {
    $k = mb_strtolower(trim($j['correo_jefe']));
    $nombres_jefes_grafica[] = $mapa_nombres[$k] ?? $j['correo_jefe'];
}

$DATOS = [
    'estado' => [
        'labels'  => ['Aprobado', 'Pendiente', 'Rechazado'],
        'valores' => [$tot['Aprobado'], $tot['Pendiente'], $tot['Rechazado']],
    ],
    'motivo' => [
        'labels'  => array_map(fn($m) => $m['motivo'], $por_motivo),
        'valores' => array_map(fn($m) => (int)$m['total'], $por_motivo),
    ],
    'jefes' => [
        'labels'     => $nombres_jefes_grafica,
        'aprobados'  => array_map(fn($j) => (int)$j['a'],  $por_jefe),
        'pendientes' => array_map(fn($j) => (int)$j['pe'], $por_jefe),
        'rechazados' => array_map(fn($j) => (int)$j['r'],  $por_jefe),
    ],
    'serie' => [
        'dia'  => $serie_dia_full,
        'mes'  => $serie_mes_full,
        'anio' => $serie_anio_full,
    ],
    'semana' => [
        'labels'  => $dias_semana_nombres,
        'valores' => $por_dia_semana,
    ],
];
$JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

// Alturas dinámicas para que las barras horizontales nunca queden apretadas
// (la altura incluye la banda del eje X)
$alto_motivo = max(240, count($por_motivo) * 42 + 90);
$alto_jefes  = max(240, count($por_jefe)   * 46 + 110);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agro-Costa | Dashboard de Permisos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --cat-yellow: #FFCD11; --cat-yellow-dark: #F7931E; --cat-black: #1A1A1A; --apple-bg: #ffffff; --soft-gray: #86868b; }
        body { background-color: var(--apple-bg); font-family: -apple-system, sans-serif; color: var(--cat-black); }
        /* navbar con fondo blanco, borde naranja y logo - paolo */
        .navbar { background: #ffffff !important; border-bottom: 3px solid var(--cat-yellow-dark); box-shadow: 0 4px 18px rgba(0,0,0,0.08); }
        .navbar-brand { font-weight: 700; color: var(--cat-black) !important; }
        .navbar .text-white { color: var(--cat-black) !important; }
        /* boton naranja para la barra - paolo */
        .btn-nav-naranja { background-color: var(--cat-yellow-dark); color: #fff !important; border: none; }
        .btn-nav-naranja:hover { background-color: #e07d0a; color: #fff; }
        .card-apple { background: #fff; border: none; border-radius: 22px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); }
        .btn-cat { background-color: var(--cat-black); color: var(--cat-yellow); border-radius: 12px; font-weight: 600; border: none; padding: 10px 24px; }
        .btn-cat:hover { background-color: #000; color: #fff; }
        .form-control, .form-select { border-radius: 12px !important; border: 1px solid #d2d2d7 !important; background-color: #fbfbfd !important; }
        label { font-weight: 600; font-size: 0.72rem; color: var(--soft-gray); margin-bottom: 5px; margin-left: 5px; text-transform: uppercase; }

        /* KPI: ficha de valor (el número ES la gráfica) */
        .kpi-card { border-radius: 20px; padding: 22px 24px; background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,0.03); height: 100%; border-left: 5px solid #e8e8ed; }
        .kpi-num  { font-size: 42px; font-weight: 700; line-height: 1.05; }
        .kpi-lbl  { font-size: 0.72rem; font-weight: 700; color: var(--soft-gray); text-transform: uppercase; letter-spacing: 0.04em; }
        .kpi-sub  { font-size: 0.75rem; color: var(--soft-gray); }

        .chart-box { position: relative; width: 100%; }
        .titulo-chart { font-weight: 700; font-size: 0.95rem; }
        .sub-chart { font-size: 0.75rem; color: var(--soft-gray); }
        .btn-gran { border: 1px solid #d2d2d7; background: #fbfbfd; border-radius: 10px; font-size: 0.75rem; font-weight: 700; padding: 5px 14px; color: #555; }
        .btn-gran.activo { background: var(--cat-black); color: var(--cat-yellow); border-color: var(--cat-black); }
        .tabla-mini { font-size: 0.8rem; }
        .tabla-mini thead th { background: var(--cat-black); color: var(--cat-yellow); font-size: 0.68rem; text-transform: uppercase; padding: 10px 12px; border: none; }
        .tabla-mini td { padding: 9px 12px; border-top: 1px solid #f2f2f7; }
        .punto { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 5px; }
    </style>
</head>
<body>

<nav class="navbar sticky-top mb-4 py-3">
    <div class="container-fluid px-5">
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <img src="logo.png" alt="Agro-Costa" style="height:84px; width:auto;">
        </a>
        <div class="d-flex align-items-center">
            <span class="small me-3 d-none d-md-block">
                Hola, <strong><?php echo htmlspecialchars($mi_nombre, ENT_QUOTES, 'UTF-8'); ?></strong> (Admin)
            </span>
            <a href="index.php" class="btn btn-sm btn-nav-naranja fw-bold me-2" style="border-radius: 10px;">
                <i class="fas fa-table me-1"></i> VOLVER A GESTIÓN
            </a>
            <a href="logout.php" class="btn btn-sm px-3" style="border-radius: 10px; background-color:#C62828; color:#fff;">SALIR</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-5 pb-5">

    <div class="mb-4 d-flex flex-wrap align-items-end justify-content-between gap-2">
        <div>
            <h4 class="fw-bold mb-1"><i class="fas fa-chart-pie text-warning me-2"></i> Dashboard de Permisos</h4>
            <p class="text-muted small mb-0">Período: <strong><?php echo htmlspecialchars($txt_periodo, ENT_QUOTES, 'UTF-8'); ?></strong> · <?php echo (int)$total_solicitudes; ?> solicitudes</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="dashboard.php?desde=<?php echo date('Y-m-01'); ?>&hasta=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-light border fw-bold" style="border-radius:10px;">Este mes</a>
            <a href="dashboard.php?desde=<?php echo date('Y'); ?>-01-01&hasta=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-light border fw-bold" style="border-radius:10px;">Este año</a>
            <a href="dashboard.php?desde=&hasta=" class="btn btn-sm btn-light border fw-bold" style="border-radius:10px;">Todo el histórico</a>
        </div>
    </div>

    <!-- ══ FILA ÚNICA DE FILTROS: todos los bloques la obedecen ══ -->
    <div class="card card-apple p-4 mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label>Desde (f. inicio)</label>
                <input type="date" name="desde" class="form-control" value="<?php echo htmlspecialchars($f_desde, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-2">
                <label>Hasta (f. inicio)</label>
                <input type="date" name="hasta" class="form-control" value="<?php echo htmlspecialchars($f_hasta, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label>Tipo de permiso</label>
                <select name="motivo_f" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach ($lista_motivos as $m): ?>
                        <option value="<?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($f_motivo === $m) echo 'selected'; ?>><?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>Jefe</label>
                <select name="jefe" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach ($lista_jefes as $j): $nj = $mapa_nombres[mb_strtolower(trim($j))] ?? $j; ?>
                        <option value="<?php echo htmlspecialchars($j, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($f_jefe === $j) echo 'selected'; ?>><?php echo htmlspecialchars($nj, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label>Estado</label>
                <select name="estado" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach ($lista_estados as $es): ?>
                        <option value="<?php echo htmlspecialchars($es, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($f_estado === $es) echo 'selected'; ?>><?php echo htmlspecialchars($es, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-cat">VER</button>
            </div>
        </form>
    </div>

    <?php if ($total_solicitudes === 0): ?>
        <div class="card card-apple p-5 text-center text-muted">
            <div style="font-size:40px;" class="mb-2"><i class="far fa-folder-open"></i></div>
            <h5 class="fw-bold">Sin solicitudes en este período</h5>
            <p class="small mb-0">Ajusta los filtros o elige "Todo el histórico" para ver datos.</p>
        </div>
    <?php else: ?>

    <!-- ══ KPIs PRINCIPALES ══ -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="kpi-card" style="border-left-color: var(--cat-yellow);">
                <div class="kpi-lbl">Total solicitudes</div>
                <div class="kpi-num"><?php echo (int)$total_solicitudes; ?></div>
                <div class="kpi-sub">en el período filtrado</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card" style="border-left-color: #2E9E4F;">
                <div class="kpi-lbl">Aprobadas</div>
                <div class="kpi-num" style="color:#2E9E4F;"><?php echo (int)$tot['Aprobado']; ?></div>
                <div class="kpi-sub"><?php echo $tasa_aprobacion !== null ? "tasa de aprobación: {$tasa_aprobacion}%" : "sin solicitudes gestionadas"; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card" style="border-left-color: #E6A700;">
                <div class="kpi-lbl">Pendientes</div>
                <div class="kpi-num" style="color:#B8860B;"><?php echo (int)$tot['Pendiente']; ?></div>
                <div class="kpi-sub">esperando gestión</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card" style="border-left-color: #E5352B;">
                <div class="kpi-lbl">Rechazadas</div>
                <div class="kpi-num" style="color:#E5352B;"><?php echo (int)$tot['Rechazado']; ?></div>
                <div class="kpi-sub">en el período filtrado</div>
            </div>
        </div>
    </div>

    <!-- ══ KPIs SECUNDARIOS ══ -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-lbl"><i class="far fa-clock me-1"></i> Tiempo promedio de respuesta</div>
                <div class="kpi-num" style="font-size:32px;"><?php echo htmlspecialchars($tiempo_respuesta, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="kpi-sub">desde la solicitud hasta la decisión del jefe</div>
            </div>
        </div>
        <div class="col-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-lbl"><i class="far fa-calendar-check me-1"></i> Días de permiso aprobados</div>
                <div class="kpi-num" style="font-size:32px;"><?php echo (int)$dias_aprobados; ?></div>
                <div class="kpi-sub">suma de días calendario de solicitudes aprobadas</div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-lbl"><i class="far fa-star me-1"></i> Permiso más frecuente</div>
                <div class="kpi-num" style="font-size:24px; line-height:1.3;"><?php echo htmlspecialchars($motivo_top, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="kpi-sub"><?php echo count($por_motivo) > 0 ? (int)$por_motivo[0]['total'] . " solicitudes en el período" : ""; ?></div>
            </div>
        </div>
    </div>

    <!-- ══ FILA 1: Estado (dona) + Tipos de permiso (barras) ══ -->
    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card card-apple p-4 h-100">
                <div class="titulo-chart">Distribución por estado</div>
                <div class="sub-chart mb-3">Participación de cada estado en el total</div>
                <div class="chart-box" style="height: 260px;">
                    <canvas id="chEstado"></canvas>
                </div>
                <div class="d-flex justify-content-center gap-3 mt-3 small flex-wrap">
                    <span><span class="punto" style="background:#2E9E4F;"></span>Aprobado <strong>(<?php echo (int)$tot['Aprobado']; ?>)</strong></span>
                    <span><span class="punto" style="background:#E6A700;"></span>Pendiente <strong>(<?php echo (int)$tot['Pendiente']; ?>)</strong></span>
                    <span><span class="punto" style="background:#E5352B;"></span>Rechazado <strong>(<?php echo (int)$tot['Rechazado']; ?>)</strong></span>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card card-apple p-4 h-100">
                <div class="titulo-chart">Solicitudes por tipo de permiso</div>
                <div class="sub-chart mb-3">Ordenado de mayor a menor en el período filtrado</div>
                <div class="chart-box" style="height: <?php echo (int)$alto_motivo; ?>px;">
                    <canvas id="chMotivo"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ FILA 2: Por jefe (barras apiladas + tabla) ══ -->
    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card card-apple p-4 h-100">
                <div class="titulo-chart">Solicitudes por jefe</div>
                <div class="sub-chart mb-3">Desglose por estado de lo que recibe cada jefe</div>
                <div class="chart-box" style="height: <?php echo (int)$alto_jefes; ?>px;">
                    <canvas id="chJefes"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card card-apple p-4 h-100">
                <div class="titulo-chart mb-3">Detalle numérico por jefe</div>
                <div class="table-responsive" style="max-height: <?php echo (int)$alto_jefes + 40; ?>px; overflow-y:auto;">
                    <table class="table tabla-mini align-middle mb-0">
                        <thead><tr><th>Jefe</th><th class="text-center">Total</th><th class="text-center">Aprob.</th><th class="text-center">Pend.</th><th class="text-center">Rech.</th></tr></thead>
                        <tbody>
                        <?php foreach ($por_jefe as $j):
                            $k  = mb_strtolower(trim($j['correo_jefe']));
                            $nj = $mapa_nombres[$k] ?? $j['correo_jefe'];
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($nj, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-muted" style="font-size:0.68rem;"><?php echo htmlspecialchars($j['correo_jefe'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="text-center fw-bold"><?php echo (int)$j['total']; ?></td>
                                <td class="text-center" style="color:#2E9E4F; font-weight:700;"><?php echo (int)$j['a']; ?></td>
                                <td class="text-center" style="color:#B8860B; font-weight:700;"><?php echo (int)$j['pe']; ?></td>
                                <td class="text-center" style="color:#E5352B; font-weight:700;"><?php echo (int)$j['r']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ FILA 3: Serie temporal con granularidad día / mes / año ══ -->
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card card-apple p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <div class="titulo-chart">Evolución de solicitudes en el tiempo</div>
                        <div class="sub-chart">Según fecha de inicio del permiso · cambia la vista con los botones</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn-gran" data-gran="dia" onclick="setGranularidad('dia')">DÍA</button>
                        <button type="button" class="btn-gran activo" data-gran="mes" onclick="setGranularidad('mes')">MES</button>
                        <button type="button" class="btn-gran" data-gran="anio" onclick="setGranularidad('anio')">AÑO</button>
                    </div>
                </div>
                <div class="chart-box" style="height: 300px;">
                    <canvas id="chSerie"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ FILA 4: Día de la semana + Top empleados ══ -->
    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card card-apple p-4 h-100">
                <div class="titulo-chart">Permisos por día de la semana</div>
                <div class="sub-chart mb-3">En qué días caen los inicios de permiso</div>
                <div class="chart-box" style="height: 280px;">
                    <canvas id="chSemana"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card card-apple p-4 h-100">
                <div class="titulo-chart mb-3">Top 10 · Empleados con más solicitudes</div>
                <div class="table-responsive">
                    <table class="table tabla-mini align-middle mb-0">
                        <thead><tr><th>Empleado</th><th>Cédula</th><th class="text-center">Total</th><th class="text-center">Aprob.</th><th class="text-center">Pend.</th><th class="text-center">Rech.</th><th class="text-center">Días aprob.</th></tr></thead>
                        <tbody>
                        <?php foreach ($por_empleado as $e): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($e['empleado'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($e['cedula'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center fw-bold"><?php echo (int)$e['total']; ?></td>
                                <td class="text-center" style="color:#2E9E4F; font-weight:700;"><?php echo (int)$e['a']; ?></td>
                                <td class="text-center" style="color:#B8860B; font-weight:700;"><?php echo (int)$e['pe']; ?></td>
                                <td class="text-center" style="color:#E5352B; font-weight:700;"><?php echo (int)$e['r']; ?></td>
                                <td class="text-center"><?php echo (int)$e['dias_aprobados']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const DATOS = <?php echo json_encode($DATOS, $JSON_FLAGS); ?>;

// Colores (validados): estados un paso más oscuros para buen contraste,
// siempre acompañados de leyenda con valores y tablas numéricas.
const C_APROBADO  = '#2E9E4F';
const C_PENDIENTE = '#E6A700';
const C_RECHAZADO = '#E5352B';
const C_CARBON    = '#1A1A1A';
const C_GRID      = '#F0F0F2';
const C_BORDE     = '#E5E5EA';

Chart.defaults.font.family = "-apple-system, 'Segoe UI', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#666';

const ejeLimpio = {
    grid:   { color: C_GRID, drawTicks: false },
    border: { color: C_BORDE },
    ticks:  { padding: 8 }
};
const ejeEntero = JSON.parse(JSON.stringify(ejeLimpio));
// precision:0 evita ticks con decimales cuando los conteos son pequeños

let hayDatos = DATOS.estado.valores.some(v => v > 0);

// ── 1. DONA DE ESTADOS ──
if (document.getElementById('chEstado') && hayDatos) {
    new Chart(document.getElementById('chEstado'), {
        type: 'doughnut',
        data: {
            labels: DATOS.estado.labels,
            datasets: [{
                data: DATOS.estado.valores,
                backgroundColor: [C_APROBADO, C_PENDIENTE, C_RECHAZADO],
                borderColor: '#ffffff',
                borderWidth: 3,          // separación entre segmentos
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: { display: false }, // la leyenda con valores está en HTML debajo
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? Math.round(ctx.parsed * 100 / total) : 0;
                            return ` ${ctx.label}: ${ctx.parsed} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

// ── 2. BARRAS POR TIPO DE PERMISO (horizontal, una sola serie → un solo color) ──
if (document.getElementById('chMotivo')) {
    new Chart(document.getElementById('chMotivo'), {
        type: 'bar',
        data: {
            // La etiqueta incluye el valor exacto: legible sin pasar el mouse
            labels: DATOS.motivo.labels.map((l, i) => l + '  ·  ' + DATOS.motivo.valores[i]),
            datasets: [{
                data: DATOS.motivo.valores,
                backgroundColor: C_CARBON,
                hoverBackgroundColor: '#FFCD00',
                barThickness: 18,
                borderRadius: 4,
                borderSkipped: 'start'   // redondea solo el extremo de datos
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ...ejeLimpio, ticks: { ...ejeLimpio.ticks, precision: 0 }, beginAtZero: true },
                y: { grid: { display: false }, border: { color: C_BORDE } }
            }
        }
    });
}

// ── 3. BARRAS APILADAS POR JEFE (estados = colores de estado) ──
if (document.getElementById('chJefes')) {
    new Chart(document.getElementById('chJefes'), {
        type: 'bar',
        data: {
            labels: DATOS.jefes.labels,
            datasets: [
                { label: 'Aprobado',  data: DATOS.jefes.aprobados,  backgroundColor: C_APROBADO,  barThickness: 20, borderColor: '#fff', borderWidth: 2 },
                { label: 'Pendiente', data: DATOS.jefes.pendientes, backgroundColor: C_PENDIENTE, barThickness: 20, borderColor: '#fff', borderWidth: 2 },
                { label: 'Rechazado', data: DATOS.jefes.rechazados, backgroundColor: C_RECHAZADO, barThickness: 20, borderColor: '#fff', borderWidth: 2 }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 16 } }
            },
            scales: {
                x: { ...ejeLimpio, stacked: true, ticks: { ...ejeLimpio.ticks, precision: 0 }, beginAtZero: true },
                y: { stacked: true, grid: { display: false }, border: { color: C_BORDE } }
            }
        }
    });
}

// ── 4. SERIE TEMPORAL (día / mes / año) ──
let chartSerie = null;
function setGranularidad(g) {
    document.querySelectorAll('.btn-gran').forEach(b => b.classList.toggle('activo', b.dataset.gran === g));
    const d = DATOS.serie[g];
    if (chartSerie) {
        chartSerie.data.labels = d.labels;
        chartSerie.data.datasets[0].data = d.valores;
        chartSerie.update();
    }
}
if (document.getElementById('chSerie')) {
    chartSerie = new Chart(document.getElementById('chSerie'), {
        type: 'line',
        data: {
            labels: DATOS.serie.mes.labels,
            datasets: [{
                label: 'Solicitudes',
                data: DATOS.serie.mes.valores,
                borderColor: C_CARBON,
                borderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 7,
                pointBackgroundColor: '#FFCD00',
                pointBorderColor: C_CARBON,
                pointBorderWidth: 1.5,
                fill: true,
                backgroundColor: 'rgba(255, 205, 0, 0.10)',
                tension: 0.25
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },  // crosshair-tooltip en toda la columna
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, border: { color: C_BORDE }, ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 20 } },
                y: { ...ejeLimpio, ticks: { ...ejeLimpio.ticks, precision: 0 }, beginAtZero: true }
            }
        }
    });
}

// ── 5. DÍA DE LA SEMANA ──
if (document.getElementById('chSemana')) {
    new Chart(document.getElementById('chSemana'), {
        type: 'bar',
        data: {
            labels: DATOS.semana.labels,
            datasets: [{
                data: DATOS.semana.valores,
                backgroundColor: C_CARBON,
                hoverBackgroundColor: '#FFCD00',
                barThickness: 22,
                borderRadius: 4,
                borderSkipped: 'bottom'
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, border: { color: C_BORDE } },
                y: { ...ejeLimpio, ticks: { ...ejeLimpio.ticks, precision: 0 }, beginAtZero: true }
            }
        }
    });
}
</script>
</body>
</html>
