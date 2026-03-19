<?php
/**
 * API: Fetch audit history for a clinic record
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('healthcare', 'clinic_records', 'read');

header('Content-Type: application/json');

$pdo = get_db_conn();
$recordId = (int)($_GET['id'] ?? 0);

if (!$recordId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing record ID']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT h.id, h.action, h.changed_by_name, h.old_values, h.new_values, h.notes, h.created_at
    FROM clinic_record_history h
    WHERE h.clinic_record_id = :rid
    ORDER BY h.created_at DESC
");
$stmt->execute([':rid' => $recordId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format for display
$history = [];
foreach ($rows as $row) {
    $actionLabels = [
        'created' => 'Record Created',
        'nurse_updated' => 'Nurse Notes Updated',
        'medtech_assigned' => 'MedTech Assigned',
        'medtech_updated' => 'MedTech Notes Updated',
        'deleted' => 'Record Deleted',
        'restored' => 'Record Restored',
        'edited' => 'Record Edited',
    ];

    $history[] = [
        'id' => (int)$row['id'],
        'action' => $row['action'],
        'action_label' => $actionLabels[$row['action']] ?? ucfirst(str_replace('_', ' ', $row['action'])),
        'changed_by' => $row['changed_by_name'] ?? 'System',
        'old_values' => $row['old_values'] ? json_decode($row['old_values'], true) : null,
        'new_values' => $row['new_values'] ? json_decode($row['new_values'], true) : null,
        'notes' => $row['notes'],
        'created_at' => $row['created_at'],
    ];
}

echo json_encode(['history' => $history]);
