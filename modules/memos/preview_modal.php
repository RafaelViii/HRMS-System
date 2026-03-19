<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/form_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$memoId = (int)($_GET['id'] ?? $_GET['memo_id'] ?? 0);
if ($memoId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid memo id']);
  exit;
}

$pdo = get_db_conn();
$userId = (int)($_SESSION['user']['id'] ?? 0);

if ($userId <= 0) {
  http_response_code(403);
  echo json_encode(['error' => 'Authentication required']);
  exit;
}

// Check if user has access to this memo
$hasAudienceAccess = memo_user_has_access($pdo, $memoId, $userId);
$canReadMemos = user_can('documents', 'memos', 'read');
$canManageMemo = user_can('documents', 'memos', 'write');

// Log access check for debugging
sys_log('MEMO-PREVIEW-ACCESS', 'Memo preview access check', [
  'module' => 'documents',
  'file' => __FILE__,
  'line' => __LINE__,
  'context' => [
    'memo_id' => $memoId,
    'user_id' => $userId,
    'has_audience_access' => $hasAudienceAccess,
    'can_read_memos' => $canReadMemos,
    'can_manage_memo' => $canManageMemo,
  ]
]);

// Allow access if user has any of: audience access, read permission, or manage permission
if (!$hasAudienceAccess && !$canReadMemos && !$canManageMemo) {
  http_response_code(403);
  echo json_encode([
    'error' => 'You do not have permission to view this memo',
    'debug' => [
      'audience_access' => $hasAudienceAccess,
      'read_permission' => $canReadMemos,
      'manage_permission' => $canManageMemo,
    ]
  ]);
  exit;
}

$memo = memo_fetch($pdo, $memoId);
if (!$memo) {
  http_response_code(404);
  echo json_encode(['error' => 'Memo not found']);
  exit;
}

$attachments = memo_fetch_attachments($pdo, $memoId);
$allowDownloads = !empty($memo['allow_downloads']);

$issuedBy = trim((string)($memo['issued_by_name'] ?? ''));
$position = trim((string)($memo['issued_by_position'] ?? ''));
if ($issuedBy !== '' && $position !== '') {
  $issuedBy .= ' • ' . $position;
} elseif ($position !== '') {
  $issuedBy = $position;
}

$body = (string)($memo['body'] ?? '');
$bodyNormalized = preg_replace('/\s+/', ' ', trim($body));
if ($bodyNormalized === '') {
  $bodyExcerpt = 'No memo content was provided.';
} else {
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    $limit = 360;
    if (mb_strlen($bodyNormalized, 'UTF-8') > $limit) {
      $bodyExcerpt = rtrim(mb_substr($bodyNormalized, 0, $limit, 'UTF-8')) . '…';
    } else {
      $bodyExcerpt = $bodyNormalized;
    }
  } else {
    $limit = 360;
    if (strlen($bodyNormalized) > $limit) {
      $bodyExcerpt = rtrim(substr($bodyNormalized, 0, $limit)) . '…';
    } else {
      $bodyExcerpt = $bodyNormalized;
    }
  }
}

$attachmentsPayload = [];
foreach (array_slice($attachments, 0, 3) as $attachment) {
  $filePath = (string)($attachment['file_path'] ?? '');
  $ext = strtolower((string)pathinfo($attachment['original_name'] ?? '', PATHINFO_EXTENSION));
  $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
  $previewUrl = BASE_URL . '/modules/memos/preview_file?id=' . (int)$attachment['id'];
  $downloadUrl = BASE_URL . '/modules/memos/download?id=' . (int)$attachment['id'];
  $sizeLabel = !empty($attachment['file_size'])
    ? number_format(((int)$attachment['file_size']) / 1024, 1) . ' KB'
    : 'Unknown size';
  $attachmentsPayload[] = [
    'id' => (int)$attachment['id'],
    'name' => (string)($attachment['original_name'] ?? 'Attachment'),
    'is_image' => $isImage,
    'preview_url' => $previewUrl,
    'download_url' => $downloadUrl,
    'mime_type' => (string)($attachment['mime_type'] ?? ''),
    'size_label' => $sizeLabel,
  ];
}

$response = [
  'id' => $memoId,
  'header' => (string)($memo['header'] ?? 'Memo'),
  'memo_code' => (string)($memo['memo_code'] ?? ''),
  'issued_by' => $issuedBy,
  'published_at' => format_datetime_display($memo['published_at'] ?? null, false, ''),
  'updated_at' => format_datetime_display($memo['updated_at'] ?? null, false, ''),
  'body_excerpt' => $bodyExcerpt,
  'view_url' => BASE_URL . '/modules/memos/view?id=' . $memoId,
  'allow_downloads' => $allowDownloads,
  'attachments' => $attachmentsPayload,
  'attachments_total' => count($attachments),
];

echo json_encode($response);
