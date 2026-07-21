<?php
// ============================================================
// exportar.php — genera .xlsx real SIN Composer ni librerías
// Generador XLSX mínimo incluido al final de este archivo
// ============================================================

date_default_timezone_set('America/Bogota');
include('config.php');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'admin') {
    die("Acceso denegado.");
}

// ── Consulta ─────────────────────────────────────────────────
// El Excel exporta EXACTAMENTE lo que se está viendo en el panel:
// recibe los mismos filtros que index.php.
$filtro_cedula  = $_GET['cedula']        ?? '';
$filtro_fecha   = $_GET['fecha']         ?? '';   // compatibilidad antigua
$filtro_nombre  = $_GET['nombre_buscar'] ?? '';
$filtro_desde   = $_GET['desde']         ?? '';
$filtro_hasta   = $_GET['hasta']         ?? '';
$filtro_estado  = $_GET['estado']        ?? '';
$filtro_motivo  = $_GET['motivo_f']      ?? '';
$filtro_jefe    = $_GET['jefe']          ?? '';
$filtro_gestor  = $_GET['gestor']        ?? '';
$filtro_soporte = $_GET['soporte_f']     ?? '';

if (!empty($filtro_fecha) && empty($filtro_desde) && empty($filtro_hasta)) {
    $filtro_desde = $filtro_fecha;
    $filtro_hasta = $filtro_fecha;
}

$sql    = "SELECT s.*, u.cargo FROM solicitudes s LEFT JOIN usuarios u ON s.cedula = u.cedula WHERE 1=1";
$params = [];

if (!empty($filtro_cedula)) {
    $sql     .= " AND s.cedula LIKE ?";
    $params[] = "%$filtro_cedula%";
}
if (!empty($filtro_nombre)) {
    $sql     .= " AND s.empleado LIKE ?";
    $params[] = "%$filtro_nombre%";
}
if (!empty($filtro_desde)) {
    $sql     .= " AND s.fecha_inicio >= ?";
    $params[] = $filtro_desde;
}
if (!empty($filtro_hasta)) {
    $sql     .= " AND s.fecha_inicio <= ?";
    $params[] = $filtro_hasta;
}
if (!empty($filtro_estado)) {
    $sql     .= " AND s.estado = ?";
    $params[] = $filtro_estado;
}
if (!empty($filtro_motivo)) {
    $sql     .= " AND s.motivo = ?";
    $params[] = $filtro_motivo;
}
if (!empty($filtro_jefe)) {
    $sql     .= " AND s.correo_jefe = ?";
    $params[] = $filtro_jefe;
}
if (!empty($filtro_gestor)) {
    $sql     .= " AND s.usuario_gestor = ?";
    $params[] = $filtro_gestor;
}
if ($filtro_soporte === 'con') {
    $sql .= " AND s.archivo_soporte IS NOT NULL AND s.archivo_soporte <> ''";
} elseif ($filtro_soporte === 'sin') {
    $sql .= " AND (s.archivo_soporte IS NULL OR s.archivo_soporte = '')";
}
$sql .= " ORDER BY s.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Construir datos ───────────────────────────────────────────
$encabezados = [
    'ID', 'Cédula', 'Empleado', 'Cargo', 'Jefe',
    'Fecha Inicio', 'Fecha Fin', 'Hora Inicio', 'Hora Fin',
    'Motivo', 'Detalle Permiso', 'Estado', 'Notas', 'Obs. Jefe'
];

$filas = [];
foreach ($resultados as $row) {
    $filas[] = [
        (int)($row['id']                ?? 0),
        $row['cedula']                  ?? '',
        $row['empleado']                ?? '',
        $row['cargo']                   ?? '',
        $row['correo_jefe']             ?? '',
        $row['fecha_inicio']            ?? '',
        $row['fecha_fin']               ?? '',
        $row['hora_inicio']             ?? '',
        $row['hora_fin']                ?? '',
        $row['motivo']                  ?? '',
        $row['detalle_permiso']         ?? '',
        $row['estado']                  ?? '',
        $row['notas']                   ?? '',
        $row['observacion_jefe']        ?? '',
    ];
}

// ── Generar y enviar XLSX ─────────────────────────────────────
$filename = 'Reporte_Permisos_AgroCosta_' . date('Y-m-d') . '.xlsx';
$xlsx     = generarXLSX($encabezados, $filas);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xlsx));
header('Cache-Control: max-age=0');
echo $xlsx;
exit();


// ============================================================
// GENERADOR XLSX PURO EN PHP — sin dependencias externas
// ============================================================
function generarXLSX(array $headers, array $rows): string
{
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFCD00"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFCCCCCC"/></left>
      <right style="thin"><color rgb="FFCCCCCC"/></right>
      <top style="thin"><color rgb="FFCCCCCC"/></top>
      <bottom style="thin"><color rgb="FFCCCCCC"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="4">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1"><alignment vertical="center" wrapText="1"/></xf>
  </cellXfs>
</styleSheet>';

    // ── Sheet ─────────────────────────────────────────────────
    $sx  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sx .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $sx .= '<sheetViews><sheetView workbookViewId="0">';
    $sx .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
    $sx .= '</sheetView></sheetViews>';

    // Anchos de columna
    $widths = [6, 13, 22, 18, 28, 13, 13, 12, 12, 30, 30, 12, 22, 22];
    $sx .= '<cols>';
    foreach ($widths as $i => $w) {
        $c = $i + 1;
        $sx .= '<col min="'.$c.'" max="'.$c.'" width="'.$w.'" customWidth="1"/>';
    }
    $sx .= '</cols><sheetData>';

    // Encabezado — estilo 1 (amarillo + negrita)
    $sx .= '<row r="1" ht="22" customHeight="1">';
    foreach ($headers as $ci => $h) {
        $ref = colLetter($ci).'1';
        $sx .= '<c r="'.$ref.'" t="inlineStr" s="1"><is><t>'.xe($h).'</t></is></c>';
    }
    $sx .= '</row>';

    // Datos — filas alternas blanco (s=2) / gris (s=3)
    foreach ($rows as $ri => $row) {
        $rn = $ri + 2;
        $s  = ($ri % 2 === 0) ? 2 : 3;
        $sx .= '<row r="'.$rn.'" ht="18" customHeight="1">';
        foreach ($row as $ci => $val) {
            $ref = colLetter($ci).$rn;
            if (is_int($val) || is_float($val)) {
                $sx .= '<c r="'.$ref.'" s="'.$s.'"><v>'.xe((string)$val).'</v></c>';
            } else {
                $sx .= '<c r="'.$ref.'" t="inlineStr" s="'.$s.'"><is><t>'.xe((string)$val).'</t></is></c>';
            }
        }
        $sx .= '</row>';
    }

    $sx .= '</sheetData><pageSetup orientation="landscape"/></worksheet>';

    // ── Empaquetar como ZIP/XLSX ──────────────────────────────
    $files = [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"          ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Permisos" sheetId="1" r:id="rId1"/></sheets>
</workbook>',

        'xl/styles.xml'              => $styles,
        'xl/worksheets/sheet1.xml'   => $sx,

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"    Target="styles.xml"/>
</Relationships>',
    ];

    return zipFiles($files);
}

function colLetter(int $idx): string
{
    $l = '';
    for ($n = $idx + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $l = chr(65 + ($n - 1) % 26) . $l;
    }
    return $l;
}

function xe(string $s): string
{
    return htmlspecialchars($s, ENT_XML1, 'UTF-8');
}

function zipFiles(array $files): string
{
    $zip = '';
    $cd  = '';
    $off = 0;

    foreach ($files as $name => $data) {
        $crc  = crc32($data);
        $sz   = strlen($data);
        $nl   = strlen($name);
        $ts   = dosTs();

        $lh  = "\x50\x4b\x03\x04";
        $lh .= pack('v', 20);   // version needed
        $lh .= pack('v', 0);    // flags
        $lh .= pack('v', 0);    // no compression
        $lh .= pack('V', $ts);
        $lh .= pack('V', $crc);
        $lh .= pack('V', $sz);
        $lh .= pack('V', $sz);
        $lh .= pack('v', $nl);
        $lh .= pack('v', 0);    // extra len
        $lh .= $name . $data;

        $ce  = "\x50\x4b\x01\x02";
        $ce .= pack('v', 20);
        $ce .= pack('v', 20);
        $ce .= pack('v', 0);
        $ce .= pack('v', 0);
        $ce .= pack('V', $ts);
        $ce .= pack('V', $crc);
        $ce .= pack('V', $sz);
        $ce .= pack('V', $sz);
        $ce .= pack('v', $nl);
        $ce .= pack('v', 0);    // extra
        $ce .= pack('v', 0);    // comment
        $ce .= pack('v', 0);    // disk start
        $ce .= pack('v', 0);    // int attr
        $ce .= pack('V', 0);    // ext attr
        $ce .= pack('V', $off);
        $ce .= $name;

        $zip .= $lh;
        $cd  .= $ce;
        $off += strlen($lh);
    }

    $cds   = strlen($cd);
    $cnt   = count($files);
    $eocd  = "\x50\x4b\x05\x06";
    $eocd .= pack('v', 0);
    $eocd .= pack('v', 0);
    $eocd .= pack('v', $cnt);
    $eocd .= pack('v', $cnt);
    $eocd .= pack('V', $cds);
    $eocd .= pack('V', $off);
    $eocd .= pack('v', 0);

    return $zip . $cd . $eocd;
}

function dosTs(): int
{
    $t = getdate();
    return (($t['year'] - 1980) << 25)
         | ($t['mon']           << 21)
         | ($t['mday']          << 16)
         | ($t['hours']         << 11)
         | ($t['minutes']       << 5)
         | ($t['seconds']       >> 1);
}
?>
