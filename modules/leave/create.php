<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
try {
  $dbDriver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
} catch (Throwable $e) {
  $dbDriver = 'pgsql';
}
$isPgsql = $dbDriver === 'pgsql';
$user = current_user();
$uid = (int)$user['id'];

// Find employee record for this user
try { $stmt = $pdo->prepare('SELECT id, employee_code, first_name, last_name, department_id FROM employees WHERE user_id = :uid LIMIT 1'); $stmt->execute([':uid'=>$uid]); $emp = $stmt->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) { $emp = null; }
$pageTitle = 'Leaves';
if (!$emp) {
  require_once __DIR__ . '/../../includes/header.php';
  show_human_error('Your account is not linked to an employee profile.');
  require_once __DIR__ . '/../../includes/footer.php';
  exit;
}

$entitlementLayers = leave_collect_entitlement_layers($pdo, (int)$emp['id']);
$leaveEntitlements = $entitlementLayers['effective'];
$knownLeaveTypes = leave_get_known_types($pdo);

// Fetch leave filing policies for validation and UI hints
$leavePolicies = [];
try {
  $policyStmt = $pdo->query("SELECT leave_type, require_advance_notice, advance_notice_days FROM leave_filing_policies WHERE is_active = TRUE");
  $policiesRaw = $policyStmt ? $policyStmt->fetchAll(PDO::FETCH_ASSOC) : [];
  foreach ($policiesRaw as $p) {
    $leavePolicies[$p['leave_type']] = [
      'require_advance_notice' => ($p['require_advance_notice'] === 't' || $p['require_advance_notice'] === true),
      'advance_notice_days' => (int)($p['advance_notice_days'] ?? 0)
    ];
  }
} catch (Throwable $e) {
  sys_log('LEAVE_POLICIES_FETCH_FAILED', 'Failed to fetch leave policies: ' . $e->getMessage());
  $leavePolicies = [];
}
foreach ($knownLeaveTypes as $leaveTypeCode) {
  if (!array_key_exists($leaveTypeCode, $leaveEntitlements)) {
    $leaveEntitlements[$leaveTypeCode] = 0;
  }
}
$leaveBalances = leave_calculate_balances($pdo, (int)$emp['id'], $leaveEntitlements);
$totalAvailableLeave = 0.0;
foreach ($leaveBalances as $balance) {
  $totalAvailableLeave += max(0, (float)$balance);
}
$pendingRequests = [];
$pendingRequestsCount = 0;
try {
  $stmt = $pdo->prepare("SELECT id, leave_type, start_date, end_date, total_days, status, created_at FROM leave_requests WHERE employee_id = :eid AND status = 'pending' ORDER BY created_at DESC LIMIT 10");
  $stmt->execute([':eid' => (int)$emp['id']]);
  $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $pendingRequestsCount = count($pendingRequests);
  if ($pendingRequestsCount < 10) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = :eid AND status = 'pending'");
    $countStmt->execute([':eid' => (int)$emp['id']]);
    $pendingRequestsCount = (int)($countStmt->fetchColumn() ?: $pendingRequestsCount);
  }
} catch (Throwable $e) {
  $pendingRequests = [];
  $pendingRequestsCount = 0;
}
$recentRequests = [];
try {
  $stmt = $pdo->prepare("SELECT id, leave_type, start_date, end_date, total_days, status, created_at FROM leave_requests WHERE employee_id = :eid ORDER BY created_at DESC LIMIT 10");
  $stmt->execute([':eid' => (int)$emp['id']]);
  $recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $recentRequests = [];
}
$latestRequest = $recentRequests[0] ?? null;
$departmentId = (int)($emp['department_id'] ?? 0);
$departmentName = '';
try {
  if ($departmentId > 0) {
    $stmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $departmentId]);
    $departmentName = (string)($stmt->fetchColumn() ?: '');
  }
} catch (Throwable $e) {
  $departmentName = '';
}
$recentMemos = [];
try {
  $stmt = $pdo->prepare("SELECT d.id, d.title, d.file_path, d.created_at
                          FROM documents d
                          LEFT JOIN document_assignments da ON da.document_id = d.id
                          WHERE d.doc_type = 'memo'
                            AND (
                              da.employee_id = :eid
                              OR da.department_id = :dept
                              OR (da.employee_id IS NULL AND da.department_id IS NULL)
                            )
                          GROUP BY d.id, d.title, d.file_path, d.created_at
                          ORDER BY d.id DESC, d.created_at DESC
                          LIMIT 5");
  $stmt->execute([
    ':eid' => (int)$emp['id'],
    ':dept' => $departmentId,
  ]);
  $recentMemos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $recentMemos = [];
}
$maxAttachmentSize = 10 * 1024 * 1024; // 10 MB per file
$maxAttachments = 5;
$allowedAttachmentExtensions = ['pdf','jpg','jpeg','png'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
  $leave_type = $_POST['leave_type'] ?? '';
  $start_date = $_POST['start_date'] ?? '';
  $end_date = $_POST['end_date'] ?? '';
  $remarks = trim($_POST['remarks'] ?? '');
  // Basic validation
  if (!in_array($leave_type, $knownLeaveTypes, true)) { $errors[] = 'Invalid leave type.'; }
  if (!$start_date || !$end_date) { $errors[] = 'Start and end dates are required.'; }
  else if ($end_date < $start_date) { $errors[] = 'End date cannot be before start date.'; }
  // Compute days (inclusive)
  $days = 0;
  if (!$errors) {
    $sd = DateTime::createFromFormat('Y-m-d', $start_date);
    $ed = DateTime::createFromFormat('Y-m-d', $end_date);
    if (!$sd || !$ed) { $errors[] = 'Invalid date format.'; }
    else {
      $days = (int)$sd->diff($ed)->format('%a') + 1;
    }
  }
  // Optional: check overlap with existing approved/pending
  if (!$errors) {
    $sql = "SELECT COUNT(*) FROM leave_requests WHERE employee_id = :eid AND status IN ('pending','approved') AND NOT (end_date < :start OR start_date > :end)";
    $st = $pdo->prepare($sql);
    $st->execute([':eid'=>$emp['id'], ':start'=>$start_date, ':end'=>$end_date]);
    $cnt = (int)$st->fetchColumn();
    if ($cnt > 0) { $errors[] = 'Overlaps with an existing leave request.'; }
  }

  // Check advance notice requirement for this leave type
  if (!$errors && $leave_type && $start_date) {
    try {
      // Use cached policies if available, otherwise fetch
      if (!isset($leavePolicies[$leave_type])) {
        $policyStmt = $pdo->prepare("SELECT require_advance_notice, advance_notice_days FROM leave_filing_policies WHERE leave_type = :lt AND is_active = TRUE LIMIT 1");
        $policyStmt->execute([':lt' => $leave_type]);
        $policy = $policyStmt->fetch(PDO::FETCH_ASSOC);
        if ($policy) {
          $leavePolicies[$leave_type] = [
            'require_advance_notice' => ($policy['require_advance_notice'] === 't' || $policy['require_advance_notice'] === true),
            'advance_notice_days' => (int)($policy['advance_notice_days'] ?? 0)
          ];
        }
      }
      
      $policy = $leavePolicies[$leave_type] ?? null;
      if ($policy && $policy['require_advance_notice']) {
        $noticeDays = max(0, (int)$policy['advance_notice_days']);
        if ($noticeDays > 0) {
          $today = new DateTime();
          $today->setTime(0, 0, 0); // Reset to start of day for accurate comparison
          $startDateObj = DateTime::createFromFormat('Y-m-d', $start_date);
          
          if ($startDateObj) {
            $startDateObj->setTime(0, 0, 0);
            $interval = $today->diff($startDateObj);
            $daysDifference = (int)$interval->format('%r%a'); // %r gives sign, %a gives days
            
            if ($daysDifference < $noticeDays) {
              $leaveTypeLabel = leave_label_for_type($leave_type);
              $daysRemaining = max(0, $daysDifference);
              $errors[] = $leaveTypeLabel . ' requires at least ' . $noticeDays . ' day' . ($noticeDays !== 1 ? 's' : '') . ' advance notice. Your leave starts in ' . $daysRemaining . ' day' . ($daysRemaining !== 1 ? 's' : '') . '. Please select a start date at least ' . $noticeDays . ' days from today.';
            }
          }
        }
      }
    } catch (Throwable $e) {
      // Log but don't block if policy check fails
      sys_log('LEAVE_POLICY_CHECK_FAILED', 'Failed to check leave filing policy: ' . $e->getMessage(), [
        'module' => 'leave',
        'file' => __FILE__,
        'line' => __LINE__,
        'leave_type' => $leave_type,
      ]);
    }
  }

  $attachmentEntries = [];
  if (!$errors) {
    $files = $_FILES['attachment_files'] ?? null;
    $typePosts = isset($_POST['document_type']) && is_array($_POST['document_type']) ? $_POST['document_type'] : [];
    $titlePosts = isset($_POST['attachment_title']) && is_array($_POST['attachment_title']) ? $_POST['attachment_title'] : [];
    $descPosts = isset($_POST['attachment_description']) && is_array($_POST['attachment_description']) ? $_POST['attachment_description'] : [];
    $fileCount = (is_array($files) && isset($files['name']) && is_array($files['name'])) ? count($files['name']) : 0;
    for ($i = 0; $i < $fileCount; $i++) {
      $errorCode = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
      $rawType = trim((string)($typePosts[$i] ?? ''));
      $rawTitle = trim((string)($titlePosts[$i] ?? ''));
      $rawDescription = trim((string)($descPosts[$i] ?? ''));
      $hasMetadata = ($rawType !== '' || $rawTitle !== '' || $rawDescription !== '');
      if ($errorCode === UPLOAD_ERR_NO_FILE) {
        if ($hasMetadata) {
          $errors[] = 'Attachment ' . ($i + 1) . ' metadata provided but no file uploaded.';
        }
        continue;
      }
      if ($errorCode !== UPLOAD_ERR_OK) {
        $errors[] = 'Attachment ' . ($i + 1) . ' failed to upload (code ' . $errorCode . ').';
        continue;
      }
      if ($rawType === '' || $rawTitle === '') {
        $errors[] = 'Attachment ' . ($i + 1) . ' needs both a document type and title.';
        continue;
      }
      $size = (int)($files['size'][$i] ?? 0);
      if ($size <= 0) {
        $errors[] = 'Attachment ' . ($i + 1) . ' appears to be empty.';
        continue;
      }
      if ($size > $maxAttachmentSize) {
        $errors[] = 'Attachment ' . ($i + 1) . ' exceeds the 10 MB limit.';
        continue;
      }
      $extension = strtolower((string)pathinfo($files['name'][$i] ?? '', PATHINFO_EXTENSION));
      if ($extension !== '' && !in_array($extension, $allowedAttachmentExtensions, true)) {
        $errors[] = 'Attachment ' . ($i + 1) . ' must be a PDF or image (PNG/JPG/JPEG).';
        continue;
      }
      
      // M-05 fix matching: Actual content type validation
      if ($extension !== '') {
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
      }
      $attachmentEntries[] = [
        'tmp_name' => $files['tmp_name'][$i],
        'original_name' => $files['name'][$i] ?? ('attachment_' . ($i + 1)),
        'document_type' => substr(preg_replace('/[^A-Za-z0-9 _-]/', '', $rawType), 0, 50),
        'title' => substr($rawTitle, 0, 150),
        'description' => $rawDescription,
        'size' => $size,
        'extension' => $extension,
      ];
      if (count($attachmentEntries) >= $maxAttachments) {
        break;
      }
    }
    if (!$errors && $fileCount > $maxAttachments) {
      $errors[] = 'A maximum of ' . $maxAttachments . ' attachments can be uploaded per leave request.';
    }
  }

  if (!$errors) {
    $storedPaths = [];
    try {
      $pdo->beginTransaction();
      $daysDec = number_format($days, 2, '.', '');
      $insertSql = 'INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, remarks) VALUES (:eid, :type, :start, :end, :days, :remarks)';
      if ($isPgsql) {
        $insertSql .= ' RETURNING id';
      }
      $insertParams = [
        ':eid' => $emp['id'],
        ':type' => $leave_type,
        ':start' => $start_date,
        ':end' => $end_date,
        ':days' => $daysDec,
        ':remarks' => $remarks,
      ];
      $ins = $pdo->prepare($insertSql);
      $ins->execute($insertParams);
      $newId = $isPgsql ? (int)$ins->fetchColumn() : (int)$pdo->lastInsertId();
      if ($newId <= 0) {
        throw new RuntimeException('Unable to determine leave request id.');
      }

      if ($attachmentEntries) {
        $uploadDir = realpath(__DIR__ . '/../../assets/uploads');
        if ($uploadDir === false) {
          $uploadDir = __DIR__ . '/../../assets/uploads';
        }
        $leaveDir = $uploadDir . DIRECTORY_SEPARATOR . 'leave';
        if (!is_dir($leaveDir)) {
          @mkdir($leaveDir, 0775, true);
        }
        $relativeBase = 'assets/uploads/leave';
  $attStmt = $pdo->prepare('INSERT INTO leave_request_attachments (leave_request_id, document_type, title, description, file_path, original_name, file_size, uploaded_by) VALUES (:lr, :type, :title, :desc, :path, :orig, :size, :by)');
        foreach ($attachmentEntries as $entry) {
          $safeBase = sanitize_file_name(pathinfo($entry['original_name'], PATHINFO_FILENAME));
          $safeBase = $safeBase !== '' ? $safeBase : 'attachment';
          $suffix = bin2hex(random_bytes(4));
          $fileName = $safeBase . '_' . $newId . '_' . $suffix;
          if ($entry['extension'] !== '') {
            $fileName .= '.' . $entry['extension'];
          }
          $relativePath = $relativeBase . '/' . $fileName;
          $absolutePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relativePath;
          $destDir = dirname($absolutePath);
          if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
          }
          if (!move_uploaded_file($entry['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Failed to store attachment file.');
          }
          $storedPaths[] = $absolutePath;
          $attStmt->execute([
            ':lr' => $newId,
            ':type' => $entry['document_type'],
            ':title' => $entry['title'],
            ':desc' => $entry['description'] !== '' ? $entry['description'] : null,
            ':path' => $relativePath,
            ':orig' => $entry['original_name'],
            ':size' => $entry['size'],
            ':by' => $uid,
          ]);
        }
      }

      $pdo->commit();

      $auditPayload = ['employee_id'=>$emp['id'],'leave_type'=>$leave_type,'start_date'=>$start_date,'end_date'=>$end_date,'days'=>$daysDec];
      if ($attachmentEntries) {
        $auditPayload['attachments'] = array_map(static function($row){
          return ['name' => $row['original_name'], 'type' => $row['document_type']];
        }, $attachmentEntries);
      }
      action_log('leave', 'leave_request_filed', 'success', ['leave_request_id' => $newId, 'attachments' => count($attachmentEntries)]);
      audit('leave_filed', json_encode($auditPayload));
      flash_success('Leave request submitted');
      header('Location: ' . BASE_URL . '/modules/leave/view?id=' . $newId);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if (!empty($storedPaths)) {
        foreach ($storedPaths as $path) {
          @unlink($path);
        }
      }
      sys_log('LEAVE1001', 'Insert leave request failed - ' . $e->getMessage(), ['module'=>'leave','file'=>__FILE__,'line'=>__LINE__]);
      $errors[] = 'Could not submit request.';
    }
  }
}

$attachmentDrafts = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
  $typePosts = isset($_POST['document_type']) && is_array($_POST['document_type']) ? $_POST['document_type'] : [];
  $titlePosts = isset($_POST['attachment_title']) && is_array($_POST['attachment_title']) ? $_POST['attachment_title'] : [];
  $descPosts = isset($_POST['attachment_description']) && is_array($_POST['attachment_description']) ? $_POST['attachment_description'] : [];
  $draftCount = max(count($typePosts), count($titlePosts), count($descPosts));
  for ($i = 0; $i < $draftCount; $i++) {
    $attachmentDrafts[] = [
      'document_type' => trim((string)($typePosts[$i] ?? '')),
      'title' => trim((string)($titlePosts[$i] ?? '')),
      'description' => trim((string)($descPosts[$i] ?? '')),
    ];
  }
}
if (!$attachmentDrafts) {
  $attachmentDrafts[] = ['document_type' => '', 'title' => '', 'description' => ''];
}

$todayDisplay = date('l, F j, Y');
$employeeDisplayName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
$employeeCodeDisplay = $emp['employee_code'] ?? '—';
$latestFiledDisplay = ($latestRequest && !empty($latestRequest['created_at'])) ? format_datetime_display($latestRequest['created_at'], false, '') : '';
$latestStatusLabel = $latestRequest ? ucfirst((string)$latestRequest['status']) : '—';

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="space-y-6">
  <section class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-emerald-500 via-teal-500 to-indigo-600 p-6 text-white shadow-lg">
    <div class="absolute inset-y-0 right-0 hidden w-72 translate-x-12 rotate-6 rounded-full border border-white/20 opacity-20 md:block"></div>
    <div class="relative z-10 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div class="max-w-2xl">
        <p class="text-sm uppercase tracking-widest text-white/80">Today • <?= htmlspecialchars($todayDisplay) ?></p>
        <h1 class="mt-1 text-2xl font-semibold md:text-3xl">Plan your time off, <?= htmlspecialchars($employeeDisplayName ?: 'there') ?>.</h1>
        <p class="mt-2 text-sm text-white/75">Submit a leave request, keep an eye on approvals, and attach everything HR needs without leaving this page.</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <a class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-medium text-emerald-700 transition hover:bg-emerald-100" href="#leaveForm">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-emerald-700"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg></span>
            New Leave Request
          </a>
          <a class="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/25" href="<?= BASE_URL ?>/modules/leave/index">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/20"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg></span>
            View History
          </a>
        </div>
      </div>
      <div class="grid w-full max-w-xs gap-3 rounded-2xl bg-white/10 p-4 text-sm md:text-base">
        <div class="flex items-center justify-between text-white">
          <span class="text-white/80">Employee ID</span>
          <span class="font-semibold">#<?= htmlspecialchars($employeeCodeDisplay) ?></span>
        </div>
        <div class="flex items-center justify-between text-white">
          <span class="text-white/80">Department</span>
          <span class="font-semibold"><?= htmlspecialchars($departmentName ?: 'Unassigned') ?></span>
        </div>
        <div class="flex items-center justify-between text-white">
          <span class="text-white/80">Available Leave</span>
          <span class="font-semibold"><?= number_format($totalAvailableLeave, 2) ?> day(s)</span>
        </div>
        <div class="flex items-center justify-between text-white">
          <span class="text-white/80">Pending Requests</span>
          <span class="font-semibold"><?= (int)$pendingRequestsCount ?></span>
        </div>
      </div>
    </div>
  </section>

  <?php if ($errors): ?>
    <div class="space-y-2">
      <?php foreach ($errors as $e): ?>
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 shadow-sm"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-base font-semibold text-slate-800">Leave Balances</h2>
        <span class="text-xs text-slate-500">As of <?= htmlspecialchars(date('M d, Y')) ?></span>
      </div>
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <?php foreach ($leaveEntitlements as $type => $total):
          $remaining = (float)($leaveBalances[$type] ?? 0);
          $used = max(0.0, (float)$total - $remaining);
          $percentUsed = $total > 0 ? min(100, round(($used / (float)$total) * 100)) : 0;
        ?>
          <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
            <div class="flex items-start justify-between">
              <div>
                <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars(leave_label_for_type($type)) ?></p>
                <p class="text-xs text-slate-500">Entitled <?= number_format((float)$total, 2) ?> • Remaining <?= number_format($remaining, 2) ?></p>
              </div>
              <span class="text-xs font-semibold text-indigo-600"><?= $percentUsed ?>% used</span>
            </div>
            <div class="mt-3 h-2 rounded-full bg-slate-200">
              <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-purple-500" style="width: <?= $total > 0 ? $percentUsed : 0 ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="space-y-4">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-base font-semibold text-slate-800">Latest Request</h3>
            <p class="text-xs text-slate-500">Quick snapshot of your most recent filing.</p>
          </div>
          <?php if ($latestRequest): ?>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"><?= htmlspecialchars($latestStatusLabel) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($latestRequest): ?>
          <div class="mt-3 space-y-1 text-sm text-slate-700">
            <div class="flex justify-between"><span>Type</span><span class="font-medium"><?= htmlspecialchars(leave_label_for_type($latestRequest['leave_type'])) ?></span></div>
            <?php if ($latestFiledDisplay !== ''): ?>
              <div class="flex justify-between"><span>Filed</span><span><?= htmlspecialchars($latestFiledDisplay) ?></span></div>
            <?php endif; ?>
            <div class="flex justify-between"><span>Coverage</span><span><?= htmlspecialchars($latestRequest['start_date']) ?> → <?= htmlspecialchars($latestRequest['end_date']) ?></span></div>
          </div>
          <div class="mt-4 text-right text-xs">
            <a class="font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/view?id=<?= (int)$latestRequest['id'] ?>">View details →</a>
          </div>
        <?php else: ?>
          <p class="mt-3 text-sm text-slate-600">You haven’t filed any leave yet. Complete the form below to get started.</p>
        <?php endif; ?>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-800">Pending Requests</h3>
        <p class="text-xs text-slate-500">Stay on top of approvals in progress.</p>
        <div class="mt-3 space-y-2 text-sm">
          <?php if ($pendingRequests): ?>
            <?php foreach ($pendingRequests as $req): ?>
              <div class="rounded-lg border border-indigo-100 bg-indigo-50/60 p-3">
                <div class="flex items-center justify-between">
                  <span class="font-medium text-indigo-900"><?= htmlspecialchars(leave_label_for_type($req['leave_type'])) ?></span>
                  <span class="text-[11px] font-semibold uppercase tracking-wide text-indigo-600">Pending</span>
                </div>
                <p class="mt-1 text-xs text-indigo-800"><?= htmlspecialchars(date('M d, Y', strtotime($req['start_date']))) ?> → <?= htmlspecialchars(date('M d, Y', strtotime($req['end_date']))) ?> • <?= number_format((float)$req['total_days'], 2) ?> day(s)</p>
                <a class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/view?id=<?= (int)$req['id'] ?>">View request →</a>
              </div>
            <?php endforeach; ?>
            <?php if ($pendingRequestsCount > count($pendingRequests)): ?>
              <p class="text-xs text-slate-500">Only the latest <?= (int)count($pendingRequests) ?> displayed.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No pending requests right now.</p>
          <?php endif; ?>
        </div>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-800">Recent Memos</h3>
        <p class="text-xs text-slate-500">Important policy updates and reminders.</p>
        <div class="mt-3 space-y-2 text-sm">
          <?php if ($recentMemos): ?>
            <?php foreach ($recentMemos as $memo): ?>
              <a class="block rounded-lg border border-slate-200 bg-slate-50/60 p-3 transition hover:border-indigo-200 hover:bg-indigo-50" href="<?= BASE_URL ?>/<?= htmlspecialchars(ltrim($memo['file_path'], '/')) ?>" target="_blank" rel="noopener">
                <div class="flex items-center justify-between">
                  <span class="font-medium text-slate-800"><?= htmlspecialchars($memo['title']) ?></span>
                  <?php if (!empty($memo['created_at'])): ?>
                    <span class="text-xs text-slate-500"><?= htmlspecialchars(date('M d, Y', strtotime($memo['created_at']))) ?></span>
                  <?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
            <div class="text-right text-xs">
              <a class="font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/documents/index">Browse all memos →</a>
            </div>
          <?php else: ?>
            <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No memos assigned to you yet.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <div class="grid gap-6 lg:grid-cols-3">
    <form id="leaveForm" method="post" enctype="multipart/form-data" class="lg:col-span-2 space-y-6 rounded-xl border border-slate-100 bg-white p-6 shadow-sm">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-lg font-semibold text-slate-800">File a Leave Request</h2>
        <span class="text-xs text-slate-500">Your approvers will be notified instantly.</span>
      </div>

      <?php
        // Show policy reminders if any leave types have advance notice requirements
        $policiesWithNotice = [];
        foreach ($leavePolicies as $lt => $pol) {
          if ($pol['require_advance_notice'] && $pol['advance_notice_days'] > 0) {
            $policiesWithNotice[$lt] = $pol;
          }
        }
        if ($policiesWithNotice):
      ?>
      <div class="rounded-lg border-l-4 border-blue-500 bg-blue-50 p-4">
        <div class="flex gap-3">
          <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <div class="text-sm">
            <p class="font-medium text-blue-900 mb-2 flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg> Advance Notice Requirements</p>
            <ul class="space-y-1 text-blue-800">
              <?php foreach ($policiesWithNotice as $lt => $pol): ?>
                <li>
                  <strong><?= htmlspecialchars(leave_label_for_type($lt)) ?>:</strong> Must be filed at least 
                  <strong><?= (int)$pol['advance_notice_days'] ?> day<?= $pol['advance_notice_days'] !== 1 ? 's' : '' ?></strong> before the leave start date
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="text-xs text-blue-700 mt-2 flex items-center gap-1"><svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/></svg> <em>Emergency and sick leaves typically don't have advance notice requirements.</em></p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="grid gap-4 md:grid-cols-2">
        <label class="block text-sm">
          <span class="text-xs uppercase tracking-wide text-slate-500">Employee</span>
          <span class="mt-1 block rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-medium text-slate-800"><?= htmlspecialchars($emp['employee_code'] . ' — ' . $emp['last_name'] . ', ' . $emp['first_name']) ?></span>
        </label>
        <label class="block text-sm">
          <span class="text-xs uppercase tracking-wide text-slate-500">Leave Type</span>
          <select name="leave_type" id="leaveTypeSelect" class="input-text mt-1 w-full" required onchange="updateLeaveTypeHint()">
            <option value="">Select…</option>
            <?php foreach ($knownLeaveTypes as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>" 
                      data-notice-required="<?= isset($leavePolicies[$t]) && $leavePolicies[$t]['require_advance_notice'] ? '1' : '0' ?>" 
                      data-notice-days="<?= isset($leavePolicies[$t]) ? (int)$leavePolicies[$t]['advance_notice_days'] : '0' ?>" 
                      <?= (($_POST['leave_type'] ?? '') === $t) ? 'selected' : '' ?>>
                <?= htmlspecialchars(leave_label_for_type($t)) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div id="leaveTypeHint" class="mt-1 text-xs hidden">
            <span class="inline-flex items-center gap-1 text-amber-700 bg-amber-50 px-2 py-1 rounded">
              <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
              </svg>
              <span id="leaveTypeHintText"></span>
            </span>
          </div>
        </label>
      </div>

      <div class="grid gap-4 md:grid-cols-2">
        <label class="block text-sm">
          <span class="text-xs uppercase tracking-wide text-slate-500">Start Date</span>
          <input type="date" name="start_date" class="input-text mt-1 w-full" value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>" required>
        </label>
        <label class="block text-sm">
          <span class="text-xs uppercase tracking-wide text-slate-500">End Date</span>
          <input type="date" name="end_date" class="input-text mt-1 w-full" value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>" required>
        </label>
      </div>

      <label class="block text-sm">
        <span class="text-xs uppercase tracking-wide text-slate-500">Remarks</span>
        <textarea name="remarks" class="input-text mt-1 w-full" rows="4" placeholder="Provide context, coverage plans, or contact details (optional)"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
      </label>

      <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50/50 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h3 class="text-sm font-medium text-slate-800">Supporting Documents</h3>
            <p class="text-xs text-slate-500">Accepted: PDF, PNG, JPG, JPEG • Up to <?= (int)$maxAttachments ?> file(s), max 10 MB each.</p>
          </div>
          <button type="button" class="btn btn-outline text-xs" id="add-attachment">Add Document</button>
        </div>
        <div class="mt-4 space-y-3" id="attachment-list">
          <?php foreach ($attachmentDrafts as $draft): ?>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" data-attachment-row>
              <div class="grid gap-3 md:grid-cols-3">
                <label class="block text-xs font-medium text-slate-600">
                  Document Type
                  <input type="text" name="document_type[]" class="input-text mt-1 w-full" value="<?= htmlspecialchars($draft['document_type']) ?>" placeholder="e.g., Medical Certificate">
                </label>
                <label class="block text-xs font-medium text-slate-600">
                  Title
                  <input type="text" name="attachment_title[]" class="input-text mt-1 w-full" value="<?= htmlspecialchars($draft['title']) ?>" placeholder="Brief title">
                </label>
                <label class="block text-xs font-medium text-slate-600">
                  Upload File
                  <input type="file" name="attachment_files[]" class="input-text mt-1 w-full" accept=".pdf,.png,.jpg,.jpeg">
                </label>
              </div>
              <label class="mt-3 block text-xs font-medium text-slate-600">
                Description
                <textarea name="attachment_description[]" class="input-text mt-1 w-full" rows="2" placeholder="Optional notes for approvers"><?= htmlspecialchars($draft['description']) ?></textarea>
              </label>
              <div class="mt-3 flex justify-end">
                <button type="button" class="btn btn-outline text-xs" data-attachment-remove>Remove</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <template id="attachment-template">
          <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" data-attachment-row>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="block text-xs font-medium text-slate-600">
                Document Type
                <input type="text" name="document_type[]" class="input-text mt-1 w-full" placeholder="e.g., Medical Certificate">
              </label>
              <label class="block text-xs font-medium text-slate-600">
                Title
                <input type="text" name="attachment_title[]" class="input-text mt-1 w-full" placeholder="Brief title">
              </label>
              <label class="block text-xs font-medium text-slate-600">
                Upload File
                <input type="file" name="attachment_files[]" class="input-text mt-1 w-full" accept=".pdf,.png,.jpg,.jpeg">
              </label>
            </div>
            <label class="mt-3 block text-xs font-medium text-slate-600">
              Description
              <textarea name="attachment_description[]" class="input-text mt-1 w-full" rows="2" placeholder="Optional notes for approvers"></textarea>
            </label>
            <div class="mt-3 flex justify-end">
              <button type="button" class="btn btn-outline text-xs" data-attachment-remove>Remove</button>
            </div>
          </div>
        </template>
      </div>

      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
        <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/leave/index">Cancel</a>
        <button class="btn btn-primary" type="submit">Submit Request</button>
      </div>
    </form>

    <div class="space-y-4">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-800">What happens next?</h3>
        <ul class="mt-3 space-y-2 text-sm text-slate-700">
          <li class="flex gap-2"><span class="mt-1 h-2 w-2 rounded-full bg-emerald-500"></span><span>Your request is logged and routed to the designated approvers.</span></li>
          <li class="flex gap-2"><span class="mt-1 h-2 w-2 rounded-full bg-emerald-500"></span><span>Upload supporting documents to avoid delays or follow-up emails.</span></li>
          <li class="flex gap-2"><span class="mt-1 h-2 w-2 rounded-full bg-emerald-500"></span><span>Track approval progress from the leave history page anytime.</span></li>
        </ul>
      </div>
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-800">
        <h4 class="text-base font-semibold">Need help?</h4>
        <p class="mt-2">Reach out to HR or your department lead if you need to adjust an existing request or if an urgent leave isn’t reflected here.</p>
      </div>
    </div>
  </div>
</div>

<script>
// Dynamic leave type policy hint
function updateLeaveTypeHint() {
  const select = document.getElementById('leaveTypeSelect');
  const hint = document.getElementById('leaveTypeHint');
  const hintText = document.getElementById('leaveTypeHintText');
  
  if (!select || !hint || !hintText) return;
  
  const selectedOption = select.options[select.selectedIndex];
  const noticeRequired = selectedOption.getAttribute('data-notice-required') === '1';
  const noticeDays = parseInt(selectedOption.getAttribute('data-notice-days') || '0', 10);
  
  if (noticeRequired && noticeDays > 0) {
    hintText.textContent = `This leave type requires ${noticeDays} day${noticeDays !== 1 ? 's' : ''} advance notice`;
    hint.classList.remove('hidden');
  } else {
    hint.classList.add('hidden');
  }
}

// Run on page load if a leave type is pre-selected
document.addEventListener('DOMContentLoaded', function() {
  updateLeaveTypeHint();
});

// Attachment controls
(function() {
  const addBtn = document.getElementById('add-attachment');
  const list = document.getElementById('attachment-list');
  const template = document.getElementById('attachment-template');
  const maxAttachments = <?= (int)$maxAttachments ?>;
  if (!addBtn || !list || !template) { return; }

  function rowCount() {
    return list.querySelectorAll('[data-attachment-row]').length;
  }

  function updateAddState() {
    if (rowCount() >= maxAttachments) {
      addBtn.setAttribute('disabled', 'disabled');
    } else {
      addBtn.removeAttribute('disabled');
    }
  }

  function addRow() {
    if (rowCount() >= maxAttachments) { return; }
    const clone = template.content.firstElementChild.cloneNode(true);
    list.appendChild(clone);
    const focusTarget = clone.querySelector('input,textarea');
    if (focusTarget) { focusTarget.focus(); }
    updateAddState();
  }

  addBtn.addEventListener('click', function(){
    addRow();
  });

  list.addEventListener('click', function(event){
    const btn = event.target.closest('[data-attachment-remove]');
    if (!btn) { return; }
    const row = btn.closest('[data-attachment-row]');
    if (!row) { return; }
    row.remove();
    if (!rowCount()) {
      addRow();
    }
    updateAddState();
  });

  updateAddState();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
