jQuery(document).ready(function($) {
    const { __, sprintf } = wp.i18n;

    // --- LÓGICA DE PESTAÑAS
    function setupTabs() {
        const tabs = $('.nav-tab');
        const tabContents = $('.tab-content');
        tabs.on('click', function(e) {
            e.preventDefault();
            const target = $(this).attr('href');
            tabs.removeClass('nav-tab-active');
            tabContents.removeClass('active');
            $(this).addClass('nav-tab-active');
            $(target).addClass('active');
            window.history.replaceState(null, null, target);
        });
        const hash = window.location.hash;
        if (hash && tabs.filter('[href="' + hash + '"]').length) {
            tabs.filter('[href="' + hash + '"]').click();
        } else {
            tabs.first().click();
        }
    }
    setupTabs();

    // --- LÓGICA DEL CRON  ---
    $('#autwa-generate-key-btn').on('click', function() {
        if (!confirm(__('Are you sure? Generating a new key will make the current URL stop working. You will need to update it in your cron service.', 'autowa-whatsapp'))) return;
        const button = $(this);
        button.prop('disabled', true).text(__('Generating...', 'autowa-whatsapp'));
        $.post(autwa_ajax.ajax_url, { action: 'autwa_generate_cron_key', nonce: autwa_ajax.nonce })
            .done(response => {
                if (response.success) {
                    window.location.href = 'admin.php?page=autwa-whatsapp&key-updated=true#automatizacion';
                } else {
                    alert(__('Error:', 'autowa-whatsapp') + ' ' + (response.data.message || __('An unknown error occurred.', 'autowa-whatsapp')));
                    button.prop('disabled', false).text(__('Generate New Security Key', 'autowa-whatsapp'));
                }
            })
            .fail(() => {
                alert(__('Error communicating with the server.', 'autowa-whatsapp'));
                button.prop('disabled', false).text(__('Generate New Security Key', 'autowa-whatsapp'));
            });
    });
    $('#autwa-copy-url-btn').on('click', function() {
        const urlField = $('#autwa-cron-url')[0];
        urlField.select();
        urlField.setSelectionRange(0, 99999);
        try {
            document.execCommand('copy');
            $('#autwa-copy-feedback').fadeIn().delay(2000).fadeOut();
        } catch (err) {
            alert(__('Could not copy the URL. Please copy it manually.', 'autowa-whatsapp'));
        }
    });

    // --- LÓGICA DE SESIÓN (Basada en PUSH / Transients) ---
    if ($('.autwa-session-control').length) {
        
        // Selectores de Licencia y Sesión
        const licenseControls = $('#autwa-license-controls');
        const sessionOverlay = $('#autwa-session-overlay');
        const allSessionButtons = $('.session-buttons button, #test-connection, #verify-config, #check-status');
        const createStartBtn = $('#create-start-session');
        const qrContainer = $('#qr-container');
        const responseMessages = $('#response-messages');
        const currentStatusSpan = $('#current-status');
        let pollingInterval;

        // --- Funciones de UI ---
        function showMessage(type, message) {
            responseMessages.html(`<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`).show();
            // No auto-ocultar errores
            if (type !== 'error') {
                setTimeout(() => { responseMessages.find('.notice').fadeOut(); }, 5000);
            }
        }

        function updateUIFromStatus(status, qr_image = null) {
            // No tocar botones si la licencia está bloqueando
            if (sessionOverlay.is(':visible')) {
                console.log("UI update skipped, license overlay is active.");
                return; 
            }

            let statusText = 'Unknown', statusColor = '#666';
            allSessionButtons.prop('disabled', true); // Deshabilitar todo por defecto

            switch (status) {
                case 'NOT_FOUND':
                case 'STOPPED':
                case 'FAILED':
                    statusText = (status === 'NOT_FOUND') ? __('Session not found', 'autowa-whatsapp') :
                                 (status === 'FAILED') ? `❌ ${__('Connection failed', 'autowa-whatsapp')}` :
                                 __('Stopped', 'autowa-whatsapp');
                    statusColor = '#dc3545';
                    createStartBtn.prop('disabled', false).text(__('Create & Start Session', 'autowa-whatsapp'));
                    qrContainer.hide();
                    if (pollingInterval) clearInterval(pollingInterval); // Detener sondeo si falla/para
                    break;
                
                
                case 'SCAN_QR_CODE':
    statusText = __('Waiting for QR scan', 'autowa-whatsapp');
    statusColor = '#ffc107'; // Amarillo/Naranja

    // 1. FORZAR VISIBILIDAD DEL CONTENEDOR PRINCIPAL
    // Esto asegura que el #qr-container sea visible y anule cualquier display: none;
    qrContainer.css('display', 'block'); 
    
    // 2. Verificar si la Data URI es válida (la consola ya confirmó que sí lo es)
    if (qr_image && qr_image.startsWith('data:image')) { 
        
        // 3. INYECTAR DIRECTAMENTE LA IMAGEN 
        // No usamos 'display: none;' para que la imagen aparezca tan pronto como el navegador la decodifique.
        qrContainer.find('#qr-code-wrapper').html(`<img src="${qr_image}" id="qr-image" alt="QR Code" style="max-width: 100%; height: auto;" />`);
        
        // 4. Ocultar el velo (overlay) inmediatamente (ya que el QR está presente)
        qrContainer.find('.qr-overlay').hide();
        
        // Habilitar botones
        $('#restart-session, #check-status').prop('disabled', false); 

    } else {
        // Lógica si el QR_IMAGE es null (el polling aún no ha recibido la Data URI)
        qrContainer.find('#qr-code-wrapper').html(''); // Limpiar si hubo un QR corrupto
        qrContainer.find('.qr-overlay').show().find('p').text(__('Generating QR... Wait for update.', 'autowa-whatsapp'));
        $('#restart-session, #check-status').prop('disabled', false); 
    }
    
    startPolling(); // Continuar el sondeo para detectar el estado 'WORKING'
    break;
                
                case 'WORKING':
                     statusText = `✅ ${__('Connected', 'autowa-whatsapp')}`;
                     statusColor = '#28a745';
                     $('#restart-session, #check-status').prop('disabled', false);
                     qrContainer.hide();
                     if (pollingInterval) clearInterval(pollingInterval); // Detener sondeo si ya conectó
                     break;
                
                case 'PENDING':
                case 'CREATED':
                    statusText = __('Session Started, Processing...', 'autowa-whatsapp');
                case 'STARTING':
                    statusText = __('Processing...', 'autowa-whatsapp');
                    statusColor = '#007bff';
                    $('#check-status').prop('disabled', false);
                    qrContainer.hide();
                    startPolling(); // Iniciar sondeo
                    break;
                
                case 'UNKNOWN':
                    statusText = `❓ ${__('Click a button to start', 'autowa-whatsapp')}`;
                    statusColor = '#6c757d';
                    createStartBtn.prop('disabled', false).text(__('Create & Start Session', 'autowa-whatsapp'));
                    qrContainer.hide();
                    if (pollingInterval) clearInterval(pollingInterval);
                    break;
                
                default:
                    statusText = status;
                    $('#check-status').prop('disabled', false);
            }
            currentStatusSpan.html(statusText).css('color', statusColor);
        }

        // --- Lógica de Comunicación (PUSH) ---
       
        function startPolling() {
            if (pollingInterval) clearInterval(pollingInterval);
            console.log("Starting polling for transient updates...");
            // Llama a la función correcta que lee el transient
            pollingInterval = setInterval(pollForUpdates, 3000); // 3 segundos
        }

        function pollForUpdates() {
            console.log("Polling transient...");
            $.post(autwa_ajax.ajax_url, { 
                action: 'autwa_get_session_update', // Esta función lee el transient
                nonce: autwa_ajax.nonce 
            })
           .done(function(response) {
    if (response.success && response.data) {
        console.log("Response Data (Poll):", response.data);
        
        const newStatus = response.data.status || 'UNKNOWN';
        let qrImage = response.data.qr_image || null; 

        // 🚀 CORRECCIÓN CRÍTICA: Limpiar el string Base64 si existe
        if (qrImage && typeof qrImage === 'string') {
            // Trim (eliminar espacios en blanco alrededor) y asegurar que no esté vacío.
            qrImage = qrImage.trim();
            if (qrImage.length < 50) { // Si es demasiado corto para ser un QR real, lo descartamos.
                qrImage = null;
            }
        } else {
            qrImage = null;
        }

        updateUIFromStatus(newStatus, qrImage);
    } else {
        console.log("Poll failed or no recent data.");
    }
})
            .fail(function() {
                showMessage('error', __('Error while polling for updates.', 'autowa-whatsapp'));
                if (pollingInterval) clearInterval(pollingInterval);
            });
        }

        // Envía el comando inicial. n8n recibe esto, inicia el proceso,
        // y n8n empieza a enviar PUSHes que `pollForUpdates` detectará.
        function sendInitialCommand() {
            showMessage('info', __('Sending command to n8n server... Please wait.', 'autowa-whatsapp'));
            createStartBtn.prop('disabled', true);
            updateUIFromStatus('PENDING'); // Poner en estado de espera

            $.ajax({
                url: autwa_ajax.ajax_url,
                type: 'POST',
                data: { action: 'autwa_create_session', nonce: autwa_ajax.nonce },
                timeout: 15000 // 15 segundos
            }).done(function(response) {
        if (response.success) {
            showMessage('success', __('Command received. Waiting for status updates from n8n...', 'autowa-whatsapp'));
            startPolling(); // Empezar a escuchar el transient
        } else {
            showMessage('error', response.data.message);
            updateUIFromStatus('FAILED');
        }
    }).fail(function(jqXHR, textStatus) {
        if (textStatus === 'timeout') {
            showMessage('info', __('Command sent (timeout). Assuming n8n is processing. Waiting for updates...', 'autowa-whatsapp'));
            startPolling(); // egurarse de que el polling inicie después del timeout
        } else {
            showMessage('error', __('Failed to send command to n8n.', 'autowa-whatsapp'));
            updateUIFromStatus('FAILED');
        }
    });
        }
        
        // Llama a `pollForUpdates` al final
        function checkSessionStatus() {
            console.log("Manual check status requested...");
            if (pollingInterval) clearInterval(pollingInterval);
            // Primero, llama a la función PULL para un estado inmediato
            $.post(autwa_ajax.ajax_url, { action: 'autwa_check_session_status', nonce: autwa_ajax.nonce })
                .done(res => {
                    console.log("Manual check response:", res);
                    if (res.success) {
                        updateUIFromStatus(res.data.status);
                        // Si el estado no es final (WORKING/FAILED/STOPPED),
                        // iniciamos el sondeo PUSH para futuras actualizaciones.
                        if (['STARTING', 'SCAN_QR_CODE', 'PENDING', 'CREATED'].includes(res.data.status)) {
                            startPolling();
                        }
                    } else {
                         updateUIFromStatus('NOT_FOUND');
                    }
                })
                .fail(() => updateUIFromStatus('FAILED'));
        }

        // --- Lógica de Licencia ---
        function manageLicense(action) {
            const licenseSpinner = licenseControls.find('.spinner');
            licenseSpinner.addClass('is-active');

            $.post(autwa_ajax.ajax_url, {
                action: 'autwa_manage_license', nonce: autwa_ajax.nonce,
                license_action: action, license_key: $('#autwa_edd_license_key').val().trim()
            }).done(function(response) {
                const isValid = response.success && response.data.license === 'valid';
                
                // Ocultar el velo gris
                sessionOverlay.toggle(!isValid);
             
                if (isValid) {
                    console.log("License OK. Running manual status check.");
                    checkSessionStatus();
                    allSessionButtons.prop('disabled', false); 
                //updateUIFromStatus('UNKNOWN');
                } else {
                    allSessionButtons.prop('disabled', true);
                    if (response.data.message) {
                        showMessage('error', response.data.message);
                    }
                    console.log("License invalid.");
                }

            }).always(() => licenseSpinner.removeClass('is-active'));
        }

        // --- Event Handlers ---
        createStartBtn.on('click', sendInitialCommand); // Llama a la función PUSH
        $('#check-status').on('click', checkSessionStatus);
        licenseControls.on('click', 'button', (e) => manageLicense($(e.currentTarget).data('action')));
        
        // El botón 'get-qr' se mantiene oculto/deshabilitado
        // ya que el QR llega por PUSH.
        $('#get-qr').hide(); 

        // --- Carga Inicial ---
        // Al cargar la página, sólo se verifica la licencia.
        // manageLicense() llamará a checkSessionStatus() si es válida.
        manageLicense('check');
    }
    
    // --- LÓGICA DE DEBUG PARA SEGURIDAD ---
    function fetchSecurityLogs() {
        const logContainer = $('#security-debug-log-container');
        if (logContainer.length === 0) return;

        $.post(autwa_ajax.ajax_url, {
            action: 'autwa_get_recent_logs',
            nonce: autwa_ajax.nonce
        }).done(response => {
            if (response.success && response.data.logs) {
                // Filtramos logs que pertenezcan a seguridad
                const securityLogs = response.data.logs.filter(log => log.session_name === 'security');
                
                if (securityLogs.length === 0) {
                    logContainer.html('<p>No security events recorded yet.</p>');
                    return;
                }

                let html = '<table class="wp-list-table widefat fixed striped" style="background: transparent; border: none;">';
                html += '<thead><tr><th style="width:150px;">Time</th><th style="width:100px;">Event</th><th>Message</th></tr></thead><tbody>';
                
                securityLogs.forEach(log => {
                    const color = log.event_type === 'alert_failed' ? '#d63638' : '#00a32a';
                    html += `<tr>
                        <td>${log.created_at}</td>
                        <td><strong style="color: ${color}">${log.event_type}</strong></td>
                        <td>${log.message}</td>
                    </tr>`;
                });
                
                html += '</tbody></table>';
                logContainer.html(html);
            }
        });
    }

    // Cargar logs al entrar en la pestaña o al hacer clic en refrescar
    $(window).on('hashchange', function() {
        if (window.location.hash === '#security') {
            fetchSecurityLogs();
        }
    });

    if (window.location.hash === '#security') fetchSecurityLogs();
    $('#refresh-security-logs').on('click', fetchSecurityLogs);
    
});