<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/TOTP_Service.php';

// Si ya tiene sesión activa completa, enviar a index
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

// Opción de cancelar y reiniciar login
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['admin_2fa_pending'], $_SESSION['admin_2fa_id'], $_SESSION['admin_2fa_user'], $_SESSION['admin_2fa_expires']);
    header('Location: login.php');
    exit;
}

// Verificar que venga del paso 1 de login
if (empty($_SESSION['admin_2fa_pending']) || empty($_SESSION['admin_2fa_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar expiración del paso intermedio (5 minutos)
if (!empty($_SESSION['admin_2fa_expires']) && time() > $_SESSION['admin_2fa_expires']) {
    unset($_SESSION['admin_2fa_pending'], $_SESSION['admin_2fa_id'], $_SESSION['admin_2fa_user'], $_SESSION['admin_2fa_expires']);
    header('Location: login.php?msg=timeout');
    exit;
}

$adminId = (int)$_SESSION['admin_2fa_id'];
$adminUser = $_SESSION['admin_2fa_user'] ?? 'Administrador';
$error = '';

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();

    if (!$admin || empty($admin['totp_enabled'])) {
        unset($_SESSION['admin_2fa_pending'], $_SESSION['admin_2fa_id'], $_SESSION['admin_2fa_user'], $_SESSION['admin_2fa_expires']);
        header('Location: login.php');
        exit;
    }

    // Verificar si el 2FA está bloqueado por demasiados intentos
    $lockCheck = TOTP_Service::check2FALockout($pdo, $adminId);
    if ($lockCheck['locked']) {
        $error = $lockCheck['message'];
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? '';
        if (function_exists('verify_csrf_token') && !verify_csrf_token($csrf)) {
            $error = 'Sesión expirada. Intentá nuevamente.';
        } else {
            $authCode = trim($_POST['auth_code'] ?? '');
            $authResult = TOTP_Service::verifyLogin2FA($pdo, $adminId, $authCode);

            if ($authResult['success']) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }

                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user'] = $authResult['username'] ?? $admin['username'];
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();

                unset($_SESSION['admin_2fa_pending'], $_SESSION['admin_2fa_id'], $_SESSION['admin_2fa_user'], $_SESSION['admin_2fa_expires']);

                if (function_exists('log_audit')) {
                    $auditAction = !empty($authResult['is_recovery']) ? 'ADMIN_LOGIN_RECOVERY_CODE_SUCCESS' : 'ADMIN_LOGIN_2FA_SUCCESS';
                    log_audit($auditAction, "Acceso confirmado con segundo factor para {$admin['username']}" . (!empty($authResult['is_recovery']) ? ' (Código de Recuperación)' : ''));
                }

                header('Location: index.php');
                exit;
            } else {
                $error = $authResult['error'] ?? 'Código de verificación incorrecto o expirado.';
                if (function_exists('log_audit') && ($authResult['reason'] ?? '') === 'invalid_code') {
                    $attempts = $authResult['attempts'] ?? 0;
                    log_audit('ADMIN_LOGIN_2FA_FAILED', "Intento fallido de segundo factor para {$admin['username']} (intento {$attempts})");
                }
            }
        }
    }
} catch (\Throwable $e) {
    error_log('Turbogram: 2FA login database error: ' . $e->getMessage());
    $error = 'Error al verificar el segundo factor. Intentá nuevamente más tarde.';
}
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Dos Factores | Turbogram</title>
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

    <div style="background: var(--admin-card); border: 1px solid var(--admin-border); border-radius: 16px; width: 100%; max-width: 420px; padding: 2.2rem 1.8rem; box-shadow: 0 20px 50px rgba(0,0,0,0.6);">
        <div style="text-align: center; margin-bottom: 2rem;">
            <div style="width: 54px; height: 54px; background: linear-gradient(135deg, #10b981, #06b6d4); border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; color: #fff; margin-bottom: 1rem; box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3);">
                <i class="fa-solid fa-mobile-screen-button"></i>
            </div>
            <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.6rem; margin: 0;">Verificación en Dos Pasos</h1>
            <p style="color: var(--admin-muted); font-size: 0.875rem; margin-top: 0.35rem;">
                Hola <strong><?= htmlspecialchars($adminUser) ?></strong>, ingresá el código de tu aplicación autenticadora.
            </p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 8px; padding: 0.85rem; color: #fca5a5; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="login_2fa.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? csrf_token() : '' ?>">

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.5rem; font-weight: 600;">
                    Código de 6 dígitos o Código de recuperación
                </label>
                <input 
                    type="text" 
                    name="auth_code" 
                    inputmode="numeric" 
                    style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 10px; padding: 0.9rem 1rem; color: #fff; font-size: 1.25rem; text-align: center; letter-spacing: 4px; font-weight: 700; outline: none; font-family: monospace;" 
                    placeholder="123456" 
                    required 
                    autofocus
                    maxlength="20"
                >
                <small style="display: block; color: var(--admin-muted); font-size: 0.78rem; margin-top: 0.4rem; text-align: center;">
                    Google Authenticator, Authy, o código de respaldo (XXXX-XXXX)
                </small>
            </div>

            <button type="submit" class="btn-admin" style="width: 100%; padding: 0.9rem; justify-content: center; font-size: 1rem; background: linear-gradient(135deg, #10b981, #059669); border: none;">
                <i class="fa-solid fa-lock-open"></i> Verificar y Acceder
            </button>

            <div style="text-align: center; margin-top: 1.5rem;">
                <a href="login_2fa.php?cancel_2fa=1" style="color: var(--admin-muted); font-size: 0.85rem; text-decoration: none; transition: color 0.2s;">
                    <i class="fa-solid fa-arrow-left"></i> Cancelar y volver al inicio
                </a>
            </div>
        </form>
    </div>

</body>
</html>
