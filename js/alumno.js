// === Inicialización al Cargar el DOM ===
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM completamente cargado - Sistema de Gestión Escolar');
    initializeMenuToggle();
    initializeSectionAnimations();
    initializeRippleEffect();
    initializeScrollToSection();
    initializeHoverEffects();
    initializeNotificationHandler();
    initializeLogoutConfirmation();
    initializeThemeToggle();
    console.log('Todas las inicializaciones ejecutadas');
});

// === Toggle del Menú Móvil ===
function initializeMenuToggle() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.student-nav');
    let isOpen = false;

    if (!menuToggle) {
        console.error('Error: Botón .menu-toggle no encontrado');
        return;
    }
    if (!sidebar) {
        console.error('Error: Elemento .student-nav no encontrado');
        return;
    }

    console.log('Menú móvil inicializado correctamente');
    menuToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        isOpen = !isOpen;
        sidebar.classList.toggle('active', isOpen);
        menuToggle.textContent = isOpen ? '✕' : '☰';
        menuToggle.setAttribute('aria-expanded', isOpen);
        menuToggle.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
        console.log(`Menú móvil: ${isOpen ? 'abierto' : 'cerrado'} - Clase active: ${sidebar.classList.contains('active')}`);
    });

    document.addEventListener('click', (e) => {
        if (isOpen && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
            isOpen = false;
            sidebar.classList.remove('active');
            menuToggle.textContent = '☰';
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.style.transform = 'rotate(0deg)';
            console.log('Menú móvil cerrado por clic fuera');
        }
    });

    window.addEventListener('resize', debounce(() => {
        if (window.innerWidth > 768) {
            isOpen = false;
            sidebar.classList.remove('active');
            sidebar.style.left = '';
            menuToggle.textContent = '☰';
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.style.transform = 'rotate(0deg)';
            menuToggle.style.display = 'none';
            console.log('Menú ajustado por redimensionamiento: cerrado en pantallas grandes');
        } else {
            menuToggle.style.display = 'block';
            if (!isOpen) {
                sidebar.style.left = '-100%';
            }
            console.log('Menú ajustado por redimensionamiento: listo en pantallas pequeñas');
        }
    }, 200));
}

// === Animaciones de Entrada para Secciones ===
function initializeSectionAnimations() {
    const sections = document.querySelectorAll('.student-section');
    const observerOptions = {
        root: null,
        threshold: 0.1,
        rootMargin: '0px'
    };

    if (sections.length === 0) {
        console.warn('No se encontraron secciones con la clase .student-section');
        return;
    }

    const sectionObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                console.log(`Sección visible: ${entry.target.id || 'sin ID'}`);
            } else {
                entry.target.classList.remove('visible');
                console.log(`Sección oculta: ${entry.target.id || 'sin ID'}`);
            }
        });
    }, observerOptions);

    console.log(`Observando ${sections.length} secciones .student-section`);
    sections.forEach(section => {
        section.classList.add('hidden');
        sectionObserver.observe(section);
    });
}

// === Efecto Ripple en Botones y Enlaces ===
function initializeRippleEffect() {
    const elements = document.querySelectorAll('.nav-link, .logout-button, .info-card');

    if (elements.length === 0) {
        console.warn('No se encontraron elementos para el efecto ripple (.nav-link, .logout-button, .info-card)');
        return;
    }

    console.log(`Efecto ripple inicializado en ${elements.length} elementos`);
    elements.forEach(element => {
        element.addEventListener('click', (e) => {
            const ripple = document.createElement('span');
            const rect = element.getBoundingClientRect();
            const size = clamp(50, Math.max(rect.width, rect.height) * 2, 150);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;

            ripple.style.width = ripple.style.height = `${size}px`;
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            ripple.style.position = 'absolute';
            ripple.style.borderRadius = '50%';
            ripple.style.background = element.classList.contains('nav-link') && window.innerWidth <= 768 
                ? 'rgba(200, 200, 200, 0.4)' 
                : 'rgba(255, 255, 255, 0.3)';
            ripple.style.transform = 'scale(0)';
            ripple.style.animation = 'ripple 0.6s ease-out';
            ripple.style.pointerEvents = 'none';
            ripple.style.zIndex = '1';

            element.appendChild(ripple);
            setTimeout(() => ripple.remove(), 600);
            console.log(`Ripple aplicado en elemento: ${element.className}`);
        });
    });
}

// Utilidad clamp para JS
function clamp(min, val, max) {
    return Math.min(Math.max(val, min), max);
}

// === Desplazamiento Suave a Secciones ===
function initializeScrollToSection() {
    const links = document.querySelectorAll('.nav-link');

    if (links.length === 0) {
        console.warn('No se encontraron enlaces .nav-link para desplazamiento');
        return;
    }

    console.log(`Desplazamiento suave inicializado en ${links.length} enlaces`);
    links.forEach(link => {
        link.addEventListener('click', (e) => {
            const href = link.getAttribute('href');
            if (href && href.startsWith('#')) {
                e.preventDefault();
                const targetId = href.substring(1);
                const targetSection = document.getElementById(targetId);

                if (!targetSection) {
                    console.error(`Sección objetivo #${targetId} no encontrada`);
                    return;
                }

                const headerHeight = document.querySelector('.student-header')?.offsetHeight || 0;
                const targetPosition = targetSection.getBoundingClientRect().top + window.pageYOffset - headerHeight;

                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });
                console.log(`Desplazamiento a sección: ${targetId}`);

                const sidebar = document.querySelector('.student-nav');
                const menuToggle = document.querySelector('.menu-toggle');
                if (window.innerWidth <= 768 && sidebar?.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    menuToggle.textContent = '☰';
                    menuToggle.setAttribute('aria-expanded', 'false');
                    menuToggle.style.transform = 'rotate(0deg)';
                    console.log('Menú móvil cerrado tras desplazamiento');
                }
            }
        });
    });
}

// === Efectos Hover en Tarjetas y Tablas ===
function initializeHoverEffects() {
    const cards = document.querySelectorAll('.info-card');
    const tableRows = document.querySelectorAll('.student-table tbody tr');

    if (cards.length === 0) console.warn('No se encontraron .info-card para hover');
    if (tableRows.length === 0) console.warn('No se encontraron filas en .student-table para hover');

    console.log(`Hover inicializado en ${cards.length} tarjetas y ${tableRows.length} filas`);
    cards.forEach(card => {
        card.addEventListener('mouseenter', () => {
            card.style.transition = 'transform 0.3s ease, box-shadow 0.3s ease';
        });
        card.addEventListener('mouseleave', () => {
            card.style.transition = 'transform 0.3s ease, box-shadow 0.3s ease';
        });
    });

    tableRows.forEach(row => {
        row.addEventListener('mouseenter', () => {
            row.style.transition = 'background 0.3s ease, transform 0.3s ease';
        });
        row.addEventListener('mouseleave', () => {
            row.style.transition = 'background 0.3s ease, transform 0.3s ease';
        });
    });
}

// === Manejo de Notificaciones ===
function initializeNotificationHandler() {
    const notification = document.querySelector('.notification');
    if (!notification) {
        console.log('No hay notificaciones .notification presentes');
        return;
    }

    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => {
            notification.remove();
            console.log('Notificación eliminada');
        }, 400);
    }, 5000);
    console.log('Notificación mostrada');
}

// === Confirmación de Cierre de Sesión ===
function initializeLogoutConfirmation() {
    const logoutButton = document.querySelector('.logout-button');
    if (!logoutButton) {
        console.warn('Botón .logout-button no encontrado');
        return;
    }

    console.log('Confirmación de logout inicializada');
    logoutButton.addEventListener('click', (e) => {
        e.preventDefault();
        if (confirm('¿Estás seguro de que deseas cerrar sesión?')) {
            console.log('Cierre de sesión confirmado');
            window.location.href = logoutButton.getAttribute('href');
        } else {
            console.log('Cierre de sesión cancelado');
        }
    });
}

// === Alternancia de Tema Claro/Oscuro ===
function initializeThemeToggle() {
    const themeToggle = document.querySelector('.theme-toggle');
    const body = document.body;

    if (!themeToggle) {
        console.error('Error: Botón .theme-toggle no encontrado');
        return;
    }
    console.log('Botón .theme-toggle encontrado, inicializando tema');

    const savedTheme = localStorage.getItem('theme') || 'light';
    if (savedTheme === 'dark') {
        body.classList.add('dark-theme');
        themeToggle.textContent = '☀️';
        console.log('Tema cargado desde localStorage: oscuro');
    } else {
        body.classList.remove('dark-theme');
        themeToggle.textContent = '🌙';
        console.log('Tema cargado desde localStorage: claro');
    }

    themeToggle.addEventListener('click', () => {
        body.classList.toggle('dark-theme');
        const isDark = body.classList.contains('dark-theme');
        themeToggle.textContent = isDark ? '☀️' : '🌙';
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        console.log(`Tema cambiado a: ${isDark ? 'oscuro' : 'claro'} - Clase dark-theme: ${isDark}`);
        console.log(`Fondo actual: ${getComputedStyle(body).backgroundColor}`);
        console.log(`Texto actual: ${getComputedStyle(body).color}`);
    });
    console.log('Alternancia de tema inicializada');
}

// === Función de debounce para optimizar eventos ===
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// === Estilos Dinámicos para Animaciones ===
const styleSheet = document.createElement('style');
styleSheet.textContent = `
    .hidden {
        opacity: 0;
        transform: translateY(30px);
    }
    .visible {
        opacity: 1;
        transform: translateY(0);
        transition: opacity 0.5s ease, transform 0.5s ease;
    }
    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(styleSheet);
console.log('Estilos dinámicos para animaciones añadidos');

// === Registro de clics en enlaces para depuración ===
document.querySelectorAll('.nav-link').forEach(link => {
    link.addEventListener('click', (e) => {
        console.log(`Navegación iniciada a: ${e.target.getAttribute('href')}`);
    });
});

console.log('JavaScript inicializado correctamente');