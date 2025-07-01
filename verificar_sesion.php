<?php
// verificar_sesion.php
// Incluye este archivo al principio de cada página protegida

// Iniciar sesión
session_start();

// Función para guardar logs
function guardarLogSesion($mensaje) {
    $archivo = "login_log.txt";
    $fecha = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'];
    $log = "[$fecha][$ip] $mensaje\n";
    file_put_contents($archivo, $log, FILE_APPEND);
}

// Nombre de la página actual
$pagina_actual = basename($_SERVER['PHP_SELF']);

// Verificar si el usuario está logueado
if (!isset($_SESSION['id']) || !isset($_SESSION['tipo']) || !isset($_SESSION['token'])) {
    guardarLogSesion("Acceso denegado a '$pagina_actual': No hay sesión activa");
    header("Location: diagnostico_login.php");
    exit;
}

// Verificar que el tipo de usuario coincida con la página
$tipo_usuario = $_SESSION['tipo'];
$paginas_permitidas = array(
    'alumno' => ['alumno.php'],
    'docente' => ['docente.php'],
    'administrativo' => ['administrativo.php']
);

// Si el usuario intenta acceder a una página que no corresponde a su tipo
if (!isset($paginas_permitidas[$tipo_usuario]) || !in_array($pagina_actual, $paginas_permitidas[$tipo_usuario])) {
    guardarLogSesion("Acceso denegado a '$pagina_actual': Usuario tipo '$tipo_usuario' no tiene permiso");
    header("Location: diagnostico_login.php");
    exit;
}

// Aquí podrías agregar verificación adicional del token con la base de datos
// Por ejemplo:
/*
require_once 'conexion.php';
$id = $_SESSION['id'];
$token = $_SESSION['token'];
$query = "SELECT token_sesion FROM usuarios WHERE id = ?";
$stmt = $conexion->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($token_db);
$stmt->fetch();
$stmt->close();

if ($token !== $token_db) {
    guardarLogSesion("Token inválido para usuario ID=$id");
    session_destroy();
    header("Location: diagnostico_login.php");
    exit;
}
*/

// Si todo está bien, registrar el acceso exitoso
guardarLogSesion("Acceso exitoso a '$pagina_actual' por usuario ID=" . $_SESSION['id'] . " (" . $_SESSION['tipo'] . ")");
?>