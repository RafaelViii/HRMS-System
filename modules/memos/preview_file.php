<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/form_helpers.php';

$attachmentId = (int)($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
  http_response_code(404);
  echo 'Attachment not found.';
  exit;
}

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

$attachment = memo_fetch_attachment($pdo, $attachmentId);
if (!$attachment) {
  http_response_code(404);
  echo 'Attachment not found.';
  exit;
}

$memoId = (int)($attachment['memo_id'] ?? 0);
$hasAudienceAccess = $uid ? memo_user_has_access($pdo, $memoId, $uid) : false;
$canReadMemos = $user ? user_can('documents', 'memos', 'read') : false;
$canManageMemo = $user ? user_can('documents', 'memos', 'write') : false;

if (!$canReadMemos && !$canManageMemo && !$hasAudienceAccess) {
  http_response_code(403);
  echo 'You are not authorized to view this memo attachment.';
  exit;
}

// Check if file content is stored in database (preferred for Heroku)
$fileContent = $attachment['file_content'] ?? null;
$fileSize = (int)($attachment['file_size'] ?? 0);
$filePath = ''; // Initialize filePath variable

// Additional validation for database content
if ($fileContent !== null) {
  if (is_resource($fileContent)) {
    // Still a resource? Try to read it again
    $fileContent = stream_get_contents($fileContent);
    if ($fileContent === false) {
      sys_log('MEMO-ATTACH-RESOURCE-FAIL', 'Failed to read file content resource', [
        'module' => 'documents',
        'file' => __FILE__,
        'line' => __LINE__,
        'context' => ['attachment_id' => $attachmentId]
      ]);
      $fileContent = null;
    }
  } elseif (is_string($fileContent) && empty($fileContent)) {
    // Empty string
    sys_log('MEMO-ATTACH-EMPTY-STRING', 'File content is empty string', [
      'module' => 'documents',
      'file' => __FILE__,
      'line' => __LINE__,
      'context' => ['attachment_id' => $attachmentId]
    ]);
    $fileContent = null;
  }
}

// Log for debugging on production
sys_log('MEMO-ATTACH-DEBUG', 'Attachment fetch attempt', [
  'module' => 'documents',
  'file' => __FILE__,
  'line' => __LINE__,
  'context' => [
    'attachment_id' => $attachmentId,
    'has_file_content' => $fileContent !== null,
    'content_length' => $fileContent ? strlen($fileContent) : 0,
    'file_size' => $fileSize,
    'file_path' => $attachment['file_path'] ?? 'NULL',
    'original_name' => $attachment['original_name'] ?? 'NULL',
    'mime_type' => $attachment['mime_type'] ?? 'NULL',
  ]
]);

if ($fileContent === null) {
  // Fallback to filesystem if no database content
  $fileRel = (string)($attachment['file_path'] ?? '');
  if ($fileRel === '') {
    sys_log('MEMO-ATTACH-PATH', 'Memo attachment missing file path and content', ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['attachment_id' => $attachmentId]]);
    http_response_code(404);
    echo 'File is unavailable.';
    exit;
  }

  // Normalize path separators for cross-platform compatibility
  $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($fileRel, '/\\'));
  
  // Try both relative to project root and absolute path
  $projectRoot = dirname(__DIR__, 2);
  $filePath = $projectRoot . DIRECTORY_SEPARATOR . $normalizedPath;
  
  // If not found, try the path as-is (might be absolute)
  if (!is_file($filePath) && is_file($fileRel)) {
    $filePath = $fileRel;
  }

  if (!is_file($filePath)) {
    sys_log('MEMO-ATTACH-FNF', 'Memo attachment file missing on disk and no database content', [
      'module' => 'documents',
      'file' => __FILE__,
      'line' => __LINE__,
      'context' => [
        'attachment_id' => $attachmentId,
        'file_rel' => $fileRel,
        'normalized_path' => $normalizedPath,
        'project_root' => $projectRoot,
        'constructed_path' => $filePath,
        'file_exists' => file_exists($filePath),
        'is_file' => is_file($filePath),
      ]
    ]);
    http_response_code(404);
    echo 'File not found.';
    exit;
  }

  if (!is_readable($filePath)) {
    sys_log('MEMO-ATTACH-PERM', 'Memo attachment file not readable', [
      'module' => 'documents',
      'file' => __FILE__,
      'line' => __LINE__,
      'context' => [
        'attachment_id' => $attachmentId,
        'path' => $filePath,
        'readable' => is_readable($filePath),
        'fileperms' => substr(sprintf('%o', fileperms($filePath)), -4),
      ]
    ]);
    http_response_code(403);
    echo 'File permission denied.';
    exit;
  }
  
  $fileContent = file_get_contents($filePath);
  if ($fileContent === false) {
    http_response_code(500);
    echo 'Failed to read file.';
    exit;
  }
  $fileSize = filesize($filePath);
}

// Final validation
if ($fileContent === null || $fileContent === '') {
  sys_log('MEMO-ATTACH-NO-CONTENT', 'No file content available after all attempts', [
    'module' => 'documents',
    'file' => __FILE__,
    'line' => __LINE__,
    'context' => [
      'attachment_id' => $attachmentId,
      'file_path' => $attachment['file_path'] ?? 'NULL',
      'had_db_content' => ($attachment['file_content'] ?? null) !== null,
    ]
  ]);
  http_response_code(404);
  echo 'File content is not available.';
  exit;
}

// Verify content length matches expected size
$actualSize = strlen($fileContent);
if ($actualSize === 0) {
  sys_log('MEMO-ATTACH-ZERO-LENGTH', 'File content has zero length', [
    'module' => 'documents',
    'file' => __FILE__,
    'line' => __LINE__,
    'context' => [
      'attachment_id' => $attachmentId,
      'expected_size' => $fileSize,
      'actual_size' => $actualSize,
    ]
  ]);
  http_response_code(500);
  echo 'File content is empty.';
  exit;
}

// Update file size to actual if different
if ($actualSize !== $fileSize) {
  sys_log('MEMO-ATTACH-SIZE-MISMATCH', 'File size mismatch', [
    'module' => 'documents',
    'file' => __FILE__,
    'line' => __LINE__,
    'context' => [
      'attachment_id' => $attachmentId,
      'expected_size' => $fileSize,
      'actual_size' => $actualSize,
    ]
  ]);
  $fileSize = $actualSize;
}

$mime = trim((string)($attachment['mime_type'] ?? ''));
if ($mime === '') {
  // Try to determine from file extension
  $originalName = trim((string)($attachment['original_name'] ?? ''));
  $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
  $mime = match ($ext) {
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    default => 'application/octet-stream',
  };
}

$filename = trim((string)($attachment['original_name'] ?? ''));
if ($filename === '') {
  $filename = 'attachment_' . $attachmentId;
}

action_log('documents', 'memo_attachment_preview', 'success', [
  'memo_id' => $memoId,
  'attachment_id' => $attachmentId,
  'user_id' => $uid,
]);

audit('memo_attachment_preview', json_encode([
  'memo_id' => $memoId,
  'attachment_id' => $attachmentId,
  'user_id' => $uid,
]));

// Clear ALL output buffers to avoid corruption
while (ob_get_level()) {
  ob_end_clean();
}

// Ensure no previous output
if (headers_sent($file, $line)) {
  sys_log('MEMO-ATTACH-HEADERS-SENT', 'Headers already sent before output', [
    'module' => 'documents',
    'file' => __FILE__,
    'line' => __LINE__,
    'context' => [
      'attachment_id' => $attachmentId,
      'sent_from_file' => $file,
      'sent_from_line' => $line,
    ]
  ]);
}

// Set proper headers for image delivery
header('Content-Type: ' . $mime);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600, must-revalidate');
header('Accept-Ranges: bytes');
header('X-Frame-Options: SAMEORIGIN');

// Disable error reporting for clean output
$oldErrorReporting = error_reporting(0);

// Output the binary content
echo $fileContent;

// Restore error reporting
error_reporting($oldErrorReporting);

exit;
