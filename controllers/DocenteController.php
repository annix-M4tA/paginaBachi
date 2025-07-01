<?php
session_start();
require 'conexion.php';

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'getParciales':
        $grupoId = $_GET['grupo_id'];
        $stmt = $conexion->prepare("SELECT id, numero_parcial FROM parciales WHERE grupo_id = ?");
        $stmt->bind_param("i", $grupoId);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        break;

    case 'getCalificaciones':
        $parcialId = $_GET['parcial_id'];
        $stmt = $conexion->prepare("
            SELECT a.id, a.nombre, c.calificacion
            FROM alumnos a
            LEFT JOIN calificaciones c ON a.id = c.alumno_id AND c.parcial_id = ?
            JOIN alumnos_grupos ag ON a.id = ag.alumno_id
            WHERE ag.grupo_id = (SELECT grupo_id FROM parciales WHERE id = ?)
        ");
        $stmt->bind_param("ii", $parcialId, $parcialId);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        break;

    case 'actualizarCalificaciones':
        $data = json_decode(file_get_contents('php://input'), true)['data'];
        foreach ($data as $calif) {
            $stmt = $conexion->prepare("
                INSERT INTO calificaciones (alumno_id, parcial_id, calificacion)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE calificacion = ?
            ");
            $stmt->bind_param("iidd", $calif['alumno_id'], $calif['parcial_id'], $calif['calificacion'], $calif['calificacion']);
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
        break;

    case 'getAlumnos':
        $grupoId = $_GET['grupo_id'];
        $stmt = $conexion->prepare("
            SELECT a.id, a.nombre
            FROM alumnos a
            JOIN alumnos_grupos ag ON a.id = ag.alumno_id
            WHERE ag.grupo_id = ?
        ");
        $stmt->bind_param("i", $grupoId);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        break;

    case 'guardarAsistencia':
        $data = json_decode(file_get_contents('php://input'), true)['data'];
        foreach ($data as $asis) {
            $stmt = $conexion->prepare("
                INSERT INTO asistencias (alumno_id, grupo_id, fecha, tipo)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE tipo = ?
            ");
            $stmt->bind_param("iisss", $asis['alumno_id'], $asis['grupo_id'], $asis['fecha'], $asis['tipo'], $asis['tipo']);
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
        break;

    case 'getGrupoDetalles':
        $grupoId = $_GET['grupo_id'];
        $stmt = $conexion->prepare("
            SELECT g.grado, g.letra_grupo AS letra, m.nombre AS materia, s.numero AS semestre, gen.nombre AS generacion
            FROM grupos g
            JOIN materias m ON g.materia_id = m.id
            JOIN semestres s ON g.semestre_id = s.id
            JOIN generaciones gen ON s.generacion_id = gen.id
            WHERE g.id = ?
        ");
        $stmt->bind_param("i", $grupoId);
        $stmt->execute();
        $detalles = $stmt->get_result()->fetch_assoc();
        $stmt = $conexion->prepare("
            SELECT a.nombre
            FROM alumnos a
            JOIN alumnos_grupos ag ON a.id = ag.alumno_id
            WHERE ag.grupo_id = ?
        ");
        $stmt->bind_param("i", $grupoId);
        $stmt->execute();
        $alumnos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(array_merge($detalles, ['alumnos' => $alumnos]));
        break;

    case 'getGrupos':
        $docenteId = $_SESSION['usuario_id'];
        $stmt = $conexion->prepare("
            SELECT g.id, CONCAT(g.grado, '° ', g.letra_grupo) AS nombre
            FROM grupos g
            WHERE g.docente_id = ?
        ");
        $stmt->bind_param("i", $docenteId);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        break;

    case 'getReporte':
        $tipo = $_GET['tipo'];
        $grupoId = $_GET['grupo_id'];
        if ($tipo === 'promedios') {
            $parcialId = $_GET['parcial_id'];
            $stmt = $conexion->prepare("
                SELECT AVG(calificacion) AS promedio
                FROM calificaciones
                WHERE parcial_id = ?
            ");
            $stmt->bind_param("i", $parcialId);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_assoc());
        } elseif ($tipo === 'asistencia') {
            $stmt = $conexion->prepare("
                SELECT 
                    SUM(CASE WHEN tipo = 'presente' THEN 1 ELSE 0 END) AS presentes,
                    SUM(CASE WHEN tipo = 'falta' THEN 1 ELSE 0 END) AS faltas,
                    SUM(CASE WHEN tipo = 'falta_justificada' THEN 1 ELSE 0 END) AS justificadas
                FROM asistencias
                WHERE grupo_id = ?
            ");
            $stmt->bind_param("i", $grupoId);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_assoc());
        }
        break;

    case 'getObservacion':
        $alumnoId = $_GET['alumno_id'];
        $stmt = $conexion->prepare("SELECT observacion FROM observaciones WHERE alumno_id = ?");
        $stmt->bind_param("i", $alumnoId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        echo json_encode($result ?? ['observacion' => '']);
        break;

    case 'guardarObservacion':
        $data = json_decode(file_get_contents('php://input'), true)['data'];
        $alumnoId = $data['alumno_id'];
        $observacion = $data['observacion'];
        $stmt = $conexion->prepare("
            INSERT INTO observaciones (alumno_id, observacion)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE observacion = ?
        ");
        $stmt->bind_param("iss", $alumnoId, $observacion, $observacion);
        $stmt->execute();
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
}
?>