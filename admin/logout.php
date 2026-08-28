<?php
require_once __DIR__ . '/../config/security.php';
session_destroy();
header('Location: login.php');
exit;
