<?php
session_start();
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json');

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado']);
    exit;
}

$query = "SELECT id, numero, generacion_id FROM semestres ORDER BY numero DESC, generacion_id DESC";
$result = $GLOBALS['conexion']->query($query);

if ($result) {
    $semestres = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($semestres);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error al cargar semestres']);
}

$GLOBALS['conexion']->close();
?>