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
$memoId = (int)($_GET['id'] ?? 0);
if ($memoId <= 0) {
  flash_error('Memo not found.');
  header('Location: ' . BASE_URL . '/modules/memos/index');
  exit;
}

$memo = memo_fetch($pdo, $memoId);
if (!$memo) {
  flash_error('Memo not found.');
  header('Location: ' . BASE_URL . '/modules/memos/index');
  exit;
}

$departments = memo_fetch_departments($pdo);
$roles = memo_fetch_roles($pdo);
$selectedEmployeeRecords = [];
$recipients = memo_fetch_recipients($pdo, $memoId);
$attachments = memo_fetch_attachments($pdo, $memoId);

$defaults = [
  'memo_code' => $memo['memo_code'],
  'header' => $memo['header'],
  'issued_by_name' => $memo['issued_by_name'],
  'issued_by_position' => $memo['issued_by_position'],
  'body' => $memo['body'],
  'audience_all' => false,
  'audience_departments' => [],
  'audience_roles' => [],
  'audience_employees' => [],
  'allow_downloads' => (bool)$memo['allow_downloads'],
];
foreach ($recipients as $row) {
  $type = strtolower((string)($row['audience_type'] ?? ''));
  $identifier = (string)($row['audience_identifier'] ?? '');
  if ($type === 'all') {
    $defaults['audience_all'] = true;
  } elseif ($type === 'department' && $identifier !== '') {
    $defaults['audience_departments'][] = $identifier;
  } elseif ($type === 'role' && $identifier !== '') {
    $defaults['audience_roles'][] = $identifier;
  } elseif ($type === 'employee' && $identifier !== '') {
    $defaults['audience_employees'][] = $identifier;
  }
}

$selectedEmployeeRecords = $defaults['audience_employees'] ? memo_fetch_employees_by_ids($pdo, array_map('intval', $defaults['audience_employees'])) : [];
$employeesById = [];
foreach ($selectedEmployeeRecords as $empRow) {
  $employeesById[(int)($empRow['id'] ?? 0)] = $empRow;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_attachment']) && csrf_verify($_POST['csrf'] ?? '')) {
  $attachmentId = (int)$_POST['remove_attachment'];
  $attachment = memo_fetch_attachment($pdo, $attachmentId);
  if ($attachment && (int)$attachment['memo_id'] === $memoId) {
    try {
      $pdo->beginTransaction();
      $del = $pdo->prepare('DELETE FROM memo_attachments WHERE id = :id');
      $del->execute([':id' => $attachmentId]);
      $pdo->commit();
      if (!empty($attachment['file_path'])) {
        $abs = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $attachment['file_path'];
        if (is_file($abs)) {
          @unlink($abs);
        }
      }
      action_log('documents', 'memo_attachment_removed', 'success', ['memo_id' => $memoId, 'attachment_id' => $attachmentId]);
      audit('memo_attachment_removed', json_encode(['memo_id' => $memoId, 'attachment_id' => $attachmentId]));
      flash_success('Attachment removed.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      sys_log('MEMO-ATT-RM', 'Failed to remove memo attachment: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId, 'attachment_id' => $attachmentId]]);
      flash_error('Unable to remove the attachment.');
    }
  } else {
    flash_error('Attachment not found.');
  }
  header('Location: ' . BASE_URL . '/modules/memos/edit?id=' . $memoId);
  exit;
}

$errors = [];
$maxFiles = 5;
$maxSize = 10 * 1024 * 1024;
$allowedTypes = ['pdf','png','jpg','jpeg'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['remove_attachment'])) {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors[] = 'Invalid CSRF token.';
  }
  $memoCode = strtoupper(trim((string)($_POST['memo_code'] ?? '')));
  $header = trim((string)($_POST['header'] ?? ''));
  $body = trim((string)($_POST['body'] ?? ''));
  $issuedName = trim((string)($_POST['issued_by_name'] ?? '')) ?: $defaults['issued_by_name'];
  $issuedPosition = trim((string)($_POST['issued_by_position'] ?? ''));
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

  $attachmentsToStore = [];
  $files = $_FILES['attachments'] ?? null;
  if ($files && isset($files['name']) && is_array($files['name'])) {
    $count = count($files['name']);
    if ($count > $maxFiles) {
      $errors[] = 'Maximum of ' . $maxFiles . ' attachments allowed in one upload.';
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
        $attachmentsToStore[] = [
          'tmp_name' => $files['tmp_name'][$i],
          'original_name' => $files['name'][$i] ?? ('attachment_' . ($i + 1)),
          'size' => $size,
          'extension' => $extension,
        ];
      }
    }
  }

  if (!$errors) {
    $storedFiles = [];
    try {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare('UPDATE memos SET memo_code = :code, header = :header, body = :body, issued_by_name = :issued_name, issued_by_position = :issued_position, allow_downloads = :allow_downloads WHERE id = :id');
      $stmt->execute([
        ':code' => $memoCode,
        ':header' => $header,
        ':body' => $body,
        ':issued_name' => $issuedName,
        ':issued_position' => $issuedPosition !== '' ? $issuedPosition : null,
        ':allow_downloads' => $allowDownloads ? 1 : 0,
        ':id' => $memoId,
      ]);

      $pdo->prepare('DELETE FROM memo_recipients WHERE memo_id = :id')->execute([':id' => $memoId]);
      $recStmt = $pdo->prepare('INSERT INTO memo_recipients (memo_id, audience_type, audience_identifier, audience_label) VALUES (:memo_id, :type, :identifier, :label)');
      foreach ($audienceRows as $row) {
        $recStmt->execute([
          ':memo_id' => $memoId,
          ':type' => $row['type'],
          ':identifier' => $row['identifier'] ?? null,
          ':label' => $row['label'] ?? null,
        ]);
      }

      if ($attachmentsToStore) {
        $uploadDir = __DIR__ . '/../../assets/uploads/memos';
        if (!is_dir($uploadDir)) {
          @mkdir($uploadDir, 0775, true);
        }
        $attStmt = $pdo->prepare('INSERT INTO memo_attachments (memo_id, file_path, original_name, file_size, mime_type, uploaded_by, file_content) VALUES (:memo_id, :path, :name, :size, :mime, :uid, :content)');
        foreach ($attachmentsToStore as $file) {
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
          $attStmt->execute([
            ':memo_id' => $memoId,
            ':path' => $relativePath,
            ':name' => $file['original_name'],
            ':size' => $file['size'],
            ':mime' => $mime,
            ':uid' => $uid ?: null,
            ':content' => $fileContent,
          ]);
        }
      }

    $pdo->commit();
    action_log('documents', 'memo_updated', 'success', ['memo_id' => $memoId, 'code' => $memoCode]);
    audit('memo_updated', json_encode(['memo_id' => $memoId, 'code' => $memoCode, 'downloads_allowed' => $allowDownloads]));

    flash_success('Memo updated successfully.');
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
      sys_log('MEMO-UPDATE', 'Failed to update memo: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId]]);
      $errors[] = 'Failed to update memo.';
    }
  }

  $defaults['memo_code'] = $memoCode;
  $defaults['header'] = $header;
  $defaults['body'] = $body;
  $defaults['issued_by_name'] = $issuedName;
  $defaults['issued_by_position'] = $issuedPosition;
  $defaults['audience_all'] = $audienceAll;
  $defaults['audience_departments'] = array_map('strval', $audienceDepartments);
  $defaults['audience_roles'] = array_map('strval', $audienceRoles);
  $defaults['audience_employees'] = array_map('strval', $audienceEmployees);
  $defaults['allow_downloads'] = $allowDownloads;
}

$audiencePayload = memo_build_audience_payload(
  $departments,
  $roles,
  $selectedEmployeeRecords,
  [
    'all' => $defaults['audience_all'],
    'departments' => $defaults['audience_departments'],
    'roles' => $defaults['audience_roles'],
    'employees' => $defaults['audience_employees'],
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

$pageTitle = 'Edit Memo';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-6xl mx-auto space-y-6">
  <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600">Memo Composer</p>
      <h1 class="text-3xl font-semibold text-slate-900">Edit Memo</h1>
      <p class="mt-1 max-w-2xl text-sm text-slate-600">Update memo details, adjust the audience, or attach additional supporting files.</p>
    </div>
    <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/memos/view?id=<?= $memoId ?>">Back to memo</a>
  </div>

  <?php foreach ($errors as $error): ?>
    <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 shadow-sm flex items-start gap-2">
      <svg class="mt-0.5 h-4 w-4 flex-none text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.007v.008H12v-.008z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php endforeach; ?>

  <form method="post" enctype="multipart/form-data" class="grid gap-6 md:grid-cols-2 lg:grid-cols-3" data-memo-recipient-form data-audience-endpoint="<?= BASE_URL ?>/modules/memos/audience_lookup.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

  <div class="space-y-6 lg:col-span-2">
      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm backdrop-blur">
        <div class="flex flex-col gap-3">
          <div class="flex items-center justify-between">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="memo-recipient-input-edit">Recipients</label>
            <button type="button" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700" data-audience-clear>Clear</button>
          </div>
          <div class="relative">
            <input id="memo-recipient-input-edit" type="text" data-audience-input class="input-text w-full" placeholder="Type @ to mention departments, roles, or individuals" autocomplete="off">
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
              <li><span class="font-semibold text-emerald-700">@dept Finance</span> — target a department by name.</li>
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
            <p class="text-sm text-slate-500">Update core memo information. Recipients see the title, issuer, and body immediately.</p>
          </div>
        </div>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <div class="space-y-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Memo Code</label>
            <input type="text" name="memo_code" class="input-text w-full" value="<?= htmlspecialchars($defaults['memo_code']) ?>" required>
          </div>
          <div class="space-y-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Header</label>
            <input type="text" name="header" class="input-text w-full" value="<?= htmlspecialchars($defaults['header']) ?>" required>
          </div>
          <div class="space-y-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Issued By (Name)</label>
            <input type="text" name="issued_by_name" class="input-text w-full" value="<?= htmlspecialchars($defaults['issued_by_name']) ?>" required>
          </div>
          <div class="space-y-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Issued By (Position)</label>
            <input type="text" name="issued_by_position" class="input-text w-full" value="<?= htmlspecialchars($defaults['issued_by_position'] ?? '') ?>" placeholder="Optional">
          </div>
        </div>
        <div class="mt-4 space-y-2">
          <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Memo Body</label>
          <textarea name="body" class="input-text w-full min-h-[240px] resize-y" required><?= htmlspecialchars($defaults['body']) ?></textarea>
          <p class="text-xs text-slate-400">Tip: Document any changes since last publish to help readers keep track.</p>
        </div>
      </section>

      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm backdrop-blur">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Existing attachments</h2>
            <p class="text-sm text-slate-500">Remove outdated files or add new ones below.</p>
          </div>
        </div>
        <?php if ($attachments): ?>
          <ul class="mt-4 space-y-3">
            <?php foreach ($attachments as $attachment): ?>
              <?php $fileUrl = BASE_URL . '/' . ltrim((string)$attachment['file_path'], '/'); ?>
              <li class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 text-sm text-slate-600 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-3 min-w-0">
                  <span class="flex h-10 w-10 flex-none items-center justify-center rounded-2xl bg-slate-200 text-xs font-semibold text-slate-700">
                    <?= strtoupper(pathinfo($attachment['original_name'], PATHINFO_EXTENSION)) ?: 'FILE' ?>
                  </span>
                  <div class="min-w-0">
                    <div class="truncate font-medium text-slate-800"><?= htmlspecialchars($attachment['original_name']) ?></div>
                    <div class="text-xs text-slate-400">Uploaded <?= htmlspecialchars(format_datetime_display($attachment['uploaded_at'])) ?></div>
                  </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                  <a href="<?= htmlspecialchars($fileUrl) ?>" class="text-xs font-medium text-emerald-600 hover:text-emerald-700" target="_blank" rel="noopener">Preview</a>
                  <form method="post" class="inline-block" data-confirm="Remove this attachment?">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="remove_attachment" value="<?= (int)$attachment['id'] ?>">
                    <button type="submit" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Remove</button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50/80 px-5 py-8 text-center text-sm text-slate-500">No attachments yet.</div>
        <?php endif; ?>
      </section>

    </div>

  <aside class="space-y-6 lg:col-span-1">
      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm backdrop-blur" id="memoEditAttachments">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Add new attachments</h2>
            <p class="text-sm text-slate-500">Upload additional PDFs or images. Existing files stay unless removed above.</p>
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
              <li>Up to <?= $maxFiles ?> files per upload.</li>
              <li>Each file must be 10 MB or smaller.</li>
              <li>Accepted types: PDF, PNG, JPG, JPEG.</li>
            </ul>
          </div>
          <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-700">
            <input type="checkbox" name="allow_downloads" value="1" class="mt-1 h-4 w-4 rounded border-emerald-400 text-emerald-600 focus:ring-emerald-500" <?= $defaults['allow_downloads'] ? 'checked' : '' ?>>
            <span><span class="font-medium text-slate-900">Allow recipients to download attachments</span><span class="block text-xs text-slate-500">Toggle this on to expose a download button in the memo preview.</span></span>
          </label>
        </div>
      </section>
      <section class="rounded-3xl border border-emerald-200 bg-emerald-50/70 p-6 text-sm text-emerald-800 shadow-sm">
        <h2 class="text-base font-semibold text-emerald-900">Change log reminder</h2>
        <ul class="mt-3 space-y-2">
          <li class="flex gap-2">
            <span class="mt-1 h-2 w-2 flex-none rounded-full bg-emerald-500"></span>
            <span>Recipients see the latest publish time as soon as you save.</span>
          </li>
          <li class="flex gap-2">
            <span class="mt-1 h-2 w-2 flex-none rounded-full bg-emerald-500"></span>
            <span>Consider adding a short summary of changes at the top of the memo.</span>
          </li>
          <li class="flex gap-2">
            <span class="mt-1 h-2 w-2 flex-none rounded-full bg-emerald-500"></span>
            <span>Attachments added now inherit the current download permission.</span>
          </li>
        </ul>
      </section>
      <div class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm">
        <div class="space-y-4">
          <button class="w-full rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-500 px-4 py-3 text-sm font-semibold text-white shadow-lg transition hover:from-emerald-500 hover:to-teal-400 focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:ring-offset-2" type="submit">Save changes</button>
          <a class="block text-center text-sm font-medium text-slate-500 transition hover:text-slate-700" href="<?= BASE_URL ?>/modules/memos/view?id=<?= $memoId ?>">Cancel</a>
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

<?php require __DIR__ . '/partials/audience_confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
