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

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_key = get_option('autwa_kapso_api_key', '');
        $this->phone_number_id = get_option('autwa_kapso_phone_number_id', '');
        
        $custom_base = get_option('autwa_kapso_base_url', '');
        if (!empty($custom_base)) {
            $this->base_url = rtrim($custom_base, '/');
        }
    }

    /**
     * Verifica si la integración con Kapso está configurada.
     */
    public function is_configured() {
        return !empty($this->api_key) && !empty($this->phone_number_id);
    }

    /**
     * Formatea y normaliza el número de teléfono para WhatsApp.
     */
    public function clean_phone_number($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        // Quitar @c.us o @s.whatsapp.net si vienen incluidos
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
     *
     * @param string $phone      Número de destino
     * @param string $media_url  URL pública del archivo
     * @param string $caption    Texto explicativo o pie de foto (opcional)
     * @param string $media_type 'image', 'document', 'audio', 'video'
     * @param string $filename   Nombre del archivo (para documentos)
     * @return array|WP_Error
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
     * Obligatoria para mensajes iniciados por el negocio fuera de la ventana de 24 horas.
     *
     * @param string $phone         Número de destino
     * @param string $template_name Nombre de la plantilla aprobada en Meta
     * @param string $language_code Código de idioma (ej. 'es', 'es_MX', 'en_US')
     * @param array  $components    Parámetros dinámicos (variables de plantilla)
     * @return array|WP_Error
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
     * Consulta el estado del número de WhatsApp y la conexión en Kapso.
     */
    public function check_connection() {
        if (!$this->is_configured()) {
            return [
                'connected' => false,
                'message'   => __('Faltan credenciales de Kapso (API Key o Phone Number ID).', 'autowa-whatsapp')
            ];
        }

        $response = $this->request('/phone_numbers/' . $this->phone_number_id, [], 'GET');

        if (is_wp_error($response)) {
            return [
                'connected' => false,
                'message'   => $response->get_error_message()
            ];
        }

        return [
            'connected' => true,
            'data'      => $response
        ];
    }

    /**
     * Genera un enlace de Meta Embedded Signup para que el cliente vincule su propio WhatsApp (Multi-Tenant).
     *
     * @param string $customer_name Nombre del cliente o negocio
     * @param string $external_id   ID único (ej. Clave de Licencia o ID de WordPress)
     * @return string|WP_Error     URL de onboarding para el cliente
     */
    public function create_setup_link($customer_name, $external_id) {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', __('API Key de Kapso no configurada.', 'autowa-whatsapp'));
        }

        // 1. Crear o sincronizar cliente en Kapso
        $customer_payload = [
            'customer' => [
                'name'                 => sanitize_text_field($customer_name),
                'external_customer_id' => sanitize_text_field($external_id)
            ]
        ];

        $customer_resp = $this->request('/customers', $customer_payload, 'POST');
        if (is_wp_error($customer_resp)) {
            return $customer_resp;
        }

        $customer_id = $customer_resp['id'] ?? $customer_resp['customer']['id'] ?? null;
        if (!$customer_id) {
            return new WP_Error('customer_failed', __('No se pudo obtener el ID de cliente en Kapso.', 'autowa-whatsapp'));
        }

        // 2. Generar Setup Link
        $setup_payload = [
            'redirect_url' => admin_url('admin.php?page=autowa-settings&kapso_connected=1')
        ];

        $link_resp = $this->request("/customers/{$customer_id}/setup_links", $setup_payload, 'POST');
        if (is_wp_error($link_resp)) {
            return $link_resp;
        }

        return $link_resp['url'] ?? $link_resp['setup_link']['url'] ?? null;
    }

    /**
     * Ejecuta una llamada HTTP autenticada hacia la API de Kapso.ai.
     */
    private function request($endpoint, $body = [], $method = 'POST') {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', __('API Key de Kapso no configurada.', 'autowa-whatsapp'));
        }

        $url = $this->base_url . $endpoint;

        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
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
            error_log("AutoWhats Kapso API Error ($code): $raw_body");
            return new WP_Error('kapso_api_error', $err_msg, ['status_code' => $code, 'response' => $data]);
        }

        return $data;
    }
}
