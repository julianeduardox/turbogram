/**
 * Turbogram - JS Interactive Engine
 * Manejo de calculadora en vivo, filtros de categoría, sliders y modales de confirmación
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
    const targetInput = document.getElementById('targetInput');
    const targetLabel = document.getElementById('targetLabel');
    const targetHelpText = document.getElementById('targetHelpText');

    // Modales
    const orderForm = document.getElementById('orderForm');
    const confirmModal = document.getElementById('confirmModal');
    const btnCancelConfirm = document.getElementById('btnCancelConfirm');

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
        const pricePer1000 = parseFloat(selectedOption.dataset.price) || 0;
        const placeholder = selectedOption.dataset.placeholder || 'Enlace o usuario de perfil';
        const serviceName = selectedOption.dataset.name || selectedOption.text;
        const categoryName = selectedOption.dataset.category || 'General';

        // Actualizar slider
        qtySlider.min = minQty;
        qtySlider.max = maxQty;

        // Ajustar valor actual si está fuera de rango
        let currentQty = parseInt(qtyInput.value) || minQty;
        if (currentQty < minQty) currentQty = minQty;
        if (currentQty > maxQty) currentQty = maxQty;
        
        qtySlider.value = currentQty;
        qtyInput.value = currentQty;

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
     * Recalcula el precio total en tiempo real
     */
    function calculatePrice() {
        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
        if (!selectedOption) return;

        const pricePer1000 = parseFloat(selectedOption.dataset.price) || 0;
        const qty = parseInt(qtyInput.value) || 0;

        const totalPrice = (qty / 1000) * pricePer1000;

        if (priceDisplay) {
            priceDisplay.textContent = formatMoney(totalPrice);
        }

        if (summaryQty) {
            summaryQty.textContent = qty.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }
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

    // Sincronización Slider -> Input
    qtySlider.addEventListener('input', function () {
        qtyInput.value = this.value;
        calculatePrice();
    });

    // Sincronización Input -> Slider
    qtyInput.addEventListener('input', function () {
        let val = parseInt(this.value) || 0;
        const min = parseInt(qtySlider.min) || 100;
        const max = parseInt(qtySlider.max) || 50000;

        if (val >= min && val <= max) {
            qtySlider.value = val;
        }
        calculatePrice();
    });

    // Evento Botones Preset (1.000, 5.000, 10.000, etc.)
    presetBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const qty = parseInt(this.dataset.qty);
            const max = parseInt(qtySlider.max);
            const min = parseInt(qtySlider.min);

            let targetQty = qty;
            if (targetQty < min) targetQty = min;
            if (targetQty > max) targetQty = max;

            qtyInput.value = targetQty;
            qtySlider.value = targetQty;
            calculatePrice();
        });
    });

    // Elementos del Modal de Confirmación
    const btnConfirmPay = document.getElementById('btnConfirmPay');

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

