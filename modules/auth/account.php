<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/work_schedules.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

$employee = null;
if ($uid > 0) {
    try {
        $stmt = $pdo->prepare('SELECT e.id, e.employee_code, e.first_name, e.last_name, e.email AS employee_email,
                                       e.phone, e.address, e.department_id, e.position_id, e.branch_id, e.hire_date,
                                       e.employment_type, e.status, e.profile_photo_path, e.profile_photo_updated_at,
                                       d.name AS department_name, p.name AS position_name, b.name AS branch_name, b.code AS branch_code
                                FROM employees e
                                LEFT JOIN departments d ON d.id = e.department_id
                                LEFT JOIN positions p ON p.id = e.position_id
                                LEFT JOIN branches b ON b.id = e.branch_id
                                WHERE e.user_id = :uid
                                LIMIT 1');
        $stmt->execute([':uid' => $uid]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        sys_log('ACCOUNT-EMP', 'Failed to load employee profile: ' . $e->getMessage(), ['module' => 'account', 'file' => __FILE__, 'line' => __LINE__, 'user_id' => $uid]);
        $employee = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!csrf_verify($token)) {
        flash_error('Your session expired. Please try again.');
        header('Location: ' . BASE_URL . '/modules/auth/account');
        exit;
    }

    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'profile':
                $name = trim($_POST['name'] ?? '');
                if ($name === '') {
                    throw new RuntimeException('Name is required.');
                }
                $stmt = $pdo->prepare('UPDATE users SET full_name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute([':name' => $name, ':id' => $uid]);
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['full_name'] = $name;
                action_log('account', 'profile_update', 'success', ['user_id' => $uid]);
                flash_success('Profile updated.');
                break;

            case 'password':
                $current = (string)($_POST['current_password'] ?? '');
                $new = (string)($_POST['new_password'] ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');
                if (strlen($new) < 8) {
                    throw new RuntimeException('New password must be at least 8 characters long.');
                }
                if ($new !== $confirm) {
                    throw new RuntimeException('Password confirmation does not match.');
                }
                $lookup = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
                $lookup->execute([':id' => $uid]);
                $row = $lookup->fetch(PDO::FETCH_ASSOC);
                if (!$row || !password_verify($current, (string)$row['password_hash'])) {
                    throw new RuntimeException('Current password is incorrect.');
                }
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $update = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $update->execute([':hash' => $hash, ':id' => $uid]);
                // Revoke all remember-me tokens to invalidate other sessions
                remember_clear_tokens($uid);
                action_log('account', 'password_change', 'success', ['user_id' => $uid]);
                flash_success('Password changed successfully.');
                break;

            case 'photo':
                if (!$employee) {
                    throw new RuntimeException('Your account is not linked to an employee profile.');
                }
                if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Please select a 2x2 photo to upload.');
                }
                $file = $_FILES['photo'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Upload failed. Please try again.');
                }
                if ($file['size'] > 2 * 1024 * 1024) {
                    throw new RuntimeException('Photo must be 2 MB or smaller.');
                }
                $info = @getimagesize($file['tmp_name']);
                if (!$info) {
                    throw new RuntimeException('Uploaded file is not a valid image.');
                }
                [$width, $height, $type] = $info;
                if ($width <= 0 || $height <= 0) {
                    throw new RuntimeException('Image dimensions are invalid.');
                }
                $ratio = $height > 0 ? $width / $height : 0;
                if ($ratio <= 0 || abs($ratio - 1) > 0.1) {
                    throw new RuntimeException('Photo must be square (2x2).');
                }
                $allowedTypes = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
                if (!isset($allowedTypes[$type])) {
                    throw new RuntimeException('Only JPEG and PNG images are allowed.');
                }
                $ext = $allowedTypes[$type];
                $uploadDir = __DIR__ . '/../../assets/uploads/profile_photos';
                if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
                    throw new RuntimeException('Unable to prepare upload folder.');
                }
                $filename = 'emp_' . (int)$employee['id'] . '_' . time() . '.' . $ext;
                $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    throw new RuntimeException('Failed to store the uploaded file.');
                }
                $relativePath = 'assets/uploads/profile_photos/' . $filename;
                if (!empty($employee['profile_photo_path'])) {
                    $oldPath = __DIR__ . '/../../' . ltrim($employee['profile_photo_path'], '/\\');
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }
                $upd = $pdo->prepare('UPDATE employees SET profile_photo_path = :path, profile_photo_updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $upd->execute([':path' => $relativePath, ':id' => (int)$employee['id']]);
                action_log('account', 'profile_photo_update', 'success', ['employee_id' => (int)$employee['id']]);
                flash_success('Profile photo updated.');
                break;

            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (RuntimeException $re) {
        flash_error($re->getMessage());
        action_log('account', 'account_action_error', 'error', ['action' => $action, 'message' => $re->getMessage()]);
    } catch (Throwable $e) {
        sys_log('ACCOUNT-ACT', 'Account action failed: ' . $e->getMessage(), ['module' => 'account', 'file' => __FILE__, 'line' => __LINE__, 'action' => $action, 'user_id' => $uid]);
        flash_error('We could not complete your request. Please try again.');
        action_log('account', 'account_action_error', 'error', ['action' => $action, 'message' => 'unexpected']);
    }

    header('Location: ' . BASE_URL . '/modules/auth/account');
    exit;
}

$displayName = trim((string)($user['name'] ?? $user['full_name'] ?? '')); 
if ($displayName === '' && $employee) {
    $displayName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
}

$initials = '';
foreach (array_filter(explode(' ', $displayName)) as $part) {
  $firstChar = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
  if ($firstChar === false || $firstChar === null) {
    continue;
  }
  $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($firstChar, 'UTF-8') : strtoupper($firstChar);
  if (strlen($initials) >= 2) {
    break;
  }
}
$initials = $initials ?: 'HR';

$profilePhotoPath = $employee['profile_photo_path'] ?? null;
$profilePhotoUrl = $profilePhotoPath ? BASE_URL . '/' . ltrim($profilePhotoPath, '/') : null;
$photoUpdatedLabel = !empty($employee['profile_photo_updated_at'])
    ? format_datetime_display($employee['profile_photo_updated_at'], true, '')
    : null;

$hireDateLabel = '—';
if (!empty($employee['hire_date'])) {
    $ts = strtotime($employee['hire_date']);
    $hireDateLabel = $ts ? date('F j, Y', $ts) : $employee['hire_date'];
}

$employmentTypeLabel = $employee['employment_type'] ?? null;
if ($employmentTypeLabel) {
    $employmentTypeLabel = ucwords(str_replace('_', ' ', $employmentTypeLabel));
}

$statusLabel = $employee ? ucwords(str_replace('-', ' ', (string)$employee['status'])) : '—';

$branchLabel = null;
if ($employee) {
    $branchLabel = trim(($employee['branch_name'] ?? '') . ' ' . ($employee['branch_code'] ? '(' . $employee['branch_code'] . ')' : ''));
    if ($branchLabel === '') {
        $branchLabel = '—';
    }
}

$activeAssignment = $employee ? work_schedule_get_active_assignment($pdo, (int)$employee['id']) : null;
$defaultTemplate = work_schedule_fetch_default_template($pdo);

if ($activeAssignment) {
    $scheduleSourceLabel = $activeAssignment['template_name'] ? ($activeAssignment['template_name'] . ' (assigned)') : 'Custom Schedule';
    $scheduleTimeLabel = work_schedule_assignment_display_time($activeAssignment);
    $scheduleDaysLabel = work_schedule_assignment_display_days($activeAssignment);
    $scheduleBreakLabel = work_schedule_assignment_display_break($activeAssignment);
    $scheduleHoursLabel = work_schedule_assignment_display_hours($activeAssignment);
    $scheduleEffectiveLabel = work_schedule_assignment_effective_range($activeAssignment) ?: 'Active now';
} elseif ($defaultTemplate) {
    $rawWorkDays = $defaultTemplate['work_days'] ?? [];
    $normalizedWorkDays = [];
    if (is_array($rawWorkDays)) {
        $normalizedWorkDays = $rawWorkDays;
    } elseif (is_string($rawWorkDays) && $rawWorkDays !== '') {
        $decodedDays = json_decode($rawWorkDays, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedDays)) {
            $normalizedWorkDays = $decodedDays;
        } else {
            $normalizedWorkDays = array_filter(array_map('trim', explode(',', $rawWorkDays)));
        }
    }
    $normalizedWorkDays = array_values(array_filter($normalizedWorkDays, static fn($day) => $day !== '' && $day !== null));

    $proxy = [
        'template_start_time' => $defaultTemplate['start_time'] ?? null,
        'template_end_time' => $defaultTemplate['end_time'] ?? null,
        'template_break_minutes' => $defaultTemplate['break_duration_minutes'] ?? null,
        'template_break_start' => $defaultTemplate['break_start_time'] ?? null,
        'template_work_days' => $normalizedWorkDays,
        'template_hours_per_week' => $defaultTemplate['hours_per_week'] ?? null,
    ];
    $scheduleSourceLabel = ($defaultTemplate['name'] ?? 'Default Schedule') . ' (default)';
    $scheduleTimeLabel = work_schedule_assignment_display_time($proxy);
    $scheduleDaysLabel = work_schedule_format_days($normalizedWorkDays);
    $scheduleBreakLabel = work_schedule_assignment_display_break($proxy);
    $scheduleHoursLabel = work_schedule_assignment_display_hours($proxy);
    $scheduleEffectiveLabel = 'Applies when no personal schedule is assigned.';
} else {
    $scheduleSourceLabel = 'No schedule configured';
    $scheduleTimeLabel = 'Not set';
    $scheduleDaysLabel = 'Not set';
    $scheduleBreakLabel = 'No scheduled break';
    $scheduleHoursLabel = 'Not set';
    $scheduleEffectiveLabel = 'Contact HR to assign a schedule.';
}

$pageTitle = 'Account Manager';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="space-y-6">
  <div class="card p-6">
    <div class="flex flex-col gap-6 md:flex-row">
      <div class="flex flex-col items-center gap-4 md:w-56">
        <div class="relative h-48 w-48 overflow-hidden rounded-2xl border border-gray-200 bg-slate-100">
          <?php if ($profilePhotoUrl): ?>
            <img src="<?= htmlspecialchars($profilePhotoUrl) ?>" alt="Profile photo" class="h-full w-full object-cover">
          <?php else: ?>
            <div class="flex h-full w-full items-center justify-center text-4xl font-semibold text-slate-500"><?= htmlspecialchars($initials) ?></div>
          <?php endif; ?>
        </div>
        <div class="text-xs text-gray-500 text-center">
          <?= $photoUpdatedLabel ? 'Updated ' . htmlspecialchars($photoUpdatedLabel) : 'No 2x2 photo uploaded yet.' ?>
        </div>
        <?php if ($employee): ?>
        <form method="post" enctype="multipart/form-data" class="w-full space-y-2">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="photo">
          <input type="file" name="photo" accept="image/jpeg,image/png" class="w-full text-sm" required>
          <button class="btn btn-secondary w-full" type="submit">Upload 2x2 Photo</button>
          <p class="text-[11px] text-gray-500">JPEG or PNG • max 2 MB • use a square, front-facing headshot.</p>
        </form>
        <?php else: ?>
        <div class="text-xs text-red-500 text-center">Link your account to an employee record to upload a profile photo.</div>
        <?php endif; ?>
      </div>
      <div class="flex-1 grid gap-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
          <div class="mb-4 flex items-center justify-between">
            <div>
              <h2 class="text-base font-semibold text-gray-900">Profile Overview</h2>
              <p class="text-sm text-gray-600">Review the information HR has on file.</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600"><?= htmlspecialchars(ucwords((string)($user['role'] ?? ''))) ?></span>
          </div>
          <?php if ($employee): ?>
          <dl class="grid gap-3 text-sm text-gray-700 sm:grid-cols-2">
            <div>
              <dt class="font-semibold text-gray-900">Employee Code</dt>
              <dd><?= htmlspecialchars($employee['employee_code'] ?? '—') ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Status</dt>
              <dd><?= htmlspecialchars($statusLabel) ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Department</dt>
              <dd><?= htmlspecialchars($employee['department_name'] ?? '—') ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Position</dt>
              <dd><?= htmlspecialchars($employee['position_name'] ?? '—') ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Branch</dt>
              <dd><?= htmlspecialchars($branchLabel ?? '—') ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Employment Type</dt>
              <dd><?= htmlspecialchars($employmentTypeLabel ?? '—') ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Hire Date</dt>
              <dd><?= htmlspecialchars($hireDateLabel) ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Work Email</dt>
              <dd><?= htmlspecialchars($employee['employee_email'] ?? $user['email'] ?? '—') ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Mobile</dt>
              <dd><?= htmlspecialchars($employee['phone'] ?? '—') ?></dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="font-semibold text-gray-900">Address</dt>
              <dd><?= nl2br(htmlspecialchars($employee['address'] ?? '—')) ?></dd>
            </div>
          </dl>
          <?php else: ?>
          <p class="text-sm text-gray-600">Your account is not yet linked to an employee record. Please coordinate with HR to complete your onboarding profile.</p>
          <?php endif; ?>
        </div>
        <div class="rounded-2xl border border-indigo-100 bg-white p-5 shadow-sm">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900">Work Schedule</h2>
            <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-600"><?= htmlspecialchars($scheduleSourceLabel) ?></span>
          </div>
          <dl class="grid gap-3 text-sm text-gray-700 sm:grid-cols-2">
            <div>
              <dt class="font-semibold text-gray-900">Schedule Window</dt>
              <dd><?= htmlspecialchars($scheduleTimeLabel) ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Work Days</dt>
              <dd><?= htmlspecialchars($scheduleDaysLabel) ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Break</dt>
              <dd><?= htmlspecialchars($scheduleBreakLabel) ?></dd>
            </div>
            <div>
              <dt class="font-semibold text-gray-900">Hours / Week</dt>
              <dd><?= htmlspecialchars($scheduleHoursLabel) ?></dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="font-semibold text-gray-900">Effective</dt>
              <dd><?= htmlspecialchars($scheduleEffectiveLabel) ?></dd>
            </div>
          </dl>
        </div>
      </div>
    </div>
  </div>

  <div class="grid gap-6 md:grid-cols-2">
    <form method="post" class="card space-y-4 p-5">
      <h2 class="text-lg font-semibold text-gray-900">Update Profile</h2>
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="profile">
      <div>
        <label class="form-label">Full Name</label>
        <input name="name" class="input-text" value="<?= htmlspecialchars($displayName) ?>" required>
      </div>
      <div>
        <label class="form-label">Email</label>
        <input class="input-text bg-gray-50" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled>
      </div>
      <div>
        <label class="form-label">Role</label>
        <input class="input-text bg-gray-50" value="<?= htmlspecialchars(ucwords((string)($user['role'] ?? ''))) ?>" disabled>
      </div>
      <button class="btn btn-primary" type="submit">Save Profile</button>
    </form>

    <form method="post" class="card space-y-4 p-5">
      <h2 class="text-lg font-semibold text-gray-900">Change Password</h2>
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="password">
      <div>
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" class="input-text" required>
      </div>
      <div>
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="input-text" minlength="8" required>
      </div>
      <div>
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="input-text" minlength="8" required>
      </div>
      <button class="btn btn-secondary" type="submit">Change Password</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
