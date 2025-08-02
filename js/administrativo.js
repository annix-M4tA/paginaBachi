// Utilidades de Registro
const log = (type = 'INFO', message) => {
    const timestamp = new Date().toISOString();
    const safeType = typeof type === 'string' ? type.toUpperCase() : 'INFO';
    console.log(`[${safeType}] ${message} - ${timestamp}`);
};

const logError = (message, error) => {
    const timestamp = new Date().toISOString();
    console.error(`[ERROR] ${message}: ${error.message || error} - ${timestamp}`);
};

// Utilidades Generales
const debounce = (func, wait) => {
    let timeout;
    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
};

const $ = (selector, context = document) => context.querySelector(selector);
const $$ = (selector, context = document) => Array.from(context.querySelectorAll(selector));

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

// Tabs
const TabModule = {
    init() {
        $$('.admin-tab-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const tabId = link.getAttribute('data-tab');
                this.switchTab(tabId);
            });
        });
    },
    switchTab(tabId) {
        $$('.admin-tab').forEach(tab => tab.style.display = 'none');
        $$('.admin-tab-link').forEach(link => link.classList.remove('active'));
        const tab = $(`#${tabId}`);
        if (tab) {
            tab.style.display = 'block';
            $(`[data-tab="${tabId}"]`).classList.add('active');
            log('INFO', `Pestaña cambiada a: ${tabId}`);
            if (tabId === 'reportes') ReporteModule.fetchReportes();
        } else {
            logError(`No se encontró la pestaña ${tabId}`);
        }
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
        } else {
            logError(`Modal ${modalId} no encontrado`);
        }
    },
    close(modalId) {
        const modal = $(`#${modalId}`);
        if (modal) {
            modal.classList.remove('active');
            document.body.classList.remove('modal-open');
            log('INFO', `Modal cerrado`);
        }
    },
    closeAll() {
        $$('.admin-modal').forEach(modal => modal.classList.remove('active'));
        document.body.classList.remove('modal-open');
        log('INFO', 'Todos los modales cerrados');
    }
};

// Formularios AJAX
const FormModule = {
    async handleAjaxSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const modalId = form.closest('.admin-modal').id;
        const action = formData.get('action');

        log('INFO', `Enviando formulario ${form.id} con acción ${action} - Datos: ${JSON.stringify(Object.fromEntries(formData))}`);

        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();

            if (data.status === 'success') {
                NotificationModule.show(data.message, 'success');
                ModalModule.close(modalId);

                switch (form.id) {
                    case 'usuario-form':
                        UserModule.updateUserTable(data.data);
                        break;
                    case 'crear-aviso-form':
                    case 'editar-aviso-form':
                        AvisoModule.updateAvisosTable(data.data);
                        DashboardModule.updateDashboard({ avisos: [data.data] });
                        break;
                    case 'crear-evento-form':
                    case 'editar-evento-form':
                        EventoModule.updateEventosTable(data.data);
                        DashboardModule.fetchDashboardData();
                        break;
                    case 'crear-semestre-form':
                    case 'editar-semestre-form':
                        SemestreModule.updateSemestresTable(data.data);
                        DashboardModule.fetchDashboardData();
                        break;
                    case 'crear-materia-form':
                    case 'editar-materia-form':
                        MateriaModule.updateMateriasTable(data.data);
                        break;
                    case 'crear-grupo-form':
                    case 'editar-grupo-form':
                        GrupoModule.updateGruposTable(data.data);
                        break;
                    case 'crear-generacion-form':
                    case 'editar-generacion-form':
                        GeneracionModule.updateGeneracionesTable(data.data);
                        break;
                    case 'crear-parcial-form':
                    case 'editar-parcial-form':
                        ParcialModule.updateParcialesTable(data.data);
                        break;
                    case 'crear-calificacion-form':
                        CalificacionModule.updateCalificacionesTable(data.data);
                        break;
                    case 'procesar-solicitud-form':
                        SolicitudModule.updateSolicitudesTable(data.data);
                        DashboardModule.fetchDashboardData();
                        break;
                }
            } else {
                const errorMessage = data.message || 'Error desconocido';
                const errorDetails = data.errors ? ` Detalles: ${JSON.stringify(data.errors)}` : '';
                NotificationModule.show(`${errorMessage}${errorDetails}`, 'error');
                if (data.errors) this.showFormErrors(form, data.errors);
            }
        } catch (error) {
            logError('Error en la solicitud AJAX', error);
            NotificationModule.show(`Error en la solicitud: ${error.message}`, 'error');
        }
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
    initFormEnhancements() {
        $$('.admin-form-group input, .admin-form-group textarea, .admin-form-group select').forEach(input => {
            const floatingLabel = $('.floating-label', input.parentNode);
            if (!floatingLabel) return;
            if (input.value) input.classList.add('not-empty');
            input.addEventListener('input', () => input.classList.toggle('not-empty', !!input.value));
        });
    }
};

// Usuarios
const UserModule = {
    usersData: window.usersData || {},
    openUserModal(action, userId = null) {
        const modal = $('#modal-usuario');
        const form = modal.querySelector('#usuario-form');
        const title = $('#modal-usuario-title');
        form.reset();
        form.querySelectorAll('.admin-form-group.error').forEach(group => group.classList.remove('error'));
        form.querySelectorAll('.error-message').forEach(span => span.remove());

        const contrasenaGroup = $('#contrasena-group');
        $('#alumno-fields').style.display = 'none';
        $('#docente-fields').style.display = 'none';
        $('#administrativo-fields').style.display = 'none';

        if (action === 'create') {
            title.textContent = 'Crear Nuevo Usuario';
            form.querySelector('#user-action').value = 'create';
            contrasenaGroup.style.display = 'block';
            $('#user-contrasena').setAttribute('required', '');
            $('#user-tipo').value = ''; // Resetear tipo
        } else if (action === 'edit' && userId) {
            title.textContent = 'Editar Usuario';
            form.querySelector('#user-action').value = 'update';
            form.querySelector('#user-id').value = userId;
            contrasenaGroup.style.display = 'none';
            $('#user-contrasena').removeAttribute('required');
            const user = this.usersData[userId];
            if (user) {
                $('#user-correo').value = user.correo;
                $('#user-nombre_usuario').value = user.nombre_usuario;
                $('#user-nombre_completo').value = user.nombre_completo || '';
                $('#user-telefono').value = user.telefono || '';
                $('#user-tipo').value = user.tipo;
                $('#user-estado').value = user.estado;
                if (user.tipo === 'alumno') {
                    $('#alumno-fields').style.display = 'block';
                    $('#user-nia').value = user.nia || '';
                    $('#user-generacion_id').value = user.generacion_id || '';
                } else if (user.tipo === 'docente') {
                    $('#docente-fields').style.display = 'block';
                    $('#user-especialidad').value = user.especialidad || '';
                } else if (user.tipo === 'administrativo') {
                    $('#administrativo-fields').style.display = 'block';
                    $('#user-departamento').value = user.departamento || '';
                }
            }
        }

        ModalModule.open('modal-usuario');
        log('INFO', `Modal de usuario abierto para ${action}${userId ? ' con ID ' + userId : ''}`);
    },
    async deleteUser(userId) {
        if (!confirm('¿Estás seguro de desactivar este usuario? Se marcará como inactivo.')) return;
        log('INFO', `[${userId}] Desactivando usuario`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=deactivate&id=${userId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                const row = $(`#usuarios-table tr[data-id="${userId}"]`);
                if (row) row.remove();
                NotificationModule.show('Usuario desactivado correctamente', 'success');
                DashboardModule.fetchDashboardData();
                delete this.usersData[userId];
            } else {
                throw new Error(data.message || 'Error al desactivar el usuario');
            }
        } catch (error) {
            logError(`Error al desactivar usuario ${userId}`, error);
            NotificationModule.show(`Error al desactivar el usuario: ${error.message}`, 'error');
        }
    },
    updateUserTable(userData) {
        const tableBody = $('#usuarios-table tbody');
        if (!tableBody) return logError('Tabla de usuarios no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-id="${userData.id}"]`);
        const rowHTML = `
            <tr data-id="${userData.id}">
                <td data-label="Correo">${userData.correo}</td>
                <td data-label="Nombre">${userData.nombre_usuario}</td>
                <td data-label="Nombre Completo">${userData.nombre_completo}</td>
                <td data-label="Tipo">${userData.tipo}</td>
                <td data-label="Estado">${userData.estado}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="edit" data-id="${userData.id}" aria-label="Editar usuario"><i class="fas fa-edit"></i></button>
                    <button class="admin-button" data-action="deactivate" data-id="${userData.id}" aria-label="Desactivar usuario"><i class="fas fa-ban"></i></button>
                </td>
            </tr>`;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
        this.usersData[userData.id] = userData;
        DashboardModule.fetchDashboardData();
    }
};

// Avisos
const AvisoModule = {
    avisosData: JSON.parse($('#avisos-data')?.textContent || '{}'),
    updateAvisosTable(avisoData) {
        this.avisosData[avisoData.id] = avisoData;
        const tableBody = $('#avisos-table tbody');
        if (!tableBody) return logError('Tabla de avisos no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-id="${avisoData.id}"]`);
        const rowHTML = `
            <tr data-id="${avisoData.id}">
                <td data-label="ID">${avisoData.id}</td>
                <td data-label="Título">${avisoData.titulo}</td>
                <td data-label="Contenido">${avisoData.contenido}</td>
                <td data-label="Prioridad">${avisoData.prioridad}</td>
                <td data-label="Fecha Creación">${avisoData.fecha_creacion || new Date().toISOString().slice(0, 19).replace('T', ' ')}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="edit" data-id="${avisoData.id}" aria-label="Editar aviso"><i class="fas fa-edit"></i></button>
                    <button class="admin-button" data-action="delete" data-id="${avisoData.id}" aria-label="Eliminar aviso"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
    },
    openEditAvisoModal(avisoId) {
        const aviso = this.avisosData[avisoId];
        if (!aviso) return logError(`Aviso ${avisoId} no encontrado en avisosData`);

        const form = $('#editar-aviso-form');
        form.querySelector('#edit-aviso-id').value = avisoId;
        form.querySelector('#edit-aviso-titulo').value = aviso.titulo;
        form.querySelector('#edit-aviso-contenido').value = aviso.contenido;
        form.querySelector('#edit-aviso-prioridad').value = aviso.prioridad;

        ModalModule.open('modal-editar-aviso');
        log('INFO', `Editando aviso ID: ${avisoId}`);
    },
    async deleteAviso(avisoId) {
        if (!confirm('¿Estás seguro de eliminar este aviso?')) return;
        log('INFO', `[${avisoId}] Eliminando aviso`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_aviso&id=${avisoId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                $(`#avisos-table tr[data-id="${avisoId}"]`)?.remove();
                delete this.avisosData[avisoId];
                NotificationModule.show('Aviso eliminado correctamente', 'success');
                DashboardModule.fetchDashboardData();
            } else {
                throw new Error(data.message || 'Error al eliminar el aviso');
            }
        } catch (error) {
            logError(`Error al eliminar aviso ${avisoId}`, error);
            NotificationModule.show('Error al eliminar el aviso', 'error');
        }
    }
};

// Eventos
const EventoModule = {
    eventosData: JSON.parse($('#eventos-data')?.textContent || '{}'),
    updateEventosTable(eventoData) {
        this.eventosData[eventoData.id] = eventoData;
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
    openEditModal(eventoId) {
        const evento = this.eventosData[eventoId];
        if (!evento) return logError(`Evento ${eventoId} no encontrado en eventosData`);

        const form = $('#editar-evento-form');
        form.querySelector('#edit-evento-id').value = eventoId;
        form.querySelector('#edit-evento-titulo').value = evento.titulo;
        form.querySelector('#edit-evento-fecha').value = evento.fecha;
        form.querySelector('#edit-evento-descripcion').value = evento.descripcion;
        form.querySelector('#edit-evento-tipo').value = evento.tipo_evento;

        ModalModule.open('modal-editar-evento');
        log('INFO', `Editando evento ID: ${eventoId}`);
    },
    async deleteEvento(eventoId) {
        if (!confirm('¿Estás seguro de eliminar este evento?')) return;
        log('INFO', `[${eventoId}] Eliminando evento`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_evento&id=${eventoId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                $(`#eventos-table tr[data-id="${eventoId}"]`)?.remove();
                delete this.eventosData[eventoId];
                NotificationModule.show('Evento eliminado correctamente', 'success');
                DashboardModule.fetchDashboardData();
            } else {
                throw new Error(data.message || 'Error al eliminar el evento');
            }
        } catch (error) {
            logError(`Error al eliminar evento ${eventoId}`, error);
            NotificationModule.show(`Error al eliminar el evento: ${error.message}`, 'error');
        }
    }
};

// Semestres
const SemestreModule = {
    semestresData: JSON.parse($('#semestres-data')?.textContent || '{}'),
    updateSemestresTable(semestreData) {
        this.semestresData[semestreData.id] = semestreData;
        const tableBody = $('#semestres-table tbody');
        if (!tableBody) return logError('Tabla de semestres no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-id="${semestreData.id}"]`);
        const rowHTML = `
            <tr data-id="${semestreData.id}" data-generacion-id="${semestreData.generacion_id}">
                <td data-label="ID">${semestreData.id}</td>
                <td data-label="Número">${semestreData.numero}</td>
                <td data-label="Generación">${semestreData.generacion}</td>
                <td data-label="Fecha Inicio">${semestreData.fecha_inicio}</td>
                <td data-label="Fecha Fin">${semestreData.fecha_fin}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="edit" data-id="${semestreData.id}" aria-label="Editar semestre"><i class="fas fa-edit"></i></button>
                    <button class="admin-button" data-action="delete" data-id="${semestreData.id}" aria-label="Eliminar semestre"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
    },
    openEditModal(semestreId) {
        const semestre = this.semestresData[semestreId];
        if (!semestre) return logError(`Semestre ${semestreId} no encontrado en semestresData`);

        const form = $('#editar-semestre-form');
        form.querySelector('#edit-semestre-id').value = semestreId;
        form.querySelector('#edit-semestre-generacion_id').value = semestre.generacion_id;
        form.querySelector('#edit-semestre-fecha_inicio').value = semestre.fecha_inicio;
        form.querySelector('#edit-semestre-fecha_fin').value = semestre.fecha_fin;

        ModalModule.open('modal-editar-semestre');
        log('INFO', `Editando semestre ID: ${semestreId}`);
    },
    async deleteSemestre(semestreId) {
        if (!confirm('¿Estás seguro de eliminar este semestre?')) return;
        log('INFO', `[${semestreId}] Eliminando semestre`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_semestre&id=${semestreId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                $(`#semestres-table tr[data-id="${semestreId}"]`)?.remove();
                delete this.semestresData[semestreId];
                NotificationModule.show('Semestre eliminado correctamente', 'success');
                DashboardModule.fetchDashboardData();
            } else {
                throw new Error(data.message || 'Error al eliminar el semestre');
            }
        } catch (error) {
            logError(`Error al eliminar semestre ${semestreId}`, error);
            NotificationModule.show(`Error al eliminar el semestre: ${error.message}`, 'error');
        }
    }
};

// Materias
const MateriaModule = {
    materiasData: JSON.parse($('#materias-data')?.textContent || '{}'),
    updateMateriasTable(materiaData) {
        this.materiasData[materiaData.id] = materiaData;
        const tableBody = $('#materias-table tbody');
        if (!tableBody) return logError('Tabla de materias no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-id="${materiaData.id}"]`);
        const rowHTML = `
            <tr data-id="${materiaData.id}">
                <td data-label="ID">${materiaData.id}</td>
                <td data-label="Nombre">${materiaData.nombre}</td>
                <td data-label="Descripción">${materiaData.descripcion || ''}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="edit" data-id="${materiaData.id}" aria-label="Editar materia"><i class="fas fa-edit"></i></button>
                    <button class="admin-button" data-action="delete" data-id="${materiaData.id}" aria-label="Eliminar materia"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
    },
    openEditModal(materiaId) {
        const materia = this.materiasData[materiaId];
        if (!materia) return logError(`Materia ${materiaId} no encontrada en materiasData`);

        const form = $('#editar-materia-form');
        form.querySelector('#edit-materia-id').value = materiaId;
        form.querySelector('#edit-materia-nombre').value = materia.nombre;
        form.querySelector('#edit-materia-descripcion').value = materia.descripcion || '';

        ModalModule.open('modal-editar-materia');
        log('INFO', `Editando materia ID: ${materiaId}`);
    },
    async deleteMateria(materiaId) {
        if (!confirm('¿Estás seguro de eliminar esta materia?')) return;
        log('INFO', `[${materiaId}] Eliminando materia`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_materia&id=${materiaId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                $(`#materias-table tr[data-id="${materiaId}"]`)?.remove();
                delete this.materiasData[materiaId];
                NotificationModule.show('Materia eliminada correctamente', 'success');
            } else {
                throw new Error(data.message || 'Error al eliminar la materia');
            }
        } catch (error) {
            logError(`Error al eliminar materia ${materiaId}`, error);
            NotificationModule.show(`Error al eliminar la materia: ${error.message}`, 'error');
        }
    }
};

// Grupos
const GrupoModule = {
    gruposData: JSON.parse($('#grupos-data')?.textContent || '{}'),
    updateGruposTable(grupoData) {
        this.gruposData[grupoData.id] = grupoData;
        const tableBody = $('#grupos-table tbody');
        if (!tableBody) return logError('Tabla de grupos no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-id="${grupoData.id}"]`);
        const rowHTML = `
            <tr data-id="${grupoData.id}" data-materia-id="${grupoData.materia_id}" data-semestre-id="${grupoData.semestre_id}" data-docente-id="${grupoData.docente_id}">
                <td data-label="ID">${grupoData.id}</td>
                <td data-label="Materia">${grupoData.materia}</td>
                <td data-label="Semestre">${grupoData.semestre}</td>
                <td data-label="Letra">${grupoData.letra_grupo}</td>
                <td data-label="Grado">${grupoData.grado}</td>
                <td data-label="Docente">${grupoData.docente}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="edit" data-id="${grupoData.id}" aria-label="Editar grupo"><i class="fas fa-edit"></i></button>
                    <button class="admin-button" data-action="delete" data-id="${grupoData.id}" aria-label="Eliminar grupo"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
    },
    openEditModal(grupoId) {
        const grupo = this.gruposData[grupoId];
        if (!grupo) return logError(`Grupo ${grupoId} no encontrado en gruposData`);

        const form = $('#editar-grupo-form');
        form.querySelector('#edit-grupo-id').value = grupoId;
        form.querySelector('#edit-grupo-materia_id').value = grupo.materia_id;
        form.querySelector('#edit-grupo-semestre_id').value = grupo.semestre_id;
        form.querySelector('#edit-grupo-letra_grupo').value = grupo.letra_grupo;
        form.querySelector('#edit-grupo-grado').value = grupo.grado;
        form.querySelector('#edit-grupo-docente_id').value = grupo.docente_id;

        ModalModule.open('modal-editar-grupo');
        log('INFO', `Editando grupo ID: ${grupoId}`);
    },
    async deleteGrupo(grupoId) {
        if (!confirm('¿Estás seguro de eliminar este grupo?')) return;
        log('INFO', `[${grupoId}] Eliminando grupo`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_grupo&id=${grupoId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                $(`#grupos-table tr[data-id="${grupoId}"]`)?.remove();
                delete this.gruposData[grupoId];
                NotificationModule.show('Grupo eliminado correctamente', 'success');
            } else {
                throw new Error(data.message || 'Error al eliminar el grupo');
            }
        } catch (error) {
            logError(`Error al eliminar grupo ${grupoId}`, error);
            NotificationModule.show(`Error al eliminar el grupo: ${error.message}`, 'error');
        }
    }
};

// Generaciones
const GeneracionModule = {
    generacionesData: JSON.parse($('#generaciones-data')?.textContent || '{}'),
    updateGeneracionesTable(generacionData) {
        this.generacionesData[generacionData.id] = generacionData;
        const tableBody = $('#generaciones-table tbody');
        if (!tableBody) return logError('Tabla de generaciones no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-id="${generacionData.id}"]`);
        const rowHTML = `
            <tr data-id="${generacionData.id}">
                <td data-label="ID">${generacionData.id}</td>
                <td data-label="Nombre">${generacionData.nombre}</td>
                <td data-label="Fecha Inicio">${generacionData.fecha_inicio}</td>
                <td data-label="Fecha Fin">${generacionData.fecha_fin}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="edit" data-id="${generacionData.id}" aria-label="Editar generación"><i class="fas fa-edit"></i></button>
                    <button class="admin-button" data-action="delete" data-id="${generacionData.id}" aria-label="Eliminar generación"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
    },
    openEditModal(generacionId) {
        const generacion = this.generacionesData[generacionId];
        if (!generacion) return logError(`Generación ${generacionId} no encontrada en generacionesData`);

        const form = $('#editar-generacion-form');
        form.querySelector('#edit-generacion-id').value = generacionId;
        form.querySelector('#edit-generacion-nombre').value = generacion.nombre;
        form.querySelector('#edit-generacion-fecha_inicio').value = generacion.fecha_inicio;
        form.querySelector('#edit-generacion-fecha_fin').value = generacion.fecha_fin;

        ModalModule.open('modal-editar-generacion');
        log('INFO', `Editando generación ID: ${generacionId}`);
    },
    async deleteGeneracion(generacionId) {
        if (!confirm('¿Estás seguro de eliminar esta generación?')) return;
        log('INFO', `[${generacionId}] Eliminando generación`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_generacion&id=${generacionId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                $(`#generaciones-table tr[data-id="${generacionId}"]`)?.remove();
                delete this.generacionesData[generacionId];
                NotificationModule.show('Generación eliminada correctamente', 'success');
            } else {
                throw new Error(data.message || 'Error al eliminar la generación');
            }
        } catch (error) {
            logError(`Error al eliminar generación ${generacionId}`, error);
            NotificationModule.show(`Error al eliminar la generación: ${error.message}`, 'error');
        }
    }
};

// Parciales
const ParcialModule = {
    parcialesData: JSON.parse($('#parciales-data')?.textContent || '{}'),
    updateParcialesTable(parcialData) {
        this.parcialesData[parcialData.id] = parcialData;
        const tableBody = $('#parciales-table tbody');
        if (!tableBody) return logError('Tabla de parciales no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-id="${parcialData.id}"]`);
        const rowHTML = `
            <tr data-id="${parcialData.id}" data-grupo-id="${parcialData.grupo_id}">
                <td data-label="ID">${parcialData.id}</td>
                <td data-label="Número">${parcialData.numero_parcial}</td>
                <td data-label="Grupo">${parcialData.letra_grupo}</td>
                <td data-label="Materia">${parcialData.materia}</td>
                <td data-label="Semestre">${parcialData.semestre}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="edit" data-id="${parcialData.id}" aria-label="Editar parcial"><i class="fas fa-edit"></i></button>
                    <button class="admin-button" data-action="delete" data-id="${parcialData.id}" aria-label="Eliminar parcial"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
    },
    openEditModal(parcialId) {
        const parcial = this.parcialesData[parcialId];
        if (!parcial) return logError(`Parcial ${parcialId} no encontrado en parcialesData`);

        const form = $('#editar-parcial-form');
        form.querySelector('#edit-parcial-id').value = parcialId;
        form.querySelector('#edit-parcial-grupo_id').value = parcial.grupo_id;
        form.querySelector('#edit-parcial-numero_parcial').value = parcial.numero_parcial;
        form.querySelector('#edit-parcial-fecha_inicio').value = parcial.fecha_inicio;
        form.querySelector('#edit-parcial-fecha_fin').value = parcial.fecha_fin;

        ModalModule.open('modal-editar-parcial');
        log('INFO', `Editando parcial ID: ${parcialId}`);
    },
    async deleteParcial(parcialId) {
        if (!confirm('¿Estás seguro de eliminar este parcial?')) return;
        log('INFO', `[${parcialId}] Eliminando parcial`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_parcial&id=${parcialId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                $(`#parciales-table tr[data-id="${parcialId}"]`)?.remove();
                delete this.parcialesData[parcialId];
                NotificationModule.show('Parcial eliminado correctamente', 'success');
            } else {
                throw new Error(data.message || 'Error al eliminar el parcial');
            }
        } catch (error) {
            logError(`Error al eliminar parcial ${parcialId}`, error);
            NotificationModule.show(`Error al eliminar el parcial: ${error.message}`, 'error');
        }
    }
};

// Calificaciones
const CalificacionModule = {
    calificacionesData: JSON.parse($('#calificaciones-data')?.textContent || '{}'),
    updateCalificacionesTable(calificacionData) {
        const key = `${calificacionData.alumno_id}-${calificacionData.parcial_id}`;
        this.calificacionesData[key] = calificacionData;
        const tableBody = $('#calificaciones-table tbody');
        if (!tableBody) return logError('Tabla de calificaciones no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-alumno-id="${calificacionData.alumno_id}"][data-parcial-id="${calificacionData.parcial_id}"]`);
        const rowHTML = `
            <tr data-alumno-id="${calificacionData.alumno_id}" data-parcial-id="${calificacionData.parcial_id}">
                <td data-label="Alumno">${calificacionData.alumno}</td>
                <td data-label="Parcial">${calificacionData.numero_parcial}</td>
                <td data-label="Calificación">${calificacionData.calificacion}</td>
                <td data-label="Penalización">${calificacionData.asistencia_penalizacion}</td>
                <td data-label="Total">${calificacionData.total}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="delete" data-alumno-id="${calificacionData.alumno_id}" data-parcial-id="${calificacionData.parcial_id}" aria-label="Eliminar calificación"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin', rowHTML);
    },
    async deleteCalificacion(alumnoId, parcialId) {
        if (!confirm('¿Estás seguro de eliminar esta calificación?')) return;
        log('INFO', `[${alumnoId}, ${parcialId}] Eliminando calificación`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_calificacion&alumno_id=${alumnoId}&parcial_id=${parcialId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                $(`#calificaciones-table tr[data-alumno-id="${alumnoId}"][data-parcial-id="${parcialId}"]`)?.remove();
                delete this.calificacionesData[`${alumnoId}-${parcialId}`];
                NotificationModule.show('Calificación eliminada correctamente', 'success');
            } else {
                throw new Error(data.message || 'Error al eliminar la calificación');
            }
        } catch (error) {
            logError(`Error al eliminar calificación [${alumnoId}, ${parcialId}]`, error);
            NotificationModule.show(`Error al eliminar la calificación: ${error.message}`, 'error');
        }
    }
};

// Solicitudes
const SolicitudModule = {
    solicitudesData: JSON.parse($('#solicitudes-data')?.textContent || '{}'),
    updateSolicitudesTable(solicitudData) {
        this.solicitudesData[solicitudData.id] = solicitudData;
        const tableBody = $('#solicitudes-table tbody');
        if (!tableBody) return logError('Tabla de solicitudes no encontrada');
        const existingRow = tableBody.querySelector(`tr[data-id="${solicitudData.id}"]`);
        const rowHTML = `
            <tr data-id="${solicitudData.id}">
                <td data-label="ID">${solicitudData.id}</td>
                <td data-label="Usuario">${solicitudData.nombre_usuario || solicitudData.correo}</td>
                <td data-label="Tipo">${solicitudData.tipo || 'Recuperación de contraseña'}</td>
                <td data-label="Estado">${solicitudData.estado}</td>
                <td data-label="Fecha Solicitud">${solicitudData.fecha_solicitud}</td>
                <td data-label="Acciones">
                    <button class="admin-button" data-action="process" data-id="${solicitudData.id}" aria-label="Procesar solicitud"><i class="fas fa-check"></i></button>
                </td>
            </tr>
        `;
        if (existingRow) existingRow.outerHTML = rowHTML;
        else tableBody.insertAdjacentHTML('afterbegin',

 rowHTML);
    },
    async processSolicitud(solicitudId) {
        const nuevaContrasena = prompt('Ingrese la nueva contraseña para el usuario (mínimo 8 caracteres):');
        if (!nuevaContrasena || nuevaContrasena.length < 8) {
            NotificationModule.show('La contraseña debe tener al menos 8 caracteres.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'process_solicitud');
        formData.append('solicitud_id', solicitudId);
        formData.append('nueva_contrasena', nuevaContrasena);
        formData.append('csrf_token', $('input[name="csrf_token"]').value);

        try {
            log('INFO', `Procesando solicitud ID: ${solicitudId}`);
            const response = await fetch('administrativo.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();

            if (data.status === 'success') {
                NotificationModule.show(data.message || 'Solicitud procesada correctamente', 'success');
                const row = $(`#solicitudes-table tr[data-id="${solicitudId}"]`);
                if (row) row.remove();
                delete this.solicitudesData[solicitudId];
                DashboardModule.fetchDashboardData();
            } else {
                throw new Error(data.message || 'Error al procesar la solicitud');
            }
        } catch (error) {
            logError(`Error al procesar solicitud ${solicitudId}`, error);
            NotificationModule.show(`Error al procesar la solicitud: ${error.message}`, 'error');
        }
    }
};

// Dashboard
const DashboardModule = {
    async fetchDashboardData() {
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=dashboard_data&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();
            if (data.status === 'success') {
                this.updateDashboard(data.data);
            } else {
                logError('Error al obtener datos del dashboard', data.message);
            }
        } catch (error) {
            logError('Error al actualizar datos del dashboard', error);
        }
    },
    updateDashboard(data) {
        $('#usuarios-activos').textContent = data.usuarios_activos || 0;
        $('#usuarios-inactivos').textContent = data.usuarios_inactivos || 0;
        $('#usuarios-bloqueados').textContent = data.usuarios_bloqueados || 0;
        $('#semestres-activos').textContent = data.semestres_activos || 0;
        $('#total-eventos').textContent = data.total_eventos || 0;
        $('#recuperaciones-pendientes').textContent = data.recuperaciones_pendientes || 0;

        const avisosList = $('#avisos-recientes');
        if (avisosList && data.avisos && Array.isArray(data.avisos)) {
            avisosList.innerHTML = data.avisos.length > 0
                ? data.avisos.slice(0, 3).map(aviso => `
                    <li class="prioridad-${aviso.prioridad}">
                        <strong>${aviso.titulo}</strong> - ${aviso.contenido}
                        <small>(${aviso.fecha_creacion})</small>
                    </li>`).join('')
                : '<li>No hay avisos recientes.</li>';
        }

        const eventosList = $('#eventos-proximos');
        if (eventosList && data.eventos_proximos && Array.isArray(data.eventos_proximos)) {
            eventosList.innerHTML = data.eventos_proximos.length > 0
                ? data.eventos_proximos.map(evento => `
                    <li class="tipo-${evento.tipo_evento}">
                        <strong>${evento.titulo}</strong> - ${evento.fecha}
                        <small>(${evento.tipo_evento})</small>
                    </li>`).join('')
                : '<li>No hay eventos próximos.</li>';
        }
    },
    initDashboard() {
        const dashboardData = $('#dashboard-data');
        if (dashboardData) {
            const data = JSON.parse(dashboardData.textContent);
            this.updateDashboard(data);
            log('INFO', 'Dashboard inicializado');
        } else {
            log('ERROR', 'Datos del dashboard no encontrados');
        }

        $$('.dashboard-card').forEach(card => {
            card.addEventListener('click', () => {
                const tab = card.getAttribute('data-tab');
                if (tab) TabModule.switchTab(tab);
            });
        });
    }
};

// Tema
const ThemeModule = {
    toggle() {
        const isDarkMode = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDarkMode ? 'dark' : 'light');
    },
    init() {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.body.classList.add('dark-mode');
        }
        $('.theme-toggle')?.addEventListener('click', this.toggle);
    }
};

// Sidebar y Panel
const AdminPanelModule = {
    init() {
        const sidebarToggle = $('.menu-toggle');
        const sidebar = $('.admin-sidebar');
        const container = $('.admin-container');
        if (sidebarToggle && sidebar && container) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                container.classList.toggle('collapsed');
            });
        }
        if (window.matchMedia("(hover: hover)").matches) {
            $$('.admin-card').forEach(card => {
                card.addEventListener('mouseenter', () => card.classList.add('admin-card--hover'));
                card.addEventListener('mouseleave', () => card.classList.remove('admin-card--hover'));
            });
        }
    }
};

// Menú Móvil
const MobileMenuModule = {
    init() {
        const mobileButton = $('#mobileMenuToggle');
        const sidebar = $('.admin-sidebar');
        if (mobileButton && sidebar) {
            mobileButton.addEventListener('click', () => {
                sidebar.classList.toggle('active');
            });
            window.addEventListener('resize', debounce(() => {
                if (window.innerWidth >= 768) sidebar.classList.remove('active');
            }, 200));
        }
    }
};
// Desplegables
const DropdownModule = {
    init() {
        $$('.admin-nav__dropdown').forEach(dropdown => {
            const trigger = $('.admin-nav__link', dropdown);
            const content = $('.admin-nav__dropdown-content', dropdown);
            if (trigger && content) {
                trigger.addEventListener('click', (e) => {
                    if (window.innerWidth <= 768) {
                        e.preventDefault();
                        content.classList.toggle('show');
                    }
                });
                document.addEventListener('click', (e) => {
                    if (!dropdown.contains(e.target)) content.classList.remove('show');
                });
            }
        });
    }
};

// Tablas y Búsqueda
const TableModule = {
    init() {
        $$('.admin-table-search').forEach(search => {
            const table = $(`#${search.dataset.table}`);
            if (table) {
                search.addEventListener('input', debounce(() => this.filterTable(table, search.value), 300));
            }
        });
    },
    filterTable(table, query) {
        const rows = $$('tbody tr', table);
        const terms = query.toLowerCase().split(' ');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = terms.every(term => text.includes(term)) ? '' : 'none';
        });
    }
};

// Filtro de Usuarios
const FilterModule = {
    init() {
        const form = $('#user-filter-form');
        if (form) {
            $$('select[name="filtro_estado"], select[name="filtro_tipo"], input[name="filtro_nombre"], input[name="filtro_nia"]', form).forEach(input => {
                input.addEventListener('change', () => form.submit());
            });
        }
    }
};

// Reportes
const ReporteModule = {
    reportesData: {},
    semestresData: {},

    async fetchSemestres() {
        try {
            const response = await fetch('get_semestres.php', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            this.semestresData = Object.fromEntries(data.map(semestre => [semestre.id, semestre]));
            this.updateSemestreSelect(data);
            log('INFO', 'Lista de semestres cargada');
        } catch (error) {
            logError('Error al cargar semestres', error);
            NotificationModule.show('Error al cargar los semestres', 'error');
        }
    },

    updateSemestreSelect(semestres) {
        const select = $('#semestre-select');
        if (!select) return logError('Selector de semestres no encontrado');
        select.innerHTML = '<option value="">Selecciona un semestre</option>' + 
            semestres.map(semestre => 
                `<option value="${semestre.numero}-${semestre.generacion_id}">${semestre.numero} (${semestre.generacion_id})</option>`
            ).join('');
    },

    updateReportLinks(semestre) {
        $$('.report-link').forEach(link => {
            const reporte = link.getAttribute('data-reporte');
            const baseUrl = 'controllers/controllerPDF.php';
            link.href = semestre ? `${baseUrl}?reporte=${reporte}&semestre=${semestre}` : '#';
            link.classList.toggle('disabled', !semestre);
        });
    },

    async fetchReportes() {
        try {
            const response = await fetch('get_reportes.php', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            this.reportesData = Object.fromEntries(data.map(reporte => [reporte.id, reporte]));
            this.updateReportesTable(data);
            log('INFO', 'Lista de reportes cargada');
        } catch (error) {
            logError('Error al cargar reportes', error);
            NotificationModule.show('Error al cargar los reportes', 'error');
        }
    },

    updateReportesTable(reportes) {
        const tableBody = $('#historial-reportes tbody');
        if (!tableBody) return logError('Tabla de reportes no encontrada');
        tableBody.innerHTML = reportes.length > 0
            ? reportes.map(reporte => `
                <tr data-id="${reporte.id}">
                    <td data-label="Tipo">${reporte.tipo_reporte}</td>
                    <td data-label="Fecha">${reporte.fecha_generado}</td>
                    <td data-label="Acciones">
                        <a href="${reporte.ruta_archivo}" download class="admin-button" aria-label="Descargar reporte"><i class="fas fa-download"></i></a>
                        <button class="admin-button" data-action="delete" data-id="${reporte.id}" aria-label="Eliminar reporte"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            `).join('')
            : '<tr><td colspan="3">No hay reportes generados.</td></tr>';
    },

    async deleteReporte(reporteId) {
        if (!confirm('¿Estás seguro de eliminar este reporte? Esto también eliminará el archivo asociado.')) return;
        log('INFO', `[${reporteId}] Eliminando reporte`);
        try {
            const response = await fetch('administrativo.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete_reporte&id=${reporteId}&csrf_token=${$('input[name="csrf_token"]').value}`
            });
            const data = await response.json();

            if (data.status === 'success') {
                const row = $(`#historial-reportes tr[data-id="${reporteId}"]`);
                if (row) row.remove();
                delete this.reportesData[reporteId];
                NotificationModule.show('Reporte eliminado correctamente', 'success');
            } else {
                throw new Error(data.message || 'Error al eliminar el reporte');
            }
        } catch (error) {
            logError(`Error al eliminar reporte ${reporteId}`, error);
            NotificationModule.show(`Error al eliminar el reporte: ${error.message}`, 'error');
        }
    }
};

// Inicialización
document.addEventListener('DOMContentLoaded', () => {
    TabModule.init();
    ThemeModule.init();
    DashboardModule.initDashboard();
    AdminPanelModule.init();
    MobileMenuModule.init();
    DropdownModule.init();
    TableModule.init();
    FormModule.initFormEnhancements();
    FilterModule.init();

    $$('#usuario-form, #crear-aviso-form, #editar-aviso-form, #crear-evento-form, #editar-evento-form, #crear-semestre-form, #editar-semestre-form, #crear-materia-form, #editar-materia-form, #crear-grupo-form, #editar-grupo-form, #crear-generacion-form, #editar-generacion-form, #crear-parcial-form, #editar-parcial-form, #crear-calificacion-form, #procesar-solicitud-form').forEach(form => {
        form.addEventListener('submit', FormModule.handleAjaxSubmit.bind(FormModule));
    });

    $('#user-tipo')?.addEventListener('change', (e) => {
        $('#alumno-fields').style.display = e.target.value === 'alumno' ? 'block' : 'none';
        $('#docente-fields').style.display = e.target.value === 'docente' ? 'block' : 'none';
        $('#administrativo-fields').style.display = e.target.value === 'administrativo' ? 'block' : 'none';
    });

    ReporteModule.fetchSemestres();
    $('#semestre-select')?.addEventListener('change', (e) => {
        const semestre = e.target.value;
        ReporteModule.updateReportLinks(semestre);
        log('INFO', `Semestre seleccionado: ${semestre}`);
    });
   
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('#sidebar');
    const navOverlay = document.querySelector('.nav-overlay');

    menuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
        navOverlay.classList.toggle('active');
    });

    navOverlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        navOverlay.classList.remove('active');
    });


    const handleGlobalClicks = (e) => {
        const modalBtn = e.target.closest('[data-modal]');
        if (modalBtn) {
            const modalId = modalBtn.getAttribute('data-modal');
            const action = modalBtn.getAttribute('data-action') || 'create';
            if (modalId === 'modal-usuario') {
                UserModule.openUserModal(action);
            } else {
                ModalModule.open(modalId);
            }
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
            const alumnoId = actionBtn.getAttribute('data-alumno-id');
            const parcialId = actionBtn.getAttribute('data-parcial-id');

            if (action === 'edit' && actionBtn.closest('#usuarios-table')) {
                UserModule.openUserModal('edit', id);
            } else if (action === 'deactivate' && actionBtn.closest('#usuarios-table')) {
                UserModule.deleteUser(id);
            } else if (action === 'edit' && actionBtn.closest('#avisos-table')) {
                AvisoModule.openEditAvisoModal(id);
            } else if (action === 'delete' && actionBtn.closest('#avisos-table')) {
                AvisoModule.deleteAviso(id);
            } else if (action === 'edit' && actionBtn.closest('#eventos-table')) {
                EventoModule.openEditModal(id);
            } else if (action === 'delete' && actionBtn.closest('#eventos-table')) {
                EventoModule.deleteEvento(id);
            } else if (action === 'edit' && actionBtn.closest('#semestres-table')) {
                SemestreModule.openEditModal(id);
            } else if (action === 'delete' && actionBtn.closest('#semestres-table')) {
                SemestreModule.deleteSemestre(id);
            } else if (action === 'edit' && actionBtn.closest('#materias-table')) {
                MateriaModule.openEditModal(id);
            } else if (action === 'delete' && actionBtn.closest('#materias-table')) {
                MateriaModule.deleteMateria(id);
            } else if (action === 'edit' && actionBtn.closest('#grupos-table')) {
                GrupoModule.openEditModal(id);
            } else if (action === 'delete' && actionBtn.closest('#grupos-table')) {
                GrupoModule.deleteGrupo(id);
            } else if (action === 'edit' && actionBtn.closest('#generaciones-table')) {
                GeneracionModule.openEditModal(id);
            } else if (action === 'delete' && actionBtn.closest('#generaciones-table')) {
                GeneracionModule.deleteGeneracion(id);
            } else if (action === 'edit' && actionBtn.closest('#parciales-table')) {
                ParcialModule.openEditModal(id);
            } else if (action === 'delete' && actionBtn.closest('#parciales-table')) {
                ParcialModule.deleteParcial(id);
            } else if (action === 'delete' && actionBtn.closest('#calificaciones-table')) {
                CalificacionModule.deleteCalificacion(alumnoId, parcialId);
            } else if (action === 'process' && actionBtn.closest('#solicitudes-table')) {
                SolicitudModule.processSolicitud(id);
            } else if (action === 'delete' && actionBtn.closest('#historial-reportes')) {
                ReporteModule.deleteReporte(id);
            }
        }
    };


    document.addEventListener('click', handleGlobalClicks);

    TabModule.switchTab = (function(originalSwitchTab) {
        return function(tabId) {
            originalSwitchTab.call(TabModule, tabId);
            if (tabId === 'reportes') {
                ReporteModule.fetchReportes();
                ReporteModule.fetchSemestres();
            }
        };
    })(TabModule.switchTab);
});