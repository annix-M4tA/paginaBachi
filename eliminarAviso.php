<?php
session_start();
if ($_SESSION['tipo_usuario'] !== 'administrativo') {
    header("Location: login.php");
    exit();
}

// Conexión a la base de datos
$conexion = new mysqli('localhost', 'erick', '', 'bachillerato');
if ($conexion->connect_error) {
    die("Error en la conexión: " . $conexion->connect_error);
}

// Obtener el ID del aviso
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    // Eliminar el aviso
    $query = "DELETE FROM avisos WHERE id = ?";
    $stmt = $conexion->prepare($query);
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        header("Location: administrativo.php");
        exit();
    } else {
        echo "Error al eliminar el aviso.";
    }
} else {
    echo "ID de aviso no válido.";
}

$conexion->close();
?>
