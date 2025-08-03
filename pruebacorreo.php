<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = htmlspecialchars($_POST['name']);
    $email = htmlspecialchars($_POST['email']);
    $message = htmlspecialchars($_POST['message']);
    $to = "anapatriciamataflores787@gmail.com";
    $subject = "Nuevo mensaje de contacto de $name";
    $body = "Nombre: $name\nCorreo: $email\nMensaje:\n$message";
    $headers = "From: $email";

    // Intenta enviar el correo
    $result = mail($to, $subject, $body, $headers);
    if ($result) {
        echo "success";
    } else {
        echo "error";
        // Verifica si hay un error antes de acceder al mensaje
        $last_error = error_get_last();
        if ($last_error && isset($last_error['message'])) {
            error_log("Error al enviar correo: " . $last_error['message'], 3, "C:/xamp2/sendmail/error.log");
        } else {
            error_log("Error al enviar correo: No se pudo obtener el mensaje de error.", 3, "C:/xamp2/sendmail/error.log");
        }
    }
} else {
    echo "Este script debe ser invocado mediante un formulario POST.";
}
?>