<?php
require_once __DIR__ . '/../config/security.php';

if (function_exists('log_audit') && !empty($_SESSION['admin_user'])) {
    log_audit('ADMIN_LOGOUT', 'Cierre de sesión seguro del usuario: ' . $_SESSION['admin_user']);
}

secure_logout_session();
header('Location: login.php?msg=logout');
exit;
