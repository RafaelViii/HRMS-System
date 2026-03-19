<?php
/**
 * Overtime Management Tab
 * Employee edit module - overtime tracking and approval interface
 */

// Group overtime requests by status
$otByStatus = [
  'pending' => [],
  'approved' => [],
  'rejected' => [],
  'paid' => []
];

foreach ($overtimeRequests as $ot) {
  $status = strtolower($ot['status'] ?? 'pending');
  if (isset($otByStatus[$status])) {
    $otByStatus[$status][] = $ot;
  }
}

$pendingCount = count($otByStatus['pending']);
$approvedCount = count($otByStatus['approved']);
$rejectedCount = count($otByStatus['rejected']);
$paidCount = count($otByStatus['paid']);
?>

<div id="tab-overtime" class="tab-content <?= $activeTab === 'overtime' ? 'active' : '' ?>">
  <div class="info-card">
    <div class="info-card-header">
      <div>
        <h2 class="info-card-title">Overtime Management</h2>
        <p class="info-card-subtitle">Review and approve employee overtime requests</p>
      </div>
      <div class="flex gap-2">
        <?php if ($pendingCount > 0): ?>
          <span class="px-3 py-1 text-xs font-semibold bg-yellow-100 text-yellow-800 rounded-full">
            <?= $pendingCount ?> Pending
          </span>
        <?php endif; ?>
        <?php if ($approvedCount > 0): ?>
          <span class="px-3 py-1 text-xs font-semibold bg-green-100 text-green-800 rounded-full">
            <?= $approvedCount ?> Approved
          </span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($overtimeRequests)): ?>
      <!-- Empty State -->
      <div class="text-center py-12">
        <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No Overtime Records</h3>
        <p class="text-sm text-gray-500">This employee hasn't submitted any overtime requests yet.</p>
      </div>
    <?php else: ?>
      <!-- Status Filter Tabs -->
      <div class="flex gap-2 mb-6 pb-4 border-b border-gray-200 overflow-x-auto">
        <button class="filter-tab active" data-filter="all">
          All Requests
          <span class="badge"><?= count($overtimeRequests) ?></span>
        </button>
        <?php if ($pendingCount > 0): ?>
          <button class="filter-tab" data-filter="pending">
            Pending
            <span class="badge bg-yellow-100 text-yellow-800"><?= $pendingCount ?></span>
          </button>
        <?php endif; ?>
        <?php if ($approvedCount > 0): ?>
          <button class="filter-tab" data-filter="approved">
            Approved
            <span class="badge bg-green-100 text-green-800"><?= $approvedCount ?></span>
          </button>
        <?php endif; ?>
        <?php if ($rejectedCount > 0): ?>
          <button class="filter-tab" data-filter="rejected">
            Rejected
            <span class="badge bg-red-100 text-red-800"><?= $rejectedCount ?></span>
          </button>
        <?php endif; ?>
        <?php if ($paidCount > 0): ?>
          <button class="filter-tab" data-filter="paid">
            Paid
            <span class="badge bg-blue-100 text-blue-800"><?= $paidCount ?></span>
          </button>
        <?php endif; ?>
      </div>

      <!-- Overtime Requests Table -->
      <div class="overflow-x-auto">
        <table class="overtime-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Hours</th>
              <th>Reason</th>
              <th>Status</th>
              <th>Approver</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($overtimeRequests as $ot): ?>
              <?php
                $otId = (int)($ot['id'] ?? 0);
                $status = strtolower($ot['status'] ?? 'pending');
                $hours = (float)($ot['hours'] ?? 0);
                $date = $ot['overtime_date'] ?? '';
                $reason = htmlspecialchars($ot['reason'] ?? 'No reason provided');
                $approverName = $ot['approver_name'] ?? '—';
                $approvedAt = $ot['approved_at'] ?? null;
                $rejectionReason = $ot['rejection_reason'] ?? null;
                
                // Badge styling
                $statusBadge = 'status-' . $status;
                $statusLabel = ucfirst($status);
              ?>
              <tr class="overtime-row" data-status="<?= $status ?>">
                <td>
                  <div class="flex items-center">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span class="font-medium text-gray-900">
                      <?= htmlspecialchars(date('M d, Y', strtotime($date))) ?>
                    </span>
                  </div>
                </td>
                <td>
                  <div class="flex items-center">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="font-semibold text-blue-600"><?= number_format($hours, 1) ?> hrs</span>
                  </div>
                </td>
                <td>
                  <div class="max-w-xs">
                    <p class="text-sm text-gray-700 truncate" title="<?= $reason ?>">
                      <?= $reason ?>
                    </p>
                  </div>
                </td>
                <td>
                  <span class="badge <?= $statusBadge ?>">
                    <?= $statusLabel ?>
                  </span>
                </td>
                <td>
                  <div class="text-sm">
                    <?php if ($approverName !== '—'): ?>
                      <div class="font-medium text-gray-900"><?= htmlspecialchars($approverName) ?></div>
                      <?php if ($approvedAt): ?>
                        <div class="text-xs text-gray-500"><?= date('M d, Y', strtotime($approvedAt)) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-gray-400">—</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ($status === 'pending'): ?>
                    <div class="flex gap-2">
                      <form method="post" class="inline" onsubmit="return confirm('Approve this overtime request?')">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="overtime_id" value="<?= $otId ?>">
                        <button type="submit" name="overtime_action" value="approve" class="btn btn-sm bg-green-50 text-green-700 hover:bg-green-100 border-green-300">
                          <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                          Approve
                        </button>
                      </form>
                      <button 
                        type="button" 
                        class="btn btn-sm bg-red-50 text-red-700 hover:bg-red-100 border-red-300"
                        onclick="openRejectModal(<?= $otId ?>)"
                      >
                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        Reject
                      </button>
                    </div>
                  <?php elseif ($status === 'rejected' && $rejectionReason): ?>
                    <button 
                      type="button" 
                      class="text-xs text-gray-500 hover:text-gray-700 underline"
                      onclick="alert('Rejection Reason:\\n\\n<?= addslashes($rejectionReason) ?>')"
                    >
                      View Reason
                    </button>
                  <?php else: ?>
                    <span class="text-xs text-gray-400">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Summary Stats -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6 pt-6 border-t border-gray-200">
        <div class="text-center">
          <div class="text-2xl font-bold text-gray-900"><?= count($overtimeRequests) ?></div>
          <div class="text-xs text-gray-500 uppercase tracking-wide">Total Requests</div>
        </div>
        <div class="text-center">
          <div class="text-2xl font-bold text-yellow-600"><?= $pendingCount ?></div>
          <div class="text-xs text-gray-500 uppercase tracking-wide">Pending</div>
        </div>
        <div class="text-center">
          <div class="text-2xl font-bold text-green-600"><?= $approvedCount ?></div>
          <div class="text-xs text-gray-500 uppercase tracking-wide">Approved</div>
        </div>
        <div class="text-center">
          <?php 
            $totalApprovedHours = array_sum(array_map(fn($ot) => (float)($ot['hours'] ?? 0), $otByStatus['approved']));
          ?>
          <div class="text-2xl font-bold text-blue-600"><?= number_format($totalApprovedHours, 1) ?></div>
          <div class="text-xs text-gray-500 uppercase tracking-wide">Approved Hours</div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-gray-900">Reject Overtime Request</h3>
      <button type="button" onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="post" id="rejectForm">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="overtime_id" id="rejectOvertimeId" value="">
      <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Reason for Rejection <span class="text-red-500">*</span>
        </label>
        <textarea 
          name="rejection_reason" 
          class="input-text w-full" 
          rows="4" 
          placeholder="Provide a reason for rejecting this overtime request..."
          required
        ></textarea>
      </div>
      <div class="flex gap-3">
        <button type="submit" name="overtime_action" value="reject" class="btn btn-danger flex-1">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          Reject Request
        </button>
        <button type="button" onclick="closeRejectModal()" class="btn btn-outline">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<style>
.filter-tab {
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #6b7280;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.filter-tab:hover {
  background: #f3f4f6;
  color: #374151;
}

.filter-tab.active {
  background: #2563eb;
  color: white;
  border-color: #2563eb;
}

.filter-tab .badge {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
  border-radius: 9999px;
  background: rgba(255, 255, 255, 0.2);
  color: inherit;
}

.filter-tab.active .badge {
  background: rgba(255, 255, 255, 0.3);
}
</style>

<script>
// Overtime filtering
document.addEventListener('DOMContentLoaded', function() {
  const filterTabs = document.querySelectorAll('.filter-tab');
  const rows = document.querySelectorAll('.overtime-row');
  
  filterTabs.forEach(tab => {
    tab.addEventListener('click', function() {
      const filter = this.dataset.filter;
      
      // Update active tab
      filterTabs.forEach(t => t.classList.remove('active'));
      this.classList.add('active');
      
      // Filter rows
      rows.forEach(row => {
        const rowStatus = row.dataset.status;
        if (filter === 'all' || rowStatus === filter) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });
  });
});

// Reject modal functions
function openRejectModal(overtimeId) {
  document.getElementById('rejectOvertimeId').value = overtimeId;
  document.getElementById('rejectModal').classList.remove('hidden');
}

function closeRejectModal() {
  document.getElementById('rejectModal').classList.add('hidden');
  document.getElementById('rejectForm').reset();
}

// Close modal on backdrop click
document.getElementById('rejectModal')?.addEventListener('click', function(e) {
  if (e.target === this) {
    closeRejectModal();
  }
});
</script>
