<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SolydSMM_API.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$pdo = Database::getConnection();

// 1. Obtener Saldo del Proveedor SolydSMM
$smmAPI = new SolydSMM_API();
$balanceResult = $smmAPI->getBalance();
$provider_balance = $balanceResult['success'] ? $balanceResult['balance'] : 0.00;
$provider_currency = $balanceResult['currency'] ?? 'USD';

// 2. Estadísticas de Ventas (Hoy, Mes, Totales)
$stmtToday = $pdo->query("SELECT SUM(total_price) as total FROM orders WHERE mp_status = 'approved' AND DATE(created_at) = CURDATE()");
$sales_today = (float)($stmtToday->fetch()['total'] ?? 0);

$stmtMonth = $pdo->query("SELECT SUM(total_price) as total FROM orders WHERE mp_status = 'approved' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$sales_month = (float)($stmtMonth->fetch()['total'] ?? 0);

$stmtOrdersCount = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN mp_status = 'approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN provider_status = 'error' THEN 1 ELSE 0 END) as errors FROM orders");
$orderStats = $stmtOrdersCount->fetch();

// 3. Órdenes Recientes
$stmtRecent = $pdo->query("
    SELECT o.*, s.name as service_name 
    FROM orders o 
    JOIN services s ON o.service_id = s.id 
    ORDER BY o.id DESC LIMIT 10
");
$recentOrders = $stmtRecent->fetchAll();
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Principal | Turbogram Panel</title>
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
    <!-- Sidebar Navigation -->
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <i class="fa-solid fa-bolt" style="color: var(--admin-accent);"></i> Turbogram Panel
        </div>

        <ul class="admin-menu">
            <li><a href="index.php" class="active"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
            <li><a href="orders.php"><i class="fa-solid fa-cart-shopping"></i> Pedidos</a></li>
            <li><a href="services.php"><i class="fa-solid fa-list-check"></i> Servicios y Precios</a></li>
            <li><a href="settings.php"><i class="fa-solid fa-sliders"></i> Mercado Pago y API</a></li>
            <li style="margin-top: auto;"><a href="logout.php" style="color: #fca5a5;"><i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesión</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="admin-main">
        <div class="admin-header">
            <div>
                <h1 class="admin-title">Panel del Dueño</h1>
                <p style="color: var(--admin-muted); font-size: 0.9rem; margin: 0;">Resumen en tiempo real de ventas y saldos</p>
            </div>
            <a href="../index.php" target="_blank" class="btn-admin" style="background: rgba(255,255,255,0.08);">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Ver Sitio Web
            </a>
        </div>

        <!-- Alerta de Saldo en Proveedor si está bajo -->
        <?php if ($provider_balance < 2.00): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 12px; padding: 1.25rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 2rem; color: #ef4444;"></i>
                    <div>
                        <strong style="color: #fca5a5; font-size: 1.05rem;">¡Atención! Saldo bajo en SolydSMM: $ <?= number_format($provider_balance, 2) ?> <?= $provider_currency ?></strong>
                        <p style="color: var(--admin-muted); font-size: 0.875rem; margin: 0.2rem 0 0 0;">Recargá tu saldo en solydsmm.com para que los nuevos pedidos pagados sigan enviándose automáticamente.</p>
                    </div>
                </div>
                <a href="https://solydsmm.com" target="_blank" class="btn-admin btn-admin-danger">Recargar Proveedor</a>
            </div>
        <?php endif; ?>

        <!-- Tarjetas de Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Saldo en SolydSMM</div>
                <div class="stat-val" style="color: #38bdf8;">
                    $ <?= number_format($provider_balance, 2) ?> <span style="font-size: 0.9rem; color: var(--admin-muted);"><?= $provider_currency ?></span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Ventas de Hoy</div>
                <div class="stat-val" style="color: #4ade80;"><?= format_price($sales_today) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Ventas del Mes</div>
                <div class="stat-val" style="color: #c084fc;"><?= format_price($sales_month) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Pedidos / Errores</div>
                <div class="stat-val">
                    <?= $orderStats['approved'] ?> <span style="font-size: 0.9rem; color: #ef4444;">(<?= $orderStats['errors'] ?> err)</span>
                </div>
            </div>
        </div>

        <!-- Tabla de Pedidos Recientes -->
        <div class="table-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="font-family: 'Outfit', sans-serif; margin: 0;">Últimos Pedidos Registrados</h3>
                <a href="orders.php" style="color: var(--admin-accent); font-size: 0.9rem; font-weight: 600; text-decoration: none;">Ver todos &rarr;</a>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Fecha</th>
                        <th>Servicio / Cantidad</th>
                        <th>Destino</th>
                        <th>Total</th>
                        <th>Pago MP</th>
                        <th>Proveedor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentOrders)): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--admin-muted); padding: 2rem;">No hay pedidos registrados aún.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentOrders as $o): ?>
                            <tr>
                                <td style="font-weight: 700; color: #fff;">
                                    <a href="orders.php?search=<?= urlencode($o['order_code']) ?>" style="color: var(--admin-accent); text-decoration: underline;">
                                        <?= htmlspecialchars($o['order_code']) ?>
                                    </a>
                                </td>
                                <td style="color: var(--admin-muted);"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                                <td>
                                    <?= htmlspecialchars($o['service_name']) ?><br>
                                    <small style="color: var(--admin-muted);">(<?= number_format($o['quantity'], 0, ',', '.') ?> un)</small>
                                </td>
                                <td style="word-break: break-all; max-width: 180px; font-size: 0.85rem;">
                                    <?= htmlspecialchars($o['target_link']) ?>
                                </td>
                                <td style="font-weight: 700; color: #4ade80;"><?= format_price($o['total_price']) ?></td>
                                <td>
                                    <?php if ($o['mp_status'] === 'approved'): ?>
                                        <span class="badge badge-success">Aprobado</span>
                                    <?php elseif ($o['mp_status'] === 'pending'): ?>
                                        <span class="badge badge-warning">Pendiente</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><?= ucfirst(htmlspecialchars($o['mp_status'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($o['provider_status'] === 'sent' || $o['provider_status'] === 'completed'): ?>
                                        <span class="badge badge-purple">Enviado (#<?= $o['provider_order_id'] ?>)</span>
                                    <?php elseif ($o['provider_status'] === 'error'): ?>
                                        <span class="badge badge-danger">Error Envío</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

</body>
</html>
