<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('hr_core', 'employees', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/encryption.php';
$pdo = get_db_conn();
$currentUser = current_user();

$id = (int)($_GET['id'] ?? 0);
// Handle Unbind Account — must run before header.php (PRG pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unbind_user']) && csrf_verify($_POST['csrf'] ?? '')) {
  $eid = (int)($_POST['employee_id'] ?? 0);
  if ($eid !== $id) { flash_error('Changes could not be saved'); header('Location: ' . BASE_URL . '/modules/employees/view?id=' . $id); exit; }
  $er = null;
  try {
    $est = $pdo->prepare('SELECT user_id, employee_code FROM employees WHERE id = :id AND deleted_at IS NULL');
    $est->execute([':id' => $id]);
    $er = $est->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    sys_log('DB3200', 'Prepare/execute failed: employee fetch for unbind - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]);
  }
  if (empty($er['user_id'])) { flash_error('Changes could not be saved'); header('Location: ' . BASE_URL . '/modules/employees/view?id=' . $id); exit; }
  $uid = (int)$er['user_id'];
  // Delete user; FK will set employees.user_id to NULL
  // Ensure backup table exists and backup user before delete
  try { $pdo->exec('CREATE TABLE IF NOT EXISTS users_backup (LIKE users INCLUDING ALL)'); } catch (Throwable $e) {}
  try {
    $bk = $pdo->prepare('INSERT INTO users_backup SELECT * FROM users WHERE id = :uid');
    $bk->execute([':uid' => $uid]);
  } catch (Throwable $e) {}
  try {
    $dst = $pdo->prepare('DELETE FROM users WHERE id = :uid');
    $ok = $dst->execute([':uid' => $uid]);
  } catch (Throwable $e) { $ok = false; sys_log('DB3201', 'Unbind account failed - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__,'context'=>['id'=>$id,'user'=>$uid]]); }
  if ($ok) { audit('unbind_account', 'emp=' . $er['employee_code'] . ', user_id=' . $uid); action_log('employees', 'unbind_account', 'success', ['employee_id' => $id, 'user_id' => $uid]); flash_success('Changes have been saved'); }
  else { flash_error('Changes could not be saved'); }
  header('Location: ' . BASE_URL . '/modules/employees/view?id=' . $id); exit;
}

require_once __DIR__ . '/../../includes/header.php';
$emp = null;
try {
  $stmt = $pdo->prepare('SELECT e.*, d.name AS dept, p.name AS pos, p.base_salary AS position_base_salary, u.status AS account_status FROM employees e 
LEFT JOIN departments d ON d.id = e.department_id AND d.deleted_at IS NULL
LEFT JOIN positions p ON p.id = e.position_id AND p.deleted_at IS NULL
LEFT JOIN users u ON u.id = e.user_id 
WHERE e.id = :id AND e.deleted_at IS NULL');
  $stmt->execute([':id' => $id]);
  $emp = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { sys_log('DB2911', 'Prepare/execute failed: employee view - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__,'context'=>['id'=>$id]]); }
if (!$emp) { echo '<div class="p-3">Not found</div>'; require_once __DIR__ . '/../../includes/footer.php'; exit; }
?>

<!-- Breadcrumb & Header -->
<div class="mb-6">
  <div class="flex items-center gap-2 text-sm text-slate-500 mb-4">
    <a href="<?= BASE_URL ?>/modules/employees/index" class="hover:text-indigo-600 transition">Employees</a>
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <span class="text-slate-900 font-medium">Employee Profile</span>
  </div>
</div>

<?php $hasAccount = !empty($emp['user_id']); ?>

<!-- Profile Header Card -->
<div class="card mb-6">
  <div class="relative">
    <!-- Cover Background -->
    <div class="h-32 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-t-xl"></div>
    
    <!-- Profile Content -->
    <div class="px-6 pb-6">
      <div class="flex flex-col md:flex-row md:items-end md:justify-between -mt-16">
        <!-- Avatar & Name -->
        <div class="flex items-end gap-4">
          <div class="w-32 h-32 rounded-2xl bg-white shadow-xl flex items-center justify-center border-4 border-white">
            <div class="w-full h-full rounded-xl bg-gradient-to-br from-indigo-100 to-purple-100 flex items-center justify-center">
              <span class="text-4xl font-bold text-indigo-600">
                <?= strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1)) ?>
              </span>
            </div>
          </div>
          <div class="pb-2">
            <h1 class="text-3xl font-bold text-slate-900"><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></h1>
            <p class="text-slate-600 mt-1"><?= htmlspecialchars($emp['pos'] ?? 'No Position') ?> • <?= htmlspecialchars($emp['dept'] ?? 'No Department') ?></p>
            <div class="flex items-center gap-2 mt-2">
              <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $emp['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-700' ?>">
                <span class="w-1.5 h-1.5 rounded-full <?= $emp['status'] === 'active' ? 'bg-green-500' : 'bg-slate-500' ?> mr-1.5"></span>
                <?= htmlspecialchars(ucfirst($emp['status'])) ?>
              </span>
              <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                <?= htmlspecialchars($emp['employee_code']) ?>
              </span>
              <?php if ($hasAccount): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">
                  <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                  Portal Account Linked
                </span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="flex flex-wrap gap-3 mt-4 md:mt-0">
          <a href="<?= BASE_URL ?>/modules/employees/edit?id=<?= $emp['id'] ?>" class="btn btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            Edit Profile
          </a>
          <a href="<?= BASE_URL ?>/modules/employees/pdf_profile?id=<?= $emp['id'] ?>" target="_blank" rel="noopener" class="btn btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Export PDF
          </a>
          <a href="<?= BASE_URL ?>/modules/employees/index" class="btn btn-light">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to List
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="grid lg:grid-cols-3 gap-6">
  <!-- Main Content -->
  <div class="lg:col-span-2 space-y-6">
    <!-- Personal Information -->
    <div class="card">
      <div class="border-b border-slate-200 px-6 py-4">
        <h2 class="text-lg font-semibold text-slate-900 flex items-center">
          <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          Personal Information
        </h2>
      </div>
      <div class="p-6">
        <div class="grid md:grid-cols-2 gap-6">
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Full Name</label>
            <p class="text-slate-900 font-medium"><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></p>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Employee Code</label>
            <p class="text-slate-900 font-medium"><?= htmlspecialchars($emp['employee_code']) ?></p>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Email Address</label>
            <p class="text-slate-900 font-medium flex items-center">
              <svg class="w-4 h-4 mr-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
              </svg>
              <?= htmlspecialchars($emp['email']) ?>
            </p>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Phone Number</label>
            <p class="text-slate-900 font-medium flex items-center">
              <svg class="w-4 h-4 mr-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
              </svg>
              <?= htmlspecialchars($emp['phone']) ?>
            </p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Address</label>
            <p class="text-slate-900 font-medium flex items-start">
              <svg class="w-4 h-4 mr-2 text-slate-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
              <?= htmlspecialchars($emp['address']) ?>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Employment Details -->
    <div class="card">
      <div class="border-b border-slate-200 px-6 py-4">
        <h2 class="text-lg font-semibold text-slate-900 flex items-center">
          <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
          </svg>
          Employment Details
        </h2>
      </div>
      <div class="p-6">
        <div class="grid md:grid-cols-2 gap-6">
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Department</label>
            <p class="text-slate-900 font-medium"><?= htmlspecialchars($emp['dept'] ?? '—') ?></p>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Position</label>
            <p class="text-slate-900 font-medium"><?= htmlspecialchars($emp['pos'] ?? '—') ?></p>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Employment Type</label>
            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-sm font-medium bg-indigo-50 text-indigo-700 border border-indigo-200">
              <?= htmlspecialchars($emp['employment_type']) ?>
            </span>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Status</label>
            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-sm font-medium <?= $emp['status'] === 'active' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-slate-50 text-slate-700 border border-slate-200' ?>">
              <span class="w-2 h-2 rounded-full <?= $emp['status'] === 'active' ? 'bg-green-500' : 'bg-slate-500' ?> mr-2"></span>
              <?= htmlspecialchars(ucfirst($emp['status'])) ?>
            </span>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Hire Date</label>
            <p class="text-slate-900 font-medium flex items-center">
              <svg class="w-4 h-4 mr-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
              </svg>
              <?= htmlspecialchars(date('F j, Y', strtotime($emp['hire_date']))) ?>
            </p>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Monthly Salary</label>
            <?php
              $empSalary = (float)($emp['salary'] ?? 0);
              $posSalary = (float)($emp['position_base_salary'] ?? 0);
              $effectiveSalary = $empSalary > 0 ? $empSalary : $posSalary;
              $isFromPosition = $empSalary <= 0 && $posSalary > 0;
            ?>
            <p class="text-slate-900 font-medium text-lg flex items-center">
              <svg class="w-4 h-4 mr-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              ₱<?= number_format($effectiveSalary, 2) ?>
            </p>
            <?php if ($isFromPosition): ?>
              <p class="text-xs text-amber-600 mt-1 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Inherited from position base salary
              </p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Government IDs & Banking -->
    <?php
      $empDecrypted = decrypt_employee($emp);
      $hasGovIds = !empty($empDecrypted['sss_number']) || !empty($empDecrypted['philhealth_number']) || !empty($empDecrypted['pagibig_number']) || !empty($empDecrypted['tin']);
      $hasBanking = !empty($empDecrypted['bank_account_number']) || !empty($empDecrypted['bank_name']);
      $canViewSensitive = user_has_access($currentUser['id'] ?? 0, 'hr_core', 'employees', 'write');
    ?>
    <?php if ($hasGovIds || $hasBanking): ?>
    <div class="card">
      <div class="border-b border-slate-200 px-6 py-4">
        <h2 class="text-lg font-semibold text-slate-900 flex items-center">
          <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          Government IDs &amp; Banking
        </h2>
      </div>
      <div class="px-6 py-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
          <?php if (!empty($empDecrypted['sss_number'])): ?>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">SSS Number</label>
            <p class="text-slate-900 font-medium"><?= $canViewSensitive ? htmlspecialchars($empDecrypted['sss_number']) : htmlspecialchars(mask_field($empDecrypted['sss_number'], 4)) ?></p>
          </div>
          <?php endif; ?>
          <?php if (!empty($empDecrypted['philhealth_number'])): ?>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">PhilHealth Number</label>
            <p class="text-slate-900 font-medium"><?= $canViewSensitive ? htmlspecialchars($empDecrypted['philhealth_number']) : htmlspecialchars(mask_field($empDecrypted['philhealth_number'], 4)) ?></p>
          </div>
          <?php endif; ?>
          <?php if (!empty($empDecrypted['pagibig_number'])): ?>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Pag-IBIG Number</label>
            <p class="text-slate-900 font-medium"><?= $canViewSensitive ? htmlspecialchars($empDecrypted['pagibig_number']) : htmlspecialchars(mask_field($empDecrypted['pagibig_number'], 4)) ?></p>
          </div>
          <?php endif; ?>
          <?php if (!empty($empDecrypted['tin'])): ?>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">TIN</label>
            <p class="text-slate-900 font-medium"><?= $canViewSensitive ? htmlspecialchars($empDecrypted['tin']) : htmlspecialchars(mask_field($empDecrypted['tin'], 4)) ?></p>
          </div>
          <?php endif; ?>
          <?php if (!empty($empDecrypted['bank_account_number'])): ?>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Bank Account Number</label>
            <p class="text-slate-900 font-medium"><?= $canViewSensitive ? htmlspecialchars($empDecrypted['bank_account_number']) : htmlspecialchars(mask_field($empDecrypted['bank_account_number'], 4)) ?></p>
          </div>
          <?php endif; ?>
          <?php if (!empty($empDecrypted['bank_name'])): ?>
          <div>
            <label class="block text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Bank Name</label>
            <p class="text-slate-900 font-medium"><?= htmlspecialchars($empDecrypted['bank_name']) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Portal Account -->
    <?php if ($hasAccount): ?>
    <div class="card">
      <div class="border-b border-slate-200 px-6 py-4">
        <h2 class="text-lg font-semibold text-slate-900 flex items-center">
          <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          Portal Account
        </h2>
      </div>
      <div class="p-6">
        <div class="flex items-center justify-between p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
          <div class="flex items-center">
            <div class="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center">
              <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <div class="ml-4">
              <p class="text-sm font-medium text-emerald-900">Portal Account Linked</p>
              <p class="text-xs text-emerald-700 mt-0.5">Employee can access the HRMS portal</p>
            </div>
          </div>
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 border border-emerald-300">
            Status: <?= htmlspecialchars(ucfirst($emp['account_status'] ?? 'active')) ?>
          </span>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sidebar -->
  <div class="space-y-6">
    <!-- Documents -->
    <div class="card">
      <div class="border-b border-slate-200 px-6 py-4">
        <h2 class="text-lg font-semibold text-slate-900 flex items-center">
          <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          Documents
        </h2>
      </div>
      <div class="p-6">
        <!-- Upload Form -->
        <form method="post" action="<?= BASE_URL ?>/modules/employees/upload" enctype="multipart/form-data" class="space-y-4 mb-6">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
          
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Document Title</label>
            <input type="text" name="title" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Enter document title" required>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Document Type</label>
            <select name="doc_type" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
              <option value="memo">Memo</option>
              <option value="contract">Contract</option>
              <option value="policy">Policy</option>
              <option value="other">Other</option>
            </select>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">File</label>
            <input type="file" name="file" class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" required>
          </div>
          
          <button type="submit" class="w-full btn btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
            </svg>
            Upload Document
          </button>
        </form>

        <div class="border-t border-slate-200 pt-4">
          <h3 class="text-sm font-medium text-slate-700 mb-3">Uploaded Documents</h3>
          <?php
          try {
            $dstmt = $pdo->prepare('SELECT da.id, d.title, d.doc_type, d.file_path, d.created_at FROM document_assignments da JOIN documents d ON d.id = da.document_id WHERE da.employee_id = :id ORDER BY da.id DESC');
            $dstmt->execute([':id' => $id]);
            $docs = $dstmt->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) { 
            sys_log('DB2912', 'Prepare/execute failed: employee docs list - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]); 
            $docs = []; 
          }
          ?>
          
          <?php if (!empty($docs)): ?>
            <div class="space-y-2">
              <?php foreach ($docs as $doc): ?>
                <?php $publicUrl = BASE_URL . '/assets/uploads/' . rawurlencode(basename($doc['file_path'])); ?>
                <a href="<?= $publicUrl ?>" target="_blank" class="block p-3 bg-slate-50 hover:bg-slate-100 rounded-lg border border-slate-200 transition group">
                  <div class="flex items-start justify-between">
                    <div class="flex items-start flex-1 min-w-0">
                      <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                      </div>
                      <div class="ml-3 flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-900 truncate group-hover:text-indigo-600 transition">
                          <?= htmlspecialchars($doc['title']) ?>
                        </p>
                        <div class="flex items-center gap-2 mt-1">
                          <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-600">
                            <?= htmlspecialchars(ucfirst($doc['doc_type'])) ?>
                          </span>
                          <span class="text-xs text-slate-500">
                            <?= date('M j, Y', strtotime($doc['created_at'])) ?>
                          </span>
                        </div>
                      </div>
                    </div>
                    <svg class="w-5 h-5 text-slate-400 group-hover:text-indigo-600 flex-shrink-0 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-center py-8">
              <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              <p class="text-sm text-slate-500">No documents uploaded yet</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
