<?php
/**
 * Cliente Universal de API SMM v2 (Multi-Proveedor)
 * Compatible con SolydSMM, JustAnotherPanel, SMMKings, Peakerr, Secsers y cualquier panel SMM v2
 * Turbogram
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings.php';

class GenericSMM_API {
    private string $api_url;
    private string $api_key;
    private ?int $provider_id = null;
    private ?string $provider_name = null;

    /**
     * @param int|array|null $providerOrId ID del proveedor, array con datos o null para usar el predeterminado
     * @param string|null $manualKey API Key manual (opcional si se pasan credenciales directas)
     * @param string|null $manualUrl URL manual (opcional si se pasan credenciales directas)
     */
    public function __construct($providerOrId = null, ?string $manualKey = null, ?string $manualUrl = null) {
        if ($manualKey !== null && $manualUrl !== null) {
            $this->api_key = $manualKey;
            $this->api_url = rtrim($manualUrl, '/');
            $this->provider_name = 'Manual';
            return;
        }

        $providerData = null;

        if (is_int($providerOrId) && $providerOrId > 0) {
            $providerData = self::getProviderById($providerOrId);
        } elseif (is_array($providerOrId) && isset($providerOrId['api_url'], $providerOrId['api_key'])) {
            $providerData = $providerOrId;
        }

        if (!$providerData) {
            $providerData = self::getDefaultProvider();
        }

        if ($providerData) {
            $this->provider_id   = isset($providerData['id']) ? (int)$providerData['id'] : null;
            $this->provider_name = $providerData['name'] ?? 'Proveedor';
            $this->api_url       = rtrim($providerData['api_url'], '/');
            $this->api_key       = $providerData['api_key'];
        } else {
            // Fallback histórico a configuraciones generales
            $this->api_url       = rtrim(Settings::get('provider_api_url', 'https://solydsmm.com/api/v2'), '/');
            $this->api_key       = Settings::get('provider_api_key', '');
            $this->provider_name = 'Fallback';
        }
    }

    public function getProviderId(): ?int {
        return $this->provider_id;
    }

    public function getProviderName(): ?string {
        return $this->provider_name;
    }

    public function getApiUrl(): string {
        return $this->api_url;
    }

    /**
     * Obtiene un proveedor por su ID
     */
    public static function getProviderById(int $id): ?array {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            return $res ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Obtiene el proveedor predeterminado activo
     */
    public static function getDefaultProvider(): ?array {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT * FROM providers WHERE is_default = 1 AND status = 1 LIMIT 1");
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$res) {
                $stmt = $pdo->query("SELECT * FROM providers WHERE status = 1 ORDER BY id ASC LIMIT 1");
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            return $res ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Obtiene todos los proveedores
     */
    public static function getAllProviders(bool $onlyActive = false): array {
        try {
            $pdo = Database::getConnection();
            $sql = "SELECT * FROM providers";
            if ($onlyActive) {
                $sql .= " WHERE status = 1";
            }
            $sql .= " ORDER BY is_default DESC, name ASC";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Realiza una petición cURL a la API del proveedor
     */
    private function request(array $params): array {
        if (empty($this->api_url) || empty($this->api_key)) {
            return [
                'success' => false,
                'error'   => 'URL o API Key del proveedor no configuradas'
            ];
        }

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
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) TurbogramBot/2.0');

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'Error de conexión cURL: ' . $error];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return [
                'success'   => false,
                'error'     => 'Respuesta no válida del proveedor (HTTP ' . $http_code . '): ' . substr($response, 0, 200),
                'raw'       => $response,
                'http_code' => $http_code
            ];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $http_code];
    }

    /**
     * Obtiene el saldo disponible de la cuenta en el proveedor
     */
    public function getBalance(): array {
        $res = $this->request(['action' => 'balance']);
        if (!$res['success']) return $res;

        if (isset($res['data']['balance'])) {
            $balance = (float)$res['data']['balance'];
            $currency = $res['data']['currency'] ?? 'USD';

            // Actualizar balance en base de datos si tenemos provider_id
            if ($this->provider_id) {
                try {
                    $pdo = Database::getConnection();
                    $stmt = $pdo->prepare("
                        UPDATE providers 
                        SET balance = ?, balance_currency = ?, last_balance_check = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$balance, $currency, $this->provider_id]);
                } catch (Exception $e) {
                    // Ignorar error de actualización de caché
                }
            }

            return [
                'success'  => true,
                'balance'  => $balance,
                'currency' => $currency
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
            'action'   => 'add',
            'service'  => $service_id,
            'link'     => $link,
            'quantity' => $quantity
        ]);

        if (!$res['success']) return $res;

        if (isset($res['data']['order'])) {
            return [
                'success'     => true,
                'order_id'    => (int)$res['data']['order'],
                'provider_id' => $this->provider_id,
                'raw'         => json_encode($res['data'])
            ];
        }

        return [
            'success'     => false,
            'provider_id' => $this->provider_id,
            'error'       => $res['data']['error'] ?? 'El proveedor rechazó la orden sin detalle',
            'raw'         => json_encode($res['data'])
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
