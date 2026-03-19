/**
 * Route definitions for HRIS screenshot automation.
 *
 * Each entry: { path, label, requiresAuth (default true), queryParams? }
 * - requiresAuth: false → captured BEFORE login (e.g., login page)
 * - queryParams: pages needing IDs default to id=1
 */

const ROUTES = [
  // ── Pre-auth pages ──────────────────────────────────────────────────────
  { path: '/login', label: 'Login Page', requiresAuth: false },

  // ── Root ────────────────────────────────────────────────────────────────
  { path: '/', label: 'Dashboard' },
  { path: '/unauthorized', label: 'Unauthorized Page' },

  // ── Account ─────────────────────────────────────────────────────────────
  { path: '/modules/account/index', label: 'Account - List' },
  { path: '/modules/account/create', label: 'Account - Create' },
  { path: '/modules/account/edit', label: 'Account - Edit', queryParams: { id: 1 } },
  { path: '/modules/account/permissions', label: 'Account - Permissions', queryParams: { id: 1 } },

  // ── Admin ───────────────────────────────────────────────────────────────
  { path: '/modules/admin/index', label: 'Admin - Index' },
  { path: '/modules/admin/management', label: 'Admin - Management' },
  { path: '/modules/admin/workbench', label: 'Admin - Workbench' },
  { path: '/modules/admin/logs', label: 'Admin - Logs' },
  { path: '/modules/admin/backup', label: 'Admin - Backup' },
  { path: '/modules/admin/audit_trail', label: 'Admin - Audit Trail' },
  { path: '/modules/admin/cutoff-periods', label: 'Admin - Cutoff Periods' },
  { path: '/modules/admin/notification_create', label: 'Admin - Create Notification' },
  { path: '/modules/admin/overtime-rates', label: 'Admin - Overtime Rates' },
  { path: '/modules/admin/system_log', label: 'Admin - System Log' },
  { path: '/modules/admin/access-control/index', label: 'Admin - Access Control' },
  { path: '/modules/admin/access-control/devices', label: 'Admin - AC Devices' },
  { path: '/modules/admin/access-control/logs', label: 'Admin - AC Logs' },
  { path: '/modules/admin/access-control/rules', label: 'Admin - AC Rules' },
  { path: '/modules/admin/access-control/settings', label: 'Admin - AC Settings' },
  { path: '/modules/admin/approval-workflow', label: 'Admin - Approval Workflow' },
  { path: '/modules/admin/approval-workflow/index', label: 'Admin - Approval Workflow Index' },
  { path: '/modules/admin/bir-reports/index', label: 'Admin - BIR Reports' },
  { path: '/modules/admin/branches/index', label: 'Admin - Branches' },
  { path: '/modules/admin/compensation/index', label: 'Admin - Compensation' },
  { path: '/modules/admin/config/index', label: 'Admin - Config' },
  { path: '/modules/admin/leave-defaults/index', label: 'Admin - Leave Defaults' },
  { path: '/modules/admin/leave-entitlements/index', label: 'Admin - Leave Entitlements' },
  { path: '/modules/admin/pdf/index', label: 'Admin - PDF Settings' },
  { path: '/modules/admin/privacy/index', label: 'Admin - Privacy' },
  { path: '/modules/admin/corrections/index', label: 'Admin - Corrections' },
  { path: '/modules/admin/system/index', label: 'Admin - System Index' },
  { path: '/modules/admin/system/archive', label: 'Admin - System Archive' },
  { path: '/modules/admin/system/archive_view', label: 'Admin - Archive View' },
  { path: '/modules/admin/system/connections', label: 'Admin - System Connections' },
  { path: '/modules/admin/system/logs', label: 'Admin - System Logs' },
  { path: '/modules/admin/tools/backup_tables_check', label: 'Admin - Backup Tables Check' },
  { path: '/modules/admin/work-schedules/index', label: 'Admin - Work Schedules' },

  // ── Attendance ──────────────────────────────────────────────────────────
  { path: '/modules/attendance/index', label: 'Attendance - Index' },
  { path: '/modules/attendance/my', label: 'Attendance - My Attendance' },
  { path: '/modules/attendance/create', label: 'Attendance - Create' },
  { path: '/modules/attendance/import', label: 'Attendance - Import' },
  { path: '/modules/attendance/schedule', label: 'Attendance - Schedule' },

  // ── Audit ───────────────────────────────────────────────────────────────
  { path: '/modules/audit/index', label: 'Audit - Index' },

  // ── Auth ────────────────────────────────────────────────────────────────
  { path: '/modules/auth/account', label: 'Auth - My Account' },

  // ── Clinic Records ─────────────────────────────────────────────────────
  { path: '/modules/clinic_records/index', label: 'Clinic Records - Index' },
  { path: '/modules/clinic_records/create', label: 'Clinic Records - Create' },

  // ── Compliance ─────────────────────────────────────────────────────────
  { path: '/modules/compliance/privacy/consent', label: 'Compliance - Privacy Consent' },
  { path: '/modules/compliance/data-export', label: 'Compliance - Data Export' },
  { path: '/modules/compliance/corrections/index', label: 'Compliance - Corrections' },

  // ── Departments ────────────────────────────────────────────────────────
  { path: '/modules/departments/index', label: 'Departments - Index' },
  { path: '/modules/departments/create', label: 'Departments - Create' },
  { path: '/modules/departments/edit', label: 'Departments - Edit', queryParams: { id: 1 } },
  { path: '/modules/departments/supervisors', label: 'Departments - Supervisors' },

  // ── Documents ──────────────────────────────────────────────────────────
  { path: '/modules/documents/index', label: 'Documents - Index' },

  // ── Employees ──────────────────────────────────────────────────────────
  { path: '/modules/employees/index', label: 'Employees - Index' },
  { path: '/modules/employees/view', label: 'Employees - View', queryParams: { id: 1 } },
  { path: '/modules/employees/create', label: 'Employees - Create' },
  { path: '/modules/employees/edit', label: 'Employees - Edit', queryParams: { id: 1 } },

  // ── Inventory ──────────────────────────────────────────────────────────
  { path: '/modules/inventory/index', label: 'Inventory - Index' },
  { path: '/modules/inventory/inventory', label: 'Inventory - Main' },
  { path: '/modules/inventory/items', label: 'Inventory - Items' },
  { path: '/modules/inventory/item_view', label: 'Inventory - Item View', queryParams: { id: 1 } },
  { path: '/modules/inventory/item_form', label: 'Inventory - Item Form' },
  { path: '/modules/inventory/categories', label: 'Inventory - Categories' },
  { path: '/modules/inventory/locations', label: 'Inventory - Locations' },
  { path: '/modules/inventory/suppliers', label: 'Inventory - Suppliers' },
  { path: '/modules/inventory/movements', label: 'Inventory - Movements' },
  { path: '/modules/inventory/reports', label: 'Inventory - Reports' },
  { path: '/modules/inventory/bulk_import', label: 'Inventory - Bulk Import' },
  { path: '/modules/inventory/restock', label: 'Inventory - Restock' },
  { path: '/modules/inventory/manual_update', label: 'Inventory - Manual Update' },
  { path: '/modules/inventory/purchase_orders', label: 'Inventory - Purchase Orders' },
  { path: '/modules/inventory/pos', label: 'Inventory - POS' },
  { path: '/modules/inventory/pos_management', label: 'Inventory - POS Management' },
  { path: '/modules/inventory/transactions', label: 'Inventory - Transactions' },
  { path: '/modules/inventory/print_server', label: 'Inventory - Print Server' },
  { path: '/modules/inventory/receipt_settings', label: 'Inventory - Receipt Settings' },

  // ── Leave ──────────────────────────────────────────────────────────────
  { path: '/modules/leave/index', label: 'Leave - Index' },
  { path: '/modules/leave/create', label: 'Leave - Create' },
  { path: '/modules/leave/admin', label: 'Leave - Admin' },

  // ── Memos ──────────────────────────────────────────────────────────────
  { path: '/modules/memos/index', label: 'Memos - Index' },
  { path: '/modules/memos/create', label: 'Memos - Create' },
  { path: '/modules/memos/view', label: 'Memos - View', queryParams: { id: 1 } },

  // ── Notifications ──────────────────────────────────────────────────────
  { path: '/modules/notifications/index', label: 'Notifications - Index' },

  // ── Overtime ───────────────────────────────────────────────────────────
  { path: '/modules/overtime/index', label: 'Overtime - Index' },
  { path: '/modules/overtime/create', label: 'Overtime - Create' },
  { path: '/modules/overtime/admin', label: 'Overtime - Admin' },

  // ── Payroll ────────────────────────────────────────────────────────────
  { path: '/modules/payroll/index', label: 'Payroll - Index' },
  { path: '/modules/payroll/run_create', label: 'Payroll - Create Run' },
  { path: '/modules/payroll/my_payslips', label: 'Payroll - My Payslips' },
  { path: '/modules/payroll/dtr_upload', label: 'Payroll - DTR Upload' },

  // ── Performance ────────────────────────────────────────────────────────
  { path: '/modules/performance/index', label: 'Performance - Index' },

  // ── Positions ──────────────────────────────────────────────────────────
  { path: '/modules/positions/index', label: 'Positions - Index' },
  { path: '/modules/positions/create', label: 'Positions - Create' },
  { path: '/modules/positions/edit', label: 'Positions - Edit', queryParams: { id: 1 } },
  { path: '/modules/positions/permissions', label: 'Positions - Permissions', queryParams: { id: 1 } },

  // ── Recruitment ────────────────────────────────────────────────────────
  { path: '/modules/recruitment/index', label: 'Recruitment - Index' },
  { path: '/modules/recruitment/create', label: 'Recruitment - Create' },
  { path: '/modules/recruitment/templates', label: 'Recruitment - Templates' },
];

module.exports = ROUTES;
