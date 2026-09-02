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

// Guardar o Editar Servicio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_service') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $id                  = (int)($_POST['service_id'] ?? 0);
        $category_id         = (int)$_POST['category_id'];
        $provider_service_id = (int)$_POST['provider_service_id'];
        $name                = clean_input($_POST['name']);
        $platform            = clean_input($_POST['platform']);
        $price_per_1000      = (float)$_POST['price_per_1000'];
        $min_quantity        = (int)$_POST['min_quantity'];
        $max_quantity        = (int)$_POST['max_quantity'];
        $placeholder         = clean_input($_POST['input_placeholder']);
        $description         = clean_input($_POST['description']);
        $status              = isset($_POST['status']) ? 1 : 0;

        if ($id > 0) {
            $stmtUpd = $pdo->prepare("
                UPDATE services 
                SET category_id=?, provider_service_id=?, name=?, platform=?, price_per_1000=?, min_quantity=?, max_quantity=?, input_placeholder=?, description=?, status=? 
                WHERE id=?
            ");
            $stmtUpd->execute([$category_id, $provider_service_id, $name, $platform, $price_per_1000, $min_quantity, $max_quantity, $placeholder, $description, $status, $id]);
            $message = "Servicio actualizado correctamente.";
        } else {
            $stmtIns = $pdo->prepare("
                INSERT INTO services 
                (category_id, provider_service_id, name, platform, price_per_1000, min_quantity, max_quantity, input_placeholder, description, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([$category_id, $provider_service_id, $name, $platform, $price_per_1000, $min_quantity, $max_quantity, $placeholder, $description, $status]);
            $message = "Nuevo servicio agregado correctamente.";
        }
        log_audit('SAVE_SERVICE', 'Servicio guardado: ' . $name);
    }
}

// Consultar Categorías
$stmtCats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC");
$categories = $stmtCats->fetchAll();

// Consultar Servicios
$stmtServices = $pdo->query("
    SELECT s.*, c.name as category_name 
    FROM services s 
    JOIN categories c ON s.category_id = c.id 
    ORDER BY c.sort_order ASC, s.sort_order ASC
");
$services = $stmtServices->fetchAll();

$editService = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmtE = $pdo->prepare("SELECT * FROM services WHERE id = ?");
    $stmtE->execute([$edit_id]);
    $editService = $stmtE->fetch();
}
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Servicios | Turbogram Panel</title>
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
            <li><a href="orders.php"><i class="fa-solid fa-cart-shopping"></i> Pedidos</a></li>
            <li><a href="services.php" class="active"><i class="fa-solid fa-list-check"></i> Servicios y Precios</a></li>
            <li><a href="promotions.php"><i class="fa-solid fa-tags"></i> Ofertas y Cupones</a></li>
            <li><a href="settings.php"><i class="fa-solid fa-sliders"></i> Mercado Pago y API</a></li>
            <li style="margin-top: auto;"><a href="logout.php" style="color: #fca5a5;"><i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesión</a></li>
        </ul>
    </aside>

    <main class="admin-main">
        <div class="admin-header">
            <div>
                <h1 class="admin-title">Servicios y Catálogo de Precios</h1>
                <p style="color: var(--admin-muted); font-size: 0.9rem; margin: 0;">Configurá precios en ARS por cada 1.000 unidades y conectá con los IDs del proveedor</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid #22c55e; border-radius: 8px; padding: 1rem; color: #4ade80; margin-bottom: 1.5rem;">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Formulario de Agregar / Editar -->
        <div class="table-card" style="margin-bottom: 2rem;">
            <h3 style="font-family: 'Outfit', sans-serif; margin-top: 0; margin-bottom: 1.25rem;">
                <?= $editService ? 'Editar Servicio: ' . htmlspecialchars($editService['name']) : 'Agregar Nuevo Servicio' ?>
            </h3>

            <form action="services.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save_service">
                <input type="hidden" name="service_id" value="<?= $editService['id'] ?? 0 ?>">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Categoría</label>
                        <select name="category_id" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= ($editService['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">ID Servicio SolydSMM</label>
                        <input type="number" name="provider_service_id" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= $editService['provider_service_id'] ?? '' ?>" placeholder="Ej: 494" required>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Plataforma</label>
                        <input type="text" name="platform" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= $editService['platform'] ?? 'instagram' ?>" placeholder="instagram, tiktok, etc." required>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Precio de Venta por 1.000 (ARS)</label>
                        <input type="number" step="10" name="price_per_1000" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= $editService['price_per_1000'] ?? '3500' ?>" placeholder="Ej: 3500" required>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Mínimo Cantidad</label>
                        <input type="number" name="min_quantity" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= $editService['min_quantity'] ?? '100' ?>" required>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Máximo Cantidad</label>
                        <input type="number" name="max_quantity" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= $editService['max_quantity'] ?? '50000' ?>" required>
                    </div>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Nombre Público del Servicio</label>
                    <input type="text" name="name" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($editService['name'] ?? '') ?>" placeholder="Ej: Seguidores Instagram HQ Rápido" required>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Placeholder del Input Destino</label>
                    <input type="text" name="input_placeholder" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff;" value="<?= htmlspecialchars($editService['input_placeholder'] ?? '') ?>" placeholder="Ej: https://instagram.com/tu_usuario o @usuario">
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; color: var(--admin-muted); margin-bottom: 0.3rem;">Descripción Pública</label>
                    <textarea name="description" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--admin-border); border-radius: 8px; padding: 0.75rem; color: #fff; height: 70px;"><?= htmlspecialchars($editService['description'] ?? '') ?></textarea>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <label style="color: #fff; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="status" value="1" <?= ($editService['status'] ?? 1) == 1 ? 'checked' : '' ?>>
                        <span>Servicio Activo en la web</span>
                    </label>

                    <button type="submit" class="btn-admin">
                        <i class="fa-solid fa-floppy-disk"></i> <?= $editService ? 'Guardar Cambios' : 'Crear Servicio' ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Lista de Servicios Registrados -->
        <div class="table-card">
            <h3 style="font-family: 'Outfit', sans-serif; margin-top: 0; margin-bottom: 1rem;">Catálogo Activo</h3>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID SolydSMM</th>
                        <th>Categoría</th>
                        <th>Nombre del Servicio</th>
                        <th>Precio / 1.000 ARS</th>
                        <th>Min / Max</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $s): ?>
                        <tr>
                            <td><strong style="color: var(--admin-accent);">#<?= $s['provider_service_id'] ?></strong></td>
                            <td><?= htmlspecialchars($s['category_name']) ?></td>
                            <td style="color: #fff; font-weight: 600;"><?= htmlspecialchars($s['name']) ?></td>
                            <td style="color: #4ade80; font-weight: 700;"><?= format_price($s['price_per_1000']) ?></td>
                            <td style="color: var(--admin-muted);"><?= number_format($s['min_quantity'], 0, ',', '.') ?> - <?= number_format($s['max_quantity'], 0, ',', '.') ?></td>
                            <td>
                                <?php if ($s['status'] == 1): ?>
                                    <span class="badge badge-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="services.php?edit=<?= $s['id'] ?>" class="btn-admin" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;">
                                    <i class="fa-solid fa-pen-to-square"></i> Editar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

</body>
</html>
