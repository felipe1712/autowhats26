<?php
/**
 * Notificaciones que n8n enva a WordPress (Webhooks).
 * Maneja tanto estados de sesin como mensajes entrantes.
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

add_action('rest_api_init', function () {
    // 1. Endpoint para mensajes entrantes (El que usa n8n)
    register_rest_route('autowa/v1', '/incoming-message', array(
        'methods' => 'POST',
        'callback' => 'autowa_handle_incoming_message_webhook',
        'permission_callback' => 'autowa_verify_webhook'
    ));

    // 2. Endpoint para actualizaciones de estado (Legacy/QR)
    register_rest_route('autowa/v1', '/status-update', array(
        'methods' => 'POST',
        'callback' => 'autowa_handle_status_webhook',
        'permission_callback' => 'autowa_verify_webhook'
    ));
    function autowa_handle_task_status_update(WP_REST_Request $request) {
    $params = $request->get_json_params();
    global $wpdb;
    
    $status = sanitize_text_field($params['status']); // 'sent' o 'failed'
    $task_id = intval($params['wp_task_id']);
    
    $wpdb->update(
        $wpdb->prefix . 'autwa_scheduled_messages',
        ['status' => $status, 'sent_at' => current_time('mysql', 1)],
        ['id' => $task_id]
    );

    return new WP_REST_Response(['success' => true], 200);
}
    
});

// Registrar la accin AJAX para el polling rpido desde JS
add_action('wp_ajax_autwa_get_recent_buffer', 'autwa_ajax_get_recent_buffer');

/**
 * Handler especfico para el nodo WP -> Message de n8n

 */
function autowa_handle_incoming_message_webhook(WP_REST_Request $request) {
    $params = $request->get_json_params();

    // Guardamos lo que llega de n8n en el archivo de texto para verlo en Admin > RAW Debug
    // $log_file = plugin_dir_path(__FILE__) . 'autowa-raw-push-log.txt';
    //  $timestamp = current_time('mysql');
    // $log_content = "[$timestamp] [INCOMING WEBHOOK]\n" . 
    //              json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . 
    //               "\n--------------------------------------------------\n";
                   
    // Escribimos (FILE_APPEND para no borrar lo anterior)
    //   @file_put_contents($log_file, $log_content, FILE_APPEND | LOCK_EX);


    if (empty($params)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Empty body'], 400);
    }

    return autowa_process_incoming_message($params);
}

/**
 * Handler para estados (QR, Session status)
 */
function autowa_handle_status_webhook(WP_REST_Request $request) {
    $params = $request->get_json_params();
    return autowa_process_session_status($params);
}function autowa_process_incoming_message($data) {
    // Normalizacion bsica
    $msg = isset($data['payload']) ? $data['payload'] : $data;
    if (isset($msg['payload'])) { $msg = $msg['payload']; }

    if (!isset($msg['from']) && !isset($msg['body'])) {
        return new WP_REST_Response(['success' => false, 'message' => 'Invalid message format'], 400);
    }

    if (!isset($msg['id']) || empty($msg['id'])) {
        $msg['id'] = 'n8n_' . md5(($msg['from'] ?? '') . ($msg['timestamp'] ?? time()) . ($msg['body'] ?? ''));
    }
    if (!isset($msg['timestamp'])) { $msg['timestamp'] = time(); }
    
    // Normalizar hasMedia
    if (isset($msg['hasMedia'])) {
         if ($msg['hasMedia'] === 'false') $msg['hasMedia'] = false;
         if ($msg['hasMedia'] === 'true') $msg['hasMedia'] = true;
    }

    // Sanitizar variables del mensaje entrantes (C-2)
    $msg['id'] = sanitize_text_field($msg['id']);
    $msg['from'] = sanitize_text_field($msg['from']);
    $msg['body'] = sanitize_textarea_field($msg['body']);
    $msg['timestamp'] = (int)$msg['timestamp'];
    if (isset($msg['hasMedia'])) {
        $msg['hasMedia'] = filter_var($msg['hasMedia'], FILTER_VALIDATE_BOOLEAN);
    }
    if (isset($msg['mimetype'])) {
        $msg['mimetype'] = sanitize_text_field($msg['mimetype']);
    }
    if (isset($msg['filename'])) {
        $msg['filename'] = sanitize_file_name($msg['filename']);
    }

    // Identificar sesin
    $session_name = isset($data['session']) ? sanitize_text_field($data['session']) : 'default';

    // Buffer Key
    $buffer_key = 'autwa_msg_buffer_' . $session_name;
    $buffer = get_transient($buffer_key);
    
    if (!is_array($buffer)) { $buffer = []; }

    $msg['_received_at'] = microtime(true);
    array_unshift($buffer, $msg);

    if (count($buffer) > 50) { $buffer = array_slice($buffer, 0, 50); }

    $saved = set_transient($buffer_key, $buffer, 1 * HOUR_IN_SECONDS);

    return new WP_REST_Response([
        'success' => true, 
        'message' => 'Message buffered successfully', 
        'generated_id' => $msg['id']
    ], 200);
}

 /**
 * AJAX: Polling ligero para el Frontend
 */
function autwa_ajax_get_recent_buffer() {
    // 1. Obtener nombre de sesin (prioridad al POST, luego opcin)
    $session_name = get_option('autwa_session_name', 'default');
    if(isset($_POST['session']) && !empty($_POST['session']) && $_POST['session'] !== 'undefined') {
        $session_name = sanitize_text_field($_POST['session']);
    }

    $buffer_key = 'autwa_msg_buffer_' . $session_name;
    $messages = get_transient($buffer_key);

    if (!$messages || !is_array($messages)) {
        wp_send_json_success(['messages' => []]);
        return;
    }

    // 2. Obtener Chat ID limpio
    $chat_id_raw = isset($_POST['chat_id']) ? sanitize_text_field($_POST['chat_id']) : null;
    $chat_id_clean = $chat_id_raw ? str_replace('@c.us', '', $chat_id_raw) : null;
    
    $filtered = [];
    if ($chat_id_clean) {
        foreach ($messages as $msg) {
            // Normalizar remitente y destinatario del mensaje en el buffer
            $msg_from = isset($msg['from']) ? str_replace('@c.us', '', $msg['from']) : '';
            $msg_to   = isset($msg['to'])   ? str_replace('@c.us', '', $msg['to'])   : '';
            
            // Comprobacin robusta: 07El mensaje pertenece a este chat?
            // A) Es un mensaje entrante DE este chat
            // B) Es un mensaje saliente HACIA este chat
            if (strpos($msg_from, $chat_id_clean) !== false || strpos($msg_to, $chat_id_clean) !== false) {
                // IMPORTANTE: Asegurar que el timestamp sea numrico para que el JS lo ordene bien
                if (isset($msg['timestamp'])) {
                    $msg['timestamp'] = (int)$msg['timestamp'];
                }
                $filtered[] = $msg;
            }
        }
    } else {
        // Si no hay chat_id, no devolvemos nada para no saturar, o devolvemos todo si es debug
        $filtered = []; 
    }

    // Re-indexar array para JSON
    wp_send_json_success(['messages' => array_values($filtered)]);
}function autowa_process_session_status($params) {
    $session_name_param = isset($params['session']) ? $params['session'] : 'default';
    $configured_session = get_option('autwa_session_name', 'default');
    $session_name = ($session_name_param && $session_name_param !== 'default') ? $session_name_param : $configured_session;

    $transient_key = 'autwa_status_' . $session_name;

    // Validar y sanitizar qr_image
    $qr_image = isset($params['qr_image']) ? $params['qr_image'] : null;
    if ($qr_image) {
        $is_valid_qr = false;
        // Check if it's a valid data URI base64 image
        if (preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+\/=\s]+$/', $qr_image)) {
            $is_valid_qr = true;
        } else {
            // Check if it's a URL of a whitelisted host
            $host = wp_parse_url($qr_image, PHP_URL_HOST);
            if ($host) {
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
                if (in_array(strtolower($host), $allowed_hosts, true)) {
                    $is_valid_qr = true;
                }
            }
        }
        if (!$is_valid_qr) {
            $qr_image = null; // Discard invalid QR image
        }
    }

    $data_to_store = [
        'status' => sanitize_text_field($params['status'] ?? 'UNKNOWN'),
        'message' => sanitize_text_field($params['message'] ?? 'Update received'),
        'qr_image' => $qr_image,
        'timestamp' => time()
    ];
    
    set_transient($transient_key, $data_to_store, 1 * HOUR_IN_SECONDS); 

    return new WP_REST_Response(['success' => true, 'message' => 'Status updated successfully.'], 200);
}

/*
 * Funcin Legacy para estados
 */
function ajax_get_session_update() {
    check_ajax_referer('autwa_nonce', 'nonce');
    $session_name = get_option('autwa_session_name', 'default');
    $transient_key = 'autwa_status_' . $session_name;
    $status_data = get_transient($transient_key);

    if ($status_data) {
        wp_send_json_success($status_data);
    } else {
        wp_send_json_error(['message' => 'No update found']);
    }
}
add_action('wp_ajax_autwa_get_session_update', 'ajax_get_session_update');
function autowa_verify_webhook(WP_REST_Request $request) {
    $signature = $request->get_header('x-autowa-signature');
    $timestamp = $request->get_header('x-autowa-timestamp');
    if (empty($signature) || empty($timestamp)) {
        return new WP_Error('rest_forbidden', 'Missing signature or timestamp header.', array('status' => 403));
    }
    
    // Mitigate replay attacks: check if timestamp is within 5 minutes (300 seconds)
    if (abs(time() - intval($timestamp)) > 300) {
        return new WP_Error('rest_forbidden', 'Request timestamp is too old or in the future.', array('status' => 403));
    }

    $secret = get_option('autwa_webhook_secret');
    if (empty($secret)) {
        return new WP_Error('rest_forbidden', 'Webhook secret not configured on server.', array('status' => 403));
    }

    $body = $request->get_body();
    $expected_signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    if (!hash_equals($expected_signature, $signature)) {
        return new WP_Error('rest_forbidden', 'Invalid HMAC signature.', array('status' => 403));
    }

    return true;
}
