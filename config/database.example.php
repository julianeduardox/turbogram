<?php
/**
 * Plantilla de Configuración de Base de Datos (Ejemplo)
 * Turbogram - Plataforma Web SMM
 *
 * Instrucciones para Hosting / Producción:
 * Copia o renombra este archivo como 'database.php' y coloca las credenciales de tu hosting.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'nombre_de_tu_bd');
define('DB_USER', getenv('DB_USER') ?: 'usuario_de_tu_bd');
define('DB_PASS', getenv('DB_PASS') ?: 'password_de_tu_bd');
define('DB_CHARSET', 'utf8mb4');

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                die("Error de conexión a la base de datos: " . $e->getMessage());
            }
        }
        return self::$instance;
    }
}
