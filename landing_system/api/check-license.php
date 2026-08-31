<?php
/**
 * AutoWhats API - Verificación de Licencia
 * Endpoint para n8n y WordPress Plugin
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../admin/db.php';

// Leer datos (Soporta JSON body, POST form-urlencoded o GET)
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true);

$license_key = trim($_POST['license_key'] ?? $_POST['license'] ?? $json_data['license_key'] ?? $json_data['license'] ?? $_GET['license_key'] ?? $_GET['license'] ?? '');
$site_url    = trim($_POST['site_url'] ?? $_POST['url'] ?? $json_data['site_url'] ?? $json_data['url'] ?? $_GET['site_url'] ?? $_GET['url'] ?? '');

if (empty($license_key)) {
    echo json_encode([
        'success' => false,
        'license' => 'invalid',
        'message' => 'No se proporcionó la clave de licencia.'
    ]);
    exit;
}

// Normalizar Site URL (remover protocolo y slashes)
$clean_domain = '';
if (!empty($site_url)) {
    $parsed = parse_url($site_url);
    $clean_domain = strtolower($parsed['host'] ?? $site_url);
    $clean_domain = preg_replace('/^www\./', '', $clean_domain);
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, license_key, client_name, client_email, site_url, plan_name, status, expires_at FROM licenses WHERE license_key = ? LIMIT 1');
    $stmt->execute([$license_key]);
    $lic = $stmt->fetch();

    if (!$lic) {
        echo json_encode([
            'success' => false,
            'license' => 'invalid',
            'message' => 'Licencia no encontrada.'
        ]);
        exit;
    }

    // 1. Validar Estado
    if ($lic['status'] !== 'active') {
        echo json_encode([
            'success' => false,
            'license' => 'invalid',
            'status'  => $lic['status'],
            'message' => 'La licencia se encuentra suspendida o inactiva.'
        ]);
        exit;
    }

    // 2. Validar Fecha de Vencimiento
    if (!empty($lic['expires_at'])) {
        $expire_time = strtotime($lic['expires_at']);
        if ($expire_time < time()) {
            // Actualizar estado a expired en BD
            $pdo->prepare('UPDATE licenses SET status = "expired" WHERE id = ?')->execute([$lic['id']]);
            echo json_encode([
                'success' => false,
                'license' => 'invalid',
                'status'  => 'expired',
                'expires_at' => $lic['expires_at'],
                'message' => 'La licencia ha expirado.'
            ]);
            exit;
        }
    }

    // 3. Vincular Dominio si aún no tiene uno asignado
    if (!empty($clean_domain)) {
        if (empty($lic['site_url'])) {
            $pdo->prepare('UPDATE licenses SET site_url = ? WHERE id = ?')->execute([$clean_domain, $lic['id']]);
            $lic['site_url'] = $clean_domain;
        } else {
            // Comprobación de dominio vinculado
            $saved_domain = strtolower(preg_replace('/^www\./', '', parse_url($lic['site_url'], PHP_URL_HOST) ?? $lic['site_url']));
            if ($saved_domain !== $clean_domain && $saved_domain !== 'localhost' && $clean_domain !== 'localhost' && $clean_domain !== '127.0.0.1') {
                echo json_encode([
                    'success' => false,
                    'license' => 'invalid',
                    'message' => 'Esta licencia ya está vinculada a otro dominio (' . $lic['site_url'] . '). Desvincula el dominio desde el panel para transferirla.'
                ]);
                exit;
            }
        }
    }

    // Respuesta Exitosa
    echo json_encode([
        'success'     => true,
        'license'     => 'valid',
        'status'      => 'active',
        'plan_name'   => $lic['plan_name'],
        'client_name' => $lic['client_name'],
        'expires_at'  => $lic['expires_at'] ?? 'lifetime',
        'site_url'    => $lic['site_url']
    ]);

} catch (PDOException $e) {
    error_log('Error en check-license API: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'license' => 'invalid',
        'message' => 'Error interno del servidor al verificar licencia.'
    ]);
}
