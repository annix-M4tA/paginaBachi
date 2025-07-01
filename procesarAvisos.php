<?php
session_start();

// Verificar autenticación y método POST
if ($_SESSION['tipo_usuario'] !== 'administrativo' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit();
}

require_once 'conexion.php';

$titulo = $_POST['titulo'] ?? '';
$contenido = $_POST['contenido'] ?? '';

if (empty($titulo) || empty($contenido)) {
    echo "<script>
    alert('Por favor, complete todos los campos');
    window.location.href = 'administrativo.php';
    </script>";
    exit();
}

$query = $conexion->prepare("INSERT INTO avisos (titulo, contenido, usuario_id) VALUES (?, ?, ?)");
$query->bind_param('ssi', $titulo, $contenido, $_SESSION['id']);

if ($query->execute()) {
    echo "<script>
    alert('Aviso publicado correctamente');
    window.location.href = 'administrativo.php';
    </script>";
    exit();
} else {
    echo "<script>
    alert('Error al publicar aviso');
    window.location.href = 'administrativo.php';
    </script>";
    exit();
}
?>