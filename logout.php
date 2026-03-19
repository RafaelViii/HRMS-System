<?php
require_once __DIR__ . '/includes/auth.php';
auth_logout();
require_once __DIR__ . '/includes/config.php';
header('Location: ' . BASE_URL . '/login');
exit;
