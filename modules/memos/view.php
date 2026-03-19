<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/form_helpers.php';

$pdo = get_db_conn();
$user = current_user();
$memoId = (int)($_GET['id'] ?? 0);
if ($memoId <= 0) {
  flash_error('Memo not found.');
  header('Location: ' . BASE_URL . '/modules/memos/index');
  exit;
}

$uid = (int)($user['id'] ?? 0);
$memo = memo_fetch($pdo, $memoId);
if (!$memo) {
  flash_error('Memo not found.');
  header('Location: ' . BASE_URL . '/modules/memos/index');
  exit;
}

$canReadMemo = $uid ? user_can('documents', 'memos', 'read') : false;
$canManageMemo = $uid ? user_can('documents', 'memos', 'write') : false;
$hasAudienceAccess = $uid ? memo_user_has_access($pdo, $memoId, $uid) : false;

if (!$canManageMemo && !$canReadMemo && !$hasAudienceAccess) {
  flash_error('You are not authorized to view that memo.');
  header('Location: ' . BASE_URL . '/modules/memos/index');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_downloads']) && csrf_verify($_POST['csrf'] ?? '')) {
  if (!$canManageMemo) {
    flash_error('You do not have permission to manage memo downloads.');
    header('Location: ' . BASE_URL . '/modules/memos/view?id=' . $memoId);
    exit;
  }
  $allow = $_POST['toggle_downloads'] === '1';
  try {
    $stmt = $pdo->prepare('UPDATE memos SET allow_downloads = :allow WHERE id = :id');
    $stmt->execute([':allow' => $allow ? 1 : 0, ':id' => $memoId]);
    action_log('documents', 'memo_toggle_downloads', 'success', ['memo_id' => $memoId, 'allow_downloads' => $allow]);
    audit('memo_toggle_downloads', json_encode(['memo_id' => $memoId, 'allow_downloads' => $allow]));
    flash_success($allow ? 'Recipients can now download attachments.' : 'Downloads have been disabled for this memo.');
  } catch (Throwable $e) {
    sys_log('MEMO-TOGGLE-DL', 'Failed to toggle memo download flag: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId]]);
    flash_error('Unable to update download permissions.');
  }
  header('Location: ' . BASE_URL . '/modules/memos/view?id=' . $memoId);
  exit;
}

// Handle Acknowledgement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge']) && csrf_verify($_POST['csrf'] ?? '')) {
  if (!$hasAudienceAccess) {
    flash_error('You are not on the recipient list for this memo.');
    header('Location: ' . BASE_URL . '/modules/memos/view?id=' . $memoId);
    exit;
  }
  try {
    $stmt = $pdo->prepare('INSERT INTO memo_acknowledgements (memo_id, user_id) VALUES (:memo, :user) ON CONFLICT (memo_id, user_id) DO UPDATE SET acknowledged_at = CURRENT_TIMESTAMP');
    $stmt->execute([':memo' => $memoId, ':user' => $uid]);
    action_log('documents', 'memo_acknowledge', 'success', ['memo_id' => $memoId, 'user_id' => $uid]);
    audit('memo_acknowledge', json_encode(['memo_id' => $memoId, 'user_id' => $uid]));
    flash_success('Thank you for acknowledging this memo.');
  } catch (Throwable $e) {
    sys_log('MEMO-ACK', 'Failed to acknowledge memo: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId, 'user_id' => $uid]]);
    flash_error('Unable to record acknowledgement.');
  }
  header('Location: ' . BASE_URL . '/modules/memos/view?id=' . $memoId);
  exit;
}

$recipients = memo_fetch_recipients($pdo, $memoId);
$attachments = memo_fetch_attachments($pdo, $memoId);

// Fetch acknowledgements
$acknowledgements = [];
$ackCount = 0;
$hasAcknowledged = false;
$role = strtolower((string)($user['role'] ?? ''));
$isAdminOrHR = in_array($role, ['admin', 'hr'], true);

try {
  // Check if current user has acknowledged
  $checkStmt = $pdo->prepare('SELECT id FROM memo_acknowledgements WHERE memo_id = :memo AND user_id = :user LIMIT 1');
  $checkStmt->execute([':memo' => $memoId, ':user' => $uid]);
  $hasAcknowledged = (bool)$checkStmt->fetch();
  
  // Get count
  $countStmt = $pdo->prepare('SELECT COUNT(*) FROM memo_acknowledgements WHERE memo_id = :memo');
  $countStmt->execute([':memo' => $memoId]);
  $ackCount = (int)$countStmt->fetchColumn();
  
  // Get full list if admin/HR
  if ($isAdminOrHR) {
    $listStmt = $pdo->prepare('SELECT ma.user_id, ma.acknowledged_at, u.full_name as name, u.email, e.employee_code 
      FROM memo_acknowledgements ma 
      JOIN users u ON u.id = ma.user_id 
      LEFT JOIN employees e ON e.user_id = u.id 
      WHERE ma.memo_id = :memo 
      ORDER BY ma.acknowledged_at DESC');
    $listStmt->execute([':memo' => $memoId]);
    $acknowledgements = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {
  sys_log('MEMO-ACK-FETCH', 'Failed to fetch acknowledgements: ' . $e->getMessage(), ['module' => 'documents', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['memo_id' => $memoId]]);
}

function memo_audience_chips(array $rows): array {
  if (!$rows) {
    return [];
  }
  $chips = [];
  foreach ($rows as $row) {
    $type = strtolower((string)($row['audience_type'] ?? ''));
    $label = trim((string)($row['audience_label'] ?? $row['audience_identifier'] ?? ''));
    if ($type === 'all') {
      $chips[] = ['label' => 'All employees', 'tone' => 'emerald'];
      continue;
    }
    if ($label === '') {
      $label = ucfirst($type);
    }
    $tone = match ($type) {
      'department' => 'indigo',
      'role' => 'sky',
      'employee' => 'slate',
      default => 'slate',
    };
    $chips[] = ['label' => $label, 'tone' => $tone];
  }
  return $chips;
}

$audienceChips = memo_audience_chips($recipients);
$pageTitle = 'Memo • ' . htmlspecialchars($memo['header']);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-6xl mx-auto space-y-8">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600">Memo Center</p>
      <h1 class="text-3xl font-semibold text-slate-900"><?= htmlspecialchars($memo['header']) ?></h1>
      <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-slate-500">
        <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-700">Code: <?= htmlspecialchars($memo['memo_code']) ?></span>
        <span><?= htmlspecialchars($memo['issued_by_name']) ?><?= $memo['issued_by_position'] ? ' • ' . htmlspecialchars($memo['issued_by_position']) : '' ?></span>
        <span>Published <?= htmlspecialchars(format_datetime_display($memo['published_at'])) ?></span>
        <span>Last updated <?= htmlspecialchars(format_datetime_display($memo['updated_at'])) ?></span>
      </div>
    </div>
    <div class="flex flex-wrap gap-3">
      <?php if ($canManageMemo): ?>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/memos/edit?id=<?= $memoId ?>">Edit memo</a>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="toggle_downloads" value="<?= $memo['allow_downloads'] ? '0' : '1' ?>">
          <button type="submit" class="btn <?= $memo['allow_downloads'] ? 'btn-outline' : 'btn-primary' ?>">
            <?= $memo['allow_downloads'] ? 'Disable downloads' : 'Allow downloads' ?>
          </button>
        </form>
      <?php endif; ?>
      <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/memos/index">Back to list</a>
    </div>
  </div>

  <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
    <section class="rounded-3xl border border-slate-200 bg-white/80 p-8 shadow-sm lg:col-span-2">
      <div class="prose max-w-none text-slate-800">
        <?= nl2br(htmlspecialchars($memo['body'])) ?>
      </div>
      <div class="mt-6 flex flex-wrap gap-2">
        <?php if ($audienceChips): ?>
          <?php foreach ($audienceChips as $chip): ?>
            <?php
              $tone = $chip['tone'];
              $classes = match ($tone) {
                'emerald' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                'indigo' => 'bg-indigo-50 text-indigo-700 border border-indigo-200',
                'sky' => 'bg-sky-50 text-sky-700 border border-sky-200',
                default => 'bg-slate-100 text-slate-700 border border-slate-200',
              };
            ?>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?= $classes ?>"><?= htmlspecialchars($chip['label']) ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="text-sm text-slate-400">No audience recorded.</span>
        <?php endif; ?>
      </div>
    </section>

    <aside class="space-y-6 lg:col-span-1">
      <!-- Acknowledgement Section -->
      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm">
        <div class="flex items-start justify-between gap-3 mb-4">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Acknowledgement</h2>
            <p class="text-xs uppercase tracking-wide text-slate-400">
              <?php if ($hasAcknowledged): ?>
                <span class="text-emerald-600 font-medium inline-flex items-center gap-1">
                  <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                  </svg>
                  You acknowledged this
                </span>
              <?php else: ?>
                Confirm receipt
              <?php endif; ?>
            </p>
          </div>
        </div>
        
        <?php if (!$hasAcknowledged && $hasAudienceAccess): ?>
          <form method="post" class="mb-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="acknowledge" value="1">
            <button type="submit" class="w-full btn btn-primary inline-flex items-center justify-center gap-2">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Acknowledge Receipt
            </button>
          </form>
        <?php elseif (!$hasAudienceAccess): ?>
          <div class="rounded-2xl bg-slate-100 px-4 py-3 text-xs text-slate-500">
            Only listed recipients can acknowledge this memo.
          </div>
        <?php endif; ?>
        
        <div class="mt-4 pt-4 border-t border-slate-200">
          <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-medium text-slate-700">Total Acknowledgements</span>
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-700">
              <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              <?= $ackCount ?>
            </span>
          </div>
          
          <?php if ($isAdminOrHR && $acknowledgements): ?>
            <details class="group">
              <summary class="cursor-pointer text-sm font-medium text-emerald-600 hover:text-emerald-700 flex items-center gap-2">
                <svg class="h-4 w-4 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                View Details (Admin)
              </summary>
              <div class="mt-3 max-h-64 overflow-y-auto space-y-2">
                <?php foreach ($acknowledgements as $ack): ?>
                  <div class="flex items-start gap-2 text-xs p-2 rounded-lg bg-slate-50">
                    <svg class="h-4 w-4 text-emerald-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <div class="flex-1 min-w-0">
                      <div class="font-medium text-slate-800 truncate"><?= htmlspecialchars($ack['name']) ?></div>
                      <?php if (!empty($ack['employee_code'])): ?>
                        <div class="text-slate-500"><?= htmlspecialchars($ack['employee_code']) ?></div>
                      <?php endif; ?>
                      <div class="text-slate-400"><?= htmlspecialchars(format_datetime_display($ack['acknowledged_at'])) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endif; ?>
        </div>
      </section>
      
      <section class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Attachments</h2>
            <p class="text-xs uppercase tracking-wide text-slate-400">Total <?= count($attachments) ?></p>
            <p class="mt-1 text-sm text-slate-500">Click a card to preview. Downloads are <?= $memo['allow_downloads'] ? '<span class="text-emerald-600 font-medium">enabled</span>' : '<span class="text-amber-600 font-medium">disabled</span>' ?>.</p>
          </div>
        </div>
        <?php if ($attachments): ?>
          <div class="mt-4 space-y-4" data-memo-attachments-grid>
            <?php foreach ($attachments as $attachment): ?>
              <?php
                $previewUrl = BASE_URL . '/modules/memos/preview_file?id=' . (int)$attachment['id'];
                $downloadUrl = BASE_URL . '/modules/memos/download?id=' . (int)$attachment['id'];
                $ext = strtolower((string)pathinfo($attachment['original_name'], PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['png','jpg','jpeg'], true);
                $type = $isImage ? 'image' : 'pdf';
                $sizeLabel = $attachment['file_size'] ? number_format($attachment['file_size'] / 1024, 1) . ' KB' : 'Unknown size';
              ?>
              <div
                class="group relative w-full cursor-pointer overflow-hidden rounded-3xl border border-slate-200 bg-slate-50 text-left shadow-sm transition hover:-translate-y-1 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-emerald-300"
                role="button"
                tabindex="0"
                data-memo-preview
                data-src="<?= htmlspecialchars($previewUrl) ?>"
                data-name="<?= htmlspecialchars($attachment['original_name']) ?>"
                data-type="<?= $type ?>"
                data-mime="<?= htmlspecialchars($attachment['mime_type'] ?? '') ?>"
                data-download-url="<?= htmlspecialchars($downloadUrl) ?>"
                data-download-allowed="<?= $memo['allow_downloads'] ? '1' : '0' ?>">
                <div class="relative h-40 w-full bg-slate-100">
                  <!-- Loading spinner (shown by default, hidden when image loads) -->
                  <div class="absolute inset-0 flex items-center justify-center bg-slate-100 attachment-loader z-10" data-attachment-loader>
                    <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-300 border-t-indigo-600"></div>
                  </div>
                  
                  <?php if ($isImage): ?>
                    <img 
                      data-lazy-src="<?= htmlspecialchars($previewUrl) ?>" 
                      alt="Attachment preview" 
                      class="attachment-image h-full w-full object-cover transition-all duration-300 group-hover:scale-105 opacity-0" 
                      data-attachment-id="<?= (int)$attachment['id'] ?>">
                  <?php else: ?>
                    <iframe 
                      data-lazy-src="<?= htmlspecialchars($previewUrl) ?>#toolbar=0&navpanes=0&scrollbar=0" 
                      title="<?= htmlspecialchars($attachment['original_name']) ?>" 
                      class="attachment-iframe h-full w-full pointer-events-none transition-opacity duration-300 opacity-0"
                      data-attachment-id="<?= (int)$attachment['id'] ?>"></iframe>
                  <?php endif; ?>
                  <?php if (!$memo['allow_downloads']): ?>
                    <span class="absolute right-3 top-3 inline-flex items-center rounded-full bg-amber-500/90 px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-white">Preview only</span>
                  <?php endif; ?>
                </div>
                <div class="flex items-center justify-between gap-3 px-5 py-4 text-sm text-slate-600">
                  <div class="min-w-0">
                    <div class="truncate font-medium text-slate-800"><?= htmlspecialchars($attachment['original_name']) ?></div>
                    <div class="text-xs text-slate-400">Uploaded <?= htmlspecialchars(format_datetime_display($attachment['uploaded_at'])) ?> • <?= htmlspecialchars($sizeLabel) ?></div>
                  </div>
                  <svg class="h-5 w-5 flex-none text-slate-400 transition group-hover:text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17L4 12m0 0l5-5m-5 5h16"/></svg>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="mt-4 rounded-3xl border border-dashed border-slate-200 bg-slate-50/70 px-5 py-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
              <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            </div>
            <p class="mt-3 text-sm font-medium text-slate-600">No attachments were added to this memo.</p>
          </div>
        <?php endif; ?>
      </section>
    </aside>
  </div>
</div>

<div id="memoPreviewModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-slate-900/70" data-memo-preview-close></div>
  <div class="relative mx-auto flex min-h-full w-full max-w-4xl items-center justify-center px-4 py-10">
    <div class="relative w-full overflow-hidden rounded-3xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <div>
          <h3 class="text-lg font-semibold text-slate-900" data-preview-title>Attachment preview</h3>
          <p class="text-xs text-slate-500" data-preview-subtitle></p>
        </div>
        <button type="button" class="rounded-full p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" data-memo-preview-close aria-label="Close preview">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="max-h-[70vh] overflow-auto bg-slate-50">
        <img data-preview-image class="hidden h-full w-full object-contain bg-slate-900/5" alt="Memo attachment">
        <iframe data-preview-frame class="hidden h-[70vh] w-full" title="Document preview"></iframe>
      </div>
      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-6 py-4">
        <div class="text-xs text-slate-500" data-preview-message></div>
        <div class="flex flex-wrap gap-2">
          <a data-preview-download href="#" class="hidden rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500" data-no-loader>Download</a>
          <span data-preview-disabled class="hidden rounded-full bg-amber-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-amber-700">Downloads disabled</span>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Aggressive lazy load attachments on view
  function initAttachmentLoaders() {
    console.log('[Memo Attachments] Starting attachment loader...');
    const container = document.querySelector('[data-memo-attachments-grid]');
    if (!container) {
      console.warn('[Memo Attachments] No attachment grid found');
      return;
    }
    
    const images = container.querySelectorAll('.attachment-image[data-lazy-src]');
    const iframes = container.querySelectorAll('.attachment-iframe[data-lazy-src]');
    
    console.log('[Memo Attachments] Found', images.length, 'images and', iframes.length, 'iframes to load');
    
    // Load images immediately, no delays
    images.forEach((img) => {
      const src = img.getAttribute('data-lazy-src');
      const attachmentId = img.getAttribute('data-attachment-id');
      const loader = img.closest('.relative')?.querySelector('[data-attachment-loader]');
      
      if (!src) {
        console.error('[Memo Attachments] Image', attachmentId, 'has no data-lazy-src attribute');
        if (loader) loader.style.display = 'none';
        return;
      }
      
      // Skip if already has a real src (not the page URL)
      if (img.src && img.src !== window.location.href && img.src !== '' && img.src !== 'about:blank') {
        console.log('[Memo Attachments] Image', attachmentId, 'already loaded, skipping');
        return;
      }
      
      console.log('[Memo Attachments] Loading image', attachmentId);
      console.log('[Memo Attachments] Source URL:', src);
      
      // Ensure loader is visible
      if (loader) {
        loader.style.display = 'flex';
        loader.style.visibility = 'visible';
      }
      
      // Track loading state
      let loadCompleted = false;
      
      // Success handler
      img.onload = function() {
        if (loadCompleted) return;
        loadCompleted = true;
        
        console.log('[Memo Attachments] ✅ Image', attachmentId, 'loaded successfully');
        img.style.opacity = '1';
        img.classList.remove('opacity-0');
        img.classList.add('opacity-100');
        
        if (loader) {
          loader.style.display = 'none';
        }
      };
      
      // Error handler
      img.onerror = function(e) {
        if (loadCompleted) return;
        loadCompleted = true;
        
        console.error('[Memo Attachments] ❌ Image', attachmentId, 'failed to load');
        console.error('[Memo Attachments] Failed URL:', img.src);
        console.error('[Memo Attachments] Error:', e);
        
        // Show error placeholder
        img.src = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22160%22%3E%3Crect fill=%22%23f1f5f9%22 width=%22400%22 height=%22160%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23ef4444%22 font-family=%22sans-serif%22 font-size=%2214%22 font-weight=%22600%22%3EFailed to load image%3C/text%3E%3Ctext x=%2250%25%22 y=%2260%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%2394a3b8%22 font-family=%22sans-serif%22 font-size=%2210%22%3EPlease try refreshing the page%3C/text%3E%3C/svg%3E';
        img.style.opacity = '1';
        img.classList.remove('opacity-0');
        img.classList.add('opacity-100');
        
        if (loader) {
          loader.style.display = 'none';
        }
      };
      
      // Timeout fallback (5 seconds)
      setTimeout(function() {
        if (loadCompleted) return;
        
        console.warn('[Memo Attachments] ⏰ Timeout for image', attachmentId, '- forcing completion');
        
        // Check if image actually loaded (natural dimensions exist)
        if (img.naturalWidth > 0 && img.naturalHeight > 0) {
          console.log('[Memo Attachments] Image actually loaded despite timeout');
          img.style.opacity = '1';
          img.classList.remove('opacity-0');
          img.classList.add('opacity-100');
        } else {
          console.error('[Memo Attachments] Image did not load after timeout');
          img.style.opacity = '0.5';
        }
        
        if (loader) {
          loader.style.display = 'none';
        }
        
        loadCompleted = true;
      }, 5000);
      
      // Actually load the image by setting src
      console.log('[Memo Attachments] Setting src for image', attachmentId);
      img.src = src;
      
      // Force reflow to ensure browser processes the src change
      void img.offsetWidth;
    });
    
    // Load iframes
    iframes.forEach((iframe) => {
      const src = iframe.getAttribute('data-lazy-src');
      const attachmentId = iframe.getAttribute('data-attachment-id');
      const loader = iframe.closest('.relative')?.querySelector('[data-attachment-loader]');
      
      if (!src) {
        console.error('[Memo Attachments] Iframe', attachmentId, 'has no data-lazy-src attribute');
        if (loader) loader.style.display = 'none';
        return;
      }
      
      if (iframe.src && iframe.src !== 'about:blank' && iframe.src !== window.location.href && iframe.src !== '') {
        console.log('[Memo Attachments] Iframe', attachmentId, 'already loaded, skipping');
        return;
      }
      
      console.log('[Memo Attachments] Loading iframe', attachmentId, 'from', src);
      
      if (loader) {
        loader.style.display = 'flex';
      }
      
      iframe.onload = function() {
        console.log('[Memo Attachments] ✅ Iframe', attachmentId, 'loaded');
        iframe.style.opacity = '1';
        iframe.classList.remove('opacity-0');
        iframe.classList.add('opacity-100');
        
        if (loader) {
          setTimeout(() => loader.style.display = 'none', 500);
        }
      };
      
      iframe.onerror = function(e) {
        console.error('[Memo Attachments] ❌ Iframe', attachmentId, 'failed to load:', e);
        iframe.style.opacity = '1';
        if (loader) loader.style.display = 'none';
      };
      
      iframe.src = src;
      
      // Timeout for iframes
      setTimeout(() => {
        if (iframe.style.opacity !== '1') {
          console.warn('[Memo Attachments] ⏰ Iframe', attachmentId, 'timeout');
          iframe.style.opacity = '1';
          if (loader) loader.style.display = 'none';
        }
      }, 8000);
    });
  }
  
  // Smart initialization with retries
  let initAttempts = 0;
  const maxAttempts = 5;
  
  function tryInit() {
    initAttempts++;
    const container = document.querySelector('[data-memo-attachments-grid]');
    
    if (container) {
      console.log('[Memo Attachments] Container found on attempt', initAttempts);
      initAttachmentLoaders();
    } else if (initAttempts < maxAttempts) {
      console.log('[Memo Attachments] Container not found, retry', initAttempts, 'of', maxAttempts);
      setTimeout(tryInit, 100 * initAttempts); // Exponential backoff
    } else {
      console.error('[Memo Attachments] Failed to find container after', maxAttempts, 'attempts');
    }
  }
  
  // Multiple initialization points to catch all scenarios
  
  // 1. Immediate execution (for full page loads)
  tryInit();
  
  // 2. DOMContentLoaded (backup)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      console.log('[Memo Attachments] DOMContentLoaded fired');
      initAttempts = 0; // Reset counter
      tryInit();
    });
  }
  
  // 3. Window load (everything including images from other sources)
  window.addEventListener('load', () => {
    console.log('[Memo Attachments] Window load fired');
    initAttempts = 0; // Reset counter
    tryInit();
  });
  
  // 4. SPA navigation
  document.addEventListener('spa:loaded', () => {
    console.log('[Memo Attachments] SPA navigation detected');
    initAttempts = 0; // Reset counter
    setTimeout(tryInit, 50); // Small delay for SPA rendering
  });
  
  // 5. MutationObserver to catch dynamic content
  const observer = new MutationObserver((mutations) => {
    const container = document.querySelector('[data-memo-attachments-grid]');
    if (container) {
      console.log('[Memo Attachments] Container detected via MutationObserver');
      observer.disconnect(); // Stop observing once found
      initAttachmentLoaders();
    }
  });
  
  observer.observe(document.body, {
    childList: true,
    subtree: true
  });
  
  // Disconnect observer after 10 seconds to prevent memory leaks
  setTimeout(() => observer.disconnect(), 10000);
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
