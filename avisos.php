<?php
// Conexión a la base de datos
$conexion = new mysqli('localhost', 'erick', '', 'bachillerato');
if ($conexion->connect_error) {
    die("Error en la conexión: " . $conexion->connect_error);
}

// Obtener los avisos de la base de datos
$query = "SELECT * FROM avisos ORDER BY fecha_creacion DESC";
$resultado = $conexion->query($query);

// Verificar si hay resultados
$avisos = [];
if ($resultado->num_rows > 0) {
    while ($row = $resultado->fetch_assoc()) {
        $avisos[] = $row;
    }
}

// Cerrar la conexión
$conexion->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avisos - Portal Académico</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Header Principal -->
    <header class="main-header">
        <div class="header-content">
            <div class="logo-container">
                <img src="images/logo.png" alt="Logo Escuela" class="logo-img">
                <div class="school-name">
                    Bachillerato General<br>
                    Miguel Hidalgo y Costilla
                </div>
            </div>
            
            <!-- Mobile menu toggle -->
            <div class="menu-toggle" onclick="toggleMenu()">☰</div>
            
            <!-- Navigation menu -->
            <nav class="main-nav" id="main-nav">
                <a href="index.php" class="nav-link">Inicio</a>
                
                
                <a href="avisos.php" class="nav-link active">Avisos</a>
                
               
            </nav>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="hero-overlay"></div>
            <div class="hero-content">
                <h1 class="hero-title">Avisos Institucionales</h1>
            </div>
        </section>

        <section class="avisos-section">
            <div class="section-container">
                <h2 class="section-title">Últimos Avisos</h2>
                <div class="avisos-container">
                    <?php if (count($avisos) > 0): ?>
                        <?php foreach ($avisos as $aviso): ?>
                            <div class="aviso-card">
                                <h3 class="aviso-title"><?php echo htmlspecialchars($aviso['titulo']); ?></h3>
                                <p class="aviso-content"><?php echo nl2br(htmlspecialchars($aviso['contenido'])); ?></p>
                                <p class="aviso-date">Publicado el: <?php echo date("d/m/Y", strtotime($aviso['fecha_creacion'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="no-aviso">No hay avisos disponibles en este momento.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Modal de Login -->
        <div id="loginModal" class="modal">
            <div class="modal-content login-content">
                <button class="close" onclick="closeModal('loginModal')">×</button>
                <h2 class="modal-title">Acceso al Portal Académico</h2>
                <form method="POST" action="procesarLogin2.php" id="loginForm">
                    <div class="form-group">
                        <label for="usuario" class="form-label">
                            <i class="fas fa-user"></i> Usuario / NIA
                        </label>
                        <input type="text" id="usuario" name="usuario" class="form-input" placeholder="Ingrese su usuario" required>
                    </div>
                    <div class="form-group">
                        <label for="contrasena" class="form-label">
                            <i class="fas fa-lock"></i> Contraseña
                        </label>
                        <div class="password-wrapper">
                            <input type="password" id="contrasena" name="contrasena" class="form-input" placeholder="Ingrese su contraseña" required>
                            <span class="toggle-password" onclick="togglePasswordVisibility()">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="tipo_usuario" class="form-label">
                            <i class="fas fa-users"></i> Tipo de Usuario
                        </label>
                        <select name="tipo_usuario" id="tipo_usuario" class="form-select" required>
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
                        <button type="button" class="btn-cancel" onclick="closeModal('loginModal')">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script src="js/script2.js"></script>
</body>
</html>