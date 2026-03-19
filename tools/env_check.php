<?php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: text/plain; charset=utf-8');
$vars = [
  'PHP_VERSION' => PHP_VERSION,
  'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? '',
  '__DIR__' => __DIR__,
  'APP_ROOT (realpath ..)' => realpath(__DIR__ . '/..') ?: '',
  'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
  'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? '',
  'BASE_URL (computed)' => defined('BASE_URL') ? BASE_URL : '(not defined)',
];
foreach ($vars as $k => $v) {
  echo str_pad($k . ':', 24) . ' ' . $v . "\n";
}
