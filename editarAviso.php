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
    // Consultar los datos del aviso a editar
    $query = "SELECT * FROM avisos WHERE id = ?";
    $stmt = $conexion->prepare($query);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $aviso = $resultado->fetch_assoc();
    
    if (!$aviso) {
        die("Aviso no encontrado.");
    }
} else {
    die("ID de aviso no válido.");
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Aviso</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="main-header">
        <nav class="main-nav">
            <a href="administrativo.php" class="nav-link">Volver a la Gestión</a>
            <a href="logout.php" class="login-button">Cerrar Sesión</a>
        </nav>
    </header>

    <main>
        <section class="form-section">
            <h2>Editar Aviso</h2>
            <form action="procesarEdicionAviso.php" method="POST">
                <input type="hidden" name="id" value="<?php echo $aviso['id']; ?>">
                <div class="form-group">
                    <label>Título del Aviso:</label>
                    <input type="text" name="titulo" value="<?php echo htmlspecialchars($aviso['titulo']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Contenido:</label>
                    <textarea name="contenido" required><?php echo htmlspecialchars($aviso['contenido']); ?></textarea>
                </div>
                <button type="submit" class="login-button">Actualizar Aviso</button>
            </form>
        </section>
    </main>
</body>
</html>

<?php
$conexion->close();
?>
