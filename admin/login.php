<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/TOTP_Service.php';

$error = '';
$info = '';
$client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'timeout') {
        $info = 'Tu sesión ha expirado por inactividad. Por favor, iniciá sesión nuevamente.';
    } elseif ($_GET['msg'] === 'max_lifetime') {
        $info = 'Tu sesión ha expirado por superar la duración máxima permitida (8 horas). Iniciá sesión nuevamente.';
    } elseif ($_GET['msg'] === 'logout') {
        $info = 'Has cerrado sesión correctamente de forma segura.';
    }
}

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

// Rate Limiting seguro por IP
$rateLimit = function_exists('check_login_rate_limit') ? check_login_rate_limit($client_ip) : ['allowed' => true];
if (!$rateLimit['allowed']) {
    $error = $rateLimit['message'] ?? 'Acceso bloqueado temporalmente por IP.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (function_exists('verify_csrf_token') && !verify_csrf_token($csrf)) {
        $error = 'Sesión expirada. Intentá nuevamente.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username !== '' && $password !== '') {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                if ($admin) {
                    // Verificar si la cuenta específica está bloqueada
                    $acctLock = TOTP_Service::checkAccountPasswordLockout($pdo, (int)$admin['id']);
                    if ($acctLock['locked']) {
                        $error = $acctLock['message'];
                    } elseif (password_verify($password, $admin['password'])) {
                        // Contraseña válida: resetear contadores de fallos
                        if (function_exists('reset_login_rate_limit')) {
                            reset_login_rate_limit($client_ip);
                        }
                        TOTP_Service::resetAccountPasswordLockout($pdo, (int)$admin['id']);

                        if (session_status() === PHP_SESSION_ACTIVE) {
                            session_regenerate_id(true);
                        }

                        // Comprobar si tiene doble factor (2FA) habilitado
                        if (!empty($admin['totp_enabled'])) {
                            // Estado intermedio para ingresar segundo factor
                            $_SESSION['admin_2fa_pending'] = true;
                            $_SESSION['admin_2fa_id'] = (int)$admin['id'];
                            $_SESSION['admin_2fa_user'] = $admin['username'];
                            $_SESSION['admin_2fa_expires'] = time() + 300; // 5 minutos de validez

                            if (function_exists('log_audit')) {
                                log_audit('ADMIN_LOGIN_PASSWORD_OK', 'Paso 1 contraseña verificado. Esperando 2FA para: ' . $admin['username']);
                            }

                            header('Location: login_2fa.php');
                            exit;
                        }

                        // Sin 2FA: acceso completo
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_user'] = $admin['username'];
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['last_activity'] = time();

                        if (function_exists('log_audit')) {
                            log_audit('ADMIN_LOGIN_SUCCESS', 'Inicio de sesión de: ' . $admin['username']);
                        }
                        header('Location: index.php');
                        exit;
                    } else {
                        // Contraseña incorrecta
                        TOTP_Service::recordFailedPassword($pdo, (int)$admin['id']);
                        if (function_exists('record_failed_login')) {
                            record_failed_login($client_ip);
                        }
                        $error = 'Usuario o contraseña incorrectos.';
                    }
                } else {
                    // Usuario no encontrado
                    if (function_exists('record_failed_login')) {
                        record_failed_login($client_ip);
                    }
                    $error = 'Usuario o contraseña incorrectos.';
                }
            } catch (\Throwable $e) {
                error_log('Turbogram: admin login database error.');
                $error = 'No se pudo procesar el inicio de sesión. Intentá nuevamente más tarde.';
            }
        } else {
            $error = 'Ingresá tu usuario y contraseña.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Administrador | Turbogram</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="192x192" href="../assets/img/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Outfit:wght@600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= time() ?>">
</head>
<body class="admin-body" style="display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1.25rem;">

    <div style="background: var(--admin-card); border: 1px solid var(--admin-border); border-radius: 16px; width: 100%; max-width: 400px; padding: 2rem 1.5rem; box-shadow: 0 20px 50px rgba(0,0,0,0.6);">
        <div style="text-align: center; margin-bottom: 2rem;">
            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #8a2be2, #ff007a); border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #fff; margin-bottom: 1rem;">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.6rem; margin: 0;">Panel del Dueño</h1>
            <p style="color: var(--admin-muted); font-size: 0.875rem; margin-top: 0.25rem;">Turbogram Backoffice</p>
        </div>

        <?php if ($info): ?>
            <div style="background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.4); border-radius: 8px; padding: 0.85rem; color: #7dd3fc; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center;">
                <i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($info) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 8px; padding: 0.85rem; color: #fca5a5; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? csrf_token() : '' ?>">

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.4rem; font-weight: 600;">Usuario</label>
                <input type="text" name="username" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.8rem 1rem; color: #fff; font-size: 0.95rem; outline: none;" placeholder="Ej: admin" required autofocus>
            </div>

            <div style="margin-bottom: 1.75rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.4rem; font-weight: 600;">Contraseña</label>
                <input type="password" name="password" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.8rem 1rem; color: #fff; font-size: 0.95rem; outline: none;" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn-admin" style="width: 100%; padding: 0.9rem; justify-content: center; font-size: 1rem;">
                <i class="fa-solid fa-right-to-bracket"></i> Iniciar Sesión
            </button>
        </form>
    </div>

</body>
</html>
