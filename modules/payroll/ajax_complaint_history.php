<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('payroll', 'payroll_runs', 'read');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

header('Content-Type: application/json');

// Only allow AJAX requests
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$complaintId = (int)($_GET['id'] ?? 0);

if ($complaintId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid complaint ID']);
    exit;
}

try {
    $pdo = get_db_conn();
    
    // Get complaint details
    $complaintStmt = $pdo->prepare("
        SELECT pc.*, 
               e.employee_code, e.first_name, e.last_name,
               pr.period_start, pr.period_end
        FROM payroll_complaints pc
        LEFT JOIN employees e ON e.id = pc.employee_id
        LEFT JOIN payroll_runs pr ON pr.id = pc.payroll_run_id
        WHERE pc.id = :id
    ");
    $complaintStmt->execute([':id' => $complaintId]);
    $complaint = $complaintStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$complaint) {
        echo json_encode(['success' => false, 'error' => 'Complaint not found']);
        exit;
    }
    
    // Build complaint info for display
    $complaintInfo = [
        'id' => $complaint['id'],
        'employee' => trim(($complaint['first_name'] ?? '') . ' ' . ($complaint['last_name'] ?? '')),
        'employee_code' => $complaint['employee_code'] ?? '—',
        'issue_type' => $complaint['issue_type'] ?? 'Payroll Concern',
        'status' => ucfirst(str_replace('_', ' ', $complaint['status'] ?? 'pending')),
        'priority' => ucfirst($complaint['priority'] ?? 'normal'),
        'description' => $complaint['description'] ?? '',
        'created_at' => $complaint['created_at'] ? date('M d, Y g:i A', strtotime($complaint['created_at'])) : '—',
        'resolved_at' => $complaint['resolved_at'] ? date('M d, Y g:i A', strtotime($complaint['resolved_at'])) : null,
    ];
    
    // Get complaint history from audit_logs (where action_log stores data)
    $historyStmt = $pdo->prepare("
        SELECT 
            al.action,
            al.details,
            al.action_type,
            al.created_at,
            u.first_name as actor_first,
            u.last_name as actor_last,
            u.role as actor_role
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.module = 'payroll'
          AND al.action LIKE '%complaint%'
          AND al.details::text LIKE :complaint_id_pattern
        ORDER BY al.created_at ASC
    ");
    
    $historyStmt->execute([
        ':complaint_id_pattern' => '%complaint_id":' . $complaintId . '%'
    ]);
    
    $rawHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Format history timeline
    $history = [];
    
    // Add initial creation event
    $history[] = [
        'action_type' => 'created',
        'action_label' => 'Complaint Filed',
        'status_label' => 'Pending',
        'action_date' => $complaint['created_at'] ? date('M d, Y g:i A', strtotime($complaint['created_at'])) : '—',
        'actor' => trim(($complaint['first_name'] ?? '') . ' ' . ($complaint['last_name'] ?? '')),
        'notes' => $complaint['description'] ?? '',
        'adjustment_details' => null
    ];
    
    // Process audit log entries (from action_log function)
    foreach ($rawHistory as $entry) {
        // Parse the nested JSON structure: details contains a JSON string with 'meta' inside
        $detailsStr = is_string($entry['details']) ? $entry['details'] : json_encode($entry['details']);
        $detailsObj = json_decode($detailsStr, true);
        
        // Extract meta from the nested structure
        $meta = $detailsObj['meta'] ?? [];
        
        // Skip if this entry doesn't relate to our complaint
        if (!isset($meta['complaint_id']) || (int)$meta['complaint_id'] !== $complaintId) {
            continue;
        }
        
        $action = $entry['action'] ?? '';
        $actor = trim(($entry['actor_first'] ?? '') . ' ' . ($entry['actor_last'] ?? ''));
        if (empty($actor)) {
            $actor = ucfirst(str_replace('_', ' ', $entry['actor_role'] ?? 'System'));
        }
        
        $actionLabel = 'Status Update';
        $statusLabel = '';
        $notes = '';
        $adjustmentDetails = null;
        
        // Determine action type and labels based on status change
        $newStatus = $meta['new_status'] ?? $meta['status'] ?? '';
        $oldStatus = $meta['old_status'] ?? '';
        
        if ($newStatus === 'resolved') {
            $actionLabel = 'Complaint Resolved';
            $statusLabel = 'Resolved';
        } elseif ($newStatus === 'rejected') {
            $actionLabel = 'Complaint Rejected';
            $statusLabel = 'Rejected';
        } elseif ($newStatus === 'in_review') {
            $actionLabel = 'Moved to Review';
            $statusLabel = 'In Review';
        } elseif ($newStatus === 'pending') {
            $actionLabel = 'Status Changed to Pending';
            $statusLabel = 'Pending';
        } else {
            $actionLabel = 'Complaint Updated';
            $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));
        }
        
        $notes = $meta['resolution_notes'] ?? $meta['remarks'] ?? '';
        
        // Check for adjustment details
        if (!empty($meta['has_adjustment'])) {
            $adjustmentDetails = 'Adjustment queued for next payroll';
        }
        
        $history[] = [
            'action_type' => $newStatus,
            'action_label' => $actionLabel,
            'status_label' => $statusLabel,
            'action_date' => $entry['created_at'] ? date('M d, Y g:i A', strtotime($entry['created_at'])) : '—',
            'actor' => $actor,
            'notes' => $notes,
            'adjustment_details' => $adjustmentDetails,
            'old_status' => $oldStatus ? ucfirst(str_replace('_', ' ', $oldStatus)) : null
        ];
    }
    
    // Sort by date
    usort($history, function($a, $b) {
        return strtotime($a['action_date']) - strtotime($b['action_date']);
    });
    
    echo json_encode([
        'success' => true,
        'complaint' => $complaintInfo,
        'history' => $history
    ]);
    
} catch (Exception $e) {
    sys_log('COMPLAINT-HISTORY-ERROR', 'Error fetching complaint history', [
        'module' => 'payroll',
        'error' => $e->getMessage(),
        'complaint_id' => $complaintId
    ]);
    
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while loading complaint history'
    ]);
}
