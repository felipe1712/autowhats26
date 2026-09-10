<?php
/**
 * AutoWhats - Administration Pages & Settings
 * Handles configuration, Kapso Cloud API connection, WooCommerce alerts, Security, and Live Chat.
 *
 * @package AutoWA
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
        // 1. Guardar ajustes de conexión de WhatsApp (Kapso.ai)
        if (isset($_POST['submit']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'autwa_settings_nonce')) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('No tienes permisos para modificar estos ajustes.', 'autowa-whatsapp'));
            }
            $this->save_connection_settings();
        }

        // 2. Guardar ajustes de WooCommerce
        if (isset($_POST['save_woo_settings']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'autwa_woo_settings_nonce')) {
            if (!current_user_can('manage_options')) return;

            update_option('autwa_woo_enabled', isset($_POST['autwa_woo_enabled']) ? 1 : 0);
            update_option('autwa_woo_admin_phone', sanitize_text_field($_POST['autwa_woo_admin_phone']));

            if (class_exists('AutoWA_WooCommerce')) {
                foreach (AutoWA_WooCommerce::get_events() as $key => $label) {
                    update_option("autwa_woo_event_{$key}_active", isset($_POST["autwa_woo_event_{$key}_active"]) ? 1 : 0);
                    update_option("autwa_woo_event_{$key}_template", sanitize_textarea_field($_POST["autwa_woo_event_{$key}_template"]));
                }
            }
            add_settings_error('autwa_messages', 'autwa_message', __('Ajustes de WooCommerce guardados.', 'autowa-whatsapp'), 'updated');
        }

        // 3. Guardar ajustes de Seguridad
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
            add_settings_error('autwa_messages', 'autwa_message', __('Ajustes de Seguridad guardados.', 'autowa-whatsapp'), 'updated');
        }
    }

    private function save_connection_settings() {
        if (isset($_POST['autwa_kapso_api_key'])) {
            update_option('autwa_kapso_api_key', sanitize_text_field($_POST['autwa_kapso_api_key']));
        }
        if (isset($_POST['autwa_kapso_phone_number_id'])) {
            update_option('autwa_kapso_phone_number_id', sanitize_text_field($_POST['autwa_kapso_phone_number_id']));
        }
        if (isset($_POST['autwa_edd_license_key'])) {
            update_option('autwa_edd_license_key', sanitize_text_field($_POST['autwa_edd_license_key']));
        }

        wp_redirect(admin_url('admin.php?page=autwa-whatsapp&settings-updated=true#conexion'));
        exit;
    }

    public function render_config_page() {
        $can_edit = current_user_can('manage_options');
        $disabled = $can_edit ? '' : 'disabled';
        ?>
        <div class="wrap autwa-wrap">
            <h1><span class="dashicons dashicons-whatsapp" style="color: #25d366;"></span> <?php esc_html_e('AutoWhats - Configuración General', 'autowa-whatsapp'); ?></h1>

            <?php
            if (isset($_GET['settings-updated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Ajustes guardados correctamente.', 'autowa-whatsapp') . '</p></div>';
            }
            ?>

            <nav class="nav-tab-wrapper">
                <a href="#conexion" class="nav-tab nav-tab-active"><?php esc_html_e('Conexión WhatsApp (Kapso)', 'autowa-whatsapp'); ?></a>
                <a href="#cron" class="nav-tab"><?php esc_html_e('Automatización & Cron (n8n)', 'autowa-whatsapp'); ?></a>
                <a href="#woocommerce" class="nav-tab"><?php esc_html_e('WooCommerce', 'autowa-whatsapp'); ?></a>
                <a href="#security" class="nav-tab"><?php esc_html_e('Seguridad', 'autowa-whatsapp'); ?></a>
                <a href="#herramientas" class="nav-tab"><?php esc_html_e('Herramientas', 'autowa-whatsapp'); ?></a>
            </nav>

            <div id="conexion" class="tab-content" style="display: block;">
                <?php $this->render_tab_conexion($disabled); ?>
            </div>
            <div id="cron" class="tab-content" style="display: none;">
                <?php $this->render_tab_cron($disabled); ?>
            </div>
            <div id="woocommerce" class="tab-content" style="display: none;">
                <?php $this->render_tab_woocommerce($disabled); ?>
            </div>
            <div id="security" class="tab-content" style="display: none;">
                <?php $this->render_tab_security($disabled); ?>
            </div>
            <div id="herramientas" class="tab-content" style="display: none;">
                <?php $this->render_tab_herramientas($disabled); ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.nav-tab-wrapper a').on('click', function(e) {
                e.preventDefault();
                $('.nav-tab-wrapper a').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                var target = $(this).attr('href');
                $('.tab-content').hide();
                $(target).show();
            });
        });
        </script>
        <?php
    }

    private function render_tab_conexion($disabled) {
        $license_key     = get_option('autwa_edd_license_key', '');
        $kapso_api_key   = get_option('autwa_kapso_api_key', '');
        $phone_number_id = get_option('autwa_kapso_phone_number_id', '');

        $kapso_client = AutoWA_Kapso_Client::get_instance();
        $is_connected = $kapso_client->is_configured();
        ?>
        <div class="autwa-card" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 18px;">
                    <span class="dashicons dashicons-cloud" style="color: #0284c7;"></span> 
                    <?php esc_html_e('Estado de la Conexión Oficial Meta Cloud API', 'autowa-whatsapp'); ?>
                </h2>
                <div>
                    <?php if ($is_connected): ?>
                        <span style="background: #22c55e; color: #fff; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 13px;">
                            ✓ <?php esc_html_e('CONECTADO (Kapso 24/7)', 'autowa-whatsapp'); ?>
                        </span>
                    <?php else: ?>
                        <span style="background: #f59e0b; color: #fff; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 13px;">
                            ⚠ <?php esc_html_e('CONFIGURACIÓN PENDIENTE', 'autowa-whatsapp'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('autwa_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><strong><?php esc_html_e('Clave de Licencia AutoWhats', 'autowa-whatsapp'); ?> *</strong></th>
                        <td>
                            <input type="text" name="autwa_edd_license_key" value="<?php echo esc_attr($license_key); ?>" class="regular-text" placeholder="AW-XXXX-XXXX-XXXX" required <?php echo $disabled; ?> />
                            <p class="description"><?php esc_html_e('Tu clave de activación de AutoWhats.', 'autowa-whatsapp'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><strong><?php esc_html_e('Kapso API Key (Bearer Token)', 'autowa-whatsapp'); ?> *</strong></th>
                        <td>
                            <input type="password" name="autwa_kapso_api_key" value="<?php echo esc_attr($kapso_api_key); ?>" class="large-text" placeholder="kap_live_xxxxxxxxxxxxxxxx" required <?php echo $disabled; ?> />
                            <p class="description"><?php esc_html_e('Obtenla desde tu panel de Kapso.ai (Configuración > API Keys).', 'autowa-whatsapp'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><strong><?php esc_html_e('WhatsApp Phone Number ID', 'autowa-whatsapp'); ?> *</strong></th>
                        <td>
                            <input type="text" name="autwa_kapso_phone_number_id" value="<?php echo esc_attr($phone_number_id); ?>" class="regular-text" placeholder="Ej: 105948372615243" required <?php echo $disabled; ?> />
                            <p class="description"><?php esc_html_e('El identificador del número de teléfono asignado por Meta en Kapso.', 'autowa-whatsapp'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Webhook Endpoint para Kapso', 'autowa-whatsapp'); ?></th>
                        <td>
                            <input type="text" value="<?php echo esc_url(get_rest_url(null, 'autowa/v1/kapso-webhook')); ?>" class="large-text" readonly onclick="this.select();" />
                            <p class="description"><?php esc_html_e('Pega esta URL en tu panel de Kapso.ai (Webhooks) para recibir mensajes y estados en tiempo real.', 'autowa-whatsapp'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Guardar Configuración de Conexión', 'autowa-whatsapp'), 'primary', 'submit', true, $disabled); ?>
            </form>
        </div>
        <?php
    }

    private function render_tab_cron($disabled) {
        $cron_url = get_rest_url(null, 'autowa/v1/cron-trigger');
        $secret   = get_option('autwa_webhook_secret', 'lhnkdkpwq9bpda8441zbx094bwlkb284379a');
        ?>
        <div class="autwa-card" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 20px;">
            <h2 style="font-size: 18px; margin-top: 0;">
                <span class="dashicons dashicons-clock" style="color: #25d366;"></span> 
                <?php esc_html_e('Disparador de Mensajes Programados (Cron Ligero con n8n)', 'autowa-whatsapp'); ?>
            </h2>
            <p><?php esc_html_e('Para garantizar que los mensajes programados y difusiones masivas se envíen con puntualidad sin depender del tráfico web de WordPress, configura un pulso en n8n:', 'autowa-whatsapp'); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><strong><?php esc_html_e('URL del Cron Trigger', 'autowa-whatsapp'); ?>:</strong></th>
                    <td>
                        <input type="text" value="<?php echo esc_url($cron_url); ?>" class="large-text" readonly onclick="this.select();" />
                        <p class="description"><?php esc_html_e('Método POST. Configura tu flujo de n8n para llamar a esta URL cada 1 o 5 minutos.', 'autowa-whatsapp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><strong><?php esc_html_e('Secreto HMAC (X-AutoWA-Signature)', 'autowa-whatsapp'); ?>:</strong></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr($secret); ?>" class="regular-text" readonly onclick="this.select();" />
                        <p class="description"><?php esc_html_e('Firma criptográfica compartida para proteger el endpoint contra peticiones no autorizadas.', 'autowa-whatsapp'); ?></p>
                    </td>
                </tr>
            </table>

            <div style="background: #f8fafc; border-left: 4px solid #25d366; padding: 15px; margin-top: 15px; border-radius: 0 6px 6px 0;">
                <h4 style="margin-top: 0;"><?php esc_html_e('¿Cómo funciona el nuevo flujo?', 'autowa-whatsapp'); ?></h4>
                <ol style="margin-bottom: 0; padding-left: 20px;">
                    <li>WordPress almacena los mensajes programados en su propia base de datos (<code>wp_autwa_scheduled_messages</code>).</li>
                    <li>n8n dispara una llamada POST a este endpoint periódicamente.</li>
                    <li>WordPress consulta las tareas pendientes y las despacha directamente a través de Kapso.ai.</li>
                </ol>
            </div>
        </div>
        <?php
    }

    private function render_tab_woocommerce($disabled) {
        $enabled = get_option('autwa_woo_enabled', 0);
        $phone   = get_option('autwa_woo_admin_phone', '');
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('autwa_woo_settings_nonce'); ?>
            <div class="autwa-card" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 20px;">
                <h2><?php esc_html_e('Notificaciones Automáticas de WooCommerce', 'autowa-whatsapp'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Activar Notificaciones', 'autowa-whatsapp'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="autwa_woo_enabled" value="1" <?php checked(1, $enabled); ?> <?php echo $disabled; ?> />
                                <?php esc_html_e('Habilitar alertas automáticas de pedidos e inventario por WhatsApp', 'autowa-whatsapp'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Teléfono del Administrador', 'autowa-whatsapp'); ?></th>
                        <td>
                            <input type="text" name="autwa_woo_admin_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" placeholder="5215512345678" <?php echo $disabled; ?> />
                            <p class="description"><?php esc_html_e('Número con código de país para recibir las alertas de la tienda.', 'autowa-whatsapp'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php if (class_exists('AutoWA_WooCommerce')): ?>
                    <h3 style="margin-top: 30px; border-top: 1px solid #f1f5f9; padding-top: 20px;"><?php esc_html_e('Plantillas de Mensajes por Evento', 'autowa-whatsapp'); ?></h3>
                    <?php foreach (AutoWA_WooCommerce::get_events() as $key => $label): 
                        $active = get_option("autwa_woo_event_{$key}_active", 0);
                        $tpl = get_option("autwa_woo_event_{$key}_template", '');
                    ?>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 15px; margin-bottom: 15px;">
                            <label style="font-weight: bold; display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="autwa_woo_event_<?php echo esc_attr($key); ?>_active" value="1" <?php checked(1, $active); ?> <?php echo $disabled; ?> />
                                <?php echo esc_html($label); ?>
                            </label>
                            <textarea name="autwa_woo_event_<?php echo esc_attr($key); ?>_template" rows="3" class="large-text" placeholder="Escribe el mensaje..." <?php echo $disabled; ?>><?php echo esc_textarea($tpl); ?></textarea>
                            <small class="description"><?php esc_html_e('Variables disponibles:', 'autowa-whatsapp'); ?> <code>{order_number}</code>, <code>{customer_name}</code>, <code>{order_total}</code>, <code>{order_status}</code>, <code>{site_name}</code></small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" name="save_woo_settings" class="button button-primary" <?php echo $disabled; ?>><?php esc_html_e('Guardar Ajustes de WooCommerce', 'autowa-whatsapp'); ?></button>
                </p>
            </div>
        </form>
        <?php
    }

    private function render_tab_security($disabled) {
        $enabled = get_option('autwa_sec_enabled', 0);
        $phone   = get_option('autwa_sec_admin_phone', '');
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('autwa_sec_settings_nonce'); ?>
            <div class="autwa-card" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 20px;">
                <h2><?php esc_html_e('Alertas de Seguridad en WordPress', 'autowa-whatsapp'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Activar Alertas', 'autowa-whatsapp'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="autwa_sec_enabled" value="1" <?php checked(1, $enabled); ?> <?php echo $disabled; ?> />
                                <?php esc_html_e('Enviar notificaciones de seguridad inmediatas a tu WhatsApp', 'autowa-whatsapp'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Teléfono de Seguridad', 'autowa-whatsapp'); ?></th>
                        <td>
                            <input type="text" name="autwa_sec_admin_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" placeholder="5215512345678" <?php echo $disabled; ?> />
                        </td>
                    </tr>
                </table>

                <?php if (class_exists('AutoWA_Security')): ?>
                    <h3 style="margin-top: 25px; border-top: 1px solid #f1f5f9; padding-top: 20px;"><?php esc_html_e('Eventos de Seguridad Monitoreados', 'autowa-whatsapp'); ?></h3>
                    <?php foreach (AutoWA_Security::get_events() as $key => $label): 
                        $active = get_option("autwa_sec_event_{$key}_active", 0);
                        $tpl = get_option("autwa_sec_event_{$key}_template", '');
                    ?>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 15px; margin-bottom: 15px;">
                            <label style="font-weight: bold; display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="autwa_sec_event_<?php echo esc_attr($key); ?>_active" value="1" <?php checked(1, $active); ?> <?php echo $disabled; ?> />
                                <?php echo esc_html($label); ?>
                            </label>
                            <textarea name="autwa_sec_event_<?php echo esc_attr($key); ?>_template" rows="2" class="large-text" <?php echo $disabled; ?>><?php echo esc_textarea($tpl); ?></textarea>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" name="save_sec_settings" class="button button-primary" <?php echo $disabled; ?>><?php esc_html_e('Guardar Ajustes de Seguridad', 'autowa-whatsapp'); ?></button>
                </p>
            </div>
        </form>
        <?php
    }

    private function render_tab_herramientas($disabled) {
        ?>
        <div class="autwa-card" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 20px;">
            <h2><?php esc_html_e('Diagnóstico y Mantenimiento', 'autowa-whatsapp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Hora del Servidor (UTC)', 'autowa-whatsapp'); ?></th>
                    <td><code><?php echo esc_html(gmdate('Y-m-d H:i:s')); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Hora de WordPress (Local)', 'autowa-whatsapp'); ?></th>
                    <td><code><?php echo esc_html(current_time('Y-m-d H:i:s')); ?></code></td>
                </tr>
            </table>

            <div style="margin-top: 20px;">
                <button type="button" id="cleanup-cache" class="button" <?php echo $disabled; ?>><?php esc_html_e('Limpiar Caché y Buffers de Chat', 'autowa-whatsapp'); ?></button>
            </div>
        </div>
        <?php
    }

    public function admin_page_chats() {
        global $wpdb;
        $table_chats = $wpdb->prefix . 'autwa_chats';
        $chats = $wpdb->get_results("SELECT * FROM $table_chats ORDER BY timestamp DESC LIMIT 100");
        ?>
        <div class="wrap autwa-wrap">
            <h1><span class="dashicons dashicons-format-chat" style="color: #25d366;"></span> <?php esc_html_e('AutoWhats - Live Chat & Mensajería', 'autowa-whatsapp'); ?></h1>
            <div id="autwa-chats-container" class="autwa-container" style="display: flex; gap: 20px; margin-top: 20px; height: 650px; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden;">
                <!-- Lista lateral de chats -->
                <div class="autwa-sidebar" style="width: 320px; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column;">
                    <div style="padding: 15px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                        <input type="text" id="search-chats-input" placeholder="Buscar chat..." style="width: 100%; border-radius: 20px; padding: 6px 12px;">
                    </div>
                    <div id="chats-list" style="flex: 1; overflow-y: auto;">
                        <?php if (empty($chats)): ?>
                            <div style="padding: 20px; text-align: center; color: #94a3b8;">
                                <?php esc_html_e('Aún no hay chats registrados.', 'autowa-whatsapp'); ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($chats as $c): ?>
                                <div class="chat-item" data-chat-id="<?php echo esc_attr($c->chat_id); ?>" style="padding: 12px 15px; border-bottom: 1px solid #f1f5f9; cursor: pointer;">
                                    <strong><?php echo esc_html($c->name ?: $c->chat_id); ?></strong>
                                    <div style="color: #64748b; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo esc_html($c->last_message); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Área principal de conversación -->
                <div class="autwa-main-content" style="flex: 1; display: flex; flex-direction: column;">
                    <div id="chat-header" style="padding: 15px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; font-weight: bold;">
                        <span id="active-chat-name"><?php esc_html_e('Selecciona un chat para ver la conversación', 'autowa-whatsapp'); ?></span>
                    </div>
                    <div id="messages-list" style="flex: 1; padding: 20px; overflow-y: auto; background: #fdfdfd;"></div>
                    <div class="autwa-message-input-area" style="padding: 15px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; gap: 10px;">
                        <textarea id="message-input" rows="2" style="flex: 1; border-radius: 6px;" placeholder="<?php esc_attr_e('Escribe tu mensaje...', 'autowa-whatsapp'); ?>"></textarea>
                        <button type="button" id="send-message-btn" class="button button-primary" style="align-self: center; padding: 10px 20px; height: auto;">
                            <?php esc_html_e('Enviar', 'autowa-whatsapp'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}