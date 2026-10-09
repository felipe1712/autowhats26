<?php
/**
 * AutoWhats - Kapso.ai API Client & Multi-Tenant Connector
 * Maneja la comunicación directa con la API oficial de WhatsApp Cloud a través de Kapso.ai.
 *
 * @package AutoWA
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoWA_Kapso_Client {

    private static $instance = null;
    private $api_key;
    private $phone_number_id;
    private $base_url = 'https://api.kapso.ai/v1';
    private $central_api_url = 'https://landing.autowhats.com.mx/api/provision-kapso.php';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_key         = get_option('autwa_kapso_api_key', '');
        $this->phone_number_id = get_option('autwa_kapso_phone_number_id', '');
        
        $custom_base = get_option('autwa_kapso_base_url', '');
        if (!empty($custom_base)) {
            $this->base_url = rtrim($custom_base, '/');
        }
    }

    /**
     * Verifica si la integración con Kapso está configurada y lista para enviar.
     */
    public function is_configured() {
        return !empty($this->phone_number_id);
    }

    /**
     * Formatea y normaliza el número de teléfono para WhatsApp.
     */
    public function clean_phone_number($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $phone = str_replace(['@c.us', '@s.whatsapp.net'], '', $phone);
        return $phone;
    }

    /**
     * Envía un mensaje de texto simple a través de Kapso.ai.
     *
     * @param string $phone Número de teléfono con código de país (ej. 5215512345678)
     * @param string $text  Contenido del mensaje
     * @return array|WP_Error
     */
    public function send_text_message($phone, $text) {
        $phone = $this->clean_phone_number($phone);
        if (empty($phone) || empty($text)) {
            return new WP_Error('invalid_params', __('Número de teléfono o texto vacío.', 'autowa-whatsapp'));
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => [
                'preview_url' => true,
                'body'        => $text
            ]
        ];

        return $this->request('/messages', $payload, 'POST');
    }

    /**
     * Envía un archivo multimedia (imagen, documento, audio, video).
     */
    public function send_media_message($phone, $media_url, $caption = '', $media_type = 'image', $filename = '') {
        $phone = $this->clean_phone_number($phone);
        if (empty($phone) || empty($media_url)) {
            return new WP_Error('invalid_params', __('Número de teléfono o URL del archivo vacío.', 'autowa-whatsapp'));
        }

        $media_object = [
            'link' => esc_url_raw($media_url)
        ];

        if (!empty($caption) && in_array($media_type, ['image', 'document', 'video'])) {
            $media_object['caption'] = $caption;
        }

        if (!empty($filename) && $media_type === 'document') {
            $media_object['filename'] = sanitize_file_name($filename);
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => $media_type,
            $media_type         => $media_object
        ];

        return $this->request('/messages', $payload, 'POST');
    }

    /**
     * Envía una plantilla oficial de WhatsApp (Template Message).
     */
    public function send_template_message($phone, $template_name, $language_code = 'es', $components = []) {
        $phone = $this->clean_phone_number($phone);
        if (empty($phone) || empty($template_name)) {
            return new WP_Error('invalid_params', __('Número o nombre de plantilla vacío.', 'autowa-whatsapp'));
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'     => sanitize_text_field($template_name),
                'language' => [
                    'code' => $language_code
                ],
                'components' => $components
            ]
        ];

        return $this->request('/messages', $payload, 'POST');
    }

    /**
     * Consulta el estado de la conexión en Kapso.
     */
    public function check_connection() {
        if (!$this->is_configured()) {
            return [
                'connected' => false,
                'message'   => __('WhatsApp aún no está vinculado. Haz clic en "Conectar mi WhatsApp".', 'autowa-whatsapp')
            ];
        }

        return [
            'connected'       => true,
            'phone_number_id' => $this->phone_number_id,
            'message'         => __('WhatsApp Cloud API Conectado y Activo 24/7.', 'autowa-whatsapp')
        ];
    }

    /**
     * Solicita al servidor central de AutoWhats el enlace único de Meta Embedded Signup (Multi-Tenant).
     *
     * @param string $license_key Clave de licencia del cliente
     * @return array|WP_Error
     */
    public function fetch_meta_setup_link($license_key) {
        if (empty($license_key)) {
            return new WP_Error('missing_license', __('Ingresa una clave de licencia válida primero.', 'autowa-whatsapp'));
        }

        $response = wp_remote_post($this->central_api_url, [
            'body'    => [
                'license_key' => $license_key,
                'site_url'    => get_site_url(),
                'action'      => 'get_setup_link'
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body) || empty($body['success'])) {
            $msg = $body['message'] ?? __('No se pudo generar el enlace de conexión.', 'autowa-whatsapp');
            return new WP_Error('provision_error', $msg);
        }

        return $body;
    }

    /**
     * Ejecuta una llamada HTTP autenticada hacia la API de Kapso.ai.
     */
    private function request($endpoint, $body = [], $method = 'POST') {
        $api_key = !empty($this->api_key) ? $this->api_key : get_option('autwa_kapso_api_key', '');
        
        $url = $this->base_url . $endpoint;

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'User-Agent'    => 'AutoWhats-WordPress-Plugin/2.0'
        ];

        $args = [
            'method'    => $method,
            'headers'   => $headers,
            'timeout'   => 20,
            'sslverify' => true,
        ];

        if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            error_log('AutoWhats Kapso Request Error: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code < 200 || $code >= 300) {
            $err_msg = $data['error']['message'] ?? $data['message'] ?? "Error HTTP $code de Kapso API";
            return new WP_Error('kapso_api_error', $err_msg, ['status_code' => $code, 'response' => $data]);
        }

        return $data;
    }
}

// Registrar Hooks AJAX para la vinculación en 1 clic
add_action('wp_ajax_autwa_get_meta_setup_link', function() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sin permisos']);
    }

    $license_key = sanitize_text_field($_POST['license_key'] ?? get_option('autwa_edd_license_key', ''));
    $client = AutoWA_Kapso_Client::get_instance();
    $result = $client->fetch_meta_setup_link($license_key);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
});

add_action('wp_ajax_autwa_disconnect_whatsapp', function() {
    check_ajax_referer('autwa_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sin permisos']);
    }

    delete_option('autwa_kapso_phone_number_id');
    wp_send_json_success(['message' => 'WhatsApp desconectado correctamente.']);
});
