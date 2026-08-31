<?php
/**
 * Helper para envío de correos transaccionales con la API v3 de Brevo
 * Utiliza las plantillas HTML ubicadas en admin/emails/
 */

require_once __DIR__ . '/config.php';

/**
 * Función principal para enviar un correo vía Brevo utilizando una plantilla HTML
 *
 * @param string $to_email      Email del destinatario
 * @param string $to_name       Nombre del destinatario
 * @param string $template_type 'recibo', 'falla_cobro', 'cancelacion', 'promocional'
 * @param string $lang          'es' o 'en'
 * @param array  $custom_vars   Variables dinámicas para reemplazar en la plantilla
 * @return bool
 */
function send_template_email_brevo($to_email, $to_name, $template_type = 'recibo', $lang = 'es', $custom_vars = []) {
    if (empty(BREVO_API_KEY) || strpos(BREVO_API_KEY, 'TU_CLAVE') !== false) {
        error_log('Brevo API Key no configurada en config.php.');
        return false;
    }

    $lang_suffix = ($lang === 'en') ? '-en.html' : '.html';
    
    $template_files = [
        'recibo'       => 'email-recibo-edd' . $lang_suffix,
        'falla_cobro'  => 'email-falla-cobro' . $lang_suffix,
        'cancelacion'  => 'email-cancelacion' . $lang_suffix,
        'promocional'  => 'email-promocional' . $lang_suffix,
    ];

    $subjects = [
        'recibo' => [
            'es' => '¡Tu compra de AutoWhats está lista! 🚀 (Licencia y Descarga)',
            'en' => 'Your AutoWhats purchase is ready! 🚀 (License & Download)'
        ],
        'falla_cobro' => [
            'es' => 'Acción Requerida: Problema procesando el pago de tu suscripción ⚠️',
            'en' => 'Action Required: Problem processing your subscription payment ⚠️'
        ],
        'cancelacion' => [
            'es' => 'Confirmación de Cancelación de Suscripción - AutoWhats',
            'en' => 'Subscription Cancellation Confirmation - AutoWhats'
        ],
        'promocional' => [
            'es' => '¡Nuevas funciones y eventos disponibles en AutoWhats! 🎉',
            'en' => 'New features and events available in AutoWhats! 🎉'
        ]
    ];

    $filename = $template_files[$template_type] ?? 'email-recibo-edd.html';
    $filepath = __DIR__ . '/emails/' . $filename;

    if (!file_exists($filepath)) {
        error_log("Plantilla de email no encontrada: $filepath");
        return false;
    }

    $html_content = file_get_contents($filepath);

    // Variables por defecto
    $defaults = [
        '{name}'                       => !empty($to_name) ? htmlspecialchars($to_name, ENT_QUOTES, 'UTF-8') : 'Cliente',
        '{{ contact.FIRSTNAME }}'      => !empty($to_name) ? htmlspecialchars($to_name, ENT_QUOTES, 'UTF-8') : 'Cliente',
        '{license_key}'                => $custom_vars['license_key'] ?? 'AW-DEMO-0000-0000',
        '{download_link}'              => defined('PLUGIN_DOWNLOAD_URL') ? PLUGIN_DOWNLOAD_URL : 'https://landing.autowhats.com.mx/downloads/autowhats.zip',
        '{subscription_name}'          => $custom_vars['plan_name'] ?? 'AutoWhats Pro Anual',
        '{plan_name}'                  => $custom_vars['plan_name'] ?? 'AutoWhats Pro Anual',
        '{update_payment_method_url}'  => $custom_vars['portal_url'] ?? 'https://billing.stripe.com/p/login/test',
        '{{ unsubscribe }}'            => 'https://landing.autowhats.com.mx/unsubscribe',
        '{site_url}'                   => 'https://landing.autowhats.com.mx'
    ];

    $vars = array_merge($defaults, $custom_vars);
    $html_content = str_replace(array_keys($vars), array_values($vars), $html_content);

    $subject = $subjects[$template_type][$lang] ?? 'Notificación de AutoWhats';

    $payload = [
        'sender' => [
            'name'  => defined('BREVO_SENDER_NAME') ? BREVO_SENDER_NAME : 'AutoWhats Soporte',
            'email' => defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : 'soporte@autowhats.com.mx'
        ],
        'to' => [
            [
                'email' => $to_email,
                'name'  => $to_name
            ]
        ],
        'subject'     => $subject,
        'htmlContent' => $html_content
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . BREVO_API_KEY,
        'content-type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log('Brevo cURL Error: ' . $error);
        return false;
    }

    if ($http_code >= 200 && $http_code < 300) {
        return true;
    } else {
        error_log("Brevo API error ($http_code): " . $response);
        return false;
    }
}

// Wrapper retrocompatible para la creación de licencias
function send_license_email_brevo($to_email, $to_name, $license_key, $plan_name, $expires_at) {
    return send_template_email_brevo($to_email, $to_name, 'recibo', 'es', [
        '{license_key}' => $license_key,
        '{plan_name}'   => $plan_name,
        '{expires_at}'  => $expires_at
    ]);
}
