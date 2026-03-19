<?php
/**
 * Privacy & Consent Management — Employee Self-Service
 * RA 10173 Data Privacy Act compliance
 * View privacy notice, manage consents, request data erasure
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);

// Define consent types
$consentTypes = [
    'data_processing' => [
        'label' => 'Data Processing Consent',
        'description' => 'I consent to the collection and processing of my personal information for employment purposes, including payroll, benefits administration, and statutory compliance as required by Philippine law.',
        'required' => true,
    ],
    'data_sharing' => [
        'label' => 'Data Sharing Consent',
        'description' => 'I consent to the sharing of my personal data with government agencies (BIR, SSS, PhilHealth, Pag-IBIG) as required by law, and with authorized third-party service providers.',
        'required' => true,
    ],
    'data_retention' => [
        'label' => 'Data Retention Consent',
        'description' => 'I acknowledge that my employment records may be retained for a period required by law (typically 10 years for tax records) even after separation from the company.',
        'required' => false,
    ],
    'marketing_comms' => [
        'label' => 'Internal Communications',
        'description' => 'I consent to receiving company memos, announcements, and non-essential notifications through the HRIS system.',
        'required' => false,
    ],
];

// Handle POST — update consents or request erasure
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'update_consents') {
        $consented = $_POST['consents'] ?? [];

        foreach ($consentTypes as $type => $info) {
            $isConsented = in_array($type, $consented, true);

            try {
                // Upsert consent
                $stmt = $pdo->prepare("
                    INSERT INTO privacy_consents (user_id, consent_type, consented, consented_at, ip_address, user_agent, version)
                    VALUES (:uid, :type, :consented, :at, :ip, :ua, 1)
                    ON CONFLICT (user_id, consent_type)
                    DO UPDATE SET
                        consented = EXCLUDED.consented,
                        consented_at = CASE WHEN EXCLUDED.consented THEN NOW() ELSE privacy_consents.consented_at END,
                        withdrawn_at = CASE WHEN NOT EXCLUDED.consented THEN NOW() ELSE NULL END,
                        ip_address = EXCLUDED.ip_address,
                        user_agent = EXCLUDED.user_agent,
                        updated_at = NOW()
                ");
                $stmt->execute([
                    ':uid' => $uid,
                    ':type' => $type,
                    ':consented' => $isConsented ? 'true' : 'false',
                    ':at' => $isConsented ? date('Y-m-d H:i:s') : null,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                ]);
            } catch (Throwable $e) {
                sys_log('PRIVACY-CONSENT', 'Consent update failed: ' . $e->getMessage(), ['module' => 'compliance']);
            }
        }

        action_log('compliance', 'consent_update', 'success', [
            'user_id' => $uid,
            'consents' => $consented,
        ]);

        flash_success('Your privacy preferences have been updated.');
        header('Location: ' . BASE_URL . '/modules/compliance/privacy/consent');
        exit;
    }

    if ($action === 'request_erasure') {
        $reason = trim($_POST['erasure_reason'] ?? '');
        $scope = $_POST['erasure_scope'] ?? 'partial';

        if (!$reason) {
            flash_error('Please provide a reason for the data erasure request.');
        } else {
            // Get employee ID
            $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = :uid LIMIT 1");
            $empStmt->execute([':uid' => $uid]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

            if ($emp) {
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO data_erasure_requests (employee_id, requested_by, scope, reason, status)
                        VALUES (:eid, :uid, :scope, :reason, 'pending')
                    ");
                    $ins->execute([
                        ':eid' => (int)$emp['id'],
                        ':uid' => $uid,
                        ':scope' => $scope,
                        ':reason' => $reason,
                    ]);

                    action_log('compliance', 'erasure_request', 'success', [
                        'employee_id' => (int)$emp['id'],
                        'scope' => $scope,
                    ]);

                    flash_success('Data erasure request submitted. An administrator will review your request.');
                } catch (Throwable $e) {
                    sys_log('PRIVACY-ERASURE', 'Erasure request failed: ' . $e->getMessage(), ['module' => 'compliance']);
                    flash_error('Failed to submit erasure request. Please try again.');
                }
            } else {
                flash_error('No employee record found.');
            }
        }

        header('Location: ' . BASE_URL . '/modules/compliance/privacy/consent');
        exit;
    }
}

// Fetch current consents
$consentStmt = $pdo->prepare("SELECT * FROM privacy_consents WHERE user_id = :uid");
$consentStmt->execute([':uid' => $uid]);
$existingConsents = [];
foreach ($consentStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $existingConsents[$c['consent_type']] = $c;
}

// Fetch erasure requests
$erasureStmt = $pdo->prepare("
    SELECT der.*, u.full_name AS reviewer_name
    FROM data_erasure_requests der
    LEFT JOIN users u ON u.id = der.reviewed_by
    WHERE der.requested_by = :uid
    ORDER BY der.created_at DESC LIMIT 5
");
$erasureStmt->execute([':uid' => $uid]);
$erasureRequests = $erasureStmt->fetchAll(PDO::FETCH_ASSOC);

$statusBadge = [
    'pending' => 'bg-amber-100 text-amber-700',
    'approved' => 'bg-emerald-100 text-emerald-700',
    'rejected' => 'bg-red-100 text-red-700',
    'executed' => 'bg-blue-100 text-blue-700',
];

$pageTitle = 'Privacy & Consent';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="mb-6">
    <h1 class="text-xl font-bold text-slate-900">Privacy & Consent Management</h1>
    <p class="text-sm text-slate-500 mt-0.5">Manage your data privacy preferences under RA 10173 (Data Privacy Act of 2012)</p>
  </div>

  <!-- Privacy Notice -->
  <div class="card">
    <div class="card-header">
      <span class="flex items-center gap-2">
        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        Privacy Notice
      </span>
    </div>
    <div class="card-body prose prose-sm max-w-none text-slate-700">
      <p>Under Republic Act No. 10173 (Data Privacy Act of 2012), you have the following rights regarding your personal data:</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
        <div class="p-3 bg-slate-50 rounded-lg">
          <h4 class="font-semibold text-slate-900 text-sm">Right to Be Informed</h4>
          <p class="text-xs text-slate-500 mt-1">You have the right to know how your personal data is collected, used, and processed.</p>
        </div>
        <div class="p-3 bg-slate-50 rounded-lg">
          <h4 class="font-semibold text-slate-900 text-sm">Right to Access</h4>
          <p class="text-xs text-slate-500 mt-1">You may request a copy of your personal data at any time.</p>
        </div>
        <div class="p-3 bg-slate-50 rounded-lg">
          <h4 class="font-semibold text-slate-900 text-sm">Right to Rectification</h4>
          <p class="text-xs text-slate-500 mt-1">You may request correction of inaccurate or incomplete personal data.</p>
        </div>
        <div class="p-3 bg-slate-50 rounded-lg">
          <h4 class="font-semibold text-slate-900 text-sm">Right to Erasure</h4>
          <p class="text-xs text-slate-500 mt-1">You may request deletion of your personal data, subject to legal retention requirements.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Consent Management -->
  <div class="card">
    <div class="card-header"><span>Your Consent Preferences</span></div>
    <div class="card-body">
      <form method="post" action="<?= BASE_URL ?>/modules/compliance/privacy/consent">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update_consents">

        <div class="space-y-4">
          <?php foreach ($consentTypes as $type => $info):
            $existing = $existingConsents[$type] ?? null;
            $isConsented = $existing ? (bool)$existing['consented'] : false;
            $consentedAt = $existing && $existing['consented_at'] ? date('M d, Y h:i A', strtotime($existing['consented_at'])) : null;
          ?>
          <div class="flex items-start gap-3 p-4 rounded-lg border <?= $isConsented ? 'border-emerald-200 bg-emerald-50/50' : 'border-slate-200 bg-white' ?>">
            <input type="checkbox" name="consents[]" value="<?= $type ?>" id="consent_<?= $type ?>"
              class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              <?= $isConsented ? 'checked' : '' ?>
              <?= $info['required'] ? 'required' : '' ?>>
            <div class="flex-1">
              <label for="consent_<?= $type ?>" class="font-medium text-sm text-slate-900 cursor-pointer">
                <?= htmlspecialchars($info['label']) ?>
                <?php if ($info['required']): ?>
                  <span class="text-red-500 text-xs ml-1">(Required)</span>
                <?php endif; ?>
              </label>
              <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($info['description']) ?></p>
              <?php if ($consentedAt): ?>
                <p class="text-xs text-emerald-600 mt-1">Consented on <?= $consentedAt ?></p>
              <?php endif; ?>
              <?php if ($existing && $existing['withdrawn_at']): ?>
                <p class="text-xs text-red-600 mt-1">Withdrawn on <?= date('M d, Y h:i A', strtotime($existing['withdrawn_at'])) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="mt-6 flex justify-end">
          <button type="submit" class="btn btn-primary">Save Preferences</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Data Erasure Request -->
  <div class="card">
    <div class="card-header">
      <span class="flex items-center gap-2">
        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        Right to Erasure
      </span>
    </div>
    <div class="card-body">
      <p class="text-sm text-slate-600 mb-4">You may request the deletion or anonymization of your personal data. Note that certain records must be retained as required by law (e.g., tax records for 10 years under BIR regulations).</p>

      <button onclick="document.getElementById('erasureModal').classList.remove('hidden')" class="btn btn-danger text-sm">
        Request Data Erasure
      </button>

      <?php if (!empty($erasureRequests)): ?>
      <div class="mt-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-2">Your Erasure Requests</h4>
        <table class="table-basic">
          <thead>
            <tr>
              <th>Date</th>
              <th>Scope</th>
              <th>Status</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($erasureRequests as $er): ?>
            <tr>
              <td class="text-sm"><?= date('M d, Y', strtotime($er['created_at'])) ?></td>
              <td class="text-sm"><?= ucfirst($er['scope']) ?></td>
              <td>
                <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusBadge[$er['status']] ?? 'bg-slate-100 text-slate-700' ?>">
                  <?= ucfirst($er['status']) ?>
                </span>
              </td>
              <td class="text-sm text-slate-500"><?= htmlspecialchars($er['review_notes'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <a href="<?= BASE_URL ?>/modules/compliance/corrections/index" class="card card-body hover:shadow-md transition-shadow spa">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
          <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        </div>
        <div>
          <h3 class="text-sm font-semibold text-slate-900">Data Corrections</h3>
          <p class="text-xs text-slate-500">Request corrections to your personal data</p>
        </div>
      </div>
    </a>
    <a href="<?= BASE_URL ?>/modules/compliance/data-export" class="card card-body hover:shadow-md transition-shadow spa">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
          <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <div>
          <h3 class="text-sm font-semibold text-slate-900">Download My Data</h3>
          <p class="text-xs text-slate-500">Export your personal data (Right to Access)</p>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- Erasure Request Modal -->
<div id="erasureModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4">
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <h3 class="text-lg font-semibold text-slate-900">Request Data Erasure</h3>
      <button onclick="document.getElementById('erasureModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">&times;</button>
    </div>
    <form method="post" action="<?= BASE_URL ?>/modules/compliance/privacy/consent">
      <div class="px-6 py-4 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="request_erasure">

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
          <p class="text-xs text-amber-800"><strong>Important:</strong> Some data must be retained per Philippine law (BIR tax records — 10 years; SSS/PhilHealth/Pag-IBIG records — as required). Only non-mandatory personal data can be erased.</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 required">Scope</label>
          <select name="erasure_scope" class="input-text mt-1 w-full" required>
            <option value="partial">Partial — Remove non-essential personal data only</option>
            <option value="full">Full — Remove all data (subject to legal retention)</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 required">Reason</label>
          <textarea name="erasure_reason" class="input-text mt-1 w-full" rows="3" required placeholder="Please explain why you are requesting data erasure"></textarea>
        </div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t">
        <button type="button" onclick="document.getElementById('erasureModal').classList.add('hidden')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-danger" data-confirm="Are you sure you want to request data erasure? This action will be reviewed by an administrator.">Submit Erasure Request</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
