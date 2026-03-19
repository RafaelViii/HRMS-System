/**
 * QZ Tray Integration Module for HRMS POS
 * Wraps the official QZ Tray JS SDK (qz-tray-sdk.js) for silent thermal printing.
 *
 * Prerequisites:
 *   1. QZ Tray installed on client machine: https://qz.io/download/
 *   2. Official SDK loaded BEFORE this file: assets/js/vendor/qz-tray-sdk.js
 *
 * Usage:
 *   await QZIntegration.connect();
 *   const printers = await QZIntegration.findPrinters();
 *   await QZIntegration.printReceipt(receiptData, 'PrinterName');
 */
window.QZIntegration = (function () {
  'use strict';

  // ── State ──────────────────────────────────────
  let _defaultPrinter = null;
  let _paperWidth = 48;       // chars per line (48 for 58mm, 42 for 80mm in condensed)
  let _autoPrint = false;
  let _onStatusChange = null;

  // ── SDK Check ──────────────────────────────────
  function _ensureSDK() {
    if (typeof qz === 'undefined') {
      throw new Error('QZ Tray SDK not loaded — include qz-tray-sdk.js before this script');
    }
  }

  // ── Security (development / self-signed mode) ──
  // QZ Tray requires a signed certificate to suppress the user trust dialog.
  //
  // CURRENT MODE: Unsigned / development
  //   - QZ Tray shows a "Trust this site?" dialog on first connection per browser session.
  //   - Acceptable for internal/intranet deployments behind authenticated sessions.
  //
  // PRODUCTION HARDENING (optional):
  //   1. Purchase or generate a QZ Tray signing certificate: https://qz.io/pricing/
  //   2. Host the public certificate PEM on your server (e.g. /assets/certs/qz-cert.pem)
  //   3. Create a server-side signing endpoint (e.g. /modules/inventory/api_qz_sign.php)
  //      that accepts the data-to-sign and returns an RSA signature using your private key.
  //   4. Replace setCertificatePromise to fetch() the PEM and resolve with its contents.
  //   5. Replace setSignaturePromise to POST toSign to your signing endpoint.
  //   6. Keep the private key server-side only — never expose it to the browser.
  //
  // SECURITY NOTES:
  //   - QZ Tray connections are local WebSocket (wss://localhost:8181) — no network exposure.
  //   - All print operations run client-side; the server only stores user preferences.
  //   - Private key stays server-side (api_qz_sign.php) — never exposed to browser.
  //   - Certificate must be imported into QZ Tray once per machine (see api_qz_sign.php header).
  function _initSecurity() {
    _ensureSDK();

    var baseUrl = (window.__baseUrl || '');

    // Use SHA-512 for signing (must match server-side algorithm in api_qz_sign.php)
    qz.security.setSignatureAlgorithm('SHA512');

    // Provide the public certificate so QZ Tray can verify our identity
    qz.security.setCertificatePromise(function(resolve, reject) {
      fetch(baseUrl + '/assets/certs/qz-cert.pem', { cache: 'force-cache' })
        .then(function(res) {
          if (!res.ok) throw new Error('Certificate not found');
          return res.text();
        })
        .then(resolve)
        .catch(function(err) {
          console.warn('[QZ] Certificate not available, falling back to unsigned mode');
          resolve(); // Fall back to unsigned (trust dialog)
        });
    });

    // Sign connection requests server-side (private key never leaves server)
    qz.security.setSignaturePromise(function(toSign) {
      return function(resolve, reject) {
        fetch(baseUrl + '/modules/inventory/api_qz_sign', {
          method: 'POST',
          headers: { 'Content-Type': 'text/plain' },
          body: toSign
        })
        .then(function(res) {
          if (!res.ok) throw new Error('Signing failed');
          return res.text();
        })
        .then(resolve)
        .catch(function(err) {
          console.warn('[QZ] Signing failed, falling back to unsigned mode');
          resolve(); // Fall back to unsigned (trust dialog)
        });
      };
    });
  }

  // ── Public API ─────────────────────────────────

  /**
   * Connect to QZ Tray via WebSocket (official SDK)
   */
  async function connect() {
    _ensureSDK();
    _initSecurity();

    if (qz.websocket.isActive()) {
      _setStatus('connected', 'Already connected to QZ Tray');
      return true;
    }

    try {
      await qz.websocket.connect();
      _setStatus('connected', 'Connected to QZ Tray');

      // Listen for close events to update status
      qz.websocket.setClosedCallbacks(function(evt) {
        _setStatus('disconnected', 'Disconnected from QZ Tray');
      });
      qz.websocket.setErrorCallbacks(function(evt) {
        _setStatus('error', 'QZ Tray connection error');
      });

      return true;
    } catch (e) {
      _setStatus('error', 'QZ Tray not running — install from qz.io');
      throw new Error('QZ Tray connection failed: ' + (e.message || e));
    }
  }

  /** Disconnect from QZ Tray */
  function disconnect() {
    _ensureSDK();
    if (qz.websocket.isActive()) {
      qz.websocket.disconnect().then(function() {
        _setStatus('disconnected', 'Disconnected');
      }).catch(function() {
        _setStatus('disconnected', 'Disconnected');
      });
    } else {
      _setStatus('disconnected', 'Disconnected');
    }
  }

  /** Check if currently connected */
  function isConnected() {
    try {
      _ensureSDK();
      return qz.websocket.isActive();
    } catch (e) {
      return false;
    }
  }

  /**
   * Get list of all printers visible to the local OS
   * @returns {Promise<string[]>}
   */
  async function findPrinters() {
    try {
      _ensureSDK();
      var printers = await qz.printers.find();
      // qz.printers.find() without query returns an array of all printers
      return Array.isArray(printers) ? printers : [printers];
    } catch (e) {
      console.error('[QZ] findPrinters error:', e);
      return [];
    }
  }

  /**
   * Find the default OS printer
   * @returns {Promise<string|null>}
   */
  async function getDefaultPrinter() {
    try {
      _ensureSDK();
      var name = await qz.printers.getDefault();
      return name || null;
    } catch (e) {
      return null;
    }
  }

  /**
   * Print raw ESC/POS data to a thermal printer
   * @param {string} printerName - Exact printer name from findPrinters()
   * @param {string[]} rawCommands - Array of ESC/POS command strings
   * @param {object} [options] - Print options
   */
  async function printRaw(printerName, rawCommands, options) {
    _ensureSDK();
    var printer = printerName || _defaultPrinter;
    if (!printer) throw new Error('No printer specified');

    var config = qz.configs.create(printer, Object.assign({}, options || {}));

    // Join all commands into a single string for raw ESC/POS printing
    var rawData = rawCommands.join('');

    // QZ Tray SDK expects data array with proper type/format/data structure
    var data = [{
      type: 'raw',
      format: 'command',
      data: rawData
    }];

    return qz.print(config, data);
  }

  /**
   * Print an HTML receipt by rendering it
   * @param {string} printerName
   * @param {string} htmlContent
   * @param {object} [options]
   */
  async function printHtml(printerName, htmlContent, options) {
    _ensureSDK();
    var printer = printerName || _defaultPrinter;
    if (!printer) throw new Error('No printer specified');

    var config = qz.configs.create(printer, Object.assign({
      colorType: 'grayscale',
      margins: { top: 0, right: 0, bottom: 0, left: 0 }
    }, options || {}));

    var data = [{
      type: 'pixel',
      format: 'html',
      flavor: 'plain',
      data: htmlContent
    }];

    return qz.print(config, data);
  }

  // ── ESC/POS Receipt Builder ────────────────────

  /**
   * Build ESC/POS commands from structured receipt data
   * Returns an array of raw command strings
   *
   * @param {object} txn - Transaction data from API
   * @param {object} [opts] - { paperWidth: 48 }
   * @returns {string[]} Array of ESC/POS command strings
   */
  function buildReceiptCommands(txn, opts) {
    var pw = (opts && opts.paperWidth) || _paperWidth;
    var cmds = [];

    // ESC/POS constants
    var ESC = '\x1B';
    var GS  = '\x1D';
    var LF  = '\x0A';

    // Initialize printer
    cmds.push(ESC + '@');
    // Select character code page (PC437 USA)
    cmds.push(ESC + 't\x00');

    // ── Header ──
    // Center align
    cmds.push(ESC + 'a\x01');
    // Double-height, bold for store name
    cmds.push(ESC + 'E\x01'); // bold on
    cmds.push(GS + '!\x11');  // double width+height
    cmds.push((txn.receipt_header || 'STORE') + LF);
    cmds.push(GS + '!\x00');  // normal size
    cmds.push(ESC + 'E\x00'); // bold off

    if (txn.receipt_subheader) {
      cmds.push(txn.receipt_subheader + LF);
    }
    if (txn.receipt_address) {
      cmds.push(txn.receipt_address + LF);
    }

    cmds.push(_repeat('-', pw) + LF);

    // ── Transaction Info ──
    // Left align
    cmds.push(ESC + 'a\x00');
    cmds.push(_padRight('Txn#: ' + (txn.txn_number || ''), pw) + LF);
    cmds.push(_padRight('Date: ' + (txn.date || ''), pw) + LF);
    cmds.push(_padRight('Cashier: ' + (txn.cashier || ''), pw) + LF);
    if (txn.customer_name) {
      cmds.push(_padRight('Customer: ' + txn.customer_name, pw) + LF);
    }
    cmds.push(_repeat('-', pw) + LF);

    // ── Items Header ──
    cmds.push(ESC + 'E\x01');
    cmds.push(_fmtItemLine('Item', 'Qty', 'Price', 'Total', pw) + LF);
    cmds.push(ESC + 'E\x00');
    cmds.push(_repeat('-', pw) + LF);

    // ── Items ──
    (txn.items || []).forEach(function (it) {
      var name = it.item_name || '';
      var qty = String(it.quantity || 0);
      var price = _fmtNum(it.unit_price);
      var total = _fmtNum(it.line_total);

      // If item name is too long, print it on its own line
      var maxName = pw - 22; // reserve space for qty+price+total
      if (name.length > maxName) {
        cmds.push(name + LF);
        cmds.push(_fmtItemLine('', qty, price, total, pw) + LF);
      } else {
        cmds.push(_fmtItemLine(name, qty, price, total, pw) + LF);
      }
    });

    cmds.push(_repeat('-', pw) + LF);

    // ── Totals ──
    cmds.push(_padBetween('Subtotal:', _fmtNum(txn.subtotal), pw) + LF);
    if (parseFloat(txn.discount_amount) > 0) {
      cmds.push(_padBetween('Discount:', '-' + _fmtNum(txn.discount_amount), pw) + LF);
    }

    // Bold total
    cmds.push(ESC + 'E\x01');
    cmds.push(GS + '!\x10'); // double height
    cmds.push(_padBetween('TOTAL:', _fmtNum(txn.total_amount), pw) + LF);
    cmds.push(GS + '!\x00');
    cmds.push(ESC + 'E\x00');

    cmds.push(_padBetween('Payment (' + (txn.payment_method || 'cash') + '):', _fmtNum(txn.amount_tendered), pw) + LF);
    if (parseFloat(txn.change_amount) > 0) {
      cmds.push(_padBetween('Change:', _fmtNum(txn.change_amount), pw) + LF);
    }

    cmds.push(_repeat('-', pw) + LF);

    // ── Footer ──
    cmds.push(ESC + 'a\x01'); // center
    cmds.push((txn.receipt_footer || 'Thank you!') + LF);
    cmds.push(LF);

    // Feed and cut
    cmds.push(LF + LF + LF);
    cmds.push(GS + 'V\x41\x03'); // partial cut

    return cmds;
  }

  /**
   * Convenience: print a POS receipt from transaction data
   * @param {object} txn - Transaction object from checkout API
   * @param {string} [printerName] - Target printer (uses configured default if omitted)
   * @returns {Promise}
   */
  async function printReceipt(txn, printerName) {
    var commands = buildReceiptCommands(txn, { paperWidth: _paperWidth });
    return printRaw(printerName || _defaultPrinter, commands);
  }

  /**
   * Print a test page to verify printer is working.
   * Uses HTML (pixel) rendering so it works on ALL printer types
   * (regular inkjet/laser AND thermal receipt printers).
   */
  async function printTestPage(printerName) {
    var now = new Date().toLocaleString('en-PH');
    var html = '<html><head><style>' +
      'body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:20px;color:#1e293b}' +
      '.card{border:2px solid #6366f1;border-radius:12px;padding:24px;max-width:400px;margin:0 auto}' +
      'h1{color:#4f46e5;font-size:22px;margin:0 0 4px}' +
      'h2{color:#64748b;font-size:13px;font-weight:normal;margin:0 0 16px}' +
      '.sep{border-top:1px dashed #cbd5e1;margin:12px 0}' +
      'table{width:100%;border-collapse:collapse;font-size:13px}' +
      'th{text-align:left;padding:4px 0;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0}' +
      'td{padding:4px 0}' +
      '.r{text-align:right}' +
      '.total{font-size:16px;font-weight:700;color:#4f46e5}' +
      '.footer{text-align:center;margin-top:16px;font-size:12px;color:#94a3b8}' +
      '.check{color:#22c55e;font-size:28px;text-align:center;margin:8px 0}' +
      '</style></head><body><div class="card">' +
      '<h1>QZ Tray Test Print</h1>' +
      '<h2>HRIS Print Server</h2>' +
      '<div class="check">&#10004; Connection Successful</div>' +
      '<div class="sep"></div>' +
      '<table>' +
      '<tr><td>Printer</td><td class="r"><strong>' + (printerName || 'Default') + '</strong></td></tr>' +
      '<tr><td>Date/Time</td><td class="r">' + now + '</td></tr>' +
      '<tr><td>Test ID</td><td class="r">TEST-' + Date.now() + '</td></tr>' +
      '</table>' +
      '<div class="sep"></div>' +
      '<table><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th></tr></thead><tbody>' +
      '<tr><td>Test Item 1</td><td class="r">2</td><td class="r">100.00</td><td class="r">200.00</td></tr>' +
      '<tr><td>Test Item 2</td><td class="r">1</td><td class="r">50.50</td><td class="r">50.50</td></tr>' +
      '</tbody></table>' +
      '<div class="sep"></div>' +
      '<table>' +
      '<tr><td>Subtotal</td><td class="r">250.50</td></tr>' +
      '<tr><td class="total">TOTAL</td><td class="r total">250.50</td></tr>' +
      '</table>' +
      '<div class="footer">If you can read this, your printer is working correctly with QZ Tray.</div>' +
      '</div></body></html>';

    return printHtml(printerName || _defaultPrinter, html);
  }

  // ── Configuration ──────────────────────────────

  function setDefaultPrinter(name) { _defaultPrinter = name; }
  function getDefaultPrinterName() { return _defaultPrinter; }
  function setPaperWidth(w) { _paperWidth = Math.max(32, Math.min(80, parseInt(w) || 48)); }
  function getPaperWidth() { return _paperWidth; }
  function setAutoPrint(v) { _autoPrint = !!v; }
  function getAutoPrint() { return _autoPrint; }

  /**
   * Register a callback for connection status changes
   * @param {function(status, message)} fn
   */
  function onStatusChange(fn) { _onStatusChange = fn; }

  /**
   * Load saved preferences from the server
   */
  async function loadSettings(baseUrl, csrf) {
    try {
      var res = await fetch(baseUrl + '/modules/inventory/api_qz_settings?action=get');
      var data = await res.json();
      if (data.success && data.settings) {
        _defaultPrinter = data.settings.default_printer || null;
        _paperWidth = parseInt(data.settings.paper_width) || 48;
        _autoPrint = data.settings.auto_print === 'true' || data.settings.auto_print === true;
      }
    } catch (e) {
      console.warn('[QZ] Could not load settings:', e);
    }
  }

  /**
   * Save preferences to the server
   */
  async function saveSettings(baseUrl, csrf) {
    try {
      var res = await fetch(baseUrl + '/modules/inventory/api_qz_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: csrf,
          action: 'save',
          default_printer: _defaultPrinter || '',
          paper_width: _paperWidth,
          auto_print: _autoPrint
        })
      });
      return await res.json();
    } catch (e) {
      console.error('[QZ] Could not save settings:', e);
      return { success: false, error: e.message };
    }
  }

  // ── Private helpers ────────────────────────────

  function _setStatus(status, message) {
    if (typeof _onStatusChange === 'function') {
      _onStatusChange(status, message);
    }
  }

  function _repeat(ch, n) { return ch.repeat(n); }

  function _padRight(str, len) {
    return str.length >= len ? str.substring(0, len) : str + ' '.repeat(len - str.length);
  }

  function _padBetween(left, right, width) {
    var gap = width - left.length - right.length;
    return left + (gap > 0 ? ' '.repeat(gap) : ' ') + right;
  }

  function _fmtNum(n) {
    return parseFloat(n || 0).toFixed(2);
  }

  function _fmtItemLine(name, qty, price, total, pw) {
    // Layout: Name  Qty  Price  Total
    var qtyW = 4;
    var priceW = 9;
    var totalW = 9;
    var nameW = pw - qtyW - priceW - totalW;

    var n = (name || '').substring(0, nameW).padEnd(nameW, ' ');
    var q = String(qty).padStart(qtyW, ' ');
    var p = String(price).padStart(priceW, ' ');
    var t = String(total).padStart(totalW, ' ');
    return n + q + p + t;
  }

  // ── Public Interface ───────────────────────────

  return {
    // Connection
    connect: connect,
    disconnect: disconnect,
    isConnected: isConnected,
    onStatusChange: onStatusChange,

    // Printer discovery
    findPrinters: findPrinters,
    getDefaultPrinter: getDefaultPrinter,

    // Printing
    printRaw: printRaw,
    printHtml: printHtml,
    printReceipt: printReceipt,
    printTestPage: printTestPage,

    // Receipt builder
    buildReceiptCommands: buildReceiptCommands,

    // Configuration
    setDefaultPrinter: setDefaultPrinter,
    getDefaultPrinterName: getDefaultPrinterName,
    setPaperWidth: setPaperWidth,
    getPaperWidth: getPaperWidth,
    setAutoPrint: setAutoPrint,
    getAutoPrint: getAutoPrint,
    loadSettings: loadSettings,
    saveSettings: saveSettings
  };
})();
