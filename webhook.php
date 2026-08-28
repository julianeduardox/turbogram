<?php
/**
 * Webhook IPN de Mercado Pago - Procesamiento Automático Anti-Exploit
 * Turbogram
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/MercadoPago_Service.php';
require_once __DIR__ . '/includes/SolydSMM_API.php';

// Mercado Pago envía notificaciones por GET o POST
$payment_id = $_GET['data_id'] ?? ($_GET['id'] ?? null);

if (!$payment_id) {
    // Intentar leer payload JSON en el body
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    $payment_id = $data['data']['id'] ?? ($data['id'] ?? null);
}

if (!$payment_id) {
    http_response_code(400);
    echo "Falta ID de Pago";
    exit;
}

// 1. Consultar estado real del pago directamente en la API de Mercado Pago
$mpService = new MercadoPago_Service();
$payment = $mpService->getPayment((string)$payment_id);

if (!$payment['success']) {
    log_audit('WEBHOOK_MP_ERROR', 'No se pudo verificar el pago ID ' . $payment_id . ': ' . ($payment['error'] ?? ''));
    http_response_code(200); // Responder 200 a MP para evitar reintentos infinitos si la credencial falló
    echo "No se pudo consultar el pago";
    exit;
}

$mp_status = $payment['status'];
$order_code = $payment['external_reference'];

if (empty($order_code)) {
    log_audit('WEBHOOK_NO_REF', 'Pago ID ' . $payment_id . ' no tiene external_reference.');
    http_response_code(200);
    echo "OK sin referencia";
    exit;
}

// 2. Buscar la Orden en la Base de Datos
$pdo = Database::getConnection();
$stmt = $pdo->prepare("
    SELECT o.*, s.provider_service_id 
    FROM orders o 
    JOIN services s ON o.service_id = s.id 
    WHERE o.order_code = ?
");
$stmt->execute([$order_code]);
$order = $stmt->fetch();

if (!$order) {
    log_audit('WEBHOOK_ORDER_NOT_FOUND', 'Código de orden no existe: ' . $order_code);
    http_response_code(200);
    echo "Orden no encontrada";
    exit;
}

// 3. MEDIDA ANTI-EXPLOIT: Verificar si el pago o envío ya fue procesado anteriormente
if ($order['mp_status'] === 'approved' && in_array($order['provider_status'], ['sent', 'completed', 'in_progress'])) {
    log_audit('WEBHOOK_DUPLICATE_PREVENTED', 'Intento de procesamiento duplicado bloqueado para la orden: ' . $order_code);
    http_response_code(200);
    echo "Orden ya procesada anteriormente";
    exit;
}

// 4. Actualizar Estado de Pago
if ($mp_status === 'approved') {
    $stmtUpdate = $pdo->prepare("UPDATE orders SET mp_status = 'approved', mp_payment_id = ? WHERE id = ?");
    $stmtUpdate->execute([$payment_id, $order['id']]);

    log_audit('PAYMENT_APPROVED', 'Pago aprobado por MP para la orden ' . $order_code . ' (MP ID: ' . $payment_id . ')');

    // 5. ENVIAR AUTOMÁTICAMENTE AL PROVEEDOR (SolydSMM)
    $smmAPI = new SolydSMM_API();
    $sendResult = $smmAPI->addOrder(
        (int)$order['provider_service_id'],
        $order['target_link'],
        (int)$order['quantity']
    );

    if ($sendResult['success']) {
        $stmtOrderSuccess = $pdo->prepare("
            UPDATE orders 
            SET provider_order_id = ?, provider_status = 'sent', provider_response = ?, error_message = NULL 
            WHERE id = ?
        ");
        $stmtOrderSuccess->execute([
            $sendResult['order_id'],
            $sendResult['raw'],
            $order['id']
        ]);

        log_audit('PROVIDER_ORDER_SENT', 'Orden ' . $order_code . ' enviada con éxito a SolydSMM. ID Proveedor: ' . $sendResult['order_id']);
    } else {
        // En caso de falla al enviar al proveedor (ej: saldo bajo en solydsmm)
        $stmtOrderError = $pdo->prepare("
            UPDATE orders 
            SET provider_status = 'error', error_message = ?, provider_response = ? 
            WHERE id = ?
        ");
        $stmtOrderError->execute([
            $sendResult['error'],
            $sendResult['raw'] ?? null,
            $order['id']
        ]);

        log_audit('PROVIDER_ORDER_FAILED', 'Error al enviar orden ' . $order_code . ' a SolydSMM: ' . $sendResult['error']);
    }
} else {
    // Pago rechazado, pendiente o cancelado
    $stmtUpdateFail = $pdo->prepare("UPDATE orders SET mp_status = ?, mp_payment_id = ? WHERE id = ?");
    $stmtUpdateFail->execute([$mp_status, $payment_id, $order['id']]);

    log_audit('PAYMENT_STATUS_UPDATE', 'Estado de pago para ' . $order_code . ' actualizado a: ' . $mp_status);
}

http_response_code(200);
echo "OK";
