<?php
/**
 * Bulk Import – Upload CSV or manually enter multiple items at once
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'write');

$pdo = get_db_conn();
$pageTitle = 'Bulk Import Items';

$categories = $pdo->query("SELECT id, name FROM inv_categories WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name FROM inv_suppliers WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, name FROM inv_locations WHERE is_active = TRUE ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$importResult = null;

// Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_type']) && csrf_verify($_POST['csrf'] ?? '')) {
    if ($_POST['import_type'] === 'csv' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed.';
        } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
            $errors[] = 'Only CSV files are allowed.';
        } else {
            $importResult = processCsvImport($pdo, $file['tmp_name'], $file['name']);
        }
    } elseif ($_POST['import_type'] === 'manual') {
        $importResult = processManualBulk($pdo, $_POST);
    }

    if ($importResult && $importResult['imported'] > 0) {
        flash_success("Imported {$importResult['imported']} items successfully." .
            ($importResult['skipped'] > 0 ? " {$importResult['skipped']} skipped." : '') .
            ($importResult['errors'] > 0 ? " {$importResult['errors']} errors." : ''));
        header('Location: ' . BASE_URL . '/modules/inventory/inventory');
        exit;
    }
}

function processCsvImport(PDO $pdo, string $tmpPath, string $filename): array {
    $result = ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'error_details' => []];
    $uid = (int)($_SESSION['user']['id'] ?? 0);

    $handle = fopen($tmpPath, 'r');
    if (!$handle) { $result['error_details'][] = 'Could not read file.'; return $result; }

    $header = fgetcsv($handle);
    if (!$header) { fclose($handle); $result['error_details'][] = 'Empty file.'; return $result; }

    $header = array_map('strtolower', array_map('trim', $header));
    $required = ['name', 'sku'];
    foreach ($required as $r) {
        if (!in_array($r, $header)) {
            $result['error_details'][] = "Missing required column: $r";
            fclose($handle);
            return $result;
        }
    }

    $batchRef = 'BULK-' . date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
    $totalRows = 0;

    $pdo->beginTransaction();
    try {
        $row = 1;
        while ($data = fgetcsv($handle)) {
            $row++;
            $totalRows++;
            $record = array_combine($header, array_pad($data, count($header), ''));

            $name = trim($record['name'] ?? '');
            $sku = trim($record['sku'] ?? '');
            if ($name === '' || $sku === '') {
                $result['skipped']++;
                $result['error_details'][] = "Row $row: Missing name or SKU.";
                continue;
            }

            // Check SKU uniqueness
            $chk = $pdo->prepare("SELECT id FROM inv_items WHERE sku = :s");
            $chk->execute([':s' => $sku]);
            if ($chk->fetch()) {
                $result['skipped']++;
                $result['error_details'][] = "Row $row: Duplicate SKU '$sku'.";
                continue;
            }

            $categoryId = null;
            if (!empty($record['category'])) {
                $catName = trim($record['category']);
                $cs = $pdo->prepare("SELECT id FROM inv_categories WHERE LOWER(name) = LOWER(:n) AND is_active = TRUE");
                $cs->execute([':n' => $catName]);
                $categoryId = $cs->fetchColumn() ?: null;
            }

            $supplierId = null;
            if (!empty($record['supplier'])) {
                $supName = trim($record['supplier']);
                $ss = $pdo->prepare("SELECT id FROM inv_suppliers WHERE LOWER(name) = LOWER(:n) AND is_active = TRUE");
                $ss->execute([':n' => $supName]);
                $supplierId = $ss->fetchColumn() ?: null;
            }

            $locationId = null;
            if (!empty($record['location'])) {
                $locName = trim($record['location']);
                $ls = $pdo->prepare("SELECT id FROM inv_locations WHERE LOWER(name) = LOWER(:n) AND is_active = TRUE");
                $ls->execute([':n' => $locName]);
                $locationId = $ls->fetchColumn() ?: null;
            }

            $qty = max(0, (int)($record['qty_on_hand'] ?? $record['quantity'] ?? 0));
            $cost = max(0, (float)($record['cost_price'] ?? $record['cost'] ?? 0));
            $price = max(0, (float)($record['selling_price'] ?? $record['price'] ?? 0));
            $reorder = max(0, (int)($record['reorder_level'] ?? 0));
            $unit = trim($record['unit'] ?? 'pcs');
            $barcode = trim($record['barcode'] ?? '');
            $generic = trim($record['generic_name'] ?? '');
            $desc = trim($record['description'] ?? '');
            $expiry = (!empty($record['expiry_date']) && strtotime($record['expiry_date'])) ? date('Y-m-d', strtotime($record['expiry_date'])) : null;

            try {
                $ins = $pdo->prepare("INSERT INTO inv_items (name, sku, barcode, generic_name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level, expiry_date)
                    VALUES (:name, :sku, :barcode, :generic, :desc, :cat, :sup, :loc, :unit, :cost, :price, :qty, :reorder, :expiry)
                    RETURNING id");
                $ins->execute([
                    ':name' => $name, ':sku' => $sku, ':barcode' => $barcode, ':generic' => $generic,
                    ':desc' => $desc, ':cat' => $categoryId, ':sup' => $supplierId, ':loc' => $locationId,
                    ':unit' => $unit, ':cost' => $cost, ':price' => $price, ':qty' => $qty,
                    ':reorder' => $reorder, ':expiry' => $expiry
                ]);
                $newId = $ins->fetchColumn();

                if ($qty > 0) {
                    $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, reference_note, created_by)
                        VALUES (:id, 'initial', :qty, :note, :uid)")
                        ->execute([':id' => $newId, ':qty' => $qty, ':note' => "Bulk import ($batchRef)", ':uid' => $uid]);
                }

                $result['imported']++;
            } catch (\PDOException $e) {
                $result['errors']++;
                $result['error_details'][] = "Row $row: " . $e->getMessage();
            }
        }

        // Record import batch
        $pdo->prepare("INSERT INTO inv_import_batches (batch_ref, filename, total_rows, imported_count, skipped_count, error_count, errors, status, created_by)
            VALUES (:ref, :fname, :total, :imp, :skip, :err, :errs, :status, :uid)")
            ->execute([
                ':ref' => $batchRef, ':fname' => $filename, ':total' => $totalRows,
                ':imp' => $result['imported'], ':skip' => $result['skipped'], ':err' => $result['errors'],
                ':errs' => json_encode($result['error_details']), ':status' => 'completed', ':uid' => $uid
            ]);

        $pdo->commit();

        action_log('inventory', 'bulk_import_csv', 'success', [
            'batch_ref' => $batchRef, 'filename' => $filename,
            'imported' => $result['imported'], 'skipped' => $result['skipped'], 'errors' => $result['errors']
        ]);

    } catch (\Exception $e) {
        $pdo->rollBack();
        $result['error_details'][] = 'Transaction failed: ' . $e->getMessage();
    }

    fclose($handle);
    return $result;
}

function processManualBulk(PDO $pdo, array $post): array {
    $result = ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'error_details' => []];
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    $batchRef = 'MANUAL-' . date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);

    $names = $post['item_name'] ?? [];
    $skus = $post['item_sku'] ?? [];
    $cats = $post['item_category'] ?? [];
    $sups = $post['item_supplier'] ?? [];
    $locs = $post['item_location'] ?? [];
    $units = $post['item_unit'] ?? [];
    $costs = $post['item_cost'] ?? [];
    $prices = $post['item_price'] ?? [];
    $qtys = $post['item_qty'] ?? [];
    $reorders = $post['item_reorder'] ?? [];

    if (empty($names)) {
        $result['error_details'][] = 'No items provided.';
        return $result;
    }

    $pdo->beginTransaction();
    try {
        foreach ($names as $i => $rawName) {
            $name = trim($rawName);
            $sku = trim($skus[$i] ?? '');
            if ($name === '' || $sku === '') {
                $result['skipped']++;
                $result['error_details'][] = "Row " . ($i+1) . ": Missing name or SKU.";
                continue;
            }

            // Check SKU uniqueness
            $chk = $pdo->prepare("SELECT id FROM inv_items WHERE sku = :s");
            $chk->execute([':s' => $sku]);
            if ($chk->fetch()) {
                $result['skipped']++;
                $result['error_details'][] = "Row " . ($i+1) . ": Duplicate SKU '$sku'.";
                continue;
            }

            $catId = ((int)($cats[$i] ?? 0)) ?: null;
            $supId = ((int)($sups[$i] ?? 0)) ?: null;
            $locId = ((int)($locs[$i] ?? 0)) ?: null;
            $unit = trim($units[$i] ?? 'pcs');
            $cost = max(0, (float)($costs[$i] ?? 0));
            $price = max(0, (float)($prices[$i] ?? 0));
            $qty = max(0, (int)($qtys[$i] ?? 0));
            $reorder = max(0, (int)($reorders[$i] ?? 0));

            try {
                $ins = $pdo->prepare("INSERT INTO inv_items (name, sku, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level)
                    VALUES (:name, :sku, :cat, :sup, :loc, :unit, :cost, :price, :qty, :reorder)
                    RETURNING id");
                $ins->execute([
                    ':name' => $name, ':sku' => $sku, ':cat' => $catId, ':sup' => $supId, ':loc' => $locId,
                    ':unit' => $unit, ':cost' => $cost, ':price' => $price, ':qty' => $qty, ':reorder' => $reorder
                ]);
                $newId = $ins->fetchColumn();

                if ($qty > 0) {
                    $pdo->prepare("INSERT INTO inv_stock_movements (item_id, movement_type, quantity, reference_note, created_by)
                        VALUES (:id, 'initial', :qty, :note, :uid)")
                        ->execute([':id' => $newId, ':qty' => $qty, ':note' => "Manual bulk ($batchRef)", ':uid' => $uid]);
                }
                $result['imported']++;
            } catch (\PDOException $e) {
                $result['errors']++;
                $result['error_details'][] = "Row " . ($i+1) . ": " . $e->getMessage();
            }
        }

        $pdo->prepare("INSERT INTO inv_import_batches (batch_ref, filename, total_rows, imported_count, skipped_count, error_count, errors, status, created_by)
            VALUES (:ref, 'manual_entry', :total, :imp, :skip, :err, :errs, 'completed', :uid)")
            ->execute([
                ':ref' => $batchRef, ':total' => count($names),
                ':imp' => $result['imported'], ':skip' => $result['skipped'], ':err' => $result['errors'],
                ':errs' => json_encode($result['error_details']), ':uid' => $uid
            ]);

        $pdo->commit();

        action_log('inventory', 'bulk_import_manual', 'success', [
            'batch_ref' => $batchRef, 'imported' => $result['imported'], 'skipped' => $result['skipped']
        ]);

    } catch (\Exception $e) {
        $pdo->rollBack();
        $result['error_details'][] = 'Transaction failed: ' . $e->getMessage();
    }

    return $result;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <!-- Header -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Bulk Import Items</h1>
      <p class="text-sm text-gray-500">Add multiple items at once via CSV upload or manual entry</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/inventory/inventory" class="btn btn-outline text-sm">
      <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
      Back to Inventory
    </a>
  </div>

  <?php if (!empty($errors) || ($importResult && ($importResult['errors'] > 0 || $importResult['skipped'] > 0))): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
    <h3 class="font-medium text-amber-800 mb-2">Import Results</h3>
    <?php if ($importResult): ?>
      <p class="text-sm text-amber-700">Imported: <?= $importResult['imported'] ?> | Skipped: <?= $importResult['skipped'] ?> | Errors: <?= $importResult['errors'] ?></p>
    <?php endif; ?>
    <?php $allErrors = array_merge($errors, $importResult['error_details'] ?? []); ?>
    <?php if (!empty($allErrors)): ?>
      <ul class="mt-2 text-xs text-amber-600 space-y-1 max-h-40 overflow-y-auto">
        <?php foreach ($allErrors as $e): ?>
          <li>• <?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="bg-white rounded-xl border overflow-hidden" x-data="{ tab: 'csv' }">
    <div class="flex border-b">
      <button @click="tab = 'csv'" :class="tab === 'csv' ? 'border-b-2 border-blue-600 text-blue-600 bg-blue-50' : 'text-gray-500 hover:text-gray-700'"
        class="flex-1 px-4 py-3 text-sm font-medium text-center transition">
        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
        CSV Upload
      </button>
      <button @click="tab = 'manual'" :class="tab === 'manual' ? 'border-b-2 border-blue-600 text-blue-600 bg-blue-50' : 'text-gray-500 hover:text-gray-700'"
        class="flex-1 px-4 py-3 text-sm font-medium text-center transition">
        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        Manual Entry
      </button>
    </div>

    <!-- CSV upload -->
    <div x-show="tab === 'csv'" class="p-6 space-y-4">
      <form method="POST" enctype="multipart/form-data" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
        <input type="hidden" name="import_type" value="csv" />

        <div class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center" id="csvDropZone">
          <svg class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
          <p class="text-gray-600 mb-2">Drag & drop your CSV file here, or</p>
          <label class="btn btn-outline text-sm cursor-pointer">
            Choose File
            <input type="file" name="csv_file" accept=".csv" class="hidden" id="csvFileInput" />
          </label>
          <p class="text-xs text-gray-400 mt-2" id="csvFileName">No file selected</p>
        </div>

        <div class="bg-gray-50 rounded-lg p-4">
          <h3 class="text-sm font-medium text-gray-700 mb-2">CSV Format Requirements</h3>
          <p class="text-xs text-gray-500 mb-2">Required columns (case-insensitive): <code class="bg-gray-200 px-1 rounded">name</code>, <code class="bg-gray-200 px-1 rounded">sku</code></p>
          <p class="text-xs text-gray-500">Optional columns: <code class="bg-gray-200 px-1 rounded">barcode</code>, <code class="bg-gray-200 px-1 rounded">generic_name</code>, <code class="bg-gray-200 px-1 rounded">description</code>, <code class="bg-gray-200 px-1 rounded">category</code>, <code class="bg-gray-200 px-1 rounded">supplier</code>, <code class="bg-gray-200 px-1 rounded">location</code>, <code class="bg-gray-200 px-1 rounded">unit</code>, <code class="bg-gray-200 px-1 rounded">cost_price</code>, <code class="bg-gray-200 px-1 rounded">selling_price</code>, <code class="bg-gray-200 px-1 rounded">qty_on_hand</code>, <code class="bg-gray-200 px-1 rounded">reorder_level</code>, <code class="bg-gray-200 px-1 rounded">expiry_date</code></p>
          <div class="mt-3">
            <a href="<?= BASE_URL ?>/modules/inventory/bulk_import_template" class="text-xs text-blue-600 hover:underline">Download CSV Template</a>
          </div>
        </div>

        <button type="submit" class="btn btn-primary text-sm w-full md:w-auto">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
          Upload &amp; Import
        </button>
      </form>
    </div>

    <!-- Manual multi-row entry -->
    <div x-show="tab === 'manual'" class="p-6 space-y-4" x-cloak>
      <form method="POST" id="manualBulkForm" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
        <input type="hidden" name="import_type" value="manual" />

        <div class="overflow-x-auto">
          <table class="w-full text-sm" id="manualTable">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
              <tr>
                <th class="px-2 py-2 text-left">#</th>
                <th class="px-2 py-2 text-left">Name *</th>
                <th class="px-2 py-2 text-left">SKU *</th>
                <th class="px-2 py-2 text-left">Category</th>
                <th class="px-2 py-2 text-left">Supplier</th>
                <th class="px-2 py-2 text-left">Location</th>
                <th class="px-2 py-2 text-left">Unit</th>
                <th class="px-2 py-2 text-right">Cost</th>
                <th class="px-2 py-2 text-right">Price</th>
                <th class="px-2 py-2 text-right">Qty</th>
                <th class="px-2 py-2 text-right">Reorder</th>
                <th class="px-2 py-2"></th>
              </tr>
            </thead>
            <tbody id="manualRows">
              <!-- rows inserted by JS -->
            </tbody>
          </table>
        </div>

        <div class="flex gap-2">
          <button type="button" onclick="addManualRow()" class="btn btn-outline text-sm">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add Row
          </button>
          <button type="button" onclick="addManualRows(5)" class="btn btn-outline text-sm">+ 5 Rows</button>
          <button type="button" onclick="addManualRows(10)" class="btn btn-outline text-sm">+ 10 Rows</button>
        </div>

        <button type="submit" class="btn btn-primary text-sm w-full md:w-auto">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
          Import All Items
        </button>
      </form>
    </div>
  </div>

  <!-- Import History -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-3 border-b bg-gray-50">
      <h2 class="text-sm font-semibold text-gray-700">Import History</h2>
    </div>
    <?php
      $histQ = $pdo->query("SELECT ib.*, u.name AS creator_name FROM inv_import_batches ib LEFT JOIN users u ON u.id = ib.created_by ORDER BY ib.created_at DESC LIMIT 10");
      $history = $histQ ? $histQ->fetchAll(PDO::FETCH_ASSOC) : [];
    ?>
    <?php if (empty($history)): ?>
      <div class="px-4 py-6 text-center text-gray-400 text-sm">No import history yet.</div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th class="px-3 py-2 text-left">Batch</th>
              <th class="px-3 py-2 text-left">File</th>
              <th class="px-3 py-2 text-right">Total</th>
              <th class="px-3 py-2 text-right">Imported</th>
              <th class="px-3 py-2 text-right">Skipped</th>
              <th class="px-3 py-2 text-right">Errors</th>
              <th class="px-3 py-2 text-left hidden md:table-cell">By</th>
              <th class="px-3 py-2 text-left">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <?php foreach ($history as $h): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-2 font-mono text-xs"><?= htmlspecialchars($h['batch_ref']) ?></td>
              <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($h['filename'] ?? '-') ?></td>
              <td class="px-3 py-2 text-right"><?= $h['total_rows'] ?></td>
              <td class="px-3 py-2 text-right text-emerald-600 font-medium"><?= $h['imported_count'] ?></td>
              <td class="px-3 py-2 text-right text-amber-600"><?= $h['skipped_count'] ?></td>
              <td class="px-3 py-2 text-right text-red-600"><?= $h['error_count'] ?></td>
              <td class="px-3 py-2 text-gray-500 hidden md:table-cell"><?= htmlspecialchars($h['creator_name'] ?? 'System') ?></td>
              <td class="px-3 py-2 text-gray-500 text-xs"><?= date('M d, Y H:i', strtotime($h['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
// CSV drag-drop
const dropZone = document.getElementById('csvDropZone');
const fileInput = document.getElementById('csvFileInput');
const fileName = document.getElementById('csvFileName');

if (dropZone) {
  ['dragenter','dragover'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('border-blue-400','bg-blue-50'); }));
  ['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('border-blue-400','bg-blue-50'); }));
  dropZone.addEventListener('drop', e => {
    const files = e.dataTransfer.files;
    if (files.length > 0) { fileInput.files = files; fileName.textContent = files[0].name; }
  });
}
if (fileInput) {
  fileInput.addEventListener('change', () => { fileName.textContent = fileInput.files[0]?.name || 'No file selected'; });
}

// Manual rows
const categoriesJson = <?= json_encode($categories) ?>;
const suppliersJson = <?= json_encode($suppliers) ?>;
const locationsJson = <?= json_encode($locations) ?>;
let rowCount = 0;

function buildSelect(name, options, placeholder) {
  let html = `<select name="${name}" class="input-text text-xs min-w-[100px]"><option value="">${placeholder}</option>`;
  options.forEach(o => { html += `<option value="${o.id}">${o.name}</option>`; });
  html += '</select>';
  return html;
}

function addManualRow() {
  rowCount++;
  const tbody = document.getElementById('manualRows');
  const tr = document.createElement('tr');
  tr.className = 'hover:bg-gray-50';
  tr.innerHTML = `
    <td class="px-2 py-1 text-gray-400 text-xs">${rowCount}</td>
    <td class="px-2 py-1"><input type="text" name="item_name[]" class="input-text text-xs min-w-[120px]" required /></td>
    <td class="px-2 py-1"><input type="text" name="item_sku[]" class="input-text text-xs min-w-[80px]" required /></td>
    <td class="px-2 py-1">${buildSelect('item_category[]', categoriesJson, 'Select')}</td>
    <td class="px-2 py-1">${buildSelect('item_supplier[]', suppliersJson, 'Select')}</td>
    <td class="px-2 py-1">${buildSelect('item_location[]', locationsJson, 'Select')}</td>
    <td class="px-2 py-1"><input type="text" name="item_unit[]" value="pcs" class="input-text text-xs w-16" /></td>
    <td class="px-2 py-1"><input type="number" name="item_cost[]" value="0" step="0.01" min="0" class="input-text text-xs w-20 text-right" /></td>
    <td class="px-2 py-1"><input type="number" name="item_price[]" value="0" step="0.01" min="0" class="input-text text-xs w-20 text-right" /></td>
    <td class="px-2 py-1"><input type="number" name="item_qty[]" value="0" min="0" class="input-text text-xs w-16 text-right" /></td>
    <td class="px-2 py-1"><input type="number" name="item_reorder[]" value="0" min="0" class="input-text text-xs w-16 text-right" /></td>
    <td class="px-2 py-1"><button type="button" onclick="this.closest('tr').remove()" class="text-red-400 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></td>
  `;
  tbody.appendChild(tr);
}

function addManualRows(n) { for (let i = 0; i < n; i++) addManualRow(); }

// Start with 3 rows
addManualRows(3);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
