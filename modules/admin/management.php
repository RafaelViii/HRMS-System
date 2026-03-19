<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'admin_hub', 'read');
require_once __DIR__ . '/../../includes/utils.php';

$me = $_SESSION['user'] ?? null;
$isAdmin = $me && strtolower((string)($me['role'] ?? '')) === 'admin';
action_log('admin', 'view_management_hub');

$cards = [
    [
        'title' => 'Account Manager',
        'description' => 'Invite, disable, and reset user access in one place.',
        'href' => BASE_URL . '/modules/account/index',
        'availability' => 'Admin & HR',
        'icon' => 'users',
        'requireAdmin' => false,
    ],
    [
        'title' => 'Branch Directory',
        'description' => 'Create and maintain company branches for payroll submissions and reporting.',
        'href' => BASE_URL . '/modules/admin/branches',
        'availability' => 'Admins & HR',
        'icon' => 'building',
        'requireAdmin' => false,
    ],
    [
        'title' => 'Cutoff Periods',
        'description' => 'Define payroll cutoff windows and attendance calculation periods.',
        'href' => BASE_URL . '/modules/admin/cutoff-periods',
        'availability' => 'Admins & HR',
        'icon' => 'calendar',
        'requireAdmin' => false,
    ],
    [
        'title' => 'Overtime & Holiday Rates',
        'description' => 'Configure system-wide default overtime and holiday pay multipliers used in payroll.',
        'href' => BASE_URL . '/modules/admin/overtime-rates',
        'availability' => 'Admins',
        'icon' => 'overtime',
        'requireAdmin' => true,
    ],
    [
        'title' => 'Allowance Types',
        'description' => 'Configure standard allowance templates applied to employee compensation.',
        'href' => BASE_URL . '/modules/admin/compensation?tab=allowances',
        'availability' => 'Admins & HR',
        'icon' => 'coins-plus',
        'requireAdmin' => false,
    ],
    [
        'title' => 'Contribution Types',
        'description' => 'Manage statutory and voluntary contribution/deduction templates.',
        'href' => BASE_URL . '/modules/admin/compensation?tab=contributions',
        'availability' => 'Admins & HR',
        'icon' => 'coins-minus',
        'requireAdmin' => false,
    ],
    [
        'title' => 'Notification Broadcasts',
        'description' => 'Push company-wide announcements with acknowledgment tracking.',
        'href' => BASE_URL . '/modules/admin/notification_create',
        'availability' => 'Admins',
        'icon' => 'megaphone',
        'requireAdmin' => true,
    ],
    [
        'title' => 'Work Schedules',
        'description' => 'Configure schedule templates and assign employees.',
        'href' => BASE_URL . '/modules/admin/work-schedules/index',
        'availability' => 'Admins & HR',
        'icon' => 'clock',
        'requireAdmin' => false,
    ],
    [
        'title' => 'Template Library',
        'description' => 'Manage PDF/layout templates used for official employee documents.',
        'href' => BASE_URL . '/modules/admin/pdf/index',
        'availability' => 'Admins',
        'icon' => 'layers',
        'requireAdmin' => true,
        'external' => true,
    ],
    [
        'title' => 'Access Control',
        'description' => 'Manage device bindings, IP restrictions, and module access with whitelist/blacklist rules.',
        'href' => BASE_URL . '/modules/admin/access-control/index',
        'availability' => 'Admins',
        'icon' => 'access-control',
        'requireAdmin' => true,
        'section' => 'Security',
    ],
    // ── Compliance & Reports ──────────────────────────────────────────────
    [
        'title' => 'BIR Reports',
        'description' => 'Generate and manage Bureau of Internal Revenue compliance reports.',
        'href' => BASE_URL . '/modules/admin/bir-reports/index',
        'availability' => 'Admins & HR',
        'icon' => 'chart-bar',
        'requireAdmin' => false,
        'section' => 'Compliance & Reports',
    ],
    [
        'title' => 'Data Corrections',
        'description' => 'Review and process employee data correction requests.',
        'href' => BASE_URL . '/modules/admin/corrections/index',
        'availability' => 'Admins & HR',
        'icon' => 'pencil-edit',
        'requireAdmin' => false,
        'section' => 'Compliance & Reports',
    ],
    [
        'title' => 'Privacy & Compliance',
        'description' => 'Manage privacy consent records, data erasure requests, and compliance settings.',
        'href' => BASE_URL . '/modules/admin/privacy/index',
        'availability' => 'Admins',
        'icon' => 'shield-check',
        'requireAdmin' => true,
        'section' => 'Compliance & Reports',
    ],
    [
        'title' => 'Backup Toolkit',
        'description' => 'Kick off quick database backups before major rollouts.',
        'href' => BASE_URL . '/modules/admin/backup',
        'availability' => 'Admins',
        'icon' => 'shield-check',
        'requireAdmin' => true,
    ],
    // ── Inventory Management ──────────────────────────────────────────────
    [
        'title' => 'Item Categories',
        'description' => 'Create and manage product categories for inventory organization.',
        'href' => BASE_URL . '/modules/inventory/categories',
        'availability' => 'Admins & HR',
        'icon' => 'tag',
        'requireAdmin' => false,
        'section' => 'Inventory Management',
    ],
    [
        'title' => 'Suppliers',
        'description' => 'Manage supplier contacts, terms, and supply chain details.',
        'href' => BASE_URL . '/modules/inventory/suppliers',
        'availability' => 'Admins & HR',
        'icon' => 'truck',
        'requireAdmin' => false,
        'section' => 'Inventory Management',
    ],
    [
        'title' => 'Storage Locations',
        'description' => 'Define warehouses, shelves, and storage areas for inventory tracking.',
        'href' => BASE_URL . '/modules/inventory/locations',
        'availability' => 'Admins & HR',
        'icon' => 'map-pin',
        'requireAdmin' => false,
        'section' => 'Inventory Management',
    ],
    // ── POS Configuration ────────────────────────────────────────────────
    [
        'title' => 'POS Management',
        'description' => 'Configure payment types, discount rules, and POS terminal settings.',
        'href' => BASE_URL . '/modules/inventory/pos_management',
        'availability' => 'Admins',
        'icon' => 'pos-settings',
        'requireAdmin' => true,
        'section' => 'POS Configuration',
    ],
    [
        'title' => 'Receipt Settings',
        'description' => 'Customize receipt headers, footers, logos, and print layout.',
        'href' => BASE_URL . '/modules/inventory/receipt_settings',
        'availability' => 'Admins',
        'icon' => 'receipt',
        'requireAdmin' => true,
        'section' => 'POS Configuration',
    ],
    // ── Healthcare ────────────────────────────────────────────────────────
    [
        'title' => 'Clinic Records',
        'description' => 'Manage nurse and medtech clinic service logs, patient records, and healthcare tracking.',
        'href' => BASE_URL . '/modules/clinic_records/index',
        'availability' => 'Admins & HR',
        'icon' => 'clinic',
        'requireAdmin' => false,
        'section' => 'Healthcare',
    ],
];

$cards = array_filter($cards, static function (array $card) use ($isAdmin) {
    if (!empty($card['requireAdmin']) && !$isAdmin) {
        return false;
    }
    return true;
});

$pageTitle = 'Admin Management Hub';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Hero Header -->
  <div class="rounded-xl bg-gradient-to-br from-slate-900 via-indigo-900 to-blue-900 p-6 text-white shadow-lg">
    <div class="flex items-start justify-between">
      <div>
        <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-white/75">
          Management Hub
        </div>
        <h1 class="text-2xl font-semibold">Centralize day-to-day administration.</h1>
        <p class="mt-1 text-sm text-white/70">Manage user access, configure compensation, broadcast announcements, and prep artifacts before a rollout.</p>
      </div>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Available tools</div>
        <div class="mt-1 text-sm font-semibold"><?= count($cards) ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Access level</div>
        <div class="mt-1 text-sm font-semibold"><?= $isAdmin ? 'Full Admin' : 'HR Partner' ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Who can access</div>
        <div class="mt-1 text-sm font-semibold"><?= $isAdmin ? 'Admins & HR' : 'HR Partners' ?></div>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <div class="text-xs text-white/60">Quick link</div>
        <div class="mt-1 text-sm font-semibold"><a href="<?= BASE_URL ?>/modules/admin/index" class="underline underline-offset-2 hover:text-white/90">HR Admin →</a></div>
      </div>
    </div>
  </div>

  <!-- Search Bar -->
  <div class="relative">
    <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none">
      <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    </div>
    <input
      id="hubSearchInput"
      type="text"
      class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-11 pr-10 text-sm shadow-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 transition"
      placeholder="Search tools — e.g. payroll, branches, overtime, backup..."
      autocomplete="off"
    >
    <button id="hubSearchClear" class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-slate-600 transition hidden" type="button">
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
  </div>

  <!-- No search results message -->
  <div id="hubNoResults" class="hidden card card-body text-center py-10">
    <svg class="mx-auto h-10 w-10 text-slate-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    <p class="text-sm font-medium text-slate-500">No tools match your search.</p>
    <p class="text-xs text-slate-400 mt-1">Try different keywords or <button type="button" class="text-indigo-600 hover:underline" onclick="document.getElementById('hubSearchInput').value='';document.getElementById('hubSearchInput').dispatchEvent(new Event('input'));">clear the filter</button></p>
  </div>

  <?php if (!$cards): ?>
    <section class="card p-6 text-sm text-gray-600">
      No management utilities available for your role.
    </section>
  <?php else: ?>
    <?php
      // Group cards by section
      $grouped = [];
      foreach ($cards as $card) {
          $section = $card['section'] ?? 'Management Tools';
          $grouped[$section][] = $card;
      }
    ?>
    <?php foreach ($grouped as $sectionName => $sectionCards): ?>
    <section class="space-y-3" data-hub-section>
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500"><?= htmlspecialchars($sectionName) ?></h2>
        <span class="text-xs text-gray-400" data-hub-section-count><?= count($sectionCards) ?> tools</span>
      </div>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($sectionCards as $card): ?>
          <a href="<?= htmlspecialchars($card['href']) ?>" class="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-lg" data-hub-card data-hub-search="<?= htmlspecialchars(strtolower($card['title'] . ' ' . $card['description'] . ' ' . ($card['availability'] ?? '') . ' ' . ($card['section'] ?? 'management tools'))) ?>" <?= !empty($card['external']) ? 'target="_blank" rel="noopener"' : '' ?>>
            <div class="flex items-start justify-between gap-3">
              <div class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                <?php switch ($card['icon']) {
                  case 'users': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2" /><circle cx="9" cy="7" r="4" /><path stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 00-3-3.87" /><path stroke-linecap="round" stroke-linejoin="round" d="M16 3.13a4 4 0 010 7.75" /></svg>
                    <?php break;
                  case 'building': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21V7a2 2 0 012-2h4V3a1 1 0 011-1h6a1 1 0 011 1v2h4a2 2 0 012 2v14" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 21V9h6v12" /><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18" /></svg>
                    <?php break;
                  case 'calendar': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2" ry="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /></svg>
                    <?php break;
                  case 'coins-plus': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="7" rx="6" ry="3" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 7v6c0 1.66 2.69 3 6 3s6-1.34 6-3V7" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 10c0 1.66 2.69 3 6 3s6-1.34 6-3" /><path stroke-linecap="round" stroke-linejoin="round" d="M19 16v3m0 0v3m0-3h3m-3 0h-3" /></svg>
                    <?php break;
                  case 'coins-minus': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="7" rx="6" ry="3" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 7v6c0 1.66 2.69 3 6 3s6-1.34 6-3V7" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 10c0 1.66 2.69 3 6 3s6-1.34 6-3" /><path stroke-linecap="round" stroke-linejoin="round" d="M16 19h6" /></svg>
                    <?php break;
                  case 'megaphone': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10v4" /><path stroke-linecap="round" stroke-linejoin="round" d="M7 9l10-5v16l-10-5" /><path stroke-linecap="round" stroke-linejoin="round" d="M7 9v6" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 006 0" /></svg>
                    <?php break;
                  case 'clock': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3" /></svg>
                    <?php break;
                  case 'layers': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l10 5-10 5-10-5z" /><path stroke-linecap="round" stroke-linejoin="round" d="M22 13l-10 5-10-5" /><path stroke-linecap="round" stroke-linejoin="round" d="M22 18l-10 5-10-5" /></svg>
                    <?php break;
                  case 'shield-check': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4" /></svg>
                    <?php break;
                  case 'chart-bar': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    <?php break;
                  case 'pencil-edit': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                    <?php break;
                  case 'access-control': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                    <?php break;
                  case 'tag': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
                    <?php break;
                  case 'truck': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M1 3h15v13H1zM16 8h4l3 3v5h-7V8z" /><circle cx="5.5" cy="18.5" r="2.5" /><circle cx="18.5" cy="18.5" r="2.5" /></svg>
                    <?php break;
                  case 'map-pin': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <?php break;
                  case 'pos-settings': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3" /><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /></svg>
                    <?php break;
                  case 'receipt': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12l3-2 3 2 3-2 3 2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 5h6M9 14h6M9 10h6" /></svg>
                    <?php break;
                  case 'clinic': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>
                    <?php break;
                  case 'overtime': ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 2.5l2 2M7.5 2.5l-2 2"/></svg>
                    <?php break;
                  default: ?>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" /></svg>
                <?php } ?>
              </div>
              <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500"><?= htmlspecialchars($card['availability']) ?></span>
            </div>
            <h3 class="mt-4 text-lg font-semibold text-gray-900 transition group-hover:text-indigo-600"><?= htmlspecialchars($card['title']) ?></h3>
            <p class="mt-2 text-sm text-gray-600"><?= htmlspecialchars($card['description']) ?></p>
            <div class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-indigo-600">
              <span>Open tool</span>
              <span class="transition group-hover:translate-x-0.5">→</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  const input = document.getElementById('hubSearchInput');
  const clearBtn = document.getElementById('hubSearchClear');
  const noResults = document.getElementById('hubNoResults');
  if (!input) return;

  function filterCards() {
    const q = input.value.trim().toLowerCase();
    clearBtn.classList.toggle('hidden', q.length === 0);
    const sections = document.querySelectorAll('[data-hub-section]');
    let totalVisible = 0;

    sections.forEach(function(section) {
      const cards = section.querySelectorAll('[data-hub-card]');
      let sectionVisible = 0;
      cards.forEach(function(card) {
        const text = card.getAttribute('data-hub-search') || '';
        const match = !q || text.includes(q);
        card.style.display = match ? '' : 'none';
        if (match) sectionVisible++;
      });
      section.style.display = sectionVisible > 0 ? '' : 'none';
      const countEl = section.querySelector('[data-hub-section-count]');
      if (countEl && q) {
        countEl.textContent = sectionVisible + ' of ' + cards.length + ' tools';
      } else if (countEl) {
        countEl.textContent = cards.length + ' tools';
      }
      totalVisible += sectionVisible;
    });

    noResults.classList.toggle('hidden', totalVisible > 0 || !q);
  }

  input.addEventListener('input', filterCards);
  clearBtn.addEventListener('click', function() {
    input.value = '';
    filterCards();
    input.focus();
  });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
