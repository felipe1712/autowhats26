jQuery(document).ready(function($) {
    'use strict';
     this.isFetching = false;
    /**
     * Clase principal para la gesti¨®n de la interfaz de chats de AutoWA.
     */
      class AutoWAChats {
        constructor() {
            this.currentChatId = null;
            this.currentSession = 'default';
            this.messagesPollInterval = null;
            this.fullSyncInterval = null;         
            this.lastSeenMessageId = null;          
            this.allChats = []; // Almacenamiento local para filtrado r¨¢pido
            this.isFetching = false; // Bandera para evitar peticiones solapadas
            this.currentAttachment = null; // Archivo pendiente de enviar

            // Vincular m¨¦todos al contexto de la clase
            this.sendMessage = this.sendMessage.bind(this);
            this.checkNewMessages = this.checkNewMessages.bind(this);

            this.cacheDOM();
            this.createImageModal();
            this.bindEvents();
            this.init();
        }

        cacheDOM() {
            this.elements = {
                chatsList: $('#chats-list'),
                messagesList: $('#messages-list'),
                messageInput: $('#message-input'),
                sendBtn: $('#send-message-btn'),
                chatView: $('#chat-view'),
                chatWelcome: $('#chat-welcome'),
                refreshBtn: $('#refresh-chats-btn'),
                searchInput: $('#chat-search-input'),
                loader: $('#autwa-loader')
                
            };
            if ($('#autwa-chat-file-input').length === 0) {
            $('body').append('<input type="file" id="autwa-chat-file-input" style="display:none;">');
            }
        }

        /**
         * Crea el contenedor del modal para visualizar im¨¢genes a pantalla completa.
         */
        createImageModal() {
            if ($('#autwa-image-modal').length === 0) {
                $('body').append(`
                    <div id="autwa-image-modal" style="display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.95); align-items:center; justify-content:center; flex-direction:column;">
                        <span id="autwa-close-modal" style="position:absolute; top:20px; right:35px; color:#f1f1f1; font-size:40px; font-weight:bold; cursor:pointer;">&times;</span>
                        <img id="autwa-modal-img-content" style="margin:auto; display:block; max-width:85%; max-height:85%; border-radius:4px; box-shadow: 0 0 20px rgba(0,0,0,0.5);">
                        <div id="autwa-modal-caption" style="margin-top:15px; color:#ccc; font-size:14px;"></div>
                    </div>
                `);

                $('#autwa-close-modal').on('click', () => $('#autwa-image-modal').fadeOut());
                $(window).on('click', (e) => {
                    if (e.target.id === 'autwa-image-modal') $('#autwa-image-modal').fadeOut();
                });
            }
        }

        bindEvents() {
            // Selecci¨®n de chat
            this.elements.chatsList.on('click', '.autwa-list-item', (e) => {
                const $item = $(e.currentTarget);
                this.selectChat($item.data('id'), $item.find('.autwa-chat-name').text());
            });

            // Env¨ªo de mensaje
            this.elements.sendBtn.on('click', this.sendMessage);
            this.elements.messageInput.on('keypress', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });

            // Bot¨®n de refrescar forzado
            this.elements.refreshBtn.on('click', () => this.fetchChats(true));

            // B¨²squeda instant¨¢nea en la lista de chats
            if (this.elements.searchInput.length) {
                this.elements.searchInput.on('input', (e) => this.filterChats(e.target.value));
            }

            // Clip: abrir selector de archivos (delegado porque se inyecta din¨¢micamente)
            $(document).on('click', '#autwa-attach-btn', (e) => {
                e.preventDefault();
                $('#autwa-chat-file-input').trigger('click');
            });

            // Quitar adjunto pendiente
            $(document).on('click', '#autwa-attach-remove', (e) => {
                e.preventDefault();
                this.clearAttachment();
            });

            // Archivo seleccionado
            $('#autwa-chat-file-input').on('change', (e) => {
                const file = e.target.files && e.target.files[0];
                if (file) this.setAttachment(file);
                // Reset para permitir re-seleccionar el mismo archivo
                $(e.target).val('');
            });
        }

        async init() {
            try {
                // Obtener configuraci¨®n de la sesi¨®n antes de cargar nada
                const response = await $.post(autwa_ajax.ajax_url, {
                    action: 'autwa_get_current_config',
                    nonce: autwa_ajax.nonce
                });
                
                if (response.success && response.data && response.data.config) {
                    this.currentSession = response.data.config.session_name || 'default';
                    this.fetchChats();
                }
            } catch (error) {
                console.error("AutoWA: Error de inicializaci¨®n", error);
            }
        }
         
         /**
         * Inyecta header y barra de herramientas (clip) sobre el ¨¢rea de input.
         * Se ejecuta cada vez que se abre un chat; usa selectores que S01 existen
         * en autowhats-admin-pages.php (.autwa-message-input-area).
         */
        renderChatUI(chatName) {
            // Header del chat
            if ($('.autwa-chat-header').length === 0) {
                this.elements.chatView.prepend(
                    `<div class="autwa-chat-header"><h3 id="chat-header-name">${chatName}</h3></div>`
                );
            } else {
                $('#chat-header-name').text(chatName);
            }

            // Barra del clip: se inserta JUSTO ANTES del ¨¢rea de input existente
            if ($('#autwa-attach-btn').length === 0) {
                const toolbarHtml = `
                    <div class="autwa-chat-footer-toolbar" style="padding: 6px 15px; display: flex; align-items:center; gap: 10px; background: #f0f2f5; border-top: 1px solid #ddd;">
                        <button type="button" id="autwa-attach-btn" class="button" title="Adjuntar archivo" style="background:none; border:none; padding:5px; cursor:pointer;">
                            <span class="dashicons dashicons-paperclip" style="font-size:20px; color:#54656f;"></span>
                        </button>
                        <div id="autwa-attach-preview" style="display:none; flex:1; align-items:center; gap:8px; font-size:12px; color:#333;">
                            <span class="dashicons dashicons-media-default"></span>
                            <span id="autwa-attach-name" style="flex:1; white-space:nowrap; text-overflow:ellipsis; overflow:hidden;"></span>
                            <a href="#" id="autwa-attach-remove" title="Quitar" style="color:#c00; text-decoration:none;">&times;</a>
                        </div>
                    </div>`;

                // El HTML real tiene .autwa-message-input-area como contenedor del input
                $('.autwa-message-input-area').before(toolbarHtml);
            }
        }  

        fetchChats(force = false) {
            this.elements.loader.show();
            $.post(autwa_ajax.ajax_url, {
                action: 'autwa_get_chats',
                nonce: autwa_ajax.nonce,
                force_refresh: force
            })
            .done(response => {
                // CORRECCI07N: Acceso correcto al anidamiento data.chats
                if (response.success && response.data && response.data.chats) {
                    this.allChats = response.data.chats;
                    this.renderChats(this.allChats);
                } else {
                    this.elements.chatsList.html(`<p style="padding:20px; text-align:center;">${(response.data && response.data.message) || 'No se pudieron cargar los chats.'}</p>`);
                }
            })
            .fail(() => this.showError('Error de red al intentar cargar los chats.'))
            .always(() => this.elements.loader.hide());
        }

        filterChats(query) {
            if (!query) return this.renderChats(this.allChats);
            const q = query.toLowerCase();
            const filtered = this.allChats.filter(chat => 
                (chat.name && chat.name.toLowerCase().includes(q)) || 
                (chat.id && chat.id.toLowerCase().includes(q))
            );
            this.renderChats(filtered);
        }

        renderChats(chats) {
            this.elements.chatsList.empty();
            chats.forEach(chat => {
                const rawPicUrl = chat.picture || `https://ui-avatars.com/api/?name=${encodeURIComponent(chat.name || 'Chat')}&background=random`;
                const picUrl = this.safeUrl(rawPicUrl) || `https://ui-avatars.com/api/?name=${encodeURIComponent(chat.name || 'Chat')}&background=random`;
                const lastMsg = chat.lastMessage ? this.escapeHtml(chat.lastMessage.body) : '';
                const time = chat.lastMessage ? this.escapeHtml(this.formatTime(chat.lastMessage.timestamp)) : '';
                const activeClass = chat.id === this.currentChatId ? 'active' : '';
                const safeChatId = this.escapeHtml(chat.id);
                const safeChatName = this.escapeHtml(chat.name || chat.id);

                const html = `
                    <div class="autwa-list-item ${activeClass}" data-id="${safeChatId}">
                        <img src="${picUrl}" class="autwa-avatar" onerror="this.src='https://ui-avatars.com/api/?name=?&background=ccc'">
                        <div class="autwa-item-content">
                            <div class="autwa-chat-header-row">
                                <h4 class="autwa-chat-name">${safeChatName}</h4>
                                <span class="autwa-chat-time">${time}</span>
                            </div>
                            <div class="autwa-chat-message-row">
                                <p class="autwa-last-message">${lastMsg}</p>
                            </div>
                        </div>
                    </div>`;
                this.elements.chatsList.append(html);
            });
        }

    selectChat(chatId, chatName) {
            if (this.currentChatId === chatId) return;
            this.currentChatId = chatId;

            $('.autwa-list-item').removeClass('active');
            $(`.autwa-list-item[data-id="${chatId}"]`).addClass('active');

            this.elements.chatWelcome.hide();
            this.elements.chatView.show();

            this.renderChatUI(chatName);
            this.clearAttachment();

            this.fetchMessages(chatId, true);

            // 8¬2 DOS pollings con cadencias diferentes:
            // - r¨¢pido (3.5s): mira el buffer de push (n8n ¡ú /incoming-message)
            // - lento (15s): hace un re-fetch completo a WAHA por si el buffer fall¨®
            if (this.messagesPollInterval) clearInterval(this.messagesPollInterval);
            if (this.fullSyncInterval)     clearInterval(this.fullSyncInterval);

            this.lastSeenMessageId = null;
            this.messagesPollInterval = setInterval(this.checkNewMessages, 3500);
            this.fullSyncInterval     = setInterval(() => {
                if (this.currentChatId && !this.isFetching) {
                    this.fetchMessages(this.currentChatId, false);
                }
            }, 15000);
        }

        fetchMessages(chatId, showSpinner = false) {
            if (this.isFetching) return;
            this.isFetching = true;

            if (showSpinner) {
                this.elements.messagesList.html('<div class="autwa-spinner-small" style="margin-top:20px;"></div>');
            }

            $.post(autwa_ajax.ajax_url, {
                action:  'autwa_get_chat_messages',
                nonce:   autwa_ajax.nonce,
                chat_id: chatId,
            })
            .done(res => {
                if (!res.success || !res.data || !Array.isArray(res.data.messages)) {
                    return;
                }

                const incoming = res.data.messages;
                if (!incoming.length) {
                    this.elements.messagesList.empty();
                    return;
                }

                const lastIncomingId = incoming[incoming.length - 1].id;
                const $lastOnScreen  = $('.autwa-message').last();
                const lastOnScreenId = $lastOnScreen.length ? $lastOnScreen.attr('data-id') : null;

                if (lastIncomingId !== lastOnScreenId) {
                    this.renderMessages(incoming);
                    this.lastSeenMessageId = lastIncomingId;
                }
            })
            .always(() => { this.isFetching = false; });
        }

        renderMessages(messages) {
            this.elements.messagesList.empty();
            let lastDate = '';

            messages.forEach(msg => {
                const msgDate = new Date(msg.timestamp * 1000).toDateString();
                if (msgDate !== lastDate) {
                    this.elements.messagesList.append(`<div class="autwa-date-separator"><span class="autwa-date-text">${this.formatDate(msg.timestamp)}</span></div>`);
                    lastDate = msgDate;
                }

                const sideClass = msg.fromMe ? 'from-me' : 'from-them';
                const time = this.escapeHtml(this.formatTime(msg.timestamp));
                
                let mediaHtml = '';
                if (msg.hasMedia) {
                    // ESCAPE DE IDS: Importante usar encodeURIComponent para IDs con caracteres especiales
                    const safeMsgId = encodeURIComponent(msg.id);
                    const safeChatId = encodeURIComponent(this.currentChatId);
                    const rawMediaUrl = `${autwa_ajax.ajax_url}?action=autwa_get_media_file&message_id=${safeMsgId}&chat_id=${safeChatId}&nonce=${autwa_ajax.nonce}&visualize=true`;
                    const mediaUrl = this.safeUrl(rawMediaUrl);
                    
                    const isImage = msg.type === 'image' || (msg.mimetype && msg.mimetype.includes('image'));
                    const isVideo = msg.type === 'video' || (msg.mimetype && msg.mimetype.includes('video'));
                    const isAudio = ['audio', 'ptt'].includes(msg.type) || (msg.mimetype && msg.mimetype.includes('audio'));

                    if (isImage) {
                        mediaHtml = `<div class="autwa-message-media"><img src="${mediaUrl}" class="autwa-message-image" data-full="${mediaUrl}" style="max-width:100%; border-radius:5px; cursor:pointer;"></div>`;
                    } else if (isVideo) {
                        mediaHtml = `<div class="autwa-message-media"><video src="${mediaUrl}" controls style="max-width:100%; border-radius:5px;"></video></div>`;
                    } else if (isAudio) {
                        mediaHtml = `<div class="autwa-message-audio" style="padding:5px 0;"><audio src="${mediaUrl}" controls style="max-width:100%;"></audio></div>`;
                    } else {
                        const escapedFilename = this.escapeHtml(msg.filename || 'Documento');
                        mediaHtml = `
                            <div class="autwa-message-document" style="background:rgba(0,0,0,0.05); padding:10px; border-radius:5px; display:flex; align-items:center; gap:10px;">
                                <span class="dashicons dashicons-media-default"></span>
                                <div style="flex:1; overflow:hidden;">
                                    <p style="margin:0; font-size:12px; white-space:nowrap; text-overflow:ellipsis; overflow:hidden;">${escapedFilename}</p>
                                    <a href="${mediaUrl}&visualize=false" style="font-size:11px; text-decoration:underline;" target="_blank">Descargar</a>
                                </div>
                            </div>`;
                    }
                }

                const safeMsgId = this.escapeHtml(msg.id);
                const html = `
                    <div class="autwa-message ${sideClass}" data-id="${safeMsgId}">
                        <div class="autwa-message-content">
                            ${mediaHtml}
                            ${msg.body ? `<p class="autwa-message-text">${this.linkify(msg.body)}</p>` : ''}
                            <div class="autwa-message-meta" style="text-align:right;">
                                <span class="autwa-message-timestamp" style="font-size:10px; color:#888;">${time}</span>
                            </div>
                        </div>
                    </div>`;
                this.elements.messagesList.append(html);
            });
            
            this.scrollToBottom();

            // Vincular Lightbox
            $('.autwa-message-image').off('click').on('click', function() {
                const url = $(this).data('full');
                $('#autwa-modal-img-content').attr('src', url);
                $('#autwa-image-modal').fadeIn().css('display', 'flex');
            });
        }

       checkNewMessages() {
            if (!this.currentChatId || this.isFetching) return;

            $.post(autwa_ajax.ajax_url, {
                action:  'autwa_get_recent_buffer',
                nonce:   autwa_ajax.nonce,
                chat_id: this.currentChatId,
            }).done(res => {
                if (!res.success || !res.data || !res.data.messages || !res.data.messages.length) {
                    return;
                }

                // 8¬2 Comparar contra un ID "¨²ltimo visto" que mantenemos en memoria,
                // no contra el DOM (que puede tener data-id sin setear).
                const newest = res.data.messages[res.data.messages.length - 1];
                if (!newest || !newest.id) return;

                if (this.lastSeenMessageId !== newest.id) {
                    this.lastSeenMessageId = newest.id;
                    this.fetchMessages(this.currentChatId, false);
                }
            });
        }

     /**
         * Guarda el archivo seleccionado y muestra el preview en la toolbar.
         */
        setAttachment(file) {
            const MAX_BYTES = 16 * 1024 * 1024; // 16 MB, l¨ªmite t¨ªpico de WhatsApp
            if (file.size > MAX_BYTES) {
                alert('El archivo supera 16 MB y WhatsApp no lo aceptar¨¢.');
                return;
            }
            this.currentAttachment = file;
            $('#autwa-attach-name').text(file.name);
            $('#autwa-attach-preview').css('display', 'flex');
        }

        /**
         * Limpia el adjunto pendiente y oculta el preview.
         */
        clearAttachment() {
            this.currentAttachment = null;
            $('#autwa-attach-name').text('');
            $('#autwa-attach-preview').hide();
            $('#autwa-chat-file-input').val('');
        }

        sendMessage() {
    const body = this.elements.messageInput.val().trim();
    if (!body && !this.currentAttachment) return;

    this.elements.loader.show();
    
    if (this.currentAttachment) {
        const formData = new FormData();
        formData.append('action', 'autwa_send_message');
        formData.append('nonce', autwa_ajax.nonce);
        formData.append('chat_id', this.currentChatId);
        formData.append('message', body);
        formData.append('file', this.currentAttachment);

        $.ajax({
            url: autwa_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: (res) => {
                this.clearAttachment();
                this.elements.messageInput.val('');
                this.fetchMessages(this.currentChatId, false);
            },
            complete: () => this.elements.loader.hide()
        });
    } else {
        $.post(autwa_ajax.ajax_url, { 
            action: 'autwa_send_message', 
            nonce: autwa_ajax.nonce, 
            chat_id: this.currentChatId, 
            message: body 
        })
        .done(() => {
            this.elements.messageInput.val('');
            this.fetchMessages(this.currentChatId, false);
        })
        .always(() => this.elements.loader.hide());
    }
}

        scrollToBottom() {
            const el = this.elements.messagesList[0];
            if(el) el.scrollTop = el.scrollHeight;
        }

        formatTime(timestamp) {
            const date = new Date(timestamp * 1000);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        formatDate(timestamp) {
            const date = new Date(timestamp * 1000);
            return date.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
        }

        escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        safeUrl(url) {
            if (!url) return '';
            const cleaned = url.trim();
            if (cleaned.startsWith('javascript:') || cleaned.startsWith('data:text/html')) {
                return '';
            }
            if (/^(https?:\/\/|data:image\/)/i.test(cleaned)) {
                return cleaned;
            }
            if (cleaned.startsWith('/') || cleaned.startsWith('./') || cleaned.startsWith('../') || cleaned.startsWith('?')) {
                return cleaned;
            }
            return '';
        }

        linkify(text) {
            if (!text) return '';
            const escaped = this.escapeHtml(text);
            const urlRegex = /(https?:\/\/[^\s<>"]+)/g;
            return escaped.replace(urlRegex, (url) => {
                const safe = this.safeUrl(url);
                return safe ? `<a href="${safe}" target="_blank" rel="noopener noreferrer">${safe}</a>` : url;
            }).replace(/\n/g, '<br>');
        }

        showError(msg) {
            $('#autwa-messages').html(`<div class="notice notice-error is-dismissible"><p>${this.escapeHtml(msg)}</p></div>`);
        }
    }

    new AutoWAChats();
});