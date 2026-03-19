<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();
$u = current_user();
$level = user_access_level((int)$u['id'], 'recruitment');
if (access_level_rank($level) < access_level_rank('write')) { header('Location: ' . BASE_URL . '/unauthorized'); exit; }

// Fetch templates
try { $tpls = $pdo->query('SELECT id, name FROM recruitment_templates ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $tpls = []; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid CSRF token'; }
  else {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $position = trim($_POST['position_applied'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $tplId = (int)($_POST['template_id'] ?? 0);

    // Validate required fields based on template
    $missing = [];
    $formVars = ['full_name'=>$full_name, 'email'=>$email, 'phone'=>$phone, 'position_applied'=>$position, 'notes'=>$notes];
    if ($tplId) {
      try { $st = $pdo->prepare('SELECT field_name, is_required FROM recruitment_template_fields WHERE template_id = :tid'); $st->execute([':tid'=>$tplId]); $reqs = $st->fetchAll(PDO::FETCH_ASSOC); foreach ($reqs as $r) { if ($r['is_required']) { $fn = $r['field_name']; $val = $formVars[$fn] ?? ''; if ($val === '') $missing[] = $fn; } } } catch (Throwable $e) {}
    }
    if ($full_name === '') $missing[] = 'full_name';

    if (!$missing) {
      try {
        $stmt = $pdo->prepare("INSERT INTO recruitment (full_name, email, phone, position_applied, template_id, resume_path, status, notes) VALUES (:full_name, :email, :phone, :position, :tplId, NULL, 'new', :notes) RETURNING id");
        $stmt->execute([
          ':full_name'=>$full_name,
          ':email'=>$email,
          ':phone'=>$phone,
          ':position'=>$position,
          ':tplId'=>$tplId ?: null,
          ':notes'=>$notes !== '' ? $notes : null,
        ]);
        $rid = (int)$stmt->fetchColumn();
        audit('recruitment_create', 'id=' . $rid);
        action_log('recruitment', 'create_applicant', 'success', ['id' => $rid, 'name' => $full_name]);
        flash_success('Applicant created');
        header('Location: ' . BASE_URL . '/modules/recruitment/view?id=' . $rid);
        exit;
      } catch (Throwable $e) { $error = 'Failed to save'; }
    } else {
      $error = 'Missing required fields: ' . implode(', ', array_map(fn($f) => str_replace('_', ' ', $f), $missing));
    }
  }
}

$pageTitle = 'Add Applicant';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">

  <!-- Page Header -->
  <div class="flex items-center gap-3">
    <a href="<?= BASE_URL ?>/modules/recruitment/index" class="inline-flex items-center justify-center h-9 w-9 rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-700 transition shadow-sm">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div>
      <h1 class="text-xl font-bold text-slate-900">Add Applicant</h1>
      <p class="text-sm text-slate-500">Create a new recruitment record</p>
    </div>
  </div>

  <!-- Error Alert -->
  <?php if ($error): ?>
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 flex items-start gap-3">
      <svg class="h-5 w-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <p class="text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></p>
    </div>
  <?php endif; ?>

  <!-- Form Card -->
  <form method="post" enctype="multipart/form-data" class="card">
    <div class="card-header">
      <span class="text-sm font-semibold text-slate-700">Applicant Information</span>
    </div>
    <div class="card-body space-y-5">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <div class="grid gap-4 md:grid-cols-2">
        <!-- Full Name -->
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1.5 required">Full Name</label>
          <input name="full_name" type="text" required
            class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"
            placeholder="e.g., Juan Dela Cruz"
            value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
        </div>

        <!-- Email -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">Email Address</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
              <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/></svg>
            </div>
            <input name="email" type="email"
              class="w-full rounded-lg border border-slate-200 bg-slate-50 pl-10 pr-3 py-2.5 text-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"
              placeholder="applicant@email.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
        </div>

        <!-- Phone -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">Phone Number</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
              <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            </div>
            <input name="phone" type="tel"
              class="w-full rounded-lg border border-slate-200 bg-slate-50 pl-10 pr-3 py-2.5 text-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"
              placeholder="09XX XXX XXXX"
              value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>

        <!-- Position Applied -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">Position Applied</label>
          <input name="position_applied" type="text"
            class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"
            placeholder="e.g., Software Developer"
            value="<?= htmlspecialchars($_POST['position_applied'] ?? '') ?>">
        </div>

        <!-- Template -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">Recruitment Template</label>
          <select name="template_id" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition">
            <option value="">— None (standard fields) —</option>
            <?php foreach ($tpls as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= ((int)($_POST['template_id'] ?? 0) === (int)$t['id']) ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="mt-1 text-xs text-slate-400">Templates define which fields & documents are required.</p>
        </div>

        <!-- Notes -->
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">Notes</label>
          <textarea name="notes" rows="3"
            class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"
            placeholder="Source, referral info, interview notes, etc."
          ><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Form Actions -->
    <div class="flex items-center justify-between border-t border-slate-100 px-5 py-4 bg-slate-50/50 rounded-b-xl">
      <a href="<?= BASE_URL ?>/modules/recruitment/index" class="text-sm font-medium text-slate-500 hover:text-slate-700 transition">Cancel</a>
      <button type="submit" class="btn btn-primary">
        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        Create Applicant
      </button>
    </div>
  </form>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
