<?php
// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Configurar el encabezado para devolver JSON
header('Content-Type: application/json');

// Verificar token CSRF
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Error de seguridad: Token CSRF inválido.']);
    exit;
}

// Incluir archivo de conexión
require_once 'conexion.php';

// Verificar si se recibieron los datos del formulario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['username']) && isset($_POST['password']) && isset($_POST['tipo'])) {
    
    // Limpiar y obtener los datos
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $tipo = $_POST['tipo'];
    
    // Verificar que los campos no estén vacíos
    if (empty($username) || empty($password) || empty($tipo)) {
        echo json_encode(['success' => false, 'message' => 'Por favor complete todos los campos.']);
        exit;
    }
    
    // Verificar acceso a la base de datos
    if (!isset($conexion) || $conexion->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
        exit;
    }
    
    // Determinar si el usuario ingresó un correo o un nombre de usuario
    $isEmail = filter_var($username, FILTER_VALIDATE_EMAIL);
    
    // Preparar la consulta según el tipo de credencial
    if ($isEmail) {
        $sql = "SELECT id, correo, contraseña, nombre_usuario, tipo FROM usuarios WHERE correo = ? AND tipo = ?";
    } else {
        $sql = "SELECT id, correo, contraseña, nombre_usuario, tipo FROM usuarios WHERE nombre_usuario = ? AND tipo = ?";
    }
    
    // Preparar la consulta
    if ($stmt = $conexion->prepare($sql)) {
        // Vincular parámetros
        $stmt->bind_param("ss", $username, $tipo);
        
        // Ejecutar la consulta
        if ($stmt->execute()) {
            // Almacenar resultado
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                // Vincular variables de resultado
                $stmt->bind_result($id, $correo_db, $contrasena_hash, $nombre_usuario, $tipo_db);
                $stmt->fetch();
                
                // Verificar contraseña
                if (password_verify($password, $contrasena_hash)) {
                    // Guardar en sesión
                    $_SESSION['id'] = $id;
                    $_SESSION['correo'] = $correo_db;
                    $_SESSION['nombre_usuario'] = $nombre_usuario;
                    $_SESSION['tipo'] = $tipo_db;
                    $_SESSION['login_time'] = time();
                    $_SESSION['token'] = bin2hex(random_bytes(32));
                    
                    // Cerrar statement y conexión antes de la respuesta
                    $stmt->close();
                    $conexion->close();
                    
                    // Devolver éxito con la URL de redirección
                    echo json_encode(['success' => true, 'redirect' => "{$tipo_db}.php"]);
                    exit;
                } else {
                    // Contraseña incorrecta
                    $stmt->close();
                    $conexion->close();
                    echo json_encode(['success' => false, 'message' => 'Credenciales incorrectas. Intente nuevamente.']);
                    exit;
                }
            } else {
                // Usuario no encontrado
                $stmt->close();
                $conexion->close();
                echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.']);
                exit;
            }
        } else {
            // Error en la consulta
            $stmt->close();
            $conexion->close();
            echo json_encode(['success' => false, 'message' => 'Error al ejecutar la consulta.']);
            exit;
        }
    } else {
        // Error al preparar la consulta
        $conexion->close();
        echo json_encode(['success' => false, 'message' => 'Error al preparar la consulta.']);
        exit;
    }
} else {
    // No se enviaron los datos correctamente
    echo json_encode(['success' => false, 'message' => 'Datos del formulario incompletos.']);
    exit;
}
?>