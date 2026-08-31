-- ==========================================================
-- AutoWhats License Manager - Base de Datos MySQL
-- ==========================================================

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `licenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `license_key` VARCHAR(64) NOT NULL UNIQUE,
  `client_name` VARCHAR(100) NOT NULL,
  `client_email` VARCHAR(150) NOT NULL,
  `site_url` VARCHAR(255) DEFAULT NULL,
  `plan_name` VARCHAR(50) DEFAULT 'Pro Anual',
  `status` ENUM('active','expired','suspended') DEFAULT 'active',
  `expires_at` DATETIME DEFAULT NULL,
  `stripe_session_id` VARCHAR(150) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_license_key` (`license_key`),
  INDEX `idx_client_email` (`client_email`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario administrador por defecto:
-- Usuario: admin
-- Contraseña inicial: AutoWhats2026! (Cámbiala desde el panel después de ingresar)
INSERT INTO `admins` (`username`, `password_hash`) 
VALUES ('admin', '$2y$10$wE9P0n3rDkK8gZ5mY7xWNu2KjA5l0Y4bV1fS8rG3wT6xQ9pZ2oNm.')
ON DUPLICATE KEY UPDATE `username`=`username`;
