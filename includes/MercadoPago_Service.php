<?php
/**
 * Servicio REST de Mercado Pago (Preferences & Payments API)
 * Turbogram
 */

require_once __DIR__ . '/../config/settings.php';

class MercadoPago_Service {
    private string $access_token;
    private bool $is_sandbox;

    public function __construct(?string $token = null) {
        $this->access_token = $token ?? Settings::get('mp_access_token', '');
        $this->is_sandbox = (bool)Settings::get('mp_sandbox', '1');
    }

    /**
     * Realiza una petición cURL a los endpoints REST de Mercado Pago
     */
    private function request(string $endpoint, string $method = 'GET', ?array $data = null): array {
        if (empty($this->access_token)) {
            return [
                'success' => false,
                'error'   => 'Las credenciales de Mercado Pago no están configuradas en el Panel del Dueño.'
            ];
        }

        // En producción se fija obligatoriamente https://api.mercadopago.com
        // MP_API_BASE solo se admite si el entorno es explícitamente testing o development
        $apiBase = 'https://api.mercadopago.com';
        $appEnv = getenv('APP_ENV') ?: 'production';
        $customBase = getenv('MP_API_BASE');
        if (in_array($appEnv, ['development', 'testing'], true) && !empty($customBase)) {
            $apiBase = $customBase;
        }

        $url = rtrim($apiBase, '/') . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $this->access_token,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success'      => false, 
                'error'        => 'Error de conexión con Mercado Pago: ' . $error,
                'is_temporary' => true,
                'http_code'    => 0
            ];
        }

        $decoded = json_decode($response, true);
        $jsonError = json_last_error();

        if ($http_code >= 400) {
            $msg = (is_array($decoded) && isset($decoded['message']))
                ? $decoded['message']
                : ((is_array($decoded) && isset($decoded['cause'][0]['description']))
                    ? $decoded['cause'][0]['description']
                    : 'Error HTTP ' . $http_code . ' devuelto por Mercado Pago');
            $isTemporary = ($http_code >= 500 || $http_code === 429);
            return [
                'success'      => false, 
                'error'        => $msg, 
                'http_code'    => $http_code,
                'is_temporary' => $isTemporary
            ];
        }

        // Validación estricta ante HTTP 200: respuesta vacía o JSON malformado es un fallo temporal upstream
        if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded) || empty($decoded)) {
            return [
                'success'      => false,
                'error'        => 'Respuesta de Mercado Pago vacía o JSON malformado (JSON error: ' . $jsonError . ')',
                'http_code'    => $http_code,
                'is_temporary' => true
            ];
        }

        return ['success' => true, 'data' => $decoded];
    }

    /**
     * Crea una preferencia de pago para una orden de Turbogram
     */
    public function createPreference(array $order): array {
        $configuredBaseUrl = rtrim(Settings::get('site_base_url', ''), '/');
        if ($configuredBaseUrl !== '' && filter_var($configuredBaseUrl, FILTER_VALIDATE_URL)) {
            $base_url = $configuredBaseUrl;
        } else {
            if (!function_exists('is_local_development') || !is_local_development()) {
                return ['success' => false, 'error' => 'Missing secure public site URL configuration.'];
            }
            $scheme = 'http';
            $host = 'localhost';

        // Detectar dinámicamente el directorio base del proyecto
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $script_dir = str_replace('\\', '/', $script_dir);
        $script_dir = trim($script_dir, '/');
        // Si se llama desde subcarpetas, removerlas del path base
        $script_dir = preg_replace('#/(admin|includes|config)$#i', '', $script_dir);
        $base_path = $script_dir ? '/' . $script_dir : '';
            $base_url = $scheme . '://' . $host . $base_path;
        }

        $payer = [
            'email' => $order['buyer_email']
        ];
        if (!empty($order['buyer_phone'])) {
            $payer['phone'] = [
                'number' => (string)$order['buyer_phone']
            ];
        }

        $payload = [
            'items' => [
                [
                    'title'       => number_format((int)$order['quantity'], 0, ',', '.') . ' unidades',
                    'quantity'    => 1,
                    'currency_id' => 'ARS',
                    'unit_price'  => (float)$order['total_price']
                ]
            ],
            'payer' => $payer,
            'external_reference' => $order['order_code'],
            'back_urls' => [
                'success' => $base_url . '/status.php?code=' . $order['order_code'],
                'pending' => $base_url . '/status.php?code=' . $order['order_code'],
                'failure' => $base_url . '/status.php?code=' . $order['order_code']
            ],
            'auto_return' => 'approved',
            'notification_url' => $base_url . '/webhook.php',
            'statement_descriptor' => 'TURBOGRAM'
        ];

        $res = $this->request('/checkout/preferences', 'POST', $payload);
        if (!$res['success']) return $res;

        $pref = $res['data'];
        $checkout_url = $this->is_sandbox ? ($pref['sandbox_init_point'] ?? $pref['init_point']) : $pref['init_point'];

        return [
            'success'       => true,
            'preference_id' => $pref['id'],
            'checkout_url'  => $checkout_url,
            'init_point'    => $pref['init_point'],
            'sandbox_point' => $pref['sandbox_init_point'] ?? null
        ];
    }

    /**
     * Consulta el estado real de un pago a través del ID de pago devuelto por Mercado Pago
     */
    public function getPayment(string $payment_id): array {
        $res = $this->request('/v1/payments/' . $payment_id, 'GET');
        if (!$res['success']) {
            return [
                'success'      => false,
                'error'        => $res['error'] ?? 'Error al consultar pago',
                'http_code'    => $res['http_code'] ?? null,
                'is_temporary' => $res['is_temporary'] ?? true
            ];
        }

        $p = $res['data'];
        if (!is_array($p) || empty($p['id']) || !isset($p['status'])) {
            return [
                'success'      => false,
                'error'        => 'Estructura de pago incompleta o no válida devuelta por Mercado Pago',
                'http_code'    => $res['http_code'] ?? 200,
                'is_temporary' => true
            ];
        }

        return [
            'success'            => true,
            'payment_id'         => (string)($p['id'] ?? ''),
            'status'             => (string)($p['status'] ?? ''),
            'status_detail'      => (string)($p['status_detail'] ?? ''),
            'external_reference' => (string)($p['external_reference'] ?? ''),
            'transaction_amount' => (float)($p['transaction_amount'] ?? 0),
            'currency_id'        => (string)($p['currency_id'] ?? ''),
            'collector_id'       => isset($p['collector_id']) ? (string)$p['collector_id'] : null,
            'live_mode'          => array_key_exists('live_mode', $p) ? $p['live_mode'] : null,
            'raw'                => json_encode($p)
        ];
    }

    /**
     * Obtiene la información de la cuenta del vendedor (/users/me)
     */
    public function getMerchantAccountInfo(): ?array {
        $res = $this->request('/users/me', 'GET');
        if ($res['success'] && isset($res['data']['id'])) {
            return [
                'collector_id' => (string)$res['data']['id'],
                'nickname'     => $res['data']['nickname'] ?? '',
                'email'        => $res['data']['email'] ?? ''
            ];
        }
        return null;
    }

    /**
     * Obtiene el identificador de cuenta del vendedor (collector_id) desde configuración o API
     */
    public function getExpectedCollectorId(): string {
        $cached = Settings::get('mp_collector_id', '');
        if (!empty($cached)) {
            return (string)$cached;
        }
        if (!empty($this->access_token)) {
            $info = $this->getMerchantAccountInfo();
            if ($info && !empty($info['collector_id'])) {
                Settings::set('mp_collector_id', (string)$info['collector_id']);
                return (string)$info['collector_id'];
            }
        }
        return '';
    }

    /**
     * Valida exhaustivamente un pago contra una orden específica:
     * - SEGURIDAD ZERO-TRUST: Se verifica la vinculación de cuenta y orden ANTES del estado del pago.
     *   1. Cuenta receptora (collector_id): OBLIGATORIA y validada contra cuenta autorizada
     *   2. Entorno (live_mode): OBLIGATORIO y coherente con mp_sandbox
     *   3. Referencia externa: coincidencia exacta con order_code
     *   4. Moneda: 'ARS'
     *   5. Importe: comparación estricta en centavos (int)
     *   6. Estado: 'approved' (solo si la vinculación está 100% demostrada)
     *
     * @param array $order Registro de la orden en la BD
     * @param string|array $payment_or_id ID de pago devuelto por MP o array con datos de pago ya consultados
     * @return array ['valid' => bool, 'is_linked' => bool, 'payment' => ?array, 'error' => ?string, 'is_temporary' => bool]
     */
    public function validatePaymentForOrder(array $order, $payment_or_id): array {
        if (is_array($payment_or_id)) {
            $payment = $payment_or_id;
        } else {
            $payment = $this->getPayment((string)$payment_or_id);
        }

        if (!$payment['success']) {
            return [
                'valid'        => false,
                'is_linked'    => false,
                'is_temporary' => $payment['is_temporary'] ?? true,
                'error'        => 'No se pudo consultar el pago en Mercado Pago: ' . ($payment['error'] ?? 'Error desconocido'),
                'payment'      => null
            ];
        }

        // --- COMPROBACIONES DE VINCULACIÓN Y SEGURIDAD (PREVIAS A CUALQUIER ESTADO) ---

        // 1. Cuenta receptora esperada de fuente confiable (OBLIGATORIA)
        $expectedCollectorId = $this->getExpectedCollectorId();
        if (empty($expectedCollectorId)) {
            return [
                'valid'     => false,
                'is_linked' => false,
                'error'     => 'No se pudo determinar la cuenta receptora autorizada de Mercado Pago desde una fuente confiable',
                'payment'   => $payment
            ];
        }

        // 2. Cuenta receptora del pago (collector_id OBLIGATORIO)
        if (empty($payment['collector_id'])) {
            return [
                'valid'     => false,
                'is_linked' => false,
                'error'     => 'El pago carece del identificador de cuenta receptora (collector_id ausente)',
                'payment'   => $payment
            ];
        }

        if ((string)$payment['collector_id'] !== (string)$expectedCollectorId) {
            return [
                'valid'     => false,
                'is_linked' => false,
                'error'     => 'El pago fue dirigido a una cuenta receptora de Mercado Pago ajena o no autorizada',
                'payment'   => $payment
            ];
        }

        // 3. Indicador de entorno (live_mode OBLIGATORIO)
        if (!isset($payment['live_mode']) || $payment['live_mode'] === null || !is_bool($payment['live_mode'])) {
            return [
                'valid'     => false,
                'is_linked' => false,
                'error'     => 'El pago carece del indicador de entorno requerido (live_mode ausente o no booleano)',
                'payment'   => $payment
            ];
        }

        $expectedLiveMode = !$this->is_sandbox;
        if ((bool)$payment['live_mode'] !== $expectedLiveMode) {
            return [
                'valid'     => false,
                'is_linked' => false,
                'error'     => 'El entorno del pago (live_mode=' . ($payment['live_mode'] ? 'true' : 'false') . ') no coincide con la configuración del sitio',
                'payment'   => $payment
            ];
        }

        // 4. Referencia externa exacta con la orden (OBLIGATORIA)
        if (empty($payment['external_reference']) || !hash_equals((string)$order['order_code'], (string)$payment['external_reference'])) {
            return [
                'valid'     => false,
                'is_linked' => false,
                'error'     => 'La referencia externa del pago no coincide con el código de este pedido',
                'payment'   => $payment
            ];
        }

        // En este punto, el pago está 100% VINCULADO a la cuenta y a la orden
        $isLinked = true;

        // 5. Moneda ARS
        if (strtoupper((string)$payment['currency_id']) !== 'ARS') {
            return [
                'valid'     => false,
                'is_linked' => $isLinked,
                'error'     => 'La moneda del pago (' . $payment['currency_id'] . ') no corresponde a ARS',
                'payment'   => $payment
            ];
        }

        // 6. Comparación de importes exacta en centavos
        $paidCents = (int)round((float)$payment['transaction_amount'] * 100);
        $expectedCents = (int)round((float)$order['total_price'] * 100);
        if ($paidCents !== $expectedCents) {
            return [
                'valid'     => false,
                'is_linked' => $isLinked,
                'error'     => "Discrepancia en importe: pagado={$paidCents} centavos, esperado={$expectedCents} centavos",
                'payment'   => $payment
            ];
        }

        // 7. Estado del pago aprobado
        if ($payment['status'] !== 'approved') {
            return [
                'valid'     => false,
                'is_linked' => $isLinked,
                'error'     => 'El pago vinculado no se encuentra aprobado (Estado actual: ' . $payment['status'] . ')',
                'payment'   => $payment
            ];
        }

        return [
            'valid'     => true,
            'is_linked' => true,
            'payment'   => $payment
        ];
    }

    /**
     * Valida la firma criptográfica HMAC-SHA256 de notificaciones Webhook de Mercado Pago (Fail-Closed)
     *
     * Formato oficial de Mercado Pago:
     * - Cabecera x-signature: "ts=[timestamp],v1=[hash]"
     * - Cabecera x-request-id: "[UUID]"
     * - Identificador data_id: "[ID del recurso notificado]"
     * - Manifiesto: "id:[data_id];request-id:[x-request-id];ts:[ts];"
     * - Hash esperado: hash_hmac('sha256', $manifest, $secret)
     *
     * @param string $xSignature Cabecera HTTP x-signature
     * @param string $xRequestId Cabecera HTTP x-request-id
     * @param string $dataId Identificador del evento o recurso notificado
     * @param string|null $secret Clave secreta (opcional; si null se obtiene de Settings / ENV)
     * @param int $toleranceSeconds Tolerancia máxima en segundos contra ataques de replay (default: 300s = 5 min)
     * @return array ['valid' => bool, 'error' => ?string, 'ts' => ?int, 'manifest' => ?string]
     */
    public static function verifyWebhookSignature(
        string $xSignature, 
        string $xRequestId, 
        string $dataId, 
        ?string $secret = null, 
        int $toleranceSeconds = 300
    ): array {
        $xSignature = trim($xSignature);
        $xRequestId = trim($xRequestId);
        $dataId = trim($dataId);

        // 1. Validar presencia de parámetros y cabeceras requeridas
        if ($xSignature === '') {
            return ['valid' => false, 'error' => 'Cabecera x-signature ausente o vacía'];
        }
        if ($xRequestId === '') {
            return ['valid' => false, 'error' => 'Cabecera x-request-id ausente o vacía'];
        }
        if ($dataId === '') {
            return ['valid' => false, 'error' => 'Identificador de datos (data_id) ausente o vacío'];
        }

        // 2. Extraer 'ts' y 'v1' de la cabecera x-signature (ej: ts=1700000000,v1=abcdef...)
        $parts = explode(',', $xSignature);
        $rawTs = null;
        $v1 = null;
        foreach ($parts as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $key = strtolower(trim($pair[0]));
                $val = trim($pair[1]);
                if ($key === 'ts') $rawTs = $val;
                if ($key === 'v1') $v1 = $val;
            }
        }

        if ($rawTs === null || $v1 === null || $rawTs === '' || $v1 === '') {
            return ['valid' => false, 'error' => 'Formato de cabecera x-signature inválido (faltan partes ts o v1)'];
        }

        // 3. Obtener clave secreta del webhook (Fail-Closed)
        $webhookSecret = $secret !== null ? $secret : (Settings::get('mp_webhook_secret', '') ?: (getenv('MP_WEBHOOK_SECRET') ?: ''));
        if (empty($webhookSecret)) {
            return ['valid' => false, 'error' => 'Clave secreta de webhook no configurada en el sistema (Fail-Closed)'];
        }

        // 4. Validar ventana temporal (Anti-Replay)
        if (!is_numeric($rawTs)) {
            return ['valid' => false, 'error' => 'Timestamp ts de cabecera no es numérico'];
        }

        // Normalización temporal: Mercado Pago documenta timestamps tanto en segundos (10 dígitos)
        // como en milisegundos (13 dígitos). Se normaliza a segundos para comparar contra el reloj del servidor,
        // pero se conserva el texto original intacto para la firma criptográfica HMAC.
        $numericTs = (float)$rawTs;
        $normalizedTs = ($numericTs > 10000000000) ? (int)floor($numericTs / 1000) : (int)$numericTs;

        $currentTime = time();
        if (abs($currentTime - $normalizedTs) > $toleranceSeconds) {
            return ['valid' => false, 'error' => 'Firma fuera de ventana temporal de tolerancia (Anti-Replay expirado)'];
        }

        // 5. Construir manifiesto de Mercado Pago: id:[data_id];request-id:[x-request-id];ts:[ts];
        // CRUCIAL: ts DEBE ser el texto original recibido en la cabecera, sin alterar.
        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$rawTs};";

        // 6. Calcular HMAC-SHA256 y comparar con hash_equals()
        $expectedHash = hash_hmac('sha256', $manifest, (string)$webhookSecret);

        if (!hash_equals($expectedHash, $v1)) {
            return ['valid' => false, 'error' => 'Firma HMAC-SHA256 no válida o cabeceras manipuladas'];
        }

        return [
            'valid'      => true,
            'ts'         => $normalizedTs,
            'raw_ts'     => $rawTs,
            'manifest'   => $manifest,
            'request_id' => $xRequestId,
            'data_id'    => $dataId
        ];
    }
}
