<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'audit_logs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/header.php';
$pdo = get_db_conn();

// Helper: try to restore a deleted employee (and possibly its account) from backups
function try_restore_employee_from_backup($pdo, int $empId): bool {
    $ok = false;
  $pdo->beginTransaction();
    try {
        // Fetch backup row
    $st = $pdo->prepare('SELECT * FROM employees_backup WHERE id = :id');
    $st->execute([':id'=>$empId]); $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('no employee backup row');
        $userId = (int)($row['user_id'] ?? 0);
        // Restore employee first
    $ins = $pdo->prepare('INSERT INTO employees SELECT * FROM employees_backup WHERE id = :id');
    $ins->execute([':id'=>$empId]);
        // If user_id present and user missing, try restoring user too
        if ($userId > 0) {
      $chk = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
      $chk->execute([':id'=>$userId]); $exists = (bool)$chk->fetchColumn();
            if (!$exists) {
        $ru = $pdo->prepare('INSERT INTO users SELECT * FROM users_backup WHERE id = :id');
        $ru->execute([':id'=>$userId]);
            }
        }
    $pdo->commit();
        $ok = true;
    } catch (Throwable $e) {
    $pdo->rollBack();
        sys_log('REV1001', 'Employee restore failed: ' . $e->getMessage(), ['module'=>'audit','file'=>__FILE__,'line'=>__LINE__,'context'=>['empId'=>$empId]]);
        $ok = false;
    }
    return $ok;
}

// Handle reversal request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reverse_id']) && csrf_verify($_POST['csrf'] ?? '')) {
    $authz = ensure_action_authorized('audit', 'reverse_action', 'admin');
  if (!$authz['ok']) { flash_error('Changes could not be saved'); header('Location: ' . BASE_URL . '/modules/audit/index'); exit; }
  $aid = (int)$_POST['reverse_id'];
    $reason = trim($_POST['reason'] ?? 'Manual reversal');
    // Prevent double reversal
  $chk = $pdo->prepare('SELECT 1 FROM action_reversals WHERE audit_log_id = :id');
  $chk->execute([':id'=>$aid]); if ($chk->fetchColumn()) { flash_error('Changes could not be saved'); header('Location: ' . BASE_URL . '/modules/audit/index'); exit; }
    // Determine action
  $ast = $pdo->prepare('SELECT action, details FROM audit_logs WHERE id = :id');
  $ast->execute([':id'=>$aid]); $alog = $ast->fetch(PDO::FETCH_ASSOC);
    $act = strtolower($alog['action'] ?? ''); $det = (string)($alog['details'] ?? '');

    $domainOk = true;
    if (strpos($act, 'delete_employee') !== false) {
        // Expect details like 'ID=123'
        if (preg_match('/ID\s*=\s*(\d+)/i', $det, $m)) {
            $empId = (int)$m[1];
      $domainOk = try_restore_employee_from_backup($pdo, $empId);
        }
    } elseif (strpos($act, 'unbind_account') !== false || strpos($act, 'delete_account_cascade') !== false) {
        // Expect details include user_id and maybe emp code
        $uid = null; if (preg_match('/user_id\s*=\s*(\d+)/i', $det, $m)) { $uid = (int)$m[1]; }
        if ($uid) {
            try {
                // Restore user from backup if missing
        $chkU = $pdo->prepare('SELECT 1 FROM users WHERE id = :id'); $chkU->execute([':id'=>$uid]); $exists = (bool)$chkU->fetchColumn();
        if (!$exists) { $ru = $pdo->prepare('INSERT INTO users SELECT * FROM users_backup WHERE id = :id'); $ru->execute([':id'=>$uid]); }
                // Try to rebind to employee if emp code present
                if (preg_match('/emp\s*=\s*([A-Za-z0-9\-_.]+)/i', $det, $n)) {
                    $code = $n[1];
          $fe = $pdo->prepare('SELECT id FROM employees WHERE employee_code = :code'); $fe->execute([':code'=>$code]); $eid = (int)($fe->fetchColumn() ?: 0); if ($eid) { $up = $pdo->prepare('UPDATE employees SET user_id = :uid WHERE id = :id'); $up->execute([':uid'=>$uid, ':id'=>$eid]); }
                }
                $domainOk = true;
            } catch (Throwable $e) {
                sys_log('REV1002', 'User restore failed: ' . $e->getMessage(), ['module'=>'audit','file'=>__FILE__,'line'=>__LINE__]);
                $domainOk = false;
            }
        }
    }
    // Record reversal even if domain restore failed (still marked sensitive); success banner only if domainOk
  try { $ins = $pdo->prepare('INSERT INTO action_reversals (audit_log_id, reversed_by, reason) VALUES (:aid, :uid, :reason)'); $uid = (int)($_SESSION['user']['id'] ?? 0); $ins->execute([':aid'=>$aid, ':uid'=>$uid, ':reason'=>$reason]); } catch (Throwable $e) {}
  audit('reverse_action', 'audit_id=' . $aid);
  sys_log('ACTION-REV', 'Action reversed', ['module'=>'audit','file'=>__FILE__,'line'=>__LINE__,'context'=>['audit_id'=>$aid,'domain_ok'=>$domainOk]]);
    if ($domainOk) flash_success('Changes have been saved'); else flash_error('Changes could not be saved');
  header('Location: ' . BASE_URL . '/modules/audit/index'); exit;
}
$rows = [];
try { $rows = $pdo->query('SELECT a.id, a.created_at, COALESCE(u.full_name, \'-\') AS full_name, a.action, COALESCE(a.details, \'\') AS details, a.module, a.action_type, a.status,
  (SELECT COUNT(*) FROM action_reversals ar WHERE ar.audit_log_id=a.id) AS is_reversed
  FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $rows = []; }
?>
<div class="bg-white p-4 rounded shadow">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
    <h1 class="text-xl font-semibold">Action Log</h1>
    <div class="flex flex-wrap items-center gap-2">
      <div class="dropdown">
        <button class="btn btn-accent" data-dd-toggle>Export</button>
        <div class="dropdown-menu hidden">
          <a class="dropdown-item csv" href="<?= BASE_URL ?>/modules/audit/csv?q=<?= urlencode($_GET['q'] ?? '') ?>" target="_blank" rel="noopener" data-no-loader>CSV</a>
          <a class="dropdown-item pdf" href="pdf" target="_blank" rel="noopener">PDF</a>
        </div>
      </div>
      <form class="flex gap-2" method="get">
        <input name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" class="input-text" placeholder="Search action/user">
        <button class="btn btn-outline btn-icon" aria-label="Search" title="Search" type="submit">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="18" height="18" aria-hidden="true">
            <path fill-rule="evenodd" d="M8.5 3a5.5 5.5 0 103.89 9.39l3.61 3.61a1 1 0 001.42-1.42l-3.61-3.61A5.5 5.5 0 008.5 3zm-3.5 5.5a3.5 3.5 0 116.999.001A3.5 3.5 0 015 8.5z" clip-rule="evenodd"/>
          </svg>
        </button>
      </form>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50"><tr><th class="p-2 text-left">Time</th><th class="p-2 text-left">User</th><th class="p-2 text-left">Module</th><th class="p-2 text-left">Action</th><th class="p-2 text-left">Details</th><th class="p-2 text-left">Status</th><th class="p-2 text-left">Reversal</th></tr></thead>
      <tbody>
  <?php foreach ($rows as $r): $rev = (int)($r['is_reversed'] ?? 0) > 0; ?>
          <tr class="border-t <?= $rev ? 'bg-red-50' : '' ?>">
            <td class="p-2 text-gray-500 whitespace-nowrap"><?= date('M d, Y h:i A', strtotime($r['created_at'])) ?></td>
            <td class="p-2"><?= htmlspecialchars($r['full_name'] ?? '-') ?></td>
            <td class="p-2">
              <?php
                $displayModule = $r['module'] ?? '';
                // Try to extract module from JSON details if column is empty
                if (empty($displayModule) && !empty($r['details'])) {
                    $parsed = @json_decode($r['details'], true);
                    if (is_array($parsed) && !empty($parsed['module'])) {
                        $displayModule = $parsed['module'];
                    }
                }
              ?>
              <?php if ($displayModule): ?>
                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700"><?= htmlspecialchars(ucfirst($displayModule)) ?></span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <td class="p-2">
              <?= htmlspecialchars(ucwords(str_replace('_', ' ', $r['action']))) ?>
              <?php if ($rev): ?><span class="ml-1 text-[10px] uppercase text-red-600 font-semibold">reversed</span><?php endif; ?>
            </td>
            <td class="p-2">
              <?php
                $det = $r['details'];
                // If details is legacy JSON, build a readable English sentence
                $displayDetail = $det;
                $jsonData = @json_decode($det, true);
                if (is_array($jsonData)) {
                    $metaForSentence = [];
                    if (!empty($jsonData['meta']) && is_array($jsonData['meta'])) {
                        $metaForSentence = $jsonData['meta'];
                    }
                    // Also merge in top-level non-system keys
                    foreach ($jsonData as $k => $v) {
                        if (!in_array($k, ['module', 'status', 'meta']) && is_scalar($v)) {
                            $metaForSentence[$k] = $v;
                        }
                    }
                    $displayDetail = _build_action_sentence(
                        $jsonData['module'] ?? $r['module'] ?? '',
                        $r['action'] ?? '',
                        $jsonData['status'] ?? $r['status'] ?? 'success',
                        $metaForSentence
                    );
                }
                $truncated = mb_strlen($displayDetail) > 100 ? mb_substr($displayDetail, 0, 100) . '…' : $displayDetail;
              ?>
              <span class="text-slate-600"><?= htmlspecialchars($truncated) ?></span>
              <?php if (mb_strlen($det) > 0): ?>
              <button type="button" class="ml-1 text-xs text-indigo-600 hover:text-indigo-800 font-medium hover:underline" onclick="openAuditDetailModal(<?= (int)$r['id'] ?>, this)" data-detail="<?= htmlspecialchars($det, ENT_QUOTES) ?>" data-sentence="<?= htmlspecialchars($displayDetail, ENT_QUOTES) ?>" data-action="<?= htmlspecialchars($r['action'] ?? '', ENT_QUOTES) ?>">View</button>
              <?php endif; ?>
            </td>
            <td class="p-2">
              <?php
                $logStatus = $r['status'] ?? '';
                if (empty($logStatus) && is_array($jsonData) && !empty($jsonData['status'])) {
                    $logStatus = $jsonData['status'];
                }
                $statusColors = ['success' => 'bg-emerald-100 text-emerald-700', 'error' => 'bg-red-100 text-red-700', 'warning' => 'bg-amber-100 text-amber-700'];
                $sc = $statusColors[$logStatus] ?? 'bg-slate-100 text-slate-600';
              ?>
              <?php if ($logStatus): ?>
                <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $sc ?>"><?= htmlspecialchars(ucfirst($logStatus)) ?></span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <td class="p-2">
              <?php if (!$rev): ?>
                <form method="post" class="inline" data-authz-module="audit" data-authz-required="admin" data-confirm="Reverse this action? This is sensitive and cannot be reversed again.">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="reverse_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="reason" value="Manual reversal">
                  <button class="px-2 py-1 text-xs rounded bg-red-600 text-white">Reverse</button>
                </form>
              <?php else: ?>
                <span class="text-xs text-gray-500">Already reversed</span>
              <?php endif; ?>
            </td>
          </tr>
  <?php endforeach; if (!$rows): ?>
          <tr><td class="p-3" colspan="7">No logs.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<!-- Audit Detail Modal -->
<div id="auditDetailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 backdrop-blur-sm">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200">
      <h3 class="text-base font-semibold text-slate-900">Audit Log Details</h3>
      <button type="button" onclick="closeAuditDetailModal()" class="text-slate-400 hover:text-slate-600 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div id="auditDetailContent" class="px-5 py-4 max-h-[60vh] overflow-y-auto text-sm text-slate-700">
    </div>
    <div class="flex justify-end px-5 py-3 border-t border-slate-200 bg-slate-50">
      <button type="button" onclick="closeAuditDetailModal()" class="btn btn-secondary text-sm">Close</button>
    </div>
  </div>
</div>
<script>
function openAuditDetailModal(id, btn) {
  var detail = btn.getAttribute('data-detail');
  var sentence = btn.getAttribute('data-sentence') || '';
  var actionName = btn.getAttribute('data-action') || '';
  var container = document.getElementById('auditDetailContent');
  container.innerHTML = '';

  var html = '';

  // Show the human-readable sentence prominently
  if (sentence) {
    html += '<div class="mb-4 p-3 bg-slate-50 rounded-lg border border-slate-200">';
    html += '<p class="text-base text-slate-800 font-medium">' + escapeHtml(sentence) + '</p>';
    html += '</div>';
  }

  // Try to parse as JSON for structured detail view
  var parsed = null;
  try { parsed = JSON.parse(detail); } catch(e) {}

  if (parsed && typeof parsed === 'object') {
    // Module + Status badges
    if (parsed.module || parsed.status) {
      html += '<div class="mb-3 flex items-center gap-2">';
      if (parsed.module) {
        html += '<span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700">' + escapeHtml(parsed.module.charAt(0).toUpperCase() + parsed.module.slice(1)) + '</span>';
      }
      if (parsed.status) {
        var sc = parsed.status === 'success' ? 'bg-emerald-100 text-emerald-700' : parsed.status === 'error' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700';
        html += '<span class="px-2 py-0.5 text-xs font-medium rounded-full ' + sc + '">' + escapeHtml(parsed.status.charAt(0).toUpperCase() + parsed.status.slice(1)) + '</span>';
      }
      html += '</div>';
    }

    // Collect all meta fields
    var meta = {};
    if (parsed.meta && typeof parsed.meta === 'object') {
      Object.keys(parsed.meta).forEach(function(k) { meta[k] = parsed.meta[k]; });
    }
    Object.keys(parsed).forEach(function(k) {
      if (['module', 'status', 'meta'].indexOf(k) === -1) { meta[k] = parsed[k]; }
    });

    var keys = Object.keys(meta);
    if (keys.length > 0) {
      html += '<div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Details</div>';
      html += '<table class="w-full text-sm">';
      keys.forEach(function(k) {
        var v = meta[k];
        if (v === null || v === undefined) v = '—';
        if (typeof v === 'object') v = JSON.stringify(v);
        var label = k.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        html += '<tr class="border-b border-slate-100">';
        html += '<td class="py-1.5 pr-3 text-slate-500 font-medium whitespace-nowrap align-top">' + escapeHtml(label) + '</td>';
        html += '<td class="py-1.5 text-slate-800">' + escapeHtml(String(v)) + '</td>';
        html += '</tr>';
      });
      html += '</table>';
    }
  } else if (detail && !sentence) {
    // Non-JSON, non-sentence: show plain text
    html += '<pre class="whitespace-pre-wrap break-words font-mono bg-slate-50 rounded-lg p-3 text-sm">' + escapeHtml(detail) + '</pre>';
  }

  container.innerHTML = html;

  var modal = document.getElementById('auditDetailModal');
  modal.classList.remove('hidden');
  modal.classList.add('flex');
}

function escapeHtml(str) {
  if (!str) return '';
  var div = document.createElement('div');
  div.appendChild(document.createTextNode(str));
  return div.innerHTML;
}

function closeAuditDetailModal() {
  var modal = document.getElementById('auditDetailModal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}
document.getElementById('auditDetailModal').addEventListener('click', function(e) {
  if (e.target === this) closeAuditDetailModal();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
