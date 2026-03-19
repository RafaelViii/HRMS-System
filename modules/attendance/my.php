<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

// Resolve employee linked to this user
$emp = null;
try {
    $st = $pdo->prepare('SELECT id, employee_code, first_name, last_name FROM employees WHERE user_id = :uid LIMIT 1');
    $st->execute([':uid' => $uid]);
    $emp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $emp = null; }

require_once __DIR__ . '/../../includes/header.php';
if (!$emp) {
    echo '<div class="card p-4 max-w-xl">';
    show_human_error('Your account is not linked to an employee profile.');
    echo '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Filters
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$params = [':eid' => (int)$emp['id']];
$where = 'a.employee_id = :eid';
if ($from) { $where .= ' AND a.date >= :from'; $params[':from'] = $from; }
if ($to)   { $where .= ' AND a.date <= :to';   $params[':to'] = $to; }

$rows = [];
try {
    $sql = "SELECT a.date, a.time_in, a.time_out, a.overtime_minutes, a.status
            FROM attendance a
            WHERE $where
            ORDER BY a.date DESC, a.id DESC
            LIMIT 200";
    $q = $pdo->prepare($sql);
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }
?>
<div class="card p-4">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
    <h1 class="text-xl font-semibold">My Attendance</h1>
    <form class="flex flex-wrap items-center gap-2" method="get">
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input-text">
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input-text">
      <button class="btn btn-icon" title="Filter" aria-label="Filter">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18M6 8h12M9 12h6M11 16h2"/></svg>
      </button>
    </form>
  </div>

  <div class="overflow-x-auto">
    <table class="table-basic min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left">Date</th>
          <th class="p-2 text-left">Time In</th>
          <th class="p-2 text-left">Time Out</th>
          <th class="p-2 text-left">Overtime (mins)</th>
          <th class="p-2 text-left">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="border-t">
          <td class="p-2"><?= htmlspecialchars($r['date']) ?></td>
          <td class="p-2"><?= htmlspecialchars($r['time_in'] ?? '') ?></td>
          <td class="p-2"><?= htmlspecialchars($r['time_out'] ?? '') ?></td>
          <td class="p-2"><?= (int)($r['overtime_minutes'] ?? 0) ?></td>
          <td class="p-2"><?= htmlspecialchars($r['status'] ?? '') ?></td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr><td class="p-3 text-gray-500" colspan="5">No attendance records.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php';
