<?php
include('conexion.php');

// Verificar si el archivo de imagen fue subido correctamente
if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
    // Variables para la imagen
    $fotoTmpPath = $_FILES['foto']['tmp_name']; // Ruta temporal del archivo
    $fotoName = $_FILES['foto']['name'];         // Nombre original del archivo
    $fotoSize = $_FILES['foto']['size'];         // Tamaño del archivo
    $fotoType = $_FILES['foto']['type'];         // Tipo MIME del archivo

    // Directorio donde se almacenarán las imágenes
    $uploadDir = 'C:/xampp/htdocs/paginaBachi/images/eventos/';
    $uploadFile = $uploadDir . basename($fotoName); // Ruta completa donde se guardará la imagen

    // Validar que el archivo sea una imagen de tipo permitido
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($fotoType, $allowedTypes)) {
        // Redirigir con mensaje de error
        header("Location: administrativo.php?mensaje=error_imagen");
        exit;
    }

    // Validar tamaño máximo (por ejemplo, 5MB)
    if ($fotoSize > 5 * 1024 * 1024) { // 5MB
        // Redirigir con mensaje de error por tamaño
        header("Location: administrativo.php?mensaje=error_tamano_imagen");
        exit;
    }

    // Mover el archivo a la carpeta de destino
    if (!move_uploaded_file($fotoTmpPath, $uploadFile)) {
        // Redirigir con mensaje de error si falla la carga
        header("Location: administrativo.php?mensaje=error_subir_imagen");
        exit;
    }

    // Recoger los datos del formulario
    $titulo = $_POST['titulo'];
    $descripcion = $_POST['descripcion'];
    $fecha = $_POST['fecha'];

    // Insertar los datos del evento en la base de datos
    $query = "INSERT INTO eventos (titulo, descripcion, fecha, ruta_foto, fecha_creacion)
              VALUES ('$titulo', '$descripcion', '$fecha', '$fotoName', NOW())";

    if ($conexion->query($query) === TRUE) {
        // Redirigir con mensaje de éxito
        header("Location: administrativo.php?mensaje=evento_publicado");
    } else {
        // Redirigir con mensaje de error en la inserción
        header("Location: administrativo.php?mensaje=error_publicar_evento");
    }

    $conexion->close();
} else {
    // Redirigir con mensaje de error si no se ha subido una imagen
    header("Location: administrativo.php?mensaje=no_imagen");
}
?>
