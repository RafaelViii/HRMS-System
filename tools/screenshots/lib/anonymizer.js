/**
 * Anonymizer — deterministic PII replacement engine.
 *
 * Runs in two modes:
 *   1. In-browser (via page.evaluate) — walks the live DOM, replaces text nodes
 *   2. Post-process (on raw HTML string) — regex-based fallback for attributes, titles, etc.
 *
 * Deterministic: same real value always maps to the same fake value (seeded).
 */

const FIRST_NAMES = [
  'James', 'Maria', 'Juan', 'Ana', 'Carlos', 'Sofia', 'Miguel', 'Isabella',
  'Rafael', 'Camila', 'Diego', 'Valentina', 'Gabriel', 'Lucia', 'Andres',
  'Elena', 'Fernando', 'Rosa', 'Antonio', 'Carmen', 'Pedro', 'Teresa',
  'Jorge', 'Patricia', 'Luis', 'Angela', 'Roberto', 'Gloria', 'Marco',
  'Diana', 'Eduardo', 'Monica', 'Oscar', 'Laura', 'Ricardo', 'Sandra',
  'Manuel', 'Claudia', 'Alejandro', 'Veronica', 'Daniel', 'Adriana',
  'Victor', 'Cristina', 'Ernesto', 'Beatriz', 'Arturo', 'Natalia',
  'Francisco', 'Silvia', 'Enrique', 'Viviana', 'Gustavo', 'Paloma',
  'Raul', 'Lorena', 'Alberto', 'Mariana', 'Sergio', 'Catalina',
];

const LAST_NAMES = [
  'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres',
  'Rivera', 'Flores', 'Gonzales', 'Ramos', 'Aquino', 'Castro', 'Morales',
  'Dela Cruz', 'Lopez', 'Hernandez', 'Perez', 'Diaz', 'Villanueva',
  'Fernandez', 'Martinez', 'Rodriguez', 'Salazar', 'Santiago', 'Navarro',
  'Castillo', 'Aguilar', 'Miranda', 'Pascual', 'Romero', 'Jimenez',
  'Vargas', 'Medina', 'Alvarez', 'Guerrero', 'Ortega', 'Fuentes',
  'Delgado', 'Cabrera', 'Vega', 'Campos', 'Molina', 'Rojas',
  'Herrera', 'Acosta', 'Valencia', 'Espinoza', 'Cortez', 'Leon',
];

class Anonymizer {
  constructor(seed = 42) {
    this.seed = seed;
    this.nameMap = new Map();       // realFullName → fakeFullName
    this.emailMap = new Map();      // realEmail → fakeEmail
    this.phoneMap = new Map();      // realPhone → fakePhone
    this.idMap = new Map();         // realID → fakeID
    this.nextId = 1;
    this._hashCounter = 0;
  }

  /**
   * Get the browser-injectable anonymization script.
   * This function returns JS code string to be injected via page.evaluate().
   */
  getBrowserScript(mappingsJSON) {
    // The script receives existing mappings and returns new ones found
    return `
    (function(existingMappings) {
      const mappings = JSON.parse(existingMappings);
      const nameMap = new Map(Object.entries(mappings.names || {}));
      const emailMap = new Map(Object.entries(mappings.emails || {}));
      const newMappings = { names: {}, emails: {}, phones: {}, ids: {} };

      const FIRST = ${JSON.stringify(FIRST_NAMES)};
      const LAST = ${JSON.stringify(LAST_NAMES)};
      let nameIdx = nameMap.size;

      function seededName(idx) {
        return FIRST[idx % FIRST.length] + ' ' + LAST[Math.floor(idx / FIRST.length) % LAST.length];
      }

      function getFakeName(real) {
        const key = real.trim();
        if (!key || key.length < 3) return real;
        if (nameMap.has(key)) return nameMap.get(key);
        const fake = seededName(nameIdx++);
        nameMap.set(key, fake);
        newMappings.names[key] = fake;
        return fake;
      }

      function getFakeEmail(real) {
        const key = real.trim().toLowerCase();
        if (emailMap.has(key)) return emailMap.get(key);
        const idx = emailMap.size;
        const first = FIRST[idx % FIRST.length].toLowerCase();
        const last = LAST[Math.floor(idx / FIRST.length) % LAST.length].toLowerCase().replace(/ /g, '');
        const fake = first + '.' + last + '@demo.hrms';
        emailMap.set(key, fake);
        newMappings.emails[key] = fake;
        return fake;
      }

      // --- Email regex ---
      const emailRe = /[a-zA-Z0-9._%+\\-]+@[a-zA-Z0-9.\\-]+\\.[a-zA-Z]{2,}/g;

      // --- Phone regex (PH formats) ---
      const phoneRe = /(?:\\+63|0)\\s*(?:\\d[\\s\\-]?){9,10}/g;

      // --- Currency regex ---
      const currencyRe = /(₱|PHP\\s*)([\\d,]+\\.?\\d{0,2})/g;

      // --- Employee ID patterns ---
      const empIdRe = /\\b(EMP|DEPT|POS|HR)[-\\s]?\\d{3,6}\\b/gi;

      // Common UI strings to skip (navigation, labels, buttons)
      const skipStrings = new Set([
        'dashboard', 'home', 'employees', 'payroll', 'attendance', 'leave',
        'overtime', 'departments', 'positions', 'memos', 'notifications',
        'inventory', 'admin', 'settings', 'logout', 'login', 'submit',
        'cancel', 'save', 'delete', 'edit', 'create', 'view', 'export',
        'import', 'search', 'filter', 'reset', 'close', 'confirm',
        'approved', 'pending', 'declined', 'cancelled', 'active', 'inactive',
        'name', 'email', 'phone', 'address', 'department', 'position',
        'status', 'date', 'action', 'actions', 'total', 'loading',
        'no data', 'showing', 'page', 'of', 'previous', 'next',
        'all', 'select', 'none', 'yes', 'no', 'ok', 'back',
        'management', 'administration', 'system', 'account', 'profile',
      ]);

      function isUIText(text) {
        const lower = text.trim().toLowerCase();
        if (lower.length < 2 || lower.length > 100) return true;
        if (skipStrings.has(lower)) return true;
        // Pure numbers, dates, single words that look like labels
        if (/^\\d+$/.test(lower)) return true;
        if (/^[a-z]{1,15}$/.test(lower)) return true;
        return false;
      }

      function anonymizeText(text) {
        let result = text;

        // Replace emails
        result = result.replace(emailRe, (match) => getFakeEmail(match));

        // Replace phone numbers
        result = result.replace(phoneRe, (match) => {
          const digits = match.replace(/\\D/g, '').slice(-10);
          newMappings.phones[match.trim()] = '(0917) 555-' + digits.slice(-4);
          return '(0917) 555-' + digits.slice(-4);
        });

        // Replace currency amounts (randomize ±20%)
        result = result.replace(currencyRe, (match, prefix, amount) => {
          const num = parseFloat(amount.replace(/,/g, ''));
          if (isNaN(num) || num === 0) return match;
          const factor = 0.8 + (Math.abs(hashCode(amount)) % 40) / 100;
          const faked = (num * factor).toFixed(2);
          const formatted = parseFloat(faked).toLocaleString('en-PH', { minimumFractionDigits: 2 });
          return prefix + formatted;
        });

        // Replace employee IDs
        result = result.replace(empIdRe, (match) => {
          const prefix = match.replace(/[\\d-]/g, '').trim() || 'EMP';
          newMappings.ids[match] = prefix + '-D' + String(Object.keys(newMappings.ids).length + 1).padStart(3, '0');
          return newMappings.ids[match];
        });

        return result;
      }

      function hashCode(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
          const ch = str.charCodeAt(i);
          hash = ((hash << 5) - hash) + ch;
          hash |= 0;
        }
        return hash;
      }

      // Walk all text nodes in the body
      function walkAndAnonymize(node) {
        if (!node) return;

        // Skip script, style, svg, canvas elements
        const tag = node.nodeName.toLowerCase();
        if (['script', 'style', 'svg', 'canvas', 'noscript', 'link', 'meta'].includes(tag)) return;

        // Skip navigation, sidebar, footer structural elements
        if (node.id === 'sidebar' || node.id === 'mnav') {
          // Only anonymize text that looks like PII in nav (e.g., user name in dropdown)
          anonymizeNavElement(node);
          return;
        }

        if (node.nodeType === Node.TEXT_NODE) {
          const original = node.textContent;
          if (!original || !original.trim()) return;

          const anonymized = anonymizeText(original);
          if (anonymized !== original) {
            node.textContent = anonymized;
          }
          return;
        }

        // For element nodes, also check certain attributes
        if (node.nodeType === Node.ELEMENT_NODE) {
          ['title', 'alt', 'placeholder', 'aria-label', 'value'].forEach(attr => {
            const val = node.getAttribute(attr);
            if (val) {
              const anon = anonymizeText(val);
              if (anon !== val) node.setAttribute(attr, anon);
            }
          });
        }

        // Recurse into children
        for (const child of Array.from(node.childNodes)) {
          walkAndAnonymize(child);
        }
      }

      function anonymizeNavElement(nav) {
        // In navigation, only anonymize the user dropdown name and email
        const userInfo = nav.querySelectorAll('[class*="user"], [class*="avatar"], [class*="dropdown"]');
        userInfo.forEach(el => {
          const texts = el.querySelectorAll('span, p, div');
          texts.forEach(t => {
            if (emailRe.test(t.textContent)) {
              t.textContent = anonymizeText(t.textContent);
            }
          });
        });
      }

      // Run anonymization on page body
      const mainContent = document.getElementById('appMain');
      if (mainContent) {
        walkAndAnonymize(mainContent);
      }

      // Also anonymize top bar user info
      const topBar = document.querySelector('.top-bar');
      if (topBar) walkAndAnonymize(topBar);

      // Anonymize notification dropdown content
      const notifDrop = document.querySelector('[id*="notif"], [class*="notif"]');
      if (notifDrop) walkAndAnonymize(notifDrop);

      // User dropdown in header
      document.querySelectorAll('.dropdown-menu').forEach(dd => walkAndAnonymize(dd));

      return JSON.stringify(newMappings);
    })('${mappingsJSON.replace(/'/g, "\\'")}')
    `;
  }

  /**
   * Run anonymization on a Puppeteer page. Updates internal mappings.
   * @param {import('puppeteer').Page} page
   */
  async anonymizePage(page) {
    const mappingsJSON = JSON.stringify({
      names: Object.fromEntries(this.nameMap),
      emails: Object.fromEntries(this.emailMap),
    });

    const script = this.getBrowserScript(mappingsJSON);
    const newMappingsJSON = await page.evaluate(script);

    // Merge new mappings
    try {
      const newMappings = JSON.parse(newMappingsJSON);
      if (newMappings.names) {
        for (const [k, v] of Object.entries(newMappings.names)) {
          this.nameMap.set(k, v);
        }
      }
      if (newMappings.emails) {
        for (const [k, v] of Object.entries(newMappings.emails)) {
          this.emailMap.set(k, v);
        }
      }
      if (newMappings.phones) {
        for (const [k, v] of Object.entries(newMappings.phones)) {
          this.phoneMap.set(k, v);
        }
      }
      if (newMappings.ids) {
        for (const [k, v] of Object.entries(newMappings.ids)) {
          this.idMap.set(k, v);
        }
      }
    } catch {
      // Anonymization might return non-JSON on error pages
    }
  }

  /** Export state for resume capability. */
  exportState() {
    return {
      nameMap: Object.fromEntries(this.nameMap),
      emailMap: Object.fromEntries(this.emailMap),
      phoneMap: Object.fromEntries(this.phoneMap),
      idMap: Object.fromEntries(this.idMap),
      nextId: this.nextId,
    };
  }

  /** Import state from a previous run. */
  importState(state) {
    if (!state) return;
    if (state.nameMap) this.nameMap = new Map(Object.entries(state.nameMap));
    if (state.emailMap) this.emailMap = new Map(Object.entries(state.emailMap));
    if (state.phoneMap) this.phoneMap = new Map(Object.entries(state.phoneMap));
    if (state.idMap) this.idMap = new Map(Object.entries(state.idMap));
    if (state.nextId) this.nextId = state.nextId;
  }
}

module.exports = Anonymizer;
