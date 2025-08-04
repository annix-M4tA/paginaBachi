<?php  
// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {  
    session_start();  
}  

// Generar token CSRF si no existe
if (!isset($_SESSION['csrf_token'])) {  
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));  
}  

// Incluir archivos de conexión y funciones
include('conexion.php');  
include('functions.php');  

// Obtener eventos desde la base de datos
$events = getEvents();  

// Datos estáticos de los docentes
$teachers = [  
    [  
        'name' => 'Dr. Juan Pérez',  
        'subject' => 'Matemáticas',  
        'image' => '/api/placeholder/250/250',  
        'description' => 'Doctor en Matemáticas Aplicadas'  
    ],  
    [  
        'name' => 'Prof. Ana Gómez',  
        'subject' => 'Literatura',  
        'image' => '/api/placeholder/250/250',  
        'description' => 'Profesora de Literatura y Lengua Española'  
    ]  
];  

// Consulta para obtener las universidades usando MySQLi
$universidades = [];
$query = "SELECT id, nombre, descripcion, logo, sitio_web FROM universidades";
$result = $conexion->query($query);

if ($result) {
    if ($result->num_rows > 0) {
        $universidades = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        // No hay universidades registradas
        $universidades = [];
    }
    $result->free();
} else {
    // Manejo de errores
    error_log("Error en la consulta de universidades: " . $conexion->error);
}

// Comprobar si hay mensaje de error de login
$login_error = '';  
if (isset($_GET['error'])) {  
    switch ($_GET['error']) {  
        case 'empty':  
            $login_error = 'Por favor complete todos los campos.';  
            break;  
        case 'invalid':  
            $login_error = 'Credenciales incorrectas. Intente nuevamente.';  
            break;  
        case 'notfound':  
            $login_error = 'Usuario no encontrado.';  
            break;  
        default:  
            $login_error = 'Ocurrió un error. Intente nuevamente.';  
    }  
}  
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bachillerato General Miguel Hidalgo y Costilla</title>
    <link rel="stylesheet" href="styles.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body>
<header class="main-header">
    <div class="header-content">
        <!-- Logo y título -->
        <div class="logo-container">
            <img src="images/logo.jpg" alt="Logo Escuela" class="logo-img">
            <h1 class="school-name">Bachillerato General Miguel Hidalgo y Costilla</h1>
        </div>

        <!-- Botón de menú móvil -->
        <div class="menu-toggle" onclick="toggleMenu()">&#9776;</div>

        <!-- Menú principal -->
        <nav class="main-nav" id="main-nav">
            <a href="index.php" class="nav-link">Inicio</a>
            <a href="#nosotros" class="nav-link">Nosotros</a>
            <a href="#oferta" class="nav-link">Oferta Educativa</a>
            <a href="avisos.php" class="nav-link">Avisos</a>
            <a href="#contacto" class="nav-link">Contacto</a>
            <a href="#uni" class="nav-link">Convenios Académicos</a>
            <button class="login-button" onclick="showLogin()">Portal Académico</button>
        </nav>
    </div>
</header>

    <!-- Barra lateral (menú lateral) -->
    <nav class="sidebar">
        <a href="#inicio" class="nav-link">Inicio</a>
        <a href="#nosotros" class="nav-link">Nodfdfdfros</a>
        <a href="#oferta" class="nav-link">Oferta Educativa</a>
        <a href="avisos.php" class="nav-link">Avisos</a>
        <a href="#contacto" class="nav-link">Contacto</a>
        <a href="#uni" class="nav-link">Convenios Académicos</a>
                
    </nav>

    <!-- Hero Section -->
<section class="hero" id="hero">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <h1 class="hero-title">Formando el Futuro de México</h1>
        <p class="hero-subtitle">Educación de calidad con valores y compromiso social</p>
    </div>
</section>

<section class="activities-carousel">
    <h2>Actividades y Eventos</h2>
    <div class="carousel-container">
        <?php
        $eventsCarousel = [];
        $resultCarousel = $conexion->query("SELECT * FROM eventos ORDER BY fecha_creacion DESC");
        if ($resultCarousel && $resultCarousel->num_rows > 0) {
            $eventsCarousel = $resultCarousel->fetch_all(MYSQLI_ASSOC);
            $resultCarousel->free();
        ?>
            <!-- Flechas de navegación -->
            <div class="carousel-arrow left-arrow">◀</div>
            <div class="carousel-images">
                <?php foreach ($eventsCarousel as $event): ?>
                    <div class="activity-card">
                        <div class="activity-image" style="background-image: url('images/eventos/<?php echo basename($event['ruta_foto']); ?>');"></div>
                        <div class="activity-content">
                            <h3><?php echo htmlspecialchars($event['titulo']); ?></h3>
                            <p><?php echo htmlspecialchars($event['descripcion']); ?></p>
                            <p><strong>Fecha:</strong> <?php echo date("d/m/Y", strtotime($event['fecha'])); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="carousel-arrow right-arrow">▶</div>
        <?php
        } else {
            echo "<p>No hay eventos disponibles.</p>";
        }
        ?>
    </div>
</section>


<!-- Nosotros Section -->
<section id="nosotros" class="section-nosotros">
    <div class="section-container">
        <div class="nosotros-content">
            <h2 class="section-title">Quiénes Somos</h2>
            <div class="nosotros-grid">
                <div class="nosotros-card">
                    <i class="icon-historia"></i>
                    <h3>Historia</h3>
                    <p>Fundada en [año], la Preparatoria Miguel Hidalgo ha sido un pilar educativo en [ubicación], formando generaciones de estudiantes comprometidos con su desarrollo académico y personal.</p>
                </div>
                <div class="nosotros-card">
                    <i class="icon-logros"></i>
                    <h3>Logros Académicos</h3>
                    <p>Reconocida por sus destacados resultados en evaluaciones estatales, olimpiadas académicas y alto porcentaje de ingreso a instituciones de educación superior.</p>
                </div>
                <div class="nosotros-card">
                    <i class="icon-comunidad"></i>
                    <h3>Compromiso Comunitario</h3>
                    <p>Implementamos programas de vinculación social, servicio comunitario y desarrollo sustentable que forman ciudadanos responsables y comprometidos con su entorno.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Oferta Educativa Section -->
<section id="oferta" class="section-oferta">
    <div class="section-container">
        <h2 class="section-title">Oferta Educativa</h2>
        <div class="oferta-grid">
            <div class="oferta-card">
                <div class="oferta-image" style="background-image: url('images/oferta.jpg')"></div>
                <div class="oferta-content">
                    <h3>Bachillerato General</h3>
                    <p>Formación académica integral con énfasis en desarrollo personal y social.</p>
                </div>
            </div>
            <!-- Add more oferta cards as needed -->
        </div>
    </div>
</section>

<!-- Sección de contacto -->
<section id="contacto" class="section-contacto">
        <div class="section-container">
            <h2 class="section-title">Contacto</h2>
            <div class="contacto-grid">
                <div class="contacto-info">
                    <h3>Información de Contacto</h3>
                    <p>Dirección: Av. Principal #123, Ciudad</p>
                    <p>Teléfono: (555) 123-4567</p>
                    <p>Email: ignacioGregorioComonfort@bachillerato.edu.mx</p>
                </div>
                <div class="contacto-form">
                    <form id="contactForm">
                        <label for="name">Nombre:</label>
                        <input type="text" id="name" name="name" placeholder="Nombre" required>
                        <label for="email">Correo Electrónico:</label>
                        <input type="email" id="email" name="email" placeholder="Ingresa tu Correo Electrónico" required>
                        <label for="message">Tu Mensaje:</label>
                        <textarea id="message" name="message" placeholder="Tu Mensaje" required></textarea>
                        <button type="submit">Enviar Mensaje</button>
                    </form>
                    <p id="confirmation"></p>
                </div>
            </div>
        </div>
    </section>

<!-- Nueva Sección: Convenios Académicos -->
<section id="uni" class="universities-section">
    <h2 class="section-title">Convenios Académicos</h2>
    <div class="universities-container">
        <?php if (!empty($universidades)): ?>
            <?php foreach ($universidades as $uni): ?>
                <div class="university-card">
                    <img src="images/logos/<?php echo htmlspecialchars($uni['logo']); ?>" alt="<?php echo htmlspecialchars($uni['nombre']); ?>" class="university-logo">
                    <h3><?php echo htmlspecialchars($uni['nombre']); ?></h3>
                    <p><?php echo htmlspecialchars($uni['descripcion']); ?></p>
                    <a href="<?php echo htmlspecialchars($uni['sitio_web']); ?>" class="university-link" target="_blank">Visitar sitio web</a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No se encontraron universidades registradas.</p>
        <?php endif; ?>
    </div>
</section>



    <!-- Sección de Profesores -->
    <section class="teachers-section">
    <h2 class="section-title">Nuestros Docentes</h2>  
       
        <div class="teachers-grid">
            <?php foreach ($teachers as $teacher): ?>
                <div class="teacher-card">
                    <div class="teacher-image" style="background-image: url('<?php echo $teacher['image']; ?>')"></div>
                    <div class="teacher-info">
                        <h3><?php echo $teacher['name']; ?></h3>
                        <p><?php echo $teacher['subject']; ?></p>
                        <p><?php echo $teacher['description']; ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

 <!-- Misión, Visión y Política de Calidad -->
<section class="mission-vision">
    <div class="mission-card">
        <h2>Misión</h2>
        <p>Somos una institución comprometida con garantizar el derecho a la educación de todas y todos los poblanos, 
            posibilitando su formación integral con excelencia, equidad de oportunidades, ética e innovación para la transformación.</p>
    </div>
    <div class="vision-card">
        <h2>Visión</h2>
        <p>Ser una entidad que garantice el derecho a la educación de la niñez y 
            juventud, colocándolos en el centro de sus decisiones, con el fin de formar ciudadanía para la transformación. 
            Con estándares pertinentes de calidad que respeten la dignidad de las personas, promuevan la formación ética 
            con perspectiva social e inclusión. Una educación que impulse el aprendizaje a lo largo de la vida, contribuya 
            a recuperar los saberes, capacidades locales y vocaciones productivas, y que sea congruente con las particularidades 
            de las 32 regiones del territorio poblano.</p>
    </div>
    <div class="politics-card">
        <h2>Política de Calidad</h2>
        <p>• La Secretaría de Educación del Gobierno del Estado de Puebla es una dependencia comprometida con la
             formación integral de la sociedad, que garantiza el derecho a la educación a través de sus cuatro 
             dimensiones: accesibilidad, asequibilidad, aceptabilidad y adaptabilidad.<br>
        • La educación en Puebla debe posibilitar la formación de una ciudadanía para la transformación,
         a través del pensamiento crítico, el sentido humanista y la ética para la vida. Impulsada en un modelo educativo de
          excelencia, mejora continua y aprendizaje a lo largo de la vida, que considere prácticas socioeducativas emancipadoras.<br>
        <small>FEBRERO 2020</small></p>
    </div>
</section>


<!-- Modal de Login -->
<div id="loginModal" class="modal login-modal">
    <div class="modal-content login-content">
        <button class="close" onclick="closeModal('loginModal')">&times;</button>
        
        <h2 class="modal-title">Acceso al Portal Académico</h2>
        <?php if (!empty($login_error)): ?>
    <div class="error-message animate__animated animate__shakeX">
        <?php echo htmlspecialchars($login_error); ?>
    </div>
<?php endif; ?>

        <form method="POST" action="procesarLogin2.php" id="loginForm">
            <!-- CSRF Token (Agregar esto) -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-group">
                <label for="username" class="form-label">
                    <i class="fas fa-user"></i> Usuario / Correo
                </label>
                <input type="text" 
                       id="username" 
                       name="username" 
                       class="form-input"
                       placeholder="usuario@escuela.com"
                       required
                       autofocus>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">
                    <i class="fas fa-lock"></i> Contraseña
                </label>
                <div class="password-wrapper">
                    <input type="password" 
                           id="password" 
                           name="password" 
                           class="form-input"
                           placeholder="••••••••"
                           required>
                           <span class="toggle-password" onclick="togglePasswordVisibility()">
                                <i class="fas fa-eye"></i>
                            </span>
                </div>
                <a href="recuperar.php" class="forgot-password">¿Olvidaste tu contraseña?</a>
            </div>

            <div class="form-group">
                <label for="tipo" class="form-label">
                    <i class="fas fa-users"></i> Tipo de Usuario
                </label>
                <select name="tipo" id="tipo" class="form-select" required>
                    <option value="" disabled selected>Selecciona tu rol</option>
                    <option value="alumno">Alumno</option>
                    <option value="docente">Docente</option>
                    <option value="administrativo">Administrativo</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Ingresar
                </button>
                <button type="button" 
                        class="btn-cancel"
                        onclick="closeModal('loginModal')">
                    Cancelar
                </button>
            </div>
        </form>      
    </div>
</div>

<!-- Modal de Actividad -->
<div id="activityModal" class="modal" style="display: none;">
    <div class="modal-content">
        <img id="modalImage" src="" alt="Actividad">
        <h3 id="modalTitle"></h3>
        <p id="modalDescription"></p>
        <button class="close" onclick="closeModal('activityModal')">&times;</button>
    </div>
</div>

<!-- Footer -->
<footer class="main-footer">
        <div class="footer-content">
            <p>&copy; <?php echo date("Y"); ?> Desarrollado por Erick V. Romero en colaboracion con Annie Mata</p>
        </div>
    </footer>

<!-- Enlace al archivo JS -->
<script src="js/script2.js"></script>
</body>
</html>