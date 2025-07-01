<?php
session_start();
require 'database.php';

if (!isset($_SESSION['usuario']) || $_SESSION['tipo_usuario'] != 'administrativo') {
    header('Location: login.php');
    exit();
}

$evento = [];
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM eventos WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $evento = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Lógica de actualización similar a creación
    $uploadDir = 'images/eventos/';
    $filename = $evento['ruta_foto'];
    
    if (!empty($_FILES['imagen']['name'])) {
        // Eliminar imagen anterior y subir nueva
    }

    $stmt = $pdo->prepare("UPDATE eventos SET 
        titulo = ?, fecha = ?, descripcion = ?, 
        ruta_foto = ?, tipo_evento = ?
        WHERE id = ?");
    
    if ($stmt->execute([
        $_POST['titulo'], $_POST['fecha'], $_POST['descripcion'],
        $filename, $_POST['tipo_evento'], $_POST['id']
    ])) {
        $_SESSION['notificacion'] = "Evento actualizado correctamente";
        $_SESSION['notificacion_tipo'] = "success";
        header('Location: administrativo.php');
    }
}
?>

<!-- Formulario similar al de creación pero en modo edición -->