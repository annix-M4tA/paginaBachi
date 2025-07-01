<?php
session_start();
ob_start();

// Configuración inicial
$_SESSION['id'] = 1; // ID ficticio para pruebas (ajusta según tu lógica de autenticación)
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Conexión a la base de datos (ajusta según tu archivo real)
require 'conexion.php';

// Consultas para el dashboard
try {
    // Usuarios
    $stmt = $conexion->prepare("SELECT estado, COUNT(*) as total FROM usuarios GROUP BY estado");
    $stmt->execute();
    $usuarios_estados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $usuarios_activos = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'activo'), 'total')[0] ?? 0;
    $usuarios_inactivos = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'inactivo'), 'total')[0] ?? 0;
    $usuarios_bloqueados = array_column(array_filter($usuarios_estados, fn($u) => $u['estado'] === 'bloqueado'), 'total')[0] ?? 0;

    // Semestres activos
    $stmt = $conexion->prepare("SELECT COUNT(*) as total FROM semestres WHERE fecha_fin >= CURDATE()");
    $stmt->execute();
    $semestres_activos = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

    // Total de eventos (sin filtros de usuario ni fecha)
    $stmt = $conexion->prepare("SELECT COUNT(*) as total FROM eventos");
    $stmt->execute();
    $total_eventos = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

    // Próximos eventos (futuros, limitados a 3, filtrados por usuario)
    $usuario_id = (int)$_SESSION['id'];
    $stmt = $conexion->prepare("SELECT id, titulo, fecha, descripcion, tipo_evento FROM eventos WHERE usuario_id = ? AND fecha >= CURDATE() ORDER BY fecha ASC LIMIT 3");
    $stmt->bind_param('i', $usuario_id);
    $stmt->execute();
    $eventos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Avisos recientes
    $stmt = $conexion->prepare("SELECT id, titulo, contenido, prioridad, fecha_creacion FROM avisos WHERE usuario_id = ? ORDER BY fecha_creacion DESC LIMIT 3");
    $stmt->bind_param('i', $usuario_id);
    $stmt->execute();
    $avisos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Solicitudes de recuperación pendientes
    $stmt = $conexion->prepare("SELECT COUNT(*) as total FROM solicitudes_recuperacion WHERE estado = 'pendiente'");
    $stmt->execute();
    $recuperaciones_pendientes = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

} catch (Exception $e) {
    error_log("[ERROR] Error en consultas del dashboard: " . $e->getMessage());
    $usuarios_activos = $usuarios_inactivos = $usuarios_bloqueados = $semestres_activos = $total_eventos = $recuperaciones_pendientes = 0;
    $eventos = $avisos = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Prueba</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/administrativo.css">
</head>
<body>
    <div class="admin-container">
        <header class="admin-header" role="banner">
            <div class="admin-header__content">
                <div class="admin-header__brand">
                    <h1 class="admin-header__title">Panel Administrativo</h1>
                </div>
                <nav class="admin-nav">
                    <ul class="admin-nav__list">
                        <li><a href="#dashboard" class="admin-tab-link admin-nav__link active" data-tab="dashboard">Inicio</a></li>
                        <li><a href="#usuarios" class="admin-tab-link admin-nav__link" data-tab="usuarios">Usuarios</a></li>
                        <li><a href="#academico" class="admin-tab-link admin-nav__link" data-tab="academico">Académico</a></li>
                        <li><a href="#evaluacion" class="admin-tab-link admin-nav__link" data-tab="evaluacion">Evaluación</a></li>
                        <li><a href="#reportes" class="admin-tab-link admin-nav__link" data-tab="reportes">Reportes</a></li>
                        <li><a href="#eventos" class="admin-tab-link admin-nav__link" data-tab="eventos">Eventos</a></li>
                        <li><a href="#avisos" class="admin-tab-link admin-nav__link" data-tab="avisos">Avisos</a></li>
                        <li><a href="#solicitudes" class="admin-tab-link admin-nav__link" data-tab="solicitudes">Solicitudes</a></li>
                    </ul>
                </nav>
                <div class="theme-toggle-container">
                    <button class="theme-toggle" aria-label="Cambiar tema"><i class="fas fa-moon"></i></button>
                </div>
            </div>
        </header>

        <main class="admin-main-content">
            <section id="dashboard" class="admin-section admin-tab" style="display: block;">
                <div class="admin-section-header">
                    <h2 class="admin-section-title">Dashboard</h2>
                </div>
                <div class="dashboard-grid">
                    <!-- Usuarios Activos -->
                    <div class="admin-card admin-card--hover">
                        <h3><i class="fas fa-users"></i> Usuarios Activos</h3>
                        <p id="usuarios-activos"><?php echo htmlspecialchars($usuarios_activos); ?></p>
                    </div>

                    <!-- Usuarios Inactivos -->
                    <div class="admin-card admin-card--hover">
                        <h3><i class="fas fa-user-slash"></i> Usuarios Inactivos</h3>
                        <p id="usuarios-inactivos"><?php echo htmlspecialchars($usuarios_inactivos); ?></p>
                    </div>

                    <!-- Usuarios Bloqueados -->
                    <div class="admin-card admin-card--hover">
                        <h3><i class="fas fa-user-lock"></i> Usuarios Bloqueados</h3>
                        <p id="usuarios-bloqueados"><?php echo htmlspecialchars($usuarios_bloqueados); ?></p>
                    </div>

                    <!-- Semestres Activos -->
                    <div class="admin-card admin-card--hover">
                        <h3><i class="fas fa-calendar-alt"></i> Semestres Activos</h3>
                        <p id="semestres-activos"><?php echo htmlspecialchars($semestres_activos); ?></p>
                    </div>

                    <!-- Total de Eventos -->
                    <div class="admin-card admin-card--hover">
                        <h3><i class="fas fa-calendar-check"></i> Total de Eventos Creados</h3>
                        <p id="total-eventos"><?php echo htmlspecialchars($total_eventos); ?></p>
                    </div>

                    <!-- Recuperaciones Pendientes -->
                    <div class="admin-card admin-card--hover">
                        <h3><i class="fas fa-key"></i> Recuperaciones Pendientes</h3>
                        <p id="recuperaciones-pendientes"><?php echo htmlspecialchars($recuperaciones_pendientes); ?></p>
                    </div>

                    <!-- Próximos Eventos -->
                    <div class="admin-card">
                        <h3><i class="fas fa-calendar-day"></i> Próximos Eventos</h3>
                        <ul class="dashboard-list" id="eventos-proximos">
                            <?php if (empty($eventos)): ?>
                                <li>No hay eventos próximos.</li>
                            <?php else: ?>
                                <?php foreach ($eventos as $evento): ?>
                                    <li class="tipo-<?php echo htmlspecialchars($evento['tipo_evento']); ?>">
                                        <span><strong><?php echo htmlspecialchars($evento['titulo']); ?></strong> - <?php echo htmlspecialchars($evento['fecha']); ?></span>
                                        <small><?php echo htmlspecialchars($evento['tipo_evento']); ?></small>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Avisos Recientes -->
                    <div class="admin-card">
                        <h3><i class="fas fa-bell"></i> Avisos Recientes</h3>
                        <ul class="dashboard-list" id="avisos-recientes">
                            <?php if (empty($avisos)): ?>
                                <li>No hay avisos recientes.</li>
                            <?php else: ?>
                                <?php foreach ($avisos as $aviso): ?>
                                    <li class="prioridad-<?php echo htmlspecialchars($aviso['prioridad']); ?>">
                                        <span><strong><?php echo htmlspecialchars($aviso['titulo']); ?></strong> - <?php echo htmlspecialchars($aviso['contenido']); ?></span>
                                        <small><?php echo htmlspecialchars($aviso['fecha_creacion']); ?></small>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
        // Funciones de utilidad
        const log = (message, type = 'INFO') => {
            console.log(`[${type.toUpperCase()}] ${message} - ${new Date().toISOString()}`);
        };
        const $ = (selector) => document.querySelector(selector);
        const $$ = (selector) => Array.from(document.querySelectorAll(selector));

        // Inicialización del Dashboard
        document.addEventListener('DOMContentLoaded', () => {
            log('Dashboard de prueba cargado');

            // Datos iniciales desde PHP
            const initialData = {
                usuariosActivos: <?php echo json_encode($usuarios_activos); ?>,
                usuariosInactivos: <?php echo json_encode($usuarios_inactivos); ?>,
                usuariosBloqueados: <?php echo json_encode($usuarios_bloqueados); ?>,
                semestresActivos: <?php echo json_encode($semestres_activos); ?>,
                totalEventos: <?php echo json_encode($total_eventos); ?>,
                recuperacionesPendientes: <?php echo json_encode($recuperaciones_pendientes); ?>,
                eventos: <?php echo json_encode($eventos); ?>,
                avisos: <?php echo json_encode($avisos); ?>
            };

            // Logs iniciales
            Object.entries(initialData).forEach(([key, value]) => {
                log(`${key}: ${JSON.stringify(value)}`);
            });

            // Actualizar dashboard dinámicamente
            const updateDashboard = () => {
                $('#usuarios-activos').textContent = initialData.usuariosActivos;
                $('#usuarios-inactivos').textContent = initialData.usuariosInactivos;
                $('#usuarios-bloqueados').textContent = initialData.usuariosBloqueados;
                $('#semestres-activos').textContent = initialData.semestresActivos;
                $('#total-eventos').textContent = initialData.totalEventos;
                $('#recuperaciones-pendientes').textContent = initialData.recuperacionesPendientes;

                const eventosList = $('#eventos-proximos');
                eventosList.innerHTML = initialData.eventos.length === 0 
                    ? '<li>No hay eventos próximos.</li>'
                    : initialData.eventos.map(e => `
                        <li class="tipo-${e.tipo_evento}">
                            <span><strong>${e.titulo}</strong> - ${e.fecha}</span>
                            <small>${e.tipo_evento}</small>
                        </li>
                    `).join('');

                const avisosList = $('#avisos-recientes');
                avisosList.innerHTML = initialData.avisos.length === 0 
                    ? '<li>No hay avisos recientes.</li>'
                    : initialData.avisos.map(a => `
                        <li class="prioridad-${a.prioridad}">
                            <span><strong>${a.titulo}</strong> - ${a.contenido}</span>
                            <small>${a.fecha_creacion}</small>
                        </li>
                    `).join('');
            };

            // Simulación de actualización dinámica
            setTimeout(() => {
                log('Simulando nueva solicitud de recuperación', 'DEBUG');
                initialData.recuperacionesPendientes += 1;
                updateDashboard();
                log(`Recuperaciones pendientes actualizadas a: ${initialData.recuperacionesPendientes}`, 'SUCCESS');
            }, 5000);

            // Interacción con tarjetas
            $$('.admin-card').forEach(card => {
                card.addEventListener('click', (e) => {
                    const title = card.querySelector('h3')?.textContent || 'Sin título';
                    log(`Clic en tarjeta: ${title}`, 'EVENT');
                    alert(`Has hecho clic en "${title}"`);
                });
            });

            // Manejo básico de pestañas (simulado)
            $$('.admin-tab-link').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const tab = link.dataset.tab;
                    log(`Intento de cambio a pestaña: ${tab}`, 'EVENT');
                    alert(`Esta es una prueba. La pestaña "${tab}" no está implementada aún.`);
                });
            });

            // Toggle de tema (básico)
            $('.theme-toggle').addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                log('Cambio de tema', 'EVENT');
            });

            // Actualización inicial
            updateDashboard();
        });
    </script>
</body>
</html>

<?php
ob_end_flush();
$conexion->close();
?>