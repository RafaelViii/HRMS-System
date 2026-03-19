#!/usr/bin/env node
/**
 * HRIS Screenshot Automation
 *
 * Captures every page of the HRIS app at desktop and mobile viewports,
 * saves individual PNGs, and compiles a single PDF with all screenshots.
 *
 * Usage:
 *   node screenshot.js                                # Both viewports
 *   node screenshot.js --viewport=desktop             # Desktop only
 *   node screenshot.js --viewport=mobile              # Mobile only
 *   node screenshot.js --base-url=https://pocc.systems
 *   node screenshot.js --output=./my-output           # Custom output dir
 */

const puppeteer = require('puppeteer');
const { PDFDocument } = require('pdf-lib');
const fs = require('fs');
const path = require('path');
const ROUTES = require('./routes');

// ─── CLI Argument Parser ──────────────────────────────────────────────────────

function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(name + '='));
  return arg ? arg.split('=').slice(1).join('=') : null;
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
  outputDir: getArg('--output') || path.join(__dirname, 'output'),
  timeouts: {
    navigation: 30_000,
    settleDelay: 2000,
    loginWait: 3000,
  },
  pdfFilename: 'hris-screenshots.pdf',
};

// Parse --viewport flag
const viewportArg = getArg('--viewport');
const ACTIVE_VIEWPORTS = viewportArg
  ? { [viewportArg]: CONFIG.viewports[viewportArg] }
  : CONFIG.viewports;

if (viewportArg && !CONFIG.viewports[viewportArg]) {
  console.error(`Unknown viewport "${viewportArg}". Use "desktop" or "mobile".`);
  process.exit(1);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function sanitizeFilename(str) {
  return str.replace(/[^a-zA-Z0-9_-]/g, '_').replace(/_+/g, '_');
}

function buildUrl(routePath, queryParams) {
  let url = CONFIG.baseUrl + routePath;
  if (queryParams) {
    url += '?' + new URLSearchParams(queryParams).toString();
  }
  return url;
}

function ts() {
  return new Date().toISOString().replace('T', ' ').substring(0, 19);
}

function log(msg) {
  console.log(`[${ts()}] ${msg}`);
}

function logError(msg) {
  console.error(`[${ts()}] ERROR: ${msg}`);
}

// ─── Core Functions ───────────────────────────────────────────────────────────

/**
 * Wait for HRIS page to finish loading:
 * 1. Wait for #appLoader and #contentLoader to have 'hidden' class
 * 2. Settle delay for JS-rendered content (Chart.js, dynamic tables)
 */
async function waitForPageReady(page) {
  // Wait for loaders to disappear (max 10s)
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
    // Page might not have these elements (e.g., login page)
  }

  await new Promise(r => setTimeout(r, CONFIG.timeouts.settleDelay));
}

/**
 * Dismiss any visible modals/overlays that might obscure content.
 */
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

/**
 * Login to the HRIS application.
 */
async function login(page) {
  const loginUrl = CONFIG.baseUrl + '/login';
  log('Navigating to login page...');

  await page.goto(loginUrl, {
    waitUntil: 'networkidle2',
    timeout: CONFIG.timeouts.navigation,
  });

  // Extract CSRF token
  const csrfToken = await page.evaluate(() => {
    const input = document.querySelector('input[name="csrf"]');
    return input ? input.value : null;
  });

  if (!csrfToken) {
    throw new Error('Could not find CSRF token on login page');
  }

  log('Found CSRF token, filling login form...');

  // Fill form fields
  await page.type('#email', CONFIG.credentials.email, { delay: 20 });
  await page.type('#password', CONFIG.credentials.password, { delay: 20 });

  // Submit and wait for navigation
  await Promise.all([
    page.waitForNavigation({
      waitUntil: 'networkidle2',
      timeout: CONFIG.timeouts.navigation,
    }),
    page.click('button[type="submit"]'),
  ]);

  // Verify login succeeded
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

/**
 * Capture a single page at a specific viewport.
 * Returns result object or null on failure.
 */
async function captureScreenshot(page, route, viewportName, viewport, outputDir) {
  const url = buildUrl(route.path, route.queryParams);
  const safeName = sanitizeFilename(route.label || route.path);
  const filename = `${safeName}__${viewportName}.png`;
  const filepath = path.join(outputDir, filename);

  try {
    // Set viewport
    await page.setViewport({
      width: viewport.width,
      height: viewport.height,
      deviceScaleFactor: viewport.scale,
    });

    // Full page navigation for isolation
    const response = await page.goto(url, {
      waitUntil: 'networkidle2',
      timeout: CONFIG.timeouts.navigation,
    });

    const status = response ? response.status() : 'unknown';
    if (status >= 400) {
      log(`  WARNING: HTTP ${status} for ${route.path}`);
    }

    // Wait for dynamic content
    await waitForPageReady(page);
    await dismissOverlays(page);

    // Scroll to top
    await page.evaluate(() => {
      window.scrollTo(0, 0);
      const main = document.getElementById('appMain');
      if (main) main.scrollTop = 0;
    });

    await new Promise(r => setTimeout(r, 500));

    // Take full-page screenshot
    await page.screenshot({
      path: filepath,
      fullPage: true,
      type: 'png',
    });

    return { filepath, filename, route, viewportName, status };
  } catch (err) {
    logError(`Failed: ${route.label} [${viewportName}] — ${err.message}`);
    return null;
  }
}

/**
 * Compile all PNGs into a single PDF.
 */
async function compilePdf(screenshots, outputDir) {
  log('Compiling PDF...');

  const pdfDoc = await PDFDocument.create();
  pdfDoc.setTitle('HRIS Application Screenshots');
  pdfDoc.setSubject('Page captures — desktop and mobile viewports');
  pdfDoc.setCreator('HRIS Screenshot Automation');
  pdfDoc.setCreationDate(new Date());

  let added = 0;

  for (const shot of screenshots) {
    if (!shot || !fs.existsSync(shot.filepath)) continue;

    try {
      const imgBytes = fs.readFileSync(shot.filepath);
      const pngImage = await pdfDoc.embedPng(imgBytes);

      // A4 landscape for desktop, A4 portrait for mobile
      const isDesktop = shot.viewportName === 'desktop';
      const pageWidth = isDesktop ? 1190 : 595;

      // Scale image to page width with margins
      const margin = 20;
      const drawWidth = pageWidth - margin * 2;
      const drawHeight = drawWidth / (pngImage.width / pngImage.height);
      const totalPageHeight = Math.max(842, drawHeight + 60);

      const pdfPage = pdfDoc.addPage([pageWidth, totalPageHeight]);

      // Label
      pdfPage.drawText(`${shot.route.label}  (${shot.viewportName})`, {
        x: margin,
        y: totalPageHeight - 18,
        size: 10,
      });

      // Image
      pdfPage.drawImage(pngImage, {
        x: margin,
        y: totalPageHeight - 35 - drawHeight,
        width: drawWidth,
        height: drawHeight,
      });

      added++;
    } catch (err) {
      logError(`PDF: failed to add ${shot.filename} — ${err.message}`);
    }
  }

  const pdfPath = path.join(outputDir, CONFIG.pdfFilename);
  const pdfBytes = await pdfDoc.save();
  fs.writeFileSync(pdfPath, pdfBytes);

  log(`PDF compiled: ${added} pages → ${pdfPath}`);
  return pdfPath;
}

// ─── Viewport Pass ────────────────────────────────────────────────────────────

/**
 * Run a complete pass for one viewport: fresh browser, login, capture all pages.
 * This avoids session invalidation from viewport/IP fingerprint changes.
 */
async function runViewportPass(vpName, vpSize, screenshotsDir, results, errors) {
  log(`\n========== ${vpName.toUpperCase()} PASS (${vpSize.width}x${vpSize.height}) ==========\n`);

  const browser = await puppeteer.launch({
    headless: 'new',
    defaultViewport: {
      width: vpSize.width,
      height: vpSize.height,
      deviceScaleFactor: vpSize.scale,
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
    '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 HRIS-Screenshot-Bot'
  );

  page.on('dialog', async dialog => {
    log(`  Dialog dismissed: "${dialog.message().substring(0, 80)}"`);
    await dialog.accept();
  });

  try {
    // Pre-auth pages
    const preAuthRoutes = ROUTES.filter(r => r.requiresAuth === false);
    for (const route of preAuthRoutes) {
      log(`Capturing: ${route.label} [${vpName}]`);
      const result = await captureScreenshot(page, route, vpName, vpSize, screenshotsDir);
      if (result) results.push(result);
      else errors.push({ route, viewport: vpName });
    }

    // Login (at the same viewport size — no switching)
    log('');
    log(`Logging in for ${vpName} pass...`);
    await login(page);

    // Authenticated pages
    const authRoutes = ROUTES.filter(r => r.requiresAuth !== false);
    const total = authRoutes.length;
    let current = 0;

    for (const route of authRoutes) {
      current++;
      const pct = ((current / total) * 100).toFixed(0);
      log(`[${current}/${total} ${pct}%] ${route.label} [${vpName}]`);

      const result = await captureScreenshot(page, route, vpName, vpSize, screenshotsDir);
      if (result) results.push(result);
      else errors.push({ route, viewport: vpName });
    }
  } catch (err) {
    logError(`Fatal in ${vpName} pass: ${err.message}`);
    console.error(err.stack);
  } finally {
    await browser.close();
  }

  log(`\n── ${vpName.toUpperCase()} pass complete ──\n`);
}

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  const startTime = Date.now();

  const screenshotsDir = path.join(CONFIG.outputDir, 'pages');
  fs.mkdirSync(screenshotsDir, { recursive: true });

  log('=== HRIS Screenshot Automation ===');
  log(`Base URL:   ${CONFIG.baseUrl}`);
  log(`Output:     ${CONFIG.outputDir}`);
  log(`Viewports:  ${Object.keys(ACTIVE_VIEWPORTS).join(', ')}`);
  log(`Routes:     ${ROUTES.length} pages`);

  const results = [];
  const errors = [];

  // Run each viewport as a separate pass with its own browser + session
  for (const [vpName, vpSize] of Object.entries(ACTIVE_VIEWPORTS)) {
    await runViewportPass(vpName, vpSize, screenshotsDir, results, errors);
  }

  // Compile PDF from all captured screenshots
  log('\n── Compiling PDF ──');
  await compilePdf(results, CONFIG.outputDir);

  // ── Summary ──
  const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
  log('');
  log('=== Summary ===');
  log(`Captured:  ${results.length} screenshots`);
  log(`Failed:    ${errors.length} pages`);
  log(`Duration:  ${elapsed}s`);
  log(`Output:    ${CONFIG.outputDir}`);

  if (errors.length > 0) {
    log('');
    log('Failed pages:');
    for (const e of errors) {
      log(`  - ${e.route.label} (${e.route.path}) [${e.viewport}]`);
    }
  }

  // Write JSON manifest
  const manifest = {
    timestamp: new Date().toISOString(),
    baseUrl: CONFIG.baseUrl,
    durationSeconds: parseFloat(elapsed),
    captured: results.map(r => ({
      path: r.route.path,
      label: r.route.label,
      viewport: r.viewportName,
      filename: r.filename,
      httpStatus: r.status,
    })),
    failed: errors.map(e => ({
      path: e.route.path,
      label: e.route.label,
      viewport: e.viewport,
    })),
  };

  const manifestPath = path.join(CONFIG.outputDir, 'manifest.json');
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
  log(`Manifest:  ${manifestPath}`);

  process.exit(errors.length > 0 ? 1 : 0);
}

main();
