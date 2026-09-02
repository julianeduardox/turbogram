<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$pdo = Database::getConnection();
$message = '';
$message_type = 'success';

// 1. Procesar Guardar / Editar Cupón
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_coupon') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $id               = (int)($_POST['coupon_id'] ?? 0);
        $code             = strtoupper(trim(clean_input($_POST['code'] ?? '')));
        $title            = clean_input($_POST['title'] ?? '');
        $discount_type    = in_array($_POST['discount_type'] ?? '', ['percentage', 'fixed']) ? $_POST['discount_type'] : 'percentage';
        $discount_value   = (float)($_POST['discount_value'] ?? 0);
        $min_order_amount = (float)($_POST['min_order_amount'] ?? 0);
        $max_uses         = (int)($_POST['max_uses'] ?? 0);
        $expires_at       = !empty($_POST['expires_at']) ? date('Y-m-d H:i:s', strtotime($_POST['expires_at'])) : null;
        $show_banner      = isset($_POST['show_banner']) ? 1 : 0;
        $banner_text      = clean_input($_POST['banner_text'] ?? '');
        $status           = isset($_POST['status']) ? 1 : 0;

        if (empty($code)) {
            $message = "El código del cupón no puede estar vacío.";
            $message_type = 'danger';
        } elseif ($discount_value <= 0) {
            $message = "El valor del descuento debe ser mayor a 0.";
            $message_type = 'danger';
        } else {
            if ($id > 0) {
                // Actualizar cupón existente
                $stmtUpd = $pdo->prepare("
                    UPDATE coupons 
                    SET code = ?, title = ?, discount_type = ?, discount_value = ?, min_order_amount = ?, max_uses = ?, expires_at = ?, show_banner = ?, banner_text = ?, status = ? 
                    WHERE id = ?
                ");
                $stmtUpd->execute([$code, $title, $discount_type, $discount_value, $min_order_amount, $max_uses, $expires_at, $show_banner, $banner_text, $status, $id]);
                $message = "Cupón <strong>" . htmlspecialchars($code) . "</strong> actualizado correctamente.";
                log_audit('UPDATE_COUPON', 'Cupón actualizado: ' . $code);
            } else {
                // Insertar nuevo cupón
                try {
                    $stmtIns = $pdo->prepare("
                        INSERT INTO coupons 
                        (code, title, discount_type, discount_value, min_order_amount, max_uses, expires_at, show_banner, banner_text, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([$code, $title, $discount_type, $discount_value, $min_order_amount, $max_uses, $expires_at, $show_banner, $banner_text, $status]);
                    $message = "Nuevo cupón <strong>" . htmlspecialchars($code) . "</strong> creado con éxito. Ya podés enviárselo a tus clientes.";
                    log_audit('CREATE_COUPON', 'Cupón creado: ' . $code);
                } catch (PDOException $e) {
                    $message = "El código de cupón '{$code}' ya existe. Elegí o generá uno diferente.";
                    $message_type = 'danger';
                }
            }
        }
    }
}

// 2. Acción Rápida: Toggle Activo/Inactivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $id = (int)$_POST['coupon_id'];
        $stmt = $pdo->prepare("UPDATE coupons SET status = IF(status = 1, 0, 1) WHERE id = ?");
        $stmt->execute([$id]);
        
        $stmtGet = $pdo->prepare("SELECT code, status FROM coupons WHERE id = ?");
        $stmtGet->execute([$id]);
        $cData = $stmtGet->fetch();
        $stText = ($cData && $cData['status'] == 1) ? '<span style="color: #4ade80;">ACTIVADO</span>' : '<span style="color: #fca5a5;">DESACTIVADO</span>';
        $message = "El cupón <strong>" . htmlspecialchars($cData['code'] ?? '') . "</strong> fue {$stText}.";
    }
}

// 3. Acción Rápida: Toggle Banner Anuncio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_banner') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $id = (int)$_POST['coupon_id'];
        $stmt = $pdo->prepare("UPDATE coupons SET show_banner = IF(show_banner = 1, 0, 1) WHERE id = ?");
        $stmt->execute([$id]);
        
        $stmtGet = $pdo->prepare("SELECT code, show_banner FROM coupons WHERE id = ?");
        $stmtGet->execute([$id]);
        $cData = $stmtGet->fetch();
        $bnText = ($cData && $cData['show_banner'] == 1) ? '<span style="color: #4ade80;">VISIBLE en la web</span>' : '<span style="color: #fca5a5;">OCULTO de la web</span>';
        $message = "El banner del cupón <strong>" . htmlspecialchars($cData['code'] ?? '') . "</strong> ahora está {$bnText}.";
    }
}

// 4. Acción Eliminar Cupón
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_coupon') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $id = (int)$_POST['coupon_id'];
        $stmt = $pdo->prepare("DELETE FROM coupons WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Cupón eliminado del sistema.";
        log_audit('DELETE_COUPON', 'Cupón ID ' . $id . ' eliminado.');
    }
}

// Consultar cupón para editar si está en URL
$editCoupon = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmtE = $pdo->prepare("SELECT * FROM coupons WHERE id = ?");
    $stmtE->execute([$edit_id]);
    $editCoupon = $stmtE->fetch();
}

// Estadísticas
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total_coupons,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_coupons,
        SUM(CASE WHEN show_banner = 1 AND status = 1 THEN 1 ELSE 0 END) as active_banners,
        SUM(times_used) as total_uses
    FROM coupons
");
$stats = $statsStmt->fetch();

// Total dinero descontado histórico
$discountTotalStmt = $pdo->query("SELECT SUM(discount_amount) as total_saved FROM orders WHERE discount_amount > 0 AND mp_status = 'approved'");
$totalSaved = (float)($discountTotalStmt->fetch()['total_saved'] ?? 0);

// Consultar todos los cupones
$stmtAll = $pdo->query("SELECT * FROM coupons ORDER BY status DESC, id DESC");
$coupons = $stmtAll->fetchAll();
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Cupones y Ofertas | Turbogram Panel</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="192x192" href="../assets/img/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Outfit:wght@600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= time() ?>">
    <style>
        .generator-pill {
            background: rgba(138, 43, 226, 0.15);
            border: 1px solid rgba(138, 43, 226, 0.35);
            color: #d8b4fe;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .generator-pill:hover {
            background: rgba(138, 43, 226, 0.35);
            color: #fff;
            transform: translateY(-1px);
        }
        .toast-notification {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: #22c55e;
            color: #000;
            font-weight: 700;
            padding: 0.85rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            display: none;
            z-index: 9999;
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
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
            <li><a href="promotions.php" class="active"><i class="fa-solid fa-tags"></i> Ofertas y Cupones</a></li>
            <li><a href="settings.php"><i class="fa-solid fa-sliders"></i> Mercado Pago y API</a></li>
            <li style="margin-top: auto;"><a href="logout.php" style="color: #fca5a5;"><i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesión</a></li>
        </ul>
    </aside>

    <main class="admin-main">
        <div class="admin-header">
            <div>
                <h1 class="admin-title">Generador de Cupones y Ofertas</h1>
                <p style="color: var(--admin-muted); font-size: 0.9rem; margin: 0;">Generá cupones de descuento para enviar a tus clientes por WhatsApp/Redes o activá ofertas temporales</p>
            </div>
            <?php if ($editCoupon): ?>
                <a href="promotions.php" class="btn-admin" style="background: rgba(255,255,255,0.1);">
                    <i class="fa-solid fa-plus"></i> Crear Nuevo Cupón
                </a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div style="background: <?= $message_type === 'success' ? 'rgba(34, 197, 94, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; border: 1px solid <?= $message_type === 'success' ? '#22c55e' : '#ef4444' ?>; border-radius: 8px; padding: 1rem; color: <?= $message_type === 'success' ? '#4ade80' : '#fca5a5' ?>; margin-bottom: 1.5rem;">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Tarjetas de Estadísticas de Promociones -->
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-label">Cupones Activos</div>
                <div class="stat-val" style="color: #4ade80;"><?= (int)$stats['active_coupons'] ?> <span style="font-size: 0.85rem; color: var(--admin-muted);">/ <?= (int)$stats['total_coupons'] ?></span></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Banners de Oferta en Web</div>
                <div class="stat-val" style="color: #38bdf8;"><?= (int)$stats['active_banners'] ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Veces Usados</div>
                <div class="stat-val" style="color: #c084fc;"><?= (int)$stats['total_uses'] ?> veces</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Ahorrado en Ventas</div>
                <div class="stat-val" style="color: #fbbf24;"><?= format_price($totalSaved) ?></div>
            </div>
        </div>

        <!-- Formulario de Crear / Editar Cupón con Generador Automático -->
        <div class="table-card" style="margin-bottom: 2rem;">
            <h3 style="font-family: 'Outfit', sans-serif; margin-top: 0; margin-bottom: 1.25rem; color: #fff;">
                <i class="fa-solid <?= $editCoupon ? 'fa-pen-to-square' : 'fa-wand-magic-sparkles' ?>" style="color: var(--admin-accent); margin-right: 0.4rem;"></i>
                <?= $editCoupon ? 'Editar Promoción / Cupón: <span style="color: #38bdf8;">' . htmlspecialchars($editCoupon['code']) . '</span>' : 'Generador y Creador de Cupones' ?>
            </h3>

            <form action="promotions.php" method="POST" id="couponForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save_coupon">
                <input type="hidden" name="coupon_id" value="<?= $editCoupon['id'] ?? 0 ?>">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                    
                    <!-- Campo Código + Generador Automático -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.3rem;">
                            <label style="font-size: 0.85rem; color: var(--admin-muted); font-weight: 600;">Código del Cupón</label>
                            <button type="button" onclick="generateRandomCode()" class="generator-pill" title="Generar un código aleatorio al instante">
                                <i class="fa-solid fa-dice"></i> Generar
                            </button>
                        </div>
                        <div style="display: flex; gap: 0.4rem;">
                            <input type="text" name="code" id="inputCode" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff; font-weight: 800; text-transform: uppercase; font-family: monospace; font-size: 1.05rem; letter-spacing: 0.5px;" value="<?= htmlspecialchars($editCoupon['code'] ?? '') ?>" placeholder="EJ: TURBO-2026" required>
                        </div>
                        <!-- Prefijos rápidos -->
                        <div style="display: flex; gap: 0.3rem; margin-top: 0.4rem; flex-wrap: wrap;">
                            <span style="font-size: 0.75rem; color: var(--admin-muted); margin-right: 0.2rem;">Prefijos:</span>
                            <span class="generator-pill" onclick="generatePrefixedCode('TURBO-')">TURBO-</span>
                            <span class="generator-pill" onclick="generatePrefixedCode('PROMO-')">PROMO-</span>
                            <span class="generator-pill" onclick="generatePrefixedCode('VIP-')">VIP-</span>
                            <span class="generator-pill" onclick="generatePrefixedCode('REGALO-')">REGALO-</span>
                        </div>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Título Descriptivo</label>
                        <input type="text" name="title" id="inputTitle" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($editCoupon['title'] ?? '') ?>" placeholder="Ej: Descuento 20% Especial Clientes" required>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Tipo de Descuento</label>
                        <select name="discount_type" id="inputDiscountType" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" required onchange="updateTitleSuggestion()">
                            <option value="percentage" <?= ($editCoupon['discount_type'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>>Porcentaje (%)</option>
                            <option value="fixed" <?= ($editCoupon['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Monto Fijo en Pesos ($ ARS)</option>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Valor del Descuento</label>
                        <input type="number" step="0.5" min="0.5" name="discount_value" id="inputDiscountValue" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff; font-weight: 700;" value="<?= htmlspecialchars($editCoupon['discount_value'] ?? '20') ?>" placeholder="20" required oninput="updateTitleSuggestion()">
                        <small style="color: var(--admin-muted); font-size: 0.75rem;">Si es porcentaje poner ej: 20 (para 20%). Si es fijo poner ej: 1000 ($1.000 ARS).</small>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Monto Mínimo de Pedido (ARS)</label>
                        <input type="number" step="100" min="0" name="min_order_amount" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($editCoupon['min_order_amount'] ?? '0') ?>" placeholder="0 para sin mínimo">
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Límite de Usos Máximos</label>
                        <input type="number" min="0" name="max_uses" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($editCoupon['max_uses'] ?? '0') ?>" placeholder="0 para ilimitado">
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem; font-weight: 600;">Fecha y Hora Límite de Expiración</label>
                        <?php 
                        $expValue = '';
                        if (!empty($editCoupon['expires_at'])) {
                            $expValue = date('Y-m-d\TH:i', strtotime($editCoupon['expires_at']));
                        }
                        ?>
                        <input type="datetime-local" name="expires_at" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= $expValue ?>">
                        <small style="color: var(--admin-muted); font-size: 0.75rem;">Dejar vacío si el cupón nunca vence.</small>
                    </div>
                </div>

                <!-- Configuración del Banner Anunciador Opcional -->
                <div style="background: rgba(138, 43, 226, 0.08); border: 1px dashed var(--admin-border); border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                        <label style="color: #fff; font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                            <input type="checkbox" name="show_banner" value="1" <?= ($editCoupon['show_banner'] ?? 0) == 1 ? 'checked' : '' ?>>
                            <span><i class="fa-solid fa-bullhorn" style="color: #38bdf8;"></i> Mostrar Banner Público en la Web (Opcional - por defecto desactivado para cupones privados)</span>
                        </label>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Texto del Banner de Oferta (Opcional - solo el anuncio general, sin revelar el código privado)</label>
                        <input type="text" name="banner_text" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($editCoupon['banner_text'] ?? '') ?>" placeholder="Ej: 🔥 ¡OFERTA ESPECIAL! Descuentos exclusivos por tiempo limitado">
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <label style="color: #fff; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="status" value="1" <?= ($editCoupon['status'] ?? 1) == 1 ? 'checked' : '' ?>>
                        <span><strong>Cupón Activo</strong> (habilitado para que los clientes lo canjeen)</span>
                    </label>

                    <button type="submit" class="btn-admin" style="padding: 0.85rem 2rem; font-size: 1rem;">
                        <i class="fa-solid fa-floppy-disk"></i> <?= $editCoupon ? 'Guardar Cambios' : 'Crear y Habilitar Cupón' ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabla de Cupones Existentes con Botón de Compartir a Clientes -->
        <div class="table-card">
            <h3 style="font-family: 'Outfit', sans-serif; margin-top: 0; margin-bottom: 1rem;">Cupones Creados y Listos para Enviar</h3>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Código / Título</th>
                        <th>Descuento</th>
                        <th>Vencimiento / Tiempo</th>
                        <th>Usos</th>
                        <th>Banner Web</th>
                        <th>Estado</th>
                        <th>Enviar al Cliente</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($coupons)): ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--admin-muted); padding: 2rem;">No hay cupones creados aún. Creá el primero arriba con el generador.</td></tr>
                    <?php else: ?>
                        <?php foreach ($coupons as $c): ?>
                            <?php 
                            $isExpired = false;
                            $expText = 'Sin vencimiento';
                            if (!empty($c['expires_at'])) {
                                $expTime = strtotime($c['expires_at']);
                                if ($expTime < time()) {
                                    $isExpired = true;
                                    $expText = '<span style="color: #ef4444; font-weight: 600;"><i class="fa-solid fa-clock-rotate-left"></i> Vencido (' . date('d/m/Y H:i', $expTime) . ')</span>';
                                } else {
                                    $diff = $expTime - time();
                                    $days = floor($diff / 86400);
                                    $hours = floor(($diff % 86400) / 3600);
                                    $expText = '<span style="color: #38bdf8;">' . date('d/m/Y H:i', $expTime) . '</span><br><small style="color: var(--admin-muted);">' . ($days > 0 ? "{$days}d {$hours}h restantes" : "{$hours}h restantes") . '</small>';
                                }
                            }
                            $discountLabel = $c['discount_type'] === 'percentage' ? ((float)$c['discount_value'] . '% OFF') : (format_price($c['discount_value']) . ' OFF');
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: #fff; font-size: 1.1rem; font-family: monospace; letter-spacing: 0.5px;"><?= htmlspecialchars($c['code']) ?></strong>
                                    <br><small style="color: var(--admin-muted);"><?= htmlspecialchars($c['title']) ?></small>
                                </td>
                                <td>
                                    <?php if ($c['discount_type'] === 'percentage'): ?>
                                        <span class="badge badge-purple" style="font-size: 0.85rem; font-weight: 800;"><?= (float)$c['discount_value'] ?>% OFF</span>
                                    <?php else: ?>
                                        <span class="badge badge-success" style="font-size: 0.85rem; font-weight: 800;"><?= format_price($c['discount_value']) ?> OFF</span>
                                    <?php endif; ?>
                                    <?php if ($c['min_order_amount'] > 0): ?>
                                        <br><small style="color: var(--admin-muted);">Min: <?= format_price($c['min_order_amount']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $expText ?>
                                </td>
                                <td>
                                    <strong style="color: #fff;"><?= (int)$c['times_used'] ?></strong>
                                    <span style="color: var(--admin-muted);">/ <?= $c['max_uses'] > 0 ? (int)$c['max_uses'] : '∞' ?></span>
                                </td>
                                <td>
                                    <!-- Toggle Banner Rápido -->
                                    <form action="promotions.php" method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="toggle_banner">
                                        <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                                        <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0;" title="Clic para alternar visibilidad del banner en la web">
                                            <?php if ($c['show_banner'] == 1 && $c['status'] == 1 && !$isExpired): ?>
                                                <span class="badge badge-success"><i class="fa-solid fa-eye"></i> Visible</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger"><i class="fa-solid fa-eye-slash"></i> Oculto</span>
                                            <?php endif; ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <!-- Toggle Estado Activo Rápido -->
                                    <form action="promotions.php" method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                                        <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0;" title="Clic para activar o desactivar este cupón">
                                            <?php if ($c['status'] == 1 && !$isExpired): ?>
                                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Activo</span>
                                            <?php elseif ($isExpired): ?>
                                                <span class="badge badge-danger"><i class="fa-solid fa-clock"></i> Expirado</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger"><i class="fa-solid fa-ban"></i> Inactivo</span>
                                            <?php endif; ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <!-- Botón Copiar Mensaje para WhatsApp / Cliente -->
                                    <button type="button" class="btn-admin" style="background: #25D366; color: #000; font-weight: 700; padding: 0.4rem 0.8rem; font-size: 0.8rem;" onclick="copyClientMessage('<?= htmlspecialchars($c['code']) ?>', '<?= htmlspecialchars($discountLabel) ?>', '<?= !empty($c['expires_at']) ? date('d/m/Y H:i', strtotime($c['expires_at'])) : '' ?>')">
                                        <i class="fa-brands fa-whatsapp"></i> Copiar Mensaje
                                    </button>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.4rem;">
                                        <a href="promotions.php?edit=<?= $c['id'] ?>" class="btn-admin" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" title="Editar">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </a>

                                        <form action="promotions.php" method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar este cupón?');" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete_coupon">
                                            <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn-admin btn-admin-danger" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" title="Eliminar">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<!-- Toast de Notificación Flotante -->
<div id="toastNotification" class="toast-notification">
    <i class="fa-solid fa-circle-check"></i> <span id="toastText">¡Mensaje copiado al portapapeles!</span>
</div>

<script>
/**
 * Genera un código aleatorio único (Ej: TURBO-7392)
 */
function generateRandomCode() {
    const prefixes = ['TURBO-', 'PROMO-', 'VIP-', 'REGALO-', 'FLASH-'];
    const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
    const num = Math.floor(1000 + Math.random() * 9000);
    const code = prefix + num;
    document.getElementById('inputCode').value = code;
    updateTitleSuggestion();
}

/**
 * Genera un código con un prefijo específico
 */
function generatePrefixedCode(prefix) {
    const num = Math.floor(1000 + Math.random() * 9000);
    document.getElementById('inputCode').value = prefix + num;
    updateTitleSuggestion();
}

/**
 * Sugerencia automática del título
 */
function updateTitleSuggestion() {
    const code = document.getElementById('inputCode').value.trim();
    const type = document.getElementById('inputDiscountType').value;
    const val = document.getElementById('inputDiscountValue').value.trim();
    const titleInput = document.getElementById('inputTitle');
    
    if (code && val && (!titleInput.value || titleInput.value.startsWith('Descuento'))) {
        const discountStr = type === 'percentage' ? (val + '% OFF') : ('$' + val + ' OFF');
        titleInput.value = 'Descuento ' + discountStr + ' (' + code + ')';
    }
}

/**
 * Copia un mensaje formateado y listo para WhatsApp para enviárselo al cliente
 */
function copyClientMessage(code, discountLabel, expires) {
    let msg = `🔥 *¡Tu Cupón de Descuento en Turbogram!* 🚀\n\n`;
    msg += `Te regalamos un cupón especial para potenciar tus redes sociales:\n\n`;
    msg += `🎟️ Código: *${code}*\n`;
    msg += `💰 Descuento: *${discountLabel}*\n`;
    if (expires) {
        msg += `⏳ Válido hasta: *${expires}*\n`;
    }
    msg += `\n🛒 Canjealo directamente al hacer tu pedido en:\n👉 https://turbogram.site`;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(msg).then(() => {
            showToast(`¡Mensaje del cupón ${code} copiado para WhatsApp!`);
        });
    } else {
        const temp = document.createElement('textarea');
        temp.value = msg;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        showToast(`¡Mensaje del cupón ${code} copiado para WhatsApp!`);
    }
}

function showToast(text) {
    const toast = document.getElementById('toastNotification');
    const toastText = document.getElementById('toastText');
    if (toast && toastText) {
        toastText.textContent = text;
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3500);
    }
}
</script>

</body>
</html>
