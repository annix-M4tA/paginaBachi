<?php
// logout.php
// Script para cerrar sesión y limpiar cookies

// Iniciar sesión
session_start();

// Guardar log de cierre de sesión si existe un usuario
if (isset($_SESSION['id'])) {
    $archivo = "login_log.txt";
    $fecha = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'];
    $usuario = isset($_SESSION['correo']) ? $_SESSION['correo'] : "desconocido";
    $log = "[$fecha][$ip] Usuario $usuario cerró sesión\n";
    file_put_contents($archivo, $log, FILE_APPEND);
}

// Destruir todas las variables de sesión
$_SESSION = array();

// Destruir la cookie de sesión si existe
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir la sesión
session_destroy();

// Redirigir al index
header("Location: index.php");
exit;
?>