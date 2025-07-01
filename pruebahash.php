<?php
// Prueba de verificación de contraseña
$password = '1234'; // Contraseña en texto plano
$hash = '$2a$12$SkA.EPh6mlYaOwmMGphRP.lIhMJyDm5GTaVAz9PEGPmnchVhrlAjS'; // Hash almacenado en la base de datos

if (password_verify($password, $hash)) {
    echo "La contraseña es válida.";
} else {
    echo "La contraseña es inválida.";
}
?>