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

// Recuperar los datos del formulario
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$titulo = isset($_POST['titulo']) ? trim($_POST['titulo']) : '';
$contenido = isset($_POST['contenido']) ? trim($_POST['contenido']) : '';

// Validar los datos
if ($id > 0 && !empty($titulo) && !empty($contenido)) {
    // Actualizar el aviso en la base de datos
    $query = "UPDATE avisos SET titulo = ?, contenido = ? WHERE id = ?";
    $stmt = $conexion->prepare($query);
    $stmt->bind_param('ssi', $titulo, $contenido, $id);
    
    if ($stmt->execute()) {
        header("Location: administrativo.php");
        exit();
    } else {
        echo "Error al actualizar el aviso.";
    }
} else {
    echo "Por favor, complete todos los campos.";
}

$conexion->close();
?>
