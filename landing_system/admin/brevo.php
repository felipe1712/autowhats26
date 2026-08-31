<?php
/**
 * Helper para envío de correos transaccionales con la API v3 de Brevo
 */

require_once __DIR__ . '/config.php';

function send_license_email_brevo($to_email, $to_name, $license_key, $plan_name, $expires_at) {
    if (empty(BREVO_API_KEY) || strpos(BREVO_API_KEY, 'TU_CLAVE') !== false) {
        error_log('Brevo API Key no configurada.');
        return false;
    }

    $expires_text = $expires_at ? date('d/m/Y', strtotime($expires_at)) : 'De por vida (Lifetime)';
    $download_url = PLUGIN_DOWNLOAD_URL;

    $subject = "¡Tu licencia de AutoWhats ($plan_name) está lista!";

    $html_content = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f6f9fc; margin: 0; padding: 20px; }
            .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
            .header { text-align: center; margin-bottom: 25px; }
            .header h1 { color: #25d366; margin: 0; font-size: 24px; }
            .license-box { background: #f0fdf4; border: 2px dashed #22c55e; border-radius: 6px; padding: 15px; text-align: center; margin: 20px 0; }
            .license-key { font-family: monospace; font-size: 20px; font-weight: bold; color: #15803d; letter-spacing: 1px; }
            .btn { display: inline-block; background-color: #25d366; color: #ffffff !important; text-decoration: none; padding: 12px 25px; border-radius: 5px; font-weight: bold; margin-top: 15px; }
            .footer { margin-top: 30px; text-align: center; color: #64748b; font-size: 13px; }
        </style>
    </head>
    <body>
        <div class='card'>
            <div class='header'>
                <h1>¡Gracias por tu compra en AutoWhats!</h1>
            </div>
            <p>Hola <strong>" . htmlspecialchars($to_name, ENT_QUOTES, 'UTF-8') . "</strong>,</p>
            <p>Tu orden ha sido procesada con éxito. A continuación encontrarás tu clave de licencia para activar el plugin en tu sitio web de WordPress:</p>
            
            <div class='license-box'>
                <div>Tu Clave de Licencia:</div>
                <div class='license-key'>" . htmlspecialchars($license_key, ENT_QUOTES, 'UTF-8') . "</div>
                <small style='color:#166534;'>Plan: <strong>" . htmlspecialchars($plan_name, ENT_QUOTES, 'UTF-8') . "</strong> | Vencimiento: <strong>$expires_text</strong></small>
            </div>

            <p><strong>Pasos para activar:</strong></p>
            <ol>
                <li>Descarga el plugin desde el siguiente enlace.</li>
                <li>Súbelo a tu WordPress en <em>Plugins > Añadir nuevo > Subir plugin</em> y actívalo.</li>
                <li>Ve al menú <strong>AutoWhats > Licencia</strong>, ingresa tu clave y haz clic en Guardar.</li>
            </ol>

            <div style='text-align: center;'>
                <a href='" . htmlspecialchars($download_url, ENT_QUOTES, 'UTF-8') . "' class='btn'>Descargar Plugin AutoWhats</a>
            </div>

            <div class='footer'>
                <p>¿Tienes dudas o necesitas ayuda? Responde directamente a este correo.</p>
                <p>&copy; " . date('Y') . " AutoWhats. Todos los derechos reservados.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    $payload = [
        'sender' => [
            'name'  => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL
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
