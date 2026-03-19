<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/form_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

try {
  require_login();
  require_access('documents', 'memos', 'write');
} catch (Throwable $e) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}

$pdo = get_db_conn();
$type = strtolower(trim((string)($_GET['type'] ?? '')));
$term = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 12);
$limit = max(1, min(30, $limit));
$minTermLength = (int)($_GET['min_term'] ?? 2);
if ($minTermLength < 0) {
  $minTermLength = 0;
}

$exclude = [
  'department' => [],
  'role' => [],
  'employee' => [],
];
if (isset($_GET['exclude']) && is_array($_GET['exclude'])) {
  foreach ($_GET['exclude'] as $key => $value) {
    $keyLower = strtolower((string)$key);
    if (!array_key_exists($keyLower, $exclude)) {
      continue;
    }
    $values = [];
    if (is_array($value)) {
      $values = $value;
    } elseif (is_string($value)) {
      $values = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $exclude[$keyLower] = array_values(array_unique(array_map('strval', $values)));
  }
}

$response = [
  'success' => true,
  'results' => [],
  'meta' => [
    'type' => $type ?: 'mixed',
    'term' => $term,
    'tooShort' => false,
  ],
];

try {
  $termLower = strtolower($term);
  if ($type === 'all') {
    $response['results'][] = memo_all_shortcut_option();
  } elseif ($type === 'department') {
    $results = [];
    foreach (memo_fetch_departments($pdo) as $dept) {
      $option = memo_department_to_option($dept);
      if (!$option) {
        continue;
      }
      if ($termLower !== '' && strpos($option['search'], $termLower) === false) {
        continue;
      }
      if (in_array($option['identifier'], $exclude['department'], true)) {
        continue;
      }
      $results[] = $option;
      if (count($results) >= $limit) {
        break;
      }
    }
    $response['results'] = $results;
  } elseif ($type === 'role') {
    $results = [];
    foreach (memo_fetch_roles($pdo) as $role) {
      $option = memo_role_to_option($role);
      if (!$option) {
        continue;
      }
      if ($termLower !== '' && strpos($option['search'], $termLower) === false) {
        continue;
      }
      if (in_array($option['identifier'], $exclude['role'], true)) {
        continue;
      }
      $results[] = $option;
      if (count($results) >= $limit) {
        break;
      }
    }
    $response['results'] = $results;
  } elseif ($type === 'employee' || $type === '') {
    if (strlen($term) < $minTermLength) {
      $response['meta']['tooShort'] = true;
      $response['results'] = [];
    } else {
      $excludeEmployeeIds = array_map('intval', $exclude['employee']);
      $response['results'] = memo_search_employees($pdo, $term, $limit, $excludeEmployeeIds);
    }
  } else {
    $results = [];
    $departments = [];
    foreach (memo_fetch_departments($pdo) as $dept) {
      $option = memo_department_to_option($dept);
      if (!$option) {
        continue;
      }
      if ($termLower !== '' && strpos($option['search'], $termLower) === false) {
        continue;
      }
      if (in_array($option['identifier'], $exclude['department'], true)) {
        continue;
      }
      $departments[] = $option;
      if (count($departments) >= (int)ceil($limit / 2)) {
        break;
      }
    }
    $roles = [];
    foreach (memo_fetch_roles($pdo) as $role) {
      $option = memo_role_to_option($role);
      if (!$option) {
        continue;
      }
      if ($termLower !== '' && strpos($option['search'], $termLower) === false) {
        continue;
      }
      if (in_array($option['identifier'], $exclude['role'], true)) {
        continue;
      }
      $roles[] = $option;
      if (count($roles) >= (int)ceil($limit / 2)) {
        break;
      }
    }
    $results = array_merge($departments, $roles);
    if ($term !== '' && strlen($term) >= $minTermLength) {
      $employeeLimit = max(1, $limit - count($results));
      $excludeEmployeeIds = array_map('intval', $exclude['employee']);
      $employeeResults = memo_search_employees($pdo, $term, $employeeLimit, $excludeEmployeeIds);
      $results = array_merge($results, $employeeResults);
    }
    $response['results'] = array_slice($results, 0, $limit);
  }
} catch (Throwable $e) {
  sys_log('MEMO-AUDIENCE-LOOKUP', 'Audience lookup failed: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
  $response['success'] = false;
  $response['error'] = 'Lookup failed.';
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
