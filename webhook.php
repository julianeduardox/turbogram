<?php
/**
 * Webhook IPN de Mercado Pago - Autenticación Fail-Closed (HMAC-SHA256) y Procesamiento Centralizado
 * Turbogram
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/MercadoPago_Service.php';
require_once __DIR__ . '/includes/Order_Processor.php';

// Helper para obtener cabeceras HTTP de forma agnóstica al entorno (Apache, Nginx, CLI)
function get_webhook_header(string $name): string {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return (string)$_SERVER[$serverKey];
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return (string)$v;
            }
        }
    }
    return '';
}

// 1. Obtener identificador del recurso notificado
$rawBody = file_get_contents('php://input');
$bodyData = json_decode($rawBody, true) ?: [];

$payment_id = $_GET['data_id'] 
    ?? ($_GET['data.id'] 
    ?? ($_GET['id'] 
    ?? ($bodyData['data']['id'] ?? ($bodyData['id'] ?? null))));

if (!$payment_id) {
    http_response_code(400);
    echo "Falta ID de datos";
    exit;
}

$payment_id = (string)$payment_id;

// 2. Extraer cabeceras de firma obligatorias
$xSignature = get_webhook_header('x-signature');
$xRequestId = get_webhook_header('x-request-id');

// 3. SEGURIDAD FAIL-CLOSED: Verificar firma criptográfica ANTES de consultar APIs externas o modificar pedidos
$sigResult = MercadoPago_Service::verifyWebhookSignature($xSignature, $xRequestId, $payment_id);

if (!$sigResult['valid']) {
    log_audit('WEBHOOK_UNAUTHORIZED', "Rechazo de webhook no autenticado (ID: {$payment_id}): " . ($sigResult['error'] ?? 'Firma inválida'));
    http_response_code(401);
    echo "Unauthorized: " . ($sigResult['error'] ?? 'Firma inválida');
    exit;
}

// 4. FIRMA VÁLIDA: Consultar estado real del pago en la API de Mercado Pago
$mpService = new MercadoPago_Service();
$payment = $mpService->getPayment($payment_id);

if (!$payment['success']) {
    $isTemp = $payment['is_temporary'] ?? true;
    if ($isTemp) {
        // Fallo temporal de conexión o 5xx de Mercado Pago: responder 503 para que MP reintente
        log_audit('WEBHOOK_MP_TIMEOUT', "Fallo temporal de conexión con Mercado Pago al consultar pago {$payment_id}: " . ($payment['error'] ?? ''));
        http_response_code(503);
        echo "Temporary upstream failure, retry later";
        exit;
    } else {
        // Fallo de cliente definitivo (ej: pago no existe, 404): responder 400
        log_audit('WEBHOOK_MP_BAD_REQ', "Pago no encontrado o error cliente en Mercado Pago {$payment_id}: " . ($payment['error'] ?? ''));
        http_response_code(400);
        echo "Bad request: " . ($payment['error'] ?? 'Pago no encontrado');
        exit;
    }
}

$order_code = $payment['external_reference'] ?? '';
if (empty($order_code)) {
    log_audit('WEBHOOK_NO_REF', "Pago legítimo {$payment_id} no contiene external_reference.");
    http_response_code(200);
    echo "OK: Sin referencia de orden";
    exit;
}

// 5. Procesamiento centralizado y despacho atómico (reutilizando datos de pago prefetched)
try {
    $result = Order_Processor::verifyPaymentAndProcess($order_code, $payment_id, 'webhook', $payment);
} catch (\Throwable $e) {
    // Error temporal interno de base de datos o sistema: responder 503 para permitir reintento
    log_audit('WEBHOOK_SERVER_ERROR', "Excepción interna procesando orden {$order_code}: " . $e->getMessage());
    http_response_code(503);
    echo "Temporary server error, retry later";
    exit;
}

// 6. Evaluación rigurosa del resultado de procesamiento
if (!empty($result['success'])) {
    http_response_code(200);
    echo "OK: Procesado con éxito";
    exit;
}

if (!empty($result['status_updated'])) {
    http_response_code(200);
    echo "OK: Estado de pago actualizado a " . $result['status_updated'];
    exit;
}

if (!empty($result['is_temporary'])) {
    // Fallo temporal interno o upstream: responder 503 para reintento automático de Mercado Pago
    log_audit('WEBHOOK_TEMP_ERROR', "Fallo temporal procesando orden {$order_code} con pago {$payment_id}: " . ($result['error'] ?? ''));
    http_response_code(503);
    echo "Temporary processing error, retry later";
    exit;
}

// Fallo de validación definitivo (ej. estado no aprobado, moneda ajena, importe discordante):
// Respondemos 200 confirmando recepción para evitar bucles de reintentos infinitos sin sentido.
log_audit('WEBHOOK_NON_RETRYABLE', "Notificación procesada sin despacho (definitivo no reintentable) para orden {$order_code}: " . ($result['error'] ?? ''));
http_response_code(200);
echo "OK: " . ($result['error'] ?? 'Completado');
