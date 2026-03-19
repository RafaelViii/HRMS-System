<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

function memo_normalize_employee_row(array $row): array {
  $empId = (int)($row['id'] ?? 0);
  $first = trim((string)($row['first_name'] ?? ''));
  $last = trim((string)($row['last_name'] ?? ''));
  $label = '';
  if ($last !== '' && $first !== '') {
    $label = $last . ', ' . $first;
  } elseif ($last !== '') {
    $label = $last;
  } elseif ($first !== '') {
    $label = $first;
  } else {
    $label = 'Employee #' . ($empId ?: '?');
  }
  $row['label'] = $label;
  return $row;
}

function memo_employee_to_option(array $row): ?array {
  $normalized = memo_normalize_employee_row($row);
  $empId = (int)($normalized['id'] ?? 0);
  if ($empId <= 0) {
    return null;
  }
  $code = trim((string)($normalized['employee_code'] ?? ''));
  $department = trim((string)($normalized['department_name'] ?? $normalized['department'] ?? ''));
  $position = trim((string)($normalized['position_name'] ?? $normalized['position'] ?? ''));
  $searchParts = array_filter([
    $normalized['label'],
    $code,
    $position,
    $department,
  ], 'strlen');
  $searchable = strtolower(implode(' ', $searchParts));
  $tagParts = ['@emp ' . $normalized['label']];
  if ($position !== '') {
    $tagParts[] = $position;
  }
  if ($department !== '') {
    $tagParts[] = $department;
  }
  return [
    'type' => 'employee',
    'identifier' => (string)$empId,
    'label' => $normalized['label'],
    'tag' => implode(' • ', $tagParts),
    'group' => 'Employees',
    'search' => $searchable,
    'meta' => [
      'code' => $code,
      'position' => $position,
      'department' => $department,
    ],
  ];
}

function memo_department_to_option(array $dept): ?array {
  $id = (int)($dept['id'] ?? 0);
  $name = trim((string)($dept['name'] ?? ''));
  if ($id <= 0 || $name === '') {
    return null;
  }
  return [
    'type' => 'department',
    'identifier' => (string)$id,
    'label' => $name,
    'tag' => '@dept ' . $name,
    'group' => 'Departments',
    'search' => strtolower($name),
  ];
}

function memo_role_to_option(array $role): ?array {
  $code = trim((string)($role['code'] ?? ''));
  $name = trim((string)($role['name'] ?? ''));
  if ($code === '' || $name === '') {
    return null;
  }
  return [
    'type' => 'role',
    'identifier' => $code,
    'label' => $name,
    'tag' => '@role ' . $code,
    'group' => 'Roles',
    'search' => strtolower($name . ' ' . $code),
  ];
}

function memo_all_shortcut_option(): array {
  return [
    'type' => 'all',
    'identifier' => 'all',
    'label' => 'All employees',
    'tag' => '@all',
    'group' => 'Shortcuts',
    'search' => 'all employees everyone whole company',
  ];
}

/**
 * Memo helper scoped check for column availability so we can stay compatible with
 * environments that have not yet applied optional schema columns.
 */
function memo_table_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = strtolower($table) . ':' . strtolower($column);
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }
  try {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column LIMIT 1');
    $stmt->execute([
      ':table' => strtolower($table),
      ':column' => strtolower($column),
    ]);
    return $cache[$key] = (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return $cache[$key] = false;
  }
}

function memo_fetch_departments(PDO $pdo): array {
  try {
    $hasStatusColumn = memo_table_has_column($pdo, 'departments', 'status');
    $sql = $hasStatusColumn
      ? 'SELECT id, name FROM departments WHERE (status IS NULL OR status = 1) AND deleted_at IS NULL ORDER BY name'
      : 'SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name';
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    sys_log('MEMO-DEPTS', 'Failed to load departments: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
    return [];
  }
}

function memo_fetch_roles(PDO $pdo): array {
  try {
    $stmt = $pdo->query("SELECT role_name AS code, COALESCE(label, INITCAP(REPLACE(role_name, '_', ' '))) AS name FROM roles_meta WHERE is_active = 1 ORDER BY name");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows) {
      return $rows;
    }
  } catch (Throwable $e) {
    sys_log('MEMO-ROLES', 'Failed to load roles from roles_meta: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
  }
  try {
    $stmt = $pdo->query("SELECT UNNEST(enum_range(NULL::user_role)) AS code");
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $code = trim((string)($row['code'] ?? ''));
      if ($code === '') {
        continue;
      }
      $rows[] = [
        'code' => $code,
        'name' => ucwords(str_replace('_', ' ', $code)),
      ];
    }
    return $rows;
  } catch (Throwable $e) {
    sys_log('MEMO-ROLES', 'Fallback role load failed: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
    return [];
  }
}

function memo_fetch_employees(PDO $pdo): array {
  try {
    $stmt = $pdo->query("SELECT e.id, e.employee_code, e.first_name, e.last_name, e.department_id, e.position_id, e.user_id, d.name AS department_name, p.name AS position_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN positions p ON p.id = e.position_id WHERE (e.status IS NULL OR e.status = 'active') AND e.deleted_at IS NULL ORDER BY e.last_name, e.first_name");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
      $row = memo_normalize_employee_row($row);
    }
    return $rows;
  } catch (Throwable $e) {
    sys_log('MEMO-EMPS', 'Failed to load employees: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
    return [];
  }
}

function memo_fetch_employees_by_ids(PDO $pdo, array $ids): array {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) {
    return [];
  }
  $placeholders = [];
  $params = [];
  foreach ($ids as $idx => $id) {
    if ($id <= 0) {
      continue;
    }
    $ph = ':emp_' . $idx;
    $placeholders[] = $ph;
    $params[$ph] = $id;
  }
  if (!$placeholders) {
    return [];
  }
  $sql = 'SELECT e.id, e.employee_code, e.first_name, e.last_name, d.name AS department_name, p.name AS position_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN positions p ON p.id = e.position_id WHERE (e.status IS NULL OR e.status = \'active\') AND e.deleted_at IS NULL AND e.id IN (' . implode(',', $placeholders) . ')';
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byId = [];
    foreach ($rows as $row) {
      $empId = (int)($row['id'] ?? 0);
      if ($empId <= 0) {
        continue;
      }
      $normalized = memo_normalize_employee_row($row);
      $byId[$empId] = $normalized;
    }
    $ordered = [];
    foreach ($ids as $empId) {
      if (isset($byId[$empId])) {
        $ordered[] = $byId[$empId];
      }
    }
    return $ordered;
  } catch (Throwable $e) {
    sys_log('MEMO-EMPS-BY-ID', 'Failed to load employees by id: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
    return [];
  }
}

function memo_search_employees(PDO $pdo, string $term, int $limit = 10, array $excludeIds = []): array {
  $term = trim($term);
  if ($term === '') {
    return [];
  }
  $limit = max(1, min(50, (int)$limit));
  $excludeIds = array_values(array_unique(array_map('intval', $excludeIds)));
  $conditions = [];
  $params = [];
  if ($excludeIds) {
    $placeholders = [];
    foreach ($excludeIds as $idx => $id) {
      if ($id <= 0) {
        continue;
      }
      $ph = ':ex_' . $idx;
      $placeholders[] = $ph;
      $params[$ph] = $id;
    }
    if ($placeholders) {
      $conditions[] = 'e.id NOT IN (' . implode(',', $placeholders) . ')';
    }
  }
  $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
  $likeParam = '%' . $escaped . '%';
  $sql = "SELECT e.id, e.employee_code, e.first_name, e.last_name, d.name AS department_name, p.name AS position_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN positions p ON p.id = e.position_id WHERE (e.status IS NULL OR e.status = 'active') AND e.deleted_at IS NULL";
  if ($conditions) {
    $sql .= ' AND ' . implode(' AND ', $conditions);
  }
  $sql .= " AND (
    e.first_name ILIKE :term ESCAPE '\\' OR
    e.last_name ILIKE :term ESCAPE '\\' OR
    e.employee_code ILIKE :term ESCAPE '\\' OR
    (e.first_name || ' ' || e.last_name) ILIKE :term ESCAPE '\\' OR
    (e.last_name || ', ' || e.first_name) ILIKE :term ESCAPE '\\' OR
    p.name ILIKE :term ESCAPE '\\' OR
    d.name ILIKE :term ESCAPE '\\'
  )
  ORDER BY e.last_name, e.first_name
  LIMIT :limit";
  try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $ph => $value) {
      $stmt->bindValue($ph, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':term', $likeParam, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $option = memo_employee_to_option($row);
      if ($option) {
        $results[] = $option;
      }
    }
    return $results;
  } catch (Throwable $e) {
    sys_log('MEMO-EMP-SEARCH', 'Failed to search employees: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
    return [];
  }
}

function memo_fetch_recipients(PDO $pdo, int $memoId): array {
  try {
    $stmt = $pdo->prepare('SELECT id, memo_id, audience_type, audience_identifier, audience_label FROM memo_recipients WHERE memo_id = :id ORDER BY id');
    $stmt->execute([':id' => $memoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    sys_log('MEMO-RECIP', 'Failed to load memo recipients: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId]]);
    return [];
  }
}

function memo_fetch_attachments(PDO $pdo, int $memoId): array {
  try {
    $stmt = $pdo->prepare('SELECT id, memo_id, file_path, original_name, file_size, mime_type, description, uploaded_by, uploaded_at FROM memo_attachments WHERE memo_id = :id ORDER BY id');
    $stmt->execute([':id' => $memoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    sys_log('MEMO-ATTACH', 'Failed to load memo attachments: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId]]);
    return [];
  }
}

function memo_attachment_public_url(?string $relativePath): string {
  $relativePath = trim((string)$relativePath);
  if ($relativePath === '') {
    return '';
  }
  $normalized = str_replace('\\', '/', $relativePath);
  $normalized = preg_replace('#/+#', '/', $normalized ?? '') ?? '';
  $normalized = ltrim($normalized, '/');
  if ($normalized === '') {
    return rtrim(BASE_URL, '/');
  }
  $segments = array_filter(explode('/', $normalized), static function ($segment): bool {
    return $segment !== '' && $segment !== '.' && $segment !== '..';
  });
  $encodedSegments = array_map(static function (string $segment): string {
    return rawurlencode($segment);
  }, $segments);
  return rtrim(BASE_URL, '/') . '/' . implode('/', $encodedSegments);
}

function memo_build_audience_payload(array $departments, array $roles, array $employees, array $state = [], array $config = []): array {
  $options = [
    'shortcuts' => [[
      'type' => 'all',
      'identifier' => 'all',
      'label' => 'All employees',
      'tag' => '@all',
      'group' => 'Shortcuts',
      'search' => 'all employees everyone whole company',
    ]],
    'departments' => [],
    'roles' => [],
    'employees' => [],
  ];

  foreach ($departments as $dept) {
    $option = memo_department_to_option($dept);
    if ($option) {
      $options['departments'][] = $option;
    }
  }

  foreach ($roles as $role) {
    $option = memo_role_to_option($role);
    if ($option) {
      $options['roles'][] = $option;
    }
  }

  foreach ($employees as $emp) {
    if (isset($emp['type'], $emp['identifier']) && $emp['type'] === 'employee') {
      $normalized = $emp;
    } else {
      $normalized = memo_employee_to_option($emp);
    }
    if (!$normalized) {
      continue;
    }
    $options['employees'][] = $normalized;
  }

  $stateNormalized = [
    'all' => !empty($state['all']),
    'departments' => array_values(array_unique(array_map('strval', $state['departments'] ?? []))),
    'roles' => array_values(array_unique(array_map('strval', $state['roles'] ?? []))),
    'employees' => array_values(array_unique(array_map('strval', $state['employees'] ?? []))),
  ];

  $shortcuts = $options['shortcuts'] ?: [];
  if (!$shortcuts) {
    $shortcuts[] = memo_all_shortcut_option();
  }
  $options['shortcuts'] = $shortcuts;

  $minTerm = (int)($config['min_term'] ?? $config['minTermLength'] ?? 2);
  if ($minTerm < 0) {
    $minTerm = 0;
  }
  $debounceMs = (int)($config['debounce_ms'] ?? $config['debounceMs'] ?? 250);
  if ($debounceMs < 0) {
    $debounceMs = 0;
  }

  return [
    'options' => $options,
    'state' => $stateNormalized,
    'config' => [
      'endpoint' => $config['endpoint'] ?? null,
      'minTermLength' => $minTerm,
      'debounceMs' => $debounceMs,
    ],
  ];
}

function memo_fetch(PDO $pdo, int $memoId): ?array {
  try {
    $stmt = $pdo->prepare('SELECT m.*, COALESCE(u.full_name, m.issued_by_name) AS issuer_name, u.email AS issuer_email FROM memos m LEFT JOIN users u ON u.id = m.issued_by_user_id WHERE m.id = :id AND m.deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $memoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    sys_log('MEMO-GET', 'Failed to load memo: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId]]);
    return null;
  }
}

function memo_fetch_attachment(PDO $pdo, int $attachmentId): ?array {
  try {
    $hasFileContent = memo_table_has_column($pdo, 'memo_attachments', 'file_content');
    $fileContentCol = $hasFileContent ? ', ma.file_content' : '';
    $stmt = $pdo->prepare('SELECT ma.*' . $fileContentCol . ', m.allow_downloads, m.memo_code, m.header FROM memo_attachments ma JOIN memos m ON m.id = ma.memo_id WHERE ma.id = :id LIMIT 1');
    $stmt->execute([':id' => $attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
      return null;
    }
    
    // Handle BYTEA content if present - PostgreSQL returns it as a resource stream
    if ($hasFileContent && isset($row['file_content'])) {
      if (is_resource($row['file_content'])) {
        // It's a stream resource, read it
        $content = stream_get_contents($row['file_content']);
        if ($content === false) {
          sys_log('MEMO-ATTACH-STREAM', 'Failed to read BYTEA stream', [
            'module' => 'documents',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['attachment_id' => $attachmentId]
          ]);
          $row['file_content'] = null;
        } else {
          $row['file_content'] = $content;
        }
      } elseif (is_string($row['file_content']) && str_starts_with($row['file_content'], '\\x')) {
        // PostgreSQL might return BYTEA as hex string like '\x89504e47...'
        // Convert hex string to binary
        $hex = substr($row['file_content'], 2); // Remove '\x' prefix
        $row['file_content'] = hex2bin($hex);
      }
      // If it's already a string (binary data), leave it as is
    }
    
    return $row;
  } catch (Throwable $e) {
    sys_log('MEMO-ATTACH-ONE', 'Failed to load memo attachment: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['attachment_id' => $attachmentId]]);
    return null;
  }
}

function memo_dispatch_notifications(PDO $pdo, int $memoId, string $header, array $audienceRows): void {
  if (!$audienceRows) {
    return;
  }
  $notifyTitle = 'New Memo: ' . $header;
  $notifyBody = 'A new memo has been posted. Click to preview.';
  $viewPath = '/modules/memos/view?id=' . $memoId;
  $previewPath = '/modules/memos/preview_modal.php?id=' . $memoId;
  $notifyPayload = [
    'type' => 'memo',
    'memo_id' => $memoId,
    'view_path' => $viewPath,
    'preview_path' => $previewPath,
    'header' => $header,
  ];
  $notifyPacket = [
    'title' => $notifyTitle,
    'body' => $notifyBody,
    'payload' => $notifyPayload,
  ];
  $notifyAll = false;
  $userIds = [];

  $departmentIds = [];
  $roleCodes = [];
  $employeeIds = [];

  foreach ($audienceRows as $row) {
    $type = strtolower((string)($row['type'] ?? $row['audience_type'] ?? ''));
    $identifier = (string)($row['identifier'] ?? $row['audience_identifier'] ?? '');
    if ($type === 'all') {
      $notifyAll = true;
      continue;
    }
    if ($type === 'department' && $identifier !== '') {
      $departmentIds[] = (int)$identifier;
      continue;
    }
    if ($type === 'role' && $identifier !== '') {
      $roleCodes[] = $identifier;
      continue;
    }
    if ($type === 'employee' && $identifier !== '') {
      $employeeIds[] = (int)$identifier;
      continue;
    }
  }

  if ($employeeIds) {
    try {
      $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
      $stmt = $pdo->prepare('SELECT user_id FROM employees WHERE id IN (' . $placeholders . ') AND user_id IS NOT NULL');
      $stmt->execute($employeeIds);
      foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ($uid) {
          $userIds[] = (int)$uid;
        }
      }
    } catch (Throwable $e) {
      sys_log('MEMO-NOTIFY-EMP', 'Failed to load employee recipients: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
    }
  }

  if ($departmentIds) {
    try {
      $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
      $stmt = $pdo->prepare('SELECT user_id FROM employees WHERE department_id IN (' . $placeholders . ') AND status = \'active\' AND user_id IS NOT NULL');
      $stmt->execute($departmentIds);
      foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ($uid) {
          $userIds[] = (int)$uid;
        }
      }
    } catch (Throwable $e) {
      sys_log('MEMO-NOTIFY-DEPT', 'Failed to load department recipients: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
    }
  }

  if ($roleCodes) {
    try {
      $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
      $stmt = $pdo->prepare('SELECT id FROM users WHERE role IN (' . $placeholders . ') AND status = \'active\'');
      $stmt->execute($roleCodes);
      foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ($uid) {
          $userIds[] = (int)$uid;
        }
      }
    } catch (Throwable $e) {
      sys_log('MEMO-NOTIFY-ROLE', 'Failed to load role recipients: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
    }
  }

  if ($notifyAll) {
    try {
      notify(null, $notifyPacket);
    } catch (Throwable $e) {
      sys_log('MEMO-NOTIFY-ALL', 'Failed to broadcast memo notification: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId]]);
    }
  }

  if ($userIds) {
    $userIds = array_values(array_unique(array_filter($userIds)));
    foreach ($userIds as $targetUserId) {
      try {
        notify($targetUserId, $notifyPacket);
      } catch (Throwable $e) {
        sys_log('MEMO-NOTIFY-USER', 'Failed to queue memo notification: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId, 'user_id' => $targetUserId]]);
      }
    }
  }
}

function memo_user_has_access(PDO $pdo, int $memoId, int $userId): bool {
  if ($userId <= 0) {
    return false;
  }
  
  // Get user's role (for backward compatibility with old memo recipients)
  $role = '';
  try {
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $role = strtolower((string)$stmt->fetchColumn());
  } catch (Throwable $e) {
    // ignore
  }

  // Get employee info including position
  $employeeId = null;
  $departmentId = null;
  $positionId = null;
  try {
    $stmt = $pdo->prepare('SELECT id, department_id, position_id FROM employees WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $employeeId = (int)$row['id'];
      $departmentId = $row['department_id'] !== null ? (int)$row['department_id'] : null;
      $positionId = $row['position_id'] !== null ? (int)$row['position_id'] : null;
    }
  } catch (Throwable $e) {
    // ignore
  }

  $conditions = [];
  $params = [':memo_id' => $memoId];
  
  // Always check for 'all' audience
  $conditions[] = "r.audience_type = 'all'";
  
  // Check department-based audience
  if ($departmentId) {
    $conditions[] = "(r.audience_type = 'department' AND r.audience_identifier = :dept_id)";
    $params[':dept_id'] = (string)$departmentId;
  }
  
  // Check employee-specific audience
  if ($employeeId) {
    $conditions[] = "(r.audience_type = 'employee' AND r.audience_identifier = :emp_id)";
    $params[':emp_id'] = (string)$employeeId;
  }
  
  // Check role-based audience (backward compatibility with old enum-based roles)
  if ($role !== '') {
    $conditions[] = "(r.audience_type = 'role' AND r.audience_identifier = :role_code)";
    $params[':role_code'] = $role;
  }
  
  // Check position-based audience (new position system)
  if ($positionId) {
    $conditions[] = "(r.audience_type = 'role' AND r.audience_identifier = :pos_id)";
    $params[':pos_id'] = (string)$positionId;
  }

  if (!$conditions) {
    return false;
  }
  
  $sql = 'SELECT 1 FROM memo_recipients r WHERE r.memo_id = :memo_id AND (' . implode(' OR ', $conditions) . ') LIMIT 1';
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    sys_log('MEMO-ACCESS', 'Failed to check memo access: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId, 'user_id' => $userId]]);
    return false;
  }
}
