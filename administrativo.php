<?php
session_start();
ob_start();

if (!isset($_SESSION['id'])) {
    $_SESSION['id'] = 1; // ID ficticio para pruebas
}
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Regenerar ID de sesión cada 30 minutos
if (!isset($_SESSION['last_regen']) || (time() - $_SESSION['last_regen']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
}

require 'conexion.php';

const UPLOAD_DIR = 'images/eventos/';
const MAX_FILE_SIZE = 10000000;
const ALLOWED_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

function sanitizar($input, $conexion, $allowHtml = false) {
    if (is_null($input) || $input === '') return '';
    $input = trim($input);
    if (!$allowHtml) {
        $input = strip_tags($input);
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $conexion->real_escape_string($input);
}

function logError($message) {
    $logDir = 'logs/';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    error_log("[$message] " . date('Y-m-d H:i:s') . "\n", 3, $logDir . 'app_' . date('Y-m-d') . '.log');
}

function ejecutarConsulta($conexion, $query, $tipos = '', $params = [], $returnResult = false) {
    try {
        $stmt = $conexion->prepare($query);
        if (!$stmt) throw new Exception("Error preparando consulta: " . $conexion->error);
        if ($tipos && $params) $stmt->bind_param($tipos, ...$params);
        if (!$stmt->execute()) throw new Exception("Error ejecutando consulta: " . $stmt->error);
        $result = $returnResult ? $stmt->get_result() : $stmt->affected_rows;
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        logError("Consulta fallida: " . $e->getMessage() . " | Query: $query");
        throw $e;
    }
}

function subirArchivo($file, $uploadDir, $allowedTypes, $maxFileSize) {
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Error al subir archivo: " . $file['error']);
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) throw new Exception("No se pudo crear directorio de subida");
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!array_key_exists($mime, $allowedMimes) || $file['size'] > $maxFileSize) throw new Exception("Tipo o tamaño de archivo no permitido");
    if (!getimagesize($file['tmp_name'])) throw new Exception("El archivo no es una imagen válida");
    $extension = $allowedMimes[$mime];
    $filename = uniqid('evt_', true) . '.' . $extension;
    $target = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new Exception("Fallo al mover archivo");
    return $target;
}

// Manejo de solicitudes AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Acción no reconocida'];

    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $response = ['status' => 'error', 'message' => 'Token CSRF inválido'];
        echo json_encode($response);
        exit();
    }

    try {
        $conexion->begin_transaction();
        $usuario_id = (int)$_SESSION['id'];

        // CRUD Usuarios
        if (isset($_POST['action']) && $_POST['action'] === 'create') {
            $correo = sanitizar($_POST['correo'] ?? '', $conexion);
            $contrasena = password_hash(sanitizar($_POST['contrasena'] ?? '', $conexion), PASSWORD_BCRYPT);
            $nombre_usuario = sanitizar($_POST['nombre_usuario'] ?? '', $conexion);
            $nombre_completo = sanitizar($_POST['nombre_completo'] ?? '', $conexion); // Nuevo campo
            $telefono = sanitizar($_POST['telefono'] ?? '', $conexion); // Nuevo campo opcional
            $tipo = sanitizar($_POST['tipo'] ?? '', $conexion);
            $estado = sanitizar($_POST['estado'] ?? 'activo', $conexion);
        
            $errors = [];
            if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL)) $errors['correo'] = 'Correo inválido';
            if (!$contrasena || strlen($_POST['contrasena']) < 8) $errors['contrasena'] = 'Contraseña debe tener al menos 8 caracteres';
            if (!$nombre_usuario) $errors['nombre_usuario'] = 'Nombre de usuario obligatorio';
            if (!$nombre_completo) $errors['nombre_completo'] = 'Nombre completo obligatorio'; // Validación nueva
            if (!in_array($tipo, ['alumno', 'docente', 'administrativo'])) $errors['tipo'] = 'Tipo inválido';
            if (!in_array($estado, ['activo', 'inactivo', 'bloqueado'])) $errors['estado'] = 'Estado inválido';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));
        
            $result = ejecutarConsulta($conexion, "SELECT id FROM usuarios WHERE correo = ? OR nombre_usuario = ?", 'ss', [$correo, $nombre_usuario], true);
            if ($result->num_rows > 0) throw new Exception('Correo o nombre de usuario ya registrados');
        
            // Incluir nombre_completo y telefono en la inserción
            ejecutarConsulta($conexion, "INSERT INTO usuarios (correo, contraseña, nombre_usuario, nombre_completo, telefono, tipo, estado) VALUES (?, ?, ?, ?, ?, ?, ?)",
                'sssssss', [$correo, $contrasena, $nombre_usuario, $nombre_completo, $telefono ?: null, $tipo, $estado]);
            $newId = $conexion->insert_id;
        
            $nia = $generacion_id = $especialidad = $departamento = null;
            if ($tipo === 'alumno') {
                $nia = sanitizar($_POST['nia'] ?? '', $conexion);
                $generacion_id = (int)($_POST['generacion_id'] ?? 0);
                if (!$nia || strlen($nia) !== 4 || !$generacion_id) throw new Exception('NIA (4 caracteres) y generación obligatorios');
                ejecutarConsulta($conexion, "INSERT INTO alumnos (usuario_id, nia, generacion_id) VALUES (?, ?, ?)", 'isi', [$newId, $nia, $generacion_id]);
            } elseif ($tipo === 'docente') {
                $especialidad = sanitizar($_POST['especialidad'] ?? '', $conexion);
                if (!$especialidad) throw new Exception('Especialidad obligatoria');
                ejecutarConsulta($conexion, "INSERT INTO docentes (usuario_id, especialidad) VALUES (?, ?)", 'is', [$newId, $especialidad]);
            } elseif ($tipo === 'administrativo') {
                $departamento = sanitizar($_POST['departamento'] ?? '', $conexion);
                if (!$departamento) throw new Exception('Departamento obligatorio');
                ejecutarConsulta($conexion, "INSERT INTO administrativos (usuario_id, departamento) VALUES (?, ?)", 'is', [$newId, $departamento]);
            }
        
            $user_data = [
                'id' => $newId, 'correo' => $correo, 'nombre_usuario' => $nombre_usuario, 'nombre_completo' => $nombre_completo, 
                'telefono' => $telefono, 'tipo' => $tipo, 'estado' => $estado, 'nia' => $nia, 'generacion_id' => $generacion_id, 
                'especialidad' => $especialidad, 'departamento' => $departamento
            ];
            $response = ['status' => 'success', 'message' => 'Usuario creado correctamente', 'data' => $user_data];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $correo = sanitizar($_POST['correo'] ?? '', $conexion);
            $nombre_usuario = sanitizar($_POST['nombre_usuario'] ?? '', $conexion);
            $tipo = sanitizar($_POST['tipo'] ?? '', $conexion);
            $estado = sanitizar($_POST['estado'] ?? 'activo', $conexion);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido';
            if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL)) $errors['correo'] = 'Correo inválido';
            if (!$nombre_usuario) $errors['nombre_usuario'] = 'Nombre de usuario obligatorio';
            if (!in_array($tipo, ['alumno', 'docente', 'administrativo'])) $errors['tipo'] = 'Tipo inválido';
            if (!in_array($estado, ['activo', 'inactivo', 'bloqueado'])) $errors['estado'] = 'Estado inválido';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $result = ejecutarConsulta($conexion, "SELECT id FROM usuarios WHERE (correo = ? OR nombre_usuario = ?) AND id != ?", 'ssi', [$correo, $nombre_usuario, $id], true);
            if ($result->num_rows > 0) throw new Exception('Correo o nombre de usuario ya registrados');

            ejecutarConsulta($conexion, "UPDATE usuarios SET correo = ?, nombre_usuario = ?, tipo = ?, estado = ? WHERE id = ?", 'ssssi', [$correo, $nombre_usuario, $tipo, $estado, $id]);
            $nia = $generacion_id = $especialidad = $departamento = null;
            if ($tipo === 'alumno') {
                $nia = sanitizar($_POST['nia'] ?? '', $conexion);
                $generacion_id = (int)($_POST['generacion_id'] ?? 0);
                if (!$nia || strlen($nia) !== 4 || !$generacion_id) throw new Exception('NIA (4 caracteres) y generación obligatorios');
                ejecutarConsulta($conexion, "INSERT INTO alumnos (usuario_id, nia, generacion_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE nia = ?, generacion_id = ?",
                    'isisi', [$id, $nia, $generacion_id, $nia, $generacion_id]);
            } elseif ($tipo === 'docente') {
                $especialidad = sanitizar($_POST['especialidad'] ?? '', $conexion);
                if (!$especialidad) throw new Exception('Especialidad obligatoria');
                ejecutarConsulta($conexion, "INSERT INTO docentes (usuario_id, especialidad) VALUES (?, ?) ON DUPLICATE KEY UPDATE especialidad = ?",
                    'iss', [$id, $especialidad, $especialidad]);
            } elseif ($tipo === 'administrativo') {
                $departamento = sanitizar($_POST['departamento'] ?? '', $conexion);
                if (!$departamento) throw new Exception('Departamento obligatorio');
                ejecutarConsulta($conexion, "INSERT INTO administrativos (usuario_id, departamento) VALUES (?, ?) ON DUPLICATE KEY UPDATE departamento = ?",
                    'iss', [$id, $departamento, $departamento]);
            }

            $tables_to_clean = ['alumnos', 'docentes', 'administrativos'];
            $current_table = $tipo === 'alumno' ? 'alumnos' : ($tipo === 'docente' ? 'docentes' : 'administrativos');
            foreach ($tables_to_clean as $table) {
                if ($table !== $current_table) ejecutarConsulta($conexion, "DELETE FROM $table WHERE usuario_id = ?", 'i', [$id]);
            }

            $user_data = ['id' => $id, 'correo' => $correo, 'nombre_usuario' => $nombre_usuario, 'tipo' => $tipo, 'estado' => $estado,
                'nia' => $nia, 'generacion_id' => $generacion_id, 'especialidad' => $especialidad, 'departamento' => $departamento];
            $response = ['status' => 'success', 'message' => 'Usuario actualizado correctamente', 'data' => $user_data];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'deactivate') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');
            ejecutarConsulta($conexion, "UPDATE usuarios SET estado = 'inactivo' WHERE id = ?", 'i', [$id]);
            $response = ['status' => 'success', 'message' => 'Usuario desactivado correctamente', 'data' => ['id' => $id]];
            $conexion->commit();
        }

        // CRUD Avisos
        elseif (isset($_POST['action']) && $_POST['action'] === 'create_aviso') {
            $titulo = sanitizar($_POST['titulo'] ?? '', $conexion);
            $contenido = sanitizar($_POST['contenido'] ?? '', $conexion, true);
            $prioridadNum = (int)($_POST['prioridad'] ?? 2);

            $errors = [];
            if (!$titulo) $errors['titulo'] = 'El título es obligatorio';
            if (!$contenido) $errors['contenido'] = 'El contenido es obligatorio';
            if (!in_array($prioridadNum, [1, 2, 3])) $errors['prioridad'] = 'Prioridad inválida';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $prioridadMap = [1 => 'baja', 2 => 'media', 3 => 'alta'];
            $prioridad = $prioridadMap[$prioridadNum];
            ejecutarConsulta($conexion, "INSERT INTO avisos (titulo, contenido, prioridad, usuario_id, fecha_creacion) VALUES (?, ?, ?, ?, NOW())",
                'sssi', [$titulo, $contenido, $prioridad, $usuario_id]);
            $newId = $conexion->insert_id;

            $response = ['status' => 'success', 'message' => 'Aviso creado correctamente', 'data' => [
                'id' => $newId, 'titulo' => $titulo, 'contenido' => $contenido, 'prioridad' => $prioridad, 'fecha_creacion' => date('Y-m-d H:i:s')]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_aviso') {
            $id = (int)($_POST['id'] ?? 0);
            $titulo = sanitizar($_POST['titulo'] ?? '', $conexion);
            $contenido = sanitizar($_POST['contenido'] ?? '', $conexion, true);
            $prioridadNum = (int)($_POST['prioridad'] ?? 2);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido';
            if (!$titulo) $errors['titulo'] = 'El título es obligatorio';
            if (!$contenido) $errors['contenido'] = 'El contenido es obligatorio';
            if (!in_array($prioridadNum, [1, 2, 3])) $errors['prioridad'] = 'Prioridad inválida';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $prioridadMap = [1 => 'baja', 2 => 'media', 3 => 'alta'];
            $prioridad = $prioridadMap[$prioridadNum];
            ejecutarConsulta($conexion, "UPDATE avisos SET titulo = ?, contenido = ?, prioridad = ? WHERE id = ? AND usuario_id = ?",
                'sssii', [$titulo, $contenido, $prioridad, $id, $usuario_id]);

            $fecha_creacion = ejecutarConsulta($conexion, "SELECT fecha_creacion FROM avisos WHERE id = ?", 'i', [$id], true)->fetch_assoc()['fecha_creacion'];
            $response = ['status' => 'success', 'message' => 'Aviso actualizado correctamente', 'data' => [
                'id' => $id, 'titulo' => $titulo, 'contenido' => $contenido, 'prioridad' => $prioridad, 'fecha_creacion' => $fecha_creacion]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_aviso') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');
            ejecutarConsulta($conexion, "DELETE FROM avisos WHERE id = ? AND usuario_id = ?", 'ii', [$id, $usuario_id]);
            $response = ['status' => 'success', 'message' => 'Aviso eliminado correctamente', 'data' => ['id' => $id]];
            $conexion->commit();
        }
        // Eliminar Reporte
elseif (isset($_POST['action']) && $_POST['action'] === 'delete_reporte') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new Exception('ID de reporte inválido');

    $stmt = $conexion->prepare("SELECT ruta_archivo FROM reportes WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result) {
        $file = $result['ruta_archivo'];
        if (file_exists($file)) unlink($file); // Eliminar archivo físico
        ejecutarConsulta($conexion, "DELETE FROM reportes WHERE id = ?", 'i', [$id]);
        $response = ['status' => 'success', 'message' => 'Reporte eliminado correctamente', 'data' => ['id' => $id]];
        $conexion->commit();
    } else {
        throw new Exception('Reporte no encontrado');
    }
}
        // CRUD Eventos
        elseif (isset($_POST['action']) && $_POST['action'] === 'create_evento') {
            $titulo = sanitizar($_POST['titulo'] ?? '', $conexion);
            $fecha = sanitizar($_POST['fecha'] ?? '', $conexion);
            $descripcion = sanitizar($_POST['descripcion'] ?? '', $conexion, true);
            $tipo_evento = sanitizar($_POST['tipo_evento'] ?? 'academico', $conexion);

            $errors = [];
            if (!$titulo) $errors['titulo'] = 'El título es obligatorio';
            if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $errors['fecha'] = 'Fecha inválida (YYYY-MM-DD)';
            if (!$descripcion) $errors['descripcion'] = 'La descripción es obligatoria';
            if (!in_array($tipo_evento, ['academico', 'deportivo', 'cultural'])) $errors['tipo_evento'] = 'Tipo de evento inválido';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $ruta_foto = 'images/eventos/default.jpg';
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $ruta_foto = subirArchivo($_FILES['foto'], UPLOAD_DIR, ALLOWED_TYPES, MAX_FILE_SIZE);
            }

            ejecutarConsulta($conexion, "INSERT INTO eventos (titulo, fecha, descripcion, ruta_foto, usuario_id, tipo_evento, fecha_creacion) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                'ssssis', [$titulo, $fecha, $descripcion, $ruta_foto, $usuario_id, $tipo_evento]);
            $newId = $conexion->insert_id;

            $response = ['status' => 'success', 'message' => 'Evento creado correctamente', 'data' => [
                'id' => $newId, 'titulo' => $titulo, 'fecha' => $fecha, 'descripcion' => $descripcion, 'ruta_foto' => $ruta_foto,
                'tipo_evento' => $tipo_evento, 'fecha_creacion' => date('Y-m-d H:i:s')]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_evento') {
            $id = (int)($_POST['id'] ?? 0);
            $titulo = sanitizar($_POST['titulo'] ?? '', $conexion);
            $fecha = sanitizar($_POST['fecha'] ?? '', $conexion);
            $descripcion = sanitizar($_POST['descripcion'] ?? '', $conexion, true);
            $tipo_evento = sanitizar($_POST['tipo_evento'] ?? 'academico', $conexion);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido';
            if (!$titulo) $errors['titulo'] = 'El título es obligatorio';
            if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $errors['fecha'] = 'Fecha inválida (YYYY-MM-DD)';
            if (!$descripcion) $errors['descripcion'] = 'La descripción es obligatoria';
            if (!in_array($tipo_evento, ['academico', 'deportivo', 'cultural'])) $errors['tipo_evento'] = 'Tipo de evento inválido';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $ruta_foto = null;
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $ruta_foto = subirArchivo($_FILES['foto'], UPLOAD_DIR, ALLOWED_TYPES, MAX_FILE_SIZE);
                $oldFoto = ejecutarConsulta($conexion, "SELECT ruta_foto FROM eventos WHERE id = ?", 'i', [$id], true)->fetch_assoc()['ruta_foto'];
                if ($oldFoto && file_exists($oldFoto) && $oldFoto !== 'images/eventos/default.jpg') unlink($oldFoto);
            }

            if ($ruta_foto) {
                ejecutarConsulta($conexion, "UPDATE eventos SET titulo = ?, fecha = ?, descripcion = ?, ruta_foto = ?, tipo_evento = ? WHERE id = ?",
                    'sssssi', [$titulo, $fecha, $descripcion, $ruta_foto, $tipo_evento, $id]);
            } else {
                ejecutarConsulta($conexion, "UPDATE eventos SET titulo = ?, fecha = ?, descripcion = ?, tipo_evento = ? WHERE id = ?",
                    'ssssi', [$titulo, $fecha, $descripcion, $tipo_evento, $id]);
            }

            $fecha_creacion = ejecutarConsulta($conexion, "SELECT fecha_creacion FROM eventos WHERE id = ?", 'i', [$id], true)->fetch_assoc()['fecha_creacion'];
            $ruta_foto_actual = $ruta_foto ?: ejecutarConsulta($conexion, "SELECT ruta_foto FROM eventos WHERE id = ?", 'i', [$id], true)->fetch_assoc()['ruta_foto'];
            $response = ['status' => 'success', 'message' => 'Evento actualizado correctamente', 'data' => [
                'id' => $id, 'titulo' => $titulo, 'fecha' => $fecha, 'descripcion' => $descripcion, 'ruta_foto' => $ruta_foto_actual,
                'tipo_evento' => $tipo_evento, 'fecha_creacion' => $fecha_creacion]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_evento') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');

            $ruta_foto = ejecutarConsulta($conexion, "SELECT ruta_foto FROM eventos WHERE id = ?", 'i', [$id], true)->fetch_assoc()['ruta_foto'];
            ejecutarConsulta($conexion, "DELETE FROM eventos WHERE id = ?", 'i', [$id]);
            if ($ruta_foto && file_exists($ruta_foto) && $ruta_foto !== 'images/eventos/default.jpg') unlink($ruta_foto);

            $response = ['status' => 'success', 'message' => 'Evento eliminado correctamente', 'data' => ['id' => $id]];
            $conexion->commit();
        }

        // CRUD Semestres
        elseif (isset($_POST['action']) && $_POST['action'] === 'create_semestre') {
            $generacion_id = (int)($_POST['generacion_id'] ?? 0);
            $fecha_inicio = sanitizar($_POST['fecha_inicio'] ?? '', $conexion);
            $fecha_fin = sanitizar($_POST['fecha_fin'] ?? '', $conexion);

            $errors = [];
            if ($generacion_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM generaciones WHERE id = ?", 'i', [$generacion_id], true)->num_rows == 0)
                $errors['generacion_id'] = 'Generación inválida';
            if (!$fecha_inicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio)) $errors['fecha_inicio'] = 'Fecha de inicio inválida';
            if (!$fecha_fin || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) $errors['fecha_fin'] = 'Fecha de fin inválida';
            if (strtotime($fecha_inicio) >= strtotime($fecha_fin)) $errors['fechas'] = 'Fecha de inicio debe ser anterior a la de fin';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $numero = ejecutarConsulta($conexion, "SELECT COALESCE(MAX(numero), 0) + 1 FROM semestres WHERE generacion_id = ?", 'i', [$generacion_id], true)->fetch_row()[0];
            ejecutarConsulta($conexion, "INSERT INTO semestres (generacion_id, numero, fecha_inicio, fecha_fin) VALUES (?, ?, ?, ?)",
                'iiss', [$generacion_id, $numero, $fecha_inicio, $fecha_fin]);
            $newId = $conexion->insert_id;

            $generacion = ejecutarConsulta($conexion, "SELECT nombre FROM generaciones WHERE id = ?", 'i', [$generacion_id], true)->fetch_assoc()['nombre'];
            $response = ['status' => 'success', 'message' => 'Semestre creado correctamente', 'data' => [
                'id' => $newId, 'numero' => $numero, 'generacion' => $generacion, 'fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin, 'generacion_id' => $generacion_id]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_semestre') {
            $id = (int)($_POST['id'] ?? 0);
            $generacion_id = (int)($_POST['generacion_id'] ?? 0);
            $fecha_inicio = sanitizar($_POST['fecha_inicio'] ?? '', $conexion);
            $fecha_fin = sanitizar($_POST['fecha_fin'] ?? '', $conexion);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido';
            if ($generacion_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM generaciones WHERE id = ?", 'i', [$generacion_id], true)->num_rows == 0)
                $errors['generacion_id'] = 'Generación inválida';
            if (!$fecha_inicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio)) $errors['fecha_inicio'] = 'Fecha de inicio inválida';
            if (!$fecha_fin || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) $errors['fecha_fin'] = 'Fecha de fin inválida';
            if (strtotime($fecha_inicio) >= strtotime($fecha_fin)) $errors['fechas'] = 'Fecha de inicio debe ser anterior a la de fin';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $numero = ejecutarConsulta($conexion, "SELECT numero FROM semestres WHERE id = ?", 'i', [$id], true)->fetch_row()[0];
            ejecutarConsulta($conexion, "UPDATE semestres SET generacion_id = ?, fecha_inicio = ?, fecha_fin = ? WHERE id = ?",
                'issi', [$generacion_id, $fecha_inicio, $fecha_fin, $id]);

            $generacion = ejecutarConsulta($conexion, "SELECT nombre FROM generaciones WHERE id = ?", 'i', [$generacion_id], true)->fetch_assoc()['nombre'];
            $response = ['status' => 'success', 'message' => 'Semestre actualizado correctamente', 'data' => [
                'id' => $id, 'numero' => $numero, 'generacion' => $generacion, 'fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin, 'generacion_id' => $generacion_id]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_semestre') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');
            if (ejecutarConsulta($conexion, "SELECT COUNT(*) FROM grupos WHERE semestre_id = ?", 'i', [$id], true)->fetch_row()[0] > 0)
                throw new Exception('No se puede eliminar: semestre con grupos asociados');
            ejecutarConsulta($conexion, "DELETE FROM semestres WHERE id = ?", 'i', [$id]);
            $response = ['status' => 'success', 'message' => 'Semestre eliminado correctamente', 'data' => ['id' => $id]];
            $conexion->commit();
        }

        // CRUD Materias
        elseif (isset($_POST['action']) && $_POST['action'] === 'create_materia') {
            $nombre = sanitizar($_POST['nombre'] ?? '', $conexion);
            $descripcion = sanitizar($_POST['descripcion'] ?? '', $conexion);

            $errors = [];
            if (!$nombre) $errors['nombre'] = 'El nombre es obligatorio';
            if (ejecutarConsulta($conexion, "SELECT 1 FROM materias WHERE nombre = ?", 's', [$nombre], true)->num_rows > 0)
                $errors['nombre'] = 'La materia ya existe';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "INSERT INTO materias (nombre, descripcion) VALUES (?, ?)", 'ss', [$nombre, $descripcion]);
            $newId = $conexion->insert_id;
            $response = ['status' => 'success', 'message' => 'Materia creada correctamente', 'data' => [
                'id' => $newId, 'nombre' => $nombre, 'descripcion' => $descripcion]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_materia') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = sanitizar($_POST['nombre'] ?? '', $conexion);
            $descripcion = sanitizar($_POST['descripcion'] ?? '', $conexion);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido';
            if (!$nombre) $errors['nombre'] = 'El nombre es obligatorio';
            if (ejecutarConsulta($conexion, "SELECT 1 FROM materias WHERE nombre = ? AND id != ?", 'si', [$nombre, $id], true)->num_rows > 0)
                $errors['nombre'] = 'Otra materia ya tiene ese nombre';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "UPDATE materias SET nombre = ?, descripcion = ? WHERE id = ?", 'ssi', [$nombre, $descripcion, $id]);
            $response = ['status' => 'success', 'message' => 'Materia actualizada correctamente', 'data' => [
                'id' => $id, 'nombre' => $nombre, 'descripcion' => $descripcion]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_materia') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');
            if (ejecutarConsulta($conexion, "SELECT COUNT(*) FROM grupos WHERE materia_id = ?", 'i', [$id], true)->fetch_row()[0] > 0)
                throw new Exception('No se puede eliminar: materia con grupos asociados');
            ejecutarConsulta($conexion, "DELETE FROM materias WHERE id = ?", 'i', [$id]);
            $response = ['status' => 'success', 'message' => 'Materia eliminada correctamente', 'data' => ['id' => $id]];
            $conexion->commit();
        }

        // CRUD Grupos
        elseif (isset($_POST['action']) && $_POST['action'] === 'create_grupo') {
            $materia_id = (int)($_POST['materia_id'] ?? 0);
            $semestre_id = (int)($_POST['semestre_id'] ?? 0);
            $letra_grupo = strtoupper(sanitizar($_POST['letra_grupo'] ?? '', $conexion));
            $grado = (int)($_POST['grado'] ?? 0);
            $docente_id = (int)($_POST['docente_id'] ?? 0);

            $errors = [];
            if ($materia_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM materias WHERE id = ?", 'i', [$materia_id], true)->num_rows == 0)
                $errors['materia_id'] = 'Materia inválida';
            if ($semestre_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM semestres WHERE id = ?", 'i', [$semestre_id], true)->num_rows == 0)
                $errors['semestre_id'] = 'Semestre inválido';
            if (!in_array($letra_grupo, ['A', 'B'])) $errors['letra_grupo'] = 'Letra de grupo inválida (A o B)';
            if ($grado < 1 || $grado > 6) $errors['grado'] = 'Grado inválido (1-6)';
            if ($docente_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM docentes WHERE usuario_id = ?", 'i', [$docente_id], true)->num_rows == 0)
                $errors['docente_id'] = 'Docente inválido';
            if (ejecutarConsulta($conexion, "SELECT 1 FROM grupos WHERE materia_id = ? AND semestre_id = ? AND letra_grupo = ?", 'iis', [$materia_id, $semestre_id, $letra_grupo], true)->num_rows > 0)
                $errors['grupo'] = 'El grupo ya existe';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "INSERT INTO grupos (materia_id, semestre_id, letra_grupo, grado, docente_id) VALUES (?, ?, ?, ?, ?)",
                'iisii', [$materia_id, $semestre_id, $letra_grupo, $grado, $docente_id]);
            $newId = $conexion->insert_id;

            $materia = ejecutarConsulta($conexion, "SELECT nombre FROM materias WHERE id = ?", 'i', [$materia_id], true)->fetch_assoc()['nombre'];
            $semestre = ejecutarConsulta($conexion, "SELECT numero FROM semestres WHERE id = ?", 'i', [$semestre_id], true)->fetch_assoc()['numero'];
            $docente = ejecutarConsulta($conexion, "SELECT u.nombre_usuario FROM usuarios u JOIN docentes d ON u.id = d.usuario_id WHERE u.id = ?", 'i', [$docente_id], true)->fetch_assoc()['nombre_usuario'];

            $response = ['status' => 'success', 'message' => 'Grupo creado correctamente', 'data' => [
                'id' => $newId, 'materia' => $materia, 'semestre' => $semestre, 'letra_grupo' => $letra_grupo, 'grado' => $grado, 'docente' => $docente,
                'materia_id' => $materia_id, 'semestre_id' => $semestre_id, 'docente_id' => $docente_id]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_grupo') {
            $id = (int)($_POST['id'] ?? 0);
            $materia_id = (int)($_POST['materia_id'] ?? 0);
            $semestre_id = (int)($_POST['semestre_id'] ?? 0);
            $letra_grupo = strtoupper(sanitizar($_POST['letra_grupo'] ?? '', $conexion));
            $grado = (int)($_POST['grado'] ?? 0);
            $docente_id = (int)($_POST['docente_id'] ?? 0);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido';
            if ($materia_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM materias WHERE id = ?", 'i', [$materia_id], true)->num_rows ==  0)
                $errors['materia_id'] = 'Materia inválida';
            if ($semestre_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM semestres WHERE id = ?", 'i', [$semestre_id], true)->num_rows == 0)
                $errors['semestre_id'] = 'Semestre inválido';
            if (!in_array($letra_grupo, ['A', 'B'])) $errors['letra_grupo'] = 'Letra de grupo inválida (A o B)';
            if ($grado < 1 || $grado > 6) $errors['grado'] = 'Grado inválido (1-6)';
            if ($docente_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM docentes WHERE usuario_id = ?", 'i', [$docente_id], true)->num_rows == 0)
                $errors['docente_id'] = 'Docente inválido';
            if (ejecutarConsulta($conexion, "SELECT 1 FROM grupos WHERE materia_id = ? AND semestre_id = ? AND letra_grupo = ? AND id != ?", 'iisi', [$materia_id, $semestre_id, $letra_grupo, $id], true)->num_rows > 0)
                $errors['grupo'] = 'Otro grupo ya existe con esos datos';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "UPDATE grupos SET materia_id = ?, semestre_id = ?, letra_grupo = ?, grado = ?, docente_id = ? WHERE id = ?",
                'iisiii', [$materia_id, $semestre_id, $letra_grupo, $grado, $docente_id, $id]);

            $materia = ejecutarConsulta($conexion, "SELECT nombre FROM materias WHERE id = ?", 'i', [$materia_id], true)->fetch_assoc()['nombre'];
            $semestre = ejecutarConsulta($conexion, "SELECT numero FROM semestres WHERE id = ?", 'i', [$semestre_id], true)->fetch_assoc()['numero'];
            $docente = ejecutarConsulta($conexion, "SELECT u.nombre_usuario FROM usuarios u JOIN docentes d ON u.id = d.usuario_id WHERE u.id = ?", 'i', [$docente_id], true)->fetch_assoc()['nombre_usuario'];

            $response = ['status' => 'success', 'message' => 'Grupo actualizado correctamente', 'data' => [
                'id' => $id, 'materia' => $materia, 'semestre' => $semestre, 'letra_grupo' => $letra_grupo, 'grado' => $grado, 'docente' => $docente,
                'materia_id' => $materia_id, 'semestre_id' => $semestre_id, 'docente_id' => $docente_id]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_grupo') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');
            if (ejecutarConsulta($conexion, "SELECT COUNT(*) FROM parciales WHERE grupo_id = ?", 'i', [$id], true)->fetch_row()[0] > 0)
                throw new Exception('No se puede eliminar: grupo con parciales asociados');
            ejecutarConsulta($conexion, "DELETE FROM grupos WHERE id = ?", 'i', [$id]);
            $response = ['status' => 'success', 'message' => 'Grupo eliminado correctamente', 'data' => ['id' => $id]];
            $conexion->commit();
        }

        // CRUD Generaciones
        elseif (isset($_POST['action']) && $_POST['action'] === 'create_generacion') {
            $nombre = sanitizar($_POST['nombre'] ?? '', $conexion);
            $fecha_inicio = sanitizar($_POST['fecha_inicio'] ?? '', $conexion);
            $fecha_fin = sanitizar($_POST['fecha_fin'] ?? '', $conexion);

            $errors = [];
            if (!$nombre) $errors['nombre'] = 'El nombre es obligatorio';
            if (!$fecha_inicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio)) $errors['fecha_inicio'] = 'Fecha de inicio inválida';
            if (!$fecha_fin || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) $errors['fecha_fin'] = 'Fecha de fin inválida';
            if (strtotime($fecha_inicio) >= strtotime($fecha_fin)) $errors['fechas'] = 'Fecha de inicio debe ser anterior a la de fin';
            if (ejecutarConsulta($conexion, "SELECT 1 FROM generaciones WHERE nombre = ?", 's', [$nombre], true)->num_rows > 0)
                $errors['nombre'] = 'La generación ya existe';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "INSERT INTO generaciones (nombre, fecha_inicio, fecha_fin) VALUES (?, ?, ?)",
                'sss', [$nombre, $fecha_inicio, $fecha_fin]);
            $newId = $conexion->insert_id;

            $response = ['status' => 'success', 'message' => 'Generación creada correctamente', 'data' => [
                'id' => $newId, 'nombre' => $nombre, 'fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_generacion') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = sanitizar($_POST['nombre'] ?? '', $conexion);
            $fecha_inicio = sanitizar($_POST['fecha_inicio'] ?? '', $conexion);
            $fecha_fin = sanitizar($_POST['fecha_fin'] ?? '', $conexion);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido';
            if (!$nombre) $errors['nombre'] = 'El nombre es obligatorio';
            if (!$fecha_inicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio)) $errors['fecha_inicio'] = 'Fecha de inicio inválida';
            if (!$fecha_fin || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) $errors['fecha_fin'] = 'Fecha de fin inválida';
            if (strtotime($fecha_inicio) >= strtotime($fecha_fin)) $errors['fechas'] = 'Fecha de inicio debe ser anterior a la de fin';
            if (ejecutarConsulta($conexion, "SELECT 1 FROM generaciones WHERE nombre = ? AND id != ?", 'si', [$nombre, $id], true)->num_rows > 0)
                $errors['nombre'] = 'Otra generación ya tiene ese nombre';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "UPDATE generaciones SET nombre = ?, fecha_inicio = ?, fecha_fin = ? WHERE id = ?",
                'sssi', [$nombre, $fecha_inicio, $fecha_fin, $id]);

            $response = ['status' => 'success', 'message' => 'Generación actualizada correctamente', 'data' => [
                'id' => $id, 'nombre' => $nombre, 'fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_generacion') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');
            if (ejecutarConsulta($conexion, "SELECT COUNT(*) FROM semestres WHERE generacion_id = ?", 'i', [$id], true)->fetch_row()[0] > 0)
                throw new Exception('No se puede eliminar: generación con semestres asociados');
            ejecutarConsulta($conexion, "DELETE FROM generaciones WHERE id = ?", 'i', [$id]);
            $response = ['status' => 'success', 'message' => 'Generación eliminada correctamente', 'data' => ['id' => $id]];
            $conexion->commit();
        }

        // CRUD Parciales
        elseif (isset($_POST['action']) && $_POST['action'] === 'create_parcial') {
            $grupo_id = (int)($_POST['grupo_id'] ?? 0);
            $numero_parcial = (int)($_POST['numero_parcial'] ?? 0);
            $fecha_inicio = sanitizar($_POST['fecha_inicio'] ?? '', $conexion);
            $fecha_fin = sanitizar($_POST['fecha_fin'] ?? '', $conexion);

            $errors = [];
            if ($grupo_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM grupos WHERE id = ?", 'i', [$grupo_id], true)->num_rows == 0)
                $errors['grupo_id'] = 'Grupo inválido';
            if ($numero_parcial < 1 || $numero_parcial > 3) $errors['numero_parcial'] = 'Número de parcial debe ser 1-3';
            if (!$fecha_inicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio)) $errors['fecha_inicio'] = 'Fecha de inicio inválida';
            if (!$fecha_fin || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) $errors['fecha_fin'] = 'Fecha de fin inválida';
            if (strtotime($fecha_inicio) >= strtotime($fecha_fin)) $errors['fechas'] = 'Fecha de inicio debe ser anterior a la de fin';
            if (ejecutarConsulta($conexion, "SELECT COUNT(*) FROM parciales WHERE grupo_id = ?", 'i', [$grupo_id], true)->fetch_row()[0] >= 3)
                $errors['parciales'] = 'Máximo 3 parciales por grupo';
            if (ejecutarConsulta($conexion, "SELECT 1 FROM parciales WHERE grupo_id = ? AND numero_parcial = ?", 'ii', [$grupo_id, $numero_parcial], true)->num_rows > 0)
                $errors['numero_parcial'] = 'Parcial ya existe para este grupo';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "INSERT INTO parciales (grupo_id, numero_parcial, fecha_inicio, fecha_fin) VALUES (?, ?, ?, ?)",
                'iiss', [$grupo_id, $numero_parcial, $fecha_inicio, $fecha_fin]);
            $newId = $conexion->insert_id;

            $grupo = ejecutarConsulta($conexion, "SELECT g.letra_grupo, m.nombre AS materia, s.numero AS semestre 
                FROM grupos g JOIN materias m ON g.materia_id = m.id JOIN semestres s ON g.semestre_id = s.id WHERE g.id = ?", 'i', [$grupo_id], true)->fetch_assoc();
            $response = ['status' => 'success', 'message' => 'Parcial creado correctamente', 'data' => [
                'id' => $newId, 'numero_parcial' => $numero_parcial, 'letra_grupo' => $grupo['letra_grupo'], 'materia' => $grupo['materia'], 
                'semestre' => $grupo['semestre'], 'grupo_id' => $grupo_id, 'fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_parcial') {
            $id = (int)($_POST['id'] ?? 0);
            $grupo_id = (int)($_POST['grupo_id'] ?? 0);
            $numero_parcial = (int)($_POST['numero_parcial'] ?? 0);
            $fecha_inicio = sanitizar($_POST['fecha_inicio'] ?? '', $conexion);
            $fecha_fin = sanitizar($_POST['fecha_fin'] ?? '', $conexion);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido';
            if ($grupo_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM grupos WHERE id = ?", 'i', [$grupo_id], true)->num_rows == 0)
                $errors['grupo_id'] = 'Grupo inválido';
            if ($numero_parcial < 1 || $numero_parcial > 3) $errors['numero_parcial'] = 'Número de parcial debe ser 1-3';
            if (!$fecha_inicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio)) $errors['fecha_inicio'] = 'Fecha de inicio inválida';
            if (!$fecha_fin || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) $errors['fecha_fin'] = 'Fecha de fin inválida';
            if (strtotime($fecha_inicio) >= strtotime($fecha_fin)) $errors['fechas'] = 'Fecha de inicio debe ser anterior a la de fin';
            if (ejecutarConsulta($conexion, "SELECT 1 FROM parciales WHERE grupo_id = ? AND numero_parcial = ? AND id != ?", 'iii', [$grupo_id, $numero_parcial, $id], true)->num_rows > 0)
                $errors['numero_parcial'] = 'Otro parcial ya existe con ese número';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "UPDATE parciales SET grupo_id = ?, numero_parcial = ?, fecha_inicio = ?, fecha_fin = ? WHERE id = ?",
                'iissi', [$grupo_id, $numero_parcial, $fecha_inicio, $fecha_fin, $id]);

            $grupo = ejecutarConsulta($conexion, "SELECT g.letra_grupo, m.nombre AS materia, s.numero AS semestre 
                FROM grupos g JOIN materias m ON g.materia_id = m.id JOIN semestres s ON g.semestre_id = s.id WHERE g.id = ?", 'i', [$grupo_id], true)->fetch_assoc();
            $response = ['status' => 'success', 'message' => 'Parcial actualizado correctamente', 'data' => [
                'id' => $id, 'numero_parcial' => $numero_parcial, 'letra_grupo' => $grupo['letra_grupo'], 'materia' => $grupo['materia'], 
                'semestre' => $grupo['semestre'], 'grupo_id' => $grupo_id, 'fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_parcial') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido');
            if (ejecutarConsulta($conexion, "SELECT COUNT(*) FROM calificaciones WHERE parcial_id = ?", 'i', [$id], true)->fetch_row()[0] > 0)
                throw new Exception('No se puede eliminar: parcial con calificaciones asociadas');
            ejecutarConsulta($conexion, "DELETE FROM parciales WHERE id = ?", 'i', [$id]);
            $response = ['status' => 'success', 'message' => 'Parcial eliminado correctamente', 'data' => ['id' => $id]];
            $conexion->commit();
        }

        // CRUD Calificaciones
        elseif (isset($_POST['action']) && $_POST['action'] === 'create_calificacion') {
            $alumno_id = (int)($_POST['alumno_id'] ?? 0);
            $parcial_id = (int)($_POST['parcial_id'] ?? 0);
            $calificacion = (float)($_POST['calificacion'] ?? 0);
            $penalizacion = (float)($_POST['asistencia_penalizacion'] ?? 0);

            $errors = [];
            if ($alumno_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM alumnos WHERE usuario_id = ?", 'i', [$alumno_id], true)->num_rows == 0)
                $errors['alumno_id'] = 'Alumno inválido';
            if ($parcial_id <= 0 || ejecutarConsulta($conexion, "SELECT 1 FROM parciales WHERE id = ?", 'i', [$parcial_id], true)->num_rows == 0)
                $errors['parcial_id'] = 'Parcial inválido';
            if ($calificacion < 0 || $calificacion > 99.99) $errors['calificacion'] = 'Calificación debe estar entre 0 y 99.99';
            if ($penalizacion < 0) $errors['penalizacion'] = 'Penalización no puede ser negativa';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $total = max(0, $calificacion - $penalizacion);
            ejecutarConsulta($conexion, "INSERT INTO calificaciones (alumno_id, parcial_id, calificacion, asistencia_penalizacion, total) 
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE calificacion = ?, asistencia_penalizacion = ?, total = ?",
                'iidddddd', [$alumno_id, $parcial_id, $calificacion, $penalizacion, $total, $calificacion, $penalizacion, $total]);

            $alumno = ejecutarConsulta($conexion, "SELECT nombre_usuario FROM usuarios WHERE id = ?", 'i', [$alumno_id], true)->fetch_assoc()['nombre_usuario'];
            $numero_parcial = ejecutarConsulta($conexion, "SELECT numero_parcial FROM parciales WHERE id = ?", 'i', [$parcial_id], true)->fetch_assoc()['numero_parcial'];

            $response = ['status' => 'success', 'message' => 'Calificación registrada correctamente', 'data' => [
                'alumno_id' => $alumno_id, 'parcial_id' => $parcial_id, 'calificacion' => $calificacion, 'asistencia_penalizacion' => $penalizacion, 'total' => $total,
                'alumno' => $alumno, 'numero_parcial' => $numero_parcial]];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_calificacion') {
            $alumno_id = (int)($_POST['alumno_id'] ?? 0);
            $parcial_id = (int)($_POST['parcial_id'] ?? 0);

            $errors = [];
            if ($alumno_id <= 0) $errors['alumno_id'] = 'ID de alumno inválido';
            if ($parcial_id <= 0) $errors['parcial_id'] = 'ID de parcial inválido';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            ejecutarConsulta($conexion, "DELETE FROM calificaciones WHERE alumno_id = ? AND parcial_id = ?", 'ii', [$alumno_id, $parcial_id]);
            $response = ['status' => 'success', 'message' => 'Calificación eliminada correctamente', 'data' => ['alumno_id' => $alumno_id, 'parcial_id' => $parcial_id]];
            $conexion->commit();
        }

        // CRUD Solicitudes de Recuperación
        elseif (isset($_POST['action']) && $_POST['action'] === 'process_solicitud') {
            $solicitud_id = (int)($_POST['solicitud_id'] ?? 0);
            $nueva_contrasena = sanitizar($_POST['nueva_contrasena'] ?? '', $conexion);

            $errors = [];
            if ($solicitud_id <= 0) $errors['solicitud_id'] = 'ID de solicitud inválido';
            if (!$nueva_contrasena || strlen($nueva_contrasena) < 8) $errors['nueva_contrasena'] = 'La contraseña debe tener al menos 8 caracteres';
            if (!empty($errors)) throw new Exception(json_encode(['message' => 'Datos inválidos', 'errors' => $errors]));

            $solicitud = ejecutarConsulta($conexion, "SELECT usuario_id, correo FROM solicitudes_recuperacion WHERE id = ? AND estado = 'pendiente'", 'i', [$solicitud_id], true)->fetch_assoc();
            if (!$solicitud || !$solicitud['usuario_id']) throw new Exception('Solicitud no válida o ya procesada');

            $contrasena_hash = password_hash($nueva_contrasena, PASSWORD_BCRYPT);
            ejecutarConsulta($conexion, "UPDATE usuarios SET contraseña = ? WHERE id = ?", 'si', [$contrasena_hash, $solicitud['usuario_id']]);
            ejecutarConsulta($conexion, "INSERT INTO historial_contrasenas (usuario_id, fecha_cambio) VALUES (?, NOW())", 'i', [$solicitud['usuario_id']]);
            ejecutarConsulta($conexion, "UPDATE solicitudes_recuperacion SET estado = 'procesada' WHERE id = ?", 'i', [$solicitud_id]);

            $response = ['status' => 'success', 'message' => 'Solicitud procesada y contraseña actualizada', 'data' => ['solicitud_id' => $solicitud_id]];
            $conexion->commit();
        }

        // Dashboard Data
        elseif (isset($_POST['action']) && $_POST['action'] === 'dashboard_data') {
            $usuarios_estados = ejecutarConsulta($conexion, "SELECT estado, COUNT(*) as total FROM usuarios GROUP BY estado", '', [], true)->fetch_all(MYSQLI_ASSOC);
            $usuarios_activos = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'activo'), 'total')[0] ?? 0;
            $usuarios_inactivos = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'inactivo'), 'total')[0] ?? 0;
            $usuarios_bloqueados = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'bloqueado'), 'total')[0] ?? 0;

            $semestres_activos = ejecutarConsulta($conexion, "SELECT COUNT(*) as total FROM semestres WHERE fecha_fin >= CURDATE()", '', [], true)->fetch_assoc()['total'] ?? 0;
            $total_eventos = ejecutarConsulta($conexion, "SELECT COUNT(*) as total FROM eventos", '', [], true)->fetch_assoc()['total'] ?? 0;
            $recuperaciones_pendientes = ejecutarConsulta($conexion, "SELECT COUNT(*) as total FROM solicitudes_recuperacion WHERE estado = 'pendiente'", '', [], true)->fetch_assoc()['total'] ?? 0;

            $avisos_recientes = ejecutarConsulta($conexion, "SELECT id, titulo, contenido, prioridad, fecha_creacion FROM avisos WHERE usuario_id = ? ORDER BY fecha_creacion DESC LIMIT 3",
                'i', [$usuario_id], true)->fetch_all(MYSQLI_ASSOC);
            $eventos_proximos = ejecutarConsulta($conexion, "SELECT id, titulo, fecha, tipo_evento FROM eventos WHERE fecha >= CURDATE() ORDER BY fecha ASC LIMIT 3", '', [], true)->fetch_all(MYSQLI_ASSOC);

            $dashboard_data = [
                'usuarios_activos' => $usuarios_activos,
                'usuarios_inactivos' => $usuarios_inactivos,
                'usuarios_bloqueados' => $usuarios_bloqueados,
                'semestres_activos' => $semestres_activos,
                'total_eventos' => $total_eventos,
                'recuperaciones_pendientes' => $recuperaciones_pendientes,
                'avisos' => $avisos_recientes,
                'eventos_proximos' => $eventos_proximos
            ];
            $response = ['status' => 'success', 'message' => 'Datos del dashboard obtenidos', 'data' => $dashboard_data];
            $conexion->commit();
        }

    } catch (Exception $e) {
        $conexion->rollback();
        $message = $e->getMessage();
        $response = json_decode($message, true) ?: ['status' => 'error', 'message' => $message];
        logError("Error en la operación CRUD: " . $message);
    }

    echo json_encode($response);
    exit();
}

// Consultas para datos iniciales
try {
    $usuarios_estados = ejecutarConsulta($conexion, "SELECT estado, COUNT(*) as total FROM usuarios GROUP BY estado", '', [], true)->fetch_all(MYSQLI_ASSOC);
    $usuarios_activos = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'activo'), 'total')[0] ?? 0;
    $usuarios_inactivos = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'inactivo'), 'total')[0] ?? 0;
    $usuarios_bloqueados = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'bloqueado'), 'total')[0] ?? 0;

    $semestres_activos = ejecutarConsulta($conexion, "SELECT COUNT(*) as total FROM semestres WHERE fecha_fin >= CURDATE()", '', [], true)->fetch_assoc()['total'] ?? 0;
    $total_eventos = ejecutarConsulta($conexion, "SELECT COUNT(*) as total FROM eventos", '', [], true)->fetch_assoc()['total'] ?? 0;
    $eventos_proximos = ejecutarConsulta($conexion, "SELECT id, titulo, fecha, tipo_evento FROM eventos WHERE fecha >= CURDATE() ORDER BY fecha ASC LIMIT 3", '', [], true)->fetch_all(MYSQLI_ASSOC);

    $usuario_id = (int)$_SESSION['id'];
    $avisos = ejecutarConsulta($conexion, "SELECT id, titulo, contenido, prioridad, fecha_creacion FROM avisos WHERE usuario_id = ? ORDER BY fecha_creacion DESC",
        'i', [$usuario_id], true)->fetch_all(MYSQLI_ASSOC);
    $avisos_recientes = array_slice($avisos, 0, 3);

    $recuperaciones_pendientes = ejecutarConsulta($conexion, "SELECT COUNT(*) as total FROM solicitudes_recuperacion WHERE estado = 'pendiente'", '', [], true)->fetch_assoc()['total'] ?? 0;
    $solicitudes_recuperacion = ejecutarConsulta($conexion, "SELECT sr.id, sr.correo, sr.fecha_solicitud, sr.estado, u.nombre_usuario 
        FROM solicitudes_recuperacion sr LEFT JOIN usuarios u ON sr.usuario_id = u.id WHERE sr.estado = 'pendiente' ORDER BY sr.fecha_solicitud DESC", '', [], true)->fetch_all(MYSQLI_ASSOC);

    $eventos = ejecutarConsulta($conexion, "SELECT id, titulo, fecha, descripcion, ruta_foto, tipo_evento, fecha_creacion FROM eventos ORDER BY fecha DESC", '', [], true)->fetch_all(MYSQLI_ASSOC);
    $semestres = ejecutarConsulta($conexion, "SELECT s.id, s.numero, g.nombre AS generacion, s.fecha_inicio, s.fecha_fin, s.generacion_id 
        FROM semestres s JOIN generaciones g ON s.generacion_id = g.id ORDER BY s.numero", '', [], true)->fetch_all(MYSQLI_ASSOC);
    $materias = ejecutarConsulta($conexion, "SELECT id, nombre, descripcion FROM materias ORDER BY nombre", '', [], true)->fetch_all(MYSQLI_ASSOC);
    $grupos = ejecutarConsulta($conexion, "SELECT g.id, m.nombre AS materia, s.numero AS semestre, g.letra_grupo, g.grado, u.nombre_usuario AS docente, g.materia_id, g.semestre_id, g.docente_id 
        FROM grupos g JOIN materias m ON g.materia_id = m.id JOIN semestres s ON g.semestre_id = s.id JOIN usuarios u ON g.docente_id = u.id ORDER BY s.numero", '', [], true)->fetch_all(MYSQLI_ASSOC);
    $parciales = ejecutarConsulta($conexion, "SELECT p.id, p.numero_parcial, g.letra_grupo, m.nombre AS materia, s.numero AS semestre, p.grupo_id, p.fecha_inicio, p.fecha_fin 
        FROM parciales p JOIN grupos g ON p.grupo_id = g.id JOIN materias m ON g.materia_id = m.id JOIN semestres s ON g.semestre_id = s.id ORDER BY p.numero_parcial", '', [], true)->fetch_all(MYSQLI_ASSOC);
    $calificaciones = ejecutarConsulta($conexion, "SELECT c.alumno_id, c.parcial_id, c.calificacion, c.asistencia_penalizacion, c.total, u.nombre_usuario AS alumno, p.numero_parcial 
        FROM calificaciones c JOIN usuarios u ON c.alumno_id = u.id JOIN parciales p ON c.parcial_id = p.id ORDER BY u.nombre_usuario", '', [], true)->fetch_all(MYSQLI_ASSOC);
    $generaciones = ejecutarConsulta($conexion, "SELECT id, nombre, fecha_inicio, fecha_fin FROM generaciones ORDER BY nombre", '', [], true)->fetch_all(MYSQLI_ASSOC);
    $docentes = ejecutarConsulta($conexion, "SELECT u.id, u.nombre_usuario FROM docentes d JOIN usuarios u ON d.usuario_id = u.id ORDER BY u.nombre_usuario", '', [], true)->fetch_all(MYSQLI_ASSOC);

    $filtro_nombre = sanitizar($_GET['filtro_nombre'] ?? '', $conexion);
    $filtro_nia = sanitizar($_GET['filtro_nia'] ?? '', $conexion);
    $filtro_tipo = sanitizar($_GET['filtro_tipo'] ?? '', $conexion);
    $filtro_estado = sanitizar($_GET['filtro_estado'] ?? 'activo', $conexion);

    $whereClauses = [];
    $params = [];
    $types = '';

    if ($filtro_nombre) {
        $whereClauses[] = "u.nombre_usuario LIKE ?";
        $params[] = "%$filtro_nombre%";
        $types .= 's';
    }
    if ($filtro_nia) {
        $whereClauses[] = "a.nia LIKE ?";
        $params[] = "%$filtro_nia%";
        $types .= 's';
    }
    if ($filtro_tipo && in_array($filtro_tipo, ['alumno', 'docente', 'administrativo'])) {
        $whereClauses[] = "u.tipo = ?";
        $params[] = $filtro_tipo;
        $types .= 's';
    }
    if ($filtro_estado && in_array($filtro_estado, ['activo', 'inactivo', 'bloqueado', 'todos'])) {
        if ($filtro_estado !== 'todos') {
            $whereClauses[] = "u.estado = ?";
            $params[] = $filtro_estado;
            $types .= 's';
        }
    } else {
        $whereClauses[] = "u.estado = 'activo'";
        $params[] = 'activo';
        $types .= 's';
    }

    $sql = "SELECT u.id, u.correo, u.nombre_usuario, u.tipo, u.estado, a.nia, a.generacion_id, d.especialidad, adm.departamento
        FROM usuarios u LEFT JOIN alumnos a ON u.id = a.usuario_id LEFT JOIN docentes d ON u.id = d.usuario_id LEFT JOIN administrativos adm ON u.id = adm.usuario_id";
    if (!empty($whereClauses)) $sql .= " WHERE " . implode(' AND ', $whereClauses);
    $sql .= " ORDER BY u.id DESC";

    $usuarios = ejecutarConsulta($conexion, $sql, $types, $params, true)->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    logError("Error en consultas iniciales: " . $e->getMessage());
    $usuarios_activos = $usuarios_inactivos = $usuarios_bloqueados = $semestres_activos = $total_eventos = $recuperaciones_pendientes = 0;
    $avisos = $avisos_recientes = $eventos = $eventos_proximos = $semestres = $materias = $grupos = $parciales = $calificaciones = $generaciones = $docentes = $usuarios = $solicitudes_recuperacion = [];
}

$dashboard_data = [
    'usuarios_activos' => $usuarios_activos,
    'usuarios_inactivos' => $usuarios_inactivos,
    'usuarios_bloqueados' => $usuarios_bloqueados,
    'semestres_activos' => $semestres_activos,
    'total_eventos' => $total_eventos,
    'recuperaciones_pendientes' => $recuperaciones_pendientes,
    'avisos' => $avisos_recientes,
    'eventos_proximos' => $eventos_proximos
];
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Panel administrativo para gestionar avisos, eventos, académico, evaluación y más.">
    <title>Panel Administrativo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="./css/administrativo.css">
</head>
<body>
    <!-- Inicializar datos para el JS -->
    <script>
        window.usersData = <?php echo json_encode(array_column($usuarios, null, 'id')); ?>;
    </script>
    <script id="dashboard-data" type="application/json">
        <?php echo json_encode($dashboard_data); ?>
    </script>
    <script id="semestres-data" type="application/json">
        <?php echo json_encode(array_column($semestres, null, 'id')); ?>
    </script>
    <script id="materias-data" type="application/json">
        <?php echo json_encode(array_column($materias, null, 'id')); ?>
    </script>
    <script id="grupos-data" type="application/json">
        <?php echo json_encode(array_column($grupos, null, 'id')); ?>
    </script>
    <script id="parciales-data" type="application/json">
        <?php echo json_encode(array_column($parciales, null, 'id')); ?>
    </script>
    <script id="calificaciones-data" type="application/json">
        <?php echo json_encode(array_column($calificaciones, null, 'parcial_id')); ?>
    </script>
    <script id="generaciones-data" type="application/json">
        <?php echo json_encode(array_column($generaciones, null, 'id')); ?>
    </script>
    <script id="docentes-data" type="application/json">
        <?php echo json_encode(array_column($docentes, null, 'id')); ?>
    </script>
    <script id="avisos-data" type="application/json">
        <?php echo json_encode(array_column($avisos, null, 'id')); ?>
    </script>
    <script id="eventos-data" type="application/json">
        <?php echo json_encode(array_column($eventos, null, 'id')); ?>
    </script>

<div class="admin-container">
    <header class="admin-header" role="banner">
        <div class="admin-header__content">
            <div class="admin-header__brand">
                <h1 class="admin-header__title">Panel Administrativo</h1>
            </div>
            <nav class="admin-nav">
                <ul class="admin-nav__list">
                    <li><a href="#dashboard" class="admin-tab-link active" data-tab="dashboard">Inicio</a></li>
                    <li><a href="#usuarios" class="admin-tab-link" data-tab="usuarios">Usuarios</a></li>
                    <li><a href="#academico" class="admin-tab-link" data-tab="academico">Académico</a></li>
                    <li><a href="#evaluacion" class="admin-tab-link" data-tab="evaluacion">Evaluación</a></li>
                    <li><a href="#reportes" class="admin-tab-link" data-tab="reportes">Reportes</a></li>
                    <li><a href="#eventos" class="admin-tab-link" data-tab="eventos">Eventos</a></li>
                    <li><a href="#avisos" class="admin-tab-link" data-tab="avisos">Avisos</a></li>
                    <li><a href="#solicitudes" class="admin-tab-link" data-tab="solicitudes">Solicitudes</a></li>
                    <li><a href="logout.php" class="logout-button">Cerrar Sesión</a></li>
                </ul>
            </nav>
            <button class="menu-toggle" aria-label="Toggle navigation">☰</button>
            <div class="theme-toggle-container">
                <button class="theme-toggle" aria-label="Cambiar tema">🌙</button>
            </div>
        </div>
    </header>

        <aside class="admin-sidebar">
            <button class="menu-toggle" aria-label="Alternar barra lateral">➤</button>
            <nav class="admin-nav">
                <ul class="admin-nav__list">
                    <li><a href="#dashboard" class="admin-tab-link active" data-tab="dashboard"><i class="fas fa-home"></i> <span>Inicio</span></a></li>
                    <li><a href="#usuarios" class="admin-tab-link" data-tab="usuarios"><i class="fas fa-users-cog"></i> <span>Usuarios</span></a></li>
                    <li><a href="#academico" class="admin-tab-link" data-tab="academico"><i class="fas fa-university"></i> <span>Académico</span></a></li>
                    <li><a href="#evaluacion" class="admin-tab-link" data-tab="evaluacion"><i class="fas fa-clipboard-check"></i> <span>Evaluación</span></a></li>
                    <li><a href="#reportes" class="admin-tab-link" data-tab="reportes"><i class="fas fa-chart-pie"></i> <span>Reportes</span></a></li>
                    <li><a href="#eventos" class="admin-tab-link" data-tab="eventos"><i class="fas fa-calendar-alt"></i> <span>Eventos</span></a></li>
                    <li><a href="#avisos" class="admin-tab-link" data-tab="avisos"><i class="fas fa-bell"></i> <span>Avisos</span></a></li>
                    <li><a href="#solicitudes" class="admin-tab-link" data-tab="solicitudes"><i class="fas fa-key"></i> <span>Solicitudes</span></a></li>
                    <li><a href="logout.php" class="admin-nav__link"><i class="fas fa-sign-out-alt"></i> <span>Cerrar Sesión</span></a></li>
                </ul>
            </nav>
        </aside>

        <main class="admin-main-content">
            <!-- Dashboard -->
            <section id="dashboard" class="admin-section admin-tab" style="display: block;">
                <header class="admin-section-header">
                    <h2 class="admin-section-title">Dashboard</h2>
                </header>
                <div class="dashboard-grid">
                    <div class="admin-card dashboard-card" data-tab="usuarios">
                        <h3><i class="fas fa-users"></i> Usuarios Activos</h3>
                        <p id="usuarios-activos"><?php echo htmlspecialchars($usuarios_activos); ?></p>
                    </div>
                    <div class="admin-card dashboard-card" data-tab="usuarios">
                        <h3><i class="fas fa-user-slash"></i> Usuarios Inactivos</h3>
                        <p id="usuarios-inactivos"><?php echo htmlspecialchars($usuarios_inactivos); ?></p>
                    </div>
                    <div class="admin-card dashboard-card" data-tab="usuarios">
                        <h3><i class="fas fa-user-lock"></i> Usuarios Bloqueados</h3>
                        <p id="usuarios-bloqueados"><?php echo htmlspecialchars($usuarios_bloqueados); ?></p>
                    </div>
                    <div class="admin-card dashboard-card" data-tab="academico">
                        <h3><i class="fas fa-calendar-alt"></i> Semestres Activos</h3>
                        <p id="semestres-activos"><?php echo htmlspecialchars($semestres_activos); ?></p>
                    </div>
                    <div class="admin-card dashboard-card" data-tab="eventos">
                        <h3><i class="fas fa-calendar-check"></i> Total de Eventos</h3>
                        <p id="total-eventos"><?php echo htmlspecialchars($total_eventos); ?></p>
                    </div>
                    <div class="admin-card dashboard-card" data-tab="solicitudes">
                        <h3><i class="fas fa-key"></i> Recuperaciones Pendientes</h3>
                        <p id="recuperaciones-pendientes"><?php echo htmlspecialchars($recuperaciones_pendientes); ?></p>
                    </div>
                    <div class="admin-card dashboard-card" data-tab="avisos">
                        <h3><i class="fas fa-bell"></i> Avisos Recientes</h3>
                        <ul class="dashboard-list" id="avisos-recientes">
                            <?php if (empty($avisos_recientes)): ?>
                                <li>No hay avisos recientes.</li>
                            <?php else: ?>
                                <?php foreach ($avisos_recientes as $aviso): ?>
                                    <li class="prioridad-<?php echo htmlspecialchars($aviso['prioridad']); ?>">
                                        <strong><?php echo htmlspecialchars($aviso['titulo']); ?></strong> - <?php echo $aviso['contenido']; ?>
                                        <small>(<?php echo htmlspecialchars($aviso['fecha_creacion']); ?>)</small>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="admin-card dashboard-card" data-tab="eventos">
                        <h3><i class="fas fa-calendar-day"></i> Eventos Próximos</h3>
                        <ul class="dashboard-list" id="eventos-proximos">
                            <?php if (empty($eventos_proximos)): ?>
                                <li>No hay eventos próximos.</li>
                            <?php else: ?>
                                <?php foreach ($eventos_proximos as $evento): ?>
                                    <li class="tipo-<?php echo htmlspecialchars($evento['tipo_evento']); ?>">
                                        <strong><?php echo htmlspecialchars($evento['titulo']); ?></strong> - <?php echo htmlspecialchars($evento['fecha']); ?>
                                        <small>(<?php echo htmlspecialchars($evento['tipo_evento']); ?>)</small>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Usuarios -->
            <section id="usuarios" class="admin-section admin-tab" style="display: none;">
                <header class="admin-section-header">
                    <h2 class="admin-section-title">Gestión de Usuarios</h2>
                    <button data-modal="modal-usuario" class="admin-button admin-button--primary" data-action="create" aria-label="Crear nuevo usuario">
                        <span>Nuevo Usuario</span>
                    </button>
                </header>
                <form id="user-filter-form" class="admin-form" method="get">
                    <div class="form-grid">
                        <div class="admin-form-group">
                            <input type="text" name="filtro_nombre" value="<?php echo htmlspecialchars($filtro_nombre); ?>" placeholder="Nombre">
                            <span class="floating-label">Filtrar por Nombre</span>
                        </div>
                        <div class="admin-form-group">
                            <input type="text" name="filtro_nia" value="<?php echo htmlspecialchars($filtro_nia); ?>" placeholder="NIA" maxlength="4">
                            <span class="floating-label">Filtrar por NIA</span>
                        </div>
                        <div class="admin-form-group">
                            <select name="filtro_tipo">
                                <option value="">Todos</option>
                                <option value="alumno" <?php echo $filtro_tipo === 'alumno' ? 'selected' : ''; ?>>Alumno</option>
                                <option value="docente" <?php echo $filtro_tipo === 'docente' ? 'selected' : ''; ?>>Docente</option>
                                <option value="administrativo" <?php echo $filtro_tipo === 'administrativo' ? 'selected' : ''; ?>>Administrativo</option>
                            </select>
                            <span class="floating-label">Filtrar por Tipo</span>
                        </div>
                        <div class="admin-form-group">
                            <select name="filtro_estado">
                                <option value="activo" <?php echo $filtro_estado === 'activo' ? 'selected' : ''; ?>>Activos</option>
                                <option value="inactivo" <?php echo $filtro_estado === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                                <option value="bloqueado" <?php echo $filtro_estado === 'bloqueado' ? 'selected' : ''; ?>>Bloqueados</option>
                                <option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos</option>
                            </select>
                            <span class="floating-label">Filtrar por Estado</span>
                        </div>
                        <div class="admin-form-group">
                            <button type="submit" class="admin-button admin-button--primary">Filtrar</button>
                        </div>
                    </div>
                </form>
                <div class="responsive-table">
                    <table class="admin-table" id="usuarios-table">
                        <thead>
                            <tr>
                                <th data-sort>ID</th>
                                <th data-sort>Correo</th>
                                <th data-sort>Nombre de Usuario</th>
                                <th data-sort>Tipo</th>
                                <th data-sort>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $usuario): ?>
                                <tr data-id="<?php echo htmlspecialchars($usuario['id']); ?>">
                                    <td data-label="ID"><?php echo htmlspecialchars($usuario['id']); ?></td>
                                    <td data-label="Correo"><?php echo htmlspecialchars($usuario['correo']); ?></td>
                                    <td data-label="Nombre de Usuario"><?php echo htmlspecialchars($usuario['nombre_usuario']); ?></td>
                                    <td data-label="Tipo"><?php echo htmlspecialchars($usuario['tipo']); ?></td>
                                    <td data-label="Estado"><?php echo htmlspecialchars($usuario['estado']); ?></td>
                                    <td data-label="Acciones">
                                        <button class="admin-button" data-action="edit" data-id="<?php echo htmlspecialchars($usuario['id']); ?>" aria-label="Editar usuario">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="admin-button" data-action="deactivate" data-id="<?php echo htmlspecialchars($usuario['id']); ?>" aria-label="Desactivar usuario">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Avisos -->
            <section id="avisos" class="admin-section admin-tab" style="display: none;">
                <header class="admin-section-header">
                    <h2 class="admin-section-title">Gestión de Avisos</h2>
                    <button data-modal="modal-crear-aviso" class="admin-button admin-button--primary" aria-label="Crear nuevo aviso">
                        <span>Nuevo Aviso</span>
                    </button>
                </header>
                <div class="responsive-table">
                    <table class="admin-table" id="avisos-table">
                        <thead>
                            <tr>
                                <th data-sort>ID</th>
                                <th data-sort>Título</th>
                                <th>Contenido</th>
                                <th data-sort>Prioridad</th>
                                <th data-sort>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($avisos as $aviso): ?>
                                <tr data-id="<?php echo htmlspecialchars($aviso['id']); ?>">
                                    <td data-label="ID"><?php echo htmlspecialchars($aviso['id']); ?></td>
                                    <td data-label="Título"><?php echo htmlspecialchars($aviso['titulo']); ?></td>
                                    <td data-label="Contenido"><?php echo $aviso['contenido']; ?></td>
                                    <td data-label="Prioridad"><?php echo htmlspecialchars($aviso['prioridad']); ?></td>
                                    <td data-label="Fecha Creación"><?php echo htmlspecialchars($aviso['fecha_creacion']); ?></td>
                                    <td data-label="Acciones">
                                        <button class="admin-button" data-action="edit" data-id="<?php echo htmlspecialchars($aviso['id']); ?>" aria-label="Editar aviso">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="admin-button" data-action="delete" data-id="<?php echo htmlspecialchars($aviso['id']); ?>" aria-label="Eliminar aviso">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Eventos -->
            <section id="eventos" class="admin-section admin-tab" style="display: none;">
                <header class="admin-section-header">
                    <h2 class="admin-section-title">Gestión de Eventos</h2>
                    <button data-modal="modal-crear-evento" class="admin-button admin-button--primary" aria-label="Crear nuevo evento">
                        <span>Nuevo Evento</span>
                    </button>
                </header>
                <div class="responsive-table">
                    <table class="admin-table" id="eventos-table">
                        <thead>
                            <tr>
                                <th data-sort>ID</th>
                                <th data-sort>Título</th>
                                <th data-sort>Fecha</th>
                                <th>Descripción</th>
                                <th>Foto</th>
                                <th data-sort>Tipo</th>
                                <th data-sort>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventos as $evento): ?>
                                <tr data-id="<?php echo htmlspecialchars($evento['id']); ?>">
                                    <td data-label="ID"><?php echo htmlspecialchars($evento['id']); ?></td>
                                    <td data-label="Título"><?php echo htmlspecialchars($evento['titulo']); ?></td>
                                    <td data-label="Fecha"><?php echo htmlspecialchars($evento['fecha']); ?></td>
                                    <td data-label="Descripción"><?php echo $evento['descripcion']; ?></td>
                                    <td data-label="Foto"><img src="<?php echo htmlspecialchars($evento['ruta_foto']); ?>" alt="Foto del evento" style="max-width: 50px;"></td>
                                    <td data-label="Tipo"><?php echo htmlspecialchars($evento['tipo_evento']); ?></td>
                                    <td data-label="Fecha Creación"><?php echo htmlspecialchars($evento['fecha_creacion']); ?></td>
                                    <td data-label="Acciones">
                                        <button class="admin-button" data-action="edit" data-id="<?php echo htmlspecialchars($evento['id']); ?>" aria-label="Editar evento">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="admin-button" data-action="delete" data-id="<?php echo htmlspecialchars($evento['id']); ?>" aria-label="Eliminar evento">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Académico -->
            <section id="academico" class="admin-section admin-tab" style="display: none;">
                <header class="admin-section-header">
                    <h2 class="admin-section-title">Gestión Académica</h2>
                </header>
                <div class="admin-subsections">
                    <!-- Semestres -->
                    <div class="admin-subsection">
                        <h3>Semestres</h3>
                        <button data-modal="modal-crear-semestre" class="admin-button admin-button--primary" aria-label="Crear nuevo semestre">
                            <span>Nuevo Semestre</span>
                        </button>
                        <div class="responsive-table">
                            <table class="admin-table" id="semestres-table">
                                <thead>
                                    <tr>
                                        <th data-sort>ID</th>
                                        <th data-sort>Número</th>
                                        <th data-sort>Generación</th>
                                        <th data-sort>Fecha Inicio</th>
                                        <th data-sort>Fecha Fin</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($semestres as $semestre): ?>
                                        <tr data-id="<?php echo htmlspecialchars($semestre['id']); ?>">
                                            <td data-label="ID"><?php echo htmlspecialchars($semestre['id']); ?></td>
                                            <td data-label="Número"><?php echo htmlspecialchars($semestre['numero']); ?></td>
                                            <td data-label="Generación"><?php echo htmlspecialchars($semestre['generacion']); ?></td>
                                            <td data-label="Fecha Inicio"><?php echo htmlspecialchars($semestre['fecha_inicio']); ?></td>
                                            <td data-label="Fecha Fin"><?php echo htmlspecialchars($semestre['fecha_fin']); ?></td>
                                            <td data-label="Acciones">
                                                <button class="admin-button" data-action="edit" data-id="<?php echo htmlspecialchars($semestre['id']); ?>" aria-label="Editar semestre">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="admin-button" data-action="delete" data-id="<?php echo htmlspecialchars($semestre['id']); ?>" aria-label="Eliminar semestre">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Materias -->
                    <div class="admin-subsection">
                        <h3>Materias</h3>
                        <button data-modal="modal-crear-materia" class="admin-button admin-button--primary" aria-label="Crear nueva materia">
                            <span>Nueva Materia</span>
                        </button>
                        <div class="responsive-table">
                            <table class="admin-table" id="materias-table">
                                <thead>
                                    <tr>
                                        <th data-sort>ID</th>
                                        <th data-sort>Nombre</th>
                                        <th>Descripción</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materias as $materia): ?>
                                        <tr data-id="<?php echo htmlspecialchars($materia['id']); ?>">
                                            <td data-label="ID"><?php echo htmlspecialchars($materia['id']); ?></td>
                                            <td data-label="Nombre"><?php echo htmlspecialchars($materia['nombre']); ?></td>
                                            <td data-label="Descripción"><?php echo htmlspecialchars($materia['descripcion']); ?></td>
                                            <td data-label="Acciones">
                                                <button class="admin-button" data-action="edit" data-id="<?php echo htmlspecialchars($materia['id']); ?>" aria-label="Editar materia">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="admin-button" data-action="delete" data-id="<?php echo htmlspecialchars($materia['id']); ?>" aria-label="Eliminar materia">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Grupos -->
                    <div class="admin-subsection">
                        <h3>Grupos</h3>
                        <button data-modal="modal-crear-grupo" class="admin-button admin-button--primary" aria-label="Crear nuevo grupo">
                            <span>Nuevo Grupo</span>
                        </button>
                        <div class="responsive-table">
                            <table class="admin-table" id="grupos-table">
                                <thead>
                                    <tr>
                                        <th data-sort>ID</th>
                                        <th data-sort>Materia</th>
                                        <th data-sort>Semestre</th>
                                        <th data-sort>Letra</th>
                                        <th data-sort>Grado</th>
                                        <th data-sort>Docente</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grupos as $grupo): ?>
                                        <tr data-id="<?php echo htmlspecialchars($grupo['id']); ?>">
                                            <td data-label="ID"><?php echo htmlspecialchars($grupo['id']); ?></td>
                                            <td data-label="Materia"><?php echo htmlspecialchars($grupo['materia']); ?></td>
                                            <td data-label="Semestre"><?php echo htmlspecialchars($grupo['semestre']); ?></td>
                                            <td data-label="Letra"><?php echo htmlspecialchars($grupo['letra_grupo']); ?></td>
                                            <td data-label="Grado"><?php echo htmlspecialchars($grupo['grado']); ?></td>
                                            <td data-label="Docente"><?php echo htmlspecialchars($grupo['docente']); ?></td>
                                            <td data-label="Acciones">
                                                <button class="admin-button" data-action="edit" data-id="<?php echo htmlspecialchars($grupo['id']); ?>" aria-label="Editar grupo">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="admin-button" data-action="delete" data-id="<?php echo htmlspecialchars($grupo['id']); ?>" aria-label="Eliminar grupo">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Generaciones -->
                    <div class="admin-subsection">
                        <h3>Generaciones</h3>
                        <button data-modal="modal-crear-generacion" class="admin-button admin-button--primary" aria-label="Crear nueva generación">
                            <span>Nueva Generación</span>
                        </button>
                        <div class="responsive-table">
                            <table class="admin-table" id="generaciones-table">
                                <thead>
                                    <tr>
                                        <th data-sort>ID</th>
                                        <th data-sort>Nombre</th>
                                        <th data-sort>Fecha Inicio</th>
                                        <th data-sort>Fecha Fin</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($generaciones as $generacion): ?>
                                        <tr data-id="<?php echo htmlspecialchars($generacion['id']); ?>">
                                            <td data-label="ID"><?php echo htmlspecialchars($generacion['id']); ?></td>
                                            <td data-label="Nombre"><?php echo htmlspecialchars($generacion['nombre']); ?></td>
                                            <td data-label="Fecha Inicio"><?php echo htmlspecialchars($generacion['fecha_inicio']); ?></td>
                                            <td data-label="Fecha Fin"><?php echo htmlspecialchars($generacion['fecha_fin']); ?></td>
                                            <td data-label="Acciones">
                                                <button class="admin-button" data-action="edit" data-id="<?php echo htmlspecialchars($generacion['id']); ?>" aria-label="Editar generación">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="admin-button" data-action="delete" data-id="<?php echo htmlspecialchars($generacion['id']); ?>" aria-label="Eliminar generación">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Evaluación -->
            <section id="evaluacion" class="admin-section admin-tab" style="display: none;">
                <header class="admin-section-header">
                    <h2 class="admin-section-title">Gestión de Evaluación</h2>
                </header>
                <div class="admin-subsections">
                    <!-- Parciales -->
                    <div class="admin-subsection">
                        <h3>Parciales</h3>
                        <button data-modal="modal-crear-parcial" class="admin-button admin-button--primary" aria-label="Crear nuevo parcial">
                            <span>Nuevo Parcial</span>
                        </button>
                        <div class="responsive-table">
                            <table class="admin-table" id="parciales-table">
                                <thead>
                                    <tr>
                                        <th data-sort>ID</th>
                                        <th data-sort>Número</th>
                                        <th data-sort>Grupo</th>
                                        <th data-sort>Materia</th>
                                        <th data-sort>Semestre</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($parciales as $parcial): ?>
                                        <tr data-id="<?php echo htmlspecialchars($parcial['id']); ?>">
                                            <td data-label="ID"><?php echo htmlspecialchars($parcial['id']); ?></td>
                                            <td data-label="Número"><?php echo htmlspecialchars($parcial['numero_parcial']); ?></td>
                                            <td data-label="Grupo"><?php echo htmlspecialchars($parcial['letra_grupo']); ?></td>
                                            <td data-label="Materia"><?php echo htmlspecialchars($parcial['materia']); ?></td>
                                            <td data-label="Semestre"><?php echo htmlspecialchars($parcial['semestre']); ?></td>
                                            <td data-label="Acciones">
                                                <button class="admin-button" data-action="edit" data-id="<?php echo htmlspecialchars($parcial['id']); ?>" aria-label="Editar parcial">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="admin-button" data-action="delete" data-id="<?php echo htmlspecialchars($parcial['id']); ?>" aria-label="Eliminar parcial">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Calificaciones -->
                    <div class="admin-subsection">
                        <h3>Calificaciones</h3>
                        <button data-modal="modal-crear-calificacion" class="admin-button admin-button--primary" aria-label="Crear nueva calificación">
                            <span>Nueva Calificación</span>
                        </button>
                        <div class="responsive-table">
                            <table class="admin-table" id="calificaciones-table">
                                <thead>
                                    <tr>
                                        <th data-sort>Alumno</th>
                                        <th data-sort>Parcial</th>
                                        <th data-sort>Calificación</th>
                                        <th data-sort>Penalización</th>
                                        <th data-sort>Total</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($calificaciones as $calificacion): ?>
                                        <tr data-alumno-id="<?php echo htmlspecialchars($calificacion['alumno_id']); ?>" data-parcial-id="<?php echo htmlspecialchars($calificacion['parcial_id']); ?>">
                                            <td data-label="Alumno"><?php echo htmlspecialchars($calificacion['alumno']); ?></td>
                                            <td data-label="Parcial"><?php echo htmlspecialchars($calificacion['numero_parcial']); ?></td>
                                            <td data-label="Calificación"><?php echo htmlspecialchars($calificacion['calificacion']); ?></td>
                                            <td data-label="Penalización"><?php echo htmlspecialchars($calificacion['asistencia_penalizacion']); ?></td>
                                            <td data-label="Total"><?php echo htmlspecialchars($calificacion['total']); ?></td>
                                            <td data-label="Acciones">
                                                <button class="admin-button" data-action="delete" data-alumno-id="<?php echo htmlspecialchars($calificacion['alumno_id']); ?>" data-parcial-id="<?php echo htmlspecialchars($calificacion['parcial_id']); ?>" aria-label="Eliminar calificación">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

<!-- Reportes -->
<section id="reportes" class="admin-section admin-tab" style="display: none;">
    <header class="admin-section-header">
        <h2 class="admin-section-title">Reportes</h2>
        <div class="report-filter">
            <form id="report-filter-form" class="admin-form">
                <div class="admin-form-group">
                    <label for="semestre-select">Semestre:</label>
                    <select id="semestre-select" name="semestre" class="admin-select">
                        <option value="">Selecciona un semestre</option>
                        <!-- Opciones cargadas dinámicamente con JS -->
                    </select>
                </div>
            </form>
        </div>
        <div class="report-buttons">
            <a href="#" data-reporte="resumen" class="admin-button admin-button--primary report-link" target="_blank">Resumen General</a>
            <a href="#" data-reporte="asistencias" class="admin-button admin-button--primary report-link" target="_blank">Asistencias Totales</a>
            <a href="#" data-reporte="calificaciones_totales" class="admin-button admin-button--primary report-link" target="_blank">Calificaciones Totales</a>
            <a href="#" data-reporte="docentes" class="admin-button admin-button--primary report-link" target="_blank">Desempeño Docentes</a>
            <a href="#" data-reporte="recomendaciones" class="admin-button admin-button--primary report-link" target="_blank">Recomendaciones</a>
        </div>
    </header>
    <div class="admin-table-container">
        <table id="historial-reportes" class="admin-table">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <!-- Los reportes se cargarán dinámicamente con JS -->
            </tbody>
        </table>
    </div>
</section>

           <!-- Solicitudes -->
<section id="solicitudes" class="admin-section admin-tab" style="display: none;">
    <header class="admin-section-header">
        <h2 class="admin-section-title">Solicitudes</h2>
    </header>
    <div class="responsive-table">
        <table class="admin-table" id="solicitudes-table">
            <thead>
                <tr>
                    <th data-sort>ID</th>
                    <th data-sort>Usuario</th>
                    <th data-sort>Tipo</th>
                    <th data-sort>Estado</th>
                    <th data-sort>Fecha Solicitud</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($solicitudes_recuperacion)): ?>
                    <tr>
                        <td colspan="6">No hay solicitudes pendientes.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($solicitudes_recuperacion as $solicitud): ?>
                        <tr data-id="<?php echo htmlspecialchars($solicitud['id']); ?>">
                            <td data-label="ID"><?php echo htmlspecialchars($solicitud['id']); ?></td>
                            <td data-label="Usuario"><?php echo htmlspecialchars($solicitud['nombre_usuario'] ?? $solicitud['correo']); ?></td>
                            <td data-label="Tipo">Recuperación de contraseña</td>
                            <td data-label="Estado"><?php echo htmlspecialchars($solicitud['estado']); ?></td>
                            <td data-label="Fecha Solicitud"><?php echo htmlspecialchars($solicitud['fecha_solicitud']); ?></td>
                            <td data-label="Acciones">
                                <button class="admin-button" data-action="process" data-id="<?php echo htmlspecialchars($solicitud['id']); ?>" aria-label="Procesar solicitud">
                                    <i class="fas fa-check"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

            <!-- Modales -->
            <!-- Modal Usuarios -->
<div id="modal-usuario" class="admin-modal">
    <div class="admin-modal-content">
        <header class="admin-modal-header">
            <h3 id="modal-usuario-title">Nuevo Usuario</h3>
            <button class="modal-close" aria-label="Cerrar modal">✖</button>
        </header>
        <form id="usuario-form" class="admin-form">
            <div class="admin-modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="id" id="user-id">
                <input type="hidden" name="action" id="user-action">
                <div class="form-grid">
                    <div class="admin-form-group">
                        <input type="email" name="correo" id="user-correo" required>
                        <span class="floating-label">Correo</span>
                    </div>
                    <div class="admin-form-group" id="contrasena-group">
                        <input type="password" name="contrasena" id="user-contrasena" required>
                        <span class="floating-label">Contraseña</span>
                    </div>
                    <div class="admin-form-group">
                        <input type="text" name="nombre_usuario" id="user-nombre_usuario" required>
                        <span class="floating-label">Nombre de Usuario</span>
                    </div>
                    <!-- Nuevo campo obligatorio -->
                    <div class="admin-form-group">
                        <input type="text" name="nombre_completo" id="user-nombre_completo" required>
                        <span class="floating-label">Nombre Completo</span>
                    </div>
                    <!-- Campo opcional -->
                    <div class="admin-form-group">
                        <input type="text" name="telefono" id="user-telefono">
                        <span class="floating-label">Teléfono</span>
                    </div>
                    <div class="admin-form-group">
                        <select name="tipo" id="user-tipo" required>
                            <option value="">Selecciona un tipo</option>
                            <option value="alumno">Alumno</option>
                            <option value="docente">Docente</option>
                            <option value="administrativo">Administrativo</option>
                        </select>
                        <span class="floating-label">Tipo de Usuario</span>
                    </div>
                    <div class="admin-form-group">
                        <select name="estado" id="user-estado" required>
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
                            <option value="bloqueado">Bloqueado</option>
                        </select>
                        <span class="floating-label">Estado</span>
                    </div>
                    <div id="alumno-fields" style="display: none;">
                        <div class="admin-form-group">
                            <input type="text" name="nia" id="user-nia" maxlength="4">
                            <span class="floating-label">NIA</span>
                        </div>
                        <div class="admin-form-group">
                            <select name="generacion_id" id="user-generacion_id">
                                <option value="">Seleccione una generación</option>
                                <?php foreach ($generaciones as $generacion): ?>
                                    <option value="<?php echo htmlspecialchars($generacion['id']); ?>">
                                        <?php echo htmlspecialchars($generacion['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="floating-label">Generación</span>
                        </div>
                    </div>
                    <div id="docente-fields" style="display: none;">
                        <div class="admin-form-group">
                            <input type="text" name="especialidad" id="user-especialidad">
                            <span class="floating-label">Especialidad</span>
                        </div>
                    </div>
                    <div id="administrativo-fields" style="display: none;">
                        <div class="admin-form-group">
                            <input type="text" name="departamento" id="user-departamento">
                            <span class="floating-label">Departamento</span>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="admin-modal-footer">
                <button type="submit" class="admin-button admin-button--primary">Guardar</button>
            </footer>
        </form>
    </div>
</div>

            <!-- Modal Crear Aviso -->
            <div id="modal-crear-aviso" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Nuevo Aviso</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-aviso-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_aviso">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="titulo" id="crear-titulo" required>
                                    <span class="floating-label">Título</span>
                                </div>
                                <div class="admin-form-group">
                                    <textarea name="contenido" id="crear-contenido" required></textarea>
                                    <span class="floating-label">Contenido</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="prioridad" id="crear-prioridad" required>
                                        <option value="1">Baja</option>
                                        <option value="2" selected>Media</option>
                                        <option value="3">Alta</option>
                                    </select>
                                    <span class="floating-label">Prioridad</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Crear Aviso</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Editar Aviso -->
            <div id="modal-editar-aviso" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Editar Aviso</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="editar-aviso-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_aviso">
                            <input type="hidden" name="id" id="edit-aviso-id">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="titulo" id="edit-aviso-titulo" required>
                                    <span class="floating-label">Título</span>
                                </div>
                                <div class="admin-form-group">
                                    <textarea name="contenido" id="edit-aviso-contenido" required></textarea>
                                    <span class="floating-label">Contenido</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="prioridad" id="edit-aviso-prioridad" required>
                                        <option value="1">Baja</option>
                                        <option value="2">Media</option>
                                        <option value="3">Alta</option>
                                    </select>
                                    <span class="floating-label">Prioridad</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Guardar Cambios</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Crear Evento -->
            <div id="modal-crear-evento" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Nuevo Evento</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-evento-form" class="admin-form" enctype="multipart/form-data">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_evento">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="titulo" id="crear-evento-titulo" required>
                                    <span class="floating-label">Título</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha" id="crear-evento-fecha" required>
                                    <span class="floating-label">Fecha</span>
                                </div>
                                <div class="admin-form-group">
                                    <textarea name="descripcion" id="crear-evento-descripcion" required></textarea>
                                    <span class="floating-label">Descripción</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="file" name="foto" id="crear-evento-foto" accept="image/*">
                                    <span class="floating-label">Foto del Evento</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="tipo_evento" id="crear-evento-tipo" required>
                                        <option value="academico">Académico</option>
                                        <option value="deportivo">Deportivo</option>
                                        <option value="cultural">Cultural</option>
                                    </select>
                                    <span class="floating-label">Tipo de Evento</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Crear Evento</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Editar Evento -->
            <div id="modal-editar-evento" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Editar Evento</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="editar-evento-form" class="admin-form" enctype="multipart/form-data">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_evento">
                            <input type="hidden" name="id" id="edit-evento-id">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="titulo" id="edit-evento-titulo" required>
                                    <span class="floating-label">Título</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha" id="edit-evento-fecha" required>
                                    <span class="floating-label">Fecha</span>
                                </div>
                                <div class="admin-form-group">
                                    <textarea name="descripcion" id="edit-evento-descripcion" required></textarea>
                                    <span class="floating-label">Descripción</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="file" name="foto" id="edit-evento-foto" accept="image/*">
                                    <span class="floating-label">Foto del Evento (opcional)</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="tipo_evento" id="edit-evento-tipo" required>
                                        <option value="academico">Académico</option>
                                        <option value="deportivo">Deportivo</option>
                                        <option value="cultural">Cultural</option>
                                    </select>
                                    <span class="floating-label">Tipo de Evento</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Guardar Cambios</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Crear Semestre -->
            <div id="modal-crear-semestre" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Nuevo Semestre</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-semestre-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_semestre">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <select name="generacion_id" id="crear-semestre-generacion_id" required>
                                        <option value="">Seleccione una generación</option>
                                        <?php foreach ($generaciones as $generacion): ?>
                                            <option value="<?php echo htmlspecialchars($generacion['id']); ?>">
                                                <?php echo htmlspecialchars($generacion['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Generación</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_inicio" id="crear-semestre-fecha_inicio" required>
                                    <span class="floating-label">Fecha Inicio</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_fin" id="crear-semestre-fecha_fin" required>
                                    <span class="floating-label">Fecha Fin</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Crear Semestre</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Editar Semestre -->
            <div id="modal-editar-semestre" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Editar Semestre</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="editar-semestre-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_semestre">
                            <input type="hidden" name="id" id="edit-semestre-id">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <select name="generacion_id" id="edit-semestre-generacion_id" required>
                                        <option value="">Seleccione una generación</option>
                                        <?php foreach ($generaciones as $generacion): ?>
                                            <option value="<?php echo htmlspecialchars($generacion['id']); ?>">
                                                <?php echo htmlspecialchars($generacion['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Generación</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_inicio" id="edit-semestre-fecha_inicio" required>
                                    <span class="floating-label">Fecha Inicio</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_fin" id="edit-semestre-fecha_fin" required>
                                    <span class="floating-label">Fecha Fin</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Guardar Cambios</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Crear Materia -->
            <div id="modal-crear-materia" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Nueva Materia</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-materia-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_materia">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="nombre" id="crear-materia-nombre" required>
                                    <span class="floating-label">Nombre</span>
                                </div>
                                <div class="admin-form-group">
                                    <textarea name="descripcion" id="crear-materia-descripcion"></textarea>
                                    <span class="floating-label">Descripción</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Crear Materia</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Editar Materia -->
            <div id="modal-editar-materia" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Editar Materia</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="editar-materia-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_materia">
                            <input type="hidden" name="id" id="edit-materia-id">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="nombre" id="edit-materia-nombre" required>
                                    <span class="floating-label">Nombre</span>
                                </div>
                                <div class="admin-form-group">
                                    <textarea name="descripcion" id="edit-materia-descripcion"></textarea>
                                    <span class="floating-label">Descripción</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Guardar Cambios</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Crear Grupo -->
            <div id="modal-crear-grupo" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Nuevo Grupo</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-grupo-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_grupo">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <select name="materia_id" id="crear-grupo-materia_id" required>
                                        <option value="">Seleccione una materia</option>
                                        <?php foreach ($materias as $materia): ?>
                                            <option value="<?php echo htmlspecialchars($materia['id']); ?>">
                                                <?php echo htmlspecialchars($materia['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Materia</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="semestre_id" id="crear-grupo-semestre_id" required>
                                        <option value="">Seleccione un semestre</option>
                                        <?php foreach ($semestres as $semestre): ?>
                                            <option value="<?php echo htmlspecialchars($semestre['id']); ?>">
                                                <?php echo htmlspecialchars($semestre['numero'] . ' - ' . $semestre['generacion']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Semestre</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="letra_grupo" id="crear-grupo-letra_grupo" required>
                                        <option value="A">A</option>
                                        <option value="B">B</option>
                                    </select>
                                    <span class="floating-label">Letra del Grupo</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="number" name="grado" id="crear-grupo-grado" min="1" max="6" required>
                                    <span class="floating-label">Grado</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="docente_id" id="crear-grupo-docente_id" required>
                                        <option value="">Seleccione un docente</option>
                                        <?php foreach ($docentes as $docente): ?>
                                            <option value="<?php echo htmlspecialchars($docente['id']); ?>">
                                                <?php echo htmlspecialchars($docente['nombre_usuario']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Docente</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Crear Grupo</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Editar Grupo -->
            <div id="modal-editar-grupo" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Editar Grupo</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="editar-grupo-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_grupo">
                            <input type="hidden" name="id" id="edit-grupo-id">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <select name="materia_id" id="edit-grupo-materia_id" required>
                                        <option value="">Seleccione una materia</option>
                                        <?php foreach ($materias as $materia): ?>
                                            <option value="<?php echo htmlspecialchars($materia['id']); ?>">
                                                <?php echo htmlspecialchars($materia['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Materia</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="semestre_id" id="edit-grupo-semestre_id" required>
                                        <option value="">Seleccione un semestre</option>
                                        <?php foreach ($semestres as $semestre): ?>
                                            <option value="<?php echo htmlspecialchars($semestre['id']); ?>">
                                                <?php echo htmlspecialchars($semestre['numero'] . ' - ' . $semestre['generacion']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Semestre</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="letra_grupo" id="edit-grupo-letra_grupo" required>
                                        <option value="A">A</option>
                                        <option value="B">B</option>
                                    </select>
                                    <span class="floating-label">Letra del Grupo</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="number" name="grado" id="edit-grupo-grado" min="1" max="6" required>
                                    <span class="floating-label">Grado</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="docente_id" id="edit-grupo-docente_id" required>
                                        <option value="">Seleccione un docente</option>
                                        <?php foreach ($docentes as $docente): ?>
                                            <option value="<?php echo htmlspecialchars($docente['id']); ?>">
                                                <?php echo htmlspecialchars($docente['nombre_usuario']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Docente</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Guardar Cambios</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Crear Generación -->
            <div id="modal-crear-generacion" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Nueva Generación</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-generacion-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_generacion">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="nombre" id="crear-generacion-nombre" required>
                                    <span class="floating-label">Nombre</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_inicio" id="crear-generacion-fecha_inicio" required>
                                    <span class="floating-label">Fecha Inicio</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_fin" id="crear-generacion-fecha_fin" required>
                                    <span class="floating-label">Fecha Fin</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Crear Generación</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Editar Generación -->
            <div id="modal-editar-generacion" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Editar Generación</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="editar-generacion-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_generacion">
                            <input type="hidden" name="id" id="edit-generacion-id">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="nombre" id="edit-generacion-nombre" required>
                                    <span class="floating-label">Nombre</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_inicio" id="edit-generacion-fecha_inicio" required>
                                    <span class="floating-label">Fecha Inicio</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_fin" id="edit-generacion-fecha_fin" required>
                                    <span class="floating-label">Fecha Fin</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Guardar Cambios</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Crear Parcial -->
            <div id="modal-crear-parcial" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Nuevo Parcial</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-parcial-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_parcial">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <select name="grupo_id" id="crear-parcial-grupo_id" required>
                                        <option value="">Seleccione un grupo</option>
                                        <?php foreach ($grupos as $grupo): ?>
                                            <option value="<?php echo htmlspecialchars($grupo['id']); ?>">
                                                <?php echo htmlspecialchars($grupo['materia'] . ' - ' . $grupo['letra_grupo'] . ' (Sem ' . $grupo['semestre'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Grupo</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="numero_parcial" id="crear-parcial-numero_parcial" required>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                    </select>
                                    <span class="floating-label">Número de Parcial</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_inicio" id="crear-parcial-fecha_inicio" required>
                                    <span class="floating-label">Fecha Inicio</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_fin" id="crear-parcial-fecha_fin" required>
                                    <span class="floating-label">Fecha Fin</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Crear Parcial</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Editar Parcial -->
            <div id="modal-editar-parcial" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Editar Parcial</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="editar-parcial-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_parcial">
                            <input type="hidden" name="id" id="edit-parcial-id">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <select name="grupo_id" id="edit-parcial-grupo_id" required>
                                        <option value="">Seleccione un grupo</option>
                                        <?php foreach ($grupos as $grupo): ?>
                                            <option value="<?php echo htmlspecialchars($grupo['id']); ?>">
                                                <?php echo htmlspecialchars($grupo['materia'] . ' - ' . $grupo['letra_grupo'] . ' (Sem ' . $grupo['semestre'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Grupo</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="numero_parcial" id="edit-parcial-numero_parcial" required>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                    </select>
                                    <span class="floating-label">Número de Parcial</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_inicio" id="edit-parcial-fecha_inicio" required>
                                    <span class="floating-label">Fecha Inicio</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha_fin" id="edit-parcial-fecha_fin" required>
                                    <span class="floating-label">Fecha Fin</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Guardar Cambios</button>
                        </footer>
                    </form>
                </div>
            </div>

            <!-- Modal Crear Calificación -->
            <div id="modal-crear-calificacion" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Nueva Calificación</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-calificacion-form" class="admin-form">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_calificacion">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <select name="alumno_id" id="crear-calificacion-alumno_id" required>
                                        <option value="">Seleccione un alumno</option>
                                        <?php foreach ($usuarios as $usuario): ?>
                                            <?php if ($usuario['tipo'] === 'alumno'): ?>
                                                <option value="<?php echo htmlspecialchars($usuario['id']); ?>">
                                                    <?php echo htmlspecialchars($usuario['nombre_usuario'] . ' (' . $usuario['nia'] . ')'); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Alumno</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="parcial_id" id="crear-calificacion-parcial_id" required>
                                        <option value="">Seleccione un parcial</option>
                                        <?php foreach ($parciales as $parcial): ?>
                                            <option value="<?php echo htmlspecialchars($parcial['id']); ?>">
                                                <?php echo htmlspecialchars($parcial['numero_parcial'] . ' - ' . $parcial['materia'] . ' (' . $parcial['letra_grupo'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="floating-label">Parcial</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="number" name="calificacion" id="crear-calificacion-calificacion" min="0" max="99.99" step="0.01" required>
                                    <span class="floating-label">Calificación</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="number" name="asistencia_penalizacion" id="crear-calificacion-penalizacion" min="0" step="0.01" value="0">
                                    <span class="floating-label">Penalización por Asistencia</span>
                                </div>
                            </div>
                        </div>
                        <footer class="admin-modal-footer">
                            <button type="submit" class="admin-button admin-button--primary">Registrar Calificación</button>
                        </footer>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="./js/administrativo.js"></script>
</body>
</html>

<?php
ob_end_flush();
$conexion->close();
?>