<?php
/**
 * Privacy & Compliance Dashboard — Admin
 * Overview of consent rates, erasure requests, and compliance status
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();
require_module_access('compliance', 'privacy_consents', 'read');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);
$canManageErasure = user_has_access($uid, 'compliance', 'data_erasure', 'write');

// Handle POST — approve/reject/execute erasure requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageErasure) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewNotes = trim($_POST['review_notes'] ?? '');

    if ($requestId && in_array($action, ['approve_erasure', 'reject_erasure', 'execute_erasure'], true)) {
        try {
            if ($action === 'approve_erasure') {
                $upd = $pdo->prepare("
                    UPDATE data_erasure_requests
                    SET status = 'approved', reviewed_by = :uid, reviewed_at = NOW(), review_notes = :notes, updated_at = NOW()
                    WHERE id = :id AND status = 'pending'
                ");
                $upd->execute([':uid' => $uid, ':notes' => $reviewNotes, ':id' => $requestId]);
                action_log('compliance', 'erasure_approve', 'success', ['request_id' => $requestId]);
                flash_success('Erasure request approved.');

            } elseif ($action === 'reject_erasure') {
                $upd = $pdo->prepare("
                    UPDATE data_erasure_requests
                    SET status = 'rejected', reviewed_by = :uid, reviewed_at = NOW(), review_notes = :notes, updated_at = NOW()
                    WHERE id = :id AND status = 'pending'
                ");
                $upd->execute([':uid' => $uid, ':notes' => $reviewNotes, ':id' => $requestId]);
                action_log('compliance', 'erasure_reject', 'success', ['request_id' => $requestId]);
                flash_success('Erasure request rejected.');

            } elseif ($action === 'execute_erasure') {
                // Get the request
                $reqStmt = $pdo->prepare("SELECT * FROM data_erasure_requests WHERE id = :id AND status = 'approved'");
                $reqStmt->execute([':id' => $requestId]);
                $ereq = $reqStmt->fetch(PDO::FETCH_ASSOC);

                if ($ereq) {
                    $empId = (int)$ereq['employee_id'];

                    // Anonymize personal data fields
                    $anonymizedFields = [];
                    $fieldsToAnonymize = ['first_name', 'last_name', 'middle_name', 'email', 'phone', 'address', 'city', 'province', 'zip_code'];

                    // Also anonymize encrypted fields
                    $encryptedFields = function_exists('encrypted_employee_fields') ? encrypted_employee_fields() : [];

                    $pdo->beginTransaction();

                    // Anonymize standard fields
                    foreach ($fieldsToAnonymize as $field) {
                        $upd = $pdo->prepare("UPDATE employees SET {$field} = :val WHERE id = :eid");
                        $anonValue = ($field === 'email') ? 'anonymized_' . $empId . '@removed.local' : '[REDACTED]';
                        $upd->execute([':val' => $anonValue, ':eid' => $empId]);
                        $anonymizedFields[] = $field;
                    }

                    // Anonymize encrypted fields
                    foreach ($encryptedFields as $field) {
                        $upd = $pdo->prepare("UPDATE employees SET {$field} = NULL WHERE id = :eid");
                        $upd->execute([':eid' => $empId]);
                        $anonymizedFields[] = $field;
                    }

                    // Mark as executed
                    $exStmt = $pdo->prepare("
                        UPDATE data_erasure_requests
                        SET status = 'executed', executed_at = NOW(), executed_by = :uid,
                            anonymized_fields = :fields, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $exStmt->execute([
                        ':uid' => $uid,
                        ':fields' => json_encode($anonymizedFields),
                        ':id' => $requestId,
                    ]);

                    $pdo->commit();

                    audit('data_erasure_executed', json_encode([
                        'request_id' => $requestId,
                        'employee_id' => $empId,
                        'anonymized_fields' => $anonymizedFields,
                    ]), [
                        'module' => 'compliance',
                        'action_type' => 'delete',
                        'target_type' => 'employee',
                        'target_id' => $empId,
                        'status' => 'success',
                        'severity' => 'critical',
                    ]);

                    flash_success('Data erasure executed. Employee data has been anonymized.');
                } else {
                    flash_error('Erasure request not found or not yet approved.');
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sys_log('COMPLIANCE-ERASURE', 'Erasure action failed: ' . $e->getMessage(), ['module' => 'compliance']);
            flash_error('Failed to process erasure request.');
        }
    }

    header('Location: ' . BASE_URL . '/modules/admin/privacy/index');
    exit;
}

// ---- Stats ----
$totalUsers = 0;
$consentStats = [];
$erasureStats = [];

try {
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
} catch (Throwable $e) {
    // Try without status filter
    try { $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(); } catch (Throwable $e2) {}
}

try {
    $cStmt = $pdo->query("
        SELECT consent_type, 
               COUNT(*) FILTER (WHERE consented = true) AS consented,
               COUNT(*) FILTER (WHERE consented = false) AS withdrawn,
               COUNT(*) AS total
        FROM privacy_consents
        GROUP BY consent_type
    ");
    $consentStats = $cStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
    $eStmt = $pdo->query("
        SELECT status, COUNT(*) AS cnt FROM data_erasure_requests GROUP BY status
    ");
    $erasureStats = array_column($eStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'status');
} catch (Throwable $e) {}

// Consent type labels
$consentLabels = [
    'data_processing' => 'Data Processing',
    'data_sharing' => 'Data Sharing',
    'data_retention' => 'Data Retention',
    'marketing_comms' => 'Internal Communications',
];

// Erasure requests list
$erasureRequests = [];
try {
    $erStmt = $pdo->prepare("
        SELECT der.*, e.first_name, e.last_name, e.employee_code,
               u.username AS requester_name, ru.username AS reviewer_name
        FROM data_erasure_requests der
        JOIN employees e ON e.id = der.employee_id
        LEFT JOIN users u ON u.id = der.requested_by
        LEFT JOIN users ru ON ru.id = der.reviewed_by
        ORDER BY der.created_at DESC LIMIT 20
    ");
    $erStmt->execute();
    $erasureRequests = $erStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$statusBadge = [
    'pending' => 'bg-amber-100 text-amber-700',
    'approved' => 'bg-emerald-100 text-emerald-700',
    'rejected' => 'bg-red-100 text-red-700',
    'executed' => 'bg-blue-100 text-blue-700',
];

$pageTitle = 'Privacy & Compliance';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="mb-6">
    <h1 class="text-xl font-bold text-slate-900">Privacy & Compliance Dashboard</h1>
    <p class="text-sm text-slate-500 mt-0.5">RA 10173 Data Privacy Act compliance — manage consents, erasure requests, and data protection</p>
  </div>

  <!-- Consent Overview -->
  <div class="card">
    <div class="card-header"><span>Consent Compliance Overview</span></div>
    <div class="card-body">
      <?php if (empty($consentStats)): ?>
        <p class="text-sm text-slate-500 py-4 text-center">No consent records yet. Employees will see the consent form in their Privacy & Consent page.</p>
      <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <?php foreach ($consentStats as $cs):
            $label = $consentLabels[$cs['consent_type']] ?? ucfirst(str_replace('_', ' ', $cs['consent_type']));
            $rate = $totalUsers > 0 ? round(((int)$cs['consented'] / $totalUsers) * 100, 1) : 0;
            $barColor = $rate >= 80 ? 'bg-emerald-500' : ($rate >= 50 ? 'bg-amber-500' : 'bg-red-500');
          ?>
          <div class="p-4 rounded-lg border border-slate-200">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-medium text-slate-900"><?= htmlspecialchars($label) ?></span>
              <span class="text-sm font-bold <?= $rate >= 80 ? 'text-emerald-600' : ($rate >= 50 ? 'text-amber-600' : 'text-red-600') ?>"><?= $rate ?>%</span>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-2 mb-2">
              <div class="<?= $barColor ?> h-2 rounded-full transition-all" style="width: <?= min($rate, 100) ?>%"></div>
            </div>
            <div class="flex justify-between text-xs text-slate-500">
              <span><?= (int)$cs['consented'] ?> consented</span>
              <span><?= (int)$cs['withdrawn'] ?> withdrawn</span>
              <span><?= $totalUsers ?> total users</span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Erasure Overview Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <div class="card card-body flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-xl font-bold text-slate-900"><?= (int)($erasureStats['pending'] ?? 0) ?></div>
        <div class="text-xs text-slate-500">Pending Erasures</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
      <div>
        <div class="text-xl font-bold text-slate-900"><?= (int)($erasureStats['approved'] ?? 0) ?></div>
        <div class="text-xs text-slate-500">Awaiting Execution</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-xl font-bold text-slate-900"><?= (int)($erasureStats['executed'] ?? 0) ?></div>
        <div class="text-xs text-slate-500">Executed</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </div>
      <div>
        <div class="text-xl font-bold text-slate-900"><?= (int)($erasureStats['rejected'] ?? 0) ?></div>
        <div class="text-xs text-slate-500">Rejected</div>
      </div>
    </div>
  </div>

  <!-- Erasure Requests -->
  <div class="card">
    <div class="card-header"><span>Data Erasure Requests</span></div>
    <div class="card-body">
      <?php if (empty($erasureRequests)): ?>
        <p class="text-sm text-slate-500 py-8 text-center">No erasure requests submitted yet.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table-basic">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Scope</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Date</th>
                <?php if ($canManageErasure): ?><th>Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($erasureRequests as $er): ?>
              <tr>
                <td>
                  <div class="font-medium text-slate-900"><?= htmlspecialchars(($er['last_name'] ?? '') . ', ' . ($er['first_name'] ?? '')) ?></div>
                  <div class="text-xs text-slate-400"><?= htmlspecialchars($er['employee_code'] ?? '') ?></div>
                </td>
                <td class="text-sm"><?= ucfirst($er['scope']) ?></td>
                <td class="text-sm text-slate-500 max-w-[200px] truncate" title="<?= htmlspecialchars($er['reason'] ?? '') ?>"><?= htmlspecialchars($er['reason'] ?? '') ?></td>
                <td>
                  <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusBadge[$er['status']] ?? 'bg-slate-100 text-slate-700' ?>">
                    <?= ucfirst($er['status']) ?>
                  </span>
                </td>
                <td class="text-sm text-slate-500"><?= date('M d, Y', strtotime($er['created_at'])) ?></td>
                <?php if ($canManageErasure): ?>
                <td>
                  <?php if ($er['status'] === 'pending'): ?>
                    <div class="flex gap-1">
                      <form method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="approve_erasure">
                        <input type="hidden" name="request_id" value="<?= (int)$er['id'] ?>">
                        <input type="hidden" name="review_notes" value="">
                        <button type="submit" class="text-xs text-emerald-600 hover:text-emerald-800 font-medium" data-confirm="Approve this erasure request?">Approve</button>
                      </form>
                      <span class="text-slate-300">|</span>
                      <form method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="reject_erasure">
                        <input type="hidden" name="request_id" value="<?= (int)$er['id'] ?>">
                        <input type="hidden" name="review_notes" value="">
                        <button type="submit" class="text-xs text-red-600 hover:text-red-800 font-medium" data-confirm="Reject this erasure request?">Reject</button>
                      </form>
                    </div>
                  <?php elseif ($er['status'] === 'approved'): ?>
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="execute_erasure">
                      <input type="hidden" name="request_id" value="<?= (int)$er['id'] ?>">
                      <button type="submit" class="text-xs text-blue-600 hover:text-blue-800 font-medium" data-confirm="CAUTION: This will permanently anonymize the employee's personal data. This action cannot be undone. Proceed?">Execute Erasure</button>
                    </form>
                  <?php else: ?>
                    <span class="text-xs text-slate-400">—</span>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Compliance Quick Info -->
  <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
    <div class="flex items-start gap-3">
      <svg class="w-5 h-5 text-indigo-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      <div>
        <h4 class="text-sm font-semibold text-indigo-900">RA 10173 Compliance Checklist</h4>
        <ul class="text-xs text-indigo-700 mt-2 space-y-1">
          <li>&#10003; <strong>Encryption:</strong> PII fields (SSS, PhilHealth, Pag-IBIG, TIN, bank account) encrypted at rest with AES-256-CBC</li>
          <li>&#10003; <strong>Access Control:</strong> Position-based permissions with audit trail for all access</li>
          <li>&#10003; <strong>Audit Logging:</strong> All data access and modifications logged with old/new values</li>
          <li>&#10003; <strong>Right to Access:</strong> Employees can download their personal data via self-service portal</li>
          <li>&#10003; <strong>Right to Rectification:</strong> Data correction requests with admin review workflow</li>
          <li>&#10003; <strong>Right to Erasure:</strong> Data anonymization requests with two-step approval</li>
          <li>&#10003; <strong>Consent Management:</strong> Granular consent tracking with timestamps and IP logging</li>
          <li>&#10003; <strong>BIR Compliance:</strong> Form 2316, 1604-C Alphalist, and Monthly Remittance reports</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
