<?php
session_start();
require 'materias.php';
require 'conexion.php';
require_once 'fpdf186/fpdf.php';


// Definir constante de entorno
define('ENVIRONMENT', 'development'); // Cambiar a 'production' en entorno real

if (!isset($conexion)) {
    die("La conexión a la base de datos no se ha establecido correctamente.");
}

// Configurar archivo de log
ini_set('log_errors', 1);
ini_set('error_log', 'debug_docente.log');

// Función para registrar en el log optimizada
function logMessage($message) {
    // Solo registrar en producción si es un error importante
    if (ENVIRONMENT === 'development' || strpos($message, 'Error') !== false) {
        error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, 'debug_docente.log');
    }
}

// Verificar sesión docente
if (!isset($_SESSION['id']) || $_SESSION['tipo'] !== 'docente') {
    header("Location: index.php");
    exit();
}
   

// Verificar permisos de escritura en el archivo de registro
$logFile = 'debug_docente.log';
if (!is_writable($logFile)) {
    die("El archivo de registro '$logFile' no tiene permisos de escritura.");
}

$docenteId = $_SESSION['id'];

logMessage("Inicio de docente.php para docente ID: $docenteId");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'download_pdf') {
    $tipoReporte = $_POST['tipo_reporte'] ?? '';
    $grupoId = $_POST['grupo_reporte'] ?? '';
    $reporte = getReporte($tipoReporte, $grupoId, $conexion);

    // Crear instancia de FPDF
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->AddPage();
    
    // Configurar márgenes
    $pdf->SetMargins(15, 15, 15);
    
    // --- ENCABEZADO ---
    // Agregar logo (ajusta la ruta y posición según necesites)
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
    
    // Línea divisoria
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(15, 40, 200, 40);
    
    // --- TÍTULO DEL REPORTE ---
    $pdf->SetFont('Arial','B',16);
    $pdf->Ln(15);
    $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', 'Reporte: ' . ucfirst(str_replace('_', ' ', $tipoReporte))), 0, 1, 'C');
    
    // Obtener detalles del grupo para el reporte
    $detallesGrupo = getDetallesGrupo($grupoId, $conexion);
    if ($detallesGrupo) {
        $pdf->SetFont('Arial','',12);
        $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1252', 'Grupo: ' . $detallesGrupo['grado'] . '° ' . $detallesGrupo['letra_grupo']), 0, 1);
        $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1252', 'Materia: ' . $detallesGrupo['nombre_materia']), 0, 1);
        $pdf->Cell(0, 8, iconv('UTF-8', 'windows-1252', 'Semestre: ' . $detallesGrupo['semestre_numero']), 0, 1);
    }
    
    $pdf->Ln(10);
    
    // --- CONTENIDO DEL REPORTE ---
    $pdf->SetFont('Arial','',10);
    
    // Encabezados de la tabla
    $headers = [];
    $widths = [];
    $aligns = [];
    
    if ($tipoReporte === 'promedios') {
        $headers = ['Alumno', 'Promedio', 'Estado'];
        $widths = [100, 40, 40];
        $aligns = ['L', 'C', 'C'];
    } elseif ($tipoReporte === 'asistencias') {
        $headers = ['Alumno', 'Presente', 'Faltas', 'Justificadas'];
        $widths = [80, 35, 35, 35];
        $aligns = ['L', 'C', 'C', 'C'];
    } elseif ($tipoReporte === 'historial_calificaciones') {
        $headers = ['Alumno', 'Parcial', 'Calificación', 'Penalización', 'Total'];
        $widths = [70, 25, 30, 30, 25];
        $aligns = ['L', 'C', 'C', 'C', 'C'];
    } elseif ($tipoReporte === 'suma_semestre') {
        $headers = ['Alumno', 'Parcial 1', 'Parcial 2', 'Parcial 3', 'Suma Total', 'Estado'];
        $widths = [60, 25, 25, 25, 30, 20];
        $aligns = ['L', 'C', 'C', 'C', 'C', 'C'];
    }
    
    // Color de fondo para encabezados
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetTextColor(0);
    $pdf->SetDrawColor(150, 150, 150);
    $pdf->SetLineWidth(0.3);
    
    // Imprimir encabezados
    foreach ($headers as $i => $header) {
        $pdf->Cell($widths[$i], 7, iconv('UTF-8', 'windows-1252', $header), 1, 0, $aligns[$i], true);
    }
    $pdf->Ln();
    
    // Restablecer colores para el contenido
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetTextColor(0);
    
    // Imprimir datos
    $fill = false; // Para alternar colores de fila
    foreach ($reporte as $row) {
        $data = [];
        
        if ($tipoReporte === 'promedios') {
            $data = [
                $row['nombre_completo'],
                number_format($row['promedio'], 2),
                ($row['promedio'] >= 6 ? 'Aprobado' : 'Reprobado')
            ];
        } elseif ($tipoReporte === 'asistencias') {
            $data = [
                $row['nombre_completo'],
                $row['presente'],
                $row['falta'],
                $row['falta_justificada']
            ];
        } elseif ($tipoReporte === 'historial_calificaciones') {
            $data = [
                $row['nombre_completo'],
                $row['numero_parcial'],
                number_format($row['calificacion'], 1),
                number_format($row['asistencia_penalizacion'], 1),
                number_format($row['total'], 1)
            ];
        } elseif ($tipoReporte === 'suma_semestre') {
            $data = [
                $row['nombre_completo'],
                number_format($row['parcial1'] ?? 0, 1),
                number_format($row['parcial2'] ?? 0, 1),
                number_format($row['parcial3'] ?? 0, 1),
                number_format($row['suma_total'], 1),
                $row['estado']
            ];
        }
        
        foreach ($data as $i => $value) {
            $pdf->Cell($widths[$i], 6, iconv('UTF-8', 'windows-1252', $value), 'LR', 0, $aligns[$i], $fill);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
    
    // Cierre de la tabla
    $pdf->Cell(array_sum($widths), 0, '', 'T');
    $pdf->Ln(10);
    
    // --- PIE DE PÁGINA ---
    $pdf->SetFont('Arial','I',8);
    $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', 'Generado el: ' . date('d/m/Y H:i:s')), 0, 0, 'L');
    $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', 'Página '.$pdf->PageNo().'/{nb}'), 0, 0, 'R');
    
    // Salida del PDF
    $pdf->Output("reporte_$tipoReporte.pdf", 'D');
    exit();
}


// Crear tabla observaciones si no existe
$sql = "CREATE TABLE IF NOT EXISTS observaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alumno_id INT NOT NULL,
    grupo_id INT NOT NULL,
    observacion TEXT NOT NULL,
    fecha DATETIME NOT NULL,
    FOREIGN KEY (alumno_id) REFERENCES alumnos(usuario_id),
    FOREIGN KEY (grupo_id) REFERENCES grupos(id)
)";

// Crear índices para optimización
$conexion->query("CREATE INDEX IF NOT EXISTS idx_calificaciones_alumno_parcial ON calificaciones(alumno_id, parcial_id)");
$conexion->query("CREATE INDEX IF NOT EXISTS idx_historial_alumno_parcial ON calificaciones_historial(alumno_id, parcial_id)");


// Obtener información del docente
$sql = "SELECT u.nombre_completo, d.especialidad 
        FROM usuarios u 
        LEFT JOIN docentes d ON u.id = d.usuario_id 
        WHERE u.id = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $docenteId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$docenteNombre = $result['nombre_completo'] ?? 'Docente';
$docenteEspecialidad = $result['especialidad'] ?? 'No especificada';

// Obtener clases asignadas
$sql = "SELECT g.id, CONCAT(g.grado, '° ', g.letra_grupo) AS nombre_grupo, 
m.nombre AS nombre_materia, s.id AS semestre_id, s.numero AS semestre_numero
        FROM grupos g 
        JOIN materias m ON g.materia_id = m.id 
        JOIN semestres s ON g.semestre_id = s.id 
        WHERE g.docente_id = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $docenteId);
$stmt->execute();
$clases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Funciones optimizadas para cargar datos
function contarAlumnosGrupo($grupoId, $conexion) {
    $sql = "SELECT COUNT(DISTINCT a.usuario_id) AS total 
            FROM alumnos a 
            JOIN usuarios u ON a.usuario_id = u.id 
            JOIN semestres s ON a.generacion_id = s.generacion_id 
            JOIN grupos g ON g.semestre_id = s.id 
            WHERE g.id = ? AND u.estado = 'activo'";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $grupoId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'] ?? 0;
}

function getParciales($grupoId, $conexion) {
    $sql = "SELECT id, numero_parcial, fecha_inicio, fecha_fin 
            FROM parciales 
            WHERE grupo_id = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $grupoId);
    $stmt->execute();
    $parciales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    logMessage("Parciales para grupo $grupoId: " . count($parciales));
    return $parciales;
}

/*funcion para mandar a traer asistencias registradas*/
function getAsistenciasRegistradas($grupoId, $fecha, $conexion) {
    $sql = "SELECT u.id AS id, a.tipo
            FROM usuarios u
            JOIN alumnos al ON al.usuario_id = u.id
            JOIN semestres s ON al.generacion_id = s.generacion_id
            JOIN grupos g ON g.semestre_id = s.id
            LEFT JOIN asistencias a ON a.alumno_id = u.id 
                AND a.grupo_id = ?
                AND a.fecha = ?
           WHERE g.id = ? AND u.estado = 'activo'";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("isi", $grupoId, $fecha, $grupoId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    logMessage("Reporte 'asistencias registradas' generado para grupo $grupoId en fecha $fecha: " . count($result) . " registros");
    return $result;
}
    
//obtener calificaciones
function getCalificaciones($grupoId, $parcialId, $conexion) {
    $sql = "SELECT a.usuario_id AS id, u.nombre_completo, 
                   c.calificacion, c.asistencia_penalizacion, c.total,
                   (SELECT SUM(c2.total) 
                    FROM calificaciones c2 
                    JOIN parciales p2 ON c2.parcial_id = p2.id 
                    WHERE c2.alumno_id = a.usuario_id AND p2.grupo_id = ?) AS acumulado
            FROM alumnos a 
            JOIN usuarios u ON a.usuario_id = u.id
            JOIN semestres s ON a.generacion_id = s.generacion_id
            JOIN grupos g ON g.semestre_id = s.id
            LEFT JOIN calificaciones c ON a.usuario_id = c.alumno_id AND c.parcial_id = ?
            WHERE g.id = ? AND u.estado = 'activo'";
    
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("iii", $grupoId, $parcialId, $grupoId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

//Obtener alumnos
function getAlumnos($grupoId, $conexion) {
    $sql = "SELECT a.usuario_id AS id, u.nombre_completo 
            FROM alumnos a 
            JOIN usuarios u ON a.usuario_id = u.id 
            JOIN semestres s ON a.generacion_id = s.generacion_id
            JOIN grupos g ON g.semestre_id = s.id 
            WHERE g.id = ? AND u.estado = 'activo'";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $grupoId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Función para obtener detalles del grupo
function getDetallesGrupo($grupoId, $conexion) {
    $sql = "SELECT g.grado, g.letra_grupo, m.nombre AS nombre_materia, s.numero AS semestre_numero, s.generacion_id
            FROM grupos g 
            JOIN materias m ON g.materia_id = m.id 
            JOIN semestres s ON g.semestre_id = s.id 
            WHERE g.id = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $grupoId);
    $stmt->execute();
    $grupo = $stmt->get_result()->fetch_assoc();
    if (!$grupo) {
        logMessage("No se encontraron detalles para el grupo $grupoId");
        return null;
    }

    $sql = "SELECT u.nombre_completo, a.nia 
            FROM alumnos a 
            JOIN usuarios u ON a.usuario_id = u.id 
            JOIN semestres s ON a.generacion_id = s.generacion_id
            JOIN grupos g ON g.semestre_id = s.id 
            WHERE g.id = ? AND u.estado = 'activo'";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $grupoId);
    $stmt->execute();
    $grupo['alumnos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    logMessage("Detalles del grupo $grupoId cargados: " . count($grupo['alumnos']) . " alumnos encontrados");
    return $grupo;
}

// Función para generar reportes
function getReporte($tipo, $grupoId, $conexion) {
    if ($tipo === 'promedios') {
        $sql = "SELECT u.nombre_completo, AVG(c.total) AS promedio
                FROM alumnos a 
                JOIN usuarios u ON a.usuario_id = u.id
                JOIN semestres s ON a.generacion_id = s.generacion_id
                JOIN grupos g ON g.semestre_id = s.id
                JOIN calificaciones c ON a.usuario_id = c.alumno_id
                JOIN parciales p ON c.parcial_id = p.id
                WHERE g.id = ? 
                GROUP BY a.usuario_id, u.nombre_completo";
    } elseif ($tipo === 'asistencias') {
        $sql = "SELECT u.nombre_completo,
                       SUM(CASE WHEN asist.tipo = 'presente' THEN 1 ELSE 0 END) AS presente,
                       SUM(CASE WHEN asist.tipo = 'falta' THEN 1 ELSE 0 END) AS falta,
                       SUM(CASE WHEN asist.tipo = 'falta_justificada' THEN 1 ELSE 0 END) AS falta_justificada
                FROM alumnos a 
                JOIN usuarios u ON a.usuario_id = u.id
                JOIN semestres s ON a.generacion_id = s.generacion_id
                JOIN grupos g ON g.semestre_id = s.id
                JOIN asistencias asist ON a.usuario_id = asist.alumno_id AND asist.grupo_id = g.id
                WHERE g.id = ? 
                GROUP BY a.usuario_id, u.nombre_completo";
    } elseif ($tipo === 'historial_calificaciones') {
        $sql = "SELECT u.nombre_completo, p.numero_parcial, c.calificacion, c.asistencia_penalizacion, c.total
                FROM alumnos a 
                JOIN usuarios u ON a.usuario_id = u.id
                JOIN semestres s ON a.generacion_id = s.generacion_id
                JOIN grupos g ON g.semestre_id = s.id
                JOIN calificaciones c ON a.usuario_id = c.alumno_id
                JOIN parciales p ON c.parcial_id = p.id
                WHERE g.id = ? 
                ORDER BY u.nombre_completo, p.numero_parcial";
    } elseif ($tipo === 'suma_semestre') {
        $sql = "SELECT u.nombre_completo,
                       c1.total AS parcial1,
                       c2.total AS parcial2,
                       c3.total AS parcial3,
                       (IFNULL(c1.total, 0) + IFNULL(c2.total, 0) + IFNULL(c3.total, 0)) AS suma_total
                FROM alumnos a
                JOIN usuarios u ON a.usuario_id = u.id
                JOIN semestres s ON a.generacion_id = s.generacion_id
                JOIN grupos g ON g.semestre_id = s.id
                LEFT JOIN calificaciones c1 ON a.usuario_id = c1.alumno_id 
                    AND c1.parcial_id = (SELECT id FROM parciales WHERE grupo_id = g.id AND numero_parcial = 1)
                LEFT JOIN calificaciones c2 ON a.usuario_id = c2.alumno_id 
                    AND c2.parcial_id = (SELECT id FROM parciales WHERE grupo_id = g.id AND numero_parcial = 2)
                LEFT JOIN calificaciones c3 ON a.usuario_id = c3.alumno_id 
                    AND c3.parcial_id = (SELECT id FROM parciales WHERE grupo_id = g.id AND numero_parcial = 3)
                WHERE g.id = ? 
                ORDER BY u.nombre_completo";
        
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("i", $grupoId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Agregar estado
        foreach ($result as &$row) {
            $row['estado'] = ($row['suma_total'] >= 18) ? 'Aprobado' : 'Reprobado';
        }
        return $result;
    } else {
        logMessage("Tipo de reporte '$tipo' no válido para el grupo $grupoId");
        return [];
    }

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $grupoId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


// Función para obtener observaciones
function getObservacion($alumnoId, $grupoId, $conexion) {
    $sql = "SELECT observacion 
            FROM observaciones 
            WHERE alumno_id = ? AND grupo_id = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("ii", $alumnoId, $grupoId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ($result && isset($result['observacion'])) {
        logMessage("Observación encontrada para el alumno $alumnoId en el grupo $grupoId");
        return $result['observacion'];
    } else {
        logMessage("No se encontró observación para el alumno $alumnoId en el grupo $grupoId");
        return '';
    }
}
//funcion para las estadisticas

//Funcion obtener estadisticas de calificaciones globales

function getGradeStatistics($docenteId, $conexion) {
    $sql = "SELECT 
                SUM(CASE WHEN c.total >= 6 THEN 1 ELSE 0 END) AS aprobados,
                SUM(CASE WHEN c.total < 6 THEN 1 ELSE 0 END) AS reprobados
         FROM grupos g
         JOIN semestres s ON g.semestre_id = s.id
         JOIN alumnos a ON a.generacion_id = s.generacion_id
         JOIN usuarios u ON a.usuario_id = u.id
         LEFT JOIN calificaciones c ON a.usuario_id = c.alumno_id
         JOIN parciales p ON c.parcial_id = p.id
         WHERE g.docente_id = ? AND u.estado = 'activo'";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $docenteId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    logMessage("Estadísticas globales de calificaciones para docente $docenteId");
    return $result ?: ['aprobados' => 0, 'reprobados' => 0];
}

$gradeStats = getGradeStatistics($docenteId, $conexion);

// Variables para mensajes y datos de formulario
$message = $_GET['message'] ?? '';
$selectedGrupoCalif = $_GET['grupo_calif'] ?? ($clases[0]['id'] ?? '');
$selectedParcial = $_GET['parcial'] ?? '';
$selectedGrupoAsist = $_GET['grupo_asist'] ?? ($clases[0]['id'] ?? '');
$selectedFecha = $_GET['fecha'] ?? date('Y-m-d');
$selectedGrupoObs = $_GET['grupo_obs'] ?? ($clases[0]['id'] ?? '');
$selectedAlumnoObs = $_GET['alumno_obs'] ?? '';
$selectedGrupoReporte = $_GET['grupo_reporte'] ?? ($clases[0]['id'] ?? '');
$selectedTipoReporte = $_GET['tipo_reporte'] ?? 'promedios';

logMessage("Actualizando lista de materias");
$materias = getAllMaterias($conexion);

//------------------------ Procesar acciones----------------------------------------------------------------------------------//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    logMessage("Procesando acción: $action");
    
    switch ($action) {
        case 'save_calificaciones':
            $grupoId = $_POST['grupo_id'] ?? '';
            $parcialId = $_POST['parcial_id'] ?? '';
            $calificaciones = $_POST['calificaciones'] ?? [];
        
            // Verificar que el parcial pertenece al grupo
            $sql = "SELECT 1 FROM parciales WHERE id = ? AND grupo_id = ?";
            $stmt = $conexion->prepare($sql);
            $stmt->bind_param("ii", $parcialId, $grupoId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $message = 'El parcial no pertenece al grupo seleccionado';
                $type = 'error';
                logMessage("Error: El parcial $parcialId no pertenece al grupo $grupoId");
                header("Location: docente.php?grupo_calif=$grupoId&parcial=$parcialId#calificaciones-section&message=" . urlencode($message) . "&type=" . urlencode($type));
                exit();
            }
        
            // Iniciar transacción
            $conexion->begin_transaction();
            try {
                foreach ($calificaciones as $alumnoId => $data) {
                    $calificacion = min(max((float)($data['calificacion'] ?? 0), 0), 10);
                    $penalizacion = min(max((float)($data['penalizacion'] ?? 0), 0), $calificacion);
                    $total = $calificacion - $penalizacion;
        
                    // Insertar en calificaciones_historial
                    $sqlHistorial = "INSERT INTO calificaciones_historial 
                                    (alumno_id, parcial_id, calificacion_anterior, calificacion_nueva, modificado_por) 
                                    SELECT ?, ?, IFNULL(c.calificacion, 0), ?, ? 
                                    FROM calificaciones c 
                                    WHERE c.alumno_id = ? AND c.parcial_id = ?";
                    $stmtHistorial = $conexion->prepare($sqlHistorial);
                    $stmtHistorial->bind_param("iiidii", $alumnoId, $parcialId, $calificacion, $docenteId, $alumnoId, $parcialId);
                    $stmtHistorial->execute();
        
                    // Insertar o actualizar en calificaciones
                    $sqlCalificaciones = "INSERT INTO calificaciones 
                                        (alumno_id, parcial_id, calificacion, asistencia_penalizacion, total) 
                                        VALUES (?, ?, ?, ?, ?) 
                                        ON DUPLICATE KEY UPDATE 
                                            calificacion = VALUES(calificacion), 
                                            asistencia_penalizacion = VALUES(asistencia_penalizacion), 
                                            total = VALUES(total)";
                    $stmtCalificaciones = $conexion->prepare($sqlCalificaciones);
                    $stmtCalificaciones->bind_param("iiddd", $alumnoId, $parcialId, $calificacion, $penalizacion, $total);
                    $stmtCalificaciones->execute();
        
                    // Verificar si es el tercer parcial (opcional, para depuración)
                    $sqlCountParciales = "SELECT COUNT(*) AS total_parciales FROM parciales WHERE grupo_id = ?";
                    $stmtCount = $conexion->prepare($sqlCountParciales);
                    $stmtCount->bind_param("i", $grupoId);
                    $stmtCount->execute();
                    $totalParciales = $stmtCount->get_result()->fetch_assoc()['total_parciales'];
        
                    $sqlSavedParciales = "SELECT COUNT(DISTINCT parcial_id) AS saved_parciales 
                                          FROM calificaciones 
                                          WHERE alumno_id = ? AND parcial_id IN (SELECT id FROM parciales WHERE grupo_id = ?)";
                    $stmtSaved = $conexion->prepare($sqlSavedParciales);
                    $stmtSaved->bind_param("ii", $alumnoId, $grupoId);
                    $stmtSaved->execute();
                    $savedParciales = $stmtSaved->get_result()->fetch_assoc()['saved_parciales'];
        
                    logMessage("Guardando calificación para alumno $alumnoId: Calificación=$calificacion, Penalización=$penalizacion, Total=$total");
                }
        
                $conexion->commit();
                $message = 'Calificaciones guardadas con éxito';
                $type = 'success';
                logMessage("Transacción completada exitosamente para grupo $grupoId, parcial $parcialId");
            } catch (Exception $e) {
                $conexion->rollback();
                $message = 'Error al guardar las calificaciones';
                $type = 'error';
                logMessage("Error al guardar calificaciones: " . $e->getMessage());
          
   
            }
              $redirectUrl = "docente.php?grupo_calif=$grupoId&parcial=$parcialId&message=" . urlencode($message) . "&type=" . urlencode($type) . "#calificaciones-section";
    logMessage("Redirigiendo a: $redirectUrl");
    header("Location: $redirectUrl");
    exit();

        case 'save_asistencia':
            $grupoId = $_POST['grupo_id'] ?? '';
            $fecha = $_POST['fecha'] ?? date('Y-m-d');
            $asistencias = $_POST['asistencias'] ?? [];
        
            logMessage("Guardando asistencia para grupo $grupoId en fecha $fecha");
        
            foreach ($asistencias as $alumnoId => $tipo) {
                $sql = "INSERT INTO asistencias (alumno_id, grupo_id, fecha, tipo) 
                        VALUES (?, ?, ?, ?) 
                        ON DUPLICATE KEY UPDATE tipo = VALUES(tipo)";
                $stmt = $conexion->prepare($sql);
                $stmt->bind_param("iiss", $alumnoId, $grupoId, $fecha, $tipo);
                $stmt->execute();
                
                logMessage("Asistencia guardada para alumno $alumnoId: Tipo=$tipo");
            }
        
            $message = 'Asistencia guardada con éxito';
            $type = 'success';
            header("Location: docente.php#asistencias-section?message=" . urlencode($message) . "&type=" . urlencode($type));
            exit();

        case 'save_observacion':
            $grupoId = $_POST['grupo_id'] ?? '';
            $alumnoId = $_POST['alumno_id'] ?? '';
            $observacion = trim($_POST['observacion'] ?? '');

            if (!empty($observacion)) {
                $sql = "INSERT INTO observaciones (alumno_id, grupo_id, observacion, fecha) 
                        VALUES (?, ?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE observacion = VALUES(observacion), fecha = NOW()";
                $stmt = $conexion->prepare($sql);
                $stmt->bind_param("iis", $alumnoId, $grupoId, $observacion);
                $stmt->execute();

                logMessage("Observación guardada para alumno $alumnoId en grupo $grupoId: Observación='$observacion'");
                $message = 'Observación guardada con éxito';
                $type = 'success';
            } else {
                logMessage("Error: La observación no puede estar vacía para el alumno $alumnoId en grupo $grupoId");
                $message = 'La observación no puede estar vacía';
                $type = 'error';
            }
            header("Location: docente.php#observaciones-section?message=" . urlencode($message) . "&type=" . urlencode($type));
            exit();
                        case 'crear_materia':
                        logMessage("Procesando acción 'crear_materia'");
                        $nombre = trim($_POST['nombre_materia'] ?? '');
                        $descripcion = trim($_POST['descripcion'] ?? '');
                        logMessage("Datos recibidos - Nombre: '$nombre', Descripción: '$descripcion'");
            
                        if (empty($nombre)) {
                            $message = "El nombre de la materia es obligatorio.";
                            $type = "error";
                            logMessage("Error: Nombre vacío");
                        } else {
                            logMessage("Intentando crear materia con createMateria()");
                            if (createMateria($nombre, $descripcion, $conexion)) {
                                $message = "Materia creada con éxito.";
                                $type = "success";
                                logMessage("Materia creada exitosamente");
                            } else {
                                $message = "Error al crear la materia.";
                                $type = "error";
                                logMessage("Error en createMateria: " . $conexion->error);
                            }
                        }
                        logMessage("Redirigiendo a materias-section con mensaje: '$message', tipo: '$type'");
                        header("Location: docente.php#materias-section?message=" . urlencode($message) . "&type=" . urlencode($type));
                        exit();
                                  
        case 'editar_materia':
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            $nombre = trim($_POST['nombre_materia'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            if (empty($nombre)) {
                $message = "El nombre de la materia es obligatorio.";
                $type = "error";
            } else {
                if (updateMateria($materiaId, $nombre, $descripcion, $conexion)) {
                    $message = "Materia actualizada con éxito.";
                    $type = "success";
                } else {
                    $message = "Error al actualizar la materia.";
                    $type = "error";
                }
            }
            header("Location: docente.php#materias-section?message=" . urlencode($message) . "&type=" . urlencode($type));
            exit();

        case 'eliminar_materia':
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            $sql = "SELECT 1 FROM grupos WHERE materia_id = ?";
            $stmt = $conexion->prepare($sql);
            $stmt->bind_param("i", $materiaId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $message = "No se puede eliminar: materia con grupos asociados.";
                $type = "error";
            } else {
                if (deleteMateria($materiaId, $conexion)) {
                    $message = "Materia eliminada con éxito.";
                    $type = "success";
                } else {
                    $message = "Error al eliminar la materia.";
                    $type = "error";
                }
            }
            header("Location: docente.php#materias-section?message=" . urlencode($message) . "&type=" . urlencode($type));
            exit();

        default:
            logMessage("Acción desconocida: " . $_POST['action']);
            $message = 'Acción no válida';
            $type = 'error';
            header("Location: docente.php?message=" . urlencode($message) . "&type=" . urlencode($type));
            exit();
    }
}

// Obtener asistencias registradas
$asistenciasRegistradas = getAsistenciasRegistradas($selectedGrupoAsist, $selectedFecha, $conexion);
$alumnos = getAlumnos($selectedGrupoAsist, $conexion);

// Crear un array asociativo de asistencias registradas
$asistenciasRegistradasMap = [];
if (!empty($asistenciasRegistradas)) {
    foreach ($asistenciasRegistradas as $asistencias) {
        if (isset($asistencias['id']) && isset($asistencias['tipo'])) {
            $asistenciasRegistradasMap[$asistencias['id']] = $asistencias['tipo'];
        }
    }
}
    
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&display=swap" rel="stylesheet">
    <title>Panel Docente - <?php echo htmlspecialchars($docenteNombre); ?></title>
    <link rel="stylesheet" href="docentes.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header class="docente-header">
        <div class="header-content">
            <div class="logo-container">
                <img src="images/logo.jpg" alt="Logo" class="logo-img">
                <h1 class="docente-title">Panel Docente - 
                    <?php echo htmlspecialchars($docenteNombre); ?></h1>
                
            </div>
           
            <button class="menu-toggle" aria-label="Toggle navigation">☰</button>
             <nav class="docente-nav" id="sidebar">
             <div class="nav-overlay"></div>
    <ul>  
        <li><a href="#dashboard-section" class="nav-link">Dashboard</a></li>
        <li><a href="#calificaciones-section" class="nav-link">Calificaciones</a></li>
        <li><a href="#asistencias-section" class="nav-link">Asistencias</a></li>
        <li><a href="#grupos-section" class="nav-link">Grupos</a></li>
        <li><a href="#materias-section" class="nav-link">Materias</a></li>
        <li><a href="#reportes-section" class="nav-link">Reportes</a></li>
        <li><a href="#observaciones-section" class="nav-link">Observaciones</a></li>
        <li><a href="logout.php" class="logout-button">Cerrar Sesión</a></li>
    </ul>
    </nav>
    </header>

    <main class="docente-main">
      
        <!-- Dashboard -->

        <section class="docente-section" id="dashboard-section">
            <?php echo "<!-- Depuración: Renderizando Dashboard -->"; logMessage("Renderizando Dashboard"); ?>
            <h2 class="section-title">Dashboard</h2>
            <div class="group-card">
                <h3>Total de Alumnos</h3>
                <p><?php echo array_sum(array_map(fn($clase) => contarAlumnosGrupo($clase['id'], $conexion), $clases)); ?></p>
            </div>
            <div class="group-card">
                <h3>Especialidad</h3>
                <p><?php echo htmlspecialchars($docenteEspecialidad); ?></p>
            </div>
            <h3>Gestión de Clases</h3>
            <div class="docente-table-wrapper">
                <table class="docente-table">
                    <thead><tr><th>Grupo</th><th>Materia</th><th>Semestre</th><th>Alumnos</th></tr></thead>
                    <tbody>
                        <?php foreach ($clases as $clase): ?>
                            <tr>
                                <td data-label="Grupo"><?php echo htmlspecialchars($clase['nombre_grupo']); ?></td>
                                <td data-label="Materia"><?php echo htmlspecialchars($clase['nombre_materia']); ?></td>
                                <td data-label="Semestre"><?php echo htmlspecialchars($clase['semestre_numero']); ?></td>
                                <td data-label="Alumnos"><?php echo contarAlumnosGrupo($clase['id'], $conexion); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    <h3>Estadísticas de Calificaciones</h3>
    <div class="docente-chart-wrapper">
        <canvas id="gradeChart"></canvas>
    </div>
</section>


  <!-- Calificaciones -->
<section class="docente-section" id="calificaciones-section">
    <?php echo "<!-- Depuración: Renderizando Calificaciones -->"; logMessage("Renderizando Calificaciones"); ?>
    <h2 class="section-title">Registrar Calificaciones</h2>
    <div class="docente-form">
        <!-- Formulario para seleccionar Grupo -->
        <form method="GET" action="docente.php#calificaciones-section">
            <div class="form-group">
                <label for="grupo_calif">Grupo:</label>
                <select name="grupo_calif" id="grupoCalif" onchange="this.form.submit()">
                    <?php if (empty($clases)): ?>
                        <option value="">No hay grupos asignados</option>
                    <?php else: ?>
                        <?php foreach ($clases as $clase): ?>
                            <option value="<?php echo $clase['id']; ?>" <?php echo $selectedGrupoCalif == $clase['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($clase['nombre_grupo'] . ' - ' . $clase['nombre_materia']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>

        <!-- Formulario para seleccionar Parcial -->
        <form method="GET" action="docente.php#calificaciones-section">
            <div class="form-group">
                <label for="parcial">Parcial:</label>
                <select name="parcial" id="parcial" onchange="this.form.submit()">
                    <option value="">Selecciona un parcial</option>
                    <?php $parciales = getParciales($selectedGrupoCalif, $conexion); ?>
                    <?php if (empty($parciales)): ?>
                        <option value="">No hay parciales disponibles</option>
                    <?php else: ?>
                        <?php foreach ($parciales as $parcial): ?>
                            <option value="<?php echo $parcial['id']; ?>" <?php echo $selectedParcial == $parcial['id'] ? 'selected' : ''; ?>>
                                Parcial <?php echo $parcial['numero_parcial']; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>

        <?php if ($selectedParcial): ?>
            <!-- Formulario para guardar calificaciones -->
            <form method="POST" action="docente.php#calificaciones-section" onsubmit="return validateForm(event)">
                <input type="hidden" name="action" value="save_calificaciones">
                <input type="hidden" name="grupo_id" value="<?php echo $selectedGrupoCalif; ?>">
                <input type="hidden" name="parcial_id" value="<?php echo $selectedParcial; ?>">
                <div class="docente-table-wrapper">
                    <table class="docente-table" id="tablaCalificaciones">
                        <thead>
                            <tr>
                                <th>Alumno</th>
                                <th>Calificación</th>
                                <th>Penalización</th>
                                <th>Total</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (getCalificaciones($selectedGrupoCalif, $selectedParcial, $conexion) as $alumno): ?>
                                <tr>
                                    <td data-label="Alumno"><?php echo htmlspecialchars($alumno['nombre_completo']); ?></td>
                                    <td data-label="Calificación">
                                        <input type="number" min="0" max="10" step="0.1" name="calificaciones[<?php echo $alumno['id']; ?>][calificacion]" value="<?php echo $alumno['calificacion'] ?? 0; ?>">
                                    </td>
                                    <td data-label="Penalización">
                                        <input type="number" min="0" max="10" step="0.1" name="calificaciones[<?php echo $alumno['id']; ?>][penalizacion]" value="<?php echo $alumno['asistencia_penalizacion'] ?? 0; ?>">
                                    </td>
                                    <td data-label="Total"><?php echo number_format($alumno['total'] ?? 0, 1); ?></td>
                                    <td data-label="Estado">
                                        <span class="calificacion-status <?php echo ($alumno['total'] ?? 0) >= 6 ? 'aprobado' : 'reprobado'; ?>">
                                            <?php echo ($alumno['total'] ?? 0) >= 6 ? 'Aprobado' : 'Reprobado'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <input type="submit" value="Guardar Calificaciones">
            </form>
        <?php endif; ?>
    </div>
</section>

<!-- Asistencias -->
<section class="docente-section asistencias-section" id="asistencias-section">
    <?php echo "<!-- Depuración: Renderizando Asistencias -->"; logMessage("Renderizando Asistencias"); ?>
    <h2 class="section-title">Tomar Asistencia</h2>
    <div class="docente-form asistencias-form">
              <form method="GET" action="docente.php#asistencias-section">
            <div class="form-group">
                <label for="grupo_asist">Grupo:</label>
                <select name="grupo_asist" id="grupoAsistencia" onchange="this.form.submit()">
                    <?php if (empty($clases)): ?>
                        <option value="">No hay grupos asignados</option>
                    <?php else: ?>
                        <?php foreach ($clases as $clase): ?>
                            <option value="<?php echo $clase['id']; ?>" <?php echo $selectedGrupoAsist == $clase['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($clase['nombre_grupo'] . ' - ' . $clase['nombre_materia']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="fecha">Fecha:</label>
                <input type="date" name="fecha" id="fecha" value="<?php echo $selectedFecha; ?>" onchange="this.form.submit()">
            </div>
        </form>
        <?php if ($selectedGrupoAsist && $selectedFecha): ?>
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_asistencia">
                <input type="hidden" name="grupo_id" value="<?php echo $selectedGrupoAsist; ?>">
                <input type="hidden" name="fecha" value="<?php echo $selectedFecha; ?>">
                <div class="docente-table-wrapper asistencias-table-wrapper">
                    <table class="docente-table asistencias-table" id="tablaAsistencia">
                        <thead><tr><th>Alumno</th><th>Asistencia</th><th>Registrada</th></tr></thead>
                        <tbody>
                            <?php foreach ($alumnos as $alumno): ?>
                                <tr>
                                    <td data-label="Alumno"><?php echo htmlspecialchars($alumno['nombre_completo']); ?></td>
                                    <td data-label="Asistencia">
    <select name="asistencias[<?php echo $alumno['id']; ?>]">
        <option value="Presente" <?php echo (isset($asistenciasRegistradasMap[$alumno['id']]) && $asistenciasRegistradasMap[$alumno['id']] === 'Presente') ? 'selected' : ''; ?>>Presente</option>
        <option value="Falta" <?php echo (isset($asistenciasRegistradasMap[$alumno['id']]) && $asistenciasRegistradasMap[$alumno['id']] === 'Falta') ? 'selected' : ''; ?>>Falta</option>
        <option value="Falta Justificada" <?php echo (isset($asistenciasRegistradasMap[$alumno['id']]) && $asistenciasRegistradasMap[$alumno['id']] === 'Falta Justificada') ? 'selected' : ''; ?>>Falta Justificada</option>
    </select>
</td>
<td data-label="Registrada">
    <?php
    $tipoAsistencia = isset($asistenciasRegistradasMap[$alumno['id']]) ? $asistenciasRegistradasMap[$alumno['id']] : 'No registrada';
    echo htmlspecialchars($tipoAsistencia);
    ?>
</td>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <input type="submit" value="Guardar Asistencia">
            </form>
        <?php endif; ?>
    </div>
</section>

        <!-- Grupos -->
        <section class="docente-section" id="grupos-section">
            <?php echo "<!-- Depuración: Renderizando Grupos -->"; logMessage("Renderizando Grupos"); ?>
            <h2 class="section-title">Grupos Asignados</h2>
            <div class="docente-groups">
                <?php foreach ($clases as $clase): ?>
                    <div class="group-card" onclick="window.location.href='?grupo_id=<?php echo $clase['id']; ?>'">
                        <h3><?php echo htmlspecialchars($clase['nombre_grupo']); ?></h3>
                        <p>Materia: <?php echo htmlspecialchars($clase['nombre_materia']); ?></p>
                        <p>Semestre: <?php echo htmlspecialchars($clase['semestre_numero']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (isset($_GET['grupo_id'])): $detalles = getDetallesGrupo($_GET['grupo_id'], $conexion); ?>
                <div id="detallesGrupo" class="docente-form">
                    <h3>Detalles del Grupo</h3>
                    <p>Grado: <?php echo htmlspecialchars($detalles['grado']); ?></p>
                    <p>Letra: <?php echo htmlspecialchars($detalles['letra_grupo']); ?></p>
                    <p>Materia: <?php echo htmlspecialchars($detalles['nombre_materia']); ?></p>
                    <p>Semestre: <?php echo htmlspecialchars($detalles['semestre_numero']); ?></p>
                    <p>Generación: <?php echo htmlspecialchars($detalles['generacion_id']); ?></p>
                    <h4>Alumnos</h4>
                    <ul>
                        <?php foreach ($detalles['alumnos'] as $alumno): ?>
                            <li><?php echo htmlspecialchars($alumno['nombre_completo']) . ' (NIA: ' . $alumno['nia'] . ')'; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>


<!-- Sección: Materias -->
<section class="docente-section" id="materias-section">
    <h2 class="section-title">Gestión de Materias</h2>
    <div class="docente-form">
        <button data-modal="modal-crear-materia" class="docente-button">
            Nueva Materia
        </button>
        <div class="docente-table-wrapper">
            <table class="docente-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Descripción</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $materias = getAllMaterias($conexion); ?>
                    <?php foreach ($materias as $materia): ?>
                        <tr>
                            <td data-label="ID"><?php echo htmlspecialchars($materia['id']); ?></td>
                            <td data-label="Nombre"><?php echo htmlspecialchars($materia['nombre']); ?></td>
                            <td data-label="Descripción"><?php echo htmlspecialchars($materia['descripcion']); ?></td>
                            <td data-label="Acciones">
                                <button data-modal="modal-editar-materia-<?php echo $materia['id']; ?>" class="docente-button">
                                    Editar
                                </button>
                                <button data-modal="modal-eliminar-materia-<?php echo $materia['id']; ?>" class="docente-button">
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Modal Crear Materia -->
 
<div id="modal-crear-materia" class="docente-modal">
    <div class="docente-modal-content">
        <button class="modal-close">X</button>
        <h3>Crear Materia</h3>
        <form class="docente-form" method="POST">
            <div class="form-group">
                <label for="nombre_materia">Nombre</label>
                <input type="text" name="nombre_materia" id="nombre_materia" required>
            </div>
            <div class="form-group">
                <label for="descripcion">Descripción</label>
                <textarea name="descripcion" id="descripcion"></textarea>
            </div>
            <input type="hidden" name="action" value="crear_materia">
            <input type="submit" value="Crear">
        </form>
    </div>
</div>

<!-- Modal Editar Materia -->
<?php foreach ($materias as $materia): ?>
    <div id="modal-editar-materia-<?php echo $materia['id']; ?>" class="docente-modal">
        <div class="docente-modal-content">
        <button class="modal-close">X</button>
            <h3>Editar Materia</h3>
            <form class="docente-form" method="POST">
                <div class="form-group">
                    <label for="nombre_materia_<?php echo $materia['id']; ?>">Nombre</label>
                    <input type="text" name="nombre_materia" id="nombre_materia_<?php echo $materia['id']; ?>" value="<?php echo htmlspecialchars($materia['nombre']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="descripcion_<?php echo $materia['id']; ?>">Descripción</label>
                    <textarea name="descripcion" id="descripcion_<?php echo $materia['id']; ?>"><?php echo htmlspecialchars($materia['descripcion']); ?></textarea>
                </div>
                <input type="hidden" name="materia_id" value="<?php echo $materia['id']; ?>">
                <input type="hidden" name="action" value="editar_materia">
                <input type="submit" value="Guardar">
            </form>
        </div>
    </div>
<?php endforeach; ?>


<!-- Modal Eliminar Materia -->
<?php foreach ($materias as $materia): ?>
    <div id="modal-eliminar-materia-<?php echo $materia['id']; ?>" class="docente-modal">
        <div class="docente-modal-content">
            <button class="modal-close">X</button>
            <h3>Eliminar Materia</h3>
            <p>¿Estás seguro de que deseas eliminar la materia "<?php echo htmlspecialchars($materia['nombre']); ?>"?</p>
            <form class="docente-form" method="POST">
                <input type="hidden" name="materia_id" value="<?php echo $materia['id']; ?>">
                <input type="hidden" name="action" value="eliminar_materia">
                <div class="button-container">
                    <input type="submit" value="Sí, eliminar" class="docente-button">
                    <button type="button" class="modal-closes">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

           
<!-- Reportes -->
<section class="docente-section" id="reportes-section">
    <h2 class="section-title">Generar Reportes</h2>
    <div class="docente-form">
        <form method="GET" action="docente.php#reportes-section">
            <div class="form-group">
                <label for="tipo_reporte">Tipo de Reporte:</label>
                <select name="tipo_reporte" id="tipoReporte" onchange="this.form.submit()">
                    <option value="promedios" <?php echo $selectedTipoReporte === 'promedios' ? 'selected' : ''; ?>>Promedios por Semestre</option>
                    <option value="asistencias" <?php echo $selectedTipoReporte === 'asistencias' ? 'selected' : ''; ?>>Estadísticas de Asistencia</option>
                    <option value="historial_calificaciones" <?php echo $selectedTipoReporte === 'historial_calificaciones' ? 'selected' : ''; ?>>Historial de Calificaciones</option>
                    <option value="suma_semestre" <?php echo $selectedTipoReporte === 'suma_semestre' ? 'selected' : ''; ?>>Suma de Parciales por Semestre</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="grupo_reporte">Grupo:</label>
                <select name="grupo_reporte" id="grupo_reporte" onchange="this.form.submit()">
                    <?php if (empty($clases)): ?>
                        <option value="">No hay grupos asignados</option>
                    <?php else: ?>
                        <?php foreach ($clases as $clase): ?>
                            <option value="<?php echo $clase['id']; ?>" <?php echo $selectedGrupoReporte == $clase['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($clase['nombre_grupo'] . ' - ' . $clase['nombre_materia']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>

        <?php if ($selectedGrupoReporte && $selectedTipoReporte): ?>
            <div class="docente-table-wrapper">
                <?php $reporte = getReporte($selectedTipoReporte, $selectedGrupoReporte, $conexion); ?>
                
                <?php if (!empty($reporte)): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="download_pdf">
                        <input type="hidden" name="tipo_reporte" value="<?php echo $selectedTipoReporte; ?>">
                        <input type="hidden" name="grupo_reporte" value="<?php echo $selectedGrupoReporte; ?>">
                        <button type="submit" class="btn-download">Descargar PDF</button>
                    </form>
                    
                    <table class="docente-table">
                        <thead>
                            <tr>
                                <?php if ($selectedTipoReporte === 'asistencias'): ?>
                                    <th>Alumno</th>
                                    <th>Presente</th>
                                    <th>Faltas</th>
                                    <th>Justificadas</th>
                                <?php elseif ($selectedTipoReporte === 'historial_calificaciones'): ?>
                                    <th>Alumno</th>
                                    <th>Parcial</th>
                                    <th>Calificación</th>
                                    <th>Penalización</th>
                                    <th>Total</th>
                                <?php elseif ($selectedTipoReporte === 'suma_semestre'): ?>
                                    <th>Alumno</th>
                                    <th>Parcial 1</th>
                                    <th>Parcial 2</th>
                                    <th>Parcial 3</th>
                                    <th>Suma Total</th>
                                    <th>Estado</th>
                                <?php elseif ($selectedTipoReporte === 'promedios'): ?>
                                    <th>Alumno</th>
                                    <th>Promedio</th>
                                    <th>Estado</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporte as $row): ?>
                                <tr>
                                    <td data-label="Alumno"><?php echo htmlspecialchars($row['nombre_completo']); ?></td>
                                    
                                    <?php if ($selectedTipoReporte === 'asistencias'): ?>
                                        <td data-label="Presente"><?php echo $row['presente']; ?></td>
                                        <td data-label="Faltas"><?php echo $row['falta']; ?></td>
                                        <td data-label="Justificadas"><?php echo $row['falta_justificada']; ?></td>
                                    <?php elseif ($selectedTipoReporte === 'historial_calificaciones'): ?>
                                        <td data-label="Parcial"><?php echo $row['numero_parcial']; ?></td>
                                        <td data-label="Calificación"><?php echo number_format($row['calificacion'], 1); ?></td>
                                        <td data-label="Penalización"><?php echo number_format($row['asistencia_penalizacion'], 1); ?></td>
                                        <td data-label="Total"><?php echo number_format($row['total'], 1); ?></td>
                                    <?php elseif ($selectedTipoReporte === 'suma_semestre'): ?>
                                        <td data-label="Parcial 1"><?php echo number_format($row['parcial1'] ?? 0, 1); ?></td>
                                        <td data-label="Parcial 2"><?php echo number_format($row['parcial2'] ?? 0, 1); ?></td>
                                        <td data-label="Parcial 3"><?php echo number_format($row['parcial3'] ?? 0, 1); ?></td>
                                        <td data-label="Suma Total"><?php echo number_format($row['suma_total'], 1); ?></td>
                                        <td data-label="Estado" class="<?php echo $row['estado'] === 'Aprobado' ? 'success' : 'error'; ?>">
                                            <?php echo $row['estado']; ?>
                                        </td>
                                    <?php elseif ($selectedTipoReporte === 'promedios'): ?>
                                        <td data-label="Promedio"><?php echo number_format($row['promedio'], 2); ?></td>
                                        <td data-label="Estado" class="<?php echo $row['promedio'] >= 6 ? 'success' : 'error'; ?>">
                                            <?php echo $row['promedio'] >= 6 ? 'Aprobado' : 'Reprobado'; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No hay datos disponibles para este reporte.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

        <!-- Observaciones -->
        <section class="docente-section" id="observaciones-section">
            <?php echo "<!-- Depuración: Renderizando Observaciones -->"; logMessage("Renderizando Observaciones"); ?>
            <h2 class="section-title">Comunicar Observaciones</h2>
            <div class="docente-form">
                            <form method="GET" action="docente.php#Observaciones-section">
                    <div class="form-group">
                        <label for="grupo_obs">Grupo:</label>
                        <select name="grupo_obs" id="grupoObservacion" onchange="this.form.submit()">
                            <?php if (empty($clases)): ?>
                                <option value="">No hay grupos asignados</option>
                            <?php else: ?>
                                <?php foreach ($clases as $clase): ?>
                                    <option value="<?php echo $clase['id']; ?>" <?php echo $selectedGrupoObs == $clase['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($clase['nombre_grupo'] . ' - ' . $clase['nombre_materia']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="alumno_obs">Alumno:</label>
                        <select name="alumno_obs" id="alumnoObservacion" onchange="this.form.submit()">
                            <option value="">Selecciona un alumno</option>

                            <?php $alumnos = getAlumnos($selectedGrupoObs, $conexion); ?>
                            <?php if (empty($alumnos)): ?>
                                <option value="">No hay alumnos en este grupo</option>
                            <?php else: ?>
                                <?php foreach ($alumnos as $alumno): ?>
                                    <option value="<?php echo $alumno['id']; ?>" <?php echo $selectedAlumnoObs == $alumno['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($alumno['nombre_completo']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </form>
                <?php if ($selectedAlumnoObs): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="save_observacion">
                        <input type="hidden" name="grupo_id" value="<?php echo $selectedGrupoObs; ?>">
                        <input type="hidden" name="alumno_id" value="<?php echo $selectedAlumnoObs; ?>">
                        <div class="form-group">
                            <label for="observacion">Observación:</label>
                            <textarea name="observacion" id="observacion" rows="4"><?php echo htmlspecialchars(getObservacion($selectedAlumnoObs, $selectedGrupoObs, $conexion)); ?></textarea>
                        </div>
                        <input type="submit" value="Guardar">
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="docente-footer">
        <p>© <?php echo date('Y'); ?> Sistema de Gestión Escolar</p>
    </footer>


<script>
    window.gradeStats = <?php echo json_encode($gradeStats); ?>;
</script>
    <script src="js/docente.js"></script>
</body>
</html>