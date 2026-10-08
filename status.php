<?php
/**
 * Consulta de Estado del Pedido y Confirmación de Compra
 * Turbogram
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/GenericSMM_API.php';
require_once __DIR__ . '/includes/SolydSMM_API.php';
require_once __DIR__ . '/includes/MercadoPago_Service.php';
require_once __DIR__ . '/includes/Order_Processor.php';

$order_code = clean_input($_GET['code'] ?? ($_GET['external_reference'] ?? ($_POST['order_code'] ?? '')));
$order = null;
$error_msg = null;

$pdo = Database::getConnection();

// 1. Soporte para Retorno Real de Mercado Pago (validación y despacho centralizados)
$returned_payment_id = clean_input($_GET['payment_id'] ?? ($_GET['collection_id'] ?? ''));

if ($order_code && !empty($returned_payment_id) && empty($_GET['simulate_pay'])) {
    Order_Processor::verifyPaymentAndProcess($order_code, $returned_payment_id, 'user_return');
}

// 2. Simulación de Pago Segura (Exclusiva de desarrollo explícito; fail-closed absoluto en producción)
if (isset($_GET['simulate_pay']) && $_GET['simulate_pay'] == '1') {
    if (getenv('APP_ENV') !== 'development') {
        if (function_exists('log_audit')) {
            log_audit('SIMULATION_PAY_BLOCKED', 'Intento no autorizado de simulación de pago en entorno no-desarrollo para orden: ' . ($order_code ?: 'N/A'));
        }
    } elseif (!empty($order_code)) {
        $stmtFind = $pdo->prepare("SELECT id, mp_status FROM orders WHERE order_code = ?");
        $stmtFind->execute([$order_code]);
        $simOrder = $stmtFind->fetch();

        if ($simOrder && $simOrder['mp_status'] !== 'approved') {
            $pdo->prepare("
                UPDATE orders 
                SET mp_status = 'approved', 
                    mp_payment_id = 'SIMULATED-DEV-TEST', 
                    provider_status = 'completed', 
                    provider_order_id = 999999, 
                    provider_response = 'Prueba local simulada sin despacho a proveedores reales' 
                WHERE id = ?
            ")->execute([$simOrder['id']]);
            if (function_exists('log_audit')) {
                log_audit('SIMULATION_DEV_PAY', 'Simulación de prueba ejecutada en entorno local para: ' . $order_code);
            }
        }
    }
}

// Consultar Orden
if (!empty($order_code)) {
    $stmt = $pdo->prepare("
        SELECT o.*, s.name as service_name, c.name as category_name, c.icon as cat_icon 
        FROM orders o 
        JOIN services s ON o.service_id = s.id 
        JOIN categories c ON s.category_id = c.id 
        WHERE o.order_code = ?
    ");
    $stmt->execute([$order_code]);
    $order = $stmt->fetch();

    if (!$order) {
        $error_msg = "No se encontró ningún pedido registrado con el código " . htmlspecialchars($order_code);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container status-page-wrap">
    
    <!-- Formulario de Búsqueda -->
    <div style="max-width: 600px; margin: 0 auto 2.5rem auto; text-align: center;">
        <h1 style="font-family: var(--font-heading); font-size: clamp(1.6rem, 5vw, 2.2rem); margin-bottom: 0.75rem;">
            Consultar <span style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Estado de Pedido</span>
        </h1>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem;">
            Ingresá tu número único de pedido (ej: <code>TURBO-8F92A1</code>) para conocer el progreso en tiempo real.
        </p>

        <form action="status.php" method="GET" class="status-search-form">
            <input type="text" name="code" class="form-input" placeholder="Código (ej: TURBO-XXXXXX)" value="<?= htmlspecialchars($order_code) ?>" required style="text-transform: uppercase;">
            <button type="submit" class="btn-submit-order" style="width: auto; padding: 0 1.5rem; white-space: nowrap;">
                <i class="fa-solid fa-magnifying-glass"></i> Buscar
            </button>
        </form>
    </div>

    <?php if ($error_msg): ?>
        <div style="max-width: 600px; margin: 0 auto; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: var(--radius-md); padding: 1.5rem; text-align: center; color: #fca5a5;">
            <i class="fa-solid fa-circle-exclamation" style="font-size: 2rem; margin-bottom: 0.75rem;"></i>
            <p><?= htmlspecialchars($error_msg) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($order): ?>
        <div class="status-card">
            
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1.25rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <span style="font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase;">Número de Orden</span>
                    <h2 style="font-family: var(--font-heading); font-size: 1.8rem; color: var(--primary-light); margin-top: 0.2rem;">
                        <?= htmlspecialchars($order['order_code']) ?>
                    </h2>
                </div>

                <div>
                    <?php if ($order['mp_status'] === 'approved'): ?>
                        <span style="background: rgba(34, 197, 94, 0.2); border: 1px solid #22c55e; color: #4ade80; padding: 0.5rem 1rem; border-radius: var(--radius-full); font-weight: 700; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                            <i class="fa-solid fa-circle-check"></i> Pago Aprobado
                        </span>
                    <?php elseif ($order['mp_status'] === 'pending'): ?>
                        <span style="background: rgba(234, 179, 8, 0.2); border: 1px solid #eab308; color: #fef08a; padding: 0.5rem 1rem; border-radius: var(--radius-full); font-weight: 700; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                            <i class="fa-solid fa-clock"></i> Pendiente de Pago
                        </span>
                    <?php else: ?>
                        <span style="background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; color: #fca5a5; padding: 0.5rem 1rem; border-radius: var(--radius-full); font-weight: 700; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                            <i class="fa-solid fa-circle-xmark"></i> Pago <?= ucfirst(htmlspecialchars($order['mp_status'])) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detalles del Pedido -->
            <div class="status-details-grid">
                <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 1rem; border-radius: var(--radius-md);">
                    <div style="font-size: 0.8rem; color: var(--text-muted);">Servicio Contratado</div>
                    <div style="font-weight: 700; margin-top: 0.25rem;">
                        <i class="<?= htmlspecialchars($order['cat_icon']) ?>" style="color: var(--primary-light); margin-right: 0.3rem;"></i>
                        <?= htmlspecialchars($order['service_name']) ?>
                    </div>
                </div>

                <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 1rem; border-radius: var(--radius-md);">
                    <div style="font-size: 0.8rem; color: var(--text-muted);">Cantidad</div>
                    <div style="font-weight: 700; margin-top: 0.25rem;">
                        <?= number_format($order['quantity'], 0, ',', '.') ?> unidades
                    </div>
                </div>

                <div class="span-2" style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 1rem; border-radius: var(--radius-md); grid-column: span 2;">
                    <div style="font-size: 0.8rem; color: var(--text-muted);">Perfil / Publicación Destinatario</div>
                    <div style="font-weight: 700; margin-top: 0.25rem; word-break: break-all; color: var(--accent-cyan);">
                        <?= htmlspecialchars($order['target_link']) ?>
                    </div>
                </div>
            </div>

            <div class="summary-item" style="border-top: 1px dashed var(--border-color); padding-top: 1rem; margin-bottom: 1.5rem;">
                <span class="label" style="font-size: 1.05rem;">Monto Total Cobrado:</span>
                <span class="value" style="font-size: 1.4rem; color: var(--secondary); font-weight: 900;">
                    <?= format_price($order['total_price']) ?>
                </span>
            </div>

            <!-- Estado de Entrega -->
            <div style="background: rgba(138, 43, 226, 0.1); border: 1px solid var(--border-highlight); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                    <div>
                        <div style="font-size: 0.85rem; color: var(--text-muted);">Estado del Envío:</div>
                        <div style="font-weight: 800; font-size: 1.1rem; color: #fff; margin-top: 0.2rem;">
                            <?php if ($order['provider_status'] === 'sent' || $order['provider_status'] === 'in_progress'): ?>
                                <i class="fa-solid fa-spinner fa-spin" style="color: var(--primary-light);"></i> En ejecución por el sistema
                            <?php elseif ($order['provider_status'] === 'completed'): ?>
                                <i class="fa-solid fa-circle-check" style="color: #22c55e;"></i> Completado con éxito
                            <?php elseif ($order['provider_status'] === 'processing'): ?>
                                <i class="fa-solid fa-gear fa-spin" style="color: var(--accent-cyan);"></i> Procesando envío...
                            <?php elseif ($order['provider_status'] === 'review'): ?>
                                <i class="fa-solid fa-clock-rotate-left" style="color: #38bdf8;"></i> En verificación de entrega
                            <?php elseif ($order['provider_status'] === 'error'): ?>
                                <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i> En revisión técnica
                            <?php else: ?>
                                En espera de confirmación de pago
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($order['provider_order_id']): ?>
                        <div style="font-size: 0.8rem; color: var(--text-dim); text-align: right;">
                            Ref. Envío: #<?= $order['provider_order_id'] ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Soporte directo por WhatsApp -->
            <?php
            $whatsapp_num = Settings::get('whatsapp_number', '5492364321999');
            $clean_wp = preg_replace('/[^0-9]/', '', $whatsapp_num);
            $msg = urlencode("Hola! Tengo una consulta sobre mi pedido número " . $order['order_code']);
            ?>
            <a href="https://wa.me/<?= $clean_wp ?>?text=<?= $msg ?>" target="_blank" class="btn-submit-order" style="background: #25d366; text-decoration: none; box-shadow: 0 8px 25px rgba(37, 211, 102, 0.3);">
                <i class="fa-brands fa-whatsapp" style="font-size: 1.3rem;"></i> Consultar por WhatsApp sobre esta orden
            </a>

        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
