<?php
/**
 * Inventory - Transaction Detail View with Receipt Print
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'read');

$pdo = get_db_conn();
$id = (int)($_GET['id'] ?? 0);
$pageTitle = 'Transaction Detail';

$txn = $pdo->prepare("SELECT t.*, u.full_name AS cashier_name, vu.full_name AS voided_by_name
    FROM inv_transactions t
    LEFT JOIN users u ON u.id = t.created_by
    LEFT JOIN users vu ON vu.id = t.voided_by
    WHERE t.id = :id");
$txn->execute([':id' => $id]);
$txn = $txn->fetch(PDO::FETCH_ASSOC);

if (!$txn) {
    flash_error('Transaction not found.');
    header('Location: ' . BASE_URL . '/modules/inventory/transactions');
    exit;
}

$items = $pdo->prepare("SELECT * FROM inv_transaction_items WHERE txn_id = :id ORDER BY id");
$items->execute([':id' => $id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$receiptSettings = $pdo->query("SELECT * FROM inv_receipt_settings ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">
  <div class="flex items-center gap-3">
    <a href="<?= BASE_URL ?>/modules/inventory/transactions" class="p-2 rounded hover:bg-gray-100 text-gray-500">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div class="flex-1">
      <h1 class="text-xl font-semibold text-gray-900"><?= htmlspecialchars($txn['txn_number']) ?></h1>
      <p class="text-sm text-gray-500"><?= date('M d, Y h:i A', strtotime($txn['created_at'])) ?></p>
    </div>
    <div>
      <?php
        $statusBg = ['completed'=>'bg-emerald-100 text-emerald-700','voided'=>'bg-red-100 text-red-700','refunded'=>'bg-amber-100 text-amber-700'];
        $sb = $statusBg[$txn['status']] ?? 'bg-gray-100 text-gray-700';
      ?>
      <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= $sb ?>"><?= ucfirst($txn['status']) ?></span>
    </div>
  </div>

  <!-- Transaction Info -->
  <div class="bg-white rounded-xl border p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
    <div><span class="text-gray-500">Cashier:</span><div class="font-medium"><?= htmlspecialchars($txn['cashier_name'] ?? '-') ?></div></div>
    <div><span class="text-gray-500">Customer:</span><div class="font-medium"><?= htmlspecialchars($txn['customer_name'] ?: '-') ?></div></div>
    <div><span class="text-gray-500">Payment:</span><div class="font-medium capitalize"><?= htmlspecialchars($txn['payment_method']) ?></div></div>
    <div><span class="text-gray-500">Type:</span><div class="font-medium capitalize"><?= htmlspecialchars($txn['txn_type']) ?></div></div>
  </div>

  <?php if ($txn['status'] === 'voided'): ?>
  <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
    <strong>Voided</strong> by <?= htmlspecialchars($txn['voided_by_name'] ?? 'Unknown') ?>
    <?php if ($txn['voided_at']): ?> on <?= date('M d, Y h:i A', strtotime($txn['voided_at'])) ?><?php endif; ?>
    <?php if ($txn['void_reason']): ?> -- Reason: <?= htmlspecialchars($txn['void_reason']) ?><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Line Items -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-3 border-b font-semibold text-gray-900">Items</div>
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
        <tr>
          <th class="px-4 py-2 text-left">Item</th>
          <th class="px-4 py-2 text-center">Qty</th>
          <th class="px-4 py-2 text-right">Unit Price</th>
          <th class="px-4 py-2 text-right">Discount</th>
          <th class="px-4 py-2 text-right">Line Total</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($items as $li): ?>
        <tr>
          <td class="px-4 py-2"><?= htmlspecialchars($li['item_name']) ?></td>
          <td class="px-4 py-2 text-center"><?= $li['quantity'] ?></td>
          <td class="px-4 py-2 text-right">P<?= number_format((float)$li['unit_price'], 2) ?></td>
          <td class="px-4 py-2 text-right">P<?= number_format((float)$li['discount'], 2) ?></td>
          <td class="px-4 py-2 text-right font-medium">P<?= number_format((float)$li['line_total'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="bg-gray-50">
        <tr><td colspan="4" class="px-4 py-2 text-right text-gray-500">Subtotal</td><td class="px-4 py-2 text-right font-medium">P<?= number_format((float)$txn['subtotal'], 2) ?></td></tr>
        <?php if ((float)$txn['discount_amount'] > 0): ?>
        <tr><td colspan="4" class="px-4 py-2 text-right text-gray-500">Discount</td><td class="px-4 py-2 text-right text-red-600">-P<?= number_format((float)$txn['discount_amount'], 2) ?></td></tr>
        <?php endif; ?>
        <tr class="font-bold"><td colspan="4" class="px-4 py-2 text-right">Total</td><td class="px-4 py-2 text-right">P<?= number_format((float)$txn['total_amount'], 2) ?></td></tr>
        <tr><td colspan="4" class="px-4 py-2 text-right text-gray-500">Tendered</td><td class="px-4 py-2 text-right">P<?= number_format((float)$txn['amount_tendered'], 2) ?></td></tr>
        <?php if ((float)$txn['change_amount'] > 0): ?>
        <tr><td colspan="4" class="px-4 py-2 text-right text-gray-500">Change</td><td class="px-4 py-2 text-right text-emerald-600">P<?= number_format((float)$txn['change_amount'], 2) ?></td></tr>
        <?php endif; ?>
      </tfoot>
    </table>
  </div>

  <!-- Print Receipt -->
  <div class="flex gap-3">
    <button type="button" onclick="printReceipt()" class="btn btn-primary">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
      Print Receipt
    </button>
    <a href="<?= BASE_URL ?>/modules/inventory/transactions" class="btn btn-outline">Back to List</a>
  </div>
</div>

<script>
function printReceipt() {
  const w = window.open('', '_blank', 'width=620,height=880');

  let itemsHtml = '';
  <?php foreach ($items as $idx => $li): ?>
    itemsHtml += `<tr>
      <td style="padding:10px 14px;border-bottom:1px solid #e8e8e8;font-size:13px;color:#333"><?= addslashes(htmlspecialchars($li['item_name'])) ?></td>
      <td style="padding:10px 14px;border-bottom:1px solid #e8e8e8;font-size:13px;color:#555"><?= addslashes(htmlspecialchars($li['item_description'] ?? '')) ?></td>
      <td style="padding:10px 14px;border-bottom:1px solid #e8e8e8;text-align:center;font-size:13px;color:#333"><?= (int)$li['quantity'] ?></td>
      <td style="padding:10px 14px;border-bottom:1px solid #e8e8e8;text-align:right;font-size:13px;color:#333"><?= number_format((float)$li['unit_price'],2) ?></td>
      <td style="padding:10px 14px;border-bottom:1px solid #e8e8e8;text-align:right;font-size:13px;font-weight:600;color:#111">₱<?= number_format((float)$li['line_total'],2) ?></td>
    </tr>`;
  <?php endforeach; ?>

  const companyName = `<?= addslashes(htmlspecialchars($receiptSettings['company_name'] ?? COMPANY_NAME)) ?>`;
  const companyAddr = `<?= addslashes(htmlspecialchars($receiptSettings['address'] ?? '')) ?>`;
  const companyPhone = `<?= addslashes(htmlspecialchars($receiptSettings['phone'] ?? '')) ?>`;
  const taxId = `<?= addslashes(htmlspecialchars($receiptSettings['tax_id'] ?? '')) ?>`;
  const headerText = `<?= addslashes(htmlspecialchars($receiptSettings['header_text'] ?? '')) ?>`;
  const footerText = `<?= addslashes(htmlspecialchars($receiptSettings['footer_text'] ?? 'Thank you for your business!')) ?>`;

  w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt <?= htmlspecialchars($txn['txn_number']) ?></title>
<style>
  @page { size: A5 portrait; margin: 0; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
    width: 148mm; min-height: 210mm;
    padding: 20mm 16mm 16mm 16mm;
    color: #333; background: #fff;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }

  /* Header / Company */
  .company-block { margin-bottom: 22px; }
  .company-name { font-size: 22px; font-weight: 700; color: #1a1a1a; margin-bottom: 2px; }
  .company-detail { font-size: 11px; color: #666; line-height: 1.6; }

  /* Receipt badge */
  .receipt-badge-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
  .customer-block {}
  .customer-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 4px; }
  .customer-name { font-size: 15px; font-weight: 700; color: #1a1a1a; }
  .customer-extra { font-size: 11px; color: #666; margin-top: 2px; }

  .receipt-badge { text-align: right; }
  .receipt-tag { display: inline-block; background: linear-gradient(135deg, #7cb342, #558b2f); color: #fff; font-size: 16px; font-weight: 700; padding: 8px 18px; border-radius: 4px; letter-spacing: 0.5px; }
  .receipt-date { font-size: 11px; color: #666; margin-top: 6px; background: #f5f5f0; padding: 4px 12px; border-radius: 3px; display: inline-block; }

  /* Items table */
  .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  .items-table thead th {
    background: linear-gradient(135deg, #7cb342, #558b2f);
    color: #fff; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    padding: 10px 14px; text-align: left;
  }
  .items-table thead th:nth-child(3) { text-align: center; }
  .items-table thead th:nth-child(4),
  .items-table thead th:nth-child(5) { text-align: right; }

  /* Totals */
  .totals-row { display: flex; justify-content: flex-end; margin-bottom: 0; }
  .totals-section { width: 260px; }
  .totals-section .line { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; border-bottom: 1px solid #eee; }
  .totals-section .line .label { color: #666; }
  .totals-section .line .value { font-weight: 600; color: #222; }
  .totals-section .line.total-line { border-bottom: none; border-top: 2px solid #333; padding-top: 10px; margin-top: 4px; }
  .totals-section .line.total-line .label { font-weight: 700; color: #111; font-size: 14px; }
  .totals-section .line.total-line .value { font-weight: 700; color: #111; font-size: 16px; }

  /* Payment block */
  .payment-block { margin-top: 14px; padding: 12px 16px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef; }
  .payment-block .line { display: flex; justify-content: space-between; font-size: 12px; padding: 3px 0; }
  .payment-block .line .label { color: #666; }
  .payment-block .line .value { font-weight: 600; color: #333; }

  /* Footer */
  .footer-section { margin-top: 24px; display: flex; justify-content: space-between; align-items: flex-end; }
  .footer-thanks { font-size: 12px; color: #888; font-style: italic; }
  .footer-meta { text-align: right; font-size: 10px; color: #aaa; line-height: 1.5; }

  /* Status watermark for voided/refunded */
  .status-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-30deg); font-size: 72px; font-weight: 900; color: rgba(220,38,38,0.12); text-transform: uppercase; pointer-events: none; z-index: 0; }

  @media print {
    body { padding: 16mm 14mm 14mm 14mm; }
  }
</style></head><body>

  <?php if ($txn['status'] !== 'completed'): ?>
  <div class="status-watermark"><?= strtoupper($txn['status']) ?></div>
  <?php endif; ?>

  <!-- Company Header -->
  <div class="company-block">
    <div class="company-name">${companyName}</div>
    <div class="company-detail">
      ${companyAddr ? companyAddr + '<br>' : ''}
      ${companyPhone ? companyPhone : ''}${companyPhone && taxId ? ' | ' : ''}${taxId ? 'TIN: ' + taxId : ''}
    </div>
    ${headerText ? '<div class="company-detail" style="margin-top:4px">' + headerText + '</div>' : ''}
  </div>

  <!-- Receipt Badge + Customer -->
  <div class="receipt-badge-row">
    <div class="customer-block">
      <div class="customer-label">Customer:</div>
      <div class="customer-name"><?= htmlspecialchars($txn['customer_name'] ?: 'Walk-in Customer') ?></div>
      <?= $txn['customer_dept'] ? '<div class="customer-extra">'.htmlspecialchars($txn['customer_dept']).'</div>' : '' ?>
    </div>
    <div class="receipt-badge">
      <div class="receipt-tag">Receipt <?= htmlspecialchars($txn['txn_number']) ?></div>
      <div class="receipt-date">Transaction Date: <?= date('F d, Y', strtotime($txn['created_at'])) ?></div>
    </div>
  </div>

  <!-- Items Table -->
  <table class="items-table">
    <thead>
      <tr>
        <th>Product / Item</th>
        <th>Description</th>
        <th>Qty</th>
        <th>Price</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>${itemsHtml}</tbody>
  </table>

  <!-- Footer + Totals side by side -->
  <div style="display:flex;justify-content:space-between;align-items:flex-start">
    <div style="flex:1">
      <div class="footer-thanks">${footerText}</div>
      <div class="payment-block" style="max-width:220px;margin-top:12px">
        <div style="font-size:11px;font-weight:700;color:#444;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px">Payment Details</div>
        <div class="line"><span class="label">Method:</span><span class="value" style="text-transform:capitalize"><?= htmlspecialchars($txn['payment_method']) ?></span></div>
        <?= $txn['reference_number'] ? '<div class="line"><span class="label">Ref #:</span><span class="value">'.htmlspecialchars($txn['reference_number']).'</span></div>' : '' ?>
        <div class="line"><span class="label">Tendered:</span><span class="value">₱<?= number_format((float)$txn['amount_tendered'],2) ?></span></div>
        <?= (float)$txn['change_amount'] > 0 ? '<div class="line"><span class="label">Change:</span><span class="value" style="color:#2e7d32">₱'.number_format((float)$txn['change_amount'],2).'</span></div>' : '' ?>
      </div>
    </div>

    <!-- Receipt Totals -->
    <div class="totals-section">
      <div style="font-size:16px;font-weight:700;color:#222;margin-bottom:10px">Receipt for Payment</div>
      <div class="line"><span class="label">Subtotal</span><span class="value">₱<?= number_format((float)$txn['subtotal'],2) ?></span></div>
      <?php if ((float)($txn['tax_amount'] ?? 0) > 0): ?>
      <div class="line"><span class="label">Tax</span><span class="value">₱<?= number_format((float)$txn['tax_amount'],2) ?></span></div>
      <?php endif; ?>
      <?php if ((float)$txn['discount_amount'] > 0): ?>
      <div class="line"><span class="label">Discount<?= $txn['discount_type'] ? ' ('.htmlspecialchars($txn['discount_type']).')' : '' ?></span><span class="value" style="color:#c62828">-₱<?= number_format((float)$txn['discount_amount'],2) ?></span></div>
      <?php endif; ?>
      <div class="line total-line"><span class="label">Total</span><span class="value">₱<?= number_format((float)$txn['total_amount'],2) ?></span></div>
    </div>
  </div>

  <!-- Bottom Meta -->
  <div class="footer-section" style="margin-top:20px;padding-top:12px;border-top:1px solid #eee">
    <div class="footer-meta">Cashier: <?= htmlspecialchars($txn['cashier_name'] ?? '-') ?></div>
    <div class="footer-meta">Printed: ${new Date().toLocaleDateString('en-US', {month:'long',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'})}</div>
  </div>

</body></html>`);
  w.document.close();
  setTimeout(() => { w.focus(); w.print(); }, 400);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
