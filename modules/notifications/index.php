<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('notifications', 'view_notifications', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/header.php';
$pdo = get_db_conn();

$uid = $_SESSION['user']['id'];
ensure_notification_reads($pdo);
// Latest notifications (global + user-specific)
try {
  $stmt = $pdo->prepare(
    'SELECT n.id,
            COALESCE(NULLIF(n.title, \'\'), LEFT(n.message, 120)) AS title,
            COALESCE(NULLIF(n.body, \'\'), n.message) AS body,
            n.is_read,
            n.created_at,
            CASE WHEN n.user_id IS NULL THEN (r.notification_id IS NULL) ELSE (NOT n.is_read) END AS is_unread_for_user
     FROM notifications n
     LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :uid
     WHERE n.user_id IS NULL OR n.user_id = :uid
     ORDER BY n.id DESC LIMIT 100'
  );
  $stmt->execute([':uid'=>$uid]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }
// Unread count (user-specific only)
$unread = 0;

try {
  $stc = $pdo->prepare(
    'SELECT (
        SELECT COUNT(*) FROM notifications n WHERE n.user_id = :uid AND n.is_read = FALSE
      ) + (
        SELECT COUNT(*) FROM notifications g
        LEFT JOIN notification_reads r ON r.notification_id = g.id AND r.user_id = :uid
        WHERE g.user_id IS NULL AND r.notification_id IS NULL
      ) AS cnt'
  );
  $stc->execute([':uid'=>$uid]);
  $unread = (int)($stc->fetchColumn() ?: 0);
} catch (Throwable $e) { $unread = 0; }
?>
<div class="card">
  <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between p-4 mb-3">
    <h1 class="text-xl font-semibold">Notifications</h1>
    <?php if ($unread > 0): ?>
      <form method="post" action="<?= BASE_URL ?>/modules/notifications/mark_all_read" onsubmit="return confirm('Mark all as read?');">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <button class="btn">Mark all as read (<?= (int)$unread ?>)</button>
      </form>
    <?php endif; ?>
  </div>
  <ul class="text-sm">
    <?php foreach ($rows as $n): ?>
      <li class="border-t p-3 flex items-start justify-between gap-3">
        <div class="flex-1">
          <div class="text-sm font-semibold text-gray-800 <?= !empty($n['is_unread_for_user']) ? 'underline decoration-blue-400' : '' ?>"><?= htmlspecialchars($n['title']) ?></div>
          <div class="text-sm text-gray-600 mt-1 leading-snug"><?= nl2br(htmlspecialchars($n['body'])) ?></div>
          <div class="text-[11px] text-gray-500 mt-2 uppercase tracking-wide"><?= htmlspecialchars(format_datetime_display($n['created_at'], true)) ?></div>
        </div>
        <div class="flex items-center gap-2">
          <?php if (!empty($n['is_unread_for_user'])): ?><span class="mt-1 inline-block w-2 h-2 rounded-full bg-blue-500" title="Unread"></span><?php endif; ?>
          <?php if (!empty($n['is_unread_for_user'])): ?>
          <form method="post" action="<?= BASE_URL ?>/modules/notifications/mark_read" class="inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
            <button class="btn" title="Mark as read">Mark read</button>
          </form>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; if (!$rows): ?>
      <li>No notifications.</li>
    <?php endif; ?>
  </ul>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
