<?php
/**
 * Inventory - Receipt Settings
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'manage');

$pdo = get_db_conn();
$pageTitle = 'Receipt Settings';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_error('Invalid security token. Please try again.');
        header('Location: ' . BASE_URL . '/modules/inventory/receipt_settings');
        exit;
    }

    $companyName = trim($_POST['company_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $taxId = trim($_POST['tax_id'] ?? '');
    $headerText = trim($_POST['header_text'] ?? '');
    $footerText = trim($_POST['footer_text'] ?? '');
    $showLogo = isset($_POST['show_logo']) ? 1 : 0;

    // Upsert into inv_receipt_settings
    $existing = $pdo->query("SELECT id FROM inv_receipt_settings LIMIT 1")->fetchColumn();
    if ($existing) {
        $st = $pdo->prepare("UPDATE inv_receipt_settings SET company_name = :cn, address = :addr, phone = :ph, tax_id = :tax, header_text = :ht, footer_text = :ft, show_logo = :sl, updated_at = NOW() WHERE id = :id");
        $st->execute([
            ':cn' => $companyName, ':addr' => $address, ':ph' => $phone,
            ':tax' => $taxId, ':ht' => $headerText, ':ft' => $footerText,
            ':sl' => $showLogo, ':id' => $existing
        ]);
    } else {
        $st = $pdo->prepare("INSERT INTO inv_receipt_settings (company_name, address, phone, tax_id, header_text, footer_text, show_logo) VALUES (:cn, :addr, :ph, :tax, :ht, :ft, :sl)");
        $st->execute([
            ':cn' => $companyName, ':addr' => $address, ':ph' => $phone,
            ':tax' => $taxId, ':ht' => $headerText, ':ft' => $footerText,
            ':sl' => $showLogo
        ]);
    }

    action_log('inventory', 'update_receipt_settings', 'success', []);
    flash_success('Receipt settings updated.');
    header('Location: ' . BASE_URL . '/modules/inventory/receipt_settings');
    exit;
}

// Load current settings
$settings = $pdo->query("SELECT * FROM inv_receipt_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    $settings = [
        'company_name' => '', 'address' => '', 'phone' => '', 'tax_id' => '',
        'header_text' => '', 'footer_text' => '', 'show_logo' => true
    ];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-2xl mx-auto space-y-4">
  <div>
    <h1 class="text-xl font-semibold text-gray-900">Receipt Settings</h1>
    <p class="text-sm text-gray-500">Configure how receipts appear when printed from the POS</p>
  </div>

  <form method="POST" class="bg-white rounded-xl border p-6 space-y-5">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Company / Store Name</label>
      <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" class="input-text w-full" placeholder="Your Company Name" />
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
      <textarea name="address" rows="2" class="input-text w-full" placeholder="123 Main St, Manila"><?= htmlspecialchars($settings['address'] ?? '') ?></textarea>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
        <input type="text" name="phone" value="<?= htmlspecialchars($settings['phone'] ?? '') ?>" class="input-text w-full" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Tax ID / TIN</label>
        <input type="text" name="tax_id" value="<?= htmlspecialchars($settings['tax_id'] ?? '') ?>" class="input-text w-full" />
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Header Text</label>
      <textarea name="header_text" rows="2" class="input-text w-full" placeholder="Extra text printed at the top of the receipt"><?= htmlspecialchars($settings['header_text'] ?? '') ?></textarea>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Footer Text</label>
      <textarea name="footer_text" rows="2" class="input-text w-full" placeholder="Thank you for your purchase!"><?= htmlspecialchars($settings['footer_text'] ?? '') ?></textarea>
    </div>

    <label class="flex items-center gap-2">
      <input type="checkbox" name="show_logo" value="1" <?= !empty($settings['show_logo']) ? 'checked' : '' ?> class="rounded border-gray-300" />
      <span class="text-sm text-gray-700">Show logo on receipt (if available)</span>
    </label>

    <div class="pt-4 border-t flex items-center justify-between">
      <button type="button" onclick="previewReceipt()" class="btn btn-outline">Preview Receipt</button>
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </form>

  <!-- Preview Section -->
  <div id="receiptPreview" class="hidden bg-white rounded-xl border p-6">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Receipt Preview</h3>
    <div class="mx-auto border-2 border-dashed border-gray-300 p-4 font-mono text-xs leading-relaxed" style="max-width: 300px;">
      <div class="text-center">
        <div id="pvName" class="font-bold text-sm"></div>
        <div id="pvAddr"></div>
        <div id="pvPhone"></div>
        <div id="pvTax"></div>
        <div id="pvHeader" class="mt-1"></div>
        <div class="mt-1 border-t border-dashed border-gray-400 pt-1">SALES RECEIPT</div>
        <div>Date: <?= date('M d, Y h:i A') ?></div>
        <div>Txn: TXN-SAMPLE</div>
      </div>
      <div class="my-2 border-t border-dashed border-gray-400"></div>
      <div class="flex justify-between"><span>Sample Item x2</span><span>P200.00</span></div>
      <div class="flex justify-between"><span>Another Item x1</span><span>P150.00</span></div>
      <div class="my-2 border-t border-dashed border-gray-400"></div>
      <div class="flex justify-between font-bold"><span>TOTAL</span><span>P350.00</span></div>
      <div class="flex justify-between"><span>Cash Tendered</span><span>P500.00</span></div>
      <div class="flex justify-between font-bold"><span>Change</span><span>P150.00</span></div>
      <div class="my-2 border-t border-dashed border-gray-400"></div>
      <div class="text-center" id="pvFooter">Thank you!</div>
    </div>
  </div>
</div>

<script>
function previewReceipt() {
  const pvEl = document.getElementById('receiptPreview');
  pvEl.classList.remove('hidden');
  document.getElementById('pvName').textContent = document.querySelector('[name=company_name]').value || 'Store Name';
  document.getElementById('pvAddr').textContent = document.querySelector('[name=address]').value || '';
  document.getElementById('pvPhone').textContent = document.querySelector('[name=phone]').value || '';
  const taxVal = document.querySelector('[name=tax_id]').value;
  document.getElementById('pvTax').textContent = taxVal ? 'TIN: ' + taxVal : '';
  document.getElementById('pvHeader').textContent = document.querySelector('[name=header_text]').value || '';
  document.getElementById('pvFooter').textContent = document.querySelector('[name=footer_text]').value || 'Thank you!';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
