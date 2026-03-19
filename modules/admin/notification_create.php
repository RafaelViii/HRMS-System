<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('notifications', 'create_notifications', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token';
  } else {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $target_user_id = $_POST['user_id'] !== '' ? (int)$_POST['user_id'] : null; // null = global memo
    if ($title === '') { $error = 'Title is required.'; }
    if ($body === '') { $error = 'Details are required.'; }
    if (!$error) {
      try {
        $trimmedTitle = function_exists('mb_substr') ? mb_substr($title, 0, 150) : substr($title, 0, 150);
        $st = $pdo->prepare('INSERT INTO notifications (user_id, title, body, message) VALUES (:uid, :title, :body, :msg)');
        $st->execute([
          ':uid' => $target_user_id,
          ':title' => $trimmedTitle,
          ':body' => $body,
          ':msg' => $body,
        ]);
        audit('notification_created', json_encode(['user_id'=>$target_user_id]));
        action_log('admin', 'create_notification', 'success', ['user_id' => $target_user_id]);
        flash_success('Notification created');
        header('Location: ' . BASE_URL . '/modules/admin/index'); exit;
      } catch (Throwable $e) {
        sys_log('ADMIN4101', 'Create notification failed - ' . $e->getMessage(), ['module'=>'admin','file'=>__FILE__,'line'=>__LINE__]);
        $error = 'Could not create notification.';
      }
    }
  }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="mb-3">
  <a class="btn btn-outline inline-flex items-center gap-2" href="<?= BASE_URL ?>/modules/admin/management">
    <span>&larr;</span>
    <span>Back to Management Hub</span>
  </a>
</div>
<div class="card p-4 max-w-xl">
  <h1 class="text-xl font-semibold mb-3">Create Notification / Memo</h1>
  <?php if ($error): ?><div class="bg-red-50 text-red-700 p-2 rounded mb-3 text-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="grid gap-3">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div>
      <label class="form-label">Title</label>
      <input type="text" name="title" class="w-full border rounded px-3 py-2" maxlength="150" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
      <p class="text-xs text-gray-500">Summarize the alert in a short phrase.</p>
    </div>
    <div>
      <label class="form-label">Details</label>
      <textarea name="body" class="w-full border rounded px-3 py-2" rows="4" required><?= htmlspecialchars($_POST['body'] ?? '') ?></textarea>
      <p class="text-xs text-gray-500">Use clear, human-friendly language. Markdown is not supported.</p>
    </div>
    <div>
      <label class="form-label">Target User (optional)</label>
      <input type="number" name="user_id" class="w-full border rounded px-3 py-2" placeholder="User ID or leave blank for global">
    </div>
    <div class="flex gap-2">
      <button class="btn btn-primary">Create</button>
      <a href="<?= BASE_URL ?>/modules/admin/index" class="btn">Cancel</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php';
