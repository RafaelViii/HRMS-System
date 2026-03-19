/**
 * Asset Bundler — downloads and localizes external dependencies.
 *
 * Downloads: Tailwind CSS (generated), Chart.js, Inter font (CSS + WOFF2),
 * app.css, app.js, logo, and any other referenced assets.
 */

const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');

class AssetBundler {
  /**
   * @param {string} baseUrl — live site URL
   * @param {string} outputDir — root output directory for the static site
   */
  constructor(baseUrl, outputDir) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.outputDir = outputDir;
    this.assetMap = new Map(); // originalURL → local relative path
  }

  async bundleAll(log = console.log) {
    log('Downloading and bundling external assets...');

    const assetsDir = path.join(this.outputDir, 'assets');
    fs.mkdirSync(path.join(assetsDir, 'css', 'fonts'), { recursive: true });
    fs.mkdirSync(path.join(assetsDir, 'js'), { recursive: true });
    fs.mkdirSync(path.join(assetsDir, 'images'), { recursive: true });

    // Download in parallel where possible
    await Promise.all([
      this._downloadChartJS(log),
      this._downloadInterFont(log),
      this._downloadAppCSS(log),
      this._downloadLogo(log),
    ]);

    // app.js needs special handling (downloaded separately, modified later)
    await this._downloadAppJS(log);

    log(`  Bundled ${this.assetMap.size} assets`);
  }

  async _downloadChartJS(log) {
    const url = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    const localPath = 'assets/js/chart.min.js';
    const fullPath = path.join(this.outputDir, localPath);

    try {
      const data = await this._fetch(url);
      fs.writeFileSync(fullPath, data);
      this.assetMap.set(url, localPath);
      // Map common CDN patterns
      this.assetMap.set('https://cdn.jsdelivr.net/npm/chart.js', localPath);
      log('  Chart.js downloaded');
    } catch (err) {
      log(`  WARNING: Could not download Chart.js: ${err.message}`);
    }
  }

  async _downloadInterFont(log) {
    const cssUrl = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700&display=swap';

    try {
      // Google Fonts requires a browser-like UA to serve WOFF2
      const cssContent = await this._fetch(cssUrl, {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      });

      const cssText = cssContent.toString('utf8');

      // Extract WOFF2 URLs
      const woff2Regex = /url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.woff2)\)/g;
      const woff2Urls = [];
      let match;
      while ((match = woff2Regex.exec(cssText)) !== null) {
        woff2Urls.push(match[1]);
      }

      // Download each WOFF2 file
      let rewrittenCSS = cssText;
      for (let i = 0; i < woff2Urls.length; i++) {
        const woff2Url = woff2Urls[i];
        const localName = `inter-${i}.woff2`;
        const localPath = `assets/css/fonts/${localName}`;
        const fullPath = path.join(this.outputDir, localPath);

        try {
          const data = await this._fetch(woff2Url);
          fs.writeFileSync(fullPath, data);
          // Rewrite URL in CSS
          rewrittenCSS = rewrittenCSS.replace(woff2Url, `fonts/${localName}`);
        } catch {
          // Skip individual font files on error
        }
      }

      // Save rewritten font CSS
      const fontCSSPath = path.join(this.outputDir, 'assets/css/inter-font.css');
      fs.writeFileSync(fontCSSPath, rewrittenCSS);
      this.assetMap.set(cssUrl, 'assets/css/inter-font.css');
      // Map common Google Fonts patterns
      this.assetMap.set('https://fonts.googleapis.com', 'assets/css/inter-font.css');

      log(`  Inter font downloaded (${woff2Urls.length} WOFF2 files)`);
    } catch (err) {
      log(`  WARNING: Could not download Inter font: ${err.message}`);
    }
  }

  async _downloadAppCSS(log) {
    const url = `${this.baseUrl}/assets/css/app.css`;
    const localPath = 'assets/css/app.css';
    const fullPath = path.join(this.outputDir, localPath);

    try {
      const data = await this._fetch(url);
      fs.writeFileSync(fullPath, data);
      this.assetMap.set(url, localPath);
      this.assetMap.set('/assets/css/app.css', localPath);
      log('  app.css downloaded');
    } catch (err) {
      log(`  WARNING: Could not download app.css: ${err.message}`);
    }
  }

  async _downloadAppJS(log) {
    const url = `${this.baseUrl}/assets/js/app.js`;
    const localPath = 'assets/js/app-original.js';
    const fullPath = path.join(this.outputDir, localPath);

    try {
      const data = await this._fetch(url);
      const jsContent = data.toString('utf8');

      // Save original
      fs.writeFileSync(fullPath, jsContent);

      // Create modified static version
      const staticJS = this._createStaticAppJS(jsContent);
      const staticPath = path.join(this.outputDir, 'assets/js/app-static.js');
      fs.writeFileSync(staticPath, staticJS);

      this.assetMap.set(url, 'assets/js/app-static.js');
      this.assetMap.set('/assets/js/app.js', 'assets/js/app-static.js');
      log('  app.js downloaded and modified for static use');
    } catch (err) {
      log(`  WARNING: Could not download app.js: ${err.message}`);
    }
  }

  async _downloadLogo(log) {
    const url = `${this.baseUrl}/assets/resources/logo.jpg`;
    const localPath = 'assets/images/logo.jpg';
    const fullPath = path.join(this.outputDir, localPath);

    try {
      const data = await this._fetch(url);
      fs.writeFileSync(fullPath, data);
      this.assetMap.set(url, localPath);
      this.assetMap.set('/assets/resources/logo.jpg', localPath);
      log('  Logo downloaded');
    } catch (err) {
      // Logo might not exist or have a different path — non-critical
      log(`  WARNING: Could not download logo: ${err.message}`);
    }
  }

  /**
   * Create a modified app.js for static site usage.
   * - Disables SPA fetch navigation (removes fetch-based content swap)
   * - Disables keepalive pings
   * - Disables authorization modal server calls
   * - Keeps: sidebar toggle, modal open/close, dropdowns, confirm dialogs, loaders
   */
  _createStaticAppJS(originalJS) {
    let js = originalJS;

    // Prepend static mode flag and demo form handler
    const prefix = `
// === STATIC SITE MODE ===
window.__STATIC_DEMO = true;

// Block all form submissions in demo mode
document.addEventListener('submit', function(e) {
  e.preventDefault();
  e.stopPropagation();
  var modal = document.getElementById('confirmModal');
  if (modal) {
    var body = modal.querySelector('.modal-body, [class*="text"]');
    if (body) body.textContent = 'This is a demo — forms are disabled.';
    modal.classList.remove('hidden');
    setTimeout(function() { modal.classList.add('hidden'); }, 3000);
  }
  return false;
}, true);

// Override fetch to prevent network requests in static mode
var _origFetch = window.fetch;
window.fetch = function(url, opts) {
  if (window.__STATIC_DEMO) {
    console.log('[DEMO] Blocked fetch:', url);
    return Promise.reject(new Error('Demo mode — network requests disabled'));
  }
  return _origFetch.apply(this, arguments);
};

// Override XMLHttpRequest
var _origXHR = XMLHttpRequest.prototype.open;
XMLHttpRequest.prototype.open = function() {
  if (window.__STATIC_DEMO) {
    console.log('[DEMO] Blocked XHR:', arguments[1]);
    return;
  }
  return _origXHR.apply(this, arguments);
};

`;

    return prefix + js;
  }

  /**
   * Create a demo banner script that shows a floating indicator.
   */
  createDemoBannerJS() {
    const bannerJS = `
// Demo mode floating banner
(function() {
  var banner = document.createElement('div');
  banner.id = 'demoBanner';
  banner.innerHTML = '<span style="margin-right:8px">&#9432;</span> Demo Mode &mdash; All data is anonymized';
  banner.style.cssText = 'position:fixed;bottom:16px;right:16px;z-index:99999;' +
    'background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;' +
    'padding:8px 16px;border-radius:8px;font-size:13px;font-family:Inter,sans-serif;' +
    'font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,0.15);cursor:pointer;' +
    'opacity:0.9;transition:opacity 0.2s;';
  banner.addEventListener('mouseenter', function() { this.style.opacity = '1'; });
  banner.addEventListener('mouseleave', function() { this.style.opacity = '0.9'; });
  banner.addEventListener('click', function() { this.style.display = 'none'; });
  document.body.appendChild(banner);
})();
`;

    const filePath = path.join(this.outputDir, 'assets/js/demo-banner.js');
    fs.writeFileSync(filePath, bannerJS);
  }

  /**
   * Fetch a URL and return a Buffer.
   */
  _fetch(url, extraHeaders = {}) {
    return new Promise((resolve, reject) => {
      const mod = url.startsWith('https') ? https : http;
      const headers = {
        'Accept': '*/*',
        'Accept-Encoding': 'identity',
        ...extraHeaders,
      };

      const req = mod.get(url, { headers, timeout: 30000 }, (res) => {
        // Follow redirects
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          this._fetch(res.headers.location, extraHeaders).then(resolve).catch(reject);
          return;
        }

        if (res.statusCode !== 200) {
          reject(new Error(`HTTP ${res.statusCode} for ${url}`));
          return;
        }

        const chunks = [];
        res.on('data', (chunk) => chunks.push(chunk));
        res.on('end', () => resolve(Buffer.concat(chunks)));
        res.on('error', reject);
      });

      req.on('error', reject);
      req.on('timeout', () => { req.destroy(); reject(new Error('Timeout')); });
    });
  }
}

module.exports = AssetBundler;
