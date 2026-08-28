<?php
/**
 * Validador AJAX de Cupones de Descuento en Tiempo Real
 * Turbogram
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$raw_code   = trim($_POST['coupon_code'] ?? '');
$service_id = (int)($_POST['service_id'] ?? 0);
$quantity   = (int)($_POST['quantity'] ?? 0);

if (empty($raw_code)) {
    echo json_encode(['success' => false, 'error' => 'Por favor ingresá un código de cupón.']);
    exit;
}

$code = strtoupper(clean_input($raw_code));
$pdo = Database::getConnection();

// 1. Obtener datos del servicio para calcular el precio original real
$stmtServ = $pdo->prepare("SELECT * FROM services WHERE id = ? AND status = 1");
$stmtServ->execute([$service_id]);
$service = $stmtServ->fetch();

if (!$service) {
    echo json_encode(['success' => false, 'error' => 'Seleccioná un servicio válido para aplicar el cupón.']);
    exit;
}

// Validar rangos de cantidad
if ($quantity < $service['min_quantity']) $quantity = $service['min_quantity'];
if ($quantity > $service['max_quantity']) $quantity = $service['max_quantity'];

// Calcular precio original
$original_price = round(($quantity / 1000) * (float)$service['price_per_1000']);
if ($original_price < 1) $original_price = 1;

// 2. Consultar el cupón en la base de datos
$stmtCoupon = $pdo->prepare("SELECT * FROM coupons WHERE code = ?");
$stmtCoupon->execute([$code]);
$coupon = $stmtCoupon->fetch();

if (!$coupon) {
    echo json_encode([
        'success' => false, 
        'error'   => "El cupón '{$code}' no existe o no es válido."
    ]);
    exit;
}

// 3. Validar estado activo
if ((int)$coupon['status'] !== 1) {
    echo json_encode([
        'success' => false, 
        'error'   => "La promoción '{$code}' no se encuentra activa en este momento."
    ]);
    exit;
}

// 4. Validar fecha de expiración
if (!empty($coupon['expires_at'])) {
    $expires_timestamp = strtotime($coupon['expires_at']);
    if ($expires_timestamp < time()) {
        echo json_encode([
            'success' => false, 
            'error'   => "Esta oferta por tiempo limitado expiró el " . date('d/m/Y H:i', $expires_timestamp) . "."
        ]);
        exit;
    }
}

// 5. Validar límite de usos máximos
if ((int)$coupon['max_uses'] > 0 && (int)$coupon['times_used'] >= (int)$coupon['max_uses']) {
    echo json_encode([
        'success' => false, 
        'error'   => "Este cupón ha alcanzado el límite máximo de usos disponibles."
    ]);
    exit;
}

// 6. Validar monto mínimo de compra
if ((float)$coupon['min_order_amount'] > 0 && $original_price < (float)$coupon['min_order_amount']) {
    echo json_encode([
        'success' => false, 
        'error'   => "El cupón requiere una compra mínima de " . format_price($coupon['min_order_amount']) . ". Tu total actual es " . format_price($original_price) . "."
    ]);
    exit;
}

// 7. Calcular importe del descuento
$discount_amount = 0.0;
if ($coupon['discount_type'] === 'percentage') {
    $percent = (float)$coupon['discount_value'];
    $discount_amount = round(($original_price * $percent) / 100);
} else {
    // Monto fijo
    $discount_amount = min($original_price, (float)$coupon['discount_value']);
}

// Regla de seguridad: el descuento no puede superar el precio original (mínimo a pagar $1)
$final_price = max(1, $original_price - $discount_amount);
$actual_discount = $original_price - $final_price;

$msgDiscountText = $coupon['discount_type'] === 'percentage' 
    ? (float)$coupon['discount_value'] . '% OFF' 
    : format_price($actual_discount) . ' OFF';

echo json_encode([
    'success'            => true,
    'code'               => $coupon['code'],
    'title'              => $coupon['title'],
    'discount_type'      => $coupon['discount_type'],
    'discount_value'     => (float)$coupon['discount_value'],
    'discount_amount'    => $actual_discount,
    'original_price'     => $original_price,
    'final_price'        => $final_price,
    'formatted_discount' => '- ' . format_price($actual_discount),
    'formatted_original' => format_price($original_price),
    'formatted_final'    => format_price($final_price),
    'message'            => "¡Cupón {$coupon['code']} aplicado! Ahorrás " . format_price($actual_discount) . " ({$msgDiscountText})"
]);
