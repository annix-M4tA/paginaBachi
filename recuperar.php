<?php
session_start();
require 'conexion.php'; // Conexión a la BD

// Función de sanitización
function sanitizar($input, $conexion) {
    $input = $input ?? '';
    return $conexion->real_escape_string(htmlspecialchars(trim($input)));
}

// Estado inicial
$mostrar_historial = false;
$usuario_verificado = null;

// Solicitud de recuperación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar'])) {
    $correo = sanitizar($_POST['correo'], $conexion);

    $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE correo = ?");
    $stmt->bind_param("s", $correo);
    $stmt->execute();
    $result = $stmt->get_result();
    $usuario = $result->fetch_assoc();
    $stmt->close();

    $identificador = bin2hex(random_bytes(16));
    $usuario_id = $usuario ? $usuario['id'] : null;

    $stmt = $conexion->prepare("INSERT INTO solicitudes_recuperacion (usuario_id, identificador, correo) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $usuario_id, $identificador, $correo);
    $stmt->execute();
    $stmt->close();

    $_SESSION['mensaje'] = "Solicitud enviada. Un administrador revisará tu petición pronto.";
    header("Location: recuperar.php");
    exit();
}

// Verificación para historial
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ver_historial'])) {
    $correo = sanitizar($_POST['correo'], $conexion);
    $nia_o_nombre = sanitizar($_POST['nia_o_nombre'], $conexion);

    $stmt = $conexion->prepare("
        SELECT u.id, u.tipo, u.nombre_completo, a.nia
        FROM usuarios u
        LEFT JOIN alumnos a ON u.id = a.usuario_id
        WHERE u.correo = ? AND (a.nia = ? OR u.nombre_completo = ?)
    ");
    $stmt->bind_param("sss", $correo, $nia_o_nombre, $nia_o_nombre);
    $stmt->execute();
    $result = $stmt->get_result();
    $usuario_verificado = $result->fetch_assoc();
    $stmt->close();

    if ($usuario_verificado) {
        $mostrar_historial = true;
        $historial = $conexion->query("
            SELECT fecha_cambio, '********' AS contrasena
            FROM historial_contrasenas 
            WHERE usuario_id = {$usuario_verificado['id']} 
            ORDER BY fecha_cambio DESC
        ")->fetch_all(MYSQLI_ASSOC) ?: [];
    } else {
        $_SESSION['mensaje'] = "Verificación fallida. Revisa tus datos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña</title>
    <link rel="stylesheet" href="css/estilos.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <h1>Recuperar Contraseña</h1>

    <?php if (isset($_SESSION['mensaje'])): ?>
        <p class="mensaje"><?php echo htmlspecialchars($_SESSION['mensaje']); unset($_SESSION['mensaje']); ?></p>
    <?php endif; ?>

    <!-- Formulario de solicitud -->
    <section class="admin-section">
        <h2>Solicitar Recuperación</h2>
        <form method="POST" class="admin-form">
            <div class="admin-form-group">
                <input type="email" id="correo" name="correo" required>
                <span class="floating-label">Correo institucional</span>
            </div>
            <button type="submit" name="solicitar" class="admin-button admin-button--primary">Enviar Solicitud</button>
        </form>
    </section>

    <!-- Formulario de verificación para historial -->
    <section class="admin-section">
        <h2>Ver Historial de Contraseñas</h2>
        <form method="POST" class="admin-form">
            <div class="admin-form-group">
                <input type="email" id="correo_historial" name="correo" required>
                <span class="floating-label">Correo institucional</span>
            </div>
            <div class="admin-form-group">
                <input type="text" id="nia_o_nombre" name="nia_o_nombre" required>
                <span class="floating-label">NIA (alumnos) o Nombre Completo (docentes/admin)</span>
            </div>
            <button type="submit" name="ver_historial" class="admin-button admin-button--primary">Verificar y Mostrar Historial</button>
        </form>
    </section>

    <!-- Historial de contraseñas -->
    <?php if ($mostrar_historial): ?>
        <section id="historial" class="admin-section">
            <h3>Historial de Contraseñas de <?php echo htmlspecialchars($usuario_verificado['nombre_completo']); ?></h3>
            <div class="responsive-table">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Fecha de Cambio</th>
                            <th>Contraseña</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historial as $entrada): ?>
                            <tr>
                                <td data-label="Fecha de Cambio"><?php echo htmlspecialchars($entrada['fecha_cambio']); ?></td>
                                <td data-label="Contraseña"><?php echo htmlspecialchars($entrada['contrasena']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <a href="index.php" class="admin-button admin-button--secondary"><i class="fas fa-arrow-left"></i> Volver al inicio</a>
</body>
</html>