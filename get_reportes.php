<?php
session_start();
require 'conexion.php'; // Asegúrate de que este archivo contiene la conexión a la BD

// Verificar si es una solicitud AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');

    try {
        // Consulta para obtener todos los reportes
        $stmt = $conexion->prepare("SELECT id, tipo_reporte, fecha_generado, ruta_archivo FROM reportes ORDER BY fecha_generado DESC");
        $stmt->execute();
        $result = $stmt->get_result();
        $reportes = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Devolver los reportes como JSON
        echo json_encode($reportes);
    } catch (Exception $e) {
        // Manejo de errores
        error_log("Error en get_reportes.php: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener los reportes']);
    }
    exit();
}

// Si no es una solicitud AJAX, devolver error
header('HTTP/1.1 403 Forbidden');
echo json_encode(['status' => 'error', 'message' => 'Acceso no permitido']);
exit();
?>