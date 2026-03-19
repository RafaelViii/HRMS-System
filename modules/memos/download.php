<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/form_helpers.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$attachmentId = (int)($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
  http_response_code(404);
  echo 'Attachment not found.';
  exit;
}

$attachment = memo_fetch_attachment($pdo, $attachmentId);
if (!$attachment) {
  http_response_code(404);
  echo 'Attachment not found.';
  exit;
}

$memoId = (int)$attachment['memo_id'];

$hasAudienceAccess = $uid ? memo_user_has_access($pdo, $memoId, $uid) : false;
$canReadMemos = $user ? user_can('documents', 'memos', 'read') : false;
$canManageMemo = $user ? user_can('documents', 'memos', 'write') : false;

if (!$canReadMemos && !$canManageMemo && !$hasAudienceAccess) {
  http_response_code(403);
  echo 'You are not authorized to access this memo.';
  exit;
}

if (!(bool)$attachment['allow_downloads']) {
  http_response_code(403);
  echo 'Downloading has been disabled for this memo.';
  exit;
}

// Check if file content is stored in database (preferred for Heroku)
$fileContent = $attachment['file_content'] ?? null;
$fileSize = (int)($attachment['file_size'] ?? 0);

// Additional validation for database content
if ($fileContent !== null) {
  if (is_resource($fileContent)) {
    // Still a resource? Try to read it again
    $fileContent = stream_get_contents($fileContent);
    if ($fileContent === false) {
      sys_log('MEMO-DL-RESOURCE-FAIL', 'Failed to read file content resource', [
        'module' => 'documents',
        'file' => __FILE__,
        'line' => __LINE__,
        'context' => ['attachment_id' => $attachmentId]
      ]);
      $fileContent = null;
    }
  }
}

if ($fileContent === null) {
  // Fallback to filesystem if no database content
  $relativePath = (string)($attachment['file_path'] ?? '');
  if ($relativePath === '') {
    sys_log('MEMO-DL-NO-PATH', 'File path missing', [
      'module' => 'documents',
      'file' => __FILE__,
      'line' => __LINE__,
      'context' => ['attachment_id' => $attachmentId]
    ]);
    http_response_code(404);
    echo 'File path missing.';
    exit;
  }
  $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
  $filePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $normalizedPath;
  if (!is_file($filePath)) {
    sys_log('MEMO-DL-NOT-FOUND', 'File not found on disk', [
      'module' => 'documents',
      'file' => __FILE__,
      'line' => __LINE__,
      'context' => [
        'attachment_id' => $attachmentId,
        'path' => $filePath,
        'relative_path' => $relativePath,
      ]
    ]);
    http_response_code(404);
    echo 'File not found.';
    exit;
  }
  
  $fileContent = file_get_contents($filePath);
  if ($fileContent === false) {
    sys_log('MEMO-DL-READ-FAIL', 'Failed to read file', [
      'module' => 'documents',
      'file' => __FILE__,
      'line' => __LINE__,
      'context' => ['attachment_id' => $attachmentId, 'path' => $filePath]
    ]);
    http_response_code(500);
    echo 'Failed to read file.';
    exit;
  }
  $fileSize = filesize($filePath);
}

// Validate content
if ($fileContent === null || $fileContent === '' || strlen($fileContent) === 0) {
  sys_log('MEMO-DL-EMPTY', 'File content is empty', [
    'module' => 'documents',
    'file' => __FILE__,
    'line' => __LINE__,
    'context' => [
      'attachment_id' => $attachmentId,
      'content_length' => $fileContent ? strlen($fileContent) : 0,
    ]
  ]);
  http_response_code(404);
  echo 'File content is empty.';
  exit;
}

// Update file size to actual
$actualSize = strlen($fileContent);
if ($actualSize !== $fileSize) {
  $fileSize = $actualSize;
}

$mime = $attachment['mime_type'] ?: 'application/octet-stream';
$filename = $attachment['original_name'] ?: 'download';

action_log('documents', 'memo_attachment_download', 'success', ['memo_id' => $memoId, 'attachment_id' => $attachmentId, 'user_id' => $uid]);

audit('memo_attachment_download', json_encode(['memo_id' => $memoId, 'attachment_id' => $attachmentId, 'user_id' => $uid]));

// Clear ALL output buffers
while (ob_get_level()) {
  ob_end_clean();
}

// Disable error reporting for clean output
$oldErrorReporting = error_reporting(0);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . $fileSize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $fileContent;

// Restore error reporting
error_reporting($oldErrorReporting);

exit;
