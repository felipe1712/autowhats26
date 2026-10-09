<?php
/**
 * AutoWhats - Endpoint Central de Aprovisionamiento Kapso.ai (Multi-Tenant)
 * Genera el enlace de Meta Embedded Signup / QR para los clientes de AutoWhats
 *
 * Ruta: /api/provision-kapso.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-AutoWA-Signature, X-AutoWA-Timestamp');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/config.php';

$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?: [];

$license_key = trim($_POST['license_key'] ?? $_POST['license'] ?? $json_data['license_key'] ?? $json_data['license'] ?? $_GET['license_key'] ?? '');
$site_url    = trim($_POST['site_url'] ?? $_POST['url'] ?? $json_data['site_url'] ?? $json_data['url'] ?? $_GET['site_url'] ?? '');
$action      = trim($_POST['action'] ?? $json_data['action'] ?? $_GET['action'] ?? 'get_setup_link');

if (empty($license_key)) {
    echo json_encode([
        'success' => false,
        'message' => 'Clave de licencia requerida.'
    ]);
    exit;
}

try {
    $pdo = get_db_connection();

    // 1. Validar que la licencia exista y esté activa
    $stmt = $pdo->prepare('SELECT id, license_key, client_name, client_email, site_url, plan_name, status, expires_at FROM licenses WHERE license_key = ? LIMIT 1');
    $stmt->execute([$license_key]);
    $lic = $stmt->fetch();

    if (!$lic) {
        echo json_encode([
            'success' => false,
            'message' => 'Licencia no válida o no encontrada.'
        ]);
        exit;
    }

    if ($lic['status'] !== 'active') {
        echo json_encode([
            'success' => false,
            'message' => 'La licencia se encuentra suspendida o expirada.'
        ]);
        exit;
    }

    if (!empty($lic['expires_at']) && strtotime($lic['expires_at']) < time()) {
        echo json_encode([
            'success' => false,
            'message' => 'La licencia ha expirado.'
        ]);
        exit;
    }

    // 2. Comunicarse con la API de Plataforma de Kapso.ai
    $kapso_api_key = defined('KAPSO_PLATFORM_API_KEY') ? KAPSO_PLATFORM_API_KEY : '';
    $kapso_base    = defined('KAPSO_API_BASE_URL') ? rtrim(KAPSO_API_BASE_URL, '/') : 'https://api.kapso.ai';

    if (empty($kapso_api_key) || strpos($kapso_api_key, 'TU_CLAVE') !== false) {
        // Modo demo / simulación si no se ha configurado la API Key de Kapso
        $mock_setup_link = 'https://app.kapso.ai/setup/demo?customer=' . urlencode($license_key);
        echo json_encode([
            'success'          => true,
            'setup_link'       => $mock_setup_link,
            'customer_id'      => 'cust_demo_' . substr(md5($license_key), 0, 12),
            'client_name'      => $lic['client_name'],
            'status'           => 'ready',
            'demo_mode'        => true,
            'message'          => 'API Key de Kapso pendiente de configurar en admin/config.php. Enlace demo generado.'
        ]);
        exit;
    }

    // 3. Crear o verificar cliente en Kapso Platform
    $ch = curl_init("$kapso_base/platform/v1/customers");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'customer' => [
            'name'                 => $lic['client_name'] ?: 'AutoWhats Cliente',
            'external_customer_id' => $license_key
        ]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $kapso_api_key,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $customer_data = json_decode($response, true);
    $customer_id = $customer_data['id'] ?? $customer_data['customer']['id'] ?? null;

    if (!$customer_id && $http_code === 409) {
        // Ya existía, consultar por external_id
        $ch = curl_init("$kapso_base/platform/v1/customers?external_customer_id=" . urlencode($license_key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $kapso_api_key,
            'Accept: application/json'
        ]);
        $get_resp = curl_exec($ch);
        curl_close($ch);
        $get_data = json_decode($get_resp, true);
        $customer_id = $get_data['data'][0]['id'] ?? $get_data['customers'][0]['id'] ?? null;
    }

    if (!$customer_id) {
        echo json_encode([
            'success' => false,
            'message' => 'Error al aprovisionar cliente en Kapso (' . $http_code . '): ' . $response
        ]);
        exit;
    }

    // 4. Generar el Setup Link (Meta Embedded Signup / QR)
    $redirect_url = !empty($site_url) ? (rtrim($site_url, '/') . '/wp-admin/admin.php?page=autwa-whatsapp&kapso_connected=1') : 'https://landing.autowhats.com.mx';

    $ch = curl_init("$kapso_base/platform/v1/customers/{$customer_id}/setup_links");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'success_redirect_url' => $redirect_url,
        'failure_redirect_url' => $redirect_url . '&error=failed'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $kapso_api_key,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $link_resp = curl_exec($ch);
    curl_close($ch);

    $link_data = json_decode($link_resp, true);
    $setup_link = $link_data['url'] ?? $link_data['setup_link']['url'] ?? null;

    if ($setup_link) {
        echo json_encode([
            'success'     => true,
            'setup_link'  => $setup_link,
            'customer_id' => $customer_id,
            'client_name' => $lic['client_name'],
            'status'      => 'ready'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo generar el enlace de conexión en Kapso: ' . $link_resp
        ]);
    }

} catch (Exception $e) {
    error_log('Error en provision-kapso: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error interno: ' . $e->getMessage()
    ]);
}
