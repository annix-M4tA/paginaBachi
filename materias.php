<?php
function getAllMaterias($conexion) {
    $sql = "SELECT * FROM materias";
    $result = $conexion->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getMateriaById($id, $conexion) {
    $sql = "SELECT * FROM materias WHERE id = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function createMateria($nombre, $descripcion, $conexion) {
    $sql = "INSERT INTO materias (nombre, descripcion) VALUES (?, ?)";
    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        logMessage("Error al preparar la consulta: " . $conexion->error);
        return false;
    }
    $stmt->bind_param("ss", $nombre, $descripcion);
    $result = $stmt->execute();
    if (!$result) {
        logMessage("Error al insertar materia: " . $stmt->error);
    }
    $stmt->close();
    return $result;
}

function updateMateria($id, $nombre, $descripcion, $conexion) {
    $sql = "UPDATE materias SET nombre = ?, descripcion = ? WHERE id = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("ssi", $nombre, $descripcion, $id);
    return $stmt->execute();
}

function deleteMateria($id, $conexion) {
    $sql = "DELETE FROM materias WHERE id = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $id);
    return $stmt->execute();
}
?>