<?php
/**
 * Memo redirect
 * Redirects to the unified memo listing page
 */
require_once __DIR__ . '/../../includes/config.php';
header('Location: ' . BASE_URL . '/modules/memos/index');
exit;
