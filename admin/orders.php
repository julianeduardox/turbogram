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
$message = '';
$message_type = '';

// Reintento Manual de Envío a SolydSMM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'retry_send') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $order_id = (int)$_POST['order_id'];
        $stmtOrder = $pdo->prepare("SELECT o.*, s.provider_service_id FROM orders o JOIN services s ON o.service_id = s.id WHERE o.id = ?");
        $stmtOrder->execute([$order_id]);
        $orderToRetry = $stmtOrder->fetch();

        if ($orderToRetry) {
            $api = new SolydSMM_API();
            $res = $api->addOrder(
                (int)$orderToRetry['provider_service_id'],
                $orderToRetry['target_link'],
                (int)$orderToRetry['quantity']
            );

            if ($res['success']) {
                $stmtUpd = $pdo->prepare("UPDATE orders SET provider_order_id = ?, provider_status = 'sent', provider_response = ?, error_message = NULL WHERE id = ?");
                $stmtUpd->execute([$res['order_id'], $res['raw'], $order_id]);

                $message = "Orden " . htmlspecialchars($orderToRetry['order_code']) . " reenviada con éxito al proveedor. ID Asignado: " . $res['order_id'];
                $message_type = "success";
                log_audit('MANUAL_RETRY_SUCCESS', 'Reintento exitoso para orden: ' . $orderToRetry['order_code']);
            } else {
                $stmtUpd = $pdo->prepare("UPDATE orders SET provider_status = 'error', error_message = ?, provider_response = ? WHERE id = ?");
                $stmtUpd->execute([$res['error'], $res['raw'] ?? null, $order_id]);

                $message = "Falla al reenviar orden " . htmlspecialchars($orderToRetry['order_code']) . ": " . $res['error'];
                $message_type = "danger";
                log_audit('MANUAL_RETRY_FAILED', 'Falla en reintento para orden: ' . $orderToRetry['order_code'] . ' Error: ' . $res['error']);
            }
        }
    }
}

// Filtros y Búsqueda
$search = clean_input($_GET['search'] ?? '');
$mp_filter = clean_input($_GET['mp_status'] ?? '');
$provider_filter = clean_input($_GET['provider_status'] ?? '');

$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(o.order_code LIKE ? OR o.buyer_email LIKE ? OR o.target_link LIKE ? OR o.mp_payment_id LIKE ?)";
    $term = "%$search%";
    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
}

if (!empty($mp_filter)) {
    $where[] = "o.mp_status = ?";
    $params[] = $mp_filter;
}

if (!empty($provider_filter)) {
    $where[] = "o.provider_status = ?";
    $params[] = $provider_filter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Exportación CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=turbogram_ventas_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Codigo', 'Fecha', 'Servicio', 'Cantidad', 'Destino', 'Email', 'Telefono', 'Total ARS', 'Estado Pago MP', 'ID Pago MP', 'ID Proveedor', 'Estado Proveedor']);

    $stmtCsv = $pdo->prepare("SELECT o.*, s.name as service_name FROM orders o JOIN services s ON o.service_id = s.id $whereClause ORDER BY o.id DESC");
    $stmtCsv->execute($params);
    while ($row = $stmtCsv->fetch()) {
        fputcsv($output, [
            $row['order_code'],
            $row['created_at'],
            $row['service_name'],
            $row['quantity'],
            $row['target_link'],
            $row['buyer_email'],
            $row['buyer_phone'],
            $row['total_price'],
            $row['mp_status'],
            $row['mp_payment_id'],
            $row['provider_order_id'],
            $row['provider_status']
        ]);
    }
    fclose($output);
    exit;
}

// Consultar Órdenes
$stmtOrders = $pdo->prepare("
    SELECT o.*, s.name as service_name 
    FROM orders o 
    JOIN services s ON o.service_id = s.id 
    $whereClause 
    ORDER BY o.id DESC
");
$stmtOrders->execute($params);
$orders = $stmtOrders->fetchAll();
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Pedidos | Turbogram Panel</title>
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
            <li><a href="orders.php" class="active"><i class="fa-solid fa-cart-shopping"></i> Pedidos</a></li>
            <li><a href="services.php"><i class="fa-solid fa-list-check"></i> Servicios y Precios</a></li>
            <li><a href="promotions.php"><i class="fa-solid fa-tags"></i> Ofertas y Cupones</a></li>
            <li><a href="settings.php"><i class="fa-solid fa-sliders"></i> Mercado Pago y API</a></li>
            <li style="margin-top: auto;"><a href="logout.php" style="color: #fca5a5;"><i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesión</a></li>
        </ul>
    </aside>

    <main class="admin-main">
        <div class="admin-header">
            <div>
                <h1 class="admin-title">Gestión de Pedidos</h1>
                <p style="color: var(--admin-muted); font-size: 0.9rem; margin: 0;">Búsqueda, auditoría y reenvíos manuales</p>
            </div>
            <a href="orders.php?export=csv<?= !empty($search) ? '&search='.urlencode($search) : '' ?>" class="btn-admin" style="background: #22c55e;">
                <i class="fa-solid fa-file-csv"></i> Exportar a CSV
            </a>
        </div>

        <?php if ($message): ?>
            <div style="background: <?= $message_type === 'success' ? 'rgba(34, 197, 94, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; border: 1px solid <?= $message_type === 'success' ? '#22c55e' : '#ef4444' ?>; border-radius: 8px; padding: 1rem; color: <?= $message_type === 'success' ? '#4ade80' : '#fca5a5' ?>; margin-bottom: 1.5rem;">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Formulario de Filtro y Búsqueda -->
        <div class="table-card" style="padding: 1.25rem; margin-bottom: 1.5rem;">
            <form action="orders.php" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <input type="text" name="search" style="flex: 2; min-width: 200px; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.7rem 1rem; color: #fff;" placeholder="Buscar código, email, usuario o ID MP..." value="<?= htmlspecialchars($search) ?>">

                <select name="mp_status" style="flex: 1; min-width: 150px; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.7rem; color: #fff;">
                    <option value="">-- Estado Pago MP --</option>
                    <option value="approved" <?= $mp_filter === 'approved' ? 'selected' : '' ?>>Aprobado</option>
                    <option value="pending" <?= $mp_filter === 'pending' ? 'selected' : '' ?>>Pendiente</option>
                    <option value="rejected" <?= $mp_filter === 'rejected' ? 'selected' : '' ?>>Rechazado</option>
                </select>

                <select name="provider_status" style="flex: 1; min-width: 150px; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.7rem; color: #fff;">
                    <option value="">-- Estado Proveedor --</option>
                    <option value="sent" <?= $provider_filter === 'sent' ? 'selected' : '' ?>>Enviado</option>
                    <option value="error" <?= $provider_filter === 'error' ? 'selected' : '' ?>>Con Error</option>
                    <option value="pending_send" <?= $provider_filter === 'pending_send' ? 'selected' : '' ?>>Pendiente Envío</option>
                </select>

                <button type="submit" class="btn-admin"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </form>
        </div>

        <!-- Tabla de Pedidos -->
        <div class="table-card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Código / Fecha</th>
                        <th>Cliente / Contacto</th>
                        <th>Servicio / Cantidad</th>
                        <th>Destino</th>
                        <th>Total</th>
                        <th>Pago MP</th>
                        <th>Envío Proveedor</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--admin-muted); padding: 2rem;">No se encontraron pedidos con los filtros aplicados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td>
                                    <strong style="color: #fff; display: block;"><?= htmlspecialchars($o['order_code']) ?></strong>
                                    <small style="color: var(--admin-muted);"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></small>
                                </td>
                                <td>
                                    <span style="display: block; color: #fff; font-size: 0.85rem;"><?= htmlspecialchars($o['buyer_email']) ?></span>
                                    <small style="color: var(--admin-muted);"><?= htmlspecialchars($o['buyer_phone']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($o['service_name']) ?><br>
                                    <small style="color: var(--admin-muted);">(<?= number_format($o['quantity'], 0, ',', '.') ?> un)</small>
                                </td>
                                <td style="word-break: break-all; max-width: 180px; font-size: 0.85rem; color: var(--admin-accent);">
                                    <?= htmlspecialchars($o['target_link']) ?>
                                </td>
                                <td style="font-weight: 700; color: #4ade80;">
                                    <?= format_price($o['total_price']) ?>
                                    <?php if (!empty($o['coupon_code'])): ?>
                                        <br><span class="badge badge-purple" style="font-size: 0.75rem; margin-top: 0.2rem;" title="Descuento: <?= format_price($o['discount_amount']) ?>"><i class="fa-solid fa-tag"></i> <?= htmlspecialchars($o['coupon_code']) ?> (-<?= format_price($o['discount_amount']) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($o['mp_status'] === 'approved'): ?>
                                        <span class="badge badge-success">Aprobado</span>
                                        <?php if ($o['mp_payment_id']): ?><br><small style="color: var(--admin-muted);">ID: <?= htmlspecialchars($o['mp_payment_id']) ?></small><?php endif; ?>
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
                                        <span class="badge badge-danger" title="<?= htmlspecialchars($o['error_message'] ?? '') ?>">Error Envío</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- Botón Reintentar Envío Manual -->
                                    <?php if ($o['mp_status'] === 'approved' && ($o['provider_status'] === 'error' || $o['provider_status'] === 'pending_send')): ?>
                                        <form action="orders.php" method="POST" onsubmit="return confirm('¿Reenviar este pedido a SolydSMM?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="retry_send">
                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                            <button type="submit" class="btn-admin" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; background: #8a2be2;">
                                                <i class="fa-solid fa-rotate-right"></i> Reenviar
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: var(--admin-muted); font-size: 0.8rem;">-</span>
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
