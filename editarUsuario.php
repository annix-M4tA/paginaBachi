<?php
require 'conexion.php';

if (!isset($_SESSION['usuario']) || $_SESSION['tipo_usuario'] != 'administrativo') {
    header('Location: login.php');
    exit;
}

$usuario = [];
if (isset($_GET['id'])) {
    $id = $conexion->real_escape_string($_GET['id']);
    $result = $conexion->query("SELECT * FROM usuarios WHERE id = $id");
    $usuario = $result->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $conexion->real_escape_string($_POST['id']);
    $nombre = $conexion->real_escape_string($_POST['nombre']);
    $correo = $conexion->real_escape_string($_POST['correo']);
    $tipo = $conexion->real_escape_string($_POST['tipo_usuario']);

    try {
        // Actualización básica
        $sql = "UPDATE usuarios SET
                nombre = '$nombre',
                correo = '$correo',
                tipo_usuario = '$tipo'
                WHERE id = $id";

        if ($conexion->query($sql)) {
            $_SESSION['notificacion'] = "✅ Usuario actualizado correctamente";
        } else {
            throw new Exception("Error al actualizar: " . $conexion->error);
        }

        // Actualizar contraseña si se proporcionó
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $conexion->query("UPDATE usuarios SET `contraseña` = '$password' WHERE id = $id");
        }

    } catch(Exception $e) {
        $_SESSION['notificacion'] = "❌ Error: " . $e->getMessage();
    } finally {
        header('Location: administrativo.php#usuarios');
        exit;
    }
}
?>

<!-- Formulario de edición (HTML) -->
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="administrativo.css">
</head>
<body>
    <div class="admin-modal active">
        <div class="modal-content">
            <h2>✏️ Editar Usuario</h2>
            <form method="POST">
                <input type="hidden" name="id" value="<?= $usuario['id'] ?? '' ?>">
                
                <div class="admin-form-group">
                    <label>Nombre completo:</label>
                    <input type="text" name="nombre" value="<?= htmlspecialchars($usuario['nombre'] ?? '') ?>" required>
                </div>
                
                <div class="admin-form-group">
                    <label>Correo electrónico:</label>
                    <input type="email" name="correo" value="<?= htmlspecialchars($usuario['correo'] ?? '') ?>">
                </div>
                
                <div class="admin-form-group">
                    <label>Tipo de usuario:</label>
                    <select name="tipo_usuario" required>
                        <?php foreach(['administrativo', 'docente', 'alumno'] as $tipo): ?>
                            <option value="<?= $tipo ?>" <?= ($usuario['tipo_usuario'] ?? '') == $tipo ? 'selected' : '' ?>>
                                <?= ucfirst($tipo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="admin-form-group">
                    <label>Nueva contraseña (opcional):</label>
                    <input type="password" name="password">
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="admin-button primary">
                        💾 Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>