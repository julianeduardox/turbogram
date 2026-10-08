<?php
/**
 * Procesador Centralizado de Órdenes y Despacho Anti-Race Condition
 * Turbogram
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/MercadoPago_Service.php';
require_once __DIR__ . '/GenericSMM_API.php';

class Order_Processor {
    /**
     * Valida el pago y procesa el pedido compartiendo la misma lógica para webhook y retorno
     */
    public static function verifyPaymentAndProcess(string $order_code, string $payment_id, string $source = 'system', ?array $prefetched_payment = null): array {
        $pdo = Database::getConnection();

        // 1. Reconciliar órdenes colgadas en 'processing' por interrupción previa
        self::reconcileStaleProcessingOrders();

        // 2. Buscar la orden en la base de datos
        $stmt = $pdo->prepare("
            SELECT o.*, s.provider_service_id, s.provider_id as service_provider_id 
            FROM orders o 
            JOIN services s ON o.service_id = s.id 
            WHERE o.order_code = ?
        ");
        $stmt->execute([$order_code]);
        $order = $stmt->fetch();

        if (!$order) {
            log_audit('ORDER_PROCESS_NOT_FOUND', "Orden no encontrada: {$order_code} (Origen: {$source})");
            return ['success' => false, 'is_temporary' => false, 'error' => 'Orden no encontrada'];
        }

        // 3. Validar pago estrictamente en Mercado Pago (reutilizando prefetched_payment si existe)
        $mpService = new MercadoPago_Service();
        $validation = $mpService->validatePaymentForOrder($order, $prefetched_payment ?? $payment_id);

        if (!$validation['valid']) {
            $err = $validation['error'] ?? 'Validación de pago fallida';
            $isTemporary = !empty($validation['is_temporary']);
            log_audit('PAYMENT_VALIDATION_FAILED', "Fallo al validar orden {$order_code} con pago {$payment_id}: {$err} (Origen: {$source}, Temporal: " . ($isTemporary ? 'si' : 'no') . ")");

            // Si es un error temporal de red o API externa, devolver flag para que el webhook responda 503
            if ($isTemporary) {
                return [
                    'success'      => false,
                    'is_temporary' => true,
                    'error'        => $err
                ];
            }

            // SEGURIDAD ZERO-TRUST:
            // Comprobación de vinculación estricta ANTES de cualquier mutación de estado.
            // Si el pago NO está vinculado (is_linked === false), es un pago ajeno o no autorizado:
            // NUNCA debe alterar el estado del pedido!
            $pay = $validation['payment'] ?? null;
            $isLinked = !empty($validation['is_linked']);

            if ($isLinked && $pay && in_array($pay['status'] ?? '', ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
                // Actualizar estado del pago en la orden incluso si ya fue enviada previamente.
                // Esto garantiza registrar devoluciones (refunded) y contracargos (charged_back) posteriores sin despachar duplicados.
                $pdo->prepare("UPDATE orders SET mp_status = ?, mp_payment_id = ? WHERE id = ?")
                    ->execute([$pay['status'], $payment_id, $order['id']]);
                log_audit('PAYMENT_LINKED_STATUS_UPDATE', "Estado de orden {$order_code} actualizado a {$pay['status']} (pago vinculado confirmado). Estado previo proveedor: {$order['provider_status']}.");

                return [
                    'success'        => false,
                    'is_temporary'   => false,
                    'error'          => $err,
                    'status_updated' => $pay['status']
                ];
            } else {
                log_audit('PAYMENT_UNLINKED_REJECTED', "Pago {$payment_id} ajeno o no vinculado intentó interactuar con orden {$order_code}. Estado del pedido protegido e intacto.");
            }

            return ['success' => false, 'is_temporary' => false, 'error' => $err];
        }

        // 4. Pago verificado con éxito: actualizar mp_status = 'approved'
        $pdo->prepare("UPDATE orders SET mp_status = 'approved', mp_payment_id = ? WHERE id = ?")
            ->execute([$payment_id, $order['id']]);
        log_audit('PAYMENT_APPROVED_VERIFIED', "Pago aprobado y verificado para orden {$order_code} (MP ID: {$payment_id}, Origen: {$source})");

        // 5. Protección de Idempotencia:
        // Si la orden ya está despachada o en proceso ('sent', 'completed', 'processing', 'review'),
        // NO provocar un nuevo despacho al proveedor.
        if (in_array($order['provider_status'], ['sent', 'completed', 'processing', 'review'], true)) {
            return [
                'success'           => true,
                'already_processed' => true,
                'order'             => $order,
                'message'           => 'La orden ya fue aprobada y se encuentra en estado ' . $order['provider_status'] . '.'
            ];
        }

        // 6. Despachar al proveedor mediante reserva atómica
        $dispatchRes = self::dispatchOrderToProvider((int)$order['id'], $source);

        if (!empty($dispatchRes['already_processing'])) {
            return [
                'success'           => true,
                'already_processed' => true,
                'order'             => $order,
                'message'           => 'La orden ya está siendo despachada o fue procesada por otro hilo.'
            ];
        }

        return $dispatchRes;
    }

    /**
     * Despacha la orden al proveedor SMM con bloqueo atómico contra carreras (Anti-Race Condition)
     *
     * @param int $order_id ID de la orden
     * @param string $source Origen del evento ('webhook', 'user_return', 'admin_retry', 'test')
     * @param bool $is_admin_retry Indica si es un reintento manual explícito del administrador
     * @param object|null $customAPI Instancia mock o cliente personalizado para pruebas sin llamadas reales
     * @param bool $already_reserved Si true, la reserva 'processing' ya fue obtenida atómicamente por el llamador
     */
    public static function dispatchOrderToProvider(int $order_id, string $source = 'system', bool $is_admin_retry = false, ?object $customAPI = null, bool $already_reserved = false): array {
        $pdo = Database::getConnection();

        // 1. Comprobar que la orden exista y esté aprobada
        $stmtChk = $pdo->prepare("SELECT id, mp_status, provider_status, order_code, provider_order_id FROM orders WHERE id = ?");
        $stmtChk->execute([$order_id]);
        $current = $stmtChk->fetch();

        if (!$current) {
            return ['success' => false, 'error' => 'La orden solicitada no existe'];
        }

        if ($current['mp_status'] !== 'approved') {
            return ['success' => false, 'error' => 'No se puede despachar: el pago no ha sido verificado ni aprobado'];
        }

        // 2. Reserva atómica (si no fue obtenida previamente de forma atómica):
        // Peticiones automáticas (webhook, retorno): ÚNICAMENTE pueden procesar 'pending_send'.
        // Reintentos manuales explícitos del administrador ($is_admin_retry === true): admiten 'pending_send' y 'error'.
        // Las órdenes en 'review' NUNCA pueden ser despachadas sin reconciliación técnica previa!
        if (!$already_reserved) {
            $allowedStatuses = $is_admin_retry ? ['pending_send', 'error'] : ['pending_send'];
            $placeholders = implode(',', array_fill(0, count($allowedStatuses), '?'));

            $stmtLock = $pdo->prepare("
                UPDATE orders 
                SET provider_status = 'processing' 
                WHERE id = ? 
                  AND mp_status = 'approved' 
                  AND provider_status IN ({$placeholders})
            ");
            $stmtLock->execute(array_merge([$order_id], $allowedStatuses));

            if ($stmtLock->rowCount() === 0) {
                // Otra petición simultánea ya ganó la reserva o ya está despachada/en revisión
                $currentStatus = $current['provider_status'];
                $msg = ($currentStatus === 'review')
                    ? 'La orden está en revisión técnica y exige reconciliación con el proveedor antes de cualquier envío.'
                    : 'La orden ya está siendo procesada o ya fue despachada.';

                return [
                    'success'            => false,
                    'already_processing' => true,
                    'error'              => $msg
                ];
            }
        }

        // 3. Reserva atómica 'processing' obtenida en exclusiva
        $stmtFull = $pdo->prepare("
            SELECT o.*, s.provider_service_id, s.provider_id as service_provider_id 
            FROM orders o 
            JOIN services s ON o.service_id = s.id 
            WHERE o.id = ?
        ");
        $stmtFull->execute([$order_id]);
        $order = $stmtFull->fetch();

        $providerId = (int)($order['provider_id'] ?: $order['service_provider_id'] ?: 1);
        $smmAPI = $customAPI ?? new GenericSMM_API($providerId);
        $provName = method_exists($smmAPI, 'getProviderName') ? ($smmAPI->getProviderName() ?: 'Proveedor') : 'Proveedor';

        try {
            $sendRes = $smmAPI->addOrder(
                (int)$order['provider_service_id'],
                $order['target_link'],
                (int)$order['quantity']
            );
        } catch (\Throwable $e) {
            // Excepción durante el despacho (timeout o fallo de red):
            // Pasar a 'review' inmediatamente; NUNCA reintentar a ciegas ni dejar en 'processing'
            $pdo->prepare("
                UPDATE orders 
                SET provider_id = ?, 
                    provider_status = 'review', 
                    error_message = ?, 
                    provider_response = NULL 
                WHERE id = ?
            ")->execute([
                $providerId,
                "Excepción o interrupción de conexión: " . $e->getMessage(),
                $order_id
            ]);

            log_audit('PROVIDER_ORDER_TIMEOUT', "Timeout o excepción en orden {$order['order_code']}. Pasada a revisión manual.");
            return [
                'success' => false,
                'status'  => 'review',
                'error'   => 'Timeout o interrupción de conexión con el proveedor. Orden pasada a revisión técnica.'
            ];
        }

        // 4. Evaluar resultado del proveedor
        if (!empty($sendRes['success']) && !empty($sendRes['order_id'])) {
            $pdo->prepare("
                UPDATE orders 
                SET provider_id = ?, 
                    provider_order_id = ?, 
                    provider_status = 'sent', 
                    provider_response = ?, 
                    error_message = NULL 
                WHERE id = ?
            ")->execute([
                $providerId,
                $sendRes['order_id'],
                $sendRes['raw'] ?? json_encode($sendRes),
                $order_id
            ]);

            log_audit('PROVIDER_ORDER_SENT', "Orden {$order['order_code']} enviada con éxito a {$provName}. ID Proveedor: {$sendRes['order_id']} (Origen: {$source})");
            return [
                'success'  => true,
                'status'   => 'sent',
                'order_id' => $sendRes['order_id'],
                'message'  => "Orden enviada con éxito a {$provName}. ID Proveedor: #{$sendRes['order_id']}"
            ];
        }

        // CLASIFICACIÓN ESTRICTA:
        // Solo pasa a 'error' si el proveedor devolvió un rechazo explícito y confirmado (is_definitive_rejection).
        // Cualquier otro resultado (timeout, respuesta ambigua, malformada o HTTP no esperado) DEBE pasar a 'review'.
        $isDefinitive = !empty($sendRes['is_definitive_rejection']);
        $targetStatus = $isDefinitive ? 'error' : 'review';
        $errMsg = $sendRes['error'] ?? 'Respuesta indeterminada del proveedor';

        $pdo->prepare("
            UPDATE orders 
            SET provider_id = ?, 
                provider_status = ?, 
                error_message = ?, 
                provider_response = ? 
            WHERE id = ?
        ")->execute([
            $providerId,
            $targetStatus,
            $errMsg,
            $sendRes['raw'] ?? null,
            $order_id
        ]);

        log_audit(
            $targetStatus === 'review' ? 'PROVIDER_ORDER_REVIEW' : 'PROVIDER_ORDER_FAILED',
            "Orden {$order['order_code']} finalizada en {$targetStatus}: {$errMsg} (Origen: {$source})"
        );

        return [
            'success' => false,
            'status'  => $targetStatus,
            'error'   => $errMsg
        ];
    }

    /**
     * Reconcilia una orden en 'review' consultando primero al proveedor antes de permitir reenvíos
     */
    public static function reconcileOrderWithProvider(int $order_id, ?object $customAPI = null): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'Orden no encontrada'];
        }

        if ($order['provider_status'] !== 'review') {
            return ['success' => false, 'error' => 'La orden no se encuentra en estado de revisión técnica'];
        }

        $providerId = (int)($order['provider_id'] ?: 1);
        $smmAPI = $customAPI ?? new GenericSMM_API($providerId);

        // Si tenemos un provider_order_id previo asignado, consultamos su estado real
        if (!empty($order['provider_order_id'])) {
            $statusRes = $smmAPI->getOrderStatus((int)$order['provider_order_id']);
            if (!empty($statusRes['success']) && isset($statusRes['status_data']['status'])) {
                $provStatus = strtolower($statusRes['status_data']['status']);
                $newStatus = in_array($provStatus, ['completed', 'partial']) ? 'completed' : 'sent';

                $pdo->prepare("
                    UPDATE orders 
                    SET provider_status = ?, 
                        error_message = NULL, 
                        provider_response = ? 
                    WHERE id = ?
                ")->execute([
                    $newStatus,
                    json_encode($statusRes['status_data']),
                    $order_id
                ]);

                log_audit('RECONCILE_SUCCESS', "Orden {$order['order_code']} reconciliada con proveedor. Estado confirmado: {$provStatus}");
                return [
                    'success' => true,
                    'reconciled' => true,
                    'status' => $newStatus,
                    'message' => "El proveedor confirmó que la orden #{$order['provider_order_id']} está en estado: {$provStatus}"
                ];
            }
        }

        // Si NO hay provider_order_id, getOrderStatus no permite localizar el envío automáticamente
        return [
            'success'               => false,
            'requires_manual_audit' => true,
            'message'               => 'La orden no tiene un ID de proveedor confirmado. Debe verificarse manualmente en el panel del proveedor si fue recibida antes de proceder.'
        ];
    }

    /**
     * Reconciliación administrativa explícita:
     * Exige comprobar primero si el proveedor recibió la orden y registrar el resultado de esa revisión.
     * NUNCA permite reenviar a ciegas una orden en revisión.
     */
    public static function adminConfirmAndResolveReview(
        int $order_id, 
        string $action, 
        string $audit_note, 
        string $verification_method = '', 
        bool $verification_confirmed = false, 
        ?int $manual_provider_order_id = null, 
        ?object $customAPI = null
    ): array {
        $audit_note = trim($audit_note);
        if (strlen($audit_note) < 15) {
            return ['success' => false, 'error' => 'Es obligatorio registrar una nota de auditoría detallada de la revisión realizada (mínimo 15 caracteres)'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if (!$order || $order['provider_status'] !== 'review') {
            return ['success' => false, 'error' => 'La orden no se encuentra en estado de revisión técnica'];
        }

        if ($action === 'confirm_received') {
            // El administrador comprobó en el panel del proveedor que sí fue recibida bajo un ID
            if (!$manual_provider_order_id || $manual_provider_order_id <= 0) {
                return ['success' => false, 'error' => 'Debe ingresar el ID de orden numérico registrado en el proveedor'];
            }

            // TRANSICIÓN ATÓMICA CONDICIONADA A provider_status = 'review':
            // Impide colisiones si otra solicitud concurrente ya resolvió o cambió la orden
            $stmtLock = $pdo->prepare("
                UPDATE orders 
                SET provider_order_id = ?, 
                    provider_status = 'sent', 
                    error_message = NULL, 
                    provider_response = ? 
                WHERE id = ? 
                  AND provider_status = 'review'
            ");
            $stmtLock->execute([
                $manual_provider_order_id,
                "Confirmado e ingresado manualmente por administrador tras revisión. Nota: " . clean_input($audit_note),
                $order_id
            ]);

            if ($stmtLock->rowCount() === 0) {
                return [
                    'success'            => false,
                    'already_processing' => true,
                    'error'              => 'La orden ya fue resuelta o ya no se encuentra en estado de revisión.'
                ];
            }

            log_audit('ADMIN_RECONCILE_CONFIRMED', "Orden {$order['order_code']} confirmada con ID #{$manual_provider_order_id} por admin. Nota: {$audit_note}");
            return ['success' => true, 'message' => "Orden confirmada y vinculada al ID #{$manual_provider_order_id} del proveedor."];

        } elseif ($action === 'confirm_not_received_and_retry') {
            // Si la orden ya contaba con un provider_order_id previo, NO se permite reenvío directo a ciegas
            if (!empty($order['provider_order_id'])) {
                return [
                    'success' => false, 
                    'error'   => "La orden ya tiene asignado el ID #{$order['provider_order_id']} en el proveedor. Debe utilizar 'Consultar Estado' para reconciliar con la API o verificar con soporte."
                ];
            }

            // Exigir comprobación previa fehaciente
            if (!$verification_confirmed) {
                return ['success' => false, 'error' => 'Debe confirmar expresamente que verificó en el panel del proveedor que la orden no fue recibida'];
            }

            $allowedMethods = ['dashboard_search', 'api_inquiry', 'support_ticket'];
            if (!in_array($verification_method, $allowedMethods, true)) {
                return ['success' => false, 'error' => 'Debe seleccionar el método de comprobación realizado en el proveedor'];
            }

            $fullAuditLog = "Reconciliación: comprobado no recibido vía [{$verification_method}]. Detalle: " . clean_input($audit_note);

            // TRANSICIÓN ATÓMICA CONDICIONADA A provider_status = 'review':
            // Pasa directamente de 'review' a 'processing' en una única sentencia atómica.
            // Si dos solicitudes concurrentes intentan resolver la misma orden, rowCount() será 0 para la perdedora.
            $stmtAtomicLock = $pdo->prepare("
                UPDATE orders 
                SET provider_status = 'processing', 
                    error_message = ? 
                WHERE id = ? 
                  AND mp_status = 'approved' 
                  AND provider_status = 'review' 
                  AND (provider_order_id IS NULL OR provider_order_id = 0)
            ");
            $stmtAtomicLock->execute([$fullAuditLog, $order_id]);

            if ($stmtAtomicLock->rowCount() === 0) {
                return [
                    'success'            => false,
                    'already_processing' => true,
                    'error'              => 'La orden ya fue resuelta o ya está siendo procesada concurrentemente por otra solicitud.'
                ];
            }

            log_audit('ADMIN_RECONCILE_RETRY', "Admin comprobó no recepción de orden {$order['order_code']} vía {$verification_method}. Reserva atómica obtenida. Nota: {$audit_note}");

            // Ya está reservada atómicamente en 'processing', despachar con $already_reserved = true
            return self::dispatchOrderToProvider($order_id, 'admin_reconciled_retry', true, $customAPI, true);
        }

        return ['success' => false, 'error' => 'Acción de reconciliación no válida'];
    }

    /**
     * Reconcilia órdenes que hayan quedado en 'processing' por interrupción inesperada.
     * Si llevan más de $timeoutSeconds en 'processing', se pasan a 'review' (requieren revisión manual).
     */
    public static function reconcileStaleProcessingOrders(int $timeoutSeconds = 300): int {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET provider_status = 'review', 
                    error_message = 'Proceso interrumpido antes de confirmar respuesta. Pasada a revisión técnica para reconciliación.' 
                WHERE provider_status = 'processing' 
                  AND updated_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$timeoutSeconds]);
            return (int)$stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
