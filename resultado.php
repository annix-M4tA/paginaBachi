<?php
// diagnostico_login.php
// Script para diagnosticar el flujo de redirección del login

// Iniciar la sesión para acceder a las variables de sesión
session_start();

// Función para guardar logs en un archivo
function guardarLog($mensaje) {
    $archivo = "login_log.txt";
    $fecha = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'];
    $log = "[$fecha][$ip] $mensaje\n";
    
    // Guardar en archivo
    file_put_contents($archivo, $log, FILE_APPEND);
    
    // También mostrar en pantalla si está habilitado el debug
    if (isset($_GET['debug']) && $_GET['debug'] == '1') {
        echo "<div style='background:#f8f9fa;border:1px solid #ddd;padding:10px;margin:10px 0;font-family:monospace;'>";
        echo htmlspecialchars($log);
        echo "</div>";
    }
}

// Iniciar el diagnóstico
guardarLog("--- INICIO DIAGNÓSTICO DE LOGIN ---");

// Registrar la URL de origen
if (isset($_SERVER['HTTP_REFERER'])) {
    guardarLog("Redirigido desde: " . $_SERVER['HTTP_REFERER']);
}

// Verificar si hay un usuario en sesión
if (isset($_SESSION['id'])) {
    guardarLog("Usuario en sesión: ID=" . $_SESSION['id'] . ", Correo=" . $_SESSION['correo'] . ", Tipo=" . $_SESSION['tipo']);
    
    // Verificar la existencia de las páginas de destino
    $tipo_usuario = $_SESSION['tipo'];
    $pagina_destino = $tipo_usuario . ".php";
    
    if (file_exists($pagina_destino)) {
        guardarLog("Página destino '$pagina_destino' existe");
    } else {
        guardarLog("ERROR: La página destino '$pagina_destino' NO existe");
    }
    
    // Verificar que los tipos coincidan con los esperados
    $tipos_validos = ['alumno', 'docente', 'administrativo'];
    if (in_array($tipo_usuario, $tipos_validos)) {
        guardarLog("Tipo de usuario '$tipo_usuario' es válido");
    } else {
        guardarLog("ERROR: Tipo de usuario '$tipo_usuario' NO es válido");
    }
    
    // Verificar token de sesión
    if (isset($_SESSION['token'])) {
        guardarLog("Token de sesión existe: " . substr($_SESSION['token'], 0, 10) . "...");
        
        // Aquí podrías hacer una consulta a la base de datos para verificar que el token coincide
        // con el almacenado en la base de datos, pero por simplicidad lo omitimos
    } else {
        guardarLog("ERROR: No hay token de sesión");
    }
    
    // Si está en modo debug, mostrar todas las variables de sesión
    if (isset($_GET['debug']) && $_GET['debug'] == '1') {
        guardarLog("Variables de sesión completas: " . print_r($_SESSION, true));
    }
    
    // Redirigir al usuario a la página correcta
    guardarLog("Redirigiendo a: $pagina_destino");
    
    if (!isset($_GET['debug']) || $_GET['debug'] != '1') {
        // Solo redirigir si no estamos en modo debug
        header("Location: $pagina_destino");
        exit;
    }
} else {
    guardarLog("ERROR: No hay sesión de usuario activa");
    
    // Si no hay sesión, redirigir al login
    if (!isset($_GET['debug']) || $_GET['debug'] != '1') {
        guardarLog("Redirigiendo a: index.php");
        header("Location: index.php");
        exit;
    }
}

// Si llegamos aquí, estamos en modo debug y no redirigimos
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo "<h1>Diagnóstico de Login</h1>";
    echo "<p>Se ha registrado la información de diagnóstico en el archivo 'login_log.txt'.</p>";
    echo "<h2>Variables de sesión:</h2>";
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
    
    echo "<h2>Variables del servidor:</h2>";
    echo "<pre>";
    $safe_server_vars = $_SERVER;
    // Eliminar información sensible
    unset($safe_server_vars['HTTP_COOKIE']);
    unset($safe_server_vars['PATH']);
    unset($safe_server_vars['DOCUMENT_ROOT']);
    print_r($safe_server_vars);
    echo "</pre>";
    
    echo "<h2>Recomendaciones:</h2>";
    echo "<ul>";
    echo "<li>Verifica que el archivo conexion.php esté configurado correctamente.</li>";
    echo "<li>Asegúrate de que las páginas alumno.php, docente.php y administrativo.php existan.</li>";
    echo "<li>Revisa que la tabla 'usuarios' tenga la estructura correcta.</li>";
    echo "<li>Comprueba que las contraseñas estén hasheadas con password_hash().</li>";
    echo "</ul>";
    
    echo "<p><a href='index.php'>Volver al inicio</a></p>";
}
?>