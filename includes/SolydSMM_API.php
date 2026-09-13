<?php
/**
 * Adaptador / Compatibilidad para SolydSMM_API
 * Hereda de GenericSMM_API para soportar múltiples proveedores sin romper compatibilidad histórica.
 * Turbogram
 */

require_once __DIR__ . '/GenericSMM_API.php';

class SolydSMM_API extends GenericSMM_API {
    public function __construct(?string $key = null, ?string $url = null, ?int $provider_id = null) {
        if ($provider_id !== null) {
            parent::__construct($provider_id);
        } elseif ($key !== null && $url !== null) {
            parent::__construct(null, $key, $url);
        } else {
            // Usa el proveedor predeterminado de la tabla providers o fallback
            parent::__construct(null);
        }
    }
}
