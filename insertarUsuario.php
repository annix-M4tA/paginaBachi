<?php
// Incluir el archivo de conexión
require_once 'conexion.php';

// Función para crear usuarios de prueba
function crearUsuarioPrueba($conexion, $correo, $contrasena, $nombre_usuario, $nombre_completo, $telefono, $tipo) {
    // Hash de la contraseña
    $contrasena_hash = password_hash($contrasena, PASSWORD_DEFAULT);
    
    // Preparar la consulta SQL
    $sql = "INSERT INTO usuarios (correo, contraseña, nombre_usuario, nombre_completo, telefono, tipo) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    if ($stmt = $conexion->prepare($sql)) {
        // Vincular parámetros
        $stmt->bind_param("ssssss", $correo, $contrasena_hash, $nombre_usuario, $nombre_completo, $telefono, $tipo);
        
        // Ejecutar la consulta
        if ($stmt->execute()) {
            echo "Usuario '$nombre_usuario' ($tipo) creado correctamente.<br>";
            return true;
        } else {
            echo "Error al crear usuario '$nombre_usuario': " . $stmt->error . "<br>";
            return false;
        }
        
        // Cerrar sentencia
        $stmt->close();
    } else {
        echo "Error al preparar la consulta: " . $conexion->error . "<br>";
        return false;
    }
}

// Crear usuarios de prueba
echo "<h2>Creación de Usuarios de Prueba</h2>";

// Usuario tipo alumno
crearUsuarioPrueba(
    $conexion,
    "alumno@test.com",
    "alumno123",
    "alumno_test",
    "Alumno de Prueba",
    "1234567890",
    "alumno"
);

// Usuario tipo docente
crearUsuarioPrueba(
    $conexion,
    "docente@test.com",
    "docente123",
    "docente_test",
    "Docente de Prueba",
    "0987654321",
    "docente"
);

// Usuario tipo administrativo
crearUsuarioPrueba(
    $conexion,
    "admin@test.com",
    "admin123",
    "admin_test",
    "Administrador de Prueba",
    "5555555555",
    "administrativo"
);

// Cerrar la conexión
$conexion->close();

echo "<p>Proceso completado. Ahora puedes probar el login con estos usuarios.</p>";
echo "<a href='login.php'>Ir al formulario de login</a>";
?>