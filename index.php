<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/security.php';

$pdo = Database::getConnection();

// 1. Obtener oferta activa con banner para la web
$stmtBanner = $pdo->query("
    SELECT * FROM coupons 
    WHERE status = 1 AND show_banner = 1 AND (expires_at IS NULL OR expires_at > NOW()) 
    ORDER BY id DESC LIMIT 1
");
$activePromo = $stmtBanner->fetch();

// 2. Obtener categorías activas
$stmtCats = $pdo->query("SELECT * FROM categories WHERE status = 1 ORDER BY sort_order ASC");
$categories = $stmtCats->fetchAll();

// 3. Obtener servicios activos con datos de categoría
$stmtServs = $pdo->query("
    SELECT s.*, c.name as category_name, c.platform as cat_platform 
    FROM services s 
    JOIN categories c ON s.category_id = c.id 
    WHERE s.status = 1 AND c.status = 1 
    ORDER BY c.sort_order ASC, s.sort_order ASC
");
$services = $stmtServs->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Banner Flotante de Oferta de Tiempo Limitado -->
<?php if ($activePromo): ?>
    <div class="promo-banner" id="promoBanner" data-expires="<?= $activePromo['expires_at'] ? date('c', strtotime($activePromo['expires_at'])) : '' ?>">
        <div class="container promo-banner-content">
            <div class="promo-banner-left">
                <span class="promo-fire-badge"><i class="fa-solid fa-bolt"></i> OFERTA</span>
                <span class="promo-banner-text">
                    <?= htmlspecialchars($activePromo['banner_text'] ?: $activePromo['title']) ?>
                </span>
            </div>
            
            <div class="promo-banner-right">
                <?php if (!empty($activePromo['expires_at'])): ?>
                    <div class="promo-countdown" id="promoCountdown" title="Tiempo restante de la oferta">
                        <i class="fa-solid fa-stopwatch" style="color: var(--secondary);"></i>
                        <span id="cdHours">00</span>h : <span id="cdMinutes">00</span>m : <span id="cdSeconds">00</span>s
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-bg-glow"></div>
    <div class="container hero-content">
        <div class="hero-badge">
            <i class="fa-solid fa-fire"></i> Plataforma #1 en Argentina &bull; Pago en Pesos
        </div>
        <h1 class="hero-title">
            Impulsá tu presencia digital <span>en segundos</span>
        </h1>
        <p class="hero-subtitle">
            Seguidores, Likes, Reproducciones y Reacciones reales. Sin contraseña, sin registro y con entrega rápida garantizada.
        </p>

        <div class="hero-features">
            <div class="feature-pill"><i class="fa-solid fa-circle-check"></i> 100% Seguro sin contraseñas</div>
            <div class="feature-pill"><i class="fa-solid fa-circle-check"></i> Mercado Pago Oficial</div>
            <div class="feature-pill"><i class="fa-solid fa-circle-check"></i> Garantía de Recarga 30 días</div>
        </div>
    </div>
</section>

<!-- Seccion Calculadora / Personalizador de Servicio -->
<section class="customizer-section" id="servicios">
    <div class="container">
        <div class="customizer-card">
            <form id="orderForm" action="checkout.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                
                <div class="customizer-grid">
                    <!-- Columna Izquierda: Configuración del Paquete -->
                    <div class="customizer-left">
                        
                        <!-- Paso 1: Elegir Red Social -->
                        <div class="step-label">
                            <i class="fa-solid fa-1"></i> Seleccioná la Red Social
                        </div>
                        <div class="categories-grid">
                            <?php foreach ($categories as $index => $cat): ?>
                                <button type="button" 
                                        class="category-btn <?= $index === 0 ? 'active' : '' ?>" 
                                        data-category-id="<?= $cat['id'] ?>"
                                        data-platform="<?= htmlspecialchars($cat['platform']) ?>">
                                    <i class="<?= htmlspecialchars($cat['icon']) ?>"></i>
                                    <span><?= htmlspecialchars($cat['name']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Paso 2: Seleccionar Servicio -->
                        <div class="step-label">
                            <i class="fa-solid fa-2"></i> Elegí el Tipo de Servicio
                        </div>
                        <select name="service_id" id="serviceSelect" class="service-select">
                            <?php foreach ($services as $serv): ?>
                                <option value="<?= $serv['id'] ?>"
                                        data-category-id="<?= $serv['category_id'] ?>"
                                        data-platform="<?= htmlspecialchars($serv['cat_platform']) ?>"
                                        data-category="<?= htmlspecialchars($serv['category_name']) ?>"
                                        data-name="<?= htmlspecialchars($serv['name']) ?>"
                                        data-price="<?= $serv['price_per_1000'] ?>"
                                        data-min="<?= $serv['min_quantity'] ?>"
                                        data-max="<?= $serv['max_quantity'] ?>"
                                        data-placeholder="<?= htmlspecialchars($serv['input_placeholder']) ?>">
                                    <?= htmlspecialchars($serv['name']) ?> &bull; (<?= format_price($serv['price_per_1000']) ?> por 1.000)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Paso 3: Ajustar Cantidad -->
                        <div class="step-label">
                            <i class="fa-solid fa-3"></i> Elegí la Cantidad
                        </div>
                        <div class="quantity-box">
                            <div class="quantity-header">
                                <span class="quantity-title">Cantidad a enviar</span>
                                <div class="quantity-input-wrap">
                                    <input type="number" name="quantity" id="qtyInput" class="quantity-number-input" value="1000" min="100" max="50000" step="50">
                                </div>
                            </div>

                            <input type="range" id="qtySlider" class="range-slider" value="1000" min="100" max="50000" step="50">

                            <div class="preset-buttons">
                                <button type="button" class="preset-btn" data-qty="500">+500</button>
                                <button type="button" class="preset-btn" data-qty="1000">+1.000</button>
                                <button type="button" class="preset-btn" data-qty="2500">+2.500</button>
                                <button type="button" class="preset-btn" data-qty="5000">+5.000</button>
                                <button type="button" class="preset-btn" data-qty="10000">+10.000</button>
                            </div>
                        </div>

                    </div>

                    <!-- Columna Derecha: Datos de Destino y Resumen de Pago -->
                    <div class="customizer-right">
                        <div class="summary-card">
                            <div>
                                <div class="summary-header">
                                    <h3 class="summary-title"><i class="fa-solid fa-receipt"></i> Resumen de Compra</h3>
                                </div>

                                <div class="summary-item">
                                    <span class="label">Categoría:</span>
                                    <span class="value" id="summaryCategory">-</span>
                                </div>
                                <div class="summary-item">
                                    <span class="label">Servicio:</span>
                                    <span class="value" id="summaryService">-</span>
                                </div>
                                <div class="summary-item">
                                    <span class="label">Cantidad:</span>
                                    <span class="value" id="summaryQty">1.000</span>
                                </div>

                                <div class="summary-item" id="summaryDiscountRow" style="display: none; color: #4ade80;">
                                    <span class="label" style="color: #4ade80;"><i class="fa-solid fa-tag"></i> Descuento (<span id="summaryCouponName"></span>):</span>
                                    <span class="value" id="summaryDiscountAmount" style="color: #4ade80;">-$ 0</span>
                                </div>

                                <div class="price-box">
                                    <div class="price-label">Total a pagar en pesos</div>
                                    <div class="price-original" id="priceOriginal" style="display: none;"></div>
                                    <div class="price-amount" id="priceDisplay">$ 0</div>
                                    <div id="discountTag" style="display: none;"></div>
                                </div>

                                <!-- Sección Cupón de Descuento -->
                                <div class="form-group">
                                    <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                                        <span><i class="fa-solid fa-tag" style="color: var(--primary-light);"></i> ¿Tenés un cupón de descuento?</span>
                                        <span id="couponAppliedStatus" style="display: none; color: #4ade80; font-size: 0.8rem; font-weight: 700;"><i class="fa-solid fa-circle-check"></i> Aplicado</span>
                                    </label>
                                    
                                    <div class="coupon-row">
                                        <input type="text" name="coupon_code" id="couponInput" class="form-input" placeholder="Ingresá código" style="text-transform: uppercase; font-weight: 700; font-family: monospace;" value="">
                                        <button type="button" id="btnApplyCoupon" class="preset-btn" style="padding: 0 1.25rem; font-weight: 700; white-space: nowrap;">
                                            Aplicar
                                        </button>
                                        <button type="button" id="btnRemoveCoupon" class="preset-btn" style="display: none; background: rgba(239, 68, 68, 0.15); border-color: rgba(239,68,68,0.4); color: #fca5a5; padding: 0 0.85rem;" title="Quitar cupón">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                    <div id="couponFeedback" style="display: none; font-size: 0.85rem; margin-top: 0.4rem; padding: 0.4rem 0.75rem; border-radius: var(--radius-sm);"></div>
                                </div>

                                <!-- Formulario del Cliente -->
                                <div class="form-group">
                                    <label class="form-label" id="targetLabel">
                                        <i class="fa-solid fa-link"></i> Enlace o Nombre de Usuario:
                                    </label>
                                    <input type="text" name="target_link" id="targetInput" class="form-input" placeholder="Ej: @tu_usuario o link de publicación" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label"><i class="fa-solid fa-envelope"></i> Tu Email (para recibir comprobante):</label>
                                    <input type="email" name="buyer_email" id="emailInput" class="form-input" placeholder="tuemail@ejemplo.com" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label"><i class="fa-brands fa-whatsapp"></i> Teléfono / WhatsApp:</label>
                                    <input type="text" name="buyer_phone" id="phoneInput" class="form-input" placeholder="1112345678" required>
                                </div>

                                <label class="checkbox-label">
                                    <input type="checkbox" id="termsCheck" required checked>
                                    <span>Confirmo que el perfil es <strong>PÚBLICO</strong> y acepto los Términos de Servicio.</span>
                                </label>
                            </div>

                            <button type="submit" class="btn-submit-order">
                                <i class="fa-solid fa-bolt"></i> Continuar al Pago
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Modal de Confirmación previo a redirigir a Mercado Pago -->
                <div class="modal-overlay" id="confirmModal">
                    <div class="modal-card">
                        <h3 class="modal-title"><i class="fa-solid fa-circle-check" style="color: var(--primary-light);"></i> Confirmar Datos</h3>
                        
                        <div class="alert-warning">
                            <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.2rem; margin-top: 0.1rem;"></i>
                            <div>
                                <strong>Perfil Público Requerido:</strong><br>
                                Tu cuenta no debe estar en modo privado durante el procesamiento para garantizar la entrega.
                            </div>
                        </div>

                        <div class="summary-item" style="margin-bottom: 0.5rem;">
                            <span class="label">Servicio:</span>
                            <span class="value" id="modalService"></span>
                        </div>
                        <div class="summary-item" style="margin-bottom: 0.5rem;">
                            <span class="label">Cantidad:</span>
                            <span class="value" id="modalQty"></span>
                        </div>
                        <div class="summary-item" style="margin-bottom: 0.5rem;">
                            <span class="label">Destinatario:</span>
                            <span class="value" id="modalTarget" style="word-break: break-all;"></span>
                        </div>
                        
                        <div class="summary-item" id="modalDiscountRow" style="display: none; margin-bottom: 0.5rem; color: #4ade80;">
                            <span class="label" style="color: #4ade80;"><i class="fa-solid fa-tag"></i> Descuento (<span id="modalCouponName"></span>):</span>
                            <span class="value" id="modalDiscountAmount" style="color: #4ade80;"></span>
                        </div>

                        <div class="summary-item" style="margin-bottom: 1.25rem; font-size: 1.1rem; border-top: 1px solid var(--border-color); padding-top: 0.6rem;">
                            <span class="label">Importe Total:</span>
                            <span class="value" id="modalPrice" style="color: var(--secondary); font-weight: 800;"></span>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="preset-btn" id="btnCancelConfirm" style="flex: 1; padding: 0.85rem; font-size: 0.95rem;">Corregir Datos</button>
                            <button type="button" class="btn-submit-order" id="btnConfirmPay" style="flex: 1.5; padding: 0.85rem; font-size: 0.95rem;">
                                <i class="fa-solid fa-shield-halved"></i> Pagar en Mercado Pago
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>

<!-- Sección Cómo Funciona -->
<section class="section-how" id="como-funciona">
    <div class="container">
        <h2 class="section-title">
            ¿Cómo funciona <span style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Turbogram</span>?
        </h2>

        <div class="steps-grid">
            <div class="step-item-card">
                <div class="step-number-icon">1</div>
                <h3 class="step-item-title">Seleccioná tu servicio</h3>
                <p class="step-item-desc">Elegí la red social, el paquete y ajustá la cantidad exacta con la calculadora en tiempo real.</p>
            </div>

            <div class="step-item-card">
                <div class="step-number-icon">2</div>
                <h3 class="step-item-title">Ingresá tu usuario</h3>
                <p class="step-item-desc">Solo necesitamos tu enlace o @usuario. Nunca te pediremos contraseñas ni claves de acceso.</p>
            </div>

            <div class="step-item-card">
                <div class="step-number-icon">3</div>
                <h3 class="step-item-title">Recibí tus resultados</h3>
                <p class="step-item-desc">Aceptás el pago mediante Mercado Pago y el sistema procesa tu pedido de forma automática en minutos.</p>
            </div>
        </div>
    </div>
</section>

<!-- Sección FAQ / Preguntas Frecuentes -->
<section class="faq-section" id="faq">
    <div class="container">
        <h2 class="section-title">
            Preguntas <span style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Frecuentes</span>
        </h2>

        <div class="faq-list">
            <details class="faq-item">
                <summary class="faq-question"><i class="fa-solid fa-lock"></i> ¿Necesitan mi contraseña de Instagram o TikTok?</summary>
                <p class="faq-answer">Jamás. Solo requerimos tu enlace público o nombre de usuario para enviar el servicio. Nunca entregues contraseñas en ningún sitio web.</p>
            </details>

            <details class="faq-item">
                <summary class="faq-question"><i class="fa-solid fa-clock"></i> ¿Cuánto tarda en llegar mi pedido?</summary>
                <p class="faq-answer">Una vez acreditado tu pago por Mercado Pago, la solicitud se envía automáticamente. La mayoría de los servicios comienzan en 5 a 30 minutos.</p>
            </details>

            <details class="faq-item">
                <summary class="faq-question"><i class="fa-solid fa-wallet"></i> ¿Qué medios de pago aceptan?</summary>
                <p class="faq-answer">Aceptamos Mercado Pago, dinero en cuenta, tarjetas de débito/crédito y transferencias bancarias en pesos argentinos.</p>
            </details>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
