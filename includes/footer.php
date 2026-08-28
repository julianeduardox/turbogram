<?php
$whatsapp_num = Settings::get('whatsapp_number', '5492364321999');
$clean_wp = preg_replace('/[^0-9]/', '', $whatsapp_num);
?>
    <!-- Footer -->
    <footer class="footer">
        <div class="container footer-grid">
            <div class="footer-col">
                <a href="index.php" class="logo">
                    <div class="logo-icon"><i class="fa-solid fa-bolt"></i></div>
                    <span class="logo-text"><?= htmlspecialchars(Settings::get('site_name', 'Turbogram')) ?></span>
                </a>
                <p class="footer-desc">
                    Plataforma líder en Argentina para hacer crecer tus cuentas de redes sociales de forma rápida, segura y transparente.
                </p>
                <div class="payment-methods">
                    <span class="pm-badge"><i class="fa-solid fa-shield-halved"></i> Mercado Pago Seguro</span>
                    <span class="pm-badge"><i class="fa-solid fa-lock"></i> SSL 256-bit</span>
                </div>
            </div>

            <div class="footer-col">
                <h4 class="footer-title">Redes Soportadas</h4>
                <ul class="footer-links">
                    <li><a href="index.php#servicios"><i class="fa-brands fa-instagram"></i> Instagram Seguidores y Likes</a></li>
                    <li><a href="index.php#servicios"><i class="fa-brands fa-tiktok"></i> TikTok Seguidores y Vistas</a></li>
                    <li><a href="index.php#servicios"><i class="fa-brands fa-telegram"></i> Telegram Miembros y Reacciones</a></li>
                    <li><a href="index.php#servicios"><i class="fa-brands fa-twitch"></i> Twitch Seguidores</a></li>
                    <li><a href="index.php#servicios"><i class="fa-brands fa-whatsapp"></i> WhatsApp Canal Miembros</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4 class="footer-title">Enlaces Útiles</h4>
                <ul class="footer-links">
                    <li><a href="status.php"><i class="fa-solid fa-truck-fast"></i> Estado de tu Pedido</a></li>
                    <li><a href="index.php#faq"><i class="fa-solid fa-circle-info"></i> Preguntas Frecuentes</a></li>
                    <li><a href="admin/login.php"><i class="fa-solid fa-user-shield"></i> Acceso Administrador</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="container footer-bottom-content">
                <p>&copy; <?= date('Y') ?> Turbogram. Todos los derechos reservados. Mercado Pago es una marca registrada de MercadoLibre S.R.L.</p>
            </div>
        </div>
    </footer>

    <!-- Botón Flotante de WhatsApp -->
    <a href="https://wa.me/<?= $clean_wp ?>?text=Hola!%20Vengo%20desde%20la%20web%20Turbogram%20y%20tengo%20una%20consulta" target="_blank" class="float-whatsapp" aria-label="Contacto por WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
        <span class="float-whatsapp-ping"></span>
    </a>

    <!-- Scripts Javascript -->
    <script src="assets/js/main.js?v=<?= time() ?>"></script>
</body>
</html>
