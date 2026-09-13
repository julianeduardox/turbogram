<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/GenericSMM_API.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$pdo = Database::getConnection();
$message = '';
$message_type = 'success';

// 1. Guardar o Editar Proveedor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_provider') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $id         = (int)($_POST['provider_id'] ?? 0);
        $name       = clean_input($_POST['name'] ?? '');
        $api_url    = trim($_POST['api_url'] ?? '');
        $api_key    = trim($_POST['api_key'] ?? '');
        $status     = isset($_POST['status']) ? 1 : 0;
        $is_default = isset($_POST['is_default']) ? 1 : 0;

        if (empty($name) || empty($api_url) || empty($api_key)) {
            $message = "Por favor completá todos los campos obligatorios (Nombre, URL de API y API Key).";
            $message_type = 'danger';
        } else {
            if ($id > 0) {
                // Actualizar
                $stmtUpd = $pdo->prepare("
                    UPDATE providers 
                    SET name = ?, api_url = ?, api_key = ?, status = ? 
                    WHERE id = ?
                ");
                $stmtUpd->execute([$name, $api_url, $api_key, $status, $id]);
                $targetId = $id;
                $message = "Proveedor \"{$name}\" actualizado correctamente.";
            } else {
                // Insertar
                $stmtIns = $pdo->prepare("
                    INSERT INTO providers (name, api_url, api_key, status, is_default) 
                    VALUES (?, ?, ?, ?, 0)
                ");
                $stmtIns->execute([$name, $api_url, $api_key, $status]);
                $targetId = (int)$pdo->lastInsertId();
                $message = "Nuevo proveedor \"{$name}\" agregado con éxito.";
            }

            // Si se marcó como predeterminado
            if ($is_default == 1) {
                $pdo->exec("UPDATE providers SET is_default = 0");
                $pdo->prepare("UPDATE providers SET is_default = 1, status = 1 WHERE id = ?")->execute([$targetId]);
                Settings::set('provider_api_url', $api_url);
                Settings::set('provider_api_key', $api_key);
            }

            // Test de saldo automático opcional al guardar
            $testApi = new GenericSMM_API($targetId);
            $bal = $testApi->getBalance();
            if ($bal['success']) {
                $message .= " Conexión verificada: Saldo disponible $" . number_format($bal['balance'], 4) . " " . $bal['currency'];
            }

            log_audit('SAVE_PROVIDER', 'Proveedor guardado: ' . $name . ' (ID: ' . $targetId . ')');
        }
    }
}

// 2. Establecer como Predeterminado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_default') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $provider_id = (int)$_POST['provider_id'];
        $stmtP = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
        $stmtP->execute([$provider_id]);
        $prov = $stmtP->fetch();

        if ($prov) {
            $pdo->exec("UPDATE providers SET is_default = 0");
            $pdo->prepare("UPDATE providers SET is_default = 1, status = 1 WHERE id = ?")->execute([$provider_id]);

            // Sincronizar en settings generales por compatibilidad
            Settings::set('provider_api_url', $prov['api_url']);
            Settings::set('provider_api_key', $prov['api_key']);

            $message = "El proveedor \"{$prov['name']}\" ahora es el PREDETERMINADO del sistema.";
            log_audit('SET_DEFAULT_PROVIDER', 'Proveedor predeterminado cambiado a: ' . $prov['name']);
        }
    }
}

// 3. Activar / Desactivar Proveedor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $provider_id = (int)$_POST['provider_id'];
        $stmtP = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
        $stmtP->execute([$provider_id]);
        $prov = $stmtP->fetch();

        if ($prov) {
            $newStatus = $prov['status'] == 1 ? 0 : 1;
            // No permitir desactivar el predeterminado
            if ($prov['is_default'] == 1 && $newStatus == 0) {
                $message = "No podés desactivar el proveedor predeterminado. Marcá otro como predeterminado antes.";
                $message_type = 'danger';
            } else {
                $pdo->prepare("UPDATE providers SET status = ? WHERE id = ?")->execute([$newStatus, $provider_id]);
                $message = "Estado del proveedor \"{$prov['name']}\" actualizado.";
                log_audit('TOGGLE_PROVIDER_STATUS', 'Estado cambiado para proveedor: ' . $prov['name']);
            }
        }
    }
}

// 4. Asignar TODOS los servicios del catálogo a este proveedor en 1 clic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_all_services') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $provider_id = (int)$_POST['provider_id'];
        $stmtP = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
        $stmtP->execute([$provider_id]);
        $prov = $stmtP->fetch();

        if ($prov) {
            $stmtUpdAll = $pdo->prepare("UPDATE services SET provider_id = ?");
            $stmtUpdAll->execute([$provider_id]);
            $count = $stmtUpdAll->rowCount();

            $message = "¡Éxito! Se asignaron todos los {$count} servicios del catálogo al proveedor \"{$prov['name']}\". Recordá verificar que los IDs de servicio coincidan con este proveedor.";
            log_audit('ASSIGN_ALL_SERVICES_PROVIDER', 'Todos los servicios asignados al proveedor: ' . $prov['name']);
        }
    }
}

// 5. Comprobar Conexión y Saldo en Vivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_balance') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $provider_id = (int)$_POST['provider_id'];
        $api = new GenericSMM_API($provider_id);
        $res = $api->getBalance();

        if ($res['success']) {
            $message = "Conexión exitosa con \"" . $api->getProviderName() . "\". Saldo disponible: $" . number_format($res['balance'], 4) . " " . ($res['currency'] ?? 'USD');
            $message_type = 'success';
        } else {
            $message = "Error al conectar con \"" . $api->getProviderName() . "\": " . ($res['error'] ?? 'Respuesta inválida');
            $message_type = 'danger';
        }
    }
}

// 6. Eliminar Proveedor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_provider') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $provider_id = (int)$_POST['provider_id'];
        $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM providers")->fetchColumn();

        if ($totalCount <= 1) {
            $message = "No podés eliminar el único proveedor registrado en el sistema.";
            $message_type = 'danger';
        } else {
            $stmtP = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
            $stmtP->execute([$provider_id]);
            $prov = $stmtP->fetch();

            if ($prov && $prov['is_default'] == 1) {
                $message = "No podés eliminar el proveedor predeterminado. Marcá otro como predeterminado primero.";
                $message_type = 'danger';
            } elseif ($prov) {
                // Reasignar servicios huérfanos al predeterminado
                $defaultId = (int)$pdo->query("SELECT id FROM providers WHERE is_default = 1 LIMIT 1")->fetchColumn();
                $pdo->prepare("UPDATE services SET provider_id = ? WHERE provider_id = ?")->execute([$defaultId, $provider_id]);
                $pdo->prepare("DELETE FROM providers WHERE id = ?")->execute([$provider_id]);

                $message = "Proveedor \"{$prov['name']}\" eliminado. Los servicios vinculados se transfirieron al proveedor predeterminado.";
                log_audit('DELETE_PROVIDER', 'Proveedor eliminado: ' . $prov['name']);
            }
        }
    }
}

// Consultar Proveedores y cantidad de servicios asignados a cada uno
$stmtProviders = $pdo->query("
    SELECT p.*, COUNT(s.id) as services_count 
    FROM providers p 
    LEFT JOIN services s ON p.id = s.provider_id 
    GROUP BY p.id 
    ORDER BY p.is_default DESC, p.id ASC
");
$providers = $stmtProviders->fetchAll();

// Si se va a editar un proveedor
$editProvider = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmtE = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
    $stmtE->execute([$edit_id]);
    $editProvider = $stmtE->fetch();
}
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proveedores SMM | Turbogram Panel</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="192x192" href="../assets/img/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Outfit:wght@600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= time() ?>">
    <style>
        .provider-card {
            background: var(--admin-card);
            border: 1px solid var(--admin-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.25s ease;
            position: relative;
        }
        .provider-card.is-default {
            border-color: rgba(168, 85, 247, 0.6);
            box-shadow: 0 0 20px rgba(138, 43, 226, 0.15);
        }
        .provider-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .provider-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .provider-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
            font-size: 0.85rem;
        }
        .provider-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .key-mask {
            font-family: monospace;
            background: rgba(0,0,0,0.3);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            color: #cbd5e1;
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
            <li><a href="promotions.php"><i class="fa-solid fa-tags"></i> Ofertas y Cupones</a></li>
            <li><a href="providers.php" class="active"><i class="fa-solid fa-server"></i> Proveedores SMM</a></li>
            <li><a href="settings.php"><i class="fa-solid fa-sliders"></i> Mercado Pago y Ajustes</a></li>
            <li style="margin-top: auto;"><a href="logout.php" style="color: #fca5a5;"><i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesión</a></li>
        </ul>
    </aside>

    <main class="admin-main">
        <div class="admin-header">
            <div>
                <h1 class="admin-title">Gestor Multi-Proveedor SMM</h1>
                <p style="color: var(--admin-muted); font-size: 0.9rem; margin: 0;">Cargá múltiples proveedores de servicios con su API Key y cambiá de proveedor en 1 clic</p>
            </div>
            <?php if ($editProvider): ?>
                <a href="providers.php" class="btn-admin" style="background: rgba(255,255,255,0.1);">
                    <i class="fa-solid fa-plus"></i> Agregar Nuevo Proveedor
                </a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div style="background: <?= $message_type === 'success' ? 'rgba(34, 197, 94, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; border: 1px solid <?= $message_type === 'success' ? '#22c55e' : '#ef4444' ?>; border-radius: 8px; padding: 1rem; color: <?= $message_type === 'success' ? '#4ade80' : '#fca5a5' ?>; margin-bottom: 1.5rem;">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- 1. Formulario Agregar / Editar Proveedor -->
        <div class="table-card" style="margin-bottom: 2rem;">
            <h3 style="font-family: 'Outfit', sans-serif; margin-top: 0; margin-bottom: 1.25rem; color: #c084fc;">
                <i class="fa-solid <?= $editProvider ? 'fa-pen-to-square' : 'fa-plus-circle' ?>"></i> 
                <?= $editProvider ? 'Editar Proveedor: ' . htmlspecialchars($editProvider['name']) : 'Registrar Nuevo Proveedor' ?>
            </h3>

            <form action="providers.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="provider_id" value="<?= $editProvider['id'] ?? 0 ?>">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div>
                        <label class="admin-form-label">Nombre Identificador *</label>
                        <input type="text" name="name" class="admin-form-input" placeholder="Ej: SolydSMM, JustAnotherPanel, Peakerr" value="<?= htmlspecialchars($editProvider['name'] ?? '') ?>" required>
                        <small style="color: var(--admin-muted); font-size: 0.75rem;">Nombre para reconocer el proveedor en el panel.</small>
                    </div>

                    <div>
                        <label class="admin-form-label">URL API del Proveedor *</label>
                        <input type="url" name="api_url" class="admin-form-input" placeholder="https://dominio-panel.com/api/v2" value="<?= htmlspecialchars($editProvider['api_url'] ?? '') ?>" required>
                        <small style="color: var(--admin-muted); font-size: 0.75rem;">Endpoint v2 provisto por el panel SMM.</small>
                    </div>

                    <div>
                        <label class="admin-form-label">API Key del Proveedor *</label>
                        <input type="password" name="api_key" class="admin-form-input" style="font-family: monospace;" placeholder="Pegá aquí la API Key generada en el panel" value="<?= htmlspecialchars($editProvider['api_key'] ?? '') ?>" required>
                        <small style="color: var(--admin-muted); font-size: 0.75rem;">Tu clave secreta de API del proveedor.</small>
                    </div>
                </div>

                <div style="display: flex; gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                    <label style="color: #fff; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="status" value="1" <?= (!isset($editProvider) || $editProvider['status'] == 1) ? 'checked' : '' ?>>
                        <span>Proveedor Activo</span>
                    </label>

                    <label style="color: #fff; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="is_default" value="1" <?= (isset($editProvider) && $editProvider['is_default'] == 1) ? 'checked' : '' ?>>
                        <span>⭐ Establecer como Proveedor Predeterminado</span>
                    </label>
                </div>

                <div style="display: flex; gap: 1rem; align-items: center;">
                    <button type="submit" class="btn-admin">
                        <i class="fa-solid fa-floppy-disk"></i> <?= $editProvider ? 'Actualizar Proveedor' : 'Guardar y Verificar Proveedor' ?>
                    </button>
                    <?php if ($editProvider): ?>
                        <a href="providers.php" class="btn-admin" style="background: rgba(255,255,255,0.1);">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- 2. Lista de Proveedores Guardados -->
        <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.3rem; margin-bottom: 1rem; color: #fff;">
            <i class="fa-solid fa-list"></i> Proveedores Registrados (<?= count($providers) ?>)
        </h2>

        <?php foreach ($providers as $p): ?>
            <div class="provider-card <?= $p['is_default'] == 1 ? 'is-default' : '' ?>">
                <div class="provider-header">
                    <div class="provider-name">
                        <i class="fa-solid fa-server" style="color: <?= $p['is_default'] == 1 ? '#c084fc' : 'var(--admin-muted)' ?>;"></i>
                        <?= htmlspecialchars($p['name']) ?>

                        <?php if ($p['is_default'] == 1): ?>
                            <span class="badge badge-purple" style="font-size: 0.75rem;"><i class="fa-solid fa-star"></i> Predeterminado</span>
                        <?php endif; ?>

                        <?php if ($p['status'] == 1): ?>
                            <span class="badge badge-success" style="font-size: 0.75rem;">Activo</span>
                        <?php else: ?>
                            <span class="badge badge-danger" style="font-size: 0.75rem;">Inactivo</span>
                        <?php endif; ?>
                    </div>

                    <div style="font-size: 0.85rem; color: var(--admin-muted);">
                        <i class="fa-solid fa-link"></i> <span style="color: #fff; font-family: monospace;"><?= htmlspecialchars($p['api_url']) ?></span>
                    </div>
                </div>

                <div class="provider-meta">
                    <div>
                        <span style="color: var(--admin-muted); display: block; margin-bottom: 0.2rem;">API Key:</span>
                        <span class="key-mask">
                            <?= htmlspecialchars(substr($p['api_key'], 0, 6) . '...' . substr($p['api_key'], -4)) ?>
                        </span>
                    </div>

                    <div>
                        <span style="color: var(--admin-muted); display: block; margin-bottom: 0.2rem;">Saldo Registrado:</span>
                        <span style="font-weight: 700; color: #4ade80; font-size: 1.05rem;">
                            <?= $p['balance'] !== null ? '$' . number_format($p['balance'], 4) . ' ' . htmlspecialchars($p['balance_currency'] ?? 'USD') : 'No verificado' ?>
                        </span>
                        <?php if (!empty($p['last_balance_check'])): ?>
                            <small style="display: block; color: var(--admin-dim); font-size: 0.7rem;">Último chequeo: <?= date('d/m H:i', strtotime($p['last_balance_check'])) ?></small>
                        <?php endif; ?>
                    </div>

                    <div>
                        <span style="color: var(--admin-muted); display: block; margin-bottom: 0.2rem;">Servicios Abastecidos:</span>
                        <span style="color: #fff; font-weight: 600;">
                            <i class="fa-solid fa-cubes"></i> <?= (int)$p['services_count'] ?> servicios asignados
                        </span>
                    </div>
                </div>

                <!-- Botones de Acción Rápida (EN CLICS) -->
                <div class="provider-actions">
                    <!-- Comprobar Saldo en Vivo -->
                    <form action="providers.php" method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="check_balance">
                        <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn-admin" style="background: #0284c7; padding: 0.45rem 0.9rem; font-size: 0.8rem;">
                            <i class="fa-solid fa-rotate"></i> Comprobar Saldo
                        </button>
                    </form>

                    <!-- Si no es predeterminado: botón para hacerlo predeterminado -->
                    <?php if ($p['is_default'] == 0): ?>
                        <form action="providers.php" method="POST" style="display: inline;" onsubmit="return confirm('¿Establecer <?= htmlspecialchars(addslashes($p['name'])) ?> como el proveedor predeterminado?');">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="set_default">
                            <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn-admin" style="background: #8a2be2; padding: 0.45rem 0.9rem; font-size: 0.8rem;">
                                <i class="fa-solid fa-star"></i> Hacer Predeterminado
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- Asignar Todos los Servicios del Catálogo a este Proveedor -->
                    <form action="providers.php" method="POST" style="display: inline;" onsubmit="return confirm('¿Asignar TODOS los servicios del catálogo a <?= htmlspecialchars(addslashes($p['name'])) ?>? Deberás asegurar que los IDs de servicio correspondan a este proveedor.');">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="assign_all_services">
                        <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn-admin" style="background: rgba(255, 255, 255, 0.1); border: 1px solid var(--admin-border); padding: 0.45rem 0.9rem; font-size: 0.8rem;" title="Muta todos los servicios para que salgan por este proveedor">
                            <i class="fa-solid fa-arrows-split-up-and-left"></i> Asignar Todo el Catálogo Aquí
                        </button>
                    </form>

                    <!-- Editar -->
                    <a href="providers.php?edit=<?= $p['id'] ?>" class="btn-admin" style="background: rgba(255, 255, 255, 0.15); padding: 0.45rem 0.9rem; font-size: 0.8rem;">
                        <i class="fa-solid fa-pen"></i> Editar
                    </a>

                    <!-- Toggle Activo/Inactivo -->
                    <?php if ($p['is_default'] == 0): ?>
                        <form action="providers.php" method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn-admin" style="background: <?= $p['status'] == 1 ? 'rgba(239, 68, 68, 0.2)' : 'rgba(34, 197, 94, 0.2)' ?>; color: <?= $p['status'] == 1 ? '#fca5a5' : '#4ade80' ?>; padding: 0.45rem 0.9rem; font-size: 0.8rem;">
                                <i class="fa-solid <?= $p['status'] == 1 ? 'fa-pause' : 'fa-play' ?>"></i> <?= $p['status'] == 1 ? 'Pausar' : 'Activar' ?>
                            </button>
                        </form>

                        <!-- Eliminar -->
                        <form action="providers.php" method="POST" style="display: inline;" onsubmit="return confirm('¿Seguro de eliminar este proveedor?');">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="delete_provider">
                            <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn-admin btn-admin-danger" style="padding: 0.45rem 0.9rem; font-size: 0.8rem;">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </main>
</div>

</body>
</html>
