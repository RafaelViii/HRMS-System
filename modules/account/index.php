<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('user_management', 'user_accounts', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();
$currentUser = $_SESSION['user'] ?? null;
$currentUserId = (int)($currentUser['id'] ?? 0);
$canManageAccounts = $currentUserId > 0 && user_has_access($currentUserId, 'user_management', 'user_accounts', 'write');

// Load departments, positions, branches for create-profile modal
$modalDeps = [];
$modalPoses = [];
$modalBranches = [];
try {
  $modalDeps = $pdo->query('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $modalPoses = $pdo->query('SELECT id, name FROM positions WHERE deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $modalBranches = branches_fetch_all($pdo);
} catch (Throwable $e) { /* non-fatal */ }

// Handle POST: create employee profile then redirect to create account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'create_profile') {
  if (!csrf_verify($_POST['csrf_token'] ?? '')) { flash_error('Invalid or expired form token.'); header('Location: ' . BASE_URL . '/modules/account/index'); exit; }
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $deptId = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
  $posId  = ($_POST['position_id'] ?? '') !== '' ? (int)$_POST['position_id'] : null;
  $branchId = ($_POST['branch_id'] ?? '') !== '' ? (int)$_POST['branch_id'] : null;

  if ($first === '' || $last === '' || $email === '') {
    flash_error('First name, last name, and email are required.');
    header('Location: ' . BASE_URL . '/modules/account/index');
    exit;
  }

  try {
    // Auto-generate employee code
    $codeStmt = $pdo->query("SELECT 'EMP-' || LPAD((COALESCE(MAX(CAST(SUBSTRING(employee_code FROM 5) AS INTEGER)), 0) + 1)::TEXT, 4, '0') FROM employees WHERE employee_code ~ '^EMP-[0-9]+$'");
    $autoCode = $codeStmt->fetchColumn() ?: 'EMP-0001';
    
    $stmt = $pdo->prepare('INSERT INTO employees (employee_code, first_name, last_name, email, phone, department_id, position_id, branch_id, status) VALUES (:code, :first, :last, :email, :phone, :dept, :pos, :branch, :status) RETURNING id');
    $stmt->execute([
      ':code' => $autoCode,
      ':first' => $first,
      ':last' => $last,
      ':email' => $email,
      ':phone' => $phone,
      ':dept' => $deptId,
      ':pos' => $posId,
      ':branch' => $branchId,
      ':status' => 'active',
    ]);
    $empId = (int)$stmt->fetchColumn();
    action_log('employees', 'create_employee', 'success', ['employee_id' => $empId, 'name' => "$first $last", 'source' => 'account_manager']);
    flash_success('Employee profile created. Now set up their account.');
    header('Location: ' . BASE_URL . '/modules/account/create?employee_id=' . $empId);
    exit;
  } catch (Throwable $e) {
    sys_log('DB-ACCT-PROFILE', 'Create profile failed: ' . $e->getMessage(), ['module' => 'account', 'file' => __FILE__, 'line' => __LINE__]);
    flash_error('Failed to create employee profile. Please try again or contact your administrator.');
    header('Location: ' . BASE_URL . '/modules/account/index');
    exit;
  }
}

// AJAX: action history
if (isset($_GET['action_history'])) {
  header('Content-Type: application/json');
  $me = $currentUser;
  if (!$me || !in_array(($me['role'] ?? ''), ['admin'], true)) {
    http_response_code(403);
    echo json_encode(['error'=>'forbidden']);
    exit;
  }
  $uid = (int)($_GET['uid'] ?? 0);
  $page = max(1, (int)($_GET['page'] ?? 1));
  $per = 20;
  $off = ($page-1)*$per;
  $rows=[]; $total=0;
  try {
    // Include actions where the actor is the user OR where JSON meta.user_id (or legacy pattern) targets the user.
    // Exclude noisy login/logout entries.
    $countSql = "SELECT COUNT(*) FROM audit_logs a
      WHERE a.action NOT IN ('login','logout') AND (
        a.user_id = :uid
        OR (a.details LIKE '{%' AND (
             (a.details::json->'meta'->>'user_id')::int = :uid
             OR (a.details::json->>'user_id')::int = :uid
           ))
        OR a.details ILIKE :legacy
      )";
    $legacyPattern = '%user_id=' . $uid . '%';
    $cst = $pdo->prepare($countSql);
    $cst->bindValue(':uid',$uid, PDO::PARAM_INT);
    $cst->bindValue(':legacy',$legacyPattern, PDO::PARAM_STR);
    $cst->execute();
    $total = (int)($cst->fetchColumn() ?: 0);
    $pages = max(1, (int)ceil($total / $per));

    $dataSql = "SELECT a.created_at, a.action, a.details, a.user_id AS actor_id
      FROM audit_logs a
      WHERE a.action NOT IN ('login','logout') AND (
        a.user_id = :uid
        OR (a.details LIKE '{%' AND (
             (a.details::json->'meta'->>'user_id')::int = :uid
             OR (a.details::json->>'user_id')::int = :uid
           ))
        OR a.details ILIKE :legacy
      )
      ORDER BY a.id DESC LIMIT :lim OFFSET :off";
    $st = $pdo->prepare($dataSql);
    $st->bindValue(':uid',$uid, PDO::PARAM_INT);
    $st->bindValue(':legacy',$legacyPattern, PDO::PARAM_STR);
    $st->bindValue(':lim',$per, PDO::PARAM_INT);
    $st->bindValue(':off',$off, PDO::PARAM_INT);
    $st->execute();
    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($raw as $r) {
      $tsFmt = format_datetime_display($r['created_at'] ?? null, true, '');
      $module=''; $status='';
      $det = $r['details'] ?? '';
      if (is_string($det) && $det !== '' && $det[0]==='{') {
        $jd = json_decode($det, true);
        if (is_array($jd)) {
          $module = $jd['module'] ?? ($jd['meta']['module'] ?? '');
          $status = $jd['status'] ?? ($jd['meta']['status'] ?? '');
        }
      }
  $rows[] = ['time'=>$tsFmt,'action'=>$r['action'],'module'=>$module,'status'=>$status];
    }
  } catch (Throwable $e) {
    sys_log('DB-ACCOUNT-AH', 'Action history fetch failed: '.$e->getMessage(), ['module'=>'account','file'=>__FILE__,'line'=>__LINE__,'context'=>['uid'=>$uid]]);
  }
  echo json_encode(['rows'=>$rows,'page'=>$page,'pages'=>$pages ?? 1,'total'=>$total]);
  exit;
}

$q = trim($_GET['q'] ?? '');
$where = '';
if ($q !== '') { $where = "WHERE u.email ILIKE :q OR u.full_name ILIKE :q"; }

$rows = [];
try {
  $sql = "SELECT u.id, u.email, u.full_name, u.role, u.status, u.last_login, u.branch_id, u.is_system_admin,
   b.name AS branch_name, b.code AS branch_code,
   (SELECT string_agg(module || ':' || level, ', ' ORDER BY module) FROM user_access_permissions p WHERE p.user_id=u.id) AS modules
   FROM users u
   LEFT JOIN branches b ON b.id = u.branch_id ";
  if ($where !== '') { $sql .= $where; }
  $sql .= " ORDER BY u.id DESC LIMIT 100";
  $stmt = $pdo->prepare($sql);
  if ($where !== '') { $like = "%$q%"; $stmt->execute([':q' => $like]); } else { $stmt->execute(); }
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  sys_log('DB4101', 'Account list failed - ' . $e->getMessage(), ['module'=>'account','file'=>__FILE__,'line'=>__LINE__]);
  $rows = [];
}
// Preload login history counts for modal; actual entries fetched via separate query per user on demand (simple page)
function fetch_login_history(PDO $pdo, int $userId): array {
  try {
    $st = $pdo->prepare("SELECT created_at, details FROM audit_logs WHERE user_id = :uid AND action = 'login' ORDER BY id DESC LIMIT 50");
    $st->execute([':uid'=>$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
      $tsIso = $row['created_at'] ?? null;
      $display = format_datetime_display($tsIso, true, $tsIso ?: '');
      $ip = 'N/A';
      $ua = null;
      $det = $row['details'] ?? '';
      if (is_string($det) && strlen($det) && $det[0] === '{') {
        $jd = json_decode($det, true);
        if (is_array($jd)) {
          if (!empty($jd['ip'])) $ip = $jd['ip'];
          if (!empty($jd['ua'])) $ua = $jd['ua'];
        }
      }
      $out[] = [
        't' => $display,
        'ip' => $ip,
        'ua' => $ua,
      ];
    }
    return $out;
  } catch (Throwable $e) { return []; }
}
$pageTitle = 'User Accounts';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container mx-auto p-4">
  <!-- Header -->
  <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
    <div>
      <div class="flex items-center gap-3 mb-1">
        <h1 class="text-2xl font-semibold text-gray-800">User Accounts</h1>
        <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs font-semibold rounded"><?= count($rows) ?> Total</span>
      </div>
      <p class="text-sm text-gray-600">Manage user accounts and access control</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <a href="<?= BASE_URL ?>/modules/admin/management" class="btn btn-light">
        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to Hub
      </a>
      <?php if ($canManageAccounts): ?>
        <a href="<?= BASE_URL ?>/modules/account/permissions" class="btn btn-accent">
          <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          Manage Permissions
        </a>
        <button type="button" onclick="document.getElementById('createProfileModal').classList.remove('hidden'); document.body.style.overflow='hidden';" class="btn btn-primary">
          <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          Create Account
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Search Bar -->
  <form class="mb-4" method="get">
    <div class="flex gap-2">
      <input name="q" value="<?= htmlspecialchars($q) ?>" 
             class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" 
             placeholder="Search by name or email...">
      <button type="submit" class="btn btn-primary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
      </button>
    </div>
  </form>
  <!-- Accounts Table -->
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role & Branch</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Access</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Login History</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Action Log</th>
            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
          </tr>
        </thead>
    <tbody>
      <?php foreach ($rows as $r): 
        $isSuperadmin = is_superadmin((int)$r['id']);
        $isSystemAdmin = (bool)($r['is_system_admin'] ?? false);
      ?>
      <tr class="border-t <?= $isSuperadmin ? 'bg-purple-50 border-purple-200' : '' ?>">
        <td class="p-2">
          <div class="flex flex-col">
            <span class="font-medium"><?= htmlspecialchars($r['full_name']) ?></span>
            <span class="text-xs text-gray-500"><?= htmlspecialchars($r['email']) ?></span>
          </div>
        </td>
        <td class="p-2">
          <div class="flex flex-col gap-1">
            <div class="flex items-center gap-2">
              <span><?= htmlspecialchars($r['role']) ?></span>
              <?php if ($isSuperadmin): ?>
                <span class="px-2 py-0.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white text-xs font-bold rounded shadow-sm flex items-center gap-1">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                  </svg>
                  SUPERADMIN
                </span>
              <?php elseif ($isSystemAdmin): ?>
                <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-xs font-semibold rounded">System Admin</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($r['branch_name'])): ?>
              <span class="text-xs text-gray-500">
                <?= htmlspecialchars($r['branch_name']) ?><?= (isset($r['branch_code']) && $r['branch_code'] !== '') ? ' (' . htmlspecialchars($r['branch_code']) . ')' : '' ?>
              </span>
            <?php endif; ?>
          </div>
        </td>
        <td class="p-2"><?= htmlspecialchars($r['status']) ?></td>
        <td class="p-2 hidden lg:table-cell"><?= htmlspecialchars($r['modules'] ?: '-') ?></td>
        <td class="p-2 text-gray-600 hidden md:table-cell">
          <?php 
            $hist = fetch_login_history($pdo, (int)$r['id']);
          ?>
          <button type="button" class="btn btn-outline btn-sm btn-login-history" data-hist='<?= htmlspecialchars(json_encode($hist, JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'>View Logins</button>
        </td>
        <td class="p-2 hidden md:table-cell">
          <?php 
            $me = $_SESSION['user'] ?? null; 
            $canViewAudit = $me && user_has_access($currentUserId, 'user_management', 'audit_logs', 'read'); 
            if ($canViewAudit): 
              // Get action count for this user in last 30 days
              try {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id = :uid AND created_at >= NOW() - INTERVAL '30 days'");
                $countStmt->execute([':uid' => (int)$r['id']]);
                $actionCount = $countStmt->fetchColumn();
              } catch (Throwable $e) {
                $actionCount = 0;
              }
          ?>
            <button type="button" 
                    class="btn btn-outline btn-sm inline-flex items-center gap-1 btn-view-actions" 
                    data-user-id="<?= (int)$r['id'] ?>"
                    data-user-name="<?= htmlspecialchars($r['full_name']) ?>">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              View Actions
              <?php if ($actionCount > 0): ?>
                <span class="px-1.5 py-0.5 bg-indigo-100 text-indigo-700 text-xs font-semibold rounded"><?= $actionCount ?></span>
              <?php endif; ?>
            </button>
          <?php else: ?>
            <span class="text-gray-400 text-xs">---</span>
          <?php endif; ?>
        </td>
        <td class="p-2">
          <?php if ($canManageAccounts): ?>
            <a class="text-indigo-600 hover:text-indigo-700 font-medium" href="<?= BASE_URL ?>/modules/account/edit?id=<?= (int)$r['id'] ?>">Manage</a>
          <?php else: ?>
            <a class="text-indigo-600 hover:text-indigo-700 font-medium" href="<?= BASE_URL ?>/modules/account/edit?id=<?= (int)$r['id'] ?>">View</a>
          <?php endif; ?>
        </td>
      </tr>
  <?php endforeach; if (!$rows): ?>
  <tr><td class="p-3" colspan="7">No users found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</div>

<!-- Action Log Modal -->
<div id="actionLogModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50">
  <div class="relative top-10 mx-auto p-6 border w-11/12 md:w-4/5 lg:w-3/4 shadow-lg rounded-lg bg-white">
    <div class="flex justify-between items-center mb-6">
      <h3 class="text-xl font-bold text-gray-900">
        Action Log - <span id="actionLogUserName"></span>
      </h3>
      <button onclick="closeActionLogModal()" class="text-gray-400 hover:text-gray-600">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Date Filter -->
    <div class="mb-6 bg-gray-50 border border-gray-200 rounded-lg p-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
          <input type="date" id="actionLogDateFrom" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
          <input type="date" id="actionLogDateTo" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <div class="flex items-end">
          <button onclick="applyActionLogFilter()" class="w-full bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition font-medium">
            Apply Filter
          </button>
        </div>
      </div>
      <div class="mt-3 flex flex-wrap gap-2">
        <button onclick="setActionLogDateRange('today')" class="text-xs px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-full transition">Today</button>
        <button onclick="setActionLogDateRange('week')" class="text-xs px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-full transition">Last 7 Days</button>
        <button onclick="setActionLogDateRange('month')" class="text-xs px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-full transition">Last 30 Days</button>
        <button onclick="clearActionLogFilter()" class="text-xs px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-full transition">Clear</button>
      </div>
    </div>

    <!-- Actions Table -->
    <div id="actionLogContent" class="mb-4">
      <div class="text-center py-8">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
        <p class="mt-2 text-gray-600">Loading actions...</p>
      </div>
    </div>

    <div class="flex justify-end">
      <button onclick="closeActionLogModal()" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition font-medium">
        Close
      </button>
    </div>
  </div>
</div>

<script>
function _initAccountPage() {
let currentActionLogUserId = null;

// Open Action Log Modal
document.querySelectorAll('.btn-view-actions').forEach(btn => {
  btn.addEventListener('click', function() {
    const userId = this.dataset.userId;
    const userName = this.dataset.userName;
    currentActionLogUserId = userId;
    
    document.getElementById('actionLogUserName').textContent = userName;
    document.getElementById('actionLogModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    
    // Set default date range (last 30 days)
    setActionLogDateRange('month');
    loadActionLog();
  });
});

function closeActionLogModal() {
  document.getElementById('actionLogModal').classList.add('hidden');
  document.body.style.overflow = 'auto';
  currentActionLogUserId = null;
}

function setActionLogDateRange(range) {
  const today = new Date();
  const dateFrom = document.getElementById('actionLogDateFrom');
  const dateTo = document.getElementById('actionLogDateTo');
  
  dateTo.value = today.toISOString().split('T')[0];
  
  let fromDate = new Date();
  switch(range) {
    case 'today':
      fromDate = today;
      break;
    case 'week':
      fromDate.setDate(today.getDate() - 7);
      break;
    case 'month':
      fromDate.setDate(today.getDate() - 30);
      break;
  }
  
  dateFrom.value = fromDate.toISOString().split('T')[0];
}

function clearActionLogFilter() {
  document.getElementById('actionLogDateFrom').value = '';
  document.getElementById('actionLogDateTo').value = '';
}

function applyActionLogFilter() {
  loadActionLog();
}

function loadActionLog() {
  if (!currentActionLogUserId) return;
  
  const dateFrom = document.getElementById('actionLogDateFrom').value;
  const dateTo = document.getElementById('actionLogDateTo').value;
  const content = document.getElementById('actionLogContent');
  
  content.innerHTML = `
    <div class="text-center py-8">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
      <p class="mt-2 text-gray-600">Loading actions...</p>
    </div>
  `;
  
  let url = `<?= BASE_URL ?>/modules/account/action_log_data?user_id=${currentActionLogUserId}`;
  if (dateFrom) url += `&date_from=${dateFrom}`;
  if (dateTo) url += `&date_to=${dateTo}`;
  
  fetch(url)
    .then(response => {
      console.log('Response status:', response.status);
      if (!response.ok) {
        return response.text().then(text => {
          console.error('Error response:', text);
          throw new Error(`HTTP ${response.status}: ${text.substring(0, 100)}`);
        });
      }
      return response.json();
    })
    .then(data => {
      console.log('Action log data:', data);
      if (data.success) {
        renderActionLog(data.actions, data.total);
      } else {
        content.innerHTML = `
          <div class="text-center py-8 text-red-600">
            <svg class="w-12 h-12 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p>${data.error || 'Failed to load actions'}</p>
          </div>
        `;
      }
    })
    .catch(error => {
      console.error('Error loading action log:', error);
      content.innerHTML = `
        <div class="text-center py-8 text-red-600">
          <svg class="w-12 h-12 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <p>Error loading actions: ${error.message}</p>
        </div>
      `;
    });
}

function renderActionLog(actions, total) {
  const content = document.getElementById('actionLogContent');
  
  if (!actions || actions.length === 0) {
    content.innerHTML = `
      <div class="text-center py-8 text-gray-500">
        <svg class="w-12 h-12 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p>No actions found for the selected date range.</p>
      </div>
    `;
    return;
  }
  
  let html = `
    <div class="mb-4 text-sm text-gray-600">
      Showing <span class="font-semibold">${actions.length}</span> of <span class="font-semibold">${total}</span> actions
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200 border border-gray-200 rounded-lg">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Timestamp</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Module</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Details</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
  `;
  
  actions.forEach(action => {
    const statusColors = {
      'success': 'bg-green-100 text-green-800',
      'failed': 'bg-red-100 text-red-800',
      'partial': 'bg-yellow-100 text-yellow-800'
    };
    const statusClass = statusColors[action.status] || 'bg-gray-100 text-gray-800';
    
    html += `
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
          <div>${action.date}</div>
          <div class="text-xs text-gray-500">${action.time}</div>
        </td>
        <td class="px-4 py-3 text-sm font-medium text-gray-900">${escapeHtml(action.action)}</td>
        <td class="px-4 py-3 text-sm">
          ${action.module ? `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">${escapeHtml(action.module)}</span>` : '<span class="text-gray-400">—</span>'}
        </td>
        <td class="px-4 py-3 text-sm">
          ${action.action_type ? `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">${escapeHtml(action.action_type)}</span>` : '<span class="text-gray-400">—</span>'}
        </td>
        <td class="px-4 py-3 text-sm">
          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${statusClass}">
            ${escapeHtml(action.status)}
          </span>
        </td>
        <td class="px-4 py-3 text-sm text-gray-600">
          <div class="max-w-xs truncate" title="${escapeHtml(action.details || '')}">
            ${escapeHtml(action.details || '—')}
          </div>
        </td>
      </tr>
    `;
  });
  
  html += `
        </tbody>
      </table>
    </div>
  `;
  
  content.innerHTML = html;
}

function escapeHtml(text) {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Close modal on ESC key
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape' && !document.getElementById('actionLogModal').classList.contains('hidden')) {
    closeActionLogModal();
  }
});

// Close modal when clicking outside
document.getElementById('actionLogModal')?.addEventListener('click', function(event) {
  if (event.target === this) {
    closeActionLogModal();
  }
});

// Expose functions globally for onclick handlers
window.closeActionLogModal = closeActionLogModal;
window.setActionLogDateRange = setActionLogDateRange;
window.clearActionLogFilter = clearActionLogFilter;
window.applyActionLogFilter = applyActionLogFilter;
window.loadActionLog = loadActionLog;
}

// Initialize on first load and on SPA navigation
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _initAccountPage);
} else {
  _initAccountPage();
}
document.addEventListener('spa:loaded', _initAccountPage);
</script>

<!-- Create Profile First Modal -->
<?php if ($canManageAccounts): ?>
<div id="createProfileModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-modal="true" role="dialog">
  <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity" onclick="closeCreateProfileModal()"></div>
  <div class="flex min-h-full items-center justify-center p-4">
    <div class="relative w-full max-w-lg transform overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 transition-all">
      <!-- Modal Header -->
      <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/80 px-6 py-4">
        <div>
          <h3 class="text-lg font-semibold text-slate-900">Create New Account</h3>
          <p class="mt-0.5 text-sm text-slate-500">Set up an employee profile first, then create their account</p>
        </div>
        <button type="button" onclick="closeCreateProfileModal()" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <!-- Choice: Create Profile or Skip -->
      <div id="profileChoiceView" class="p-6 space-y-4">
        <div class="grid grid-cols-1 gap-3">
          <!-- Option 1: Create Profile -->
          <button type="button" onclick="showProfileForm()" class="group relative flex items-start gap-4 rounded-xl border-2 border-slate-200 bg-white p-4 text-left transition-all hover:border-indigo-300 hover:bg-indigo-50/50 hover:shadow-sm">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 transition group-hover:bg-indigo-200">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
            <div class="min-w-0">
              <div class="text-sm font-semibold text-slate-900">Create Employee Profile First</div>
              <p class="mt-0.5 text-xs text-slate-500 leading-relaxed">Set up the employee's basic information (name, department, position) then proceed to create their system account.</p>
              <span class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600">
                Recommended
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
              </span>
            </div>
          </button>

          <!-- Option 2: Skip to Account Creation -->
          <a href="<?= BASE_URL ?>/modules/account/create" class="group relative flex items-start gap-4 rounded-xl border-2 border-slate-200 bg-white p-4 text-left transition-all hover:border-slate-300 hover:bg-slate-50/80 hover:shadow-sm">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition group-hover:bg-slate-200">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <div class="min-w-0">
              <div class="text-sm font-semibold text-slate-900">Create Account Only</div>
              <p class="mt-0.5 text-xs text-slate-500 leading-relaxed">Skip the profile setup and create a standalone user account. You can link it to an employee record later.</p>
            </div>
          </a>
        </div>
      </div>

      <!-- Profile Form (hidden by default) -->
      <div id="profileFormView" class="hidden">
        <form method="post" action="<?= BASE_URL ?>/modules/account/index" class="divide-y divide-slate-100">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="create_profile">

          <div class="p-6 space-y-4">
            <!-- Back link -->
            <button type="button" onclick="showChoiceView()" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700 transition-colors -mt-1 mb-1">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
              Back to options
            </button>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1 required">First Name</label>
                <input type="text" name="first_name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Juan">
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1 required">Last Name</label>
                <input type="text" name="last_name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Dela Cruz">
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1 required">Email</label>
                <input type="email" name="email" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="juan@company.com">
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
                <input type="tel" name="phone" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="09XX XXX XXXX">
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Department</label>
                <select name="department_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                  <option value="">— Select —</option>
                  <?php foreach ($modalDeps as $d): ?>
                    <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Position</label>
                <select name="position_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                  <option value="">— Select —</option>
                  <?php foreach ($modalPoses as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <?php if ($modalBranches): ?>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Branch</label>
              <select name="branch_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <option value="">— Select —</option>
                <?php foreach ($modalBranches as $branch): ?>
                  <option value="<?= (int)$branch['id'] ?>"><?= htmlspecialchars($branch['name']) ?> (<?= htmlspecialchars($branch['code']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <div class="rounded-lg bg-indigo-50 border border-indigo-100 p-3">
              <div class="flex items-start gap-2">
                <svg class="w-4 h-4 text-indigo-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                <p class="text-xs text-indigo-700 leading-relaxed">An employee code will be auto-generated. After creating the profile, you'll set up the login credentials on the next page.</p>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex items-center justify-end gap-3 bg-slate-50/80 px-6 py-4">
            <button type="button" onclick="closeCreateProfileModal()" class="btn btn-outline text-sm">Cancel</button>
            <button type="submit" class="btn btn-primary text-sm">
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              Create Profile & Continue
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function closeCreateProfileModal() {
  document.getElementById('createProfileModal').classList.add('hidden');
  document.body.style.overflow = 'auto';
  // Reset to choice view
  showChoiceView();
}

function showProfileForm() {
  document.getElementById('profileChoiceView').classList.add('hidden');
  document.getElementById('profileFormView').classList.remove('hidden');
}

function showChoiceView() {
  document.getElementById('profileChoiceView').classList.remove('hidden');
  document.getElementById('profileFormView').classList.add('hidden');
}

// Close create profile modal on ESC
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape' && !document.getElementById('createProfileModal').classList.contains('hidden')) {
    closeCreateProfileModal();
  }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
