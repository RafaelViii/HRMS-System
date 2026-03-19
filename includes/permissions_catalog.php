<?php
/**
 * Position-Based Access Control: Permissions Catalog
 * 
 * This file defines all access-controlled resources in the system.
 * Each resource is organized by domain and includes metadata for UI display.
 * 
 * Access Levels:
 * - none: No access
 * - read: View/list/download only
 * - write: Create/update (includes read)
 * - manage: Full control including delete and sensitive operations (includes write)
 */

if (!defined('BASE_URL')) {
    die('Direct access not permitted');
}

/**
 * Complete permissions catalog organized by domain
 */
function get_permissions_catalog(): array {
    return [
        'system' => [
            'label' => 'System Administration',
            'description' => 'Core system settings, configuration, and monitoring',
            'icon' => 'cog',
            'resources' => [
                'dashboard' => [
                    'label' => 'Dashboard',
                    'description' => 'View system dashboard and statistics',
                    'pages' => ['index.php'],
                ],
                'system_settings' => [
                    'label' => 'System Settings',
                    'description' => 'Configure global system parameters',
                    'pages' => ['modules/admin/config/*.php'],
                ],
                'audit_logs' => [
                    'label' => 'Audit Logs',
                    'description' => 'View system audit trail and user actions',
                    'pages' => ['modules/audit/*.php', 'modules/admin/logs.php'],
                ],
                'system_logs' => [
                    'label' => 'System Logs',
                    'description' => 'View technical system logs and errors',
                    'pages' => ['modules/admin/system_log.php'],
                ],
                'backup_restore' => [
                    'label' => 'Backup & Restore',
                    'description' => 'Manage database backups and restoration',
                    'pages' => ['modules/admin/backup.php'],
                ],
                'tools_workbench' => [
                    'label' => 'Tools & Workbench',
                    'description' => 'Access developer tools and database workbench',
                    'pages' => ['modules/admin/workbench.php', 'modules/admin/tools/*.php'],
                ],
                'access_control' => [
                    'label' => 'Access Control',
                    'description' => 'Manage device bindings, IP whitelist/blacklist, and module access restrictions',
                    'pages' => ['modules/admin/access-control/*.php'],
                ],
            ],
        ],
        
        'hr_core' => [
            'label' => 'HR Core Functions',
            'description' => 'Employee records, departments, positions, and organizational structure',
            'icon' => 'users',
            'resources' => [
                'employees' => [
                    'label' => 'Employee Management',
                    'description' => 'View, create, update, and manage employee records',
                    'pages' => ['modules/employees/*.php'],
                    'sensitive' => ['delete employees', 'change employee status'],
                ],
                'departments' => [
                    'label' => 'Department Management',
                    'description' => 'Manage organizational departments',
                    'pages' => ['modules/departments/*.php'],
                    'sensitive' => ['delete departments', 'assign supervisors'],
                ],
                'positions' => [
                    'label' => 'Position Management',
                    'description' => 'Define and manage job positions',
                    'pages' => ['modules/positions/*.php'],
                    'sensitive' => ['delete positions', 'modify position permissions'],
                ],
                'branches' => [
                    'label' => 'Branch Management',
                    'description' => 'Manage company branches and locations',
                    'pages' => ['modules/admin/branches/*.php'],
                    'sensitive' => ['delete branches'],
                ],
                'recruitment' => [
                    'label' => 'Recruitment & Hiring',
                    'description' => 'Manage job applications and recruitment pipeline',
                    'pages' => ['modules/recruitment/*.php'],
                    'sensitive' => ['convert applicants to employees', 'delete applications'],
                ],
            ],
        ],
        
        'payroll' => [
            'label' => 'Payroll Management',
            'description' => 'Payroll processing, compensation, and financial operations',
            'icon' => 'dollar-sign',
            'resources' => [
                'payroll_runs' => [
                    'label' => 'Payroll Runs',
                    'description' => 'Create and manage payroll processing cycles',
                    'pages' => ['modules/payroll/index.php', 'modules/payroll/create.php'],
                    'sensitive' => ['release payroll', 'close payroll runs', 'delete runs'],
                ],
                'payroll_batches' => [
                    'label' => 'Payroll Batches',
                    'description' => 'Manage branch-specific payroll batches',
                    'pages' => ['modules/payroll/batch*.php'],
                    'sensitive' => ['approve batches', 'submit batches'],
                ],
                'payslips' => [
                    'label' => 'Payslips',
                    'description' => 'View and generate employee payslips',
                    'pages' => ['modules/payroll/payslips*.php', 'modules/payroll/view_payslip.php'],
                ],
                'payroll_config' => [
                    'label' => 'Payroll Configuration',
                    'description' => 'Configure payroll settings, cutoffs, and formulas',
                    'pages' => ['modules/admin/compensation/*.php'],
                    'sensitive' => ['modify tax rates', 'change compensation formulas'],
                ],
                'payroll_complaints' => [
                    'label' => 'Payroll Complaints',
                    'description' => 'Handle employee payroll complaints and disputes',
                    'pages' => ['modules/payroll/complaints*.php'],
                    'sensitive' => ['resolve complaints', 'issue adjustments'],
                ],
                'overtime' => [
                    'label' => 'Overtime Management',
                    'description' => 'Review and approve overtime requests',
                    'pages' => ['modules/admin/overtime/*.php'],
                    'sensitive' => ['approve overtime', 'modify overtime rates'],
                ],
                'dtr_uploads' => [
                    'label' => 'DTR Uploads',
                    'description' => 'Upload and manage Daily Time Records',
                    'pages' => ['modules/payroll/upload_dtr.php'],
                ],
            ],
        ],
        
        'leave' => [
            'label' => 'Leave Management',
            'description' => 'Leave requests, approvals, and balance tracking',
            'icon' => 'calendar',
            'resources' => [
                'leave_requests' => [
                    'label' => 'Leave Requests',
                    'description' => 'File and manage leave applications',
                    'pages' => ['modules/leave/index.php', 'modules/leave/create.php'],
                    'self_service' => true, // Employees can file their own
                ],
                'leave_approval' => [
                    'label' => 'Leave Approvals',
                    'description' => 'Review and approve/reject leave requests',
                    'pages' => ['modules/leave/admin.php', 'modules/leave/api_admin_*.php'],
                    'sensitive' => ['approve leave', 'reject leave', 'cancel approved leave'],
                ],
                'leave_balances' => [
                    'label' => 'Leave Balances',
                    'description' => 'View employee leave credits and balances',
                    'pages' => ['modules/leave/balances.php'],
                ],
                'leave_config' => [
                    'label' => 'Leave Configuration',
                    'description' => 'Configure leave types, entitlements, and policies',
                    'pages' => ['modules/admin/leave-entitlements/*.php', 'modules/admin/leave-defaults/*.php'],
                    'sensitive' => ['modify leave entitlements', 'change leave policies'],
                ],
            ],
        ],
        
        'attendance' => [
            'label' => 'Attendance & Scheduling',
            'description' => 'Time tracking, attendance monitoring, and work schedules',
            'icon' => 'clock',
            'resources' => [
                'attendance_records' => [
                    'label' => 'Attendance Records',
                    'description' => 'View and manage attendance logs',
                    'pages' => ['modules/attendance/*.php'],
                    'sensitive' => ['modify attendance', 'delete records'],
                ],
                'work_schedules' => [
                    'label' => 'Work Schedules',
                    'description' => 'Define and assign employee work schedules',
                    'pages' => ['modules/admin/work-schedules/*.php'],
                ],
            ],
        ],
        
        'documents' => [
            'label' => 'Documents & Memos',
            'description' => 'Company documents, memos, and announcements',
            'icon' => 'file-text',
            'resources' => [
                'memos' => [
                    'label' => 'Memos',
                    'description' => 'Create and publish company memos',
                    'pages' => ['modules/memos/*.php'],
                    'sensitive' => ['publish memos', 'delete memos', 'edit published memos'],
                ],
                'documents' => [
                    'label' => 'Documents',
                    'description' => 'Manage company documents and files',
                    'pages' => ['modules/documents/*.php'],
                    'sensitive' => ['delete documents', 'manage document assignments'],
                ],
            ],
        ],
        
        'performance' => [
            'label' => 'Performance Management',
            'description' => 'Employee performance reviews and evaluations',
            'icon' => 'trending-up',
            'resources' => [
                'performance_reviews' => [
                    'label' => 'Performance Reviews',
                    'description' => 'Conduct and view employee performance reviews',
                    'pages' => ['modules/performance/*.php'],
                    'sensitive' => ['create reviews', 'modify KPI scores'],
                ],
            ],
        ],
        
        'notifications' => [
            'label' => 'Notifications',
            'description' => 'System notifications and announcements',
            'icon' => 'bell',
            'resources' => [
                'view_notifications' => [
                    'label' => 'View Notifications',
                    'description' => 'Access notification center',
                    'pages' => ['modules/notifications/index.php'],
                    'self_service' => true,
                ],
                'create_notifications' => [
                    'label' => 'Create Notifications',
                    'description' => 'Send system-wide notifications',
                    'pages' => ['modules/admin/notification_create.php'],
                    'sensitive' => ['send mass notifications'],
                ],
            ],
        ],
        
        'user_management' => [
            'label' => 'User Accounts',
            'description' => 'User accounts, authentication, and profile management',
            'icon' => 'user',
            'resources' => [
                'user_accounts' => [
                    'label' => 'User Account Management',
                    'description' => 'Create, modify, and deactivate user accounts',
                    'pages' => ['modules/account/*.php'],
                    'sensitive' => ['create users', 'reset passwords', 'deactivate users', 'change user positions'],
                ],
                'self_profile' => [
                    'label' => 'Own Profile',
                    'description' => 'View and edit own profile information',
                    'pages' => ['modules/auth/account.php'],
                    'self_service' => true,
                ],
            ],
        ],
        
        'inventory' => [
            'label' => 'Inventory & POS',
            'description' => 'Medical supplies inventory management, point-of-sale, and stock tracking',
            'icon' => 'package',
            'resources' => [
                'inventory_items' => [
                    'label' => 'Inventory Management',
                    'description' => 'Manage items, categories, suppliers, locations, and purchase orders',
                    'pages' => ['modules/inventory/inventory.php', 'modules/inventory/item_form.php', 'modules/inventory/item_view.php', 'modules/inventory/categories.php', 'modules/inventory/suppliers.php', 'modules/inventory/locations.php', 'modules/inventory/purchase_orders.php', 'modules/inventory/bulk_import.php'],
                    'sensitive' => ['delete items', 'adjust stock', 'manage suppliers', 'manage purchase orders'],
                ],
                'pos_transactions' => [
                    'label' => 'POS & Transactions',
                    'description' => 'Process sales, manage transactions, and configure receipt settings',
                    'pages' => ['modules/inventory/pos.php', 'modules/inventory/transactions.php', 'modules/inventory/transaction_view.php', 'modules/inventory/receipt_settings.php', 'modules/inventory/pos_management.php'],
                    'sensitive' => ['void transactions', 'modify receipt settings'],
                ],
                'inventory_reports' => [
                    'label' => 'Inventory Reports',
                    'description' => 'View inventory analytics, stock valuation, and movement reports',
                    'pages' => ['modules/inventory/reports.php', 'modules/inventory/movements.php'],
                ],
                'print_server' => [
                    'label' => 'Print Server',
                    'description' => 'Manage printers, monitor print queues, and view print history',
                    'pages' => ['modules/inventory/print_server.php'],
                    'sensitive' => ['delete printers', 'cancel print jobs', 'manage printer settings'],
                ],
            ],
        ],
        
        'reports' => [
            'label' => 'Reports & Analytics',
            'description' => 'System reports, exports, and data analytics',
            'icon' => 'bar-chart',
            'resources' => [
                'export_data' => [
                    'label' => 'Data Export',
                    'description' => 'Export data to CSV, PDF, and other formats',
                    'pages' => ['modules/*/csv.php', 'modules/*/pdf.php', 'modules/admin/pdf/*.php'],
                ],
                'analytics' => [
                    'label' => 'Analytics & Reports',
                    'description' => 'View analytics dashboards and generate reports',
                    'pages' => ['modules/admin/management.php'],
                ],
                'bir_reports' => [
                    'label' => 'BIR Reports',
                    'description' => 'Generate BIR compliance reports: Form 2316, 1604-C Alphalist, Monthly Remittances',
                    'pages' => ['modules/admin/bir-reports/index.php', 'modules/admin/bir-reports/export-2316.php', 'modules/admin/bir-reports/export-alphalist.php', 'modules/admin/bir-reports/export-remittance.php'],
                    'sensitive' => ['generate BIR reports', 'export BIR data'],
                ],
            ],
        ],

        'compliance' => [
            'label' => 'Compliance & Privacy',
            'description' => 'RA 10173 Data Privacy compliance: corrections, consent, erasure, data access',
            'icon' => 'shield',
            'resources' => [
                'data_corrections' => [
                    'label' => 'Data Corrections',
                    'description' => 'Review and approve employee data correction requests (Right to Rectification)',
                    'pages' => ['modules/admin/corrections/index.php'],
                    'sensitive' => ['approve corrections', 'reject corrections'],
                ],
                'privacy_consents' => [
                    'label' => 'Privacy Consents',
                    'description' => 'Manage employee privacy consents and compliance dashboard',
                    'pages' => ['modules/admin/privacy/index.php'],
                ],
                'data_erasure' => [
                    'label' => 'Data Erasure',
                    'description' => 'Review and execute data erasure/anonymization requests (Right to be Forgotten)',
                    'pages' => ['modules/admin/erasure/index.php'],
                    'sensitive' => ['approve erasure', 'execute erasure'],
                ],
                'data_export' => [
                    'label' => 'Data Access Export',
                    'description' => 'Manage employee personal data access requests (Right to Access)',
                    'self_service' => true,
                    'pages' => ['modules/compliance/data-export.php'],
                ],
            ],
        ],

        'healthcare' => [
            'label' => 'Healthcare & Clinic',
            'description' => 'Clinic service records, nurse and medtech documentation',
            'icon' => 'activity',
            'resources' => [
                'clinic_records' => [
                    'label' => 'Clinic Records',
                    'description' => 'View, create, update, and manage clinic service records',
                    'pages' => ['modules/clinic_records/*.php'],
                    'sensitive' => ['delete records', 'cancel records'],
                ],
            ],
        ],
    ];
}

/**
 * Get all resource keys (flat list)
 */
function get_all_resource_keys(): array {
    $keys = [];
    $catalog = get_permissions_catalog();
    foreach ($catalog as $domain => $domainData) {
        foreach ($domainData['resources'] as $resourceKey => $resourceData) {
            $keys[] = $domain . '.' . $resourceKey;
        }
    }
    return $keys;
}

/**
 * Get resource metadata
 */
function get_resource_info(string $domain, string $resourceKey): ?array {
    $catalog = get_permissions_catalog();
    if (!isset($catalog[$domain]['resources'][$resourceKey])) {
        return null;
    }
    return $catalog[$domain]['resources'][$resourceKey];
}

/**
 * Get domain metadata
 */
function get_domain_info(string $domain): ?array {
    $catalog = get_permissions_catalog();
    return $catalog[$domain] ?? null;
}

/**
 * Access level definitions
 */
function get_access_levels(): array {
    return [
        'none' => [
            'label' => 'None',
            'description' => 'No access to this resource',
            'rank' => 0,
            'color' => 'gray',
        ],
        'read' => [
            'label' => 'Read',
            'description' => 'View and download only (no modifications)',
            'rank' => 1,
            'color' => 'blue',
        ],
        'write' => [
            'label' => 'Read/Write',
            'description' => 'Create and update (includes Read access)',
            'rank' => 2,
            'color' => 'green',
        ],
        'manage' => [
            'label' => 'Manage',
            'description' => 'Full control including delete and sensitive operations (includes Read/Write)',
            'rank' => 3,
            'color' => 'purple',
        ],
    ];
}

/**
 * Check if an access level includes another
 * Example: manage includes write, write includes read
 */
function access_level_includes(string $level, string $required): bool {
    $levels = get_access_levels();
    $levelRank = $levels[$level]['rank'] ?? 0;
    $requiredRank = $levels[$required]['rank'] ?? 0;
    return $levelRank >= $requiredRank;
}
