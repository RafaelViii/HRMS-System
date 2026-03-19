#!/usr/bin/env node
/**
 * HRIS Static Site Generator
 *
 * Creates a self-contained, anonymized HTML mirror of the HRIS application.
 * All pages become static .html files with bundled assets, working sidebar,
 * modals, and navigation — no backend required.
 *
 * Usage:
 *   node static-site.js                                        # Desktop, default settings
 *   node static-site.js --base-url=https://www.pocc.systems    # Custom base URL
 *   node static-site.js --viewport=mobile                      # Mobile viewport
 *   node static-site.js --resume                               # Resume interrupted run
 *   node static-site.js --no-anonymize                         # Skip data anonymization
 *   node static-site.js --output=./my-output                   # Custom output dir
 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const ROUTES = require('./routes');
const Anonymizer = require('./lib/anonymizer');
const AssetBundler = require('./lib/asset-bundler');
const HTMLProcessor = require('./lib/html-processor');
const URLMapper = require('./lib/url-mapper');

// ─── CLI Argument Parser ──────────────────────────────────────────────────────

function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(name + '='));
  if (arg) return arg.split('=').slice(1).join('=');
  // Boolean flags (--resume, --no-anonymize)
  return process.argv.includes(name) ? 'true' : null;
}

// ─── Configuration ────────────────────────────────────────────────────────────

const CONFIG = {
  baseUrl: (getArg('--base-url') || process.env.HRMS_BASE_URL || 'http://localhost').replace(/\/+$/, ''),
  credentials: {
    email: getArg('--email') || process.env.HRMS_EMAIL || 'bobis.daniel.bscs2023@gmail.com',
    password: getArg('--password') || process.env.HRMS_PASSWORD || 'Admin@123',
  },
  viewports: {
    desktop: { width: 1920, height: 1080, scale: 1 },
    mobile: { width: 375, height: 812, scale: 2 },
  },
  outputDir: getArg('--output') || path.join(__dirname, 'output', 'static-site'),
  timeouts: {
    navigation: 30_000,
    settleDelay: 2000,
    loginWait: 3000,
  },
  resume: getArg('--resume') === 'true',
  anonymize: getArg('--no-anonymize') !== 'true',
};

// Parse --viewport flag
const viewportArg = getArg('--viewport');
const activeViewport = viewportArg || 'desktop';
if (viewportArg && !CONFIG.viewports[viewportArg]) {
  console.error(`Unknown viewport "${viewportArg}". Use "desktop" or "mobile".`);
  process.exit(1);
}
const viewportConfig = CONFIG.viewports[activeViewport];

// ─── Helpers ──────────────────────────────────────────────────────────────────

function ts() {
  return new Date().toISOString().replace('T', ' ').substring(0, 19);
}

function log(msg) {
  console.log(`[${ts()}] ${msg}`);
}

function logError(msg) {
  console.error(`[${ts()}] ERROR: ${msg}`);
}

function buildUrl(routePath, queryParams) {
  let url = CONFIG.baseUrl + routePath;
  if (queryParams) {
    url += '?' + new URLSearchParams(queryParams).toString();
  }
  return url;
}

// ─── Page Loading Helpers (reused from screenshot.js) ─────────────────────────

async function waitForPageReady(page) {
  try {
    await page.evaluate(() => {
      return new Promise((resolve) => {
        const deadline = Date.now() + 10_000;
        const check = () => {
          const appLoader = document.getElementById('appLoader');
          const contentLoader = document.getElementById('contentLoader');
          const appHidden = !appLoader || appLoader.classList.contains('hidden') || appLoader.style.display === 'none';
          const contentHidden = !contentLoader || contentLoader.classList.contains('hidden') || contentLoader.style.display === 'none';
          if ((appHidden && contentHidden) || Date.now() > deadline) {
            resolve();
          } else {
            setTimeout(check, 200);
          }
        };
        check();
      });
    });
  } catch {
    // Page might not have these elements
  }

  await new Promise(r => setTimeout(r, CONFIG.timeouts.settleDelay));
}

async function dismissOverlays(page) {
  try {
    await page.evaluate(() => {
      ['confirmModal', 'authzModal', 'forgotModal'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('hidden');
      });
      document.querySelectorAll('.dropdown-menu').forEach(el => {
        el.classList.add('hidden');
      });
    });
  } catch {
    // Non-critical
  }
}

// ─── Login ────────────────────────────────────────────────────────────────────

async function login(page) {
  const loginUrl = CONFIG.baseUrl + '/login';
  log('Navigating to login page...');

  await page.goto(loginUrl, {
    waitUntil: 'networkidle2',
    timeout: CONFIG.timeouts.navigation,
  });

  const csrfToken = await page.evaluate(() => {
    const input = document.querySelector('input[name="csrf"]');
    return input ? input.value : null;
  });

  if (!csrfToken) {
    throw new Error('Could not find CSRF token on login page');
  }

  log('Found CSRF token, filling login form...');

  await page.type('#email', CONFIG.credentials.email, { delay: 20 });
  await page.type('#password', CONFIG.credentials.password, { delay: 20 });

  await Promise.all([
    page.waitForNavigation({
      waitUntil: 'networkidle2',
      timeout: CONFIG.timeouts.navigation,
    }),
    page.click('button[type="submit"]'),
  ]);

  const currentUrl = page.url();
  if (currentUrl.includes('/login')) {
    const errorText = await page.evaluate(() => {
      const el = document.querySelector('.bg-red-50, .flash-error, [role="alert"]');
      return el ? el.textContent.trim() : 'Unknown error';
    });
    throw new Error(`Login failed: ${errorText}`);
  }

  log('Login successful!');
  await new Promise(r => setTimeout(r, CONFIG.timeouts.loginWait));
}

// ─── Capture a single page's HTML ────────────────────────────────────────────

async function capturePage(page, route, anonymizer, htmlProcessor, urlMapper) {
  const url = buildUrl(route.path, route.queryParams);
  const htmlFilePath = urlMapper.routeToHTMLPath(route.path, route.queryParams);

  try {
    const response = await page.goto(url, {
      waitUntil: 'networkidle2',
      timeout: CONFIG.timeouts.navigation,
    });

    const status = response ? response.status() : 'unknown';
    if (status >= 400) {
      log(`  WARNING: HTTP ${status} for ${route.path}`);
    }

    await waitForPageReady(page);
    await dismissOverlays(page);

    // Scroll to top
    await page.evaluate(() => {
      window.scrollTo(0, 0);
      const main = document.getElementById('appMain');
      if (main) main.scrollTop = 0;
    });

    await new Promise(r => setTimeout(r, 500));

    // Run in-browser anonymization if enabled
    if (CONFIG.anonymize) {
      await anonymizer.anonymizePage(page);
    }

    // Capture the full rendered HTML
    const rawHTML = await page.evaluate(() => {
      return '<!DOCTYPE html>\n' + document.documentElement.outerHTML;
    });

    // Post-process with Cheerio
    const processedHTML = htmlProcessor.process(rawHTML, htmlFilePath, route);

    // Save to file
    const fullPath = path.join(CONFIG.outputDir, htmlFilePath);
    fs.mkdirSync(path.dirname(fullPath), { recursive: true });
    fs.writeFileSync(fullPath, processedHTML, 'utf8');

    return { htmlFilePath, route, status };
  } catch (err) {
    logError(`Failed: ${route.label} — ${err.message}`);
    return null;
  }
}

// ─── Generate Index/Sitemap Page ──────────────────────────────────────────────

function generateIndexPage(outputDir, processed, urlMapper) {
  // Group pages by module
  const groups = {};
  for (const item of processed) {
    const parts = item.route.path.split('/').filter(Boolean);
    let groupName = 'Root';
    if (parts[0] === 'modules' && parts.length > 1) {
      // Capitalize module name
      groupName = parts[1].charAt(0).toUpperCase() + parts[1].slice(1);
      groupName = groupName.replace(/[_-]/g, ' ');
    }
    if (!groups[groupName]) groups[groupName] = [];
    groups[groupName].push(item);
  }

  const groupsHTML = Object.entries(groups)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([name, items]) => {
      const linksHTML = items.map(item => {
        const relPath = item.htmlFilePath;
        return `          <a href="./${relPath}" class="block p-3 border border-slate-200 rounded-lg hover:bg-indigo-50 hover:border-indigo-200 transition-colors text-sm font-medium text-slate-700 hover:text-indigo-700">${item.route.label}</a>`;
      }).join('\n');

      return `
      <section class="mb-8">
        <h2 class="text-lg font-semibold text-slate-800 mb-3 flex items-center gap-2">
          <span class="w-2 h-2 bg-indigo-500 rounded-full"></span>
          ${name}
          <span class="text-xs font-normal text-slate-400">(${items.length} pages)</span>
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
${linksHTML}
        </div>
      </section>`;
    }).join('\n');

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>HRIS Application — Interactive Demo</title>
  <link rel="stylesheet" href="./assets/css/inter-font.css">
  <link rel="stylesheet" href="./assets/css/tailwind-generated.css">
  <link rel="stylesheet" href="./assets/css/app.css">
  <style>
    body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
  </style>
</head>
<body class="bg-slate-50 min-h-screen">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-12">
    <header class="text-center mb-10">
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 mb-4">
        <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
        </svg>
      </div>
      <h1 class="text-3xl sm:text-4xl font-bold text-slate-900 mb-2">HRIS Application Demo</h1>
      <p class="text-base sm:text-lg text-slate-500 max-w-2xl mx-auto">
        Interactive static mirror of the Human Resource Information System.
        Browse ${processed.length} pages with working sidebar, modals, and navigation.
      </p>
      <p class="text-xs text-slate-400 mt-2">All employee data has been anonymized for this demo.</p>

      <div class="mt-6 flex flex-wrap justify-center gap-3">
        <a href="./pages/dashboard.html" class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-600 to-indigo-500 text-white font-semibold rounded-lg shadow hover:shadow-md transition-all text-sm">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
          Open Dashboard
        </a>
        <a href="./pages/login.html" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white text-slate-700 font-semibold rounded-lg border border-slate-200 hover:bg-slate-50 transition-all text-sm">
          View Login Page
        </a>
      </div>
    </header>

    <div class="mb-6">
      <input type="text" id="searchPages" placeholder="Search pages..."
        class="w-full px-4 py-3 border border-slate-200 rounded-xl bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 text-sm">
    </div>

    <div id="pageGroups">
${groupsHTML}
    </div>

    <footer class="mt-12 pt-6 border-t border-slate-200 text-center text-xs text-slate-400">
      Generated on ${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
      &middot; ${processed.length} pages &middot; All data anonymized
    </footer>
  </div>

  <script>
    document.getElementById('searchPages').addEventListener('input', function(e) {
      var query = e.target.value.toLowerCase();
      document.querySelectorAll('#pageGroups section').forEach(function(section) {
        var links = section.querySelectorAll('a');
        var anyVisible = false;
        links.forEach(function(link) {
          var match = link.textContent.toLowerCase().includes(query);
          link.style.display = match ? '' : 'none';
          if (match) anyVisible = true;
        });
        section.style.display = anyVisible ? '' : 'none';
      });
    });
  </script>
  <script src="./assets/js/demo-banner.js" defer></script>
</body>
</html>`;

  const indexPath = path.join(outputDir, 'index.html');
  fs.writeFileSync(indexPath, html, 'utf8');
  log(`Index page generated: ${indexPath}`);
}

// ─── Progress / Resume ────────────────────────────────────────────────────────

function loadProgress(outputDir) {
  const progressFile = path.join(outputDir, 'progress.json');
  if (fs.existsSync(progressFile)) {
    try {
      return JSON.parse(fs.readFileSync(progressFile, 'utf8'));
    } catch {
      return null;
    }
  }
  return null;
}

function saveProgress(outputDir, completed, anonymizerState) {
  const progressFile = path.join(outputDir, 'progress.json');
  fs.writeFileSync(progressFile, JSON.stringify({
    completed,
    anonymizerState,
    timestamp: new Date().toISOString(),
  }, null, 2));
}

function clearProgress(outputDir) {
  const progressFile = path.join(outputDir, 'progress.json');
  if (fs.existsSync(progressFile)) {
    fs.unlinkSync(progressFile);
  }
}

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  const startTime = Date.now();

  log('=== HRIS Static Site Generator ===');
  log(`Base URL:     ${CONFIG.baseUrl}`);
  log(`Output:       ${CONFIG.outputDir}`);
  log(`Viewport:     ${activeViewport} (${viewportConfig.width}x${viewportConfig.height})`);
  log(`Routes:       ${ROUTES.length} pages`);
  log(`Anonymize:    ${CONFIG.anonymize ? 'yes' : 'no'}`);
  log(`Resume:       ${CONFIG.resume ? 'yes' : 'no'}`);
  log('');

  // Setup output directory
  fs.mkdirSync(path.join(CONFIG.outputDir, 'pages'), { recursive: true });

  // Initialize components
  const urlMapper = new URLMapper(ROUTES, CONFIG.baseUrl);
  const assetBundler = new AssetBundler(CONFIG.baseUrl, CONFIG.outputDir);
  const anonymizer = new Anonymizer();
  const htmlProcessor = new HTMLProcessor(urlMapper, assetBundler, CONFIG.outputDir);

  // Load resume state if applicable
  let completedPaths = new Set();
  if (CONFIG.resume) {
    const progress = loadProgress(CONFIG.outputDir);
    if (progress) {
      completedPaths = new Set(progress.completed || []);
      if (progress.anonymizerState) {
        anonymizer.importState(progress.anonymizerState);
      }
      log(`Resuming: ${completedPaths.size} pages already completed`);
    }
  }

  // Phase 1: Download and bundle external assets
  log('── Phase 1: Bundle Assets ──');
  await assetBundler.bundleAll(log);
  assetBundler.createDemoBannerJS();
  log('');

  // Phase 2: Launch browser and login
  log('── Phase 2: Browser Setup ──');
  const browser = await puppeteer.launch({
    headless: 'new',
    defaultViewport: {
      width: viewportConfig.width,
      height: viewportConfig.height,
      deviceScaleFactor: viewportConfig.scale,
    },
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
    ],
  });

  const page = await browser.newPage();

  await page.setUserAgent(
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
    '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 HRIS-StaticGen'
  );

  page.on('dialog', async dialog => {
    log(`  Dialog dismissed: "${dialog.message().substring(0, 80)}"`);
    await dialog.accept();
  });

  // Phase 3: Capture pages
  log('');
  log('── Phase 3: Capture Pages ──');

  const processed = [];
  const failed = [];

  // Pre-auth pages first
  const preAuthRoutes = ROUTES.filter(r => r.requiresAuth === false);
  for (const route of preAuthRoutes) {
    const routeKey = route.path + (route.queryParams ? '?' + new URLSearchParams(route.queryParams).toString() : '');

    if (completedPaths.has(routeKey)) {
      log(`  SKIP (already done): ${route.label}`);
      // Still need it in processed for index page
      processed.push({
        htmlFilePath: urlMapper.routeToHTMLPath(route.path, route.queryParams),
        route,
        status: 'cached',
      });
      continue;
    }

    log(`[pre-auth] ${route.label}`);
    const result = await capturePage(page, route, anonymizer, htmlProcessor, urlMapper);
    if (result) {
      processed.push(result);
      completedPaths.add(routeKey);
      saveProgress(CONFIG.outputDir, Array.from(completedPaths), anonymizer.exportState());
    } else {
      failed.push({ route, error: 'capture failed' });
    }
  }

  // Login
  log('');
  log('Logging in...');
  await login(page);

  // Authenticated pages
  const authRoutes = ROUTES.filter(r => r.requiresAuth !== false);
  const total = authRoutes.length;
  let current = 0;

  for (const route of authRoutes) {
    current++;
    const pct = ((current / total) * 100).toFixed(0);
    const routeKey = route.path + (route.queryParams ? '?' + new URLSearchParams(route.queryParams).toString() : '');

    if (completedPaths.has(routeKey)) {
      log(`  SKIP (already done): ${route.label}`);
      processed.push({
        htmlFilePath: urlMapper.routeToHTMLPath(route.path, route.queryParams),
        route,
        status: 'cached',
      });
      continue;
    }

    log(`[${current}/${total} ${pct}%] ${route.label}`);
    const result = await capturePage(page, route, anonymizer, htmlProcessor, urlMapper);
    if (result) {
      processed.push(result);
      completedPaths.add(routeKey);
      saveProgress(CONFIG.outputDir, Array.from(completedPaths), anonymizer.exportState());
    } else {
      failed.push({ route, error: 'capture failed' });
    }

    // Periodically check if session is still valid (every 25 pages)
    if (current % 25 === 0) {
      const isLoggedIn = await page.evaluate(() => {
        return !window.location.pathname.includes('/login');
      });
      if (!isLoggedIn) {
        log('  Session expired — re-logging in...');
        await login(page);
      }
    }
  }

  await browser.close();

  // Phase 4: Generate index page
  log('');
  log('── Phase 4: Generate Index ──');
  generateIndexPage(CONFIG.outputDir, processed, urlMapper);

  // Save manifest
  const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
  const manifest = {
    timestamp: new Date().toISOString(),
    baseUrl: CONFIG.baseUrl,
    viewport: activeViewport,
    viewportSize: viewportConfig,
    anonymized: CONFIG.anonymize,
    durationSeconds: parseFloat(elapsed),
    totalPages: processed.length,
    failedPages: failed.length,
    pages: processed.map(p => ({
      path: p.route.path,
      label: p.route.label,
      htmlFile: p.htmlFilePath,
      status: p.status,
    })),
    failed: failed.map(f => ({
      path: f.route.path,
      label: f.route.label,
      error: f.error,
    })),
  };

  const manifestPath = path.join(CONFIG.outputDir, 'manifest.json');
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));

  // Clean up progress file on success
  if (failed.length === 0) {
    clearProgress(CONFIG.outputDir);
  }

  // Summary
  log('');
  log('=== Generation Complete ===');
  log(`Captured:   ${processed.length} pages`);
  log(`Failed:     ${failed.length} pages`);
  log(`Duration:   ${elapsed}s`);
  log(`Output:     ${CONFIG.outputDir}`);
  log('');
  log('To preview:');
  log(`  cd "${CONFIG.outputDir}" && npx http-server -p 8080`);
  log('  Then open http://localhost:8080');

  if (failed.length > 0) {
    log('');
    log('Failed pages:');
    for (const f of failed) {
      log(`  - ${f.route.label} (${f.route.path})`);
    }
  }

  process.exit(failed.length > 0 ? 1 : 0);
}

main().catch(err => {
  logError(`Fatal: ${err.message}`);
  console.error(err.stack);
  process.exit(2);
});
