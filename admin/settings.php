<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        
        // Mercado Pago
        Settings::set('mp_access_token', trim($_POST['mp_access_token'] ?? ''));
        Settings::set('mp_public_key', trim($_POST['mp_public_key'] ?? ''));
        Settings::set('mp_sandbox', isset($_POST['mp_sandbox']) ? '1' : '0');

        // Proveedor API
        Settings::set('provider_api_url', trim($_POST['provider_api_url'] ?? 'https://solydsmm.com/api/v2'));
        Settings::set('provider_api_key', trim($_POST['provider_api_key'] ?? ''));

        // Generales
        Settings::set('site_name', trim($_POST['site_name'] ?? 'Turbogram'));
        Settings::set('site_tagline', trim($_POST['site_tagline'] ?? ''));
        Settings::set('whatsapp_number', trim($_POST['whatsapp_number'] ?? ''));

        $message = "Configuraciones guardadas correctamente.";
        log_audit('SAVE_SETTINGS', 'Actualización de credenciales y configuraciones generales del sitio.');
    }
}

$mp_access_token = Settings::get('mp_access_token', '');
$mp_public_key   = Settings::get('mp_public_key', '');
$mp_sandbox      = Settings::get('mp_sandbox', '1');
$provider_url    = Settings::get('provider_api_url', 'https://solydsmm.com/api/v2');
$provider_key    = Settings::get('provider_api_key', '');
$site_name       = Settings::get('site_name', 'Turbogram');
$site_tagline    = Settings::get('site_tagline', 'Impulsá tus redes sociales en segundos');
$whatsapp_num    = Settings::get('whatsapp_number', '5492364321999');
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Sistema | Turbogram Panel</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="192x192" href="../assets/img/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Outfit:wght@600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">

<div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <i class="fa-solid fa-bolt" style="color: var(--admin-accent);"></i> Turbogram Panel
        </div>
        <ul class="admin-menu">
            <li><a href="index.php"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
            <li><a href="orders.php"><i class="fa-solid fa-cart-shopping"></i> Pedidos</a></li>
            <li><a href="services.php"><i class="fa-solid fa-list-check"></i> Servicios y Precios</a></li>
            <li><a href="promotions.php"><i class="fa-solid fa-tags"></i> Ofertas y Cupones</a></li>
            <li><a href="settings.php" class="active"><i class="fa-solid fa-sliders"></i> Mercado Pago y API</a></li>
            <li style="margin-top: auto;"><a href="logout.php" style="color: #fca5a5;"><i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesión</a></li>
        </ul>
    </aside>

    <main class="admin-main">
        <div class="admin-header">
            <div>
                <h1 class="admin-title">Configuración de Pasarela y API</h1>
                <p style="color: var(--admin-muted); font-size: 0.9rem; margin: 0;">Administrá tus credenciales de Mercado Pago, clave de proveedor y datos generales</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid #22c55e; border-radius: 8px; padding: 1rem; color: #4ade80; margin-bottom: 1.5rem;">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form action="settings.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_settings">

            <!-- 1. Mercado Pago -->
            <div class="table-card" style="margin-bottom: 2rem;">
                <h3 style="font-family: 'Outfit', sans-serif; margin-top: 0; margin-bottom: 1.25rem; color: #38bdf8;">
                    <i class="fa-solid fa-hand-holding-dollar"></i> Credenciales Mercado Pago
                </h3>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Access Token (Producción o Pruebas)</label>
                    <input type="password" name="mp_access_token" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff; font-family: monospace;" value="<?= htmlspecialchars($mp_access_token) ?>" placeholder="APP_USR-xxxxxxxxxxxxxxxxxxxxxxxxxx">
                    <small style="color: var(--admin-muted); font-size: 0.75rem;">Se obtiene en Developers Mercado Pago &gt; Tus Integraciones &gt; Credenciales de producción.</small>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Public Key</label>
                    <input type="text" name="mp_public_key" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff; font-family: monospace;" value="<?= htmlspecialchars($mp_public_key) ?>" placeholder="APP_USR-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                </div>

                <label style="color: #fff; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="mp_sandbox" value="1" <?= $mp_sandbox == '1' ? 'checked' : '' ?>>
                    <span>Activar Modo Sandbox / Pruebas de Mercado Pago</span>
                </label>
            </div>

            <!-- 2. Proveedor SMM -->
            <div class="table-card" style="margin-bottom: 2rem;">
                <h3 style="font-family: 'Outfit', sans-serif; margin-top: 0; margin-bottom: 1.25rem; color: #c084fc;">
                    <i class="fa-solid fa-server"></i> Integración con Proveedor (SolydSMM)
                </h3>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">URL API del Proveedor</label>
                    <input type="text" name="provider_api_url" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($provider_url) ?>" required>
                </div>

                <div>
                    <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">API Key del Proveedor</label>
                    <input type="password" name="provider_api_key" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff; font-family: monospace;" value="<?= htmlspecialchars($provider_key) ?>" required>
                </div>
            </div>

            <!-- 3. Configuraciones Generales -->
            <div class="table-card" style="margin-bottom: 2rem;">
                <h3 style="font-family: 'Outfit', sans-serif; margin-top: 0; margin-bottom: 1.25rem; color: #4ade80;">
                    <i class="fa-solid fa-globe"></i> Datos Generales de la Web
                </h3>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Nombre del Sitio</label>
                        <input type="text" name="site_name" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($site_name) ?>" required>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Teléfono de WhatsApp Soporte</label>
                        <input type="text" name="whatsapp_number" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($whatsapp_num) ?>" placeholder="Ej: 5492364321999" required>
                    </div>
                </div>

                <div>
                    <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Eslogan Principal</label>
                    <input type="text" name="site_tagline" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($site_tagline) ?>">
                </div>
            </div>

            <button type="submit" class="btn-admin" style="padding: 0.9rem 2rem; font-size: 1rem;">
                <i class="fa-solid fa-floppy-disk"></i> Guardar Configuraciones
            </button>
        </form>
    </main>
</div>

</body>
</html>
