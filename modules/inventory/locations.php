<?php
/**
 * Inventory Storage Locations - CRUD
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_access('inventory', 'inventory_items', 'read');

$pdo = get_db_conn();
$pageTitle = 'Storage Locations';
$uid = (int)($_SESSION['user']['id'] ?? 0);
$canWrite = user_has_access($uid, 'inventory', 'inventory_items', 'write');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    require_access('inventory', 'inventory_items', 'write');
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        if ($name === '') { flash_error('Location name is required.'); }
        else {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO inv_locations (name, description, created_by) VALUES (:n, :d, :uid)")
                    ->execute([':n'=>$name, ':d'=>$description, ':uid'=>$uid]);
                action_log('inventory', 'create_location', 'success', ['name' => $name]);
                flash_success('Location created.');
            } else {
                $editId = (int)($_POST['id'] ?? 0);
                $pdo->prepare("UPDATE inv_locations SET name=:n, description=:d, updated_at=NOW() WHERE id=:id")
                    ->execute([':n'=>$name, ':d'=>$description, ':id'=>$editId]);
                action_log('inventory', 'update_location', 'success', ['id' => $editId]);
                flash_success('Location updated.');
            }
        }
    } elseif ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE inv_locations SET is_active = FALSE WHERE id = :id")->execute([':id' => $delId]);
        action_log('inventory', 'deactivate_location', 'success', ['id' => $delId]);
        flash_success('Location deactivated.');
    }
    header('Location: ' . BASE_URL . '/modules/inventory/locations');
    exit;
}

$locations = $pdo->query("SELECT l.*, (SELECT COUNT(*) FROM inv_items i WHERE i.location_id = l.id AND i.is_active = TRUE) AS item_count
    FROM inv_locations l WHERE l.is_active = TRUE ORDER BY l.name ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-4">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-semibold text-gray-900">Storage Locations</h1>
      <p class="text-sm text-gray-500">Manage storage rooms, shelves, and areas</p>
    </div>
    <?php if ($canWrite): ?>
    <button type="button" onclick="openLocForm()" class="btn btn-primary">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Add Location
    </button>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-xl border overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
        <tr>
          <th class="px-4 py-3 text-left">Location Name</th>
          <th class="px-4 py-3 text-left">Description</th>
          <th class="px-4 py-3 text-right">Items Stored</th>
          <?php if ($canWrite): ?><th class="px-4 py-3 text-center">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php if (empty($locations)): ?>
          <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No locations yet.</td></tr>
        <?php else: ?>
          <?php foreach ($locations as $loc): ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-2.5 font-medium text-gray-900"><?= htmlspecialchars($loc['name']) ?></td>
            <td class="px-4 py-2.5 text-gray-500"><?= htmlspecialchars($loc['description'] ?? '-') ?></td>
            <td class="px-4 py-2.5 text-right"><?= $loc['item_count'] ?></td>
            <?php if ($canWrite): ?>
            <td class="px-4 py-2.5 text-center">
              <div class="flex items-center justify-center gap-1">
                <button type="button" class="p-1.5 rounded hover:bg-gray-100 text-gray-500 hover:text-blue-600" title="Edit"
                  onclick='editLoc(<?= htmlspecialchars(json_encode($loc)) ?>)'>
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
                <form method="POST" class="inline" onsubmit="return confirm('Deactivate this location?')">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
                  <input type="hidden" name="action" value="delete" />
                  <input type="hidden" name="id" value="<?= $loc['id'] ?>" />
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

<!-- Location Form Modal -->
<div id="locFormModal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
  <div class="absolute inset-0 bg-black/40" onclick="closeLocForm()"></div>
  <div class="relative bg-white rounded-xl shadow-lg w-full max-w-md">
    <div class="px-4 py-3 border-b font-semibold flex items-center justify-between">
      <span id="locFormTitle">Add Location</span>
      <button type="button" class="text-gray-400 hover:text-gray-600" onclick="closeLocForm()">&times;</button>
    </div>
    <form method="POST" class="p-4 space-y-3">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>" />
      <input type="hidden" name="action" id="locAction" value="create" />
      <input type="hidden" name="id" id="locId" value="" />
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="locName" class="input-text w-full" required />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea name="description" id="locDesc" rows="2" class="input-text w-full"></textarea>
      </div>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn btn-outline" onclick="closeLocForm()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openLocForm() { document.getElementById('locFormModal').classList.remove('hidden'); document.getElementById('locFormModal').classList.add('flex'); }
function closeLocForm() { document.getElementById('locFormModal').classList.add('hidden'); document.getElementById('locFormModal').classList.remove('flex'); }
function editLoc(l) {
  document.getElementById('locFormTitle').textContent = 'Edit Location';
  document.getElementById('locAction').value = 'update';
  document.getElementById('locId').value = l.id;
  document.getElementById('locName').value = l.name || '';
  document.getElementById('locDesc').value = l.description || '';
  openLocForm();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
