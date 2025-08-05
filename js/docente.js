// @ts-nocheck
function validateForm(event) {
    console.log('Validando formulario');
    const inputs = document.querySelectorAll('#tablaCalificaciones input[type="number"]');
    let isValid = true;

    inputs.forEach(input => {
        const value = parseFloat(input.value) || 0;
        if (value < 0 || value > 10) {
            isValid = false;
            alert(`La calificación o penalización en "${input.name}" debe estar entre 0 y 10.`);
            event.preventDefault();
            return false;
        }
    });

    if (!isValid) {
        event.preventDefault();
        return false;
    }

    // Mensaje en consola si la validación es exitosa
    console.log('Calificaciones guardadas exitosamente');
    return true;
}
//Inicializar 
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM cargado, ejecutando inicialización');

    // === Funciones de Navegación ===
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.querySelector('.menu-toggle');
        const overlay = document.querySelector('.nav-overlay');
        const body = document.body;

        if (sidebar) {
            sidebar.classList.toggle('docente-header__nav--active');
            menuToggle.classList.toggle('active');
            overlay.classList.toggle('active');
            body.classList.toggle('no-scroll');
        } else {
            console.error('Sidebar no encontrado');
        }
    }

    // Inicialización de la gráfica de calificaciones
    const gradeStats = window.gradeStats || { aprobados: 0, reprobados: 0 };
    const gradeChartCanvas = document.getElementById('gradeChart');
    if (gradeChartCanvas && gradeStats && typeof gradeStats === 'object' && 'aprobados' in gradeStats && 'reprobados' in gradeStats) {
        console.log('Datos de gradeStats:', gradeStats); // Para depuración
        new Chart(gradeChartCanvas, {
            type: 'pie',
            data: {
                labels: ['Aprobados ', 'Reprobados'],
                datasets: [{
                    data: [parseInt(gradeStats.aprobados) || 0, parseInt(gradeStats.reprobados) || 0],
                    backgroundColor: ['rgba(76, 175, 80, 0.7)', 'rgba(244, 67, 54, 0.7)'],
                    borderColor: ['rgba(76, 175, 80, 1)', 'rgba(244, 67, 54, 1)'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true, // Mantener proporción para evitar deformaciones
                aspectRatio: 1, // Proporción 1:1 para una gráfica circular consistente
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    title: {
                        display: true,
                        text: 'Proporción de Aprobados y Reprobados'
                    }
                }
            }
        });
    } else {
        console.log('No hay datos válidos para la gráfica de calificaciones o el canvas no se encontró');
    }


    function setupNavLinks() {
        const navLinks = document.querySelectorAll('.docente-nav .nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const href = link.getAttribute('href');
                console.log('Clic en enlace:', href);
                if (href) {
                    const sidebar = document.getElementById('sidebar');
                    const menuToggle = document.querySelector('.menu-toggle');
                    const overlay = document.querySelector('.nav-overlay');
                    const body = document.body;

                    if (sidebar) {
                        sidebar.classList.remove('docente-header__nav--active');
                        menuToggle.classList.remove('active');
                        overlay.classList.remove('active');
                        body.classList.remove('no-scroll');
                    }

                    const section = document.querySelector(href);
                    if (section) {
                        section.scrollIntoView({ behavior: 'smooth' });
                    } else {
                        window.location.href = href;
                    }
                }
            });
        });

        const logoutButton = document.querySelector('.logout-button');
        if (logoutButton) {
            logoutButton.addEventListener('click', (e) => {
                e.preventDefault();
                console.log('Clic en Cerrar Sesión');
                window.location.href = logoutButton.getAttribute('href');
            });
        }
    }

    // === Funciones de Calificaciones ===

    function updateCalificaciones(row) {
        const cells = row.getElementsByTagName('td');
        if (cells.length >= 4) {
            const calificacion = parseFloat(cells[1].querySelector('input')?.value) || 0;
            const penalizacion = parseFloat(cells[2].querySelector('input')?.value) || 0;
            const total = calificacion - penalizacion;
            cells[3].textContent = isNaN(total) ? '0.0' : total.toFixed(1);
            const estadoCell = cells[4].querySelector('.calificacion-status');
            if (estadoCell) {
                estadoCell.className = 'calificacion-status ' + (total >= 6 ? 'aprobado' : 'reprobado');
                estadoCell.textContent = total >= 6 ? 'Aprobado' : 'Reprobado';
            }
        }
    }


           // Inicializar eventos para calificaciones
    const table = document.getElementById('tablaCalificaciones');
    if (table) {
        const inputs = table.querySelectorAll('input[type="number"]');
        inputs.forEach(input => {
            input.addEventListener('input', (event) => {
                const row = event.target.closest('tr');
                if (row) {
                    updateCalificaciones(row);
                }
            });

            // Prevenir submit con Enter a nivel de input
            input.addEventListener('keypress', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    console.log('Formulario detectado')
                    return false;

                }
            });
        });

        // Prevenir submit con Enter a nivel de formulario (respaldo)
        const form = table.closest('form');
        if (form) {
            form.addEventListener('keypress', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    console.log('Formulario detectado')
                    return false;
                }
            });
        }
    }

    // === Funciones de Notificaciones ===
    function showNotification(message, type = 'success') {
        console.log('Creando notificación:', message, 'Tipo:', type);
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);
        console.log('Notificación añadida al DOM:', notification);
        setTimeout(() => {
            notification.remove();
            console.log('Notificación eliminada:', message);
        }, 4000);
    }

    // === Funciones de Modales ===
    function setupModalEvents() {
        const modalButtons = document.querySelectorAll('[data-modal]');
        const closeModalButtons = document.querySelectorAll('.modal-close');
        const closeModalButtonEliminar = document.querySelectorAll('.modal-closes');
        const modals = document.querySelectorAll('.docente-modal');

        modalButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const modalId = button.getAttribute('data-modal');
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.add('show');
                }
            });
        });

        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-close')) {
                const modal = e.target.closest('.docente-modal');
                if (modal) {
                    modal.classList.remove('show');
                }
            }
        });

        closeModalButtonEliminar.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const modal = button.closest('.docente-modal');
                if (modal) {
                    modal.classList.remove('show');
                    showNotification('Eliminación cancelada', 'success');
                }
            });
        });

        modals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('show');
                }
            });
        });

        const createMateriaForm = document.querySelector('#modal-crear-materia form');
        if (createMateriaForm) {
            createMateriaForm.addEventListener('submit', (e) => {
                console.log('Formulario enviado con método:', createMateriaForm.method);
                console.log('Datos del formulario:', new FormData(createMateriaForm));
            });
        }
    }

    // === Inicialización ===
    const menuToggle = document.querySelector('.menu-toggle');
    if (menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    } else {
        console.error('Botón .menu-toggle no encontrado');
    }

    setupNavLinks();
    setupModalEvents();

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('grupo_calif') || urlParams.has('parcial')) {
        window.location.hash = '#calificaciones-section';
    }

    const hash = window.location.hash.split('?')[0];
    console.log('Hash limpio detectado:', hash);

    let notificationParams;
    if (window.location.hash.includes('?')) {
        const hashParams = window.location.hash.split('?')[1];
        notificationParams = new URLSearchParams(hashParams);
    } else {
        notificationParams = new URLSearchParams(window.location.search);
    }
    const message = notificationParams.get('message');
    const type = notificationParams.get('type') || 'success';

    console.log('URL completa:', window.location.href);
    console.log('Parámetro message:', message);
    console.log('Parámetro type:', type);

    if (message) {
        console.log('Mostrando notificación con mensaje:', message, 'y tipo:', type);
        showNotification(decodeURIComponent(message), type);
        history.replaceState({}, document.title, window.location.pathname + window.location.search + hash);
    } else {
        console.log('No se encontró mensaje en la URL');
    }

    if (hash) {
        const section = document.querySelector(hash);
        if (section) {
            console.log(`Desplazando a la sección: ${hash}`);
            section.scrollIntoView({ behavior: 'smooth' });
        } else {
            console.warn(`Sección no encontrada: ${hash}`);
        }
    }

    const sections = document.querySelectorAll('.docente-section');
    console.log(`Número de secciones encontradas: ${sections.length}`);
    sections.forEach(section => {
        const id = section.getAttribute('id');
        const isVisible = window.getComputedStyle(section).display !== 'none';
        console.log(`Sección ${id}: ${isVisible ? 'visible' : 'oculta'}`);
    });

    if (window.location.search.includes('semestre_finalizado')) {
        showNotification('Semestre finalizado. Revisa el reporte de suma de parciales.', 'success');
    }
  
});