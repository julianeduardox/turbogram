<?php
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/security.php';

$site_name = Settings::get('site_name', 'Turbogram');
$site_tagline = Settings::get('site_tagline', 'Impulsá tus redes sociales en segundos');
$whatsapp_num = Settings::get('whatsapp_number', '5492364321999');
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($site_name) ?> | <?= htmlspecialchars($site_tagline) ?></title>
    <meta name="description" content="Comprá seguidores, likes y vistas para Instagram, TikTok, Telegram, Twitch y WhatsApp en pesos argentinos con Mercado Pago. Entrega rápida y garantizada.">
    <link rel="canonical" href="https://turbogram.site/">
    
    <!-- Favicon & Iconos Oficiales para Google Search, Chrome y Móvil -->
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/img/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/img/icon-512.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/icon-192.png">
    <link rel="manifest" href="site.webmanifest">
    <meta name="theme-color" content="#8a2be2">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Estilos CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body>

    <!-- Header Navigation -->
    <header class="header">
        <div class="container header-container">
            <a href="index.php" class="logo">
                <div class="logo-icon"><i class="fa-solid fa-bolt"></i></div>
                <span class="logo-text"><?= htmlspecialchars($site_name) ?></span>
            </a>

            <nav class="nav-menu" id="navMenu">
                <a href="index.php#servicios" class="nav-link"><i class="fa-solid fa-layer-group"></i> Servicios</a>
                <a href="index.php#como-funciona" class="nav-link"><i class="fa-solid fa-wand-magic-sparkles"></i> Cómo funciona</a>
                <a href="index.php#faq" class="nav-link"><i class="fa-solid fa-circle-question"></i> Preguntas</a>
                <a href="status.php" class="nav-link"><i class="fa-solid fa-magnifying-glass"></i> Consultar Pedido</a>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $whatsapp_num) ?>?text=Hola!%20Tengo%20una%20consulta%20sobre%20Turbogram" target="_blank" class="nav-link btn-whatsapp-nav">
                    <i class="fa-brands fa-whatsapp"></i> Soporte
                </a>
            </nav>

            <button class="mobile-menu-toggle" id="menuToggle" aria-label="Abrir menú">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>
    </header>
