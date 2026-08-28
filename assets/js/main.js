/**
 * Turbogram - JS Interactive Engine
 * Manejo de calculadora en vivo, cupones de descuento, banners con cuenta regresiva,
 * filtros de categoría, sliders y modales de confirmación.
 */

document.addEventListener('DOMContentLoaded', function () {
    const categoryBtns = document.querySelectorAll('.category-btn');
    const serviceSelect = document.getElementById('serviceSelect');
    const qtySlider = document.getElementById('qtySlider');
    const qtyInput = document.getElementById('qtyInput');
    const presetBtns = document.querySelectorAll('.preset-btn');
    
    // Elementos del resumen
    const summaryCategory = document.getElementById('summaryCategory');
    const summaryService = document.getElementById('summaryService');
    const summaryQty = document.getElementById('summaryQty');
    const priceDisplay = document.getElementById('priceDisplay');
    const priceOriginal = document.getElementById('priceOriginal');
    const discountTag = document.getElementById('discountTag');
    const summaryDiscountRow = document.getElementById('summaryDiscountRow');
    const summaryCouponName = document.getElementById('summaryCouponName');
    const summaryDiscountAmount = document.getElementById('summaryDiscountAmount');
    const targetInput = document.getElementById('targetInput');

    // Elementos de Cupones
    const couponInput = document.getElementById('couponInput');
    const btnApplyCoupon = document.getElementById('btnApplyCoupon');
    const btnRemoveCoupon = document.getElementById('btnRemoveCoupon');
    const couponFeedback = document.getElementById('couponFeedback');
    const couponAppliedStatus = document.getElementById('couponAppliedStatus');

    // Elementos del Banner de Oferta
    const promoBanner = document.getElementById('promoBanner');
    const cdHours = document.getElementById('cdHours');
    const cdMinutes = document.getElementById('cdMinutes');
    const cdSeconds = document.getElementById('cdSeconds');

    // Modales
    const orderForm = document.getElementById('orderForm');
    const confirmModal = document.getElementById('confirmModal');
    const btnCancelConfirm = document.getElementById('btnCancelConfirm');
    const btnConfirmPay = document.getElementById('btnConfirmPay');
    const modalDiscountRow = document.getElementById('modalDiscountRow');
    const modalCouponName = document.getElementById('modalCouponName');
    const modalDiscountAmount = document.getElementById('modalDiscountAmount');

    // Estado del Cupón
    let appliedCoupon = null;

    if (!serviceSelect) return;

    /**
     * Formatea un número según la Regla 9: sin decimales y con punto como separador de miles.
     * Ejemplo: 1500 -> "$ 1.500"
     */
    function formatMoney(amount) {
        const rounded = Math.round(amount);
        const formatted = rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return "$ " + formatted;
    }

    /**
     * Obtiene la cantidad actual de forma segura (nunca 0)
     */
    function getCurrentQuantity() {
        let qty = parseInt(qtyInput && qtyInput.value ? qtyInput.value : 0);
        if (isNaN(qty) || qty <= 0) {
            qty = parseInt(qtySlider && qtySlider.value ? qtySlider.value : 0);
        }
        if (isNaN(qty) || qty <= 0) {
            qty = 1000;
        }
        return qty;
    }

    /**
     * Actualiza las opciones del selector de servicios según la categoría activa
     */
    function filterServicesByCategory(platform) {
        let firstSelectable = null;
        Array.from(serviceSelect.options).forEach(opt => {
            const optPlatform = opt.dataset.platform;
            if (!platform || optPlatform === platform) {
                opt.style.display = 'block';
                if (!firstSelectable) firstSelectable = opt;
            } else {
                opt.style.display = 'none';
            }
        });

        if (firstSelectable) {
            serviceSelect.value = firstSelectable.value;
            updateServiceDetails();
        }
    }

    /**
     * Actualiza mínimos, máximos, placeholder y precio según el servicio seleccionado
     */
    function updateServiceDetails() {
        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
        if (!selectedOption) return;

        const minQty = parseInt(selectedOption.dataset.min) || 100;
        const maxQty = parseInt(selectedOption.dataset.max) || 50000;
        const placeholder = selectedOption.dataset.placeholder || 'Enlace o usuario de perfil';
        const serviceName = selectedOption.dataset.name || selectedOption.text;
        const categoryName = selectedOption.dataset.category || 'General';

        // Actualizar slider e input
        if (qtySlider) {
            qtySlider.min = minQty;
            qtySlider.max = maxQty;
        }
        if (qtyInput) {
            qtyInput.min = minQty;
            qtyInput.max = maxQty;
        }

        // Ajustar valor actual si está fuera de rango
        let currentQty = getCurrentQuantity();
        if (currentQty < minQty) currentQty = minQty;
        if (currentQty > maxQty) currentQty = maxQty;
        
        if (qtySlider) qtySlider.value = currentQty;
        if (qtyInput) qtyInput.value = currentQty;

        // Actualizar placeholders y etiquetas
        if (targetInput) {
            targetInput.placeholder = placeholder;
        }

        // Actualizar Resumen
        if (summaryCategory) summaryCategory.textContent = categoryName;
        if (summaryService) summaryService.textContent = serviceName;
        
        calculatePrice();
    }

    /**
     * Recalcula el precio total en tiempo real aplicando descuentos si hay cupón
     */
    function calculatePrice() {
        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
        if (!selectedOption) return;

        const pricePer1000 = parseFloat(selectedOption.dataset.price) || 0;
        const qty = getCurrentQuantity();

        let originalPrice = Math.round((qty / 1000) * pricePer1000);
        if (originalPrice < 1) originalPrice = 1;

        let finalPrice = originalPrice;
        let discountAmount = 0;

        // Si hay un cupón activo, calcular descuento dinámicamente
        if (appliedCoupon) {
            const minReq = parseFloat(appliedCoupon.min_order_amount) || 0;
            if (minReq > 0 && originalPrice < minReq) {
                // No cumple compra mínima
                if (couponFeedback) {
                    couponFeedback.style.display = 'block';
                    couponFeedback.style.background = 'rgba(239, 68, 68, 0.15)';
                    couponFeedback.style.border = '1px solid rgba(239, 68, 68, 0.4)';
                    couponFeedback.style.color = '#fca5a5';
                    couponFeedback.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Requiere compra mínima de ${formatMoney(minReq)}.`;
                }
                discountAmount = 0;
                finalPrice = originalPrice;
            } else {
                if (appliedCoupon.discount_type === 'percentage') {
                    discountAmount = Math.round((originalPrice * parseFloat(appliedCoupon.discount_value)) / 100);
                } else {
                    discountAmount = Math.min(originalPrice, parseFloat(appliedCoupon.discount_value));
                }

                finalPrice = Math.max(1, originalPrice - discountAmount);
                discountAmount = originalPrice - finalPrice;

                // Actualizar feedback dinámico con el ahorro recalculado para la cantidad actual
                if (couponFeedback) {
                    couponFeedback.style.display = 'block';
                    couponFeedback.style.background = 'rgba(34, 197, 94, 0.15)';
                    couponFeedback.style.border = '1px solid rgba(34, 197, 94, 0.4)';
                    couponFeedback.style.color = '#4ade80';
                    const discountText = appliedCoupon.discount_type === 'percentage' 
                        ? `${appliedCoupon.discount_value}% OFF` 
                        : `${formatMoney(discountAmount)} OFF`;
                    couponFeedback.innerHTML = `<i class="fa-solid fa-circle-check"></i> ¡Cupón <strong>${appliedCoupon.code}</strong> aplicado! Ahorrás ${formatMoney(discountAmount)} (${discountText})`;
                }
            }
        }

        // Actualizar visualización del precio final
        if (priceDisplay) {
            priceDisplay.textContent = formatMoney(finalPrice);
        }

        if (summaryQty) {
            summaryQty.textContent = qty.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }

        // Si hay descuento activo: tachar precio anterior y mostrar insignias
        if (discountAmount > 0) {
            if (priceOriginal) {
                priceOriginal.textContent = formatMoney(originalPrice);
                priceOriginal.style.display = 'block';
            }
            if (discountTag) {
                const badgeText = appliedCoupon.discount_type === 'percentage' 
                    ? `🔥 Ahorrás ${formatMoney(discountAmount)} (${appliedCoupon.discount_value}% OFF)`
                    : `🔥 Ahorrás ${formatMoney(discountAmount)}`;
                discountTag.innerHTML = badgeText;
                discountTag.style.display = 'inline-flex';
            }
            if (summaryDiscountRow) {
                summaryDiscountRow.style.display = 'flex';
                if (summaryCouponName) summaryCouponName.textContent = appliedCoupon.code;
                if (summaryDiscountAmount) summaryDiscountAmount.textContent = '- ' + formatMoney(discountAmount);
            }
        } else {
            if (priceOriginal) priceOriginal.style.display = 'none';
            if (discountTag) discountTag.style.display = 'none';
            if (summaryDiscountRow) summaryDiscountRow.style.display = 'none';
        }
    }

    /**
     * Aplica y valida un cupón de descuento por AJAX
     */
    function applyCouponCode(codeToApply) {
        const code = (codeToApply || (couponInput ? couponInput.value : '')).trim().toUpperCase();
        if (!code) {
            alert('Por favor ingresá un código de cupón.');
            if (couponInput) couponInput.focus();
            return;
        }

        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
        const serviceId = selectedOption ? selectedOption.value : 0;
        const qty = getCurrentQuantity();

        if (btnApplyCoupon) {
            btnApplyCoupon.disabled = true;
            btnApplyCoupon.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        }

        const formData = new FormData();
        formData.append('coupon_code', code);
        formData.append('service_id', serviceId);
        formData.append('quantity', qty);

        fetch('ajax_coupon.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (btnApplyCoupon) {
                btnApplyCoupon.disabled = false;
                btnApplyCoupon.innerHTML = 'Aplicar';
            }

            if (data.success) {
                appliedCoupon = data;
                if (couponInput) couponInput.value = data.code;

                if (couponAppliedStatus) couponAppliedStatus.style.display = 'inline-flex';
                if (btnRemoveCoupon) btnRemoveCoupon.style.display = 'inline-block';
                if (btnApplyCoupon) btnApplyCoupon.style.display = 'none';

                calculatePrice();
            } else {
                appliedCoupon = null;
                if (couponFeedback) {
                    couponFeedback.style.display = 'block';
                    couponFeedback.style.background = 'rgba(239, 68, 68, 0.15)';
                    couponFeedback.style.border = '1px solid rgba(239, 68, 68, 0.4)';
                    couponFeedback.style.color = '#fca5a5';
                    couponFeedback.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> ${data.error}`;
                }
                if (couponAppliedStatus) couponAppliedStatus.style.display = 'none';
                if (btnRemoveCoupon) btnRemoveCoupon.style.display = 'none';
                if (btnApplyCoupon) btnApplyCoupon.style.display = 'inline-block';

                calculatePrice();
            }
        })
        .catch(err => {
            if (btnApplyCoupon) {
                btnApplyCoupon.disabled = false;
                btnApplyCoupon.innerHTML = 'Aplicar';
            }
            alert('Error al validar el cupón. Intentá nuevamente.');
        });
    }

    /**
     * Remueve el cupón activo
     */
    function removeCoupon() {
        appliedCoupon = null;
        if (couponInput) couponInput.value = '';
        if (couponFeedback) couponFeedback.style.display = 'none';
        if (couponAppliedStatus) couponAppliedStatus.style.display = 'none';
        if (btnRemoveCoupon) btnRemoveCoupon.style.display = 'none';
        if (btnApplyCoupon) btnApplyCoupon.style.display = 'inline-block';
        calculatePrice();
    }

    // Eventos de Cupones
    if (btnApplyCoupon) {
        btnApplyCoupon.addEventListener('click', function () {
            applyCouponCode();
        });
    }

    if (btnRemoveCoupon) {
        btnRemoveCoupon.addEventListener('click', removeCoupon);
    }

    if (couponInput) {
        couponInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyCouponCode();
            }
        });
    }

    // Cuenta regresiva del Banner (si tiene vencimiento)
    if (promoBanner && promoBanner.dataset.expires) {
        const expiresTime = new Date(promoBanner.dataset.expires).getTime();

        function updateCountdown() {
            const now = new Date().getTime();
            const distance = expiresTime - now;

            if (distance <= 0) {
                if (promoBanner) promoBanner.style.display = 'none';
                return;
            }

            const hours = Math.floor(distance / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            if (cdHours) cdHours.textContent = hours < 10 ? '0' + hours : hours;
            if (cdMinutes) cdMinutes.textContent = minutes < 10 ? '0' + minutes : minutes;
            if (cdSeconds) cdSeconds.textContent = seconds < 10 ? '0' + seconds : seconds;
        }

        updateCountdown();
        setInterval(updateCountdown, 1000);
    }

    // Eventos de Categoría
    categoryBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            categoryBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const platform = this.dataset.platform;
            filterServicesByCategory(platform);
        });
    });

    // Evento Selector de Servicio
    serviceSelect.addEventListener('change', updateServiceDetails);

    // Sincronización Slider -> Input (movimiento en vivo)
    if (qtySlider) {
        qtySlider.addEventListener('input', function () {
            if (qtyInput) qtyInput.value = this.value;
            calculatePrice();
        });
    }

    // Sincronización Input -> Slider (escritura directa)
    if (qtyInput) {
        qtyInput.addEventListener('input', function () {
            let val = parseInt(this.value);
            if (!isNaN(val) && val > 0 && qtySlider) {
                const min = parseInt(qtySlider.min) || 100;
                const max = parseInt(qtySlider.max) || 50000;
                if (val >= min && val <= max) {
                    qtySlider.value = val;
                }
            }
            calculatePrice();
        });

        qtyInput.addEventListener('blur', function () {
            const min = parseInt(qtySlider ? qtySlider.min : 100) || 100;
            const max = parseInt(qtySlider ? qtySlider.max : 50000) || 50000;
            let val = parseInt(this.value);
            if (isNaN(val) || val < min) val = min;
            if (val > max) val = max;
            this.value = val;
            if (qtySlider) qtySlider.value = val;
            calculatePrice();
        });
    }

    // Evento Botones Preset (+500, +1.000, +2.500, +5.000, +10.000)
    presetBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const addQty = parseInt(this.dataset.qty) || 0;
            const max = parseInt(qtySlider ? qtySlider.max : 50000) || 50000;
            const min = parseInt(qtySlider ? qtySlider.min : 100) || 100;

            let current = getCurrentQuantity();
            let targetQty = current + addQty;
            if (targetQty > max) targetQty = max;
            if (targetQty < min) targetQty = min;

            if (qtyInput) qtyInput.value = targetQty;
            if (qtySlider) qtySlider.value = targetQty;
            calculatePrice();
        });
    });

    // Modal de Confirmación previo a enviar
    if (orderForm) {
        orderForm.addEventListener('submit', function (e) {
            e.preventDefault();

            // Validaciones de entrada
            const linkVal = targetInput ? targetInput.value.trim() : '';
            const emailInput = document.getElementById('emailInput');
            const emailVal = emailInput ? emailInput.value.trim() : '';
            const termsCheck = document.getElementById('termsCheck');

            if (!linkVal) {
                alert('Por favor decinos tu enlace o nombre de usuario de la red social.');
                if (targetInput) targetInput.focus();
                return;
            }

            if (!emailVal || !emailVal.includes('@')) {
                alert('Ingresá un correo electrónico válido para enviarte el comprobante.');
                if (emailInput) emailInput.focus();
                return;
            }

            if (termsCheck && !termsCheck.checked) {
                alert('Debés aceptar los Términos y Condiciones para continuar.');
                return;
            }

            // Mostrar Modal de Verificación de Perfil Público
            if (confirmModal) {
                const modalService = document.getElementById('modalService');
                const modalQty = document.getElementById('modalQty');
                const modalTarget = document.getElementById('modalTarget');
                const modalPrice = document.getElementById('modalPrice');

                if (modalService && summaryService) modalService.textContent = summaryService.textContent;
                if (modalQty && summaryQty) modalQty.textContent = summaryQty.textContent;
                if (modalTarget) modalTarget.textContent = linkVal;
                if (modalPrice && priceDisplay) modalPrice.textContent = priceDisplay.textContent;

                // Desglose de Cupón en Modal si está activo
                if (appliedCoupon && modalDiscountRow) {
                    modalDiscountRow.style.display = 'flex';
                    if (modalCouponName) modalCouponName.textContent = appliedCoupon.code;
                    if (modalDiscountAmount && summaryDiscountAmount) modalDiscountAmount.textContent = summaryDiscountAmount.textContent;
                } else if (modalDiscountRow) {
                    modalDiscountRow.style.display = 'none';
                }

                // Resetear estado del botón de pago
                if (btnConfirmPay) {
                    btnConfirmPay.disabled = false;
                    btnConfirmPay.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Pagar en Mercado Pago';
                }

                confirmModal.style.display = 'flex';
            } else {
                HTMLFormElement.prototype.submit.call(orderForm);
            }
        });
    }

    // Acción del botón Pagar en el modal de confirmación
    if (btnConfirmPay) {
        btnConfirmPay.addEventListener('click', function () {
            btnConfirmPay.disabled = true;
            btnConfirmPay.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Redirigiendo a Mercado Pago...';
            // Enviar formulario al backend
            HTMLFormElement.prototype.submit.call(orderForm);
        });
    }

    // Botón cancelar / corregir datos
    if (btnCancelConfirm) {
        btnCancelConfirm.addEventListener('click', function () {
            if (confirmModal) confirmModal.style.display = 'none';
        });
    }

    // Cerrar modal al hacer clic en el fondo oscuro
    if (confirmModal) {
        confirmModal.addEventListener('click', function (e) {
            if (e.target === confirmModal) {
                confirmModal.style.display = 'none';
            }
        });
    }

    // Cerrar modal con la tecla Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && confirmModal && confirmModal.style.display === 'flex') {
            confirmModal.style.display = 'none';
        }
    });

    // Inicializar primera carga
    updateServiceDetails();
});
