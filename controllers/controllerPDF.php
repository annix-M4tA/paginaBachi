<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../fpdf186/fpdf.php'; 
require_once __DIR__ . '/../conexion.php';

define('COLOR_NAVY', [0, 31, 63]);
define('COLOR_RED', [191, 0, 0]);
define('COLOR_YELLOW', [255, 215, 0]);
define('COLOR_BLACK', [0, 0, 0]);
define('COLOR_WHITE', [255, 255, 255]);

class PDF extends FPDF {
    function Header() {
        $this->SetFillColor(COLOR_NAVY[0], COLOR_NAVY[1], COLOR_NAVY[2]);
        $this->Rect(0, 0, $this->GetPageWidth(), 30, 'F');
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(COLOR_YELLOW[0], COLOR_YELLOW[1], COLOR_YELLOW[2]);
        $this->Cell(0, 10, 'Reporte Administrativo Escolar', 0, 1, 'C');
        $this->SetFont('Arial', 'I', 10);
        $this->SetTextColor(COLOR_WHITE[0], COLOR_WHITE[1], COLOR_WHITE[2]);
        $this->Cell(0, 5, 'Generado el ' . date('d/m/Y'), 0, 1, 'C');
        $this->SetDrawColor(COLOR_RED[0], COLOR_RED[1], COLOR_RED[2]);
        $this->SetLineWidth(1);
        $this->Line(10, 25, $this->GetPageWidth() - 10, 25);
        $this->Ln(10);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(COLOR_BLACK[0], COLOR_BLACK[1], COLOR_BLACK[2]);
        $this->Cell(0, 10, 'Página ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(COLOR_NAVY[0], COLOR_NAVY[1], COLOR_NAVY[2]);
        $this->Cell(0, 10, $title, 0, 1, 'L');
        $this->SetDrawColor(COLOR_YELLOW[0], COLOR_YELLOW[1], COLOR_YELLOW[2]);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
        $this->Ln(5);
    }

    function DataTable($header, $data) {
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(COLOR_NAVY[0], COLOR_NAVY[1], COLOR_NAVY[2]);
        $this->SetTextColor(COLOR_YELLOW[0], COLOR_YELLOW[1], COLOR_YELLOW[2]);
        $w = array_map(fn($h) => $this->GetStringWidth($h) + 10, $header);
        $totalWidth = array_sum($w);
        $scale = ($this->GetPageWidth() - 20) / $totalWidth;
        $w = array_map(fn($width) => $width * $scale, $w);

        foreach ($header as $i => $col) {
            $this->Cell($w[$i], 7, $col, 1, 0, 'C', true);
        }
        $this->Ln();

        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(COLOR_BLACK[0], COLOR_BLACK[1], COLOR_BLACK[2]);
        $this->SetFillColor(240, 240, 240);
        $fill = false;

        foreach ($data as $row) {
            foreach ($row as $i => $col) {
                $this->Cell($w[$i], 6, $col, 1, 0, 'L', $fill);
            }
            $this->Ln();
            $fill = !$fill;
        }
        $this->Ln(5);
    }

    function SummaryBox($title, $value) {
        $this->SetFillColor(COLOR_YELLOW[0], COLOR_YELLOW[1], COLOR_YELLOW[2]);
        $this->SetDrawColor(COLOR_NAVY[0], COLOR_NAVY[1], COLOR_NAVY[2]);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(50, 10, $title, 1, 0, 'C', true);
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', '', 12);
        $this->Cell(50, 10, $value, 1, 1, 'C', true);
    }
}

function sendError($message, $code = 400) {
    http_response_code($code);
    echo $message;
    if (isset($GLOBALS['conexion'])) $GLOBALS['conexion']->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['reporte'])) {
    sendError("Error: No se especificó un reporte válido en la solicitud. Usa ?reporte=tipo (ej. calificaciones, resumen).");
}

$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);

$reporte = $_GET['reporte'];
$semestre = isset($_GET['semestre']) ? $_GET['semestre'] : date('Y') . '-1';
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');
$grupo = isset($_GET['grupo']) ? (int)$_GET['grupo'] : null;
$alumno = isset($_GET['alumno']) ? (int)$_GET['alumno'] : null;

$tipo_reporte_map = [
    'resumen' => 'progreso',
    'asistencias' => 'asistencias',
    'calificaciones' => 'calificaciones',
    'calificaciones_totales' => 'calificaciones',
    'docentes' => 'progreso',
    'recomendaciones' => 'progreso'
];
$tipo_reporte = $tipo_reporte_map[$reporte] ?? null;
if (!$tipo_reporte) {
    sendError("Error: El tipo de reporte '$reporte' no está soportado.");
}

$filename = "Reporte_{$reporte}_" . date('YmdHis') . '.pdf';
$reportes_dir = __DIR__ . '/../reportes';
if (!file_exists($reportes_dir) && !mkdir($reportes_dir, 0755, true)) {
    sendError("Error: No se pudo crear el directorio 'reportes'. Verifica los permisos.", 500);
}
if (!is_writable($reportes_dir)) {
    sendError("Error: El directorio 'reportes' no tiene permisos de escritura.", 500);
}
$filepath = "$reportes_dir/$filename";
$file_relative_path = "reportes/$filename";

// Generación de contenido del PDF
if ($reporte === 'resumen') {
    $pdf->SectionTitle("Resumen General - $anio");
    $total_alumnos = $GLOBALS['conexion']->query("SELECT COUNT(*) FROM usuarios WHERE tipo = 'alumno'")->fetch_row()[0] ?? 0;
    $total_docentes = $GLOBALS['conexion']->query("SELECT COUNT(*) FROM usuarios WHERE tipo = 'docente'")->fetch_row()[0] ?? 0;
    $total_grupos = $GLOBALS['conexion']->query("SELECT COUNT(*) FROM grupos")->fetch_row()[0] ?? 0;
    $total_asistencias = $GLOBALS['conexion']->query("SELECT COUNT(*) FROM asistencias WHERE tipo = 'presente' AND YEAR(fecha) = $anio")->fetch_row()[0] ?? 0;
    $total_faltas = $GLOBALS['conexion']->query("SELECT COUNT(*) FROM asistencias WHERE tipo = 'falta' AND YEAR(fecha) = $anio")->fetch_row()[0] ?? 0;

    $pdf->SummaryBox('Total Alumnos', $total_alumnos);
    $pdf->SummaryBox('Total Docentes', $total_docentes);
    $pdf->SummaryBox('Total Grupos', $total_grupos);
    $pdf->SummaryBox('Asistencias', $total_asistencias);
    $pdf->SummaryBox('Faltas', $total_faltas);
}

if ($reporte === 'asistencias') {
    $pdf->SectionTitle("Asistencias Totales - $semestre");
    $query = "SELECT CONCAT(g.grado, g.letra_grupo) as nombre_grupo, 
                     SUM(CASE WHEN a.tipo = 'presente' THEN 1 ELSE 0 END) as presentes,
                     SUM(CASE WHEN a.tipo = 'falta' THEN 1 ELSE 0 END) as faltas
              FROM asistencias a
              JOIN grupos g ON a.grupo_id = g.id
              JOIN semestres s ON g.semestre_id = s.id
              WHERE CONCAT(s.numero, '-', s.generacion_id) = ?
              GROUP BY g.id";
    $stmt = $GLOBALS['conexion']->prepare($query);
    if (!$stmt) sendError("Error en la preparación de la consulta: " . $GLOBALS['conexion']->error, 500);
    $stmt->bind_param('s', $semestre);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        $pdf->Cell(0, 10, "Error en la consulta: " . $GLOBALS['conexion']->error, 0, 1);
        error_log("Error en asistencias totales: " . $GLOBALS['conexion']->error);
    } else {
        $asistencias = $result->fetch_all(MYSQLI_ASSOC);
        if (empty($asistencias)) {
            $pdf->Cell(0, 10, 'No hay datos de asistencias para este semestre.', 0, 1);
        } else {
            $header = ['Grupo', 'Presentes', 'Faltas'];
            $data = array_map(fn($a) => [$a['nombre_grupo'], $a['presentes'], $a['faltas']], $asistencias);
            $pdf->DataTable($header, $data);
        }
    }
    $stmt->close();
}

if ($reporte === 'calificaciones') {
    if ($alumno) {
        $pdf->SectionTitle("Calificaciones Individual - Alumno ID: $alumno ($semestre)");
        $query = "SELECT m.nombre, c.calificacion, c.parcial_id 
                  FROM calificaciones c 
                  JOIN materias m ON c.materia_id = m.id 
                  WHERE c.alumno_id = ?";
        $stmt = $GLOBALS['conexion']->prepare($query);
        if (!$stmt) sendError("Error en la preparación de la consulta: " . $GLOBALS['conexion']->error, 500);
        $stmt->bind_param('i', $alumno);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result === false) {
            $pdf->Cell(0, 10, 'Error en la consulta: ' . $GLOBALS['conexion']->error, 0, 1);
            error_log("Error en calificaciones individual: " . $GLOBALS['conexion']->error);
        } else {
            $calificaciones = $result->fetch_all(MYSQLI_ASSOC);
            if (empty($calificaciones)) {
                $pdf->Cell(0, 10, 'No hay calificaciones para este alumno.', 0, 1);
            } else {
                $header = ['Materia', 'Calificación', 'Parcial ID'];
                $data = array_map(fn($c) => [$c['nombre'], $c['calificacion'], $c['parcial_id']], $calificaciones);
                $pdf->DataTable($header, $data);
            }
        }
        $stmt->close();
    } elseif ($grupo) {
        $pdf->SectionTitle("Calificaciones por Grupo - ID: $grupo ($semestre)");
        $query = "SELECT u.nombre_usuario, AVG(c.calificacion) as promedio 
                  FROM calificaciones c 
                  JOIN usuarios u ON c.alumno_id = u.id 
                  JOIN parciales p ON c.parcial_id = p.id 
                  JOIN grupos g ON p.grupo_id = g.id 
                  WHERE g.id = ?
                  GROUP BY u.id";
        $stmt = $GLOBALS['conexion']->prepare($query);
        if (!$stmt) sendError("Error en la preparación de la consulta: " . $GLOBALS['conexion']->error, 500);
        $stmt->bind_param('i', $grupo);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result === false) {
            $pdf->Cell(0, 10, 'Error en la consulta: ' . $GLOBALS['conexion']->error, 0, 1);
            error_log("Error en calificaciones por grupo: " . $GLOBALS['conexion']->error);
        } else {
            $calificaciones = $result->fetch_all(MYSQLI_ASSOC);
            if (empty($calificaciones)) {
                $pdf->Cell(0, 10, 'No hay calificaciones para este grupo.', 0, 1);
            } else {
                $header = ['Alumno', 'Promedio'];
                $data = array_map(fn($c) => [$c['nombre_usuario'], number_format($c['promedio'], 2)], $calificaciones);
                $pdf->DataTable($header, $data);
            }
        }
        $stmt->close();
    }
}

if ($reporte === 'calificaciones_totales') {
    $pdf->SectionTitle("Calificaciones Totales - $semestre");
    $query = "SELECT CONCAT(g.grado, g.letra_grupo) as nombre_grupo, AVG(c.calificacion) as promedio 
              FROM calificaciones c 
              JOIN parciales p ON c.parcial_id = p.id 
              JOIN grupos g ON p.grupo_id = g.id 
              JOIN semestres s ON g.semestre_id = s.id 
              WHERE CONCAT(s.numero, '-', s.generacion_id) = ?
              GROUP BY g.id";
    $stmt = $GLOBALS['conexion']->prepare($query);
    if (!$stmt) sendError("Error en la preparación de la consulta: " . $GLOBALS['conexion']->error, 500);
    $stmt->bind_param('s', $semestre);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result === false) {
        $pdf->Cell(0, 10, "Error en la consulta: " . $GLOBALS['conexion']->error, 0, 1);
        error_log("Error en calificaciones totales: " . $GLOBALS['conexion']->error);
    } else {
        $calificaciones = $result->fetch_all(MYSQLI_ASSOC);
        if (empty($calificaciones)) {
            $pdf->Cell(0, 10, 'No hay calificaciones para este semestre.', 0, 1);
        } else {
            $header = ['Grupo', 'Promedio General'];
            $data = array_map(fn($c) => [$c['nombre_grupo'], number_format($c['promedio'], 2)], $calificaciones);
            $pdf->DataTable($header, $data);
        }
    }
    $stmt->close();
}

if ($reporte === 'docentes') {
    $pdf->SectionTitle("Desempeño Docentes - $semestre");
    $query = "SELECT u.nombre_usuario as docente, m.nombre as materia, 
                     CONCAT(g.grado, g.letra_grupo) as grupo, AVG(c.calificacion) as promedio
              FROM calificaciones c
              JOIN parciales p ON c.parcial_id = p.id
              JOIN grupos g ON p.grupo_id = g.id
              JOIN materias m ON g.materia_id = m.id
              JOIN usuarios u ON g.docente_id = u.id
              JOIN semestres s ON g.semestre_id = s.id
              WHERE CONCAT(s.numero, '-', s.generacion_id) = ?
              GROUP BY g.id, u.id, m.id";
    $stmt = $GLOBALS['conexion']->prepare($query);
    if (!$stmt) sendError("Error en la preparación de la consulta: " . $GLOBALS['conexion']->error, 500);
    $stmt->bind_param('s', $semestre);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        $pdf->Cell(0, 10, "Error en la consulta: " . $GLOBALS['conexion']->error, 0, 1);
        error_log("Error en desempeño docentes: " . $GLOBALS['conexion']->error);
    } else {
        $desempeno = $result->fetch_all(MYSQLI_ASSOC);
        if (empty($desempeno)) {
            $pdf->Cell(0, 10, 'No hay datos de desempeño para este semestre.', 0, 1);
        } else {
            $header = ['Docente', 'Materia', 'Grupo', 'Promedio'];
            $data = array_map(fn($d) => [$d['docente'], $d['materia'], $d['grupo'], number_format($d['promedio'], 2)], $desempeno);
            $pdf->DataTable($header, $data);
        }
    }
    $stmt->close();
}

if ($reporte === 'recomendaciones') {
    $pdf->SectionTitle("Recomendaciones - $semestre");
    $query = "SELECT u.nombre_usuario as alumno, m.nombre as materia, 
                     CONCAT(g.grado, g.letra_grupo) as grupo, c.calificacion, 
                     SUM(CASE WHEN a.tipo = 'falta' THEN 1 ELSE 0 END) as faltas
              FROM calificaciones c
              JOIN usuarios u ON c.alumno_id = u.id
              JOIN parciales p ON c.parcial_id = p.id
              JOIN grupos g ON p.grupo_id = g.id
              JOIN materias m ON g.materia_id = m.id
              JOIN semestres s ON g.semestre_id = s.id
              LEFT JOIN asistencias a ON a.alumno_id = c.alumno_id AND a.grupo_id = g.id
              WHERE CONCAT(s.numero, '-', s.generacion_id) = ?
              GROUP BY c.alumno_id, g.id, m.id
              HAVING c.calificacion < 6 OR faltas > 3";
    $stmt = $GLOBALS['conexion']->prepare($query);
    if (!$stmt) sendError("Error en la preparación de la consulta: " . $GLOBALS['conexion']->error, 500);
    $stmt->bind_param('s', $semestre);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        $pdf->Cell(0, 10, "Error en la consulta: " . $GLOBALS['conexion']->error, 0, 1);
        error_log("Error en recomendaciones: " . $GLOBALS['conexion']->error);
    } else {
        $recomendaciones = $result->fetch_all(MYSQLI_ASSOC);
        if (empty($recomendaciones)) {
            $pdf->Cell(0, 10, 'No hay recomendaciones necesarias para este semestre.', 0, 1);
        } else {
            $header = ['Alumno', 'Materia', 'Grupo', 'Calificación', 'Faltas', 'Recomendación'];
            $data = array_map(fn($r) => [
                $r['alumno'], 
                $r['materia'], 
                $r['grupo'], 
                $r['calificacion'], 
                $r['faltas'], 
                $r['calificacion'] < 6 ? 'Reforzar materia' : 'Mejorar asistencia'
            ], $recomendaciones);
            $pdf->DataTable($header, $data);
        }
    }
    $stmt->close();
}

// Guardar y enviar el PDF
$pdf->Output('F', $filepath);

if (isset($_SESSION['id'])) {
    $generado_por = (int)$_SESSION['id'];
    $semestre_result = $GLOBALS['conexion']->query("SELECT id FROM semestres WHERE CONCAT(numero, '-', generacion_id) = '$semestre' LIMIT 1");
    $semestre_id = $semestre_result && $semestre_result->num_rows > 0 ? $semestre_result->fetch_row()[0] : 1;
    $generacion_id = 1;

    $stmt = $GLOBALS['conexion']->prepare("INSERT INTO reportes (tipo_reporte, fecha_generado, ruta_archivo, generacion_id, semestre_id, generado_por) VALUES (?, NOW(), ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('ssiii', $tipo_reporte, $file_relative_path, $generacion_id, $semestre_id, $generado_por);
        if (!$stmt->execute()) {
            error_log("Error al insertar en reportes: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Error al preparar la inserción: " . $GLOBALS['conexion']->error);
    }
}

ob_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
$GLOBALS['conexion']->close();
exit;
?>