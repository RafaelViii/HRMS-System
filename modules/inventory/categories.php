<?php
/**
 * Inventory Categories - CRUD
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'read');

$pdo = get_db_conn();
$pageTitle = 'Item Categories';
$uid = (int)($_SESSION['user']['id'] ?? 0);
$canWrite = user_has_access($uid, 'inventory', 'inventory_items', 'write');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    require_access('inventory', 'inventory_items', 'write');

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        $parentId = ((int)($_POST['parent_id'] ?? 0)) ?: null;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') {
            flash_error('Category name is required.');
        } else {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO inv_categories (name, description, parent_id, sort_order, created_by) VALUES (:n, :d, :p, :s, :uid)")
                    ->execute([':n'=>$name, ':d'=>$description, ':p'=>$parentId, ':s'=>$sortOrder, ':uid'=>$uid]);
                action_log('inventory', 'create_category', 'success', ['name' => $name]);
                flash_success('Category created.');
            } else {
                $editId = (int)($_POST['id'] ?? 0);
                $pdo->prepare("UPDATE inv_categories SET name=:n, description=:d, parent_id=:p, sort_order=:s, updated_at=NOW() WHERE id=:id")
                    ->execute([':n'=>$name, ':d'=>$description, ':p'=>$parentId, ':s'=>$sortOrder, ':id'=>$editId]);
                action_log('inventory', 'update_category', 'success', ['id' => $editId]);
                flash_success('Category updated.');
            }
        }
    } elseif ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE inv_categories SET is_active = FALSE, updated_at = NOW() WHERE id = :id")->execute([':id' => $delId]);
        action_log('inventory', 'deactivate_category', 'success', ['id' => $delId]);
        flash_success('Category deactivated.');
    }

    header('Location: ' . BASE_URL . '/modules/inventory/categories');
    exit;
}

$categories = $pdo->query("SELECT c.*, p.name AS parent_name, (SELECT COUNT(*) FROM inv_items i WHERE i.category_id = c.id AND i.is_active = TRUE) AS item_count
    FROM inv_categories c LEFT JOIN inv_categories p ON p.id = c.parent_id
    WHERE c.is_active = TRUE ORDER BY c.sort_order ASC, c.name ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Item Categories</h1>
      <p class="text-sm text-gray-500"><?= count($categories) ?> categories</p>
    </div>
    <?php if ($canWrite): ?>
    <button type="button" onclick="document.getElementById('catFormModal').classList.remove('hidden');document.getElementById('catFormModal').classList.add('flex');" class="btn btn-primary">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Add Category
    </button>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-xl border overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
        <tr>
          <th class="px-4 py-3 text-left">Name</th>
          <th class="px-4 py-3 text-left">Parent</th>
          <th class="px-4 py-3 text-left">Description</th>
          <th class="px-4 py-3 text-right">Items</th>
          <th class="px-4 py-3 text-right">Order</th>
          <?php if ($canWrite): ?><th class="px-4 py-3 text-center">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php if (empty($categories)): ?>
          <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No categories yet.</td></tr>
        <?php else: ?>
          <?php foreach ($categories as $cat): ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-2.5 font-medium text-gray-900"><?= htmlspecialchars($cat['name']) ?></td>
            <td class="px-4 py-2.5 text-gray-500"><?= htmlspecialchars($cat['parent_name'] ?? '-') ?></td>
            <td class="px-4 py-2.5 text-gray-500 max-w-xs truncate"><?= htmlspecialchars($cat['description'] ?? '-') ?></td>
            <td class="px-4 py-2.5 text-right"><?= $cat['item_count'] ?></td>
            <td class="px-4 py-2.5 text-right text-gray-400"><?= $cat['sort_order'] ?></td>
            <?php if ($canWrite): ?>
            <td class="px-4 py-2.5 text-center">
              <div class="flex items-center justify-center gap-1">
                <button type="button" class="p-1.5 rounded hover:bg-gray-100 text-gray-500 hover:text-blue-600" title="Edit"
                  onclick="editCat(<?= htmlspecialchars(json_encode($cat)) ?>)">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
                <form method="POST" class="inline" onsubmit="return confirm('Deactivate this category?')">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
                  <input type="hidden" name="action" value="delete" />
                  <input type="hidden" name="id" value="<?= $cat['id'] ?>" />
                  <button type="submit" class="p-1.5 rounded hover:bg-red-50 text-gray-400 hover:text-red-600" title="Deactivate">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </form>
              </div>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Category Form Modal -->
<div id="catFormModal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
  <div class="absolute inset-0 bg-black/40" onclick="document.getElementById('catFormModal').classList.add('hidden');document.getElementById('catFormModal').classList.remove('flex');"></div>
  <div class="relative bg-white rounded-xl shadow-lg w-full max-w-md">
    <div class="px-4 py-3 border-b font-semibold flex items-center justify-between">
      <span id="catFormTitle">Add Category</span>
      <button type="button" class="text-gray-400 hover:text-gray-600" onclick="document.getElementById('catFormModal').classList.add('hidden');document.getElementById('catFormModal').classList.remove('flex');">&times;</button>
    </div>
    <form method="POST" class="p-4 space-y-3">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
      <input type="hidden" name="action" id="catAction" value="create" />
      <input type="hidden" name="id" id="catId" value="" />
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="catName" class="input-text w-full" required />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Parent Category</label>
        <select name="parent_id" id="catParent" class="input-text w-full">
          <option value="">-- None (Top Level) --</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea name="description" id="catDesc" rows="2" class="input-text w-full"></textarea>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
        <input type="number" name="sort_order" id="catSort" value="0" class="input-text w-full" />
      </div>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('catFormModal').classList.add('hidden');document.getElementById('catFormModal').classList.remove('flex');">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function editCat(cat) {
  document.getElementById('catFormTitle').textContent = 'Edit Category';
  document.getElementById('catAction').value = 'update';
  document.getElementById('catId').value = cat.id;
  document.getElementById('catName').value = cat.name;
  document.getElementById('catParent').value = cat.parent_id || '';
  document.getElementById('catDesc').value = cat.description || '';
  document.getElementById('catSort').value = cat.sort_order || 0;
  document.getElementById('catFormModal').classList.remove('hidden');
  document.getElementById('catFormModal').classList.add('flex');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
