<?php
/**
 * Funciones de Seguridad, Sanitización y Formato
 * Turbogram
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

/**
 * Sanitiza texto recibido de formularios
 */
function clean_input(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Formato de precio en pesos argentinos según Regla 9:
 * Sin decimales y utilizando punto (.) como separador de miles.
 * Ejemplo: 1500 -> "$ 1.500"
 */
function format_price(float|int $amount, bool $include_symbol = true): string {
    $formatted = number_format(round($amount), 0, ',', '.');
    return $include_symbol ? '$ ' . $formatted : $formatted;
}

/**
 * Genera un código de orden único e irrepetible (ej: TURBO-A94F2B)
 */
function generate_order_code(): string {
    return 'TURBO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/**
 * Genera un token CSRF para formularios
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica si el token CSRF es válido
 */
function verify_csrf_token(?string $token): bool {
    return isset($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Registra una acción en la tabla de auditoría
 */
function log_audit(string $action, string $details = ''): void {
    try {
        require_once __DIR__ . '/database.php';
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO audit_logs (action, details) VALUES (?, ?)");
        $stmt->execute([$action, $details]);
    } catch (Exception $e) {
        // Silencioso
    }
}
