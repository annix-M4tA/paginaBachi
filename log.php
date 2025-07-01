<?php
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
if ($input && isset($input['message'])) {
    $logFile = 'admin_log.txt';
    $message = $input['message'] . PHP_EOL;
    file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Datos inválidos']);
}
?>