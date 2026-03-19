<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$role = strtolower((string)($user['role'] ?? ''));

// Allow JS to hit the same page for JSON payloads so we avoid cross-path redirects
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  require_once __DIR__ . '/api_admin_list.php';
  exit;
}

// Check if user is a department supervisor FIRST (before permission check)
$supervisedDepartments = [];
try {
  $deptStmt = $pdo->prepare('SELECT d.id, d.name FROM department_supervisors ds JOIN departments d ON d.id = ds.department_id WHERE ds.supervisor_user_id = :uid ORDER BY d.name ASC');
  $deptStmt->execute([':uid' => $uid]);
  $supervisedDepartments = $deptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $supervisedDepartments = [];
}

$isDepartmentSupervisor = !empty($supervisedDepartments);
$isAdminOrHR = in_array($role, ['admin', 'hr'], true);
$hasWritePermission = false;

// Check access: Department supervisors bypass permission check
if (!$isDepartmentSupervisor) {
  // Not a department supervisor, check standard permissions
  require_access('leave', 'leave_requests', 'write');
  $hasWritePermission = true; // If require_access didn't exit, user has permission
} else {
  $hasWritePermission = true; // Dept supervisors are allowed
}

// Final guard: allow if any valid path grants access
if (!$isAdminOrHR && !$isDepartmentSupervisor && !$hasWritePermission) {
  flash_error('Leave management is restricted to HR, Admin roles, and Department Supervisors.');
  header('Location: ' . BASE_URL . '/modules/leave/index');
  exit;
}

$pageTitle = 'Leave Management';

// Get leave types for filter
$leaveTypes = leave_get_known_types($pdo);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="space-y-5">
  <!-- Page Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Leave Management</h1>
      <p class="text-sm text-slate-500 mt-0.5">Review, approve, or decline leave requests across your workforce.
        <?php if ($isDepartmentSupervisor && !$isAdminOrHR): ?>
          <span class="text-indigo-600 font-medium">Supervising: <?= implode(', ', array_column($supervisedDepartments, 'name')) ?></span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex items-center gap-2">
      <button id="refreshBtn" class="btn btn-outline">
        <svg class="h-4 w-4 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        Refresh
      </button>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-900" id="totalCount">&mdash;</div>
        <div class="text-xs text-slate-500">Total Requests</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-amber-600" id="pendingCount">&mdash;</div>
        <div class="text-xs text-slate-500">Pending</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-emerald-600" id="approvedCount">&mdash;</div>
        <div class="text-xs text-slate-500">Approved</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-red-600" id="rejectedCount">&mdash;</div>
        <div class="text-xs text-slate-500">Rejected</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-3 p-4">
      <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-400" id="cancelledCount">&mdash;</div>
        <div class="text-xs text-slate-500">Cancelled</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card">
    <div class="card-body">
      <div class="flex flex-col gap-4">
        <!-- Status Filters -->
        <div>
          <label class="mb-2 block text-xs font-medium text-slate-500">Filter by Status</label>
          <div class="flex flex-wrap gap-2" id="statusFilters">
            <button type="button" class="status-filter-btn active" data-status="">All Requests</button>
            <button type="button" class="status-filter-btn" data-status="pending">
              <span class="inline-flex h-2 w-2 rounded-full bg-amber-500"></span> Pending
            </button>
            <button type="button" class="status-filter-btn" data-status="approved">
              <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span> Approved
            </button>
            <button type="button" class="status-filter-btn" data-status="rejected">
              <span class="inline-flex h-2 w-2 rounded-full bg-red-500"></span> Rejected
            </button>
            <button type="button" class="status-filter-btn" data-status="cancelled">
              <span class="inline-flex h-2 w-2 rounded-full bg-slate-400"></span> Cancelled
            </button>
          </div>
        </div>
        <!-- Search and Type Filter -->
        <div class="flex flex-wrap gap-3 items-end">
          <div class="flex-1 min-w-0 sm:min-w-[200px]">
            <label class="block text-xs font-medium text-slate-500 mb-1">Search Employee</label>
            <input type="text" id="searchInput" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Name or employee code..." autocomplete="off">
          </div>
          <div class="min-w-[180px]">
            <label class="block text-xs font-medium text-slate-500 mb-1">Leave Type</label>
            <select id="typeFilter" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All Types</option>
              <?php foreach ($leaveTypes as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(leave_label_for_type($type)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Table -->
  <div class="card relative">
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="hidden absolute inset-0 z-10 flex items-center justify-center bg-white/80 backdrop-blur-sm rounded-xl">
      <div class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-6 py-4 shadow-lg">
        <div class="loader-spinner" style="width: 24px; height: 24px; border-width: 3px;"></div>
        <span class="text-sm font-medium text-slate-700">Loading requests...</span>
      </div>
    </div>

    <div class="card-header flex items-center justify-between flex-wrap gap-2">
      <span class="font-semibold text-slate-800">Leave Requests <span class="text-sm font-normal text-slate-500">(<span id="resultCount">0</span> of <span id="totalResults">0</span> showing)</span></span>
    </div>
    <div class="card-body p-0">
      <div class="overflow-x-auto">
        <table class="table-basic w-full">
          <thead>
            <tr>
              <th>Employee</th>
              <th class="hidden md:table-cell">Department</th>
              <th>Leave Type</th>
              <th>Period</th>
              <th class="hidden lg:table-cell">Days</th>
              <th>Status</th>
              <th class="hidden md:table-cell">Filed</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="resultsTableBody">
            <!-- Results will be inserted here by JavaScript -->
          </tbody>
        </table>
      </div>
    </div>

    <!-- Empty State -->
    <div id="emptyState" class="hidden px-5 py-12 text-center">
      <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
      </svg>
      <h3 class="mt-3 text-sm font-semibold text-slate-900">No leave requests found</h3>
      <p class="mt-1 text-xs text-slate-500">Try adjusting your filters or search criteria.</p>
    </div>

    <!-- Pagination -->
    <div id="paginationContainer" class="hidden border-t border-slate-100 px-5 py-4">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-sm text-slate-600">Page <span id="currentPage">1</span> of <span id="totalPages">1</span></div>
        <div class="flex items-center gap-2">
          <button id="prevPageBtn" class="btn btn-outline text-xs px-3 py-1.5 disabled:opacity-50 disabled:cursor-not-allowed">Previous</button>
          <button id="nextPageBtn" class="btn btn-outline text-xs px-3 py-1.5 disabled:opacity-50 disabled:cursor-not-allowed">Next</button>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.status-filter-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: 0.5rem;
  border: 1px solid #e2e8f0;
  background: white;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #475569;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  cursor: pointer;
}
.status-filter-btn:hover {
  border-color: #cbd5e1;
  background: #f8fafc;
  transform: translateY(-1px);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}
.status-filter-btn.active {
  border-color: #6366f1;
  background: #eef2ff;
  color: #3730a3;
  font-weight: 600;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}
.status-filter-btn:active {
  transform: translateY(0);
}
#loadingOverlay {
  transition: opacity 0.2s ease-in-out;
}
.table-row-enter {
  animation: slideIn 0.3s ease-out;
}
@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>

<script>
(function() {
  'use strict';

  if (!window.__fetchPatched) {
    const __origFetch = window.fetch.bind(window);
    window.fetch = function(resource, init) {
      try {
        const url = resource instanceof Request ? resource.url : resource;
        console.log('🛰 fetch called:', url);
      } catch (err) {
        console.warn('🛰 fetch logging failed:', err);
      }
      return __origFetch(resource, init);
    };
    window.__fetchPatched = true;
  }

  if (!window.__xhrPatched && window.XMLHttpRequest) {
    const OrigXHR = window.XMLHttpRequest;
    const origOpen = OrigXHR.prototype.open;
    OrigXHR.prototype.open = function(method, url) {
      try {
        console.log('🛰 XHR open:', method, url);
      } catch (err) {}
      return origOpen.apply(this, arguments);
    };
    window.__xhrPatched = true;
  }

  if (!window.__beaconPatched && navigator.sendBeacon) {
    const origBeacon = navigator.sendBeacon.bind(navigator);
    navigator.sendBeacon = function(url, data) {
      try {
        console.log('🛰 sendBeacon:', url);
      } catch (err) {}
      return origBeacon(url, data);
    };
    window.__beaconPatched = true;
  }

  if (!window.__historyPatched && window.history && history.replaceState) {
    const wrapHistory = (fnName) => {
      const orig = history[fnName].bind(history);
      history[fnName] = function(state, title, url) {
        try {
          console.log(`🛰 history.${fnName}:`, url);
        } catch (err) {}
        return orig(state, title, url);
      };
    };
    wrapHistory('replaceState');
    if (history.pushState) wrapHistory('pushState');
    window.__historyPatched = true;
  }
  
  // Build absolute URL to avoid mixed content and path issues
  const origin = window.location.origin;
  const path = window.location.pathname || '';
  const basePrefix = path.includes('/modules/leave/') ? path.split('/modules/leave/')[0] : '';
  const normalizedBase = basePrefix.endsWith('/') ? basePrefix.slice(0, -1) : basePrefix;
  const moduleBasePath = `${normalizedBase || ''}/modules/leave`;
  const API_URL = `${origin}${moduleBasePath}/admin`;
  const VIEW_URL = `${origin}${moduleBasePath}/view`;
  
  console.log('Leave Admin JS loaded - version 2025-11-18-v2');
  console.log('window.__baseUrl:', window.__baseUrl);
  console.log('BASE_URL from PHP:', '<?= BASE_URL ?>');
  console.log('API_URL:', API_URL);
  console.log('VIEW_URL:', VIEW_URL);
  
  let currentFilters = {
    status: '',
    type: '',
    search: '',
    page: 1,
    limit: 50
  };
  
  let debounceTimer = null;
  
  // DOM elements
  const searchInput = document.getElementById('searchInput');
  const typeFilter = document.getElementById('typeFilter');
  const statusButtons = document.querySelectorAll('.status-filter-btn');
  const resultsTableBody = document.getElementById('resultsTableBody');
  const loadingOverlay = document.getElementById('loadingOverlay');
  const emptyState = document.getElementById('emptyState');
  const paginationContainer = document.getElementById('paginationContainer');
  const refreshBtn = document.getElementById('refreshBtn');
  const prevPageBtn = document.getElementById('prevPageBtn');
  const nextPageBtn = document.getElementById('nextPageBtn');
  
  // Status badge classes
  const statusBadges = {
    pending: 'inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700',
    approved: 'inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700',
    rejected: 'inline-flex items-center gap-1.5 rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700',
    cancelled: 'inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600'
  };
  
  const statusDots = {
    pending: '<span class="inline-flex h-1.5 w-1.5 rounded-full bg-amber-500"></span>',
    approved: '<span class="inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500"></span>',
    rejected: '<span class="inline-flex h-1.5 w-1.5 rounded-full bg-rose-500"></span>',
    cancelled: '<span class="inline-flex h-1.5 w-1.5 rounded-full bg-slate-400"></span>'
  };
  
  // Initialize
  function init() {
    // Read URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const statusParam = urlParams.get('status') || '';
    
    if (statusParam) {
      currentFilters.status = statusParam;
      updateActiveStatusButton(statusParam);
    }
    
    // Attach event listeners
    statusButtons.forEach(btn => {
      btn.addEventListener('click', handleStatusFilter);
    });
    
    searchInput.addEventListener('input', handleSearchInput);
    typeFilter.addEventListener('change', handleTypeFilter);
    refreshBtn.addEventListener('click', () => loadData());
    prevPageBtn.addEventListener('click', () => changePage(-1));
    nextPageBtn.addEventListener('click', () => changePage(1));
    
    // Initial load
    loadData();
  }
  
  function handleStatusFilter(e) {
    const btn = e.currentTarget;
    const status = btn.dataset.status || '';
    currentFilters.status = status;
    currentFilters.page = 1;
    updateActiveStatusButton(status);
    updateURL();
    loadData();
  }
  
  function handleSearchInput(e) {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      currentFilters.search = e.target.value.trim();
      currentFilters.page = 1;
      updateURL();
      loadData();
    }, 500);
  }
  
  function handleTypeFilter(e) {
    currentFilters.type = e.target.value;
    currentFilters.page = 1;
    updateURL();
    loadData();
  }
  
  function updateActiveStatusButton(status) {
    statusButtons.forEach(btn => {
      const btnStatus = btn.dataset.status || '';
      if (btnStatus === status) {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    });
  }
  
  function updateURL() {
    const params = new URLSearchParams();
    if (currentFilters.status) params.set('status', currentFilters.status);
    if (currentFilters.type) params.set('type', currentFilters.type);
    if (currentFilters.search) params.set('search', currentFilters.search);
    
    const newURL = params.toString() ? `?${params.toString()}` : window.location.pathname;
    window.history.replaceState({}, '', newURL);
  }
  
  function showLoading() {
    loadingOverlay.classList.remove('hidden');
  }
  
  function hideLoading() {
    loadingOverlay.classList.add('hidden');
  }
  
  async function loadData() {
    console.log('📍 loadData() called from:', new Error().stack);
    showLoading();
    
    try {
      const params = new URLSearchParams();
      if (currentFilters.status) params.set('status', currentFilters.status);
      if (currentFilters.type) params.set('type', currentFilters.type);
      if (currentFilters.search) params.set('search', currentFilters.search);
      params.set('page', currentFilters.page);
      params.set('limit', currentFilters.limit);
      params.set('ajax', '1');
      
      const fetchUrl = `${API_URL}?${params.toString()}`;
      console.log('🔍 About to fetch:', fetchUrl);
      console.log('🔍 Fetch URL is absolute?', /^https?:\/\//i.test(fetchUrl));
      
      const response = await fetch(fetchUrl);
      console.log('✅ Fetch completed, status:', response.status);
      
      // Check if response is OK
      if (!response.ok) {
        const errorText = await response.text();
        console.error('HTTP Error:', response.status, errorText);
        showError(`Server error (${response.status}): ${response.statusText}`);
        return;
      }
      
      // Check content type
      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        const responseText = await response.text();
        console.error('Invalid response type:', contentType);
        console.error('Response body:', responseText.substring(0, 500));
        showError('Server returned invalid response format. Check console for details.');
        return;
      }
      
      const result = await response.json();
      
      if (result.success) {
        renderResults(result.data);
        updatePagination(result.pagination);
        updateCounts(result.data.length || 0, result.pagination);
        updateStats(result.stats || {});
      } else {
        console.error('API error:', result.error);
        showError(result.error || 'Failed to load leave requests');
      }
    } catch (error) {
      console.error('Fetch error:', error);
      showError('Network error occurred: ' + error.message);
    } finally {
      hideLoading();
    }
  }
  
  function renderResults(data) {
    if (data.length === 0) {
      resultsTableBody.innerHTML = '';
      emptyState.classList.remove('hidden');
      paginationContainer.classList.add('hidden');
      return;
    }
    
    emptyState.classList.add('hidden');
    
    const html = data.map(item => {
      const badgeClass = statusBadges[item.status] || statusBadges.cancelled;
      const statusDot = statusDots[item.status] || statusDots.cancelled;
      
      return `
        <tr class="hover:bg-slate-50 transition-colors table-row-enter">
          <td class="px-5 py-4">
            <div class="flex flex-col">
              <span class="font-medium text-slate-900">${escapeHtml(item.employee_name)}</span>
              <span class="text-xs text-slate-500">${escapeHtml(item.employee_code)}</span>
            </div>
          </td>
          <td class="px-5 py-4 text-sm text-slate-700 hidden md:table-cell">${escapeHtml(item.department)}</td>
          <td class="px-5 py-4">
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700">
              ${escapeHtml(item.leave_type_label)}
            </span>
          </td>
          <td class="px-5 py-4">
            <div class="flex flex-col text-sm">
              <span class="text-slate-900">${escapeHtml(item.formatted.start_date)}</span>
              <span class="text-slate-500">to ${escapeHtml(item.formatted.end_date)}</span>
            </div>
          </td>
          <td class="px-5 py-4 hidden lg:table-cell">
            <span class="inline-flex items-center gap-1 text-sm font-semibold text-slate-900">
              ${item.total_days.toFixed(1)}
              <span class="text-xs font-normal text-slate-500">days</span>
            </span>
          </td>
          <td class="px-5 py-4">
            <span class="${badgeClass}">
              ${statusDot}
              ${escapeHtml(item.status.charAt(0).toUpperCase() + item.status.slice(1))}
            </span>
          </td>
          <td class="px-5 py-4 text-xs text-slate-500 hidden md:table-cell">${escapeHtml(item.formatted.created_at)}</td>
          <td class="px-5 py-4 text-right">
            <a href="${VIEW_URL}?id=${item.id}" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-indigo-700">
              View
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
            </a>
          </td>
        </tr>
      `;
    }).join('');
    
    resultsTableBody.innerHTML = html;
  }
  
  function updatePagination(pagination) {
    if (pagination.pages <= 1) {
      paginationContainer.classList.add('hidden');
      return;
    }
    
    paginationContainer.classList.remove('hidden');
    document.getElementById('currentPage').textContent = pagination.page;
    document.getElementById('totalPages').textContent = pagination.pages;
    
    prevPageBtn.disabled = pagination.page <= 1;
    nextPageBtn.disabled = pagination.page >= pagination.pages;
  }
  
  function updateCounts(countOnPage, pagination) {
    document.getElementById('resultCount').textContent = countOnPage;
    document.getElementById('totalResults').textContent = pagination.total;
  }
  
  function updateStats(stats) {
    const total = (stats.pending || 0) + (stats.approved || 0) + (stats.rejected || 0) + (stats.cancelled || 0);
    document.getElementById('totalCount').textContent = total.toLocaleString();
    document.getElementById('pendingCount').textContent = (stats.pending || 0).toLocaleString();
    document.getElementById('approvedCount').textContent = (stats.approved || 0).toLocaleString();
    document.getElementById('rejectedCount').textContent = (stats.rejected || 0).toLocaleString();
    document.getElementById('cancelledCount').textContent = (stats.cancelled || 0).toLocaleString();
  }
  
  function changePage(direction) {
    currentFilters.page += direction;
    loadData();
  }
  
  function showError(message) {
    resultsTableBody.innerHTML = `
      <tr>
        <td colspan="8" class="px-5 py-8 text-center">
          <div class="text-rose-600">${escapeHtml(message)}</div>
        </td>
      </tr>
    `;
  }
  
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  // Start the app
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
