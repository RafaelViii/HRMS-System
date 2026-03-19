<?php
/**
 * Personal Information Tab
 * Employee edit module - personal details section
 */
?>

<div id="tab-personal" class="tab-content <?= $activeTab === 'personal' ? 'active' : '' ?>">
  <div class="info-card">
    <div class="info-card-header">
      <div>
        <h2 class="info-card-title">Personal Information</h2>
        <p class="info-card-subtitle">Basic employee details and contact information</p>
      </div>
    </div>

    <form method="post" class="space-y-6">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="employee_details">

      <!-- Basic Info Grid -->
      <div class="grid gap-6 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Employee Code <span class="text-red-500">*</span>
          </label>
          <input 
            name="employee_code" 
            type="text"
            class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
            value="<?= htmlspecialchars($emp['employee_code'] ?? '') ?>" 
            required
          >
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Email <span class="text-red-500">*</span>
          </label>
          <input 
            name="email" 
            type="email" 
            class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
            value="<?= htmlspecialchars($emp['email'] ?? '') ?>" 
            required
          >
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            First Name <span class="text-red-500">*</span>
          </label>
          <input 
            name="first_name" 
            type="text"
            class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
            value="<?= htmlspecialchars($emp['first_name'] ?? '') ?>" 
            required
          >
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Last Name <span class="text-red-500">*</span>
          </label>
          <input 
            name="last_name" 
            type="text"
            class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
            value="<?= htmlspecialchars($emp['last_name'] ?? '') ?>" 
            required
          >
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Phone
          </label>
          <input 
            name="phone" 
            type="tel"
            class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
            value="<?= htmlspecialchars($emp['phone'] ?? '') ?>"
          >
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Branch
          </label>
          <select 
            name="branch_id" 
            class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          >
            <option value="">— None —</option>
            <?php foreach ($branches as $b): ?>
              <?php $bId = (int)($b['id'] ?? 0); ?>
              <option value="<?= $bId ?>" <?= (int)($emp['branch_id'] ?? 0) === $bId ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['name'] ?? '') ?><?= isset($b['code']) && $b['code'] !== '' ? ' (' . htmlspecialchars($b['code']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Address -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Address
        </label>
        <input 
          name="address" 
          type="text"
          class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
          value="<?= htmlspecialchars($emp['address'] ?? '') ?>"
        >
      </div>

      <!-- Employment Details Grid -->
      <div class="pt-6 border-t border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Employment Details</h3>
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Department
            </label>
            <select 
              name="department_id" 
              class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="">— None —</option>
              <?php foreach ($deps as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $emp['department_id']==$d['id']?'selected':'' ?>>
                  <?= htmlspecialchars($d['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Position
            </label>
            <select 
              name="position_id" 
              class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="">— None —</option>
              <?php foreach ($poses as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $emp['position_id']==$p['id']?'selected':'' ?>>
                  <?= htmlspecialchars($p['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Hire Date
            </label>
            <input 
              type="date" 
              name="hire_date" 
              class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
              value="<?= htmlspecialchars($emp['hire_date'] ?? '') ?>"
            >
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Employment Type
            </label>
            <select 
              name="employment_type" 
              class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <?php foreach (['regular','probationary','contract','part-time'] as $t): ?>
                <option value="<?= $t ?>" <?= $emp['employment_type']==$t?'selected':'' ?>>
                  <?= ucfirst($t) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Status
            </label>
            <select 
              name="status" 
              class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <?php foreach (['active','terminated','resigned','on-leave'] as $s): ?>
                <option value="<?= $s ?>" <?= $emp['status']===$s?'selected':'' ?>>
                  <?= ucfirst($s) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

        </div>
      </div>

      <!-- Government IDs & Banking -->
      <div class="pt-6 border-t border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900 mb-1 flex items-center gap-2">
          <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          Government IDs &amp; Banking
        </h3>
        <p class="text-sm text-gray-500 mb-4">Sensitive data is encrypted at rest</p>
        <?php $empDecrypted = decrypt_employee($emp); ?>
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">SSS Number</label>
            <input name="sss_number" type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" value="<?= htmlspecialchars($empDecrypted['sss_number'] ?? '') ?>" placeholder="00-0000000-0">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">PhilHealth Number</label>
            <input name="philhealth_number" type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" value="<?= htmlspecialchars($empDecrypted['philhealth_number'] ?? '') ?>" placeholder="00-000000000-0">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Pag-IBIG Number</label>
            <input name="pagibig_number" type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" value="<?= htmlspecialchars($empDecrypted['pagibig_number'] ?? '') ?>" placeholder="0000-0000-0000">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">TIN</label>
            <input name="tin" type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" value="<?= htmlspecialchars($empDecrypted['tin'] ?? '') ?>" placeholder="000-000-000-000">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Bank Account Number</label>
            <input name="bank_account_number" type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" value="<?= htmlspecialchars($empDecrypted['bank_account_number'] ?? '') ?>">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Bank Name</label>
            <input name="bank_name" type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent" value="<?= htmlspecialchars($empDecrypted['bank_name'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="flex flex-wrap gap-3 pt-6 border-t border-gray-200">
        <button type="submit" class="btn btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
          Save Changes
        </button>
        <a href="<?= BASE_URL ?>/modules/employees/view?id=<?= $id ?>" class="btn btn-outline">
          Cancel
        </a>
      </div>
    </form>
  </div>
</div>
