<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();

// Check if user can manage memos (create/edit)
$canManageMemos = user_can('documents', 'memos', 'write');
$userId = (int)$user['id'];
$pageTitle = $canManageMemos ? 'Memo Management' : 'Company Memos';

$search = trim((string)($_GET['search'] ?? ''));
$limit = 100;
$params = [];
$where = '';

// If user cannot manage memos, filter to show only memos they're recipients of
if (!$canManageMemos) {
  // Get user's employee record and role
  $empStmt = $pdo->prepare('SELECT id, department_id, position_id FROM employees WHERE user_id = :user_id LIMIT 1');
  $empStmt->execute([':user_id' => $userId]);
  $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
  
  // Get user's role for backward compatibility
  $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :user_id LIMIT 1');
  $roleStmt->execute([':user_id' => $userId]);
  $userRole = strtolower((string)$roleStmt->fetchColumn());
  
  if ($employee) {
    $employeeId = (int)$employee['id'];
    $departmentId = (int)($employee['department_id'] ?? 0);
    $positionId = (int)($employee['position_id'] ?? 0);
    
    // Build WHERE clause to filter memos by audience
    // Include: all employees, specific employee, department match, role match (old enum), or position match (new system)
    $audienceConditions = [
      "mr.audience_type = 'all'",
      "(mr.audience_type = 'employee' AND mr.audience_identifier = :emp_id)",
      "(mr.audience_type = 'department' AND mr.audience_identifier = :dept_id)",
    ];
    
    $params[':emp_id'] = (string)$employeeId;
    $params[':dept_id'] = (string)$departmentId;
    
    // Check for old role-based audience (enum values)
    if ($userRole !== '') {
      $audienceConditions[] = "(mr.audience_type = 'role' AND mr.audience_identifier = :role_code)";
      $params[':role_code'] = $userRole;
    }
    
    // Check for position-based audience (new system)
    if ($positionId > 0) {
      $audienceConditions[] = "(mr.audience_type = 'role' AND mr.audience_identifier = :pos_id)";
      $params[':pos_id'] = (string)$positionId;
    }
    
    $where = 'WHERE m.id IN (
      SELECT DISTINCT mr.memo_id 
      FROM memo_recipients mr 
      WHERE ' . implode(' OR ', $audienceConditions) . '
    )';
  } else {
    // No employee record, show no memos
    $where = 'WHERE 1=0';
  }
}

// Add search filter if provided
if ($search !== '') {
  $searchCondition = '(m.memo_code ILIKE :term OR m.header ILIKE :term)';
  if ($where === '') {
    $where = 'WHERE ' . $searchCondition;
  } else {
    $where .= ' AND ' . $searchCondition;
  }
  $params[':term'] = '%' . $search . '%';
}

$sql = 'SELECT m.id, m.memo_code, m.header, m.status, m.published_at, m.updated_at, m.issued_by_name, m.issued_by_position, m.allow_downloads
  FROM memos m ' . $where . ' ORDER BY m.published_at DESC, m.id DESC LIMIT ' . (int)$limit;
$memoRows = [];
try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $memoRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  sys_log('MEMO-LIST', 'Unable to load memos: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
  $memoRows = [];
}

$memoRecipients = [];
$attachmentCounts = [];
if ($memoRows) {
  $ids = array_column($memoRows, 'id');
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  try {
    $stmt = $pdo->prepare('SELECT memo_id, audience_type, audience_identifier, audience_label FROM memo_recipients WHERE memo_id IN (' . $placeholders . ') ORDER BY id');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $memoRecipients[(int)$row['memo_id']][] = $row;
    }
  } catch (Throwable $e) {
    sys_log('MEMO-LIST-RECIP', 'Unable to load memo recipients: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
  }
  try {
    $stmt = $pdo->prepare('SELECT memo_id, COUNT(*) AS total FROM memo_attachments WHERE memo_id IN (' . $placeholders . ') GROUP BY memo_id');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $attachmentCounts[(int)$row['memo_id']] = (int)$row['total'];
    }
  } catch (Throwable $e) {
    sys_log('MEMO-LIST-ATT', 'Unable to load memo attachments: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__]);
  }
}

function format_memo_recipients(array $recipients): string {
  if (!$recipients) {
    return '—';
  }
  $types = ['all' => [], 'department' => [], 'role' => [], 'employee' => []];
  foreach ($recipients as $row) {
    $type = strtolower((string)($row['audience_type'] ?? ''));
    $label = trim((string)($row['audience_label'] ?? $row['audience_identifier'] ?? ''));
    if ($type === 'all') {
      return 'All employees';
    }
    if ($label === '') {
      $label = $row['audience_identifier'] ?? '';
    }
    if ($label === '') {
      $label = ucfirst($type);
    }
    $types[$type][] = $label;
  }
  $chunks = [];
  if ($types['department']) {
    $chunks[] = 'Departments: ' . implode(', ', array_unique($types['department']));
  }
  if ($types['role']) {
    $chunks[] = 'Roles: ' . implode(', ', array_unique($types['role']));
  }
  if ($types['employee']) {
    $chunks[] = 'Individuals: ' . implode(', ', array_unique($types['employee']));
  }
  return $chunks ? implode(' • ', $chunks) : 'Custom audience';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-6xl mx-auto space-y-6">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600">Memo Center</p>
      <h1 class="text-3xl font-semibold text-slate-900"><?= $canManageMemos ? 'Memo Management' : 'Company Memos' ?></h1>
      <p class="mt-1 text-sm text-slate-600"><?= $canManageMemos ? 'Review published memos, monitor attachment access, and craft new announcements.' : 'View memos and announcements shared with you.' ?></p>
    </div>
    <div class="flex flex-col gap-2 sm:flex-row">
      <form method="get" class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-white/80 px-3 py-2 shadow-sm">
        <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
        <input type="text" name="search" class="flex-1 bg-transparent text-sm focus:outline-none" placeholder="Search code or header" value="<?= htmlspecialchars($search) ?>">
        <?php if ($search !== ''): ?><a href="<?= BASE_URL ?>/modules/memos/index" class="text-xs text-slate-400 hover:text-slate-600">Clear</a><?php endif; ?>
        <button class="hidden" type="submit">Search</button>
      </form>
      <?php if ($canManageMemos): ?>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/memos/create">Create memo</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($memoRows): ?>
    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      <?php foreach ($memoRows as $memo): ?>
        <?php
          $id = (int)$memo['id'];
          $audience = format_memo_recipients($memoRecipients[$id] ?? []);
          $attachCount = $attachmentCounts[$id] ?? 0;
          $downloadsAllowed = !empty($memo['allow_downloads']);
          $viewUrl = BASE_URL . '/modules/memos/view?id=' . $id;
          $editUrl = BASE_URL . '/modules/memos/edit?id=' . $id;
        ?>
        <article
          class="group relative flex h-full cursor-pointer flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white/80 shadow-sm transition hover:-translate-y-1 hover:shadow-lg focus-within:-translate-y-1 focus-within:shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-500"
          data-card-link="<?= htmlspecialchars($viewUrl) ?>"
          data-card-spa="1"
          role="link"
          tabindex="0"
          aria-label="View memo <?= htmlspecialchars($memo['memo_code']) ?>"
        >
          <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white px-6 py-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <span class="inline-flex items-center gap-2 rounded-full bg-slate-900/90 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">Code <?= htmlspecialchars($memo['memo_code']) ?></span>
                <h2 class="mt-3 line-clamp-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars($memo['header']) ?></h2>
              </div>
              <div class="text-right text-xs text-slate-400">
                <div>Published</div>
                <div class="font-medium text-slate-600"><?= htmlspecialchars(format_datetime_display($memo['published_at'])) ?></div>
              </div>
            </div>
            <p class="mt-3 text-xs text-slate-500">By <?= htmlspecialchars($memo['issued_by_name']) ?><?= $memo['issued_by_position'] ? ' • ' . htmlspecialchars($memo['issued_by_position']) : '' ?></p>
          </div>
          <div class="flex flex-1 flex-col gap-4 px-6 py-5 text-sm text-slate-600">
            <div class="flex flex-wrap items-center gap-2">
              <?php if ($audience !== '—'): ?>
                <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Audience</span>
                <span class="truncate text-sm text-slate-600" title="<?= htmlspecialchars($audience) ?>"><?= htmlspecialchars($audience) ?></span>
              <?php else: ?>
                <span class="text-sm text-slate-400">No audience recorded</span>
              <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-3 text-xs text-slate-500">
              <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-600">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 17v-6a2 2 0 00-2-2h-5l-2-3H5a2 2 0 00-2 2v9M3 17h18"/></svg>
                <?= $attachCount ?> file<?= $attachCount === 1 ? '' : 's' ?>
              </span>
              <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 font-medium <?= $downloadsAllowed ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?= $downloadsAllowed ? 'Downloads enabled' : 'Preview only' ?>
              </span>
              <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-600">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2 2m5-2a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                Updated <?= htmlspecialchars(format_datetime_display($memo['updated_at'])) ?>
              </span>
            </div>
          </div>
          <div class="relative z-10 flex items-center justify-between border-t border-slate-100 bg-slate-50 px-6 py-4 text-sm">
            <div class="text-xs text-slate-400">Status: <span class="font-semibold text-slate-600"><?= htmlspecialchars(ucfirst($memo['status'])) ?></span></div>
            <div class="flex items-center gap-3 font-medium">
              <span class="text-xs text-slate-400">Tap anywhere to open</span>
              <?php if ($canManageMemos): ?>
                <span class="h-4 w-px bg-slate-200"></span>
                <a href="<?= htmlspecialchars($editUrl) ?>" class="text-slate-500 transition hover:text-slate-700" data-card-link-stop>Edit</a>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="rounded-3xl border border-dashed border-slate-200 bg-white/70 px-8 py-16 text-center">
      <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-slate-400">
        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/><circle cx="12" cy="12" r="10"/></svg>
      </div>
      <h2 class="mt-4 text-lg font-semibold text-slate-800">No memos <?= $search !== '' ? 'found' : 'yet' ?></h2>
      <p class="mt-2 text-sm text-slate-500">
        <?php if ($canManageMemos): ?>
          Start by creating your first memo so teams receive timely announcements.
        <?php elseif ($search !== ''): ?>
          Try adjusting your search terms or clearing the search to view all memos.
        <?php else: ?>
          You don't have any memos shared with you at this time.
        <?php endif; ?>
      </p>
      <?php if ($canManageMemos): ?>
        <a class="mt-6 inline-flex items-center gap-2 rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-emerald-500" href="<?= BASE_URL ?>/modules/memos/create">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
          Create memo
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
