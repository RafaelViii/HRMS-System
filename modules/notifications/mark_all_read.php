<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf'] ?? '')) {
  if ($isAjax) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Invalid request']);
    exit;
  }
  header('Location: ' . BASE_URL . '/modules/notifications/index');
  exit;
}

$uid = (int)($_SESSION['user']['id'] ?? 0);
if ($uid > 0) {
  try {
    ensure_notification_reads($pdo);
    // Mark user-specific as read
    $st = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :uid AND is_read = FALSE");
    $st->execute([':uid' => $uid]);
    // Insert read markers for global notifications not yet marked by this user
    $pdo->prepare(
      'INSERT INTO notification_reads (notification_id, user_id)
       SELECT g.id, :uid FROM notifications g
       LEFT JOIN notification_reads r ON r.notification_id = g.id AND r.user_id = :uid
       WHERE g.user_id IS NULL AND r.notification_id IS NULL'
    )->execute([':uid' => $uid]);
    audit('notifications.mark_all_read', 'user_id=' . $uid);
    if ($isAjax) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => true]);
      exit;
    }
    flash_success('All notifications marked as read.');
  } catch (Throwable $e) {
    sys_log('DB4201', 'Mark all read failed - ' . $e->getMessage(), ['module'=>'notifications','file'=>__FILE__,'line'=>__LINE__]);
    if ($isAjax) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'message' => 'Could not mark as read']);
      exit;
    }
    flash_error('Could not mark notifications as read.');
  }
}

if ($isAjax) {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'message' => 'No user context']);
  exit;
}

header('Location: ' . BASE_URL . '/modules/notifications/index');
exit;
