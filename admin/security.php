<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/TOTP_Service.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$pdo = Database::getConnection();
$adminId = (int)$_SESSION['admin_id'];

$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    secure_logout_session();
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';
$flashCodes = $_SESSION['flash_recovery_codes'] ?? null;
unset($_SESSION['flash_recovery_codes']);

if (isset($_GET['enabled'])) {
    $message = '¡Doble factor (2FA) activado correctamente! Tu cuenta ahora está blindada.';
} elseif (isset($_GET['disabled'])) {
    $message = 'El doble factor (2FA) ha sido desactivado.';
} elseif (isset($_GET['regenerated'])) {
    $message = 'Se han generado nuevos códigos de recuperación de un solo uso.';
}

// Procesar Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $error = 'Token de seguridad inválido o expirado. Por favor, recargá la página.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Iniciar asistente de configuración 2FA (generar secreto provisional)
        if ($action === 'start_setup_2fa') {
            if (!empty($admin['totp_enabled'])) {
                // Reautenticación estricta previa para reconfigurar o reemplazar 2FA activo
                $currentPassword = $_POST['current_password'] ?? '';
                $totpCode = trim($_POST['totp_code'] ?? '');
                $reauth = TOTP_Service::verifyReauth($pdo, $adminId, $currentPassword, $totpCode);
                if (!$reauth['success']) {
                    $error = 'Para reconfigurar el doble factor debe reautenticarse con su contraseña y código actual: ' . ($reauth['error'] ?? 'Credenciales inválidas.');
                } else {
                    $setupSecret = TOTP_Service::generateSecret(20);
                    $_SESSION['2fa_setup_secret'] = $setupSecret;
                    $_SESSION['2fa_reauth_admin_id'] = $adminId;
                    $_SESSION['2fa_reauth_secret_hash'] = hash('sha256', $setupSecret);
                    $_SESSION['2fa_reauth_expires'] = time() + 300; // Validez estricta de 5 minutos
                }
            } else {
                $setupSecret = TOTP_Service::generateSecret(20);
                $_SESSION['2fa_setup_secret'] = $setupSecret;
                $_SESSION['2fa_setup_admin_id'] = $adminId;
                $_SESSION['2fa_setup_secret_hash'] = hash('sha256', $setupSecret);
                $_SESSION['2fa_setup_expires'] = time() + 300; // Validez de 5 minutos
            }
        }

        // 2. Cancelar asistente de configuración
        elseif ($action === 'cancel_setup_2fa') {
            unset(
                $_SESSION['2fa_setup_secret'], 
                $_SESSION['2fa_setup_admin_id'],
                $_SESSION['2fa_setup_secret_hash'],
                $_SESSION['2fa_setup_expires'],
                $_SESSION['2fa_reauth_admin_id'], 
                $_SESSION['2fa_reauth_secret_hash'], 
                $_SESSION['2fa_reauth_expires']
            );
        }

        // 3. Confirmar activación de 2FA con primer código válido
        elseif ($action === 'confirm_enable_2fa') {
            $setupSecret = $_SESSION['2fa_setup_secret'] ?? '';
            $now = time();

            // Si ya estaba activo, validar que la autorización no haya caducado, corresponda al admin y coincida con el secreto
            if (!empty($admin['totp_enabled'])) {
                $isReauthValid = !empty($_SESSION['2fa_reauth_admin_id'])
                    && (int)$_SESSION['2fa_reauth_admin_id'] === $adminId
                    && !empty($_SESSION['2fa_reauth_expires'])
                    && $now <= (int)$_SESSION['2fa_reauth_expires']
                    && !empty($_SESSION['2fa_reauth_secret_hash'])
                    && hash_equals($_SESSION['2fa_reauth_secret_hash'], hash('sha256', (string)$setupSecret));

                if (!$isReauthValid) {
                    $error = 'La autorización para reconfigurar el doble factor ha expirado o no es válida. Debe reautenticarse nuevamente.';
                    unset(
                        $_SESSION['2fa_setup_secret'], 
                        $_SESSION['2fa_reauth_admin_id'], 
                        $_SESSION['2fa_reauth_secret_hash'], 
                        $_SESSION['2fa_reauth_expires']
                    );
                }
            } else {
                $isSetupValid = !empty($_SESSION['2fa_setup_admin_id'])
                    && (int)$_SESSION['2fa_setup_admin_id'] === $adminId
                    && !empty($_SESSION['2fa_setup_expires'])
                    && $now <= (int)$_SESSION['2fa_setup_expires']
                    && !empty($_SESSION['2fa_setup_secret_hash'])
                    && hash_equals($_SESSION['2fa_setup_secret_hash'], hash('sha256', (string)$setupSecret));

                if (!$isSetupValid) {
                    $error = 'La sesión de configuración del doble factor ha expirado o no es válida. Iniciá el proceso nuevamente.';
                    unset(
                        $_SESSION['2fa_setup_secret'],
                        $_SESSION['2fa_setup_admin_id'],
                        $_SESSION['2fa_setup_secret_hash'],
                        $_SESSION['2fa_setup_expires']
                    );
                }
            }

            if (empty($error)) {
                $confirmCode = trim($_POST['confirm_code'] ?? '');

                if ($setupSecret === '') {
                    $error = 'La sesión de configuración expiró. Iniciá el proceso nuevamente.';
                } else {
                    $verify = TOTP_Service::verifyCode($setupSecret, $confirmCode);
                    if ($verify['valid']) {
                        // Cifrar secreto con AES-256-GCM
                        $encryptedSecret = TOTP_Service::encryptSecret($setupSecret);

                        // Generar 8 códigos de recuperación
                        $recoveryCodes = TOTP_Service::generateRecoveryCodes(8);

                        // Transacción atómica ÚNICA para secreto y códigos de recuperación
                        TOTP_Service::activate2FA($pdo, $adminId, $encryptedSecret, $verify['step'], $recoveryCodes);

                        // Consumir inmediatamente la autorización de un solo uso
                        unset(
                            $_SESSION['2fa_setup_secret'], 
                            $_SESSION['2fa_setup_admin_id'],
                            $_SESSION['2fa_setup_secret_hash'],
                            $_SESSION['2fa_setup_expires'],
                            $_SESSION['2fa_reauth_admin_id'], 
                            $_SESSION['2fa_reauth_secret_hash'], 
                            $_SESSION['2fa_reauth_expires']
                        );
                        $_SESSION['flash_recovery_codes'] = $recoveryCodes;

                        if (function_exists('log_audit')) {
                            log_audit('ADMIN_2FA_ENABLED', "2FA activado con éxito para admin {$admin['username']}");
                        }

                        header('Location: security.php?enabled=1');
                        exit;
                    } else {
                        $error = 'Código de verificación incorrecto. Asegurate de que la hora de tu dispositivo esté sincronizada.';
                    }
                }
            }
        }

        // 4. Desactivar 2FA (Exige Reautenticación estricta: Password + TOTP en transacción atómica)
        elseif ($action === 'disable_2fa') {
            $currentPassword = $_POST['current_password'] ?? '';
            $totpCode = trim($_POST['totp_code'] ?? '');

            $reauth = TOTP_Service::verifyReauth($pdo, $adminId, $currentPassword, $totpCode);
            if ($reauth['success']) {
                TOTP_Service::disable2FA($pdo, $adminId);

                if (function_exists('log_audit')) {
                    log_audit('ADMIN_2FA_DISABLED', "2FA desactivado para admin {$admin['username']}");
                }

                header('Location: security.php?disabled=1');
                exit;
            } else {
                $error = 'Error de reautenticación: ' . ($reauth['error'] ?? 'Credenciales inválidas.');
            }
        }

        // 5. Regenerar códigos de recuperación (Exige Reautenticación estricta: Password + TOTP)
        elseif ($action === 'regenerate_recovery_codes') {
            $currentPassword = $_POST['current_password'] ?? '';
            $totpCode = trim($_POST['totp_code'] ?? '');

            $reauth = TOTP_Service::verifyReauth($pdo, $adminId, $currentPassword, $totpCode);
            if ($reauth['success']) {
                $recoveryCodes = TOTP_Service::generateRecoveryCodes(8);
                TOTP_Service::storeRecoveryCodes($pdo, $adminId, $recoveryCodes);
                $_SESSION['flash_recovery_codes'] = $recoveryCodes;

                if (function_exists('log_audit')) {
                    log_audit('ADMIN_2FA_RECOVERY_REGENERATED', "Códigos de recuperación regenerados para admin {$admin['username']}");
                }

                header('Location: security.php?regenerated=1');
                exit;
            } else {
                $error = 'Error de reautenticación: ' . ($reauth['error'] ?? 'Credenciales inválidas.');
            }
        }
    }
}

// Refrescar estado del admin
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
$is2FAEnabled = !empty($admin['totp_enabled']);
$availableRecoveryCount = $is2FAEnabled ? TOTP_Service::getAvailableRecoveryCodesCount($pdo, $adminId) : 0;
$setupSecret = $_SESSION['2fa_setup_secret'] ?? null;
$otpAuthUri = $setupSecret ? TOTP_Service::getOtpAuthUri($admin['username'], $setupSecret) : '';
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seguridad y Doble Factor (2FA) | Turbogram</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="192x192" href="../assets/img/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Outfit:wght@600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= time() ?>">
    <!-- QR Code Generator autónomo local verificado (cero llamadas a CDN o APIs externas) -->
    <script src="../assets/js/qrcode.min.js"></script>
    <style>
        .sec-card {
            background: var(--admin-card);
            border: 1px solid var(--admin-border);
            border-radius: 14px;
            padding: 1.75rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }
        .code-pill {
            background: rgba(0,0,0,0.4);
            border: 1px solid var(--admin-border);
            border-radius: 8px;
            padding: 0.6rem 1rem;
            font-family: monospace;
            font-size: 1.05rem;
            letter-spacing: 2px;
            color: #38bdf8;
            display: inline-block;
            font-weight: 700;
        }
        .recovery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 0.75rem;
            margin: 1.25rem 0;
        }
        .recovery-item {
            background: rgba(0,0,0,0.5);
            border: 1px dashed rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 0.65rem 0.75rem;
            text-align: center;
            font-family: monospace;
            font-size: 1rem;
            color: #fef08a;
            font-weight: 700;
            letter-spacing: 1px;
        }
    </style>
</head>
<body class="admin-body">

<div class="admin-layout">
    <!-- Sidebar Navigation -->
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <i class="fa-solid fa-bolt" style="color: var(--admin-accent);"></i> Turbogram Panel
        </div>

        <ul class="admin-menu">
            <li><a href="index.php"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
            <li><a href="orders.php"><i class="fa-solid fa-cart-shopping"></i> Pedidos</a></li>
            <li><a href="services.php"><i class="fa-solid fa-list-check"></i> Servicios y Precios</a></li>
            <li><a href="promotions.php"><i class="fa-solid fa-tags"></i> Ofertas y Cupones</a></li>
            <li><a href="providers.php"><i class="fa-solid fa-server"></i> Proveedores SMM</a></li>
            <li><a href="settings.php"><i class="fa-solid fa-sliders"></i> Mercado Pago y API</a></li>
            <li><a href="security.php" class="active"><i class="fa-solid fa-shield-halved"></i> Seguridad y 2FA</a></li>
            <li style="margin-top: auto;"><a href="logout.php" style="color: #fca5a5;"><i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesión</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="admin-main">
        <div class="admin-header">
            <div>
                <h1 class="admin-title">Seguridad de la Cuenta</h1>
                <p style="color: var(--admin-muted); font-size: 0.9rem; margin: 0;">
                    Protección de acceso del administrador mediante Doble Factor (TOTP RFC 6238) y cifrado AES-256-GCM
                </p>
            </div>
        </div>

        <?php if ($message): ?>
            <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 10px; padding: 1rem 1.25rem; color: #6ee7b7; font-size: 0.95rem; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-circle-check" style="font-size: 1.25rem;"></i>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 10px; padding: 1rem 1.25rem; color: #fca5a5; font-size: 0.95rem; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.25rem;"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- Códigos de Recuperación Flash (Una sola vez) -->
        <?php if (!empty($flashCodes)): ?>
            <div class="sec-card" style="border-color: #f59e0b; background: rgba(245, 158, 11, 0.08);">
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <i class="fa-solid fa-key" style="font-size: 1.5rem; color: #fbbf24;"></i>
                    <h2 style="font-size: 1.25rem; color: #fef08a; margin: 0;">Guardá tus Códigos de Recuperación</h2>
                </div>
                <p style="color: #fde68a; font-size: 0.9rem; margin: 0 0 1rem 0;">
                    <strong>Atención crítica:</strong> Estos códigos son de <strong>un solo uso</strong>. Si perdés el acceso a tu aplicación de autenticación, serán tu única forma de ingresar al panel. <em>No se volverán a mostrar en texto claro.</em>
                </p>

                <div class="recovery-grid">
                    <?php foreach ($flashCodes as $c): ?>
                        <div class="recovery-item"><?= htmlspecialchars($c) ?></div>
                    <?php endforeach; ?>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 1.25rem; flex-wrap: wrap;">
                    <button type="button" class="btn-admin" onclick="copyRecoveryCodes()" style="background: #fbbf24; color: #000; font-weight: 700;">
                        <i class="fa-solid fa-copy"></i> Copiar Todos
                    </button>
                    <button type="button" class="btn-admin" onclick="downloadRecoveryCodes()" style="background: rgba(255,255,255,0.1);">
                        <i class="fa-solid fa-download"></i> Descargar como TXT
                    </button>
                </div>
            </div>

            <script>
                const recoveryCodesList = <?= json_encode($flashCodes) ?>;
                function copyRecoveryCodes() {
                    const text = "Turbogram - Códigos de Recuperación de Administrador:\n" + recoveryCodesList.join("\n");
                    navigator.clipboard.writeText(text).then(() => {
                        alert("Códigos copiados al portapapeles. Guardalos en tu gestor de contraseñas de forma segura.");
                    });
                }
                function downloadRecoveryCodes() {
                    const text = "Turbogram - Códigos de Recuperación de Administrador:\n" + recoveryCodesList.join("\n") + "\n\nFecha: " + new Date().toISOString();
                    const blob = new Blob([text], { type: 'text/plain' });
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'turbogram_recovery_codes.txt';
                    a.click();
                }
            </script>
        <?php endif; ?>

        <!-- Estado Actual del 2FA -->
        <div class="sec-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; background: <?= $is2FAEnabled ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)' ?>; color: <?= $is2FAEnabled ? '#34d399' : '#f87171' ?>;">
                        <i class="fa-solid <?= $is2FAEnabled ? 'fa-shield-check' : 'fa-shield-slash' ?>"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 1.25rem; margin: 0;">Autenticación en Dos Factores (TOTP)</h2>
                        <p style="color: var(--admin-muted); font-size: 0.875rem; margin: 0.2rem 0 0 0;">
                            Estándar RFC 6238 con cifrado AES-256-GCM de clave externa y protección anti-repetición.
                        </p>
                    </div>
                </div>

                <div>
                    <?php if ($is2FAEnabled): ?>
                        <span style="background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.4); color: #6ee7b7; padding: 0.45rem 0.9rem; border-radius: 20px; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-lock"></i> ACTIVADO Y BLINDADO
                        </span>
                    <?php else: ?>
                        <span style="background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; padding: 0.45rem 0.9rem; border-radius: 20px; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-lock-open"></i> DESACTIVADO
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$is2FAEnabled): ?>
                <!-- Flujo para Habilitar 2FA -->
                <?php if (!$setupSecret): ?>
                    <p style="color: var(--admin-muted); font-size: 0.95rem; line-height: 1.6;">
                        El doble factor agrega una capa de defensa impenetrable: para iniciar sesión se requerirá tu contraseña habitual y un código numérico temporal de 6 dígitos generado en tu teléfono móvil (Google Authenticator, Authy, etc.).
                    </p>
                    <form action="security.php" method="POST" style="margin-top: 1.5rem;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="start_setup_2fa">
                        <button type="submit" class="btn-admin" style="background: linear-gradient(135deg, #10b981, #059669); border: none; padding: 0.85rem 1.5rem; font-size: 1rem;">
                            <i class="fa-solid fa-qrcode"></i> Configurar y Activar Doble Factor
                        </button>
                    </form>
                <?php else: ?>
                    <!-- Asistente de Configuración con Código QR y Confirmación -->
                    <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 12px; padding: 1.75rem; margin-top: 1.5rem;">
                        <h3 style="font-size: 1.1rem; margin: 0 0 1rem 0; color: #fff;">
                            Paso 1: Escaneá el código QR en tu aplicación autenticadora
                        </h3>
                        <p style="color: var(--admin-muted); font-size: 0.875rem; margin-bottom: 1.5rem;">
                            Abrí Google Authenticator, Authy, Microsoft Authenticator o 1Password y escaneá este código:
                        </p>

                        <div style="display: flex; gap: 2rem; align-items: center; flex-wrap: wrap; margin-bottom: 2rem;">
                            <div style="background: #fff; padding: 12px; border-radius: 12px; display: inline-block; box-shadow: 0 8px 24px rgba(0,0,0,0.4);" id="qrcode_container"></div>

                            <div style="flex: 1; min-width: 260px;">
                                <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.4rem; font-weight: 600;">
                                    ¿No podés escanearlo? Ingresá la clave manualmente:
                                </label>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span class="code-pill" id="secret_text"><?= htmlspecialchars(chunk_split($setupSecret, 4, ' ')) ?></span>
                                    <button type="button" class="btn-admin" onclick="navigator.clipboard.writeText('<?= $setupSecret ?>'); alert('Clave secreta copiada.');" style="padding: 0.6rem 0.9rem;">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                </div>
                                <p style="color: var(--admin-muted); font-size: 0.8rem; margin-top: 0.75rem;">
                                    Tipo: Basado en tiempo (TOTP) | Intervalo: 30 segundos | Dígitos: 6
                                </p>
                            </div>
                        </div>

                        <script>
                            new QRCode(document.getElementById("qrcode_container"), {
                                text: "<?= $otpAuthUri ?>",
                                width: 160,
                                height: 160,
                                colorDark : "#000000",
                                colorLight : "#ffffff",
                                correctLevel : QRCode.CorrectLevel.M
                            });
                        </script>

                        <hr style="border: none; border-top: 1px solid var(--admin-border); margin: 1.75rem 0;">

                        <h3 style="font-size: 1.1rem; margin: 0 0 0.5rem 0; color: #fff;">
                            Paso 2: Confirmá el primer código de 6 dígitos
                        </h3>
                        <p style="color: var(--admin-muted); font-size: 0.875rem; margin-bottom: 1.25rem;">
                            Ingresá el código que muestra tu app ahora mismo para verificar que la configuración sea correcta y activar el servicio:
                        </p>

                        <form action="security.php" method="POST" autocomplete="off" style="max-width: 360px;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="confirm_enable_2fa">

                            <div style="margin-bottom: 1.25rem;">
                                <input 
                                    type="text" 
                                    name="confirm_code" 
                                    inputmode="numeric" 
                                    maxlength="6" 
                                    style="width: 100%; background: rgba(0,0,0,0.4); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.8rem 1rem; color: #fff; font-size: 1.3rem; text-align: center; letter-spacing: 4px; font-weight: 700; outline: none; font-family: monospace;" 
                                    placeholder="123456" 
                                    required 
                                    autofocus
                                >
                            </div>

                            <div style="display: flex; gap: 0.75rem;">
                                <button type="submit" class="btn-admin" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #10b981, #059669); border: none;">
                                    <i class="fa-solid fa-check"></i> Activar 2FA
                                </button>
                                <button type="submit" name="action" value="cancel_setup_2fa" class="btn-admin" style="background: rgba(255,255,255,0.1); border: none;">
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- 2FA ya Habilitado: Gestión y Opciones de Reautenticación -->
                <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <strong style="color: #6ee7b7; font-size: 1rem;">
                            <i class="fa-solid fa-shield-virus"></i> Códigos de Recuperación Disponibles: <?= $availableRecoveryCount ?> de 8
                        </strong>
                        <p style="color: var(--admin-muted); font-size: 0.85rem; margin: 0.25rem 0 0 0;">
                            Los códigos consumidos quedan inutilizables permanentemente para prevenir reutilizaciones.
                        </p>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
                    <!-- Caja: Regenerar Códigos de Recuperación -->
                    <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 12px; padding: 1.5rem;">
                        <h3 style="font-size: 1.05rem; margin: 0 0 0.5rem 0; color: #fff;">
                            <i class="fa-solid fa-arrows-rotate" style="color: #38bdf8;"></i> Regenerar Códigos de Recuperación
                        </h3>
                        <p style="color: var(--admin-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
                            Invalida todos los códigos anteriores e imprime 8 códigos nuevos. Exige reautenticación por seguridad.
                        </p>

                        <form action="security.php" method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="regenerate_recovery_codes">

                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Contraseña Actual</label>
                                <input type="password" name="current_password" style="width: 100%; background: rgba(0,0,0,0.4); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 0.9rem;" placeholder="••••••••" required>
                            </div>

                            <div style="margin-bottom: 1.25rem;">
                                <label style="display: block; font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Código 2FA Actual (6 dígitos)</label>
                                <input type="text" name="totp_code" inputmode="numeric" maxlength="6" style="width: 100%; background: rgba(0,0,0,0.4); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 1rem; font-family: monospace; text-align: center; letter-spacing: 2px;" placeholder="123456" required>
                            </div>

                            <button type="submit" class="btn-admin" style="width: 100%; justify-content: center; background: rgba(56, 189, 248, 0.2); border: 1px solid #38bdf8; color: #7dd3fc;">
                                <i class="fa-solid fa-key"></i> Regenerar Códigos
                            </button>
                        </form>
                    </div>

                    <!-- Caja: Desactivar Doble Factor -->
                    <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 12px; padding: 1.5rem;">
                        <h3 style="font-size: 1.05rem; margin: 0 0 0.5rem 0; color: #fca5a5;">
                            <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i> Desactivar Doble Factor (2FA)
                        </h3>
                        <p style="color: var(--admin-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
                            Reduce la seguridad de la cuenta al requerir solo contraseña. Exige reautenticación obligatoria con contraseña y código TOTP actual.
                        </p>

                        <form action="security.php" method="POST" autocomplete="off" onsubmit="return confirm('¿Seguro que deseas desactivar el doble factor de tu cuenta?');">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="disable_2fa">

                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Contraseña Actual</label>
                                <input type="password" name="current_password" style="width: 100%; background: rgba(0,0,0,0.4); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 0.9rem;" placeholder="••••••••" required>
                            </div>

                            <div style="margin-bottom: 1.25rem;">
                                <label style="display: block; font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Código 2FA Actual (6 dígitos)</label>
                                <input type="text" name="totp_code" inputmode="numeric" maxlength="6" style="width: 100%; background: rgba(0,0,0,0.4); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 1rem; font-family: monospace; text-align: center; letter-spacing: 2px;" placeholder="123456" required>
                            </div>

                            <button type="submit" class="btn-admin btn-admin-danger" style="width: 100%; justify-content: center;">
                                <i class="fa-solid fa-lock-open"></i> Desactivar 2FA
                            </button>
                        </form>
                    </div>

                    <!-- Caja: Reconfigurar 2FA (Cambiar Dispositivo) -->
                    <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 12px; padding: 1.5rem;">
                        <h3 style="font-size: 1.05rem; margin: 0 0 0.5rem 0; color: #c084fc;">
                            <i class="fa-solid fa-mobile-screen" style="color: #c084fc;"></i> Reconfigurar Dispositivo 2FA
                        </h3>
                        <p style="color: var(--admin-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
                            Genera un nuevo secreto QR para vincular otro teléfono. Exige reautenticación previa con tu clave y código 2FA actual.
                        </p>

                        <form action="security.php" method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="start_setup_2fa">

                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Contraseña Actual</label>
                                <input type="password" name="current_password" style="width: 100%; background: rgba(0,0,0,0.4); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 0.9rem;" placeholder="••••••••" required>
                            </div>

                            <div style="margin-bottom: 1.25rem;">
                                <label style="display: block; font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Código 2FA Actual (6 dígitos)</label>
                                <input type="text" name="totp_code" inputmode="numeric" maxlength="6" style="width: 100%; background: rgba(0,0,0,0.4); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 1rem; font-family: monospace; text-align: center; letter-spacing: 2px;" placeholder="123456" required>
                            </div>

                            <button type="submit" class="btn-admin" style="width: 100%; justify-content: center; background: rgba(168, 85, 247, 0.2); border: 1px solid #a855f7; color: #d8b4fe;">
                                <i class="fa-solid fa-qrcode"></i> Reconfigurar Nuevo Dispositivo
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Parámetros de Sesión y Ciberseguridad Activa -->
        <div class="sec-card" style="margin-bottom: 0;">
            <h3 style="font-size: 1.1rem; margin: 0 0 1rem 0; color: #fff;">
                <i class="fa-solid fa-server" style="color: var(--admin-accent);"></i> Blindaje de Sesión y Almacenamiento
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
                <div style="background: rgba(0,0,0,0.25); border: 1px solid var(--admin-border); border-radius: 10px; padding: 1rem;">
                    <div style="color: var(--admin-muted); font-size: 0.8rem; margin-bottom: 0.25rem;">Caducidad de Sesión por Inactividad</div>
                    <div style="font-size: 1rem; font-weight: 600; color: #4ade80;">
                        <i class="fa-regular fa-clock"></i> 30 minutos
                    </div>
                </div>
                <div style="background: rgba(0,0,0,0.25); border: 1px solid var(--admin-border); border-radius: 10px; padding: 1rem;">
                    <div style="color: var(--admin-muted); font-size: 0.8rem; margin-bottom: 0.25rem;">Algoritmo Criptográfico 2FA</div>
                    <div style="font-size: 1rem; font-weight: 600; color: #38bdf8;">
                        <i class="fa-solid fa-key"></i> RFC 6238 TOTP (SHA-1 / 30s)
                    </div>
                </div>
                <div style="background: rgba(0,0,0,0.25); border: 1px solid var(--admin-border); border-radius: 10px; padding: 1rem;">
                    <div style="color: var(--admin-muted); font-size: 0.8rem; margin-bottom: 0.25rem;">Cifrado en Reposo de Secretos</div>
                    <div style="font-size: 1rem; font-weight: 600; color: #a855f7;">
                        <i class="fa-solid fa-lock"></i> AES-256-GCM (Clave externa)
                    </div>
                </div>
                <div style="background: rgba(0,0,0,0.25); border: 1px solid var(--admin-border); border-radius: 10px; padding: 1rem;">
                    <div style="color: var(--admin-muted); font-size: 0.8rem; margin-bottom: 0.25rem;">Protección contra Fuerza Bruta</div>
                    <div style="font-size: 1rem; font-weight: 600; color: #fbbf24;">
                        <i class="fa-solid fa-shield-halved"></i> Lockout (5 intentos / 15 min)
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

</body>
</html>
