(function($) {
    "use strict";

    if (!$('body').hasClass('autowa-whatsapp_page_autwa-contacts')) {
        return;
    }

    $(document).ready(function() {
        const { __, sprintf } = wp.i18n;
        
        const loader = $('#autwa-loader');
        const contactsList = $('#contacts-list');
        const groupsList = $('#groups-list');
        const contactsCount = $('#contacts-count');
        const groupsCount = $('#groups-count');
        const contactsPagination = $('#contacts-pagination');
        const groupsPagination = $('#groups-pagination');
        const searchInput = $('#contact-search');
        const navTabs = $('.nav-tab');

        let allContacts = [];
        let allGroups = [];
        let filteredContacts = [];
        let filteredGroups = [];
        let currentPage = 1;
        const itemsPerPage = 20;

        function stringToColor(str) {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                hash = str.charCodeAt(i) + ((hash << 5) - hash);
            }
            const c = (hash & 0x00FFFFFF).toString(16).toUpperCase();
            return '#' + '00000'.substring(0, 6 - c.length) + c;
        }

        function getInitials(name) {
            if (!name) return '?';
            const parts = name.trim().split(' ');
            if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
            return parts[0][0].toUpperCase();
        }

        // --- CARGA DE DATOS (CORREGIDO) ---
        function loadAllData(force = false) {
            loader.show();
            
            // Petición de Contactos
            const p1 = $.post(autwa_ajax.ajax_url, {
                action: 'autwa_get_contacts',
                nonce: autwa_ajax.nonce,
                force_refresh: force
            });

            // Petición de Grupos
            const p2 = $.post(autwa_ajax.ajax_url, {
                action: 'autwa_get_groups',
                nonce: autwa_ajax.nonce,
                force_refresh: force
            });

            $.when(p1, p2).done((r1, r2) => {
                const resContacts = r1[0];
                const resGroups = r2[0];

                // CORRECCIÓN: Accedemos a resContacts.data.contacts
                if (resContacts.success && resContacts.data && resContacts.data.contacts) {
                    allContacts = resContacts.data.contacts;
                } else {
                    allContacts = [];
                }

                // CORRECCIÓN: Accedemos a resGroups.data.groups
                if (resGroups.success && resGroups.data && resGroups.data.groups) {
                    allGroups = resGroups.data.groups;
                } else {
                    allGroups = [];
                }

                renderCurrentView(true);
            }).always(() => {
                loader.hide();
            });
        }

        function renderCurrentView(isSearch = false) {
            const activeTab = $('.nav-tab-active').attr('href');
            const query = searchInput.val().toLowerCase();

            if (activeTab === '#contacts-tab') {
                filteredContacts = allContacts.filter(c => 
                    (c.name && c.name.toLowerCase().includes(query)) || 
                    (c.id && c.id.toLowerCase().includes(query))
                );
                renderList(filteredContacts, contactsList, contactsCount, contactsPagination);
            } else {
                filteredGroups = allGroups.filter(g => 
                    (g.subject && g.subject.toLowerCase().includes(query)) || 
                    (g.id && g.id.toLowerCase().includes(query))
                );
                renderList(filteredGroups, groupsList, groupsCount, groupsPagination);
            }
        }

        function renderList(items, listContainer, countContainer, paginationContainer) {
            listContainer.empty();
            countContainer.text(items.length);

            if (items.length === 0) {
                listContainer.html('<p style="grid-column: 1/-1; text-align:center; padding:40px; color:#666;">No se encontraron resultados.</p>');
                paginationContainer.empty();
                return;
            }

            const startIndex = (currentPage - 1) * itemsPerPage;
            const pageItems = items.slice(startIndex, startIndex + itemsPerPage);

            pageItems.forEach(item => {
                const name = item.name || item.subject || item.id;
                const initials = getInitials(name);
                const color = stringToColor(name);
                const subtext = item.id;
                
                // Resolver imagen de perfil
                const picUrl = item.profile_pic_url || item.picture || null;
                const avatarHtml = picUrl 
                    ? `<img src="${picUrl}" class="autwa-avatar" onerror="this.style.display='none'; $(this).next().show();">`
                    : '';
                const placeholderHtml = `<div class="autwa-avatar" style="background-color:${color}; display: ${picUrl ? 'none' : 'flex'}; align-items:center; justify-content:center; color:white; font-weight:bold;">${initials}</div>`;

                const card = `
                    <div class="autwa-contact-card" data-id="${item.id}">
                        ${avatarHtml}
                        ${placeholderHtml}
                        <div class="autwa-item-content">
                            <h4>${name}</h4>
                            <p>${subtext}</p>
                            ${item.isGroup ? `<span class="autwa-business-badge">${item.participantCount || 0} participantes</span>` : ''}
                        </div>
                    </div>
                `;
                listContainer.append(card);
            });

            renderPagination(items.length, paginationContainer);
        }

        function renderPagination(totalItems, container) {
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            if (totalPages <= 1) {
                container.empty();
                return;
            }

            let html = '<div class="autwa-pagination">';
            if (currentPage > 1) html += `<a class="button page-numbers" href="#" data-page="${currentPage - 1}">«</a>`;
            
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    const currentClass = i === currentPage ? 'current' : '';
                    html += `<a class="button page-numbers ${currentClass}" href="#" data-page="${i}">${i}</a>`;
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                     html += `<span class="page-numbers dots">...</span>`;
                }
            }
            if (currentPage < totalPages) html += `<a class="button page-numbers" href="#" data-page="${currentPage + 1}">»</a>`;
            html += '</div>';
            container.html(html);
        }

     
        navTabs.on('click', function(e) {
            e.preventDefault();

            const targetTab = $(this).attr('href'); // "#contacts-tab" o "#groups-tab"

            // 1. Estado visual del menú
            navTabs.removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            // 2. Mostrar/ocultar paneles 
            $('.tab-content').removeClass('active');
            $(targetTab).addClass('active');

            // 3. Resetear paginación y re-renderizar la vista activa
            currentPage = 1;
            renderCurrentView();
        });

        $(document).on('click', '.page-numbers[data-page]', function(e) {
            e.preventDefault();
            currentPage = parseInt($(this).data('page'));
            renderCurrentView();
            window.scrollTo(0, 0);
        });

        $('#refresh-contacts-btn').on('click', () => loadAllData(true));

        searchInput.on('input', function() {
            currentPage = 1;
            renderCurrentView();
        });

        loadAllData(false);
    });
})(jQuery);