<?php
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

require 'fpdf186/fpdf.php';
require 'conexion.php';

// Constantes
const UPLOAD_DIR = 'images/eventos/';
const REPORTS_DIR = 'reportes/';
const MAX_FILE_SIZE = 10000000;
const ALLOWED_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

// Encabezados de seguridad
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Verificación de sesión
if (!isset($_SESSION['id']) || !isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'administrativo' ||
    !isset($_SESSION['token']) || empty($_SESSION['token'])) {
    session_regenerate_id(true);
    session_destroy();
    header('Location: index.php?error=sesion_invalida');
    exit();
}
$usuario_id = (int)$_SESSION['id'];
$token_sesion = $_SESSION['token'];

// Regenerar ID de sesión cada 30 minutos
if (!isset($_SESSION['last_regen']) || (time() - $_SESSION['last_regen']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
}

// Generación de token CSRF
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Usar $conexion directamente desde conexion.php
if ($conexion->connect_error) {
    logError("Conexión fallida: " . $conexion->connect_error);
    die("Error del servidor. Contacte al administrador.");
}

// Función de logging
function logError($message) {
    $logDir = 'logs/';
    if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) return;
    $logFile = $logDir . 'app_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] ERROR: $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    error_log($logMessage); // También registra en el log del servidor (visible en consola o archivo de log del servidor)
}

// Función de sanitización
function sanitizar($input, $conexion, $allowHtml = false) {
    if (is_null($input) || $input === '') return '';
    $input = trim($input);
    if (!$allowHtml) {
        $input = strip_tags($input);
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $conexion->real_escape_string($input);
}

// Función para ejecutar consultas preparadas
function ejecutarConsulta($conexion, $query, $tipos = '', $params = [], $returnResult = false) {
    try {
        $stmt = $conexion->prepare($query);
        if (!$stmt) {
            throw new Exception("Error preparando consulta: " . $conexion->error);
        }
        if ($tipos && $params) {
            if (strlen($tipos) !== count($params)) {
                throw new Exception("Mismatch entre tipos ($tipos) y parámetros (" . count($params) . ")");
            }
            $stmt->bind_param($tipos, ...$params);
        }
        if (!$stmt->execute()) {
            throw new Exception("Error ejecutando consulta: " . $stmt->error);
        }
        $result = $returnResult ? $stmt->get_result() : $stmt->affected_rows;
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        logError("Consulta fallida: " . $e->getMessage() . " | Query: $query");
        throw $e;
    }
}

// Función para manejar transacciones
function ejecutarTransaccion($conexion, callable $callback, $errorCallback = null) {
    $conexion->begin_transaction();
    try {
        $result = $callback($conexion);
        $conexion->commit();
        return $result;
    } catch (Exception $e) {
        $conexion->rollback();
        logError("Transacción fallida: " . $e->getMessage());
        if ($errorCallback) $errorCallback($e);
        throw $e;
    }
}

// Obtener datos con paginación
function obtenerDatos($conexion, $query, $tipos = '', $params = [], $pagina = 1, $porPagina = 20) {
    $offset = ($pagina - 1) * $porPagina;
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $porPagina;
    $params[] = $offset;
    $tipos .= 'ii';
    $result = ejecutarConsulta($conexion, $query, $tipos, $params, true);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// Función para subir archivos
function subirArchivo($file, $uploadDir, $allowedTypes, $maxFileSize) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al subir archivo: " . $file['error']);
    }
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception("No se pudo crear directorio de subida");
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!array_key_exists($mime, $allowedMimes) || $file['size'] > $maxFileSize) {
        throw new Exception("Tipo o tamaño de archivo no permitido");
    }
    if (!getimagesize($file['tmp_name'])) {
        throw new Exception("El archivo no es una imagen válida");
    }
    $extension = $allowedMimes[$mime];
    $filename = uniqid('evt_', true) . '.' . $extension;
    $target = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception("Fallo al mover archivo");
    }
    return $target;
}

// CRUD vía GET
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion'])) {
    $accion = sanitizar($_GET['accion'], $conexion);
    $id = (int)($_GET['id'] ?? 0);
    ejecutarTransaccion($conexion, function($conn) use ($accion, $id, $usuario_id) {
        switch ($accion) {
            case 'eliminar_usuario':
                try {
                    $dependencias = $conn->query("SELECT SUM(counts) as total FROM (
                        SELECT COUNT(*) as counts FROM calificaciones WHERE alumno_id = $id UNION ALL
                        SELECT COUNT(*) FROM grupos WHERE docente_id = $id UNION ALL
                        SELECT COUNT(*) FROM avisos WHERE usuario_id = $id UNION ALL
                        SELECT COUNT(*) FROM eventos WHERE usuario_id = $id UNION ALL
                        SELECT COUNT(*) FROM reportes WHERE generado_por = $id
                    ) as deps")->fetch_assoc()['total'];
                    if ($dependencias > 0) {
                        throw new Exception("No se puede eliminar: usuario con $dependencias registros asociados.");
                    }
                    ejecutarConsulta($conn, "DELETE FROM alumnos WHERE usuario_id = ?", 'i', [$id]);
                    ejecutarConsulta($conn, "DELETE FROM docentes WHERE usuario_id = ?", 'i', [$id]);
                    ejecutarConsulta($conn, "DELETE FROM administrativos WHERE usuario_id = ?", 'i', [$id]);
                    ejecutarConsulta($conn, "DELETE FROM usuarios WHERE id = ?", 'i', [$id]);
                    $_SESSION['notificacion'] = "Usuario eliminado.";
                } catch (Exception $e) {
                    logError("Error eliminando usuario ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'eliminar_evento':
                try {
                    $evento = ejecutarConsulta($conn, "SELECT ruta_foto FROM eventos WHERE id = ?", 'i', [$id], true)->fetch_assoc();
                    if ($evento && file_exists($evento['ruta_foto'])) unlink($evento['ruta_foto']);
                    ejecutarConsulta($conn, "DELETE FROM eventos WHERE id = ?", 'i', [$id]);
                    $_SESSION['notificacion'] = "Evento eliminado.";
                } catch (Exception $e) {
                    logError("Error eliminando evento ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'eliminar_aviso':
                try {
                    ejecutarConsulta($conn, "DELETE FROM avisos WHERE id = ? AND usuario_id = ?", 'ii', [$id, $usuario_id]);
                    $_SESSION['notificacion'] = "Aviso eliminado.";
                } catch (Exception $e) {
                    logError("Error eliminando aviso ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'eliminar_reporte':
                try {
                    $reporte = ejecutarConsulta($conn, "SELECT ruta_archivo FROM reportes WHERE id = ? AND generado_por = ?", 'ii', [$id, $usuario_id], true)->fetch_assoc();
                    if ($reporte && file_exists($reporte['ruta_archivo'])) unlink($reporte['ruta_archivo']);
                    ejecutarConsulta($conn, "DELETE FROM reportes WHERE id = ? AND generado_por = ?", 'ii', [$id, $usuario_id]);
                    $_SESSION['notificacion'] = "Reporte eliminado.";
                } catch (Exception $e) {
                    logError("Error eliminando reporte ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'eliminar_semestre':
                try {
                    if (ejecutarConsulta($conn, "SELECT COUNT(*) FROM grupos WHERE semestre_id = ?", 'i', [$id], true)->fetch_row()[0] > 0) {
                        throw new Exception("No se puede eliminar: semestre con grupos asociados.");
                    }
                    ejecutarConsulta($conn, "DELETE FROM semestres WHERE id = ?", 'i', [$id]);
                    $_SESSION['notificacion'] = "Semestre eliminado.";
                } catch (Exception $e) {
                    logError("Error eliminando semestre ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'eliminar_materia':
                try {
                    if (ejecutarConsulta($conn, "SELECT COUNT(*) FROM grupos WHERE materia_id = ?", 'i', [$id], true)->fetch_row()[0] > 0) {
                        throw new Exception("No se puede eliminar: materia con grupos asociados.");
                    }
                    ejecutarConsulta($conn, "DELETE FROM materias WHERE id = ?", 'i', [$id]);
                    $_SESSION['notificacion'] = "Materia eliminada.";
                } catch (Exception $e) {
                    logError("Error eliminando materia ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'eliminar_grupo':
                try {
                    if (ejecutarConsulta($conn, "SELECT COUNT(*) FROM parciales WHERE grupo_id = ?", 'i', [$id], true)->fetch_row()[0] > 0) {
                        throw new Exception("No se puede eliminar: grupo con parciales asociados.");
                    }
                    ejecutarConsulta($conn, "DELETE FROM grupos WHERE id = ?", 'i', [$id]);
                    $_SESSION['notificacion'] = "Grupo eliminado.";
                } catch (Exception $e) {
                    logError("Error eliminando grupo ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'eliminar_parcial':
                try {
                    if (ejecutarConsulta($conn, "SELECT COUNT(*) FROM calificaciones WHERE parcial_id = ?", 'i', [$id], true)->fetch_row()[0] > 0) {
                        throw new Exception("No se puede eliminar: parcial con calificaciones asociadas.");
                    }
                    ejecutarConsulta($conn, "DELETE FROM parciales WHERE id = ?", 'i', [$id]);
                    $_SESSION['notificacion'] = "Parcial eliminado.";
                } catch (Exception $e) {
                    logError("Error eliminando parcial ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'eliminar_generacion':
                try {
                    if (ejecutarConsulta($conn, "SELECT COUNT(*) FROM semestres WHERE generacion_id = ?", 'i', [$id], true)->fetch_row()[0] > 0) {
                        throw new Exception("No se puede eliminar: generación con semestres asociados.");
                    }
                    ejecutarConsulta($conn, "DELETE FROM generaciones WHERE id = ?", 'i', [$id]);
                    $_SESSION['notificacion'] = "Generación eliminada.";
                } catch (Exception $e) {
                    logError("Error eliminando generación ID $id: " . $e->getMessage());
                    throw $e;
                }
                break;
        }
    }, function($e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: administrativo.php');
        exit();
    });
    header('Location: administrativo.php');
    exit();
}

// CRUD vía POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($csrf_token, $_POST['csrf_token'])) {
    ejecutarTransaccion($conexion, function($conn) use ($usuario_id) {
        // Crear Usuario
        if (isset($_POST['crear_usuario'])) {
            try {
                $nombreUsuario = sanitizar($_POST['nombre_usuario'] ?? '', $conn);
                $nombreCompleto = sanitizar($_POST['nombre_completo'] ?? '', $conn);
                $correo = sanitizar($_POST['correo'] ?? '', $conn);
                $tipo = sanitizar($_POST['tipo'] ?? '', $conn);
                $telefono = sanitizar($_POST['telefono'] ?? '', $conn);
                $contrasena = password_hash($_POST['contrasena'] ?? '', PASSWORD_DEFAULT);

                if (empty($nombreUsuario) || empty($nombreCompleto) || empty($correo) || empty($tipo) || empty($telefono) || empty($_POST['contrasena'])) {
                    throw new Exception("Todos los campos son obligatorios.");
                }
                if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido.");
                if (!preg_match('/^[0-9\-+() ]{7,20}$/', $telefono)) throw new Exception("Teléfono inválido (7-20 caracteres, números, +, -, (), espacios).");
                if (!in_array($tipo, ['alumno', 'docente', 'administrativo'])) throw new Exception("Tipo de usuario no válido.");
                if (ejecutarConsulta($conn, "SELECT 1 FROM usuarios WHERE nombre_usuario = ? OR correo = ?", 'ss', [$nombreUsuario, $correo], true)->num_rows > 0) {
                    throw new Exception("Nombre de usuario o correo ya en uso.");
                }

                ejecutarConsulta($conn, "INSERT INTO usuarios (nombre_usuario, nombre_completo, correo, tipo, telefono, contrasena) 
                                        VALUES (?, ?, ?, ?, ?, ?)", 'ssssss', [$nombreUsuario, $nombreCompleto, $correo, $tipo, $telefono, $contrasena]);
                $usuarioId = $conn->insert_id;

                switch ($tipo) {
                    case 'alumno':
                        $nia = sanitizar($_POST['nia'] ?? '', $conn);
                        $generacionId = (int)($_POST['generacion_id'] ?? 0);
                        if (empty($nia) || !preg_match('/^[A-Za-z0-9]{4}$/', $nia)) throw new Exception("NIA debe ser 4 caracteres alfanuméricos.");
                        if (!$generacionId || ejecutarConsulta($conn, "SELECT 1 FROM generaciones WHERE id = ?", 'i', [$generacionId], true)->num_rows == 0) {
                            throw new Exception("Generación inválida o no seleccionada.");
                        }
                        ejecutarConsulta($conn, "INSERT INTO alumnos (usuario_id, nia, generacion_id) VALUES (?, ?, ?)", 'isi', [$usuarioId, $nia, $generacionId]);
                        break;
                    case 'docente':
                        $especialidad = sanitizar($_POST['especialidad'] ?? '', $conn);
                        if (empty($especialidad)) throw new Exception("Especialidad obligatoria para docentes.");
                        ejecutarConsulta($conn, "INSERT INTO docentes (usuario_id, especialidad) VALUES (?, ?)", 'is', [$usuarioId, $especialidad]);
                        break;
                    case 'administrativo':
                        $departamento = sanitizar($_POST['departamento'] ?? '', $conn);
                        if (empty($departamento)) throw new Exception("Departamento obligatorio para administrativos.");
                        ejecutarConsulta($conn, "INSERT INTO administrativos (usuario_id, departamento) VALUES (?, ?)", 'is', [$usuarioId, $departamento]);
                        break;
                }

                $_SESSION['notificacion'] = "Usuario creado exitosamente.";
                header('Location: administrativo.php#usuarios');
                exit();
            } catch (Exception $e) {
                logError("Error al crear usuario: " . $e->getMessage());
                throw $e;
            }
        }

        // Editar Usuario
        if (isset($_POST['editar_usuario'])) {
            try {
                $usuarioId = (int)($_POST['usuario_id'] ?? 0);
                $nombreUsuario = sanitizar($_POST['nombre_usuario'] ?? '', $conn);
                $nombreCompleto = sanitizar($_POST['nombre_completo'] ?? '', $conn);
                $correo = sanitizar($_POST['correo'] ?? '', $conn);
                $tipo = sanitizar($_POST['tipo'] ?? '', $conn);
                $telefono = sanitizar($_POST['telefono'] ?? '', $conn);
                $contrasena = !empty($_POST['contrasena']) ? password_hash($_POST['contrasena'], PASSWORD_DEFAULT) : null;

                if (!$usuarioId || ejecutarConsulta($conn, "SELECT 1 FROM usuarios WHERE id = ?", 'i', [$usuarioId], true)->num_rows == 0) {
                    throw new Exception("Usuario no encontrado.");
                }
                if (empty($nombreUsuario) || empty($nombreCompleto) || empty($correo) || empty($tipo) || empty($telefono)) {
                    throw new Exception("Todos los campos son obligatorios.");
                }
                if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido.");
                if (!preg_match('/^[0-9\-+() ]{7,20}$/', $telefono)) throw new Exception("Teléfono inválido (7-20 caracteres, números, +, -, (), espacios).");
                if (!in_array($tipo, ['alumno', 'docente', 'administrativo'])) throw new Exception("Tipo de usuario no válido.");
                if (ejecutarConsulta($conn, "SELECT 1 FROM usuarios WHERE (nombre_usuario = ? OR correo = ?) AND id != ?", 'ssi', [$nombreUsuario, $correo, $usuarioId], true)->num_rows > 0) {
                    throw new Exception("Nombre de usuario o correo ya en uso.");
                }

                if ($contrasena) {
                    ejecutarConsulta($conn, "UPDATE usuarios SET nombre_usuario = ?, nombre_completo = ?, correo = ?, tipo = ?, telefono = ?, contrasena = ? WHERE id = ?",
                        'ssssssi', [$nombreUsuario, $nombreCompleto, $correo, $tipo, $telefono, $contrasena, $usuarioId]);
                } else {
                    ejecutarConsulta($conn, "UPDATE usuarios SET nombre_usuario = ?, nombre_completo = ?, correo = ?, tipo = ?, telefono = ? WHERE id = ?",
                        'sssssi', [$nombreUsuario, $nombreCompleto, $correo, $tipo, $telefono, $usuarioId]);
                }

                switch ($tipo) {
                    case 'alumno':
                        $nia = sanitizar($_POST['nia'] ?? '', $conn);
                        $generacionId = (int)($_POST['generacion_id'] ?? 0);
                        if (empty($nia) || !preg_match('/^[A-Za-z0-9]{4}$/', $nia)) throw new Exception("NIA debe ser 4 caracteres alfanuméricos.");
                        if (!$generacionId || ejecutarConsulta($conn, "SELECT 1 FROM generaciones WHERE id = ?", 'i', [$generacionId], true)->num_rows == 0) {
                            throw new Exception("Generación inválida o no seleccionada.");
                        }
                        ejecutarConsulta($conn, "INSERT INTO alumnos (usuario_id, nia, generacion_id) VALUES (?, ?, ?) 
                                                ON DUPLICATE KEY UPDATE nia = ?, generacion_id = ?",
                            'isisi', [$usuarioId, $nia, $generacionId, $nia, $generacionId]);
                        ejecutarConsulta($conn, "DELETE FROM docentes WHERE usuario_id = ?", 'i', [$usuarioId]);
                        ejecutarConsulta($conn, "DELETE FROM administrativos WHERE usuario_id = ?", 'i', [$usuarioId]);
                        break;
                    case 'docente':
                        $especialidad = sanitizar($_POST['especialidad'] ?? '', $conn);
                        if (empty($especialidad)) throw new Exception("Especialidad obligatoria para docentes.");
                        ejecutarConsulta($conn, "INSERT INTO docentes (usuario_id, especialidad) VALUES (?, ?) 
                                                ON DUPLICATE KEY UPDATE especialidad = ?",
                            'iss', [$usuarioId, $especialidad, $especialidad]);
                        ejecutarConsulta($conn, "DELETE FROM alumnos WHERE usuario_id = ?", 'i', [$usuarioId]);
                        ejecutarConsulta($conn, "DELETE FROM administrativos WHERE usuario_id = ?", 'i', [$usuarioId]);
                        break;
                    case 'administrativo':
                        $departamento = sanitizar($_POST['departamento'] ?? '', $conn);
                        if (empty($departamento)) throw new Exception("Departamento obligatorio para administrativos.");
                        ejecutarConsulta($conn, "INSERT INTO administrativos (usuario_id, departamento) VALUES (?, ?) 
                                                ON DUPLICATE KEY UPDATE departamento = ?",
                            'iss', [$usuarioId, $departamento, $departamento]);
                        ejecutarConsulta($conn, "DELETE FROM alumnos WHERE usuario_id = ?", 'i', [$usuarioId]);
                        ejecutarConsulta($conn, "DELETE FROM docentes WHERE usuario_id = ?", 'i', [$usuarioId]);
                        break;
                }

                $_SESSION['notificacion'] = "Usuario actualizado exitosamente.";
                header('Location: administrativo.php#usuarios');
                exit();
            } catch (Exception $e) {
                logError("Error al actualizar usuario ID $usuarioId: " . $e->getMessage());
                throw $e;
            }
        }

        // Crear Evento
        if (isset($_POST['crear_evento'])) {
            try {
                $titulo = sanitizar($_POST['titulo'], $conn);
                $descripcion = sanitizar($_POST['descripcion'], $conn, true);
                $fecha = sanitizar($_POST['fecha'], $conn);
                $tipoEvento = sanitizar($_POST['tipo_evento'], $conn);

                if (empty($titulo) || empty($descripcion) || empty($fecha) || empty($tipoEvento)) {
                    throw new Exception("Todos los campos son obligatorios.");
                }
                $rutaFoto = null;
                if (!empty($_FILES['imagen']['name'])) {
                    $rutaFoto = subirArchivo($_FILES['imagen'], UPLOAD_DIR, ALLOWED_TYPES, MAX_FILE_SIZE);
                }
                ejecutarConsulta($conn, "INSERT INTO eventos (titulo, fecha, descripcion, ruta_foto, tipo_evento, usuario_id) 
                                        VALUES (?, ?, ?, ?, ?, ?)", 'sssssi', [$titulo, $fecha, $descripcion, $rutaFoto, $tipoEvento, $usuario_id]);
                $_SESSION['notificacion'] = "Evento creado.";
                header('Location: administrativo.php#eventos');
                exit();
            } catch (Exception $e) {
                logError("Error al crear evento: " . $e->getMessage());
                throw $e;
            }
        }

        // Editar Evento
        if (isset($_POST['editar_evento'])) {
            try {
                $id = (int)$_POST['id'];
                $titulo = sanitizar($_POST['titulo'], $conn);
                $descripcion = sanitizar($_POST['descripcion'], $conn, true);
                $fecha = sanitizar($_POST['fecha'], $conn);
                $tipoEvento = sanitizar($_POST['tipo_evento'], $conn);

                if (empty($titulo) || empty($descripcion) || empty($fecha) || empty($tipoEvento)) {
                    throw new Exception("Todos los campos son obligatorios.");
                }
                if (!empty($_FILES['imagen']['name']) && $_FILES['imagen']['size'] > 0) {
                    $rutaFoto = subirArchivo($_FILES['imagen'], UPLOAD_DIR, ALLOWED_TYPES, MAX_FILE_SIZE);
                    $oldImage = ejecutarConsulta($conn, "SELECT ruta_foto FROM eventos WHERE id = ?", 'i', [$id], true)->fetch_assoc()['ruta_foto'];
                    if ($oldImage && file_exists($oldImage)) unlink($oldImage);
                    ejecutarConsulta($conn, "UPDATE eventos SET titulo = ?, fecha = ?, descripcion = ?, ruta_foto = ?, tipo_evento = ? WHERE id = ?",
                        'sssssi', [$titulo, $fecha, $descripcion, $rutaFoto, $tipoEvento, $id]);
                } else {
                    ejecutarConsulta($conn, "UPDATE eventos SET titulo = ?, fecha = ?, descripcion = ?, tipo_evento = ? WHERE id = ?",
                        'ssssi', [$titulo, $fecha, $descripcion, $tipoEvento, $id]);
                }
                $_SESSION['notificacion'] = "Evento actualizado.";
                header('Location: administrativo.php#eventos');
                exit();
            } catch (Exception $e) {
                logError("Error al editar evento ID $id: " . $e->getMessage());
                throw $e;
            }
        }

        // Crear Aviso
        if (isset($_POST['crear_aviso'])) {
            try {
                $titulo = sanitizar($_POST['titulo'], $conn);
                $contenido = sanitizar($_POST['contenido'], $conn, true);
                $prioridadNum = (int)$_POST['prioridad'];
                $prioridadMap = [1 => 'baja', 2 => 'media', 3 => 'alta'];
                $prioridad = $prioridadMap[$prioridadNum] ?? 'media';

                if (empty($titulo) || empty($contenido) || !array_key_exists($prioridadNum, $prioridadMap)) {
                    throw new Exception("Campos obligatorios o prioridad inválida (1-3).");
                }
                ejecutarConsulta($conn, "INSERT INTO avisos (titulo, contenido, prioridad, usuario_id) VALUES (?, ?, ?, ?)",
                    'sssi', [$titulo, $contenido, $prioridad, $usuario_id]);
                $_SESSION['notificacion'] = "Aviso creado.";
                header('Location: administrativo.php#avisos');
                exit();
            } catch (Exception $e) {
                logError("Error al crear aviso: " . $e->getMessage());
                throw $e;
            }
        }

        // Editar Aviso
        if (isset($_POST['editar_aviso'])) {
            try {
                $id = (int)$_POST['aviso_id'];
                $titulo = sanitizar($_POST['titulo'], $conn);
                $contenido = sanitizar($_POST['contenido'], $conn, true);
                $prioridadNum = (int)$_POST['prioridad'];
                $prioridadMap = [1 => 'baja', 2 => 'media', 3 => 'alta'];
                $prioridad = $prioridadMap[$prioridadNum] ?? 'media';

                if (empty($titulo) || empty($contenido) || !array_key_exists($prioridadNum, $prioridadMap)) {
                    throw new Exception("Campos obligatorios o prioridad inválida (1-3).");
                }
                ejecutarConsulta($conn, "UPDATE avisos SET titulo = ?, contenido = ?, prioridad = ? WHERE id = ? AND usuario_id = ?",
                    'sssii', [$titulo, $contenido, $prioridad, $id, $usuario_id]);
                $_SESSION['notificacion'] = "Aviso actualizado.";
                header('Location: administrativo.php#avisos');
                exit();
            } catch (Exception $e) {
                logError("Error al editar aviso ID $id: " . $e->getMessage());
                throw $e;
            }
        }

        // Registrar/Actualizar Calificación
        if (isset($_POST['registrar_calificacion'])) {
            try {
                $alumnoId = (int)$_POST['alumno_id'];
                $parcialId = (int)$_POST['parcial_id'];
                $calificacion = (float)$_POST['calificacion'];
                $penalizacion = (float)$_POST['asistencia_penalizacion'];

                if ($calificacion < 0 || $calificacion > 99.99 || $penalizacion < 0) {
                    throw new Exception("Calificación entre 0 y 99.99, penalización no negativa.");
                }
                $total = max(0, $calificacion - $penalizacion);
                ejecutarConsulta($conn, "INSERT INTO calificaciones (alumno_id, parcial_id, calificacion, asistencia_penalizacion, total) 
                                        VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE calificacion = ?, asistencia_penalizacion = ?, total = ?",
                    'iidddddd', [$alumnoId, $parcialId, $calificacion, $penalizacion, $total, $calificacion, $penalizacion, $total]);
                $_SESSION['notificacion'] = "Calificación registrada.";
                header('Location: administrativo.php#evaluacion');
                exit();
            } catch (Exception $e) {
                logError("Error al registrar calificación para alumno ID $alumnoId, parcial ID $parcialId: " . $e->getMessage());
                throw $e;
            }
        }

        // Eliminar Calificación
        if (isset($_POST['eliminar_calificacion'])) {
            try {
                $alumnoId = (int)$_POST['alumno_id'];
                $parcialId = (int)$_POST['parcial_id'];
                $permiso = ejecutarConsulta($conn, "SELECT COUNT(*) FROM parciales p JOIN grupos g ON p.grupo_id = g.id WHERE p.id = ?", 
                    'i', [$parcialId], true)->fetch_row()[0];
                if ($permiso == 0) throw new Exception("Parcial no encontrado.");
                ejecutarConsulta($conn, "DELETE FROM calificaciones WHERE alumno_id = ? AND parcial_id = ?", 'ii', [$alumnoId, $parcialId]);
                $_SESSION['notificacion'] = "Calificación eliminada.";
                header('Location: administrativo.php#evaluacion');
                exit();
            } catch (Exception $e) {
                logError("Error al eliminar calificación para alumno ID $alumnoId, parcial ID $parcialId: " . $e->getMessage());
                throw $e;
            }
        }

        // Crear Semestre
        if (isset($_POST['crear_semestre'])) {
            try {
                $generacionId = (int)$_POST['generacion_id'];
                $fechaInicio = sanitizar($_POST['fecha_inicio'], $conn);
                $fechaFin = sanitizar($_POST['fecha_fin'], $conn);

                if (empty($fechaInicio) || empty($fechaFin)) throw new Exception("Fechas obligatorias.");
                if (strtotime($fechaInicio) >= strtotime($fechaFin)) throw new Exception("Fecha inicio debe ser anterior a fin.");
                if (ejecutarConsulta($conn, "SELECT 1 FROM generaciones WHERE id = ?", 'i', [$generacionId], true)->num_rows == 0) {
                    throw new Exception("Generación no existe.");
                }
                $numeroSemestre = ejecutarConsulta($conn, "SELECT COALESCE(MAX(numero), 0) + 1 FROM semestres WHERE generacion_id = ?", 'i', [$generacionId], true)->fetch_row()[0];
                ejecutarConsulta($conn, "INSERT INTO semestres (generacion_id, numero, fecha_inicio, fecha_fin) VALUES (?, ?, ?, ?)",
                    'iiss', [$generacionId, $numeroSemestre, $fechaInicio, $fechaFin]);
                $_SESSION['notificacion'] = "Semestre creado.";
                header('Location: administrativo.php#academico');
                exit();
            } catch (Exception $e) {
                logError("Error al crear semestre: " . $e->getMessage());
                throw $e;
            }
        }

        // Editar Semestre
        if (isset($_POST['editar_semestre'])) {
            try {
                $semestreId = (int)$_POST['semestre_id'];
                $fechaInicio = sanitizar($_POST['fecha_inicio'], $conn);
                $fechaFin = sanitizar($_POST['fecha_fin'], $conn);

                if (empty($fechaInicio) || empty($fechaFin)) throw new Exception("Fechas obligatorias.");
                if (strtotime($fechaInicio) >= strtotime($fechaFin)) throw new Exception("Fecha inicio debe ser anterior a fin.");
                ejecutarConsulta($conn, "UPDATE semestres SET fecha_inicio = ?, fecha_fin = ? WHERE id = ?", 'ssi', [$fechaInicio, $fechaFin, $semestreId]);
                $_SESSION['notificacion'] = "Semestre actualizado.";
                header('Location: administrativo.php#academico');
                exit();
            } catch (Exception $e) {
                logError("Error al editar semestre ID $semestreId: " . $e->getMessage());
                throw $e;
            }
        }

        // Crear Materia
        if (isset($_POST['crear_materia'])) {
            try {
                $nombre = sanitizar($_POST['nombre_materia'], $conn);
                $descripcion = sanitizar($_POST['descripcion'], $conn);

                if (empty($nombre)) throw new Exception("Nombre obligatorio.");
                if (ejecutarConsulta($conn, "SELECT 1 FROM materias WHERE nombre = ?", 's', [$nombre], true)->num_rows > 0) {
                    throw new Exception("Materia ya existe.");
                }
                ejecutarConsulta($conn, "INSERT INTO materias (nombre, descripcion) VALUES (?, ?)", 'ss', [$nombre, $descripcion]);
                $_SESSION['notificacion'] = "Materia creada.";
                header('Location: administrativo.php#academico');
                exit();
            } catch (Exception $e) {
                logError("Error al crear materia: " . $e->getMessage());
                throw $e;
            }
        }

        // Editar Materia
        if (isset($_POST['editar_materia'])) {
            try {
                $materiaId = (int)$_POST['materia_id'];
                $nombre = sanitizar($_POST['nombre_materia'], $conn);
                $descripcion = sanitizar($_POST['descripcion'], $conn);

                if (empty($nombre)) throw new Exception("Nombre obligatorio.");
                if (ejecutarConsulta($conn, "SELECT 1 FROM materias WHERE nombre = ? AND id != ?", 'si', [$nombre, $materiaId], true)->num_rows > 0) {
                    throw new Exception("Otra materia ya tiene ese nombre.");
                }
                ejecutarConsulta($conn, "UPDATE materias SET nombre = ?, descripcion = ? WHERE id = ?", 'ssi', [$nombre, $descripcion, $materiaId]);
                $_SESSION['notificacion'] = "Materia actualizada.";
                header('Location: administrativo.php#academico');
                exit();
            } catch (Exception $e) {
                logError("Error al editar materia ID $materiaId: " . $e->getMessage());
                throw $e;
            }
        }

        // Crear Grupo
        if (isset($_POST['crear_grupo'])) {
            try {
                $materiaId = (int)$_POST['materia_id'];
                $semestreId = (int)$_POST['semestre_id'];
                $letraGrupo = strtoupper(sanitizar($_POST['letra_grupo'], $conn));
                $grado = (int)$_POST['grado'];
                $docenteId = (int)$_POST['docente_id'];

                if (ejecutarConsulta($conn, "SELECT 1 FROM materias WHERE id = ?", 'i', [$materiaId], true)->num_rows == 0) {
                    throw new Exception("Materia no existe.");
                }
                if (ejecutarConsulta($conn, "SELECT 1 FROM semestres WHERE id = ?", 'i', [$semestreId], true)->num_rows == 0) {
                    throw new Exception("Semestre no existe.");
                }
                if (ejecutarConsulta($conn, "SELECT 1 FROM docentes WHERE usuario_id = ?", 'i', [$docenteId], true)->num_rows == 0) {
                    throw new Exception("Docente no existe.");
                }
                if (!in_array($letraGrupo, ['A', 'B'])) throw new Exception("Letra de grupo inválida (solo A o B).");
                if (ejecutarConsulta($conn, "SELECT 1 FROM grupos WHERE materia_id = ? AND semestre_id = ? AND letra_grupo = ?", 'iis', [$materiaId, $semestreId, $letraGrupo], true)->num_rows > 0) {
                    throw new Exception("Grupo ya existe.");
                }
                ejecutarConsulta($conn, "INSERT INTO grupos (materia_id, semestre_id, letra_grupo, grado, docente_id) VALUES (?, ?, ?, ?, ?)",
                    'iisii', [$materiaId, $semestreId, $letraGrupo, $grado, $docenteId]);
                $_SESSION['notificacion'] = "Grupo creado.";
                header('Location: administrativo.php#academico');
                exit();
            } catch (Exception $e) {
                logError("Error al crear grupo: " . $e->getMessage());
                throw $e;
            }
        }

        // Editar Grupo
        if (isset($_POST['editar_grupo'])) {
            try {
                $grupoId = (int)$_POST['grupo_id'];
                $materiaId = (int)$_POST['materia_id'];
                $semestreId = (int)$_POST['semestre_id'];
                $letraGrupo = strtoupper(sanitizar($_POST['letra_grupo'], $conn));
                $grado = (int)$_POST['grado'];
                $docenteId = (int)$_POST['docente_id'];

                if (ejecutarConsulta($conn, "SELECT 1 FROM materias WHERE id = ?", 'i', [$materiaId], true)->num_rows == 0) {
                    throw new Exception("Materia no existe.");
                }
                if (ejecutarConsulta($conn, "SELECT 1 FROM semestres WHERE id = ?", 'i', [$semestreId], true)->num_rows == 0) {
                    throw new Exception("Semestre no existe.");
                }
                if (ejecutarConsulta($conn, "SELECT 1 FROM docentes WHERE usuario_id = ?", 'i', [$docenteId], true)->num_rows == 0) {
                    throw new Exception("Docente no existe.");
                }
                if (!in_array($letraGrupo, ['A', 'B'])) throw new Exception("Letra de grupo inválida (solo A o B).");
                if (ejecutarConsulta($conn, "SELECT 1 FROM grupos WHERE materia_id = ? AND semestre_id = ? AND letra_grupo = ? AND id != ?", 'iisi', [$materiaId, $semestreId, $letraGrupo, $grupoId], true)->num_rows > 0) {
                    throw new Exception("Otro grupo ya existe con esos datos.");
                }
                ejecutarConsulta($conn, "UPDATE grupos SET materia_id = ?, semestre_id = ?, letra_grupo = ?, grado = ?, docente_id = ? WHERE id = ?",
                    'iisiii', [$materiaId, $semestreId, $letraGrupo, $grado, $docenteId, $grupoId]);
                $_SESSION['notificacion'] = "Grupo actualizado.";
                header('Location: administrativo.php#academico');
                exit();
            } catch (Exception $e) {
                logError("Error al editar grupo ID $grupoId: " . $e->getMessage());
                throw $e;
            }
        }

        // Crear Parcial
        if (isset($_POST['crear_parcial'])) {
            try {
                $grupoId = (int)$_POST['grupo_id'];
                $numeroParcial = (int)$_POST['numero_parcial'];
                $fechaInicio = sanitizar($_POST['fecha_inicio'], $conn);
                $fechaFin = sanitizar($_POST['fecha_fin'], $conn);

                if (ejecutarConsulta($conn, "SELECT 1 FROM grupos WHERE id = ?", 'i', [$grupoId], true)->num_rows == 0) {
                    throw new Exception("Grupo no existe.");
                }
                if ($numeroParcial < 1 || $numeroParcial > 3) throw new Exception("Número de parcial debe ser 1-3.");
                if (strtotime($fechaInicio) >= strtotime($fechaFin)) throw new Exception("Fecha inicio debe ser anterior a fin.");
                if (ejecutarConsulta($conn, "SELECT COUNT(*) FROM parciales WHERE grupo_id = ?", 'i', [$grupoId], true)->fetch_row()[0] >= 3) {
                    throw new Exception("Máximo 3 parciales por grupo.");
                }
                if (ejecutarConsulta($conn, "SELECT 1 FROM parciales WHERE grupo_id = ? AND numero_parcial = ?", 'ii', [$grupoId, $numeroParcial], true)->num_rows > 0) {
                    throw new Exception("Parcial ya existe para este grupo.");
                }
                ejecutarConsulta($conn, "INSERT INTO parciales (grupo_id, numero_parcial, fecha_inicio, fecha_fin) VALUES (?, ?, ?, ?)",
                    'iiss', [$grupoId, $numeroParcial, $fechaInicio, $fechaFin]);
                $_SESSION['notificacion'] = "Parcial creado.";
                header('Location: administrativo.php#evaluacion');
                exit();
            } catch (Exception $e) {
                logError("Error al crear parcial: " . $e->getMessage());
                throw $e;
            }
        }

        // Editar Parcial
        if (isset($_POST['editar_parcial'])) {
            try {
                $parcialId = (int)$_POST['parcial_id'];
                $grupoId = (int)$_POST['grupo_id'];
                $numeroParcial = (int)$_POST['numero_parcial'];
                $fechaInicio = sanitizar($_POST['fecha_inicio'], $conn);
                $fechaFin = sanitizar($_POST['fecha_fin'], $conn);

                if (ejecutarConsulta($conn, "SELECT 1 FROM grupos WHERE id = ?", 'i', [$grupoId], true)->num_rows == 0) {
                    throw new Exception("Grupo no existe.");
                }
                if ($numeroParcial < 1 || $numeroParcial > 3) throw new Exception("Número de parcial debe ser 1-3.");
                if (strtotime($fechaInicio) >= strtotime($fechaFin)) throw new Exception("Fecha inicio debe ser anterior a fin.");
                if (ejecutarConsulta($conn, "SELECT 1 FROM parciales WHERE grupo_id = ? AND numero_parcial = ? AND id != ?", 'iii', [$grupoId, $numeroParcial, $parcialId], true)->num_rows > 0) {
                    throw new Exception("Otro parcial ya existe con ese número.");
                }
                ejecutarConsulta($conn, "UPDATE parciales SET grupo_id = ?, numero_parcial = ?, fecha_inicio = ?, fecha_fin = ? WHERE id = ?",
                    'iissi', [$grupoId, $numeroParcial, $fechaInicio, $fechaFin, $parcialId]);
                $_SESSION['notificacion'] = "Parcial actualizado.";
                header('Location: administrativo.php#evaluacion');
                exit();
            } catch (Exception $e) {
                logError("Error al editar parcial ID $parcialId: " . $e->getMessage());
                throw $e;
            }
        }

        // Crear Generación
        if (isset($_POST['crear_generacion'])) {
            try {
                $nombre = sanitizar($_POST['nombre'], $conn);
                $fechaInicio = sanitizar($_POST['fecha_inicio'], $conn);
                $fechaFin = sanitizar($_POST['fecha_fin'], $conn);

                if (empty($nombre) || empty($fechaInicio) || empty($fechaFin)) throw new Exception("Todos los campos son obligatorios.");
                if (strtotime($fechaInicio) >= strtotime($fechaFin)) throw new Exception("Fecha inicio debe ser anterior a fin.");
                if (ejecutarConsulta($conn, "SELECT 1 FROM generaciones WHERE nombre = ?", 's', [$nombre], true)->num_rows > 0) {
                    throw new Exception("Generación ya existe.");
                }
                ejecutarConsulta($conn, "INSERT INTO generaciones (nombre, fecha_inicio, fecha_fin) VALUES (?, ?, ?)",
                    'sss', [$nombre, $fechaInicio, $fechaFin]);
                $_SESSION['notificacion'] = "Generación creada.";
                header('Location: administrativo.php#academico');
                exit();
            } catch (Exception $e) {
                logError("Error al crear generación: " . $e->getMessage());
                throw $e;
            }
        }

        // Editar Generación
        if (isset($_POST['editar_generacion'])) {
            try {
                $generacionId = (int)$_POST['generacion_id'];
                $nombre = sanitizar($_POST['nombre'], $conn);
                $fechaInicio = sanitizar($_POST['fecha_inicio'], $conn);
                $fechaFin = sanitizar($_POST['fecha_fin'], $conn);

                if (empty($nombre) || empty($fechaInicio) || empty($fechaFin)) throw new Exception("Todos los campos son obligatorios.");
                if (strtotime($fechaInicio) >= strtotime($fechaFin)) throw new Exception("Fecha inicio debe ser anterior a fin.");
                if (ejecutarConsulta($conn, "SELECT 1 FROM generaciones WHERE nombre = ? AND id != ?", 'si', [$nombre, $generacionId], true)->num_rows > 0) {
                    throw new Exception("Otra generación ya tiene ese nombre.");
                }
                ejecutarConsulta($conn, "UPDATE generaciones SET nombre = ?, fecha_inicio = ?, fecha_fin = ? WHERE id = ?",
                    'sssi', [$nombre, $fechaInicio, $fechaFin, $generacionId]);
                $_SESSION['notificacion'] = "Generación actualizada.";
                header('Location: administrativo.php#academico');
                exit();
            } catch (Exception $e) {
                logError("Error al editar generación ID $generacionId: " . $e->getMessage());
                throw $e;
            }
        }

        // Generar Reporte
        if (isset($_POST['generar_reporte'])) {
            try {
                $tipoReporte = sanitizar($_POST['tipo_reporte'], $conn);
                $generacionId = (int)$_POST['generacion_id'];
                $semestreId = (int)$_POST['semestre_id'];

                if (!is_dir(REPORTS_DIR) && !mkdir(REPORTS_DIR, 0755, true)) {
                    throw new Exception("No se pudo crear directorio de reportes.");
                }

                $reportes = [
                    'calificaciones' => [
                        'sql' => "SELECT u.nombre_completo AS alumno, a.nia, m.nombre AS materia, g.letra_grupo AS grupo,
                                        p.numero_parcial, c.calificacion, c.asistencia_penalizacion, c.total,
                                        CASE WHEN SUM(c.total) >= 18 THEN 'Aprobado' ELSE 'Reprobado' END AS estado_materia
                                  FROM calificaciones c
                                  JOIN usuarios u ON c.alumno_id = u.id
                                  JOIN alumnos a ON u.id = a.usuario_id
                                  JOIN parciales p ON c.parcial_id = p.id
                                  JOIN grupos g ON p.grupo_id = g.id
                                  JOIN materias m ON g.materia_id = m.id
                                  JOIN semestres s ON g.semestre_id = s.id
                                  WHERE a.generacion_id = ? AND g.semestre_id = ?
                                  GROUP BY c.alumno_id, m.id, p.id",
                        'tipos' => 'ii',
                        'params' => [$generacionId, $semestreId],
                        'titulo' => 'Reporte de Calificaciones',
                        'headers' => ['Alumno', 'NIA', 'Materia', 'Grupo', 'Parcial', 'Calificación', 'Penalización', 'Total', 'Estado']
                    ],
                    'asistencias' => [
                        'sql' => "SELECT u.nombre_completo AS alumno, a.nia, m.nombre AS materia, g.letra_grupo AS grupo, ast.fecha, ast.tipo
                                  FROM asistencias ast 
                                  JOIN usuarios u ON ast.alumno_id = u.id 
                                  JOIN alumnos a ON u.id = a.usuario_id
                                  JOIN grupos g ON ast.grupo_id = g.id 
                                  JOIN materias m ON g.materia_id = m.id
                                  JOIN semestres s ON g.semestre_id = s.id
                                  WHERE a.generacion_id = ? AND g.semestre_id = ?
                                  ORDER BY u.nombre_completo, m.nombre, ast.fecha",
                        'tipos' => 'ii',
                        'params' => [$generacionId, $semestreId],
                        'titulo' => 'Reporte de Asistencias',
                        'headers' => ['Alumno', 'NIA', 'Materia', 'Grupo', 'Fecha', 'Estado']
                    ],
                    'progreso' => [
                        'sql' => "SELECT u.nombre_completo AS alumno, a.nia, m.nombre AS materia, 
                                        AVG(c.total) AS promedio_materia,
                                        (SELECT AVG(c2.total) FROM calificaciones c2 
                                         JOIN parciales p2 ON c2.parcial_id = p2.id 
                                         JOIN grupos g2 ON p2.grupo_id = g2.id
                                         WHERE c2.alumno_id = u.id AND g2.semestre_id = ?) AS promedio_general,
                                        (SELECT COUNT(*) FROM asistencias ast WHERE ast.alumno_id = u.id AND ast.grupo_id = g.id AND ast.tipo = 'presente') AS asistencias,
                                        (SELECT COUNT(*) FROM asistencias ast WHERE ast.alumno_id = u.id AND ast.grupo_id = g.id AND ast.tipo = 'falta') AS faltas,
                                        (SELECT COUNT(*) FROM asistencias ast WHERE ast.alumno_id = u.id AND ast.grupo_id = g.id AND ast.tipo = 'falta_justificada') AS retardos,
                                        (SELECT IF(SUM(c2.total) >= 18, 'Aprobado', 'Reprobado') 
                                         FROM calificaciones c2 
                                         JOIN parciales p2 ON c2.parcial_id = p2.id 
                                         JOIN grupos g2 ON p2.grupo_id = g2.id 
                                         WHERE c2.alumno_id = c.alumno_id AND g2.materia_id = g.materia_id) AS estado_materia
                                  FROM usuarios u 
                                  JOIN alumnos a ON u.id = a.usuario_id 
                                  JOIN calificaciones c ON u.id = c.alumno_id
                                  JOIN parciales p ON c.parcial_id = p.id 
                                  JOIN grupos g ON p.grupo_id = g.id 
                                  JOIN materias m ON g.materia_id = m.id
                                  LEFT JOIN asistencias ast ON u.id = ast.alumno_id AND g.id = ast.grupo_id
                                  JOIN semestres s ON g.semestre_id = s.id
                                  WHERE a.generacion_id = ? AND g.semestre_id = ?
                                  GROUP BY u.id, m.id 
                                  ORDER BY u.nombre_completo, m.nombre",
                        'tipos' => 'iii',
                        'params' => [$semestreId, $generacionId, $semestreId],
                        'titulo' => 'Reporte de Progreso',
                        'headers' => ['Alumno', 'NIA', 'Materia', 'Promedio Materia', 'Promedio General', 'Asistencias', 'Faltas', 'Retardos', 'Estado']
                    ]
                ];

                if (!isset($reportes[$tipoReporte])) throw new Exception("Tipo de reporte inválido.");
                $reporte = $reportes[$tipoReporte];
                $datos = ejecutarConsulta($conn, $reporte['sql'], $reporte['tipos'], $reporte['params'], true)->fetch_all(MYSQLI_ASSOC);
                if (empty($datos)) throw new Exception("No hay datos para este reporte.");

                $pdf = new FPDF();
                $pdf->AddPage();
                $pdf->SetFont('Arial', 'B', 12);
                $pdf->Cell(0, 10, $reporte['titulo'], 0, 1, 'C');
                $pdf->Ln(5);
                $colWidth = 190 / count($reporte['headers']);
                $pdf->SetFont('Arial', 'B', 10);
                foreach ($reporte['headers'] as $header) {
                    $pdf->Cell($colWidth, 8, $header, 1);
                }
                $pdf->Ln();
                $pdf->SetFont('Arial', '', 9);
                foreach ($datos as $row) {
                    foreach ($row as $value) {
                        $pdf->Cell($colWidth, 8, $value ?? 'N/A', 1);
                    }
                    $pdf->Ln();
                }

                $filename = REPORTS_DIR . "reporte_{$tipoReporte}_" . date('Ymd_His') . ".pdf";
                $pdf->Output('F', $filename);
                ejecutarConsulta($conn, "INSERT INTO reportes (tipo_reporte, ruta_archivo, generacion_id, semestre_id, generado_por) 
                                        VALUES (?, ?, ?, ?, ?)", 'ssiii', [$tipoReporte, $filename, $generacionId, $semestreId, $usuario_id]);
                $_SESSION['notificacion'] = "Reporte generado.";
                header('Location: administrativo.php#reportes');
                exit();
            } catch (Exception $e) {
                logError("Error al generar reporte tipo $tipoReporte: " . $e->getMessage());
                throw $e;
            }
        }
    }, function($e) use (&$conexion) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: administrativo.php');
        exit();
    });
}

// Mostrar errores o notificaciones
if (isset($_SESSION['error'])) {
    echo "<script>alert('" . addslashes($_SESSION['error']) . "');</script>";
    unset($_SESSION['error']);
}
if (isset($_SESSION['notificacion'])) {
    echo "<script>alert('" . addslashes($_SESSION['notificacion']) . "');</script>";
    unset($_SESSION['notificacion']);
}

// Preparar datos para frontend
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$filtros = [
    'nombre' => sanitizar($_GET['filtro_nombre'] ?? '', $conexion),
    'nia' => sanitizar($_GET['filtro_nia'] ?? '', $conexion),
    'tipo' => sanitizar($_GET['filtro_tipo'] ?? '', $conexion)
];

// Usuarios con filtros y paginación
$usuarios = obtenerDatos($conexion, "SELECT u.id, u.nombre_usuario, u.nombre_completo, u.correo, u.tipo, u.telefono,
                                    a.nia AS alumno_nia, d.especialidad AS docente_especialidad,
                                    ad.departamento AS administrativo_departamento
                                    FROM usuarios u
                                    LEFT JOIN alumnos a ON u.id = a.usuario_id
                                    LEFT JOIN docentes d ON u.id = d.usuario_id
                                    LEFT JOIN administrativos ad ON u.id = ad.usuario_id
                                    WHERE 1=1" .
                                    (!empty($filtros['tipo']) ? " AND u.tipo = ?" : "") .
                                    (!empty($filtros['nombre']) ? " AND (u.nombre_completo LIKE ? OR u.nombre_usuario LIKE ?)" : "") .
                                    (!empty($filtros['nia']) ? " AND a.nia LIKE ?" : ""),
                                    (!empty($filtros['tipo']) ? 's' : '') . (!empty($filtros['nombre']) ? 'ss' : '') . (!empty($filtros['nia']) ? 's' : ''),
                                    array_filter([$filtros['tipo'], $filtros['nombre'] ? "%{$filtros['nombre']}%" : null, $filtros['nombre'] ? "%{$filtros['nombre']}%" : null, $filtros['nia'] ? "%{$filtros['nia']}%" : null]),
                                    $page, $perPage);

// Eventos con paginación
$eventos = obtenerDatos($conexion, "SELECT id, titulo, fecha, descripcion, ruta_foto, tipo_evento FROM eventos ORDER BY fecha DESC", '', [], $page, $perPage);
// Avisos: Conteo total sin filtro de usuario_id ni paginación para el dashboard
$totalAvisosResult = ejecutarConsulta($conexion, "SELECT COUNT(*) as total FROM avisos", '', [], true);
$totalAvisos = $totalAvisosResult->fetch_assoc()['total'];
// Calificaciones con paginación
$calificaciones = obtenerDatos($conexion, "SELECT c.alumno_id, c.parcial_id, c.calificacion, c.asistencia_penalizacion, c.total, 
                                          u.nombre_completo AS alumno_nombre, a.nia, p.numero_parcial, m.nombre AS materia_nombre, g.letra_grupo 
                                          FROM calificaciones c 
                                          JOIN usuarios u ON c.alumno_id = u.id 
                                          JOIN alumnos a ON u.id = a.usuario_id 
                                          JOIN parciales p ON c.parcial_id = p.id 
                                          JOIN grupos g ON p.grupo_id = g.id 
                                          JOIN materias m ON g.materia_id = m.id 
                                          ORDER BY u.nombre_completo, m.nombre, p.numero_parcial", '', [], $page, $perPage);

// Semestres: Conteo total de semestres en curso sin paginación para el dashboard
$semestresTotalResult = ejecutarConsulta($conexion, 
    "SELECT COUNT(*) as total 
     FROM semestres s 
     JOIN generaciones g ON s.generacion_id = g.id 
     WHERE s.fecha_inicio <= CURDATE() AND s.fecha_fin >= CURDATE()", 
    '', [], true);
$semestresEnCurso = $semestresTotalResult->fetch_assoc()['total'];

// Semestres con paginación para otras secciones
$semestres = obtenerDatos($conexion, "SELECT s.id, s.numero, CONCAT('Semestre ', s.numero, ' - ', g.nombre) AS nombre_semestre, g.nombre AS generacion, 
                                      DATE_FORMAT(s.fecha_inicio, '%Y-%m-%d') AS fecha_inicio, DATE_FORMAT(s.fecha_fin, '%Y-%m-%d') AS fecha_fin,
                                      CASE WHEN s.fecha_fin < CURDATE() THEN 'Finalizado' WHEN s.fecha_inicio > CURDATE() THEN 'Pendiente' ELSE 'En Curso' END AS estado 
                                      FROM semestres s 
                                      JOIN generaciones g ON s.generacion_id = g.id 
                                      ORDER BY s.fecha_inicio DESC", '', [], $page, $perPage);
$materias = obtenerDatos($conexion, "SELECT id, nombre, descripcion FROM materias ORDER BY nombre", '', [], $page, $perPage);
$grupos = obtenerDatos($conexion, "SELECT g.id, m.nombre AS materia, s.numero AS semestre, g.letra_grupo, g.grado, u.nombre_completo AS docente 
                                  FROM grupos g 
                                  JOIN materias m ON g.materia_id = m.id 
                                  JOIN semestres s ON g.semestre_id = s.id 
                                  LEFT JOIN usuarios u ON g.docente_id = u.id 
                                  ORDER BY s.numero, m.nombre, g.letra_grupo", '', [], $page, $perPage);
$parciales = obtenerDatos($conexion, "SELECT p.id, p.numero_parcial, g.letra_grupo, m.nombre AS materia, s.numero AS semestre 
                                      FROM parciales p 
                                      JOIN grupos g ON p.grupo_id = g.id 
                                      JOIN materias m ON g.materia_id = m.id 
                                      JOIN semestres s ON g.semestre_id = s.id 
                                      ORDER BY s.numero, m.nombre, p.numero_parcial", '', [], $page, $perPage);
$generaciones = obtenerDatos($conexion, "SELECT id, nombre, fecha_inicio, fecha_fin FROM generaciones ORDER BY nombre", '', [], $page, $perPage);
$docentes = obtenerDatos($conexion, "SELECT u.id, u.nombre_completo FROM docentes d JOIN usuarios u ON d.usuario_id = u.id ORDER BY u.nombre_completo", '', [], $page, $perPage);
$reportes = obtenerDatos($conexion, "SELECT r.id, r.tipo_reporte, r.fecha_generado, r.ruta_archivo, g.nombre AS generacion, s.numero AS semestre, u.nombre_completo AS generado_por 
                                    FROM reportes r 
                                    JOIN generaciones g ON r.generacion_id = g.id 
                                    JOIN semestres s ON r.semestre_id = s.id 
                                    JOIN usuarios u ON r.generado_por = u.id 
                                    ORDER BY r.fecha_generado DESC", '', [], $page, $perPage);

$conexion->close();
?>