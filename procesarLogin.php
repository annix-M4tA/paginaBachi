<?php  
require_once 'conexion.php';  

// Activar reportes de errores de MySQLi
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Iniciar sesión solo si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si se recibieron datos del formulario  
if ($_SERVER["REQUEST_METHOD"] == "POST") {  
    
    // Obtener y validar datos  
    $username = trim($_POST['username'] ?? '');  
    $password = trim($_POST['password'] ?? '');  
    $tipo = trim($_POST['tipo'] ?? '');  // Usar 'tipo'
    
    // Mostrar datos recibidos para depuración
    echo "Datos recibidos:<br>";
    echo "Usuario/Correo: $username<br>";
    echo "Contraseña: $password<br>";
    echo "Tipo de Usuario: $tipo<br>";

    // Verificar que no estén vacíos  
    if (empty($username) || empty($password)) {  
        echo "Error: Los campos de usuario y contraseña son obligatorios.";
        exit;  
    }  
    
    // Preparar consulta (buscar por correo o nombre_usuario y filtrar por tipo de usuario)  
    $sql = "SELECT id, correo, contraseña, nombre_usuario, nombre_completo, tipo 
            FROM usuarios 
            WHERE (correo = ? OR nombre_usuario = ?) AND tipo = ?";  
    
    try {
        if ($stmt = $conexion->prepare($sql)) {  
            $stmt->bind_param("sss", $username, $username, $tipo);  
            $stmt->execute();  
            $result = $stmt->get_result();  

            // Mostrar número de filas encontradas para depuración
            echo "Filas encontradas: " . $result->num_rows . "<br>";

            if ($result->num_rows == 1) {  
                $usuario = $result->fetch_assoc();  
                
                // Verificar contraseña usando bcrypt
                if (password_verify($password, $usuario['contraseña'])) {  
                    echo "Contraseña válida. Iniciando sesión...<br>";
                    
                    // Iniciar sesión
                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['tipo'] = $usuario['tipo'];  
                    $_SESSION['nombre_usuario'] = $usuario['nombre_usuario'];  
                    $_SESSION['nombre_completo'] = $usuario['nombre_completo'];
                    
                    // Redirigir según el tipo de usuario  
                    switch ($usuario['tipo']) {  
                        case 'alumno':  
                            echo "Redirigiendo a alumno.php...";
                            header("Location: alumno.php");  
                            break;  
                        case 'docente':  
                            echo "Redirigiendo a docente.php...";
                            header("Location: docente.php");  
                            break;  
                        case 'administrativo':  
                            echo "Redirigiendo a administrativo.php...";
                            header("Location: administrativo.php");  
                            break;  
                        default:  
                            echo "Error: Tipo de usuario desconocido.";
                            exit;
                    }  
                    exit;  
                } else {  
                    echo "Error: La contraseña es incorrecta.";
                    header("Location: index.php?error=invalid");
                    exit;  
                }  
            } else {  
                echo "Error: Usuario no encontrado o tipo de usuario incorrecto.";
                header("Location: index.php?error=notfound");
                exit;  
            }  
            
            $stmt->close();  
        } else {  
            echo "Error: No se pudo preparar la consulta SQL.";
            header("Location: index.php?error=system");
            exit;  
        }  
    } catch (Exception $e) {
        echo "Excepción capturada: " . $e->getMessage();
        header("Location: index.php?error=system");
        exit;
    }
} else {  
    echo "Error: Acceso directo al archivo no permitido.";
    exit;  
}  
?>