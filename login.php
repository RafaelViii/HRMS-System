<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/db.php';

// Prevent auth credentials from being passed via GET query string (GET-for-POST mitigation)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['email']) || isset($_GET['password']))) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login', true, 302);
    exit;
}

auth_attempt_remember_login();
if (!empty($_SESSION['user'])) {
  require_once __DIR__ . '/includes/config.php';
  header('Location: ' . BASE_URL . '/');
  exit;
}

$pdo = get_db_conn();
$defaultBranchId = branches_get_default_id($pdo);

// One-time bootstrap: ensure superadmin account exists and is active
try {
  $total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
  $needAdmin = false;
  if ($total === 0) {
    $needAdmin = true;
  } else {
    // Only check if superadmin exists — do NOT auto-reactivate deactivated accounts
    $chk = $pdo->prepare('SELECT id, status FROM users WHERE email = :email LIMIT 1');
    $chk->execute([':email' => SUPERADMIN_EMAIL]);
    $u = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
      $needAdmin = true;
    }
  }
  if ($needAdmin) {
    $hash = password_hash(SUPERADMIN_DEFAULT_PASSWORD, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role, status, is_system_admin, branch_id) VALUES (:email,:hash,:name,:role,'active',true,:branch)");
    $ins->execute([
      ':email' => SUPERADMIN_EMAIL,
      ':hash' => $hash,
      ':name' => 'System Admin',
      ':role' => 'admin',
      ':branch' => $defaultBranchId,
    ]);
  }
} catch (Throwable $e) {
  // Silently ignore bootstrap errors on login page
}

$error = '';
$emailInput = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token';
  } else {
    $now = time();
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $emailInput = $email;
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Use first IP if forwarded contains multiple
    if (str_contains($clientIp, ',')) { $clientIp = trim(explode(',', $clientIp)[0]); }

    // --- DB-backed rate limiting (immune to session clearing) ---
    $rateLimitKey = $clientIp; // rate limit by IP
    $maxAttempts = 5;
    $lockoutSeconds = 120; // 2-minute lockout after 5 failures
    $windowSeconds = 900;  // 15-minute sliding window

    // Ensure table exists (idempotent)
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
        ip_address VARCHAR(45) NOT NULL, email VARCHAR(255) NOT NULL DEFAULT '',
        attempts INTEGER NOT NULL DEFAULT 1,
        first_attempt_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
        blocked_until TIMESTAMP WITHOUT TIME ZONE, PRIMARY KEY (ip_address, email))");
    } catch (Throwable $e) { /* table likely already exists */ }

    // Clean stale entries (older than window)
    try {
      $pdo->prepare("DELETE FROM login_rate_limits WHERE first_attempt_at < NOW() - INTERVAL '" . (int)$windowSeconds . " seconds'")->execute();
    } catch (Throwable $e) { /* non-critical */ }

    // Check current rate limit status for this IP
    $blocked = false;
    try {
      $rlStmt = $pdo->prepare("SELECT attempts, blocked_until FROM login_rate_limits WHERE ip_address = :ip AND email = ''");
      $rlStmt->execute([':ip' => $rateLimitKey]);
      $rl = $rlStmt->fetch(PDO::FETCH_ASSOC);
      if ($rl && $rl['blocked_until'] && strtotime($rl['blocked_until']) > $now) {
        $remaining = strtotime($rl['blocked_until']) - $now;
        $error = 'Too many login attempts. Please try again in ' . $remaining . ' seconds.';
        $blocked = true;
      }
    } catch (Throwable $e) { /* if table doesn't exist yet, allow login */ }

    if (!$blocked) {
      if (auth_login($email, $password, false)) {
        // Clear rate limit on success
        try {
          $pdo->prepare("DELETE FROM login_rate_limits WHERE ip_address = :ip")->execute([':ip' => $rateLimitKey]);
        } catch (Throwable $e) { /* non-critical */ }
        require_once __DIR__ . '/includes/config.php';
        header('Location: ' . BASE_URL . '/');
        exit;
      }

      // Record failed attempt
      try {
        $pdo->prepare("INSERT INTO login_rate_limits (ip_address, email, attempts, first_attempt_at)
          VALUES (:ip, '', 1, NOW())
          ON CONFLICT (ip_address, email) DO UPDATE SET attempts = login_rate_limits.attempts + 1")
          ->execute([':ip' => $rateLimitKey]);

        // Check if lockout threshold reached
        $chkStmt = $pdo->prepare("SELECT attempts FROM login_rate_limits WHERE ip_address = :ip AND email = ''");
        $chkStmt->execute([':ip' => $rateLimitKey]);
        $currentAttempts = (int)($chkStmt->fetchColumn() ?: 0);
        if ($currentAttempts >= $maxAttempts) {
          $pdo->prepare("UPDATE login_rate_limits SET blocked_until = NOW() + INTERVAL '" . (int)$lockoutSeconds . " seconds' WHERE ip_address = :ip AND email = ''")
            ->execute([':ip' => $rateLimitKey]);
        }
      } catch (Throwable $e) { /* non-critical: fail open on DB errors */ }

      $error = 'Invalid email or password.';
      audit('login.failed', 'Failed login for ' . $email);
    }
  }
}

$infoMessage = flash_get('info');
$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign in &middot; HydroMed HRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700&display=swap" rel="stylesheet" crossorigin="anonymous">
  <link rel="stylesheet" href="assets/css/tailwind.css" />
  <link rel="stylesheet" href="assets/css/app.css" />
</head>
<body class="relative min-h-screen overflow-hidden bg-gradient-to-br from-indigo-700 via-violet-600 to-slate-900 text-white">
  <div class="pointer-events-none absolute inset-0">
    <div class="absolute -top-28 -left-24 h-72 w-72 rounded-full bg-indigo-400/30 blur-3xl"></div>
    <div class="absolute top-1/2 -right-16 h-80 w-80 -translate-y-1/2 rounded-full bg-violet-400/25 blur-3xl"></div>
    <div class="absolute bottom-[-6rem] left-1/3 h-72 w-72 rounded-full bg-blue-300/20 blur-3xl"></div>
  </div>

  <div class="relative z-10 flex min-h-screen items-center justify-center px-6 py-12">
    <div class="grid w-full max-w-5xl items-center gap-12 lg:grid-cols-[1.1fr,0.9fr]">
      <div class="hidden flex-col gap-6 text-white/90 lg:flex">
        <div class="rounded-3xl border border-white/20 bg-white/10 p-10 backdrop-blur">
          <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium uppercase tracking-wide text-indigo-100">HydroMed</span>
          <h1 class="mt-5 text-4xl font-semibold leading-tight">Welcome to HydroMed HRMS</h1>
          <p class="mt-3 text-base text-indigo-100/90">Manage your HR operations from a single, secure workspace.</p>
          <ul class="mt-6 space-y-3 text-sm text-indigo-50/90">
            <li class="flex items-start gap-3">
              <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-300/80 text-indigo-900">&#10003;</span>
              <span>Manage employee records and roles with secure access controls.</span>
            </li>
            <li class="flex items-start gap-3">
              <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-300/80 text-indigo-900">&#10003;</span>
              <span>Track leave, attendance, and payroll readiness in one place.</span>
            </li>
            <li class="flex items-start gap-3">
              <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-300/80 text-indigo-900">&#10003;</span>
              <span>Audited changes for sensitive actions to protect your data.</span>
            </li>
          </ul>
          <p class="mt-6 text-xs text-indigo-100/80">HydroMed HRMS &middot; Human Resource Management System</p>
        </div>
      </div>

      <div class="relative">
        <div class="absolute inset-0 -translate-y-6 translate-x-4 rounded-3xl bg-white/25 blur-2xl"></div>
        <div class="relative rounded-3xl bg-white p-8 text-gray-900 shadow-2xl lg:p-10">
          <div class="mb-6">
            <span class="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-emerald-700">Secure Access</span>
            <h2 class="mt-4 text-2xl font-semibold text-gray-900">Sign in to HydroMed HRMS</h2>
            <p class="mt-2 text-sm text-gray-500">Use your credentials to access the HR workspace.</p>
          </div>

          <?php if ($infoMessage): ?>
            <div class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-700">
              <?= htmlspecialchars($infoMessage) ?>
            </div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <?= htmlspecialchars($error) ?>
            </div>
          <?php endif; ?>
          <div id="clientError" class="mb-4 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>

          <form id="loginForm" method="post" class="space-y-5" novalidate>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>" />
            <div class="space-y-2">
              <label for="email" class="block text-xs font-semibold uppercase tracking-wide text-gray-600">Work Email</label>
              <input id="email" name="email" type="email" value="<?= htmlspecialchars($emailInput) ?>" class="input-text w-full" placeholder="you@company.com" required autocomplete="email" />
            </div>
            <div class="space-y-2">
              <label for="password" class="block text-xs font-semibold uppercase tracking-wide text-gray-600">Password</label>
              <div class="relative">
                <input id="password" name="password" type="password" class="input-text w-full pr-12" placeholder="Enter your password" required autocomplete="current-password" />
                <button type="button" class="absolute inset-y-0 right-3 flex items-center justify-center text-gray-500 hover:text-indigo-600" data-toggle-password aria-label="Toggle password visibility" aria-pressed="false">
                  <span class="sr-only">Toggle password visibility</span>
                  <svg data-icon="show" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.436 0 .643C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                  <svg data-icon="hide" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c1.712 0 3.332-.356 4.786-1.004M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.5a10.523 10.523 0 01-4.293 5.223M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88" />
                  </svg>
                </button>
              </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3 text-sm">
              <button type="button" data-open-forgot class="font-medium text-indigo-600 hover:text-indigo-700">Forgot password?</button>
            </div>
            <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-500 px-4 py-3 text-sm font-semibold text-white shadow-lg transition hover:from-indigo-500 hover:to-violet-400 focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:ring-offset-2 focus:ring-offset-white">Sign in</button>
            <p class="mt-4 text-center text-xs text-gray-500">HydroMed HRMS &middot; Human Resource Management System</p>
          </form>

          <?php if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)): ?>
            <p class="mt-4 text-xs text-gray-500"><a class="font-medium text-indigo-600 hover:text-indigo-700" href="tools/reset_admin.php">Reset admin password</a></p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div id="forgotModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" data-forgot-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-2xl">
        <h3 class="text-lg font-semibold text-gray-900">Need a password reset?</h3>
        <p class="mt-3 text-sm text-gray-600">For security reasons, password resets are handled by your system administrator. Please reach out to them to restore your account access.</p>
        <button type="button" class="mt-6 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" data-forgot-close>Got it</button>
      </div>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const passwordInput = document.getElementById('password');
    const toggleBtn = document.querySelector('[data-toggle-password]');
    if (toggleBtn && passwordInput) {
      toggleBtn.addEventListener('click', () => {
        const isHidden = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isHidden ? 'text' : 'password');
        toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        toggleBtn.querySelectorAll('[data-icon]').forEach((icon) => {
          icon.classList.toggle('hidden', icon.dataset.icon !== (isHidden ? 'hide' : 'show'));
        });
      });
    }

    const form = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const clientError = document.getElementById('clientError');
    const clearClientError = () => {
      if (!clientError) return;
      clientError.classList.add('hidden');
      clientError.textContent = '';
      emailInput?.classList.remove('input-error');
      passwordInput?.classList.remove('input-error');
    };
    emailInput?.addEventListener('input', clearClientError);
    passwordInput?.addEventListener('input', clearClientError);

    form?.addEventListener('submit', (event) => {
      clearClientError();
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
        return;
      }
      const emailVal = emailInput.value.trim();
      const passwordVal = passwordInput.value.trim();
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailPattern.test(emailVal)) {
        event.preventDefault();
        clientError.textContent = 'Enter a valid work email address.';
        clientError.classList.remove('hidden');
        emailInput.classList.add('input-error');
        emailInput.focus();
        return;
      }
      if (passwordVal.length < 6) {
        event.preventDefault();
        clientError.textContent = 'Password must be at least 6 characters long.';
        clientError.classList.remove('hidden');
        passwordInput.classList.add('input-error');
        passwordInput.focus();
      }
    });

    const forgotModal = document.getElementById('forgotModal');
    const openForgot = document.querySelector('[data-open-forgot]');
    const closeForgot = () => {
      if (!forgotModal) return;
      forgotModal.classList.add('hidden');
      forgotModal.classList.remove('flex');
    };
    openForgot?.addEventListener('click', (event) => {
      event.preventDefault();
      if (!forgotModal) return;
      forgotModal.classList.remove('hidden');
      forgotModal.classList.add('flex');
    });
    forgotModal?.addEventListener('click', (event) => {
      if (event.target.closest('[data-forgot-close]')) {
        closeForgot();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeForgot();
      }
    });
  });
  </script>
</body>
</html>
