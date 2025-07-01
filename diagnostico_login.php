<?php
// login_debug.php
// Script para depurar el proceso de login paso a paso

// Configuración para mostrar todos los errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Función para registro
function log_debug($mensaje, $tipo = 'info') {
    $archivo = "login_debug.txt";
    $fecha = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'];
    $nivel = strtoupper($tipo);
    $log = "[$fecha][$ip][$nivel] $mensaje\n";
    
    // Escribir en archivo
    file_put_contents($archivo, $log, FILE_APPEND);
    
    // Mostrar en pantalla
    echo "<div style='margin:5px;padding:10px;border:1px solid #ccc;background-color:";
    if ($tipo == 'error') echo "#ffebee";
    elseif ($tipo == 'success') echo "#e8f5e9";
    else echo "#e3f2fd";
    echo ";'><strong>$nivel:</strong> " . htmlspecialchars($mensaje) . "</div>";
}

// Incluir archivo de conexión
if (file_exists('conexion.php')) {
    require_once 'conexion.php';
    log_debug("Archivo conexion.php cargado correctamente", "success");
    
    // Verificar conexión
    if (isset($conexion) && $conexion instanceof mysqli) {
        if ($conexion->connect_error) {
            log_debug("Error de conexión a la base de datos: " . $conexion->connect_error, "error");
        } else {
            log_debug("Conexión a la base de datos establecida correctamente", "success");
        }
    } else {
        log_debug("Variable \$conexion no está definida correctamente en conexion.php", "error");
    }
} else {
    log_debug("No se encontró el archivo conexion.php", "error");
}

// Verificar configuración de sesión
log_debug("Configuración de sesión: session.save_path = " . ini_get('session.save_path'));
log_debug("Configuración de sesión: session.gc_maxlifetime = " . ini_get('session.gc_maxlifetime'));
log_debug("Configuración de sesión: session.cookie_lifetime = " . ini_get('session.cookie_lifetime'));

// Comprobar si phpinfo() está habilitado (para desarrollo)
echo "<h2>Procesamiento de Login</h2>";

// Verificar POST de login
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['correo']) && isset($_POST['contrasena'])) {
    $correo = trim($_POST['correo']);
    $contrasena = $_POST['contrasena'];
    
    log_debug("Intento de login con correo: " . $correo);
    
    // Verificar acceso a la base de datos
    if (isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_error) {
        // Preparar la consulta
        $sql = "SELECT id, correo, contraseña, nombre_usuario, tipo FROM usuarios WHERE correo = ?";
        
        if ($stmt = $conexion->prepare($sql)) {
            log_debug("Consulta SQL preparada correctamente");
            
            // Vincular parámetros
            $stmt->bind_param("s", $correo);
            
            // Ejecutar la consulta
            if ($stmt->execute()) {
                log_debug("Consulta ejecutada correctamente");
                
                // Almacenar resultado
                $stmt->store_result();
                
                if ($stmt->num_rows > 0) {
                    log_debug("Usuario encontrado en la base de datos");
                    
                    // Vincular variables de resultado
                    $stmt->bind_result($id, $correo_db, $contrasena_hash, $nombre_usuario, $tipo);
                    $stmt->fetch();
                    
                    // Mostrar la contraseña hash (solo para depuración)
                    log_debug("Hash de contraseña almacenado: " . substr($contrasena_hash, 0, 10) . "...");
                    
                    // Verificar contraseña
                    if (password_verify($contrasena, $contrasena_hash)) {
                        log_debug("Contraseña verificada correctamente", "success");
                        
                        // Guardar en sesión
                        $_SESSION['id'] = $id;
                        $_SESSION['correo'] = $correo_db;
                        $_SESSION['nombre_usuario'] = $nombre_usuario;
                        $_SESSION['tipo'] = $tipo;
                        $_SESSION['login_time'] = time();
                        $_SESSION['token'] = bin2hex(random_bytes(32));
                        
                        log_debug("Variables de sesión establecidas:", "success");
                        log_debug("ID: " . $_SESSION['id']);
                        log_debug("Correo: " . $_SESSION['correo']);
                        log_debug("Nombre: " . $_SESSION['nombre_usuario']);
                        log_debug("Tipo: " . $_SESSION['tipo']);
                        log_debug("Token: " . substr($_SESSION['token'], 0, 10) . "...");
                        
                        // Verificar que la página de destino existe
                        $pagina_destino = $tipo . ".php";
                        if (file_exists($pagina_destino)) {
                            log_debug("Página de destino '$pagina_destino' existe", "success");
                            log_debug("Redirigiendo a: $pagina_destino", "success");
                            
                            echo "<p><strong>Login exitoso.</strong> Redirigiendo a $pagina_destino en 5 segundos...</p>";
                            echo "<p>Variables de sesión actuales:</p>";
                            echo "<pre>";
                            print_r($_SESSION);
                            echo "</pre>";
                            
                            echo "<script>
                                setTimeout(function() {
                                    window.location.href = '$pagina_destino';
                                }, 5000);
                            </script>";
                            
                            echo "<p><a href='$pagina_destino'>Clic aquí si no eres redirigido automáticamente</a></p>";
                        } else {
                            log_debug("ERROR: La página de destino '$pagina_destino' no existe", "error");
                        }
                    } else {
                        log_debug("Contraseña incorrecta", "error");
                    }
                } else {
                    log_debug("Usuario no encontrado en la base de datos", "error");
                }
                
            } else {
                log_debug("Error al ejecutar la consulta: " . $stmt->error, "error");
            }
            
            $stmt->close();
        } else {
            log_debug("Error al preparar la consulta: " . $conexion->error, "error");
        }
        
    } else {
        log_debug("No hay conexión válida a la base de datos", "error");
    }
}

// Mostrar estado actual de la sesión
echo "<h2>Estado Actual de la Sesión</h2>";
if (count($_SESSION) > 0) {
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
} else {
    echo "<p>No hay variables de sesión establecidas.</p>";
}

// Formulario de prueba
echo '<h2>Formulario de Prueba</h2>
<form method="post" action="' . htmlspecialchars($_SERVER["PHP_SELF"]) . '">
    <div style="margin-bottom: 10px;">
        <label for="correo">Correo:</label>
        <input type="email" id="correo" name="correo" required style="margin-left: 10px;">
    </div>
    <div style="margin-bottom: 10px;">
        <label for="contrasena">Contraseña:</label>
        <input type="password" id="contrasena" name="contrasena" required style="margin-left: 10px;">
    </div>
    <button type="submit" style="padding: 5px 15px;">Iniciar Sesión (Prueba)</button>
</form>';

// Mostrar información de PHP
echo "<h2>Información de Configuración</h2>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Session Save Path: " . session_save_path() . "</p>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Session Name: " . session_name() . "</p>";
echo "<p>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p>Script Path: " . $_SERVER['SCRIPT_FILENAME'] . "</p>";

// Links útiles
echo "<h2>Enlaces Útiles</h2>";
echo "<ul>";
echo "<li><a href='logout.php'>Cerrar Sesión</a></li>";
echo "<li><a href='index.php'>Página de Inicio</a></li>";
if (file_exists('alumno.php')) echo "<li><a href='alumno.php'>Página de Alumno</a></li>";
if (file_exists('docente.php')) echo "<li><a href='docente.php'>Página de Docente</a></li>";
if (file_exists('administrativo.php')) echo "<li><a href='administrativo.php'>Página de Administrativo</a></li>";
echo "</ul>";
?>