<?php
/**
 * Inventory POS - Point of Sale Interface
 * Mobile-first POS terminal redesigned per wireframe
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'pos_transactions', 'write');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$userName = htmlspecialchars($_SESSION['user']['name'] ?? 'Cashier');
$canRefund = user_has_access($uid, 'inventory', 'pos_transactions', 'manage');
$pageTitle = 'Point of Sale';

// Load payment types & discount types from POS Management tables
$paymentTypes = [];
$discountTypes = [];
try {
    $paymentTypes = $pdo->query("SELECT id, code, label, icon, requires_reference FROM inv_payment_types WHERE is_active = TRUE ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
    $discountTypes = $pdo->query("SELECT id, code, label, discount_mode, value, applies_to, min_amount, max_discount, requires_approval FROM inv_discount_types WHERE is_active = TRUE ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Tables may not exist yet — fallback to defaults
}

// Fallbacks if tables empty or don't exist
if (empty($paymentTypes)) {
    $paymentTypes = [
        ['id' => 0, 'code' => 'cash',     'label' => 'Cash',     'icon' => '💵', 'requires_reference' => false],
        ['id' => 0, 'code' => 'e-wallet',  'label' => 'E-Wallet', 'icon' => '📱', 'requires_reference' => true],
        ['id' => 0, 'code' => 'card',      'label' => 'Card',     'icon' => '💳', 'requires_reference' => true],
    ];
}
if (empty($discountTypes)) {
    $discountTypes = [
        ['id' => 0, 'code' => 'none', 'label' => 'No Discount', 'discount_mode' => 'fixed', 'value' => 0, 'applies_to' => 'transaction', 'min_amount' => 0, 'max_discount' => null, 'requires_approval' => false],
    ];
}

// Ensure "No Discount" is first if not already present
$hasNone = false;
foreach ($discountTypes as $dt) {
    if ($dt['code'] === 'none' || (float)$dt['value'] === 0.0) { $hasNone = true; break; }
}
if (!$hasNone) {
    array_unshift($discountTypes, ['id' => 0, 'code' => 'none', 'label' => 'No Discount', 'discount_mode' => 'fixed', 'value' => 0, 'applies_to' => 'transaction', 'min_amount' => 0, 'max_discount' => null, 'requires_approval' => false]);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- QZ Tray: Official SDK + HRMS wrapper — must load before POS inline script -->
<script src="<?= BASE_URL ?>/assets/js/vendor/qz-tray-sdk.js"></script>
<script src="<?= BASE_URL ?>/assets/js/qz-tray.js?v=<?= time() ?>"></script>

<!-- POS Terminal — mobile-first, desktop-optimised -->
<style>
  /* Lock viewport for POS: prevent any outer scroll, fill parent */
  #appMain:has(#posApp) { overflow: hidden !important; padding: 0 !important; }
  @supports not (selector(:has(*))) { .pos-main-override { overflow: hidden !important; padding: 0 !important; } }
  #posApp { height: 100%; }

  /* Category chips: bigger, bolder */
  .pos-cat { font-size: 0.875rem !important; padding: 0.5rem 1rem !important; }
  .pos-cat.active { background: #4f46e5; color: #fff; border-color: #4f46e5; }
  .pos-cat:not(.active) { background: #fff; color: #334155; border-color: #e2e8f0; }
  .pos-cat:not(.active):hover { background: #f1f5f9; border-color: #c7d2fe; }

  /* Desktop: category bar wraps */
  #deskCatBar { flex-wrap: wrap; }

  /* Mobile product grid: 12 items visible (3 cols x 4 rows) */
  @media (max-width: 1023px) {
    #mobProductList .pos-product { min-height: 0; }
  }
</style>
<script>document.getElementById('appMain')?.classList.add('pos-main-override');</script>
<div id="posApp" class="flex flex-col w-full">

  <!-- ===== DESKTOP LAYOUT (lg+): side-by-side ===== -->
  <div class="hidden lg:flex flex-1 min-h-0 overflow-hidden">
    <!-- Left: Cart -->
    <div class="w-[380px] xl:w-[440px] flex flex-col border-r bg-white">
      <div class="px-4 py-3 border-b bg-slate-50 flex items-center justify-between">
        <div class="font-semibold text-slate-900 text-sm">Current Order <span id="deskCartCount" class="text-xs text-slate-400 font-normal">(0)</span></div>
        <button type="button" id="deskClearCart" class="text-xs text-red-500 hover:text-red-700">Clear All</button>
      </div>
      <div id="deskCartItems" class="flex-1 min-h-0 overflow-y-auto overscroll-contain">
        <div class="px-4 py-12 text-center text-sm text-slate-400" id="deskCartEmpty">
          <svg class="w-10 h-10 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
          Tap an item to add
        </div>
      </div>
      <!-- Summary + Actions -->
      <div class="border-t bg-slate-50 p-4 space-y-2">
        <div class="flex justify-between text-sm"><span class="text-slate-500">Subtotal</span><span id="deskSubtotal" class="font-medium">₱0.00</span></div>
        <div class="flex justify-between text-sm">
          <span class="text-slate-500">Discount</span>
          <select id="deskDiscount" class="w-40 text-right border rounded-lg px-2 py-1 text-xs focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400">
            <?php foreach ($discountTypes as $dt): ?>
            <option value="<?= htmlspecialchars($dt['code']) ?>"
                    data-mode="<?= htmlspecialchars($dt['discount_mode']) ?>"
                    data-value="<?= (float)$dt['value'] ?>"
                    data-min="<?= (float)($dt['min_amount'] ?? 0) ?>"
                    data-max="<?= $dt['max_discount'] !== null ? (float)$dt['max_discount'] : '' ?>"
                    <?= $dt['code'] === 'none' ? 'selected' : '' ?>>
              <?= htmlspecialchars($dt['label']) ?><?= (float)$dt['value'] > 0 ? ($dt['discount_mode'] === 'percentage' ? ' (' . (float)$dt['value'] . '%)' : ' (₱' . number_format((float)$dt['value'], 2) . ')') : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="deskDiscountAmt" class="flex justify-between text-xs text-indigo-600 hidden"><span>Discount Amount</span><span id="deskDiscountDisplay">-₱0.00</span></div>
        <div class="flex justify-between text-base font-bold border-t pt-2"><span>Total</span><span id="deskTotal">₱0.00</span></div>
      </div>
      <div class="border-t p-4 space-y-3">
        <div class="grid grid-cols-2 gap-2">
          <div><label class="text-xs text-slate-500">Payment</label>
            <select id="deskPayMethod" class="w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400">
              <?php foreach ($paymentTypes as $pt): ?>
              <option value="<?= htmlspecialchars($pt['code']) ?>" data-requires-ref="<?= $pt['requires_reference'] ? '1' : '0' ?>">
                <?= htmlspecialchars($pt['label']) ?>
              </option>
              <?php endforeach; ?>
            </select></div>
          <div><label class="text-xs text-slate-500">Amount Received</label>
            <input type="number" id="deskTendered" min="0" step="0.01" class="w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400" placeholder="0.00" /></div>
        </div>
        <div class="flex justify-between text-sm hidden" id="deskChangeRow"><span class="text-slate-500">Change</span><span id="deskChange" class="font-bold text-emerald-600">₱0.00</span></div>
        <div id="deskRefRow" class="hidden">
          <input type="text" id="deskRefNumber" class="w-full border rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400" placeholder="Reference # (required)" />
        </div>
        <input type="text" id="deskCustomer" class="w-full border rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400" placeholder="Customer / Department (optional)" />
        <input type="text" id="deskNotes" class="w-full border rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400" placeholder="Notes (optional)" />
        <div class="grid grid-cols-2 gap-2">
          <button type="button" id="deskHistoryBtn" class="btn btn-outline py-2.5 text-sm">
            <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>History
          </button>
          <button type="button" id="deskCheckout" class="btn btn-primary py-2.5 text-sm disabled:opacity-50" disabled>Confirm Sale</button>
        </div>
      </div>
    </div>
    <!-- Right: Search + Categories + Products -->
    <div class="flex-1 flex flex-col bg-slate-50 overflow-hidden">
      <div class="p-3 bg-white border-b space-y-3">
        <div class="relative">
          <input type="text" id="deskSearch" placeholder="Search by name, SKU, or barcode..." class="w-full border rounded-lg pl-9 pr-3 py-2 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400" autofocus />
          <svg class="w-4 h-4 absolute left-3 top-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
        <div id="deskCatBar" class="flex gap-2 pb-1 flex-wrap"></div>
      </div>
      <div id="deskProductGrid" class="flex-1 overflow-y-auto p-3">
        <div class="grid grid-cols-4 xl:grid-cols-4 2xl:grid-cols-6 gap-3" id="deskProductList">
          <div class="col-span-full text-center text-slate-400 py-10 text-sm">Loading items...</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== MOBILE LAYOUT (<lg): viewport-locked ===== -->
  <div class="flex lg:hidden flex-col flex-1 min-h-0 overflow-hidden bg-slate-50">
    <!-- Cart area — fixed-height with scrollable item list -->
    <div id="mobCartArea" class="bg-white border-b shrink-0 flex flex-col" style="height:18vh; max-height:140px; min-height:80px;">
      <div class="px-3 py-1.5 border-b bg-slate-50 flex items-center justify-between shrink-0">
        <div class="font-semibold text-slate-900 text-xs">ORDER <span id="mobCartCount" class="text-slate-400 font-normal">(0)</span></div>
        <button type="button" id="mobClearCart" class="text-[10px] text-red-500">CLEAR</button>
      </div>
      <div id="mobCartItems" class="flex-1 min-h-0 overflow-y-auto overscroll-contain">
        <div class="px-3 py-4 text-center text-xs text-slate-400" id="mobCartEmpty">
          <svg class="w-7 h-7 mx-auto mb-1 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
          Tap items below to add
        </div>
      </div>
    </div>

    <!-- Search -->
    <div class="px-3 py-1.5 bg-white border-b shrink-0">
      <div class="relative">
        <input type="text" id="mobSearch" placeholder="Search items..." class="w-full border rounded-lg pl-9 pr-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400" />
        <svg class="w-4 h-4 absolute left-3 top-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      </div>
    </div>

    <!-- Category chips -->
    <div id="mobCatBar" class="flex gap-2 px-3 py-2 overflow-x-auto bg-white border-b scrollbar-thin shrink-0"></div>

    <!-- Product grid (takes remaining space, scrolls internally) -->
    <div id="mobProductGrid" class="flex-1 min-h-0 overflow-y-auto overscroll-contain p-2">
      <div class="grid grid-cols-2 gap-2.5" id="mobProductList">
        <div class="col-span-full text-center text-slate-400 py-8 text-sm">Loading...</div>
      </div>
    </div>

    <!-- Bottom bar: pinned to bottom, never scrolls -->
    <div class="bg-white border-t px-3 py-2 shrink-0" style="padding-bottom:max(0.5rem, env(safe-area-inset-bottom));">
      <div class="flex items-center justify-between mb-1.5">
        <span class="text-sm text-slate-500">Total: <strong id="mobTotal" class="text-slate-900">₱0.00</strong></span>
        <span class="text-slate-400 text-xs"><span id="mobItemCount">0</span> items</span>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <button type="button" id="mobHistoryBtn" class="btn btn-outline py-2 text-sm">
          <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>History
        </button>
        <button type="button" id="mobCheckout" class="btn btn-primary py-2 text-sm disabled:opacity-50" disabled>
          Confirm
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ===== MODAL: Edit Cart Item (qty / remove) ===== -->
<div id="cartEditModal" class="fixed inset-0 z-[60] hidden items-end sm:items-center justify-center">
  <div class="absolute inset-0 bg-black/50 pos-overlay" data-close="cartEditModal"></div>
  <div class="relative bg-white w-full sm:w-96 sm:rounded-xl rounded-t-2xl shadow-2xl max-h-[80vh] overflow-y-auto animate-slide-up">
    <div class="px-4 py-3 border-b flex items-center justify-between">
      <h3 class="font-semibold text-slate-900 text-sm">Edit Item</h3>
      <button type="button" class="text-slate-400 hover:text-slate-600 text-xl leading-none pos-modal-close" data-close="cartEditModal">&times;</button>
    </div>
    <div class="p-4">
      <div class="text-sm font-medium text-slate-900 mb-1" id="ceItemName"></div>
      <div class="text-xs text-slate-400 mb-4" id="ceItemSku"></div>
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm text-slate-600">Unit Price</span>
        <span class="text-sm font-medium" id="cePrice"></span>
      </div>
      <div class="flex items-center justify-center gap-4 mb-4">
        <button type="button" id="ceMinusBtn" class="w-12 h-12 rounded-xl border-2 border-slate-200 flex items-center justify-center text-xl font-bold text-slate-600 hover:bg-slate-100 active:bg-slate-200 transition">−</button>
        <input type="number" id="ceQtyInput" min="1" class="w-20 h-12 text-center text-xl font-bold border-2 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400" value="1" />
        <button type="button" id="cePlusBtn" class="w-12 h-12 rounded-xl border-2 border-slate-200 flex items-center justify-center text-xl font-bold text-slate-600 hover:bg-slate-100 active:bg-slate-200 transition">+</button>
      </div>
      <div class="flex items-center justify-between mb-4">
        <span class="text-sm text-slate-600">Line Total</span>
        <span class="text-base font-bold text-indigo-600" id="ceLineTotal"></span>
      </div>
      <div class="flex gap-2">
        <button type="button" id="ceRemoveBtn" class="btn btn-danger flex-1 py-2.5">Remove</button>
        <button type="button" id="ceSaveBtn" class="btn btn-primary flex-1 py-2.5">Update</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== MODAL: Mobile Checkout ===== -->
<div id="mobCheckoutModal" class="fixed inset-0 z-[60] hidden items-end sm:items-center justify-center">
  <div class="absolute inset-0 bg-black/50 pos-overlay" data-close="mobCheckoutModal"></div>
  <div class="relative bg-white w-full sm:w-96 sm:rounded-xl rounded-t-2xl shadow-2xl max-h-[90vh] overflow-y-auto animate-slide-up">
    <div class="px-4 py-3 border-b flex items-center justify-between">
      <h3 class="font-semibold text-slate-900 text-sm">Complete Sale</h3>
      <button type="button" class="text-slate-400 hover:text-slate-600 text-xl leading-none pos-modal-close" data-close="mobCheckoutModal">&times;</button>
    </div>
    <div class="p-4 space-y-3">
      <div class="flex justify-between text-sm"><span class="text-slate-500">Subtotal</span><span id="mcSubtotal" class="font-medium">₱0.00</span></div>
      <div class="flex justify-between text-sm items-center">
        <span class="text-slate-500">Discount</span>
        <select id="mcDiscount" class="w-40 text-right border rounded-lg px-2 py-1 text-xs">
          <?php foreach ($discountTypes as $dt): ?>
          <option value="<?= htmlspecialchars($dt['code']) ?>"
                  data-mode="<?= htmlspecialchars($dt['discount_mode']) ?>"
                  data-value="<?= (float)$dt['value'] ?>"
                  data-min="<?= (float)($dt['min_amount'] ?? 0) ?>"
                  data-max="<?= $dt['max_discount'] !== null ? (float)$dt['max_discount'] : '' ?>"
                  <?= $dt['code'] === 'none' ? 'selected' : '' ?>>
            <?= htmlspecialchars($dt['label']) ?><?= (float)$dt['value'] > 0 ? ($dt['discount_mode'] === 'percentage' ? ' (' . (float)$dt['value'] . '%)' : ' (₱' . number_format((float)$dt['value'], 2) . ')') : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="mcDiscountAmt" class="flex justify-between text-xs text-indigo-600 hidden"><span>Discount Amount</span><span id="mcDiscountDisplay">-₱0.00</span></div>
      <div class="flex justify-between text-lg font-bold border-t pt-2"><span>Total</span><span id="mcTotal">₱0.00</span></div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="text-xs text-slate-500">Payment</label>
          <select id="mcPayMethod" class="w-full border rounded-lg px-2 py-1.5 text-sm">
            <?php foreach ($paymentTypes as $pt): ?>
            <option value="<?= htmlspecialchars($pt['code']) ?>" data-requires-ref="<?= $pt['requires_reference'] ? '1' : '0' ?>">
              <?= htmlspecialchars($pt['label']) ?>
            </option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="text-xs text-slate-500">Amount Received</label>
          <input type="number" id="mcTendered" min="0" step="0.01" class="w-full border rounded-lg px-2 py-1.5 text-sm" placeholder="0.00" /></div>
      </div>
      <div class="flex justify-between text-sm hidden" id="mcChangeRow"><span class="text-slate-500">Change</span><span id="mcChange" class="font-bold text-emerald-600">₱0.00</span></div>
      <div id="mcRefRow" class="hidden">
        <input type="text" id="mcRefNumber" class="w-full border rounded-lg px-3 py-1.5 text-sm" placeholder="Reference # (required)" />
      </div>
      <input type="text" id="mcCustomer" class="w-full border rounded-lg px-3 py-1.5 text-sm" placeholder="Customer / Department (optional)" />
      <input type="text" id="mcNotes" class="w-full border rounded-lg px-3 py-1.5 text-sm" placeholder="Notes (optional)" />
      <button type="button" id="mcConfirmBtn" class="btn btn-primary w-full py-3 text-sm disabled:opacity-50" disabled>Complete Sale</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Transaction History ===== -->
<div id="txnHistoryModal" class="fixed inset-0 z-[60] hidden items-end sm:items-center justify-center">
  <div class="absolute inset-0 bg-black/50 pos-overlay" data-close="txnHistoryModal"></div>
  <div class="relative bg-white w-full sm:w-[500px] sm:rounded-xl rounded-t-2xl shadow-2xl flex flex-col" style="max-height:90vh; max-height:90dvh;">
    <div class="px-4 py-3 border-b flex items-center justify-between shrink-0">
      <h3 class="font-semibold text-slate-900 text-sm">Transaction History</h3>
      <button type="button" class="text-slate-400 hover:text-slate-600 text-xl leading-none pos-modal-close" data-close="txnHistoryModal">&times;</button>
    </div>
    <div class="px-4 py-2 border-b shrink-0">
      <input type="text" id="txnSearchInput" placeholder="Search transactions..." class="w-full border rounded-lg px-3 py-1.5 text-sm" />
    </div>
    <div id="txnHistoryList" class="flex-1 overflow-y-auto divide-y">
      <div class="px-4 py-8 text-center text-sm text-slate-400">Loading...</div>
    </div>
  </div>
</div>

<!-- ===== MODAL: Transaction Detail ===== -->
<div id="txnDetailModal" class="fixed inset-0 z-[70] hidden items-end sm:items-center justify-center">
  <div class="absolute inset-0 bg-black/50 pos-overlay" data-close="txnDetailModal"></div>
  <div class="relative bg-white w-full sm:w-[480px] sm:rounded-xl rounded-t-2xl shadow-2xl flex flex-col" style="max-height:90vh; max-height:90dvh;">
    <div class="px-4 py-3 border-b flex items-center justify-between shrink-0">
      <h3 class="font-semibold text-slate-900 text-sm" id="txnDetailTitle">Transaction Detail</h3>
      <button type="button" class="text-slate-400 hover:text-slate-600 text-xl leading-none pos-modal-close" data-close="txnDetailModal">&times;</button>
    </div>
    <div id="txnDetailBody" class="flex-1 overflow-y-auto p-4">
      <div class="text-center text-sm text-slate-400 py-8">Loading...</div>
    </div>
    <div id="txnDetailActions" class="border-t px-4 py-3 shrink-0 hidden">
      <button type="button" id="txnRefundBtn" class="btn btn-danger w-full py-2.5 text-sm">
        <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>Return / Refund
      </button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Receipt ===== -->
<div id="receiptModal" class="fixed inset-0 z-[60] hidden items-center justify-center px-4">
  <div class="absolute inset-0 bg-black/50 pos-overlay" data-close="receiptModal"></div>
  <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h2 class="font-semibold text-slate-900 text-sm">Transaction Complete</h2>
      <button type="button" class="text-slate-400 hover:text-slate-600 pos-modal-close" data-close="receiptModal">&times;</button>
    </div>
    <div id="receiptContent" class="p-4"></div>
    <div class="px-4 py-3 border-t flex gap-2">
      <button id="receiptPrint" class="btn btn-primary flex-1">Print Receipt</button>
      <button id="receiptNewSale" class="btn btn-outline flex-1">New Sale</button>
    </div>
  </div>
</div>

<script>
(function() {
  const BASE = window.__baseUrl || '';
  const CSRF = '<?= csrf_token() ?>';
  const CAN_REFUND = <?= $canRefund ? 'true' : 'false' ?>;

  // ── Viewport lock: cleanup when leaving POS via SPA ──
  (function lockViewport() {
    document.addEventListener('spa:loaded', function posCleanup() {
      document.getElementById('appMain')?.classList.remove('pos-main-override');
      document.removeEventListener('spa:loaded', posCleanup);
    });
  })();

  // ── State ──
  let cart = [];
  let allItems = [];
  let categories = [];
  let editingItemId = null;

  // ── Helpers ──
  function esc(s) { if (!s) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function fmt(n) { return '₱' + (+n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  const _payMethodMap = <?= json_encode(array_column($paymentTypes, 'label', 'code')) ?>;
  function formatPaymentMethod(m) { return _payMethodMap[m] || m; }
  function $(id) { return document.getElementById(id); }

  function showToast(msg, kind) {
    const host = $('notifHost');
    if (!host) { alert(msg); return; }
    const cls = kind === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800';
    const div = document.createElement('div');
    div.className = 'notif pointer-events-auto shadow-lg rounded-lg border px-3 py-2 text-sm flex items-center justify-between min-w-[280px] max-w-[520px] ' + cls;
    div.innerHTML = '<div class="pr-2">' + esc(msg) + '</div><button class="ml-4 opacity-70 hover:opacity-100" onclick="this.parentElement.remove()">&times;</button>';
    host.appendChild(div);
    setTimeout(() => div.remove(), 4000);
  }

  // ── Modal helpers ──
  function openModal(id) { const m = $(id); m.classList.remove('hidden'); m.classList.add('flex'); }
  function closeModal(id) { const m = $(id); m.classList.add('hidden'); m.classList.remove('flex'); }

  document.querySelectorAll('.pos-overlay').forEach(el => el.addEventListener('click', () => closeModal(el.dataset.close)));
  document.querySelectorAll('.pos-modal-close').forEach(el => el.addEventListener('click', () => closeModal(el.dataset.close)));

  // ── Load Items ──
  async function loadItems() {
    try {
      const res = await fetch(BASE + '/modules/inventory/api_pos_items');
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); } catch (e) { throw new Error('Invalid JSON response'); }
      allItems = Array.isArray(data.items) ? data.items : [];
      categories = Array.isArray(data.categories) ? data.categories : [];
      renderCategories();
      renderProducts(allItems);
    } catch (e) {
      console.error('POS loadItems error:', e);
      const retryHtml = '<div class="col-span-full text-center py-10"><div class="text-red-500 text-sm mb-2">Failed to load items.</div><button class="pos-retry-btn text-xs text-indigo-600 hover:underline">Retry</button></div>';
      setProductHTML(retryHtml);
      document.querySelectorAll('.pos-retry-btn').forEach(btn => btn.addEventListener('click', loadItems));
    }
  }

  // ── Categories ──
  function renderCategories() {
    let html = '<button class="pos-cat active text-sm px-4 py-2 rounded-full border whitespace-nowrap font-semibold transition" data-cat="all">All</button>';
    categories.forEach(c => {
      html += '<button class="pos-cat text-sm px-4 py-2 rounded-full border whitespace-nowrap font-semibold transition" data-cat="' + c.id + '">' + esc(c.name) + '</button>';
    });
    $('deskCatBar').innerHTML = html;
    $('mobCatBar').innerHTML = html;
    [$('deskCatBar'), $('mobCatBar')].forEach(bar => {
      bar.querySelectorAll('.pos-cat').forEach(btn => {
        btn.addEventListener('click', () => {
          // sync both bars
          document.querySelectorAll('.pos-cat').forEach(b => b.classList.remove('active'));
          document.querySelectorAll('.pos-cat[data-cat="' + btn.dataset.cat + '"]').forEach(b => b.classList.add('active'));
          filterProducts();
        });
      });
    });
  }

  function filterProducts() {
    const dq = $('deskSearch').value.trim().toLowerCase();
    const mq = $('mobSearch').value.trim().toLowerCase();
    const q = dq || mq;
    const activeCat = document.querySelector('.pos-cat.active');
    const catId = activeCat ? activeCat.dataset.cat : 'all';
    let filtered = allItems.filter(item => {
      const matchQ = !q || (item.name && item.name.toLowerCase().includes(q)) || (item.sku && item.sku.toLowerCase().includes(q)) || (item.barcode && item.barcode.toLowerCase().includes(q)) || (item.generic_name && item.generic_name.toLowerCase().includes(q));
      const matchCat = catId === 'all' || String(item.category_id) === catId;
      return matchQ && matchCat;
    });
    renderProducts(filtered);
  }

  function setProductHTML(html) {
    $('deskProductList').innerHTML = html;
    $('mobProductList').innerHTML = html;
  }

  function renderProducts(items) {
    if (!items.length) {
      setProductHTML('<div class="col-span-full text-center text-slate-400 py-10 text-sm">No items found.</div>');
      return;
    }
    // Desktop cards (compact)
    let deskHtml = '';
    items.forEach(item => {
      const inCart = cart.find(c => +c.id === +item.id);
      const cartQty = inCart ? inCart.qty : 0;
      const qty = +item.qty_on_hand || 0;
      const isOutOfStock = qty <= 0;
      const badge = cartQty > 0 ? '<span class="absolute -top-1 -right-1 bg-indigo-600 text-white text-[10px] w-5 h-5 rounded-full flex items-center justify-center font-bold">' + cartQty + '</span>' : '';
      const oosBadge = isOutOfStock ? '<span class="absolute top-1 left-1 bg-red-100 text-red-600 text-[9px] px-1.5 py-0.5 rounded font-semibold uppercase">Out of Stock</span>' : '';
      const oosClass = isOutOfStock ? 'opacity-50 cursor-not-allowed' : 'hover:border-indigo-400 hover:shadow-sm active:scale-[0.97]';
      const stockColor = isOutOfStock ? 'text-red-500 font-semibold' : (qty <= (+item.reorder_level || 0) ? 'text-amber-600' : 'text-slate-400');
      deskHtml += `<button class="pos-product bg-white rounded-xl border p-2.5 text-left transition relative ${oosClass}" data-id="${item.id}" ${isOutOfStock ? 'data-oos="1"' : ''}>
        ${badge}${oosBadge}
        <div class="text-xs font-medium text-slate-900 truncate leading-tight">${esc(item.name)}</div>
        <div class="text-[10px] text-slate-400 truncate mt-0.5">${esc(item.sku)}${item.unit ? ' · ' + esc(item.unit) : ''}</div>
        <div class="flex items-center justify-between mt-1.5">
          <span class="text-xs font-bold text-indigo-600">${fmt(item.selling_price)}</span>
          <span class="text-[10px] ${stockColor}">${qty}${!isOutOfStock && item.unit ? ' ' + esc(item.unit) : ''}</span>
        </div>
      </button>`;
    });
    // Mobile cards (bigger, easier to read & tap)
    let mobHtml = '';
    items.forEach(item => {
      const inCart = cart.find(c => +c.id === +item.id);
      const cartQty = inCart ? inCart.qty : 0;
      const qty = +item.qty_on_hand || 0;
      const isOutOfStock = qty <= 0;
      const badge = cartQty > 0 ? '<span class="absolute -top-1.5 -right-1.5 bg-indigo-600 text-white text-xs w-6 h-6 rounded-full flex items-center justify-center font-bold shadow">' + cartQty + '</span>' : '';
      const oosBadge = isOutOfStock ? '<span class="absolute top-2 left-2 bg-red-100 text-red-600 text-[10px] px-2 py-0.5 rounded font-semibold uppercase">Out of Stock</span>' : '';
      const oosClass = isOutOfStock ? 'opacity-50 cursor-not-allowed' : 'hover:border-indigo-400 hover:shadow-sm active:scale-[0.97]';
      const stockColor = isOutOfStock ? 'text-red-500 font-semibold' : (qty <= (+item.reorder_level || 0) ? 'text-amber-600' : 'text-slate-400');
      mobHtml += `<button class="pos-product bg-white rounded-xl border p-3 text-left transition relative ${oosClass}" data-id="${item.id}" ${isOutOfStock ? 'data-oos="1"' : ''}>
        ${badge}${oosBadge}
        <div class="text-sm font-semibold text-slate-900 truncate leading-snug">${esc(item.name)}</div>
        <div class="text-xs text-slate-400 truncate mt-0.5">${esc(item.sku)}${item.unit ? ' · ' + esc(item.unit) : ''}</div>
        <div class="flex items-center justify-between mt-2">
          <span class="text-sm font-bold text-indigo-600">${fmt(item.selling_price)}</span>
          <span class="text-xs ${stockColor}">${qty}${!isOutOfStock && item.unit ? ' ' + esc(item.unit) : ''}</span>
        </div>
      </button>`;
    });
    $('deskProductList').innerHTML = deskHtml;
    $('mobProductList').innerHTML = mobHtml;
    document.querySelectorAll('.pos-product').forEach(card => {
      card.addEventListener('click', () => {
        if (card.dataset.oos === '1') { showToast('This item is out of stock'); return; }
        addToCart(+card.dataset.id);
      });
    });
  }

  // Sync searches
  $('deskSearch').addEventListener('input', () => { $('mobSearch').value = $('deskSearch').value; filterProducts(); });
  $('mobSearch').addEventListener('input', () => { $('deskSearch').value = $('mobSearch').value; filterProducts(); });

  // ── Cart Logic ──
  function addToCart(itemId) {
    const item = allItems.find(i => +i.id === itemId);
    if (!item) return;
    const qty = +item.qty_on_hand || 0;
    if (qty <= 0) { showToast('This item is out of stock'); return; }
    const existing = cart.find(c => +c.id === itemId);
    if (existing) {
      if (existing.qty >= qty) { showToast('Not enough stock'); return; }
      existing.qty++;
    } else {
      cart.push({ id: +item.id, name: item.name, sku: item.sku, price: +item.selling_price, qty: 1, unit: item.unit, maxQty: qty });
    }
    renderCart();
    renderProducts(getFilteredItems());
  }

  function removeFromCart(itemId) {
    cart = cart.filter(c => +c.id !== itemId);
    renderCart();
    renderProducts(getFilteredItems());
  }

  function updateCartQty(itemId, newQty) {
    const item = cart.find(c => +c.id === itemId);
    if (!item) return;
    const master = allItems.find(i => +i.id === itemId);
    if (newQty < 1) { removeFromCart(itemId); return; }
    if (master && newQty > (+master.qty_on_hand || 0)) { showToast('Not enough stock'); return; }
    item.qty = newQty;
    renderCart();
    renderProducts(getFilteredItems());
  }

  function getFilteredItems() {
    const q = ($('deskSearch').value || $('mobSearch').value).trim().toLowerCase();
    const activeCat = document.querySelector('.pos-cat.active');
    const catId = activeCat ? activeCat.dataset.cat : 'all';
    return allItems.filter(item => {
      const matchQ = !q || (item.name && item.name.toLowerCase().includes(q)) || (item.sku && item.sku.toLowerCase().includes(q));
      const matchCat = catId === 'all' || String(item.category_id) === catId;
      return matchQ && matchCat;
    });
  }

  // ── Cart Rendering ──
  function renderCart() {
    const totalItems = cart.reduce((s, i) => s + i.qty, 0);
    $('deskCartCount').textContent = '(' + totalItems + ')';
    $('mobCartCount').textContent = '(' + totalItems + ')';
    $('mobItemCount').textContent = totalItems;

    // Desktop cart
    if (!cart.length) {
      $('deskCartItems').innerHTML = '<div class="px-4 py-12 text-center text-sm text-slate-400"><svg class="w-10 h-10 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>Tap an item to add</div>';
      $('mobCartItems').innerHTML = '<div class="px-3 py-6 text-center text-xs text-slate-400"><svg class="w-8 h-8 mx-auto mb-1 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>Tap items below to add</div>';
    } else {
      // Desktop
      let dh = '';
      cart.forEach(item => {
        dh += `<div class="px-4 py-2 flex items-center gap-3 hover:bg-slate-50 cursor-pointer cart-row" data-id="${item.id}">
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-slate-900 truncate">${esc(item.name)}</div>
            <div class="text-[11px] text-slate-400">${fmt(item.price)} × ${item.qty}</div>
          </div>
          <div class="text-sm font-semibold text-slate-900 shrink-0">${fmt(item.price * item.qty)}</div>
        </div>`;
      });
      $('deskCartItems').innerHTML = '<div class="divide-y">' + dh + '</div>';

      // Mobile
      let mh = '';
      cart.forEach(item => {
        mh += `<div class="px-3 py-2 flex items-center gap-2 active:bg-slate-50 cursor-pointer cart-row" data-id="${item.id}">
          <div class="flex-1 min-w-0">
            <div class="text-xs font-medium text-slate-900 truncate">${esc(item.name)}</div>
            <div class="text-[10px] text-slate-400">${fmt(item.price)} × ${item.qty}</div>
          </div>
          <div class="text-xs font-semibold text-slate-900 shrink-0">${fmt(item.price * item.qty)}</div>
        </div>`;
      });
      $('mobCartItems').innerHTML = '<div class="divide-y">' + mh + '</div>';
    }

    // Cart row click → open edit modal
    document.querySelectorAll('.cart-row').forEach(row => {
      row.addEventListener('click', () => openCartEdit(+row.dataset.id));
    });

    updateTotals();
  }

  // ── Cart Edit Modal ──
  function openCartEdit(itemId) {
    const item = cart.find(c => c.id === itemId);
    if (!item) return;
    editingItemId = itemId;
    $('ceItemName').textContent = item.name;
    $('ceItemSku').textContent = item.sku + ' • ' + item.unit;
    $('cePrice').textContent = fmt(item.price);
    $('ceQtyInput').value = item.qty;
    $('ceQtyInput').max = item.maxQty;
    $('ceLineTotal').textContent = fmt(item.price * item.qty);
    openModal('cartEditModal');
  }

  function updateCartEditTotal() {
    const item = cart.find(c => c.id === editingItemId);
    if (!item) return;
    const qty = Math.max(1, +$('ceQtyInput').value || 1);
    $('ceLineTotal').textContent = fmt(item.price * qty);
  }

  $('ceMinusBtn').addEventListener('click', () => { const v = Math.max(1, (+$('ceQtyInput').value || 1) - 1); $('ceQtyInput').value = v; updateCartEditTotal(); });
  $('cePlusBtn').addEventListener('click', () => { const max = +$('ceQtyInput').max; const v = Math.min(max, (+$('ceQtyInput').value || 1) + 1); $('ceQtyInput').value = v; updateCartEditTotal(); });
  $('ceQtyInput').addEventListener('input', updateCartEditTotal);

  $('ceSaveBtn').addEventListener('click', () => {
    if (editingItemId) {
      updateCartQty(editingItemId, +$('ceQtyInput').value || 1);
    }
    closeModal('cartEditModal');
  });

  $('ceRemoveBtn').addEventListener('click', () => {
    if (editingItemId) removeFromCart(editingItemId);
    closeModal('cartEditModal');
  });

  // ── Discount Computation ──
  function computeDiscount(selectEl, subtotal) {
    const opt = selectEl.options[selectEl.selectedIndex];
    if (!opt || opt.value === 'none') return 0;
    const mode = opt.dataset.mode;
    const val = parseFloat(opt.dataset.value) || 0;
    const minAmt = parseFloat(opt.dataset.min) || 0;
    const maxDisc = opt.dataset.max !== '' ? parseFloat(opt.dataset.max) : Infinity;
    if (subtotal < minAmt) return 0; // doesn't meet minimum
    let disc = 0;
    if (mode === 'percentage') {
      disc = subtotal * (val / 100);
    } else {
      disc = val;
    }
    if (maxDisc && isFinite(maxDisc)) disc = Math.min(disc, maxDisc);
    return Math.min(disc, subtotal); // never exceed subtotal
  }

  // ── Reference # toggling ──
  function toggleRefField(selectEl, refRowId, refInputId) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const needsRef = opt && opt.dataset.requiresRef === '1';
    $(refRowId).classList.toggle('hidden', !needsRef);
    if (!needsRef) $(refInputId).value = '';
  }

  // ── Totals ──
  function updateTotals() {
    const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const dDisc = computeDiscount($('deskDiscount'), sub);
    const total = Math.max(0, sub - dDisc);

    $('deskSubtotal').textContent = fmt(sub);
    $('deskTotal').textContent = fmt(total);
    $('mobTotal').textContent = fmt(total);

    // Show discount amount if > 0
    if (dDisc > 0) {
      $('deskDiscountAmt').classList.remove('hidden');
      $('deskDiscountDisplay').textContent = '-' + fmt(dDisc);
    } else {
      $('deskDiscountAmt').classList.add('hidden');
    }

    // Desktop change
    const dTend = +$('deskTendered').value || 0;
    if ($('deskPayMethod').value === 'cash' && dTend > 0) {
      $('deskChangeRow').classList.remove('hidden');
      $('deskChange').textContent = fmt(Math.max(0, dTend - total));
    } else {
      $('deskChangeRow').classList.add('hidden');
    }

    // Toggle reference # field
    toggleRefField($('deskPayMethod'), 'deskRefRow', 'deskRefNumber');

    $('deskCheckout').disabled = cart.length === 0;
    $('mobCheckout').disabled = cart.length === 0;
  }

  $('deskDiscount').addEventListener('change', updateTotals);
  $('deskTendered').addEventListener('input', updateTotals);
  $('deskPayMethod').addEventListener('change', updateTotals);

  // Clear cart
  $('deskClearCart').addEventListener('click', () => { if (cart.length && confirm('Clear all items?')) { cart = []; renderCart(); renderProducts(getFilteredItems()); } });
  $('mobClearCart').addEventListener('click', () => { if (cart.length && confirm('Clear all items?')) { cart = []; renderCart(); renderProducts(getFilteredItems()); } });

  // ── Mobile Checkout Modal ──
  $('mobCheckout').addEventListener('click', () => {
    if (!cart.length) return;
    const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
    $('mcSubtotal').textContent = fmt(sub);
    $('mcTotal').textContent = fmt(sub);
    $('mcDiscount').value = 'none';
    $('mcTendered').value = '';
    $('mcRefNumber').value = '';
    $('mcRefRow').classList.add('hidden');
    $('mcDiscountAmt').classList.add('hidden');
    $('mcChangeRow').classList.add('hidden');
    $('mcConfirmBtn').disabled = false;
    $('mcConfirmBtn').textContent = 'Complete Sale';
    openModal('mobCheckoutModal');
  });

  function updateMobCheckout() {
    const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const disc = computeDiscount($('mcDiscount'), sub);
    const total = Math.max(0, sub - disc);
    $('mcTotal').textContent = fmt(total);

    // Show discount amount if > 0
    if (disc > 0) {
      $('mcDiscountAmt').classList.remove('hidden');
      $('mcDiscountDisplay').textContent = '-' + fmt(disc);
    } else {
      $('mcDiscountAmt').classList.add('hidden');
    }

    const tend = +$('mcTendered').value || 0;
    if ($('mcPayMethod').value === 'cash' && tend > 0) {
      $('mcChangeRow').classList.remove('hidden');
      $('mcChange').textContent = fmt(Math.max(0, tend - total));
    } else {
      $('mcChangeRow').classList.add('hidden');
    }

    // Toggle reference # field
    toggleRefField($('mcPayMethod'), 'mcRefRow', 'mcRefNumber');

    $('mcConfirmBtn').disabled = cart.length === 0;
  }

  $('mcDiscount').addEventListener('change', updateMobCheckout);
  $('mcTendered').addEventListener('input', updateMobCheckout);
  $('mcPayMethod').addEventListener('change', updateMobCheckout);

  // ── Checkout (shared logic) ──
  async function doCheckout(source) {
    if (!cart.length) return;
    const isMob = source === 'mobile';

    // Compute discount from dropdown
    const discSelect = isMob ? $('mcDiscount') : $('deskDiscount');
    const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const disc = computeDiscount(discSelect, sub);
    const discountCode = discSelect.value;

    const method = isMob ? $('mcPayMethod').value : $('deskPayMethod').value;
    const tend = isMob ? +$('mcTendered').value || 0 : +$('deskTendered').value || 0;
    const cust = isMob ? $('mcCustomer').value.trim() : $('deskCustomer').value.trim();
    const notes = isMob ? $('mcNotes').value.trim() : $('deskNotes').value.trim();
    const refNum = isMob ? ($('mcRefNumber') ? $('mcRefNumber').value.trim() : '') : ($('deskRefNumber') ? $('deskRefNumber').value.trim() : '');

    const total = Math.max(0, sub - disc);

    // ── Validation ──
    if (!method) {
      showToast('Please select a payment method');
      return;
    }

    // Check if payment method requires reference #
    const payOpt = isMob ? $('mcPayMethod') : $('deskPayMethod');
    const selOpt = payOpt.options[payOpt.selectedIndex];
    if (selOpt && selOpt.dataset.requiresRef === '1' && !refNum) {
      showToast('Reference number is required for ' + selOpt.textContent.trim() + ' payments');
      (isMob ? $('mcRefNumber') : $('deskRefNumber')).focus();
      return;
    }

    if (method === 'cash' && tend <= 0) {
      showToast('Please enter the amount received');
      (isMob ? $('mcTendered') : $('deskTendered')).focus();
      return;
    }

    if (method === 'cash' && tend < total) {
      showToast('Amount received is less than total');
      (isMob ? $('mcTendered') : $('deskTendered')).focus();
      return;
    }

    if (method !== 'cash' && tend <= 0) {
      // For non-cash, auto-fill tender = total
      // (allow zero; amount will be set to total server-side)
    }

    const btn = isMob ? $('mcConfirmBtn') : $('deskCheckout');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    try {
      const res = await fetch(BASE + '/modules/inventory/api_pos_checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: CSRF,
          items: cart.map(i => ({ id: i.id, qty: i.qty, price: i.price })),
          discount: disc,
          discount_type: discountCode,
          payment_method: method,
          amount_tendered: method === 'cash' ? tend : total,
          customer_name: cust,
          notes: notes,
          reference_number: refNum
        })
      });
      const data = await res.json();
      if (data.success) {
        if (isMob) closeModal('mobCheckoutModal');
        showReceipt(data.transaction);
        // QZ Tray auto-print if enabled
        qzAutoPrintReceipt(data.transaction);
        cart = [];
        renderCart();
        loadItems();
        // Reset desktop fields
        $('deskDiscount').value = 'none';
        $('deskTendered').value = '';
        $('deskCustomer').value = '';
        $('deskNotes').value = '';
        if ($('deskRefNumber')) $('deskRefNumber').value = '';
        $('deskRefRow').classList.add('hidden');
        $('deskDiscountAmt').classList.add('hidden');
      } else {
        showToast(data.error || 'Checkout failed');
      }
    } catch (e) {
      showToast('Network error. Please try again.');
    }
    btn.disabled = false;
    btn.textContent = isMob ? 'Complete Sale' : 'Confirm Sale';
  }

  $('deskCheckout').addEventListener('click', () => doCheckout('desktop'));
  $('mcConfirmBtn').addEventListener('click', () => doCheckout('mobile'));

  // ── Receipt Modal ──
  let _lastReceiptTxn = null;
  function showReceipt(txn) {
    _lastReceiptTxn = txn;
    let itemsHtml = '';
    (txn.items || []).forEach(it => {
      itemsHtml += `<tr><td class="text-left py-0.5">${esc(it.item_name)}</td><td class="text-center">${it.quantity}</td><td class="text-right">${fmt(it.unit_price)}</td><td class="text-right">${fmt(it.line_total)}</td></tr>`;
    });
    $('receiptContent').innerHTML = `
      <div id="receiptPrintArea" class="font-mono text-xs leading-relaxed">
        <div class="text-center space-y-0.5 mb-3">
          <div class="font-bold text-sm">${esc(txn.receipt_header || 'HRMS')}</div>
          <div>${esc(txn.receipt_subheader || '')}</div>
          <div class="text-[10px]">${esc(txn.receipt_address || '')}</div>
          <div class="border-b border-dashed my-2"></div>
        </div>
        <div class="flex justify-between mb-1"><span>Txn#: ${esc(txn.txn_number)}</span><span>${esc(txn.date)}</span></div>
        <div class="mb-1">Cashier: ${esc(txn.cashier)}</div>
        ${txn.customer_name ? '<div class="mb-1">Customer: ' + esc(txn.customer_name) + '</div>' : ''}
        <div class="border-b border-dashed my-2"></div>
        <table class="w-full text-xs">
          <thead><tr class="border-b"><th class="text-left py-0.5">Item</th><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
          <tbody>${itemsHtml}</tbody>
        </table>
        <div class="border-b border-dashed my-2"></div>
        <div class="space-y-0.5">
          <div class="flex justify-between"><span>Subtotal:</span><span>${fmt(txn.subtotal)}</span></div>
          ${+txn.discount_amount > 0 ? '<div class="flex justify-between"><span>Discount' + (txn.discount_type ? ' (' + esc(txn.discount_type) + ')' : '') + ':</span><span>-' + fmt(txn.discount_amount) + '</span></div>' : ''}
          <div class="flex justify-between font-bold text-sm"><span>TOTAL:</span><span>${fmt(txn.total_amount)}</span></div>
          <div class="flex justify-between"><span>Amount Received (${esc(formatPaymentMethod(txn.payment_method))}):</span><span>${fmt(txn.amount_tendered)}</span></div>
          ${txn.reference_number ? '<div class="flex justify-between"><span>Ref#:</span><span>' + esc(txn.reference_number) + '</span></div>' : ''}
          ${+txn.change_amount > 0 ? '<div class="flex justify-between"><span>Change:</span><span>' + fmt(txn.change_amount) + '</span></div>' : ''}
        </div>
        <div class="border-b border-dashed my-2"></div>
        <div class="text-center text-[10px] mt-2">${esc(txn.receipt_footer || 'Thank you!')}</div>
      </div>`;
    openModal('receiptModal');
  }

  $('receiptNewSale').addEventListener('click', () => { closeModal('receiptModal'); $('deskSearch').focus(); });
  $('receiptPrint').addEventListener('click', () => {
    // Try QZ Tray first if connected
    if (typeof QZIntegration !== 'undefined' && QZIntegration.isConnected() && QZIntegration.getDefaultPrinterName() && _lastReceiptTxn) {
      qzPrintReceipt(_lastReceiptTxn);
      return;
    }
    // Fallback: browser print popup
    const area = $('receiptPrintArea');
    if (!area) return;
    const w = window.open('', '_blank', 'width=320,height=600');
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:"Courier New",monospace;font-size:11px;padding:10px;width:280px}table{width:100%;border-collapse:collapse}th,td{padding:2px 0}.text-center{text-align:center}.text-right{text-align:right}.text-left{text-align:left}.font-bold{font-weight:bold}.text-sm{font-size:12px}.text-xs,.text-\\[10px\\]{font-size:10px}.border-b{border-bottom:1px dashed #000}.my-2{margin:6px 0}.mb-1{margin-bottom:4px}.mb-3{margin-bottom:10px}.mt-2{margin-top:6px}.space-y-0\\.5>*+*{margin-top:2px}.flex{display:flex}.justify-between{justify-content:space-between}@media print{body{width:auto}}</style></head><body>' + area.innerHTML + '</body></html>');
    w.document.close();
    setTimeout(() => w.print(), 300);
  });

  // ── Transaction History Modal ──
  let txnCache = [];
  async function loadTransactionHistory(q) {
    const url = BASE + '/modules/inventory/api_pos_transactions?action=list' + (q ? '&q=' + encodeURIComponent(q) : '');
    try {
      const res = await fetch(url);
      const data = await res.json();
      if (!data.success) {
        $('txnHistoryList').innerHTML = '<div class="px-4 py-8 text-center text-sm text-red-500">' + esc(data.error || 'Failed to load.') + '</div>';
        return;
      }
      txnCache = data.transactions || [];
      renderTxnList(txnCache);
    } catch (e) {
      $('txnHistoryList').innerHTML = '<div class="px-4 py-8 text-center text-sm text-red-500">Failed to load.</div>';
    }
  }

  function renderTxnList(txns) {
    if (!txns.length) {
      $('txnHistoryList').innerHTML = '<div class="px-4 py-8 text-center text-sm text-slate-400">No transactions found.</div>';
      return;
    }
    let html = '';
    txns.forEach(t => {
      const statusCls = t.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : t.status === 'voided' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700';
      const dt = new Date(t.created_at);
      const dateStr = dt.toLocaleDateString('en-PH', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
      html += `<div class="px-4 py-3 hover:bg-slate-50 cursor-pointer active:bg-slate-100 txn-row transition" data-id="${t.id}">
        <div class="flex items-center justify-between mb-0.5">
          <span class="text-sm font-medium text-slate-900">${esc(t.txn_number)}</span>
          <span class="text-xs px-2 py-0.5 rounded-full font-medium ${statusCls}">${esc(t.status)}</span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-xs text-slate-400">${dateStr}${t.customer_name ? ' • ' + esc(t.customer_name) : ''}</span>
          <span class="text-sm font-semibold text-slate-900">${fmt(t.total_amount)}</span>
        </div>
      </div>`;
    });
    $('txnHistoryList').innerHTML = html;
    $('txnHistoryList').querySelectorAll('.txn-row').forEach(row => {
      row.addEventListener('click', () => openTxnDetail(+row.dataset.id));
    });
  }

  function openHistoryModal() {
    openModal('txnHistoryModal');
    $('txnSearchInput').value = '';
    loadTransactionHistory();
  }

  $('deskHistoryBtn').addEventListener('click', openHistoryModal);
  $('mobHistoryBtn').addEventListener('click', openHistoryModal);

  let txnSearchTimer;
  $('txnSearchInput').addEventListener('input', () => {
    clearTimeout(txnSearchTimer);
    txnSearchTimer = setTimeout(() => loadTransactionHistory($('txnSearchInput').value.trim()), 300);
  });

  // ── Transaction Detail Modal ──
  async function openTxnDetail(id) {
    openModal('txnDetailModal');
    $('txnDetailBody').innerHTML = '<div class="text-center text-sm text-slate-400 py-8">Loading...</div>';
    $('txnDetailActions').classList.add('hidden');
    try {
      const res = await fetch(BASE + '/modules/inventory/api_pos_transactions?action=detail&id=' + id);
      const data = await res.json();
      if (!data.success) { $('txnDetailBody').innerHTML = '<div class="text-center text-sm text-red-500 py-8">' + esc(data.error) + '</div>'; return; }
      const t = data.transaction;
      $('txnDetailTitle').textContent = t.txn_number;

      const statusCls = t.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : t.status === 'voided' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700';
      const dt = new Date(t.created_at);
      const dateStr = dt.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });

      let itemsHtml = '';
      (t.items || []).forEach(it => {
        itemsHtml += `<div class="flex items-center justify-between py-1.5">
          <div class="flex-1 min-w-0"><div class="text-sm text-slate-900 truncate">${esc(it.item_name)}</div><div class="text-xs text-slate-400">${fmt(it.unit_price)} × ${it.quantity}</div></div>
          <div class="text-sm font-medium text-slate-900 shrink-0">${fmt(it.line_total)}</div>
        </div>`;
      });

      $('txnDetailBody').innerHTML = `
        <div class="flex items-center justify-between mb-3">
          <span class="text-xs px-2 py-0.5 rounded-full font-medium ${statusCls}">${esc(t.status)}</span>
          <span class="text-xs text-slate-400">${dateStr}</span>
        </div>
        <div class="text-xs text-slate-500 space-y-1 mb-3">
          <div>Cashier: <span class="text-slate-700">${esc(t.cashier_name)}</span></div>
          ${t.customer_name ? '<div>Customer: <span class="text-slate-700">' + esc(t.customer_name) + '</span></div>' : ''}
          <div>Payment: <span class="text-slate-700">${esc(formatPaymentMethod(t.payment_method))}</span></div>
          ${t.reference_number ? '<div>Ref#: <span class="text-slate-700">' + esc(t.reference_number) + '</span></div>' : ''}
        </div>
        <div class="border-t pt-2 divide-y">${itemsHtml}</div>
        <div class="border-t mt-2 pt-2 space-y-1">
          <div class="flex justify-between text-sm"><span class="text-slate-500">Subtotal</span><span>${fmt(t.subtotal)}</span></div>
          ${+t.discount_amount > 0 ? '<div class="flex justify-between text-sm"><span class="text-slate-500">Discount' + (t.discount_type ? ' (' + esc(t.discount_type) + ')' : '') + '</span><span>-' + fmt(t.discount_amount) + '</span></div>' : ''}
          <div class="flex justify-between text-base font-bold"><span>Total</span><span>${fmt(t.total_amount)}</span></div>
          ${t.payment_method === 'cash' ? '<div class="flex justify-between text-sm"><span class="text-slate-500">Amount Received</span><span>' + fmt(t.amount_tendered) + '</span></div>' : ''}
          ${+t.change_amount > 0 ? '<div class="flex justify-between text-sm"><span class="text-slate-500">Change</span><span class="text-emerald-600">' + fmt(t.change_amount) + '</span></div>' : ''}
        </div>
        ${t.status === 'refunded' && t.void_reason ? '<div class="mt-3 p-2 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800"><strong>Refund Reason:</strong> ' + esc(t.void_reason) + '</div>' : ''}
        ${t.status === 'voided' && t.void_reason ? '<div class="mt-3 p-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-800"><strong>Void Reason:</strong> ' + esc(t.void_reason) + '</div>' : ''}`;

      // Show refund button only for completed transactions with manage access
      if (CAN_REFUND && t.status === 'completed') {
        $('txnDetailActions').classList.remove('hidden');
        $('txnRefundBtn').dataset.id = t.id;
        $('txnRefundBtn').dataset.num = t.txn_number;
      } else {
        $('txnDetailActions').classList.add('hidden');
      }
    } catch (e) {
      $('txnDetailBody').innerHTML = '<div class="text-center text-sm text-red-500 py-8">Failed to load details.</div>';
    }
  }

  // ── Refund Handler ──
  $('txnRefundBtn').addEventListener('click', async () => {
    const id = +$('txnRefundBtn').dataset.id;
    const num = $('txnRefundBtn').dataset.num;
    const reason = prompt('Reason for return/refund of ' + num + ':');
    if (reason === null) return;
    if (!reason.trim()) { showToast('Please provide a reason'); return; }

    $('txnRefundBtn').disabled = true;
    $('txnRefundBtn').textContent = 'Processing...';
    try {
      const res = await fetch(BASE + '/modules/inventory/api_pos_transactions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'refund', id: id, reason: reason.trim(), csrf: CSRF })
      });
      const data = await res.json();
      if (data.success) {
        showToast('Transaction refunded successfully', 'success');
        closeModal('txnDetailModal');
        loadTransactionHistory($('txnSearchInput').value.trim());
        loadItems(); // refresh stock
      } else {
        showToast(data.error || 'Refund failed');
      }
    } catch (e) {
      showToast('Network error');
    }
    $('txnRefundBtn').disabled = false;
    $('txnRefundBtn').innerHTML = '<svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>Return / Refund';
  });

  // ── QZ Tray Integration ──
  let _qzSettingsLoaded = false;

  async function qzLoadSettings() {
    if (_qzSettingsLoaded || typeof QZIntegration === 'undefined') return;
    try {
      await QZIntegration.loadSettings(BASE, CSRF);
      _qzSettingsLoaded = true;
    } catch (e) {
      console.warn('[POS] QZ settings load failed:', e);
    }
  }

  async function qzAutoPrintReceipt(txn) {
    if (typeof QZIntegration === 'undefined') return;
    if (!_qzSettingsLoaded) await qzLoadSettings();
    if (!QZIntegration.getAutoPrint()) return;
    if (!QZIntegration.getDefaultPrinterName()) return;

    // Auto-connect if not already connected
    try {
      if (!QZIntegration.isConnected()) {
        await QZIntegration.connect();
      }
      await QZIntegration.printReceipt(txn);
      showToast('Receipt printed via QZ Tray', 'success');
    } catch (e) {
      console.warn('[POS] QZ auto-print failed:', e);
      // Fail silently — user can still print manually
    }
  }

  async function qzPrintReceipt(txn) {
    if (typeof QZIntegration === 'undefined') return;
    try {
      if (!QZIntegration.isConnected()) {
        await QZIntegration.connect();
      }
      const printer = QZIntegration.getDefaultPrinterName();
      if (!printer) {
        showToast('No QZ Tray printer configured — go to Print Server → QZ Tray tab');
        return;
      }
      await QZIntegration.printReceipt(txn, printer);
      showToast('Receipt sent to ' + printer, 'success');
    } catch (e) {
      showToast('QZ Tray print failed: ' + e.message);
    }
  }

  // Pre-load QZ settings on POS load
  qzLoadSettings();

  // ── Init ──
  loadItems();
})();
</script>

<style>
  .pos-cat.active { background-color: #4f46e5; color: #fff; border-color: #4f46e5; }
  .pos-cat:not(.active) { background-color: #fff; color: #475569; border-color: #e2e8f0; }
  .pos-cat:not(.active):hover { background-color: #f1f5f9; }
  .pos-cat:active { transform: scale(0.95); }
  .scrollbar-thin::-webkit-scrollbar { height: 4px; }
  .scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9px; }
  .animate-slide-up { animation: slideUp .25s ease-out; }
  @keyframes slideUp { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  @supports (padding-bottom: env(safe-area-inset-bottom)) {
    .safe-area-bottom { padding-bottom: calc(0.5rem + env(safe-area-inset-bottom)); }
  }
  /* Hide number input spinners on mobile for qty */
  input[type="number"]::-webkit-inner-spin-button,
  input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
  input[type="number"] { -moz-appearance: textfield; }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
