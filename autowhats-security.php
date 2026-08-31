<?php
/**
 * AutoWA Security Integration
 * Monitorea eventos críticos de seguridad de WordPress y envía alertas inmediatas.
 *
 * @package AutoWA
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoWA_Security {

    private $plugin_instance;
    private $admin_phone;
    private $is_enabled;

    public function __construct($plugin_instance) {
        $this->plugin_instance = $plugin_instance;
        $this->is_enabled = get_option('autwa_sec_enabled', 0);
        $this->admin_phone = get_option('autwa_sec_admin_phone', '');
    }

    /**
     * Define los eventos de seguridad que el plugin puede monitorear.
     */
    public static function get_events() {
        return [
            'wp_login'          => __('Admin Login Success', 'autowa-whatsapp'),
            'wp_login_failed'   => __('Login Failed (Brute Force Alert)', 'autowa-whatsapp'),
            'plugin_changed'    => __('Plugin Activated/Deactivated', 'autowa-whatsapp'),
            'user_register'     => __('New User Registered', 'autowa-whatsapp'),
            'password_reset'    => __('Password Changed/Reset', 'autowa-whatsapp'),
        ];
    }

    /**
     * Inicializa los ganchos de WordPress para capturar eventos.
     */
    public function init() {
        if (!$this->is_enabled || empty($this->admin_phone)) return;

        // 1. Login Exitoso (Solo Administradores)
        add_action('wp_login', array($this, 'handle_wp_login'), 10, 2);

        // 2. Intento de Login Fallido
        add_action('wp_login_failed', array($this, 'handle_login_failed'), 10, 1);

        // 3. Cambios en Plugins (Activación/Desactivación)
        add_action('activated_plugin', array($this, 'handle_plugin_change'), 10, 2);
        add_action('deactivated_plugin', array($this, 'handle_plugin_change'), 10, 2);

        // 4. Registro de Nuevo Usuario
        add_action('user_register', array($this, 'handle_user_register'), 10, 1);
        
        // 5. Cambio de Contraseña
        add_action('profile_update', array($this, 'handle_profile_update'), 10, 2);
    }

    // --- MANEJADORES DE EVENTOS ---

    public function handle_wp_login($user_login, $user) {
        if (in_array('administrator', (array) $user->roles)) {
            $this->process_security_event('wp_login', [
                '{username}' => $user_login,
                '{role}'     => 'Administrator'
            ]);
        }
    }

    public function handle_login_failed($username) {
        $this->process_security_event('wp_login_failed', [
            '{username}' => $username,
            '{role}'     => 'Unknown'
        ]);
    }

    public function handle_plugin_change($plugin, $network_wide = null) {
        $action = current_action() === 'activated_plugin' ? 'Activado' : 'Desactivado';
        
        $this->process_security_event('plugin_changed', [
            '{plugin_name}' => $plugin,
            '{action}'      => $action,
            '{username}'    => wp_get_current_user()->user_login
        ]);
    }

    public function handle_user_register($user_id) {
        $user = get_userdata($user_id);
        $this->process_security_event('user_register', [
            '{username}' => $user->user_login,
            '{email}'    => $user->user_email,
            '{role}'     => implode(', ', (array) $user->roles)
        ]);
    }
    
    public function handle_profile_update($user_id, $old_user_data) {
        if ( ! empty( $_POST['pass1'] ) && ! empty( $_POST['pass2'] ) ) {
             $user = get_userdata($user_id);
             $this->process_security_event('password_reset', [
                '{username}' => $user->user_login,
                '{role}'     => implode(', ', (array) $user->roles)
            ]);
        }
    }

    // --- LÓGICA CORE ---

    /**
     * Procesa la plantilla del evento y dispara el envío.
     */
      private function process_security_event($event_key, $custom_vars = []) {
        $is_event_active = get_option("autwa_sec_event_{$event_key}_active", 0);
        if (!$is_event_active) return;

        $template = get_option("autwa_sec_event_{$event_key}_template", '');
        
        // CORRECCIÓN: Si el template está vacío, usamos el mensaje por defecto
        if (empty($template)) {
            $events = self::get_events();
            $label = isset($events[$event_key]) ? $events[$event_key] : 'Evento de Seguridad';
            $template = "*Alerta:* Se ha detectado un evento de tipo '{$label}' en {site_name}. IP: {ip_address}, Usuario: {username}.";
        }

        $site_name = get_option('blogname'); // Usamos get_option para mayor seguridad

        $vars = array_merge($custom_vars, [
            '{ip_address}' => $this->get_client_ip(),
            '{date}'       => current_time('mysql'),
            '{site_name}'  => !empty($site_name) ? $site_name : get_bloginfo('name'),
            '{username}'   => isset($custom_vars['{username}']) ? $custom_vars['{username}'] : 'Sistema'
        ]);

        $message = str_replace(array_keys($vars), array_values($vars), $template);
        $this->send_immediate_message($message);
    }
    
    /**
     * Realiza la comunicación directa con el API a través de la clase principal.
     */
    private function send_immediate_message($message_body) {
        $clean_phone = preg_replace('/[^0-9]/', '', $this->admin_phone);
        
        if (empty($clean_phone)) {
            $this->plugin_instance->log_event('security', 'error', 'No hay teléfono configurado para alertas de seguridad.');
            return;
        }

        // Llamamos al método de envío inmediato en autowhats.php
        $response = $this->plugin_instance->send_immediate_whatsapp_message($clean_phone, $message_body);

        // Registro de Logs para la pestaña de depuración
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->plugin_instance->log_event('security', 'alert_failed', $message_body, ['error' => $error_msg]);
            error_log('AutoWA Security Error: ' . $error_msg);
        } else {
            // alert_sent es lo que busca el JS para marcar en verde
            $this->plugin_instance->log_event('security', 'alert_sent', $message_body);
        }
    }

    /**
     * Obtiene la IP real del cliente.
     */
    private function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $forwarded_ip = trim(end($ips));
            if (filter_var($forwarded_ip, FILTER_VALIDATE_IP)) {
                return $forwarded_ip;
            }
        }
        
        if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}