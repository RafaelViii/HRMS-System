<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('documents', 'memos', 'write');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/form_helpers.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$pageTitle = 'Create Memo';

$departments = memo_fetch_departments($pdo);
$roles = memo_fetch_roles($pdo);
$selectedEmployeeRecords = [];

$audienceAll = false;
$audienceDepartments = [];
$audienceRoles = [];
$audienceEmployees = [];
$allowDownloads = false;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors[] = 'Invalid CSRF token.';
  }
  $memoCode = strtoupper(trim((string)($_POST['memo_code'] ?? '')));
  $header = trim((string)($_POST['header'] ?? ''));
  $audienceAll = isset($_POST['audience_all']) && $_POST['audience_all'] === '1';
  $audienceDepartments = json_decode($_POST['audience_departments'] ?? '[]', true);
  $audienceRoles = json_decode($_POST['audience_roles'] ?? '[]', true);
  $audienceEmployees = json_decode($_POST['audience_employees'] ?? '[]', true);
  if (!is_array($audienceDepartments)) { $audienceDepartments = []; }
  if (!is_array($audienceRoles)) { $audienceRoles = []; }
  if (!is_array($audienceEmployees)) { $audienceEmployees = []; }
  $audienceDepartments = array_filter(array_map('strval', $audienceDepartments), 'strlen');
  $audienceRoles = array_filter(array_map('strval', $audienceRoles), 'strlen');
  $audienceEmployees = array_filter(array_map('strval', $audienceEmployees), 'strlen');
  $audienceEmployeeIds = array_values(array_unique(array_map('intval', $audienceEmployees)));
  $selectedEmployeeRecords = $audienceEmployeeIds ? memo_fetch_employees_by_ids($pdo, $audienceEmployeeIds) : [];
  $employeesById = [];
  foreach ($selectedEmployeeRecords as $empRow) {
    $employeesById[(int)($empRow['id'] ?? 0)] = $empRow;
  }
  $body = trim((string)($_POST['body'] ?? ''));
  $issuedName = trim((string)($_POST['issued_by_name'] ?? '')) ?: trim((string)($user['full_name'] ?? ''));
  $issuedPosition = trim((string)($_POST['issued_by_position'] ?? ''));
  $allowDownloads = isset($_POST['allow_downloads']) && $_POST['allow_downloads'] === '1';

  if ($memoCode === '') {
    $errors[] = 'Memo code is required.';
  }
  if ($header === '') {
    $errors[] = 'Memo header is required.';
  }
  if ($body === '') {
    $errors[] = 'Memo body is required.';
  }

  $audienceRows = [];
  if ($audienceAll) {
    $audienceRows[] = ['type' => 'all', 'identifier' => null, 'label' => 'All employees'];
  }
  foreach ($audienceDepartments as $deptId) {
    $deptId = (int)$deptId;
    if ($deptId <= 0) {
      continue;
    }
    foreach ($departments as $dept) {
      if ((int)$dept['id'] === $deptId) {
        $audienceRows[] = ['type' => 'department', 'identifier' => (string)$deptId, 'label' => $dept['name']];
        break;
      }
    }
  }
  foreach ($audienceRoles as $roleCode) {
    $roleCode = trim((string)$roleCode);
    if ($roleCode === '') {
      continue;
    }
    foreach ($roles as $role) {
      if ((string)$role['code'] === $roleCode) {
        $audienceRows[] = ['type' => 'role', 'identifier' => $roleCode, 'label' => $role['name']];
        break;
      }
    }
  }
  foreach ($audienceEmployeeIds as $empId) {
    if ($empId <= 0) {
      continue;
    }
    if (isset($employeesById[$empId])) {
      $emp = $employeesById[$empId];
      $audienceRows[] = ['type' => 'employee', 'identifier' => (string)$empId, 'label' => $emp['label'] ?? ($emp['employee_code'] ?? 'Employee')];
    }
  }

  if (!$audienceRows) {
    $errors[] = 'Select at least one audience (All, Departments, Roles, or Individuals).';
  }

  $maxFiles = 5;
  $maxSize = 10 * 1024 * 1024; // 10 MB
  $allowedTypes = ['pdf','png','jpg','jpeg'];
  $attachments = [];
  if (!$errors) {
    $files = $_FILES['attachments'] ?? null;
    if ($files && isset($files['name']) && is_array($files['name'])) {
      $count = count($files['name']);
      if ($count > $maxFiles) {
        $errors[] = 'Maximum of ' . $maxFiles . ' attachments allowed.';
      } else {
        for ($i = 0; $i < $count; $i++) {
          $errorCode = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
          if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
          }
          if ($errorCode !== UPLOAD_ERR_OK) {
            $errors[] = 'Attachment ' . ($i + 1) . ' failed to upload.';
            continue;
          }
          $size = (int)($files['size'][$i] ?? 0);
          if ($size <= 0) {
            $errors[] = 'Attachment ' . ($i + 1) . ' is empty.';
            continue;
          }
          if ($size > $maxSize) {
            $errors[] = 'Attachment ' . ($i + 1) . ' exceeds 10 MB.';
            continue;
          }
          $extension = strtolower((string)pathinfo($files['name'][$i] ?? '', PATHINFO_EXTENSION));
          if ($extension === '' || !in_array($extension, $allowedTypes, true)) {
            $errors[] = 'Attachment ' . ($i + 1) . ' must be a PDF or image (PNG/JPG/JPEG).';
            continue;
          }

          // M-05 fix matching: Actual content type validation
          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          $mimeType = finfo_file($finfo, $files['tmp_name'][$i]);
          finfo_close($finfo);
        
          $validMime = false;
          if ($extension === 'pdf' && $mimeType === 'application/pdf') { $validMime = true; }
          elseif (in_array($extension, ['jpg', 'jpeg'], true) && $mimeType === 'image/jpeg') { $validMime = true; }
          elseif ($extension === 'png' && $mimeType === 'image/png') { $validMime = true; }

          if (!$validMime) {
              $errors[] = 'Attachment ' . ($i + 1) . ' contains invalid content for its extension.';
              continue;
          }

          $attachments[] = [
            'tmp_name' => $files['tmp_name'][$i],
            'original_name' => $files['name'][$i] ?? ('attachment_' . ($i + 1)),
            'size' => $size,
            'extension' => $extension,
          ];
        }
      }
    }
  }

  $storedFiles = [];
  if (!$errors) {
    try {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare('INSERT INTO memos (memo_code, header, body, issued_by_user_id, issued_by_name, issued_by_position, allow_downloads) VALUES (:code, :header, :body, :uid, :issued_name, :issued_position, :allow_downloads) RETURNING id');
      $stmt->execute([
        ':code' => $memoCode,
        ':header' => $header,
        ':body' => $body,
        ':uid' => $uid ?: null,
        ':issued_name' => $issuedName,
        ':issued_position' => $issuedPosition !== '' ? $issuedPosition : null,
        ':allow_downloads' => $allowDownloads ? 1 : 0,
      ]);
      $memoId = (int)$stmt->fetchColumn();

      $recStmt = $pdo->prepare('INSERT INTO memo_recipients (memo_id, audience_type, audience_identifier, audience_label) VALUES (:memo_id, :type, :identifier, :label)');
      foreach ($audienceRows as $row) {
        $recStmt->execute([
          ':memo_id' => $memoId,
          ':type' => $row['type'],
          ':identifier' => $row['identifier'] ?? null,
          ':label' => $row['label'] ?? null,
        ]);
      }

      if ($attachments) {
        $uploadDir = __DIR__ . '/../../assets/uploads/memos';
        if (!is_dir($uploadDir)) {
          @mkdir($uploadDir, 0775, true);
        }
        $attStmt = $pdo->prepare('INSERT INTO memo_attachments (memo_id, file_path, original_name, file_size, mime_type, uploaded_by, file_content) VALUES (:memo_id, :path, :name, :size, :mime, :uid, :content)');
        foreach ($attachments as $file) {
          $safeBase = sanitize_file_name(pathinfo($file['original_name'], PATHINFO_FILENAME));
          if ($safeBase === '') {
            $safeBase = 'memo_attachment';
          }
          $suffix = bin2hex(random_bytes(4));
          $fileName = $safeBase . '_' . $memoId . '_' . $suffix . '.' . $file['extension'];
          $relativePath = 'assets/uploads/memos/' . $fileName;
          $absolutePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relativePath;
          
          // Read file content into memory for database storage
          $fileContent = file_get_contents($file['tmp_name']);
          if ($fileContent === false) {
            throw new RuntimeException('Failed to read uploaded file content.');
          }
          
          // Also try to save to filesystem as backup (optional, will work locally but not on Heroku)
          $destDir = dirname($absolutePath);
          if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
          }
          @move_uploaded_file($file['tmp_name'], $absolutePath);
          if (file_exists($absolutePath)) {
            $storedFiles[] = $absolutePath;
          }
          
          $mime = 'application/octet-stream';
          if ($file['extension'] === 'pdf') {
            $mime = 'application/pdf';
          } elseif (in_array($file['extension'], ['jpg', 'jpeg'], true)) {
            $mime = 'image/jpeg';
          } elseif ($file['extension'] === 'png') {
            $mime = 'image/png';
          }
          
          // Bind file content as binary data using PDO::PARAM_LOB
          $attStmt->bindValue(':memo_id', $memoId, PDO::PARAM_INT);
          $attStmt->bindValue(':path', $relativePath, PDO::PARAM_STR);
          $attStmt->bindValue(':name', $file['original_name'], PDO::PARAM_STR);
          $attStmt->bindValue(':size', $file['size'], PDO::PARAM_INT);
          $attStmt->bindValue(':mime', $mime, PDO::PARAM_STR);
          $attStmt->bindValue(':uid', $uid ?: null, PDO::PARAM_INT);
          $attStmt->bindValue(':content', $fileContent, PDO::PARAM_LOB);
          $attStmt->execute();
        }
      }

      $pdo->commit();

      action_log('documents', 'memo_created', 'success', ['memo_id' => $memoId, 'code' => $memoCode]);
      audit('memo_created', json_encode(['memo_id' => $memoId, 'code' => $memoCode, 'audience' => array_column($audienceRows, 'type'), 'downloads_allowed' => $allowDownloads]));

      // Notifications now sent automatically by database trigger (trg_notify_memo_published)
      // when published_at is set. No need to call memo_dispatch_notifications() here.
      // See: database/migrations/2025-11-08_notification_triggers.sql
      // memo_dispatch_notifications($pdo, $memoId, $header, $audienceRows);

      flash_success('Memo posted successfully.');
      header('Location: ' . BASE_URL . '/modules/memos/view?id=' . $memoId);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      foreach ($storedFiles as $path) {
        if (is_file($path)) {
          @unlink($path);
        }
      }
      if ((string)$e->getCode() === '23505') {
        $errors[] = 'Memo code already exists. Please choose a different code.';
      } else {
        sys_log('MEMO-CREATE', 'Failed to create memo: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
        $errors[] = 'Failed to create memo.';
      }
    }
  }
}

$audiencePayload = memo_build_audience_payload(
  $departments,
  $roles,
  $selectedEmployeeRecords,
  [
    'all' => $audienceAll,
    'departments' => $audienceDepartments,
    'roles' => $audienceRoles,
    'employees' => $audienceEmployees,
  ],
  [
    'endpoint' => BASE_URL . '/modules/memos/audience_lookup.php',
    'min_term' => 2,
    'debounce_ms' => 250,
  ]
);
$audienceState = $audiencePayload['state'];
$audiencePayloadJson = json_encode($audiencePayload, JSON_UNESCAPED_SLASHES);
if ($audiencePayloadJson === false) {
  $audiencePayloadJson = '{"options":{"shortcuts":[],"departments":[],"roles":[],"employees":[]},"state":{"all":false,"departments":[],"roles":[],"employees":[]}}';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-6xl mx-auto space-y-6">
  <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600">Memo Composer</p>
      <h1 class="text-3xl font-semibold text-slate-900">Create Memo</h1>
      <p class="mt-1 max-w-2xl text-sm text-slate-600">Craft a memo, target the right audience, and attach supporting files with live previews before sending.</p>
    </div>
    <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/memos/index">Back to Memo Hub</a>
  </div>

  <?php foreach ($errors as $error): ?>
    <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 shadow-sm flex items-start gap-2">
      <svg class="mt-0.5 h-4 w-4 flex-none text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.007v.008H12v-.008z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php endforeach; ?>

  <form method="post" enctype="multipart/form-data" class="grid gap-6 lg:grid-cols-[minmax(0,2fr),minmax(0,1fr)]" data-memo-recipient-form data-audience-endpoint="<?= BASE_URL ?>/modules/memos/audience_lookup.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

    <div class="space-y-6">
      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm backdrop-blur">
        <div class="flex flex-col gap-3">
          <div class="flex items-center justify-between">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="memo-recipient-input">Recipients</label>
            <button type="button" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700" data-audience-clear>Clear</button>
          </div>
          <div class="relative">
            <input id="memo-recipient-input" type="text" data-audience-input class="input-text w-full" placeholder="Type @ to mention departments, roles, or individuals" autocomplete="off">
            <div class="absolute left-0 right-0 top-full z-20 mt-2 hidden rounded-2xl border border-slate-200 bg-white shadow-xl" data-audience-suggestions></div>
          </div>
          <div class="flex min-h-[2.5rem] flex-wrap items-center gap-2" data-audience-chips>
            <span class="text-xs text-slate-400">No recipients selected yet.</span>
          </div>
          <input type="hidden" name="audience_all" value="<?= !empty($audienceState['all']) ? '1' : '0' ?>" data-audience-all>
          <input type="hidden" name="audience_departments" value='<?= htmlspecialchars(json_encode($audienceState['departments']), ENT_QUOTES, 'UTF-8') ?>' data-audience-departments>
          <input type="hidden" name="audience_roles" value='<?= htmlspecialchars(json_encode($audienceState['roles']), ENT_QUOTES, 'UTF-8') ?>' data-audience-roles>
          <input type="hidden" name="audience_employees" value='<?= htmlspecialchars(json_encode($audienceState['employees']), ENT_QUOTES, 'UTF-8') ?>' data-audience-employees>
          <input type="hidden" name="audience_rows" value='<?= htmlspecialchars(json_encode($audiencePayload['state']), ENT_QUOTES, 'UTF-8') ?>' data-audience-serialized>
          <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-xs text-emerald-700">
            <p class="font-medium text-emerald-900">Mention shortcuts</p>
            <ul class="mt-1 space-y-1">
              <li><span class="font-semibold text-emerald-700">@all</span> — everyone in the organization.</li>
              <li><span class="font-semibold text-emerald-700">@dept Sales</span> — target a department by name.</li>
              <li><span class="font-semibold text-emerald-700">@role HR_Manager</span> — reach users with a specific role.</li>
              <li><span class="font-semibold text-emerald-700">@emp Dela Cruz</span> — mention an individual employee.</li>
            </ul>
          </div>
          <script type="application/json" data-memo-audience><?= htmlspecialchars($audiencePayloadJson, ENT_QUOTES, 'UTF-8') ?></script>
        </div>
      </section>

      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm backdrop-blur">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Memo details</h2>
            <p class="text-sm text-slate-500">Give your memo a recognizable code and title so recipients know what it is about at a glance.</p>
          </div>
        </div>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <div class="space-y-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Memo Code</label>
            <input type="text" name="memo_code" class="input-text w-full" value="<?= htmlspecialchars($_POST['memo_code'] ?? '') ?>" placeholder="e.g. MEMO-2025-010" required>
          </div>
          <div class="space-y-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Header</label>
            <input type="text" name="header" class="input-text w-full" value="<?= htmlspecialchars($_POST['header'] ?? '') ?>" placeholder="Memo subject" required>
          </div>
          <div class="space-y-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Issued By (Name)</label>
            <input type="text" name="issued_by_name" class="input-text w-full" value="<?= htmlspecialchars($_POST['issued_by_name'] ?? ($user['full_name'] ?? '')) ?>" required>
          </div>
          <div class="space-y-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Issued By (Position)</label>
            <input type="text" name="issued_by_position" class="input-text w-full" value="<?= htmlspecialchars($_POST['issued_by_position'] ?? ($user['role'] ?? '')) ?>" placeholder="Optional">
          </div>
        </div>
        <div class="mt-4 space-y-2">
          <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Memo Body</label>
          <textarea name="body" class="input-text w-full min-h-[240px] resize-y" placeholder="Write the memo content here..." required><?= htmlspecialchars($_POST['body'] ?? '') ?></textarea>
          <p class="text-xs text-slate-400">Tip: Keep important points in short paragraphs for easier scanning.</p>
        </div>
      </section>

      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm backdrop-blur" id="memoAttachmentsCard">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Attachments</h2>
            <p class="text-sm text-slate-500">Attach supporting PDFs or images. Recipients can preview them in place.</p>
          </div>
        </div>
        <div class="mt-4 space-y-4">
          <label class="block w-full cursor-pointer rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50/80 p-6 text-center text-sm text-slate-500 transition hover:border-emerald-400 hover:bg-emerald-50/60">
            <span class="font-medium text-slate-700">Drop files here or click to browse</span>
            <input data-memo-files type="file" name="attachments[]" accept=".pdf,.png,.jpg,.jpeg" class="hidden" multiple>
          </label>
          <div class="rounded-2xl border border-slate-200 bg-white/70" data-memo-file-list>
            <div class="px-4 py-6 text-center text-sm text-slate-400" data-empty-state>No files added yet.</div>
            <ul class="hidden divide-y divide-slate-100" data-file-items></ul>
          </div>
          <div class="flex flex-col gap-2 rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-xs text-amber-700">
            <div class="font-medium text-amber-800">Attachment rules</div>
            <ul class="list-disc space-y-1 pl-5">
              <li>Up to <?= $maxFiles ?> files.</li>
              <li>Each file must be 10 MB or smaller.</li>
              <li>Accepted types: PDF, PNG, JPG, JPEG.</li>
            </ul>
          </div>
          <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-700">
            <input type="checkbox" name="allow_downloads" value="1" class="mt-1 h-4 w-4 rounded border-emerald-400 text-emerald-600 focus:ring-emerald-500" <?= $allowDownloads ? 'checked' : '' ?>>
            <span><span class="font-medium text-slate-900">Allow recipients to download attachments</span><span class="block text-xs text-slate-500">When disabled, recipients can preview attachments in-app but won&rsquo;t see a download option. You can update this later.</span></span>
          </label>
        </div>
      </section>
    </div>

    <aside class="space-y-6">
      <section class="rounded-3xl border border-emerald-200 bg-emerald-50/70 p-6 text-sm text-emerald-800 shadow-sm">
        <h2 class="text-base font-semibold text-emerald-900">Publishing checklist</h2>
        <ul class="mt-3 space-y-3">
          <li class="flex gap-3">
            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-emerald-500/20 text-emerald-600">1</span>
            <div>
              <p class="font-medium">Review the audience</p>
              <p class="text-xs text-emerald-700/80">Confirm departments, roles, or individuals who must receive the memo.</p>
            </div>
          </li>
          <li class="flex gap-3">
            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-emerald-500/20 text-emerald-600">2</span>
            <div>
              <p class="font-medium">Check attachment previews</p>
              <p class="text-xs text-emerald-700/80">Ensure documents are final; you can drag files to reorder before sending.</p>
            </div>
          </li>
          <li class="flex gap-3">
            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-emerald-500/20 text-emerald-600">3</span>
            <div>
              <p class="font-medium">Send confidently</p>
              <p class="text-xs text-emerald-700/80">Recipients receive notifications instantly once you post the memo.</p>
            </div>
          </li>
        </ul>
      </section>
      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 text-sm text-slate-600 shadow-sm">
        <h2 class="text-base font-semibold text-slate-900">What happens after posting?</h2>
        <ul class="mt-3 space-y-2">
          <li class="flex gap-2">
            <span class="mt-1 h-2 w-2 flex-none rounded-full bg-emerald-500"></span>
            <span>Recipients get a notification pointing to this memo.</span>
          </li>
          <li class="flex gap-2">
            <span class="mt-1 h-2 w-2 flex-none rounded-full bg-emerald-500"></span>
            <span>Audit logs capture the publish action with audience details.</span>
          </li>
          <li class="flex gap-2">
            <span class="mt-1 h-2 w-2 flex-none rounded-full bg-emerald-500"></span>
            <span>You can edit the memo later to refine text, audience, or download access.</span>
          </li>
        </ul>
      </section>
      <div class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm">
        <div class="space-y-4">
          <button class="w-full rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-500 px-4 py-3 text-sm font-semibold text-white shadow-lg transition hover:from-emerald-500 hover:to-teal-400 focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:ring-offset-2" type="submit">Post memo</button>
          <a class="block text-center text-sm font-medium text-slate-500 transition hover:text-slate-700" href="<?= BASE_URL ?>/modules/memos/index">Cancel</a>
        </div>
      </div>
    </aside>
  </form>
</div>

<script>
  (function(){
    function init(scope){
      const input = scope.querySelector('[data-memo-files]');
      if (!input || input.dataset.memoBound) return;
      input.dataset.memoBound = '1';
      const wrapper = scope.querySelector('[data-memo-file-list]');
      const emptyState = wrapper?.querySelector('[data-empty-state]');
      const list = wrapper?.querySelector('[data-file-items]');
      const exts = { pdf: 'PDF', jpg: 'IMG', jpeg: 'IMG', png: 'IMG' };
      const formatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
      const formatSize = (bytes) => {
        if (!bytes || bytes < 1024) return bytes + ' B';
        const units = ['KB','MB','GB'];
        let val = bytes / 1024; let idx = 0;
        while (val >= 1024 && idx < units.length - 1) { val /= 1024; idx++; }
        return formatter.format(val) + ' ' + units[idx];
      };
      const render = () => {
        if (!list || !emptyState) return;
        list.innerHTML = '';
        const files = Array.from(input.files || []);
        if (!files.length) {
          emptyState.classList.remove('hidden');
          list.classList.add('hidden');
          return;
        }
        emptyState.classList.add('hidden');
        list.classList.remove('hidden');
        files.forEach((file, idx) => {
          const ext = (file.name.split('.').pop() || '').toLowerCase();
          const badge = exts[ext] || (ext ? ext.toUpperCase() : 'FILE');
          const li = document.createElement('li');
          li.className = 'flex items-center gap-3 px-4 py-3 text-sm text-slate-600';
          li.innerHTML = `
            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-700">${badge}</span>
            <div class="flex-1 min-w-0">
              <div class="truncate font-medium text-slate-800">${file.name}</div>
              <div class="text-xs text-slate-400">${formatSize(file.size)}${file.lastModified ? ' • ' + new Date(file.lastModified).toLocaleDateString() : ''}</div>
            </div>
            <div class="text-[11px] uppercase tracking-wide text-slate-400">#${idx + 1}</div>`;
          list.appendChild(li);
        });
      };
      input.addEventListener('change', render);
      input.addEventListener('drop', () => setTimeout(render, 0));
    }
    document.addEventListener('DOMContentLoaded', () => init(document));
    document.addEventListener('spa:loaded', () => init(document));
  })();
</script>

<!-- Memo Preview/Confirmation Modal -->
<div id="memoPreviewModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
  <div class="absolute inset-0 bg-slate-900/70"></div>
  <div class="flex min-h-full items-center justify-center p-4">
    <div class="relative w-full max-w-4xl overflow-hidden rounded-3xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 bg-gradient-to-r from-emerald-600 to-teal-500 px-6 py-4">
        <h3 class="text-lg font-semibold text-white flex items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg> Confirm Memo Posting</h3>
        <button type="button" class="rounded-full p-2 text-white/80 transition hover:bg-white/20 hover:text-white" data-modal-cancel aria-label="Cancel">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="max-h-[70vh] overflow-auto px-6 py-6">
        <!-- Preview Content -->
        <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <div class="flex items-start gap-2">
            <svg class="mt-0.5 h-5 w-5 flex-none text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
            <div>
              <p class="font-semibold text-amber-900">Review before posting</p>
              <p class="mt-1 text-xs text-amber-700">Please review the memo details below. Once posted, recipients will be notified immediately.</p>
            </div>
          </div>
        </div>
        
        <div class="space-y-6">
          <!-- Memo Header -->
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
            <div class="mb-4 flex items-start justify-between gap-4">
              <div class="flex-1">
                <div class="mb-2 inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700" data-preview-code>MEMO-CODE</div>
                <h1 class="text-2xl font-bold text-slate-900" data-preview-header>Memo Header</h1>
              </div>
            </div>
            <div class="grid gap-4 text-sm md:grid-cols-2">
              <div>
                <span class="font-semibold text-slate-700">Issued By:</span>
                <span class="text-slate-600" data-preview-issued-name>Name</span>
              </div>
              <div>
                <span class="font-semibold text-slate-700">Position:</span>
                <span class="text-slate-600" data-preview-issued-position>Position</span>
              </div>
            </div>
          </div>

          <!-- Recipients -->
          <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
            <h3 class="mb-2 text-sm font-semibold text-blue-900 flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg> Recipients</h3>
            <div class="flex flex-wrap gap-2" data-preview-recipients>
              <span class="text-sm text-blue-600">No recipients selected</span>
            </div>
          </div>

          <!-- Memo Body -->
          <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Memo Content</h3>
            <div class="prose prose-slate max-w-none text-slate-700" data-preview-body style="white-space: pre-wrap;">No content provided</div>
          </div>

          <!-- Attachments -->
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4" data-preview-attachments-section style="display:none;">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500 flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg> Attachments</h3>
            <div class="space-y-2" data-preview-attachments></div>
          </div>
        </div>
      </div>
      <div class="border-t border-slate-200 bg-slate-50 px-6 py-4">
        <div class="flex items-center justify-end gap-3">
          <button type="button" class="rounded-xl border border-slate-300 bg-white px-6 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50" data-modal-cancel>Cancel</button>
          <button type="button" class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-500 px-6 py-2 text-sm font-semibold text-white shadow transition hover:from-emerald-500 hover:to-teal-400" data-modal-confirm>Confirm & Post Memo</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('memoPreviewModal');
  const form = document.querySelector('[data-memo-recipient-form]');
  
  if (!modal || !form) return;
  
  let formSubmitApproved = false;
  
  // Close modal handlers
  modal.querySelectorAll('[data-modal-cancel]').forEach(btn => {
    btn.addEventListener('click', () => {
      modal.classList.add('hidden');
      formSubmitApproved = false;
    });
  });
  
  // Confirm button
  const confirmBtn = modal.querySelector('[data-modal-confirm]');
  if (confirmBtn) {
    confirmBtn.addEventListener('click', () => {
      formSubmitApproved = true;
      modal.classList.add('hidden');
      form.submit();
    });
  }
  
  // Escape key to close
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
      modal.classList.add('hidden');
      formSubmitApproved = false;
    }
  });
  
  // Intercept form submission
  form.addEventListener('submit', (e) => {
    if (formSubmitApproved) {
      // Already approved, let it submit
      formSubmitApproved = false;
      return;
    }
    
    e.preventDefault();
    
    // Get form data
    const formData = new FormData(form);
    const memoCode = formData.get('memo_code') || 'MEMO-CODE';
    const header = formData.get('header') || 'Memo Header';
    const issuedName = formData.get('issued_by_name') || 'Not specified';
    const issuedPosition = formData.get('issued_by_position') || 'Not specified';
    const body = formData.get('body') || 'No content provided';
    
    // Validate required fields
    if (!formData.get('memo_code') || !formData.get('header') || !formData.get('body')) {
      alert('Please fill in all required fields: Memo Code, Header, and Body.');
      return;
    }
    
    // Get recipients from audience system
    const audienceAll = formData.get('audience_all') === '1';
    const audienceDepartments = JSON.parse(formData.get('audience_departments') || '[]');
    const audienceRoles = JSON.parse(formData.get('audience_roles') || '[]');
    const audienceEmployees = JSON.parse(formData.get('audience_employees') || '[]');
    
    // Check if recipients are selected
    const hasRecipients = audienceAll || 
                         (Array.isArray(audienceDepartments) && audienceDepartments.length > 0) ||
                         (Array.isArray(audienceRoles) && audienceRoles.length > 0) ||
                         (Array.isArray(audienceEmployees) && audienceEmployees.length > 0);
    
    if (!hasRecipients) {
      alert('Please select at least one recipient for this memo.');
      return;
    }
    
    // Update preview content
    modal.querySelector('[data-preview-code]').textContent = memoCode;
    modal.querySelector('[data-preview-header]').textContent = header;
    modal.querySelector('[data-preview-issued-name]').textContent = issuedName;
    modal.querySelector('[data-preview-issued-position]').textContent = issuedPosition;
    modal.querySelector('[data-preview-body]').textContent = body;
    
    // Build recipients display
    const recipientsContainer = modal.querySelector('[data-preview-recipients]');
    recipientsContainer.innerHTML = '';
    
    if (audienceAll) {
      const badge = document.createElement('span');
      badge.className = 'inline-flex items-center gap-1.5 rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700';
      badge.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg> All Employees';
      recipientsContainer.appendChild(badge);
    } else {
      let hasRecipientChips = false;
      
      // Get audience chips from the form
      const chips = document.querySelectorAll('[data-audience-chips] [data-audience-chip]');
      chips.forEach(chip => {
        hasRecipientChips = true;
        const badge = document.createElement('span');
        badge.className = 'inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700';
        badge.textContent = chip.querySelector('span').textContent;
        recipientsContainer.appendChild(badge);
      });
      
      if (!hasRecipientChips) {
        recipientsContainer.innerHTML = '<span class="text-sm text-amber-600 flex items-center gap-1"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg> No recipients selected</span>';
      }
    }
    
    // Handle attachments
    const fileInput = document.querySelector('[data-memo-files]');
    const files = Array.from(fileInput?.files || []);
    const attachmentsSection = modal.querySelector('[data-preview-attachments-section]');
    const attachmentsContainer = modal.querySelector('[data-preview-attachments]');
    
    if (files.length > 0) {
      attachmentsSection.style.display = 'block';
      attachmentsContainer.innerHTML = '';
      
      files.forEach((file, idx) => {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const exts = { pdf: 'PDF', jpg: 'IMG', jpeg: 'IMG', png: 'IMG' };
        const badge = exts[ext] || 'FILE';
        
        const formatSize = (bytes) => {
          if (bytes < 1024) return bytes + ' B';
          const units = ['KB', 'MB', 'GB'];
          let val = bytes / 1024;
          let idx = 0;
          while (val >= 1024 && idx < units.length - 1) { val /= 1024; idx++; }
          return val.toFixed(1) + ' ' + units[idx];
        };
        
        const item = document.createElement('div');
        item.className = 'flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3';
        item.innerHTML = `
          <span class="flex h-10 w-10 flex-none items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-700">${badge}</span>
          <div class="flex-1 min-w-0">
            <div class="truncate font-medium text-slate-800">${file.name}</div>
            <div class="text-xs text-slate-400">${formatSize(file.size)}</div>
          </div>
          <div class="text-xs text-slate-400">#${idx + 1}</div>
        `;
        attachmentsContainer.appendChild(item);
      });
    } else {
      attachmentsSection.style.display = 'none';
    }
    
    // Show modal
    modal.classList.remove('hidden');
  });
})();
</script>

<?php require __DIR__ . '/partials/audience_confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
