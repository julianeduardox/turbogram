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

        $url = 'https://api.mercadopago.com' . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $this->access_token,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
            return ['success' => false, 'error' => 'Error de conexión con Mercado Pago: ' . $error];
        }

        $decoded = json_decode($response, true);
        if ($http_code >= 400) {
            $msg = $decoded['message'] ?? ($decoded['cause'][0]['description'] ?? 'Error devuelto por Mercado Pago');
            return ['success' => false, 'error' => $msg, 'http_code' => $http_code];
        }

        return ['success' => true, 'data' => $decoded];
    }

    /**
     * Crea una preferencia de pago para una orden de Turbogram
     */
    public function createPreference(array $order): array {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Detectar dinámicamente el directorio base del proyecto
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $script_dir = str_replace('\\', '/', $script_dir);
        $script_dir = trim($script_dir, '/');
        // Si se llama desde subcarpetas, removerlas del path base
        $script_dir = preg_replace('#/(admin|includes|config)$#i', '', $script_dir);
        $base_path = $script_dir ? '/' . $script_dir : '';
        $base_url = $scheme . '://' . $host . $base_path;

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
                    'title'       => 'Turbogram: ' . $order['service_name'] . ' (x' . number_format($order['quantity'], 0, ',', '.') . ')',
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
        if (!$res['success']) return $res;

        $p = $res['data'];
        return [
            'success'            => true,
            'payment_id'         => (string)$p['id'],
            'status'             => $p['status'], // approved, pending, rejected, refunded, etc.
            'status_detail'      => $p['status_detail'] ?? '',
            'external_reference' => $p['external_reference'] ?? '',
            'transaction_amount' => (float)($p['transaction_amount'] ?? 0),
            'raw'                => json_encode($p)
        ];
    }
}
