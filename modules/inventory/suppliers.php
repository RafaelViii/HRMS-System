<?php
/**
 * Inventory Suppliers - CRUD
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'read');

$pdo = get_db_conn();
$pageTitle = 'Suppliers';
$uid = (int)($_SESSION['user']['id'] ?? 0);
$canWrite = user_has_access($uid, 'inventory', 'inventory_items', 'write');

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    require_access('inventory', 'inventory_items', 'write');
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'contact_person' => trim($_POST['contact_person'] ?? '') ?: null,
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'email' => trim($_POST['email'] ?? '') ?: null,
            'address' => trim($_POST['address'] ?? '') ?: null,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($data['name'] === '') { flash_error('Supplier name is required.'); }
        else {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO inv_suppliers (name, contact_person, phone, email, address, notes, created_by) VALUES (:name, :cp, :ph, :em, :addr, :notes, :uid)")
                    ->execute([':name'=>$data['name'],':cp'=>$data['contact_person'],':ph'=>$data['phone'],':em'=>$data['email'],':addr'=>$data['address'],':notes'=>$data['notes'],':uid'=>$uid]);
                action_log('inventory', 'create_supplier', 'success', ['name' => $data['name']]);
                flash_success('Supplier created.');
            } else {
                $editId = (int)($_POST['id'] ?? 0);
                $pdo->prepare("UPDATE inv_suppliers SET name=:name, contact_person=:cp, phone=:ph, email=:em, address=:addr, notes=:notes, updated_at=NOW() WHERE id=:id")
                    ->execute([':name'=>$data['name'],':cp'=>$data['contact_person'],':ph'=>$data['phone'],':em'=>$data['email'],':addr'=>$data['address'],':notes'=>$data['notes'],':id'=>$editId]);
                action_log('inventory', 'update_supplier', 'success', ['id' => $editId]);
                flash_success('Supplier updated.');
            }
        }
    } elseif ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE inv_suppliers SET is_active = FALSE, updated_at = NOW() WHERE id = :id")->execute([':id' => $delId]);
        action_log('inventory', 'deactivate_supplier', 'success', ['id' => $delId]);
        flash_success('Supplier deactivated.');
    }
    header('Location: ' . BASE_URL . '/modules/inventory/suppliers');
    exit;
}

$suppliers = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM inv_items i WHERE i.supplier_id = s.id AND i.is_active = TRUE) AS item_count
    FROM inv_suppliers s WHERE s.is_active = TRUE ORDER BY s.name ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Suppliers</h1>
      <p class="text-sm text-gray-500"><?= count($suppliers) ?> active suppliers</p>
    </div>
    <?php if ($canWrite): ?>
    <button type="button" onclick="openSupForm()" class="btn btn-primary">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Add Supplier
    </button>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php if (empty($suppliers)): ?>
      <div class="col-span-full bg-white rounded-xl border px-4 py-8 text-center text-gray-400">No suppliers yet.</div>
    <?php endif; ?>
    <?php foreach ($suppliers as $sup): ?>
    <div class="bg-white rounded-xl border p-4 hover:shadow-sm transition">
      <div class="flex items-start justify-between">
        <div>
          <h3 class="font-semibold text-gray-900"><?= htmlspecialchars($sup['name']) ?></h3>
          <?php if ($sup['contact_person']): ?>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($sup['contact_person']) ?></p>
          <?php endif; ?>
        </div>
        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded"><?= $sup['item_count'] ?> items</span>
      </div>
      <div class="mt-3 space-y-1 text-xs text-gray-500">
        <?php if ($sup['phone']): ?><div>Phone: <?= htmlspecialchars($sup['phone']) ?></div><?php endif; ?>
        <?php if ($sup['email']): ?><div>Email: <?= htmlspecialchars($sup['email']) ?></div><?php endif; ?>
        <?php if ($sup['address']): ?><div class="truncate">Address: <?= htmlspecialchars($sup['address']) ?></div><?php endif; ?>
      </div>
      <?php if ($canWrite): ?>
      <div class="flex gap-2 mt-3 pt-3 border-t">
        <button type="button" onclick='editSup(<?= htmlspecialchars(json_encode($sup)) ?>)' class="text-xs text-blue-600 hover:underline">Edit</button>
        <form method="POST" class="inline" onsubmit="return confirm('Deactivate this supplier?')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="id" value="<?= $sup['id'] ?>" />
          <button type="submit" class="text-xs text-red-600 hover:underline">Deactivate</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Supplier Form Modal -->
<div id="supFormModal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
  <div class="absolute inset-0 bg-black/40" onclick="closeSupForm()"></div>
  <div class="relative bg-white rounded-xl shadow-lg w-full max-w-md">
    <div class="px-4 py-3 border-b font-semibold flex items-center justify-between">
      <span id="supFormTitle">Add Supplier</span>
      <button type="button" class="text-gray-400 hover:text-gray-600" onclick="closeSupForm()">&times;</button>
    </div>
    <form method="POST" class="p-4 space-y-3">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
      <input type="hidden" name="action" id="supAction" value="create" />
      <input type="hidden" name="id" id="supId" value="" />
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="supName" class="input-text w-full" required />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
        <input type="text" name="contact_person" id="supContact" class="input-text w-full" />
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
          <input type="text" name="phone" id="supPhone" class="input-text w-full" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input type="email" name="email" id="supEmail" class="input-text w-full" />
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
        <textarea name="address" id="supAddress" rows="2" class="input-text w-full"></textarea>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
        <textarea name="notes" id="supNotes" rows="2" class="input-text w-full"></textarea>
      </div>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn btn-outline" onclick="closeSupForm()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openSupForm() { document.getElementById('supFormModal').classList.remove('hidden'); document.getElementById('supFormModal').classList.add('flex'); }
function closeSupForm() { document.getElementById('supFormModal').classList.add('hidden'); document.getElementById('supFormModal').classList.remove('flex'); }
function editSup(s) {
  document.getElementById('supFormTitle').textContent = 'Edit Supplier';
  document.getElementById('supAction').value = 'update';
  document.getElementById('supId').value = s.id;
  document.getElementById('supName').value = s.name || '';
  document.getElementById('supContact').value = s.contact_person || '';
  document.getElementById('supPhone').value = s.phone || '';
  document.getElementById('supEmail').value = s.email || '';
  document.getElementById('supAddress').value = s.address || '';
  document.getElementById('supNotes').value = s.notes || '';
  openSupForm();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
