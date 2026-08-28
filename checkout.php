<?php
/**
 * Procesador de Orden y Generador de Checkout Mercado Pago
 * Turbogram
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/MercadoPago_Service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// 1. Validar Token CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    die("Sesión expirada o token no válido. Por favor volvé al inicio e intentá de nuevo.");
}

// 2. Sanitizar entradas
$service_id = (int)($_POST['service_id'] ?? 0);
$quantity   = (int)($_POST['quantity'] ?? 0);
$target_link= clean_input($_POST['target_link'] ?? '');
$buyer_email= clean_input($_POST['buyer_email'] ?? '');
$buyer_phone= clean_input($_POST['buyer_phone'] ?? '');

if ($service_id <= 0 || $quantity <= 0 || empty($target_link) || empty($buyer_email)) {
    die("Todos los campos marcados son obligatorios. Por favor volvé e ingresalos correctamente.");
}

// 3. Consultar servicio y calcular precio estricto en el Servidor
$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT * FROM services WHERE id = ? AND status = 1");
$stmt->execute([$service_id]);
$service = $stmt->fetch();

if (!$service) {
    die("El servicio seleccionado no está disponible en este momento.");
}

// Ajustar rangos de cantidad por seguridad
if ($quantity < $service['min_quantity']) $quantity = $service['min_quantity'];
if ($quantity > $service['max_quantity']) $quantity = $service['max_quantity'];

// REGLA DE SEGURIDAD: Recálculo de precio en el Servidor
$total_price = round(($quantity / 1000) * (float)$service['price_per_1000']);
if ($total_price < 1) $total_price = 1;

// 4. Generar Código de Orden Único
$order_code = generate_order_code();

// 5. Guardar la Orden en BD con estado pendiente
$stmtInsert = $pdo->prepare("
    INSERT INTO orders 
    (order_code, service_id, service_name, quantity, target_link, buyer_email, buyer_phone, total_price, mp_status, provider_status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending_send')
");
$stmtInsert->execute([
    $order_code,
    $service['id'],
    $service['name'],
    $quantity,
    $target_link,
    $buyer_email,
    $buyer_phone,
    $total_price
]);

// 6. Generar Preferencia de Pago en Mercado Pago
$mpService = new MercadoPago_Service();
$orderData = [
    'order_code'   => $order_code,
    'service_name' => $service['name'],
    'quantity'     => $quantity,
    'total_price'  => $total_price,
    'buyer_email'  => $buyer_email,
    'buyer_phone'  => $buyer_phone
];

$prefResult = $mpService->createPreference($orderData);

if ($prefResult['success']) {
    // Guardar preference_id en BD
    $stmtUpdate = $pdo->prepare("UPDATE orders SET mp_preference_id = ? WHERE order_code = ?");
    $stmtUpdate->execute([$prefResult['preference_id'], $order_code]);

    // Redirigir al cliente a Mercado Pago
    header('Location: ' . $prefResult['checkout_url']);
    exit;
} else {
    // Si Mercado Pago no está configurado (ej: primer uso antes de colocar credenciales en el Panel)
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="container" style="padding: 4rem 1.25rem; max-width: 600px;">
        <div style="background: var(--bg-card); border: 1px solid var(--border-highlight); border-radius: var(--radius-lg); padding: 2.5rem; text-align: center;">
            <div style="width: 70px; height: 70px; background: rgba(255, 0, 122, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: var(--secondary); margin: 0 auto 1.5rem auto;">
                <i class="fa-solid fa-gear"></i>
            </div>
            
            <h2 style="font-family: var(--font-heading); font-size: 1.6rem; margin-bottom: 1rem;">Orden Registrada: <?= htmlspecialchars($order_code) ?></h2>
            
            <div class="alert-warning" style="text-align: left; margin-bottom: 1.5rem;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.5rem;"></i>
                <div>
                    <strong>Aviso de Configuración:</strong><br>
                    <?= htmlspecialchars($prefResult['error']) ?>
                </div>
            </div>

            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 2rem;">
                Para completar la prueba en modo local, podés configurar el <strong>Access Token</strong> de Mercado Pago desde el <a href="admin/settings.php" style="color: var(--primary-light); text-decoration: underline;">Panel del Dueño</a> o simular la aprobación directa del pedido.
            </p>

            <!-- Botón de Simulación para entorno local -->
            <a href="status.php?code=<?= urlencode($order_code) ?>&simulate_pay=1" class="btn-submit-order" style="text-decoration: none;">
                <i class="fa-solid fa-vial"></i> Simular Pago Aprobado (Prueba Local)
            </a>
            
            <a href="index.php" style="display: inline-block; margin-top: 1rem; color: var(--text-muted); font-size: 0.9rem;">Volver al inicio</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
}
