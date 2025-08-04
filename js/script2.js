// Asegurarse de que el DOM esté completamente cargado
jQuery(document).ready(function($) {
    console.log('jQuery y script2.js cargados correctamente.');

    // Manejar el envío del formulario con jQuery
    const loginForm = $('#loginForm');
    if (loginForm.length) {
        console.log('Formulario de login encontrado.');

        loginForm.on('submit', function(event) {
            event.preventDefault(); // Evitar el envío tradicional
            console.log('Evento submit capturado. Enviando datos con AJAX...');

            const formData = new FormData(this);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'procesarLogin2.php', true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        console.log('Respuesta recibida del servidor:', xhr.responseText);
                        try {
                            const data = JSON.parse(xhr.responseText);
                            console.log('Datos procesados:', data);
                            if (data.success) {
                                // Redirigir si el login es exitoso
                                window.location.href = data.redirect;
                            } else {
                                // Mostrar el mensaje de error en el modal
                                const errorDiv = document.createElement('div');
                                errorDiv.className = 'error-message animate__animated animate__shakeX';
                                errorDiv.textContent = data.message;
                                const existingError = loginForm.find('.error-message')[0];
                                if (existingError) existingError.remove();
                                loginForm.prepend(errorDiv);
                            }
                        } catch (e) {
                            console.error('Error al parsear JSON:', e);
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'error-message animate__animated animate__shakeX';
                            errorDiv.textContent = 'Error al procesar la respuesta del servidor.';
                            const existingError = loginForm.find('.error-message')[0];
                            if (existingError) existingError.remove();
                            loginForm.prepend(errorDiv);
                        }
                    } else {
                        console.error('Error en la solicitud AJAX:', xhr.status);
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'error-message animate__animated animate__shakeX';
                        errorDiv.textContent = 'Error de conexión. Intente nuevamente.';
                        const existingError = loginForm.find('.error-message')[0];
                        if (existingError) existingError.remove();
                        loginForm.prepend(errorDiv);
                    }
                }
            };
            xhr.send(formData);
        });
    } else {
        console.error('Formulario de login no encontrado.');
    }

    // Funciones existentes del script2.js
    let currentIndex = 0;
    const carouselImages = $('.carousel-images');
    const activityCards = $('.activity-card');
    const totalCards = activityCards.length;
    const autoplaySpeed = 5000; // 5 segundos
    let autoplayInterval;

    if (totalCards > 0) {
        updateCarousel();
        startAutoplay();

        $('.carousel-container').on('mouseenter', stopAutoplay);
        $('.carousel-container').on('mouseleave', startAutoplay);

        $('.left-arrow').on('click', () => navigate(-1));
        $('.right-arrow').on('click', () => navigate(1));

        activityCards.each(function(index) {
            $(this).on('click', () => openActivityModal(index));
        });

        $(window).on('resize', updateCarousel);
    }

    function updateCarousel() {
        const visibleCards = window.innerWidth > 768 ? 3 : 1;
        const cardWidthPercentage = 100 / visibleCards;
        const offset = -currentIndex * cardWidthPercentage;
        carouselImages.css('transform', `translateX(${offset}%)`);
    }

    function navigate(direction) {
        stopAutoplay();
        const visibleCards = window.innerWidth > 768 ? 3 : 1;
        const maxIndex = totalCards - visibleCards;

        currentIndex += direction;
        if (currentIndex < 0) {
            currentIndex = 0;
        } else if (currentIndex > maxIndex) {
            currentIndex = maxIndex;
        }

        updateCarousel();
        startAutoplay();
    }

    function startAutoplay() {
        stopAutoplay();
        autoplayInterval = setInterval(() => {
            const visibleCards = window.innerWidth > 768 ? 3 : 1;
            const maxIndex = totalCards - visibleCards;
            currentIndex = (currentIndex + 1 > maxIndex) ? 0 : currentIndex + 1;
            updateCarousel();
        }, autoplaySpeed);
    }

    function stopAutoplay() {
        clearInterval(autoplayInterval);
    }

    function openActivityModal(index) {
        const modal = $('#activityModal');
        const card = activityCards.eq(index);

        if (card.length && modal.length) {
            const imageUrl = card.find('.activity-image').css('backgroundImage').replace(/url\(['"](.+)['"]\)/, '$1');
            const title = card.find('h3').text();
            const description = card.find('p:not(:last-child)').text();
            const date = card.find('p:last-child').text();

            $('#modalImage').attr('src', imageUrl);
            $('#modalTitle').text(title);
            $('#modalDescription').html(`${description}<br><strong>${date}</strong>`);

            modal.css('display', 'flex');
            setTimeout(() => modal.find('.modal-content').addClass('modal-open'), 10);
        }
    }

    window.togglePasswordVisibility = function() {
        const passwordInput = $('#password');
        const toggleIcon = $('.toggle-password i');

        if (passwordInput.attr('type') === 'password') {
            passwordInput.attr('type', 'text');
            toggleIcon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            passwordInput.attr('type', 'password');
            toggleIcon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    };

    window.toggleMenu = function() {
        $('#main-nav').toggleClass('active');
    };

    window.closeModal = function(modalId) {
        const modal = $(`#${modalId}`);
        if (modal.length) {
            const modalContent = modal.find('.modal-content');
            modalContent.removeClass('modal-open');
            setTimeout(() => {
                modal.css('display', 'none');
            }, 300);
        }
    };

    window.showLogin = function() {
        const modal = $('#loginModal');
        modal.css('display', 'flex');
        setTimeout(() => modal.find('.modal-content').addClass('modal-open'), 10);
        $('#username').focus();
    };

    $('a[href^="#"]').on('click', function(e) {
        e.preventDefault();
        const targetId = $(this).attr('href');
        if (targetId === '#') return;

        const targetElement = $(targetId);
        if (targetElement.length) {
            $('#main-nav').removeClass('active');
            window.scrollTo({
                top: targetElement.offset().top - 80,
                behavior: 'smooth'
            });
        }
    });
$(document).ready(function() {
    $('#contactForm').on('submit', function(e) {
        e.preventDefault(); // Evita la redirección
        const form = $(this);
        const confirmation = $('#confirmation');
        const formData = new FormData(form[0]);

        $.ajax({
            url: 'pruebacorreo.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(result) {
                if (result === 'success') {
                    confirmation.text('Mensaje enviado con éxito').removeClass('error').addClass('success');
                    form[0].reset(); // Limpia el formulario
                } else {
                    confirmation.text('Error al enviar el mensaje. Por favor, intenta de nuevo.').removeClass('success').addClass('error');
                }
                confirmation.show();
                setTimeout(function() {
                    confirmation.hide();
                }, 5000); // Oculta el mensaje después de 5 segundos
            },
            error: function() {
                confirmation.text('Error en la conexión. Revisa tu red.').removeClass('success').addClass('error');
                confirmation.show();
            }
        });
    });
});
    $(window).on('scroll', function() {
        const sections = $('section[id]');
        const navLinks = $('.nav-link');
        let currentSection = '';

        sections.each(function() {
            const sectionTop = $(this).offset().top;
            const sectionHeight = $(this).outerHeight();

            if (window.pageYOffset >= sectionTop - 100) {
                currentSection = `#${$(this).attr('id')}`;
            }
        });

        navLinks.each(function() {
            $(this).removeClass('active-link');
            if ($(this).attr('href') === currentSection) {
                $(this).addClass('active-link');
            }
        });
    });
});