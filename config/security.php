<?php
/**
 * Funciones de Seguridad, Sanitización, Rate Limiting y Cabeceras
 * Turbogram
 */

// 1. Configuración de Cookies de Sesión Seguras (OWASP)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
if (session_status() === PHP_SESSION_NONE) {
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
    header("X-XSS-Protection: 0");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: base-uri 'self'; form-action 'self' https://www.mercadopago.com https://sandbox.mercadopago.com; frame-ancestors 'self'; object-src 'none'");
    if ($is_https) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }

    // Cabeceras Anti-Cache para el panel administrativo y sesiones autenticadas
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($uri, '/admin/') || !empty($_SESSION['admin_logged_in']) || !empty($_SESSION['admin_2fa_pending'])) {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Expires: 0");
    }
}

/**
 * Obtiene la dirección IP real del cliente de forma segura
 */
function get_client_ip(): string {
    // X-Forwarded-For can be forged unless a trusted proxy is configured.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}

function is_local_development(): bool {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '', 2)[0]);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
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
    return 'TURBO-' . strtoupper(bin2hex(random_bytes(16)));
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
        return ['allowed' => true];
    } catch (\Throwable $e) {
        error_log('Turbogram Security: Error crítico al verificar rate limit por IP: ' . $e->getMessage());
        return [
            'allowed'           => false,
            'remaining_minutes' => 15,
            'message'           => 'Error de seguridad al validar rate limit. Acceso denegado preventivamente (Fail-Closed).'
        ];
    }
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

/**
 * Destruye de forma segura y completa la sesión actual, invalidando la cookie
 */
function secure_logout_session(): void {
    $_SESSION = [];
    if (!headers_sent() && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            $params['secure'] ?? false,
            $params['httponly'] ?? true
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
}

/**
 * Verifica y aplica expiración por inactividad (30 min) y duración máxima absoluta de sesión (8 horas)
 */
function check_session_timeout(int $max_idle_seconds = 1800, int $max_lifetime_seconds = 28800): void {
    if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
        @session_start();
    }

    if (!empty($_SESSION['admin_logged_in'])) {
        $now = time();

        // 1. Duración máxima absoluta de sesión (8 horas desde el inicio de sesión)
        $loginTime = $_SESSION['login_time'] ?? null;
        if ($loginTime !== null && ($now - (int)$loginTime > $max_lifetime_seconds)) {
            if (function_exists('log_audit')) {
                log_audit('ADMIN_SESSION_MAX_LIFETIME_EXPIRED', 'Sesión cerrada por superar la duración máxima absoluta (8 horas)');
            }
            secure_logout_session();
            if (PHP_SAPI !== 'cli') {
                header('Location: login.php?msg=max_lifetime');
                exit;
            }
            return;
        }

        // 2. Inactividad (30 minutos sin solicitudes)
        $lastActivity = $_SESSION['last_activity'] ?? null;
        if ($lastActivity !== null && ($now - (int)$lastActivity > $max_idle_seconds)) {
            if (function_exists('log_audit')) {
                log_audit('ADMIN_SESSION_TIMEOUT', 'Sesión cerrada automáticamente por inactividad');
            }
            secure_logout_session();
            if (PHP_SAPI !== 'cli') {
                header('Location: login.php?msg=timeout');
                exit;
            }
            return;
        }

        if ($loginTime === null) {
            $_SESSION['login_time'] = $now;
        }
        $_SESSION['last_activity'] = $now;
    }
}

// Ejecutar automáticamente la comprobación de inactividad
check_session_timeout();

/**
 * Neutraliza inyección de fórmulas maliciosas en archivos CSV (CSV / Formula Injection - CWE-1236).
 * Previene que caracteres como '=', '+', '-', '@', '\t', '\r' sean ejecutados al abrir el CSV en Excel/Calc.
 */
function sanitize_csv_cell(mixed $value): string {
    if ($value === null) {
        return '';
    }
    $str = (string)$value;
    // Si la cadena comienza con un caracter disparador de fórmulas en hojas de cálculo
    if (preg_match('/^[\=\+\-\@\t\r]/', $str)) {
        return "'" . $str;
    }
    return $str;
}

function sanitize_csv_row(array $row): array {
    return array_map('sanitize_csv_cell', $row);
}


