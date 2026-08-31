<?php
/**
 * AutoWhats License Manager - Configuración General
 */

// Configuración de Base de Datos MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'autowhats_licenses');
define('DB_USER', 'tu_usuario_mysql');
define('DB_PASS', 'tu_password_mysql');
define('DB_CHARSET', 'utf8mb4');

// Configuración de Brevo (Email Transaccional)
define('BREVO_API_KEY', 'xkeysib-TU_CLAVE_API_DE_BREVO');
define('BREVO_SENDER_EMAIL', 'soporte@autowhats.com.mx');
define('BREVO_SENDER_NAME', 'AutoWhats Soporte');

// Configuración de Stripe (Opcional si usas Webhook)
define('STRIPE_WEBHOOK_SECRET', 'whsec_TU_SECRETO_DE_STRIPE');

// URL de descarga del plugin que se envía al cliente por correo
define('PLUGIN_DOWNLOAD_URL', 'https://landing.autowhats.com.mx/downloads/autowhats.zip');
