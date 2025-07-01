<?php
session_start();
require 'conexion.php';

// Configurar archivo de log
ini_set('log_errors', 1);
ini_set('error_log', 'debug_student.log');

function logMessage($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, 'debug_student.log');
}

if (!isset($_SESSION['id']) || $_SESSION['tipo'] !== 'alumno') {
    header("Location: index.php");
    exit();
}
$alumnoId = $_SESSION['id'];

logMessage("Inicio de alumno.php para alumno ID: $alumnoId");

// Obtener información del perfil
$sql = "SELECT u.nombre_completo, a.nia, g.nombre AS generacion 
        FROM usuarios u 
        JOIN alumnos a ON u.id = a.usuario_id 
        JOIN generaciones g ON a.generacion_id = g.id 
        WHERE u.id = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $alumnoId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$alumnoNombre = $result['nombre_completo'] ?? 'Alumno';
$alumnoNIA = $result['nia'] ?? 'N/A';
$alumnoGeneracion = $result['generacion'] ?? 'No especificada';
logMessage("Perfil: $alumnoNombre, NIA: $alumnoNIA, Generación: $alumnoGeneracion");

// Obtener grupos del alumno
$sql = "SELECT g.id, CONCAT(g.grado, '° ', g.letra_grupo) AS nombre_grupo, m.nombre AS nombre_materia, s.numero AS semestre_numero
        FROM grupos g 
        JOIN materias m ON g.materia_id = m.id 
        JOIN semestres s ON g.semestre_id = s.id 
        JOIN alumnos a ON a.generacion_id = s.generacion_id 
        WHERE a.usuario_id = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $alumnoId);
$stmt->execute();
$grupos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
logMessage("Grupos asignados: " . count($grupos));

// Obtener calificaciones
function getCalificaciones($alumnoId, $conexion) {
    $sql = "SELECT CONCAT(g.grado, '° ', g.letra_grupo) AS nombre_grupo, m.nombre AS materia, p.numero_parcial, c.calificacion, c.asistencia_penalizacion, c.total
            FROM calificaciones c
            JOIN parciales p ON c.parcial_id = p.id
            JOIN grupos g ON p.grupo_id = g.id
            JOIN materias m ON g.materia_id = m.id
            WHERE c.alumno_id = ?
            ORDER BY p.numero_parcial";
    $stmt = $conexion->prepare($sql);
    if ($stmt === false) {
        logMessage("Error preparando consulta de calificaciones: " . $conexion->error);
        return [];
    }
    $stmt->bind_param("i", $alumnoId);
    $stmt->execute();
    $calificaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    logMessage("Calificaciones encontradas: " . count($calificaciones));
    return $calificaciones;
}

// Obtener asistencias
function getAsistencias($alumnoId, $conexion) {
    $sql = "SELECT CONCAT(g.grado, '° ', g.letra_grupo) AS nombre_grupo, a.fecha, a.tipo
            FROM asistencias a
            JOIN grupos g ON a.grupo_id = g.id
            WHERE a.alumno_id = ?
            ORDER BY a.fecha DESC
            LIMIT 10";
    $stmt = $conexion->prepare($sql);
    if ($stmt === false) {
        logMessage("Error preparando consulta de asistencias: " . $conexion->error);
        return [];
    }
    $stmt->bind_param("i", $alumnoId);
    $stmt->execute();
    $asistencias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    logMessage("Asistencias encontradas: " . count($asistencias));
    return $asistencias;
}

// Obtener historial de calificaciones
function getHistorialCalificaciones($alumnoId, $conexion) {
    $sql = "SELECT ch.fecha_modificacion, ch.calificacion_anterior, ch.calificacion_nueva, u.nombre_completo AS modificado_por
            FROM calificaciones_historial ch
            JOIN calificaciones c ON ch.alumno_id = c.alumno_id AND ch.parcial_id = c.parcial_id
            JOIN usuarios u ON ch.modificado_por = u.id
            WHERE c.alumno_id = ?
            ORDER BY ch.fecha_modificacion DESC
            LIMIT 5";
    $stmt = $conexion->prepare($sql);
    if ($stmt === false) {
        logMessage("Error preparando consulta de historial: " . $conexion->error);
        return [];
    }
    $stmt->bind_param("i", $alumnoId);
    $stmt->execute();
    $historial = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    logMessage("Historial de calificaciones: " . count($historial));
    return $historial;
}

// Obtener observaciones
function getObservaciones($alumnoId, $conexion) {
    $sql = "SELECT o.id, CONCAT(g.grado, '° ', g.letra_grupo) AS nombre_grupo, o.observacion, o.fecha
            FROM observaciones o
            JOIN grupos g ON o.grupo_id = g.id
            WHERE o.alumno_id = ?
            ORDER BY o.fecha DESC";
    $stmt = $conexion->prepare($sql);
    if ($stmt === false) {
        logMessage("Error preparando consulta de observaciones: " . $conexion->error);
        return [];
    }
    $stmt->bind_param("i", $alumnoId);
    $stmt->execute();
    $observaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    logMessage("Observaciones encontradas: " . count($observaciones));
    return $observaciones;
}

// Datos para las secciones
$calificaciones = getCalificaciones($alumnoId, $conexion);
$asistencias = getAsistencias($alumnoId, $conexion);
$historial = getHistorialCalificaciones($alumnoId, $conexion);
$observaciones = getObservaciones($alumnoId, $conexion);
$message = $_GET['message'] ?? '';

// Agrega esto en la sección donde procesas las acciones (antes del HTML)
if (isset($_GET['action'])) {
    require_once 'fpdf186/fpdf.php';
    
    switch ($_GET['action']) {
        case 'download_kardex':
            generateKardexPDF($alumnoNombre, $alumnoNIA, $alumnoGeneracion, $calificaciones);
            break;
            
        case 'download_calificaciones':
            generateCalificacionesPDF($alumnoNombre, $alumnoNIA, $alumnoGeneracion, $calificaciones);
            break;
            
        case 'download_asistencias':
            generateAsistenciasPDF($alumnoNombre, $alumnoNIA, $alumnoGeneracion, $asistencias);
            break;
            
        case 'download_historial':
            generateHistorialPDF($alumnoNombre, $alumnoNIA, $alumnoGeneracion, $historial);
            break;
    }
}

// Funciones para generar los PDFs
function generateKardexPDF($nombre, $nia, $generacion, $calificaciones) {
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->AddPage();
    
    // Encabezado
    addPDFHeader($pdf, 'KARDEX ACADÉMICO');
    addStudentInfo($pdf, $nombre, $nia, $generacion);
    
    // Tabla de calificaciones
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(40, 10, 'Grupo', 1, 0, 'C', true);
    $pdf->Cell(60, 10, 'Materia', 1, 0, 'C', true);
    $pdf->Cell(20, 10, 'Parcial', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Calificación', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Total', 1, 1, 'C', true);
    
    $pdf->SetFont('Arial','',10);
    $fill = false;
    foreach ($calificaciones as $calif) {
        $pdf->Cell(40, 8, iconv('UTF-8', 'windows-1252', $calif['nombre_grupo']), 'LR', 0, 'L', $fill);
        $pdf->Cell(60, 8, iconv('UTF-8', 'windows-1252', $calif['materia']), 'LR', 0, 'L', $fill);
        $pdf->Cell(20, 8, $calif['numero_parcial'], 'LR', 0, 'C', $fill);
        $pdf->Cell(30, 8, number_format($calif['calificacion'], 1), 'LR', 0, 'C', $fill);
        $pdf->Cell(30, 8, number_format($calif['total'], 1), 'LR', 1, 'C', $fill);
        $fill = !$fill;
    }
    $pdf->Cell(180, 0, '', 'T');
    
    addPDFFooter($pdf);
    $pdf->Output('kardex_'.$nia.'.pdf', 'D');
    exit();
}

function generateCalificacionesPDF($nombre, $nia, $generacion, $calificaciones) {
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->AddPage();
    
    addPDFHeader($pdf, 'REPORTE DE CALIFICACIONES');
    addStudentInfo($pdf, $nombre, $nia, $generacion);
    
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(40, 10, 'Grupo', 1, 0, 'C', true);
    $pdf->Cell(60, 10, 'Materia', 1, 0, 'C', true);
    $pdf->Cell(20, 10, 'Parcial', 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'Calificación', 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'Penalización', 1, 0, 'C', true);
    $pdf->Cell(20, 10, 'Total', 1, 1, 'C', true);
    
    $pdf->SetFont('Arial','',10);
    $fill = false;
    foreach ($calificaciones as $calif) {
        $pdf->Cell(40, 8, iconv('UTF-8', 'windows-1252', $calif['nombre_grupo']), 'LR', 0, 'L', $fill);
        $pdf->Cell(60, 8, iconv('UTF-8', 'windows-1252', $calif['materia']), 'LR', 0, 'L', $fill);
        $pdf->Cell(20, 8, $calif['numero_parcial'], 'LR', 0, 'C', $fill);
        $pdf->Cell(25, 8, number_format($calif['calificacion'], 1), 'LR', 0, 'C', $fill);
        $pdf->Cell(25, 8, number_format($calif['asistencia_penalizacion'], 1), 'LR', 0, 'C', $fill);
        $pdf->Cell(20, 8, number_format($calif['total'], 1), 'LR', 1, 'C', $fill);
        $fill = !$fill;
    }
    $pdf->Cell(190, 0, '', 'T');
    
    addPDFFooter($pdf);
    $pdf->Output('calificaciones_'.$nia.'.pdf', 'D');
    exit();
}

function generateAsistenciasPDF($nombre, $nia, $generacion, $asistencias) {
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->AddPage();
    
    addPDFHeader($pdf, 'REPORTE DE ASISTENCIAS');
    addStudentInfo($pdf, $nombre, $nia, $generacion);
    
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(60, 10, 'Grupo', 1, 0, 'C', true);
    $pdf->Cell(40, 10, 'Fecha', 1, 0, 'C', true);
    $pdf->Cell(40, 10, 'Tipo', 1, 1, 'C', true);
    
    $pdf->SetFont('Arial','',10);
    $fill = false;
    foreach ($asistencias as $asist) {
        $pdf->Cell(60, 8, iconv('UTF-8', 'windows-1252', $asist['nombre_grupo']), 'LR', 0, 'L', $fill);
        $pdf->Cell(40, 8, date('d/m/Y', strtotime($asist['fecha'])), 'LR', 0, 'C', $fill);
        
        // Color según el tipo de asistencia
        if ($asist['tipo'] === 'presente') {
            $pdf->SetTextColor(0, 128, 0); // Verde
        } elseif ($asist['tipo'] === 'falta') {
            $pdf->SetTextColor(255, 0, 0); // Rojo
        } else {
            $pdf->SetTextColor(0, 0, 0); // Negro
        }
        
        $pdf->Cell(40, 8, iconv('UTF-8', 'windows-1252', ucfirst($asist['tipo'])), 'LR', 1, 'C', $fill);
        $pdf->SetTextColor(0, 0, 0); // Restaurar color
        $fill = !$fill;
    }
    $pdf->Cell(140, 0, '', 'T');
    
    addPDFFooter($pdf);
    $pdf->Output('asistencias_'.$nia.'.pdf', 'D');
    exit();
}

function generateHistorialPDF($nombre, $nia, $generacion, $historial) {
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->AddPage();
    
    addPDFHeader($pdf, 'HISTORIAL DE CAMBIOS DE CALIFICACIONES');
    addStudentInfo($pdf, $nombre, $nia, $generacion);
    
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(50, 10, 'Fecha', 1, 0, 'C', true);
    $pdf->Cell(40, 10, 'Calif. Anterior', 1, 0, 'C', true);
    $pdf->Cell(40, 10, 'Calif. Nueva', 1, 0, 'C', true);
    $pdf->Cell(60, 10, 'Modificado Por', 1, 1, 'C', true);
    
    $pdf->SetFont('Arial','',10);
    $fill = false;
    foreach ($historial as $entry) {
        $pdf->Cell(50, 8, date('d/m/Y H:i', strtotime($entry['fecha_modificacion'])), 'LR', 0, 'C', $fill);
        $pdf->Cell(40, 8, number_format($entry['calificacion_anterior'], 1), 'LR', 0, 'C', $fill);
        $pdf->Cell(40, 8, number_format($entry['calificacion_nueva'], 1), 'LR', 0, 'C', $fill);
        $pdf->Cell(60, 8, iconv('UTF-8', 'windows-1252', $entry['modificado_por']), 'LR', 1, 'L', $fill);
        $fill = !$fill;
    }
    $pdf->Cell(190, 0, '', 'T');
    
    addPDFFooter($pdf);
    $pdf->Output('historial_calificaciones_'.$nia.'.pdf', 'D');
    exit();
}

// Funciones auxiliares para el formato común
function addPDFHeader(&$pdf, $titulo) {
    // Logo
    $pdf->Image('images/logo.jpg', 15, 10, 25);
    
    // Información de la escuela
    $pdf->SetFont('Arial','B',14);
    $pdf->SetXY(50, 10);
    $pdf->Cell(0, 6, iconv('UTF-8', 'windows-1252', 'BACHILLERATO GENERAL MIGUEL HIGALDO Y COSTILLA'), 0, 1);
    
    $pdf->SetFont('Arial','',10);
    $pdf->SetX(50);
    $pdf->Cell(0, 6, iconv('UTF-8', 'windows-1252', 'Plantel: Zona 07'), 0, 1);
    $pdf->SetX(50);
    $pdf->Cell(0, 6, iconv('UTF-8', 'windows-1252', 'Clave: 21DB06A02'), 0, 1);
    $pdf->SetX(50);
    $pdf->Cell(0, 6, iconv('UTF-8', 'windows-1252', 'Dirección: Av. Miguel higalgo N.2101'), 0, 1);
    
    // Título del reporte
    $pdf->SetFont('Arial','B',16);
    $pdf->Ln(10);
    $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', $titulo), 0, 1, 'C');
    $pdf->Ln(5);
}

function addStudentInfo(&$pdf, $nombre, $nia, $generacion) {
    $pdf->SetFont('Arial','',12);
    $pdf->Cell(40, 8, 'Nombre:', 0, 0, 'L');
    $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1252', $nombre), 0, 1);
    $pdf->Cell(40, 8, 'NIA:', 0, 0, 'L');
    $pdf->Cell(0, 8, $nia, 0, 1);
    $pdf->Cell(40, 8, 'Generación:', 0, 0, 'L');
    $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1252', $generacion), 0, 1);
    $pdf->Ln(10);
}

function addPDFFooter(&$pdf) {
    $pdf->SetY(-15);
    $pdf->SetFont('Arial','I',8);
    $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', 'Generado el: ' . date('d/m/Y H:i:s')), 0, 0, 'L');
    $pdf->Cell(0, 10, 'Página '.$pdf->PageNo().'/{nb}', 0, 0, 'R');
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Alumno ~<?php echo htmlspecialchars($alumnoNombre); ?></title>
    <link rel="stylesheet" href="css/alumno.css">
</head>
<body>
    <!-- Header -->
    <header class="student-header">
    <div class="header-content">
        <div class="logo-container">
            <img src="images/logo.jpg" alt="Logo" class="logo-img">
            <h1 class="student-title">Panel Alumno - <?php echo htmlspecialchars($alumnoNombre); ?></h1>
        </div>
        <button class="menu-toggle" aria-label="Toggle navigation">☰</button>
        <div class="theme-toggle-container">
            <button class="theme-toggle" aria-label="Toggle theme">🌙</button>
        </div>
        <nav class="student-nav" id="sidebar">
            <ul>
                <li><a href="#profile-section" class="nav-link">Perfil</a></li>
                <li><a href="#grades-section" class="nav-link">Calificaciones</a></li>
                <li><a href="#attendance-section" class="nav-link">Asistencias</a></li>
                <li><a href="#history-section" class="nav-link">Historial</a></li>
                <li><a href="#groups-section" class="nav-link">Grupos</a></li>
                <li><a href="#observations-section" class="nav-link">Observaciones</a></li>
                <li><a href="logout.php" class="logout-button">Cerrar Sesión</a></li>
            </ul>
        </nav>
    </div>
</header>

    <!-- Contenido Principal -->
    <main class="student-main">
        <?php if (!empty($message)): ?>
            <div class="notification"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Sección Perfil -->
        <section class="student-section" id="profile-section">
            <h2 class="section-title">Mi Perfil</h2>
            <div class="student-info">
                <div class="info-card">
                    <h3>Nombre Completo</h3>
                    <p><?php echo htmlspecialchars($alumnoNombre); ?></p>
                </div>
                <div class="info-card">
                    <h3>NIA</h3>
                    <p><?php echo htmlspecialchars($alumnoNIA); ?></p>
                </div>
                <div class="info-card">
                    <h3>Generación</h3>
                    <p><?php echo htmlspecialchars($alumnoGeneracion); ?></p>
                </div>
            </div>
        </section>

        <!-- Sección Calificaciones -->
        <section class="student-section" id="grades-section">
            <h2 class="section-title">Mis Calificaciones</h2>
            <div class="student-table-wrapper">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Grupo</th>
                            <th>Materia</th>
                            <th>Parcial</th>
                            <th>Calificación</th>
                            <th>Penalización</th>
                            <th>Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($calificaciones)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">No hay calificaciones registradas.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($calificaciones as $calif): ?>
                                <tr>
                                    <td data-label="Grupo"><?php echo htmlspecialchars($calif['nombre_grupo']); ?></td>
                                    <td data-label="Materia"><?php echo htmlspecialchars($calif['materia']); ?></td>
                                    <td data-label="Parcial"><?php echo htmlspecialchars($calif['numero_parcial']); ?></td>
                                    <td data-label="Calificación"><?php echo number_format($calif['calificacion'], 1); ?></td>
                                    <td data-label="Penalización"><?php echo number_format($calif['asistencia_penalizacion'], 1); ?></td>
                                    <td data-label="Total"><?php echo number_format($calif['total'], 1); ?></td>
                                    <td data-label="Estado" class="<?php echo $calif['total'] >= 10 ? 'success' : 'error'; ?>">
                                        <?php echo $calif['total'] >= 10 ? 'Aprobado' : 'Reprobado'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    </table>
            </div>
                  <!-- Formulario para las descargas -->
    <form id="pdf-form" method="get" action="">
        <div class="action-buttons">
            <button type="submit" name="action" value="download_kardex" class="download-button">
                <span class="download-icon">↓</span> Descargar Kardex
            </button>
        </section>

        <!-- Sección Asistencias -->
        <section class="student-section" id="attendance-section">
            <h2 class="section-title">Mis Asistencias</h2>
            <div class="student-table-wrapper">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Grupo</th>
                            <th>Fecha</th>
                            <th>Tipo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($asistencias)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center;">No hay asistencias registradas.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($asistencias as $asist): ?>
                                <tr>
                                    <td data-label="Grupo"><?php echo htmlspecialchars($asist['nombre_grupo']); ?></td>
                                    <td data-label="Fecha"><?php echo date('d/m/Y', strtotime($asist['fecha'])); ?></td>
                                    <td data-label="Tipo" class="<?php echo $asist['tipo'] === 'presente' ? 'success' : ($asist['tipo'] === 'falta' ? 'error' : ''); ?>">
                                        <?php echo ucfirst(htmlspecialchars($asist['tipo'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
               <!-- Formulario para las descargas -->
    <form id="pdf-form" method="get" action="">
        <div class="action-buttons">
            <button type="submit" name="action" value="download_asistencias" class="download-button">
                <span class="download-icon">↓</span> Descargar Asistencias
            </button>
        </section>

        <!-- Sección Historial -->
        <section class="student-section" id="history-section">
            <h2 class="section-title">Historial de Calificaciones</h2>
            <div class="student-table-wrapper">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Calif. Anterior</th>
                            <th>Calif. Nueva</th>
                            <th>Modificado Por</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historial)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">No hay cambios registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($historial as $entry): ?>
                                <tr>
                                    <td data-label="Fecha"><?php echo date('d/m/Y H:i', strtotime($entry['fecha_modificacion'])); ?></td>
                                    <td data-label="Calif. Anterior"><?php echo number_format($entry['calificacion_anterior'], 1); ?></td>
                                    <td data-label="Calif. Nueva"><?php echo number_format($entry['calificacion_nueva'], 1); ?></td>
                                    <td data-label="Modificado Por"><?php echo htmlspecialchars($entry['modificado_por']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <form id="pdf-form" method="get" action="">
        <div class="action-buttons">
            <button type="submit" name="action" value="download_historial" class="download-button">
                <span class="download-icon">↓</span> Descargar Historial
            </button>
        </section>

        <!-- Sección Grupos -->
        <section class="student-section" id="groups-section">
            <h2 class="section-title">Mis Grupos</h2>
            <div class="student-info">
                <?php if (empty($grupos)): ?>
                    <p style="text-align: center; width: 100%;">No estás inscrito en ningún grupo.</p>
                <?php else: ?>
                    <?php foreach ($grupos as $grupo): ?>
                        <div class="info-card">
                            <h3><?php echo htmlspecialchars($grupo['nombre_grupo']); ?></h3>
                            <p><strong>Materia:</strong> <?php echo htmlspecialchars($grupo['nombre_materia']); ?></p>
                            <p><strong>Semestre:</strong> <?php echo htmlspecialchars($grupo['semestre_numero']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Sección Observaciones -->
        <section class="student-section" id="observations-section">
            <h2 class="section-title">Mis Observaciones</h2>
            <div class="student-table-wrapper">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Grupo</th>
                            <th>Observación</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($observaciones)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center;">No hay observaciones registradas.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($observaciones as $obs): ?>
                                <tr>
                                    <td data-label="Grupo"><?php echo htmlspecialchars($obs['nombre_grupo']); ?></td>
                                    <td data-label="Observación"><?php echo htmlspecialchars($obs['observacion']); ?></td>
                                    <td data-label="Fecha"><?php echo date('d/m/Y H:i', strtotime($obs['fecha'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="student-footer">
        <p>© <?php echo date('Y'); ?> Sistema de Gestión Escolar - Desarrollado con pasión</p>
    </footer>

    <script src="js/alumno.js"></script>
</body>
</html>