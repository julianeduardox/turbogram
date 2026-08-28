CREATE DATABASE IF NOT EXISTS `turbogram_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `turbogram_db`;

-- 1. Tabla de Configuraciones
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'Turbogram'),
('site_tagline', 'Impulsá tus redes sociales en segundos'),
('whatsapp_number', '5492364321999'),
('mp_access_token', ''),
('mp_public_key', ''),
('mp_sandbox', '1'),
('provider_api_url', 'https://solydsmm.com/api/v2'),
('provider_api_key', '4ca3f76aaaa9eee0be6bfef255c072f8'),
('currency_symbol', '$')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 2. Tabla de Administradores
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admins` (`username`, `password`, `email`) VALUES
('admin', '$2y$10$b6V5g/.qTBeNA0/sz7h3r.5ARHr6HGWarSx/NOLsAM.lg4OkgWi32', 'admin@turbogram.com')
ON DUPLICATE KEY UPDATE `username` = `username`;

-- 3. Tabla de Categorías (Redes Sociales)
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(50) NOT NULL DEFAULT 'fa-rocket',
  `platform` VARCHAR(50) NOT NULL,
  `sort_order` INT DEFAULT 0,
  `status` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`id`, `name`, `icon`, `platform`, `sort_order`, `status`) VALUES
(1, 'Instagram Seguidores', 'fa-brands fa-instagram', 'instagram', 1, 1),
(2, 'Instagram Likes', 'fa-solid fa-heart', 'instagram', 2, 1),
(3, 'TikTok Seguidores & Views', 'fa-brands fa-tiktok', 'tiktok', 3, 1),
(4, 'Telegram Miembros & Reacciones', 'fa-brands fa-telegram', 'telegram', 4, 1),
(5, 'Twitch & Kick Followers', 'fa-brands fa-twitch', 'twitch', 5, 1),
(6, 'WhatsApp Miembros', 'fa-brands fa-whatsapp', 'whatsapp', 6, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 4. Tabla de Servicios (Mapeados con SolydSMM)
CREATE TABLE IF NOT EXISTS `services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `provider_service_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `platform` VARCHAR(50) NOT NULL,
  `service_type` VARCHAR(50) NOT NULL DEFAULT 'followers',
  `price_per_1000` DECIMAL(12,2) NOT NULL,
  `min_quantity` INT NOT NULL DEFAULT 100,
  `max_quantity` INT NOT NULL DEFAULT 50000,
  `input_placeholder` VARCHAR(255) DEFAULT 'https://instagram.com/tu_usuario o @usuario',
  `description` TEXT NULL,
  `status` TINYINT(1) DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `services` (`id`, `category_id`, `provider_service_id`, `name`, `platform`, `service_type`, `price_per_1000`, `min_quantity`, `max_quantity`, `input_placeholder`, `description`, `status`, `sort_order`) VALUES
(1, 1, 382, 'Seguidores Instagram Estándar (Sin Recarga)', 'instagram', 'followers', 2500.00, 100, 50000, 'https://instagram.com/tu_usuario o @tu_usuario', 'Perfil público requerido. Opción económica sin recarga.', 1, 1),
(8, 1, 340, 'Seguidores Instagram Reales Premium (Garantía 60 Días)', 'instagram', 'followers', 4800.00, 100, 50000, 'https://instagram.com/tu_usuario o @tu_usuario', 'Perfiles de alta calidad con publicaciones reales y garantía de recarga por 60 días.', 1, 2),
(9, 1, 564, 'Seguidores Instagram Latinos / Hispanos', 'instagram', 'followers', 7900.00, 50, 10000, 'https://instagram.com/tu_usuario o @tu_usuario', 'Seguidores dirigidos de público Latinoamericano e Hispano.', 1, 3),
(2, 2, 568, 'Likes Instagram para Publicación o Reel', 'instagram', 'likes', 1800.00, 100, 30000, 'Enlace directo de la publicación o Reel de Instagram', 'Enlace directo a la foto o Reel. Tu cuenta debe ser pública.', 1, 4),
(3, 3, 494, 'Seguidores TikTok Alta Calidad', 'tiktok', 'followers', 4200.00, 100, 10000, 'https://tiktok.com/@tu_usuario o @tu_usuario', 'Aumentá la credibilidad de tu perfil de TikTok al instante.', 1, 3),
(4, 4, 373, 'Miembros Telegram para Canal o Grupo', 'telegram', 'members', 4800.00, 100, 20000, 'https://t.me/nombre_de_canal o @canal', 'Enlace público del canal o grupo de Telegram.', 1, 4),
(5, 5, 494, 'Seguidores Twitch HQ', 'twitch', 'followers', 3900.00, 100, 10000, 'https://twitch.tv/tu_canal', 'Potenciá tu canal de Twitch para alcanzar el afiliado.', 1, 5),
(6, 6, 89, 'Miembros Canal de WhatsApp', 'whatsapp', 'members', 5500.00, 10, 10000, 'Enlace de invitación al canal público de WhatsApp', 'Aumentá los miembros de tu canal oficial de WhatsApp.', 1, 6),
(7, 3, 256, 'Reproducciones TikTok (Views Instantáneas)', 'tiktok', 'views', 1200.00, 1000, 1000000, 'https://tiktok.com/@usuario/video/123456789', 'Vistas ultra rápidas para tu video de TikTok.', 1, 4)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `price_per_1000` = VALUES(`price_per_1000`);

-- 5. Tabla de Órdenes / Pedidos
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_code` VARCHAR(30) NOT NULL UNIQUE,
  `service_id` INT NOT NULL,
  `service_name` VARCHAR(255) NOT NULL,
  `quantity` INT NOT NULL,
  `target_link` VARCHAR(500) NOT NULL,
  `buyer_email` VARCHAR(150) NOT NULL,
  `buyer_phone` VARCHAR(50) NOT NULL,
  `total_price` DECIMAL(12,2) NOT NULL,
  `mp_preference_id` VARCHAR(100) DEFAULT NULL,
  `mp_payment_id` VARCHAR(100) DEFAULT NULL,
  `mp_status` VARCHAR(50) DEFAULT 'pending',
  `provider_order_id` INT DEFAULT NULL,
  `provider_status` VARCHAR(50) DEFAULT 'pending_send',
  `provider_response` TEXT DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_order_code` (`order_code`),
  INDEX `idx_mp_payment` (`mp_payment_id`),
  INDEX `idx_mp_status` (`mp_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabla de Registros de Auditoría
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
