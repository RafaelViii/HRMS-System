<?php
/**
 * API: Employee Search — returns JSON for autocomplete
 * Used by clinic records forms to search for patients, nurses, and medtechs.
 * Accepts optional `type` param: 'nurse' or 'medtech' to filter by position.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('healthcare', 'clinic_records', 'read');

header('Content-Type: application/json');

$pdo = get_db_conn();
$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');

if (strlen($q) < 2) {
    echo '[]';
    exit;
}

// Build position filter based on type
$positionFilter = '';
$params = [':q' => '%' . $q . '%'];

if ($type === 'nurse') {
    // Only show employees in nurse-related positions
    $positionFilter = "AND p.name IS NOT NULL AND (LOWER(p.name) LIKE '%nurse%' OR LOWER(p.name) LIKE '%nursing%')";
} elseif ($type === 'medtech') {
    // Only show employees in medtech-related positions
    $positionFilter = "AND p.name IS NOT NULL AND (LOWER(p.name) LIKE '%medtech%' OR LOWER(p.name) LIKE '%med tech%' OR LOWER(p.name) LIKE '%medical techno%' OR LOWER(p.name) LIKE '%laboratory%')";
}

$stmt = $pdo->prepare("
    SELECT e.id, e.employee_code, e.first_name, e.last_name,
           p.name AS position_name, d.name AS department_name
    FROM employees e
    LEFT JOIN positions p ON p.id = e.position_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.deleted_at IS NULL
      AND e.status = 'active'
      AND (e.first_name ILIKE :q OR e.last_name ILIKE :q OR e.employee_code ILIKE :q
           OR CONCAT(e.first_name, ' ', e.last_name) ILIKE :q)
      {$positionFilter}
    ORDER BY e.last_name, e.first_name
    LIMIT 15
");
$stmt->execute($params);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
