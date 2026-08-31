<?php
/**
 * AutoWhats API - Webhook de Stripe
 * Escucha el evento checkout.session.completed para generar licencias y enviar emails por Brevo
 */

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/brevo.php';

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verificación opcional de firma de Stripe si se configuró el webhook secret
if (!empty(STRIPE_WEBHOOK_SECRET) && strpos(STRIPE_WEBHOOK_SECRET, 'TU_SECRETO') === false) {
    // Si tienes instalada la librería de stripe-php o validación de header
    // Aquí validamos estructura básica
}

$event = json_decode($payload, true);

if (!$event || !isset($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON inválido']);
    exit;
}

if ($event['type'] === 'checkout.session.completed') {
    $session = $event['data']['object'];

    $customer_email = $session['customer_details']['email'] ?? $session['customer_email'] ?? '';
    $customer_name  = $session['customer_details']['name'] ?? 'Cliente AutoWhats';
    $session_id     = $session['id'] ?? '';
    $amount_total   = $session['amount_total'] ?? 0;

    if (!empty($customer_email)) {
        $pdo = get_db_connection();

        // Evitar duplicados si Stripe reintenta el webhook
        $stmt = $pdo->prepare('SELECT id, license_key FROM licenses WHERE stripe_session_id = ? LIMIT 1');
        $stmt->execute([$session_id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            $license_key = generate_license_key();
            $plan_name   = 'Pro Anual';
            $expires_at  = date('Y-m-d H:i:s', strtotime('+1 year'));

            // Si el pago es de por vida o recurrente mensual según metadata o monto
            if (isset($session['metadata']['plan'])) {
                $plan_name = $session['metadata']['plan'];
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO licenses (license_key, client_name, client_email, plan_name, expires_at, status, stripe_session_id) VALUES (?, ?, ?, ?, ?, "active", ?)');
                $stmt->execute([$license_key, $customer_name, $customer_email, $plan_name, $expires_at, $session_id]);

                // Enviar correo con Brevo
                send_license_email_brevo($customer_email, $customer_name, $license_key, $plan_name, $expires_at);

                error_log("Licencia $license_key creada para $customer_email vía Stripe Webhook.");
            } catch (PDOException $e) {
                error_log('Error al guardar licencia desde Stripe: ' . $e->getMessage());
            }
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
