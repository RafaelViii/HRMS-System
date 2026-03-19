<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('attendance', 'work_schedules', 'write');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/work_schedules.php';

$pageTitle = 'Work Schedules';
$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!csrf_verify($token)) {
        flash_error('Your session expired. Please try again.');
        header('Location: ' . BASE_URL . '/modules/admin/work-schedules/index');
        exit;
    }

    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create_template':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $start = trim($_POST['start_time'] ?? '');
                $end = trim($_POST['end_time'] ?? '');
                $breakMinutes = (int)($_POST['break_minutes'] ?? 0);
                $breakStart = trim($_POST['break_start'] ?? '');
                $hoursPerWeek = $_POST['hours_per_week'] !== '' ? (float)$_POST['hours_per_week'] : null;
                $days = $_POST['work_days'] ?? [];

                if ($name === '' || $start === '' || $end === '') {
                    throw new RuntimeException('Name, start time, and end time are required.');
                }
                if (strtotime($end) <= strtotime($start)) {
                    throw new RuntimeException('End time must be after start time.');
                }
                $weekdays = work_schedule_normalize_days($days);
        $stmt = $pdo->prepare('INSERT INTO work_schedule_templates (name, description, start_time, end_time, break_duration_minutes, break_start_time, work_days, hours_per_week, template_type, config_level, is_active, created_by)
                     VALUES (:name, :desc, :start, :end, :break_minutes, :break_start, :work_days, :hours_per_week, :type, :level, TRUE, :created_by)
                     RETURNING id');
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        if ($description !== '') {
          $stmt->bindValue(':desc', $description, PDO::PARAM_STR);
        } else {
          $stmt->bindValue(':desc', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':start', $start, PDO::PARAM_STR);
        $stmt->bindValue(':end', $end, PDO::PARAM_STR);
        $breakMinutesValue = $breakMinutes > 0 ? $breakMinutes : null;
        if ($breakMinutesValue === null) {
          $stmt->bindValue(':break_minutes', null, PDO::PARAM_NULL);
        } else {
          $stmt->bindValue(':break_minutes', $breakMinutesValue, PDO::PARAM_INT);
        }
        if ($breakStart !== '') {
          $stmt->bindValue(':break_start', $breakStart, PDO::PARAM_STR);
        } else {
          $stmt->bindValue(':break_start', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':work_days', json_encode($weekdays), PDO::PARAM_STR);
        if ($hoursPerWeek !== null && $hoursPerWeek > 0) {
          $stmt->bindValue(':hours_per_week', $hoursPerWeek, PDO::PARAM_STR);
        } else {
          $stmt->bindValue(':hours_per_week', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':type', 'system', PDO::PARAM_STR);
        $stmt->bindValue(':level', 'system', PDO::PARAM_STR);
        if ($currentUserId > 0) {
          $stmt->bindValue(':created_by', $currentUserId, PDO::PARAM_INT);
        } else {
          $stmt->bindValue(':created_by', null, PDO::PARAM_NULL);
        }
                $stmt->execute();
                $templateId = (int)($stmt->fetchColumn() ?: 0);
                action_log('work_schedules', 'create_template', 'success', ['template_id' => $templateId]);
                flash_success('Work schedule template created.');
                break;

            case 'update_template':
                $templateId = (int)($_POST['template_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $start = trim($_POST['start_time'] ?? '');
                $end = trim($_POST['end_time'] ?? '');
                $breakMinutes = (int)($_POST['break_minutes'] ?? 0);
                $breakStart = trim($_POST['break_start'] ?? '');
                $hoursPerWeek = $_POST['hours_per_week'] !== '' ? (float)$_POST['hours_per_week'] : null;
                $days = $_POST['work_days'] ?? [];

                if ($templateId <= 0) {
                    throw new RuntimeException('Template not found.');
                }
                if ($name === '' || $start === '' || $end === '') {
                    throw new RuntimeException('Name, start time, and end time are required.');
                }
                if (strtotime($end) <= strtotime($start)) {
                    throw new RuntimeException('End time must be after start time.');
                }
                $weekdays = work_schedule_normalize_days($days);
        $stmt = $pdo->prepare('UPDATE work_schedule_templates
                     SET name = :name,
                       description = :desc,
                       start_time = :start,
                       end_time = :end,
                       break_duration_minutes = :break_minutes,
                       break_start_time = :break_start,
                       work_days = :work_days,
                       hours_per_week = :hours_per_week,
                       updated_at = CURRENT_TIMESTAMP
                   WHERE id = :id');
        $stmt->bindValue(':id', $templateId, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        if ($description !== '') {
          $stmt->bindValue(':desc', $description, PDO::PARAM_STR);
        } else {
          $stmt->bindValue(':desc', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':start', $start, PDO::PARAM_STR);
        $stmt->bindValue(':end', $end, PDO::PARAM_STR);
        $breakMinutesValue = $breakMinutes > 0 ? $breakMinutes : null;
        if ($breakMinutesValue === null) {
          $stmt->bindValue(':break_minutes', null, PDO::PARAM_NULL);
        } else {
          $stmt->bindValue(':break_minutes', $breakMinutesValue, PDO::PARAM_INT);
        }
        if ($breakStart !== '') {
          $stmt->bindValue(':break_start', $breakStart, PDO::PARAM_STR);
        } else {
          $stmt->bindValue(':break_start', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':work_days', json_encode($weekdays), PDO::PARAM_STR);
        if ($hoursPerWeek !== null && $hoursPerWeek > 0) {
          $stmt->bindValue(':hours_per_week', $hoursPerWeek, PDO::PARAM_STR);
        } else {
          $stmt->bindValue(':hours_per_week', null, PDO::PARAM_NULL);
        }
        $stmt->execute();
                action_log('work_schedules', 'update_template', 'success', ['template_id' => $templateId]);
                flash_success('Work schedule template updated.');
                break;

            case 'delete_template':
                $templateId = (int)($_POST['template_id'] ?? 0);
                if ($templateId <= 0) {
                    throw new RuntimeException('Template not found.');
                }
                $stmt = $pdo->prepare('DELETE FROM work_schedule_templates WHERE id = :id');
                $stmt->execute([':id' => $templateId]);
                if (work_schedule_get_default_template_id() === $templateId) {
                    work_schedule_set_default_template_id(null, $currentUserId ?: null);
                }
                action_log('work_schedules', 'delete_template', 'success', ['template_id' => $templateId]);
                flash_success('Template deleted. Assignments referencing it will fall back to custom settings.');
                break;

            case 'set_default_template':
                $templateId = (int)($_POST['template_id'] ?? 0);
                if ($templateId > 0) {
                    $exists = work_schedule_fetch_template($pdo, $templateId);
                    if (!$exists) {
                        throw new RuntimeException('Selected template does not exist.');
                    }
                    work_schedule_set_default_template_id($templateId, $currentUserId ?: null);
                } else {
                    work_schedule_set_default_template_id(null, $currentUserId ?: null);
                }
                action_log('work_schedules', 'set_default_template', 'success', ['template_id' => $templateId]);
                flash_success('Default work schedule updated.');
                break;

            case 'assign_template':
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $templateId = (int)($_POST['template_id'] ?? 0);
                $effectiveFrom = $_POST['effective_from'] ?? date('Y-m-d');
                $priority = (int)($_POST['priority'] ?? 10);
                $notes = trim($_POST['notes'] ?? '');

                if ($employeeId <= 0 || $templateId <= 0) {
                    throw new RuntimeException('Employee and template are required.');
                }
                $existsEmployee = $pdo->prepare('SELECT id FROM employees WHERE id = :id');
                $existsEmployee->execute([':id' => $employeeId]);
                if (!$existsEmployee->fetchColumn()) {
                    throw new RuntimeException('Selected employee does not exist.');
                }
                $template = work_schedule_fetch_template($pdo, $templateId);
                if (!$template) {
                    throw new RuntimeException('Selected template does not exist.');
                }
                if (!$effectiveFrom) {
                    $effectiveFrom = date('Y-m-d');
                }
                $pdo->beginTransaction();
                try {
                    $activeStmt = $pdo->prepare('SELECT id FROM employee_work_schedules WHERE employee_id = :eid AND effective_to IS NULL ORDER BY effective_from DESC, priority DESC LIMIT 1');
                    $activeStmt->execute([':eid' => $employeeId]);
                    $currentAssignmentId = (int)($activeStmt->fetchColumn() ?: 0);
                    if ($currentAssignmentId > 0) {
                        $update = $pdo->prepare('UPDATE employee_work_schedules
                                                  SET schedule_template_id = :tpl,
                                                      custom_start_time = NULL,
                                                      custom_end_time = NULL,
                                                      custom_break_minutes = NULL,
                                                      custom_work_days = NULL,
                                                      custom_hours_per_week = NULL,
                                                      effective_from = :eff_from,
                                                      effective_to = NULL,
                                                      priority = :priority,
                                                      notes = :notes,
                                                      assigned_by = :assigned_by,
                                                      updated_at = CURRENT_TIMESTAMP
                                                WHERE id = :id');
                        $update->execute([
                            ':tpl' => $templateId,
                            ':eff_from' => $effectiveFrom,
                            ':priority' => $priority,
                            ':notes' => $notes !== '' ? $notes : null,
                            ':assigned_by' => $currentUserId ?: null,
                            ':id' => $currentAssignmentId,
                        ]);
                        $assignmentId = $currentAssignmentId;
                    } else {
                        $insert = $pdo->prepare('INSERT INTO employee_work_schedules (employee_id, schedule_template_id, effective_from, priority, notes, assigned_by)
                                                  VALUES (:eid, :tpl, :eff_from, :priority, :notes, :assigned_by)
                                                  RETURNING id');
                        $insert->execute([
                            ':eid' => $employeeId,
                            ':tpl' => $templateId,
                            ':eff_from' => $effectiveFrom,
                            ':priority' => $priority,
                            ':notes' => $notes !== '' ? $notes : null,
                            ':assigned_by' => $currentUserId ?: null,
                        ]);
                        $assignmentId = (int)($insert->fetchColumn() ?: 0);
                    }
                    $pdo->commit();
                } catch (Throwable $inner) {
                    $pdo->rollBack();
                    throw $inner;
                }
                action_log('work_schedules', 'assign_template', 'success', ['employee_id' => $employeeId, 'template_id' => $templateId]);
                flash_success('Work schedule assignment saved.');
                break;

            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $e) {
        flash_error('An error occurred. Please try again.');
        action_log('work_schedules', 'action_error', 'error', ['action' => $action, 'message' => $e->getMessage()]);
    }

    header('Location: ' . BASE_URL . '/modules/admin/work-schedules/index');
    exit;
}

$templates = work_schedule_fetch_templates($pdo);
$weekdayMap = work_schedule_weekday_map();
$selectedTemplateId = isset($_GET['template']) ? (int)$_GET['template'] : 0;
$currentTemplate = $selectedTemplateId > 0 ? work_schedule_fetch_template($pdo, $selectedTemplateId) : null;
$defaultTemplateId = work_schedule_get_default_template_id();
$defaultTemplate = $defaultTemplateId ? work_schedule_fetch_template($pdo, $defaultTemplateId) : null;

$employeeStmt = $pdo->query("SELECT id, employee_code, first_name, last_name FROM employees ORDER BY first_name, last_name");
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$assignments = [];
try {
    $assignStmt = $pdo->query("SELECT ews.id, e.employee_code, e.first_name, e.last_name, ews.effective_from, ews.effective_to, ews.priority, tpl.name AS template_name
                                FROM employee_work_schedules ews
                                JOIN employees e ON e.id = ews.employee_id
                                LEFT JOIN work_schedule_templates tpl ON tpl.id = ews.schedule_template_id
                                ORDER BY e.last_name, e.first_name");
    $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $assignments = [];
}

action_log('work_schedules', 'view_admin_module', 'success');

require_once __DIR__ . '/../../../includes/header.php';
?>
<div class="space-y-6">
  <div class="rounded-xl bg-gradient-to-br from-slate-900 via-slate-800 to-blue-900 p-6 text-white shadow-lg">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div>
        <h1 class="text-2xl font-semibold mb-1">Work Schedule Templates</h1>
        <p class="text-sm text-white/70">Define standard shifts and assign them to employees. Employees will see their current schedule in their account profile.</p>
      </div>
      <div class="text-sm text-white/80">
        <span class="uppercase text-[11px] tracking-widest text-white/60 block">Default Template</span>
        <span><?= $defaultTemplate ? htmlspecialchars($defaultTemplate['name']) : 'Not set' ?></span>
      </div>
    </div>
  </div>

  <div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-1 card p-4 space-y-3">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900">Templates</h2>
        <a href="<?= BASE_URL ?>/modules/admin/work-schedules/index" class="text-xs text-blue-600">New</a>
      </div>
      <ul class="divide-y divide-gray-200 text-sm">
        <?php foreach ($templates as $template): ?>
          <li class="py-2 flex items-center justify-between">
            <a class="text-blue-700" href="<?= BASE_URL ?>/modules/admin/work-schedules/index?template=<?= (int)$template['id'] ?>"><?= htmlspecialchars($template['name']) ?></a>
            <?php if ((int)$template['id'] === (int)$defaultTemplateId): ?>
              <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Default</span>
            <?php endif; ?>
          </li>
        <?php endforeach; if (!$templates): ?>
          <li class="py-3 text-gray-500">No templates created yet.</li>
        <?php endif; ?>
      </ul>
    </div>

    <div class="lg:col-span-2 card p-5 space-y-4">
      <h2 class="text-base font-semibold text-gray-900"><?= $currentTemplate ? 'Edit Template' : 'Create Template' ?></h2>
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <?php if ($currentTemplate): ?>
          <input type="hidden" name="template_id" value="<?= (int)$currentTemplate['id'] ?>">
        <?php endif; ?>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label class="form-label">Name</label>
            <input class="input-text" name="name" value="<?= htmlspecialchars($currentTemplate['name'] ?? '') ?>" required>
          </div>
          <div>
            <label class="form-label">Description</label>
            <input class="input-text" name="description" value="<?= htmlspecialchars($currentTemplate['description'] ?? '') ?>">
          </div>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
          <div>
            <label class="form-label">Start Time</label>
            <input type="time" class="input-text" name="start_time" value="<?= htmlspecialchars($currentTemplate['start_time'] ?? '') ?>" required>
          </div>
          <div>
            <label class="form-label">End Time</label>
            <input type="time" class="input-text" name="end_time" value="<?= htmlspecialchars($currentTemplate['end_time'] ?? '') ?>" required>
          </div>
          <div>
            <label class="form-label">Break (minutes)</label>
            <input type="number" min="0" class="input-text" name="break_minutes" value="<?= htmlspecialchars((string)($currentTemplate['break_duration_minutes'] ?? '')) ?>">
          </div>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
          <div>
            <label class="form-label">Break Starts</label>
            <input type="time" class="input-text" name="break_start" value="<?= htmlspecialchars($currentTemplate['break_start_time'] ?? '') ?>">
          </div>
          <div>
            <label class="form-label">Hours / Week</label>
            <input type="number" step="0.25" min="0" class="input-text" name="hours_per_week" value="<?= htmlspecialchars((string)($currentTemplate['hours_per_week'] ?? '')) ?>">
          </div>
          <div>
            <label class="form-label">Active</label>
            <span class="block text-sm text-gray-600"><?= (!isset($currentTemplate['is_active']) || $currentTemplate['is_active']) ? 'Yes' : 'No' ?></span>
          </div>
        </div>
        <div>
          <label class="form-label">Work Days</label>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
            <?php
            $selectedDays = $currentTemplate['work_days'] ?? [];
            foreach ($weekdayMap as $dayNumber => $label):
              $isChecked = in_array($dayNumber, $selectedDays, true);
            ?>
              <label class="inline-flex items-center gap-2 rounded border border-gray-200 px-3 py-2">
                <input type="checkbox" name="work_days[]" value="<?= (int)$dayNumber ?>" <?= $isChecked ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <button class="btn btn-primary" type="submit" name="action" value="<?= $currentTemplate ? 'update_template' : 'create_template' ?>"><?= $currentTemplate ? 'Save Changes' : 'Create Template' ?></button>
          <?php if ($currentTemplate): ?>
            <button class="btn btn-danger" type="submit" name="action" value="delete_template" onclick="return confirm('Delete this template?');">Delete</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card p-5 space-y-4">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-base font-semibold text-gray-900">Default Template</h2>
        <p class="text-sm text-gray-600">The default is used when no employee-specific assignment is active.</p>
      </div>
      <form method="post" class="flex items-center gap-2">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="set_default_template">
        <select name="template_id" class="input-text">
          <option value="0">No default</option>
          <?php foreach ($templates as $template): ?>
            <option value="<?= (int)$template['id'] ?>" <?= (int)$template['id'] === (int)$defaultTemplateId ? 'selected' : '' ?>><?= htmlspecialchars($template['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary" type="submit">Save</button>
      </form>
    </div>
    <?php if ($defaultTemplate): ?>
      <dl class="grid gap-3 md:grid-cols-4 text-sm text-gray-700">
        <div>
          <dt class="font-semibold">Hours</dt>
          <dd><?= htmlspecialchars(work_schedule_assignment_display_hours([
            'template_hours_per_week' => $defaultTemplate['hours_per_week']
          ])) ?></dd>
        </div>
        <div>
          <dt class="font-semibold">Schedule</dt>
          <dd><?= htmlspecialchars(work_schedule_assignment_display_time([
            'template_start_time' => $defaultTemplate['start_time'] ?? null,
            'template_end_time' => $defaultTemplate['end_time'] ?? null,
          ])) ?></dd>
        </div>
        <div>
          <dt class="font-semibold">Break</dt>
          <dd><?= htmlspecialchars(work_schedule_assignment_display_break([
            'template_break_minutes' => $defaultTemplate['break_duration_minutes'] ?? null,
            'template_break_start' => $defaultTemplate['break_start_time'] ?? null,
          ])) ?></dd>
        </div>
        <div>
          <dt class="font-semibold">Work Days</dt>
          <dd><?= htmlspecialchars(work_schedule_format_days($defaultTemplate['work_days'] ?? [])) ?></dd>
        </div>
      </dl>
    <?php endif; ?>
  </div>

  <div class="card p-5 space-y-5">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-base font-semibold text-gray-900">Assign Template to Employee</h2>
        <p class="text-sm text-gray-600">Assignments update instantly in the employee account profile.</p>
      </div>
    </div>
    <form method="post" class="grid gap-4 md:grid-cols-4">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="assign_template">
      <div class="md:col-span-2">
        <label class="form-label">Employee</label>
        <select name="employee_id" class="input-text" required>
          <option value="">Select employee</option>
          <?php foreach ($employees as $employee): ?>
            <option value="<?= (int)$employee['id'] ?>"><?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_code'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Template</label>
        <select name="template_id" class="input-text" required>
          <option value="">Select template</option>
          <?php foreach ($templates as $template): ?>
            <option value="<?= (int)$template['id'] ?>"><?= htmlspecialchars($template['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Effective From</label>
        <input type="date" name="effective_from" class="input-text" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div>
        <label class="form-label">Priority</label>
        <input type="number" name="priority" class="input-text" value="10" min="1" max="999">
      </div>
      <div class="md:col-span-3">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="input-text" placeholder="Optional remarks">
      </div>
      <div>
        <label class="form-label invisible">Save</label>
        <button class="btn btn-primary w-full" type="submit">Assign Template</button>
      </div>
    </form>

    <div class="overflow-x-auto">
      <table class="table-basic min-w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="p-2 text-left">Employee</th>
            <th class="p-2 text-left">Template</th>
            <th class="p-2 text-left">Effective</th>
            <th class="p-2 text-left">Priority</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($assignments as $row): ?>
            <tr class="border-t">
              <td class="p-2">
                <div class="font-medium text-gray-900"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></div>
                <div class="text-xs text-gray-500"><?= htmlspecialchars($row['employee_code']) ?></div>
              </td>
              <td class="p-2"><?= htmlspecialchars($row['template_name'] ?? '—') ?></td>
              <td class="p-2">
                <div><?= htmlspecialchars($row['effective_from']) ?></div>
                <?php if (!empty($row['effective_to'])): ?>
                  <div class="text-xs text-gray-500">until <?= htmlspecialchars($row['effective_to']) ?></div>
                <?php else: ?>
                  <div class="text-xs text-emerald-600">Active</div>
                <?php endif; ?>
              </td>
              <td class="p-2 text-center"><?= (int)$row['priority'] ?></td>
            </tr>
          <?php endforeach; if (!$assignments): ?>
            <tr><td class="p-3 text-gray-500" colspan="4">No assignments yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
