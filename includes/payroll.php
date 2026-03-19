<?php
// Payroll helper scaffolding (draft)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

/**
 * Fetch a single payroll batch by id.
 */
function payroll_get_batch(PDO $pdo, int $batchId): ?array {
    try {
        $stmt = $pdo->prepare('SELECT pb.*, b.name AS branch_name, b.code AS branch_code FROM payroll_batches pb JOIN branches b ON b.id = pb.branch_id WHERE pb.id = :id LIMIT 1');
        $stmt->execute([':id' => $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        sys_log('PAYROLL-BATCH-GET', 'Failed fetching batch: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['batch_id' => $batchId]]);
        return null;
    }
}

/**
 * Compute approval state for a batch from approvers_chain + approvals_log.
 * Returns ['steps' => [ {step_order, role, user_id, user_name, requires_override, status, remarks, acted_at, acted_by}...], 'current_step' => int|null, 'has_rejected' => bool, 'all_approved' => bool]
 */
function payroll_get_batch_approval_state(PDO $pdo, int $batchId): array {
    $result = [
        'steps' => [],
        'current_step' => null,
        'has_rejected' => false,
        'all_approved' => false,
    ];

    $batch = payroll_get_batch($pdo, $batchId);
    if (!$batch) {
        return $result;
    }

    $chain = json_decode($batch['approvers_chain'] ?? '[]', true) ?: [];
    $log = json_decode($batch['approvals_log'] ?? '[]', true) ?: [];

    $decisionsByStep = [];
    foreach ($log as $entry) {
        $stepOrder = (int)($entry['step_order'] ?? 0);
        if ($stepOrder <= 0) {
            continue;
        }
        $decisionsByStep[$stepOrder] = [
            'status' => strtolower((string)($entry['decision'] ?? 'pending')),
            'remarks' => $entry['remarks'] ?? null,
            'acted_at' => $entry['acted_at'] ?? null,
            'acted_by' => $entry['acted_by'] ?? null,
        ];
    }

    $steps = [];
    $pendingSteps = [];
    $hasRejected = false;

    foreach ($chain as $row) {
        $stepOrder = (int)($row['step_order'] ?? 0);
        if ($stepOrder <= 0) {
            continue;
        }

        $status = 'pending';
        $remarks = null;
        $actedAt = null;
        $actedBy = null;

        if (isset($decisionsByStep[$stepOrder])) {
            $status = $decisionsByStep[$stepOrder]['status'] ?: 'pending';
            $remarks = $decisionsByStep[$stepOrder]['remarks'];
            $actedAt = $decisionsByStep[$stepOrder]['acted_at'];
            $actedBy = $decisionsByStep[$stepOrder]['acted_by'];
            if ($status === 'rejected') {
                $hasRejected = true;
            }
        } else {
            $pendingSteps[] = $stepOrder;
        }

        $requiresOverride = $row['requires_override'] ?? true;

        $steps[] = [
            'step_order' => $stepOrder,
            'role' => $row['role'] ?? null,
            'user_id' => $row['user_id'] ?? null,
            'user_name' => $row['user_name'] ?? null,
            'requires_override' => (bool)$requiresOverride,
            'status' => $status,
            'remarks' => $remarks,
            'acted_at' => $actedAt,
            'acted_by' => $actedBy,
        ];
    }

    usort($steps, static function (array $a, array $b): int {
        return ($a['step_order'] ?? 0) <=> ($b['step_order'] ?? 0);
    });

    sort($pendingSteps);
    $currentStep = $pendingSteps ? $pendingSteps[0] : null;

    $result['steps'] = $steps;
    $result['current_step'] = $currentStep;
    $result['has_rejected'] = $hasRejected;
    $result['all_approved'] = !$hasRejected && !$pendingSteps && !empty($steps);

    return $result;
}

/**
 * Update (append) a batch approval decision. Enforces sequential order.
 */
function payroll_update_batch_approval(PDO $pdo, int $batchId, int $stepOrder, string $decision, ?string $remarks = null, ?int $actingUserId = null): array {
    $out = ['ok' => false, 'error' => null];
    $decision = in_array($decision, ['approved', 'rejected'], true) ? $decision : 'approved';

    if ($batchId <= 0 || $stepOrder <= 0) {
        $out['error'] = 'Invalid approval request.';
        return $out;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT approvers_chain, approvals_log, status FROM payroll_batches WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            $out['error'] = 'Batch not found.';
            return $out;
        }

        $chain = json_decode($row['approvers_chain'] ?? '[]', true) ?: [];
        $validSteps = [];
        foreach ($chain as $chainStep) {
            $value = (int)($chainStep['step_order'] ?? 0);
            if ($value > 0) {
                $validSteps[] = $value;
            }
        }
        sort($validSteps);

        if (!$validSteps || !in_array($stepOrder, $validSteps, true)) {
            $pdo->rollBack();
            $out['error'] = 'Approval step not found.';
            return $out;
        }

        $log = json_decode($row['approvals_log'] ?? '[]', true);
        if (!is_array($log)) {
            $log = [];
        }

        $decisions = [];
        foreach ($log as $entry) {
            $so = (int)($entry['step_order'] ?? 0);
            if ($so <= 0) {
                continue;
            }
            $decisions[$so] = strtolower((string)($entry['decision'] ?? 'pending'));
        }

        if (($decisions[$stepOrder] ?? 'pending') !== 'pending') {
            $pdo->rollBack();
            $out['error'] = 'Approval step already processed.';
            return $out;
        }

        $currentStep = null;
        foreach ($validSteps as $validStep) {
            $status = $decisions[$validStep] ?? 'pending';
            if ($status !== 'approved') {
                $currentStep = $validStep;
                break;
            }
        }

        if ($decision === 'approved' && $currentStep !== null && $stepOrder !== $currentStep) {
            $pdo->rollBack();
            $out['error'] = 'Earlier approval steps must be completed first.';
            return $out;
        }

        $log[] = [
            'step_order' => $stepOrder,
            'decision' => $decision,
            'remarks' => ($remarks !== null && $remarks !== '') ? $remarks : null,
            'acted_at' => date('c'),
            'acted_by' => $actingUserId ?: null,
        ];

        $decisionsAfter = [];
        foreach ($log as $entry) {
            $so = (int)($entry['step_order'] ?? 0);
            if ($so <= 0) {
                continue;
            }
            $decisionsAfter[$so] = strtolower((string)($entry['decision'] ?? 'pending'));
        }

        $hasRejected = false;
        foreach ($decisionsAfter as $status) {
            if ($status === 'rejected') {
                $hasRejected = true;
                break;
            }
        }

        $allApproved = true;
        foreach ($validSteps as $validStep) {
            $state = $decisionsAfter[$validStep] ?? 'pending';
            if ($state !== 'approved') {
                $allApproved = false;
                break;
            }
        }

        if ($hasRejected) {
            $newStatus = 'for_revision';
        } elseif ($allApproved) {
            $newStatus = 'approved';
        } else {
            $newStatus = 'for_review';
        }

        $upd = $pdo->prepare('UPDATE payroll_batches SET approvals_log = :log::jsonb, status = :status, updated_at = NOW() WHERE id = :id');
        $upd->execute([
            ':log' => json_encode(array_values($log), JSON_UNESCAPED_SLASHES),
            ':status' => $newStatus,
            ':id' => $batchId,
        ]);

        $pdo->commit();
        action_log('payroll', 'batch_approval', 'success', ['batch_id' => $batchId, 'step' => $stepOrder, 'decision' => $decision]);

        $out['ok'] = true;
        $out['status'] = $newStatus;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $inner) {}
        }
        sys_log('PAYROLL-BATCH-APPROVAL', 'Failed updating batch approval: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['batch_id' => $batchId, 'step' => $stepOrder],
        ]);
        $out['error'] = 'System error';
        return $out;
    }
}

/**
 * List active payroll approval chain templates.
 */
function payroll_get_approval_templates(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT id, chain_key, label, is_active FROM payroll_approval_chain_templates WHERE is_active = TRUE ORDER BY label");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-TEMPLATES', 'Failed loading approval templates: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return [];
    }
}

/**
 * Build a snapshot of approvers for a template by resolving each step's role to a user.
 */
function payroll_build_template_approver_snapshot(PDO $pdo, int $templateId): array {
    try {
        $st = $pdo->prepare('SELECT step_order, role, requires_override, notify, instructions FROM payroll_approval_chain_steps WHERE template_id = :tid ORDER BY step_order');
        $st->execute([':tid' => $templateId]);
        $steps = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$steps) { return []; }
        $snapshot = [];
        $userStmt = $pdo->prepare('SELECT id, full_name FROM users WHERE role = :role ORDER BY id LIMIT 1');
        foreach ($steps as $row) {
            $role = (string)$row['role'];
            $userStmt->execute([':role' => $role]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $snapshot[] = [
                'step_order' => (int)($row['step_order'] ?? 1),
                'role' => $role,
                'user_id' => $user ? (int)$user['id'] : null,
                'user_name' => $user ? (string)$user['full_name'] : null,
                'requires_override' => (bool)($row['requires_override'] ?? true),
                'notify' => (bool)($row['notify'] ?? true),
                'instructions' => $row['instructions'] ?? null,
            ];
        }
        return $snapshot;
    } catch (Throwable $e) {
        sys_log('PAYROLL-TEMPLATE-SNAPSHOT', 'Failed building approver snapshot: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['template_id' => $templateId]]);
        return [];
    }
}

/**
 * Fetch active rate configurations keyed by code.
 */
function payroll_get_rate_configs(PDO $pdo, ?string $asOf = null): array {
    $asOf = $asOf ?: date('Y-m-d');
    $sql = 'SELECT code, category, default_value, override_value, effective_start, effective_end, meta
            FROM payroll_rate_configs
            WHERE effective_start <= :as_of AND (effective_end IS NULL OR effective_end >= :as_of)
            ORDER BY effective_start DESC';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':as_of' => $asOf]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-RATE-READ', 'Failed loading rate configs: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        $code = strtolower($row['code']);
        if (!isset($map[$code]) || $row['effective_start'] >= ($map[$code]['effective_start'] ?? '1900-01-01')) {
            $map[$code] = $row;
        }
    }
    return $map;
}

/** Return human-friendly labels for rate configuration categories. */
function payroll_rate_category_labels(): array {
    return [
        'statutory' => 'Statutory',
        'allowance' => 'Allowances & Benefits',
        'custom_rate' => 'Custom Rates',
    ];
}

/** Resolve a category label with a sensible fallback when unknown. */
function payroll_rate_category_label(string $category): string {
    $map = payroll_rate_category_labels();
    $key = strtolower(trim($category));
    if (isset($map[$key])) {
        return $map[$key];
    }
    $pretty = str_replace(['_', '-'], ' ', $category);
    return ucwords($pretty ?: 'Other');
}

/**
 * Fetch active formula settings (Milestone 1 table) keyed by code.
 */
function payroll_get_formula_settings(PDO $pdo, ?string $asOf = null): array {
    $asOf = $asOf ?: date('Y-m-d');
    $sql = 'SELECT code, label, category, description, default_value, is_percentage, config, effective_start, effective_end, is_active
            FROM payroll_formula_settings
            WHERE is_active = TRUE AND effective_start <= :as_of AND (effective_end IS NULL OR effective_end >= :as_of)
            ORDER BY effective_start DESC';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':as_of' => $asOf]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-FORMULA-READ', 'Failed loading formula settings: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        $code = strtolower((string)$row['code']);
        if (!isset($map[$code]) || $row['effective_start'] >= ($map[$code]['effective_start'] ?? '1900-01-01')) {
            $map[$code] = $row;
        }
    }
    return $map;
}

function payroll_ensure_compensation_tables(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payroll_compensation_defaults (
                id INT PRIMARY KEY DEFAULT 1,
                allowances JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                deductions JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                tax_percentage NUMERIC(5,2) NULL,
                notes TEXT NULL,
                updated_by INT NULL,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_payroll_comp_defaults_user FOREIGN KEY (updated_by)
                    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
            );'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS employee_compensation_overrides (
                employee_id INT PRIMARY KEY,
                allowances JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                deductions JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                tax_percentage NUMERIC(5,2) NULL,
                notes TEXT NULL,
                updated_by INT NULL,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_comp_override_employee FOREIGN KEY (employee_id)
                    REFERENCES employees (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_comp_override_user FOREIGN KEY (updated_by)
                    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
            );'
        );
        $pdo->exec("INSERT INTO payroll_compensation_defaults (id) VALUES (1) ON CONFLICT (id) DO NOTHING");
        if (!payroll_table_has_column($pdo, 'payroll_compensation_defaults', 'tax_percentage')) {
            $pdo->exec("ALTER TABLE payroll_compensation_defaults ADD COLUMN tax_percentage NUMERIC(5,2) NULL");
        }
        if (!payroll_table_has_column($pdo, 'employee_compensation_overrides', 'tax_percentage')) {
            $pdo->exec("ALTER TABLE employee_compensation_overrides ADD COLUMN tax_percentage NUMERIC(5,2) NULL");
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMP-TABLE', 'Failed ensuring compensation tables: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
    }
    $ensured = true;
}

function payroll_normalize_compensation_items($items): array {
    if (!is_array($items)) {
        return [];
    }
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = trim((string)($item['label'] ?? ''));
        $amount = (float)($item['amount'] ?? 0);
        if ($label === '' || $amount == 0.0) {
            continue;
        }
        $amount = round(abs($amount), 2);
        $rawCode = strtoupper(trim((string)($item['code'] ?? '')));
        $code = preg_replace('/[^A-Z0-9_-]/', '', $rawCode) ?: substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($label)), 0, 12);
        if ($code === '') {
            $code = 'ITEM' . str_pad((string)(count($normalized) + 1), 2, '0', STR_PAD_LEFT);
        }
        $normalized[] = [
            'code' => $code,
            'label' => $label,
            'amount' => $amount,
        ];
    }
    return $normalized;
}

function payroll_decode_compensation_value($value): array {
    if (is_array($value)) {
        return payroll_normalize_compensation_items($value);
    }
    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return payroll_normalize_compensation_items($decoded);
        }
    }
    return [];
}

function payroll_compensation_item_key(array $item): string {
    $code = strtoupper(trim((string)($item['code'] ?? '')));
    if ($code !== '') {
        return $code;
    }
    $label = strtoupper(trim((string)($item['label'] ?? '')));
    if ($label !== '') {
        return 'LABEL:' . preg_replace('/\s+/', '-', $label);
    }
    return 'IDX:' . substr(sha1(json_encode($item)), 0, 12);
}

function payroll_merge_compensation_items(array $base, array $overrides, string $defaultSource = 'default', string $overrideSource = 'override'): array {
    $order = [];
    $merged = [];
    foreach ($base as $item) {
        $key = payroll_compensation_item_key($item);
        if (!in_array($key, $order, true)) {
            $order[] = $key;
        }
        $merged[$key] = $item + ['source' => $defaultSource];
    }
    foreach ($overrides as $item) {
        $key = payroll_compensation_item_key($item);
        if (!in_array($key, $order, true)) {
            $order[] = $key;
        }
        $merged[$key] = $item + ['source' => $overrideSource];
    }
    $output = [];
    foreach ($order as $key) {
        if (!isset($merged[$key])) {
            continue;
        }
        $row = $merged[$key];
        $row['amount'] = round((float)($row['amount'] ?? 0), 2);
        if ($row['amount'] <= 0) {
            continue;
        }
        $output[] = $row;
    }
    return $output;
}

function payroll_build_compensation_profile(array $defaults, ?array $override): array {
    $defaultAllowances = is_array($defaults['allowances'] ?? null) ? $defaults['allowances'] : [];
    $overrideAllowances = is_array($override['allowances'] ?? null) ? $override['allowances'] : [];
    $defaultDeductions = is_array($defaults['deductions'] ?? null) ? $defaults['deductions'] : [];
    $overrideDeductions = is_array($override['deductions'] ?? null) ? $override['deductions'] : [];

    $allowances = payroll_merge_compensation_items($defaultAllowances, $overrideAllowances, 'default', 'employee_override');
    $deductions = payroll_merge_compensation_items($defaultDeductions, $overrideDeductions, 'default', 'employee_override');

    $defaultTax = isset($defaults['tax_percentage']) && $defaults['tax_percentage'] !== null ? (float)$defaults['tax_percentage'] : null;
    $overrideTax = isset($override['tax_percentage']) && $override['tax_percentage'] !== null ? (float)$override['tax_percentage'] : null;
    $effectiveTax = $overrideTax !== null ? $overrideTax : $defaultTax;

    $notesDefault = isset($defaults['notes']) && trim((string)$defaults['notes']) !== '' ? (string)$defaults['notes'] : null;
    $notesOverride = isset($override['notes']) && trim((string)$override['notes']) !== '' ? (string)$override['notes'] : null;

    return [
        'allowances' => $allowances,
        'deductions' => $deductions,
        'tax_percentage' => [
            'value' => $effectiveTax !== null ? round($effectiveTax, 4) : null,
            'source' => $overrideTax !== null ? 'employee_override' : ($defaultTax !== null ? 'default' : null),
            'default_value' => $defaultTax !== null ? round($defaultTax, 4) : null,
            'override_value' => $overrideTax !== null ? round($overrideTax, 4) : null,
        ],
        'notes' => [
            'default' => $notesDefault,
            'override' => $notesOverride,
        ],
        'has_override' => ($overrideAllowances !== [] || $overrideDeductions !== [] || $overrideTax !== null || $notesOverride !== null),
    ];
}

function payroll_get_compensation_defaults(PDO $pdo): array {
    payroll_ensure_compensation_tables($pdo);
    try {
        $stmt = $pdo->query('SELECT allowances, deductions, tax_percentage, notes, updated_by, updated_at FROM payroll_compensation_defaults WHERE id = 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMP-DEFAULTS', 'Failed reading compensation defaults: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        $row = [];
    }
    return [
        'allowances' => payroll_decode_compensation_value($row['allowances'] ?? []),
        'deductions' => payroll_decode_compensation_value($row['deductions'] ?? []),
        'tax_percentage' => isset($row['tax_percentage']) && $row['tax_percentage'] !== null ? (float)$row['tax_percentage'] : null,
        'notes' => (string)($row['notes'] ?? ''),
        'updated_by' => isset($row['updated_by']) ? (int)$row['updated_by'] : null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function payroll_save_compensation_defaults(PDO $pdo, array $allowances, array $deductions, ?string $notes, ?int $userId, ?float $taxPercentage = null): bool {
    payroll_ensure_compensation_tables($pdo);
    $allowances = payroll_normalize_compensation_items($allowances);
    $deductions = payroll_normalize_compensation_items($deductions);
    $notes = $notes !== null ? trim($notes) : null;
    $taxPercentage = $taxPercentage !== null ? max(0.0, min(100.0, round((float)$taxPercentage, 4))) : null;
    try {
        $stmt = $pdo->prepare('UPDATE payroll_compensation_defaults SET allowances = :allowances::jsonb, deductions = :deductions::jsonb, tax_percentage = :tax, notes = :notes, updated_by = :by, updated_at = NOW() WHERE id = 1');
        $stmt->execute([
            ':allowances' => json_encode($allowances, JSON_UNESCAPED_SLASHES),
            ':deductions' => json_encode($deductions, JSON_UNESCAPED_SLASHES),
            ':tax' => $taxPercentage,
            ':notes' => $notes,
            ':by' => $userId ?: null,
        ]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMP-DEFAULTS-SAVE', 'Failed saving compensation defaults: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return false;
    }
}

function payroll_get_employee_compensation(PDO $pdo, int $employeeId): ?array {
    payroll_ensure_compensation_tables($pdo);
    if ($employeeId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT allowances, deductions, tax_percentage, notes, updated_by, updated_at FROM employee_compensation_overrides WHERE employee_id = :id');
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'allowances' => payroll_decode_compensation_value($row['allowances'] ?? []),
            'deductions' => payroll_decode_compensation_value($row['deductions'] ?? []),
            'tax_percentage' => isset($row['tax_percentage']) && $row['tax_percentage'] !== null ? (float)$row['tax_percentage'] : null,
            'notes' => (string)($row['notes'] ?? ''),
            'updated_by' => isset($row['updated_by']) ? (int)$row['updated_by'] : null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMP-EMP-GET', 'Failed reading employee compensation override: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
        return null;
    }
}

function payroll_get_employee_compensation_map(PDO $pdo, array $employeeIds): array {
    payroll_ensure_compensation_tables($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $map = [];
    try {
        $stmt = $pdo->prepare('SELECT employee_id, allowances, deductions, tax_percentage, notes FROM employee_compensation_overrides WHERE employee_id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $empId = (int)$row['employee_id'];
            $map[$empId] = [
                'allowances' => payroll_decode_compensation_value($row['allowances'] ?? []),
                'deductions' => payroll_decode_compensation_value($row['deductions'] ?? []),
                'tax_percentage' => isset($row['tax_percentage']) && $row['tax_percentage'] !== null ? (float)$row['tax_percentage'] : null,
                'notes' => (string)($row['notes'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMP-EMP-MAP', 'Failed loading employee compensation overrides: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
    }
    return $map;
}

function payroll_save_employee_compensation(PDO $pdo, int $employeeId, array $allowances, array $deductions, ?string $notes, ?int $userId, ?float $taxPercentage = null): bool {
    if ($employeeId <= 0) {
        return false;
    }
    payroll_ensure_compensation_tables($pdo);
    $allowances = payroll_normalize_compensation_items($allowances);
    $deductions = payroll_normalize_compensation_items($deductions);
    $notes = $notes !== null ? trim($notes) : null;
    $taxPercentage = $taxPercentage !== null ? max(0.0, min(100.0, round((float)$taxPercentage, 4))) : null;
    try {
        $stmt = $pdo->prepare('INSERT INTO employee_compensation_overrides (employee_id, allowances, deductions, tax_percentage, notes, updated_by, updated_at)
            VALUES (:id, :allowances::jsonb, :deductions::jsonb, :tax, :notes, :by, NOW())
            ON CONFLICT (employee_id) DO UPDATE SET
                allowances = EXCLUDED.allowances,
                deductions = EXCLUDED.deductions,
                tax_percentage = EXCLUDED.tax_percentage,
                notes = EXCLUDED.notes,
                updated_by = EXCLUDED.updated_by,
                updated_at = NOW()');
        $stmt->execute([
            ':id' => $employeeId,
            ':allowances' => json_encode($allowances, JSON_UNESCAPED_SLASHES),
            ':deductions' => json_encode($deductions, JSON_UNESCAPED_SLASHES),
            ':tax' => $taxPercentage,
            ':notes' => $notes,
            ':by' => $userId ?: null,
        ]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMP-EMP-SAVE', 'Failed saving employee compensation override: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
        return false;
    }
}

function payroll_delete_employee_compensation_override(PDO $pdo, int $employeeId): bool {
    if ($employeeId <= 0) {
        return false;
    }
    payroll_ensure_compensation_tables($pdo);
    try {
        $stmt = $pdo->prepare('DELETE FROM employee_compensation_overrides WHERE employee_id = :id');
        $stmt->execute([':id' => $employeeId]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMP-EMP-DEL', 'Failed deleting employee compensation override: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
        return false;
    }
}

function payroll_ensure_employee_profiles_table(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if (!payroll_table_exists($pdo, 'employee_payroll_profiles')) {
        $sql = "CREATE TABLE IF NOT EXISTS employee_payroll_profiles (
            employee_id INT PRIMARY KEY REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
            allow_overtime BOOLEAN NOT NULL DEFAULT FALSE,
            duty_start TIME WITHOUT TIME ZONE NULL,
            duty_end TIME WITHOUT TIME ZONE NULL,
            hours_per_day INT NULL,
            working_days_per_month INT NULL,
            updated_by INT NULL REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
            updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )";
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            sys_log('PAYROLL-PROFILE-DDL', 'Failed creating employee payroll profiles table: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        }
    }

    $ddlColumns = [
        'overtime_multiplier' => "ALTER TABLE employee_payroll_profiles ADD COLUMN overtime_multiplier NUMERIC(6,3) DEFAULT 1.250",
        'custom_hourly_rate' => "ALTER TABLE employee_payroll_profiles ADD COLUMN custom_hourly_rate NUMERIC(12,2) NULL",
        'custom_daily_rate' => "ALTER TABLE employee_payroll_profiles ADD COLUMN custom_daily_rate NUMERIC(12,2) NULL",
        'profile_notes' => "ALTER TABLE employee_payroll_profiles ADD COLUMN profile_notes TEXT NULL",
    ];

    foreach ($ddlColumns as $column => $sql) {
        if (!payroll_table_has_column($pdo, 'employee_payroll_profiles', $column)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                sys_log('PAYROLL-PROFILE-DDL', 'Failed adding column to employee payroll profiles: ' . $e->getMessage(), [
                    'module' => 'payroll',
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'context' => ['column' => $column],
                ]);
            }
        }
    }

    try {
        $pdo->exec("UPDATE employee_payroll_profiles SET overtime_multiplier = COALESCE(overtime_multiplier, 1.250)");
    } catch (Throwable $e) {
        sys_log('PAYROLL-PROFILE-DDL', 'Failed normalizing overtime multiplier defaults: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
        ]);
    }

    $ensured = true;
}

function payroll_normalize_profile_row(?array $row): array {
    $defaults = [
        'allow_overtime' => false,
        'duty_start' => null,
        'duty_end' => null,
        'hours_per_day' => null,
        'working_days_per_month' => null,
        'overtime_multiplier' => 1.25,
        'custom_hourly_rate' => null,
        'custom_daily_rate' => null,
        'profile_notes' => null,
        'updated_at' => null,
        'updated_by' => null,
    ];
    if (!$row) {
        return $defaults;
    }
    $normalized = $defaults;
    $normalized['allow_overtime'] = !empty($row['allow_overtime']);
    $normalized['duty_start'] = $row['duty_start'] ?? null;
    $normalized['duty_end'] = $row['duty_end'] ?? null;
    $normalized['hours_per_day'] = isset($row['hours_per_day']) && $row['hours_per_day'] !== null ? (int)$row['hours_per_day'] : null;
    $normalized['working_days_per_month'] = isset($row['working_days_per_month']) && $row['working_days_per_month'] !== null ? (int)$row['working_days_per_month'] : null;
    $normalized['overtime_multiplier'] = isset($row['overtime_multiplier']) && is_numeric($row['overtime_multiplier'])
        ? round(max(0.0, (float)$row['overtime_multiplier']), 3)
        : 1.25;
    $normalized['custom_hourly_rate'] = isset($row['custom_hourly_rate']) && is_numeric($row['custom_hourly_rate'])
        ? round(max(0.0, (float)$row['custom_hourly_rate']), 2)
        : null;
    $normalized['custom_daily_rate'] = isset($row['custom_daily_rate']) && is_numeric($row['custom_daily_rate'])
        ? round(max(0.0, (float)$row['custom_daily_rate']), 2)
        : null;
    $normalized['profile_notes'] = isset($row['profile_notes']) && trim((string)$row['profile_notes']) !== ''
        ? trim((string)$row['profile_notes'])
        : null;
    $normalized['updated_at'] = $row['updated_at'] ?? null;
    $normalized['updated_by'] = isset($row['updated_by']) ? (int)$row['updated_by'] : null;
    return $normalized;
}

function payroll_get_employee_profile(PDO $pdo, int $employeeId): array {
    payroll_ensure_employee_profiles_table($pdo);
    if ($employeeId <= 0) {
        return payroll_normalize_profile_row(null);
    }
    try {
    $stmt = $pdo->prepare('SELECT allow_overtime, duty_start, duty_end, hours_per_day, working_days_per_month, overtime_multiplier, custom_hourly_rate, custom_daily_rate, profile_notes, updated_by, updated_at FROM employee_payroll_profiles WHERE employee_id = :id');
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        sys_log('PAYROLL-PROFILE-GET', 'Failed loading employee payroll profile: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
        $row = null;
    }
    return payroll_normalize_profile_row($row);
}

function payroll_get_employee_profile_map(PDO $pdo, array $employeeIds): array {
    payroll_ensure_employee_profiles_table($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $map = [];
    try {
    $stmt = $pdo->prepare('SELECT employee_id, allow_overtime, duty_start, duty_end, hours_per_day, working_days_per_month, overtime_multiplier, custom_hourly_rate, custom_daily_rate, profile_notes, updated_by, updated_at FROM employee_payroll_profiles WHERE employee_id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $empId = (int)$row['employee_id'];
            $map[$empId] = payroll_normalize_profile_row($row);
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-PROFILE-MAP', 'Failed loading employee payroll profiles: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
    }
    return $map;
}

function payroll_save_employee_profile(PDO $pdo, int $employeeId, array $profile, ?int $userId = null): bool {
    if ($employeeId <= 0) {
        return false;
    }

    payroll_ensure_employee_profiles_table($pdo);

    $allowOvertime = !empty($profile['allow_overtime']);
    $hoursPerDay = isset($profile['hours_per_day']) && is_numeric($profile['hours_per_day']) ? (int)$profile['hours_per_day'] : null;
    $workingDaysPerMonth = isset($profile['working_days_per_month']) && is_numeric($profile['working_days_per_month']) ? (int)$profile['working_days_per_month'] : null;
    $dutyStart = $profile['duty_start'] ?? null;
    $dutyEnd = $profile['duty_end'] ?? null;

    $normalizeTime = static function ($value): ?string {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $parsed = \DateTime::createFromFormat('H:i', $value);
        if (!$parsed) {
            $parsed = \DateTime::createFromFormat('H:i:s', $value);
        }
        return $parsed ? $parsed->format('H:i:s') : null;
    };

    $dutyStart = $normalizeTime($dutyStart);
    $dutyEnd = $normalizeTime($dutyEnd);

    if ($hoursPerDay !== null && $hoursPerDay <= 0) {
        $hoursPerDay = null;
    }
    if ($workingDaysPerMonth !== null && $workingDaysPerMonth <= 0) {
        $workingDaysPerMonth = null;
    }

    $overtimeMultiplier = isset($profile['overtime_multiplier']) && is_numeric($profile['overtime_multiplier'])
        ? round(max(0.0, (float)$profile['overtime_multiplier']), 3)
        : 1.25;
    if ($overtimeMultiplier <= 0) {
        $overtimeMultiplier = 1.25;
    }

    $customHourly = isset($profile['custom_hourly_rate']) && $profile['custom_hourly_rate'] !== ''
        ? round(max(0.0, (float)$profile['custom_hourly_rate']), 2)
        : null;
    if ($customHourly !== null && $customHourly <= 0) {
        $customHourly = null;
    }

    $customDaily = isset($profile['custom_daily_rate']) && $profile['custom_daily_rate'] !== ''
        ? round(max(0.0, (float)$profile['custom_daily_rate']), 2)
        : null;
    if ($customDaily !== null && $customDaily <= 0) {
        $customDaily = null;
    }

    $profileNotes = isset($profile['profile_notes']) ? trim((string)$profile['profile_notes']) : null;
    if ($profileNotes === '') {
        $profileNotes = null;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO employee_payroll_profiles (employee_id, allow_overtime, duty_start, duty_end, hours_per_day, working_days_per_month, overtime_multiplier, custom_hourly_rate, custom_daily_rate, profile_notes, updated_by, updated_at)
            VALUES (:id, :allow, :start, :end, :hours, :days, :multiplier, :custom_hourly, :custom_daily, :notes, :by, NOW())
            ON CONFLICT (employee_id) DO UPDATE SET
                allow_overtime = EXCLUDED.allow_overtime,
                duty_start = EXCLUDED.duty_start,
                duty_end = EXCLUDED.duty_end,
                hours_per_day = EXCLUDED.hours_per_day,
                working_days_per_month = EXCLUDED.working_days_per_month,
                overtime_multiplier = EXCLUDED.overtime_multiplier,
                custom_hourly_rate = EXCLUDED.custom_hourly_rate,
                custom_daily_rate = EXCLUDED.custom_daily_rate,
                profile_notes = EXCLUDED.profile_notes,
                updated_by = EXCLUDED.updated_by,
                updated_at = NOW()');
        $stmt->execute([
            ':id' => $employeeId,
            ':allow' => $allowOvertime ? 1 : 0,
            ':start' => $dutyStart,
            ':end' => $dutyEnd,
            ':hours' => $hoursPerDay,
            ':days' => $workingDaysPerMonth,
            ':multiplier' => $overtimeMultiplier,
            ':custom_hourly' => $customHourly,
            ':custom_daily' => $customDaily,
            ':notes' => $profileNotes,
            ':by' => $userId ?: null,
        ]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-PROFILE-SAVE', 'Failed saving employee payroll profile: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
        return false;
    }
}

/**
 * Fetch active branches. Returns associative array keyed by branch ID.
 */
function payroll_get_branches(PDO $pdo): array {
    try {
        $rows = $pdo->query('SELECT id, code, name FROM branches ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) { $map[(int)$row['id']] = $row; }
        return $map;
    } catch (Throwable $e) {
        // Avoid log spam if table absent; only log once per request
        static $logged = false;
        if (!$logged) {
            sys_log('PAYROLL-BRANCHES', 'Failed loading branches: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
            $logged = true;
        }
        return [];
    }
}

function payroll_fetch_branch_employees(PDO $pdo, array $branchIds = [], bool $onlyActive = true): array {
    if (!payroll_table_exists($pdo, 'employees')) {
        return [];
    }
    $sql = 'SELECT e.* FROM employees e WHERE e.branch_id IS NOT NULL AND e.deleted_at IS NULL';
    $params = [];
    if ($onlyActive) {
        $sql .= ' AND e.status = :emp_status';
        $params[':emp_status'] = 'active';
    }
    if ($branchIds) {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        if ($branchIds) {
            $placeholders = [];
            foreach ($branchIds as $idx => $branchId) {
                $param = ':branch_' . $idx;
                $placeholders[] = $param;
                $params[$param] = $branchId;
            }
            $sql .= ' AND e.branch_id IN (' . implode(',', $placeholders) . ')';
        }
    }
    $sql .= ' ORDER BY e.branch_id, e.last_name, e.first_name, e.id';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-BRANCH-EMPLOYEES', 'Failed fetching branch employees: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return [];
    }
    $grouped = [];
    foreach ($rows as $row) {
        $branchId = (int)($row['branch_id'] ?? 0);
        if ($branchId <= 0) {
            continue;
        }
        $grouped[$branchId][] = $row;
    }
    return $grouped;
}

function payroll_fetch_attendance_map(PDO $pdo, string $periodStart, string $periodEnd, array $branchIds = [], bool $onlyActive = true): array {
    if (!payroll_table_exists($pdo, 'attendance') || !payroll_table_exists($pdo, 'employees')) {
        return [];
    }
    $sql = 'SELECT a.employee_id, e.branch_id, e.employee_code, a.date, a.status
            FROM attendance a
            JOIN employees e ON e.id = a.employee_id
            WHERE a.date BETWEEN :start AND :end';
    $params = [
        ':start' => $periodStart,
        ':end' => $periodEnd,
    ];
    if ($onlyActive) {
        $sql .= ' AND e.status = :emp_status';
        $params[':emp_status'] = 'active';
    }
    if ($branchIds) {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        if ($branchIds) {
            $placeholders = [];
            foreach ($branchIds as $idx => $branchId) {
                $param = ':att_branch_' . $idx;
                $placeholders[] = $param;
                $params[$param] = $branchId;
            }
            $sql .= ' AND e.branch_id IN (' . implode(',', $placeholders) . ')';
        }
    }
    $sql .= ' ORDER BY e.branch_id, a.employee_id, a.date';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-ATTENDANCE-MAP', 'Failed fetching attendance records: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return [];
    }

    $map = [];
    $presenceStatuses = ['present', 'late', 'holiday', 'on-leave', 'submitted'];
    foreach ($rows as $row) {
        $branchId = (int)($row['branch_id'] ?? 0);
        $employeeId = (int)($row['employee_id'] ?? 0);
        $codeRaw = trim((string)($row['employee_code'] ?? ''));
        $codeKey = $codeRaw !== '' ? strtoupper($codeRaw) : null;
        $dateRaw = $row['date'] ?? null;
        $status = strtolower((string)($row['status'] ?? 'present'));
        if ($branchId <= 0 || $employeeId <= 0 || !$dateRaw) {
            continue;
        }
        $date = date('Y-m-d', strtotime((string)$dateRaw));
        if (!$date) {
            continue;
        }

        if (!isset($map[$branchId])) {
            $map[$branchId] = ['by_employee' => [], 'code_index' => []];
        }
        if (!isset($map[$branchId]['by_employee'][$employeeId])) {
            $map[$branchId]['by_employee'][$employeeId] = [
                'branch_id' => $branchId,
                'employee_id' => $employeeId,
                'employee_code' => $codeRaw,
                'present_dates' => [],
                'status_dates' => [],
                'status_counts' => [],
                'records' => [],
                'source' => 'attendance_table',
                'notes' => [],
            ];
        }
        if (!isset($map[$branchId]['by_employee'][$employeeId]['status_dates'][$status])) {
            $map[$branchId]['by_employee'][$employeeId]['status_dates'][$status] = [];
        }
        if (!in_array($date, $map[$branchId]['by_employee'][$employeeId]['status_dates'][$status], true)) {
            $map[$branchId]['by_employee'][$employeeId]['status_dates'][$status][] = $date;
        }
        $map[$branchId]['by_employee'][$employeeId]['records'][] = ['date' => $date, 'status' => $status];
        if (in_array($status, $presenceStatuses, true) && !in_array($date, $map[$branchId]['by_employee'][$employeeId]['present_dates'], true)) {
            $map[$branchId]['by_employee'][$employeeId]['present_dates'][] = $date;
        }
        if ($codeKey !== null) {
            $map[$branchId]['code_index'][$codeKey] = $employeeId;
        }
    }

    foreach ($map as $branchId => &$branchPayload) {
        foreach ($branchPayload['by_employee'] as $employeeId => &$bucket) {
            sort($bucket['present_dates']);
            foreach ($bucket['status_dates'] as $status => $dates) {
                $uniqueDates = array_values(array_unique($dates));
                sort($uniqueDates);
                $bucket['status_dates'][$status] = $uniqueDates;
                $bucket['status_counts'][$status] = count($uniqueDates);
            }
            ksort($bucket['status_counts']);
        }
        unset($bucket);
        ksort($branchPayload['code_index']);
    }
    unset($branchPayload);

    return $map;
}

function payroll_get_branch_attendance_snapshot(PDO $pdo, string $periodStart, string $periodEnd, array $branchIds = []): array {
    $branches = payroll_get_branches($pdo);
    if (!$branches) {
        return [];
    }
    if ($branchIds) {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        $branches = array_intersect_key($branches, array_flip($branchIds));
    }
    if (!$branches) {
        return [];
    }
    try {
        $start = new DateTime($periodStart);
        $end = new DateTime($periodEnd);
    } catch (Throwable $e) {
        return [];
    }
    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }
    $startDate = $start->format('Y-m-d');
    $endDate = $end->format('Y-m-d');

    $snapshot = [];
    $branchIdList = array_keys($branches);
    foreach ($branchIdList as $branchId) {
        $snapshot[$branchId] = [
            'branch' => $branches[$branchId],
            'employees' => ['active' => 0],
            'attendance' => [
                'records' => 0,
                'distinct_employees' => 0,
                'last_record_date' => null,
                'last_captured_at' => null,
            ],
        ];
    }

    if (!payroll_table_exists($pdo, 'employees')) {
        return $snapshot;
    }

    $employeeSql = 'SELECT branch_id, COUNT(*) AS cnt FROM employees WHERE branch_id IS NOT NULL AND status = :status AND deleted_at IS NULL';
    $employeeParams = [':status' => 'active'];
    $placeholders = [];
    foreach ($branchIdList as $idx => $branchId) {
        $ph = ':emp_branch_' . $idx;
        $placeholders[] = $ph;
        $employeeParams[$ph] = $branchId;
    }
    if ($placeholders) {
        $employeeSql .= ' AND branch_id IN (' . implode(',', $placeholders) . ')';
    }
    $employeeSql .= ' GROUP BY branch_id';
    try {
        $stmt = $pdo->prepare($employeeSql);
        $stmt->execute($employeeParams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $branchId = (int)($row['branch_id'] ?? 0);
            if (isset($snapshot[$branchId])) {
                $snapshot[$branchId]['employees']['active'] = (int)($row['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-ATT-SNAPSHOT', 'Failed loading employee totals: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return $snapshot;
    }

    if (!payroll_table_exists($pdo, 'attendance')) {
        return $snapshot;
    }

    $attendanceSql = 'SELECT e.branch_id, COUNT(*) AS records, COUNT(DISTINCT a.employee_id) AS employees, MAX(a.date) AS last_record_date, MAX(COALESCE(a.updated_at, a.created_at)) AS last_captured_at
                      FROM attendance a
                      JOIN employees e ON e.id = a.employee_id
                      WHERE e.status = :emp_status AND a.date BETWEEN :start AND :end';
    $attendanceParams = [
        ':emp_status' => 'active',
        ':start' => $startDate,
        ':end' => $endDate,
    ];
    $attPlaceholders = [];
    foreach ($branchIdList as $idx => $branchId) {
        $ph = ':att_branch_' . $idx;
        $attPlaceholders[] = $ph;
        $attendanceParams[$ph] = $branchId;
    }
    if ($attPlaceholders) {
        $attendanceSql .= ' AND e.branch_id IN (' . implode(',', $attPlaceholders) . ')';
    }
    $attendanceSql .= ' GROUP BY e.branch_id';

    try {
        $stmt = $pdo->prepare($attendanceSql);
        $stmt->execute($attendanceParams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $branchId = (int)($row['branch_id'] ?? 0);
            if (!isset($snapshot[$branchId])) {
                continue;
            }
            $snapshot[$branchId]['attendance']['records'] = (int)($row['records'] ?? 0);
            $snapshot[$branchId]['attendance']['distinct_employees'] = (int)($row['employees'] ?? 0);
            $lastDateRaw = $row['last_record_date'] ?? null;
            $lastCapturedRaw = $row['last_captured_at'] ?? null;
            if ($lastDateRaw) {
                $snapshot[$branchId]['attendance']['last_record_date'] = date('Y-m-d', strtotime((string)$lastDateRaw));
            }
            if ($lastCapturedRaw) {
                $snapshot[$branchId]['attendance']['last_captured_at'] = date('Y-m-d H:i', strtotime((string)$lastCapturedRaw));
            }
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-ATT-SNAPSHOT', 'Failed loading attendance stats: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
    }

    return $snapshot;
}

function payroll_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table LIMIT 1");
        $stmt->execute([':table' => strtolower($table)]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function payroll_table_has_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table) . ':' . strtolower($column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column LIMIT 1"
        );
        $stmt->execute([':table' => strtolower($table), ':column' => strtolower($column)]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function payroll_complaint_has_column(PDO $pdo, string $column): bool {
    return payroll_table_has_column($pdo, 'payroll_complaints', $column);
}

function payroll_quiet_rollback(PDO $pdo): void {
    try {
        $pdo->rollBack();
        return;
    } catch (Throwable $e) {
        // If PDO believes no transaction is active, attempt a manual ROLLBACK to clear aborted states.
    }

    try {
        $pdo->exec('ROLLBACK');
    } catch (Throwable $ignored) {
        // Swallow secondary failures; connection will naturally reset on next request if needed.
    }
}

function payroll_ensure_payslip_columns(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    // Check if we're in a transaction - if so, do NOT run DDL as it could abort the transaction
    $inTransaction = false;
    try {
        $inTransaction = $pdo->inTransaction();
    } catch (Throwable $e) {
        // Ignore
    }
    
    if ($inTransaction) {
        sys_log('PAYROLL-PAYSLIP-DDL', 'Skipping column check - already in transaction', ['module' => 'payroll']);
        $ensured = true;
        return;
    }

    $ddl = [];

    $definitions = [
        'earnings_json' => "ALTER TABLE payslips ADD COLUMN earnings_json JSONB DEFAULT '[]'::jsonb",
        'deductions_json' => "ALTER TABLE payslips ADD COLUMN deductions_json JSONB DEFAULT '[]'::jsonb",
        'rollup_meta' => "ALTER TABLE payslips ADD COLUMN rollup_meta JSONB DEFAULT '{}'::jsonb",
        'change_reason' => "ALTER TABLE payslips ADD COLUMN change_reason VARCHAR(255) NULL",
    ];

    foreach ($definitions as $column => $sql) {
        if (!payroll_table_has_column($pdo, 'payslips', $column)) {
            $ddl[] = $sql;
        }
    }

    foreach ($ddl as $sql) {
        try {
            $pdo->exec($sql);
            // If DDL failed and aborted transaction, rollback to clear error state
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            sys_log('PAYROLL-PAYSLIP-DDL', 'Failed ensuring payslip column: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['sql' => $sql]]);
            // Rollback to clear any transaction abort state
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable $rb) {
                // Ignore rollback errors
            }
        }
    }

    try {
        if (payroll_table_has_column($pdo, 'payslips', 'earnings_json')) {
            $pdo->exec("UPDATE payslips SET earnings_json = '[]'::jsonb WHERE earnings_json IS NULL");
        }
        if (payroll_table_has_column($pdo, 'payslips', 'deductions_json')) {
            $pdo->exec("UPDATE payslips SET deductions_json = '[]'::jsonb WHERE deductions_json IS NULL");
        }
        // If UPDATE failed and aborted transaction, rollback to clear error state
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-PAYSLIP-DEFAULTS', 'Failed normalizing payslip JSON defaults: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        // Rollback to clear any transaction abort state
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rb) {
            // Ignore rollback errors
        }
    }

    $ensured = true;
}

/**
 * Placeholder for calculating standard rates (daily, hourly, minute).
 */
function payroll_calculate_rates(array $employee, array $rateConfigs = [], array $overrides = []): array {
    // Use employee salary if set, otherwise fall back to position base_salary
    $employeeSalary = (float)($employee['salary'] ?? 0);
    $positionSalary = (float)($employee['position_base_salary'] ?? 0);
    $monthly = max(0.0, $employeeSalary > 0 ? $employeeSalary : $positionSalary);

    $workingDaysPerMonth = 22;
    $hoursPerDay = 8;

    if (isset($overrides['working_days_per_month']) && (int)$overrides['working_days_per_month'] > 0) {
        $workingDaysPerMonth = (int)$overrides['working_days_per_month'];
    } elseif (isset($rateConfigs['working_days_per_month'])) {
        $candidate = (float)($rateConfigs['working_days_per_month']['override_value'] ?? $rateConfigs['working_days_per_month']['default_value'] ?? $workingDaysPerMonth);
        if ($candidate > 0) {
            $workingDaysPerMonth = (int)round($candidate);
        }
    } elseif (isset($rateConfigs['rate_computation_defaults']['config']['working_days_per_month'])) {
        $candidate = (int)$rateConfigs['rate_computation_defaults']['config']['working_days_per_month'];
        if ($candidate > 0) {
            $workingDaysPerMonth = $candidate;
        }
    }

    if (isset($overrides['hours_per_day']) && (int)$overrides['hours_per_day'] > 0) {
        $hoursPerDay = (int)$overrides['hours_per_day'];
    } elseif (isset($rateConfigs['hours_per_day'])) {
        $candidate = (float)($rateConfigs['hours_per_day']['override_value'] ?? $rateConfigs['hours_per_day']['default_value'] ?? $hoursPerDay);
        if ($candidate > 0) {
            $hoursPerDay = (int)round($candidate);
        }
    } elseif (isset($rateConfigs['rate_computation_defaults']['config']['hours_per_day'])) {
        $candidate = (int)$rateConfigs['rate_computation_defaults']['config']['hours_per_day'];
        if ($candidate > 0) {
            $hoursPerDay = $candidate;
        }
    }

    $biMonthly = round($monthly / 2, 2);
    $dailyRate = $workingDaysPerMonth > 0 ? $monthly / $workingDaysPerMonth : 0.0;
    $hourlyRate = $hoursPerDay > 0 ? $dailyRate / $hoursPerDay : 0.0;
    $minuteRate = $hourlyRate / 60;

    return [
        'monthly' => round($monthly, 2),
        'bi_monthly' => $biMonthly,
        'daily' => round($dailyRate, 2),
        'hourly' => round($hourlyRate, 2),
        'per_minute' => round($minuteRate, 3),
        'working_days_per_month' => $workingDaysPerMonth,
        'hours_per_day' => $hoursPerDay,
    ];
}

function payroll_apply_profile_rate_overrides(array $rates, ?array $profileOverrides = null): array {
    if (!is_array($profileOverrides)) {
        return $rates;
    }

    $hoursPerDay = (int)($profileOverrides['hours_per_day'] ?? $rates['hours_per_day'] ?? 8);
    if ($hoursPerDay <= 0) {
        $hoursPerDay = 8;
    }

    if (isset($profileOverrides['custom_hourly_rate']) && $profileOverrides['custom_hourly_rate'] !== null && $profileOverrides['custom_hourly_rate'] !== '') {
        $hourly = round(max(0.0, (float)$profileOverrides['custom_hourly_rate']), 2);
        if ($hourly > 0) {
            $rates['hourly'] = $hourly;
            $rates['daily'] = round($hourly * $hoursPerDay, 2);
            $rates['per_minute'] = round($hourly / 60, 3);
        }
    } elseif (isset($profileOverrides['custom_daily_rate']) && $profileOverrides['custom_daily_rate'] !== null && $profileOverrides['custom_daily_rate'] !== '') {
        $daily = round(max(0.0, (float)$profileOverrides['custom_daily_rate']), 2);
        if ($daily > 0) {
            $rates['daily'] = $daily;
            $hourly = $hoursPerDay > 0 ? $daily / $hoursPerDay : 0.0;
            $rates['hourly'] = round($hourly, 2);
            $rates['per_minute'] = round($hourly / 60, 3);
        }
    }

    return $rates;
}

function payroll_resolve_overtime_multiplier(?array $profileOverrides = null): float {
    $default = 1.25;
    if (!is_array($profileOverrides)) {
        return $default;
    }
    $value = isset($profileOverrides['overtime_multiplier']) && is_numeric($profileOverrides['overtime_multiplier'])
        ? (float)$profileOverrides['overtime_multiplier']
        : $default;
    if ($value <= 0) {
        $value = $default;
    }
    return round($value, 3);
}

function payroll_fetch_overtime_summary(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd, float $baseHourlyRate, float $overtimeMultiplier, ?int $payrollRunId = null): array {
    $summary = [
        'request_count' => 0,
        'pending_count' => 0,
        'total_hours' => 0.0,
        'total_amount' => 0.0,
        'base_hourly_rate' => round(max(0.0, $baseHourlyRate), 2),
        'multiplier' => round(max(0.0, $overtimeMultiplier), 3),
        'requests' => [],
    ];

    if ($employeeId <= 0 || !payroll_table_exists($pdo, 'overtime_requests')) {
        return $summary;
    }

    $periodStart = date('Y-m-d', strtotime($periodStart));
    $periodEnd = date('Y-m-d', strtotime($periodEnd));
    if (!$periodStart || !$periodEnd) {
        return $summary;
    }

    $params = [
        ':emp' => $employeeId,
        ':start' => $periodStart,
        ':end' => $periodEnd,
    ];

        $sql = "SELECT id, overtime_date, hours_worked AS hours, status, approved_at, included_in_payroll_run_id
            FROM overtime_requests
            WHERE employee_id = :emp
              AND overtime_date BETWEEN :start AND :end
              AND status IN ('approved','paid')";

    if ($payrollRunId !== null) {
        $sql .= ' AND (included_in_payroll_run_id IS NULL OR included_in_payroll_run_id = :run)';
        $params[':run'] = $payrollRunId;
    }

    try {
        $stmt = $pdo->prepare($sql . ' ORDER BY overtime_date');
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hours = round(max(0.0, (float)($row['hours'] ?? 0)), 2);
            if ($hours <= 0) {
                continue;
            }
            $amount = round($hours * $summary['base_hourly_rate'] * $summary['multiplier'], 2);
            $summary['requests'][] = [
                'id' => (int)($row['id'] ?? 0),
                'date' => $row['overtime_date'] ?? null,
                'hours' => $hours,
                'status' => strtolower((string)($row['status'] ?? 'approved')),
                'approved_at' => $row['approved_at'] ?? null,
                'included_in_payroll_run_id' => isset($row['included_in_payroll_run_id']) ? (int)$row['included_in_payroll_run_id'] : null,
                'amount' => $amount,
            ];
            $summary['total_hours'] += $hours;
            $summary['total_amount'] += $amount;
            $summary['request_count']++;
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-OT-SUMMARY', 'Failed fetching overtime summary: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
    }

    try {
        $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM overtime_requests WHERE employee_id = :emp AND overtime_date BETWEEN :start AND :end AND status = 'pending'");
        $pendingStmt->execute([':emp' => $employeeId, ':start' => $periodStart, ':end' => $periodEnd]);
        $summary['pending_count'] = (int)$pendingStmt->fetchColumn();
    } catch (Throwable $e) {
        sys_log('PAYROLL-OT-PENDING', 'Failed counting pending overtime: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
    }

    $summary['total_hours'] = round($summary['total_hours'], 2);
    $summary['total_amount'] = round($summary['total_amount'], 2);

    return $summary;
}

function payroll_get_complaint_categories(): array {
    return [
        'earnings' => [
            'label' => 'Earnings',
            'items' => [
                'basic_pay' => 'Base Pay / Salary',
                'overtime' => 'Overtime Pay',
                'allowances' => 'Allowances & Incentives',
                'adjustments' => 'Manual Adjustments',
            ],
        ],
        'deductions' => [
            'label' => 'Deductions',
            'items' => [
                'tax' => 'Income Tax',
                'government' => 'Government Contributions',
                'loans' => 'Loan / Cash Advance',
                'other_deductions' => 'Other Deductions',
            ],
        ],
        'benefits' => [
            'label' => 'Benefits',
            'items' => [
                'health' => 'Health Insurance',
                'retirement' => 'Retirement / Provident',
                'insurance' => 'Other Insurance',
            ],
        ],
        'timekeeping' => [
            'label' => 'Timekeeping',
            'items' => [
                'attendance' => 'Attendance / Absences',
                'overtime_tracking' => 'Overtime Tracking',
                'holiday' => 'Holiday Credit',
            ],
        ],
        'compliance' => [
            'label' => 'Compliance & Support',
            'items' => [
                'statutory' => 'Statutory Filing',
                'documentation' => 'Supporting Documents',
                'system_issue' => 'System Issue',
            ],
        ],
    ];
}

/** Determine SSS monthly salary credit rounded to the nearest PHP 500 within statutory bounds. */
function payroll__resolve_sss_msc(float $monthlySalary): float {
    if ($monthlySalary <= 0) {
        return 0.0;
    }
    $min = 3250; // 2024/2025 SSS table minimum MSC for employed members
    $max = 35000; // Updated per RA 11199 / SSS Circular 2024-001 (effective Jan 2025)
    $msc = ceil($monthlySalary / 500) * 500;
    if ($msc < $min) {
        $msc = $min;
    }
    if ($msc > $max) {
        $msc = $max;
    }
    return (float)$msc;
}

/** Compute employee-side SSS + WISP contribution for the given monthly salary. */
function payroll_calculate_sss_contribution(float $monthlySalary): array {
    $msc = payroll__resolve_sss_msc($monthlySalary);
    if ($msc <= 0) {
        return ['monthly' => 0.0, 'per_period' => 0.0, 'details' => ['msc' => 0, 'sss_employee' => 0, 'wisp_employee' => 0]];
    }
    $sssEmployee = round($msc * 0.045, 2); // 4.5% employee share
    $wispEmployee = round($msc * 0.005, 2); // 0.5% employee share
    $monthlyTotal = round($sssEmployee + $wispEmployee, 2);
    return [
        'monthly' => $monthlyTotal,
        'per_period' => round($monthlyTotal / 2, 2),
        'details' => [
            'msc' => $msc,
            'sss_employee' => $sssEmployee,
            'wisp_employee' => $wispEmployee,
        ],
    ];
}

/** Compute PhilHealth contribution (employee share) based on the 5% premium with 10k-90k cap. */
function payroll_calculate_philhealth_contribution(float $monthlySalary): array {
    if ($monthlySalary <= 0) {
        return ['monthly' => 0.0, 'per_period' => 0.0, 'details' => ['basis' => 0, 'premium' => 0]];
    }
    $minBase = 10000;
    $maxBase = 90000;
    $basis = max($minBase, min($monthlySalary, $maxBase));
    $monthlyPremium = round($basis * 0.05, 2);
    $employeeMonthly = round($monthlyPremium / 2, 2);
    return [
        'monthly' => $employeeMonthly,
        'per_period' => round($employeeMonthly / 2, 2),
        'details' => [
            'basis' => $basis,
            'premium' => $monthlyPremium,
        ],
    ];
}

/** Compute Pag-IBIG (HDMF) employee share with the PHP 100 cap, typically deducted in full per pay run. */
function payroll_calculate_pagibig_contribution(float $monthlySalary): array {
    if ($monthlySalary <= 0) {
        return ['monthly' => 0.0, 'per_period' => 0.0, 'details' => ['rate' => 0]];
    }
    $raw = round($monthlySalary * 0.02, 2); // 2% employee share
    $monthly = round(min($raw, 100.00), 2);
    return [
        'monthly' => $monthly,
        'per_period' => $monthly, // employer policy: take in full each pay run per client spec
        'details' => [
            'rate' => 0.02,
        ],
    ];
}

/** Compute annual withholding tax using TRAIN law brackets (effective 2023 onward). */
function payroll__compute_annual_withholding_tax(float $annualTaxable): float {
    if ($annualTaxable <= 0) {
        return 0.0;
    }
    if ($annualTaxable <= 250000) {
        return 0.0;
    }
    if ($annualTaxable <= 400000) {
        return ($annualTaxable - 250000) * 0.15;
    }
    if ($annualTaxable <= 800000) {
        return 22500 + ($annualTaxable - 400000) * 0.20;
    }
    if ($annualTaxable <= 2000000) {
        return 102500 + ($annualTaxable - 800000) * 0.25;
    }
    if ($annualTaxable <= 8000000) {
        return 402500 + ($annualTaxable - 2000000) * 0.30;
    }
    return 2202500 + ($annualTaxable - 8000000) * 0.35;
}

/** Determine withholding tax deductions for the period. */
function payroll_calculate_withholding_tax(float $monthlySalary, float $monthlySSS, float $monthlyPhilHealth, float $monthlyPagibig, ?float $percentageOverride = null): array {
    $taxableMonthly = max(0.0, $monthlySalary - ($monthlySSS + $monthlyPhilHealth + $monthlyPagibig));
    $annualTaxable = $taxableMonthly * 12;
    $details = [
        'taxable_monthly' => round($taxableMonthly, 2),
        'taxable_annual' => round($annualTaxable, 2),
    ];

    if ($percentageOverride !== null) {
        $pct = max(0.0, min(100.0, round((float)$percentageOverride, 4)));
        $monthlyTax = round($taxableMonthly * ($pct / 100), 2);
        $annualTax = round($monthlyTax * 12, 2);
        $details['override'] = [
            'type' => 'percentage',
            'value' => $pct,
            'monthly_tax' => $monthlyTax,
            'annual_tax' => $annualTax,
        ];
        return [
            'annual' => $annualTax,
            'monthly' => $monthlyTax,
            'per_period' => round($monthlyTax / 2, 2),
            'details' => $details,
            'override_applied' => true,
        ];
    }

    $annualTax = round(payroll__compute_annual_withholding_tax($annualTaxable), 2);
    return [
        'annual' => $annualTax,
        'monthly' => round($annualTax / 12, 2),
        'per_period' => round($annualTax / 24, 2),
        'details' => $details,
        'override_applied' => false,
    ];
}

function payroll_get_complaint_priorities(): array {
    return [
        'normal' => 'Normal',
        'urgent' => 'Urgent',
    ];
}

function payroll_resolve_complaint_category(?string $categoryCode, ?string $subcategoryCode): array {
    $categories = payroll_get_complaint_categories();
    $categoryCode = $categoryCode ? strtolower(trim($categoryCode)) : null;
    $subcategoryCode = $subcategoryCode ? strtolower(trim($subcategoryCode)) : null;
    if ($categoryCode && isset($categories[$categoryCode])) {
        $items = $categories[$categoryCode]['items'] ?? [];
        if ($subcategoryCode && isset($items[$subcategoryCode])) {
            return [
                'valid' => true,
                'category_code' => $categoryCode,
                'category_label' => $categories[$categoryCode]['label'],
                'subcategory_code' => $subcategoryCode,
                'subcategory_label' => $items[$subcategoryCode],
                'label' => $items[$subcategoryCode],
            ];
        }
    }
    return [
        'valid' => false,
        'category_code' => null,
        'category_label' => null,
        'subcategory_code' => null,
        'subcategory_label' => null,
        'label' => '',
    ];
}

/**
 * Create a payroll run record.
 */
function payroll_create_run(PDO $pdo, string $periodStart, string $periodEnd, int $userId, ?string $notes = null, ?int $approvalTemplateId = null, ?string $runMode = null, ?string $computationMode = null): ?int {
    try {
        if (!payroll_table_exists($pdo, 'payroll_runs')) {
            sys_log('PAYROLL-CREATE', 'Cannot create payroll run; payroll_runs table missing', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
            return null;
        }
        $pdo->beginTransaction();
        $cols = ['period_start','period_end','notes','generated_by'];
        $vals = [':start', ':end', ':notes', ':user'];
        $params = [
            ':start' => $periodStart,
            ':end' => $periodEnd,
            ':notes' => $notes,
            ':user' => $userId ?: null,
        ];

        if (payroll_table_has_column($pdo, 'payroll_runs', 'initiated_by')) {
            $cols[] = 'initiated_by';
            $vals[] = ':initiated_by';
            $params[':initiated_by'] = $userId ?: null;
        }

        if ($approvalTemplateId !== null) {
            if (payroll_table_has_column($pdo, 'payroll_runs', 'approval_template_id')) {
                $cols[] = 'approval_template_id';
                $vals[] = ':template_id';
                $params[':template_id'] = $approvalTemplateId;
            } else {
                sys_log('PAYROLL-CREATE', 'Skipped approval_template_id column; missing in schema', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
            }
        }

        if ($runMode !== null && $runMode !== '') {
            if (payroll_table_has_column($pdo, 'payroll_runs', 'run_mode')) {
                $cols[] = 'run_mode';
                $vals[] = ':run_mode';
                $params[':run_mode'] = $runMode;
            } else {
                sys_log('PAYROLL-CREATE', 'Skipped run_mode column; missing in schema', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
            }
        }

        if ($computationMode !== null && $computationMode !== '') {
            if (payroll_table_has_column($pdo, 'payroll_runs', 'computation_mode')) {
                $cols[] = 'computation_mode';
                $vals[] = ':comp_mode';
                $params[':comp_mode'] = $computationMode;
            } else {
                sys_log('PAYROLL-CREATE', 'Skipped computation_mode column; missing in schema', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
            }
        }
        $sql = 'INSERT INTO payroll_runs (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ') RETURNING id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $runId = (int)$stmt->fetchColumn();
        if ($runId <= 0) {
            $pdo->rollBack();
            return null;
        }
        $pdo->commit();
        audit('payroll_run_created', json_encode(['run_id' => $runId, 'period_start' => $periodStart, 'period_end' => $periodEnd], JSON_UNESCAPED_SLASHES));
        return $runId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $inner) {}
        }
        sys_log('PAYROLL-RUN-CREATE', 'Failed creating payroll run: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return null;
    }
}

/**
 * Initialize payroll_batches for a run (one per selected branch).
 */
function payroll_init_batches_for_run(PDO $pdo, int $runId, ?array $branchIds = null): bool {
    try {
        if (!payroll_table_exists($pdo, 'branches') || !payroll_table_exists($pdo, 'payroll_batches')) {
            sys_log('PAYROLL-BATCHES-INIT', 'Cannot initialize batches; required tables missing', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
            return false;
        }
        $branches = payroll_get_branches($pdo);
        if ($branchIds === null) {
            $branchIds = array_keys($branches);
        } else {
            $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
            $branchIds = array_values(array_intersect($branchIds, array_keys($branches)));
        }
        if (!$branchIds) {
            audit('payroll_batches_initialized', json_encode(['run_id' => $runId, 'branch_ids' => []], JSON_UNESCAPED_SLASHES));
            return true;
        }
        $pdo->beginTransaction();
        $templateId = null;
        try {
            $stmt = $pdo->prepare('SELECT approval_template_id FROM payroll_runs WHERE id = :id');
            $stmt->execute([':id' => $runId]);
            $templateId = (int)($stmt->fetchColumn() ?: 0) ?: null;
        } catch (Throwable $e1) {}

        $ins = $pdo->prepare('INSERT INTO payroll_batches (payroll_run_id, branch_id, approval_template_id) VALUES (:run_id, :branch_id, :template_id)
                              ON CONFLICT (payroll_run_id, branch_id) DO NOTHING');
        foreach ($branchIds as $branchId) {
            $ins->execute([
                ':run_id' => $runId,
                ':branch_id' => $branchId,
                ':template_id' => $templateId,
            ]);
        }
        if ($templateId) {
            $snapshot = payroll_build_template_approver_snapshot($pdo, $templateId);
            if ($snapshot) {
                $updSql = 'UPDATE payroll_batches SET approvers_chain = :chain::jsonb WHERE payroll_run_id = :run AND approvers_chain IS NULL';
                if ($branchIds) {
                    $placeholders = [];
                    $params = [
                        ':chain' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                        ':run' => $runId,
                    ];
                    foreach ($branchIds as $idx => $branchId) {
                        $ph = ':branch_' . $idx;
                        $placeholders[] = $ph;
                        $params[$ph] = $branchId;
                    }
                    $updSql .= ' AND branch_id IN (' . implode(',', $placeholders) . ')';
                    $upd = $pdo->prepare($updSql);
                    $upd->execute($params);
                } else {
                    $upd = $pdo->prepare($updSql);
                    $upd->execute([':chain' => json_encode($snapshot, JSON_UNESCAPED_SLASHES), ':run' => $runId]);
                }
            }
        }
        $pdo->commit();
        audit('payroll_batches_initialized', json_encode(['run_id' => $runId, 'branch_ids' => $branchIds], JSON_UNESCAPED_SLASHES));
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $inner) {}
        }
        sys_log('PAYROLL-BATCHES-INIT', 'Failed initializing batches: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return false;
    }
}

/**
 * Initialize payroll_branch_submissions for a run (one per branch).
 * Creates pending submission records for each branch to track document uploads.
 */
function payroll_init_branch_submissions_for_run(PDO $pdo, int $runId, ?array $branchIds = null): bool {
    try {
        if (!payroll_table_exists($pdo, 'branches') || !payroll_table_exists($pdo, 'payroll_branch_submissions')) {
            sys_log('PAYROLL-SUBMISSIONS-INIT', 'Cannot initialize branch submissions; required tables missing', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
            return false;
        }
        
        // Get all branches or specified subset
        $branches = payroll_get_branches($pdo);
        if ($branchIds === null) {
            $branchIds = array_keys($branches);
        } else {
            $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
            $branchIds = array_values(array_intersect($branchIds, array_keys($branches)));
        }
        
        if (!$branchIds) {
            audit('payroll_branch_submissions_initialized', json_encode(['run_id' => $runId, 'branch_ids' => []], JSON_UNESCAPED_SLASHES));
            return true;
        }
        
        $pdo->beginTransaction();
        
        // Insert submission records with ON CONFLICT to handle existing records
        $ins = $pdo->prepare('INSERT INTO payroll_branch_submissions (payroll_run_id, branch_id, status) 
                              VALUES (:run_id, :branch_id, :status)
                              ON CONFLICT (payroll_run_id, branch_id) DO NOTHING');
        
        foreach ($branchIds as $branchId) {
            $ins->execute([
                ':run_id' => $runId,
                ':branch_id' => $branchId,
                ':status' => 'pending',
            ]);
        }
        
        $pdo->commit();
        audit('payroll_branch_submissions_initialized', json_encode(['run_id' => $runId, 'branch_ids' => $branchIds], JSON_UNESCAPED_SLASHES));
        action_log('payroll', 'branch_submissions_initialized', 'success', ['run_id' => $runId, 'branch_count' => count($branchIds)]);
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $inner) {}
        }
        sys_log('PAYROLL-SUBMISSIONS-INIT', 'Failed initializing branch submissions: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return false;
    }
}

/**
 * List payroll_batches for a run with branch info.
 */
function payroll_list_batches(PDO $pdo, int $runId): array {
    if (!payroll_table_exists($pdo, 'payroll_batches')) {
        return [];
    }
        $sql = 'SELECT pb.*, b.code AS branch_code, b.name AS branch_name,
               u.full_name AS submitted_by_name, u.email AS submitted_by_email
            FROM payroll_batches pb
            JOIN branches b ON b.id = pb.branch_id
            LEFT JOIN users u ON u.id = pb.submitted_by
            WHERE pb.payroll_run_id = :run
            ORDER BY b.name, b.code';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-BATCHES-LIST', 'Failed listing batches: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return [];
    }
}

/**
 * Update payroll_batch status; when transitioning to submitted, stamp submitted_by and submitted_at.
 */
function payroll_update_batch_status(PDO $pdo, int $batchId, string $status, ?int $actingUserId = null, ?string $remarks = null): bool {
    $status = strtolower(trim($status));
    $allowed = ['pending','awaiting_dtr','submitted','computing','for_review','for_revision','approved','released','closed','error'];
    if (!in_array($status, $allowed, true)) { $status = 'pending'; }
    try {
        if ($status === 'submitted') {
            $stmt = $pdo->prepare("UPDATE payroll_batches
                SET status = :st, submitted_by = COALESCE(:uid, submitted_by), submission_meta = CASE WHEN :remarks IS NOT NULL THEN jsonb_set(COALESCE(submission_meta,'{}'::jsonb), '{remarks}', to_jsonb(:remarks::text), true) ELSE submission_meta END, updated_at = NOW()
                WHERE id = :id");
            $stmt->execute([':st' => $status, ':uid' => $actingUserId ?: null, ':remarks' => $remarks, ':id' => $batchId]);
        } else {
            $stmt = $pdo->prepare("UPDATE payroll_batches SET status = :st, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':st' => $status, ':id' => $batchId]);
        }
        action_log('payroll', 'batch_status_update', 'success', ['batch_id' => $batchId, 'status' => $status]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-BATCH-UPDATE', 'Failed updating batch status: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['batch_id' => $batchId, 'status' => $status]]);
        return false;
    }
}

/**
 * Count payroll_batches per status for a given run.
 */
function payroll_batch_status_counts(PDO $pdo, int $runId): array {
    $counts = [];
    try {
        $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM payroll_batches WHERE payroll_run_id = :run GROUP BY status");
        $stmt->execute([':run' => $runId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[strtolower((string)$row['status'])] = (int)$row['cnt'];
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-BATCH-COUNTS', 'Failed counting batches: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
    }
    return $counts;
}

/**
 * Fetch list of payroll runs with optional limit.
 */
function payroll_list_runs(PDO $pdo, int $limit = 20): array {
    $sql = 'SELECT pr.*, gb.full_name AS generated_by_name, rb.full_name AS released_by_name
            FROM payroll_runs pr
            LEFT JOIN users gb ON gb.id = pr.generated_by
            LEFT JOIN users rb ON rb.id = pr.released_by
            ORDER BY pr.id DESC
            LIMIT :limit';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-RUN-LIST', 'Failed listing runs: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
        return [];
    }
}

function payroll_get_run(PDO $pdo, int $runId): ?array {
    $sql = 'SELECT pr.*, gb.full_name AS generated_by_name, rb.full_name AS released_by_name
            FROM payroll_runs pr
            LEFT JOIN users gb ON gb.id = pr.generated_by
            LEFT JOIN users rb ON rb.id = pr.released_by
            WHERE pr.id = :id
            LIMIT 1';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        sys_log('PAYROLL-RUN-GET', 'Failed fetching run: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return null;
    }
}

function payroll_get_run_submissions(PDO $pdo, int $runId): array {
    if (!payroll_table_exists($pdo, 'payroll_branch_submissions')) {
        return [];
    }
    $sql = 'SELECT r.*, b.code, b.name
            FROM payroll_branch_submissions r
            LEFT JOIN branches b ON b.id = r.branch_id
            WHERE r.payroll_run_id = :run
            ORDER BY b.name';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-SUBMISSIONS', 'Failed fetching branch submissions: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return [];
    }
}

function payroll_initialize_approvals(PDO $pdo, int $runId): bool {
    try {
        // If approvals already exist, do nothing
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_run_approvals WHERE payroll_run_id = :id');
        $stmt->execute([':id' => $runId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }

        // Check if run has a template assigned
        $tplId = null;
        try {
            $s = $pdo->prepare('SELECT approval_template_id FROM payroll_runs WHERE id = :id');
            $s->execute([':id' => $runId]);
            $tplId = (int)($s->fetchColumn() ?: 0) ?: null;
        } catch (Throwable $inner) {}

        if ($tplId) {
            // Build template-driven approvals (one approver per step via role -> user mapping)
            $steps = [];
            $st = $pdo->prepare('SELECT step_order, role FROM payroll_approval_chain_steps WHERE template_id = :tid ORDER BY step_order');
            $st->execute([':tid' => $tplId]);
            $steps = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$steps) {
                sys_log('PAYROLL-APPROVALS', 'Template has no steps; falling back to global approvers', ['module' => 'payroll', 'context' => ['run_id' => $runId, 'template_id' => $tplId]]);
            } else {
                $pdo->beginTransaction();
                $insApprover = $pdo->prepare('INSERT INTO payroll_approvers (user_id, step_order, applies_to, active) VALUES (:uid, :step, :scope, TRUE) RETURNING id');
                $insRunAppr = $pdo->prepare('INSERT INTO payroll_run_approvals (payroll_run_id, approver_id, step_order) VALUES (:run, :appr, :step)');
                $scope = 'run:' . $runId;
                $createdCount = 0;
                foreach ($steps as $row) {
                    $stepOrder = (int)($row['step_order'] ?? 1);
                    $role = (string)$row['role'];
                    $usr = $pdo->prepare('SELECT id FROM users WHERE role = :role ORDER BY id LIMIT 1');
                    $usr->execute([':role' => $role]);
                    $userId = (int)($usr->fetchColumn() ?: 0);
                    if ($userId <= 0) {
                        sys_log('PAYROLL-APPROVALS', 'No user found for template role', ['module' => 'payroll', 'context' => ['run_id' => $runId, 'role' => $role, 'step' => $stepOrder]]);
                        continue; // skip this step; maintain sequence for others
                    }
                    $insApprover->execute([':uid' => $userId, ':step' => $stepOrder, ':scope' => $scope]);
                    $approverId = (int)$insApprover->fetchColumn();
                    $insRunAppr->execute([':run' => $runId, ':appr' => $approverId, ':step' => $stepOrder]);
                    $createdCount++;
                }
                if ($createdCount > 0) {
                    $pdo->commit();
                    return true;
                }
                // Nothing created; roll back to fallback
                try { $pdo->rollBack(); } catch (Throwable $inner2) {}
            }
        }

        // Fallback to global list
    $approvers = $pdo->query("SELECT id, step_order FROM payroll_approvers WHERE active = TRUE AND (applies_to IS NULL OR applies_to = 'global') ORDER BY step_order, id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$approvers) {
            sys_log('PAYROLL-APPROVALS', 'No active payroll approvers configured', ['module' => 'payroll', 'context' => ['run_id' => $runId]]);
            return false;
        }
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO payroll_run_approvals (payroll_run_id, approver_id, step_order) VALUES (:run_id, :approver_id, :step)');
        foreach ($approvers as $appr) {
            $ins->execute([
                ':run_id' => $runId,
                ':approver_id' => $appr['id'],
                ':step' => $appr['step_order'] ?? 1,
            ]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $inner) {}
        }
        sys_log('PAYROLL-APPROVALS-INIT', 'Failed initializing approvals: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return false;
    }
}

function payroll_get_run_approvals(PDO $pdo, int $runId): array {
    $sql = 'SELECT pra.*, pa.user_id, pa.applies_to, u.full_name AS approver_name, u.email AS approver_email
            FROM payroll_run_approvals pra
            JOIN payroll_approvers pa ON pa.id = pra.approver_id
            LEFT JOIN users u ON u.id = pa.user_id
            WHERE pra.payroll_run_id = :id
            ORDER BY pra.step_order, pra.id';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-APPROVALS-LIST', 'Failed fetching run approvals: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return [];
    }
}

function payroll_get_run_payslips(PDO $pdo, int $runId): array {
    if ($runId <= 0) {
        return [];
    }
    $sql = 'SELECT ps.*, e.employee_code, e.first_name, e.last_name, d.name AS department_name, u.full_name AS generated_by_name
        FROM payslips ps
        JOIN employees e ON e.id = ps.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN users u ON u.id = ps.generated_by
        WHERE ps.payroll_run_id = :run
        ORDER BY e.last_name, e.first_name, e.employee_code';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-PAYSLIPS', 'Failed loading payslips for run: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return [];
    }
}

function payroll_get_payslip_items(PDO $pdo, array $payslipIds): array {
    if (empty($payslipIds)) {
        return [];
    }
    $ids = array_values(array_filter(array_map('intval', $payslipIds)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT id, payslip_id, type, code, label, amount, meta
        FROM payslip_items
        WHERE payslip_id IN (' . $placeholders . ')
        ORDER BY payslip_id, type, id';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-PAYSLIP-ITEMS', 'Failed loading payslip items: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['payslip_ids' => $ids]]);
        return [];
    }
    $grouped = [];
    foreach ($rows as $row) {
        $payslipId = (int)($row['payslip_id'] ?? 0);
        if ($payslipId <= 0) {
            continue;
        }
        $grouped[$payslipId][] = $row;
    }
    return $grouped;
}

/** Fetch a single payslip with joins for display. */
function payroll_get_payslip(PDO $pdo, int $payslipId): ?array {
    if ($payslipId <= 0) return null;
    $sql = 'SELECT ps.*, e.employee_code, e.first_name, e.last_name, e.user_id AS owner_user_id,
                   d.name AS department_name, u.full_name AS generated_by_name,
                   pr.period_start, pr.period_end
            FROM payslips ps
            JOIN employees e ON e.id = ps.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN users u ON u.id = ps.generated_by
            LEFT JOIN payroll_runs pr ON pr.id = ps.payroll_run_id
            WHERE ps.id = :id LIMIT 1';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $payslipId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        sys_log('PAYROLL-PAYSLIP-GET', 'Failed loading payslip: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['payslip_id' => $payslipId]]);
        return null;
    }
}

/**
 * Clone a payslip to a new version with an adjustment item.
 * $type: 'earning'|'deduction'
 * Returns new payslip id or null.
 */
function payroll_clone_payslip_with_adjustment(PDO $pdo, int $payslipId, string $type, string $code, string $label, float $amount, string $reason, int $actingUserId): ?int {
    $type = strtolower(trim($type));
    if (!in_array($type, ['earning','deduction'], true)) { $type = 'earning'; }
    if ($amount <= 0) { return null; }
    try {
        $pdo->beginTransaction();

        $old = payroll_get_payslip($pdo, $payslipId);
        if (!$old) { if ($pdo->inTransaction()) $pdo->rollBack(); return null; }
        $items = payroll_get_payslip_items($pdo, [$payslipId]);
        $oldItems = $items[$payslipId] ?? [];

        $basic = (float)($old['basic_pay'] ?? 0);
        $earn = (float)($old['total_earnings'] ?? 0);
        $ded = (float)($old['total_deductions'] ?? 0);
        // total_earnings already includes basic pay, so don't add basic again
        $gross = (float)($old['gross_pay'] ?? $earn);
        $net = (float)($old['net_pay'] ?? ($earn - $ded));

        if ($type === 'earning') { $earn += $amount; $gross += $amount; $net += $amount; }
        else { $ded += $amount; $net -= $amount; }

        $newVersion = (int)($old['version'] ?? 1) + 1;
        $earnJson = json_encode((object)[], JSON_UNESCAPED_SLASHES);
        $dedJson = json_encode((object)[], JSON_UNESCAPED_SLASHES);
        $breakdown = json_encode((object)[], JSON_UNESCAPED_SLASHES);

        $ins = $pdo->prepare('INSERT INTO payslips (
            payroll_run_id, employee_id, period_start, period_end,
            basic_pay, total_earnings, total_deductions, net_pay, gross_pay,
            earnings_json, deductions_json, rollup_meta, status,
            generated_by, change_reason, version, prev_version_id, released_at, released_by
        ) VALUES (
            :run, :emp, :start, :end,
            :basic, :earn, :ded, :net, :gross,
            :earn_json, :ded_json, :breakdown, :status,
            :gen_by, :reason, :ver, :prev, NOW(), :released_by
        ) RETURNING id');
        $ins->execute([
            ':run' => (int)$old['payroll_run_id'],
            ':emp' => (int)$old['employee_id'],
            ':start' => $old['period_start'],
            ':end' => $old['period_end'],
            ':basic' => $basic,
            ':earn' => $earn,
            ':ded' => $ded,
            ':net' => $net,
            ':gross' => $gross,
            ':earn_json' => $earnJson,
            ':ded_json' => $dedJson,
            ':breakdown' => $breakdown,
            ':status' => 'released',
            ':gen_by' => $actingUserId ?: null,
            ':reason' => $reason,
            ':ver' => $newVersion,
            ':prev' => $payslipId,
            ':released_by' => $actingUserId ?: null,
        ]);
        $newId = (int)$ins->fetchColumn();

        // copy old items
        $itemIns = $pdo->prepare('INSERT INTO payslip_items (payslip_id, type, code, label, amount, meta) VALUES (:ps, :type, :code, :label, :amount, :meta)');
        foreach ($oldItems as $it) {
            $itemIns->execute([
                ':ps' => $newId,
                ':type' => $it['type'],
                ':code' => $it['code'],
                ':label' => $it['label'],
                ':amount' => (float)$it['amount'],
                ':meta' => json_encode((object)[], JSON_UNESCAPED_SLASHES),
            ]);
        }
        // add adjustment
        $itemIns->execute([
            ':ps' => $newId,
            ':type' => $type,
            ':code' => $code,
            ':label' => $label,
            ':amount' => $amount,
            ':meta' => json_encode(['adjustment' => true], JSON_UNESCAPED_SLASHES),
        ]);

        $pdo->commit();
        audit('payslip_adjusted', json_encode(['old_id' => $payslipId, 'new_id' => $newId, 'type' => $type, 'amount' => $amount], JSON_UNESCAPED_SLASHES));
        action_log('payroll', 'payslip_adjusted', 'success', ['old_id' => $payslipId, 'new_id' => $newId, 'type' => $type, 'code' => $code, 'label' => $label, 'amount' => $amount]);
        return $newId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $ie) {} }
        sys_log('PAYROLL-PAYSLIP-ADJUST', 'Failed cloning payslip: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['payslip_id' => $payslipId]]);
        return null;
    }
}

function payroll_update_run_status(PDO $pdo, int $runId, string $status): bool {
    $sql = 'UPDATE payroll_runs SET status = :status, updated_at = NOW() WHERE id = :id';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':status' => $status, ':id' => $runId]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-RUN-STATUS', 'Failed updating run status: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId, 'status' => $status]]);
        return false;
    }
}

function payroll_evaluate_release(PDO $pdo, int $runId): array {
    $result = [
        'ok' => false,
        'issues' => [],
        'already_released' => false,
    ];

    try {
        $stmt = $pdo->prepare('SELECT status FROM payroll_runs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            $result['issues'][] = 'Payroll run not found.';
            return $result;
        }

        if (strtolower((string)$run['status']) === 'released') {
            $result['ok'] = true;
            $result['already_released'] = true;
            return $result;
        }

        if (strtolower((string)$run['status']) !== 'approved') {
            $result['issues'][] = 'Run must reach Approved status before release.';
        }

        $approvalCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        $stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM payroll_run_approvals WHERE payroll_run_id = :id GROUP BY status');
        $stmt->execute([':id' => $runId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusKey = strtolower((string)($row['status'] ?? ''));
            if (isset($approvalCounts[$statusKey])) {
                $approvalCounts[$statusKey] = (int)$row['cnt'];
            }
        }
        if ($approvalCounts['pending'] > 0) {
            $result['issues'][] = 'Complete all approval steps before releasing the run.';
        }
        if ($approvalCounts['rejected'] > 0) {
            $result['issues'][] = 'Resolve rejected approval steps before release.';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_complaints WHERE payroll_run_id = :id AND status IN (\'pending\', \'in_review\')');
        $stmt->execute([':id' => $runId]);
        $openComplaints = (int)$stmt->fetchColumn();
        if ($openComplaints > 0) {
            $result['issues'][] = 'Resolve open complaints before releasing the run.';
        }

        $stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM payroll_branch_submissions WHERE payroll_run_id = :id GROUP BY status');
        $stmt->execute([':id' => $runId]);
        $branchTotals = 0;
        $incompleteBranches = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $branchTotals += (int)$row['cnt'];
            $statusKey = strtolower((string)($row['status'] ?? ''));
            if (!in_array($statusKey, ['submitted', 'accepted'], true)) {
                $incompleteBranches += (int)$row['cnt'];
            }
        }
        if ($branchTotals === 0) {
            $result['issues'][] = 'Branch submissions must be initialized before release.';
        } elseif ($incompleteBranches > 0) {
            $result['issues'][] = 'Ensure all branches submit and are marked submitted/accepted before release.';
        }

        $result['ok'] = empty($result['issues']);
        return $result;
    } catch (Throwable $e) {
        sys_log('PAYROLL-RELEASE-CHECK', 'Failed evaluating release readiness: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        $result['issues'][] = 'Unable to verify release readiness due to a system error.';
        return $result;
    }
}

function payroll_mark_run_released(PDO $pdo, int $runId, int $releasedBy): bool {
    $run = payroll_get_run($pdo, $runId);
    if (!$run) {
        sys_log('PAYROLL-RUN-RELEASE-FAIL', 'Payroll run not found while attempting release', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return false;
    }

    $alreadyReleased = !empty($run['released_at']);
    $pdo->beginTransaction();
    $sql = "UPDATE payroll_runs
            SET status = 'released',
                released_at = NOW(),
                released_by = :released_by,
                updated_at = NOW()
            WHERE id = :id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':released_by' => $releasedBy ?: null,
            ':id' => $runId,
        ]);

        // Ensure associated payslips carry release metadata and status.
        $ps = $pdo->prepare("UPDATE payslips
                              SET released_at = COALESCE(released_at, NOW()),
                                  released_by = COALESCE(released_by, :by),
                                  status = CASE WHEN status = 'void' THEN status ELSE 'released' END
                            WHERE payroll_run_id = :run_id");
        $ps->execute([':by' => $releasedBy ?: null, ':run_id' => $runId]);

        $pdo->commit();

        // Payroll release notifications now sent automatically by database trigger
        // (trg_notify_payroll_released) when payroll_runs.released_at is set.
        // See: database/migrations/2025-11-08_notification_triggers.sql
        // The trigger queries payroll_data to find all employees in the run and creates
        // notifications with period dates, run_id, and view_path payload.
        // No need to manually create notifications here.
        
        /*
        // OLD CODE: Manually creating notifications (now handled by trigger)
        if (!$alreadyReleased) {
            $recipients = [];
            try {
                $recipientStmt = $pdo->prepare('SELECT DISTINCT e.user_id
                                                 FROM payslips ps
                                                 JOIN employees e ON e.id = ps.employee_id
                                                WHERE ps.payroll_run_id = :run_id
                                                  AND e.user_id IS NOT NULL');
                $recipientStmt->execute([':run_id' => $runId]);
                $recipients = array_map('intval', $recipientStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (Throwable $recipientError) {
                sys_log('PAYROLL-RELEASE-NOTIFY', 'Failed resolving release notification recipients: ' . $recipientError->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
            }

            if ($recipients) {
                $periodParts = [];
                if (!empty($run['period_start'])) {
                    try { $periodParts[] = (new DateTimeImmutable((string)$run['period_start']))->format('M d, Y'); } catch (Throwable $ignored) {}
                }
                if (!empty($run['period_end'])) {
                    try { $periodParts[] = (new DateTimeImmutable((string)$run['period_end']))->format('M d, Y'); } catch (Throwable $ignored) {}
                }
                $periodLabel = $periodParts ? implode(' - ', $periodParts) : '';
                $notifyTitle = 'Payslip Released';
                $notifyBody = $periodLabel !== ''
                    ? 'Your payslip for ' . $periodLabel . ' is now available in HRMS.'
                    : 'Your latest payslip is now available in HRMS.';

                $supportsRichNotifications = true;
                try {
                    $insertNotify = $pdo->prepare('INSERT INTO notifications (user_id, title, body, message) VALUES (:uid, :title, :body, :msg)');
                } catch (Throwable $prepError) {
                    $supportsRichNotifications = false;
                    try {
                        $insertNotify = $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (:uid, :msg)');
                    } catch (Throwable $fallbackError) {
                        $insertNotify = null;
                        sys_log('PAYROLL-RELEASE-NOTIFY', 'Failed preparing notification insert statements: ' . $fallbackError->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
                    }
                }

                if ($insertNotify) {
                    foreach ($recipients as $userId) {
                        if ($userId <= 0) {
                            continue;
                        }
                        $params = $supportsRichNotifications
                            ? [
                                ':uid' => $userId,
                                ':title' => $notifyTitle,
                                ':body' => $notifyBody,
                                ':msg' => $notifyBody,
                            ]
                            : [
                                ':uid' => $userId,
                                ':msg' => $notifyBody,
                            ];
                        try {
                            $insertNotify->execute($params);
                        } catch (Throwable $notifyError) {
                            sys_log('PAYROLL-RELEASE-NOTIFY', 'Failed writing release notification: ' . $notifyError->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId, 'user_id' => $userId]]);
                        }
                    }
                }
            }
        }
        */

        audit('payroll_run_released', json_encode(['run_id' => $runId, 'released_by' => $releasedBy], JSON_UNESCAPED_SLASHES));
        sys_log('PAYROLL-RUN-RELEASED', 'Payroll run released', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId, 'released_by' => $releasedBy]]);
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sys_log('PAYROLL-RUN-RELEASE-FAIL', 'Failed marking payroll run released: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return false;
    }
}

function payroll_update_approval(PDO $pdo, int $approvalId, string $status, ?string $remarks, ?int $actingUserId): bool {
    $sql = 'UPDATE payroll_run_approvals SET status = :status, remarks = :remarks, acted_at = NOW(), updated_at = NOW() WHERE id = :id';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':remarks' => $remarks,
            ':id' => $approvalId,
        ]);
        if ($actingUserId) {
            audit('payroll_run_approval', json_encode(['approval_id' => $approvalId, 'status' => $status, 'acted_by' => $actingUserId], JSON_UNESCAPED_SLASHES));
        }
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-APPROVAL-UPDATE', 'Failed updating approval: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['approval_id' => $approvalId]]);
        return false;
    }
}

function payroll_get_complaints(PDO $pdo, int $runId): array {
    $sql = 'SELECT pc.*, e.employee_code, e.first_name, e.last_name
            FROM payroll_complaints pc
            LEFT JOIN employees e ON e.id = pc.employee_id
            WHERE pc.payroll_run_id = :run
            ORDER BY pc.created_at DESC';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMPLAINT-LIST', 'Failed fetching complaints: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return [];
    }
}

function payroll_get_complaint(PDO $pdo, int $complaintId): ?array {
    $sql = 'SELECT pc.*, pr.period_start, pr.period_end, pr.status AS run_status, pr.id AS run_id,
            e.employee_code, e.first_name, e.last_name
            FROM payroll_complaints pc
            JOIN payroll_runs pr ON pr.id = pc.payroll_run_id
            LEFT JOIN employees e ON e.id = pc.employee_id
            WHERE pc.id = :id
            LIMIT 1';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMPLAINT-GET', 'Failed fetching complaint: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['complaint_id' => $complaintId]]);
        return null;
    }
}

function payroll_list_complaints(PDO $pdo, array $filters = []): array {
    $sql = 'SELECT pc.*, pr.period_start, pr.period_end, pr.status AS run_status, pr.id AS run_id,
            e.employee_code, e.first_name, e.last_name
            FROM payroll_complaints pc
            JOIN payroll_runs pr ON pr.id = pc.payroll_run_id
            LEFT JOIN employees e ON e.id = pc.employee_id';
    $where = [];
    $params = [];

    if (!empty($filters['run_id'])) {
        $where[] = 'pc.payroll_run_id = :filter_run_id';
        $params[':filter_run_id'] = (int)$filters['run_id'];
    }

    if (!empty($filters['employee_id'])) {
        $where[] = 'pc.employee_id = :filter_employee_id';
        $params[':filter_employee_id'] = (int)$filters['employee_id'];
    }

    if (!empty($filters['priority'])) {
        $priorities = array_values(array_filter(array_map('strval', (array)$filters['priority'])));
        if ($priorities) {
            $placeholders = [];
            foreach ($priorities as $idx => $priority) {
                $ph = ':filter_priority_' . $idx;
                $placeholders[] = $ph;
                $params[$ph] = strtolower($priority);
            }
            $where[] = 'pc.priority IN (' . implode(',', $placeholders) . ')';
        }
    }

    if (!empty($filters['statuses'])) {
        $statuses = array_values(array_filter(array_map('strval', (array)$filters['statuses'])));
        if ($statuses) {
            $placeholders = [];
            foreach ($statuses as $idx => $status) {
                $ph = ':filter_status_' . $idx;
                $placeholders[] = $ph;
                $params[$ph] = strtolower($status);
            }
            $where[] = 'pc.status IN (' . implode(',', $placeholders) . ')';
        }
    }

    if (!empty($filters['exclude_statuses'])) {
        $exStatuses = array_values(array_filter(array_map('strval', (array)$filters['exclude_statuses'])));
        if ($exStatuses) {
            $placeholders = [];
            foreach ($exStatuses as $idx => $status) {
                $ph = ':exclude_status_' . $idx;
                $placeholders[] = $ph;
                $params[$ph] = strtolower($status);
            }
            $where[] = 'pc.status NOT IN (' . implode(',', $placeholders) . ')';
        }
    }

    if (!empty($filters['search'])) {
        $where[] = '(LOWER(pc.issue_type) LIKE :search OR LOWER(pc.description) LIKE :search)';
        $params[':search'] = '%' . strtolower(trim((string)$filters['search'])) . '%';
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY pc.submitted_at DESC, pc.id DESC';

    $limit = (int)($filters['limit'] ?? 100);
    if ($limit <= 0) {
        $limit = 100;
    }
    $sql .= ' LIMIT :limit';

    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMPLAINT-LIST-ALL', 'Failed listing complaints: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['filters' => $filters]]);
        return [];
    }
}

function payroll_complaint_status_totals(PDO $pdo): array {
    $totals = ['pending' => 0, 'in_review' => 0, 'resolved' => 0, 'confirmed' => 0, 'rejected' => 0];
    try {
        $stmt = $pdo->query('SELECT status, COUNT(*) AS cnt FROM payroll_complaints GROUP BY status');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower((string)($row['status'] ?? ''));
            if (isset($totals[$status])) {
                $totals[$status] = (int)$row['cnt'];
            }
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMPLAINT-TOTALS', 'Failed counting complaints: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
    }
    return $totals;
}

function payroll_log_complaint(PDO $pdo, int $runId, int $employeeId, string $issueType, string $description, ?int $userId, ?int $payslipId = null, ?string $categoryCode = null, ?string $subcategoryCode = null, ?string $priority = null): ?int {
    $resolved = payroll_resolve_complaint_category($categoryCode, $subcategoryCode);
    if ($resolved['valid']) {
        $categoryCode = $resolved['category_code'];
        $subcategoryCode = $resolved['subcategory_code'];
        $issueLabel = $resolved['label'];
    } else {
        $categoryCode = null;
        $subcategoryCode = null;
        $issueLabel = $issueType ?: 'General';
    }
    $priorities = payroll_get_complaint_priorities();
    $priority = $priority && isset($priorities[$priority]) ? $priority : 'normal';
    $sql = 'INSERT INTO payroll_complaints (payroll_run_id, employee_id, payslip_id, issue_type, description, category_code, subcategory_code, priority)
            VALUES (:run, :emp, :payslip, :issue, :desc, :category, :subcategory, :priority)
            RETURNING id';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':run' => $runId,
            ':emp' => $employeeId,
            ':payslip' => $payslipId ?: null,
            ':issue' => $issueLabel ?: null,
            ':desc' => $description,
            ':category' => $categoryCode,
            ':subcategory' => $subcategoryCode,
            ':priority' => $priority,
        ]);
        $id = (int)$stmt->fetchColumn();
        if ($userId) {
            audit('payroll_complaint_logged', json_encode(['complaint_id' => $id, 'run_id' => $runId, 'employee_id' => $employeeId], JSON_UNESCAPED_SLASHES));
        }
        return $id;
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMPLAINT-ADD', 'Failed logging complaint: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return null;
    }
}

function payroll_find_next_cutoff_period(PDO $pdo, string $afterDate): ?array {
    if (!payroll_table_exists($pdo, 'cutoff_periods')) {
        return null;
    }
    $normalized = date('Y-m-d', strtotime($afterDate ?: 'today'));
    try {
        $stmt = $pdo->prepare("SELECT * FROM cutoff_periods WHERE start_date > :after AND status <> 'cancelled' ORDER BY start_date ASC LIMIT 1");
        $stmt->execute([':after' => $normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        sys_log('PAYROLL-CUTOFF-NEXT', 'Failed fetching next cutoff period: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['after' => $normalized]]);
        return null;
    }
}

function payroll_schedule_adjustment(PDO $pdo, array $data): ?int {
    if (!payroll_table_exists($pdo, 'payroll_adjustment_queue')) {
        sys_log('PAYROLL-ADJUST-QUEUE', 'Adjustment queue table is missing; cannot schedule adjustment.', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => $data]);
        return null;
    }

    $employeeId = (int)($data['employee_id'] ?? 0);
    $amount = isset($data['amount']) ? round(abs((float)$data['amount']), 2) : 0.0;
    if ($employeeId <= 0 || $amount <= 0) {
        return null;
    }

    $adjustmentType = strtolower((string)($data['adjustment_type'] ?? 'earning'));
    if (!in_array($adjustmentType, ['earning', 'deduction'], true)) {
        $adjustmentType = 'earning';
    }

    $label = trim((string)($data['label'] ?? 'Payroll Adjustment'));
    if ($label === '') {
        $label = $adjustmentType === 'deduction' ? 'Deduction Adjustment' : 'Earning Adjustment';
    }
    $code = trim((string)($data['code'] ?? '')); 
    $notes = isset($data['notes']) ? trim((string)$data['notes']) : null;
    if ($notes === '') {
        $notes = null;
    }

    $start = date('Y-m-d', strtotime((string)($data['effective_period_start'] ?? 'today')));
    $end = date('Y-m-d', strtotime((string)($data['effective_period_end'] ?? $start)));
    if ($end < $start) {
        $end = $start;
    }

    $payload = [
        ':emp' => $employeeId,
        ':complaint' => isset($data['complaint_id']) ? (int)$data['complaint_id'] : null,
        ':cutoff' => isset($data['cutoff_period_id']) ? (int)$data['cutoff_period_id'] : null,
        ':run' => isset($data['payroll_run_id']) ? (int)$data['payroll_run_id'] : null,
        ':payslip' => isset($data['payslip_id']) ? (int)$data['payslip_id'] : null,
        ':start' => $start,
        ':end' => $end,
        ':type' => $adjustmentType,
        ':code' => $code !== '' ? $code : null,
        ':label' => $label,
        ':amount' => $amount,
        ':notes' => $notes,
        ':created_by' => isset($data['created_by']) && (int)$data['created_by'] > 0 ? (int)$data['created_by'] : null,
    ];

    $savepoint = null;
    if ($pdo->inTransaction()) {
        try {
            $savepoint = 'sp_adj_' . bin2hex(random_bytes(4));
            $pdo->exec('SAVEPOINT ' . $savepoint);
        } catch (Throwable $e) {
            $savepoint = null;
        }
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO payroll_adjustment_queue (
                employee_id, complaint_id, cutoff_period_id, payroll_run_id, payslip_id,
                effective_period_start, effective_period_end,
                adjustment_type, code, label, amount, notes, created_by
            ) VALUES (
                :emp, :complaint, :cutoff, :run, :payslip,
                :start, :end,
                :type, :code, :label, :amount, :notes, :created_by
            ) RETURNING id');
        $stmt->execute($payload);
        $id = (int)$stmt->fetchColumn();
        if ($savepoint) {
            try {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } catch (Throwable $ignored) {
                // Safe to ignore; savepoint will automatically release on commit/rollback.
            }
        }
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        if ($savepoint) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            } catch (Throwable $ignored) {
                // No-op; connection will be reset by outer rollback if needed.
            }
        }

        $errorInfo = [];
        if ($e instanceof PDOException) {
            $errorInfo = $e->errorInfo ?? [];
        }

        sys_log('PAYROLL-ADJUST-QUEUE', 'Failed scheduling payroll adjustment: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => [
                'data' => $data,
                'error_info' => $errorInfo,
            ],
        ]);
        return null;
    }
}

/**
 * Fetch pending adjustment queue items for a specific payroll run and employee.
 * Returns array of adjustments to be applied to the payslip.
 * Only returns approved adjustments (approval_status='approved').
 */
function payroll_get_queued_adjustments(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd, ?int $runId = null): array {
    if (!payroll_table_exists($pdo, 'payroll_adjustment_queue')) {
        return [];
    }
    
    try {
        $sql = 'SELECT id, adjustment_type, code, label, amount, notes, complaint_id
                FROM payroll_adjustment_queue
                WHERE employee_id = :emp
                  AND status = :status
                  AND effective_period_start <= :end
                  AND effective_period_end >= :start';
        
        $params = [
            ':emp' => $employeeId,
            ':status' => 'pending',
            ':start' => $periodStart,
            ':end' => $periodEnd,
        ];
        
        // Only fetch approved adjustments (if approval_status column exists)
        $checkApprovalColumn = $pdo->query("SELECT column_name FROM information_schema.columns 
                                            WHERE table_schema = current_schema() 
                                              AND table_name = 'payroll_adjustment_queue' 
                                              AND column_name = 'approval_status'");
        if ($checkApprovalColumn && $checkApprovalColumn->rowCount() > 0) {
            $sql .= ' AND approval_status = :approval_status';
            $params[':approval_status'] = 'approved';
        }
        
        // Optionally filter by specific run
        if ($runId) {
            $sql .= ' AND (payroll_run_id = :run OR payroll_run_id IS NULL)';
            $params[':run'] = $runId;
        }
        
        $sql .= ' ORDER BY created_at ASC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-QUEUE-FETCH', 'Failed fetching queued adjustments: ' . $e->getMessage(), [
            'module' => 'payroll',
            'context' => ['employee_id' => $employeeId, 'period' => [$periodStart, $periodEnd]]
        ]);
        return [];
    }
}

/**
 * Mark adjustment queue items as applied after successful payslip generation.
 */
function payroll_mark_adjustments_applied(PDO $pdo, array $adjustmentIds, int $payslipId): bool {
    if (empty($adjustmentIds) || !payroll_table_exists($pdo, 'payroll_adjustment_queue')) {
        return false;
    }
    
    try {
        $placeholders = implode(',', array_fill(0, count($adjustmentIds), '?'));
        $stmt = $pdo->prepare("UPDATE payroll_adjustment_queue 
                               SET status = 'applied', 
                                   payslip_id = ?,
                                   applied_at = NOW(),
                                   updated_at = NOW()
                               WHERE id IN ($placeholders)");
        
        $params = array_merge([$payslipId], $adjustmentIds);
        $stmt->execute($params);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-QUEUE-MARK', 'Failed marking adjustments as applied: ' . $e->getMessage(), [
            'module' => 'payroll',
            'context' => ['adjustment_ids' => $adjustmentIds, 'payslip_id' => $payslipId]
        ]);
        return false;
    }
}

function payroll_mark_complaint_in_review(PDO $pdo, int $complaintId, int $actingUserId, ?string $reviewNotes = null): array {
    $out = ['ok' => false, 'error' => null];
    if ($complaintId <= 0) {
        $out['error'] = 'Invalid complaint reference.';
        return $out;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status FROM payroll_complaints WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            $out['error'] = 'Complaint not found.';
            return $out;
        }
        $currentStatus = strtolower((string)$row['status']);
        if (!in_array($currentStatus, ['pending', 'in_review'], true)) {
            $pdo->rollBack();
            $out['error'] = 'Complaint is already resolved.';
            return $out;
        }
        $notes = $reviewNotes !== null ? trim($reviewNotes) : null;
        if ($notes === '') {
            $notes = null;
        }

        $updateFields = ['status = :status::payroll_complaint_status', 'updated_at = NOW()'];
        $params = [
            ':status' => 'in_review',
            ':id' => $complaintId,
        ];

        if (payroll_complaint_has_column($pdo, 'review_notes')) {
            $updateFields[] = 'review_notes = COALESCE(:review_notes, review_notes)';
            $params[':review_notes'] = $notes;
        }

        if (payroll_complaint_has_column($pdo, 'reviewed_by')) {
            $updateFields[] = 'reviewed_by = :reviewed_by';
            $params[':reviewed_by'] = $actingUserId ?: null;
        }

        if (payroll_complaint_has_column($pdo, 'reviewed_at')) {
            $updateFields[] = 'reviewed_at = NOW()';
        }

        $update = $pdo->prepare('UPDATE payroll_complaints SET ' . implode(', ', $updateFields) . ' WHERE id = :id');
        $update->execute($params);
        $pdo->commit();
        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        payroll_quiet_rollback($pdo);
        sys_log('PAYROLL-COMPLAINT-REVIEW', 'Failed to mark complaint in review: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['complaint_id' => $complaintId]]);
        $out['error'] = 'System error.';
        return $out;
    }
}

function payroll_resolve_complaint(PDO $pdo, int $complaintId, array $resolutionData, int $actingUserId): array {
    $out = ['ok' => false, 'error' => null];
    
    // Entry debug log
    sys_log('PAYROLL-COMPLAINT-RESOLVE-START', 'Starting complaint resolution', [
        'module' => 'payroll',
        'file' => __FILE__,
        'line' => __LINE__,
        'context' => [
            'complaint_id' => $complaintId,
            'resolution_data' => $resolutionData,
            'acting_user_id' => $actingUserId,
        ],
    ]);
    
    if ($complaintId <= 0) {
        $out['error'] = 'Invalid complaint reference.';
        return $out;
    }

    $targetStatus = strtolower((string)($resolutionData['status'] ?? 'resolved'));
    if (!in_array($targetStatus, ['resolved', 'rejected'], true)) {
        $targetStatus = 'resolved';
    }

    $resolutionNotes = isset($resolutionData['notes']) ? trim((string)$resolutionData['notes']) : null;
    if ($resolutionNotes === '') {
        $resolutionNotes = null;
    }

    try {
        $pdo->beginTransaction();
        
        // First, lock and get the complaint record
        $stmt = $pdo->prepare('SELECT * FROM payroll_complaints WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            $out['error'] = 'Complaint not found.';
            return $out;
        }
        
        // Then get the payroll run period dates if needed (no lock needed)
        if ($row['payroll_run_id']) {
            $runStmt = $pdo->prepare('SELECT period_start, period_end FROM payroll_runs WHERE id = :id');
            $runStmt->execute([':id' => $row['payroll_run_id']]);
            $runData = $runStmt->fetch(PDO::FETCH_ASSOC);
            if ($runData) {
                $row['period_start'] = $runData['period_start'];
                $row['period_end'] = $runData['period_end'];
            }
        }

        $currentStatus = strtolower((string)($row['status'] ?? 'pending'));
        if ($targetStatus === 'resolved' && !in_array($currentStatus, ['pending', 'in_review', 'resolved'], true)) {
            $pdo->rollBack();
            $out['error'] = 'Complaint cannot be resolved from its current status.';
            return $out;
        }
        if ($targetStatus === 'rejected' && !in_array($currentStatus, ['pending', 'in_review'], true)) {
            $pdo->rollBack();
            $out['error'] = 'Complaint cannot be rejected after resolution.';
            return $out;
        }

        $queueId = null;
        $adjustmentAmount = isset($resolutionData['adjustment_amount']) ? round(abs((float)$resolutionData['adjustment_amount']), 2) : 0.0;
        $adjustmentType = strtolower((string)($resolutionData['adjustment_type'] ?? 'earning'));
        if (!in_array($adjustmentType, ['earning', 'deduction'], true)) {
            $adjustmentType = 'earning';
        }

        $effectiveStart = isset($resolutionData['effective_start']) ? date('Y-m-d', strtotime((string)$resolutionData['effective_start'])) : null;
        $effectiveEnd = isset($resolutionData['effective_end']) ? date('Y-m-d', strtotime((string)$resolutionData['effective_end'])) : null;

        if ($effectiveStart === null || $effectiveEnd === null) {
            $baseDate = $row['period_end'] ?? $row['submitted_at'] ?? date('Y-m-d');
            $nextCutoff = payroll_find_next_cutoff_period($pdo, $baseDate);
            if ($nextCutoff) {
                $effectiveStart = $nextCutoff['start_date'];
                $effectiveEnd = $nextCutoff['end_date'];
                $resolutionData['cutoff_period_id'] = $nextCutoff['id'];
            } else {
                $periodStart = isset($row['period_end']) ? date('Y-m-d', strtotime((string)$row['period_end'] . ' +1 day')) : date('Y-m-d');
                $effectiveStart = $periodStart;
                $effectiveEnd = date('Y-m-d', strtotime($periodStart . ' +14 days'));
            }
        }
        if ($effectiveEnd < $effectiveStart) {
            $effectiveEnd = $effectiveStart;
        }

        if ($targetStatus === 'resolved' && $adjustmentAmount > 0) {
            $queueId = payroll_schedule_adjustment($pdo, [
                'employee_id' => (int)$row['employee_id'],
                'complaint_id' => $complaintId,
                'payroll_run_id' => isset($resolutionData['payroll_run_id']) ? (int)$resolutionData['payroll_run_id'] : (int)$row['payroll_run_id'],
                'cutoff_period_id' => isset($resolutionData['cutoff_period_id']) ? (int)$resolutionData['cutoff_period_id'] : null,
                'adjustment_type' => $adjustmentType,
                'amount' => $adjustmentAmount,
                'code' => $resolutionData['adjustment_code'] ?? null,
                'label' => $resolutionData['adjustment_label'] ?? 'Complaint Adjustment',
                'notes' => $resolutionData['adjustment_notes'] ?? $resolutionNotes,
                'effective_period_start' => $effectiveStart,
                'effective_period_end' => $effectiveEnd,
                'created_by' => $actingUserId,
            ]);
            
            // Log if adjustment scheduling fails
            if ($queueId === null) {
                sys_log('PAYROLL-COMPLAINT-RESOLVE', 'Warning: Failed to schedule adjustment for complaint resolution', [
                    'module' => 'payroll',
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'context' => [
                        'complaint_id' => $complaintId,
                        'employee_id' => (int)$row['employee_id'],
                        'adjustment_amount' => $adjustmentAmount,
                    ],
                ]);
            }
        }

        $updateFields = [
            'status = :status::payroll_complaint_status',
            'resolution_notes = :resolution_notes',
            'resolved_at = NOW()',
            'updated_at = NOW()'
        ];

        $updateParams = [
            ':status' => $targetStatus,
            ':resolution_notes' => $resolutionNotes,
            ':id' => $complaintId,
        ];

        if (payroll_complaint_has_column($pdo, 'resolution_by')) {
            $updateFields[] = 'resolution_by = :resolution_by';
            $updateParams[':resolution_by'] = $actingUserId ?: null;
        }

        if (payroll_complaint_has_column($pdo, 'resolution_at')) {
            $updateFields[] = 'resolution_at = NOW()';
        }

        if ($targetStatus === 'resolved' && $adjustmentAmount > 0) {
            if (payroll_complaint_has_column($pdo, 'adjustment_amount')) {
                $updateFields[] = 'adjustment_amount = :adjustment_amount';
                $updateParams[':adjustment_amount'] = $adjustmentAmount;
            }

            if (payroll_complaint_has_column($pdo, 'adjustment_type')) {
                $updateFields[] = 'adjustment_type = :adjustment_type::payslip_item_type';
                $updateParams[':adjustment_type'] = $adjustmentType;
            }

            if (payroll_complaint_has_column($pdo, 'adjustment_label')) {
                $updateFields[] = 'adjustment_label = :adjustment_label';
                $adjLabel = isset($resolutionData['adjustment_label']) ? trim((string)$resolutionData['adjustment_label']) : '';
                $updateParams[':adjustment_label'] = $adjLabel !== '' ? $adjLabel : 'Complaint Adjustment';
            }

            if (payroll_complaint_has_column($pdo, 'adjustment_code')) {
                $updateFields[] = 'adjustment_code = :adjustment_code';
                $adjCode = isset($resolutionData['adjustment_code']) ? trim((string)$resolutionData['adjustment_code']) : '';
                $updateParams[':adjustment_code'] = $adjCode !== '' ? $adjCode : null;
            }

            if (payroll_complaint_has_column($pdo, 'adjustment_notes')) {
                $updateFields[] = 'adjustment_notes = :adjustment_notes';
                $updateParams[':adjustment_notes'] = $resolutionData['adjustment_notes'] ?? $resolutionNotes;
            }

            if (payroll_complaint_has_column($pdo, 'adjustment_effective_start')) {
                $updateFields[] = 'adjustment_effective_start = :adjustment_effective_start';
                $updateParams[':adjustment_effective_start'] = $effectiveStart;
            }

            if (payroll_complaint_has_column($pdo, 'adjustment_effective_end')) {
                $updateFields[] = 'adjustment_effective_end = :adjustment_effective_end';
                $updateParams[':adjustment_effective_end'] = $effectiveEnd;
            }

            if (payroll_complaint_has_column($pdo, 'adjustment_queue_id')) {
                $updateFields[] = 'adjustment_queue_id = :adjustment_queue_id';
                $updateParams[':adjustment_queue_id'] = $queueId;
            }
        }

        $updateSql = 'UPDATE payroll_complaints SET ' . implode(', ', $updateFields) . ' WHERE id = :id';
        $update = $pdo->prepare($updateSql);
        
        // Debug log the update parameters
        sys_log('PAYROLL-COMPLAINT-RESOLVE-DEBUG', 'About to execute UPDATE', [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => [
                'complaint_id' => $complaintId,
                'target_status' => $targetStatus,
                'current_status' => $currentStatus,
                'update_params' => $updateParams,
            ],
        ]);
        
        $update->execute($updateParams);

        $pdo->commit();
        
        sys_log('PAYROLL-COMPLAINT-RESOLVE-SUCCESS', 'Complaint resolved successfully', [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => [
                'complaint_id' => $complaintId,
                'status' => $targetStatus,
                'queue_id' => $queueId,
            ],
        ]);
        
        $out['ok'] = true;
        $out['status'] = $targetStatus;
        $out['queue_id'] = $queueId;
        return $out;
    } catch (Throwable $e) {
        payroll_quiet_rollback($pdo);
        
        // Get PDO error info if available
        $pdoError = '';
        if ($e instanceof PDOException) {
            $errorInfo = $pdo->errorInfo();
            $pdoError = sprintf(' [SQLSTATE: %s, Error Code: %s, Message: %s]', 
                $errorInfo[0] ?? 'N/A',
                $errorInfo[1] ?? 'N/A', 
                $errorInfo[2] ?? 'N/A'
            );
        }
        
        sys_log('PAYROLL-COMPLAINT-RESOLVE-ERROR', 'Failed resolving complaint: ' . $e->getMessage() . $pdoError, [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'exception_type' => get_class($e),
            'exception_code' => $e->getCode(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'exception_trace' => $e->getTraceAsString(),
            'context' => [
                'complaint_id' => $complaintId,
                'data' => $resolutionData,
                'target_status' => $targetStatus,
                'pdo_error_info' => $pdo->errorInfo(),
            ],
        ]);
        $out['error'] = 'System error: ' . $e->getMessage();
        return $out;
    }
}

function payroll_confirm_complaint(PDO $pdo, int $complaintId, int $actingUserId, ?string $confirmationNotes = null): array {
    $out = ['ok' => false, 'error' => null];
    if ($complaintId <= 0) {
        $out['error'] = 'Invalid complaint reference.';
        return $out;
    }
    $notes = $confirmationNotes !== null ? trim($confirmationNotes) : null;
    if ($notes === '') {
        $notes = null;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status FROM payroll_complaints WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            $out['error'] = 'Complaint not found.';
            return $out;
        }
        $currentStatus = strtolower((string)($row['status'] ?? 'pending'));
        if ($currentStatus !== 'resolved') {
            $pdo->rollBack();
            $out['error'] = 'Complaint must be resolved before confirmation.';
            return $out;
        }
        $updateFields = ['status = :status::payroll_complaint_status', 'updated_at = NOW()'];
        $params = [
            ':status' => 'confirmed',
            ':id' => $complaintId,
        ];

        if (payroll_complaint_has_column($pdo, 'confirmation_notes')) {
            $updateFields[] = 'confirmation_notes = :confirmation_notes';
            $params[':confirmation_notes'] = $notes;
        } elseif ($notes !== null) {
            // Fall back to resolution notes if confirmation-specific column is unavailable
            $updateFields[] = 'resolution_notes = :confirmation_notes';
            $params[':confirmation_notes'] = $notes;
        }

        if (payroll_complaint_has_column($pdo, 'confirmation_by')) {
            $updateFields[] = 'confirmation_by = :confirmation_by';
            $params[':confirmation_by'] = $actingUserId ?: null;
        }

        if (payroll_complaint_has_column($pdo, 'confirmation_at')) {
            $updateFields[] = 'confirmation_at = NOW()';
        }

        $update = $pdo->prepare('UPDATE payroll_complaints SET ' . implode(', ', $updateFields) . ' WHERE id = :id');
        $update->execute($params);
        $pdo->commit();
        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        payroll_quiet_rollback($pdo);
        sys_log('PAYROLL-COMPLAINT-CONFIRM', 'Failed confirming complaint: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['complaint_id' => $complaintId]]);
        $out['error'] = 'System error.';
        return $out;
    }
}

function payroll_update_complaint_status(PDO $pdo, int $complaintId, string $status, ?string $resolutionNotes = null, array $options = []): bool {
    $status = strtolower(trim($status));
    $actingUserId = (int)($options['acting_user_id'] ?? $options['user_id'] ?? 0);

    if ($status === 'in_review') {
        $result = payroll_mark_complaint_in_review($pdo, $complaintId, $actingUserId, $options['review_notes'] ?? $resolutionNotes);
        if (!($result['ok'] ?? false)) {
            sys_log('PAYROLL-COMPLAINT-UPDATE', 'Failed updating complaint to in_review: ' . ($result['error'] ?? 'Unknown error'), [
                'module' => 'payroll',
                'file' => __FILE__,
                'line' => __LINE__,
                'context' => ['complaint_id' => $complaintId, 'status' => $status, 'acting_user_id' => $actingUserId]
            ]);
        }
        return $result['ok'] ?? false;
    }

    if ($status === 'resolved' || $status === 'rejected') {
        $payload = $options;
        $payload['status'] = $status;
        if ($resolutionNotes !== null && !isset($payload['notes'])) {
            $payload['notes'] = $resolutionNotes;
        }
        $result = payroll_resolve_complaint($pdo, $complaintId, $payload, $actingUserId);
        if (!($result['ok'] ?? false)) {
            sys_log('PAYROLL-COMPLAINT-UPDATE', 'Failed updating complaint to ' . $status . ': ' . ($result['error'] ?? 'Unknown error'), [
                'module' => 'payroll',
                'file' => __FILE__,
                'line' => __LINE__,
                'context' => ['complaint_id' => $complaintId, 'status' => $status, 'acting_user_id' => $actingUserId, 'payload' => $payload]
            ]);
        }
        return $result['ok'] ?? false;
    }

    if ($status === 'confirmed') {
        $result = payroll_confirm_complaint($pdo, $complaintId, $actingUserId, $options['confirmation_notes'] ?? $resolutionNotes);
        if (!($result['ok'] ?? false)) {
            sys_log('PAYROLL-COMPLAINT-UPDATE', 'Failed updating complaint to confirmed: ' . ($result['error'] ?? 'Unknown error'), [
                'module' => 'payroll',
                'file' => __FILE__,
                'line' => __LINE__,
                'context' => ['complaint_id' => $complaintId, 'status' => $status, 'acting_user_id' => $actingUserId]
            ]);
        }
        return $result['ok'] ?? false;
    }

    // Fallback for legacy statuses (e.g., resetting to pending)
    try {
        $stmt = $pdo->prepare('UPDATE payroll_complaints SET status = :status::payroll_complaint_status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':id' => $complaintId,
        ]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-COMPLAINT-UPDATE', 'Failed updating complaint status (fallback): ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['complaint_id' => $complaintId, 'status' => $status]]);
        return false;
    }
}

/**
 * Draft placeholder for payroll computation skeleton.
 */
function payroll_generate_payslip(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd, ?int $runId = null): ?int {
    // Placeholder: to be replaced with full calculation engine per roadmap.
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            $pdo->rollBack();
            return null;
        }
        $rates = payroll_calculate_rates($employee, payroll_get_rate_configs($pdo, $periodEnd));
        $insert = $pdo->prepare('INSERT INTO payslips (payroll_run_id, employee_id, period_start, period_end, basic_pay, total_earnings, total_deductions, net_pay, breakdown, status)
                                 VALUES (:run_id, :employee_id, :start, :end, :basic, :earnings, :deductions, :net, :breakdown, :status)
                                 RETURNING id');
        $basic = round(($employee['salary'] ?? 0) / 2, 2);
        $payload = [
            ':run_id' => $runId,
            ':employee_id' => $employeeId,
            ':start' => $periodStart,
            ':end' => $periodEnd,
            ':basic' => $basic,
            ':earnings' => $basic,
            ':deductions' => 0,
            ':net' => $basic,
            ':breakdown' => json_encode(['stub' => true, 'rates' => $rates]),
            ':status' => 'draft',
        ];
        $insert->execute($payload);
        $payslipId = (int)$insert->fetchColumn();
        $pdo->commit();
        return $payslipId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $inner) {}
        }
        sys_log('PAYROLL-GENERATE-STUB', 'Stub generation failed: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
        return null;
    }
}


/**
 * Aggregate earnings, deductions, and attendance-derived adjustments for an employee.
 */
function payroll_compute_payslip_components(array $employee, array $settings, string $periodStart, string $periodEnd, array $attendanceDates = [], array $attendanceMeta = [], ?array $compensationProfile = null, ?array $profileOverrides = null, array $overtimeSummary = [], array $queuedAdjustments = []): array {
    // Use employee salary if set, otherwise fall back to position base_salary
    $employeeSalary = (float)($employee['salary'] ?? 0);
    $positionSalary = (float)($employee['position_base_salary'] ?? 0);
    $monthly = max(0.0, $employeeSalary > 0 ? $employeeSalary : $positionSalary);

    $workingDaysPerMonth = (int)($settings['rate_computation_defaults']['config']['working_days_per_month'] ?? 22);
    $hoursPerDay = (int)($settings['rate_computation_defaults']['config']['hours_per_day'] ?? 8);
    $profileOverrides = is_array($profileOverrides) ? $profileOverrides : [];
    if (isset($profileOverrides['working_days_per_month']) && (int)$profileOverrides['working_days_per_month'] > 0) {
        $workingDaysPerMonth = (int)$profileOverrides['working_days_per_month'];
    }
    if (isset($profileOverrides['hours_per_day']) && (int)$profileOverrides['hours_per_day'] > 0) {
        $hoursPerDay = (int)$profileOverrides['hours_per_day'];
    }
    $rates = payroll_calculate_rates($employee, [], [
        'working_days_per_month' => $workingDaysPerMonth,
        'hours_per_day' => $hoursPerDay,
    ]);
    $rates = payroll_apply_profile_rate_overrides($rates, $profileOverrides);

    $basic = $rates['bi_monthly'] ?? round($monthly / 2, 2);

    $earnings = [[
        'type' => 'earning',
        'code' => 'BASIC',
        'label' => 'Basic Pay',
        'amount' => $basic,
    ]];

    $compProfile = $compensationProfile ?: [];
    $compAllowances = is_array($compProfile['allowances'] ?? null) ? $compProfile['allowances'] : [];
    $compDeductions = is_array($compProfile['deductions'] ?? null) ? $compProfile['deductions'] : [];
    $taxProfile = $compProfile['tax_percentage'] ?? null;
    $taxOverrideValue = null;
    $taxOverrideSource = null;
    if (is_array($taxProfile)) {
        if (isset($taxProfile['value']) && $taxProfile['value'] !== null) {
            $taxOverrideValue = (float)$taxProfile['value'];
        }
        if (isset($taxProfile['source'])) {
            $taxOverrideSource = (string)$taxProfile['source'];
        }
    } elseif ($taxProfile !== null) {
        $taxOverrideValue = (float)$taxProfile;
    }

    foreach ($compAllowances as $item) {
        $amount = round((float)($item['amount'] ?? 0), 2);
        if ($amount <= 0) {
            continue;
        }
        $earnings[] = [
            'type' => 'earning',
            'code' => $item['code'] ?? null,
            'label' => $item['label'] ?? ($item['code'] ?? 'Allowance'),
            'amount' => $amount,
            'source' => $item['source'] ?? 'default',
        ];
    }

    $normalizedDates = [];
    foreach ($attendanceDates as $date) {
        $normalized = date('Y-m-d', strtotime((string)$date));
        if ($normalized && !in_array($normalized, $normalizedDates, true)) {
            $normalizedDates[] = $normalized;
        }
    }
    sort($normalizedDates);

    $weekdayCount = payroll__count_weekdays($periodStart, $periodEnd);
    $presentCount = count($normalizedDates);
    
    // For bi-monthly payroll: if basic pay is half of monthly salary, 
    // then expected days should also be proportional to the pay ratio
    $payRatio = $monthly > 0 ? ($basic / $monthly) : 0.5;
    $expectedDays = round($weekdayCount * $payRatio);
    
    $absentDays = max(0, $expectedDays - $presentCount);

    $dailyRate = (float)($rates['daily'] ?? 0.0);
    $perMinuteRate = (float)($rates['per_minute'] ?? 0.0);

    $deductions = [];
    $attendanceAdjustments = [];

    $overtimeSummary = is_array($overtimeSummary) ? $overtimeSummary : [];
    if (!empty($overtimeSummary) && !empty($overtimeSummary['requests']) && $overtimeSummary['total_amount'] > 0) {
        $summaryLabel = 'Overtime (' . number_format((float)$overtimeSummary['total_hours'], 2) . ' hrs @ x' . number_format((float)$overtimeSummary['multiplier'], 2) . ')';
        $earnings[] = [
            'type' => 'earning',
            'code' => 'OT',
            'label' => $summaryLabel,
            'amount' => round((float)$overtimeSummary['total_amount'], 2),
            'source' => 'overtime',
            'meta' => [
                'requests' => $overtimeSummary['requests'],
                'base_hourly_rate' => $overtimeSummary['base_hourly_rate'] ?? null,
                'multiplier' => $overtimeSummary['multiplier'] ?? null,
            ],
        ];
    }

    if ($absentDays > 0 && $dailyRate > 0) {
        $absenceAmount = round($dailyRate * $absentDays, 2);
        // Cap absence deduction at basic pay to prevent negative net pay from absences alone
        $absenceAmount = min($absenceAmount, $basic);
        if ($absenceAmount > 0) {
            $deductions[] = [
                'type' => 'deduction',
                'code' => 'ABS',
                'label' => 'Absences (' . $absentDays . ' day' . ($absentDays === 1 ? '' : 's') . ')',
                'amount' => $absenceAmount,
            ];
            $attendanceAdjustments[] = [
                'code' => 'ABS',
                'days' => $absentDays,
                'amount' => $absenceAmount,
            ];
        }
    }


            foreach ($compDeductions as $item) {
                $amount = round((float)($item['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }
                $deductions[] = [
                    'type' => 'deduction',
                    'code' => $item['code'] ?? null,
                    'label' => $item['label'] ?? ($item['code'] ?? 'Contribution'),
                    'amount' => $amount,
                    'source' => $item['source'] ?? 'default',
                ];
            }
    $tardyMinutes = (int)($attendanceMeta['tardy_minutes'] ?? 0);
    if ($tardyMinutes > 0 && $perMinuteRate > 0) {
        $tardyAmount = round($tardyMinutes * $perMinuteRate, 2);
        if ($tardyAmount > 0) {
            $deductions[] = [
                'type' => 'deduction',
                'code' => 'TARDY',
                'label' => 'Tardiness (' . $tardyMinutes . ' min)',
                'amount' => $tardyAmount,
            ];
            $attendanceAdjustments[] = [
                'code' => 'TARDY',
                'minutes' => $tardyMinutes,
                'amount' => $tardyAmount,
            ];
        }
    }

    $undertimeMinutes = (int)($attendanceMeta['undertime_minutes'] ?? 0);
    if ($undertimeMinutes > 0 && $perMinuteRate > 0) {
        $undertimeAmount = round($undertimeMinutes * $perMinuteRate, 2);
        if ($undertimeAmount > 0) {
            $deductions[] = [
                'type' => 'deduction',
                'code' => 'UT',
                'label' => 'Undertime (' . $undertimeMinutes . ' min)',
                'amount' => $undertimeAmount,
            ];
            $attendanceAdjustments[] = [
                'code' => 'UT',
                'minutes' => $undertimeMinutes,
                'amount' => $undertimeAmount,
            ];
        }
    }

    $sss = payroll_calculate_sss_contribution($monthly);
    if ($sss['per_period'] > 0) {
        $deductions[] = [
            'type' => 'deduction',
            'code' => 'SSS',
            'label' => 'SSS + WISP (Employee Share)',
            'amount' => $sss['per_period'],
        ];
    }

    $philHealth = payroll_calculate_philhealth_contribution($monthly);
    if ($philHealth['per_period'] > 0) {
        $deductions[] = [
            'type' => 'deduction',
            'code' => 'PHIC',
            'label' => 'PhilHealth (Employee Share)',
            'amount' => $philHealth['per_period'],
        ];
    }

    $pagibig = payroll_calculate_pagibig_contribution($monthly);
    if ($pagibig['per_period'] > 0) {
        $deductions[] = [
            'type' => 'deduction',
            'code' => 'HDMF',
            'label' => 'Pag-IBIG Contribution',
            'amount' => $pagibig['per_period'],
        ];
    }

    $withholding = payroll_calculate_withholding_tax($monthly, $sss['monthly'], $philHealth['monthly'], $pagibig['monthly'], $taxOverrideValue);
    if ($withholding['per_period'] > 0) {
        $deductions[] = [
            'type' => 'deduction',
            'code' => 'TAX',
            'label' => 'Withholding Tax',
            'amount' => $withholding['per_period'],
        ];
    }

    // Apply queued adjustments from complaint resolutions or manual adjustments
    $appliedAdjustments = [];
    foreach ($queuedAdjustments as $adj) {
        $adjType = strtolower((string)($adj['adjustment_type'] ?? 'earning'));
        $adjAmount = round(abs((float)($adj['amount'] ?? 0)), 2);
        if ($adjAmount <= 0) {
            continue;
        }
        
        $adjCode = trim((string)($adj['code'] ?? 'ADJ'));
        if ($adjCode === '') {
            $adjCode = 'ADJ';
        }
        
        $adjLabel = trim((string)($adj['label'] ?? 'Payroll Adjustment'));
        if ($adjLabel === '') {
            $adjLabel = $adjType === 'deduction' ? 'Deduction Adjustment' : 'Earning Adjustment';
        }
        
        // Add notes reference if from complaint
        if (!empty($adj['complaint_id'])) {
            $adjLabel .= ' (Complaint #' . $adj['complaint_id'] . ')';
        }
        
        $adjustmentEntry = [
            'type' => $adjType,
            'code' => $adjCode,
            'label' => $adjLabel,
            'amount' => $adjAmount,
            'source' => 'adjustment_queue',
            'queue_id' => (int)($adj['id'] ?? 0),
        ];
        
        if ($adjType === 'earning') {
            $earnings[] = $adjustmentEntry;
        } else {
            $deductions[] = $adjustmentEntry;
        }
        
        $appliedAdjustments[] = (int)($adj['id'] ?? 0);
    }

    $totalEarnings = 0.0;
    foreach ($earnings as $entry) {
        $totalEarnings += (float)$entry['amount'];
    }
    $totalDeductions = 0.0;
    foreach ($deductions as $entry) {
        $totalDeductions += (float)$entry['amount'];
    }

    $gross = $totalEarnings;
    $net = $gross - $totalDeductions;

    $meta = [
        'attendance' => [
            'expected_days' => $expectedDays,
            'present_days' => $presentCount,
            'absent_days' => $absentDays,
            'source' => $attendanceMeta['source'] ?? ($normalizedDates ? 'attendance_table' : 'none'),
            'status_counts' => $attendanceMeta['status_counts'] ?? [],
            'status_dates' => $attendanceMeta['status_dates'] ?? [],
        ],
        'notes' => $attendanceMeta['notes'] ?? [],
        'adjustments' => $attendanceAdjustments,
        'records' => $attendanceMeta['records'] ?? [],
        'rates' => $rates,
        'statutory' => [
            'sss' => $sss,
            'philhealth' => $philHealth,
            'pagibig' => $pagibig,
            'withholding_tax' => $withholding,
        ],
    ];

    if ($overtimeSummary) {
        $meta['overtime'] = $overtimeSummary;
    }

    $metaComp = $compProfile;
    if (!is_array($metaComp)) {
        $metaComp = [];
    }
    $metaComp['applied_allowances'] = count($compAllowances);
    $metaComp['applied_deductions'] = count($compDeductions);
    if (!isset($metaComp['tax_percentage']) || !is_array($metaComp['tax_percentage'])) {
        $metaComp['tax_percentage'] = [
            'value' => $taxOverrideValue,
            'source' => $taxOverrideSource,
        ];
    }
    $metaComp['tax_percentage']['effective_value'] = $taxOverrideValue !== null ? round($taxOverrideValue, 4) : ($metaComp['tax_percentage']['value'] ?? null);
    $metaComp['tax_percentage']['override_applied'] = $withholding['override_applied'] ?? false;
    $metaComp['tax_percentage']['source'] = $taxOverrideSource ?? ($metaComp['tax_percentage']['source'] ?? null);

    $meta['compensation'] = $metaComp;
    $meta['profile'] = [
        'allow_overtime' => (bool)($profileOverrides['allow_overtime'] ?? false),
        'duty_start' => $profileOverrides['duty_start'] ?? null,
        'duty_end' => $profileOverrides['duty_end'] ?? null,
        'hours_per_day' => $profileOverrides['hours_per_day'] ?? null,
        'working_days_per_month' => $profileOverrides['working_days_per_month'] ?? null,
        'overtime_multiplier' => $profileOverrides['overtime_multiplier'] ?? null,
        'custom_hourly_rate' => $profileOverrides['custom_hourly_rate'] ?? null,
        'custom_daily_rate' => $profileOverrides['custom_daily_rate'] ?? null,
        'profile_notes' => $profileOverrides['profile_notes'] ?? null,
    ];

    $warnings = $attendanceMeta['warnings'] ?? [];
    if (!empty($overtimeSummary['pending_count'])) {
        $warnings[] = 'There are ' . $overtimeSummary['pending_count'] . ' pending overtime request(s) for this period.';
    }

    return [
        'basic_pay' => $basic,
        'earnings' => $earnings,
        'deductions' => $deductions,
        'totals' => [
            'earnings' => round($totalEarnings, 2),
            'deductions' => round($totalDeductions, 2),
            'gross' => round($gross, 2),
            'net' => round($net, 2),
        ],
        'meta' => $meta,
        'warnings' => $warnings,
        'applied_adjustment_ids' => $appliedAdjustments, // IDs to mark as applied after successful insert
    ];
}

/**
 * Generate or update payslips for a run (optionally for a specific branch).
 * Returns summary array with counts and errors.
 */
function payroll_generate_payslips_for_run(PDO $pdo, int $runId, ?int $branchId = null, ?int $actingUserId = null): array {
    sys_log('PAYROLL-GEN-START', 'Payroll generation function called', [
        'module' => 'payroll',
        'context' => [
            'run_id' => $runId,
            'branch_id' => $branchId,
            'timestamp' => date('Y-m-d H:i:s'),
            'file_mtime' => date('Y-m-d H:i:s', filemtime(__FILE__))
        ]
    ]);
    
    $summary = ['ok' => false, 'generated' => 0, 'updated' => 0, 'errors' => [], 'warnings' => []];
    try {
        $run = payroll_get_run($pdo, $runId);
        if (!$run) {
            $summary['errors'][] = 'Run not found';
            return $summary;
        }
        $periodStart = (string)$run['period_start'];
        $periodEnd = (string)$run['period_end'];
        $settings = payroll_get_formula_settings($pdo, $periodEnd);

        payroll_ensure_payslip_columns($pdo);

        $hasGross = payroll_table_has_column($pdo, 'payslips', 'gross_pay');
        $hasEarnJson = payroll_table_has_column($pdo, 'payslips', 'earnings_json');
        $hasDedJson = payroll_table_has_column($pdo, 'payslips', 'deductions_json');
        $hasChangeReason = payroll_table_has_column($pdo, 'payslips', 'change_reason');
        $hasGeneratedBy = payroll_table_has_column($pdo, 'payslips', 'generated_by');
        $hasUpdatedAt = payroll_table_has_column($pdo, 'payslips', 'updated_at');
        $hasVersionCol = payroll_table_has_column($pdo, 'payslips', 'version');

        $hasPayslipItems = payroll_table_exists($pdo, 'payslip_items');
        $hasPayslipVersions = payroll_table_exists($pdo, 'payslip_versions');

        $sql = 'SELECT e.*, p.base_salary AS position_base_salary FROM employees e LEFT JOIN positions p ON e.position_id = p.id AND p.deleted_at IS NULL WHERE e.deleted_at IS NULL AND e.status = \'active\'';
        $params = [];
        if ($branchId) {
            $sql .= ' AND e.branch_id = :branch';
            $params[':branch'] = $branchId;
        }
        $sql .= ' ORDER BY e.last_name, e.first_name, e.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$employees) {
            $summary['warnings'][] = 'No employees found' . ($branchId ? ' for branch' : '');
            $summary['ok'] = true;
            return $summary;
        }

        $compDefaults = payroll_get_compensation_defaults($pdo);
        $employeeIds = array_map(static function ($row) {
            return (int)($row['id'] ?? 0);
        }, $employees);
        $profileOverridesMap = $employeeIds ? payroll_get_employee_profile_map($pdo, $employeeIds) : [];
        $compensationOverrides = $employeeIds ? payroll_get_employee_compensation_map($pdo, $employeeIds) : [];

        $employeesByBranch = [];
        $codeIndexByBranch = [];
        foreach ($employees as $empRow) {
            $empId = (int)($empRow['id'] ?? 0);
            $empBranch = (int)($empRow['branch_id'] ?? 0);
            if (!isset($employeesByBranch[$empBranch])) {
                $employeesByBranch[$empBranch] = [];
            }
            $employeesByBranch[$empBranch][$empId] = $empRow;
            $codeKey = strtoupper(trim((string)($empRow['employee_code'] ?? '')));
            if ($codeKey !== '') {
                if (!isset($codeIndexByBranch[$empBranch])) {
                    $codeIndexByBranch[$empBranch] = [];
                }
                $codeIndexByBranch[$empBranch][$codeKey] = $empId;
            }
        }

        $branchScope = $branchId ? [$branchId] : array_keys($employeesByBranch);
        $branchScope = array_values(array_unique(array_filter($branchScope, static function ($value) {
            return $value !== null;
        })));
        $attendanceRaw = payroll_fetch_attendance_map($pdo, $periodStart, $periodEnd, $branchScope);
        $attendanceByBranch = [];
        if ($branchScope) {
            foreach ($branchScope as $branchKey) {
                $attendanceByBranch[$branchKey] = $attendanceRaw[$branchKey] ?? ['by_employee' => [], 'code_index' => []];
            }
        } else {
            foreach ($attendanceRaw as $branchKey => $payload) {
                $attendanceByBranch[$branchKey] = $payload;
            }
        }

        if ($branchId) {
            try {
                $batchStmt = $pdo->prepare('SELECT dtr_file_path FROM payroll_batches WHERE payroll_run_id = :run AND branch_id = :branch LIMIT 1');
                $batchStmt->execute([':run' => $runId, ':branch' => $branchId]);
                $dtrPathRel = (string)($batchStmt->fetchColumn() ?: '');
                if ($dtrPathRel !== '') {
                    $root = realpath(__DIR__ . '/..');
                    $absPath = $root ? $root . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dtrPathRel), DIRECTORY_SEPARATOR) : null;
                    if ($absPath && is_readable($absPath)) {
                        $dtrMap = payroll__parse_dtr_csv_to_map($absPath);
                        if ($dtrMap) {
                            if (!isset($attendanceByBranch[$branchId])) {
                                $attendanceByBranch[$branchId] = ['by_employee' => [], 'code_index' => []];
                            }
                            $branchAttendance =& $attendanceByBranch[$branchId];
                            $branchCodeIndex = $codeIndexByBranch[$branchId] ?? [];
                            foreach ($dtrMap as $code => $info) {
                                $codeKey = strtoupper((string)$code);
                                if ($codeKey === '') {
                                    continue;
                                }
                                $employeeId = $branchCodeIndex[$codeKey] ?? null;
                                if (!$employeeId) {
                                    $summary['warnings'][] = 'DTR record for code ' . $code . ' could not be matched to a branch employee.';
                                    continue;
                                }
                                $employeeMeta = $employeesByBranch[$branchId][$employeeId] ?? null;
                                $info['branch_id'] = $branchId;
                                $info['employee_id'] = $employeeId;
                                $info['employee_code'] = $employeeMeta['employee_code'] ?? $code;
                                $info['source'] = $info['source'] ?? 'dtr_upload';
                                $info['notes'] = $info['notes'] ?? [];
                                if (!isset($info['status_dates'])) {
                                    $info['status_dates'] = ['present' => $info['present_dates'] ?? []];
                                }
                                foreach ($info['status_dates'] as &$datesList) {
                                    $datesList = array_values(array_unique($datesList));
                                    sort($datesList);
                                }
                                unset($datesList);
                                $info['status_counts'] = [];
                                foreach ($info['status_dates'] as $statusKey => $datesList) {
                                    $info['status_counts'][$statusKey] = count($datesList);
                                }
                                if (!isset($info['records']) || !$info['records']) {
                                    $info['records'] = [];
                                    foreach ($info['present_dates'] ?? [] as $date) {
                                        $info['records'][] = ['date' => $date, 'status' => 'present'];
                                    }
                                }

                                if (!isset($branchAttendance['by_employee'][$employeeId])) {
                                    $branchAttendance['by_employee'][$employeeId] = $info;
                                } else {
                                    $existing =& $branchAttendance['by_employee'][$employeeId];
                                    $existing['present_dates'] = array_values(array_unique(array_merge($existing['present_dates'] ?? [], $info['present_dates'] ?? [])));
                                    sort($existing['present_dates']);

                                    $existing['status_dates'] = $existing['status_dates'] ?? [];
                                    foreach ($info['status_dates'] as $statusKey => $datesList) {
                                        $existing['status_dates'][$statusKey] = isset($existing['status_dates'][$statusKey]) ? $existing['status_dates'][$statusKey] : [];
                                        $existing['status_dates'][$statusKey] = array_values(array_unique(array_merge($existing['status_dates'][$statusKey], $datesList)));
                                        sort($existing['status_dates'][$statusKey]);
                                    }

                                    $existing['records'] = array_merge($existing['records'] ?? [], $info['records'] ?? []);
                                    $existing['notes'] = array_values(array_unique(array_merge($existing['notes'] ?? [], $info['notes'] ?? [])));

                                    $existing['status_counts'] = [];
                                    foreach ($existing['status_dates'] as $statusKey => $datesList) {
                                        $existing['status_counts'][$statusKey] = count($datesList);
                                    }

                                    $existingSource = $existing['source'] ?? 'attendance_table';
                                    if (strpos($existingSource, 'dtr_upload') === false) {
                                        $existing['source'] = $existingSource . '+dtr_upload';
                                    }
                                }

                                $branchAttendance['code_index'][$codeKey] = $employeeId;
                            }
                        }
                    }
                }
            } catch (Throwable $mergeError) {
                $summary['warnings'][] = 'Unable to merge DTR upload: ' . $mergeError->getMessage();
            }
        }

        $branchDirectory = payroll_get_branches($pdo);
        $attendanceOverview = [];
        foreach ($employeesByBranch as $bId => $empList) {
            if ($branchId && $bId !== $branchId) {
                continue;
            }
            $attendanceBucket = $attendanceByBranch[$bId]['by_employee'] ?? [];
            $withAttendance = 0;
            $statusTotals = [];
            foreach ($attendanceBucket as $employeeAttendance) {
                $hasRecords = !empty($employeeAttendance['present_dates']) || !empty($employeeAttendance['records']);
                if ($hasRecords) {
                    $withAttendance++;
                }
                foreach ($employeeAttendance['status_counts'] ?? [] as $statusKey => $countVal) {
                    if (!isset($statusTotals[$statusKey])) {
                        $statusTotals[$statusKey] = 0;
                    }
                    $statusTotals[$statusKey] += (int)$countVal;
                }
            }
            $attendanceOverview[$bId] = [
                'branch_id' => $bId,
                'branch_code' => $branchDirectory[$bId]['code'] ?? null,
                'branch_name' => $branchDirectory[$bId]['name'] ?? null,
                'employees_total' => count($empList),
                'employees_with_attendance' => $withAttendance,
                'status_counts' => $statusTotals,
            ];
        }
        $summary['attendance_overview'] = $attendanceOverview;

        $selectPayslip = $pdo->prepare('SELECT * FROM payslips WHERE payroll_run_id = :run AND employee_id = :emp LIMIT 1');

        $insertColumns = ['payroll_run_id', 'employee_id', 'period_start', 'period_end', 'basic_pay', 'total_earnings', 'total_deductions', 'net_pay', 'breakdown', 'status'];
        $insertValues = [':run', ':emp', ':start', ':end', ':basic', ':earn', ':ded', ':net', ':breakdown', ':status'];
        if ($hasGross) {
            $insertColumns[] = 'gross_pay';
            $insertValues[] = ':gross';
        }
        if ($hasEarnJson) {
            $insertColumns[] = 'earnings_json';
            $insertValues[] = ':earn_json';
        }
        if ($hasDedJson) {
            $insertColumns[] = 'deductions_json';
            $insertValues[] = ':ded_json';
        }
        if ($hasGeneratedBy) {
            $insertColumns[] = 'generated_by';
            $insertValues[] = ':gen_by';
        }
        if ($hasChangeReason) {
            $insertColumns[] = 'change_reason';
            $insertValues[] = ':reason';
        }
        if ($hasVersionCol) {
            $insertColumns[] = 'version';
            $insertValues[] = '1';
        }
        if ($hasUpdatedAt) {
            $insertColumns[] = 'updated_at';
            $insertValues[] = 'NOW()';
        }
        $insertSql = 'INSERT INTO payslips (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ') RETURNING id';
        $insertPayslip = $pdo->prepare($insertSql);
        
        // Debug: Log the actual SQL being prepared  
        sys_log('PAYROLL-SQL-DEBUG', 'Prepared INSERT statement', [
            'module' => 'payroll',
            'context' => [
                'sql' => $insertSql,
                'columns' => $insertColumns,
                'values' => $insertValues
            ]
        ]);

        $updateParts = [
            'basic_pay = :basic',
            'total_earnings = :earn',
            'total_deductions = :ded',
            'net_pay = :net',
            'breakdown = :breakdown',
            'status = :status',
        ];
        if ($hasGross) {
            $updateParts[] = 'gross_pay = :gross';
        }
        if ($hasEarnJson) {
            $updateParts[] = 'earnings_json = :earn_json';
        }
        if ($hasDedJson) {
            $updateParts[] = 'deductions_json = :ded_json';
        }
        if ($hasGeneratedBy) {
            $updateParts[] = 'generated_by = :gen_by';
        }
        if ($hasChangeReason) {
            $updateParts[] = 'change_reason = :reason';
        }
        if ($hasVersionCol) {
            $updateParts[] = 'version = COALESCE(version, 0) + 1';
        }
        if ($hasUpdatedAt) {
            $updateParts[] = 'updated_at = NOW()';
        }
        $updateSql = 'UPDATE payslips SET ' . implode(', ', $updateParts) . ' WHERE id = :id';
        $updatePayslip = $pdo->prepare($updateSql);

        $insertItem = null;
        $deleteItems = null;
        if ($hasPayslipItems) {
            $insertItem = $pdo->prepare('INSERT INTO payslip_items (payslip_id, type, code, label, amount, meta) VALUES (:ps, :type, :code, :label, :amount, :meta)');
            $deleteItems = $pdo->prepare('DELETE FROM payslip_items WHERE payslip_id = :ps');
        }

        $insertVersion = null;
        $selectItemsSnapshot = null;
        if ($hasPayslipVersions) {
            try {
                $insertVersion = $pdo->prepare('INSERT INTO payslip_versions (payslip_id, version, snapshot, change_reason, created_by) VALUES (:ps, :version, :snapshot, :reason, :by)');
            } catch (Throwable $prepErr) {
                sys_log('PAYROLL-PREP-ERROR', 'Failed preparing insertVersion: ' . $prepErr->getMessage(), [
                    'module' => 'payroll',
                    'context' => ['run_id' => $runId]
                ]);
            }
            if ($hasPayslipItems) {
                try {
                    $selectItemsSnapshot = $pdo->prepare('SELECT type, code, label, amount, meta FROM payslip_items WHERE payslip_id = :ps ORDER BY id');
                } catch (Throwable $prepErr) {
                    sys_log('PAYROLL-PREP-ERROR', 'Failed preparing selectItemsSnapshot: ' . $prepErr->getMessage(), [
                        'module' => 'payroll',
                        'context' => ['run_id' => $runId]
                    ]);
                }
            }
        }
        
        sys_log('PAYROLL-LOOP-START', 'Starting employee loop', [
            'module' => 'payroll',
            'context' => [
                'run_id' => $runId,
                'employee_count' => count($employees),
                'in_transaction' => $pdo->inTransaction()
            ]
        ]);

        foreach ($employees as $employeeRow) {
            $employeeId = (int)$employeeRow['id'];
            $employeeCode = trim((string)($employeeRow['employee_code'] ?? ''));
            
            try {
                // Force clean slate - aggressive rollback if needed
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $rollbackErr) {
                    // If rollback fails, try direct ROLLBACK command
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $ignore) {
                        // Last resort - continue anyway
                    }
                }
                
                // Log start of employee processing
                sys_log('PAYROLL-EMP-START', 'Starting payroll generation for employee #' . $employeeId, [
                    'module' => 'payroll',
                    'context' => [
                        'employee_id' => $employeeId,
                        'employee_code' => $employeeCode,
                        'run_id' => $runId,
                        'in_transaction' => $pdo->inTransaction()
                    ]
                ]);
                
                // ===== PHASE 1: READ-ONLY DATA GATHERING (before transaction) =====
                // Gather all data needed for payroll computation WITHOUT starting a transaction
                // This prevents transaction aborts from failed SELECT queries
                
                $branchForEmp = (int)($employeeRow['branch_id'] ?? 0);
                $employeeCode = trim((string)($employeeRow['employee_code'] ?? ''));
                $employeeCodeKey = $employeeCode !== '' ? strtoupper($employeeCode) : null;
                $attendanceInfo = [];
                if ($branchForEmp && isset($attendanceByBranch[$branchForEmp])) {
                    $branchAttendance = $attendanceByBranch[$branchForEmp];
                    if (isset($branchAttendance['by_employee'][$employeeId])) {
                        $attendanceInfo = $branchAttendance['by_employee'][$employeeId];
                    } elseif ($employeeCodeKey && isset($branchAttendance['code_index'][$employeeCodeKey])) {
                        $mappedId = $branchAttendance['code_index'][$employeeCodeKey];
                        if (isset($branchAttendance['by_employee'][$mappedId])) {
                            $attendanceInfo = $branchAttendance['by_employee'][$mappedId];
                        }
                    }
                }

                $overrideProfile = $compensationOverrides[$employeeId] ?? null;
                $compProfile = payroll_build_compensation_profile($compDefaults, $overrideProfile ?? null);

                $profileOverride = $profileOverridesMap[$employeeId] ?? [];
                $profileOverride = is_array($profileOverride) ? $profileOverride : [];

                $defaultWorkingDays = (int)($settings['rate_computation_defaults']['config']['working_days_per_month'] ?? 22);
                if ($defaultWorkingDays <= 0) {
                    $defaultWorkingDays = 22;
                }
                $defaultHoursPerDay = (int)($settings['rate_computation_defaults']['config']['hours_per_day'] ?? 8);
                if ($defaultHoursPerDay <= 0) {
                    $defaultHoursPerDay = 8;
                }

                $rateWorkingDays = $defaultWorkingDays;
                if (isset($profileOverride['working_days_per_month']) && (int)$profileOverride['working_days_per_month'] > 0) {
                    $rateWorkingDays = (int)$profileOverride['working_days_per_month'];
                }
                $rateHoursPerDay = $defaultHoursPerDay;
                if (isset($profileOverride['hours_per_day']) && (int)$profileOverride['hours_per_day'] > 0) {
                    $rateHoursPerDay = (int)$profileOverride['hours_per_day'];
                }

                $rateBaseline = payroll_calculate_rates($employeeRow, [], [
                    'working_days_per_month' => $rateWorkingDays,
                    'hours_per_day' => $rateHoursPerDay,
                ]);
                $rateBaseline = payroll_apply_profile_rate_overrides($rateBaseline, $profileOverride);
                $baseHourlyRate = (float)($rateBaseline['hourly'] ?? 0.0);
                $overtimeMultiplier = payroll_resolve_overtime_multiplier($profileOverride);

                $overtimeSummary = [];
                if ($baseHourlyRate > 0) {
                    $overtimeSummary = payroll_fetch_overtime_summary($pdo, $employeeId, $periodStart, $periodEnd, $baseHourlyRate, $overtimeMultiplier, $runId);
                    if (empty($profileOverride['allow_overtime']) && !empty($overtimeSummary['request_count'])) {
                        $summary['warnings'][] = 'Employee #' . $employeeId . ' has approved overtime but overtime is disabled in their payroll profile.';
                    }
                }

                // Fetch queued adjustments for this employee and period
                $queuedAdjustments = payroll_get_queued_adjustments($pdo, $employeeId, $periodStart, $periodEnd, $runId);
                if (!empty($queuedAdjustments)) {
                    $summary['warnings'][] = 'Employee #' . $employeeId . ': ' . count($queuedAdjustments) . ' queued adjustment(s) will be applied.';
                }

                $components = payroll_compute_payslip_components(
                    $employeeRow,
                    $settings,
                    $periodStart,
                    $periodEnd,
                    $attendanceInfo['present_dates'] ?? [],
                    $attendanceInfo,
                    $compProfile,
                    $profileOverride,
                    $overtimeSummary,
                    $queuedAdjustments
                );
                
                // ===== PHASE 2: TRANSACTION-BASED WRITES =====
                // Now that all data is gathered, start transaction for writes
                
                // Begin transaction with error handling
                try {
                    $pdo->beginTransaction();
                } catch (Throwable $beginError) {
                    sys_log('PAYROLL-BEGIN-FAIL', 'Failed to begin transaction for employee #' . $employeeId, [
                        'module' => 'payroll',
                        'context' => [
                            'employee_id' => $employeeId,
                            'error' => $beginError->getMessage(),
                            'in_transaction' => $pdo->inTransaction()
                        ]
                    ]);
                    throw $beginError;
                }

                try {
                    $selectPayslip->execute([':run' => $runId, ':emp' => $employeeId]);
                    $existing = $selectPayslip->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $selectError) {
                    sys_log('PAYROLL-SELECT-FAIL', 'Failed to check existing payslip for employee #' . $employeeId, [
                        'module' => 'payroll',
                        'context' => [
                            'employee_id' => $employeeId,
                            'employee_code' => $employeeCode,
                            'run_id' => $runId,
                            'error' => $selectError->getMessage(),
                            'error_code' => $selectError->getCode(),
                            'sql_state' => $selectError->errorInfo ?? null
                        ]
                    ]);
                    throw $selectError;
                }

                $totals = $components['totals'];
                $earnings = $components['earnings'];
                $deductions = $components['deductions'];

                if (empty($attendanceInfo) && $branchId) {
                    $summary['warnings'][] = 'No attendance data for employee #' . $employeeId . ' (' . ($employeeCode ?: 'no code') . ')';
                }
                foreach ($components['warnings'] as $warning) {
                    $summary['warnings'][] = 'Employee #' . $employeeId . ': ' . $warning;
                }

                $earningsJson = json_encode($earnings, JSON_UNESCAPED_SLASHES);
                $deductionsJson = json_encode($deductions, JSON_UNESCAPED_SLASHES);
                $breakdown = json_encode([
                    'engine' => 'v0.1',
                    'period' => [$periodStart, $periodEnd],
                    'attendance' => $components['meta']['attendance'],
                    'notes' => $components['meta']['notes'],
                    'adjustments' => $components['meta']['adjustments'],
                    'rates' => $components['meta']['rates'] ?? [],
                    'statutory' => $components['meta']['statutory'] ?? [],
                    'compensation' => $components['meta']['compensation'] ?? [],
                    'profile' => $components['meta']['profile'] ?? [],
                    'overtime' => $components['meta']['overtime'] ?? $overtimeSummary,
                ], JSON_UNESCAPED_SLASHES);
                $status = 'locked';
                $reason = $existing ? 'Recompute' : 'Initial compute';

                $commonBindings = [
                    ':basic' => $components['basic_pay'],
                    ':earn' => $totals['earnings'],
                    ':ded' => $totals['deductions'],
                    ':net' => $totals['net'],
                    ':breakdown' => $breakdown,
                    ':status' => $status,
                ];
                if ($hasGross) {
                    $commonBindings[':gross'] = $totals['gross'];
                }
                if ($hasEarnJson) {
                    $commonBindings[':earn_json'] = $earningsJson;
                }
                if ($hasDedJson) {
                    $commonBindings[':ded_json'] = $deductionsJson;
                }
                if ($hasGeneratedBy) {
                    $commonBindings[':gen_by'] = $actingUserId ?: null;
                }
                if ($hasChangeReason) {
                    $commonBindings[':reason'] = $reason;
                }

                if ($existing) {
                    if ($hasPayslipVersions && $insertVersion) {
                        $snapshot = $existing;
                        if ($selectItemsSnapshot) {
                            $selectItemsSnapshot->execute([':ps' => (int)$existing['id']]);
                            $snapshot['items'] = $selectItemsSnapshot->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        }
                        $insertVersion->execute([
                            ':ps' => (int)$existing['id'],
                            ':version' => (int)($existing['version'] ?? 1),
                            ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                            ':reason' => 'Superseded by recompute',
                            ':by' => $actingUserId ?: null,
                        ]);
                    }

                    $updateBindings = $commonBindings;
                    $updateBindings[':id'] = (int)$existing['id'];
                    $updatePayslip->execute($updateBindings);
                    $payslipId = (int)$existing['id'];
                    $summary['updated']++;
                } else {
                    $insertBindings = $commonBindings;
                    $insertBindings[':run'] = $runId;
                    $insertBindings[':emp'] = $employeeId;
                    $insertBindings[':start'] = $periodStart;
                    $insertBindings[':end'] = $periodEnd;
                    
                    // Debug: Log bindings before execute
                    sys_log('PAYROLL-BIND-DEBUG', 'Insert bindings for employee #' . $employeeId, [
                        'module' => 'payroll',
                        'context' => [
                            'employee_id' => $employeeId,
                            'employee_code' => $employeeRow['employee_code'] ?? 'N/A',
                            'bindings' => $insertBindings
                        ]
                    ]);
                    
                    $insertSuccess = $insertPayslip->execute($insertBindings);
                    if (!$insertSuccess) {
                        throw new Exception('Failed to insert payslip for employee #' . $employeeId . ': ' . implode(', ', $insertPayslip->errorInfo()));
                    }
                    
                    $fetchedId = $insertPayslip->fetchColumn();
                    if ($fetchedId === false) {
                        throw new Exception('Failed to fetch inserted payslip ID for employee #' . $employeeId);
                    }
                    
                    $payslipId = (int)$fetchedId;
                    $summary['generated']++;
                }

                if ($hasPayslipItems && $deleteItems && $insertItem) {
                    $deleteItems->execute([':ps' => $payslipId]);
                    foreach ($earnings as $entry) {
                        $insertItem->execute([
                            ':ps' => $payslipId,
                            ':type' => 'earning',
                            ':code' => $entry['code'] ?? null,
                            ':label' => $entry['label'],
                            ':amount' => $entry['amount'],
                            ':meta' => json_encode((object)[], JSON_UNESCAPED_SLASHES),
                        ]);
                    }
                    foreach ($deductions as $entry) {
                        $insertItem->execute([
                            ':ps' => $payslipId,
                            ':type' => 'deduction',
                            ':code' => $entry['code'] ?? null,
                            ':label' => $entry['label'],
                            ':amount' => $entry['amount'],
                            ':meta' => json_encode((object)[], JSON_UNESCAPED_SLASHES),
                        ]);
                    }
                }

                // Mark queued adjustments as applied
                $appliedAdjustmentIds = $components['applied_adjustment_ids'] ?? [];
                if (!empty($appliedAdjustmentIds)) {
                    payroll_mark_adjustments_applied($pdo, $appliedAdjustmentIds, $payslipId);
                }

                $pdo->commit();
            } catch (Throwable $employeeError) {
                // PostgreSQL requires explicit rollback after ANY error in a transaction
                // All subsequent commands fail with "current transaction is aborted" until ROLLBACK
                
                // Log the actual error before rollback
                $errorMsg = $employeeError->getMessage();
                $errorCode = $employeeError->getCode();
                
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $rollbackError) {
                    // Rollback itself failed - force with exec
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $forceRollbackError) {
                        // Last resort: try to recover connection by closing and reopening would require reconnection
                        // For now, just log the critical error
                        sys_log('CRITICAL-PAYROLL-ROLLBACK', 'Cannot rollback transaction', [
                            'module' => 'payroll',
                            'employee_id' => (int)$employeeRow['id'],
                            'rollback_error' => $rollbackError->getMessage(),
                            'original_error' => $errorMsg
                        ]);
                    }
                }
                
                // Include the SQL error code in the summary for debugging
                $empId = (int)$employeeRow['id'];
                $empCode = $employeeRow['employee_code'] ?? 'N/A';

                sys_log('PAYROLL-EMP-ERROR', 'Failed generating payslip for employee #' . $empId . ': ' . $errorMsg, [
                    'module' => 'payroll',
                    'context' => [
                        'employee_id' => $empId,
                        'employee_code' => $empCode,
                        'run_id' => $runId,
                        'error' => $errorMsg,
                        'error_code' => $errorCode,
                        'exception' => get_class($employeeError),
                    ],
                ]);

                $summary['errors'][] = "Emp #{$empId} ({$empCode}): [{$errorCode}] {$errorMsg}";
            }
        }

        $summary['ok'] = empty($summary['errors']);
        return $summary;
    } catch (Throwable $e) {
        $summary['errors'][] = $e->getMessage();
        return $summary;
    }
}

/**
 * Helper: Count weekdays (Mon-Fri) between dates inclusive.
 */
function payroll__count_weekdays(string $startDate, string $endDate): int {
    $start = strtotime($startDate);
    $end = strtotime($endDate);
    if ($start === false || $end === false || $end < $start) return 0;
    $count = 0;
    for ($ts = $start; $ts <= $end; $ts += 86400) {
        $w = (int)date('N', $ts); // 1=Mon..7=Sun
        if ($w >= 1 && $w <= 5) { $count++; }
    }
    return $count;
}

/**
 * Helper: Parse DTR CSV into map employee_code => unique date list.
 * Accepts headers: employee_code, date (YYYY-MM-DD or parseable).
 */
function payroll__parse_dtr_csv_to_map(string $absPath): array {
    $map = [];
    try {
        $fh = @fopen($absPath, 'r');
        if (!$fh) {
            return $map;
        }
        $headers = null;
        $idxCode = -1;
        $idxDate = -1;
        while (($row = fgetcsv($fh)) !== false) {
            if ($headers === null) {
                $headers = array_map(function ($h) { return strtolower(trim((string)$h)); }, $row);
                $idxCode = array_search('employee_code', $headers, true);
                $idxDate = array_search('date', $headers, true);
                if ($idxCode === false) { $idxCode = -1; }
                if ($idxDate === false) { $idxDate = -1; }
                continue;
            }
            if ($idxCode < 0 || $idxDate < 0) {
                break;
            }
            $code = strtoupper(trim((string)($row[$idxCode] ?? '')));
            $dateStr = trim((string)($row[$idxDate] ?? ''));
            if ($code === '' || $dateStr === '') {
                continue;
            }
            $date = date('Y-m-d', strtotime($dateStr));
            // Detect unparseable dates (strtotime returns false → date() yields 1970-01-01)
            $ts = strtotime($dateStr);
            if ($ts === false) {
                continue;
            }
            $date = date('Y-m-d', $ts);
            if (!isset($map[$code])) {
                $map[$code] = [
                    'branch_id' => null,
                    'employee_id' => null,
                    'employee_code' => $code,
                    'present_dates' => [],
                    'status_dates' => ['present' => []],
                    'status_counts' => [],
                    'records' => [],
                    'source' => 'dtr_upload',
                    'notes' => [],
                ];
            }
            if (!in_array($date, $map[$code]['present_dates'], true)) {
                $map[$code]['present_dates'][] = $date;
                $map[$code]['status_dates']['present'][] = $date;
            }
            $map[$code]['records'][] = ['date' => $date, 'status' => 'present'];
        }
        @fclose($fh);
        foreach ($map as $code => $payload) {
            $uniquePresent = array_values(array_unique($payload['present_dates']));
            sort($uniquePresent);
            $map[$code]['present_dates'] = $uniquePresent;
            $map[$code]['status_dates']['present'] = $uniquePresent;
            $map[$code]['status_counts']['present'] = count($uniquePresent);
        }
    } catch (Throwable $e) {
        // ignore; return what we have
    }
    return $map;
}

/**
 * Generate payslips for a batch and manage batch status transitions.
 */
function payroll_generate_payslips_for_batch(PDO $pdo, int $batchId, ?int $actingUserId = null): array {
    $summary = ['ok' => false, 'generated' => 0, 'updated' => 0, 'errors' => []];
    try {
        $stmt = $pdo->prepare('SELECT payroll_run_id, branch_id FROM payroll_batches WHERE id = :id');
        $stmt->execute([':id' => $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $summary['errors'][] = 'Batch not found'; return $summary; }
        $runId = (int)$row['payroll_run_id'];
        $branchId = (int)$row['branch_id'];

        // mark computing
        try {
            $pdo->prepare("UPDATE payroll_batches SET status = 'computing', updated_at = NOW() WHERE id = :id")->execute([':id' => $batchId]);
        } catch (Throwable $e1) {}

        $res = payroll_generate_payslips_for_run($pdo, $runId, $branchId, $actingUserId);
        $summary = $res;
        // mark for_review when ok, else error
        try {
            $pdo->prepare("UPDATE payroll_batches SET status = :st, last_computed_at = NOW(), updated_at = NOW() WHERE id = :id")
                ->execute([':st' => ($res['ok'] ? 'for_review' : 'error'), ':id' => $batchId]);
        } catch (Throwable $e2) {}
        return $summary;
    } catch (Throwable $e) {
        $summary['errors'][] = $e->getMessage();
        return $summary;
    }
}

/** Mark run submitted and stamp submitted_at. */
function payroll_mark_run_submitted(PDO $pdo, int $runId, ?int $actingUserId = null): bool {
    try {
        $stmt = $pdo->prepare("UPDATE payroll_runs SET status = 'submitted', submitted_at = COALESCE(submitted_at, NOW()), initiated_by = COALESCE(initiated_by, :by), updated_at = NOW() WHERE id = :id");
        $stmt->execute([':by' => $actingUserId ?: null, ':id' => $runId]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-RUN-SUBMIT', 'Failed marking run submitted: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['run_id' => $runId]]);
        return false;
    }
}

/** Attach a DTR upload to a batch and mark submission info. */
function payroll_attach_batch_dtr(PDO $pdo, int $batchId, string $relativePath, ?int $actingUserId = null): bool {
    try {
        $sql = "UPDATE payroll_batches
                SET dtr_file_path = :path,
                    dtr_uploaded_at = COALESCE(dtr_uploaded_at, NOW()),
                    submitted_by = COALESCE(submitted_by, :by),
                    status = CASE WHEN status IN ('pending','awaiting_dtr') THEN 'submitted' ELSE status END,
                    updated_at = NOW()
                WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':path' => $relativePath, ':by' => $actingUserId ?: null, ':id' => $batchId]);
        action_log('payroll', 'batch_dtr_uploaded', 'success', ['batch_id' => $batchId, 'path' => $relativePath]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-DTR-UPLOAD', 'Failed attaching DTR to batch: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['batch_id' => $batchId]]);
        return false;
    }
}

/** Enqueue a batch for computation; returns job id string or null on failure. */
function payroll_enqueue_batch_compute(PDO $pdo, int $batchId, ?int $actingUserId = null, ?string $payloadPath = null): ?string {
    try {
        // Generate a random job id (uuid-ish)
        $jobId = bin2hex(random_bytes(16));

        $pdo->beginTransaction();

        // Insert job
        $ins = $pdo->prepare('INSERT INTO payroll_compute_jobs (id, payroll_batch_id, status, progress, payload_path, created_at, updated_at) VALUES (:id, :batch, \n            \'' . "queued" . '\', 0, :payload, NOW(), NOW())');
        $ins->execute([':id' => $jobId, ':batch' => $batchId, ':payload' => $payloadPath]);

        // Attach to batch
        $upd = $pdo->prepare("UPDATE payroll_batches SET computation_job_id = :job, status = CASE WHEN status = 'pending' THEN 'awaiting_dtr' ELSE status END, updated_at = NOW() WHERE id = :id");
        $upd->execute([':job' => $jobId, ':id' => $batchId]);

        $pdo->commit();
        action_log('payroll', 'batch_enqueued', 'success', ['batch_id' => $batchId, 'job_id' => $jobId]);
        return $jobId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $ie) {} }
        sys_log('PAYROLL-ENQUEUE', 'Failed to enqueue batch compute: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['batch_id' => $batchId]]);
        return null;
    }
}

/** Get job status by id. */
function payroll_get_job(PDO $pdo, string $jobId): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM payroll_compute_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get active adjustment approvers in order
 */
function payroll_get_adjustment_approvers(PDO $pdo, bool $activeOnly = true): array {
    if (!payroll_table_exists($pdo, 'payroll_adjustment_approvers')) {
        return [];
    }
    
    try {
        $sql = 'SELECT paa.*, u.full_name, u.email, e.first_name, e.last_name 
                FROM payroll_adjustment_approvers paa 
                JOIN users u ON paa.user_id = u.id 
                LEFT JOIN employees e ON e.user_id = u.id';
        
        if ($activeOnly) {
            $sql .= ' WHERE paa.active = TRUE';
        }
        
        $sql .= ' ORDER BY paa.approval_order';
        
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-ADJUSTMENT-APPROVERS', 'Failed fetching adjustment approvers: ' . $e->getMessage(), [
            'module' => 'payroll',
        ]);
        return [];
    }
}

/**
 * Check if user is an adjustment approver
 */
function payroll_is_adjustment_approver(PDO $pdo, int $userId): bool {
    if (!payroll_table_exists($pdo, 'payroll_adjustment_approvers')) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_adjustment_approvers WHERE user_id = :uid AND active = TRUE');
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        sys_log('PAYROLL-ADJUSTMENT-APPROVER-CHECK', 'Failed checking adjustment approver: ' . $e->getMessage(), [
            'module' => 'payroll',
            'context' => ['user_id' => $userId]
        ]);
        return false;
    }
}

/**
 * Get pending adjustments for a payroll run (for approval review)
 */
function payroll_get_pending_adjustments(PDO $pdo, ?int $runId = null): array {
    if (!payroll_table_exists($pdo, 'payroll_adjustment_queue')) {
        return [];
    }
    
    try {
        $sql = 'SELECT paq.*, 
                       e.employee_code, e.first_name, e.last_name, e.department_id,
                       d.name as department_name,
                       pc.id as complaint_id, pc.topic as complaint_topic,
                       u.full_name as created_by_username
                FROM payroll_adjustment_queue paq
                JOIN employees e ON paq.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN payroll_complaints pc ON paq.complaint_id = pc.id
                LEFT JOIN users u ON paq.created_by = u.id
                WHERE paq.status = :status 
                  AND paq.approval_status = :approval_status';
        
        $params = [
            ':status' => 'queued',
            ':approval_status' => 'pending_approval',
        ];
        
        if ($runId) {
            $sql .= ' AND (paq.payroll_run_id = :run_id OR paq.payroll_run_id IS NULL)';
            $params[':run_id'] = $runId;
        }
        
        $sql .= ' ORDER BY paq.created_at DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        sys_log('PAYROLL-PENDING-ADJUSTMENTS', 'Failed fetching pending adjustments: ' . $e->getMessage(), [
            'module' => 'payroll',
            'context' => ['run_id' => $runId]
        ]);
        return [];
    }
}

/**
 * Approve a payroll adjustment
 */
function payroll_approve_adjustment(PDO $pdo, int $adjustmentId, int $approverUserId): array {
    $out = ['ok' => false, 'error' => null];
    
    if (!payroll_table_exists($pdo, 'payroll_adjustment_queue')) {
        $out['error'] = 'Adjustment queue table not found';
        return $out;
    }
    
    if (!payroll_is_adjustment_approver($pdo, $approverUserId)) {
        $out['error'] = 'You are not authorized to approve adjustments';
        return $out;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current adjustment
        $stmt = $pdo->prepare('SELECT * FROM payroll_adjustment_queue WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $adjustmentId]);
        $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adjustment) {
            $pdo->rollBack();
            $out['error'] = 'Adjustment not found';
            return $out;
        }
        
        if ($adjustment['approval_status'] !== 'pending_approval') {
            $pdo->rollBack();
            $out['error'] = 'Adjustment has already been processed';
            return $out;
        }
        
        // Approve adjustment
        $stmt = $pdo->prepare('UPDATE payroll_adjustment_queue 
                               SET approval_status = :status, 
                                   approved_by = :approver, 
                                   approved_at = NOW(),
                                   status = :queue_status,
                                   updated_at = NOW() 
                               WHERE id = :id');
        $stmt->execute([
            ':status' => 'approved',
            ':approver' => $approverUserId,
            ':queue_status' => 'pending', // Change from 'queued' to 'pending' for payroll application
            ':id' => $adjustmentId,
        ]);
        
        $pdo->commit();
        
        action_log('payroll_adjustment', 'approve_adjustment', 'success', [
            'adjustment_id' => $adjustmentId,
            'employee_id' => $adjustment['employee_id'],
            'amount' => $adjustment['amount'],
        ]);
        
        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sys_log('PAYROLL-APPROVE-ADJUSTMENT', 'Failed approving adjustment: ' . $e->getMessage(), [
            'module' => 'payroll',
            'context' => ['adjustment_id' => $adjustmentId, 'approver' => $approverUserId]
        ]);
        $out['error'] = 'Internal error approving adjustment';
        return $out;
    }
}

/**
 * Reject a payroll adjustment
 */
function payroll_reject_adjustment(PDO $pdo, int $adjustmentId, int $approverUserId, string $reason): array {
    $out = ['ok' => false, 'error' => null];
    
    if (!payroll_table_exists($pdo, 'payroll_adjustment_queue')) {
        $out['error'] = 'Adjustment queue table not found';
        return $out;
    }
    
    if (!payroll_is_adjustment_approver($pdo, $approverUserId)) {
        $out['error'] = 'You are not authorized to reject adjustments';
        return $out;
    }
    
    if (empty(trim($reason))) {
        $out['error'] = 'Rejection reason is required';
        return $out;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current adjustment
        $stmt = $pdo->prepare('SELECT * FROM payroll_adjustment_queue WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $adjustmentId]);
        $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adjustment) {
            $pdo->rollBack();
            $out['error'] = 'Adjustment not found';
            return $out;
        }
        
        if ($adjustment['approval_status'] !== 'pending_approval') {
            $pdo->rollBack();
            $out['error'] = 'Adjustment has already been processed';
            return $out;
        }
        
        // Reject adjustment
        $stmt = $pdo->prepare('UPDATE payroll_adjustment_queue 
                               SET approval_status = :status, 
                                   approved_by = :approver, 
                                   approved_at = NOW(),
                                   rejection_reason = :reason,
                                   status = :queue_status,
                                   updated_at = NOW() 
                               WHERE id = :id');
        $stmt->execute([
            ':status' => 'rejected',
            ':approver' => $approverUserId,
            ':reason' => trim($reason),
            ':queue_status' => 'cancelled',
            ':id' => $adjustmentId,
        ]);
        
        $pdo->commit();
        
        action_log('payroll_adjustment', 'reject_adjustment', 'success', [
            'adjustment_id' => $adjustmentId,
            'employee_id' => $adjustment['employee_id'],
            'amount' => $adjustment['amount'],
            'reason' => trim($reason),
        ]);
        
        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sys_log('PAYROLL-REJECT-ADJUSTMENT', 'Failed rejecting adjustment: ' . $e->getMessage(), [
            'module' => 'payroll',
            'context' => ['adjustment_id' => $adjustmentId, 'approver' => $approverUserId]
        ]);
        $out['error'] = 'Internal error rejecting adjustment';
        return $out;
    }
}

function payroll_get_compensation_templates(PDO $pdo, ?string $category = null, bool $activeOnly = true, bool $includeExpired = false): array {
    try {
        $sql = 'SELECT id, category, name, code, amount_type, static_amount, percentage, is_modifiable, 
                       effectivity_until, notes, is_active, created_by, updated_by, created_at, updated_at 
                FROM compensation_templates WHERE 1=1';
        $params = [];
        
        if ($category !== null && in_array($category, ['allowance', 'contribution', 'tax', 'deduction'])) {
            $sql .= ' AND category = :category';
            $params[':category'] = $category;
        }
        
        if ($activeOnly) {
            $sql .= ' AND is_active = TRUE';
        }
        
        if (!$includeExpired) {
            $sql .= ' AND (effectivity_until IS NULL OR effectivity_until >= CURRENT_DATE)';
        }
        
        $sql .= ' ORDER BY category, name';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert numeric strings to proper types
        foreach ($templates as &$template) {
            $template['id'] = (int)$template['id'];
            $template['static_amount'] = $template['static_amount'] !== null ? (float)$template['static_amount'] : null;
            $template['percentage'] = $template['percentage'] !== null ? (float)$template['percentage'] : null;
            $template['is_modifiable'] = (bool)$template['is_modifiable'];
            $template['is_active'] = (bool)$template['is_active'];
            $template['created_by'] = $template['created_by'] !== null ? (int)$template['created_by'] : null;
            $template['updated_by'] = $template['updated_by'] !== null ? (int)$template['updated_by'] : null;
        }
        
        return $templates;
    } catch (Throwable $e) {
        sys_log('PAYROLL-GET-TEMPLATES', 'Failed reading compensation templates: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['category' => $category, 'activeOnly' => $activeOnly]
        ]);
        return [];
    }
}

/**
 * Save or update a compensation template
 */
function payroll_save_compensation_template(PDO $pdo, array $data, int $userId): array {
    $out = ['ok' => false];
    
    // Validate required fields
    if (empty($data['category']) || !in_array($data['category'], ['allowance', 'contribution', 'tax', 'deduction'])) {
        $out['error'] = 'Invalid category';
        return $out;
    }
    
    if (empty($data['name']) || strlen(trim($data['name'])) < 2) {
        $out['error'] = 'Template name is required';
        return $out;
    }
    
    if (empty($data['amount_type']) || !in_array($data['amount_type'], ['static', 'percentage'])) {
        $out['error'] = 'Invalid amount type';
        return $out;
    }
    
    // Validate amount based on type
    if ($data['amount_type'] === 'static') {
        if (!isset($data['static_amount']) || $data['static_amount'] < 0) {
            $out['error'] = 'Static amount must be >= 0';
            return $out;
        }
        $staticAmount = round((float)$data['static_amount'], 2);
        $percentage = null;
    } else {
        if (!isset($data['percentage']) || $data['percentage'] < 0 || $data['percentage'] > 100) {
            $out['error'] = 'Percentage must be between 0 and 100';
            return $out;
        }
        $percentage = round((float)$data['percentage'], 2);
        $staticAmount = null;
    }
    
    try {
        $isUpdate = !empty($data['id']) && (int)$data['id'] > 0;
        
        if ($isUpdate) {
            // Update existing template
            $sql = 'UPDATE compensation_templates 
                    SET category = :category, name = :name, code = :code, amount_type = :amount_type,
                        static_amount = :static_amount, percentage = :percentage, is_modifiable = :is_modifiable,
                        effectivity_until = :effectivity_until, notes = :notes, is_active = :is_active,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id';
            $params = [
                ':id' => (int)$data['id'],
                ':category' => $data['category'],
                ':name' => trim($data['name']),
                ':code' => !empty($data['code']) ? trim($data['code']) : null,
                ':amount_type' => $data['amount_type'],
                ':static_amount' => $staticAmount,
                ':percentage' => $percentage,
                ':is_modifiable' => !empty($data['is_modifiable']),
                ':effectivity_until' => !empty($data['effectivity_until']) ? $data['effectivity_until'] : null,
                ':notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                ':is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true,
                ':updated_by' => $userId,
            ];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $out['ok'] = true;
            $out['id'] = (int)$data['id'];
            
            action_log('compensation_template', 'update', 'success', [
                'template_id' => $out['id'],
                'category' => $data['category'],
                'name' => trim($data['name']),
            ]);
        } else {
            // Create new template
            $sql = 'INSERT INTO compensation_templates 
                    (category, name, code, amount_type, static_amount, percentage, is_modifiable, 
                     effectivity_until, notes, is_active, created_by, updated_by)
                    VALUES (:category, :name, :code, :amount_type, :static_amount, :percentage, :is_modifiable,
                            :effectivity_until, :notes, :is_active, :created_by, :updated_by)
                    RETURNING id';
            $params = [
                ':category' => $data['category'],
                ':name' => trim($data['name']),
                ':code' => !empty($data['code']) ? trim($data['code']) : null,
                ':amount_type' => $data['amount_type'],
                ':static_amount' => $staticAmount,
                ':percentage' => $percentage,
                ':is_modifiable' => !empty($data['is_modifiable']),
                ':effectivity_until' => !empty($data['effectivity_until']) ? $data['effectivity_until'] : null,
                ':notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                ':is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true,
                ':created_by' => $userId,
                ':updated_by' => $userId,
            ];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $out['id'] = (int)$stmt->fetchColumn();
            $out['ok'] = true;
            
            action_log('compensation_template', 'create', 'success', [
                'template_id' => $out['id'],
                'category' => $data['category'],
                'name' => trim($data['name']),
            ]);
        }
        
        return $out;
    } catch (Throwable $e) {
        sys_log('PAYROLL-SAVE-TEMPLATE', 'Failed saving compensation template: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['data' => $data, 'user_id' => $userId]
        ]);
        $out['error'] = 'Failed to save template';
        return $out;
    }
}


function payroll_delete_compensation_template(PDO $pdo, int $id): bool {
    try {
        $stmt = $pdo->prepare('UPDATE compensation_templates SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $id]);
        
        action_log('compensation_template', 'delete', 'success', ['template_id' => $id]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-DELETE-TEMPLATE', 'Failed deleting compensation template: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['template_id' => $id]
        ]);
        return false;
    }
}


function payroll_get_shift_benefits(PDO $pdo, bool $activeOnly = true, bool $includeExpired = false): array {
    try {
        $sql = 'SELECT id, shift_name, shift_code, benefit_type, amount_type, static_amount, percentage,
                       effectivity_until, notes, is_active, created_by, updated_by, created_at, updated_at
                FROM shift_benefits WHERE 1=1';
        $params = [];
        
        if ($activeOnly) {
            $sql .= ' AND is_active = TRUE';
        }
        
        if (!$includeExpired) {
            $sql .= ' AND (effectivity_until IS NULL OR effectivity_until >= CURRENT_DATE)';
        }
        
        $sql .= ' ORDER BY shift_name, benefit_type';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $benefits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert numeric strings to proper types
        foreach ($benefits as &$benefit) {
            $benefit['id'] = (int)$benefit['id'];
            $benefit['static_amount'] = $benefit['static_amount'] !== null ? (float)$benefit['static_amount'] : null;
            $benefit['percentage'] = $benefit['percentage'] !== null ? (float)$benefit['percentage'] : null;
            $benefit['is_active'] = (bool)$benefit['is_active'];
            $benefit['created_by'] = $benefit['created_by'] !== null ? (int)$benefit['created_by'] : null;
            $benefit['updated_by'] = $benefit['updated_by'] !== null ? (int)$benefit['updated_by'] : null;
        }
        
        return $benefits;
    } catch (Throwable $e) {
        sys_log('PAYROLL-GET-SHIFT-BENEFITS', 'Failed reading shift benefits: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['activeOnly' => $activeOnly]
        ]);
        return [];
    }
}

function payroll_save_shift_benefit(PDO $pdo, array $data, int $userId): array {
    $out = ['ok' => false];
    
    // Validate required fields
    if (empty($data['shift_name']) || strlen(trim($data['shift_name'])) < 2) {
        $out['error'] = 'Shift name is required';
        return $out;
    }
    
    if (empty($data['benefit_type']) || !in_array($data['benefit_type'], ['night_differential', 'shift_allowance', 'hazard_pay', 'other'])) {
        $out['error'] = 'Invalid benefit type';
        return $out;
    }
    
    if (empty($data['amount_type']) || !in_array($data['amount_type'], ['static', 'percentage'])) {
        $out['error'] = 'Invalid amount type';
        return $out;
    }
    
    // Validate amount based on type
    if ($data['amount_type'] === 'static') {
        if (!isset($data['static_amount']) || $data['static_amount'] < 0) {
            $out['error'] = 'Static amount must be >= 0';
            return $out;
        }
        $staticAmount = round((float)$data['static_amount'], 2);
        $percentage = null;
    } else {
        if (!isset($data['percentage']) || $data['percentage'] < 0 || $data['percentage'] > 100) {
            $out['error'] = 'Percentage must be between 0 and 100';
            return $out;
        }
        $percentage = round((float)$data['percentage'], 2);
        $staticAmount = null;
    }
    
    try {
        $isUpdate = !empty($data['id']) && (int)$data['id'] > 0;
        
        if ($isUpdate) {
            // Update existing benefit
            $sql = 'UPDATE shift_benefits 
                    SET shift_name = :shift_name, shift_code = :shift_code, benefit_type = :benefit_type,
                        amount_type = :amount_type, static_amount = :static_amount, percentage = :percentage,
                        effectivity_until = :effectivity_until, notes = :notes, is_active = :is_active,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id';
            $params = [
                ':id' => (int)$data['id'],
                ':shift_name' => trim($data['shift_name']),
                ':shift_code' => !empty($data['shift_code']) ? trim($data['shift_code']) : null,
                ':benefit_type' => $data['benefit_type'],
                ':amount_type' => $data['amount_type'],
                ':static_amount' => $staticAmount,
                ':percentage' => $percentage,
                ':effectivity_until' => !empty($data['effectivity_until']) ? $data['effectivity_until'] : null,
                ':notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                ':is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true,
                ':updated_by' => $userId,
            ];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $out['ok'] = true;
            $out['id'] = (int)$data['id'];
            
            action_log('shift_benefit', 'update', 'success', [
                'benefit_id' => $out['id'],
                'shift_name' => trim($data['shift_name']),
                'benefit_type' => $data['benefit_type'],
            ]);
        } else {
            // Create new benefit
            $sql = 'INSERT INTO shift_benefits 
                    (shift_name, shift_code, benefit_type, amount_type, static_amount, percentage,
                     effectivity_until, notes, is_active, created_by, updated_by)
                    VALUES (:shift_name, :shift_code, :benefit_type, :amount_type, :static_amount, :percentage,
                            :effectivity_until, :notes, :is_active, :created_by, :updated_by)
                    RETURNING id';
            $params = [
                ':shift_name' => trim($data['shift_name']),
                ':shift_code' => !empty($data['shift_code']) ? trim($data['shift_code']) : null,
                ':benefit_type' => $data['benefit_type'],
                ':amount_type' => $data['amount_type'],
                ':static_amount' => $staticAmount,
                ':percentage' => $percentage,
                ':effectivity_until' => !empty($data['effectivity_until']) ? $data['effectivity_until'] : null,
                ':notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                ':is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true,
                ':created_by' => $userId,
                ':updated_by' => $userId,
            ];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $out['id'] = (int)$stmt->fetchColumn();
            $out['ok'] = true;
            
            action_log('shift_benefit', 'create', 'success', [
                'benefit_id' => $out['id'],
                'shift_name' => trim($data['shift_name']),
                'benefit_type' => $data['benefit_type'],
            ]);
        }
        
        return $out;
    } catch (Throwable $e) {
        sys_log('PAYROLL-SAVE-SHIFT-BENEFIT', 'Failed saving shift benefit: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['data' => $data, 'user_id' => $userId]
        ]);
        $out['error'] = 'Failed to save shift benefit';
        return $out;
    }
}

/**
 * Delete (soft delete) a shift benefit
 * @param PDO $pdo Database connection
 * @param int $id Benefit ID
 * @return bool Success status
 */
function payroll_delete_shift_benefit(PDO $pdo, int $id): bool {
    try {
        $stmt = $pdo->prepare('UPDATE shift_benefits SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $id]);
        
        action_log('shift_benefit', 'delete', 'success', ['benefit_id' => $id]);
        return true;
    } catch (Throwable $e) {
        sys_log('PAYROLL-DELETE-SHIFT-BENEFIT', 'Failed deleting shift benefit: ' . $e->getMessage(), [
            'module' => 'payroll',
            'file' => __FILE__,
            'line' => __LINE__,
            'context' => ['benefit_id' => $id]
        ]);
        return false;
    }
}

/**
 * Calculate bi-monthly amount (divide by 2 for bi-monthly payroll)
 * @param float $amount Full monthly amount
 * @return float Half amount for bi-monthly period
 */
function payroll_calculate_bi_monthly_amount(float $amount): float {
    return round($amount / 2, 2);
}
