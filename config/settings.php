<?php
/**
 * Gestor de Configuraciones Dinámicas (Base de Datos)
 * Turbogram
 */

require_once __DIR__ . '/database.php';

class Settings {
    private static array $cache = [];
    private static bool $loaded = false;

    public static function load(): void {
        if (self::$loaded) return;
        
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
            while ($row = $stmt->fetch()) {
                self::$cache[$row['setting_key']] = $row['setting_value'];
            }
            self::$loaded = true;
        } catch (Exception $e) {
            // Silencioso o log en inicialización
        }
    }

    public static function get(string $key, string $default = ''): string {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $result = $stmt->execute([$key, $value]);
        if ($result) {
            self::$cache[$key] = $value;
        }
        return $result;
    }

    public static function getAll(): array {
        self::load();
        return self::$cache;
    }
}
