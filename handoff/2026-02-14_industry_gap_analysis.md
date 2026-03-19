# HRIS Industry Gap Analysis & Implementation Roadmap

> Date: February 14, 2026  
> Scope: Gap assessment against industry-standard SaaS HRMS platforms  
> Baseline: Current HRIS codebase (plain PHP 8.x, PostgreSQL, Tailwind CSS)

---

## Table of Contents

1. [Current Strengths](#1-current-strengths)
2. [Gap Analysis](#2-gap-analysis)
3. [Priority 1 — Critical Production Gaps](#3-priority-1--critical-production-gaps)
4. [Priority 2 — SaaS Readiness](#4-priority-2--saas-readiness)
5. [Priority 3 — Enterprise Scale](#5-priority-3--enterprise-scale)
6. [Implementation Timeline](#6-implementation-timeline)

---

## 1. Current Strengths

These areas are already at or above industry standard and require no changes:

| Area | Implementation | Status |
|---|---|---|
| Authentication & Sessions | Fingerprinting, rotation every 5 min, idle timeout (3h), absolute timeout (24h), remember-me with selector:token + SHA-256 | ✅ Strong |
| CSRF Protection | Per-form token generation via `csrf_token()`, validated on every POST via `csrf_verify()` | ✅ Standard |
| SQL Injection Prevention | PDO prepared statements with named parameters across all queries | ✅ Correct |
| Audit Trail | Structured `audit()` with old/new value tracking, override attribution, `action_log()` for CRUD | ✅ Enterprise-grade |
| Permission Model | Position-based hierarchical access (none → read → write → manage), self-service carve-outs, superadmin bypass | ✅ Flexible |
| POST/Redirect/GET | `flash_success()`/`flash_error()` + `header()` redirect on every mutation | ✅ Proper |
| Payroll Engine | Sequential approval chain (JSON), batch processing per-branch, statutory computations (SSS, PhilHealth, Pag-IBIG) | ✅ Solid |
| Database Migrations | Date-prefixed, idempotent SQL, SHA-256 checksum tracking via `schema_migrations` table | ✅ Professional |
| UI Design System | Consistent Tailwind + custom CSS tokens, responsive layouts, Inter typography | ✅ Cohesive |

---

## 2. Gap Analysis

### 2.1 Security & Compliance Gaps

#### GAP-SEC-001: Sensitive Data Not Encrypted at Rest
- **Severity:** 🔴 Critical
- **Description:** Employee salaries, tax identification numbers (TIN, SSS, PhilHealth, Pag-IBIG numbers), bank account details, and personal addresses are stored as plaintext in the database.
- **Risk:** Non-compliant with Data Privacy Act of 2012 (RA 10173), GDPR (if any EU employees), and general data protection best practices. A database breach exposes all sensitive HR data.
- **Affected Tables:** `employees` (salary, tax fields, government IDs), `payroll_payslips` (compensation breakdowns), `users` (personal info)
- **Industry Standard:** AES-256 encryption for PII fields, with encryption keys stored in environment variables or a key management service (AWS KMS, Vault).

#### GAP-SEC-002: Missing Security Headers
- **Severity:** 🟡 Medium
- **Description:** No Content Security Policy (CSP), HTTP Strict Transport Security (HSTS), X-Frame-Options, X-Content-Type-Options, or Referrer-Policy headers are set.
- **Risk:** Vulnerable to clickjacking, MIME-type sniffing attacks, and mixed-content injection.
- **Current State:** Only session-level hardening exists in `includes/session.php` (HTTP-only cookies, SameSite=Lax).
- **Industry Standard:** All security headers set at server or application level.

#### GAP-SEC-003: No Rate Limiting
- **Severity:** 🟡 Medium
- **Description:** Login endpoint, password reset, and API calls have no rate limiting. Brute-force attacks against `/modules/auth/login.php` are unthrottled.
- **Risk:** Account takeover via credential stuffing, resource exhaustion.
- **Industry Standard:** Rate limiting per IP (e.g., 5 login attempts per minute), with progressive lockout.

#### GAP-SEC-004: No Data Privacy Compliance Features
- **Severity:** 🔴 Critical (for commercial deployment)
- **Description:** No mechanism for data subject access requests (DSAR), right to deletion, data export, or consent management.
- **Risk:** Legal liability under RA 10173, GDPR, and similar regulations.
- **Industry Standard:** Admin tools to export all data for an employee, anonymize/delete records, and log consent.

---

### 2.2 Infrastructure Gaps

#### GAP-INF-001: File Storage on Local Filesystem
- **Severity:** 🔴 Critical (Heroku deployment)
- **Description:** Uploaded files (employee documents, profile photos, receipts) are stored in `assets/uploads/` on the local filesystem.
- **Risk:** Heroku uses ephemeral dynos — all uploaded files are lost on every deploy or dyno restart. This is a **data loss** issue in production.
- **Affected Code:** `includes/utils.php` → `handle_upload()`, `UPLOAD_DIR` constant in `includes/config.php`
- **Industry Standard:** Cloud object storage (AWS S3, Cloudflare R2, DigitalOcean Spaces) with signed URLs for access control.

#### GAP-INF-002: No Background Job Processing
- **Severity:** 🟡 Medium
- **Description:** All operations run synchronously within the HTTP request cycle. Payroll computation, PDF generation, email sending, and report generation all block the response.
- **Risk:** Request timeouts on large payroll runs (500+ employees), poor UX during heavy operations, Heroku's 30-second request timeout.
- **Affected Code:** `includes/payroll.php` (5000+ lines, all synchronous), PDF generation via FPDF
- **Industry Standard:** Message queue (Redis + worker process) for heavy operations. User gets immediate acknowledgment, results delivered async.

#### GAP-INF-003: No Caching Layer
- **Severity:** 🟡 Medium
- **Description:** All data is fetched from PostgreSQL on every request. Permission checks, user profiles, configuration values, and sidebar navigation all hit the database.
- **Risk:** Performance degradation at scale (~500+ concurrent users). Permission checks alone can generate 3-5 queries per page load.
- **Affected Code:** `includes/permissions.php` → `get_user_effective_access()`, `includes/auth.php` → `current_user()`
- **Industry Standard:** Redis or APCu for session storage, permission caching, and frequently-read config values.

#### GAP-INF-004: No Transactional Email
- **Severity:** 🟡 Medium
- **Description:** The system has no email sending capability. Password resets, leave approvals, payslip delivery, and notifications exist only as in-app features.
- **Risk:** Users miss time-sensitive notifications (leave approvals, payroll issues). Password reset requires admin intervention.
- **Industry Standard:** Transactional email via SendGrid, AWS SES, or Mailgun with templated emails for key events.

---

### 2.3 Quality & Reliability Gaps

#### GAP-QA-001: No Automated Test Suite
- **Severity:** 🔴 Critical
- **Description:** Zero automated tests exist. No unit tests, integration tests, or end-to-end tests.
- **Risk:** Payroll calculation bugs go undetected until employees are paid incorrectly (financial and legal liability). Regression bugs introduced with every change.
- **Industry Standard:** PHPUnit/Pest test suite with minimum coverage for: payroll calculations, permission checks, leave balance computations, authentication flows.

#### GAP-QA-002: No CI/CD Pipeline
- **Severity:** 🟡 Medium
- **Description:** Deployment is manual (`git push heroku`). No automated testing, linting, or staging environment validation before production deploy.
- **Risk:** Broken code reaches production. No rollback strategy beyond `git revert`.
- **Industry Standard:** GitHub Actions pipeline: lint → test → deploy to staging → manual approval → deploy to production.

#### GAP-QA-003: No Error Tracking Service
- **Severity:** 🟡 Medium
- **Description:** Errors are logged to `system_logs` table via `sys_log()`. No real-time alerting, no stack traces for PHP fatal errors, no frontend error capture.
- **Risk:** Production errors go unnoticed until users report them. Root cause analysis is difficult.
- **Industry Standard:** Sentry, Bugsnag, or Rollbar for real-time error tracking with alerts.

---

### 2.4 Architecture Gaps

#### GAP-ARCH-001: No API Layer
- **Severity:** 🟡 Medium
- **Description:** The application is server-rendered PHP. Only a few `api_*.php` files exist for AJAX calls. No RESTful API or documented endpoints.
- **Risk:** Cannot support mobile apps, third-party integrations (biometric devices, accounting software), or a modern SPA frontend.
- **Industry Standard:** RESTful API (or GraphQL) with JWT/OAuth2 authentication, versioning, and OpenAPI documentation.

#### GAP-ARCH-002: Single-Tenant Architecture
- **Severity:** 🟡 Medium (only if pursuing SaaS)
- **Description:** The system serves one company. Database connection, configuration, and branding are hardcoded for a single tenant.
- **Risk:** Cannot scale to multiple companies without separate deployments.
- **Industry Standard:** Multi-tenant with database-per-tenant isolation (recommended for HRMS due to data sensitivity).

#### GAP-ARCH-003: No Framework Foundation
- **Severity:** 🟡 Low-Medium
- **Description:** Plain PHP without a framework means no built-in: dependency injection, middleware pipeline, request/response abstraction, ORM, validation layer, or CLI tooling.
- **Risk:** Increasing maintenance burden as codebase grows. Each cross-cutting concern (logging, auth, validation) is manually wired.
- **Note:** This is a **trade-off**, not necessarily a deficiency. The current codebase is well-organized with clear conventions. A framework migration is only justified if pursuing enterprise scale.

---

### 2.5 Accessibility & Compliance Gaps

#### GAP-ACC-001: No WCAG Audit
- **Severity:** 🟡 Medium
- **Description:** No accessibility audit has been performed. ARIA labels, keyboard navigation, screen reader compatibility, and color contrast are not systematically verified.
- **Risk:** Legal liability in jurisdictions requiring accessibility compliance (US ADA, EU EAA). Excludes users with disabilities.
- **Industry Standard:** WCAG 2.1 AA compliance minimum.

---

## 3. Priority 1 — Critical Production Gaps

These must be resolved before any commercial deployment or handling of real employee data at scale. Estimated total: **4-6 weeks**.

### P1.1 Encrypt Sensitive Data at Rest

**Effort:** 1-2 weeks  
**Files to modify:** `includes/db.php` or new `includes/encryption.php`, employee CRUD modules, payroll modules

**Implementation Guide:**

1. **Create encryption helper** (`includes/encryption.php`):
   ```php
   // Key must be in environment variable, never committed to code
   // ENCRYPTION_KEY = base64-encoded 32-byte key
   // Generate: php -r "echo base64_encode(random_bytes(32));"

   function encrypt_field(string $plaintext): string {
       $key = base64_decode(getenv('ENCRYPTION_KEY'));
       $iv = random_bytes(16);
       $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
       return base64_encode($iv . $ciphertext);
   }

   function decrypt_field(string $encrypted): string {
       $key = base64_decode(getenv('ENCRYPTION_KEY'));
       $data = base64_decode($encrypted);
       $iv = substr($data, 0, 16);
       $ciphertext = substr($data, 16);
       return openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
   }
   ```

2. **Fields to encrypt:**
   - `employees`: `sss_number`, `philhealth_number`, `pagibig_number`, `tin`, `bank_account_number`
   - `payroll_payslips`: `net_pay`, `gross_pay` (consider — impacts reporting queries)
   - `users`: `email` (if treating as PII — impacts login lookups, may need hash index)

3. **Migration strategy:**
   - Add `_encrypted` suffix columns alongside originals
   - Run a one-time migration script to encrypt existing data
   - Update all read/write code to use encrypted columns
   - Drop plaintext columns after verification

4. **Key management:**
   - Store `ENCRYPTION_KEY` in Heroku config vars: `heroku config:set ENCRYPTION_KEY=...`
   - Rotate key annually with re-encryption migration
   - Never log decrypted values

**Testing criteria:**
- Encrypted values in DB are not human-readable
- Decryption returns original value
- Key rotation works without data loss
- Search/filtering still works (use blind index for searchable encrypted fields)

---

### P1.2 Move File Uploads to Cloud Storage

**Effort:** 1 week  
**Files to modify:** `includes/config.php`, `includes/utils.php` (`handle_upload()`), all file display/download code

**Implementation Guide:**

1. **Choose provider:** AWS S3 (most common) or Cloudflare R2 (S3-compatible, cheaper)

2. **Install AWS SDK:**
   ```bash
   composer require aws/aws-sdk-php
   ```

3. **Create storage helper** (`includes/storage.php`):
   ```php
   function upload_to_s3(string $localPath, string $s3Key): string {
       $s3 = new Aws\S3\S3Client([
           'version' => 'latest',
           'region'  => getenv('AWS_REGION') ?: 'ap-southeast-1',
           'credentials' => [
               'key'    => getenv('AWS_ACCESS_KEY_ID'),
               'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
           ],
       ]);
       $s3->putObject([
           'Bucket' => getenv('S3_BUCKET'),
           'Key'    => $s3Key,
           'SourceFile' => $localPath,
           'ServerSideEncryption' => 'AES256',
       ]);
       return $s3Key;
   }

   function get_file_url(string $s3Key, int $expiresMinutes = 15): string {
       // Signed URL — expires after N minutes, no public access needed
       $s3 = /* ... same client ... */;
       $cmd = $s3->getCommand('GetObject', [
           'Bucket' => getenv('S3_BUCKET'),
           'Key'    => $s3Key,
       ]);
       $request = $s3->createPresignedRequest($cmd, "+{$expiresMinutes} minutes");
       return (string)$request->getUri();
   }
   ```

4. **Update `handle_upload()`** in `includes/utils.php`:
   - Upload to S3 instead of local filesystem
   - Store S3 key in database instead of local path
   - Return signed URL for display

5. **Migration:**
   - Upload all existing `assets/uploads/` files to S3
   - Update database records to reference S3 keys
   - Remove local upload directory dependency

**Environment variables (Heroku):**
```
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_REGION=ap-southeast-1
S3_BUCKET=hris-uploads
```

---

### P1.3 Add Security Headers

**Effort:** 2-3 hours  
**Files to modify:** `includes/session.php` or `.htaccess`

**Implementation Guide:**

Add to `includes/session.php` (after session start, before any output):

```php
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// HSTS — only enable when confirmed HTTPS-only
if (!empty($_SERVER['HTTPS']) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// CSP — restrictive but allows Tailwind CDN, Google Fonts, Chart.js
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'");
```

**Note on `unsafe-inline`/`unsafe-eval`:** Required because Tailwind CDN and inline `<script>` blocks are used. To fully remove these, Tailwind would need to be compiled locally and inline scripts moved to external files — this is a Phase 2+ optimization.

---

### P1.4 Basic Test Suite for Critical Paths

**Effort:** 1-2 weeks  
**Setup:** PHPUnit 10.x

**Implementation Guide:**

1. **Install PHPUnit:**
   ```bash
   composer require --dev phpunit/phpunit
   ```

2. **Create `phpunit.xml`** in project root:
   ```xml
   <phpunit bootstrap="tests/bootstrap.php" colors="true">
     <testsuites>
       <testsuite name="Unit">
         <directory>tests/Unit</directory>
       </testsuite>
       <testsuite name="Integration">
         <directory>tests/Integration</directory>
       </testsuite>
     </testsuites>
   </phpunit>
   ```

3. **Create `tests/bootstrap.php`:**
   ```php
   <?php
   // Set test environment
   putenv('APP_ENV=testing');
   putenv('DATABASE_URL=postgres://test_user:test_pass@localhost:5432/hris_test');
   require_once __DIR__ . '/../includes/config.php';
   require_once __DIR__ . '/../includes/db.php';
   ```

4. **Priority test cases (minimum viable coverage):**

   | Test File | What to Test | Why |
   |---|---|---|
   | `tests/Unit/PayrollCalculationTest.php` | Gross pay, deductions, net pay, SSS/PhilHealth/Pag-IBIG tables, overtime rates, tax computation | **Financial accuracy** — wrong payroll = legal liability |
   | `tests/Unit/LeaveBalanceTest.php` | `leave_calculate_balances()` with various scenarios: new hire, mid-year, used leave, pending requests | Employee entitlement accuracy |
   | `tests/Unit/PermissionsTest.php` | `get_user_effective_access()`, `user_has_access()`, hierarchical level checks, superadmin bypass, self-service flags | Security — permission bypass = data breach |
   | `tests/Unit/CsrfTest.php` | `csrf_token()` generation, `csrf_verify()` validation, token expiry | Security baseline |
   | `tests/Integration/AuthFlowTest.php` | Login, logout, session creation, remember-me token, password hashing | Auth bypass = total compromise |

5. **Example test structure:**
   ```php
   // tests/Unit/PayrollCalculationTest.php
   class PayrollCalculationTest extends \PHPUnit\Framework\TestCase {
       public function test_sss_contribution_bracket(): void {
           // Employee with ₱25,000 monthly salary
           $contribution = compute_sss_contribution(25000);
           $this->assertEquals(1125.00, $contribution['employee']);
           $this->assertEquals(2475.00, $contribution['employer']);
       }

       public function test_overtime_rate_regular_day(): void {
           $rate = calculate_overtime_rate(125.00, 'regular', 2);
           // Regular OT = hourly rate × 1.25 × hours
           $this->assertEquals(312.50, $rate);
       }

       public function test_net_pay_calculation(): void {
           $result = compute_payslip([
               'basic_salary' => 25000,
               'days_worked' => 22,
               'total_days' => 22,
               'overtime_hours' => 0,
               // ... other inputs
           ]);
           $this->assertGreaterThan(0, $result['net_pay']);
           $this->assertEquals($result['gross_pay'] - $result['total_deductions'], $result['net_pay']);
       }
   }
   ```

---

## 4. Priority 2 — SaaS Readiness

Required to sell the HRMS to multiple companies. Estimated total: **6-8 weeks**.

### P2.1 Multi-Tenant Architecture (Database-per-Tenant)

**Effort:** 2-3 weeks

**Components to build:**

1. **Tenant Registry Database** — a central database with:
   - `tenants` table: `id`, `slug`, `company_name`, `subdomain`, `db_host`, `db_name`, `db_user`, `db_password`, `plan`, `status`, `created_at`
   - `tenant_admins` table: link between manager-portal users and their tenants

2. **Routing Middleware** — resolve tenant from subdomain:
   ```php
   // includes/tenant.php
   function resolve_tenant(): array {
       $host = $_SERVER['HTTP_HOST'];
       // companyA.yourhris.com → slug = "companyA"
       $slug = explode('.', $host)[0];
       $manager_db = get_manager_db();
       $stmt = $manager_db->prepare("SELECT * FROM tenants WHERE slug = :s AND status = 'active'");
       $stmt->execute([':s' => $slug]);
       return $stmt->fetch(PDO::FETCH_ASSOC) ?: die('Tenant not found');
   }
   ```

3. **Tenant-aware `get_db_conn()`** — modify to use resolved tenant's connection:
   ```php
   function get_db_conn(): PDO {
       static $conn = null;
       if ($conn) return $conn;
       $tenant = resolve_tenant();
       $dsn = "pgsql:host={$tenant['db_host']};dbname={$tenant['db_name']}";
       $conn = new PDO($dsn, $tenant['db_user'], $tenant['db_password']);
       return $conn;
   }
   ```

4. **Provisioning Script** — auto-create tenant database:
   ```
   Create PostgreSQL database → Run schema_postgre.sql → Run all migrations → Seed default admin user → Store credentials in tenant registry
   ```

5. **Manager Portal** — separate admin interface at `manage.yourhris.com`:
   - List all tenants with status
   - Create/suspend/delete tenants
   - Run migrations across all tenant DBs
   - Usage metrics per tenant

### P2.2 REST API Layer

**Effort:** 2-3 weeks

**Structure:**
```
api/
├── v1/
│   ├── index.php          # Router
│   ├── auth.php           # JWT login, token refresh
│   ├── employees.php      # GET/POST/PUT/DELETE
│   ├── leave.php          # Leave requests
│   ├── attendance.php     # DTR records
│   ├── payroll.php        # Payslip retrieval
│   └── middleware.php     # Auth, rate limiting, CORS
```

**Key decisions:**
- Authentication: JWT tokens (access + refresh)
- Versioning: URL path (`/api/v1/`)
- Response format: JSON with consistent envelope (`{ data, meta, errors }`)
- Rate limiting: Token bucket per API key (Redis-backed)
- Documentation: OpenAPI 3.0 spec auto-generated or maintained manually

### P2.3 Background Job Processing

**Effort:** 1-2 weeks

**Options (PHP-compatible):**
- **Simple:** Database-backed queue with cron worker (no new infrastructure)
- **Standard:** Redis + custom worker process (Heroku worker dyno)
- **Full:** Laravel Queue standalone or Symfony Messenger (adds framework dependency)

**Recommended (database queue, least infrastructure change):**

1. Create `job_queue` table:
   ```sql
   CREATE TABLE job_queue (
       id SERIAL PRIMARY KEY,
       job_type VARCHAR(100) NOT NULL,
       payload JSONB NOT NULL DEFAULT '{}',
       status VARCHAR(20) DEFAULT 'pending', -- pending, processing, completed, failed
       attempts INT DEFAULT 0,
       max_attempts INT DEFAULT 3,
       scheduled_at TIMESTAMPTZ DEFAULT NOW(),
       started_at TIMESTAMPTZ,
       completed_at TIMESTAMPTZ,
       error_message TEXT,
       created_by INT REFERENCES users(id),
       created_at TIMESTAMPTZ DEFAULT NOW()
   );
   ```

2. Worker process (`tools/worker.php`):
   ```php
   // Run via Heroku worker dyno or cron
   while (true) {
       $job = claim_next_job($pdo); // SELECT ... FOR UPDATE SKIP LOCKED
       if ($job) {
           process_job($job);
       } else {
           sleep(5);
       }
   }
   ```

3. Jobs to offload:
   - Payroll batch computation
   - PDF payslip generation
   - Email notifications
   - Report generation (CSV/PDF exports)
   - Bulk data imports

### P2.4 Transactional Email

**Effort:** 1 week

**Recommended:** SendGrid (free tier: 100 emails/day)

**Events to email:**
| Event | Recipient | Template |
|---|---|---|
| Password reset | User | Reset link with expiry |
| Leave request filed | Approver | Request details + approve/reject link |
| Leave approved/rejected | Employee | Decision + balance update |
| Payslip available | Employee | Period + link to view |
| Overtime approved/rejected | Employee | Decision details |
| Account created | New user | Welcome + initial password |
| Payroll batch ready for approval | Next approver | Batch details + link |

---

## 5. Priority 3 — Enterprise Scale

For competing with established HRMS platforms. **3-6+ months** depending on scope.

| Item | Description | Effort |
|---|---|---|
| **Framework Migration** | Migrate to Laravel 11 or Symfony 7 for DI, middleware, Eloquent ORM, Artisan CLI, built-in queue/mail/cache | 2-3 months |
| **SPA Frontend** | React/Vue frontend consuming the REST API. Better UX, offline support, mobile-responsive | 2-3 months |
| **Mobile App** | React Native or Flutter app for employee self-service (leave, DTR, payslips) | 2-3 months |
| **SSO / OAuth2** | Google Workspace, Microsoft Entra ID, SAML integration for enterprise clients | 2-3 weeks |
| **SOC 2 Compliance** | Security policies, penetration testing, compliance documentation | 2-3 months (process) |
| **Webhooks** | Allow tenants to subscribe to events (employee created, leave approved, payroll completed) | 1-2 weeks |
| **Custom Fields** | Let each tenant define custom fields for employees, leave types, etc. | 2-3 weeks |
| **Reporting Engine** | Drag-and-drop report builder with scheduled delivery | 3-4 weeks |
| **Biometric Integration** | API endpoints for fingerprint/face scanners to push attendance data | 2-3 weeks |
| **Localization (i18n)** | Multi-language support for international deployments | 2-3 weeks |

---

## 6. Implementation Timeline

### Recommended Sequence

```
Week 1-2     ┃ P1.3 Security Headers (quick win)
             ┃ P1.4 Test Suite Setup + Payroll Tests
             ┃
Week 3-4     ┃ P1.1 Field Encryption (employees, payroll)
             ┃ P1.2 S3 File Storage Migration
             ┃
Week 5-6     ┃ P1.1 Encryption Contd. (migration script, verification)
             ┃ P1.4 Test Suite Expansion (permissions, auth, leave)
             ┃
━━━━━━━━━━━━━╋━━━━ Priority 1 Complete ━━━━━━━━━━━━━━━━━━
             ┃
Week 7-9     ┃ P2.1 Multi-Tenant (registry, routing, provisioning)
             ┃
Week 10-11   ┃ P2.4 Email Integration (SendGrid + templates)
             ┃ P2.3 Background Jobs (DB queue + worker)
             ┃
Week 12-14   ┃ P2.2 REST API (auth, employees, leave, payroll)
             ┃ P2.1 Manager Portal UI
             ┃
━━━━━━━━━━━━━╋━━━━ Priority 2 Complete (SaaS-ready) ━━━━━
             ┃
Month 4+     ┃ P3 Enterprise features (as needed)
```

### Decision Gates

After each priority phase, evaluate:
- **After P1:** Is the product being used in production with real employee data? → Security is now adequate.
- **After P2:** Are there paying customers or prospects? → Multi-tenant + API + email enables commercial deployment.
- **Before P3:** Is framework migration justified by team size and feature velocity? → Only if 2+ developers and rapid feature growth.

---

## Appendix: Environment Variables Needed

| Variable | Purpose | When Needed |
|---|---|---|
| `ENCRYPTION_KEY` | AES-256 encryption key (base64-encoded 32 bytes) | P1.1 |
| `AWS_ACCESS_KEY_ID` | S3 access key | P1.2 |
| `AWS_SECRET_ACCESS_KEY` | S3 secret key | P1.2 |
| `AWS_REGION` | S3 region | P1.2 |
| `S3_BUCKET` | S3 bucket name | P1.2 |
| `SENDGRID_API_KEY` | Email service API key | P2.4 |
| `MAIL_FROM_ADDRESS` | Default sender email | P2.4 |
| `MAIL_FROM_NAME` | Default sender name | P2.4 |
| `MANAGER_DATABASE_URL` | Central tenant registry DB | P2.1 |
| `JWT_SECRET` | API token signing key | P2.2 |
