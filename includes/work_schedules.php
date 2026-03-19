<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function work_schedule_get_default_template_id(): ?int {
    $raw = app_settings_get('work_schedule.default_template_id');
    if ($raw === null || $raw === '') {
        return null;
    }
    $id = (int)$raw;
    return $id > 0 ? $id : null;
}

function work_schedule_set_default_template_id(?int $templateId, ?int $userId = null): void {
    $value = $templateId && $templateId > 0 ? (string)$templateId : '';
    app_settings_set('work_schedule.default_template_id', $value, $userId);
}

function work_schedule_fetch_default_template(PDO $pdo): ?array {
    $id = work_schedule_get_default_template_id();
    if (!$id) {
        return null;
    }
    return work_schedule_fetch_template($pdo, $id);
}

function work_schedule_weekday_map(): array {
    return [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
}

function work_schedule_normalize_days($value): array {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        }
    }
    if (!is_array($value)) {
        return [];
    }
    $days = [];
    foreach ($value as $day) {
        $dayInt = (int)$day;
        if ($dayInt >= 1 && $dayInt <= 7) {
            $days[$dayInt] = $dayInt;
        }
    }
    ksort($days);
    return array_values($days);
}

function work_schedule_format_days($value): string {
    $days = work_schedule_normalize_days($value);
    if (!$days) {
        return 'No days set';
    }
    $map = work_schedule_weekday_map();
    $labels = [];
    foreach ($days as $day) {
        $labels[] = $map[$day] ?? ('Day ' . $day);
    }
    return implode(', ', $labels);
}

function work_schedule_fetch_templates(PDO $pdo): array {
    try {
        $stmt = $pdo->query('SELECT id, name, description, start_time, end_time, break_duration_minutes, break_start_time, work_days, hours_per_week, template_type, config_level, is_active FROM work_schedule_templates ORDER BY name');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['work_days'] = work_schedule_normalize_days($row['work_days'] ?? []);
        }
        return $rows;
    } catch (Throwable $e) {
        sys_log('WORK-SCHEDULE-LIST', 'Failed to fetch work schedule templates: ' . $e->getMessage(), ['module' => 'attendance', 'file' => __FILE__, 'line' => __LINE__]);
        return [];
    }
}

function work_schedule_fetch_template(PDO $pdo, int $templateId): ?array {
    try {
        $stmt = $pdo->prepare('SELECT id, name, description, start_time, end_time, break_duration_minutes, break_start_time, work_days, hours_per_week, template_type, config_level, is_active FROM work_schedule_templates WHERE id = :id');
        $stmt->execute([':id' => $templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $row['work_days'] = work_schedule_normalize_days($row['work_days'] ?? []);
        }
        return $row ?: null;
    } catch (Throwable $e) {
        sys_log('WORK-SCHEDULE-GET', 'Failed to load work schedule template: ' . $e->getMessage(), ['module' => 'attendance', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['id' => $templateId]]);
        return null;
    }
}

function work_schedule_get_active_assignment(PDO $pdo, int $employeeId, ?string $date = null): ?array {
    $date = $date ?: date('Y-m-d');
    try {
        $sql = "SELECT ews.id, ews.schedule_template_id, ews.custom_start_time, ews.custom_end_time,
                       ews.custom_break_minutes, ews.custom_work_days, ews.custom_hours_per_week,
                       ews.effective_from, ews.effective_to, ews.priority, ews.notes,
                       tpl.name AS template_name, tpl.description AS template_description,
                       tpl.start_time AS template_start_time, tpl.end_time AS template_end_time,
                       tpl.break_duration_minutes AS template_break_minutes,
                       tpl.break_start_time AS template_break_start,
                       tpl.work_days AS template_work_days,
                       tpl.hours_per_week AS template_hours_per_week
                FROM employee_work_schedules ews
                LEFT JOIN work_schedule_templates tpl ON tpl.id = ews.schedule_template_id
                WHERE ews.employee_id = :eid
                  AND ews.effective_from <= :ref_date
                  AND (ews.effective_to IS NULL OR ews.effective_to >= :ref_date)
                ORDER BY ews.priority DESC, ews.effective_from DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':eid' => $employeeId, ':ref_date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }
        $row['custom_work_days'] = work_schedule_normalize_days($row['custom_work_days'] ?? []);
        $row['template_work_days'] = work_schedule_normalize_days($row['template_work_days'] ?? []);
        return $row;
    } catch (Throwable $e) {
        sys_log('WORK-SCHEDULE-ACTIVE', 'Failed to load active schedule: ' . $e->getMessage(), ['module' => 'attendance', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['employee_id' => $employeeId]]);
        return null;
    }
}

function work_schedule_assignment_effective_range(?array $assignment): string {
    if (!$assignment) {
        return '';
    }
    $from = $assignment['effective_from'] ?? null;
    $to = $assignment['effective_to'] ?? null;
    if ($from && $to) {
        return sprintf('%s to %s', $from, $to);
    }
    if ($from) {
        return sprintf('Effective %s', $from);
    }
    return '';
}

function work_schedule_assignment_display_days(?array $assignment): string {
    if (!$assignment) {
        return 'Not set';
    }
    if (!empty($assignment['custom_work_days'])) {
        return work_schedule_format_days($assignment['custom_work_days']);
    }
    if (!empty($assignment['template_work_days'])) {
        return work_schedule_format_days($assignment['template_work_days']);
    }
    return 'Not set';
}

function work_schedule_assignment_display_time(?array $assignment): string {
    if (!$assignment) {
        return 'Not set';
    }
    $start = $assignment['custom_start_time'] ?? $assignment['template_start_time'] ?? null;
    $end = $assignment['custom_end_time'] ?? $assignment['template_end_time'] ?? null;
    if ($start && $end) {
        return date('g:i A', strtotime($start)) . ' - ' . date('g:i A', strtotime($end));
    }
    return 'Not set';
}

function work_schedule_assignment_display_break(?array $assignment): string {
    if (!$assignment) {
        return 'No scheduled break';
    }
    $minutes = (int)($assignment['custom_break_minutes'] ?? $assignment['template_break_minutes'] ?? 0);
    if ($minutes <= 0) {
        return 'No scheduled break';
    }
    $start = $assignment['template_break_start'] ?? null;
    if ($start) {
        $formatted = @date('g:i A', strtotime($start));
        return sprintf('%d minutes starting %s', $minutes, $formatted ?: $start);
    }
    return sprintf('%d minutes', $minutes);
}

function work_schedule_assignment_display_hours(?array $assignment): string {
    if (!$assignment) {
        return 'Not set';
    }
    $hours = $assignment['custom_hours_per_week'] ?? $assignment['template_hours_per_week'] ?? null;
    if ($hours === null || $hours === '') {
        return 'Not set';
    }
    $hoursFloat = (float)$hours;
    if ($hoursFloat <= 0) {
        return 'Not set';
    }
    return rtrim(rtrim(number_format($hoursFloat, 2, '.', ''), '0'), '.') . ' hrs/week';
}
