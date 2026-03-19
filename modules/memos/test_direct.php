<?php
/**
 * Direct test of attachment serving
 * Access: /modules/memos/test_direct?id=35
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/form_helpers.php';

$attachmentId = (int)($_GET['id'] ?? 0);

if ($attachmentId <= 0) {
    die('Error: No attachment ID provided. Use ?id=35');
}

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

echo "<h1>Direct Attachment Test</h1>";
echo "<p><strong>Attachment ID:</strong> $attachmentId</p>";
echo "<p><strong>User:</strong> " . htmlspecialchars($user['full_name']) . " (ID: $uid)</p>";

$attachment = memo_fetch_attachment($pdo, $attachmentId);

if (!$attachment) {
    die('<p style="color:red">❌ Attachment not found in database</p>');
}

echo "<h2>✅ Attachment Found</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Value</th></tr>";
echo "<tr><td>ID</td><td>" . htmlspecialchars($attachment['id']) . "</td></tr>";
echo "<tr><td>Memo ID</td><td>" . htmlspecialchars($attachment['memo_id']) . "</td></tr>";
echo "<tr><td>Original Name</td><td>" . htmlspecialchars($attachment['original_name']) . "</td></tr>";
echo "<tr><td>File Path</td><td>" . htmlspecialchars($attachment['file_path'] ?? 'NULL') . "</td></tr>";
echo "<tr><td>File Size</td><td>" . htmlspecialchars($attachment['file_size']) . " bytes</td></tr>";
echo "<tr><td>MIME Type</td><td>" . htmlspecialchars($attachment['mime_type']) . "</td></tr>";

$fileContent = $attachment['file_content'] ?? null;
$hasContent = $fileContent !== null;
$contentLength = 0;

if ($hasContent) {
    if (is_resource($fileContent)) {
        $fileContent = stream_get_contents($fileContent);
        $hasContent = $fileContent !== false && $fileContent !== '';
    }
    if ($hasContent) {
        $contentLength = strlen($fileContent);
    }
}

echo "<tr><td>Has DB Content</td><td>" . ($hasContent ? '✅ YES' : '❌ NO') . "</td></tr>";
echo "<tr><td>Content Length</td><td>" . number_format($contentLength) . " bytes</td></tr>";
echo "<tr><td>Match</td><td>" . ($contentLength === (int)$attachment['file_size'] ? '✅ YES' : '❌ NO - MISMATCH!') . "</td></tr>";
echo "</table>";

// Check access
$memoId = (int)$attachment['memo_id'];
$hasAudienceAccess = memo_user_has_access($pdo, $memoId, $uid);
$canReadMemos = user_can('documents', 'memos', 'read');
$canManageMemo = user_can('documents', 'memos', 'write');

echo "<h2>Access Check</h2>";
echo "<ul>";
echo "<li><strong>Can Read Memos:</strong> " . ($canReadMemos ? '✅ YES' : '❌ NO') . "</li>";
echo "<li><strong>Can Manage Memos:</strong> " . ($canManageMemo ? '✅ YES' : '❌ NO') . "</li>";
echo "<li><strong>Has Audience Access:</strong> " . ($hasAudienceAccess ? '✅ YES' : '❌ NO') . "</li>";
$hasAccess = $canReadMemos || $canManageMemo || $hasAudienceAccess;
echo "<li><strong>Final Access:</strong> " . ($hasAccess ? '✅ GRANTED' : '❌ DENIED') . "</li>";
echo "</ul>";

if (!$hasAccess) {
    die('<p style="color:red">❌ You do not have access to this memo</p>');
}

if (!$hasContent) {
    die('<p style="color:red">❌ File content is not available in database or filesystem</p>');
}

// Test URLs
$previewUrl = BASE_URL . '/modules/memos/preview_file?id=' . $attachmentId;
$downloadUrl = BASE_URL . '/modules/memos/download?id=' . $attachmentId;

echo "<h2>Test URLs (extensionless)</h2>";
echo "<ul>";
echo "<li><strong>Preview:</strong> <a href='" . htmlspecialchars($previewUrl) . "' target='_blank'>" . htmlspecialchars($previewUrl) . "</a></li>";
echo "<li><strong>Download:</strong> <a href='" . htmlspecialchars($downloadUrl) . "' target='_blank'>" . htmlspecialchars($downloadUrl) . "</a></li>";
echo "</ul>";

// Try to display image
if (str_starts_with($attachment['mime_type'], 'image/')) {
    echo "<h2>Image Preview Test</h2>";
    echo "<p>If the image loads below, the preview is working:</p>";
    echo "<img src='" . htmlspecialchars($previewUrl) . "' alt='Test' style='max-width:500px; border:2px solid #ccc;' onerror=\"this.style.display='none'; this.nextElementSibling.style.display='block';\">";
    echo "<p style='display:none; color:red; font-weight:bold;'>❌ Image failed to load!</p>";
    
    echo "<h3>Debug: Direct Data URL</h3>";
    echo "<p>Testing with inline base64 data:</p>";
    $base64 = base64_encode($fileContent);
    echo "<img src='data:" . htmlspecialchars($attachment['mime_type']) . ";base64," . $base64 . "' alt='Direct' style='max-width:500px; border:2px solid green;'>";
    echo "<p style='color:green;'>☝️ If you see the image above, the file content IS valid in the database!</p>";
}

echo "<hr>";
echo "<p><a href='" . BASE_URL . "/modules/memos/index'>← Back to Memos</a></p>";
?>
