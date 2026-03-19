<?php
/**
 * Print Server — Printer management, queue monitoring & history
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_module_access('inventory', 'print_server', 'read');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$userBranchId = (int)($_SESSION['user']['branch_id'] ?? 0);
$canWrite = user_has_access($uid, 'inventory', 'print_server', 'write');
$canManage = user_has_access($uid, 'inventory', 'print_server', 'manage');

// ── POST handlers (before header) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { flash_error('Invalid or expired form token.'); header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $action = $_POST['action'] ?? '';

    // Toggle office allocation setting
    if ($action === 'toggle_office_allocation' && $canManage) {
        $newVal = ($_POST['office_allocation'] ?? '') === '1' ? 'true' : 'false';
        try {
            $pdo->prepare("UPDATE print_server_settings SET setting_value = :val, updated_by = :uid, updated_at = NOW() WHERE setting_key = 'office_allocation_enabled'")
                ->execute([':val' => $newVal, ':uid' => $uid]);
            action_log('inventory', 'update', 'success', ['entity' => 'print_server_settings', 'office_allocation_enabled' => $newVal]);
            flash_success('Office-based allocation ' . ($newVal === 'true' ? 'enabled' : 'disabled') . '.');
        } catch (PDOException $e) {
            flash_error('Could not update setting.');
        }
        header('Location: ' . BASE_URL . '/modules/inventory/print_server');
        exit;
    }

    // Cancel print job
    if ($action === 'cancel_job' && $canWrite) {
        $jid = (int)($_POST['job_id'] ?? 0);
        if ($jid) {
            $pdo->prepare("UPDATE print_jobs SET status='cancelled' WHERE id=:id AND status IN ('queued','printing')")->execute([':id'=>$jid]);

            // Log to history
            $job = $pdo->prepare("SELECT pj.*, p.name as printer_name FROM print_jobs pj LEFT JOIN printers p ON p.id = pj.printer_id WHERE pj.id=:id");
            $job->execute([':id'=>$jid]);
            $j = $job->fetch(PDO::FETCH_ASSOC);
            if ($j) {
                $pdo->prepare("INSERT INTO print_history (printer_id, print_job_id, printer_name, document_type, document_ref, document_title, copies, status, created_by, user_name) VALUES (:pid, :jid, :pn, :dt, :dr, :dtl, :c, 'cancelled', :uid, :un)")
                    ->execute([':pid'=>$j['printer_id'], ':jid'=>$jid, ':pn'=>$j['printer_name']??'', ':dt'=>$j['document_type'], ':dr'=>$j['document_ref'], ':dtl'=>$j['document_title'], ':c'=>$j['copies'], ':uid'=>$uid, ':un'=>$_SESSION['user']['name']??'']);
            }
            action_log('inventory', 'cancel', 'success', ['entity'=>'print_job', 'id'=>$jid]);
            flash_success('Print job cancelled.');
        }
        header('Location: ' . BASE_URL . '/modules/inventory/print_server');
        exit;
    }

    // Clear completed jobs
    if ($action === 'clear_completed' && $canManage) {
        $pdo->exec("DELETE FROM print_jobs WHERE status IN ('completed','failed','cancelled')");
        flash_success('Completed/failed jobs cleared.');
        header('Location: ' . BASE_URL . '/modules/inventory/print_server');
        exit;
    }

    header('Location: ' . BASE_URL . '/modules/inventory/print_server');
    exit;
}

// ── Fetch data ──
// Active queue
$queue = $pdo->query("SELECT pj.*, p.name as printer_name FROM print_jobs pj LEFT JOIN printers p ON p.id = pj.printer_id WHERE pj.status IN ('queued','printing') ORDER BY pj.priority ASC, pj.created_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// Recent history
$history = $pdo->query("SELECT ph.*, p.name as current_printer_name FROM print_history ph LEFT JOIN printers p ON p.id = ph.printer_id ORDER BY ph.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$queuedJobs = count($queue);
$todayPrints = $pdo->query("SELECT COUNT(*) FROM print_history WHERE created_at >= CURRENT_DATE")->fetchColumn();

$pageTitle = 'Print Server';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900">Print Server</h1>
    <p class="text-sm text-slate-500 mt-0.5">Manage printers via QZ Tray, monitor print queues, and view history</p>
  </div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
  <div class="card card-body flex items-center gap-3">
    <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0">
      <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
    </div>
    <div>
      <div class="text-2xl font-bold text-slate-900" id="statQzStatus">—</div>
      <div class="text-xs text-slate-500">QZ Tray Status</div>
    </div>
  </div>
  <div class="card card-body flex items-center gap-3">
    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center shrink-0">
      <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div>
      <div class="text-2xl font-bold text-amber-600"><?= $queuedJobs ?></div>
      <div class="text-xs text-slate-500">In Queue</div>
    </div>
  </div>
  <div class="card card-body flex items-center gap-3">
    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center shrink-0">
      <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
    </div>
    <div>
      <div class="text-2xl font-bold text-blue-600"><?= $todayPrints ?></div>
      <div class="text-xs text-slate-500">Printed Today</div>
    </div>
  </div>
</div>

<!-- Tab navigation -->
<div class="border-b mb-4">
  <div class="flex gap-0 -mb-px" id="psTabs">
    <button class="ps-tab active px-4 py-2.5 text-sm font-medium border-b-2 transition" data-tab="printers">
      <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
      Printers
    </button>
    <button class="ps-tab px-4 py-2.5 text-sm font-medium border-b-2 transition" data-tab="queue">Queue <span class="ml-1 px-1.5 py-0.5 text-[10px] rounded-full <?= $queuedJobs > 0 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500' ?>"><?= $queuedJobs ?></span></button>
    <button class="ps-tab px-4 py-2.5 text-sm font-medium border-b-2 transition" data-tab="history">History</button>
  </div>
</div>

<!-- ===== PRINTERS TAB (QZ Tray-based) ===== -->
<div id="tab-printers" class="ps-tab-content">

  <!-- QZ Tray Connection Banner -->
  <div id="qzStatusBanner" class="mb-4 p-4 rounded-xl border bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div class="flex items-center gap-3">
      <div id="qzStatusIcon" class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center shrink-0">
        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
      </div>
      <div>
        <div class="text-sm font-semibold text-slate-900" id="qzStatusTitle">QZ Tray — Not Connected</div>
        <div class="text-xs text-slate-500" id="qzStatusMsg">Click "Connect" to detect printers on this computer via QZ Tray</div>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button type="button" id="qzConnectBtn" onclick="qzConnect()" class="btn btn-primary text-sm">
        <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        Connect
      </button>
      <button type="button" id="qzDisconnectBtn" onclick="qzDisconnect()" class="btn btn-outline text-sm hidden">
        Disconnect
      </button>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Detected Printers (wider) -->
    <div class="lg:col-span-2 card">
      <div class="card-header flex items-center justify-between">
        <span>Local Printers</span>
        <button type="button" id="qzRefreshBtn" onclick="qzRefreshPrinters()" class="text-xs text-indigo-600 hover:underline font-medium hidden">
          <svg class="w-3.5 h-3.5 inline -mt-0.5 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          Refresh
        </button>
      </div>
      <div class="card-body">
        <div id="qzPrinterList" class="space-y-2">
          <div class="text-center py-8 text-sm text-slate-400">
            <svg class="w-10 h-10 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            <p class="font-medium text-slate-500 mb-1">No printers detected</p>
            <p class="text-xs text-slate-400">Connect to QZ Tray to detect printers installed on this computer.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Printer Settings (sidebar) -->
    <div class="card">
      <div class="card-header">Printer Settings</div>
      <div class="card-body space-y-4">
        <!-- Default Printer -->
        <div>
          <label class="text-sm font-medium text-slate-700">Default Receipt Printer</label>
          <select id="qzDefaultPrinter" class="w-full border rounded-lg px-3 py-2 text-sm mt-1 focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400" disabled>
            <option value="">— Connect to QZ Tray first —</option>
          </select>
          <p class="text-xs text-slate-400 mt-1">Used for POS receipts and test prints</p>
        </div>

        <!-- Paper Width -->
        <div>
          <label class="text-sm font-medium text-slate-700">Paper Width</label>
          <select id="qzPaperWidth" class="w-full border rounded-lg px-3 py-2 text-sm mt-1 focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400">
            <option value="32">58mm (32 chars — narrow)</option>
            <option value="42">80mm (42 chars — standard)</option>
            <option value="48" selected>80mm (48 chars — condensed)</option>
          </select>
        </div>

        <!-- Auto-print Toggle -->
        <div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 border">
          <div>
            <div class="text-sm font-medium text-slate-900">Auto-print</div>
            <div class="text-xs text-slate-500 mt-0.5">Print receipt on POS checkout</div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" id="qzAutoPrint" class="sr-only peer">
            <div class="w-9 h-5 bg-slate-200 border border-slate-300 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
          </label>
        </div>

        <!-- Actions -->
        <div class="space-y-2 pt-2">
          <button type="button" onclick="qzSaveSettings()" class="btn btn-primary text-sm w-full">
            <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Save Settings
          </button>
          <button type="button" onclick="qzTestPrint()" id="qzTestPrintBtn" class="btn btn-outline text-sm w-full" disabled>
            <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Test Print
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Setup Guide -->
  <div class="card mt-4">
    <div class="card-header flex items-center justify-between">
      <span>Setup Guide</span>
      <a href="https://qz.io/download/" target="_blank" rel="noopener" class="text-xs text-indigo-600 hover:underline font-medium">
        <svg class="w-3.5 h-3.5 inline -mt-0.5 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        Download QZ Tray
      </a>
    </div>
    <div class="card-body">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="flex gap-3">
          <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0 text-sm font-bold">1</div>
          <div>
            <div class="text-sm font-medium text-slate-900">Install QZ Tray</div>
            <div class="text-xs text-slate-500 mt-0.5">Download from <a href="https://qz.io/download/" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">qz.io</a> and keep it running in the system tray.</div>
          </div>
        </div>
        <div class="flex gap-3">
          <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0 text-sm font-bold">2</div>
          <div>
            <div class="text-sm font-medium text-slate-900">Connect</div>
            <div class="text-xs text-slate-500 mt-0.5">Click the "Connect" button above to establish a WebSocket link with QZ Tray.</div>
          </div>
        </div>
        <div class="flex gap-3">
          <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0 text-sm font-bold">3</div>
          <div>
            <div class="text-sm font-medium text-slate-900">Select Printer</div>
            <div class="text-xs text-slate-500 mt-0.5">Choose your receipt printer from the detected list and save it as default.</div>
          </div>
        </div>
        <div class="flex gap-3">
          <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0 text-sm font-bold">4</div>
          <div>
            <div class="text-sm font-medium text-slate-900">Auto-print</div>
            <div class="text-xs text-slate-500 mt-0.5">Enable "Auto-print" so every POS sale silently prints a receipt.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== QUEUE TAB ===== -->
<div id="tab-queue" class="ps-tab-content hidden">
  <div class="card">
    <div class="card-header flex items-center justify-between">
      <span>Print Queue</span>
      <?php if ($canManage && $queuedJobs > 0): ?>
      <form method="POST" data-confirm="Clear all completed/failed jobs from queue?">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="clear_completed">
        <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium">Clear Completed</button>
      </form>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (empty($queue)): ?>
        <div class="text-center py-8 text-sm text-slate-400">
          <svg class="w-10 h-10 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          No jobs in queue.
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table-basic">
            <thead><tr><th>Job #</th><th>Printer</th><th>Document</th><th>Type</th><th>Status</th><th>Queued</th><?php if ($canWrite): ?><th>Action</th><?php endif; ?></tr></thead>
            <tbody>
              <?php foreach ($queue as $j):
                $jColor = match($j['status']) {
                  'queued' => 'bg-amber-100 text-amber-700',
                  'printing' => 'bg-blue-100 text-blue-700',
                  default => 'bg-slate-100 text-slate-500'
                };
              ?>
              <tr>
                <td class="font-medium text-xs"><?= htmlspecialchars($j['job_number']) ?></td>
                <td class="text-xs"><?= htmlspecialchars($j['printer_name'] ?? '—') ?></td>
                <td class="text-xs"><?= htmlspecialchars($j['document_title'] ?: $j['document_ref'] ?: '—') ?></td>
                <td><span class="text-xs capitalize"><?= str_replace('_', ' ', $j['document_type']) ?></span></td>
                <td><span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $jColor ?>"><?= ucfirst($j['status']) ?></span></td>
                <td class="text-xs text-slate-400"><?= date('M d, h:i A', strtotime($j['created_at'])) ?></td>
                <?php if ($canWrite): ?>
                <td>
                  <form method="POST" class="inline" data-confirm="Cancel this print job?">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="cancel_job">
                    <input type="hidden" name="job_id" value="<?= $j['id'] ?>">
                    <button type="submit" class="text-xs text-red-600 hover:text-red-800">Cancel</button>
                  </form>
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
</div>

<!-- ===== HISTORY TAB ===== -->
<div id="tab-history" class="ps-tab-content hidden">
  <div class="card">
    <div class="card-header flex items-center justify-between">
      <span>Print History</span>
      <div class="flex items-center gap-2">
        <select id="historyFilter" class="border rounded-lg px-2 py-1 text-xs">
          <option value="all">All Statuses</option>
          <option value="completed">Completed</option>
          <option value="failed">Failed</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <input type="text" id="historySearch" placeholder="Search..." class="border rounded-lg px-2 py-1 text-xs w-40" />
      </div>
    </div>
    <div class="card-body">
      <?php if (empty($history)): ?>
        <div class="text-center py-8 text-sm text-slate-400">No print history yet.</div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table-basic" id="historyTable">
            <thead><tr><th>Date</th><th>Printer</th><th>Document</th><th>Type</th><th>Copies</th><th>Status</th><th>User</th></tr></thead>
            <tbody>
              <?php foreach ($history as $h):
                $hColor = match($h['status']) {
                  'completed' => 'bg-emerald-100 text-emerald-700',
                  'failed' => 'bg-red-100 text-red-700',
                  'cancelled' => 'bg-slate-100 text-slate-500',
                  default => 'bg-slate-100 text-slate-500'
                };
              ?>
              <tr data-status="<?= $h['status'] ?>">
                <td class="text-xs text-slate-500"><?= date('M d, h:i A', strtotime($h['created_at'])) ?></td>
                <td class="text-xs font-medium"><?= htmlspecialchars($h['printer_name'] ?: $h['current_printer_name'] ?: '—') ?></td>
                <td class="text-xs"><?= htmlspecialchars($h['document_title'] ?: $h['document_ref'] ?: '—') ?></td>
                <td class="text-xs capitalize"><?= str_replace('_', ' ', $h['document_type'] ?? '—') ?></td>
                <td class="text-xs text-center"><?= $h['copies'] ?></td>
                <td><span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $hColor ?>"><?= ucfirst($h['status']) ?></span></td>
                <td class="text-xs"><?= htmlspecialchars($h['user_name'] ?: '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function() {
  const BASE = window.__baseUrl || '';

  // ── Tab Switching ──
  document.querySelectorAll('.ps-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.ps-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      document.querySelectorAll('.ps-tab-content').forEach(c => c.classList.add('hidden'));
      document.getElementById('tab-' + tab.dataset.tab).classList.remove('hidden');
    });
  });

  // ── History Filtering ──
  const historyFilter = document.getElementById('historyFilter');
  const historySearch = document.getElementById('historySearch');
  function filterHistory() {
    const status = historyFilter ? historyFilter.value : 'all';
    const q = historySearch ? historySearch.value.toLowerCase().trim() : '';
    document.querySelectorAll('#historyTable tbody tr').forEach(tr => {
      const matchStatus = status === 'all' || tr.dataset.status === status;
      const matchSearch = !q || tr.textContent.toLowerCase().includes(q);
      tr.style.display = matchStatus && matchSearch ? '' : 'none';
    });
  }
  if (historyFilter) historyFilter.addEventListener('change', filterHistory);
  if (historySearch) historySearch.addEventListener('input', filterHistory);

  // ── Auto-refresh stats periodically ──
  setInterval(async () => {
    try {
      const res = await fetch(BASE + '/modules/inventory/api_print_server?action=stats');
      const d = await res.json();
      if (d.success) {
        const queueBadge = document.querySelector('[data-tab="queue"] span');
        if (queueBadge) queueBadge.textContent = d.queue_count;
      }
    } catch(e) {}
  }, 30000);
})();
</script>

<script>
// ── QZ Tray: Load SDK + wrapper dynamically, then initialize ──
(function() {
  var BASE_URL_QZ = '<?= BASE_URL ?>';
  var QZ_CSRF = '<?= csrf_token() ?>';
  var QZ_BASE = window.__baseUrl || '';

  function _qzEl(id) { return document.getElementById(id); }

  // Dynamically load a script and return a promise
  function loadScript(src) {
    return new Promise(function(resolve, reject) {
      // If already loaded (full-page load or previous SPA visit), skip
      if (src.indexOf('qz-tray-sdk') !== -1 && typeof qz !== 'undefined') { resolve(); return; }
      if (src.indexOf('qz-tray.js') !== -1 && typeof QZIntegration !== 'undefined') { resolve(); return; }
      var s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = function() { console.warn('[QZ] Failed to load: ' + src); resolve(); };
      document.head.appendChild(s);
    });
  }

  // Chain: SDK → wrapper → init
  console.log('[QZ] Starting SDK/wrapper load chain...');
  loadScript(BASE_URL_QZ + '/assets/js/vendor/qz-tray-sdk.js')
    .then(function() {
      console.log('[QZ] SDK loaded, loading wrapper...');
      return loadScript(BASE_URL_QZ + '/assets/js/qz-tray.js?v=<?= $assetVer ?? time() ?>');
    })
    .then(function() {
      console.log('[QZ] Wrapper loaded, initializing...');
      initQZTray();
    });

  function initQZTray() {
    if (typeof QZIntegration === 'undefined') {
      console.warn('[QZ] QZIntegration not available after script load');
      return;
    }

    QZIntegration.onStatusChange(function(status, message) {
    const icon = _qzEl('qzStatusIcon');
    const title = _qzEl('qzStatusTitle');
    const msg = _qzEl('qzStatusMsg');
    const connBtn = _qzEl('qzConnectBtn');
    const discBtn = _qzEl('qzDisconnectBtn');
    const banner = _qzEl('qzStatusBanner');

    if (!icon) return; // tab may not be in DOM yet

    // Update the stat card too
    var statEl = _qzEl('statQzStatus');

    if (status === 'connected') {
      icon.className = 'w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center shrink-0';
      icon.innerHTML = '<svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
      title.textContent = 'QZ Tray — Connected';
      msg.textContent = message || 'Connected to QZ Tray on this computer';
      banner.className = banner.className.replace('border-red-200', '').replace('bg-red-50', '') + '';
      connBtn.classList.add('hidden');
      discBtn.classList.remove('hidden');
      _qzEl('qzRefreshBtn') && _qzEl('qzRefreshBtn').classList.remove('hidden');
      _qzEl('qzTestPrintBtn') && (_qzEl('qzTestPrintBtn').disabled = false);
      _qzEl('qzDefaultPrinter') && (_qzEl('qzDefaultPrinter').disabled = false);
      if (statEl) { statEl.textContent = 'Online'; statEl.className = 'text-2xl font-bold text-emerald-600'; }
    } else if (status === 'error') {
      icon.className = 'w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center shrink-0';
      icon.innerHTML = '<svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
      title.textContent = 'QZ Tray — Error';
      msg.textContent = message || 'Could not connect';
      connBtn.classList.remove('hidden');
      discBtn.classList.add('hidden');
      if (statEl) { statEl.textContent = 'Error'; statEl.className = 'text-2xl font-bold text-red-600'; }
    } else {
      icon.className = 'w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center shrink-0';
      icon.innerHTML = '<svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>';
      title.textContent = 'QZ Tray — Disconnected';
      msg.textContent = message || 'Click "Connect" to link with QZ Tray on this computer';
      connBtn.classList.remove('hidden');
      discBtn.classList.add('hidden');
      _qzEl('qzRefreshBtn') && _qzEl('qzRefreshBtn').classList.add('hidden');
      _qzEl('qzTestPrintBtn') && (_qzEl('qzTestPrintBtn').disabled = true);
      _qzEl('qzDefaultPrinter') && (_qzEl('qzDefaultPrinter').disabled = true);
      if (statEl) { statEl.textContent = 'Offline'; statEl.className = 'text-2xl font-bold text-slate-400'; }
    }
  });

  // Load saved settings on page load
    QZIntegration.loadSettings(QZ_BASE, QZ_CSRF).then(function() {
      var pw = _qzEl('qzPaperWidth');
      if (pw) pw.value = String(QZIntegration.getPaperWidth());
      var ap = _qzEl('qzAutoPrint');
      if (ap) ap.checked = QZIntegration.getAutoPrint();
    });
  } // end initQZTray

  // ── Expose QZ functions globally for onclick handlers ──

  window.qzConnect = async function() {
    console.log('[QZ] Connect button clicked');
    if (typeof QZIntegration === 'undefined') {
      alert('QZ Tray library not loaded yet. Please wait a moment and try again, or refresh the page.');
      console.error('[QZ] QZIntegration is undefined — SDK/wrapper scripts may have failed to load');
      return;
    }
    var btn = _qzEl('qzConnectBtn');
    var msg = _qzEl('qzStatusMsg');
    var banner = _qzEl('qzStatusBanner');

    // ── Loading state ──
    btn.disabled = true;
    btn.className = 'btn btn-primary text-sm opacity-80 pointer-events-none';
    btn.innerHTML = '<svg class="w-4 h-4 inline -mt-0.5 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Connecting...';
    if (msg) msg.textContent = 'Attempting to connect to QZ Tray on this computer...';

    try {
      await QZIntegration.connect();
      console.log('[QZ] Connected successfully');

      // ── Success state on button ──
      btn.className = 'btn text-sm bg-emerald-600 hover:bg-emerald-700 text-white border-emerald-600';
      btn.innerHTML = '<svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Connected!';

      await window.qzRefreshPrinters();

      // Hide connect button after a moment since disconnect button takes over
      setTimeout(function() {
        btn.classList.add('hidden');
        btn.disabled = false;
        btn.className = 'btn btn-primary text-sm hidden';
        btn.innerHTML = '<svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>Connect';
      }, 1500);

    } catch (e) {
      console.error('[QZ] Connection failed:', e);

      // ── Failed state on button ──
      btn.className = 'btn text-sm bg-red-600 hover:bg-red-700 text-white border-red-600';
      btn.innerHTML = '<svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>Failed';
      if (msg) msg.textContent = 'Could not connect — is QZ Tray installed and running? Download from qz.io/download';
      if (banner) { banner.classList.add('border-red-200', 'bg-red-50'); }

      // Reset button after 3 seconds so user can retry
      setTimeout(function() {
        btn.disabled = false;
        btn.className = 'btn btn-primary text-sm';
        btn.innerHTML = '<svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>Retry';
      }, 3000);
    }
  };

  window.qzDisconnect = function() {
    if (typeof QZIntegration !== 'undefined') {
      QZIntegration.disconnect();
    }
    var list = _qzEl('qzPrinterList');
    if (list) {
      list.innerHTML = '<div class="text-center py-6 text-sm text-slate-400">' +
        '<svg class="w-8 h-8 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>' +
        'Connect to QZ Tray to see local printers</div>';
    }
    var sel = _qzEl('qzDefaultPrinter');
    if (sel) {
      sel.innerHTML = '<option value="">— Connect to QZ Tray first —</option>';
      sel.disabled = true;
    }
  };

  window.qzRefreshPrinters = async function() {
    if (typeof QZIntegration === 'undefined' || !QZIntegration.isConnected()) return;

    var list = _qzEl('qzPrinterList');
    var sel = _qzEl('qzDefaultPrinter');
    list.innerHTML = '<div class="text-center py-4"><div class="spinner-mini mx-auto"></div><div class="text-xs text-slate-400 mt-2">Scanning local printers...</div></div>';

    var printers = await QZIntegration.findPrinters();
    var osDef = await QZIntegration.getDefaultPrinter();
    var savedDefault = QZIntegration.getDefaultPrinterName();

    if (!printers.length) {
      list.innerHTML = '<div class="text-center py-6 text-sm text-slate-400">No printers found on this computer.</div>';
      sel.innerHTML = '<option value="">No printers found</option>';
      return;
    }

    var html = '';
    printers.forEach(function(name) {
      var isOsDef = name === osDef;
      var isSavedDef = name === savedDefault;
      var badges = (isOsDef ? '<span class="text-[10px] px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded-full font-medium">OS Default</span>' : '') +
                     (isSavedDef ? '<span class="text-[10px] px-1.5 py-0.5 bg-indigo-100 text-indigo-700 rounded-full font-medium">Selected</span>' : '');
      html += '<div class="flex items-center justify-between p-3 border rounded-lg hover:bg-slate-50 transition">' +
        '<div class="flex items-center gap-3">' +
          '<div class="w-9 h-9 rounded-lg ' + (isSavedDef ? 'bg-indigo-100' : 'bg-slate-100') + ' flex items-center justify-center shrink-0">' +
            '<svg class="w-5 h-5 ' + (isSavedDef ? 'text-indigo-600' : 'text-slate-400') + '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>' +
          '</div>' +
          '<div>' +
            '<div class="flex items-center gap-2">' +
              '<span class="text-sm font-medium text-slate-900">' + escapeHtml(name) + '</span>' + badges +
            '</div>' +
          '</div>' +
        '</div>' +
        '<button type="button" class="text-xs text-indigo-600 hover:underline font-medium" onclick="qzSelectPrinter(\'' + escapeHtml(name).replace(/'/g, "\\'") + '\')">Select</button>' +
      '</div>';
    });
    list.innerHTML = html;

    var selHtml = '<option value="">— Select a printer —</option>';
    printers.forEach(function(name) {
      var selected = name === savedDefault ? ' selected' : '';
      selHtml += '<option value="' + escapeHtml(name) + '"' + selected + '>' + escapeHtml(name) + (name === osDef ? ' (OS Default)' : '') + '</option>';
    });
    sel.innerHTML = selHtml;
    sel.disabled = false;
  };

  window.qzSelectPrinter = function(name) {
    QZIntegration.setDefaultPrinter(name);
    var sel = _qzEl('qzDefaultPrinter');
    if (sel) {
      for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === name) { sel.selectedIndex = i; break; }
      }
    }
    window.qzRefreshPrinters();
  };

  window.qzSaveSettings = async function() {
    var sel = _qzEl('qzDefaultPrinter');
    var pw = _qzEl('qzPaperWidth');
    var ap = _qzEl('qzAutoPrint');

    QZIntegration.setDefaultPrinter(sel ? sel.value : '');
    QZIntegration.setPaperWidth(pw ? parseInt(pw.value) : 48);
    QZIntegration.setAutoPrint(ap ? ap.checked : false);

    var result = await QZIntegration.saveSettings(QZ_BASE, QZ_CSRF);
    if (result && result.success) {
      if (typeof showToast === 'function') showToast('QZ Tray settings saved');
      else alert('Settings saved!');
    } else {
      alert('Failed to save settings: ' + (result.error || 'Unknown error'));
    }
  };

  window.qzTestPrint = async function() {
    if (typeof QZIntegration === 'undefined' || !QZIntegration.isConnected()) {
      alert('Connect to QZ Tray first');
      return;
    }
    var printerEl = _qzEl('qzDefaultPrinter');
    var printer = (printerEl ? printerEl.value : '') || QZIntegration.getDefaultPrinterName();
    if (!printer) {
      alert('Select a printer first');
      return;
    }

    var btn = _qzEl('qzTestPrintBtn');
    btn.disabled = true;
    btn.textContent = 'Printing...';
    try {
      await QZIntegration.printTestPage(printer);
      if (typeof showToast === 'function') showToast('Test page sent to ' + printer);
      else alert('Test print sent!');
    } catch (e) {
      alert('Print failed: ' + e.message);
    }
    btn.disabled = false;
    btn.innerHTML = '<svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Test Print';
  };

})();
</script>

<style>
  .ps-tab { color: #94a3b8; border-color: transparent; cursor: pointer; }
  .ps-tab:hover { color: #475569; }
  .ps-tab.active { color: #4f46e5; border-color: #4f46e5; }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
