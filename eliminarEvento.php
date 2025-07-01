<?php
session_start();
require 'database.php';

if (!isset($_SESSION['usuario']) || $_SESSION['tipo_usuario'] != 'administrativo') {
    header('Location: login.php');
    exit();
}

if (isset($_GET['id'])) {
    // Eliminar imagen asociada primero
    $stmt = $pdo->prepare("SELECT ruta_foto FROM eventos WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $evento = $stmt->fetch();
    
    if (file_exists($evento['ruta_foto'])) {
        unlink($evento['ruta_foto']);
    }

    $pdo->prepare("DELETE FROM eventos WHERE id = ?")->execute([$_GET['id']]);
    
    $_SESSION['notificacion'] = "Evento eliminado correctamente";
    $_SESSION['notificacion_tipo'] = "success";
}

header('Location: administrativo.php');