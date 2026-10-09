<?php
/**
 * AutoWhats - Alias para Provision Kapso
 */
if (file_exists(__DIR__ . '/../api/provision-kapso.php')) {
    require_once __DIR__ . '/../api/provision-kapso.php';
} else {
    require_once __DIR__ . '/provision-kapso.php';
}
