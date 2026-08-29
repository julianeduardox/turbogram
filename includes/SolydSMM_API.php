<?php
/**
 * Cliente API para Proveedor SolydSMM (api/v2)
 * Turbogram
 */

require_once __DIR__ . '/../config/settings.php';

class SolydSMM_API {
    private string $api_url;
    private string $api_key;

    public function __construct(?string $key = null, ?string $url = null) {
        $this->api_url = $url ?? Settings::get('provider_api_url', 'https://solydsmm.com/api/v2');
        $this->api_key = $key ?? Settings::get('provider_api_key', '');
    }

    /**
     * Realiza una petición cURL a la API del proveedor
     */
    private function request(array $params): array {
        $params['key'] = $this->api_key;

        $is_localhost = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1']) || in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1']);
        $ssl_verify = !$is_localhost;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) TurbogramBot/1.0');

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'Error de conexión cURL: ' . $error];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['success' => false, 'error' => 'Respuesta no válida del proveedor: ' . substr($response, 0, 200)];
        }

        return ['success' => true, 'data' => $decoded];
    }

    /**
     * Obtiene el saldo disponible de la cuenta en el proveedor
     */
    public function getBalance(): array {
        $res = $this->request(['action' => 'balance']);
        if (!$res['success']) return $res;

        if (isset($res['data']['balance'])) {
            return [
                'success' => true,
                'balance' => (float)$res['data']['balance'],
                'currency' => $res['data']['currency'] ?? 'USD'
            ];
        }

        return ['success' => false, 'error' => $res['data']['error'] ?? 'No se pudo obtener el saldo'];
    }

    /**
     * Obtiene el catálogo completo de servicios del proveedor
     */
    public function getServices(): array {
        $res = $this->request(['action' => 'services']);
        if (!$res['success']) return $res;

        return ['success' => true, 'services' => $res['data']];
    }

    /**
     * Envia una nueva orden al proveedor
     */
    public function addOrder(int $service_id, string $link, int $quantity): array {
        $res = $this->request([
            'action'  => 'add',
            'service' => $service_id,
            'link'    => $link,
            'quantity'=> $quantity
        ]);

        if (!$res['success']) return $res;

        if (isset($res['data']['order'])) {
            return [
                'success'  => true,
                'order_id' => (int)$res['data']['order'],
                'raw'      => json_encode($res['data'])
            ];
        }

        return [
            'success' => false,
            'error'   => $res['data']['error'] ?? 'El proveedor rechazó la orden sin detalle',
            'raw'     => json_encode($res['data'])
        ];
    }

    /**
     * Consulta el estado de una orden enviada al proveedor
     */
    public function getOrderStatus(int $provider_order_id): array {
        $res = $this->request([
            'action' => 'status',
            'order'  => $provider_order_id
        ]);

        if (!$res['success']) return $res;

        return ['success' => true, 'status_data' => $res['data']];
    }
}
