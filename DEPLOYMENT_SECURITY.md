# Entrega segura de Turbogram

Antes de publicar, verifique todas las credenciales y asegúrese de que ninguna haya estado expuesta en repositorios públicos. Las credenciales actuales de Mercado Pago pueden conservarse si se mantuvieron en reserva privada; no es necesario rotarlas únicamente por trasladar el hosting.

## 1. Servidor y Entorno

1. **Configuración de Entorno:** Configure explícitamente en el entorno del hosting `APP_ENV=production`. Esto desactiva automáticamente cualquier simulación de desarrollo y exige credenciales privadas obligatorias.
2. **Sincronización Horaria (NTP):** Mantenga rigurosamente sincronizado el reloj del sistema del servidor mediante NTP. La verificación de tokens TOTP RFC 6238 (ventanas de 30s) y la firma HMAC de notificaciones Webhook (tolerancia de 5 min) dependen de la hora exacta del servidor.
3. **Archivos a Publicar:** Publique únicamente los archivos de la aplicación (los 46 archivos del paquete de entrega). No suba `.git`, `.gitignore`, copias `.zip`, volcados `.sql`, archivos `.key` ni scripts de prueba.
4. **HTTPS:** Active HTTPS con un certificado TLS válido y fuerce la redirección incondicional de HTTP a HTTPS desde el panel o servidor web.
5. **Protección de Archivos Privados:** Mantenga `AllowOverride All` en Apache (o directivas equivalentes en Nginx) para que las reglas de bloqueo se apliquen. Respuestas `403 Forbidden` o `404 Not Found` son ambas perfectamente aceptables; lo crítico es que el servidor web nunca entregue el contenido en texto plano.
6. **Configuración PHP:** Use PHP 8.1 o superior, con `display_errors=Off`, `log_errors=On`, `expose_php=Off` y permisos de archivos `0644` (directorios `0755`), nunca `0777`.

## 2. Base de Datos y Mínimo Privilegio

`config/database.php` no contiene credenciales y prohíbe valores por defecto en producción. Configure en las variables de entorno privadas del hosting: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` y opcionalmente `DB_CHARSET=utf8mb4`.

1. **Importación Inicial (Cuenta de Instalación):** Importe `schema.sql` una sola vez desde la herramienta administrativa privada del hosting (phpMyAdmin o CLI) usando una cuenta con permisos DDL (`CREATE`, `INDEX`).
2. **Usuario Habitual de la Aplicación (Mínimo Privilegio):** Cree un usuario exclusivo para la aplicación (e.g. `turbogram_app`) y asígnele estrictamente permisos DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`) únicamente sobre la base de datos `turbogram_db`.
3. **Automigraciones:** `schema.sql` ya contiene la totalidad de tablas (`providers`, `admin_recovery_codes`) y columnas (`provider_id` en `services` y `orders`). **No otorgue permisos administrativos ni de `ALTER`/`CREATE` al usuario habitual** de la aplicación para resolver comprobaciones automáticas residuales.
4. **Resguardos de BD:** Guarde los respaldos fuera del directorio público `htdocs` y pruebe periódicamente su restauración en una base temporal para verificar su integridad.

## 3. Primer Acceso Administrativo

El esquema no crea cuentas predeterminadas. Cree la primera cuenta tras importar la base. Genere un hash con `password_hash('una-clave-larga-y-unica', PASSWORD_DEFAULT)` y guárdelo en `admins.password`. Use una contraseña aleatoria de al menos 16 caracteres y regístrela en un gestor de contraseñas.

## 4. Configuración de Pagos y Webhook Fail-Closed

1. Al ingresar por primera vez al panel (`admin/settings.php`), configure la **URL pública segura** con la dirección final HTTPS (e.g. `https://dominio-del-cliente.com`). Sin esa URL los cobros se bloquean preventivamente en producción.
2. Ingrese las credenciales de Mercado Pago (Access Token, Public Key y la **Secret Key del Webhook**) y del proveedor SMM.
3. **Resguardo de Credenciales:** El enmascaramiento con `••••••••` en el panel previene la visualización involuntaria en pantalla. Tenga en cuenta que en la tabla `settings` se almacenan en texto de configuración; por ende, proteger la base de datos contra accesos no autorizados y volcados públicos es crucial.
4. **Webhook Fail-Closed:** Cada notificación valida la firma HMAC-SHA256 (`x-signature` y `x-request-id`) sobre el manifiesto canónico antes de consultar pagos o despachar pedidos. Soporta timestamps en segundos y milisegundos, responde HTTP 503 ante caídas temporales de Mercado Pago (permitiendo reintentos) y HTTP 200 idempotente ante entregas exitosas o repetidas.

## 5. Doble Factor de Autenticación (2FA) y Cifrado AES-256-GCM

1. Defina en el entorno privado del servidor la clave externa `APP_2FA_ENCRYPTION_KEY` con 64 caracteres hexadecimales (256 bits seguros). Alternativamente, almacénela en `config/2fa.key` (con permisos `0600` e ignorada por Git).
2. **Copia de Seguridad de la Clave:** Guarde una copia segura y privada de la clave de cifrado simétrica fuera del servidor (en un gestor de contraseñas o baúl de claves). Si la clave de cifrado se pierde, los secretos 2FA almacenados en la base de datos no podrán ser descifrados.
3. Active el 2FA de inmediato en `admin/security.php`:
   - Escanee el código QR local o ingrese la clave Base32 en su aplicación autenticadora.
   - Confirme con un primer código válido de 6 dígitos.
   - Guarde de inmediato los 8 códigos de recuperación de un solo uso.
4. Las sesiones inactivas por más de 30 minutos caducan automáticamente y existe un límite absoluto de 8 horas. Desactivar 2FA o regenerar códigos exige reautenticación estricta con contraseña y TOTP actual.

## Comprobación y Checklist Final en el Hosting de Producción

Antes de entregar formalmente la plataforma al cliente, realice las siguientes comprobaciones obligatorias en el entorno real de despliegue:

1. **HTTPS y Certificado TLS:**
   - Verifique que todo el sitio cargue bajo HTTPS con certificado válido.
   - Compruebe que las solicitudes por HTTP redirijan automáticamente a HTTPS.
2. **Bloqueo Web de Archivos y Carpetas Privadas:**
   - Verifique directamente en el navegador que `https://dominio/config/database.php`, `https://dominio/includes/header.php`, `https://dominio/schema.sql`, `/.env`, `*.key` y cualquier archivo `.zip` devuelvan código `403 Forbidden` o `404 Not Found`.
3. **Principio de Mínimo Privilegio en Base de Datos:**
   - Asegúrese de que el usuario de base de datos (`DB_USER`) no sea `root` ni tenga privilegios administrativos globales (SUPER, GRANT, DROP DATABASE). Solo debe poseer permisos DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`) sobre la base de datos de Turbogram.
4. **Acceso Administrativo y Doble Factor (2FA):**
   - Inicie sesión en `admin/login.php` con las credenciales creadas.
   - Acceda a `admin/security.php` y active el 2FA con una aplicación autenticadora.
   - Cierre sesión y verifique el flujo de autenticación de dos pasos (`login.php` -> `login_2fa.php`) con un código TOTP.
   - Compruebe el uso de un código de recuperación de un solo uso.
5. **Circuito de Pagos de Extremo a Extremo:**
   - Genere un pedido de prueba real. Verifique la creación de preferencia en Mercado Pago.
   - Compruebe la recepción de la notificación webhook firmada con HMAC (`x-signature` y `x-request-id`) y que responda HTTP 200 sin procesar pagos ajenos o repetidos.
   - Verifique que `status.php?code=...&simulate_pay=1` falle de forma segura (Fail-Closed) sin aprobar pagos ni despachar servicios.
6. **Exportación CSV Segura:**
   - En el panel de pedidos (`admin/orders.php`), descargue la exportación CSV de ventas y confirme que cualquier dato con caracteres especiales no sea interpretado como fórmula ejecutable en hojas de cálculo.
7. **Monitoreo y Registros:**
   - Revise el archivo de registro de errores de PHP del hosting (`error_log`) para constatar que no existan advertencias ni excepciones no capturadas.
