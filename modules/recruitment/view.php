<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('hr_core', 'recruitment', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();
$u = current_user();
$level = user_access_level((int)$u['id'], 'recruitment');
$canWrite = access_level_rank($level) >= access_level_rank('write');
$canAdmin = access_level_rank($level) >= access_level_rank('manage');
$id = (int)($_GET['id'] ?? 0);
try {
  $stmt = $pdo->prepare('SELECT * FROM recruitment WHERE id = :id');
  $stmt->execute([':id' => $id]);
  $rec = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { sys_log('RECRUIT1001', 'Fetch recruitment failed - ' . $e->getMessage(), ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__]); $rec = null; }
if (!$rec) { require_once __DIR__ . '/../../includes/header.php'; echo '<div class="p-3">Not found.</div>'; require_once __DIR__ . '/../../includes/footer.php'; exit; }

$statusOptions = [
  'new' => 'Pending',
  'shortlist' => 'For Final Interview',
  'interviewed' => 'Interviewed',
  'hired' => 'Hired',
  'rejected' => 'Rejected',
];

$statusColors = [
  'new'         => ['bg' => 'bg-blue-100',    'text' => 'text-blue-700',    'dot' => 'bg-blue-500',    'ring' => 'ring-blue-200'],
  'shortlist'   => ['bg' => 'bg-amber-100',   'text' => 'text-amber-700',   'dot' => 'bg-amber-500',   'ring' => 'ring-amber-200'],
  'interviewed' => ['bg' => 'bg-indigo-100',  'text' => 'text-indigo-700',  'dot' => 'bg-indigo-500',  'ring' => 'ring-indigo-200'],
  'hired'       => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'dot' => 'bg-emerald-500', 'ring' => 'ring-emerald-200'],
  'rejected'    => ['bg' => 'bg-red-100',     'text' => 'text-red-700',     'dot' => 'bg-red-500',     'ring' => 'ring-red-200'],
];

$templates = [];
try {
  $tplStmt = $pdo->query('SELECT id, name FROM recruitment_templates ORDER BY name');
  $templates = $tplStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $templates = [];
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid CSRF'; }
  else {
    if (isset($_POST['save_profile'])) {
      if (!$canWrite) { header('Location: ' . BASE_URL . '/unauthorized'); exit; }
      $fullName = trim($_POST['full_name'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $phone = trim($_POST['phone'] ?? '');
      $position = trim($_POST['position_applied'] ?? '');
      $status = $_POST['status'] ?? ($rec['status'] ?? 'new');
      $currentStatus = $rec['status'] ?? 'new';
      $notes = trim($_POST['notes'] ?? '');
      $tplId = (int)($_POST['template_id'] ?? 0);

      if ($fullName === '') { $error = 'Full name is required.'; }
      elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Please provide a valid email address.'; }
      elseif (!array_key_exists($status, $statusOptions)) { $error = 'Invalid status selected.'; }
      elseif ($status === 'hired' && (int)($rec['converted_employee_id'] ?? 0) === 0) { $error = 'Use the Transition to Employee action to mark this applicant as hired.'; }
      elseif ((int)($rec['converted_employee_id'] ?? 0) > 0 && $status !== $currentStatus) { $error = 'Status cannot be changed after conversion.'; }

      if (!$error && $tplId > 0) {
        $tplValid = false;
        foreach ($templates as $tpl) {
          if ((int)$tpl['id'] === $tplId) { $tplValid = true; break; }
        }
        if (!$tplValid) { $error = 'Selected template was not found.'; }
      }

      $rec = array_merge($rec, [
        'full_name' => $fullName,
        'email' => $email !== '' ? $email : null,
        'phone' => $phone !== '' ? $phone : null,
        'position_applied' => $position !== '' ? $position : null,
        'status' => array_key_exists($status, $statusOptions) ? $status : ($rec['status'] ?? 'new'),
        'notes' => $notes !== '' ? $notes : null,
        'template_id' => $tplId ?: null,
      ]);

      if (!$error) {
        try {
          $upd = $pdo->prepare('UPDATE recruitment SET full_name = :full_name, email = :email, phone = :phone, position_applied = :position, status = :status, notes = :notes, template_id = :template_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
          $upd->execute([
            ':full_name' => $fullName,
            ':email' => $email !== '' ? $email : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':position' => $position !== '' ? $position : null,
            ':status' => $status,
            ':notes' => $notes !== '' ? $notes : null,
            ':template_id' => $tplId ?: null,
            ':id' => $rec['id'],
          ]);
          audit('recruitment_update', json_encode(['id' => $rec['id']]));
          action_log('recruitment', 'update_applicant', 'success', ['id' => $rec['id']]);
          flash_success('Applicant updated.');
          header('Location: ' . BASE_URL . '/modules/recruitment/view?id=' . $id);
          exit;
        } catch (Throwable $e) {
          sys_log('RECRUIT1601', 'Update applicant failed - ' . $e->getMessage(), ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__]);
          $error = 'Could not save changes.';
        }
      }
    }
    elseif (isset($_POST['upload_file'])) {
      if (!$canWrite) { header('Location: ' . BASE_URL . '/unauthorized'); exit; }
      $label = trim($_POST['label'] ?? '');
      if ($label === '') { $error = 'Label is required'; }
      else if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { $error = 'File required'; }
      else {
        $allowedExts = ['pdf','doc','docx','jpg','jpeg','png','gif','xls','xlsx','csv','txt'];
        $maxFileSize = 10 * 1024 * 1024;
        $uploadExt = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($uploadExt, $allowedExts, true)) {
          $error = 'File type not allowed. Accepted: ' . implode(', ', $allowedExts);
        } else {
            // M-05 fix matching: Actual content type validation
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);
            finfo_close($finfo);
          
            $validMime = false;
            if ($uploadExt === 'pdf' && $mimeType === 'application/pdf') { $validMime = true; }
            elseif (in_array($uploadExt, ['jpg', 'jpeg'], true) && $mimeType === 'image/jpeg') { $validMime = true; }
            elseif ($uploadExt === 'png' && $mimeType === 'image/png') { $validMime = true; }
            elseif ($uploadExt === 'gif' && $mimeType === 'image/gif') { $validMime = true; }
            elseif (in_array($uploadExt, ['doc', 'docx'], true) && in_array($mimeType, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) { $validMime = true; }
            elseif (in_array($uploadExt, ['xls', 'xlsx'], true) && in_array($mimeType, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) { $validMime = true; }
            elseif ($uploadExt === 'csv' && in_array($mimeType, ['text/csv', 'text/plain'], true)) { $validMime = true; }
            elseif ($uploadExt === 'txt' && $mimeType === 'text/plain') { $validMime = true; }

            if (!$validMime) {
                $error = 'File contains invalid content for its extension.';
            } else if ($_FILES['file']['size'] > $maxFileSize) {
                $error = 'File too large. Maximum size: 10MB';
            } else {
              $dir = __DIR__ . '/../../assets/uploads/recruitment/'; if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
              $safeBase = sanitize_file_name(pathinfo($_FILES['file']['name'], PATHINFO_FILENAME));
              $fn = time() . '_' . ($safeBase !== '' ? $safeBase : 'upload') . '.' . $uploadExt;
          $path = $dir . $fn;
          if (move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
            $rel = 'assets/uploads/recruitment/' . $fn;
            try {
              $st = $pdo->prepare('INSERT INTO recruitment_files (recruitment_id, label, file_path, uploaded_by) VALUES (:rid, :label, :path, :uid)');
              $st->execute([':rid'=>$id, ':label'=>$label, ':path'=>$rel, ':uid'=>(int)$u['id']]);
              audit('recruitment_upload', 'id='.$id.', label='.$label);
              action_log('recruitment', 'upload_file', 'success', ['id' => $id, 'label' => $label]);
              flash_success('File uploaded');
              header('Location: ' . BASE_URL . '/modules/recruitment/view?id=' . $id);
              exit;
            } catch (Throwable $e) { sys_log('RECRUIT1201', 'Insert recruitment_file failed - '.$e->getMessage(), ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__]); $error = 'Save failed'; }
          } else { $error = 'Upload failed'; }
          }
        }
      }
    }
    elseif (isset($_POST['transition_employee'])) {
      if (!$canAdmin) { header('Location: ' . BASE_URL . '/unauthorized'); exit; }
      $first = trim($_POST['first_name'] ?? '');
      $last  = trim($_POST['last_name'] ?? '');
      $email = trim($_POST['emp_email'] ?? '');
      $phone = trim($_POST['emp_phone'] ?? '');
      $dept  = (int)($_POST['department_id'] ?? 0);
      $pos   = (int)($_POST['position_id'] ?? 0);
      $code  = trim($_POST['employee_code'] ?? '');
      if ($first === '' || $last === '') { $error = 'First/Last name are required.'; }
      elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Valid employee email is required.'; }
      elseif ($code === '') { $error = 'Employee code is required.'; }
      if (!$error) {
        try { $ck = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE employee_code = :code'); $ck->execute([':code'=>$code]); $cnt = (int)$ck->fetchColumn(); if ($cnt>0) { $error = 'Employee code already exists.'; } } catch (Throwable $e) { sys_log('RECRUIT1301','Dup check code failed - '.$e->getMessage(), ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__]); }
      }
      if (!$error && $email !== '') {
        try { $ck2 = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE email = :email'); $ck2->execute([':email'=>$email]); $cnt2 = (int)$ck2->fetchColumn(); if ($cnt2>0) { $error = 'Employee email already exists.'; } } catch (Throwable $e) { sys_log('RECRUIT1302','Dup check email failed - '.$e->getMessage(), ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__]); }
      }
      if (!$error && isset($rec['template_id']) && (int)$rec['template_id']>0) {
        try {
          $reqs = $pdo->prepare('SELECT label, is_required FROM recruitment_template_files WHERE template_id = :tid');
          $reqs->execute([':tid' => (int)$rec['template_id']]);
          $reqRows = $reqs->fetchAll(PDO::FETCH_ASSOC);
          $have = $pdo->prepare('SELECT DISTINCT label FROM recruitment_files WHERE recruitment_id = :rid');
          $have->execute([':rid' => (int)$rec['id']]);
          $haveLabels = array_map(fn($r)=>$r['label'], $have->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) { sys_log('RECRUIT1401','Template require check failed - '.$e->getMessage(), ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__]); $reqRows=[]; $haveLabels=[]; }
        $miss = [];
        foreach ($reqRows as $rf) { if ((int)$rf['is_required']===1 && !in_array($rf['label'],$haveLabels,true)) { $miss[]=$rf['label']; } }
        if ($miss) { $error = 'Cannot transition: missing required files: ' . implode(', ', $miss); }
      }
      if (!$error) {
        try {
          $pdo->beginTransaction();
          $ins = $pdo->prepare("INSERT INTO employees (user_id, employee_code, first_name, last_name, email, phone, department_id, position_id, hire_date, employment_type, status, salary) VALUES (NULL, :code, :first, :last, :email, :phone, :dept, :pos, CURRENT_DATE, 'regular', 'active', 0) RETURNING id");
          $ins->execute([':code'=>$code, ':first'=>$first, ':last'=>$last, ':email'=>$email, ':phone'=>$phone, ':dept'=>$dept?:null, ':pos'=>$pos?:null]);
          $newEmpId = (int)($ins->fetchColumn() ?: 0);
          $up = $pdo->prepare("UPDATE recruitment SET status='hired', converted_employee_id = :eid, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
          $up->execute([':eid'=>$newEmpId, ':id'=>$rec['id']]);
          $pdo->commit();
          audit('recruitment_transition', json_encode(['recruitment_id'=>$rec['id'],'employee_id'=>$newEmpId]));
          action_log('recruitment', 'transition_to_employee', 'success', ['recruitment_id' => $rec['id'], 'employee_id' => $newEmpId]);
          $success = 'Applicant transitioned to employee successfully.';
          $stmt = $pdo->prepare('SELECT * FROM recruitment WHERE id = :id');
          $stmt->execute([':id' => $id]);
          $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) { $pdo->rollBack(); }
          sys_log('RECRUIT2001', 'Transition failed - '.$e->getMessage(), ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__]);
          $error = 'Could not transition applicant. Please try again.';
        }
      }
    }
  }
}

$fileRows = [];
try { $files = $pdo->prepare('SELECT * FROM recruitment_files WHERE recruitment_id = :rid ORDER BY created_at DESC'); $files->execute([':rid'=>$id]); $fileRows = $files->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { sys_log('RECRUIT1501','Fetch files failed - '.$e->getMessage(), ['module'=>'recruitment','file'=>__FILE__,'line'=>__LINE__]); }

$missing = [];
if (isset($rec['template_id']) && (int)$rec['template_id'] > 0) {
  $tplId = (int)$rec['template_id'];
  $qq = $pdo->prepare('SELECT label, is_required FROM recruitment_template_files WHERE template_id = :tid');
  if ($qq) {
    $qq->execute([':tid' => $tplId]); $reqFiles = $qq->fetchAll(PDO::FETCH_ASSOC);
    $haveLabels = array_map(fn($r)=>$r['label'], $fileRows);
    foreach ($reqFiles as $rf) {
      if ((int)($rf['is_required'] ?? 1) === 1 && !in_array($rf['label'], $haveLabels, true)) {
        $missing[] = $rf['label'];
      }
    }
  }
}

$templateName = '';
if (!empty($rec['template_id'])) {
  foreach ($templates as $tpl) {
    if ((int)$tpl['id'] === (int)$rec['template_id']) { $templateName = $tpl['name']; break; }
  }
}

$statusLabel = $statusOptions[$rec['status']] ?? ucwords(str_replace('_',' ', (string)($rec['status'] ?? '')));
$statusLocked = (int)($rec['converted_employee_id'] ?? 0) > 0;
$sc = $statusColors[$rec['status']] ?? ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'dot' => 'bg-slate-500', 'ring' => 'ring-slate-200'];

// Initials for avatar
$nameParts = explode(' ', trim($rec['full_name'] ?? ''));
$initials = strtoupper(substr($nameParts[0] ?? '', 0, 1));
if (count($nameParts) > 1) $initials .= strtoupper(substr(end($nameParts), 0, 1));

$pageTitle = 'Applicant — ' . ($rec['full_name'] ?? '');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-5xl mx-auto space-y-6">

  <!-- Page Header -->
  <div class="flex items-center gap-3">
    <a href="<?= BASE_URL ?>/modules/recruitment/index" class="inline-flex items-center justify-center h-9 w-9 rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-700 transition shadow-sm">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div class="flex-1">
      <h1 class="text-xl font-bold text-slate-900">Applicant Profile</h1>
      <p class="text-sm text-slate-500">Recruitment #<?= (int)$rec['id'] ?></p>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($error): ?>
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 flex items-start gap-3">
      <svg class="h-5 w-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <p class="text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></p>
    </div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 flex items-start gap-3">
      <svg class="h-5 w-5 text-emerald-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <p class="text-sm font-medium text-emerald-700"><?= htmlspecialchars($success) ?></p>
    </div>
  <?php endif; ?>

  <!-- Profile Card -->
  <div class="card overflow-hidden">
    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-5">
      <div class="flex items-center gap-4">
        <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl bg-white/20 text-lg font-bold text-white backdrop-blur-sm">
          <?= $initials ?>
        </div>
        <div class="flex-1 min-w-0">
          <h2 class="text-lg font-bold text-white truncate"><?= htmlspecialchars($rec['full_name'] ?? '') ?></h2>
          <p class="text-sm text-white/80"><?= htmlspecialchars($rec['position_applied'] ?? 'No position specified') ?></p>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/20 backdrop-blur-sm px-3 py-1.5 text-xs font-semibold text-white ring-1 ring-white/30">
          <span class="h-2 w-2 rounded-full <?= $sc['dot'] ?>"></span>
          <?= htmlspecialchars($statusLabel) ?>
        </span>
      </div>
    </div>
    <div class="p-5">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <div class="text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1">Email</div>
          <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars($rec['email'] ?? '—') ?></p>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1">Phone</div>
          <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars($rec['phone'] ?? '—') ?></p>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1">Applied</div>
          <p class="text-sm font-medium text-slate-900"><?= !empty($rec['created_at']) ? date('M d, Y h:i A', strtotime($rec['created_at'])) : '—' ?></p>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1">Template</div>
          <p class="text-sm font-medium text-slate-900"><?= $templateName ? htmlspecialchars($templateName) : '— None —' ?></p>
        </div>
      </div>
      <?php if (!empty($rec['notes'])): ?>
        <div class="mt-4 pt-4 border-t border-slate-100">
          <div class="text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">Notes</div>
          <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($rec['notes'])) ?></p>
        </div>
      <?php endif; ?>
      <?php if ((int)($rec['converted_employee_id'] ?? 0) > 0): ?>
        <div class="mt-4 pt-4 border-t border-slate-100">
          <div class="flex items-center gap-2 rounded-lg bg-emerald-50 border border-emerald-200 p-3">
            <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-sm font-medium text-emerald-700">Converted to Employee ID: <?= (int)$rec['converted_employee_id'] ?></span>
            <a href="<?= BASE_URL ?>/modules/employees/edit?id=<?= (int)$rec['converted_employee_id'] ?>" class="ml-auto text-xs font-semibold text-emerald-600 hover:text-emerald-800 transition">View Employee →</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid gap-6 lg:grid-cols-2">

    <!-- Edit Applicant -->
    <?php if ($canWrite): ?>
      <div class="card">
        <div class="card-header flex items-center gap-2">
          <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          <span class="text-sm font-semibold text-slate-700">Edit Applicant</span>
        </div>
        <form method="post" class="card-body space-y-4">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="save_profile" value="1">
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-slate-700 mb-1 required">Full Name</label>
              <input name="full_name" required class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" value="<?= htmlspecialchars($rec['full_name'] ?? '') ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
              <input name="email" type="email" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" value="<?= htmlspecialchars($rec['email'] ?? '') ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
              <input name="phone" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" value="<?= htmlspecialchars($rec['phone'] ?? '') ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Position Applied</label>
              <input name="position_applied" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" value="<?= htmlspecialchars($rec['position_applied'] ?? '') ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
              <select name="status" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" <?= $statusLocked ? 'disabled' : '' ?>>
                <?php foreach ($statusOptions as $value => $label): ?>
                  <option value="<?= htmlspecialchars($value) ?>" <?= ($rec['status'] === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($statusLocked): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($rec['status'] ?? 'hired') ?>">
                <p class="mt-1 text-xs text-slate-400">Locked after transition to employee.</p>
              <?php endif; ?>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Template</label>
              <select name="template_id" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition">
                <option value="0">— None —</option>
                <?php foreach ($templates as $tpl): ?>
                  <option value="<?= (int)$tpl['id'] ?>" <?= ((int)($rec['template_id'] ?? 0) === (int)$tpl['id']) ? 'selected' : '' ?>><?= htmlspecialchars($tpl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
              <textarea name="notes" rows="3" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition"><?= htmlspecialchars($rec['notes'] ?? '') ?></textarea>
            </div>
          </div>
          <div class="flex justify-end pt-2">
            <button class="btn btn-primary" type="submit">
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
              Save Changes
            </button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <!-- Files & Documents -->
    <div class="card">
      <div class="card-header flex items-center justify-between">
        <div class="flex items-center gap-2">
          <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          <span class="text-sm font-semibold text-slate-700">Files & Documents</span>
        </div>
        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= count($fileRows) ?> file<?= count($fileRows) !== 1 ? 's' : '' ?></span>
      </div>
      <div class="card-body space-y-4">
        <?php if ($missing): ?>
          <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 flex items-start gap-2">
            <svg class="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            <div>
              <p class="text-sm font-medium text-amber-800">Missing required files</p>
              <p class="text-xs text-amber-700 mt-0.5"><?= htmlspecialchars(implode(', ', $missing)) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($canWrite): ?>
          <form method="post" enctype="multipart/form-data" class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="upload_file" value="1">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-end gap-3">
              <div class="flex-1">
                <label class="block text-xs font-medium text-slate-600 mb-1">Document Label</label>
                <input name="label" required class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition" placeholder="e.g., Resume, ID, Diploma">
              </div>
              <div class="flex-1">
                <label class="block text-xs font-medium text-slate-600 mb-1">File</label>
                <input type="file" name="file" required class="w-full rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm file:mr-2 file:rounded file:border-0 file:bg-indigo-50 file:px-2 file:py-1 file:text-xs file:font-medium file:text-indigo-600">
              </div>
              <button type="submit" class="btn btn-primary whitespace-nowrap">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Upload
              </button>
            </div>
            <p class="mt-2 text-xs text-slate-400">Accepted: PDF, DOC, DOCX, JPG, PNG, XLS, XLSX, CSV, TXT. Max 10MB.</p>
          </form>
        <?php endif; ?>

        <?php if ($fileRows): ?>
          <div class="divide-y divide-slate-100">
            <?php foreach ($fileRows as $f):
              $ext = strtolower(pathinfo($f['file_path'] ?? '', PATHINFO_EXTENSION));
              $iconColor = in_array($ext, ['pdf']) ? 'text-red-500' : (in_array($ext, ['doc','docx']) ? 'text-blue-500' : (in_array($ext, ['jpg','jpeg','png','gif']) ? 'text-emerald-500' : 'text-slate-400'));
            ?>
              <div class="flex items-center gap-3 py-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100">
                  <svg class="h-4 w-4 <?= $iconColor ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-slate-900 truncate"><?= htmlspecialchars($f['label']) ?></p>
                  <p class="text-xs text-slate-400"><?= strtoupper($ext) ?> &middot; <?= !empty($f['created_at']) ? date('M d, Y h:i A', strtotime($f['created_at'])) : '' ?></p>
                </div>
                <a class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 transition" target="_blank" href="<?= BASE_URL . '/' . htmlspecialchars($f['file_path']) ?>">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                  View
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-center py-6">
            <svg class="mx-auto h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <p class="text-sm text-slate-400">No documents uploaded yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Transition to Employee -->
  <?php if ($canAdmin && (int)($rec['converted_employee_id'] ?? 0) === 0): ?>
    <div class="card border-indigo-200">
      <div class="card-header bg-indigo-50/50 flex items-center gap-2">
        <svg class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
        <span class="text-sm font-semibold text-indigo-900">Transition to Employee</span>
        <span class="ml-auto inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-medium text-indigo-700">Admin Only</span>
      </div>
      <form method="post" data-confirm="Transition this applicant to an Employee record? This will mark them as Hired.">
        <div class="card-body space-y-4">
          <p class="text-sm text-slate-600">Convert this applicant into a full employee record. This will create a new entry in the employees table and mark this recruitment record as <strong>Hired</strong>.</p>
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="transition_employee" value="1">
          <?php
            $parts = preg_split('/\s+/', (string)($rec['full_name'] ?? ''));
            $prefFirst = $parts ? array_shift($parts) : '';
            $prefLast  = $parts ? implode(' ', $parts) : '';
          ?>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1 required">Employee Code</label>
              <input name="employee_code" required class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" placeholder="e.g., EMP-001">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1 required">First Name</label>
              <input name="first_name" required class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" value="<?= htmlspecialchars($prefFirst) ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1 required">Last Name</label>
              <input name="last_name" required class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" value="<?= htmlspecialchars($prefLast) ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1 required">Email</label>
              <input name="emp_email" type="email" required class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" value="<?= htmlspecialchars($rec['email'] ?? '') ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
              <input name="emp_phone" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:bg-white transition" value="<?= htmlspecialchars($rec['phone'] ?? '') ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Department</label>
              <select name="department_id" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition">
                <option value="0">— None —</option>
                <?php try { $deps = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $deps = []; } ?>
                <?php foreach ($deps as $d): ?>
                  <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Position</label>
              <select name="position_id" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition">
                <option value="0">— None —</option>
                <?php try { $positions = $pdo->query('SELECT id, name FROM positions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $positions = []; } ?>
                <?php foreach ($positions as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="flex items-center justify-end border-t border-slate-100 px-5 py-4 bg-slate-50/50 rounded-b-xl">
          <button type="submit" class="btn btn-accent">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            Transition to Employee
          </button>
        </div>
      </form>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
