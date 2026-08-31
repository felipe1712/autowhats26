<?php
/**
 * This file handles the rendering of the administration pages
 * for the AutoWA WhatsApp plugin.
 * Refactored and internationalized version.
 */

if (!defined('ABSPATH')) {
    exit; 
}

class AutoWA_Admin_Pages {

    private $plugin_instance;

    public function __construct($plugin_instance) {
        $this->plugin_instance = $plugin_instance;
        add_action('admin_init', array($this, 'handle_settings_save'));
    }

    public function handle_settings_save() {
        // Guardar ajustes de sesión principales
        if (isset($_POST['submit']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'autwa_settings_nonce')) {
       
            // Si el usuario no es administrador, se detiene la ejecución.
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to save these settings.', 'autowa-whatsapp'));
            }
            $this->save_session_settings();
        }
        // --- GUARDAR AJUSTES DE WOOCOMMERCE ---
        if (isset($_POST['save_woo_settings']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'autwa_woo_settings_nonce')) {
            if (!current_user_can('manage_options')) return;

            // Guardar configuración general
            update_option('autwa_woo_enabled', isset($_POST['autwa_woo_enabled']) ? 1 : 0);
            update_option('autwa_woo_admin_phone', sanitize_text_field($_POST['autwa_woo_admin_phone']));

            // Guardar configuración de cada evento (usando el nuevo método get_events)
            if (class_exists('AutoWA_WooCommerce')) {
                foreach (AutoWA_WooCommerce::get_events() as $key => $label) {
                    update_option("autwa_woo_event_{$key}_active", isset($_POST["autwa_woo_event_{$key}_active"]) ? 1 : 0);
                    // Permitimos algo de HTML en templates, o usamos sanitize_textarea_field para texto plano
                    update_option("autwa_woo_event_{$key}_template", sanitize_textarea_field($_POST["autwa_woo_event_{$key}_template"]));
                }
            }
            
            add_settings_error('autwa_messages', 'autwa_message', __('WooCommerce settings saved.', 'autowa-whatsapp'), 'updated');
        }
        // --- GUARDAR AJUSTES DE SEGURIDAD ---
        if (isset($_POST['save_sec_settings']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'autwa_sec_settings_nonce')) {
            if (!current_user_can('manage_options')) return;

            update_option('autwa_sec_enabled', isset($_POST['autwa_sec_enabled']) ? 1 : 0);
            update_option('autwa_sec_admin_phone', sanitize_text_field($_POST['autwa_sec_admin_phone']));

            if (class_exists('AutoWA_Security')) {
                foreach (AutoWA_Security::get_events() as $key => $label) {
                    update_option("autwa_sec_event_{$key}_active", isset($_POST["autwa_sec_event_{$key}_active"]) ? 1 : 0);
                    update_option("autwa_sec_event_{$key}_template", sanitize_textarea_field($_POST["autwa_sec_event_{$key}_template"]));
                }
            }
            add_settings_error('autwa_messages', 'autwa_message', __('Security settings saved.', 'autowa-whatsapp'), 'updated');
        }
    }

    public function render_config_page() {
        // Variable para comprobar permisos y deshabilitar campos
        $can_edit_settings = current_user_can('manage_options');
        $disabled_attr = $can_edit_settings ? '' : 'disabled';
        ?>
        <div class="wrap autwa-wrap">
            <?php if (!$can_edit_settings) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><strong><?php esc_html_e('Read-only Mode', 'autowa-whatsapp'); ?>:</strong> <?php esc_html_e('You can view the settings, but you do not have permission to modify them.', 'autowa-whatsapp'); ?></p>
                </div>
            <?php endif; ?>

            <h1><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('AutoWA - General Settings', 'autowa-whatsapp'); ?></h1>

            <?php
            if (isset($_GET['settings-updated'])) {
                 echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'autowa-whatsapp') . '</p></div>';
            }
            if (isset($_GET['key-updated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('New security key generated. Remember to update the URL in your cron service.', 'autowa-whatsapp') . '</p></div>';
           }
            ?>

            <nav class="nav-tab-wrapper">
                <a href="#sesion" class="nav-tab"><?php esc_html_e('Session', 'autowa-whatsapp'); ?></a>
                <a href="#herramientas" class="nav-tab"><?php esc_html_e('Tools', 'autowa-whatsapp'); ?></a>
                <a href="#woocommerce" class="nav-tab"><?php esc_html_e('WooCommerce', 'autowa-whatsapp'); ?></a>
                <a href="#security" class="nav-tab"><?php esc_html_e('Security', 'autowa-whatsapp'); ?></a>
            </nav>

            <div id="sesion" class="tab-content">
                <?php $this->render_tab_sesion($disabled_attr); ?>
            </div>
            <div id="herramientas" class="tab-content">
                <?php $this->render_tab_herramientas($disabled_attr); ?>
            </div>
            <div id="woocommerce" class="tab-content">
            <?php $this->render_tab_woocommerce($disabled_attr); ?>
            </div>
            <div id="security" class="tab-content">
            <?php $this->render_tab_security($disabled_attr); ?>
        </div>
            
        </div>
        <?php
        
    }

    private function save_session_settings() {
    $user_provided_name = sanitize_text_field($_POST['autwa_session_name']);
    update_option('autwa_session_base_name', $user_provided_name);
    
    $site_host = parse_url(get_site_url(), PHP_URL_HOST);
    $site_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $site_host));
    $random_number = rand(1, 1000);

    $full_session_name = $user_provided_name . $site_slug . $random_number;

    if (strlen($full_session_name) > 24) {
        $final_session_name = substr($full_session_name, 0, 24);
    } else {
        $final_session_name = $full_session_name;
    }
    
    // Guardamos la clave de licencia
    update_option('autwa_edd_license_key', sanitize_text_field($_POST['autwa_edd_license_key']));

    // Guardamos la URL de webhook n8n
    if (isset($_POST['autwa_n8n_webhook_url'])) {
        update_option('autwa_n8n_webhook_url', esc_url_raw($_POST['autwa_n8n_webhook_url']));
    }

    delete_option('autwa_api_key');
    delete_option('autwa_webhook_url');
    update_option('autwa_session_name', $final_session_name);
    
    wp_redirect(admin_url('admin.php?page=autwa-whatsapp&settings-updated=true#sesion'));
    exit;
}

   private function render_tab_sesion($disabled_attr) {

    //Campo para la licencia de EDD.
    $edd_license_key = get_option('autwa_edd_license_key', '');

    $session_base_name = get_option('autwa_session_base_name', 'default');
    $session_full_name = get_option('autwa_session_name', 'default');
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('autwa_settings_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row" style="color: #0073aa; font-weight: bold;"><?php esc_html_e('AutoWhats License Key', 'autowa-whatsapp'); ?></th>
                <td>
    <input type="password" id="autwa_edd_license_key" name="autwa_edd_license_key" value="<?php echo esc_attr(get_option('autwa_edd_license_key', '')); ?>" class="regular-text" required <?php echo $disabled_attr; ?> />
    <p class="description"><?php esc_html_e('Enter the license key you received with your purchase.', 'autowa-whatsapp'); ?></p>

    <div id="autwa-license-controls" style="margin-top: 10px;">
        <button type="button" id="autwa-activate-license-btn" class="button button-secondary" data-action="activate" <?php echo $disabled_attr; ?>>
            <?php esc_html_e('Activate License', 'autowa-whatsapp'); ?>
        </button>
        <button type="button" id="autwa-deactivate-license-btn" class="button button-secondary" data-action="deactivate" <?php echo $disabled_attr; ?>>
            <?php esc_html_e('Deactivate License', 'autowa-whatsapp'); ?>
        </button>
        <span id="autwa-license-status" style="margin-left: 10px; font-weight: bold; vertical-align: middle;"></span>
        <span class="spinner" style="float: none; vertical-align: middle;"></span>
    </div>
</td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Session Name', 'autowa-whatsapp'); ?></th>
                <td>
                    <input type="text" name="autwa_session_name" value="<?php echo esc_attr($session_base_name); ?>" class="regular-text" required <?php echo $disabled_attr; ?> />
                    <p class="description"><?php esc_html_e('Unique name for your session (max 24 characters).', 'autowa-whatsapp'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('n8n Webhook URL', 'autowa-whatsapp'); ?></th>
                <td>
                    <input type="url" name="autwa_n8n_webhook_url" value="<?php echo esc_url(get_option('autwa_n8n_webhook_url', 'https://n8n.autowhats.com.mx/webhook/84ccc3da-be97-4fc8-b9e1-7845b09e88a1')); ?>" class="large-text" required <?php echo $disabled_attr; ?> />
                    <p class="description"><?php esc_html_e('Configured n8n Webhook URL for this plugin instance.', 'autowa-whatsapp'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Webhook Secret (Read-only)', 'autowa-whatsapp'); ?></th>
                <td>
                    <input type="text" value="<?php echo esc_attr(get_option('autwa_webhook_secret')); ?>" class="regular-text" readonly onclick="this.select();" />
                    <p class="description"><?php esc_html_e('Copy this secret to your n8n workflow configuration for HMAC signature verification.', 'autowa-whatsapp'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Save Settings', 'autowa-whatsapp'), 'primary', 'submit', true, $disabled_attr); ?>
    </form>

    <div class="autwa-session-control" style="position: relative;">
    <div id="autwa-session-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.7); z-index: 10; display: flex; align-items: center; justify-content: center; text-align: center; border-radius: 4px;">
        <p style="font-weight: bold; color: #555; padding: 20px; background: rgba(240,240,240,0.9); border-radius: 8px;">
            <?php esc_html_e('Waiting for a valid license key to manage the session...', 'autowa-whatsapp'); ?>
        </p>
    </div>

    <h2><?php esc_html_e('Session Control', 'autowa-whatsapp'); ?></h2>
    <div id="session-status">
        <p><strong><?php esc_html_e('Current status:', 'autowa-whatsapp'); ?></strong> <span id="current-status" style="font-weight: bold; color: #666;"><?php esc_html_e('---', 'autowa-whatsapp'); ?></span></p>
        <p><strong><?php esc_html_e('Session name:', 'autowa-whatsapp'); ?></strong> <span id="current-session-name" style="font-weight: bold; color: #0073aa;"><?php echo esc_html($session_full_name); ?></span></p>
    </div>
    <div class="session-buttons">
        <button type="button" id="test-connection" class="button" disabled><?php esc_html_e('Test Connection', 'autowa-whatsapp'); ?></button>
        <button type="button" id="verify-config" class="button" disabled><?php esc_html_e('Verify Configuration', 'autowa-whatsapp'); ?></button>
        <button type="button" id="create-start-session" class="button button-primary" disabled><?php esc_html_e('Create & Start Session', 'autowa-whatsapp'); ?></button>
        <button type="button" id="restart-session" class="button" disabled><?php esc_html_e('Restart Session', 'autowa-whatsapp'); ?></button>
        <button type="button" id="get-qr" class="button" disabled><?php esc_html_e('Get QR', 'autowa-whatsapp'); ?></button>
        <button type="button" id="check-status" class="button" disabled><?php esc_html_e('Check Status', 'autowa-whatsapp'); ?></button>
    </div>
    <div id="qr-container" style="display: none;">
       <div class="qr-section">
            <h3><?php esc_html_e('Link your WhatsApp', 'autowa-whatsapp'); ?></h3>
            <div class="qr-instructions">
                <p><?php esc_html_e('Open WhatsApp on your phone and go to:', 'autowa-whatsapp'); ?></p>
                <ol>
                    <li><strong><?php esc_html_e('Settings', 'autowa-whatsapp'); ?></strong> > <strong><?php esc_html_e('Linked Devices', 'autowa-whatsapp'); ?></strong></li>
                    <li><?php esc_html_e('Tap on "Link a Device"', 'autowa-whatsapp'); ?></li>
                    <li><?php esc_html_e('Point your phone at this screen', 'autowa-whatsapp'); ?></li>
                </ol>
            </div>
            
            <div id="qr-code-wrapper" class="qr-code-wrapper">
                <div class="qr-overlay">
                    <div class="qr-spinner"></div>
                    <p><?php esc_html_e('Generating QR code...', 'autowa-whatsapp'); ?></p>
                </div>
            </div>

            <div class="qr-status">
                <p><?php esc_html_e('Status:', 'autowa-whatsapp'); ?> <span id="qr-countdown"><?php esc_html_e('Waiting for stream...', 'autowa-whatsapp'); ?></span></p>
            </div>
        </div>
    </div>
    <div id="response-messages" style="margin-top: 15px;"></div>
</div>
    <?php
    
      if ( isset($_GET['clear_autwa_error']) && $_GET['clear_autwa_error'] === 'true' ) {
        delete_transient('autwa_last_fatal_error');
        // Redirigimos para limpiar la URL
        wp_safe_redirect(admin_url('admin.php?page=autwa-whatsapp#sesion'));
        exit;
    }

    // Ahora, intentamos obtener el error guardado
    $last_error = get_transient('autwa_last_fatal_error');

    // Si existe un error, lo mostramos
    if ( $last_error ) {
        echo '<div class="autwa-card" style="margin-top: 30px; border-color: #dc3545; border-width: 2px;">';
        echo '<h2 style="color: #dc3545;"><span class="dashicons dashicons-warning"></span> Último Error Fatal Capturado</h2>';
        echo '<p>El siguiente error fatal ocurrió durante la última petición AJAX. Esto es probablemente la causa del problema:</p>';
        echo '<pre style="background: #fdf2f2; color: #721c24; padding: 15px; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word;">';
        echo esc_html( $last_error );
        echo '</pre>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=autwa-whatsapp&clear_autwa_error=true')) . '" class="button">Limpiar este mensaje y reintentar</a></p>';
        echo '</div>';
    }
   
    ?>
<?php
}


    private function render_tab_herramientas($disabled_attr) {
        ?>
        <div class="autwa-tools-container">
            <div class="autwa-card" style="margin-bottom: 20px;">
                <h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e('Time Diagnostics', 'autowa-whatsapp'); ?></h3>
                <p><?php esc_html_e('Use this section to check the different time settings. For scheduled sends to work correctly, the "WordPress Time" should match your local time zone.', 'autowa-whatsapp'); ?></p>
                <table class="form-table">
                    <tbody>
                        <tr><th scope="row"><?php esc_html_e('Server Time (UTC/GMT)', 'autowa-whatsapp'); ?></th><td><code><?php echo esc_html(gmdate('Y-m-d H:i:s')); ?></code></td></tr>
                        <tr><th scope="row"><?php esc_html_e('Server Timezone (PHP)', 'autowa-whatsapp'); ?></th><td><code><?php echo esc_html(date_default_timezone_get()); ?></code></td></tr>
                        <tr><th scope="row"><?php esc_html_e('WordPress Time (Configured)', 'autowa-whatsapp'); ?></th><td><code><?php echo esc_html(current_time('Y-m-d H:i:s')); ?></code><p class="description"><?php esc_html_e('This is the time the plugin uses for sending.', 'autowa-whatsapp'); ?></p></td></tr>
                        <tr><th scope="row"><?php esc_html_e('WordPress Timezone', 'autowa-whatsapp'); ?></th><td><code><?php echo esc_html(get_option('timezone_string') ?: __('Not configured (using UTC offset)', 'autowa-whatsapp')); ?></code><p class="description"><?php printf(esc_html__('You can change it in %s.', 'autowa-whatsapp'), '<a href="' . esc_url(admin_url('options-general.php')) . '" target="_blank">' . esc_html__('Settings > General', 'autowa-whatsapp') . '</a>'); ?></p></td></tr>
                        <tr><th scope="row"><?php esc_html_e('Your Browser Time (Local)', 'autowa-whatsapp'); ?></th><td><code id="autwa-browser-time"><?php esc_html_e('Loading...', 'autowa-whatsapp'); ?></code></td></tr>
                    </tbody>
                </table>
            </div>
            
            <div class="autwa-cleanup-section">
                <h2><?php esc_html_e('Cleanup Tools', 'autowa-whatsapp'); ?></h2>
                <div class="autwa-tool-group"><h3><?php esc_html_e('Clear Logs', 'autowa-whatsapp'); ?></h3><p><?php esc_html_e('Deletes all log records.', 'autowa-whatsapp'); ?></p><button type="button" id="cleanup-logs" class="button" <?php echo $disabled_attr; ?>><?php esc_html_e('Clear Logs', 'autowa-whatsapp'); ?></button></div>
                <div class="autwa-tool-group"><h3><?php esc_html_e('Clear Inactive Sessions', 'autowa-whatsapp'); ?></h3><p><?php esc_html_e('Deletes sessions that are not in WORKING state.', 'autowa-whatsapp'); ?></p><button type="button" id="cleanup-sessions" class="button" <?php echo $disabled_attr; ?>><?php esc_html_e('Clear Inactive Sessions', 'autowa-whatsapp'); ?></button></div>
                <div class="autwa-tool-group"><h3><?php esc_html_e('Clear Temporary Files', 'autowa-whatsapp'); ?></h3><p><?php esc_html_e('Deletes plugin cache.', 'autowa-whatsapp'); ?></p><button type="button" id="cleanup-files" class="button" <?php echo $disabled_attr; ?>><?php esc_html_e('Clear Files', 'autowa-whatsapp'); ?></button></div>
                <div class="autwa-tool-group"><h3><?php esc_html_e('Clear Data Cache', 'autowa-whatsapp'); ?></h3><p><?php esc_html_e('Deletes all cached data (chats, contacts, transients).', 'autowa-whatsapp'); ?></p><button type="button" id="cleanup-cache" class="button" <?php echo $disabled_attr; ?>><?php esc_html_e('Clear All Cache', 'autowa-whatsapp'); ?></button></div>
            </div>
            <div class="autwa-advanced-section">
                <h2><?php esc_html_e('Advanced Tools', 'autowa-whatsapp'); ?></h2>
                <div class="autwa-warning-box"><strong>⚠️ <?php esc_html_e('WARNING:', 'autowa-whatsapp'); ?></strong> <?php esc_html_e('The following actions are irreversible.', 'autowa-whatsapp'); ?></div>
                <div class="autwa-tool-group"><h3><?php esc_html_e('Reset Plugin Completely', 'autowa-whatsapp'); ?></h3><p><?php esc_html_e('Deletes all settings, sessions, and logs.', 'autowa-whatsapp'); ?></p><button type="button" id="reset-plugin" class="button button-secondary" <?php echo $disabled_attr; ?>><?php esc_html_e('Reset Plugin', 'autowa-whatsapp'); ?></button></div>
                <div class="autwa-tool-group"><h3><?php esc_html_e('Delete All Data', 'autowa-whatsapp'); ?></h3><p><?php esc_html_e('Completely deletes all plugin data.', 'autowa-whatsapp'); ?></p><button type="button" id="cleanup-all" class="button button-delete" <?php echo $disabled_attr; ?>><?php esc_html_e('Delete Everything', 'autowa-whatsapp'); ?></button></div>
            </div>
            <div id="tools-messages"></div>
        </div>
        <?php
    }

    public function admin_page_chats() {
        ?>
        <div class="wrap autwa-wrap">
            <h1><span class="dashicons dashicons-format-chat"></span> <?php esc_html_e('AutoWA - Manage Chats', 'autowa-whatsapp'); ?></h1>
            <div id="autwa-chats-container" class="autwa-container">
                <div class="autwa-sidebar">
                    <div class="autwa-sidebar-header">
                        <h2><?php esc_html_e('Recent Chats', 'autowa-whatsapp'); ?></h2>
                        <button id="refresh-chats-btn" class="button"><span class="dashicons dashicons-update"></span> <?php esc_html_e('Refresh (Force)', 'autowa-whatsapp'); ?></button>
                    </div>
                    <div id="chats-list" class="autwa-list"></div>
                </div>
                <div class="autwa-main-content">
                    <div id="chat-welcome" class="autwa-welcome-panel">
                        <span class="dashicons dashicons-whatsapp"></span>
                        <h2><?php esc_html_e('Select a chat to view messages', 'autowa-whatsapp'); ?></h2>
                        <p><?php esc_html_e('Chats will load automatically. If you don\'t see any, make sure your WhatsApp session is connected.', 'autowa-whatsapp'); ?></p>
                    </div>
                    <div id="chat-view" class="autwa-chat-view" style="display: none;">
                        <div id="messages-list" class="autwa-messages-list"></div>
                        <div class="autwa-message-input-area">
                            <div class="autwa-input-wrapper">
                                <textarea id="message-input" placeholder="<?php esc_attr_e('Write a message...', 'autowa-whatsapp'); ?>"></textarea>
                            </div>
                            <div class="autwa-send-controls">
                                <button id="send-message-btn" class="button button-primary"><?php esc_html_e('Send', 'autowa-whatsapp'); ?></button>
                            </div>
                        </div>
                    
                    </div>
                </div>
            </div>
            <div id="autwa-loader" class="autwa-loader-overlay" style="display: none;"><div class="autwa-spinner"></div></div>
            <div id="autwa-messages"></div>
        </div>
        <?php
    }

    public function admin_page_contacts() {
        ?>
        <div class="wrap autwa-wrap">
            <h1><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e('AutoWA - Contacts', 'autowa-whatsapp'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="#contacts-tab" class="nav-tab nav-tab-active"><?php esc_html_e('Contacts', 'autowa-whatsapp'); ?></a>
                <a href="#groups-tab" class="nav-tab"><?php esc_html_e('Groups', 'autowa-whatsapp'); ?></a>
            </nav>

            <div id="autwa-contacts-container" class="autwa-container-full">
                <div class="autwa-full-header">
                    <input type="text" id="contact-search" placeholder="<?php esc_attr_e('Search by name or number...', 'autowa-whatsapp'); ?>">
                    <button id="refresh-contacts-btn" class="button"><span class="dashicons dashicons-update"></span> <?php esc_html_e('Refresh (Force)', 'autowa-whatsapp'); ?></button>
                </div>

                <div id="contacts-tab" class="tab-content active">
                    <div id="contacts-list" class="autwa-grid-list"></div>
                    <div id="contacts-pagination" class="autwa-pagination-container"></div>
                    <p class="autwa-total-count"><span id="contacts-count">0</span> <?php esc_html_e('contacts found.', 'autowa-whatsapp'); ?></p>
                </div>
                <div id="groups-tab" class="tab-content">
                    <div id="groups-list" class="autwa-grid-list"></div>
                    <div id="groups-pagination" class="autwa-pagination-container"></div>
                    <p class="autwa-total-count"><span id="groups-count">0</span> <?php esc_html_e('groups found.', 'autowa-whatsapp'); ?></p>
                </div>
            </div>

            <div id="autwa-loader" class="autwa-loader-overlay" style="display: none;"><div class="autwa-spinner"></div></div>
            <div id="autwa-messages"></div>
        </div>
        <?php
    }
     
     public function admin_page_scheduler() {
        ?>
        <div class="wrap autwa-wrap">
            <h1><span class="dashicons dashicons-clock"></span> <?php esc_html_e('AutoWA - Message Scheduler', 'autowa-whatsapp'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="#programming-sends" class="nav-tab nav-tab-active"><?php esc_html_e('Programming Sends', 'autowa-whatsapp'); ?></a>
                <a href="#broadcast-lists" class="nav-tab"><?php esc_html_e('Manage Broadcast Lists', 'autowa-whatsapp'); ?></a>
            </nav>

            <div id="programming-sends" class="tab-content active">
                <div id="autwa-scheduler-container">
                    <div class="autwa-card">
                        <h2><span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e('Schedule New Send', 'autowa-whatsapp'); ?></h2>
                        <form id="schedule-message-form">
                             <div class="form-group">
                                <div class="form-group-header">
                                    <label><strong>1. <?php esc_html_e('Select Recipients', 'autowa-whatsapp'); ?></strong></label>
                                    <button type="button" id="reload-recipients-btn" class="button"><span class="dashicons dashicons-update"></span> <?php esc_html_e('Load Contacts', 'autowa-whatsapp'); ?></button>
                                </div>
                                <div class="recipient-selector">
                                    <div class="recipient-available-row three-columns">
                                        <div class="recipient-box">
                                            <label for="source-contacts"><?php esc_html_e('Available Contacts', 'autowa-whatsapp'); ?></label>
                                            <input type="text" id="contact-search" placeholder="<?php esc_attr_e('Search...', 'autowa-whatsapp'); ?>" class="autwa-recipient-search">
                                            <select id="source-contacts" multiple></select>
                                        </div>
                                        <div class="recipient-box">
                                            <label for="source-groups"><?php esc_html_e('Available Groups', 'autowa-whatsapp'); ?></label>
                                            <input type="text" id="group-search" placeholder="<?php esc_attr_e('Search...', 'autowa-whatsapp'); ?>" class="autwa-recipient-search">
                                            <select id="source-groups" multiple></select>
                                        </div>
                                        <div class="recipient-box">
                                            <label for="source-broadcasts"><?php esc_html_e('Broadcast Lists', 'autowa-whatsapp'); ?></label>
                                            <input type="text" id="broadcast-search" placeholder="<?php esc_attr_e('Search...', 'autowa-whatsapp'); ?>" class="autwa-recipient-search">
                                            <select id="source-broadcasts" multiple></select>
                                        </div>
                                    </div>
                                    <div class="recipient-controls">
                                        <button type="button" id="add-recipient-btn" class="button">▼ <?php esc_html_e('Add selected', 'autowa-whatsapp'); ?></button>
                                        <button type="button" id="remove-recipient-btn" class="button">▲ <?php esc_html_e('Remove selected', 'autowa-whatsapp'); ?></button>
                                    </div>
                                    <div class="recipient-box">
                                        <label for="destination-recipients"><?php esc_html_e('Selected for sending', 'autowa-whatsapp'); ?></label>
                                        <select id="destination-recipients" multiple></select>
                                    </div>
                                </div>
                            </div>
                              <div class="message-toolbar">
                                <button type="button" class="format-btn" data-format="bold" title="Negrita"><b>B</b></button>
                                <button type="button" class="format-btn" data-format="italic" title="Cursiva"><i>I</i></button>
                                <button type="button" class="format-btn" data-format="strikethrough" title="Tachado"><s>S</s></button>
                                <button type="button" class="format-btn" data-format="monospace" title="Monospace"><code>Mon</code></button>
                                <button type="button" class="format-btn" data-format="bullet-list" title="Viñetas">•</button>
                                <button type="button" class="format-btn" data-format="numbered-list" title="Numerada">1.</button>
                                <button type="button" class="format-btn" data-format="quote" title="Cita">&gt;</button>
                                <button type="button" class="format-btn" data-format="link" title="Insertar Enlace">🔗</button>
                                <button type="button" class="format-btn" data-format="variable" title="Insertar Variable">{x}</button>
                                <button type="button" class="format-btn emoji-btn" title="Emojis">😊</button>
                            </div>
                            <div class="form-group">
                                <label for="message-text"><strong>2. <?php esc_html_e('Write your Message', 'autowa-whatsapp'); ?></strong></label>
                                <textarea id="message-text" rows="6" placeholder="<?php esc_attr_e('Your message here... (optional if attaching a file)', 'autowa-whatsapp'); ?>"></textarea>
                            </div>
                            
                            <div class="form-group form-group-inline">
                                <label><strong>3. <?php esc_html_e('Attach File (Optional)', 'autowa-whatsapp'); ?></strong></label>
                                <div class="form-group-content">
                                    <button type="button" id="upload-attachment-btn" class="button"><?php esc_html_e('Select File', 'autowa-whatsapp'); ?></button>
                                    <div id="attachment-preview" class="hidden">
                                        <span class="dashicons dashicons-media-default"></span>
                                        <span id="attachment-filename"></span>
                                        <button type="button" id="remove-attachment" class="autwa-remove-btn">&times;</button>
                                    </div>
                                    <input type="hidden" id="attachment-url"><input type="hidden" id="attachment-mimetype"><input type="hidden" id="attachment-name-hidden">
                                </div>
                            </div>

                            <div class="form-group form-group-inline">
                                <label for="schedule-datetime"><strong>4. <?php esc_html_e('Choose Date and Time', 'autowa-whatsapp'); ?></strong></label>
                                <div class="form-group-content">
                                    <input type="datetime-local" id="schedule-datetime" required>
                                    <button type="submit" id="schedule-submit-btn" class="button button-primary button-large"><?php esc_html_e('Schedule Message', 'autowa-whatsapp'); ?></button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <div class="autwa-scheduler-lists-wrapper">
                        <div class="autwa-card">
                            <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Pending Sends', 'autowa-whatsapp'); ?></h2>
                            <div id="pending-schedules-list" class="autwa-scheduled-list"></div>
                        </div>
                        <div class="autwa-card">
                            <div class="autwa-card-header">
                                <h2><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Sending History', 'autowa-whatsapp'); ?></h2>
                                <button id="clear-history-btn" class="button button-secondary button-small"><?php esc_html_e('Clear History', 'autowa-whatsapp'); ?></button>
                            </div>
                            <div id="history-schedules-list" class="autwa-scheduled-list"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="broadcast-lists" class="tab-content">
                <div class="autwa-card">
                    <div class="autwa-broadcast-top-bar">
                        <div class="autwa-broadcast-list-new">
                            <input type="text" id="new-broadcast-list-name" placeholder="<?php esc_attr_e('New list name...', 'autowa-whatsapp'); ?>">
                            <button id="create-broadcast-list-btn" class="button button-primary"><?php esc_html_e('Create', 'autowa-whatsapp'); ?></button>
                        </div>
                        <div class="autwa-broadcast-list-selector">
                             <label for="broadcast-lists-dropdown"><?php esc_html_e('Broadcast Lists', 'autowa-whatsapp'); ?></label>
                            <select id="broadcast-lists-dropdown"></select>
                        </div>
                    </div>
                </div>

                <div id="broadcast-welcome-panel" class="autwa-card">
                    <p><?php esc_html_e('Select a list to manage its recipients or create a new one to get started.', 'autowa-whatsapp'); ?></p>
                </div>

                <div id="broadcast-editor-panel" class="autwa-card" style="display: none;">
                    <h2 id="current-list-name"></h2>
                    <p><?php esc_html_e('Add or remove contacts and groups from this broadcast list.', 'autowa-whatsapp'); ?></p>
                    <div class="recipient-selector">
                        <div class="recipient-available-row two-columns">
                             <div class="recipient-box">
                                <label><?php esc_html_e('Available Contacts', 'autowa-whatsapp'); ?></label>
                                <input type="text" class="autwa-recipient-search broadcast-contact-search" placeholder="<?php esc_attr_e('Search...', 'autowa-whatsapp'); ?>">
                                <select class="broadcast-source-contacts" multiple></select>
                            </div>
                            <div class="recipient-box">
                                <label><?php esc_html_e('Available Groups', 'autowa-whatsapp'); ?></label>
                                <input type="text" class="autwa-recipient-search broadcast-group-search" placeholder="<?php esc_attr_e('Search...', 'autowa-whatsapp'); ?>">
                                <select class="broadcast-source-groups" multiple></select>
                            </div>
                        </div>
                        <div class="recipient-controls">
                            <button class="button broadcast-add-recipient-btn">▼ <?php esc_html_e('Add selected', 'autowa-whatsapp'); ?></button>
                            <button class="button broadcast-remove-recipient-btn">▲ <?php esc_html_e('Remove selected', 'autowa-whatsapp'); ?></button>
                        </div>
                        <div class="recipient-box">
                            <label><?php esc_html_e('Recipients in this List', 'autowa-whatsapp'); ?></label>
                            <select class="broadcast-destination-recipients" multiple></select>
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <button id="save-recipients-btn" class="button button-primary"><?php esc_html_e('Save Changes', 'autowa-whatsapp'); ?></button>
                        <button id="delete-list-btn" class="button button-link-delete"><?php esc_html_e('Delete List', 'autowa-whatsapp'); ?></button>
                    </div>
                </div>
            </div>

            <div id="autwa-loader" class="autwa-loader-overlay" style="display: none;"><div class="autwa-spinner"></div></div>
            <div id="autwa-messages"></div>
        </div>
        <?php
        
    }
   
   private function render_tab_woocommerce($disabled_attr) {
        // Verificar si WooCommerce está activo
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('WooCommerce is not active. Please install and activate WooCommerce to use this module.', 'autowa-whatsapp') . '</p></div>';
            return;
        }

        // Recuperar valores
        $woo_enabled = get_option('autwa_woo_enabled', 0);
        $admin_phone = get_option('autwa_woo_admin_phone', '');
        
        // Hint de variables (No traducimos los códigos de variable, solo el texto alrededor si hubiera)
        $vars_hint = '<code>{order_id}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{total}</code>, <code>{status}</code>, <code>{billing_phone}</code>, <code>{products}</code>';
        ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('autwa_woo_settings_nonce'); ?>
            
            <h3><?php esc_html_e('WooCommerce Integration Settings', 'autowa-whatsapp'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Integration', 'autowa-whatsapp'); ?></th>
                    <td>
                        <label class="autwa-switch">
                            <input type="checkbox" name="autwa_woo_enabled" value="1" <?php checked(1, $woo_enabled); ?> <?php echo $disabled_attr; ?>>
                            <span class="autwa-slider round"></span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Admin Phone Number', 'autowa-whatsapp'); ?></th>
                    <td>
                        <input type="text" name="autwa_woo_admin_phone" value="<?php echo esc_attr($admin_phone); ?>" class="regular-text" placeholder="5215512345678" <?php echo $disabled_attr; ?> />
                        <p class="description"><?php esc_html_e('Format: Country code + Number (e.g. 52155...)', 'autowa-whatsapp'); ?></p>
                    </td>
                </tr>
            </table>

            <hr>

            <h3><?php esc_html_e('Notification Events', 'autowa-whatsapp'); ?></h3>
            <p class="description" style="margin-bottom: 20px;">
                <?php esc_html_e('Configure automatic messages for each event. Leave the message blank to disable sending for that specific event.', 'autowa-whatsapp'); ?><br>
                <strong><?php esc_html_e('Available Variables:', 'autowa-whatsapp'); ?></strong> <?php echo $vars_hint; ?>
            </p>

            <table class="form-table">
                <?php 
                if (class_exists('AutoWA_WooCommerce')) {
                    // Usamos get_events() para obtener las etiquetas traducidas
                    foreach (AutoWA_WooCommerce::get_events() as $key => $label) : 
                        $is_active = get_option("autwa_woo_event_{$key}_active", 0);
                        $template = get_option("autwa_woo_event_{$key}_template", '');
                        
                        // Generamos un placeholder traducido dinámicamente
                        /* translators: 1: Variable name, 2: Event name */
                        $placeholder_text = sprintf(esc_attr__('Hello {first_name}, your order #{order_id} is now: %s...', 'autowa-whatsapp'), $label);
                ?>
                <tr style="border-top: 1px solid #eee;">
                    <th scope="row">
                        <label for="autwa_woo_event_<?php echo esc_attr($key); ?>_active">
                            <?php echo esc_html($label); ?>
                        </label>
                    </th>
                    <td>
                        <label class="autwa-switch" style="margin-bottom: 10px; display:block;">
                            <input type="checkbox" 
                                   name="autwa_woo_event_<?php echo esc_attr($key); ?>_active" 
                                   id="autwa_woo_event_<?php echo esc_attr($key); ?>_active" 
                                   value="1" 
                                   <?php checked(1, $is_active); ?> 
                                   <?php echo $disabled_attr; ?>>
                            <span class="autwa-slider round"></span>
                        </label>
                        
                        <textarea name="autwa_woo_event_<?php echo esc_attr($key); ?>_template" 
                                  rows="3" 
                                  class="large-text code" 
                                  placeholder="<?php echo $placeholder_text; ?>"
                                  <?php echo $disabled_attr; ?>><?php echo esc_textarea($template); ?></textarea>
                    </td>
                </tr>
                <?php endforeach; 
                } ?>
            </table>

            <p class="submit">
                <input type="submit" name="save_woo_settings" id="submit" class="button button-primary" value="<?php esc_attr_e('Save WooCommerce Settings', 'autowa-whatsapp'); ?>" <?php echo $disabled_attr; ?>>
            </p>
        </form>
        <?php
        
    }
   
   private function render_tab_security($disabled_attr) {
        $sec_enabled = get_option('autwa_sec_enabled', 0);
        $admin_phone = get_option('autwa_sec_admin_phone', '');
        $vars_hint = '<code>{ip_address}</code>, <code>{username}</code>, <code>{date}</code>, <code>{site_name}</code>';
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('autwa_sec_settings_nonce'); ?>
            
            <h3><?php esc_html_e('Security Alerts Settings', 'autowa-whatsapp'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Security Alerts', 'autowa-whatsapp'); ?></th>
                    <td>
                        <label class="autwa-switch">
                            <input type="checkbox" name="autwa_sec_enabled" value="1" <?php checked(1, $sec_enabled); ?> <?php echo $disabled_attr; ?>>
                            <span class="autwa-slider round"></span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Security Admin Phone', 'autowa-whatsapp'); ?></th>
                    <td>
                        <input type="text" name="autwa_sec_admin_phone" value="<?php echo esc_attr($admin_phone); ?>" class="regular-text" placeholder="52155..." <?php echo $disabled_attr; ?> />
                    </td>
                </tr>
            </table>
            <hr>
            <h3><?php esc_html_e('Security Events', 'autowa-whatsapp'); ?></h3>
            <p class="description"><strong><?php esc_html_e('Global Variables:', 'autowa-whatsapp'); ?></strong> <?php echo $vars_hint; ?></p>

            <table class="form-table">
                <?php 
                if (class_exists('AutoWA_Security')) {
                    foreach (AutoWA_Security::get_events() as $key => $label) : 
                        $is_active = get_option("autwa_sec_event_{$key}_active", 0);
                        $template = get_option("autwa_sec_event_{$key}_template", '');
                        
                        $placeholder = sprintf(esc_attr__('ALERTA: %s en {site_name}. IP: {ip_address}, Usuario: {username}...', 'autowa-whatsapp'), $label);
                ?>
                <tr style="border-top: 1px solid #eee;">
                    <th scope="row">
                        <label><?php echo esc_html($label); ?></label>
                    </th>
                    <td>
                        <label class="autwa-switch" style="margin-bottom: 10px; display:block;">
                            <input type="checkbox" name="autwa_sec_event_<?php echo $key; ?>_active" value="1" <?php checked(1, $is_active); ?> <?php echo $disabled_attr; ?>>
                            <span class="autwa-slider round"></span>
                        </label>
                        <textarea name="autwa_sec_event_<?php echo $key; ?>_template" rows="2" class="large-text code" placeholder="<?php echo $placeholder; ?>" <?php echo $disabled_attr; ?>><?php echo esc_textarea($template); ?></textarea>
                    </td>
                </tr>
                <?php endforeach; } ?>
            </table>

            <p class="submit">
                <input type="submit" name="save_sec_settings" class="button button-primary" value="<?php esc_attr_e('Save Security Settings', 'autowa-whatsapp'); ?>" <?php echo $disabled_attr; ?>>
            </p>
        </form>

        <!-- Nueva sección de depuración -->
        <div class="autwa-card" style="margin-top: 40px; border-top: 3px solid #721c24;">
            <h3><span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Security Debug Log', 'autowa-whatsapp'); ?></h3>
            <p class="description"><?php esc_html_e('Last events captured by the security module and their status with n8n.', 'autowa-whatsapp'); ?></p>
            <div id="security-debug-log-container" style="background: #f0f0f0; padding: 15px; border-radius: 4px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                <p><?php esc_html_e('Loading logs...', 'autowa-whatsapp'); ?></p>
            </div>
            <p><button type="button" id="refresh-security-logs" class="button button-secondary"><?php esc_html_e('Refresh Log', 'autowa-whatsapp'); ?></button></p>
        </div>
<?php
    }
}