<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('notifications', 'view_notifications', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    if ($isAjax) {
      http_response_code(400);
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']);
      exit;
    }
    flash_error('Invalid CSRF token.');
    header('Location: ' . BASE_URL . '/modules/notifications/index'); exit;
  }
  $nid = (int)($_POST['id'] ?? 0);
  if ($nid > 0) {
    try {
      ensure_notification_reads($pdo);
      // Determine if notification is user-specific or global
      $st = $pdo->prepare('SELECT user_id FROM notifications WHERE id = :id');
      $st->execute([':id' => $nid]);
      $owner = $st->fetchColumn();
      if ($owner === false) {
        if ($isAjax) {
          http_response_code(404);
          header('Content-Type: application/json');
          echo json_encode(['ok' => false, 'message' => 'Notification not found']);
          exit;
        }
        flash_error('Notification not found.');
      }
      else if ($owner !== null) {
        // User-specific: only the owner can mark read
        if ((int)$owner === $uid) {
          $u = $pdo->prepare('UPDATE notifications SET is_read = TRUE WHERE id = :id AND user_id = :uid');
          $u->execute([':id'=>$nid, ':uid'=>$uid]);
          audit('notifications.mark_read', 'id=' . $nid . ', user=' . $uid);
          if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
          }
          flash_success('Marked as read.');
        } else {
          if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Not authorized']);
            exit;
          }
          flash_error('Not authorized to modify this notification.');
        }
      } else {
        // Global: insert read marker for this user (idempotent)
        $i = $pdo->prepare('INSERT INTO notification_reads (notification_id, user_id) VALUES (:nid, :uid) ON CONFLICT (notification_id, user_id) DO NOTHING');
        $i->execute([':nid'=>$nid, ':uid'=>$uid]);
        audit('notifications.mark_read', 'global_id=' . $nid . ', user=' . $uid);
        if ($isAjax) {
          header('Content-Type: application/json');
          echo json_encode(['ok' => true]);
          exit;
        }
        flash_success('Marked as read.');
      }
    } catch (Throwable $e) {
      sys_log('DB4202', 'Mark read failed - ' . $e->getMessage(), ['module'=>'notifications','file'=>__FILE__,'line'=>__LINE__]);
      if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Could not mark as read']);
        exit;
      }
      flash_error('Could not mark as read.');
    }
  }
}

if ($isAjax) {
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'message' => 'Invalid request']);
  exit;
}

header('Location: ' . BASE_URL . '/modules/notifications/index');
exit;
