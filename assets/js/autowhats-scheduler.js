(function($) {
    "use strict";

    // Evitar conflictos: ejecutar solo en la página del scheduler
    if (!$('body').hasClass('autowa-whatsapp_page_autwa-scheduler')) {
        return;
    }

    /**
     * Establece la fecha y hora por defecto (ahora + 5 minutos)
     */
    function setDefaultDateTime() {
        const now = new Date();
        now.setMinutes(now.getMinutes() + 5);

        // Formato requerido por input datetime-local: YYYY-MM-DDThh:mm
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');

        const defaultValue = `${year}-${month}-${day}T${hours}:${minutes}`;
        const $datetimeInput = $('#schedule-datetime');
        
        if ($datetimeInput.length) {
            $datetimeInput
                .attr('min', defaultValue)
                .val(defaultValue);
        }
    }

    $(document).ready(function() {
        const { __, sprintf } = wp.i18n;
       
        setDefaultDateTime();
        
        // --- Selectores Globales ---
        const loader = $('#autwa-loader');
        const messagesContainer = $('#autwa-messages');
        let mediaUploader;

        // --- Lógica de Pestañas (Tabs) ---
        $('.autwa-wrap > .nav-tab-wrapper .nav-tab').on('click', function(e) {
            e.preventDefault();
            const target = $(this).attr('href');
            
            $('.autwa-wrap > .nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
            $('.wrap > .tab-content').removeClass('active');
            
            $(this).addClass('nav-tab-active');
            $(target).addClass('active');
            
            // Guardar estado en URL
            if (window.history.pushState) {
                window.history.pushState({ path: target }, '', target);
            } else {
                window.location.hash = target;
            }
        });

        const hash = window.location.hash;
        if (hash === '#broadcast-lists') {
            $('.nav-tab[href="#broadcast-lists"]').click();
        } else {
            if(!$('.nav-tab-active').length) {
                $('.nav-tab[href="#programming-sends"]').click();
            }
        }

        // --- Utilidad: Mostrar Mensajes ---
        function showMessage(type, text) {
            const cssClass = type === 'success' ? 'notice notice-success' : 'notice notice-error';
            messagesContainer.html(`<div class="${cssClass} is-dismissible"><p>${text}</p></div>`);
            
            $('html, body').animate({ scrollTop: 0 }, 'slow');
            
            setTimeout(() => {
                messagesContainer.fadeOut(500, function() {
                    $(this).empty().show();
                });
            }, 5000);
        }

        function showLoader() { loader.fadeIn(200); }
        function hideLoader() { loader.fadeOut(200); }

        // ==========================================
        // SECCIÓN 1: PROGRAMADOR DE MENSAJES
        // ==========================================

        /**
         * Cargar Destinatarios (Contactos, Grupos, Listas)
         */
        function loadAvailableRecipients() {
            showLoader();
            
            // Limpiar selects antes de cargar para evitar duplicados visuales
            $('#source-contacts, #source-groups, #source-broadcasts').empty();

            // 1.1 Cargar Contactos
            const pContacts = $.post(autwa_ajax.ajax_url, { action: 'autwa_get_contacts', nonce: autwa_ajax.nonce })
            .done(function(res) {
                if(res.success && res.data.contacts) {
                    const validContacts = [];
                    const seenIds = new Set();

                    res.data.contacts.forEach(c => {
                        let idStr = c.id?._serialized || c.id || '';
                        idStr = String(idStr).trim();

                        if (!idStr || !idStr.endsWith('@c.us') || seenIds.has(idStr)) return;
                        if (c.isGroup === true || c.isMe === true) return;
                        if (typeof c.isMyContact !== 'undefined' && c.isMyContact !== true) return;

                        seenIds.add(idStr);
                        const name = (c.name || c.pushname || c.number || idStr.replace('@c.us', '')).trim() || 'Contacto sin nombre';
                        validContacts.push({ id: idStr, name: name });
                    });

                    validContacts.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));

                    let optionsHtml = '';
                    validContacts.forEach(contact => {
                        const safeName = contact.name.replace(/"/g, '&quot;').replace(/</g, '&lt;');
                        optionsHtml += `<option value="${contact.id}">${safeName}</option>`;
                    });

                    $('#source-contacts').html(optionsHtml).trigger('change');
                }
            });

            // 1.2 Cargar Grupos
            const pGroups = $.post(autwa_ajax.ajax_url, { action: 'autwa_get_groups', nonce: autwa_ajax.nonce })
            .done(function(res) {
                if(res.success && res.data.groups) {
                    const validGroups = [];
                    const seenGroupIds = new Set();

                    res.data.groups.forEach(g => {
                        if (seenGroupIds.has(g.id)) return;
                        if (String(g.id).indexOf('@g.us') === -1) return;
                        
                        seenGroupIds.add(g.id);
                        validGroups.push({ name: g.subject || 'Grupo desconocido', id: g.id });
                    });

                    validGroups.sort((a, b) => a.name.localeCompare(b.name));

                    let optionsHtml = '';
                    validGroups.forEach(group => {
                        const safeName = group.name.replace(/"/g, '&quot;');
                        optionsHtml += `<option value="${group.id}" data-type="group">${safeName}</option>`;
                    });

                    $('#source-groups').html(optionsHtml).trigger('change');
                }
            });

            // 1.3 Cargar Listas de Difusión
            const pBroadcasts = $.post(autwa_ajax.ajax_url, { action: 'autwa_get_broadcast_lists', nonce: autwa_ajax.nonce })
            .done(function(res) {
                if(res.success && res.data.lists) {
                    let optionsHtml = '';
                    res.data.lists.forEach(l => {
                        const safeName = l.name.replace(/"/g, '&quot;');
                        optionsHtml += `<option value="${l.id}" data-type="broadcast">${safeName} (${l.recipient_count})</option>`;
                    });
                    $('#source-broadcasts').html(optionsHtml).trigger('change');
                }
            });

            // Ocultar loader cuando todas terminen
            $.when(pContacts, pGroups, pBroadcasts).always(() => hideLoader());
        }

        $('#reload-recipients-btn').on('click', function(e) {
            e.preventDefault();
            loadAvailableRecipients();
        });

        // --- Lógica de Mover Destinatarios ---
        $('#add-recipient-btn').on('click', function(e) {
            e.preventDefault();
            const dest = $('#destination-recipients');
            
            ['source-contacts', 'source-groups', 'source-broadcasts'].forEach(sourceId => {
                const type = sourceId === 'source-contacts' ? 'contact' : (sourceId === 'source-groups' ? 'group' : 'broadcast');
                $(`#${sourceId} option:selected`).each(function() {
                    const val = $(this).val();
                    const text = $(this).text();
                    if (dest.find(`option[value="${val}"]`).length === 0) {
                        const newOpt = new Option(text, val);
                        $(newOpt).data('type', $(this).data('type') || type);
                        dest.append(newOpt);
                    }
                });
                $(`#${sourceId} option:selected`).prop('selected', false).trigger('change');
            });
        });

        $('#remove-recipient-btn').on('click', function(e) {
            e.preventDefault();
            $('#destination-recipients option:selected').remove();
            $('#destination-recipients').trigger('change');
        });

        $('.autwa-recipient-search').on('keyup', function() {
            const term = $(this).val().toLowerCase();
            const targetSelect = $(this).next('select');
            targetSelect.find('option').each(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(term) > -1);
            });
        });

        // --- Manejo de Archivos ---
        $('#upload-attachment-btn').on('click', function(e) {
            e.preventDefault();
            if (mediaUploader) { mediaUploader.open(); return; }
            mediaUploader = wp.media({
                title: __('Select Attachment', 'autowa-whatsapp'),
                button: { text: __('Use this file', 'autowa-whatsapp') },
                multiple: false
            });
            mediaUploader.on('select', function() {
                const attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#attachment-url').val(attachment.url);
                $('#attachment-filename').text(attachment.filename);
                $('#attachment-name-hidden').val(attachment.filename);
                $('#attachment-mimetype').val(attachment.mime);

                let msgType = 'document';
                if (attachment.mime.startsWith('image/')) msgType = 'image';
                else if (attachment.mime.startsWith('video/')) msgType = 'video';
                else if (attachment.mime.startsWith('audio/')) msgType = 'audio';
                
                $('#attachment-preview').data('detected-type', msgType).removeClass('hidden').show();
                $('#upload-attachment-btn').text(__('Change File', 'autowa-whatsapp'));
            });
            mediaUploader.open();
        });

        $('#remove-attachment').on('click', function() {
            $('#attachment-url, #attachment-mimetype, #attachment-name-hidden').val('');
            $('#attachment-preview').data('detected-type', 'text').hide();
            $('#upload-attachment-btn').text(__('Select File', 'autowa-whatsapp'));
        });

        // --- ENVIAR PROGRAMACIÓN (SUBMIT) ---
        $('#schedule-message-form').on('submit', function(e) {
            e.preventDefault();

            const recipients = [];
            $('#destination-recipients option').each(function() {
                recipients.push({
                    id: $(this).val(),
                    text: $(this).text(),
                    type: $(this).data('type') || 'contact'
                });
            });

            if (recipients.length === 0) {
                showMessage('error', __('Please select at least one recipient.', 'autowa-whatsapp'));
                return;
            }

            const messageText = $('#message-text').val().trim();
            const attachmentUrl = $('#attachment-url').val();
            const scheduleTime = $('#schedule-datetime').val();

            if (!messageText && !attachmentUrl) {
                showMessage('error', __('Please write a message or attach a file.', 'autowa-whatsapp'));
                return;
            }

            if (!scheduleTime) {
                showMessage('error', __('Please select a date and time.', 'autowa-whatsapp'));
                return;
            }

            const payload = {
                action: 'autwa_schedule_message',
                nonce: autwa_ajax.nonce,
                recipients: JSON.stringify(recipients),
                message: messageText,
                attachment_url: attachmentUrl,
                attachment_filename: $('#attachment-name-hidden').val(),
                message_type: attachmentUrl ? ($('#attachment-preview').data('detected-type') || 'document') : 'text',
                date: scheduleTime.split('T')[0],
                time: scheduleTime.split('T')[1]
            };

            const submitBtn = $('#schedule-submit-btn');
            const originalBtnText = submitBtn.text();
            
            submitBtn.prop('disabled', true).text(__('Scheduling...', 'autowa-whatsapp'));
            showLoader();

            $.post(autwa_ajax.ajax_url, payload)
                .done(function(response) {
                    if (response.success) {
                        showMessage('success', response.data.message);
                        
                        // Limpiar formulario completo
                        $('#message-text').val('');
                        $('#destination-recipients').empty().trigger('change'); 
                        $('#remove-attachment').click();
                        
                        // RECARGA DE DATOS: Asegura que contactos y grupos se refresquen
                        loadAvailableRecipients(); 
                        loadScheduledMessages(); 
                        if (typeof loadAllRecipients === 'function') loadAllRecipients(false);
                    } else {
                        showMessage('error', response.data.message || __('Error scheduling message.', 'autowa-whatsapp'));
                    }
                })
                .fail(function() {
                    showMessage('error', __('Server error occurred.', 'autowa-whatsapp'));
                })
                .always(function() {
                    submitBtn.prop('disabled', false).text(originalBtnText);
                    hideLoader();
                });
        });

        // --- Historial y Pendientes ---
        function loadScheduledMessages() {
            $.post(autwa_ajax.ajax_url, { action: 'autwa_get_scheduled_messages', nonce: autwa_ajax.nonce })
            .done(function(res) {
                if(res.success) {
                    renderScheduleList($('#pending-schedules-list'), res.data.pending, true);
                    renderScheduleList($('#history-schedules-list'), res.data.history, false);
                }
            });
        }

        function renderScheduleList(container, items, isPending) {
            container.empty();
            if(!items || items.length === 0) {
                container.html(`<p style="color:#777; font-style:italic;">${__('No messages found.', 'autowa-whatsapp')}</p>`);
                return;
            }

            items.forEach(item => {
                let statusClass = `status-${item.status}`;
                let count = 1;
                try {
                    const parsed = JSON.parse(item.recipients);
                    if(Array.isArray(parsed)) count = parsed.length;
                } catch(e) {}
                
                const typeLabel = (item.message_type || 'TEXT').toUpperCase();

                const html = `
                    <div class="autwa-schedule-card">
                        <div class="autwa-card-content">
                            <span class="autwa-status-badge ${statusClass}">${item.status.toUpperCase()}</span>
                            <span style="font-size:10px; border:1px solid #ccc; padding:2px 4px; border-radius:3px; margin-left:5px;">${typeLabel}</span>
                            <p><strong>${__('Scheduled for:', 'autowa-whatsapp')}</strong> ${item.scheduled_at}</p>
                            <p><strong>${__('Recipients:', 'autowa-whatsapp')}</strong> ${count}</p>
                            <p><strong>${__('Message:', 'autowa-whatsapp')}</strong> ${item.message ? item.message.substring(0, 50) + '...' : '('+__('Media Only', 'autowa-whatsapp')+')'}</p>
                        </div>
                        <div class="autwa-card-actions">
                            ${isPending ? `<button class="button button-small delete-schedule-btn" data-id="${item.id}" style="color:#a00;">${__('Cancel', 'autowa-whatsapp')}</button>` : ''}
                        </div>
                    </div>
                `;
                container.append(html);
            });
        }

        $(document).on('click', '.delete-schedule-btn', function(e) {
            e.preventDefault();
            if(!confirm(__('Are you sure?', 'autowa-whatsapp'))) return;
            const id = $(this).data('id');
            $.post(autwa_ajax.ajax_url, { action: 'autwa_delete_scheduled_message', nonce: autwa_ajax.nonce, id: id })
            .done(res => { if(res.success) loadScheduledMessages(); });
        });

        $('#clear-history-btn').on('click', function(e) {
            e.preventDefault();
            if(!confirm(__('Delete history?', 'autowa-whatsapp'))) return;
            $.post(autwa_ajax.ajax_url, { action: 'autwa_clear_scheduler_history', nonce: autwa_ajax.nonce })
            .done(res => { if(res.success) loadScheduledMessages(); });
        });

        // ==========================================
        // SECCIÓN 2: LISTAS DE DIFUSIÓN (BROADCASTS)
        // ==========================================
        (function initBroadcastManager() {
            const dropdown = $('#broadcast-lists-dropdown');
            const welcomePanel = $('#broadcast-welcome-panel');
            const editorPanel = $('#broadcast-editor-panel');
            const listNameTitle = $('#current-list-name');
            let currentListId = null;

            window.loadAllRecipients = function(triggerChange = true) {
                $.post(autwa_ajax.ajax_url, { action: 'autwa_get_broadcast_lists', nonce: autwa_ajax.nonce })
                .done(res => {
                    dropdown.empty().append(new Option(__('Select a list...', 'autowa-whatsapp'), ''));
                    if(res.success && res.data.lists) {
                        res.data.lists.forEach(l => {
                            dropdown.append(new Option(`${l.name} (${l.recipient_count})`, l.id));
                        });
                    }
                    if(triggerChange) dropdown.trigger('change');
                });
            };

            $('#create-broadcast-list-btn').on('click', function() {
                const name = $('#new-broadcast-list-name').val().trim();
                if(!name) return alert(__('Enter a name', 'autowa-whatsapp'));
                showLoader();
                $.post(autwa_ajax.ajax_url, { action: 'autwa_create_broadcast_list', nonce: autwa_ajax.nonce, list_name: name })
                .done(res => {
                    if(res.success) {
                        showMessage('success', res.data.message);
                        $('#new-broadcast-list-name').val('');
                        loadAllRecipients(false);
                    }
                }).always(hideLoader);
            });

            dropdown.on('change', function() {
                const listId = $(this).val();
                if(!listId) { welcomePanel.show(); editorPanel.hide(); currentListId = null; return; }
                currentListId = listId;
                listNameTitle.text($(this).find('option:selected').text());
                welcomePanel.hide(); editorPanel.show();
                loadListMembers(listId);
            });

            function loadListMembers(listId) {
                showLoader();
                const sourceContacts = $('.broadcast-source-contacts');
                const sourceGroups = $('.broadcast-source-groups');
                const dest = $('.broadcast-destination-recipients');
                
                $.when(
                    $.post(autwa_ajax.ajax_url, { action: 'autwa_get_contacts', nonce: autwa_ajax.nonce }),
                    $.post(autwa_ajax.ajax_url, { action: 'autwa_get_groups', nonce: autwa_ajax.nonce }),
                    $.post(autwa_ajax.ajax_url, { action: 'autwa_get_list_recipients', nonce: autwa_ajax.nonce, list_id: listId })
                ).done(function(cRes, gRes, mRes) {
                    const contacts = cRes[0].success ? cRes[0].data.contacts : [];
                    const groups = gRes[0].success ? gRes[0].data.groups : [];
                    const members = mRes[0].success ? mRes[0].data.recipients : [];
                    const memberIds = members.map(m => m.recipient_id);

                    let cHtml = '', gHtml = '', dHtml = '';

                    contacts.forEach(c => {
                        const id = c.id?._serialized || c.id;
                        if (!id || !String(id).endsWith('@c.us') || memberIds.includes(id)) return;
                        cHtml += `<option value="${id}" data-type="contact">${c.name || c.number || id}</option>`;
                    });

                    groups.forEach(g => {
                        if (String(g.id).indexOf('@g.us') === -1 || memberIds.includes(g.id)) return;
                        gHtml += `<option value="${g.id}" data-type="group">${g.subject || g.id}</option>`;
                    });

                    members.forEach(m => {
                        dHtml += `<option value="${m.recipient_id}" data-type="${m.recipient_type}">${m.recipient_name}</option>`;
                    });

                    sourceContacts.html(cHtml).trigger('change');
                    sourceGroups.html(gHtml).trigger('change');
                    dest.html(dHtml).trigger('change');

                }).always(hideLoader);
            }

            $('.broadcast-add-recipient-btn').on('click', function(e) {
                e.preventDefault();
                const dest = $('.broadcast-destination-recipients');
                ['.broadcast-source-contacts', '.broadcast-source-groups'].forEach(sel => {
                    $(sel).find('option:selected').each(function() {
                        const newOpt = new Option($(this).text(), $(this).val());
                        $(newOpt).data('type', $(this).data('type'));
                        dest.append(newOpt);
                        $(this).remove();
                    });
                });
                dest.trigger('change');
            });

            $('.broadcast-remove-recipient-btn').on('click', function(e) {
                e.preventDefault();
                $('.broadcast-destination-recipients option:selected').remove();
                $('.broadcast-destination-recipients').trigger('change');
            });

            $('#save-recipients-btn').on('click', function() {
                if (!currentListId) return;
                const recipients = [];
                $('.broadcast-destination-recipients option').each(function() {
                    recipients.push({ id: $(this).val(), text: $(this).text(), type: $(this).data('type') });
                });
                showLoader();
                $.post(autwa_ajax.ajax_url, { action: 'autwa_update_list_recipients', nonce: autwa_ajax.nonce, list_id: currentListId, recipients: JSON.stringify(recipients) })
                .done(res => showMessage(res.success ? 'success' : 'error', res.data.message)).always(hideLoader);
            });

            $('#delete-list-btn').on('click', function() {
                if (!currentListId || !confirm(__('Delete list?', 'autowa-whatsapp'))) return;
                showLoader();
                $.post(autwa_ajax.ajax_url, { action: 'autwa_delete_broadcast_list', nonce: autwa_ajax.nonce, list_id: currentListId })
                .done(res => { if(res.success) { currentListId = null; editorPanel.hide(); welcomePanel.show(); loadAllRecipients(); } }).always(hideLoader);
            });
        })();

        // ==========================================
        // SECCIÓN 3: BARRA DE FORMATO Y EMOJIS
        // ==========================================
         (function initTextEditor() {
            const textarea = $('#message-text');
            const toolbar = $('.message-toolbar');
            if (!textarea.length || !toolbar.length) return;

            // Agregar leyenda informativa
            toolbar.before(`<p class="autwa-toolbar-hint" style="font-size: 11px; color: #666; font-style: italic; margin-bottom: 5px;">${__('Selecciona el texto antes de aplicar el formato', 'autowa-whatsapp')}</p>`);

            // Definir tooltips para los botones
            const tooltips = {
                'bold': __('Negrita: Envuelve el texto entre asteriscos (*texto*)', 'autowa-whatsapp'),
                'italic': __('Cursiva: Envuelve el texto entre guiones bajos (_texto_)', 'autowa-whatsapp'),
                'strikethrough': __('Tachado: Envuelve el texto entre virgulillas (~texto~)', 'autowa-whatsapp'),
                'monospace': __('Monoespaciado: Envuelve el texto entre tres comillas (```texto```)', 'autowa-whatsapp'),
                'bullet-list': __('Lista: Añade un asterisco al inicio de cada línea', 'autowa-whatsapp'),
                'numbered-list': __('Numeración: Añade números al inicio de cada línea', 'autowa-whatsapp'),
                'quote': __('Cita: Añade el símbolo "> " al inicio del texto', 'autowa-whatsapp'),
                'link': __('Enlace: Inserta una URL web completa', 'autowa-whatsapp'),
                'variable': __('Variable: Inserta una etiqueta dinámica como {nombre}', 'autowa-whatsapp'),
                'emoji': __('Emojis: Abre el selector de emoticonos', 'autowa-whatsapp')
            };

            // Aplicar tooltips a los botones existentes
            toolbar.find('.format-btn, .emoji-btn').each(function() {
                const format = $(this).data('format') || 'emoji';
                if (tooltips[format]) {
                    $(this).attr('title', tooltips[format]);
                }
            });

            function insertAtCursor(textToInsert, wrapWith = '', isMultiline = false) {
                const start = textarea[0].selectionStart;
                const end = textarea[0].selectionEnd;
                const text = textarea.val();
                const selected = text.substring(start, end);
                let toInsert = textToInsert;

                if (wrapWith) {
                    toInsert = wrapWith + (selected || textToInsert) + wrapWith;
                } else if (isMultiline) {
                    if (selected) {
                        toInsert = selected.split('\n').map(line => textToInsert + line).join('\n');
                    } else {
                        toInsert = textToInsert;
                    }
                }

                textarea.val(text.substring(0, start) + toInsert + text.substring(end));
                const newPos = start + toInsert.length;
                textarea[0].setSelectionRange(newPos, newPos);
                textarea.focus();
            }

            $('.format-btn').on('click', function() {
                const format = $(this).data('format');
                switch(format) {
                    case 'bold': insertAtCursor('', '*'); break;
                    case 'italic': insertAtCursor('', '_'); break;
                    case 'strikethrough': insertAtCursor('', '~'); break;
                    case 'monospace': insertAtCursor('', '```'); break;
                    case 'bullet-list': insertAtCursor('* ', '', true); break;
                    case 'numbered-list': insertAtCursor('1. ', '', true); break;
                    case 'quote': insertAtCursor('> ', '', true); break;
                    case 'link': 
                        const url = prompt(__('Ingrese la URL:', 'autowa-whatsapp'), 'https://');
                        if (url) insertAtCursor(url);
                        break;
                    case 'variable': insertAtCursor('{nombre}'); break;
                }
            });

            // Atajos de teclado y continuación de listas
            textarea.on('keydown', function(e) {
                // Manejo de la tecla Enter para continuación de listas
                if (e.key === 'Enter') {
                    const pos = textarea[0].selectionStart;
                    const text = textarea.val();
                    const lineStart = text.lastIndexOf('\n', pos - 1) + 1;
                    const currentLine = text.substring(lineStart, pos);

                    // Detectar si la línea actual es una lista
                    const bulletMatch = currentLine.match(/^(\*|-) /);
                    const numberMatch = currentLine.match(/^(\d+)\. /);

                    if (bulletMatch || numberMatch) {
                        // Si la línea está vacía (solo el marcador), limpiamos el marcador al dar Enter
                        if (currentLine.trim() === '*' || currentLine.trim() === '-' || /^\d+\.$/.test(currentLine.trim())) {
                            e.preventDefault();
                            const newText = text.substring(0, lineStart) + text.substring(pos);
                            textarea.val(newText);
                            textarea[0].setSelectionRange(lineStart, lineStart);
                        } else {
                            // Continuar la lista
                            e.preventDefault();
                            let nextPrefix = '';
                            if (bulletMatch) {
                                nextPrefix = bulletMatch[0];
                            } else if (numberMatch) {
                                const nextNum = parseInt(numberMatch[1]) + 1;
                                nextPrefix = nextNum + '. ';
                            }
                            const insert = '\n' + nextPrefix;
                            textarea.val(text.substring(0, pos) + insert + text.substring(pos));
                            const newPos = pos + insert.length;
                            textarea[0].setSelectionRange(newPos, newPos);
                        }
                    }
                }

                // Otros atajos rápidos
                if (e.ctrlKey || e.metaKey) {
                    if (e.key.toLowerCase() === 'b') { e.preventDefault(); insertAtCursor('', '*'); }
                    if (e.key.toLowerCase() === 'i') { e.preventDefault(); insertAtCursor('', '_'); }
                }
            });

            // Emoji Picker
            $('.emoji-btn').on('click', function() {
                const emojiCategories = {
                    'Caras': ['😀', '😁', '😂', '🤣', '😃', '😄', '😅', '😆', '😉', '😊', '😋', '😎', '😍', '😘', '🥰', '😗', '😙', '😚', '🙂', '🤗', '🤩', '🤔', '🤨', '😐', '😑', '😶', '🙄', '😏', '😣', '😥', '😮', '🤐', '😯', '😪', '😫', '😴', '😌', '😛', '😜', '😝', '🤤', '😒', '😓', '😔', '😕', '🙃', '🤑', '😲', '🙁', '😖', '😞', '😟', '😤', '😢', '😭', '😦', '😧', '😨', '😩', '🤯', '😬', '😰', '😱', '🥵', '🥶', '😳', '🤪', '😵', '🥴', '😠', '😡', '🤬', '😷', '🤒', '🤕', '🤢', '🤮', '🤧', '🥳', '🥺', '🤠', '🤡', '🤥', '🤫', '🤭', '🧐', '🤓', '😈', '👿', '👹', '👺', '💀', '👻', '👽', '👾', '🤖', '💩'],
                    'Naturaleza': ['😺', '😸', '🐶', '🐺', '🦊', '🐱', '🦁', '🐯', '🐴', '🦄', '🦓', '🐮', '🐷', '🐑', '🐪', '🐘', '🐭', '🐰', '🐻', '🐼', '🐨', '🐸', '🐢', '🐍', '🐳', '🐬', '🐟', '🐙', '🐚', '🦋', '🌸', '🌹', '🌺', '🌻', '🌼', '🌷', '🌱', '🌲', '🌳', '🌴', '🌵', '🌾', '🌿', '☘️', '🍀', '🍁', '🍂', '🍃'],
                    'Símbolos': ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '💔', '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '☮️', '✝️', '☪️', '🕉️', '☸️', '✡️', '🔯', '🕎', '☯️', '☦️', '🛐', '⛎', '♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓', '🆔', '⚛️', '🉑', '☢️', '☣️', '📴', '📳', '🈶', '🈚', '🈸', '🈺', '🈷️', '✴️', '🆚', '💮', '🉐', '㊙️', '㊗️', '🈴', '🈵', '🈹', '🈲', '🅰️', '🅱️', '🆎', '🆑', '🅾️', '🆘', '❌', '⭕', '🛑', '⛔', '📛', '🚫', '💯', '💢', '♨️', '🚷', '🚯', '🚳', '🚱', '🔞', '📵', '🚭', '❗', '❕', '❓', '❔', '‼️', '⁉️', '🔅', '🔆', '〽️', '⚠️', '🚸', '🔱', '⚜️', '🔰', '♻️', '✅', '🈯', '💹', '❇️', '✳️', '❎', '🌐', '💠', 'Ⓜ️', '🌀', '💤', '🏧', '🚾', '♿', '🅿️', '🛗', '🈳', '🛂', '🛃', '🛄', '🛅', '🚹', '🚺', '🚼', '⚧', '🚻', '🚮', '🎦', '📶', '🈁', '🔣', 'ℹ️', '🔤', '🔡', '🔠', '🆖', '🆗', '🆙', '🆒', '🆕', '🆓', '0️⃣', '1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟', '🔢', '#️⃣', '*️⃣', '▶️', '⏸️', '⏯️', '⏹️', '⏺️', '⏭️', '⏮️', '⏩', '⏪', '⏫', '⏬', '◀️', '🔼', '🔽', '➡️', '⬅️', '⬆️', '⬇️', '↗️', '↘️', '↙️', '↖️', '↕️', '↔️', '↪️', '↩️', '⤴️', '⤵️', '🔀', '🔁', '🔂', '🔄', '🔃', '🎵', '🎶', '➕', '➖', '➗', '✖️', '🟰', '♾️', '💲', '💱', '™️', '©️', '®️', '〰️', '➰', '➿', '🔚', '🔙', '🔛', '🔝', '🔜', '✔️', '☑️', '🔘', '⚪', '⚫', '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '🟤', '⬛', '⬜', '🟫', '🟥', '🟧', '🟨', '🟩', '🟦', '🟪', '🔺', '🔻', '🔸', '🔹', '🔶', '🔷', '🔳', '🔲', '▪️', '▫️', '◾', '◽', '◼️', '◻️']
                };

                let phtml = '<div class="emoji-picker"><input type="search" placeholder="Buscar..." class="emoji-search">';
                Object.keys(emojiCategories).forEach(cat => {
                    phtml += `<div class="category"><h4>${cat}</h4><div class="emojis">`;
                    emojiCategories[cat].forEach(e => phtml += `<span class="emoji" data-emoji="${e}">${e}</span>`);
                    phtml += '</div></div>';
                });
                phtml += '</div>';

                $('.emoji-picker').remove();
                $(this).after(phtml);
                $('.emoji-search').on('input', function() {
                    const t = $(this).val().toLowerCase();
                    $('.emoji-picker .emoji').each(function() { $(this).toggle($(this).data('emoji').includes(t)); });
                });
                $('.emoji-picker .emoji').on('click', function() {
                    insertAtCursor($(this).text());
                    $('.emoji-picker').remove();
                });
                setTimeout(() => {
                    $(document).one('click', e => { if (!$(e.target).closest('.emoji-picker, .emoji-btn').length) $('.emoji-picker').remove(); });
                }, 0);
            });
        })();

        // Cargas iniciales
        loadAvailableRecipients();
        loadScheduledMessages();
        loadAllRecipients(false);
    });
})(jQuery);