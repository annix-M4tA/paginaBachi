<?php
session_start();
ob_start();

if (!isset($_SESSION['id'])) {
    $_SESSION['id'] = 1; // ID ficticio para pruebas
}
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

require 'conexion.php';

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
    error_log("[$message] " . date('Y-m-d H:i:s') . "\n", 3, 'logs/app_' . date('Y-m-d') . '.log');
}

// Manejo de solicitudes AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Acción no reconocida'];

    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $response = ['status' => 'error', 'message' => 'Token CSRF inválido.'];
        echo json_encode($response);
        exit();
    }

    try {
        $conexion->begin_transaction();

        if (isset($_POST['action']) && $_POST['action'] === 'create_evento') {
            $titulo = sanitizar($_POST['titulo'] ?? '', $conexion);
            $fecha = sanitizar($_POST['fecha'] ?? '', $conexion);
            $descripcion = sanitizar($_POST['descripcion'] ?? '', $conexion, true);
            $tipo_evento = sanitizar($_POST['tipo_evento'] ?? 'academico', $conexion);
            $usuario_id = (int)$_SESSION['id'];

            $errors = [];
            if (!$titulo) $errors['titulo'] = 'El título es obligatorio.';
            if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $errors['fecha'] = 'Fecha inválida (YYYY-MM-DD).';
            if (!$descripcion) $errors['descripcion'] = 'La descripción es obligatoria.';
            if (!in_array($tipo_evento, ['academico', 'deportivo', 'cultural'])) $errors['tipo_evento'] = 'Tipo de evento inválido.';

            $ruta_foto = 'images/eventos/default.jpg';
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['foto']['tmp_name'];
                $fileName = $_FILES['foto']['name'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png'];
                if (!in_array($fileExt, $allowedExts)) {
                    $errors['foto'] = 'Solo se permiten archivos JPG, JPEG o PNG.';
                } else {
                    $newFileName = uniqid('evento_') . '.' . $fileExt;
                    $ruta_foto = 'images/eventos/' . $newFileName;
                    if (!move_uploaded_file($fileTmpPath, $ruta_foto)) {
                        $errors['foto'] = 'Error al subir la foto.';
                    }
                }
            }

            if (!empty($errors)) {
                $response = ['status' => 'error', 'message' => 'Datos inválidos.', 'errors' => $errors];
                echo json_encode($response);
                exit();
            }

            $stmt = $conexion->prepare("INSERT INTO eventos (titulo, fecha, descripcion, ruta_foto, usuario_id, tipo_evento) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssis', $titulo, $fecha, $descripcion, $ruta_foto, $usuario_id, $tipo_evento);
            if (!$stmt->execute()) throw new Exception('Error al crear evento: ' . $conexion->error);
            $newId = $conexion->insert_id;
            $stmt->close();

            $response = [
                'status' => 'success',
                'message' => 'Evento creado correctamente.',
                'data' => [
                    'id' => $newId,
                    'titulo' => $titulo,
                    'fecha' => $fecha,
                    'descripcion' => $descripcion,
                    'ruta_foto' => $ruta_foto,
                    'tipo_evento' => $tipo_evento,
                    'fecha_creacion' => date('Y-m-d H:i:s')
                ]
            ];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_evento') {
            $id = (int)($_POST['id'] ?? 0);
            $titulo = sanitizar($_POST['titulo'] ?? '', $conexion);
            $fecha = sanitizar($_POST['fecha'] ?? '', $conexion);
            $descripcion = sanitizar($_POST['descripcion'] ?? '', $conexion, true);
            $tipo_evento = sanitizar($_POST['tipo_evento'] ?? 'academico', $conexion);

            $errors = [];
            if ($id <= 0) $errors['id'] = 'ID inválido.';
            if (!$titulo) $errors['titulo'] = 'El título es obligatorio.';
            if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $errors['fecha'] = 'Fecha inválida (YYYY-MM-DD).';
            if (!$descripcion) $errors['descripcion'] = 'La descripción es obligatoria.';
            if (!in_array($tipo_evento, ['academico', 'deportivo', 'cultural'])) $errors['tipo_evento'] = 'Tipo de evento inválido.';

            $ruta_foto = null;
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['foto']['tmp_name'];
                $fileName = $_FILES['foto']['name'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png'];
                if (!in_array($fileExt, $allowedExts)) {
                    $errors['foto'] = 'Solo se permiten archivos JPG, JPEG o PNG.';
                } else {
                    $newFileName = uniqid('evento_') . '.' . $fileExt;
                    $ruta_foto = 'images/eventos/' . $newFileName;
                    if (!move_uploaded_file($fileTmpPath, $ruta_foto)) {
                        $errors['foto'] = 'Error al subir la foto.';
                    }
                }
            }

            if (!empty($errors)) {
                $response = ['status' => 'error', 'message' => 'Datos inválidos.', 'errors' => $errors];
                echo json_encode($response);
                exit();
            }

            if ($ruta_foto) {
                $stmt = $conexion->prepare("UPDATE eventos SET titulo = ?, fecha = ?, descripcion = ?, ruta_foto = ?, tipo_evento = ? WHERE id = ?");
                $stmt->bind_param('sssssi', $titulo, $fecha, $descripcion, $ruta_foto, $tipo_evento, $id);
            } else {
                $stmt = $conexion->prepare("UPDATE eventos SET titulo = ?, fecha = ?, descripcion = ?, tipo_evento = ? WHERE id = ?");
                $stmt->bind_param('ssssi', $titulo, $fecha, $descripcion, $tipo_evento, $id);
            }
            if (!$stmt->execute()) throw new Exception('Error al actualizar evento: ' . $conexion->error);
            if ($stmt->affected_rows === 0) throw new Exception('Evento no encontrado o sin cambios');
            $stmt->close();

            $response = [
                'status' => 'success',
                'message' => 'Evento actualizado correctamente.',
                'data' => [
                    'id' => $id,
                    'titulo' => $titulo,
                    'fecha' => $fecha,
                    'descripcion' => $descripcion,
                    'ruta_foto' => $ruta_foto,
                    'tipo_evento' => $tipo_evento
                ]
            ];
            $conexion->commit();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_evento') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) throw new Exception('ID inválido.');

            $stmt = $conexion->prepare("SELECT ruta_foto FROM eventos WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $ruta_foto = $result['ruta_foto'] ?? null;
            $stmt->close();

            $stmt = $conexion->prepare("DELETE FROM eventos WHERE id = ?");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Error al eliminar evento: ' . $conexion->error);
            if ($stmt->affected_rows === 0) throw new Exception('Evento no encontrado.');
            $stmt->close();

            if ($ruta_foto && file_exists($ruta_foto) && $ruta_foto !== 'images/eventos/default.jpg') {
                unlink($ruta_foto);
            }

            $response = ['status' => 'success', 'message' => 'Evento eliminado correctamente.', 'data' => ['id' => $id]];
            $conexion->commit();
        }

    } catch (Exception $e) {
        $conexion->rollback();
        logError("Error en operación de eventos: " . $e->getMessage());
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

// Consultar eventos para la tabla
try {
    $stmt = $conexion->prepare("SELECT id, titulo, fecha, descripcion, ruta_foto, tipo_evento, fecha_creacion FROM eventos ORDER BY fecha DESC");
    $stmt->execute();
    $eventos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    logError("Error al consultar eventos: " . $e->getMessage());
    $eventos = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba de Eventos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="./css/administrativo.css">
</head>
<body>
    <div class="admin-container">
        <main class="admin-main-content">
            <section id="eventos" class="admin-section admin-tab" style="display: block;">
                <header class="admin-section-header">
                    <h2 class="admin-section-title">Gestión de Eventos</h2>
                    <button data-modal="modal-crear-evento" class="admin-button admin-button--primary">
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

            <!-- Modal Crear Evento -->
            <div id="modal-crear-evento" class="admin-modal">
                <div class="admin-modal-content">
                    <header class="admin-modal-header">
                        <h3>Crear Nuevo Evento</h3>
                        <button class="modal-close" aria-label="Cerrar modal">✖</button>
                    </header>
                    <form id="crear-evento-form" class="admin-form" enctype="multipart/form-data">
                        <div class="admin-modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_evento">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="titulo" id="titulo" required>
                                    <span class="floating-label">Título</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha" id="fecha" required>
                                    <span class="floating-label">Fecha</span>
                                </div>
                                <div class="admin-form-group">
                                    <textarea name="descripcion" id="descripcion" required></textarea>
                                    <span class="floating-label">Descripción</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="file" name="foto" id="foto" accept="image/*">
                                    <span class="floating-label">Foto del Evento</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="tipo_evento" id="tipo_evento" required>
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
                            <input type="hidden" name="id" id="edit-id">
                            <div class="form-grid">
                                <div class="admin-form-group">
                                    <input type="text" name="titulo" id="edit-titulo" required>
                                    <span class="floating-label">Título</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="date" name="fecha" id="edit-fecha" required>
                                    <span class="floating-label">Fecha</span>
                                </div>
                                <div class="admin-form-group">
                                    <textarea name="descripcion" id="edit-descripcion" required></textarea>
                                    <span class="floating-label">Descripción</span>
                                </div>
                                <div class="admin-form-group">
                                    <input type="file" name="foto" id="edit-foto" accept="image/*">
                                    <span class="floating-label">Foto del Evento (opcional)</span>
                                </div>
                                <div class="admin-form-group">
                                    <select name="tipo_evento" id="edit-tipo_evento" required>
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
        </main>
    </div>

    <script>
        // Utilidades
        const $ = (selector, context = document) => context.querySelector(selector);
        const $$ = (selector, context = document) => Array.from(context.querySelectorAll(selector));
        const log = (type = 'INFO', message) => console.log(`[${type}] ${message} - ${new Date().toISOString()}`);
        const logError = (message, error) => console.error(`[ERROR] ${message}: ${error.message || error} - ${new Date().toISOString()}`);

        // Notificaciones
        const NotificationModule = {
            show(message, type = 'info', duration = 3000) {
                const notification = document.createElement('div');
                notification.className = `admin-notification ${type} show`;
                notification.innerHTML = message;
                document.body.appendChild(notification);
                const index = $$('.admin-notification').length - 1;
                notification.style.setProperty('--notification-index', index);
                setTimeout(() => {
                    notification.classList.remove('show');
                    setTimeout(() => notification.remove(), 300);
                }, duration);
            }
        };

        // Modals
        const ModalModule = {
            open(modalId) {
                const modal = $(`#${modalId}`);
                if (modal) {
                    modal.classList.add('active');
                    document.body.classList.add('modal-open');
                    log('INFO', `Modal ${modalId} abierto`);
                }
            },
            close(modalId) {
                const modal = $(`#${modalId}`);
                if (modal) {
                    modal.classList.remove('active');
                    document.body.classList.remove('modal-open');
                    log('INFO', `Modal ${modalId} cerrado`);
                }
            },
            closeAll() {
                $$('.admin-modal').forEach(modal => modal.classList.remove('active'));
                document.body.classList.remove('modal-open');
                log('INFO', 'Todos los modales cerrados');
            }
        };

        // Eventos Module
        const EventoModule = {
            async handleSubmit(e) {
                e.preventDefault();
                const form = e.target;
                const formData = new FormData(form);
                const modalId = form.closest('.admin-modal').id;

                try {
                    log('INFO', `Enviando formulario ${form.id}`);
                    const response = await fetch('eventosPrueba.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await response.json();

                    if (data.status === 'success') {
                        NotificationModule.show(data.message, 'success');
                        ModalModule.close(modalId);
                        this.updateEventosTable(data.data);
                    } else {
                        NotificationModule.show(data.message || 'Error al procesar la solicitud.', 'error');
                        if (data.errors) this.showFormErrors(form, data.errors);
                    }
                } catch (error) {
                    logError('Error en la solicitud AJAX', error);
                    NotificationModule.show('Error al procesar la solicitud.', 'error');
                }
            },
            updateEventosTable(eventoData) {
                const tableBody = $('#eventos-table tbody');
                if (!tableBody) return logError('Tabla de eventos no encontrada');
                const existingRow = tableBody.querySelector(`tr[data-id="${eventoData.id}"]`);
                const rowHTML = `
                    <tr data-id="${eventoData.id}">
                        <td data-label="ID">${eventoData.id}</td>
                        <td data-label="Título">${eventoData.titulo}</td>
                        <td data-label="Fecha">${eventoData.fecha}</td>
                        <td data-label="Descripción">${eventoData.descripcion}</td>
                        <td data-label="Foto"><img src="${eventoData.ruta_foto || 'images/eventos/default.jpg'}" alt="Foto del evento" style="max-width: 50px;"></td>
                        <td data-label="Tipo">${eventoData.tipo_evento}</td>
                        <td data-label="Fecha Creación">${eventoData.fecha_creacion || new Date().toISOString().slice(0, 19).replace('T', ' ')}</td>
                        <td data-label="Acciones">
                            <button class="admin-button" data-action="edit" data-id="${eventoData.id}" aria-label="Editar evento"><i class="fas fa-edit"></i></button>
                            <button class="admin-button" data-action="delete" data-id="${eventoData.id}" aria-label="Eliminar evento"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                `;
                if (existingRow) existingRow.outerHTML = rowHTML;
                else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
            },
            showFormErrors(form, errors) {
                Object.entries(errors).forEach(([field, message]) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    if (input) {
                        const group = input.closest('.admin-form-group');
                        group.classList.add('error');
                        let errorSpan = group.querySelector('.error-message');
                        if (!errorSpan) {
                            errorSpan = document.createElement('span');
                            errorSpan.className = 'error-message';
                            group.appendChild(errorSpan);
                        }
                        errorSpan.textContent = message;
                    }
                });
            },
            openEditModal(eventoId) {
                const row = $(`#eventos-table tr[data-id="${eventoId}"]`);
                if (!row) return logError(`Fila de evento ${eventoId} no encontrada`);

                const form = $('#editar-evento-form');
                form.querySelector('#edit-id').value = eventoId;
                form.querySelector('#edit-titulo').value = row.querySelector('[data-label="Título"]').textContent;
                form.querySelector('#edit-fecha').value = row.querySelector('[data-label="Fecha"]').textContent;
                form.querySelector('#edit-descripcion').value = row.querySelector('[data-label="Descripción"]').innerHTML;
                form.querySelector('#edit-tipo_evento').value = row.querySelector('[data-label="Tipo"]').textContent;

                ModalModule.open('modal-editar-evento');
                log('INFO', `Editando evento ID: ${eventoId}`);
            },
            async deleteEvento(eventoId) {
                if (!confirm('¿Estás seguro de eliminar este evento?')) return;
                log('INFO', `[${eventoId}] Eliminando evento`);
                try {
                    const response = await fetch('eventosPrueba.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=delete_evento&id=${eventoId}&csrf_token=${$('input[name="csrf_token"]').value}`
                    });
                    const data = await response.json();

                    if (data.status === 'success') {
                        $(`#eventos-table tr[data-id="${eventoId}"]`)?.remove();
                        NotificationModule.show('Evento eliminado correctamente', 'success');
                    } else {
                        throw new Error(data.message || 'Error al eliminar el evento');
                    }
                } catch (error) {
                    logError(`Error al eliminar evento ${eventoId}`, error);
                    NotificationModule.show(`Error al eliminar el evento: ${error.message}`, 'error');
                }
            }
        };

        // Inicialización
        document.addEventListener('DOMContentLoaded', () => {
            $('#crear-evento-form').addEventListener('submit', EventoModule.handleSubmit.bind(EventoModule));
            $('#editar-evento-form').addEventListener('submit', EventoModule.handleSubmit.bind(EventoModule));

            document.addEventListener('click', (e) => {
                const modalBtn = e.target.closest('[data-modal]');
                if (modalBtn) {
                    ModalModule.open(modalBtn.getAttribute('data-modal'));
                    return;
                }

                if (e.target.classList.contains('modal-close')) {
                    ModalModule.closeAll();
                    return;
                }

                const actionBtn = e.target.closest('.admin-button[data-action]');
                if (actionBtn) {
                    const action = actionBtn.getAttribute('data-action');
                    const id = actionBtn.getAttribute('data-id');
                    if (action === 'edit') EventoModule.openEditModal(id);
                    else if (action === 'delete') EventoModule.deleteEvento(id);
                }
            });
        });
    </script>
</body>
</html>

<?php
ob_end_flush();
$conexion->close();
?>