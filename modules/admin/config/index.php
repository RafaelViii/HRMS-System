<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('payroll', 'payroll_config', 'manage');
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';
require_once __DIR__ . '/../../../includes/payroll.php';

$pdo = get_db_conn();
$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$canManageRates = true; // Now controlled by require_access above

$categoryLabels = payroll_rate_category_labels();
$redirectUrl = BASE_URL . '/modules/admin/config/index';

if (!function_exists('config_format_rate_value')) {
    function config_format_rate_value($value): string {
        if ($value === null || $value === '') {
      return '--';
        }
        $num = (float)$value;
        return number_format($num, 4);
    }
}

if (!function_exists('config_format_utc_offset')) {
  function config_format_utc_offset(int $seconds): string {
    $sign = $seconds >= 0 ? '+' : '-';
    $seconds = abs($seconds);
    $hours = (int)floor($seconds / 3600);
    $minutes = (int)floor(($seconds % 3600) / 60);
    return sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);
  }
}

if (!function_exists('config_describe_time_offset')) {
  function config_describe_time_offset(int $seconds): string {
    if ($seconds === 0) {
      return 'No manual offset is applied';
    }
    $ahead = $seconds > 0;
    $seconds = abs($seconds);
    $hours = (int)floor($seconds / 3600);
    $minutes = (int)floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $parts = [];
    if ($hours > 0) {
      $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
    if ($minutes > 0) {
      $parts[] = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    if ($secs > 0 && !$hours && !$minutes) {
      $parts[] = $secs . ' second' . ($secs === 1 ? '' : 's');
    }
    if (!$parts) {
      $parts[] = '0 minutes';
    }
    $phrase = implode(' ', $parts);
    return ($ahead ? 'Clock is ahead by ' : 'Clock is behind by ') . $phrase;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postAction = $_POST['action'] ?? '';
  switch ($postAction) {
    case 'timezone_update':
      if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Session expired. Please try again.');
        header('Location: ' . $redirectUrl); exit;
      }
      $tzName = trim((string)($_POST['timezone'] ?? ''));
      try {
        $tz = new DateTimeZone($tzName);
      } catch (Throwable $e) {
        flash_error('Select a valid timezone.');
        header('Location: ' . $redirectUrl); exit;
      }
      $authTz = ensure_action_authorized('settings', 'timezone_update', 'admin');
      if (!$authTz['ok']) {
        flash_error('Authorization was not granted.');
        header('Location: ' . $redirectUrl); exit;
      }
      app_settings_set('system.timezone', $tz->getName(), (int)$authTz['as_user']);
      app_timezone(true);
      action_log('settings', 'timezone_update', 'success', ['timezone' => $tz->getName()]);
      flash_success('Timezone updated.');
      header('Location: ' . $redirectUrl); exit;

    case 'time_override_apply':
      if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Session expired. Please try again.');
        header('Location: ' . $redirectUrl); exit;
      }
      $manualRaw = trim((string)($_POST['manual_datetime'] ?? ''));
      if ($manualRaw === '') {
        flash_error('Enter the date and time to apply.');
        header('Location: ' . $redirectUrl); exit;
      }
      $tz = app_timezone();
      $manualDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $manualRaw, $tz);
      if (!$manualDt) {
        flash_error('Manual time value is invalid.');
        header('Location: ' . $redirectUrl); exit;
      }
      $authOverride = ensure_action_authorized('settings', 'time_override_apply', 'admin');
      if (!$authOverride['ok']) {
        flash_error('Authorization was not granted.');
        header('Location: ' . $redirectUrl); exit;
      }
      $now = new DateTimeImmutable('now', $tz);
      $offsetSeconds = (int)($manualDt->getTimestamp() - $now->getTimestamp());
      if (abs($offsetSeconds) > 604800) { // 7 days
        flash_error('Manual override cannot shift more than 7 days.');
        header('Location: ' . $redirectUrl); exit;
      }
      app_settings_set('system.time_offset', (string)$offsetSeconds, (int)$authOverride['as_user']);
      app_time_offset_seconds(true);
      action_log('settings', 'time_override_apply', 'success', ['offset_seconds' => $offsetSeconds]);
      flash_success('Manual system time override applied.');
      header('Location: ' . $redirectUrl); exit;

    case 'time_override_reset':
      if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Session expired. Please try again.');
        header('Location: ' . $redirectUrl); exit;
      }
      $authReset = ensure_action_authorized('settings', 'time_override_reset', 'admin');
      if (!$authReset['ok']) {
        flash_error('Authorization was not granted.');
        header('Location: ' . $redirectUrl); exit;
      }
      app_settings_set('system.time_offset', '0', (int)$authReset['as_user']);
      app_time_offset_seconds(true);
      action_log('settings', 'time_override_reset', 'success', []);
      flash_success('Manual time override cleared.');
      header('Location: ' . $redirectUrl); exit;

    case 'rate_save':
      if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Session expired. Please try again.');
        header('Location: ' . $redirectUrl); exit;
      }

      $authz = ensure_action_authorized('payroll', 'rate_config_update', 'admin');
      if (!$authz['ok']) {
        flash_error('Authorization was not granted.');
        header('Location: ' . $redirectUrl); exit;
      }

      $isNew = ($_POST['is_new'] ?? '0') === '1';
      $codeRaw = trim((string)($_POST['code'] ?? ''));
      $code = strtolower($codeRaw);
      $label = trim((string)($_POST['label'] ?? ''));
      $category = strtolower(trim((string)($_POST['category'] ?? '')));
      $defaultRaw = trim((string)($_POST['default_value'] ?? ''));
      $overrideRaw = trim((string)($_POST['override_value'] ?? ''));
      $effectiveStartRaw = trim((string)($_POST['effective_start'] ?? ''));
      $effectiveEndRaw = trim((string)($_POST['effective_end'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));

      $errors = [];
      if ($code === '' || !preg_match('/^[a-z0-9_\-]+$/', $code)) {
        $errors[] = 'Rate code must use letters, numbers, dashes, or underscores only.';
      }
      if ($label === '') {
        $errors[] = 'Rate label is required.';
      } elseif (mb_strlen($label) > 191) {
        $errors[] = 'Rate label must be 191 characters or fewer.';
      }
      if (!isset($categoryLabels[$category])) {
        $errors[] = 'Invalid rate category selected.';
      }
      if ($defaultRaw === '' || !is_numeric($defaultRaw)) {
        $errors[] = 'Default value must be a valid number.';
      }
      $defaultValue = (float)$defaultRaw;
      if ($defaultValue < 0) {
        $errors[] = 'Default value cannot be negative.';
      }
      $overrideValue = null;
      if ($overrideRaw !== '') {
        if (!is_numeric($overrideRaw)) {
          $errors[] = 'Override value must be a valid number when provided.';
        } else {
          $overrideValue = (float)$overrideRaw;
          if ($overrideValue < 0) {
            $errors[] = 'Override value cannot be negative.';
          }
        }
      }
      $effectiveStart = null;
      if ($effectiveStartRaw === '') {
        $errors[] = 'Effective start date is required.';
      } else {
        try {
          $dtStart = new DateTime($effectiveStartRaw);
          $effectiveStart = $dtStart->format('Y-m-d');
        } catch (Throwable $e) {
          $errors[] = 'Effective start date is invalid.';
        }
      }
      $effectiveEnd = null;
      if ($effectiveEndRaw !== '') {
        try {
          $dtEnd = new DateTime($effectiveEndRaw);
          $effectiveEnd = $dtEnd->format('Y-m-d');
        } catch (Throwable $e) {
          $errors[] = 'Effective end date is invalid.';
        }
      }
      if ($effectiveStart && $effectiveEnd && $effectiveEnd < $effectiveStart) {
        $errors[] = 'Effective end date must be after the start date.';
      }
      if (mb_strlen($notes) > 500) {
        $errors[] = 'Notes must be 500 characters or fewer.';
      }

      $logMeta = [
        'code' => $code,
        'category' => $category,
        'is_new' => $isNew,
        'default_value' => $defaultValue,
        'override_value' => $overrideValue,
        'effective_start' => $effectiveStart,
        'effective_end' => $effectiveEnd,
      ];

      if ($errors) {
        action_log('payroll', 'rate_config_update', 'error', $logMeta + ['reason' => 'validation', 'messages' => $errors]);
        flash_error($errors[0] ?? 'Changes could not be saved');
        header('Location: ' . $redirectUrl); exit;
      }

      $meta = ['source' => 'manual_ui'];
      if ($notes !== '') {
        $meta['notes'] = $notes;
      }
      $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

      try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM payroll_rate_configs WHERE code = :code ORDER BY effective_start DESC, id DESC LIMIT 1');
        $stmt->execute([':code' => $code]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $mode = 'create';

        if ($current) {
          $currentStart = $current['effective_start'] ?? null;
          if ($effectiveStart < $currentStart) {
            $pdo->rollBack();
            action_log('payroll', 'rate_config_update', 'error', $logMeta + ['reason' => 'chronology']);
            flash_error('Effective start cannot be earlier than the latest active configuration (' . $currentStart . ').');
            header('Location: ' . $redirectUrl); exit;
          }
          if ($effectiveStart === $currentStart) {
            $mode = 'update';
            $upd = $pdo->prepare('UPDATE payroll_rate_configs
              SET category = :category,
                label = :label,
                default_value = :default_value,
                override_value = :override_value,
                effective_end = :effective_end,
                meta = :meta::jsonb,
                updated_by = :updated_by,
                updated_at = NOW()
              WHERE id = :id');
            $upd->bindValue(':category', $category, PDO::PARAM_STR);
            $upd->bindValue(':label', $label, PDO::PARAM_STR);
            $upd->bindValue(':default_value', $defaultValue, PDO::PARAM_STR);
            if ($overrideValue === null) {
              $upd->bindValue(':override_value', null, PDO::PARAM_NULL);
            } else {
              $upd->bindValue(':override_value', $overrideValue, PDO::PARAM_STR);
            }
            if ($effectiveEnd === null) {
              $upd->bindValue(':effective_end', null, PDO::PARAM_NULL);
            } else {
              $upd->bindValue(':effective_end', $effectiveEnd, PDO::PARAM_STR);
            }
            $upd->bindValue(':meta', $metaJson, PDO::PARAM_STR);
            $upd->bindValue(':updated_by', (int)$authz['as_user'], PDO::PARAM_INT);
            $upd->bindValue(':id', (int)$current['id'], PDO::PARAM_INT);
            $upd->execute();
          } else {
            $mode = 'rollover';
            $prevEndDate = (new DateTime($effectiveStart))->modify('-1 day')->format('Y-m-d');
            $close = $pdo->prepare('UPDATE payroll_rate_configs
              SET effective_end = :prev_end,
                updated_by = :updated_by,
                updated_at = NOW()
              WHERE code = :code
                AND (effective_end IS NULL OR effective_end >= :effective_start)
                AND effective_start < :effective_start');
            $close->execute([
              ':prev_end' => $prevEndDate,
              ':updated_by' => (int)$authz['as_user'],
              ':code' => $code,
              ':effective_start' => $effectiveStart,
            ]);

            $ins = $pdo->prepare('INSERT INTO payroll_rate_configs
              (category, code, label, default_value, override_value, effective_start, effective_end, meta, updated_by)
              VALUES (:category, :code, :label, :default_value, :override_value, :effective_start, :effective_end, :meta::jsonb, :updated_by)
              RETURNING id');
            $ins->bindValue(':category', $category, PDO::PARAM_STR);
            $ins->bindValue(':code', $code, PDO::PARAM_STR);
            $ins->bindValue(':label', $label, PDO::PARAM_STR);
            $ins->bindValue(':default_value', $defaultValue, PDO::PARAM_STR);
            if ($overrideValue === null) {
              $ins->bindValue(':override_value', null, PDO::PARAM_NULL);
            } else {
              $ins->bindValue(':override_value', $overrideValue, PDO::PARAM_STR);
            }
            $ins->bindValue(':effective_start', $effectiveStart, PDO::PARAM_STR);
            if ($effectiveEnd === null) {
              $ins->bindValue(':effective_end', null, PDO::PARAM_NULL);
            } else {
              $ins->bindValue(':effective_end', $effectiveEnd, PDO::PARAM_STR);
            }
            $ins->bindValue(':meta', $metaJson, PDO::PARAM_STR);
            $ins->bindValue(':updated_by', (int)$authz['as_user'], PDO::PARAM_INT);
            $ins->execute();
            $ins->fetchColumn();
          }
        } else {
          $mode = 'create';
          $ins = $pdo->prepare('INSERT INTO payroll_rate_configs
            (category, code, label, default_value, override_value, effective_start, effective_end, meta, updated_by)
            VALUES (:category, :code, :label, :default_value, :override_value, :effective_start, :effective_end, :meta::jsonb, :updated_by)
            RETURNING id');
          $ins->bindValue(':category', $category, PDO::PARAM_STR);
          $ins->bindValue(':code', $code, PDO::PARAM_STR);
          $ins->bindValue(':label', $label, PDO::PARAM_STR);
          $ins->bindValue(':default_value', $defaultValue, PDO::PARAM_STR);
          if ($overrideValue === null) {
            $ins->bindValue(':override_value', null, PDO::PARAM_NULL);
          } else {
            $ins->bindValue(':override_value', $overrideValue, PDO::PARAM_STR);
          }
          $ins->bindValue(':effective_start', $effectiveStart, PDO::PARAM_STR);
          if ($effectiveEnd === null) {
            $ins->bindValue(':effective_end', null, PDO::PARAM_NULL);
          } else {
            $ins->bindValue(':effective_end', $effectiveEnd, PDO::PARAM_STR);
          }
          $ins->bindValue(':meta', $metaJson, PDO::PARAM_STR);
          $ins->bindValue(':updated_by', (int)$authz['as_user'], PDO::PARAM_INT);
          $ins->execute();
          $ins->fetchColumn();
        }

        $pdo->commit();
        action_log('payroll', 'rate_config_update', 'success', $logMeta + ['mode' => $mode]);
        flash_success('Rate configuration saved');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        sys_log('PAYROLL-RATE-SAVE', 'Failed saving rate configuration: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => $logMeta]);
        action_log('payroll', 'rate_config_update', 'error', $logMeta + ['reason' => 'exception']);
        flash_error('Changes could not be saved');
      }

      header('Location: ' . $redirectUrl); exit;

    default:
      break;
  }
}

try {
    $rateStmt = $pdo->query('SELECT id, category, code, label, default_value, override_value, effective_start, effective_end, meta, updated_by, updated_at FROM payroll_rate_configs ORDER BY code, effective_start DESC, id DESC');
    $allRates = $rateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    sys_log('PAYROLL-RATE-LIST', 'Failed listing rate configs: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
    $allRates = [];
}

$currentRates = [];
$rateHistory = [];
foreach ($allRates as $row) {
    $codeKey = strtolower((string)($row['code'] ?? ''));
    if (!$codeKey) {
        continue;
    }
    if (!isset($currentRates[$codeKey])) {
        $currentRates[$codeKey] = $row;
    }
    $rateHistory[$codeKey][] = $row;
}

$ratesByCategory = [];
foreach ($currentRates as $row) {
    $cat = strtolower((string)($row['category'] ?? 'custom_rate'));
    $ratesByCategory[$cat][] = $row;
}
foreach ($ratesByCategory as &$items) {
    usort($items, function ($a, $b) {
        return strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });
}
unset($items);

$userMap = [];
$userIds = [];
foreach ($allRates as $row) {
    $uid = (int)($row['updated_by'] ?? 0);
    if ($uid > 0) {
        $userIds[$uid] = $uid;
    }
}
if ($userIds) {
    $placeholders = [];
    $params = [];
    $i = 0;
    foreach ($userIds as $uid) {
        $ph = ':u' . $i++;
        $placeholders[] = $ph;
        $params[$ph] = $uid;
    }
    $sql = 'SELECT id, full_name FROM users WHERE id IN (' . implode(',', $placeholders) . ')';
    try {
        $uStmt = $pdo->prepare($sql);
        $uStmt->execute($params);
        while ($row = $uStmt->fetch(PDO::FETCH_ASSOC)) {
            $userMap[(int)$row['id']] = $row['full_name'] ?? ('User #' . (int)$row['id']);
        }
    } catch (Throwable $e) {
        sys_log('PAYROLL-RATE-USERS', 'Failed loading user names: ' . $e->getMessage(), ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__]);
    }
}

try {
    $logStmt = $pdo->query('SELECT id, code, message, module, created_at FROM system_logs ORDER BY id DESC LIMIT 8');
    $recentLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    sys_log('SYSLOG-LIST', 'Failed loading system log snapshot: ' . $e->getMessage(), ['module' => 'admin', 'file' => __FILE__, 'line' => __LINE__]);
    $recentLogs = [];
}

$recentRateEvents = $allRates;
usort($recentRateEvents, function ($a, $b) {
    return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
});
$recentRateEvents = array_slice($recentRateEvents, 0, 6);

$appTimeNow = format_datetime_display('now', true, '');
$appTz = app_timezone();
$appTimeRef = new DateTimeImmutable('now', $appTz);
$appTzAbbr = $appTimeRef->format('T');
$appTzOffset = $appTimeRef->format('P');
$appTzName = $appTz->getName();
$appTimeSample = format_datetime_display('2025-10-21 15:30:00', false, '');
$appTimeSampleSeconds = format_datetime_display('2025-10-21 15:30:00', true, '');
$appOffsetSeconds = app_time_offset_seconds();
$manualOverrideActive = $appOffsetSeconds !== 0;
$effectiveNow = $manualOverrideActive
  ? $appTimeRef->modify(($appOffsetSeconds >= 0 ? '+' : '-') . abs($appOffsetSeconds) . ' seconds')
  : $appTimeRef;
$manualOffsetSummary = config_describe_time_offset($appOffsetSeconds);
$effectiveNowDisplay = format_datetime_display($effectiveNow, true, '');
$effectiveNowInputValue = $effectiveNow->format('Y-m-d\TH:i');
$serverTimeDisplay = $appTimeRef->format('M d, Y g:i:s A');

$timezoneOptions = [];
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $identifier) {
  if (strpos($identifier, '/') === false) {
    continue;
  }
  [$region, $city] = array_pad(explode('/', $identifier, 2), 2, '');
  $tzObj = new DateTimeZone($identifier);
  $offset = $tzObj->getOffset($nowUtc);
  $timezoneOptions[$region][] = [
    'name' => $identifier,
    'label' => str_replace(['_', '-'], [' ', ' '], $city ?: $region),
    'offset' => $offset,
    'display_offset' => config_format_utc_offset($offset),
  ];
}
ksort($timezoneOptions);
foreach ($timezoneOptions as &$group) {
  usort($group, function (array $a, array $b) {
    if ($a['offset'] === $b['offset']) {
      return strcmp($a['label'], $b['label']);
    }
    return $a['offset'] <=> $b['offset'];
  });
}
unset($group);

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
?>
<div class="mb-3">
  <a class="btn btn-outline inline-flex items-center gap-2" href="<?= BASE_URL ?>/modules/admin/management">
    <span>&larr;</span>
    <span>Back to Management Hub</span>
  </a>
</div>
<div class="flex flex-col gap-3 mb-4 md:flex-row md:items-center md:justify-between">
  <div>
    <h1 class="text-xl font-semibold">System Configuration</h1>
    <p class="text-sm text-gray-600">Manage the application clock, timezone, and payroll rate configurations.</p>
  </div>
  <div class="flex items-center gap-2">
    <?php if ($canManageRates): ?>
    <button type="button" class="btn btn-primary" id="btnAddRate">Add Rate</button>
    <?php endif; ?>
    <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/admin/system_log">Open System Log</a>
  </div>
</div>
<div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="space-y-4">
    <div class="card p-4 space-y-4">
      <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
        <div>
          <h2 class="text-lg font-semibold">System Clock &amp; Timezone</h2>
          <p class="text-xs text-gray-500">Configure the timezone and manual clock offset applied across the platform.</p>
          <p class="text-xs text-amber-600 mt-1">Authorized credentials will be requested before any clock changes are applied.</p>
        </div>
        <div class="text-xs text-gray-500 text-right space-y-1">
          <div><span class="font-semibold text-gray-700">Timezone:</span> <?= htmlspecialchars($appTzName) ?> (<?= htmlspecialchars($appTzAbbr) ?>)</div>
          <div><span class="font-semibold text-gray-700">Offset:</span> <?= htmlspecialchars($appTzOffset) ?></div>
        </div>
      </div>
      <div class="grid gap-3 md:grid-cols-2 text-sm">
        <div class="rounded border border-gray-200 bg-gray-50 p-3">
          <div class="text-xs uppercase tracking-wide text-gray-500">Application Clock</div>
          <div class="mt-1 text-lg font-mono text-gray-900"><?= htmlspecialchars($effectiveNowDisplay) ?></div>
          <div class="mt-1 text-xs text-gray-600"><?= htmlspecialchars($manualOffsetSummary) ?></div>
        </div>
        <div class="rounded border border-gray-200 bg-gray-50 p-3">
          <div class="text-xs uppercase tracking-wide text-gray-500">Server Time (<?= htmlspecialchars($appTzName) ?>)</div>
          <div class="mt-1 text-lg font-mono text-gray-900"><?= htmlspecialchars($serverTimeDisplay) ?></div>
          <div class="mt-1 text-xs text-gray-600">Baseline clock without manual overrides.</div>
        </div>
      </div>
      <form method="post" class="space-y-2" data-authz-module="settings" data-authz-required="admin" data-authz-force data-authz-action="Update application timezone">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="timezone_update">
        <label class="block text-sm font-medium text-gray-700" for="appTimezone">Time Zone</label>
        <select id="appTimezone" name="timezone" class="input-text w-full" required>
          <?php foreach ($timezoneOptions as $region => $options): ?>
            <optgroup label="<?= htmlspecialchars($region) ?>">
              <?php foreach ($options as $tzOpt): ?>
                <option value="<?= htmlspecialchars($tzOpt['name']) ?>" <?= $tzOpt['name'] === $appTzName ? 'selected' : '' ?>>
                  <?= htmlspecialchars($tzOpt['label'] . ' (' . $tzOpt['display_offset'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <div class="flex justify-end">
          <button type="submit" class="btn btn-secondary">Save Timezone</button>
        </div>
      </form>
      <form method="post" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto]" data-authz-module="settings" data-authz-required="admin" data-authz-force data-authz-action="Apply manual system time override" data-confirm="Apply manual time override? This updates the displayed time for all users.">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="time_override_apply">
        <div>
          <label class="block text-sm font-medium text-gray-700" for="manualDatetime">Set Application Time</label>
          <input id="manualDatetime" type="datetime-local" name="manual_datetime" class="input-text w-full" required value="<?= htmlspecialchars($effectiveNowInputValue, ENT_QUOTES) ?>">
          <p class="mt-1 text-xs text-gray-500">Enter the exact local date and time the system should display.</p>
        </div>
        <div class="flex items-end">
          <button type="submit" class="btn btn-primary">Apply Override</button>
        </div>
      </form>
      <?php if ($manualOverrideActive): ?>
      <form method="post" class="flex justify-end" data-authz-module="settings" data-authz-required="admin" data-authz-force data-authz-action="Reset manual system time override" data-confirm="Reset manual time override and return to server time?">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="time_override_reset">
        <button type="submit" class="btn btn-outline">Reset to Server Time</button>
      </form>
      <?php else: ?>
      <p class="text-xs text-gray-500 text-right">No manual override is currently applied.</p>
      <?php endif; ?>
    </div>
    <?php $hasRates = false; foreach ($categoryLabels as $catKey => $catLabel): $items = $ratesByCategory[$catKey] ?? []; if (!$items) { continue; } $hasRates = true; ?>
    <div class="card">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold"><?= htmlspecialchars($catLabel) ?></h2>
        <span class="text-xs text-gray-500"><?= count($items) ?> active</span>
      </div>
      <div class="overflow-x-auto">
        <table class="table-basic min-w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="p-2 text-left">Code</th>
              <th class="p-2 text-left">Description</th>
              <th class="p-2 text-right">Default</th>
              <th class="p-2 text-right">Override</th>
              <th class="p-2 text-left">Effective</th>
              <th class="p-2 text-left">Updated</th>
              <th class="p-2 text-left">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $row):
              $codeKey = strtolower((string)$row['code']);
              $hist = $rateHistory[$codeKey] ?? [];
              $histCount = count($hist);
              $updatedByName = null;
              $updatedById = (int)($row['updated_by'] ?? 0);
              if ($updatedById) { $updatedByName = $userMap[$updatedById] ?? ('User #' . $updatedById); }
        $updatedAtFmt = format_datetime_display($row['updated_at'] ?? null, false, '');
              $effectiveRange = $row['effective_start'] ?? '';
        if (!empty($row['effective_end'])) {
          $effectiveRange .= ' - ' . $row['effective_end'];
              } else {
                  $effectiveRange .= ' onward';
              }
              $todayTs = strtotime('today');
              $nextTs = $todayTs;
              if (!empty($row['effective_end'])) {
                  $nextTs = strtotime($row['effective_end'] . ' +1 day');
              } elseif (!empty($row['effective_start'])) {
                  $nextTs = strtotime($row['effective_start'] . ' +1 day');
              }
              if ($nextTs === false) { $nextTs = $todayTs; }
              if ($nextTs < $todayTs) { $nextTs = $todayTs; }
              $nextStart = date('Y-m-d', $nextTs);
            ?>
            <tr class="border-t">
              <td class="p-2 align-top">
                <span class="font-mono uppercase text-xs text-gray-700"><?= htmlspecialchars($row['code']) ?></span>
              </td>
              <td class="p-2 align-top">
                <div class="font-medium text-gray-800"><?= htmlspecialchars($row['label']) ?></div>
                <div class="text-xs text-gray-500"><?= htmlspecialchars(payroll_rate_category_label((string)$row['category'])) ?></div>
              </td>
              <td class="p-2 text-right align-top">
                <?= htmlspecialchars(config_format_rate_value($row['default_value'])) ?>
              </td>
              <td class="p-2 text-right align-top">
                <?php if ($row['override_value'] !== null && $row['override_value'] !== ''): ?>
                  <span class="text-blue-700 font-medium"><?= htmlspecialchars(config_format_rate_value($row['override_value'])) ?></span>
                <?php else: ?>
                  <span class="text-gray-400">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="p-2 text-sm text-gray-600 align-top">
                <?= htmlspecialchars($effectiveRange) ?>
              </td>
              <td class="p-2 text-xs text-gray-500 align-top">
                <div><?= htmlspecialchars($updatedAtFmt ?: 'n/a') ?></div>
                <?php if ($updatedByName): ?>
                <div><?= htmlspecialchars($updatedByName) ?></div>
                <?php endif; ?>
              </td>
              <td class="p-2 align-top">
                <?php if ($canManageRates): ?>
                <button type="button"
                  class="btn btn-primary btn-sm rate-adjust"
                  data-code="<?= htmlspecialchars($row['code'], ENT_QUOTES) ?>"
                  data-label="<?= htmlspecialchars($row['label'], ENT_QUOTES) ?>"
                  data-category="<?= htmlspecialchars((string)$row['category'], ENT_QUOTES) ?>"
                  data-default="<?= htmlspecialchars((string)$row['default_value'], ENT_QUOTES) ?>"
                  data-override="<?= htmlspecialchars((string)$row['override_value'], ENT_QUOTES) ?>"
                  data-start="<?= htmlspecialchars((string)$row['effective_start'], ENT_QUOTES) ?>"
                  data-end="<?= htmlspecialchars((string)($row['effective_end'] ?? ''), ENT_QUOTES) ?>"
                  data-next-start="<?= htmlspecialchars($nextStart, ENT_QUOTES) ?>">
                  Adjust Rate
                </button>
                <?php else: ?>
                <span class="text-xs text-gray-400">View only</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($histCount > 1): ?>
            <tr class="border-t bg-gray-50">
              <td colspan="7" class="p-3">
                <details>
                  <summary class="text-sm font-medium text-gray-700 cursor-pointer">History (<?= $histCount ?>)</summary>
                  <div class="mt-2 space-y-2 text-xs text-gray-600">
                    <?php $histSlice = array_slice($hist, 0, 8); foreach ($histSlice as $record):
                      $recRange = $record['effective_start'] ?? '';
            if (!empty($record['effective_end'])) {
              $recRange .= ' - ' . $record['effective_end'];
                      } else {
                          $recRange .= ' onward';
                      }
            $recUpdated = format_datetime_display($record['updated_at'] ?? null, false, '');
                      $recUpdatedBy = null;
                      $recUid = (int)($record['updated_by'] ?? 0);
                      if ($recUid) { $recUpdatedBy = $userMap[$recUid] ?? ('User #' . $recUid); }
                    ?>
                    <div class="border border-gray-200 rounded-md p-2 flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                      <div>
                        <div class="font-medium text-gray-800">Default <?= htmlspecialchars(config_format_rate_value($record['default_value'])) ?><?php if ($record['override_value'] !== null && $record['override_value'] !== ''): ?> - Override <?= htmlspecialchars(config_format_rate_value($record['override_value'])) ?><?php endif; ?></div>
                        <div><?= htmlspecialchars($recRange) ?></div>
                        <div class="text-gray-500">Updated <?= htmlspecialchars($recUpdated ?: 'n/a') ?><?= $recUpdatedBy ? ' - ' . htmlspecialchars($recUpdatedBy) : '' ?></div>
                      </div>
                      <div class="flex items-center gap-2 mt-2 md:mt-0">
                        <?php if ($canManageRates): ?>
                        <button type="button"
                          class="btn btn-outline btn-xs rate-reuse"
                          data-code="<?= htmlspecialchars($record['code'], ENT_QUOTES) ?>"
                          data-label="<?= htmlspecialchars($record['label'], ENT_QUOTES) ?>"
                          data-category="<?= htmlspecialchars((string)$record['category'], ENT_QUOTES) ?>"
                          data-default="<?= htmlspecialchars((string)$record['default_value'], ENT_QUOTES) ?>"
                          data-override="<?= htmlspecialchars((string)$record['override_value'], ENT_QUOTES) ?>"
                          data-start="<?= htmlspecialchars((string)$record['effective_start'], ENT_QUOTES) ?>"
                          data-end="<?= htmlspecialchars((string)($record['effective_end'] ?? ''), ENT_QUOTES) ?>"
                          data-next-start="<?= htmlspecialchars($nextStart, ENT_QUOTES) ?>">
                          Load Values
                        </button>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($histCount > count($histSlice)): ?>
                    <div class="text-gray-500">Showing latest <?= count($histSlice) ?> of <?= $histCount ?> records.</div>
                    <?php endif; ?>
                  </div>
                </details>
              </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; if (!$hasRates): ?>
    <div class="card text-sm text-gray-600">
      <h2 class="text-lg font-semibold mb-2">No rate configurations yet</h2>
      <p>Use the <strong>Add Rate</strong> button to seed statutory or custom rate entries.</p>
    </div>
    <?php endif; ?>
  </div>
  <div class="space-y-4">
    <div class="card">
      <h2 class="text-lg font-semibold mb-2">Time Settings</h2>
      <dl class="space-y-2 text-sm text-gray-700">
        <div>
          <dt class="text-xs uppercase tracking-wide text-gray-500">Current Time</dt>
          <dd><?= htmlspecialchars($appTimeNow ?: 'n/a') ?></dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-gray-500">Timezone</dt>
          <dd><?= htmlspecialchars($appTzName) ?> (<?= htmlspecialchars($appTzAbbr) ?> <?= htmlspecialchars($appTzOffset) ?>)</dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-gray-500">Display Format</dt>
          <dd>12-hour &middot; <?= htmlspecialchars($appTimeSample) ?><?php if ($appTimeSampleSeconds): ?> (with seconds: <?= htmlspecialchars($appTimeSampleSeconds) ?>)<?php endif; ?></dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-gray-500">Manual Offset</dt>
          <dd><?= htmlspecialchars($manualOffsetSummary) ?></dd>
        </div>
      </dl>
      <p class="mt-3 text-xs text-gray-500">Use the controls above to align the global clock with finance requirements.</p>
    </div>
    <div class="card">
      <div class="mb-2 flex items-center justify-between">
        <h2 class="text-lg font-semibold">System Log Snapshot</h2>
        <a class="text-sm text-blue-700 hover:underline spa" href="<?= BASE_URL ?>/modules/admin/system_log">View all</a>
      </div>
      <ul class="divide-y divide-gray-200 text-sm">
        <?php foreach ($recentLogs as $log):
          $created = format_datetime_display($log['created_at'] ?? null, false, '');
          $message = (string)($log['message'] ?? '');
      if (mb_strlen($message) > 120) {
        $message = mb_substr($message, 0, 117) . '...';
          }
        ?>
        <li class="py-2">
          <div class="flex items-center justify-between">
            <span class="font-mono text-xs text-gray-500"><?= htmlspecialchars($log['code'] ?? '--') ?></span>
            <span class="text-xs text-gray-400"><?= htmlspecialchars($created ?: 'n/a') ?></span>
          </div>
          <div class="text-gray-700"><?= htmlspecialchars($message) ?></div>
          <?php if (!empty($log['module'])): ?>
          <div class="text-xs text-gray-500">Module: <?= htmlspecialchars($log['module']) ?></div>
          <?php endif; ?>
        </li>
        <?php endforeach; if (!$recentLogs): ?>
        <li class="py-4 text-center text-gray-500">No recent log entries.</li>
        <?php endif; ?>
      </ul>
    </div>
    <div class="card">
      <h2 class="text-lg font-semibold mb-2">Recent Rate Activity</h2>
      <ul class="divide-y divide-gray-200 text-sm">
        <?php foreach ($recentRateEvents as $event):
          $evtUpdated = format_datetime_display($event['updated_at'] ?? null, false, '');
          $evtUser = null;
          $evtUid = (int)($event['updated_by'] ?? 0);
          if ($evtUid) { $evtUser = $userMap[$evtUid] ?? ('User #' . $evtUid); }
        ?>
        <li class="py-2">
          <div class="flex items-center justify-between">
            <span class="font-semibold text-gray-800"><?= strtoupper(htmlspecialchars((string)$event['code'])) ?></span>
            <span class="text-xs text-gray-400"><?= htmlspecialchars($evtUpdated ?: 'n/a') ?></span>
          </div>
          <div class="text-xs text-gray-600">Default <?= htmlspecialchars(config_format_rate_value($event['default_value'])) ?><?php if ($event['override_value'] !== null && $event['override_value'] !== ''): ?> - Override <?= htmlspecialchars(config_format_rate_value($event['override_value'])) ?><?php endif; ?></div>
          <div class="text-xs text-gray-500">Effective <?= htmlspecialchars((string)$event['effective_start']) ?><?php if (!empty($event['effective_end'])): ?> - <?= htmlspecialchars((string)$event['effective_end']) ?><?php else: ?> onward<?php endif; ?></div>
          <?php if ($evtUser): ?>
          <div class="text-xs text-gray-400">By <?= htmlspecialchars($evtUser) ?></div>
          <?php endif; ?>
        </li>
        <?php endforeach; if (!$recentRateEvents): ?>
        <li class="py-4 text-center text-gray-500">No rate adjustments yet.</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<!-- Rate Configuration Modal -->
<div id="rateModal" class="fixed inset-0 z-50 hidden items-center justify-center">
  <div class="absolute inset-0 bg-black/40" data-close></div>
  <div class="relative bg-white w-full max-w-2xl mx-3 rounded shadow-lg">
    <div class="flex items-center justify-between border-b px-4 py-3">
      <div>
        <h2 class="text-lg font-semibold" data-rate-modal-title>Adjust Rate</h2>
        <p class="text-xs text-gray-500">Changes require confirmation and authorization before taking effect.</p>
      </div>
      <button type="button" class="btn btn-outline" data-close>&times;</button>
    </div>
    <form method="post" class="p-4 space-y-4" id="rateForm" data-confirm="Apply this rate configuration change?" data-authz-module="payroll" data-authz-required="admin">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="rate_save">
      <input type="hidden" name="is_new" id="rateIsNew" value="0">
      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700" for="rateCategory">Category</label>
          <select id="rateCategory" name="category" class="input-text" required>
            <option value="" disabled selected>Select category</option>
            <?php foreach ($categoryLabels as $key => $labelText): ?>
            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($labelText) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700" for="rateCode">Code</label>
          <input id="rateCode" name="code" class="input-text" required pattern="[A-Za-z0-9_\-]+" placeholder="e.g., sss_rate">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700" for="rateLabel">Configuration Label</label>
        <input id="rateLabel" name="label" class="input-text" required maxlength="191" placeholder="Display name">
      </div>
      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700" for="rateDefault">Default Value</label>
          <input id="rateDefault" name="default_value" class="input-text" required type="number" step="0.0001" min="0">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700" for="rateOverride">Override Value</label>
          <input id="rateOverride" name="override_value" class="input-text" type="number" step="0.0001" min="0" placeholder="Leave blank to use default">
        </div>
      </div>
      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700" for="rateStart">Effective Start</label>
          <input id="rateStart" name="effective_start" class="input-text" type="date" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700" for="rateEnd">Effective End</label>
          <input id="rateEnd" name="effective_end" class="input-text" type="date" placeholder="Optional">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700" for="rateNotes">Notes</label>
        <textarea id="rateNotes" name="notes" class="input-text" rows="3" maxlength="500" placeholder="Internal note for auditors (optional)"></textarea>
        <p class="text-xs text-gray-500 mt-1">Notes appear in the audit trail to clarify why the change was made.</p>
      </div>
      <div class="flex items-center justify-between border-t pt-3">
        <div class="text-xs text-gray-500">An authorization override may be required before submitting.</div>
        <button type="submit" class="btn btn-primary">Save Configuration</button>
      </div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
