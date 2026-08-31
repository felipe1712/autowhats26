<?php
/**
 * Helper de Autenticación y Seguridad de Sesión
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generate_license_key() {
    // Genera clave con formato AW-XXXX-XXXX-XXXX
    $bytes1 = strtoupper(bin2hex(random_bytes(2)));
    $bytes2 = strtoupper(bin2hex(random_bytes(2)));
    $bytes3 = strtoupper(bin2hex(random_bytes(2)));
    return "AW-$bytes1-$bytes2-$bytes3";
}
