<?php
/**
 * Servicio de Autenticación de Dos Factores (TOTP - RFC 6238) y Cifrado AES-256-GCM
 * Turbogram - Fase 4 Security Hardening
 */

class TOTP_Service {

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    /**
     * Obtiene la clave simétrica externa para cifrar secretos TOTP en reposo (AES-256-GCM).
     * Se busca en:
     * 1. Variable de entorno APP_2FA_ENCRYPTION_KEY
     * 2. Constante APP_2FA_ENCRYPTION_KEY
     * 3. Archivo config/2fa.key (fuera o protegido en servidor)
     * En producción, si no existe, lanza excepción estricta (Pilar 4).
     */
    public static function getEncryptionKey(): string {
        $envKey = getenv('APP_2FA_ENCRYPTION_KEY');
        if (!empty($envKey)) {
            return self::normalizeKey($envKey);
        }

        if (defined('APP_2FA_ENCRYPTION_KEY') && !empty(constant('APP_2FA_ENCRYPTION_KEY'))) {
            return self::normalizeKey(constant('APP_2FA_ENCRYPTION_KEY'));
        }

        $keyFile = __DIR__ . '/../config/2fa.key';
        if (file_exists($keyFile)) {
            $fileKey = trim((string)file_get_contents($keyFile));
            if (!empty($fileKey)) {
                return self::normalizeKey($fileKey);
            }
        }

        // Solo se permite generación de clave local si el entorno es explícitamente development o testing
        $appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: (defined('APP_ENV') ? constant('APP_ENV') : ''))));
        $isExplicitDevOrTest = in_array($appEnv, ['development', 'testing'], true);

        if (!$isExplicitDevOrTest) {
            throw new \RuntimeException(
                'Error crítico de seguridad: Clave externa APP_2FA_ENCRYPTION_KEY no configurada. ' .
                'Cuando el entorno no es explícitamente "development" o "testing", se exige una clave privada segura de 256 bits.'
            );
        }

        // En desarrollo/testing explícito: generar una clave de 256 bits y almacenarla en config/2fa.key
        $generated = bin2hex(random_bytes(32));
        @file_put_contents($keyFile, $generated);
        @chmod($keyFile, 0600);

        return hex2bin($generated);
    }

    private static function normalizeKey(string $key): string {
        $clean = trim($key);
        if (strlen($clean) === 64 && ctype_xdigit($clean)) {
            return hex2bin($clean);
        }
        if (strlen($clean) === 32) {
            return $clean;
        }

        // PROHIBIDO: Convertir textos arbitrarios o contraseñas cortas con SHA-256. Exigir 256 bits estrictos.
        throw new \InvalidArgumentException(
            'Clave de cifrado 2FA débil o inválida: debe tener exactamente 256 bits ' .
            '(64 caracteres hexadecimales o 32 bytes binarios).'
        );
    }

    /**
     * Cifra un secreto TOTP con AES-256-GCM en reposo.
     * Formato: enc:v1:<base64(iv(12) . tag(16) . ciphertext)>
     */
    public static function encryptSecret(string $plainSecret, ?string $keyOverride = null): string {
        if ($plainSecret === '') {
            return '';
        }

        $key = $keyOverride ? self::normalizeKey($keyOverride) : self::getEncryptionKey();
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plainSecret,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Error al cifrar el secreto TOTP con AES-256-GCM.');
        }

        return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Desencripta un secreto TOTP cifrado con AES-256-GCM.
     */
    public static function decryptSecret(string $payload, ?string $keyOverride = null): string {
        if ($payload === '') {
            return '';
        }

        if (!str_starts_with($payload, 'enc:v1:')) {
            return '';
        }

        $raw = base64_decode(substr($payload, 7), true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $key = $keyOverride ? self::normalizeKey($keyOverride) : self::getEncryptionKey();

        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Genera un secreto aleatorio codificado en Base32 (160 bits de entropía).
     */
    public static function generateSecret(int $byteLength = 20): string {
        $bytes = random_bytes($byteLength);
        return self::base32Encode($bytes);
    }

    /**
     * Codifica bytes binarios a Base32 (RFC 4648 sin padding).
     */
    public static function base32Encode(string $data): string {
        if ($data === '') {
            return '';
        }

        $alphabet = self::BASE32_ALPHABET;
        $binary = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $b32 = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $b32 .= $alphabet[bindec($chunk)];
        }

        return $b32;
    }

    /**
     * Decodifica una cadena Base32 a bytes binarios.
     */
    public static function base32Decode(string $b32): string {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        if ($clean === '') {
            return '';
        }

        $alphabet = self::BASE32_ALPHABET;
        $binary = '';
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $clean[$i]);
            if ($pos === false) {
                return '';
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        $byteChunks = str_split($binary, 8);
        foreach ($byteChunks as $byteChunk) {
            if (strlen($byteChunk) === 8) {
                $bytes .= chr(bindec($byteChunk));
            }
        }

        return $bytes;
    }

    /**
     * Calcula el código TOTP RFC 6238 para un step o timestamp dado.
     */
    public static function generateCode(string $secret, ?int $timestamp = null, int $stepSeconds = 30): string {
        $ts = $timestamp ?? time();
        $counter = (int)floor($ts / $stepSeconds);

        $binaryCounter = pack('N*', 0, $counter);
        $binaryKey = self::base32Decode($secret);

        $hash = hash_hmac('sha1', $binaryCounter, $binaryKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $unpacked = unpack('N', substr($hash, $offset, 4));
        $truncated = ($unpacked[1] ?? 0) & 0x7FFFFFFF;

        return str_pad((string)($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica un código TOTP considerando una ventana de tolerancia de ±$window pasos (por defecto ±1 = 90s).
     * Incorpora protección Anti-Replay: el paso debe ser estrictamente mayor a $lastStep.
     */
    public static function verifyCode(
        string $secret,
        string $inputCode,
        ?int $lastStep = null,
        int $window = 1,
        ?int $timestamp = null
    ): array {
        $cleanCode = preg_replace('/\s+/', '', $inputCode);
        if (!preg_match('/^\d{6}$/', $cleanCode)) {
            return [
                'valid' => false,
                'step' => 0,
                'message' => 'El código debe contener exactamente 6 dígitos numéricos.'
            ];
        }

        $currentStep = (int)floor(($timestamp ?? time()) / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $testStep = $currentStep + $i;
            $expectedCode = self::generateCodeForStep($secret, $testStep);

            if (hash_equals($expectedCode, $cleanCode)) {
                if ($lastStep !== null && $testStep <= $lastStep) {
                    return [
                        'valid' => false,
                        'step' => $testStep,
                        'message' => 'Este código ya fue utilizado en esta ventana. Espere al siguiente código de su app.'
                    ];
                }

                return [
                    'valid' => true,
                    'step' => $testStep,
                    'message' => 'Código TOTP verificado correctamente.'
                ];
            }
        }

        return [
            'valid' => false,
            'step' => 0,
            'message' => 'Código de autenticación incorrecto o expirado.'
        ];
    }

    private static function generateCodeForStep(string $secret, int $step): string {
        $binaryCounter = pack('N*', 0, $step);
        $binaryKey = self::base32Decode($secret);
        $hash = hash_hmac('sha1', $binaryCounter, $binaryKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $unpacked = unpack('N', substr($hash, $offset, 4));
        $truncated = ($unpacked[1] ?? 0) & 0x7FFFFFFF;

        return str_pad((string)($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Construye la URI estándar otpauth:// para escanear en apps autenticadoras.
     */
    public static function getOtpAuthUri(string $username, string $secret, string $issuer = 'Turbogram'): string {
        $cleanUser = rawurlencode($username);
        $cleanIssuer = rawurlencode($issuer);
        return "otpauth://totp/{$cleanIssuer}:{$cleanUser}?secret={$secret}&issuer={$cleanIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Genera un conjunto de 8 códigos de recuperación de un solo uso.
     * Formato: XXXX-XXXX (alfanumérico legible sin ambigüedades).
     */
    public static function generateRecoveryCodes(int $count = 8): array {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Excluye 0, O, 1, I
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $part1 = '';
            $part2 = '';
            for ($j = 0; $j < 4; $j++) {
                $part1 .= $chars[random_int(0, strlen($chars) - 1)];
                $part2 .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $codes[] = "{$part1}-{$part2}";
        }

        return $codes;
    }

    /**
     * Almacena un conjunto de códigos de recuperación para un administrador con hash criptográfico.
     * Invalida los códigos anteriores no consumidos.
     */
    public static function storeRecoveryCodes(\PDO $pdo, int $adminId, array $codes): void {
        $pdo->beginTransaction();
        try {
            $delStmt = $pdo->prepare("DELETE FROM admin_recovery_codes WHERE admin_id = ?");
            $delStmt->execute([$adminId]);

            $insStmt = $pdo->prepare("INSERT INTO admin_recovery_codes (admin_id, code_hash, created_at) VALUES (?, ?, NOW())");
            foreach ($codes as $code) {
                $hash = password_hash(strtoupper(trim($code)), PASSWORD_DEFAULT);
                $insStmt->execute([$adminId, $hash]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Activa o sustituye el doble factor y sus códigos de recuperación de forma atómica en una ÚNICA transacción.
     * Si cualquier parte falla, revierte todo (rollback) para evitar inconsistencias de configuración.
     */
    public static function activate2FA(\PDO $pdo, int $adminId, string $encryptedSecret, int $step, array $recoveryCodes): void {
        $pdo->beginTransaction();
        try {
            // 1. Invalidar códigos de recuperación anteriores
            $delStmt = $pdo->prepare("DELETE FROM admin_recovery_codes WHERE admin_id = ?");
            $delStmt->execute([$adminId]);

            // 2. Insertar los nuevos códigos de recuperación con hash seguro
            $insStmt = $pdo->prepare("INSERT INTO admin_recovery_codes (admin_id, code_hash, created_at) VALUES (?, ?, NOW())");
            foreach ($recoveryCodes as $code) {
                $hash = password_hash(strtoupper(trim($code)), PASSWORD_DEFAULT);
                $insStmt->execute([$adminId, $hash]);
            }

            // 3. Actualizar la cuenta del administrador con el secreto cifrado y el paso inicial
            $upd = $pdo->prepare("
                UPDATE admins 
                SET totp_secret = :secret, 
                    totp_enabled = 1, 
                    totp_last_step = :step,
                    failed_2fa_attempts = 0,
                    lockout_2fa_until = NULL
                WHERE id = :id
            ");
            $upd->execute([
                ':secret' => $encryptedSecret,
                ':step'   => $step,
                ':id'     => $adminId
            ]);

            if ($upd->rowCount() !== 1) {
                throw new \RuntimeException('No se pudo actualizar el registro del administrador al activar 2FA.');
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Verifica y consume un código de recuperación de forma atómica (un solo uso).
     */
    public static function verifyAndConsumeRecoveryCode(\PDO $pdo, int $adminId, string $inputCode): bool {
        $cleanInput = strtoupper(preg_replace('/[^A-Z0-9]/', '', $inputCode));
        if (strlen($cleanInput) < 6) {
            return false;
        }

        // Variantes: con guión o plano
        $stmt = $pdo->prepare("SELECT id, code_hash FROM admin_recovery_codes WHERE admin_id = ? AND consumed_at IS NULL");
        $stmt->execute([$adminId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            // Compara directamente con la variante enviada y con guión
            $hyphenated = strlen($cleanInput) === 8 ? substr($cleanInput, 0, 4) . '-' . substr($cleanInput, 4) : $cleanInput;

            if (password_verify(trim($inputCode), $row['code_hash']) ||
                password_verify($hyphenated, $row['code_hash']) ||
                password_verify($cleanInput, $row['code_hash'])) {

                // Consumo atómico condicionado a consumed_at IS NULL
                $upd = $pdo->prepare("UPDATE admin_recovery_codes SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL");
                $upd->execute([$row['id']]);

                return $upd->rowCount() === 1;
            }
        }

        return false;
    }

    /**
     * Obtiene el número de códigos de recuperación disponibles para un administrador.
     */
    public static function getAvailableRecoveryCodesCount(\PDO $pdo, int $adminId): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND consumed_at IS NULL");
        $stmt->execute([$adminId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Rate Limiting y Lockout para intentos de 2FA.
     */
    public static function check2FALockout(\PDO $pdo, int $adminId): array {
        $stmt = $pdo->prepare("SELECT failed_2fa_attempts, lockout_2fa_until FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['locked' => false];
        }

        if (!empty($row['lockout_2fa_until'])) {
            $lockoutTime = strtotime($row['lockout_2fa_until']);
            $remainingSeconds = $lockoutTime - time();

            if ($remainingSeconds > 0) {
                $remMinutes = ceil($remainingSeconds / 60);
                return [
                    'locked' => true,
                    'remaining_minutes' => (int)$remMinutes,
                    'message' => "Acceso por doble factor bloqueado temporalmente por demasiados intentos. Reintente en {$remMinutes} minuto(s)."
                ];
            } else {
                // Desbloqueo expirado: reiniciar contador
                self::reset2FALockout($pdo, $adminId);
            }
        }

        return ['locked' => false];
    }

    public static function recordFailed2FA(\PDO $pdo, int $adminId): int {
        $stmt = $pdo->prepare("
            UPDATE admins 
            SET failed_2fa_attempts = failed_2fa_attempts + 1,
                lockout_2fa_until = CASE 
                    WHEN failed_2fa_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                    ELSE lockout_2fa_until
                END
            WHERE id = ?
        ");
        $stmt->execute([self::MAX_ATTEMPTS, self::LOCKOUT_MINUTES, $adminId]);

        $checkStmt = $pdo->prepare("SELECT failed_2fa_attempts FROM admins WHERE id = ?");
        $checkStmt->execute([$adminId]);
        return (int)$checkStmt->fetchColumn();
    }

    public static function reset2FALockout(\PDO $pdo, int $adminId): void {
        $stmt = $pdo->prepare("UPDATE admins SET failed_2fa_attempts = 0, lockout_2fa_until = NULL WHERE id = ?");
        $stmt->execute([$adminId]);
    }

    /**
     * Rate Limiting y Lockout para intentos de contraseña a nivel de cuenta.
     */
    public static function checkAccountPasswordLockout(\PDO $pdo, int $adminId): array {
        $stmt = $pdo->prepare("SELECT failed_login_attempts, lockout_login_until FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['locked' => false];
        }

        if (!empty($row['lockout_login_until'])) {
            $lockoutTime = strtotime($row['lockout_login_until']);
            $remainingSeconds = $lockoutTime - time();

            if ($remainingSeconds > 0) {
                $remMinutes = ceil($remainingSeconds / 60);
                return [
                    'locked' => true,
                    'remaining_minutes' => (int)$remMinutes,
                    'message' => "Cuenta temporalmente bloqueada por reiterados intentos fallidos. Reintente en {$remMinutes} minuto(s)."
                ];
            } else {
                self::resetAccountPasswordLockout($pdo, $adminId);
            }
        }

        return ['locked' => false];
    }

    public static function recordFailedPassword(\PDO $pdo, int $adminId): void {
        $stmt = $pdo->prepare("
            UPDATE admins 
            SET failed_login_attempts = failed_login_attempts + 1,
                lockout_login_until = CASE 
                    WHEN failed_login_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                    ELSE lockout_login_until
                END
            WHERE id = ?
        ");
        $stmt->execute([self::MAX_ATTEMPTS, self::LOCKOUT_MINUTES, $adminId]);
    }

    public static function resetAccountPasswordLockout(\PDO $pdo, int $adminId): void {
        $stmt = $pdo->prepare("UPDATE admins SET failed_login_attempts = 0, lockout_login_until = NULL WHERE id = ?");
        $stmt->execute([$adminId]);
    }

    /**
     * Reautenticación estricta: exige contraseña actual válida Y código TOTP actual válido.
     * Incorpora límites de intentos (Lockout de contraseña y de 2FA), auditoría y actualización atómica anti-replay.
     */
    public static function verifyReauth(\PDO $pdo, int $adminId, string $currentPassword, string $totpCode): array {
        // 0. Comprobar lockouts activos antes de procesar
        $passLock = self::checkAccountPasswordLockout($pdo, $adminId);
        if ($passLock['locked']) {
            return ['success' => false, 'error' => $passLock['message']];
        }

        $twoFaLock = self::check2FALockout($pdo, $adminId);
        if ($twoFaLock['locked']) {
            return ['success' => false, 'error' => $twoFaLock['message']];
        }

        $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$admin) {
            return ['success' => false, 'error' => 'Administrador no encontrado.'];
        }

        // 1. Validar Contraseña
        if (!password_verify($currentPassword, $admin['password'])) {
            self::recordFailedPassword($pdo, $adminId);
            if (function_exists('log_audit')) {
                log_audit('ADMIN_REAUTH_PASSWORD_FAILED', "Contraseña incorrecta en reautenticación para {$admin['username']}");
            }
            $reCheckPass = self::checkAccountPasswordLockout($pdo, $adminId);
            if ($reCheckPass['locked']) {
                return ['success' => false, 'error' => $reCheckPass['message']];
            }
            return ['success' => false, 'error' => 'La contraseña actual ingresada es incorrecta.'];
        }

        // 2. Si 2FA está activo, validar código TOTP
        if (!empty($admin['totp_enabled'])) {
            $plainSecret = self::decryptSecret($admin['totp_secret'] ?? '');
            if (empty($plainSecret)) {
                return ['success' => false, 'error' => 'Error al desencriptar el secreto 2FA configurado.'];
            }

            $totpResult = self::verifyCode($plainSecret, $totpCode, $admin['totp_last_step'] ?? null);
            if (!$totpResult['valid']) {
                $attempts = self::recordFailed2FA($pdo, $adminId);
                if (function_exists('log_audit')) {
                    log_audit('ADMIN_REAUTH_2FA_FAILED', "Código 2FA incorrecto en reautenticación para {$admin['username']} (intento {$attempts})");
                }
                $reCheck2fa = self::check2FALockout($pdo, $adminId);
                if ($reCheck2fa['locked']) {
                    return ['success' => false, 'error' => $reCheck2fa['message']];
                }
                return ['success' => false, 'error' => $totpResult['message']];
            }

            // Actualización atómica del paso anti-replay: solo tiene éxito si newStep > totp_last_step
            $upd = $pdo->prepare("
                UPDATE admins 
                SET totp_last_step = :newStep 
                WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :newStepCheck)
            ");
            $upd->execute([
                ':newStep' => $totpResult['step'],
                ':id' => $adminId,
                ':newStepCheck' => $totpResult['step']
            ]);

            if ($upd->rowCount() !== 1) {
                return [
                    'success' => false, 
                    'error' => 'El código de segundo factor ya fue consumido en otra solicitud simultánea (protección anti-repetición atómica).'
                ];
            }
        }

        // Éxito: restablecer contadores de intentos fallidos
        self::resetAccountPasswordLockout($pdo, $adminId);
        self::reset2FALockout($pdo, $adminId);

        if (function_exists('log_audit')) {
            log_audit('ADMIN_REAUTH_SUCCESS', "Reautenticación exitosa para {$admin['username']}");
        }

        return ['success' => true];
    }

    /**
     * Desactiva de forma atómica el doble factor y elimina todos los códigos de recuperación.
     * Ambas operaciones se ejecutan dentro de una única transacción PDO con rollback.
     */
    public static function disable2FA(\PDO $pdo, int $adminId): void {
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM admin_recovery_codes WHERE admin_id = ?");
            $del->execute([$adminId]);

            $upd = $pdo->prepare("
                UPDATE admins 
                SET totp_enabled = 0, 
                    totp_secret = NULL, 
                    totp_last_step = NULL,
                    failed_2fa_attempts = 0,
                    lockout_2fa_until = NULL
                WHERE id = ?
            ");
            $upd->execute([$adminId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Flujo completo de verificación de segundo factor para inicio de sesión:
     * - Rate limiting / Lockout de 2FA
     * - Verificación TOTP (RFC 6238) con descifrado AES-256-GCM
     * - Consumo atómico condicionado de paso anti-repetición (rowCount === 1)
     * - Fallback a códigos de recuperación de un solo uso
     * - Registro de fallos y auditoría
     */
    public static function verifyLogin2FA(\PDO $pdo, int $adminId, string $authCode): array {
        // 1. Lockout check
        $lockCheck = self::check2FALockout($pdo, $adminId);
        if ($lockCheck['locked']) {
            return [
                'success' => false,
                'is_recovery' => false,
                'error' => $lockCheck['message'],
                'reason' => 'lockout'
            ];
        }

        $cleanCode = preg_replace('/\s+/', '', $authCode);
        if ($cleanCode === '') {
            return [
                'success' => false,
                'is_recovery' => false,
                'error' => 'Por favor, ingresá tu código de 6 dígitos o un código de recuperación.',
                'reason' => 'empty'
            ];
        }

        // Obtener datos del admin
        $stmt = $pdo->prepare("SELECT id, username, totp_secret, totp_enabled, totp_last_step FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$admin || empty($admin['totp_enabled'])) {
            return [
                'success' => false,
                'is_recovery' => false,
                'error' => 'La autenticación de doble factor no está habilitada para esta cuenta.',
                'reason' => 'not_enabled'
            ];
        }

        $authenticated = false;
        $isRecovery = false;
        $specificError = '';

        // 2. Probar como código TOTP si es numérico de 6 dígitos
        if (preg_match('/^\d{6}$/', $cleanCode)) {
            $plainSecret = self::decryptSecret($admin['totp_secret'] ?? '');
            if ($plainSecret !== '') {
                $totpResult = self::verifyCode(
                    $plainSecret,
                    $cleanCode,
                    $admin['totp_last_step'] ?? null
                );

                if ($totpResult['valid']) {
                    // Actualizar step y resetear fallos de forma atómica condicional
                    $upd = $pdo->prepare("
                        UPDATE admins 
                        SET totp_last_step = :newStep, 
                            failed_2fa_attempts = 0, 
                            lockout_2fa_until = NULL 
                        WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :newStepCheck)
                    ");
                    $upd->execute([
                        ':newStep' => $totpResult['step'],
                        ':id' => $adminId,
                        ':newStepCheck' => $totpResult['step']
                    ]);

                    if ($upd->rowCount() === 1) {
                        return [
                            'success' => true,
                            'is_recovery' => false,
                            'step' => $totpResult['step'],
                            'username' => $admin['username'],
                            'error' => null
                        ];
                    } else {
                        $specificError = 'Este código ya fue consumido en otra solicitud simultánea (protección anti-repetición atómica).';
                    }
                } else {
                    $specificError = $totpResult['message'];
                }
            } else {
                $specificError = 'Error de descifrado del secreto 2FA. Contacte soporte.';
            }
        }

        // 3. Si no es TOTP o falló, probar como código de recuperación de un solo uso
        if (!$authenticated) {
            $recoveryConsumed = self::verifyAndConsumeRecoveryCode($pdo, $adminId, $authCode);
            if ($recoveryConsumed) {
                self::reset2FALockout($pdo, $adminId);
                return [
                    'success' => true,
                    'is_recovery' => true,
                    'step' => null,
                    'username' => $admin['username'],
                    'error' => null
                ];
            }
        }

        // 4. Intento fallido
        $attempts = self::recordFailed2FA($pdo, $adminId);
        $reCheck = self::check2FALockout($pdo, $adminId);
        $errorMessage = $reCheck['locked'] ? $reCheck['message'] : ($specificError ?: 'Código de verificación incorrecto o expirado.');

        return [
            'success' => false,
            'is_recovery' => false,
            'error' => $errorMessage,
            'reason' => 'invalid_code',
            'attempts' => $attempts
        ];
    }
}

