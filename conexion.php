<?php  
// Definir credenciales como constantes
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'erick');
define('DB_PASSWORD', ''); // Asegúrate de que esto sea seguro
define('DB_NAME', 'bachilleratofin');

// Crear conexión MySQLi
$conexion = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Verificar conexión
if ($conexion->connect_error) {
    // Registrar el error en un archivo de log
    error_log("Error de conexión a la base de datos: " . $conexion->connect_error, 3, "db_errors.log");
    
    // Mostrar un mensaje genérico al usuario
    die("Lo sentimos, ha ocurrido un error inesperado. Por favor, inténtalo más tarde.");
}

// Establecer codificación de caracteres
$conexion->set_charset("utf8mb4");
?>