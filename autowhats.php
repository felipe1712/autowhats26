<?php
/**
 * Plugin Name: AutoWA WhatsApp Integration
 * Plugin URI: https://autowhats.com.mx
 * Description: Plugin to integrate WhatsApp with session, chat, and contact management.
 * Version: 1.5.3
 * Author: FCM Causer Consulting
 * License: GPL v2 or later
 * Text Domain: autowa-whatsapp
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Forzar limpieza de cache de este archivo específicamente si es posible
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}
// Plugin constants
define('AUTWA_PLUGIN_VERSION', '1.5.3');
define('AUTWA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AUTWA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once(AUTWA_PLUGIN_PATH . 'autowhats-kapso.php');
require_once(AUTWA_PLUGIN_PATH . 'autowhats-admin-pages.php');
require_once(AUTWA_PLUGIN_PATH . 'autowhats-api-updates.php');
require_once(AUTWA_PLUGIN_PATH . 'autowhats-security.php');

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    require_once(AUTWA_PLUGIN_PATH . 'autowhats-woocommerce.php');
}
 #[\AllowDynamicProperties]
class AutoWAWhatsAppPlugin {
    
    private $admin_pages;
    private $security_module;
    private $woo_module;
     
    public function __construct() {
        // Initialization and menu hooks
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
         add_action('plugins_loaded', array($this, 'load_textdomain'));
        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('AutoWAWhatsAppPlugin', 'uninstall'));

        // Hooks for the WordPress Cron system
        add_filter('cron_schedules', array($this, 'add_custom_cron_interval'));
        add_action('autwa_send_scheduled_messages_cron', array($this, 'send_scheduled_messages'));
        
        // Cron auto-repair hook
        add_action('admin_init', array($this, 'ensure_cron_is_scheduled'));

        // AJAX request hooks
        add_action('wp_ajax_autwa_create_session', array($this, 'ajax_create_session'));
        add_action('wp_ajax_autwa_get_qr', array($this, 'ajax_get_qr'));
        add_action('wp_ajax_autwa_check_session_status', array($this, 'ajax_check_session_status'));
        add_action('wp_ajax_autwa_get_contacts', array($this, 'ajax_get_contacts'));
        add_action('wp_ajax_autwa_get_chats', array($this, 'ajax_get_chats'));
        add_action('wp_ajax_autwa_send_message', array($this, 'ajax_send_message'));
        add_action('wp_ajax_autwa_get_chat_messages', array($this, 'ajax_get_chat_messages'));
        add_action('wp_ajax_autwa_cleanup_data', array($this, 'ajax_cleanup_data'));
        add_action('wp_ajax_autwa_reset_plugin', array($this, 'ajax_reset_plugin'));
        add_action('wp_ajax_autwa_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_autwa_get_recent_logs', array($this, 'ajax_get_recent_logs'));
        add_action('wp_ajax_autwa_get_current_config', array($this, 'ajax_get_current_config'));
        add_action('wp_ajax_autwa_get_groups', array($this, 'ajax_get_groups'));
        add_action('wp_ajax_autwa_get_picture', array($this, 'ajax_get_picture'));
        add_action('wp_ajax_autwa_get_media_file', array($this, 'ajax_get_media_file'));
        add_action('wp_ajax_autwa_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_autwa_start_session', array($this, 'ajax_start_session'));
        add_action('wp_ajax_autwa_restart_session', array($this, 'ajax_restart_session'));
        add_action('plugins_loaded', array($this, 'setup_ajax_filters')); 
        add_action('wp_ajax_autwa_delete_session', array($this, 'ajax_delete_session'));
         add_action('wp_enqueue_scripts', array($this, 'fix_ie_conditional_error'), 999);
        
        // Hooks for scheduler and cron
        add_action('wp_ajax_autwa_schedule_message', array($this, 'ajax_schedule_message'));
        add_action('wp_ajax_autwa_get_scheduled_messages', array($this, 'ajax_get_scheduled_messages'));
        add_action('wp_ajax_autwa_delete_scheduled_message', array($this, 'ajax_delete_scheduled_message'));
        add_action('wp_ajax_autwa_manual_cron_trigger', array($this, 'ajax_manual_cron_trigger'));
        add_action('wp_ajax_autwa_reschedule_cron', array($this, 'ajax_reschedule_cron'));
           add_action('wp_ajax_autwa_clear_scheduler_history', array($this, 'ajax_clear_scheduler_history'));
        
        // Hooks for Broadcast Lists
        add_action('wp_ajax_autwa_get_broadcast_lists', array($this, 'ajax_get_broadcast_lists'));
        add_action('wp_ajax_autwa_create_broadcast_list', array($this, 'ajax_create_broadcast_list'));
        add_action('wp_ajax_autwa_delete_broadcast_list', array($this, 'ajax_delete_broadcast_list'));
        add_action('wp_ajax_autwa_get_list_recipients', array($this, 'ajax_get_list_recipients'));
        add_action('wp_ajax_autwa_update_list_recipients', array($this, 'ajax_update_list_recipients'));
        add_action('wp_ajax_autwa_get_broadcast_schedules', array($this, 'ajax_get_broadcast_schedules'));
        add_action('wp_ajax_autwa_delete_broadcast_schedule', array($this, 'ajax_delete_broadcast_schedule'));
        add_action('wp_ajax_autwa_manage_license', array($this, 'ajax_manage_license'));
        
        $this->add_ajax_hooks();
        $this->admin_pages = new AutoWA_Admin_Pages($this);
        
        // Módulo de Seguridad
        if (class_exists('AutoWA_Security')) {
            $this->security_module = new AutoWA_Security($this);
            $this->security_module->init();
        }

        // Módulo de WooCommerce
        if (class_exists('AutoWA_WooCommerce')) {
            $this->woo_module = new AutoWA_WooCommerce($this);
            $this->woo_module->init();
        }
        
        add_action('plugins_loaded', array($this, 'setup_ajax_filters')); 
        register_shutdown_function(array($this, 'autwa_shutdown_error_handler'));
    }
    
    public function fix_ie_conditional_error() {
        global $wp_scripts, $wp_styles;
        
        // Limpiar scripts
        if (isset($wp_scripts->registered)) {
            foreach ($wp_scripts->registered as $handle => $data) {
                if (isset($data->extra['conditional'])) {
                    unset($wp_scripts->registered[$handle]->extra['conditional']);
                }
            }
        }
        
        // Limpiar estilos
        if (isset($wp_styles->registered)) {
            foreach ($wp_styles->registered as $handle => $data) {
                if (isset($data->extra['conditional'])) {
                    unset($wp_styles->registered[$handle]->extra['conditional']);
                }
            }
        }
    }
    
     private function add_ajax_hooks() {
        $ajax_actions = [
            'create_session', 'get_qr', 'check_session_status', 'get_contacts', 
            'get_chats', 'send_message', 'get_chat_messages', 'cleanup_data', 
            'reset_plugin', 'test_connection', 'get_recent_logs', 'get_current_config', 
            'get_groups', 'get_picture', 'get_media_file', 'clear_cache', 
            'start_session', 'restart_session', 'schedule_message', 
            'get_scheduled_messages', 'delete_scheduled_message', 'manual_cron_trigger', 
            'reschedule_cron', 'get_broadcast_lists', 'create_broadcast_list', 
            'delete_broadcast_list', 'get_list_recipients', 'update_list_recipients', 
            'get_broadcast_schedules', 'delete_broadcast_schedule'
        ];
        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_autwa_' . $action, array($this, 'ajax_' . $action));
        }
    }

    public function ensure_cron_is_scheduled() {
        if (!wp_next_scheduled('autwa_send_scheduled_messages_cron')) {
            wp_schedule_event(time(), 'every_minute', 'autwa_send_scheduled_messages_cron');
        }
    }
    
    
 private function call_n8n_proxy($target_endpoint, $payload = [], $method = 'POST') {
    $n8n_webhook_url = get_option('autwa_n8n_webhook_url', 'https://n8n.autowhats.com.mx/webhook/84ccc3da-be97-4fc8-b9e1-7845b09e88a1');
    $license_key = get_option('autwa_edd_license_key');

    if (empty($n8n_webhook_url)) {
        return new WP_Error('not_configured', __('The Webhook URL is not configured.', 'autowa-whatsapp'));
    }

    $body_for_n8n = [
        'license_key'     => $license_key,
        'site_url'        => home_url(),
        'target_endpoint' => $target_endpoint,
        'method_for_waha' => $method,
        'payload'         => $payload
    ];

    $secret = get_option('autwa_webhook_secret');
    if (empty($secret)) {
        $secret = wp_generate_password(48, false);
        update_option('autwa_webhook_secret', $secret);
    }
    
    $timestamp = time();
    $body_string = json_encode($body_for_n8n);
    $signature = hash_hmac('sha256', $timestamp . '.' . $body_string, $secret);

    $args = [
        'method'    => 'POST', 
        'body'      => $body_string,
        'headers'   => [
            'Content-Type'        => 'application/json',
            'X-AutoWA-Signature'  => $signature,
            'X-AutoWA-Timestamp'  => $timestamp,
        ],
        'timeout'   => 45,
        'sslverify' => true,
    ];
    
    $response = wp_remote_request($n8n_webhook_url, $args);
    return $response;
}

     public function send_immediate_whatsapp_message($phone, $message) {
        if (empty($phone) || empty($message)) {
            return new WP_Error('missing_data', __('Phone or message empty.', 'autowa-whatsapp'));
        }

        $kapso = AutoWA_Kapso_Client::get_instance();
        return $kapso->send_text_message($phone, $message);
    }

    public function load_textdomain() {
        load_plugin_textdomain('autowa-whatsapp', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    private function get_directory_size($directory){$size=0;if(is_dir($directory)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,RecursiveDirectoryIterator::SKIP_DOTS))as $file){$size+=$file->getSize();}}return $size;}
    private function format_bytes($size,$precision=2){if($size===0){return '0 B';}$units=array('B','KB','MB','GB','TB');$base=log($size,1024);return round(pow(1024,$base-floor($base)),$precision).' '.$units[floor($base)];}
    public function ajax_cleanup_data(){check_ajax_referer('autwa_nonce','nonce');if(!current_user_can('manage_options')){wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp'));}$cleanup_type=sanitize_text_field($_POST['cleanup_type']);switch($cleanup_type){case 'logs':$result=$this->cleanup_logs();break;case 'sessions':$result=$this->cleanup_inactive_sessions();break;case 'files':$result=$this->cleanup_files();break;case 'reset':$result=$this->reset_plugin_data();break;case 'all':$result=$this->cleanup_all_data();break;default:wp_send_json_error(array('message'=>__('Invalid type', 'autowa-whatsapp')));return;}if($result){wp_send_json_success(array('message'=>$result));}else{wp_send_json_error(array('message'=>__('Error during cleanup.', 'autowa-whatsapp')));}}
    public function ajax_clear_cache() {
        check_ajax_referer('autwa_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp')); }
        
        global $wpdb;
        
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_autwa_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_autwa_%'");
        
        $clear_tables = isset($_POST['clear_tables']) && $_POST['clear_tables'] === 'true';
        if ($clear_tables) {
            $chats_table = $wpdb->prefix . 'autwa_chats';
            $contacts_table = $wpdb->prefix . 'autwa_contacts';
            
            $wpdb->query("TRUNCATE TABLE $chats_table");
            $wpdb->query("TRUNCATE TABLE $contacts_table");
        }
        
        wp_send_json_success(array('message' => __('Cache cleared successfully.', 'autowa-whatsapp')));
    }
    
public function setup_ajax_filters() {
    // Si es una petición AJAX de nuestro plugin...
    if (wp_doing_ajax() && isset($_REQUEST['action']) && strpos($_REQUEST['action'], 'autwa_') === 0) {
        // Desactivamos filtros de contenido que pueden alterar URLs o hacer clickable
        remove_filter('the_content', 'make_clickable');
        remove_filter('the_excerpt', 'make_clickable');
        remove_filter('widget_text_content', 'make_clickable');
        remove_filter('comment_text', 'make_clickable');
        add_filter('clean_url', array($this, 'preserve_url'), 99, 3);
    }
}

public function preserve_url($url, $original_url = '', $context = '') {
   
    $action = $_REQUEST['action'] ?? '';

    
    if (strpos($action, 'autwa_') === 0) {
        return $original_url ?: $url;
    }
    return $url;
}
 
   public function ajax_test_connection(){
    check_ajax_referer('autwa_nonce','nonce');
    if(!current_user_can('manage_options')){
        wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp'));
    }

    $waha_payload = []; // No se necesita payload para esta petición
    $target_endpoint = '/sessions';
    
    $response = $this->call_n8n_proxy($target_endpoint, $waha_payload, 'GET');

    if (is_wp_error($response)) {
        wp_send_json_error(array('message'=> sprintf(__('Connection error: %s', 'autowa-whatsapp'), $response->get_error_message())));
        return;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if($response_code === 200){
        $data = json_decode($response_body,true);
        $session_count=is_array($data)?count($data):0;
        wp_send_json_success(array('message'=>sprintf(__('✅ Connection successful. Found %d sessions.', 'autowa-whatsapp'), $session_count)));
    } elseif ($response_code === 401) { // Error de licencia desde n8n
        $error_data = json_decode($response_body, true);
        $error_message = isset($error_data['data']['message']) ? $error_data['data']['message'] : __('Authentication error. Check your License Key.', 'autowa-whatsapp');
        wp_send_json_error(array('message' => '❌ ' . $error_message));
    } else {
        wp_send_json_error(array('message'=>sprintf(__('❌ Connection error via proxy. HTTP Code: %1$s.', 'autowa-whatsapp'), $response_code)));
    }
}
    public function ajax_get_recent_logs(){check_ajax_referer('autwa_nonce','nonce');if(!current_user_can('manage_options')){wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp'));}global $wpdb;$logs_table=$wpdb->prefix.'autwa_logs';$logs=$wpdb->get_results("SELECT session_name, event_type, message, created_at FROM $logs_table ORDER BY created_at DESC LIMIT 50");if($wpdb->last_error){
            error_log("AutoWA Database Error: " . $wpdb->last_error);
            wp_send_json_error(array('message'=>__('A database error occurred. Please check system logs.', 'autowa-whatsapp')));
            return;
        }wp_send_json_success(array('logs'=>$logs));}
    public function ajax_get_current_config(){
        check_ajax_referer('autwa_nonce','nonce');
        if(!current_user_can('manage_autowhats')){wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp'));}
        $config = array(
            'api_url' => get_option('autwa_api_url', ''),
            'api_key_set' => !empty(get_option('autwa_api_key', '')),
            'webhook_url' => get_option('autwa_webhook_url', ''),
            'session_name' => get_option('autwa_session_name', 'default')
        );
        wp_send_json_success(array('config' => $config));
    }
    private function cleanup_logs(){global $wpdb;$logs_table=$wpdb->prefix.'autwa_logs';$deleted=$wpdb->query("DELETE FROM $logs_table");return sprintf(_n('%d log record deleted.', '%d log records deleted.', $deleted, 'autowa-whatsapp'), $deleted);}
    private function cleanup_inactive_sessions(){global $wpdb;$sessions_table=$wpdb->prefix.'autwa_sessions';$deleted=$wpdb->query("DELETE FROM $sessions_table WHERE status NOT IN ('WORKING')");return sprintf(_n('%d inactive session deleted.', '%d inactive sessions deleted.', $deleted, 'autowa-whatsapp'), $deleted);}
    private function cleanup_files(){$upload_dir=wp_upload_dir();$autwa_dir=$upload_dir['basedir'].'/autwa-whatsapp/';if(is_dir($autwa_dir)){$this->recursive_rmdir($autwa_dir);$this->create_plugin_directories();}return __('Temporary files deleted.', 'autowa-whatsapp');}
    private function reset_plugin_data(){$this->stop_all_sessions();$this->cleanup_logs();global $wpdb;$sessions_table=$wpdb->prefix.'autwa_sessions';$wpdb->query("DELETE FROM $sessions_table");update_option('autwa_api_url','https://autowhats.com.mx/api');update_option('autwa_api_key','');update_option('autwa_webhook_url','');update_option('autwa_session_name','default');$this->cleanup_files();return __('Plugin completely reset.', 'autowa-whatsapp');}
    private function cleanup_all_data(){$this->stop_all_sessions();self::drop_tables();self::delete_plugin_options();self::cleanup_plugin_files();return __('All plugin data has been deleted.', 'autowa-whatsapp');}
    public function delete_backup_file($backup_file){if(file_exists($backup_file)){unlink($backup_file);}}
    public function log_event($session_name,$event_type,$message,$data=null){global $wpdb;$logs_table=$wpdb->prefix.'autwa_logs';if($wpdb->get_var("SHOW TABLES LIKE '$logs_table'")!=$logs_table){return false;}$result=$wpdb->insert($logs_table,array('session_name'=>$session_name,'event_type'=>$event_type,'message'=>$message,'data'=>is_array($data)?json_encode($data):$data,'created_at'=>current_time('mysql')));if($result===false){error_log("AutoWA: Error saving log: ".$wpdb->last_error);}return $result;}
    public function verify_plugin_integrity(){
        global $wpdb;
        $issues=array();
        $required_tables=array(
            $wpdb->prefix.'autwa_sessions',
            $wpdb->prefix.'autwa_logs',
            $wpdb->prefix.'autwa_contacts',
            $wpdb->prefix.'autwa_chats'
        );
        foreach($required_tables as $table){
            if($wpdb->get_var("SHOW TABLES LIKE '$table'")!=$table){
                $issues[]=sprintf(__('Missing table: %s', 'autowa-whatsapp'), $table);
            }
        }
        $upload_dir=wp_upload_dir();
        $autwa_dir=$upload_dir['basedir'].'/autwa-whatsapp/';
        if(!is_dir($autwa_dir)){
            $issues[]=sprintf(__('File directory does not exist: %s', 'autowa-whatsapp'), $autwa_dir);
        }
        if(empty(get_option('autwa_api_url'))){
            $issues[]=__('API URL not configured', 'autowa-whatsapp');
        }
        return $issues;
    }
    public function repair_plugin(){
        $this->create_tables();
        $this->create_plugin_directories();
        $this->set_default_options();
        $this->log_event('system','repair',__('Plugin automatically repaired', 'autowa-whatsapp'));
    }
    public function init(){
        $this->fix_broadcast_recipients_table();
        $issues=$this->verify_plugin_integrity();
        if(!empty($issues)){
            $this->repair_plugin();
        }
        $current_version=get_option('autwa_plugin_version');
        if($current_version!==AUTWA_PLUGIN_VERSION){
            $this->update_plugin($current_version);
        }
    }
    
    private function fix_broadcast_recipients_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'autwa_broadcast_recipients';
    
    // Check if the table exists to avoid errors on first activation
    if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        return;
    }

    // Check if the incorrect index exists
    $index_exists = $wpdb->get_var("SHOW INDEX FROM $table WHERE Key_name = 'list_contact'");
    
    if ($index_exists) {
        // Drop incorrect index
        $wpdb->query("ALTER TABLE $table DROP INDEX `list_contact`");
        
        // Add correct index
        $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY `list_recipient` (`list_id`, `recipient_id`)");
        
        error_log('AutoWA: Fixed broadcast_recipients table index');
    }
}
    
    private function update_plugin($old_version){
        $this->create_tables();
        $this->set_default_options();
        update_option('autwa_plugin_version',AUTWA_PLUGIN_VERSION);
        $this->log_event('system','update',sprintf(__('Plugin updated from %1$s to %2$s', 'autowa-whatsapp'), $old_version, AUTWA_PLUGIN_VERSION));
    }
    
    public function activate(){
        $this->create_tables();
        $this->set_default_options();
        $this->create_plugin_directories();
        add_option('autwa_plugin_version',AUTWA_PLUGIN_VERSION);
        add_option('autwa_plugin_activated_time',current_time('mysql'));
          
        if (!wp_next_scheduled('autwa_send_scheduled_messages_cron')) {
            wp_schedule_event(time(), 'every_minute', 'autwa_send_scheduled_messages_cron');
        }

        // Asignar capability manage_autowhats a los roles de administrador y editor
        $roles_to_assign = ['administrator', 'editor'];
        foreach ($roles_to_assign as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('manage_autowhats');
            }
        }
       
        if(ob_get_level()){ob_clean();}
    }
    
    public function deactivate(){
        $this->stop_all_sessions();
        wp_clear_scheduled_hook('autwa_cleanup_sessions');
        wp_clear_scheduled_hook('autwa_send_scheduled_messages_cron');
    }

    public static function uninstall(){
        self::drop_tables();
        self::delete_plugin_options();
        self::cleanup_plugin_files();
    }
    
    private function set_default_options(){
        add_option('autwa_api_url','https://autowhats.com.mx/api');
        add_option('autwa_api_key','');
        add_option('autwa_webhook_url','');
        add_option('autwa_session_name','default');
        add_option('autwa_debug_mode',false);
        add_option('autwa_auto_restart',false);
        add_option('autwa_min_delay', 1); // Delay mínimo en segundos
        add_option('autwa_max_delay', 5); // Delay máximo en segundos
        add_option('autwa_n8n_webhook_url', 'https://n8n.autowhats.com.mx/webhook/84ccc3da-be97-4fc8-b9e1-7845b09e88a1');
        if (empty(get_option('autwa_webhook_secret'))) {
            add_option('autwa_webhook_secret', wp_generate_password(48, false));
        }
    }
    private function create_plugin_directories(){$upload_dir=wp_upload_dir();$autwa_dir=$upload_dir['basedir'].'/autwa-whatsapp/';$logs_dir=$autwa_dir.'logs/';if(!file_exists($autwa_dir)){wp_mkdir_p($autwa_dir);}if(!file_exists($logs_dir)){wp_mkdir_p($logs_dir);}$htaccess_file=$autwa_dir.'.htaccess';if(!file_exists($htaccess_file)){file_put_contents($htaccess_file,"Order deny,allow\nDeny from all");}}
    private function stop_all_sessions(){global $wpdb;$table_name=$wpdb->prefix.'autwa_sessions';$active_sessions=$wpdb->get_results("SELECT session_name FROM $table_name WHERE status IN ('WORKING', 'STARTING', 'SCAN_QR_CODE')");foreach($active_sessions as $session){$this->stop_session_api($session->session_name);}}
    private function stop_session_api($session_name){
    if(empty($session_name)){
        return false;
    }
    $waha_payload = []; 
    $target_endpoint = '/sessions/'.$session_name.'/stop';
    $this->call_n8n_proxy($target_endpoint, $waha_payload, 'POST');

    return true;
}
    private static function drop_tables(){
        global $wpdb;
        $tables=array(
            $wpdb->prefix.'autwa_sessions',
            $wpdb->prefix.'autwa_logs',
            $wpdb->prefix.'autwa_contacts',
            $wpdb->prefix.'autwa_chats',
            $wpdb->prefix.'autwa_scheduled_messages',
            $wpdb->prefix.'autwa_broadcast_lists',
            $wpdb->prefix.'autwa_broadcast_recipients',
            $wpdb->prefix.'autwa_broadcast_schedules'
        );
        foreach($tables as $table){
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // 1. Tabla de Sesiones (Sin cambios)
        $table_name_sessions = $wpdb->prefix . 'autwa_sessions';
        $sql_sessions = "CREATE TABLE $table_name_sessions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            session_name varchar(100) NOT NULL,
            status varchar(50) DEFAULT 'STOPPED' NOT NULL,
            qr_code text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_name (session_name)
        ) $charset_collate;";

        // 2. Tabla de Mensajes Programados (ACTUALIZADA)
        $table_name_scheduled = $wpdb->prefix . 'autwa_scheduled_messages';
        $sql_scheduled = "CREATE TABLE $table_name_scheduled (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            phone varchar(20) NOT NULL,
            message text NOT NULL,
            media_url text DEFAULT NULL,           -- NUEVO: URL pública del archivo
            media_filename varchar(255) DEFAULT NULL, -- NUEVO: Nombre del archivo
            message_type varchar(20) DEFAULT 'text',  -- NUEVO: 'text', 'image', 'document'
            status varchar(20) DEFAULT 'pending' NOT NULL,
            sent_at datetime DEFAULT NULL,
            scheduled_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // 3. Tabla de Chats (Sin cambios - si la usas)
        $table_name_chats = $wpdb->prefix . 'autwa_chats';
        $sql_chats = "CREATE TABLE $table_name_chats (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            chat_id varchar(100) NOT NULL,
            name varchar(255),
            last_message text,
            timestamp bigint,
            unread_count int DEFAULT 0,
            profile_pic_url text,
            is_group boolean DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY chat_id (chat_id)
        ) $charset_collate;";

        // 4. Tabla de Listas de Difusion (NUEVO)
        $table_name_lists = $wpdb->prefix . 'autwa_broadcast_lists';
        $sql_lists = "CREATE TABLE $table_name_lists (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name)
        ) $charset_collate;";

        // 5. Tabla de Destinatarios de Difusion (NUEVO)
        $table_name_recipients = $wpdb->prefix . 'autwa_broadcast_recipients';
        $sql_recipients = "CREATE TABLE $table_name_recipients (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            list_id mediumint(9) NOT NULL,
            recipient_id varchar(100) NOT NULL,
            recipient_name varchar(255) DEFAULT NULL,
            recipient_type varchar(50) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY list_recipient (list_id, recipient_id)
        ) $charset_collate;";

        // 6. Tabla de Programaciones de Difusion (NUEVO)
        $table_name_schedules = $wpdb->prefix . 'autwa_broadcast_schedules';
        $sql_schedules = "CREATE TABLE $table_name_schedules (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            list_id mediumint(9) NOT NULL,
            message text NOT NULL,
            media_url text DEFAULT NULL,
            media_filename varchar(255) DEFAULT NULL,
            message_type varchar(20) DEFAULT 'text',
            status varchar(20) DEFAULT 'pending' NOT NULL,
            retry_count int DEFAULT 0,
            api_response text DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            scheduled_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // dbDelta es mágico: si la tabla ya existe, la compara y agrega SOLO las columnas que faltan.
        dbDelta($sql_sessions);
        dbDelta($sql_scheduled);
        dbDelta($sql_chats);
        dbDelta($sql_lists);
        dbDelta($sql_recipients);
        dbDelta($sql_schedules);
    }

    private static function delete_plugin_options(){$options=array('autwa_api_url','autwa_api_key','autwa_webhook_url','autwa_session_name','autwa_debug_mode','autwa_auto_restart','autwa_plugin_version','autwa_plugin_activated_time');foreach($options as $option){delete_option($option);}global $wpdb;$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'autwa_%'");}
    private static function cleanup_plugin_files(){$upload_dir=wp_upload_dir();$autwa_dir=$upload_dir['basedir'].'/autwa-whatsapp/';if(is_dir($autwa_dir)){self::recursive_rmdir($autwa_dir);}}
    private static function recursive_rmdir($dir){if(is_dir($dir)){$objects=scandir($dir);foreach($objects as $object){if($object!="."&&$object!=".."){if(is_dir($dir."/".$object)){self::recursive_rmdir($dir."/".$object);}else{unlink($dir."/".$object);}}}rmdir($dir);}}
    
    public function add_admin_menu() {
        // The base permission is changed to 'edit_posts' so roles like Editor can see it.
        $base_capability = 'manage_autowhats';
    
        add_menu_page(
            __('AutoWA WhatsApp', 'autowa-whatsapp'),
            __('AutoWA WhatsApp', 'autowa-whatsapp'),
            $base_capability, // <--- KEY CHANGE
            'autwa-whatsapp',
            array($this->admin_pages, 'render_config_page'), 
            'dashicons-smartphone',
            30
        );
        add_submenu_page(
            'autwa-whatsapp',
            __('Settings', 'autowa-whatsapp'),
            __('Settings', 'autowa-whatsapp'),
            $base_capability, // <--- KEY CHANGE: Allows non-admins to see the tab
            'autwa-whatsapp', // This is the slug for the main/settings page
            array($this->admin_pages, 'render_config_page') 
        );
        add_submenu_page(
            'autwa-whatsapp',
            __('Manage Chats', 'autowa-whatsapp'),
            __('Manage Chats', 'autowa-whatsapp'),
            $base_capability, // <--- Permission for non-admins
            'autwa-chats',
            array($this->admin_pages, 'admin_page_chats')
        );
        add_submenu_page(
            'autwa-whatsapp',
            __('Contacts', 'autowa-whatsapp'),
            __('Contacts', 'autowa-whatsapp'),
            $base_capability, // <--- Permission for non-admins
            'autwa-contacts',
            array($this->admin_pages, 'admin_page_contacts')
        );
        add_submenu_page(
            'autwa-whatsapp',
            __('Scheduler', 'autowa-whatsapp'),
            __('Scheduler', 'autowa-whatsapp'),
            $base_capability, // <--- Permission for non-admins
            'autwa-scheduler',
            array($this->admin_pages, 'admin_page_scheduler')
        );
       
    }
    
       public function enqueue_admin_scripts($hook) {
        // Load styles on all plugin pages
        if (strpos($hook, 'autwa-') !== false || $hook === 'toplevel_page_autwa-whatsapp') {
            wp_enqueue_style('autwa-styles', AUTWA_PLUGIN_URL . 'assets/css/autowhats-styles.css', array(), AUTWA_PLUGIN_VERSION);
            
        }

        // Load scripts only on the specific page where they are needed
        if ($hook === 'toplevel_page_autwa-whatsapp') { // Settings Page
            wp_enqueue_script('autwa-config-js', AUTWA_PLUGIN_URL . 'assets/js/autowhats-config-page.js', array('jquery', 'wp-i18n'), AUTWA_PLUGIN_VERSION, true);

            wp_enqueue_script('autwa-debug-cron-js', AUTWA_PLUGIN_URL . 'assets/js/autowhats-debug-cron.js', array('jquery', 'wp-i18n'), AUTWA_PLUGIN_VERSION, true);

            wp_localize_script('autwa-config-js', 'autwa_ajax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('autwa_nonce')]);
            wp_set_script_translations('autwa-config-js', 'autowa-whatsapp');
        }

        if ($hook === 'autowa-whatsapp_page_autwa-chats') { // Chats Page
            wp_enqueue_script('autwa-chats-js', AUTWA_PLUGIN_URL . 'assets/js/autowhats-chats.js', array('jquery', 'wp-i18n'), AUTWA_PLUGIN_VERSION, true);
            wp_localize_script('autwa-chats-js', 'autwa_ajax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('autwa_nonce')]);
            wp_set_script_translations('autwa-chats-js', 'autowa-whatsapp');
        }

        if ($hook === 'autowa-whatsapp_page_autwa-scheduler') { // Scheduler Page
            wp_enqueue_script('autwa-scheduler-js', AUTWA_PLUGIN_URL . 'assets/js/autowhats-scheduler.js', array('jquery', 'wp-i18n'), AUTWA_PLUGIN_VERSION, true);
            wp_localize_script('autwa-scheduler-js', 'autwa_ajax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('autwa_nonce')]);
            wp_set_script_translations('autwa-scheduler-js', 'autowa-whatsapp');
            wp_enqueue_media();
        }
        
        if ($hook === 'autowa-whatsapp_page_autwa-contacts') { // Contacts Page
            wp_enqueue_script('autwa-contacts-js', AUTWA_PLUGIN_URL . 'assets/js/autowhats-contacts.js', array('jquery', 'wp-i18n'), AUTWA_PLUGIN_VERSION, true);
            wp_localize_script('autwa-contacts-js', 'autwa_ajax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('autwa_nonce')]);
            wp_set_script_translations('autwa-contacts-js', 'autowa-whatsapp');
        }
    }
    
 public function ajax_create_session() { 
    check_ajax_referer('autwa_nonce', 'nonce'); 
    if (!current_user_can('manage_options')) { 
        wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp')); 
    } 
    
    $session_name = get_option('autwa_session_name', 'default'); 
    

    $waha_payload = array('name' => $session_name); 

   
    $target_endpoint = '/sessions';

 
    $response = $this->call_n8n_proxy($target_endpoint, $waha_payload, 'POST');

    if (is_wp_error($response)) { 
        wp_send_json_error(array('message' => sprintf(__('Connection error: %s', 'autowa-whatsapp'), $response->get_error_message()))); 
        return; 
    } 
    
    $response_code = wp_remote_retrieve_response_code($response); 
    $response_body = wp_remote_retrieve_body($response);
    
    if ($response_code === 201 || $response_code === 200) { 
        wp_send_json_success(array('message' => sprintf(__('Session "%s" created successfully.', 'autowa-whatsapp'), esc_html($session_name)))); 
    } elseif ($response_code === 409) { 
        wp_send_json_success(array('message' => sprintf(__('Session "%s" already exists.', 'autowa-whatsapp'), esc_html($session_name)))); 
    } elseif ($response_code === 401) { // Error de licencia desde n8n
        $error_data = json_decode($response_body, true);
        $error_message = isset($error_data['data']['message']) ? $error_data['data']['message'] : __('Authentication error. Check your License Key.', 'autowa-whatsapp');
        wp_send_json_error(array('message' => $error_message));
    } else { 
        $error_data = json_decode($response_body, true); 
        $error_message = isset($error_data['message']) ? $error_data['message'] : __('Unknown error', 'autowa-whatsapp'); 
        wp_send_json_error(array('message' => sprintf(__('API Error (%1$s): %2$s', 'autowa-whatsapp'), $response_code, $error_message))); 
    } 
}
    
   public function ajax_start_session() { 
    check_ajax_referer('autwa_nonce', 'nonce'); 
    if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp')); } 
    
    $session_name = get_option('autwa_session_name', 'default'); 
    $target_endpoint = '/sessions/' . $session_name . '/start';
    
    $response = $this->call_n8n_proxy($target_endpoint, [], 'POST');

    if (is_wp_error($response)) { 
        wp_send_json_error(array('message' => sprintf(__('Connection error: %s', 'autowa-whatsapp'), $response->get_error_message()))); 
    } 
    
    $response_code = wp_remote_retrieve_response_code($response); 
    if ($response_code === 200 || $response_code === 201) { 
        wp_send_json_success(array('message' => sprintf(__('Session "%s" started successfully.', 'autowa-whatsapp'), esc_html($session_name)))); 
    } else { 
        $response_body = wp_remote_retrieve_body($response); 
        $error_data = json_decode($response_body, true); 
        $error_message = isset($error_data['message']) ? $error_data['message'] : __('Unknown error', 'autowa-whatsapp'); 
        wp_send_json_error(array('message' => sprintf(__('Error starting session \'%1$s\' (%2$s): %3$s', 'autowa-whatsapp'), esc_html($session_name), $response_code, $error_message))); 
    } 
}
    
   public function ajax_restart_session() { 
    check_ajax_referer('autwa_nonce', 'nonce'); 
    if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp')); } 
    
    $session_name = get_option('autwa_session_name', 'default'); 
    $target_endpoint = '/sessions/' . $session_name . '/restart';

    $response = $this->call_n8n_proxy($target_endpoint, [], 'POST');
    
    if (is_wp_error($response)) { 
        wp_send_json_error(array('message' => sprintf(__('Connection error: %s', 'autowa-whatsapp'), $response->get_error_message()))); 
    } 
    
    $response_code = wp_remote_retrieve_response_code($response); 
    if ($response_code === 200 || $response_code === 201) { 
        wp_send_json_success(array('message' => __('Session restarted.', 'autowa-whatsapp'))); 
    } else { 
        $response_body = wp_remote_retrieve_body($response); 
        $error_data = json_decode($response_body, true); 
        $error_message = isset($error_data['message']) ? $error_data['message'] : __('Unknown error', 'autowa-whatsapp'); 
        wp_send_json_error(array('message' => sprintf(__('Error restarting (%1$s): %2$s', 'autowa-whatsapp'), $response_code, $error_message))); 
    } 
}
    
    public function ajax_get_qr() { 
    check_ajax_referer('autwa_nonce', 'nonce'); 
    if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp')); } 
    
    $session_name = get_option('autwa_session_name', 'default'); 
    $target_endpoint = '/' . $session_name . '/auth/qr';
    
    $response = $this->call_n8n_proxy($target_endpoint, [], 'GET');

    if (is_wp_error($response)) { 
        wp_send_json_error(array('message' => sprintf(__('Connection error: %s', 'autowa-whatsapp'), $response->get_error_message()))); 
        return; 
    } 
    
    $response_code = wp_remote_retrieve_response_code($response); 
    $response_body = wp_remote_retrieve_body($response); 
    $response_headers = wp_remote_retrieve_headers($response); 
    
    if ($response_code === 200) { 
        
        $content_type = isset($response_headers['content-type']) ? strtolower($response_headers['content-type']) : ''; 
        if (strpos($content_type, 'image/') === 0) { 
            $base64_image = base64_encode($response_body); 
            $qr_data = "data:$content_type;base64,$base64_image"; 
            wp_send_json_success(array('qr_image' => $qr_data, 'debug_info' => array('format' => 'binary_image'))); 
            return; 
        } 
        
        $data = json_decode($response_body, true); 
        if (json_last_error() === JSON_ERROR_NONE) { 
            $possible_fields = ['qrCode', 'qr', 'base64', 'image', 'data', 'code']; 
            $qr_data = null; 
            foreach ($possible_fields as $field) { 
                if (isset($data[$field]) && !empty($data[$field])) { 
                    $qr_data = $data[$field]; 
                    break; 
                } 
            } 
            if ($qr_data) { 
                if (strpos($qr_data, 'data:image') !== 0)  { 
                    $qr_data = 'data:image/png;base64,' . $qr_data; 
                } 
                wp_send_json_success(array('qr_image' => $qr_data)); 
            } else { 
                wp_send_json_error(array('message' => __('No QR data found.', 'autowa-whatsapp'))); 
            } 
        } else { 
            wp_send_json_error(array('message' => __('Unrecognized response format.', 'autowa-whatsapp'))); 
        } 
    } else { 
        $error_data = json_decode($response_body, true); 
        $error_message = isset($error_data['message']) ? $error_data['message'] : __('Error', 'autowa-whatsapp'); 
        wp_send_json_error(array('message' => sprintf(__('QR Error (%1$s): %2$s', 'autowa-whatsapp'), $response_code, $error_message))); 
    } 
}
    
    public function ajax_check_session_status() {
        check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_autowhats')) {
        wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp'));
    }

    $session_name = get_option('autwa_session_name', 'default');

    // Este es un chequeo de estado directo, no la orquestación completa.
    // Le damos a n8n el endpoint y el payload que necesita para encontrar la sesión.
    $target_endpoint = '/sessions/' . $session_name; 
    $waha_payload = ['name' => $session_name];

    $response = $this->call_n8n_proxy($target_endpoint, $waha_payload, 'GET');

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($response_code === 200) {
        $data = json_decode($response_body, true);
        $status = isset($data['status']) ? $data['status'] : 'UNKNOWN';
        wp_send_json_success(array('status' => $status, 'raw' => $data, 'session_name' => $session_name));
    } elseif ($response_code === 404) {
        wp_send_json_success(array('status' => 'NOT_FOUND', 'raw' => json_decode($response_body, true), 'session_name' => $session_name));
    } else {
        wp_send_json_error(array('message' => 'API Error via proxy.', 'raw' => json_decode($response_body, true)));
    }
}
    
  public function ajax_get_contacts() {
      if (isset($_GET['clear_autwa_cache'])) {
    delete_transient('autwa_contacts_' . get_option('autwa_session_name', 'default'));
    wp_die('Cache cleared. Quita este snippet ahora.');
}
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_autowhats')) {
        wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp'));
    }

    $session_name  = get_option('autwa_session_name', 'default');
    // Aceptar tanto "true"/"1" como bool real, evita falsos negativos
    $force_refresh = isset($_POST['force_refresh'])
        && in_array((string) $_POST['force_refresh'], ['true', '1'], true);
    $transient_key = 'autwa_contacts_' . $session_name;

    if ($force_refresh) {
        delete_transient($transient_key);
    }

    $cached_contacts = get_transient($transient_key);
    if (false !== $cached_contacts) {
        wp_send_json_success(array('contacts' => $cached_contacts, 'source' => 'cache'));
        return;
    }

    $target_endpoint = '/' . $session_name . '/contacts/all';
    $response = $this->call_n8n_proxy($target_endpoint, [], 'GET');

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Connection error: %s', 'autowa-whatsapp'), $response->get_error_message())
        ));
        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($response_code !== 200) {
        wp_send_json_error(array(
            'message' => sprintf(__('Error fetching contacts (%s)', 'autowa-whatsapp'), $response_code),
            'details' => $response_body,
        ));
        return;
    }

    $raw_data = json_decode($response_body, true);
    $contacts_from_api = [];

    if (isset($raw_data['data']) && is_array($raw_data['data'])) {
        $contacts_from_api = $raw_data['data'];
    } elseif (is_array($raw_data)) {
        $contacts_from_api = $raw_data;
    }

    $filtered_contacts = [];
    $processed_ids     = [];
    $debug_total       = count($contacts_from_api);
    $debug_kept        = 0;

    foreach ($contacts_from_api as $contact) {
        // 1. Normalizar ID
        $raw_id = $contact['id'] ?? null;
        $contact_id = is_string($raw_id)
            ? $raw_id
            : ($raw_id['_serialized'] ?? null);

        if (!$contact_id) continue;

        // 2. Excluir grupos, broadcasts, status, newsletters y servicios
        if (strpos($contact_id, '@g.us')       !== false) continue;
        if (strpos($contact_id, '@broadcast')  !== false) continue;
        if (strpos($contact_id, '@newsletter') !== false) continue;
        if (strpos($contact_id, 'status@')     !== false) continue;
        if ($contact_id === '0@c.us')                     continue;

        // 3. Evitar duplicados
        if (in_array($contact_id, $processed_ids, true)) continue;

        // 4. ⭐ FILTRO CLAVE: solo contactos REALMENTE guardados
        if (!$this->is_real_saved_contact($contact)) continue;

        $processed_ids[] = $contact_id;
        $debug_kept++;

        // 5. Normalizar nombre visible
        $contact['id'] = $contact_id;
        $display_name = $contact['name']
            ?? $contact['pushname']
            ?? $contact['number']
            ?? $contact_id;

        $contact['name'] = $display_name;
        if (empty($contact['shortName'])) {
            $contact['shortName'] = $display_name;
        }

        $filtered_contacts[] = $this->fix_media_urls($contact);
    }

    // Orden alfabético
    usort($filtered_contacts, function ($a, $b) {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    // Log temporal para depuración (revisa wp-content/debug.log)
    error_log("AutoWA Contacts Filter: recibidos {$debug_total}, conservados {$debug_kept}");

    set_transient($transient_key, $filtered_contacts, 12 * HOUR_IN_SECONDS);
    wp_send_json_success(array(
        'contacts' => $filtered_contacts,
        'source'   => 'api',
        'debug'    => ['total' => $debug_total, 'kept' => $debug_kept],
    ));
}


private function is_real_saved_contact($contact) {
    // Regla 0: los IDs @lid son identificadores internos de Baileys
    // para participantes de grupos. NUNCA son contactos guardados.
    $contact_id = $contact['id'] ?? '';
    if (is_string($contact_id) && strpos($contact_id, '@lid') !== false) {
        return false;
    }

    // Regla A: `isMyContact` es la fuente de verdad cuando existe (engine WEBJS).
    if (array_key_exists('isMyContact', $contact)) {
        return (bool) $contact['isMyContact'];
    }

    // Regla B (engine NOWEB / Baileys): los contactos REALMENTE guardados
    // traen el resultado del "merge" interno de WhatsApp: `lid` (Linked ID)
    // y/o `phoneNumber` (JID normalizado @s.whatsapp.net).
    // Los participantes de grupo solo vistos NO traen estos campos.
    $has_lid          = !empty($contact['lid']);
    $has_phone_number = !empty($contact['phoneNumber']);

    if ($has_lid || $has_phone_number) {
        return true; // Caso Arturo Del Castillo, Sunny BlueS, etc.
    }

    // Regla C (fallback estricto): sin lid ni phoneNumber,
    // exigir que `name` sea distinto a `pushname` y al `number`,
    // y no sea un nombre vacío o simbólico.
    $name     = isset($contact['name'])     ? trim((string) $contact['name'])     : '';
    $pushname = isset($contact['pushname']) ? trim((string) $contact['pushname']) : '';
    $number   = isset($contact['number'])   ? trim((string) $contact['number'])   : '';

    if ($name === '')                              return false;
    if ($number !== ''   && $name === $number)     return false;
    if ($pushname !== '' && $name === $pushname)   return false; // Salvatore, rk, etc.

    return true;
}

    public function ajax_get_groups() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_autowhats')) {
        wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp'));
    }

    $session_name = get_option('autwa_session_name', 'default');

    // Detección permisiva de force_refresh
    $raw_force     = $_POST['force_refresh'] ?? '';
    $force_refresh = filter_var($raw_force, FILTER_VALIDATE_BOOLEAN);

    $transient_key = 'autwa_groups_final_' . $session_name;

    if ($force_refresh) {
        delete_transient($transient_key);
        error_log('AutoWA Groups: force_refresh activo, caché borrada.');
    }

    $cached_groups = get_transient($transient_key);
    if (false !== $cached_groups) {
        wp_send_json_success(array('groups' => $cached_groups, 'source' => 'cache'));
        return;
    }

    $target_endpoint = '/' . $session_name . '/groups';
    $response = $this->call_n8n_proxy($target_endpoint, [], 'GET');

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        wp_send_json_error(array('message' => __('Could not fetch groups from API via proxy.', 'autowa-whatsapp')));
        return;
    }

    $response_body = wp_remote_retrieve_body($response);

    // Reparar JSON concatenado (caso histórico)
    $trimmed_body = trim($response_body);
    if (substr($trimmed_body, 0, 1) === '{' && preg_match('/}\s*{/', $trimmed_body)) {
        $response_body = '[' . preg_replace('/}\s*{/', '},{', $trimmed_body) . ']';
    }

    $raw_data = json_decode($response_body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $raw_data = json_decode('[' . $response_body . ']', true);
    }

    // ⭐ NORMALIZACIÓN: aplanar a una lista uniforme de grupos
    $groups_list = $this->normalize_groups_response($raw_data);

    $processed_groups = [];
    foreach ($groups_list as $group) {
        if (!is_array($group)) continue;

        // 1. Resolver ID
        $group_id = null;
        if (isset($group['id'])) {
            if (is_string($group['id'])) {
                $group_id = $group['id'];
            } elseif (is_array($group['id'])) {
                $group_id = $group['id']['_serialized']
                    ?? (isset($group['id']['user'], $group['id']['server'])
                        ? $group['id']['user'] . '@' . $group['id']['server']
                        : null);
            }
        }
        if (!$group_id && isset($group['JID']) && is_string($group['JID'])) {
            $group_id = $group['JID'];
        }
        // Fallback: el ID puede venir en `_map_key` que añadimos al normalizar
        if (!$group_id && isset($group['_map_key']) && is_string($group['_map_key'])) {
            $group_id = $group['_map_key'];
        }

        if (!$group_id) continue;
        if (strpos($group_id, '@g.us') === false) continue;

        // 2. Nombre
        $raw_name = $group['subject']
            ?? $group['Name']
            ?? $group['name']
            ?? __('Unnamed Group', 'autowa-whatsapp');

        // 3. Participantes (preferimos `size` que es más fiable en NOWEB)
        $participants = $group['participants'] ?? $group['Participants'] ?? [];
        $participant_count = isset($group['size'])
            ? (int) $group['size']
            : (is_array($participants) ? count($participants) : 0);

        // 4. Foto
        $pic = $group['profile_pic_url'] ?? $group['picture'] ?? null;

        $processed_groups[] = [
            'id'               => $group_id,
            'subject'          => $raw_name,
            'isGroup'          => true,
            'participantCount' => $participant_count,
            'profile_pic_url'  => $pic,
            'owner'            => $group['ownerPn'] ?? $group['owner'] ?? null,
            'creation'         => $group['creation'] ?? null,
        ];
    }

    // Orden alfabético por nombre
    usort($processed_groups, function ($a, $b) {
        return strcasecmp($a['subject'] ?? '', $b['subject'] ?? '');
    });

    error_log('AutoWA Groups: procesados ' . count($processed_groups) . ' grupos.');

    set_transient($transient_key, $processed_groups, HOUR_IN_SECONDS);
    wp_send_json_success(array(
        'groups' => $processed_groups,
        'source' => 'api',
        'debug'  => ['count' => count($processed_groups)],
    ));
}

/**
 * Aplana la respuesta a una lista uniforme.
 */
 
private function normalize_groups_response($raw) {
    if (!is_array($raw)) return [];

    // Desenvolver capa `data`
    if (isset($raw['data'])) {
        $raw = $raw['data'];
    }
    if (!is_array($raw)) return [];

    $out = [];

    // ¿Es un array (lista) o un objeto-mapa?
    $is_assoc = array_keys($raw) !== range(0, count($raw) - 1);

    if (!$is_assoc) {
        // Es una lista. Cada elemento puede ser:
        //   a) Un grupo individual con `id` adentro
        //   b) Un objeto-mapa { "id@g.us": {...}, ... }
        foreach ($raw as $item) {
            if (!is_array($item)) continue;

            if (isset($item['id']) || isset($item['JID']) || isset($item['subject'])) {
                // Caso a: grupo individual
                $out[] = $item;
            } else {
                // Caso b: el item ES un mapa de grupos
                foreach ($item as $key => $group) {
                    if (!is_array($group)) continue;
                    if (is_string($key) && empty($group['id'])) {
                        $group['_map_key'] = $key; // por si el id interno no estuviera
                    }
                    $out[] = $group;
                }
            }
        }
    } else {
        // Es un mapa directo { "id@g.us": {...}, ... }
        foreach ($raw as $key => $group) {
            if (!is_array($group)) continue;
            if (is_string($key) && empty($group['id'])) {
                $group['_map_key'] = $key;
            }
            $out[] = $group;
        }
    }

    return $out;
}

 public function ajax_get_chats() { 
        check_ajax_referer('autwa_nonce', 'nonce'); 
        if (!current_user_can('manage_autowhats')) { wp_die(); } 

        global $wpdb;
        $table_chats = $wpdb->prefix . 'autwa_chats';
        $chats = $wpdb->get_results("SELECT chat_id as id, name, last_message, timestamp, unread_count FROM $table_chats ORDER BY timestamp DESC LIMIT 100", ARRAY_A);

        wp_send_json_success(array('chats' => $chats ?: [], 'source' => 'db'));
    }
    
public function ajax_get_chat_messages() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_autowhats')) {
        wp_send_json_error(array('message' => __('No tienes permisos para ver mensajes.', 'autowa-whatsapp')));
        return;
    }
    $chat_id = sanitize_text_field($_POST['chat_id'] ?? '');

    $buffer_key = 'autwa_msg_buffer_default';
    $messages = get_transient($buffer_key);
    $clean = [];

    if (is_array($messages)) {
        $chat_clean = str_replace('@c.us', '', $chat_id);
        foreach ($messages as $m) {
            $from = str_replace('@c.us', '', $m['from'] ?? '');
            if (strpos($from, $chat_clean) !== false || (!empty($m['isOutbound']) && ($m['to'] ?? '') === $chat_id)) {
                $clean[] = [
                    'id'        => $m['id'] ?? uniqid(),
                    'fromMe'    => !empty($m['isOutbound']),
                    'body'      => $m['body'] ?? '',
                    'timestamp' => $m['timestamp'] ?? time(),
                    'hasMedia'  => !empty($m['hasMedia']),
                    'mediaUrl'  => $m['mediaUrl'] ?? ''
                ];
            }
        }
    }

    wp_send_json_success(['messages' => array_reverse($clean)]);
}

public function ajax_send_message() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_autowhats')) {
        wp_send_json_error(['message' => 'Sin permisos']);
    }

    $chat_id = sanitize_text_field($_POST['chat_id'] ?? '');
    $text    = isset($_POST['message']) ? wp_unslash($_POST['message']) : '';

    if (empty($chat_id)) {
        wp_send_json_error(['message' => 'chat_id requerido']);
    }

    $kapso = AutoWA_Kapso_Client::get_instance();
    $has_file = !empty($_FILES['file']) && !empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;

    if ($has_file) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $upload = wp_handle_upload($_FILES['file'], ['test_form' => false]);
        if (isset($upload['error'])) {
            wp_send_json_error(['message' => 'Error al subir archivo: ' . $upload['error']]);
        }

        $media_url = $upload['url'];
        $mime_type = $upload['type'];
        $type = 'document';
        if (strpos($mime_type, 'image/') === 0) $type = 'image';
        elseif (strpos($mime_type, 'audio/') === 0) $type = 'audio';
        elseif (strpos($mime_type, 'video/') === 0) $type = 'video';

        $response = $kapso->send_media_message($chat_id, $media_url, $text, $type, $_FILES['file']['name']);
    } else {
        $response = $kapso->send_text_message($chat_id, $text);
    }

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    // Guardar mensaje saliente en buffer local
    $buffer_key = 'autwa_msg_buffer_default';
    $buffer = get_transient($buffer_key);
    if (!is_array($buffer)) $buffer = [];

    $out_msg = [
        'id'         => 'out_' . uniqid(),
        'from'       => 'me',
        'to'         => $chat_id,
        'body'       => $text,
        'timestamp'  => time(),
        'hasMedia'   => $has_file,
        'isOutbound' => true
    ];
    array_unshift($buffer, $out_msg);
    set_transient($buffer_key, $buffer, 2 * HOUR_IN_SECONDS);

    // Actualizar tabla local de chats
    global $wpdb;
    $table_chats = $wpdb->prefix . 'autwa_chats';
    $wpdb->query($wpdb->prepare(
        "UPDATE $table_chats SET last_message = %s, timestamp = %d WHERE chat_id = %s",
        $text,
        time(),
        $chat_id
    ));

    wp_send_json_success(['message' => 'Mensaje enviado', 'response' => $response]);
}

private function fix_media_urls($data) {
    if (is_array($data)) {
        foreach ($data as $key => &$value) {
      
            // Si la clave es 'body', 'caption', o 'text', no la modificamos y continuamos.
            if (in_array($key, ['body', 'caption', 'text'], true)) {
                continue;
            }
   

            if (is_array($value)) {
                $value = $this->fix_media_urls($value);
            } elseif (is_string($value) && (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0)) {
                $parsed_url = parse_url($value);

                if (isset($parsed_url['host'])) {
                    $api_url_setting = get_option('autwa_api_url', 'https://autowhats.com.mx/api');
                    $correct_host_parts = parse_url($api_url_setting);
                    $correct_host = $correct_host_parts['host'] ?? 'autowhats.com.mx';

                    if ($parsed_url['host'] !== $correct_host || isset($parsed_url['port'])) {

                        $new_url = ($parsed_url['scheme'] ?? 'https') . '://' . $correct_host;

                        if (isset($parsed_url['path'])) {
                            $new_url .= $parsed_url['path'];
                        }
                        if (isset($parsed_url['query'])) {
                            $new_url .= '?' . $parsed_url['query'];
                        }
                        if (isset($parsed_url['fragment'])) {
                            $new_url .= '#' . $parsed_url['fragment'];
                        }

                        $value = $new_url;
                    }
                }
            }
        }
        unset($value); 
    }
    return $data;
}
   
   public function ajax_get_media_file() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_autowhats')) {
        status_header(403);
        wp_die('Access denied.');
    }

    // 1. Datos del frontend
    $message_id = isset($_REQUEST['message_id']) ? sanitize_text_field($_REQUEST['message_id']) : '';
    $chat_id    = isset($_REQUEST['chat_id'])    ? sanitize_text_field($_REQUEST['chat_id'])    : '';
    $visualize  = isset($_REQUEST['visualize']) && $_REQUEST['visualize'] === 'true';

    if (empty($message_id)) {
        status_header(400);
        wp_die('Message ID not provided.');
    }

    $session_name = get_option('autwa_session_name', 'default');

    // 2. Pedir metadata a n8n
    $payload = [
        'name'      => $session_name,
        'chatId'    => $chat_id,
        'messageId' => $message_id,
        'download'  => true,
    ];

    $response = $this->call_n8n_proxy('/proxy-chat-media', $payload, 'POST');

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        error_log('AutoWA Media Error (n8n): ' . print_r($response, true));
        $this->serve_media_placeholder($visualize, 'n8n_unreachable');
        return;
    }

    $response_body = wp_remote_retrieve_body($response);
    $content_type  = wp_remote_retrieve_header($response, 'content-type');

    // 3. Si n8n devolvió JSON, extraer la URL del binario
    if (is_string($content_type) && strpos($content_type, 'application/json') !== false) {
        $json_data = json_decode($response_body, true);
        $file_url  = null;

        if (isset($json_data['url'])) {
            $file_url = $json_data['url'];
        } elseif (isset($json_data['media']['url'])) {
            $file_url = $json_data['media']['url'];
        } elseif (isset($json_data['data'][0]['media']['url'])) {
            $file_url = $json_data['data'][0]['media']['url'];
        } elseif (is_array($json_data) && isset($json_data[0]['media']['url'])) {
            $file_url = $json_data[0]['media']['url'];
        }

        // 3.a Buffer crudo (algunos workflows de n8n)
        if (!$file_url && isset($json_data['data'], $json_data['type']) && $json_data['type'] === 'Buffer' && is_array($json_data['data'])) {
            $response_body = pack('C*', ...$json_data['data']);
            $content_type  = 'application/octet-stream';
        }
        // 3.b Base64 directo
        elseif (!$file_url && isset($json_data['data']) && is_string($json_data['data']) && !empty($json_data['mimetype'])) {
            $maybe_bin = base64_decode($json_data['data'], true);
            if ($maybe_bin !== false) {
                $response_body = $maybe_bin;
                $content_type  = $json_data['mimetype'];
            }
        }
        // 3.c URL: descargar el binario real
        elseif ($file_url) {
            // Reescribir URL si el host interno (puerto 3000, IP privada, etc.) no es alcanzable desde WP
            $file_url      = $this->normalize_media_url($file_url);
            $file_response = wp_remote_get($file_url, [
                'timeout'   => 45,
                'sslverify' => false,
            ]);

            if (is_wp_error($file_response) || wp_remote_retrieve_response_code($file_response) !== 200) {
                $err = is_wp_error($file_response)
                    ? $file_response->get_error_message()
                    : 'HTTP ' . wp_remote_retrieve_response_code($file_response);
                error_log('AutoWA Media Download Failed from: ' . $file_url . ' | ' . $err);
                $this->serve_media_placeholder($visualize, 'download_failed');
                return;
            }

            $response_body = wp_remote_retrieve_body($file_response);
            $content_type  = wp_remote_retrieve_header($file_response, 'content-type');
        }
        // 3.d No se encontró nada utilizable
        else {
            error_log('AutoWA Media: respuesta JSON sin URL ni buffer reconocido: ' . substr($response_body, 0, 500));
            $this->serve_media_placeholder($visualize, 'unknown_payload');
            return;
        }
    }

    // 4. Construir nombre de archivo
    $filename = 'media-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $message_id), 0, 12);
    if (strpos($content_type, 'image/jpeg') !== false || strpos($content_type, 'image/jpg') !== false) {
        $filename .= '.jpg';
    } elseif (strpos($content_type, 'image/png') !== false)  { $filename .= '.png';
    } elseif (strpos($content_type, 'image/gif') !== false)  { $filename .= '.gif';
    } elseif (strpos($content_type, 'image/webp') !== false) { $filename .= '.webp';
    } elseif (strpos($content_type, 'pdf')        !== false) { $filename .= '.pdf';
    } elseif (strpos($content_type, 'audio')      !== false) { $filename .= '.mp3';
    } elseif (strpos($content_type, 'video')      !== false) { $filename .= '.mp4';
    } else {                                                   $filename .= '.bin'; }

    // 5. Servir binario
    nocache_headers();
    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . strlen($response_body));
    header('Content-Disposition: ' . ($visualize ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    echo $response_body;
    exit;
}

/**
 * Sirve un SVG placeholder gris en lugar de devolver JSON cuando falla la descarga.
 * Así el <img> del frontend muestra un icono y NO una imagen rota.
 */
private function serve_media_placeholder($visualize, $reason = 'unknown') {
    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
         . '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="180" viewBox="0 0 240 180">'
         . '<rect width="240" height="180" fill="#ECEFF1"/>'
         . '<g fill="#90A4AE" font-family="Arial, sans-serif" text-anchor="middle">'
         . '<text x="120" y="85" font-size="14">Media no disponible</text>'
         . '<text x="120" y="105" font-size="10">' . esc_html($reason) . '</text>'
         . '</g></svg>';

    status_header(200); // 200 para que el <img> no muestre el icono roto del navegador
    header('Content-Type: image/svg+xml');
    header('Content-Disposition: ' . ($visualize ? 'inline' : 'attachment') . '; filename="placeholder.svg"');
    header('Cache-Control: no-store');
    echo $svg;
    exit;
}

/**
 * Normaliza URLs internas (host:puerto privado) hacia el host público configurado.
 * Reaprovecha la lógica de fix_media_urls() pero sobre una URL única.
 */
private function normalize_media_url($url) {
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['host'])) {
        return $url;
    }

    $api_url_setting   = get_option('autwa_api_url', 'https://autowhats.com.mx/api');
    $correct_host_parts = parse_url($api_url_setting);
    $correct_host       = $correct_host_parts['host'] ?? 'autowhats.com.mx';

    // Si la URL ya apunta al host público y no usa puerto raro, déjala
    if ($parsed['host'] === $correct_host && empty($parsed['port'])) {
        return $url;
    }

    $new = ($parsed['scheme'] ?? 'https') . '://' . $correct_host;
    if (!empty($parsed['path']))     $new .= $parsed['path'];
    if (!empty($parsed['query']))    $new .= '?' . $parsed['query'];
    if (!empty($parsed['fragment'])) $new .= '#' . $parsed['fragment'];
    return $new;
}
    
public function ajax_get_picture() {
        check_ajax_referer('autwa_nonce', 'nonce');
        
        if (!current_user_can('manage_autowhats')) {
            status_header(403);
            wp_die();
        }

        $chat_id = isset($_REQUEST['chat_id']) ? sanitize_text_field($_REQUEST['chat_id']) : '';
        if (empty($chat_id)) {
            status_header(400);
            wp_die('Chat ID required');
        }

        // Limpieza de ID (si viene serializado incorrectamente)
        if (strpos($chat_id, '_') !== false && strpos($chat_id, '@') !== false) {
            $parts = explode('_', $chat_id);
            foreach ($parts as $p) {
                if (strpos($p, '@') !== false) {
                    $chat_id = $p;
                    break;
                }
            }
        }

        $session_name = get_option('autwa_session_name', 'default');

        // Usamos el endpoint personalizado proxy
        $target_endpoint = '/proxy-media-by-chat-id';

        // Construimos el payload exactamente como se ve en tu imagen de n8n
        $payload = [
            'name'       => $session_name, // usa 'name' en lugar de 'session'
            'chatId'     => $chat_id,
            'media_type' => 'picture'      // Indicador para tu switch en n8n
        ];

        // Enviamos la petición por POST (requerido por tu nodo Webhook)
        $response = $this->call_n8n_proxy($target_endpoint, $payload, 'POST');

        if (is_wp_error($response)) {
            status_header(404);
            wp_die();
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            status_header(404);
            wp_die();
        }

        $body = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');

        // Opción A: n8n devuelve la imagen binaria directamente
        if (strpos($content_type, 'image') !== false) {
            header('Content-Type: ' . $content_type);
            header('Content-Length: ' . strlen($body));
            header('Cache-Control: public, max-age=3600'); // Cache de 1 hora
            echo $body;
        } 
        // Opción B: n8n devuelve un JSON con la URL de la imagen
        else {
            $json = json_decode($body, true);
            // Buscamos la URL en propiedades comunes
            $url = null;
            if (isset($json['url'])) $url = $json['url'];
            elseif (isset($json['profilePicUrl'])) $url = $json['profilePicUrl'];
            
            if ($url) {
                $allowed_hosts = [
                    'n8n.autowhats.com.mx',
                    'autowhats.com.mx',
                ];
                $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
                if ($home_host) {
                    $allowed_hosts[] = $home_host;
                }
                $configured_n8n_url = get_option('autwa_n8n_webhook_url');
                if ($configured_n8n_url) {
                    $configured_host = wp_parse_url($configured_n8n_url, PHP_URL_HOST);
                    if ($configured_host) {
                        $allowed_hosts[] = $configured_host;
                    }
                }
                $url_host = wp_parse_url($url, PHP_URL_HOST);
                $is_allowed = false;
                if ($url_host) {
                    if (in_array(strtolower($url_host), $allowed_hosts, true)) {
                        $is_allowed = true;
                    } elseif (preg_match('/\.whatsapp\.net$/i', $url_host)) {
                        $is_allowed = true;
                    }
                }
                if ($is_allowed) {
                    wp_redirect($url);
                } else {
                    status_header(403);
                    wp_die('Host de redirección no permitido.');
                }
            } else {
                // Si no hay imagen, devolvemos 404 para que JS ponga el placeholder gris
                status_header(404);
            }
        }
        wp_die();
    }
 
   // ... dentro de la clase AutoWAWhatsAppPlugin ...

    public function ajax_schedule_message() {
        check_ajax_referer('autwa_nonce', 'nonce');

        if (!current_user_can('manage_autowhats')) {
            wp_send_json_error(['message' => __('Permissions denied.', 'autowa-whatsapp')]);
            return;
        }

        global $wpdb;
        $table_individual = $wpdb->prefix . 'autwa_scheduled_messages';
        
        // 1. Recibir y validar JSON de destinatarios
        $recipients_raw = isset($_POST['recipients']) ? stripslashes($_POST['recipients']) : '[]';
        $decoded = json_decode($recipients_raw, true);
        
        if (empty($decoded) || !is_array($decoded)) {
            wp_send_json_error(['message' => __('No valid recipients selected.', 'autowa-whatsapp')]);
            return;
        }

        $sanitized_recipients = [];
        foreach ($decoded as $recipient) {
            if (empty($recipient['id']) || empty($recipient['type'])) {
                continue;
            }
            $sanitized_recipients[] = [
                'id'   => sanitize_text_field($recipient['id']),
                'text' => sanitize_text_field($recipient['text'] ?? ''),
                'type' => sanitize_text_field($recipient['type']),
            ];
        }

        if (empty($sanitized_recipients)) {
            wp_send_json_error(['message' => __('No valid recipients selected.', 'autowa-whatsapp')]);
            return;
        }

        $clean_recipients_json = json_encode($sanitized_recipients);

        // 2. Sanitización de campos
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        // Helper para limpiar strings 'undefined' o 'null' que a veces manda JS
        $clean_val = function($k) { 
            $v = isset($_POST[$k]) ? sanitize_text_field($_POST[$k]) : '';
            return ($v === 'undefined' || $v === 'null') ? '' : $v;
        };
        
        $media_url = isset($_POST['attachment_url']) ? esc_url_raw($_POST['attachment_url']) : '';
        if ($media_url === 'undefined' || $media_url === 'null') $media_url = '';
        
        $media_filename = $clean_val('attachment_filename');
        
        // Recibimos el tipo calculado por JS (image, video, audio, document, text)
        $msg_type = $clean_val('message_type');
        
        // Fallback de seguridad: Si hay URL pero no tipo, deducir por extensión
        if (!empty($media_url) && (empty($msg_type) || $msg_type === 'text')) {
            $ext = strtolower(pathinfo($media_url, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $msg_type = 'image';
            elseif (in_array($ext, ['mp4', 'mov', 'avi'])) $msg_type = 'video';
            elseif (in_array($ext, ['mp3', 'ogg', 'wav'])) $msg_type = 'audio';
            else $msg_type = 'document';
        }
        
        if (empty($msg_type)) $msg_type = 'text';

        // 3. Procesar Fecha
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';
        
        // Validar fecha válida
        $scheduled_ts = strtotime("$date $time");
        if (!$scheduled_ts) {
             wp_send_json_error(['message' => __('Invalid date or time format.', 'autowa-whatsapp')]);
             return;
        }
        
        $formatted_date = date('Y-m-d H:i:s', $scheduled_ts);

        // 4. Insertar en Base de Datos
        // Guardamos 'bulk_task' en el teléfono porque es una tarea que contiene múltiples destinatarios en el JSON
        $inserted = $wpdb->insert(
            $table_individual,
            [
                'recipients'     => $clean_recipients_json, 
                'phone'          => 'bulk_task',     
                'message'        => $message,
                'media_url'      => $media_url,
                'media_filename' => $media_filename,
                'message_type'   => $msg_type,
                'status'         => 'pending',
                'scheduled_at'   => $formatted_date,
                'created_at'     => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted) {
            wp_send_json_success(['message' => __('Message scheduled successfully.', 'autowa-whatsapp')]);
        } else {
            error_log("AutoWA Database Error: " . $wpdb->last_error);
            wp_send_json_error(['message' => __('A database error occurred. Please try again.', 'autowa-whatsapp')]);
        }
    }


public function send_scheduled_messages() {
    global $wpdb;
    $kapso = AutoWA_Kapso_Client::get_instance();

    $max_retries     = 3;
    $expiration_hrs  = 6;
    $expiration_cut  = gmdate('Y-m-d H:i:s', strtotime("-{$expiration_hrs} hours"));
    $processed_count = 0;

    // 0. Expirar mensajes viejos en cola
    $table_individual = $wpdb->prefix . 'autwa_scheduled_messages';
    $wpdb->query($wpdb->prepare(
        "UPDATE $table_individual 
         SET status = 'expired', api_response = 'Expired: pending more than {$expiration_hrs}h' 
         WHERE status = 'pending' AND scheduled_at < %s",
        $expiration_cut
    ));

    $table_broadcast = $wpdb->prefix . 'autwa_broadcast_schedules';
    $wpdb->query($wpdb->prepare(
        "UPDATE $table_broadcast 
         SET status = 'expired', api_response = 'Expired: pending more than {$expiration_hrs}h' 
         WHERE status = 'pending' AND scheduled_at < %s",
        $expiration_cut
    ));

    // 1. Procesar mensajes individuales
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_individual 
         WHERE status = 'pending' 
           AND scheduled_at <= %s 
           AND (retry_count IS NULL OR retry_count < %d)
         ORDER BY scheduled_at ASC
         LIMIT 50",
        current_time('mysql', 1),
        $max_retries
    ));

    if (!empty($messages)) {
        foreach ($messages as $job) {
            $locked = $wpdb->update(
                $table_individual,
                [
                    'status'      => 'processing',
                    'retry_count' => (int) $job->retry_count + 1,
                ],
                ['id' => $job->id, 'status' => 'pending']
            );
            if (!$locked) continue;

            $phone          = $job->phone;
            $msg_type       = $job->message_type ?: 'text';
            $media_url      = ($job->media_url === 'undefined' || $job->media_url === 'null') ? '' : $job->media_url;
            $media_filename = ($job->media_filename === 'undefined' || $job->media_filename === 'null') ? '' : $job->media_filename;

            if ($msg_type !== 'text' && !empty($media_url)) {
                $res = $kapso->send_media_message($phone, $media_url, $job->message, $msg_type, $media_filename);
            } else {
                $res = $kapso->send_text_message($phone, $job->message);
            }

            if (!is_wp_error($res)) {
                $wpdb->update($table_individual, [
                    'status'       => 'sent',
                    'sent_at'      => current_time('mysql', 1),
                    'api_response' => json_encode($res)
                ], ['id' => $job->id]);
                $processed_count++;
            } else {
                $new_status = ((int)$job->retry_count + 1 >= $max_retries) ? 'failed' : 'pending';
                $wpdb->update($table_individual, [
                    'status'       => $new_status,
                    'api_response' => $res->get_error_message()
                ], ['id' => $job->id]);
            }
        }
    }

    // 2. Procesar listas de difusión
    $table_recipients = $wpdb->prefix . 'autwa_broadcast_recipients';
    $broadcasts = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_broadcast 
         WHERE status = 'pending' 
           AND scheduled_at <= %s 
           AND (retry_count IS NULL OR retry_count < %d)
         ORDER BY scheduled_at ASC
         LIMIT 20",
        current_time('mysql', 1),
        $max_retries
    ));

    if (!empty($broadcasts)) {
        foreach ($broadcasts as $job) {
            $locked = $wpdb->update(
                $table_broadcast,
                ['status' => 'processing', 'retry_count' => (int) $job->retry_count + 1],
                ['id' => $job->id, 'status' => 'pending']
            );
            if (!$locked) continue;

            $raw_recipients = $wpdb->get_results($wpdb->prepare(
                "SELECT recipient_id as id, recipient_name as text 
                 FROM $table_recipients WHERE list_id = %d",
                $job->list_id
            ), ARRAY_A);

            if (empty($raw_recipients)) {
                $wpdb->update($table_broadcast, [
                    'status'       => 'failed',
                    'api_response' => 'Error: Empty Broadcast List',
                ], ['id' => $job->id]);
                continue;
            }

            $media_url      = ($job->media_url === 'undefined' || $job->media_url === 'null') ? '' : $job->media_url;
            $media_filename = ($job->media_filename === 'undefined' || $job->media_filename === 'null') ? '' : $job->media_filename;
            $msg_type       = $job->message_type ?: 'text';
            $all_ok         = true;

            foreach ($raw_recipients as $recipient) {
                $r_phone = $recipient['id'];
                if ($msg_type !== 'text' && !empty($media_url)) {
                    $res = $kapso->send_media_message($r_phone, $media_url, $job->message, $msg_type, $media_filename);
                } else {
                    $res = $kapso->send_text_message($r_phone, $job->message);
                }
                if (is_wp_error($res)) {
                    $all_ok = false;
                }
                $processed_count++;
            }

            $wpdb->update($table_broadcast, [
                'status'       => $all_ok ? 'sent' : 'partially_sent',
                'sent_at'      => current_time('mysql', 1),
                'api_response' => 'Processed ' . count($raw_recipients) . ' recipients via Kapso'
            ], ['id' => $job->id]);
        }
    }

    return $processed_count;
}

    
    public function ajax_get_scheduled_messages() {
        check_ajax_referer('autwa_nonce', 'nonce');
        // <-- KEY CHANGE: Changed from 'manage_options' to 'edit_posts'.
        if (!current_user_can('manage_autowhats')) { wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp')); }

        global $wpdb;
        $table_name = $wpdb->prefix . 'autwa_scheduled_messages';
        
        $pending = $wpdb->get_results("SELECT * FROM $table_name WHERE status = 'pending' ORDER BY scheduled_at ASC");
        
        $ten_days_ago = (new DateTime('-10 days'))->format('Y-m-d H:i:s');
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE status IN ('sent', 'failed') AND sent_at >= %s ORDER BY sent_at DESC",
            $ten_days_ago
        ));

        wp_send_json_success(['pending' => $pending, 'history' => $history]);
    }

    public function ajax_delete_scheduled_message() {
        check_ajax_referer('autwa_nonce', 'nonce');
        // <-- KEY CHANGE: Changed from 'manage_options' to 'edit_posts'.
        if (!current_user_can('manage_autowhats')) { wp_die(esc_html__('You do not have permission to do this.', 'autowa-whatsapp')); }
        
        global $wpdb;
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id > 0) {
            $table_name = $wpdb->prefix . 'autwa_scheduled_messages';
            $wpdb->delete($table_name, ['id' => $id, 'status' => 'pending']);
            wp_send_json_success(['message' => __('Dispatch canceled successfully.', 'autowa-whatsapp')]);
        } else {
            wp_send_json_error(['message' => __('Invalid dispatch ID.', 'autowa-whatsapp')]);
        }
    }
     
    

    /**
     * Helper privado para envío (Sin cambios, solo para referencia)
     */
    private function process_n8n_dispatch($table, $id, $payload, $max_retries = 3) {
    global $wpdb;

    $response = $this->call_n8n_proxy('/scheduler', $payload);

    if (is_wp_error($response)) {
        // n8n no respondió. Volver a pending para reintentar si quedan reintentos.
        $current = $wpdb->get_row($wpdb->prepare("SELECT retry_count FROM $table WHERE id = %d", $id));
        $retries = (int) ($current->retry_count ?? 1);
        $next_status = ($retries >= $max_retries) ? 'failed' : 'pending';

        $wpdb->update($table, [
            'status'       => $next_status,
            'api_response' => 'n8n unreachable: ' . $response->get_error_message(),
        ], ['id' => $id]);
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code >= 200 && $code < 300) {
        $wpdb->update($table, [
            'status'       => 'sent',
            'sent_at'      => current_time('mysql', 1),
            'api_response' => 'Sent to n8n',
        ], ['id' => $id]);
    } else {
        // HTTP error: reintentar si quedan intentos
        $current = $wpdb->get_row($wpdb->prepare("SELECT retry_count FROM $table WHERE id = %d", $id));
        $retries = (int) ($current->retry_count ?? 1);
        $next_status = ($retries >= $max_retries) ? 'failed' : 'pending';

        $err = substr($body, 0, 255);
        $wpdb->update($table, [
            'status'       => $next_status,
            'api_response' => "HTTP {$code}: {$err}",
        ], ['id' => $id]);
    }
}
    
    public function ajax_manual_cron_trigger() {
        check_ajax_referer('autwa_nonce', 'nonce');
        // <-- KEY CHANGE: Changed from 'manage_options' to 'edit_posts'.
        if (!current_user_can('manage_autowhats')) { wp_send_json_error(['message' => __('You do not have permission.', 'autowa-whatsapp')]); }

        $this->send_scheduled_messages();
        wp_send_json_success(['message' => __('Sending task executed. Check the history on the Scheduler page to see the results.', 'autowa-whatsapp')]);
    }

    public function ajax_reschedule_cron() {
        check_ajax_referer('autwa_nonce', 'nonce');
        // <-- KEY CHANGE: Changed from 'manage_options' to 'edit_posts'.
        if (!current_user_can('manage_autowhats')) { wp_send_json_error(['message' => __('You do not have permission.', 'autowa-whatsapp')]); }

        wp_clear_scheduled_hook('autwa_send_scheduled_messages_cron');
        wp_schedule_event(time(), 'every_minute', 'autwa_send_scheduled_messages_cron');

        wp_send_json_success(['message' => __('Task rescheduled successfully! Refresh the page to see the new status.', 'autowa-whatsapp')]);
    }
    
    public function add_custom_cron_interval($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => esc_html__('Every Minute', 'autowa-whatsapp'),
        );
        return $schedules;
    }

    /* --- START: FUNCTIONS FOR BROADCAST LISTS --- */
    public function ajax_get_broadcast_lists() {
        check_ajax_referer('autwa_nonce', 'nonce');
        if (!current_user_can('manage_autowhats')) { wp_die(__('Permission denied.', 'autowa-whatsapp')); }
        
        global $wpdb;
        $lists_table = $wpdb->prefix . 'autwa_broadcast_lists';
        $recipients_table = $wpdb->prefix . 'autwa_broadcast_recipients';
        
        if (current_user_can('manage_options')) {
            $lists = $wpdb->get_results("SELECT l.id, l.name, COUNT(r.id) as recipient_count FROM $lists_table l LEFT JOIN $recipients_table r ON l.id = r.list_id GROUP BY l.id ORDER BY l.name ASC");
        } else {
            $lists = $wpdb->get_results($wpdb->prepare(
                "SELECT l.id, l.name, COUNT(r.id) as recipient_count FROM $lists_table l LEFT JOIN $recipients_table r ON l.id = r.list_id WHERE l.created_by = %d GROUP BY l.id ORDER BY l.name ASC",
                get_current_user_id()
            ));
        }

        wp_send_json_success(['lists' => $lists]);
    }

    private function check_list_ownership($list_id) {
        if (current_user_can('manage_options')) {
            return true; // Admins always have access
        }
        global $wpdb;
        $table = $wpdb->prefix . 'autwa_broadcast_lists';
        $created_by = $wpdb->get_var($wpdb->prepare("SELECT created_by FROM $table WHERE id = %d", $list_id));
        return ((int)$created_by === (int)get_current_user_id());
    }

    public function ajax_create_broadcast_list() {
        check_ajax_referer('autwa_nonce', 'nonce');
        if (!current_user_can('manage_autowhats')) { wp_die(__('Permission denied.', 'autowa-whatsapp')); }
        
        $list_name = isset($_POST['list_name']) ? sanitize_text_field($_POST['list_name']) : '';
        if (empty($list_name)) { wp_send_json_error(['message' => __('List name cannot be empty.', 'autowa-whatsapp')]); }

        global $wpdb;
        $table = $wpdb->prefix . 'autwa_broadcast_lists';
        $result = $wpdb->insert($table, [
            'name' => $list_name, 
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', 1)
        ]);

        if ($result) {
            wp_send_json_success(['message' => __('List created successfully.', 'autowa-whatsapp'), 'list_id' => $wpdb->insert_id]);
        } else {
            wp_send_json_error(['message' => __('A list with this name already exists.', 'autowa-whatsapp')]);
        }
    }

    public function ajax_delete_broadcast_list() {
        check_ajax_referer('autwa_nonce', 'nonce');
        if (!current_user_can('manage_autowhats')) { wp_die(__('Permission denied.', 'autowa-whatsapp')); }
        
        $list_id = isset($_POST['list_id']) ? intval($_POST['list_id']) : 0;
        if ($list_id > 0) {
            if (!$this->check_list_ownership($list_id)) {
                wp_send_json_error(['message' => __('You do not own this list.', 'autowa-whatsapp')]);
                return;
            }
            global $wpdb;
            $table = $wpdb->prefix . 'autwa_broadcast_lists';
            $wpdb->delete($table, ['id' => $list_id]);
            wp_send_json_success(['message' => __('List deleted successfully.', 'autowa-whatsapp')]);
        } else {
            wp_send_json_error(['message' => __('Invalid list ID.', 'autowa-whatsapp')]);
        }
    }

    public function ajax_get_list_recipients() {
        check_ajax_referer('autwa_nonce', 'nonce');
        if (!current_user_can('manage_autowhats')) { wp_die(__('Permission denied.', 'autowa-whatsapp')); }

        $list_id = isset($_POST['list_id']) ? intval($_POST['list_id']) : 0;
        if ($list_id > 0) {
            if (!$this->check_list_ownership($list_id)) {
                wp_send_json_error(['message' => __('You do not own this list.', 'autowa-whatsapp')]);
                return;
            }
            global $wpdb;
            $table = $wpdb->prefix . 'autwa_broadcast_recipients';
            $recipients = $wpdb->get_results($wpdb->prepare("SELECT recipient_id, recipient_name, recipient_type FROM $table WHERE list_id = %d", $list_id));
            wp_send_json_success(['recipients' => $recipients]);
        } else {
            wp_send_json_error(['message' => __('Invalid list ID.', 'autowa-whatsapp')]);
        }
    }

    public function ajax_update_list_recipients() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_autowhats')) { 
        wp_send_json_error(['message' => __('Permission denied.', 'autowa-whatsapp')]); 
        return;
    }

    $list_id = isset($_POST['list_id']) ? intval($_POST['list_id']) : 0;
    $recipients = isset($_POST['recipients']) ? json_decode(stripslashes($_POST['recipients']), true) : [];

    if ($list_id <= 0) { 
        wp_send_json_error(['message' => __('Invalid list ID.', 'autowa-whatsapp')]); 
        return; 
    }

    if (!$this->check_list_ownership($list_id)) {
        wp_send_json_error(['message' => __('You do not own this list.', 'autowa-whatsapp')]);
        return;
    }

    if ($list_id <= 0) { 
        wp_send_json_error(['message' => __('Invalid list ID.', 'autowa-whatsapp')]); 
        return; 
    }

    global $wpdb;
    $table = $wpdb->prefix . 'autwa_broadcast_recipients';
    
    $wpdb->suppress_errors();
    $wpdb->query('START TRANSACTION');
    
    try {
        $delete_result = $wpdb->delete($table, ['list_id' => $list_id]);
        
        if ($delete_result === false) {
            throw new Exception($wpdb->last_error);
        }
        
        if (!empty($recipients)) {
            foreach ($recipients as $recipient) {
                if (empty($recipient['id']) || empty($recipient['type'])) {
                    continue;
                }
                
                $insert_result = $wpdb->insert($table, [
                    'list_id' => $list_id,
                    'recipient_id' => sanitize_text_field($recipient['id']),
                    'recipient_name' => sanitize_text_field($recipient['text']),
                    'recipient_type' => sanitize_text_field($recipient['type'])
                ]);
                
                if ($insert_result === false) {
                    if (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                        continue;
                    } else {
                        throw new Exception($wpdb->last_error);
                    }
                }
            }
        }
        
        $wpdb->query('COMMIT');
        $wpdb->show_errors();
        wp_send_json_success(['message' => __('Recipient list updated successfully.', 'autowa-whatsapp')]);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        $wpdb->show_errors();
        error_log('AutoWA Error updating recipients: ' . $e->getMessage());
        wp_send_json_error(['message' => __('Error updating recipient list. Please try again.', 'autowa-whatsapp')]);
    }
}
    
    public function ajax_get_broadcast_schedules() {
        check_ajax_referer('autwa_nonce', 'nonce');
        // <-- KEY CHANGE: Changed from 'manage_options' to 'edit_posts'.
        if (!current_user_can('manage_autowhats')) { wp_die(__('Permission denied.', 'autowa-whatsapp')); }

        global $wpdb;
        $schedules_table = $wpdb->prefix . 'autwa_broadcast_schedules';
        $lists_table = $wpdb->prefix . 'autwa_broadcast_lists';
        
        $pending = $wpdb->get_results("SELECT s.*, l.name as list_name FROM $schedules_table s LEFT JOIN $lists_table l ON s.list_id = l.id WHERE s.status = 'pending' ORDER BY s.scheduled_at ASC");
        
        $ten_days_ago = (new DateTime('-10 days'))->format('Y-m-d H:i:s');
        $history = $wpdb->get_results($wpdb->prepare("SELECT s.*, l.name as list_name FROM $schedules_table s LEFT JOIN $lists_table l ON s.list_id = l.id WHERE s.status IN ('sent', 'failed') AND s.sent_at >= %s ORDER BY s.sent_at DESC", $ten_days_ago));

        wp_send_json_success(['pending' => $pending, 'history' => $history]);
    }
    
    public function ajax_delete_broadcast_schedule() {
        check_ajax_referer('autwa_nonce', 'nonce');
        // <-- KEY CHANGE: Changed from 'manage_options' to 'edit_posts'.
        if (!current_user_can('manage_autowhats')) { wp_die(__('Permission denied.', 'autowa-whatsapp')); }
        
        global $wpdb;
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id > 0) {
            $table_name = $wpdb->prefix . 'autwa_broadcast_schedules';
            $wpdb->delete($table_name, ['id' => $id, 'status' => 'pending']);
            wp_send_json_success(['message' => __('Broadcast canceled successfully.', 'autowa-whatsapp')]);
        } else {
            wp_send_json_error(['message' => __('Invalid broadcast ID.', 'autowa-whatsapp')]);
        }
    }
    
    /* --- END: FUNCTIONS FOR BROADCAST LISTS --- */
    /* --- FUNCTIONS FOR HISTORY CLEANUP --- */
    public function ajax_clear_scheduler_history() {
        check_ajax_referer('autwa_nonce', 'nonce');
        // <-- KEY CHANGE: Changed from 'manage_options' to 'edit_posts'.
        if (!current_user_can('manage_autowhats')) {
            wp_send_json_error(['message' => __('Permission denied.', 'autowa-whatsapp')]);
        }

        global $wpdb;
        $table_individual = $wpdb->prefix . 'autwa_scheduled_messages';
        $table_broadcast = $wpdb->prefix . 'autwa_broadcast_schedules';

        $wpdb->query("DELETE FROM $table_individual WHERE status IN ('sent', 'failed')");
        $wpdb->query("DELETE FROM $table_broadcast WHERE status IN ('sent', 'failed')");

        if ($wpdb->last_error) {
            error_log("AutoWA Database Error: " . $wpdb->last_error);
            wp_send_json_error(['message' => __('A database error occurred while clearing history.', 'autowa-whatsapp')]);
        } else {
            wp_send_json_success(['message' => __('Sending history has been cleared successfully.', 'autowa-whatsapp')]);
        }
    }
    /* --- END HISTORY CLEANUP --- */
    
    /**
 * Cazador de errores fatales.
 * Se ejecuta al final de cada script para capturar errores que detienen la ejecución.
 */
public function autwa_shutdown_error_handler() {
    $error = error_get_last();
    // Verificamos si hubo un error y si fue un error fatal
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        // Limpiamos el buffer de salida para evitar respuestas corruptas
        ob_get_clean();
        // Formateamos el mensaje de error para que sea legible
        $error_message = "Tipo: " . $error['type'] . "\n";
        $error_message .= "Mensaje: " . $error['message'] . "\n";
        $error_message .= "Archivo: " . $error['file'] . "\n";
        $error_message .= "Línea: " . $error['line'];

        // Guardamos el error en un "post-it" temporal de WordPress (transient)
        // que expira en 60 segundos.
        set_transient('autwa_last_fatal_error', $error_message, 60);
    }
}
    
 
    /**
     Maneja activar, desactivar Y verificar la licencia de EDD.
    **/
public function ajax_manage_license() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have permission to do this.', 'autowa-whatsapp')]);
    }

    // Para la acción 'check', la clave viene de las opciones guardadas. Para otras, del POST.
    $action_type = isset($_POST['license_action']) ? sanitize_key($_POST['license_action']) : '';
    $license_key = ($action_type === 'check') 
                    ? get_option('autwa_edd_license_key', '')
                    : (isset($_POST['license_key']) ? sanitize_text_field(trim($_POST['license_key'])) : '');

    if (empty($license_key) || empty($action_type)) {
        wp_send_json_error(['message' => __('Missing license key or action.', 'autowa-whatsapp')]);
    }

    // Determina la acción correcta para la API de EDD
    $edd_api_action = ($action_type === 'check') ? 'check_license' : $action_type . '_license';

    $store_url = 'https://landing.autowhats.com.mx/';
    $api_params = [
        'edd_action' => $edd_api_action,
        'license'    => $license_key,
        'item_name'  => 'AutoWhats',
        'url'        => home_url()
    ];

    $response = wp_remote_post($store_url, ['body' => $api_params, 'timeout' => 15]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body) || !isset($body['success'])) {
        wp_send_json_error(['message' => __('Empty or invalid response from the license server.', 'autowa-whatsapp')]);
    }

    // Si la acción fue exitosa, devolvemos la respuesta completa de EDD
    if ($body['success'] === true) {
        wp_send_json_success($body);
    } else {
        $error_message = isset($body['error']) ? $body['error'] : 'unknown_error';
        wp_send_json_error(['message' => sprintf(__('License error: %s', 'autowa-whatsapp'), $error_message), 'license_data' => $body]);
    }
}

   /**
 * Maneja la eliminación de una sesión a través del proxy n8n.
 */
public function ajax_delete_session() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have permission to do this.', 'autowa-whatsapp')]);
    }

    $session_name = get_option('autwa_session_name', 'default');
    if (empty($session_name)) {
        wp_send_json_error(['message' => __('Session name not configured.', 'autowa-whatsapp')]);
    }

    // El endpoint es el mismo que para obtener el estado, pero usaremos el método DELETE.
    $target_endpoint = '/sessions/' . $session_name;

    $response = $this->call_n8n_proxy($target_endpoint, [], 'DELETE');

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    // WAHA a menudo devuelve 200 OK con un JSON de éxito en la eliminación.
    if ($response_code === 200 && isset($body['success']) && $body['success'] === true) {
        wp_send_json_success($body);
    } else {
        wp_send_json_error($body);
    }
}

}
new AutoWAWhatsAppPlugin();