<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('performance', 'performance_reviews', 'read');
require_once __DIR__ . '/../../includes/db.php';

flash_error('The performance module has been retired.');
header('Location: ' . BASE_URL . '/modules/admin/index');
exit;
