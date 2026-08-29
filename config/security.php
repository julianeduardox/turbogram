<?php
/**
 * Funciones de Seguridad, Sanitización, Rate Limiting y Cabeceras
 * Turbogram
 */

// 1. Configuración de Cookies de Sesión Seguras (OWASP)
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// 2. Cabeceras HTTP de Seguridad
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("X-XSS-Protection: 1; mode=block");
}

/**
 * Obtiene la dirección IP real del cliente de forma segura
 */
function get_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim($forwarded[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
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
 * Genera un código de orden único con alta entropía criptográfica (ej: TURBO-A94F2B1C)
 */
function generate_order_code(): string {
    return 'TURBO-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
}

/**
 * Genera un token CSRF criptográficamente seguro para formularios
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica si el token CSRF es válido utilizando comparación constante en tiempo
 */
function verify_csrf_token(?string $token): bool {
    return isset($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Verifica si una IP ha excedido el límite de intentos fallidos de login (Rate Limiting)
 * Bloquea tras 5 intentos fallidos durante 15 minutos.
 */
function check_login_rate_limit(string $ip): array {
    try {
        require_once __DIR__ . '/database.php';
        $pdo = Database::getConnection();
        
        $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $record = $stmt->fetch();

        if ($record) {
            $max_attempts = 5;
            $lockout_time = 15 * 60; // 15 minutos en segundos
            $last_time = strtotime($record['last_attempt']);
            $elapsed = time() - $last_time;

            if ($record['attempts'] >= $max_attempts) {
                if ($elapsed < $lockout_time) {
                    $remaining_minutes = ceil(($lockout_time - $elapsed) / 60);
                    return [
                        'allowed'           => false,
                        'remaining_minutes' => $remaining_minutes,
                        'message'           => "Demasiados intentos fallidos. Por seguridad tu acceso está temporalmente bloqueado por {$remaining_minutes} minuto(s)."
                    ];
                } else {
                    // El tiempo de bloqueo ya expiró: reiniciar contador
                    $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
                }
            }
        }
    } catch (\Throwable $e) {
        // En caso de que la tabla aún no exista, permitir flujo sin error 500
    }

    return ['allowed' => true];
}

/**
 * Registra un intento fallido de inicio de sesión para el Rate Limiter
 */
function record_failed_login(string $ip): void {
    try {
        require_once __DIR__ . '/database.php';
        $pdo = Database::getConnection();
        
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (ip_address, attempts, last_attempt) 
            VALUES (?, 1, NOW()) 
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()
        ");
        $stmt->execute([$ip]);
    } catch (\Throwable $e) {
        // Silencioso
    }
}

/**
 * Restablece los intentos fallidos de inicio de sesión tras un acceso exitoso
 */
function reset_login_rate_limit(string $ip): void {
    try {
        require_once __DIR__ . '/database.php';
        $pdo = Database::getConnection();
        $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
    } catch (\Throwable $e) {
        // Silencioso
    }
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
    } catch (\Throwable $e) {
        // Silencioso
    }
}

