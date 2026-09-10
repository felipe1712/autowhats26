<?php
/**
 * AutoWhats - REST API Endpoints & Webhook Handlers
 * Maneja:
 * 1. Webhooks entrantes de Kapso.ai (Mensajes recibidos, estados de entrega, conexiones).
 * 2. Endpoint Cron-Trigger para n8n (Disparo programado seguro con HMAC).
 * 3. Buffer de mensajes para el Live Chat de WordPress.
 *
 * @package AutoWA
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    // 1. Webhook oficial de Kapso.ai (WhatsApp Cloud API)
    register_rest_route('autowa/v1', '/kapso-webhook', array(
        'methods'             => ['POST', 'GET'],
        'callback'            => 'autowa_handle_kapso_webhook',
        'permission_callback' => '__return_true' // Kapso / Meta validation
    ));

    // 2. Disparador de Cron desde n8n (Verificado con HMAC)
    register_rest_route('autowa/v1', '/cron-trigger', array(
        'methods'             => 'POST',
        'callback'            => 'autowa_handle_cron_trigger',
        'permission_callback' => 'autowa_verify_webhook'
    ));

    // 3. Endpoint retrocompatible para mensajes entrantes de n8n / middleware
    register_rest_route('autowa/v1', '/incoming-message', array(
        'methods'             => 'POST',
        'callback'            => 'autowa_handle_incoming_message_webhook',
        'permission_callback' => 'autowa_verify_webhook'
    ));

    // 4. Endpoint retrocompatible para estados
    register_rest_route('autowa/v1', '/status-update', array(
        'methods'             => 'POST',
        'callback'            => 'autowa_handle_status_webhook',
        'permission_callback' => 'autowa_verify_webhook'
    ));
});

// Registrar acción AJAX para el polling rápido del Live Chat desde JS
add_action('wp_ajax_autwa_get_recent_buffer', 'autwa_ajax_get_recent_buffer');

/**
 * Handler principal para los Webhooks de Kapso.ai
 */
function autowa_handle_kapso_webhook(WP_REST_Request $request) {
    // Verificación de Webhook inicial de Meta / Kapso (GET)
    if ($request->get_method() === 'GET') {
        $mode      = $request->get_param('hub_mode') ?? $request->get_param('hub.mode');
        $token     = $request->get_param('hub_verify_token') ?? $request->get_param('hub.verify_token');
        $challenge = $request->get_param('hub_challenge') ?? $request->get_param('hub.challenge');

        $saved_secret = get_option('autwa_webhook_secret', 'lhnkdkpwq9bpda8441zbx094bwlkb284379a');

        if ($mode === 'subscribe' && $token === $saved_secret) {
            return new WP_REST_Response((int)$challenge, 200);
        }
        return new WP_REST_Response('Verification failed', 403);
    }

    $data = $request->get_json_params();
    if (empty($data)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Empty webhook body'], 400);
    }

    $event_type = $data['type'] ?? $data['event'] ?? '';

    // A) Mensaje Entrante de WhatsApp
    if ($event_type === 'whatsapp.message.received' || isset($data['entry']) || isset($data['messages'])) {
        return autowa_process_kapso_incoming_message($data);
    }

    // B) Actualización de Estado de Entrega (sent, delivered, read, failed)
    elseif (strpos($event_type, 'whatsapp.message.') === 0) {
        return autowa_process_kapso_message_status($data);
    }

    // C) Eventos de conexión del número
    elseif ($event_type === 'whatsapp.phone_number.created' || $event_type === 'whatsapp.phone_number.connected') {
        $phone_id = $data['data']['phone_number']['id'] ?? $data['phone_number_id'] ?? null;
        if ($phone_id) {
            update_option('autwa_kapso_phone_number_id', sanitize_text_field($phone_id));
        }
        return new WP_REST_Response(['success' => true, 'message' => 'Phone number updated'], 200);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Event acknowledged'], 200);
}

/**
 * Procesa un mensaje entrante recibido desde Kapso.ai
 */
function autowa_process_kapso_incoming_message($data) {
    global $wpdb;

    // Normalizar formato de Kapso v2 o Meta Cloud API
    $from = '';
    $body = '';
    $msg_id = '';
    $has_media = false;
    $media_url = '';
    $timestamp = time();

    if (isset($data['data']['message'])) {
        $m = $data['data']['message'];
        $from      = $m['from'] ?? '';
        $msg_id    = $m['id'] ?? ('kapso_' . uniqid());
        $timestamp = isset($m['timestamp']) ? (int)$m['timestamp'] : time();
        $type      = $m['type'] ?? 'text';

        if ($type === 'text') {
            $body = $m['text']['body'] ?? '';
        } elseif (isset($m[$type])) {
            $has_media = true;
            $media_url = $m[$type]['link'] ?? $m[$type]['url'] ?? '';
            $body      = $m[$type]['caption'] ?? "[$type]";
        }
    } elseif (isset($data['payload'])) {
        $m = $data['payload'];
        $from      = $m['from'] ?? '';
        $body      = $m['body'] ?? $m['text'] ?? '';
        $msg_id    = $m['id'] ?? ('kapso_' . uniqid());
        $timestamp = isset($m['timestamp']) ? (int)$m['timestamp'] : time();
        $has_media = !empty($m['hasMedia']) || !empty($m['mediaUrl']);
        $media_url = $m['mediaUrl'] ?? '';
    }

    if (empty($from)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Missing sender phone'], 400);
    }

    $clean_phone = preg_replace('/[^0-9]/', '', $from);

    // 1. Guardar en el buffer para Live Chat
    $msg_obj = [
        'id'           => sanitize_text_field($msg_id),
        'from'         => $clean_phone . '@c.us',
        'body'         => sanitize_textarea_field($body),
        'timestamp'    => $timestamp,
        'hasMedia'     => $has_media,
        'mediaUrl'     => esc_url_raw($media_url),
        'isOutbound'   => false,
        '_received_at' => microtime(true)
    ];

    $buffer_key = 'autwa_msg_buffer_default';
    $buffer = get_transient($buffer_key);
    if (!is_array($buffer)) {
        $buffer = [];
    }
    array_unshift($buffer, $msg_obj);
    if (count($buffer) > 60) {
        $buffer = array_slice($buffer, 0, 60);
    }
    set_transient($buffer_key, $buffer, 2 * HOUR_IN_SECONDS);

    // 2. Actualizar o insertar en tabla local de chats
    $table_chats = $wpdb->prefix . 'autwa_chats';
    $chat_id = $clean_phone . '@c.us';

    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_chats WHERE chat_id = %s", $chat_id));
    if ($exists) {
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_chats SET last_message = %s, timestamp = %d, unread_count = unread_count + 1 WHERE chat_id = %s",
            $body,
            $timestamp,
            $chat_id
        ));
    } else {
        $wpdb->insert($table_chats, [
            'chat_id'      => $chat_id,
            'name'         => $clean_phone,
            'last_message' => $body,
            'timestamp'    => $timestamp,
            'unread_count' => 1
        ]);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Incoming message saved', 'id' => $msg_id], 200);
}

/**
 * Procesa actualizaciones de entrega de mensajes de Kapso
 */
function autowa_process_kapso_message_status($data) {
    global $wpdb;
    $status = $data['type'] ?? $data['event'] ?? '';
    $msg_id = $data['data']['message_id'] ?? $data['message_id'] ?? '';

    // Si es un mensaje programado en BD, actualizar su estado
    if (!empty($msg_id)) {
        $status_clean = str_replace('whatsapp.message.', '', $status); // sent, delivered, read, failed
        $table_sched = $wpdb->prefix . 'autwa_scheduled_messages';
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_sched SET status = %s WHERE api_response LIKE %s",
            $status_clean,
            '%' . $wpdb->esc_like($msg_id) . '%'
        ));
    }

    return new WP_REST_Response(['success' => true], 200);
}

/**
 * Handler para el disparador de Cron desde n8n
 */
function autowa_handle_cron_trigger(WP_REST_Request $request) {
    if (class_exists('AutoWA_WhatsApp_Plugin')) {
        $plugin = AutoWA_WhatsApp_Plugin::get_instance();
        if (method_exists($plugin, 'send_scheduled_messages')) {
            $processed = $plugin->send_scheduled_messages();
            return new WP_REST_Response([
                'success'   => true,
                'message'   => 'Cron execution completed',
                'processed' => $processed,
                'timestamp' => current_time('mysql', 1)
            ], 200);
        }
    }

    return new WP_REST_Response(['success' => false, 'message' => 'Plugin instance not ready'], 500);
}

/**
 * Handlers retrocompatibles
 */
function autowa_handle_incoming_message_webhook(WP_REST_Request $request) {
    $params = $request->get_json_params();
    if (empty($params)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Empty body'], 400);
    }
    return autowa_process_kapso_incoming_message($params);
}

function autowa_handle_status_webhook(WP_REST_Request $request) {
    return new WP_REST_Response(['success' => true], 200);
}

/**
 * AJAX: Polling ligero para el Live Chat de WordPress
 */
function autwa_ajax_get_recent_buffer() {
    $buffer_key = 'autwa_msg_buffer_default';
    $messages = get_transient($buffer_key);

    if (!$messages || !is_array($messages)) {
        wp_send_json_success(['messages' => []]);
        return;
    }

    $chat_id_raw = isset($_POST['chat_id']) ? sanitize_text_field($_POST['chat_id']) : null;
    $chat_id_clean = $chat_id_raw ? str_replace('@c.us', '', $chat_id_raw) : null;

    $filtered = [];
    if ($chat_id_clean) {
        foreach ($messages as $msg) {
            $msg_from = isset($msg['from']) ? str_replace('@c.us', '', $msg['from']) : '';
            $msg_to   = isset($msg['to'])   ? str_replace('@c.us', '', $msg['to'])   : '';

            if (strpos($msg_from, $chat_id_clean) !== false || strpos($msg_to, $chat_id_clean) !== false) {
                if (isset($msg['timestamp'])) {
                    $msg['timestamp'] = (int)$msg['timestamp'];
                }
                $filtered[] = $msg;
            }
        }
    }

    wp_send_json_success(['messages' => array_values($filtered)]);
}
