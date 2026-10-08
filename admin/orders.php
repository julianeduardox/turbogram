<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/GenericSMM_API.php';
require_once __DIR__ . '/../includes/SolydSMM_API.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/Order_Processor.php';

$pdo = Database::getConnection();
$message = '';
$message_type = '';

// Reintento Manual de Envío a Proveedor con reserva atómica
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'retry_send') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $order_id = (int)$_POST['order_id'];

        // Comprobación de seguridad: Órdenes en 'review' NUNCA pueden ser reenviadas directamente
        $stmtChk = $pdo->prepare("SELECT provider_status FROM orders WHERE id = ?");
        $stmtChk->execute([$order_id]);
        $ordChk = $stmtChk->fetch();

        if ($ordChk && $ordChk['provider_status'] === 'review') {
            $message = "Las órdenes en revisión técnica exigen comprobación previa y reconciliación con el proveedor. No pueden reenviarse directamente.";
            $message_type = "danger";
        } else {
            $res = Order_Processor::dispatchOrderToProvider($order_id, 'admin_manual', true);

            if (!empty($res['success'])) {
                $message = "Orden despachada con éxito al proveedor. " . ($res['message'] ?? '');
                $message_type = "success";
            } else {
                $message = "No se pudo despachar la orden: " . ($res['error'] ?? 'Error desconocido');
                $message_type = "danger";
            }
        }
    }
}

// Reconciliar con Proveedor (Comprobar si el proveedor ya la recibió)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reconcile_order') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $order_id = (int)$_POST['order_id'];
        $res = Order_Processor::reconcileOrderWithProvider($order_id);
        if (!empty($res['success'])) {
            $message = "Reconciliación exitosa: " . ($res['message'] ?? '');
            $message_type = "success";
        } else {
            $message = $res['message'] ?? ($res['error'] ?? 'No se pudo reconciliar automáticamente.');
            $message_type = "warning";
        }
    }
}

// Resolver revisión con comprobación y auditoría obligatorias
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_review') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $order_id = (int)$_POST['order_id'];
        $resolve_type = clean_input($_POST['resolve_type'] ?? '');
        $audit_note = clean_input($_POST['audit_note'] ?? '');
        $verification_method = clean_input($_POST['verification_method'] ?? '');
        $verification_confirmed = !empty($_POST['verification_confirmed']);
        $manual_id = (int)($_POST['manual_provider_order_id'] ?? 0);

        $res = Order_Processor::adminConfirmAndResolveReview(
            $order_id, 
            $resolve_type, 
            $audit_note, 
            $verification_method, 
            $verification_confirmed, 
            $manual_id
        );
        if (!empty($res['success'])) {
            $message = "Revisión resuelta: " . ($res['message'] ?? '');
            $message_type = "success";
        } else {
            $message = "Error en resolución: " . ($res['error'] ?? '');
            $message_type = "danger";
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
        fputcsv($output, sanitize_csv_row([
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
        ]));
    }
    fclose($output);
    exit;
}

// Consultar Órdenes
$stmtOrders = $pdo->prepare("
    SELECT o.*, s.name as service_name, p.name as provider_name 
    FROM orders o 
    JOIN services s ON o.service_id = s.id 
    LEFT JOIN providers p ON o.provider_id = p.id 
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
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= time() ?>">
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
            <li><a href="providers.php"><i class="fa-solid fa-server"></i> Proveedores SMM</a></li>
            <li><a href="settings.php"><i class="fa-solid fa-sliders"></i> Mercado Pago y API</a></li>
            <li><a href="security.php"><i class="fa-solid fa-shield-halved"></i> Seguridad y 2FA</a></li>
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
                                        <br><small style="color: var(--admin-muted); font-size: 0.75rem;"><i class="fa-solid fa-server"></i> <?= htmlspecialchars($o['provider_name'] ?? 'Proveedor') ?></small>
                                    <?php elseif ($o['provider_status'] === 'processing'): ?>
                                        <span class="badge badge-warning" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: 1px solid #38bdf8;"><i class="fa-solid fa-spinner fa-spin"></i> Procesando</span>
                                    <?php elseif ($o['provider_status'] === 'review'): ?>
                                        <span class="badge badge-warning" style="background: rgba(251, 146, 60, 0.2); color: #fb923c; border: 1px solid #fb923c;" title="<?= htmlspecialchars($o['error_message'] ?? '') ?>"><i class="fa-solid fa-eye"></i> En Revisión</span>
                                        <?php if (!empty($o['error_message'])): ?><br><small style="color: #fb923c; font-size: 0.72rem;"><?= htmlspecialchars(substr($o['error_message'], 0, 35)) ?>...</small><?php endif; ?>
                                    <?php elseif ($o['provider_status'] === 'error'): ?>
                                        <span class="badge badge-danger" title="<?= htmlspecialchars($o['error_message'] ?? '') ?>">Error Envío</span>
                                        <?php if (!empty($o['provider_name'])): ?><br><small style="color: #fca5a5; font-size: 0.75rem;"><?= htmlspecialchars($o['provider_name']) ?></small><?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- Acciones según Estado de Proveedor -->
                                    <?php if ($o['mp_status'] === 'approved' && $o['provider_status'] === 'review'): ?>
                                        <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                                            <?php if (!empty($o['provider_order_id'])): ?>
                                                <form action="orders.php" method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="reconcile_order">
                                                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                                    <button type="submit" class="btn-admin" style="padding: 0.25rem 0.5rem; font-size: 0.72rem; background: #0284c7; width: 100%;">
                                                        <i class="fa-solid fa-magnifying-glass"></i> Consultar Estado
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <button type="button" class="btn-admin" style="padding: 0.25rem 0.5rem; font-size: 0.72rem; background: #d97706; width: 100%;" onclick="openResolveModal(<?= $o['id'] ?>, '<?= htmlspecialchars($o['order_code']) ?>')">
                                                <i class="fa-solid fa-clipboard-check"></i> Resolver Revisión
                                            </button>
                                        </div>
                                    <?php elseif ($o['mp_status'] === 'approved' && in_array($o['provider_status'], ['error', 'pending_send'], true)): ?>
                                        <form action="orders.php" method="POST" onsubmit="return confirm('¿Despachar este pedido al proveedor asignado?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="retry_send">
                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                            <button type="submit" class="btn-admin" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; background: #8a2be2;">
                                                <i class="fa-solid fa-rotate-right"></i> Despachar
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

<!-- Modal de Reconciliación y Resolución de Revisión -->
<div id="resolveModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #1e1b2e; border: 1px solid rgba(255,255,255,0.15); border-radius: 12px; max-width: 500px; width: 100%; padding: 1.5rem; color: #fff;">
        <h3 style="margin-top: 0; font-size: 1.15rem; color: #fb923c;"><i class="fa-solid fa-clipboard-check"></i> Reconciliación de Orden <span id="modalOrderCode"></span></h3>
        <p style="font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 1rem;">
            Las órdenes en revisión requieren verificación manual en el panel del proveedor para evitar duplicar pedidos.
        </p>

        <form action="orders.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="resolve_review">
            <input type="hidden" name="order_id" id="modalOrderId" value="">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; margin-bottom: 0.4rem; font-weight: 600;">Resultado de la Comprobación:</label>
                <select name="resolve_type" id="modalResolveType" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 6px; padding: 0.6rem; color: #fff;" onchange="toggleReconcileFields(this.value)">
                    <option value="confirm_not_received_and_retry">1. Comprobado: NO fue recibida por el proveedor (Autorizar Reenvío)</option>
                    <option value="confirm_received">2. Comprobado: SÍ ingresó al proveedor (Asignar ID y marcar Enviada)</option>
                </select>
            </div>

            <div id="retryVerifyWrap" style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; margin-bottom: 0.4rem; font-weight: 600;">Método de Comprobación en Proveedor:</label>
                <select name="verification_method" id="modalVerifyMethod" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 6px; padding: 0.6rem; color: #fff;">
                    <option value="dashboard_search">Búsqueda manual por enlace/usuario en panel del proveedor</option>
                    <option value="api_inquiry">Consulta directa de historial de órdenes por API</option>
                    <option value="support_ticket">Ticket de soporte confirmado con el proveedor</option>
                </select>
                <div style="margin-top: 0.6rem;">
                    <label style="display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.82rem; color: #fb923c; cursor: pointer;">
                        <input type="checkbox" name="verification_confirmed" id="modalVerifyCheck" value="1" style="margin-top: 0.2rem;">
                        <span>Confirmo formalmente que he verificado el sistema del proveedor y esta orden NO figura registrada ni procesada.</span>
                    </label>
                </div>
            </div>

            <div id="manualIdWrap" style="margin-bottom: 1rem; display: none;">
                <label style="display: block; font-size: 0.85rem; margin-bottom: 0.4rem; font-weight: 600;">ID de Orden en el Proveedor:</label>
                <input type="number" name="manual_provider_order_id" id="modalManualId" placeholder="Ej: 84920" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 6px; padding: 0.6rem; color: #fff;">
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.85rem; margin-bottom: 0.4rem; font-weight: 600;">Nota de Auditoría Obligatoria (Mínimo 15 caracteres):</label>
                <textarea name="audit_note" required minlength="15" placeholder="Ej: Comprobado en historial de SolydSMM a las 22:15 filtrando por target_link; sin registro previo." style="width: 100%; height: 75px; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 6px; padding: 0.6rem; color: #fff;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-admin" style="background: rgba(255,255,255,0.1);" onclick="closeResolveModal()">Cancelar</button>
                <button type="submit" class="btn-admin" style="background: #8a2be2;">Guardar y Ejecutar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResolveModal(id, code) {
    document.getElementById('modalOrderId').value = id;
    document.getElementById('modalOrderCode').textContent = code;
    document.getElementById('modalResolveType').value = 'confirm_not_received_and_retry';
    toggleReconcileFields('confirm_not_received_and_retry');
    document.getElementById('resolveModal').style.display = 'flex';
}
function closeResolveModal() {
    document.getElementById('resolveModal').style.display = 'none';
}
function toggleReconcileFields(val) {
    var isReceived = (val === 'confirm_received');
    document.getElementById('manualIdWrap').style.display = isReceived ? 'block' : 'none';
    document.getElementById('retryVerifyWrap').style.display = isReceived ? 'none' : 'block';
    var manualInput = document.getElementById('modalManualId');
    var verifyCheck = document.getElementById('modalVerifyCheck');
    if (manualInput) manualInput.required = isReceived;
    if (verifyCheck) verifyCheck.required = !isReceived;
}
</script>

</body>
</html>
