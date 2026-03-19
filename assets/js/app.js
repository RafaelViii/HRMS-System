// HRMS Global JavaScript - Version 2025-02-09-v1
if (window.__DEBUG_APP) console.log('app.js loaded - version 2025-02-09-v1');

function openModal(id){ document.getElementById(id)?.classList.remove('hidden'); }
function closeModal(id){ document.getElementById(id)?.classList.add('hidden'); }

// Simple HTML escaper used by history modals
function escapeHtml(str){
	const map = { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' };
	return (str == null ? '' : String(str)).replace(/[&<>"']/g, c => map[c] || c);
}

// Global and content loaders
const appLoader = document.getElementById('appLoader');
const contentLoader = document.getElementById('contentLoader');
function showLoader(){ appLoader && appLoader.classList.remove('hidden'); }
function hideLoader(){ appLoader && appLoader.classList.add('hidden'); }
function showContentLoader(){ contentLoader && contentLoader.classList.remove('hidden'); }
function hideContentLoader(){ contentLoader && contentLoader.classList.add('hidden'); }

// Minimal SPA: intercept navigation for links with .spa and replace main content
const appMain = document.getElementById('appMain');
async function navigateSpa(url) {
	if (!appMain) { window.location.href = url; return; }
	let absUrl = url; // Initialize with original URL for error fallback
	try {
		showContentLoader();
		// Resolve relative links against the current page URL so module pages work correctly
		absUrl = new URL(url, window.location.href).toString();
		const res = await fetch(absUrl, { headers: { 'X-Requested-With': 'fetch' } });
		if (!res.ok) {
			// Server returned error status, fallback to regular navigation
			console.warn('SPA fetch failed with status:', res.status);
			window.location.href = absUrl;
			return;
		}
		const html = await res.text();
		const doc = new DOMParser().parseFromString(html, 'text/html');
		const newMain = doc.querySelector('#appMain');
		if (newMain) {
			appMain.innerHTML = newMain.innerHTML;
			// Execute inline scripts that innerHTML doesn't run automatically
			appMain.querySelectorAll('script').forEach(oldScript => {
				const newScript = document.createElement('script');
				if (oldScript.src) {
					newScript.src = oldScript.src;
				} else {
					newScript.textContent = oldScript.textContent;
				}
				// Copy attributes (type, defer, etc.)
				Array.from(oldScript.attributes).forEach(attr => {
					if (attr.name !== 'src') newScript.setAttribute(attr.name, attr.value);
				});
				oldScript.parentNode.replaceChild(newScript, oldScript);
			});
			window.history.pushState({}, '', absUrl);
			hideContentLoader();
			// Close mobile menu if open
			document.getElementById('mnav')?.classList.add('hidden');
			// Signal page-specific initializers
			document.dispatchEvent(new CustomEvent('spa:loaded', { detail: { url: absUrl } }));
		} else {
			// Fallback if selector not found
			console.warn('SPA navigation failed: #appMain not found in response');
			window.location.href = absUrl;
		}
	} catch (e) {
		console.error('SPA navigation error:', e);
		window.location.href = absUrl;
	} finally {
		hideContentLoader();
	}
}

document.addEventListener('click', (e) => {
	const a = e.target.closest('a.spa');
	if (a && a.getAttribute('href') && !a.getAttribute('target')) {
		const href = a.getAttribute('href');
		// Prevent leaving if form dirty
		if (window.__formDirty && !confirm('You have unsaved changes. Leave this page?')) { e.preventDefault(); return; }
		// Only SPA internal links
		if (href.startsWith(window.location.origin) || href.startsWith('/') || !href.match(/^https?:/i)) {
			e.preventDefault();
			navigateSpa(href);
		}
	}
});

// Clickable cards with data-card-link redirect
(function(){
	function go(card, href){
		if (!href) return;
		if (typeof navigateSpa === 'function' && card?.dataset.cardSpa === '1') {
			navigateSpa(href);
		} else {
			window.location.href = href;
		}
	}
	document.addEventListener('click', (ev) => {
		if (ev.target.closest('[data-card-link-stop]')) return;
		const card = ev.target.closest('[data-card-link]');
		if (!card) return;
		const interactive = ev.target.closest('a,button,input,textarea,select,label');
		if (interactive && interactive !== card) return;
		const href = card.getAttribute('data-card-link');
		if (!href) return;
		ev.preventDefault();
		go(card, href);
	});
	document.addEventListener('keydown', (ev) => {
		if (ev.key !== 'Enter' && ev.key !== ' ') return;
		const card = ev.target.closest('[data-card-link]');
		if (!card) return;
		if (ev.target.matches('input,textarea,select')) return;
		const href = card.getAttribute('data-card-link');
		if (!href) return;
		ev.preventDefault();
		go(card, href);
	});
})();

// Show global loader on full page unload only
window.addEventListener('beforeunload', (e) => {
	// Skip showing loader for explicit no-loader elements (e.g., CSV downloads)
	const ae = document.activeElement;
	if (ae && (ae.hasAttribute?.('data-no-loader') || ae.getAttribute?.('target') === '_blank')) {
		return; // don't show global loader
	}
	showLoader();
});

// Custom confirmation modal for elements/forms with data-confirm
(function(){
	const modal = document.getElementById('confirmModal');
	const msgEl = document.getElementById('confirmMessage');
	const btnYes = document.getElementById('confirmYes');
	if (!modal || !msgEl || !btnYes) return;
	let pendingAction = null;
	function openConfirm(message, action){
		msgEl.textContent = message || 'Are you sure?';
		pendingAction = action;
		modal.classList.remove('hidden');
	}
	function closeConfirm(){ modal.classList.add('hidden'); pendingAction = null; }
	modal.addEventListener('click', (e) => { if (e.target.closest('[data-confirm-close]')) closeConfirm(); });
	btnYes.addEventListener('click', () => {
		const act = pendingAction; closeConfirm();
		if (typeof act === 'function') try { act(); } catch(e) {}
	});
	// Intercept button/link clicks
	document.addEventListener('click', (e) => {
		const el = e.target.closest('[data-confirm]');
		if (!el) return;
		const isActionEl = el.tagName === 'A' || el.tagName === 'BUTTON' || el.type === 'submit';
		if (!isActionEl) return;
		const message = el.getAttribute('data-confirm') || 'Are you sure?';
		// If it's inside a form and is submit, the submit listener will handle; skip here
		if (el.closest('form') && (el.type === 'submit')) return;
		e.preventDefault(); e.stopPropagation();
		if (el.tagName === 'A') {
			const href = el.getAttribute('href');
			openConfirm(message, () => {
				if (href) {
					if (el.classList.contains('spa')) navigateSpa(href); else window.location.href = href;
				}
			});
		} else if (el.tagName === 'BUTTON') {
			openConfirm(message, () => {
				// If inside a form, submit the form
				const f = el.closest('form');
				if (f) f.submit();
			});
		}
	});
	// Intercept form submits (custom confirm modal). After confirm we dispatch a synthetic submit so auth layer can intercept.
	document.addEventListener('submit', (e) => {
		const form = e.target.closest('form');
		if (!form) return;
		if (window.__DEBUG_AUTHZ) console.log('[confirm] intercept', form, 'confirmed?', form.dataset.confirmed);
		if (form.dataset.confirmed === '1') {
			return; // already confirmed; allow auth layer or native submission
		}
		const message = form.getAttribute('data-confirm');
		if (!message) return;
		e.preventDefault();
		openConfirm(message, () => {
			form.dataset.confirmed = '1';
			const ev = new Event('submit', { cancelable: true, bubbles: true });
			const proceed = form.dispatchEvent(ev);
			if (window.__DEBUG_AUTHZ) console.log('[confirm] synthetic submit dispatched; prevented?', !proceed);
			if (proceed) {
				if (window.__DEBUG_AUTHZ) console.log('[confirm] native submit now (no auth intercept)');
				form.submit();
			}
		});
	});
})();

// ===== Generic dropdown toggles (Export menus, etc.) =====
(function(){
	let _docListenersBound = false;
	function init(scope=document){
		// Toggle on click for elements with [data-dd-toggle]
		scope.querySelectorAll('[data-dd-toggle]').forEach(btn => {
			if (btn.dataset.ddBound) return; btn.dataset.ddBound = '1';
			const menuId = btn.getAttribute('data-dd-toggle');
			const menu = menuId ? document.getElementById(menuId) : btn.nextElementSibling;
			btn.addEventListener('click', (e) => {
				e.preventDefault(); e.stopPropagation();
				if (!menu) return;
				// Close other open menus first
				document.querySelectorAll('.dropdown-menu').forEach(m => { if (m !== menu) m.classList.add('hidden'); });
				menu.classList.toggle('hidden');
			});
		});
		// Close menus on outside click or Escape (bind only once)
		if (!_docListenersBound) {
			_docListenersBound = true;
			document.addEventListener('click', (e) => {
				if (e.target.closest('[data-dd-toggle]') || e.target.closest('.dropdown-menu')) return;
				document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.add('hidden'));
			});
			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.add('hidden'));
			});
		}
	}
	document.addEventListener('DOMContentLoaded', () => init(document));
	document.addEventListener('spa:loaded', () => init(document));
})();

	// ===== Memo attachment preview modal =====
	(function(){
		function init(scope=document){
			const modal = document.getElementById('memoPreviewModal');
			if (!modal) return;
			const image = modal.querySelector('[data-preview-image]');
			const frame = modal.querySelector('[data-preview-frame]');
			const title = modal.querySelector('[data-preview-title]');
			const subtitle = modal.querySelector('[data-preview-subtitle]');
			const message = modal.querySelector('[data-preview-message]');
			const downloadLink = modal.querySelector('[data-preview-download]');
			const disabledTag = modal.querySelector('[data-preview-disabled]');
			const closeEls = modal.querySelectorAll('[data-memo-preview-close]');
			const openModal = () => modal.classList.remove('hidden');
			const closeModal = () => {
				modal.classList.add('hidden');
				if (frame) frame.src = 'about:blank';
				if (image) image.src = '';
			};
			closeEls.forEach(btn => btn.addEventListener('click', closeModal));
			if (!modal.__memoBound) {
				document.addEventListener('keydown', (ev) => {
					if (ev.key === 'Escape' && !modal.classList.contains('hidden')) {
						closeModal();
					}
				});
				modal.__memoBound = true;
			}
			scope.querySelectorAll('[data-memo-preview]').forEach(btn => {
				if (btn.dataset.memoPreviewBound) return;
				btn.dataset.memoPreviewBound = '1';
				btn.addEventListener('click', () => {
					const src = btn.getAttribute('data-src');
					if (!src) {
						console.error('[Memo Preview] Missing data-src attribute');
						return;
					}
					if (window.__DEBUG_MEMO) console.log('[Memo Preview] Opening modal with src:', src);
					
					const name = btn.getAttribute('data-name') || 'Attachment';
					const type = (btn.getAttribute('data-type') || '').toLowerCase();
					const mime = btn.getAttribute('data-mime') || '';
					const allowDownload = btn.getAttribute('data-download-allowed') === '1';
					const downloadUrl = btn.getAttribute('data-download-url') || '#';
					
					if (title) title.textContent = name;
					if (subtitle) subtitle.textContent = type === 'image' ? 'Image preview' : 'Document preview';
					if (message) message.textContent = mime ? 'File type: ' + mime : '';
					
					if (image && frame) {
						if (type === 'image') {
							if (window.__DEBUG_MEMO) console.log('[Memo Preview] Loading image:', src);
							image.onload = function() {
								if (window.__DEBUG_MEMO) console.log('[Memo Preview] Image loaded successfully');
							};
							image.onerror = function(e) {
								if (window.__DEBUG_MEMO) console.error('[Memo Preview] Image failed to load:', src, e);
							};
							image.src = src;
							image.classList.remove('hidden');
							frame.classList.add('hidden');
						} else {
							if (window.__DEBUG_MEMO) console.log('[Memo Preview] Loading document iframe:', src);
							frame.src = src + (src.includes('?') ? '&' : '?') + 'preview=1';
							frame.classList.remove('hidden');
							image.classList.add('hidden');
						}
					}
					
					if (downloadLink && disabledTag) {
						if (allowDownload) {
							downloadLink.href = downloadUrl;
							downloadLink.classList.remove('hidden');
							disabledTag.classList.add('hidden');
						} else {
							downloadLink.classList.add('hidden');
							disabledTag.classList.remove('hidden');
						}
					}
					openModal();
				});
				btn.addEventListener('keydown', (ev) => {
					if (ev.key === 'Enter' || ev.key === ' ') {
						ev.preventDefault();
						btn.click();
					}
				});
			});
		}
			document.addEventListener('DOMContentLoaded', () => init(document));
			document.addEventListener('spa:loaded', () => init(document));
	})();

	// ===== Memo audience mention UI =====
	(function(){
		const SUGGESTION_LIMIT = 12;

		function parsePayload(node){
			if (!node) return null;
			try {
				const text = node.textContent || node.innerText || '';
				return text ? JSON.parse(text) : null;
			} catch (err) {
				if (window.__DEBUG_MEMO) console.warn('[memo:audience] failed to parse payload', err);
				return null;
			}
		}

		function normalizeItem(raw){
			if (!raw) return null;
			const type = (raw.type || '').toLowerCase();
			const identifier = raw.identifier == null ? '' : String(raw.identifier);
			const label = raw.label == null ? '' : String(raw.label);
			if (!type || !identifier || !label) {
				return null;
			}
			const tag = raw.tag == null ? '' : String(raw.tag);
			const group = raw.group == null ? '' : String(raw.group);
			const searchSource = raw.search == null ? (label + ' ' + tag) : String(raw.search);
			return {
				type,
				identifier,
				label,
				tag,
				group,
				source: raw,
				search: searchSource.toLowerCase(),
				meta: raw.meta && typeof raw.meta === 'object' ? raw.meta : {},
			};
		}

		function chipLabel(item){
			if (!item) return '';
			if (item.type === 'department') return 'Dept: ' + item.label;
			if (item.type === 'role') return 'Role: ' + item.label;
			if (item.type === 'employee') {
				var code = item.meta && item.meta.code ? String(item.meta.code) : '';
				return code ? item.label + ' (' + code + ')' : item.label;
			}
			return item.label;
		}

		function ensureJson(value){
			try {
				return JSON.stringify(value);
			} catch (err) {
				return '[]';
			}
		}

		function setupConfirmModal(){
			const modal = document.querySelector('[data-memo-confirm-modal]');
			if (!modal) return null;
			if (modal.__memoConfirmApi) return modal.__memoConfirmApi;
			const summaryNode = modal.querySelector('[data-memo-confirm-summary]');
			const emptyNode = modal.querySelector('[data-memo-confirm-empty]');
			const btnYes = modal.querySelector('[data-memo-confirm-yes]');
			const cancelButtons = modal.querySelectorAll('[data-memo-confirm-cancel]');
			let onConfirm = null;

			const close = () => {
				modal.classList.add('hidden');
				onConfirm = null;
			};

			cancelButtons.forEach(btn => btn.addEventListener('click', () => close()));
			modal.addEventListener('click', (evt) => {
				if (evt.target === modal) close();
			});
			document.addEventListener('keydown', (evt) => {
				if (evt.key === 'Escape' && !modal.classList.contains('hidden')) close();
			});

			btnYes?.addEventListener('click', () => {
				if (btnYes.disabled) return;
				const handler = onConfirm;
				close();
				if (typeof handler === 'function') handler();
			});

			function renderSummary(rows){
				if (!summaryNode) return;
				summaryNode.innerHTML = '';
				const groups = {
					all: [],
					department: [],
					role: [],
					employee: [],
				};
				rows.forEach(row => {
					if (!row || !row.type) return;
					if (!groups[row.type]) groups[row.type] = [];
					groups[row.type].push(row);
				});
				const frag = document.createDocumentFragment();
				const addGroup = (title, items, accent) => {
					if (!items || !items.length) return;
					const section = document.createElement('div');
					section.className = 'rounded-2xl border border-slate-200 px-4 py-3';
					const heading = document.createElement('p');
					heading.className = 'text-xs font-semibold uppercase tracking-wide ' + (accent || 'text-slate-500');
					heading.textContent = title;
					section.appendChild(heading);
					const list = document.createElement('div');
					list.className = 'mt-2 flex flex-wrap gap-2';
					items.forEach(item => {
						const tag = document.createElement('span');
						tag.className = 'inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700';
						tag.textContent = chipLabel(item);
						list.appendChild(tag);
					});
					section.appendChild(list);
					frag.appendChild(section);
				};
				addGroup('Everyone', groups.all, 'text-emerald-600');
				addGroup('Departments', groups.department, 'text-sky-600');
				addGroup('Roles', groups.role, 'text-indigo-600');
				addGroup('Individuals', groups.employee, 'text-rose-600');
				summaryNode.appendChild(frag);
				if (emptyNode) emptyNode.classList.toggle('hidden', rows.length > 0);
			}

			modal.__memoConfirmApi = {
				open(rows, options){
					options = options || {};
					renderSummary(rows || []);
					if (btnYes) {
						btnYes.disabled = options.allowSubmit === false;
					}
					onConfirm = options.onConfirm || null;
					modal.classList.remove('hidden');
				},
				close,
			};
			return modal.__memoConfirmApi;
		}

		const confirmApi = setupConfirmModal();

		function init(scope){
			(scope || document).querySelectorAll('form[data-memo-recipient-form]').forEach(form => {
				if (form.__memoAudienceBound) return;
				const input = form.querySelector('[data-audience-input]');
				const chips = form.querySelector('[data-audience-chips]');
				const suggestions = form.querySelector('[data-audience-suggestions]');
				const hiddenAll = form.querySelector('[data-audience-all]');
				const hiddenDepartments = form.querySelector('[data-audience-departments]');
				const hiddenRoles = form.querySelector('[data-audience-roles]');
				const hiddenEmployees = form.querySelector('[data-audience-employees]');
				const hiddenSerialized = form.querySelector('[data-audience-serialized]');
				const clearBtn = form.querySelector('[data-audience-clear]');
				const payloadNode = form.querySelector('script[data-memo-audience]');
				if (!input || !chips || !suggestions || !hiddenAll || !hiddenDepartments || !hiddenRoles || !hiddenEmployees || !hiddenSerialized || !payloadNode) {
					return;
				}

				const payload = parsePayload(payloadNode) || {};
				const options = payload.options || {};
				const stateRaw = payload.state || {};
				const config = payload.config || {};
				const remoteConfig = {
					endpoint: form.getAttribute('data-audience-endpoint') || config.endpoint || '',
					minTermLength: Number.isFinite(config.minTermLength) ? config.minTermLength : parseInt(config.minTermLength, 10),
					debounceMs: Number.isFinite(config.debounceMs) ? config.debounceMs : parseInt(config.debounceMs, 10),
				};
				if (!Number.isFinite(remoteConfig.minTermLength) || remoteConfig.minTermLength < 0) remoteConfig.minTermLength = 2;
				if (!Number.isFinite(remoteConfig.debounceMs) || remoteConfig.debounceMs < 0) remoteConfig.debounceMs = 250;

				const map = {
					all: new Map(),
					department: new Map(),
					role: new Map(),
					employee: new Map(),
				};
				const shortcuts = [];
				const departments = [];
				const roles = [];
				const employees = [];

				const storeItem = (item) => {
					if (!item) return;
					if (item.type === 'all') {
						if (!map.all.has(item.identifier)) {
							shortcuts.push(item);
						}
						map.all.set(item.identifier, item);
						return;
					}
					if (item.type === 'department') {
						if (!map.department.has(item.identifier)) {
							departments.push(item);
						}
						map.department.set(item.identifier, item);
						return;
					}
					if (item.type === 'role') {
						if (!map.role.has(item.identifier)) {
							roles.push(item);
						}
						map.role.set(item.identifier, item);
						return;
					}
					if (item.type === 'employee') {
						if (!map.employee.has(item.identifier)) {
							employees.push(item);
						}
						map.employee.set(item.identifier, item);
						return;
					}
				};

				(options.shortcuts || []).forEach(item => storeItem(normalizeItem(item)));
				(options.departments || []).forEach(item => storeItem(normalizeItem(item)));
				(options.roles || []).forEach(item => storeItem(normalizeItem(item)));
				(options.employees || []).forEach(item => storeItem(normalizeItem(item)));

				const allFallback = map.all.get('all') || normalizeItem({ type: 'all', identifier: 'all', label: 'All employees', tag: '@all', group: 'Shortcuts', search: 'all employees everyone whole company' });
				if (allFallback && !map.all.has(allFallback.identifier)) {
					storeItem(allFallback);
				}

				const state = {
					all: !!stateRaw.all,
					departments: new Set(Array.isArray(stateRaw.departments) ? stateRaw.departments.map(String) : []),
					roles: new Set(Array.isArray(stateRaw.roles) ? stateRaw.roles.map(String) : []),
					employees: new Set(Array.isArray(stateRaw.employees) ? stateRaw.employees.map(String) : []),
				};

				let currentSuggestions = [];
				let highlightedIndex = -1;
				let queryCounter = 0;
				let activeQueryToken = 0;
				const remoteState = { timer: null, controller: null, lastSignature: '', activeSignature: '' };

				const markDirty = () => { window.__formDirty = true; };

				const collectAudienceRows = () => {
					const rows = [];
					if (state.all) {
						rows.push(map.all.get('all') || allFallback);
					}
					state.departments.forEach(id => { const item = map.department.get(id); if (item) rows.push(item); });
					state.roles.forEach(id => { const item = map.role.get(id); if (item) rows.push(item); });
					state.employees.forEach(id => { const item = map.employee.get(id); if (item) rows.push(item); });
					return rows.map(item => ({
						type: item.type,
						identifier: item.identifier,
						label: item.label,
						meta: item.meta || {},
					}));
				};

				const syncHidden = () => {
					hiddenAll.value = state.all ? '1' : '0';
					hiddenDepartments.value = ensureJson(Array.from(state.departments));
					hiddenRoles.value = ensureJson(Array.from(state.roles));
					hiddenEmployees.value = ensureJson(Array.from(state.employees));
					hiddenSerialized.value = ensureJson(collectAudienceRows());
				};

				const renderChips = () => {
					chips.innerHTML = '';
					const frag = document.createDocumentFragment();
					const addChip = (item) => {
						if (!item) return;
						const wrapper = document.createElement('span');
						wrapper.className = 'inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700';
						wrapper.setAttribute('data-audience-chip', '');
						wrapper.setAttribute('data-audience-type', item.type);
						wrapper.setAttribute('data-audience-id', item.identifier);
						const label = document.createElement('span');
						label.textContent = chipLabel(item);
						const btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-emerald-600 text-[11px] leading-none text-white hover:bg-emerald-500';
						btn.setAttribute('data-remove-type', item.type);
						btn.setAttribute('data-remove-id', item.identifier);
						btn.setAttribute('aria-label', 'Remove recipient');
						btn.innerHTML = '&times;';
						wrapper.appendChild(label);
						wrapper.appendChild(btn);
						frag.appendChild(wrapper);
					};
					if (state.all) addChip(map.all.get('all') || allFallback);
					state.departments.forEach(id => addChip(map.department.get(id)));
					state.roles.forEach(id => addChip(map.role.get(id)));
					state.employees.forEach(id => addChip(map.employee.get(id)));
					if (!frag.childNodes.length) {
						const placeholder = document.createElement('span');
						placeholder.className = 'text-xs text-slate-400';
						placeholder.textContent = 'No recipients selected yet.';
						chips.appendChild(placeholder);
						return;
					}
					chips.appendChild(frag);
				};

				const setSuggestionsMessage = (text, options) => {
					suggestions.innerHTML = '';
					suggestions.classList.remove('hidden');
					const msg = document.createElement('div');
					msg.className = 'px-4 py-3 text-sm ' + (options && options.loading ? 'text-slate-500' : 'text-slate-500');
					msg.textContent = text;
					suggestions.appendChild(msg);
					highlightedIndex = -1;
					currentSuggestions = [];
				};

				const hideSuggestions = () => {
					suggestions.classList.add('hidden');
					suggestions.innerHTML = '';
					highlightedIndex = -1;
					currentSuggestions = [];
				};

				const updateHighlight = () => {
					const buttons = suggestions.querySelectorAll('[data-suggestion-index]');
					buttons.forEach(btn => btn.classList.remove('bg-emerald-50'));
					if (highlightedIndex >= 0 && buttons[highlightedIndex]) {
						buttons[highlightedIndex].classList.add('bg-emerald-50');
					}
				};

				const renderSuggestions = (items) => {
					suggestions.innerHTML = '';
					if (!items.length) {
						hideSuggestions();
						return;
					}
					const frag = document.createDocumentFragment();
					let currentGroup = '';
					items.forEach((item, idx) => {
						if (item.group && item.group !== currentGroup) {
							currentGroup = item.group;
							const header = document.createElement('div');
							header.className = 'px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400';
							header.textContent = currentGroup;
							frag.appendChild(header);
						}
						const btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'w-full text-left px-4 py-3 text-sm text-slate-700 hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none';
						btn.setAttribute('data-suggestion-index', String(idx));
						btn.innerHTML = '<span class="font-medium text-slate-900">' + escapeHtml(item.label) + '</span>' +
							(item.tag ? '<span class="block text-xs text-slate-500">' + escapeHtml(item.tag) + '</span>' : '');
						frag.appendChild(btn);
					});
					suggestions.appendChild(frag);
					suggestions.classList.remove('hidden');
					highlightedIndex = 0;
					updateHighlight();
				};

				const parseContext = (raw) => {
					let typeFilter = '';
					let term = '';
					if (raw.startsWith('@')) {
						const after = raw.slice(1);
						const parts = after.split(/\s+/).filter(Boolean);
						const prefix = (parts.shift() || '').toLowerCase();
						if (prefix === 'dept' || prefix === 'department') typeFilter = 'department';
						else if (prefix === 'role') typeFilter = 'role';
						else if (prefix === 'emp' || prefix === 'employee' || prefix === 'user') typeFilter = 'employee';
						else if (prefix === 'all') typeFilter = 'all';
						term = parts.join(' ');
					} else {
						term = raw;
					}
					return {
						raw,
						rawLower: raw.toLowerCase(),
						typeFilter,
						term,
						termLower: term.toLowerCase(),
					};
				};

				const gatherDefaults = () => {
					const defaults = [];
					if (!state.all && shortcuts.length) defaults.push(shortcuts[0]);
					defaults.push.apply(defaults, departments.slice(0, 3));
					defaults.push.apply(defaults, roles.slice(0, 3));
					return defaults;
				};

				const gatherMatches = (context, opts) => {
					opts = opts || {};
					const results = [];
					const seen = new Set();
					const typeFilter = context.typeFilter;
					const termLower = context.termLower;
					const includeEmployees = opts.skipEmployees ? false : true;
					const consider = [];
					consider.push.apply(consider, shortcuts);
					consider.push.apply(consider, departments);
					consider.push.apply(consider, roles);
					if (includeEmployees) consider.push.apply(consider, employees);
					for (let i = 0; i < consider.length && results.length < SUGGESTION_LIMIT; i++) {
						const item = consider[i];
						if (!item || seen.has(item.type + ':' + item.identifier)) continue;
						seen.add(item.type + ':' + item.identifier);
						if (item.type === 'all' && state.all) continue;
						if (item.type === 'department' && state.departments.has(item.identifier)) continue;
						if (item.type === 'role' && state.roles.has(item.identifier)) continue;
						if (item.type === 'employee' && state.employees.has(item.identifier)) continue;
						if (typeFilter && item.type !== typeFilter) continue;
						if (item.type === 'employee') {
							if (!includeEmployees) continue;
							if (termLower && item.search.indexOf(termLower) === -1) continue;
							if (!termLower && typeFilter !== 'employee') continue;
						} else if (termLower && item.search.indexOf(termLower) === -1) {
							continue;
						}
						results.push(item);
					}
					return results;
				};

				const evaluateRemote = (context) => {
					if (!remoteConfig.endpoint) return { action: 'none' };
					if (context.typeFilter && context.typeFilter !== 'employee') return { action: 'none' };
					if (!context.termLower) return { action: 'none' };
					if (context.termLower.length < remoteConfig.minTermLength) {
						return { action: 'too-short', message: 'Type at least ' + remoteConfig.minTermLength + ' characters to search employees.' };
					}
					return { action: 'fetch' };
				};

				const clearRemoteTimer = () => {
					if (remoteState.timer) {
						clearTimeout(remoteState.timer);
						remoteState.timer = null;
					}
				};

				const abortRemote = () => {
					if (remoteState.controller) {
						remoteState.controller.abort();
						remoteState.controller = null;
					}
					remoteState.activeSignature = '';
				};

				const clearRemoteRequests = () => {
					clearRemoteTimer();
					abortRemote();
				};

				const executeRemoteFetch = (context, token, signature, hasBase) => {
					abortRemote();
					remoteState.activeSignature = signature;
					const controller = new AbortController();
					remoteState.controller = controller;
					try {
						const url = new URL(remoteConfig.endpoint, window.location.href);
						const params = new URLSearchParams();
						params.set('type', context.typeFilter || 'employee');
						params.set('q', context.term);
						params.set('limit', String(SUGGESTION_LIMIT));
						params.set('min_term', String(remoteConfig.minTermLength));
						state.departments.forEach(id => params.append('exclude[department][]', id));
						state.roles.forEach(id => params.append('exclude[role][]', id));
						state.employees.forEach(id => params.append('exclude[employee][]', id));
						const fetchUrl = url.toString() + (url.search ? '&' : '?') + params.toString();
						fetch(fetchUrl, { signal: controller.signal, headers: { 'Accept': 'application/json' } }).then(res => {
							if (!res.ok) throw new Error('HTTPS ' + res.status);
							return res.json();
						}).then(data => {
							if (controller.signal.aborted || token !== activeQueryToken || remoteState.activeSignature !== signature) return;
							if (!data || data.success === false) {
								if (!hasBase) setSuggestionsMessage(data && data.error ? data.error : 'No matches found.', {});
								return;
							}
							if (data.meta && data.meta.tooShort && !hasBase) {
								setSuggestionsMessage('Type at least ' + remoteConfig.minTermLength + ' characters to search employees.', {});
								return;
							}
							const resultItems = [];
							(data.results || []).forEach(item => {
								const normalized = normalizeItem(item);
								if (!normalized) return;
								storeItem(normalized);
								if (!context.typeFilter || context.typeFilter === normalized.type || normalized.type === 'employee') {
									resultItems.push(normalized);
								}
							});
							const freshContext = parseContext(input.value.trim());
							const base = gatherMatches(freshContext, { skipEmployees: true });
							const combined = freshContext.typeFilter === 'employee' ? resultItems : base.concat(resultItems);
							if (combined.length) {
								currentSuggestions = combined.slice(0, SUGGESTION_LIMIT);
								renderSuggestions(currentSuggestions);
							} else if (!hasBase) {
								setSuggestionsMessage('No matches found.', {});
							}
						}).catch(err => {
							if (controller.signal.aborted) return;
							if (window.__DEBUG_MEMO) console.warn('[memo:audience] remote lookup failed', err);
							if (!hasBase) setSuggestionsMessage('Unable to search right now. Try again later.', {});
						}).finally(() => {
							remoteState.controller = null;
							remoteState.activeSignature = '';
						});
					} catch (err) {
						remoteState.controller = null;
						remoteState.activeSignature = '';
						if (!hasBase) setSuggestionsMessage('Unable to search right now.', {});
					}
				};

				const scheduleRemoteFetch = (context, token, hasBase) => {
					const signature = (context.typeFilter || 'employee') + '|' + context.termLower;
					if (remoteState.lastSignature === signature && !hasBase) {
						return; // avoid spamming when nothing changed
					}
					remoteState.lastSignature = signature;
					clearRemoteTimer();
					remoteState.timer = setTimeout(() => executeRemoteFetch(context, token, signature, hasBase), remoteConfig.debounceMs);
				};

				const addItem = (item) => {
					if (!item) return false;
					if (item.type === 'all') {
						if (state.all) return false;
						state.all = true;
					} else if (item.type === 'department') {
						if (state.departments.has(item.identifier)) return false;
						state.departments.add(item.identifier);
					} else if (item.type === 'role') {
						if (state.roles.has(item.identifier)) return false;
						state.roles.add(item.identifier);
					} else if (item.type === 'employee') {
						if (state.employees.has(item.identifier)) return false;
						state.employees.add(item.identifier);
					} else {
						return false;
					}
					syncHidden();
					renderChips();
					markDirty();
					return true;
				};

				const selectSuggestion = (index) => {
					if (index < 0 || index >= currentSuggestions.length) return;
					const item = currentSuggestions[index];
					if (!item) return;
					if (addItem(item)) input.value = '';
					hideSuggestions();
				};

				const refreshSuggestions = () => {
					const raw = input.value.trim();
					const context = parseContext(raw);
					const token = ++queryCounter;
					activeQueryToken = token;

					if (context.rawLower === '@all') {
						if (addItem(map.all.get('all') || allFallback)) {
							input.value = '';
							syncHidden();
							renderChips();
						}
						hideSuggestions();
						return;
					}

					if (!raw && !state.all && !state.departments.size && !state.roles.size && !state.employees.size) {
						currentSuggestions = gatherDefaults();
						renderSuggestions(currentSuggestions);
						clearRemoteRequests();
						return;
					}

					const baseMatches = gatherMatches(context);
					if (baseMatches.length) {
						currentSuggestions = baseMatches;
						renderSuggestions(currentSuggestions);
					} else if (!raw) {
						hideSuggestions();
					} else {
						setSuggestionsMessage('No matches found.', {});
					}

					const remoteDecision = evaluateRemote(context);
					if (remoteDecision.action === 'fetch') {
						scheduleRemoteFetch(context, token, baseMatches.length > 0);
						if (!baseMatches.length) {
							setSuggestionsMessage('Searching employees…', { loading: true });
						}
					} else {
						clearRemoteRequests();
						if (!baseMatches.length && remoteDecision.action === 'too-short' && raw) {
							setSuggestionsMessage(remoteDecision.message, {});
						}
					}
				};

				input.addEventListener('focus', () => refreshSuggestions());
				input.addEventListener('input', () => refreshSuggestions());
				input.addEventListener('keydown', (e) => {
					if (e.key === 'ArrowDown') {
						e.preventDefault();
						if (!currentSuggestions.length) refreshSuggestions(); else {
							highlightedIndex = (highlightedIndex + 1) % currentSuggestions.length;
							updateHighlight();
						}
					} else if (e.key === 'ArrowUp') {
						e.preventDefault();
						if (currentSuggestions.length) {
							highlightedIndex = (highlightedIndex - 1 + currentSuggestions.length) % currentSuggestions.length;
							updateHighlight();
						}
					} else if (e.key === 'Enter') {
						if (currentSuggestions.length && highlightedIndex >= 0) {
							e.preventDefault();
							selectSuggestion(highlightedIndex);
						}
					} else if (e.key === 'Escape') {
						hideSuggestions();
					}
				});

				suggestions.addEventListener('mousedown', (e) => {
					const target = e.target.closest('[data-suggestion-index]');
					if (!target) return;
					e.preventDefault();
					const idx = parseInt(target.getAttribute('data-suggestion-index'), 10);
					selectSuggestion(isNaN(idx) ? -1 : idx);
				});

				chips.addEventListener('click', (e) => {
					const btn = e.target.closest('[data-remove-type]');
					if (!btn) return;
					e.preventDefault();
					const type = btn.getAttribute('data-remove-type');
					const identifier = btn.getAttribute('data-remove-id') || '';
					let changed = false;
					if (type === 'all') {
						if (state.all) { state.all = false; changed = true; }
					} else if (type === 'department') {
						changed = state.departments.delete(identifier);
					} else if (type === 'role') {
						changed = state.roles.delete(identifier);
					} else if (type === 'employee') {
						changed = state.employees.delete(identifier);
					}
					if (changed) {
						syncHidden();
						renderChips();
						markDirty();
						refreshSuggestions();
					}
				});

				if (clearBtn) {
					clearBtn.addEventListener('click', (e) => {
						e.preventDefault();
						if (!state.all && !state.departments.size && !state.roles.size && !state.employees.size) return;
						state.all = false;
						state.departments.clear();
						state.roles.clear();
						state.employees.clear();
						input.value = '';
						syncHidden();
						renderChips();
						hideSuggestions();
						clearRemoteRequests();
						markDirty();
					});
				}

				input.addEventListener('blur', () => {
					setTimeout(() => {
						if (!form.contains(document.activeElement)) {
							hideSuggestions();
						}
					}, 120);
				});

				if (confirmApi) {
					form.addEventListener('submit', (e) => {
						if (form.dataset.memoConfirmed === '1') {
							form.dataset.memoConfirmed = '0';
							return;
						}
						e.preventDefault();
						const rows = collectAudienceRows();
						confirmApi.open(rows, {
							allowSubmit: rows.length > 0,
							onConfirm: () => {
								form.dataset.memoConfirmed = '1';
								form.submit();
							},
						});
					});
				}

				syncHidden();
				renderChips();
				form.__memoAudienceBound = true;
			});
		}

		document.addEventListener('DOMContentLoaded', () => init(document));
		document.addEventListener('spa:loaded', () => init(document));
	})();

// Unsaved changes protection (data-dirty-watch)
window.__formDirty = false;
function bindDirtyWatch(scope=document) {
	scope.querySelectorAll('form[data-dirty-watch] input, form[data-dirty-watch] textarea, form[data-dirty-watch] select')
		.forEach(el => {
			el.addEventListener('change', () => { window.__formDirty = true; });
			el.addEventListener('input', () => { window.__formDirty = true; });
		});
}
bindDirtyWatch(document);
document.addEventListener('spa:loaded', () => {
	window.__formDirty = false;
	bindDirtyWatch(document);
});

// Auto-enhance POST forms with dirty-watch unless opted out
function enhanceForms(scope=document) {
	scope.querySelectorAll('form').forEach(f => {
		const method = (f.getAttribute('method') || '').toLowerCase();
		if (method === 'post' && f.getAttribute('data-dirty-watch') !== 'off') {
			f.setAttribute('data-dirty-watch', '');
		}
	});
}
enhanceForms(document);
document.addEventListener('spa:loaded', () => enhanceForms(document));

// Sidebar collapse toggle
const btnCollapse = document.getElementById('btnCollapse');
const sidebar = document.getElementById('sidebar');
if (btnCollapse && sidebar) {
	btnCollapse.addEventListener('click', () => {
		sidebar.classList.toggle('collapsed');
		try { localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0'); } catch {}
	});
}

// Sidebar group toggle removed — consolidated into single implementation below (localStorage-backed)
// Previous duplicate IIFE was causing conflicting behavior with the localStorage version

// User menu toggle
const btnUser = document.getElementById('btnUser');
const userMenu = document.getElementById('userMenu');
if (btnUser && userMenu) {
	btnUser.addEventListener('click', () => userMenu.classList.toggle('hidden'));
	document.addEventListener('click', (e) => {
		if (!e.target.closest('#btnUser') && !e.target.closest('#userMenu')) {
			userMenu.classList.add('hidden');
		}
	});
}

function initNotifications(scope = document) {
	const trigger = scope.getElementById ? scope.getElementById('btnNotif') : document.getElementById('btnNotif');
	const dropdown = document.getElementById('notifDropdown');
	const detailModal = document.getElementById('notifDetailModal');
	const detailTitle = document.getElementById('notifDetailTitle');
	const detailBody = document.getElementById('notifDetailBody');
	const detailTimestamp = document.getElementById('notifDetailTimestamp');
	const detailBodyWrap = detailModal ? detailModal.querySelector('#notifDetailBodyWrap') : null;
	const memoPreviewWrap = detailModal ? detailModal.querySelector('[data-notif-memo]') : null;
	const memoCard = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-card]') : null;
	const memoContent = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-content]') : null;
	const memoLoading = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-loading]') : null;
	const memoError = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-error]') : null;
	const memoBodyEl = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-body]') : null;
	const memoMetaEl = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-meta]') : null;
	const memoCodeEl = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-code]') : null;
	const memoAttachmentsEl = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-attachments]') : null;
	const memoEmptyEl = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-empty]') : null;
	const memoTitleEl = memoPreviewWrap ? memoPreviewWrap.querySelector('[data-notif-memo-title]') : null;
	const listWrapper = document.getElementById('notifList');
	const itemsContainer = document.getElementById('notifItems');
	const emptyState = document.getElementById('notifEmpty');
	const markAllBtn = document.getElementById('notifMarkAll');
	const viewAllLink = document.getElementById('notifViewAll');
	const badge = trigger ? trigger.querySelector('[data-notif-badge]') || document.querySelector('[data-notif-badge]') : null;

	if (!trigger || !dropdown || !itemsContainer || trigger.__notifBound) {
		return;
	}
	trigger.__notifBound = true;
	dropdown.dataset.open = dropdown.dataset.open || '0';

	if (viewAllLink && trigger.dataset.viewAll) {
		viewAllLink.setAttribute('href', trigger.dataset.viewAll);
	}

	const emptyDefault = emptyState ? emptyState.textContent : '';
	let isLoading = false;

	const resolveUrl = (value) => {
		if (!value) return '';
		const stringValue = String(value);
		if (/^https?:\/\//i.test(stringValue)) return stringValue;
		const baseRaw = typeof window.__baseUrl === 'string' ? window.__baseUrl : '';
		const base = baseRaw ? baseRaw.replace(/\/$/, '') : '';
		const origin = typeof window.location?.origin === 'string' ? window.location.origin : '';
		const originBase = origin ? origin.replace(/\/$/, '') : '';
		if (stringValue.startsWith('/')) {
			// Always use origin + base to create absolute URLs with protocol
			if (originBase && base) return originBase + base + stringValue;
			if (originBase) return originBase + stringValue;
			return stringValue;
		}
		if (originBase && base) return originBase + base + '/' + stringValue;
		if (originBase) return originBase + '/' + stringValue;
		return stringValue;
	};

	const resetDetailModal = () => {
		if (!detailModal) return;
		detailModal.dataset.type = '';
		detailModal.dataset.targetUrl = '';
		if (detailBodyWrap) detailBodyWrap.classList.remove('hidden');
		if (memoPreviewWrap) {
			memoPreviewWrap.classList.add('hidden');
			delete memoPreviewWrap.dataset.memoId;
		}
		if (memoLoading) memoLoading.classList.add('hidden');
		if (memoError) memoError.classList.add('hidden');
		if (memoContent) memoContent.classList.remove('hidden');
		if (memoAttachmentsEl) memoAttachmentsEl.innerHTML = '';
		if (memoEmptyEl) memoEmptyEl.classList.add('hidden');
		if (memoBodyEl) memoBodyEl.textContent = '';
		if (memoMetaEl) {
			memoMetaEl.textContent = '';
			memoMetaEl.classList.remove('hidden');
		}
		if (memoCodeEl) {
			memoCodeEl.textContent = '';
			memoCodeEl.classList.add('hidden');
		}
		if (memoTitleEl) memoTitleEl.textContent = '';
		if (memoCard) memoCard.setAttribute('aria-label', 'Open memo details');
	};

	const renderMemoAttachments = (data) => {
		if (!memoAttachmentsEl) return;
		memoAttachmentsEl.innerHTML = '';
		const attachments = Array.isArray(data?.attachments) ? data.attachments : [];
		attachments.forEach((att) => {
			const iconSvg = att && att.is_image
				? '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 6.75A2.25 2.25 0 016.75 4.5h10.5A2.25 2.25 0 0119.5 6.75v10.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 17.25V6.75zm12 0L9 15l-3-3m6.75-3.75h.008v.008h-.008V9z"/></svg>'
				: '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6.5A2.25 2.25 0 0017.25 5.5H6.75A2.25 2.25 0 004.5 7.75v10.5A2.25 2.25 0 006.75 20.5h7.5m2.25-6.25L20 18m0 0l3-3m-3 3l-3-3"/></svg>';
			const sizeParts = [];
			if (att && att.size_label) sizeParts.push(att.size_label);
			if (att && att.mime_type) sizeParts.push(att.mime_type);
			const row = document.createElement('div');
			row.className = 'flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600';
			row.innerHTML = `
				<div class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
					${iconSvg}
				</div>
				<div class="min-w-0 flex-1">
					<p class="truncate font-medium text-slate-800">${escapeHtml(att?.name || 'Attachment')}</p>
					<p class="text-xs text-slate-400">${escapeHtml(sizeParts.join(' • '))}</p>
				</div>
			`;
			memoAttachmentsEl.appendChild(row);
		});
		const total = Number(data?.attachments_total || 0);
		if (total > attachments.length) {
			const remaining = total - attachments.length;
			const extraRow = document.createElement('div');
			extraRow.className = 'rounded-xl border border-dashed border-slate-200 px-4 py-2 text-xs text-slate-500';
			extraRow.textContent = `+${remaining} more attachment${remaining === 1 ? '' : 's'} not shown.`;
			memoAttachmentsEl.appendChild(extraRow);
		}
		if (memoEmptyEl) memoEmptyEl.classList.toggle('hidden', attachments.length > 0);
	};

	const renderMemoPreview = (data) => {
		if (!memoPreviewWrap) return;
		if (memoLoading) memoLoading.classList.add('hidden');
		if (memoError) memoError.classList.add('hidden');
		if (memoContent) memoContent.classList.remove('hidden');
		if (memoPreviewWrap && data && data.id) memoPreviewWrap.dataset.memoId = data.id;
		const memoTitle = data && data.header ? data.header : 'Memo';
		if (memoTitleEl) memoTitleEl.textContent = memoTitle;
		if (memoCard) memoCard.setAttribute('aria-label', `Open memo ${memoTitle}`);
		if (memoCodeEl) {
			if (data && data.memo_code) {
				memoCodeEl.textContent = data.memo_code;
				memoCodeEl.classList.remove('hidden');
			} else {
				memoCodeEl.textContent = '';
				memoCodeEl.classList.add('hidden');
			}
		}
		if (memoMetaEl) {
			const metaParts = [];
			if (data && data.issued_by) metaParts.push(data.issued_by);
			if (data && data.published_at) metaParts.push(data.published_at);
			memoMetaEl.textContent = metaParts.join(' • ');
			memoMetaEl.classList.toggle('hidden', metaParts.length === 0);
		}
		if (memoBodyEl) memoBodyEl.textContent = data && data.body_excerpt ? data.body_excerpt : 'No memo content available.';
		renderMemoAttachments(data);
		if (data && data.view_url) {
			const resolved = resolveUrl(data.view_url);
			if (resolved) detailModal.dataset.targetUrl = resolved;
		}
	};

	const navigateToTarget = () => {
		if (!detailModal) return;
		const target = detailModal.dataset.targetUrl;
		if (target) {
			window.location.href = target;
		}
	};

	if (memoCard && !memoCard.__notifMemoBound) {
		memoCard.addEventListener('click', (ev) => {
			ev.preventDefault();
			navigateToTarget();
		});
		memoCard.addEventListener('keydown', (ev) => {
			if (ev.key === 'Enter' || ev.key === ' ') {
				ev.preventDefault();
				navigateToTarget();
			}
		});
		memoCard.__notifMemoBound = true;
	}
	if (memoPreviewWrap && !memoPreviewWrap.__notifMemoWrapBound) {
		memoPreviewWrap.addEventListener('click', (ev) => {
			if (memoCard && memoCard.contains(ev.target)) return;
			ev.preventDefault();
			navigateToTarget();
		});
		memoPreviewWrap.__notifMemoWrapBound = true;
	}

	const closeDropdown = () => {
		dropdown.classList.add('hidden');
		dropdown.dataset.open = '0';
	};

	const openDropdown = () => {
		dropdown.classList.remove('hidden');
		dropdown.dataset.open = '1';
		loadFeed();
	};

	const setLoading = (state) => {
		isLoading = state;
		if (!listWrapper) return;
		if (state) {
			if (emptyState) emptyState.classList.add('hidden');
			listWrapper.dataset.state = 'loading';
			itemsContainer.innerHTML = `
				<div class="divide-y divide-slate-100">
					<div class="px-4 py-4 animate-pulse">
						<div class="h-3 w-2/3 rounded bg-slate-200"></div>
						<div class="mt-2 h-3 w-1/2 rounded bg-slate-200"></div>
					</div>
					<div class="px-4 py-4 animate-pulse">
						<div class="h-3 w-3/4 rounded bg-slate-200"></div>
						<div class="mt-2 h-3 w-1/3 rounded bg-slate-200"></div>
					</div>
				</div>`;
		} else {
			listWrapper.dataset.state = 'idle';
		}
	};

	const openDetailModal = (item) => {
		if (!detailModal) return;
		resetDetailModal();
		const payload = item && typeof item === 'object' ? item.payload || {} : {};
		if (detailTitle) detailTitle.textContent = item.title || 'Notification';
		if (detailBody) detailBody.textContent = item.body ? item.body : 'No additional details provided.';
		if (detailTimestamp) detailTimestamp.textContent = item.created_human || item.created_at || '';
		const payloadType = typeof payload.type === 'string' ? payload.type.toLowerCase() : '';
		const memoId = payload.memo_id || payload.memoId;
		if (payloadType === 'memo' && memoId && memoPreviewWrap) {
			detailModal.dataset.type = 'memo';
			let targetUrl = resolveUrl(payload.view_url || payload.view_path || '');
			if (!targetUrl) {
				targetUrl = resolveUrl(`/modules/memos/view?id=${memoId}`);
			}
			if (targetUrl) detailModal.dataset.targetUrl = targetUrl;
			if (detailBodyWrap) detailBodyWrap.classList.add('hidden');
			memoPreviewWrap.classList.remove('hidden');
			memoPreviewWrap.dataset.memoId = memoId;
			if (memoLoading) memoLoading.classList.remove('hidden');
			if (memoContent) memoContent.classList.add('hidden');
			if (memoError) memoError.classList.add('hidden');
			const previewUrl = resolveUrl(payload.preview_url || payload.preview_path || `/modules/memos/preview_modal.php?id=${memoId}`);
			if (previewUrl) {
				fetch(previewUrl, { headers: { 'X-Requested-With': 'fetch' } })
					.then((res) => {
						if (!res.ok) throw new Error('Failed to load memo preview');
						return res.json();
					})
					.then((data) => {
						renderMemoPreview(data);
					})
					.catch(() => {
						if (memoLoading) memoLoading.classList.add('hidden');
						if (memoContent) memoContent.classList.add('hidden');
						if (memoError) memoError.classList.remove('hidden');
					});
			} else {
				if (memoLoading) memoLoading.classList.add('hidden');
				if (memoContent) memoContent.classList.add('hidden');
				if (memoError) memoError.classList.remove('hidden');
			}
		}
		detailModal.classList.remove('hidden');
		detailModal.classList.add('flex');
	};

	const closeDetailModal = () => {
		if (!detailModal) return;
		detailModal.classList.add('hidden');
		detailModal.classList.remove('flex');
		resetDetailModal();
	};

	const markNotification = async (itemId) => {
		if (!trigger.dataset.markUrl) return;
		try {
			const body = new URLSearchParams({ csrf: trigger.dataset.csrf || '', id: itemId });
			await fetch(trigger.dataset.markUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-Requested-With': 'fetch',
				},
				body,
			});
		} catch (err) {
			// ignore errors; UI will refresh on next poll
		} finally {
			loadFeed(true);
		}
	};

	const renderFeed = (payload) => {
		const items = payload.items || [];
		const unread = payload.unread_count || 0;

		if (badge) {
			if (unread > 0) {
				badge.textContent = unread > 99 ? '99+' : unread;
				badge.classList.remove('hidden');
			} else {
				badge.classList.add('hidden');
			}
		}

		if (markAllBtn) {
			markAllBtn.disabled = unread === 0;
		}

		itemsContainer.innerHTML = '';
		if (!items.length) {
			if (emptyState) emptyState.classList.remove('hidden');
			return;
		}
		if (emptyState) emptyState.classList.add('hidden');

		items.forEach((item) => {
			const row = document.createElement('button');
			row.type = 'button';
			row.className = 'flex w-full gap-3 px-4 py-3 text-left transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none';
			if (item.is_unread) {
				row.classList.add('bg-indigo-50/60');
			}
			row.dataset.id = item.id;
			row.dataset.title = item.title || 'Notification';
			row.dataset.body = item.body || '';
			row.dataset.timestamp = item.created_human || item.created_at || '';
			const title = escapeHtml(item.title || 'Notification');
			const body = escapeHtml(item.body || '');
			const snippet = body.length > 120 ? body.slice(0, 117) + '…' : body;
			const when = escapeHtml(item.created_human || item.created_at || '');
			row.innerHTML = `
				<div class="relative mt-1">
					<span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600">${escapeHtml((item.title || 'N').charAt(0).toUpperCase())}</span>
					${item.is_unread ? '<span class="absolute -right-0.5 -top-0.5 inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>' : ''}
				</div>
				<div class="flex-1 min-w-0">
					<p class="text-sm font-medium text-slate-900 ${item.is_unread ? 'font-semibold' : ''}">${title}</p>
					${snippet ? `<p class="mt-1 text-sm text-slate-600 overflow-hidden text-ellipsis whitespace-nowrap">${snippet.replace(/\n/g, ' ')}</p>` : ''}
					<p class="mt-2 text-[11px] uppercase tracking-wide text-slate-400">${when}</p>
				</div>
			`;
			row.addEventListener('click', () => {
				closeDropdown();
				openDetailModal(item);
				if (item.is_unread) {
					markNotification(item.id);
				}
			});
			itemsContainer.appendChild(row);
		});
	};

	const showError = (message) => {
		itemsContainer.innerHTML = '';
		if (emptyState) {
			emptyState.textContent = message;
			emptyState.classList.remove('hidden');
		}
	};

	const loadFeed = async (force = false) => {
		if (isLoading && !force) return;
		if (!trigger.dataset.feedUrl) {
			showError('Notifications are unavailable right now.');
			return;
		}
		setLoading(true);
		try {
			const res = await fetch(trigger.dataset.feedUrl, { headers: { 'X-Requested-With': 'fetch' } });
			if (!res.ok) throw new Error('Unable to load notifications');
			const payload = await res.json();
			if (emptyState) emptyState.textContent = emptyDefault;
			renderFeed(payload);
		} catch (err) {
			showError('We can\'t load notifications right now. Please try again in a moment.');
		} finally {
			setLoading(false);
		}
	};

	const handleMarkAll = async () => {
		if (!markAllBtn || markAllBtn.disabled) return;
		if (!trigger.dataset.markAllUrl) {
			showError('Bulk mark-as-read is not available.');
			return;
		}
		markAllBtn.disabled = true;
		try {
			const body = new URLSearchParams({ csrf: trigger.dataset.csrf });
			const res = await fetch(trigger.dataset.markAllUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-Requested-With': 'fetch',
				},
				body,
			});
			if (!res.ok) throw new Error('Request failed');
			await loadFeed(true);
		} catch (err) {
			showError('Could not mark notifications as read. Please retry.');
			markAllBtn.disabled = false;
		}
	};

	trigger.addEventListener('click', (e) => {
		e.preventDefault();
		const isOpen = dropdown.dataset.open === '1';
		if (isOpen) {
			closeDropdown();
		} else {
			openDropdown();
		}
	});

	document.addEventListener('click', (e) => {
		if (!dropdown.contains(e.target) && !trigger.contains(e.target)) {
			closeDropdown();
		}
	});

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			if (dropdown.dataset.open === '1') closeDropdown();
			closeDetailModal();
		}
	});

	if (detailModal && !detailModal.__notifBound) {
		detailModal.addEventListener('click', (e) => {
			if (e.target.closest('[data-detail-dismiss]')) {
				closeDetailModal();
			}
		});
		detailModal.__notifBound = true;
	}

	if (markAllBtn && !markAllBtn.__notifBound) {
		markAllBtn.addEventListener('click', handleMarkAll);
		markAllBtn.__notifBound = true;
	}
}

document.addEventListener('DOMContentLoaded', () => initNotifications(document));
document.addEventListener('spa:loaded', () => initNotifications(document));

// Restore sidebar collapsed state
try {
	if (localStorage.getItem('sidebarCollapsed') === '1') {
		sidebar?.classList.add('collapsed');
	}
} catch {}

// Basic SPA active nav highlight
function updateActiveNav() {
	const links = document.querySelectorAll('.nav-item');
	const path = window.location.pathname;
	links.forEach(a => {
		const href = a.getAttribute('href');
		if (!href) return;
		try {
			const aPath = new URL(href, window.location.href).pathname;
			if (aPath === path || (aPath !== '/' && path.startsWith(aPath))) {
				a.classList.add('active');
			} else {
				a.classList.remove('active');
			}
		} catch {}
	});
	// Hide items with data-module if user has no access
	const access = window.__userAccess || {};
	document.querySelectorAll('.nav-item[data-module]').forEach(a => {
		const mod = a.getAttribute('data-module');
		const lvl = (access[mod] || '').toLowerCase();
		if (lvl === 'none') a.style.display = 'none'; else a.style.display = '';
	});
}
updateActiveNav();
document.addEventListener('spa:loaded', updateActiveNav);

// Charts initializer using data-* attributes
function initCharts(scope=document) {
	if (typeof Chart === 'undefined') return;
	// Clean up existing charts stored on elements
	scope.querySelectorAll('canvas[data-chart]').forEach(cv => {
		try { cv.__chart && cv.__chart.destroy(); } catch {}
		const type = cv.dataset.chart || 'line';
		let labels = [];
		let datasets = [];
		try { labels = JSON.parse(cv.dataset.labels || '[]'); } catch {}
		try { datasets = JSON.parse(cv.dataset.datasets || '[]'); } catch {}
		const data = { labels, datasets };

		const isDoughnutOrPie = (type === 'doughnut' || type === 'pie');

		// Container controls chart height; Chart.js fills it responsively
		const options = {
			responsive: true,
			maintainAspectRatio: false,
			resizeDelay: 50,
			plugins: {
				legend: {
					display: true,
					position: isDoughnutOrPie ? 'bottom' : 'top',
					labels: {
						boxWidth: 12,
						padding: 10,
						font: { size: 11 }
					}
				}
			}
		};

		if (!isDoughnutOrPie) {
			options.scales = {
				x: {
					ticks: {
						color: '#6b7280',
						maxRotation: 45,
						minRotation: 0,
						autoSkip: true,
						autoSkipPadding: 10,
						font: { size: 10 }
					},
					grid: { display: false }
				},
				y: {
					beginAtZero: true,
					ticks: {
						precision: 0,
						color: '#6b7280',
						font: { size: 10 }
					}
				}
			};
		}

		// Strip hard-coded dimensions; container div height governs sizing
		cv.removeAttribute('height');
		cv.removeAttribute('width');
		cv.__chart = new Chart(cv, { type, data, options });
	});
}
document.addEventListener('DOMContentLoaded', () => initCharts(document));
document.addEventListener('spa:loaded', () => initCharts(document));

// Header live clock
function startHeaderClock() {
	const el = document.getElementById('headerClock');
	if (!el) return;
	function tick() {
		const now = new Date();
		// Example: Mon, Sep 1, 2025 14:05:09
		const fmt = now.toLocaleString(undefined, {
			weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
			hour: '2-digit', minute: '2-digit', second: '2-digit'
		});
		el.textContent = fmt;
	}
	tick();
	clearInterval(window.__clockTimer);
	window.__clockTimer = setInterval(tick, 1000);
}
document.addEventListener('DOMContentLoaded', startHeaderClock);
document.addEventListener('spa:loaded', startHeaderClock);

// Form UX: focus animation on inputs
function bindFocusAnim(scope=document) {
	scope.querySelectorAll('input, textarea, select').forEach(el => {
		el.addEventListener('focus', () => el.classList.add('focus-anim'));
		el.addEventListener('blur', () => el.classList.remove('focus-anim'));
	});
}
bindFocusAnim(document);
document.addEventListener('spa:loaded', () => bindFocusAnim(document));

// Auto-mark required labels
function markRequiredLabels(scope=document) {
	scope.querySelectorAll('input[required], select[required], textarea[required]').forEach(input => {
		const id = input.id;
		let lbl = id ? scope.querySelector(`label[for="${CSS.escape(id)}"]`) : null;
		if (!lbl) {
			lbl = input.closest('label');
		}
		if (lbl) lbl.classList.add('required');
	});
}
markRequiredLabels(document);
document.addEventListener('spa:loaded', () => markRequiredLabels(document));

// Session keepalive: ping server on real user activity to keep 3-hour inactivity window accurate
(function(){
	const cfg = window.__keepaliveCfg;
	if (!cfg || !cfg.url || !cfg.token) return;
	let lastPing = 0;
	let pendingTimer = null;
	const throttleMs = typeof cfg.pingMs === 'number' && cfg.pingMs > 0 ? cfg.pingMs : 60000;
	function clearPending(){ if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; } }
	function sendPing(force = false){
		clearPending();
		const now = Date.now();
		if (!force && now - lastPing < throttleMs) return;
		lastPing = now;
		const body = new URLSearchParams({ csrf: cfg.token });
		fetch(cfg.url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
			body
		}).then(res => {
			if (!res.ok) throw new Error('keepalive');
			return res.json().catch(() => null);
		}).then(data => {
			if (data && data.session && typeof window.__sessionUpdate === 'function') {
				window.__sessionUpdate(data.session);
			}
		}).catch(() => {});
	}
	function schedulePing(){
		if (document.visibilityState === 'hidden') return;
		const now = Date.now();
		const elapsed = now - lastPing;
		if (elapsed >= throttleMs) {
			sendPing();
			return;
		}
		if (pendingTimer) return;
		pendingTimer = setTimeout(sendPing, throttleMs - elapsed);
	}
	['mousemove','mousedown','keydown','scroll','touchstart','touchmove'].forEach(evt => {
		document.addEventListener(evt, schedulePing, { passive: true });
	});
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') schedulePing();
	});
	document.addEventListener('spa:loaded', schedulePing);
	document.addEventListener('DOMContentLoaded', schedulePing);
	// Fire once on load in case page remains idle but user just logged in.
	if (document.readyState === 'complete' || document.readyState === 'interactive') {
		schedulePing();
	}
	window.__keepalivePing = sendPing;
})();
// Session expiry manager: warn users 10 minutes before logout and allow refresh
(function(){
	const cfg = window.__keepaliveCfg;
	const initialMeta = window.__sessionMeta;
	if (!cfg || !initialMeta) return;
	const warnThresholdMs = 10 * 60 * 1000;
	let meta = { ...initialMeta };
	let skewMs = Date.now() - (meta.serverNow ? meta.serverNow * 1000 : Date.now());
	let warnTimer = null;
	let logoutTimer = null;
	let countdownInterval = null;
	let warningEl = null;
	let countdownEl = null;
	let statusEl = null;
	let visible = false;

	function toNumber(value) {
		const num = Number(value);
		return Number.isFinite(num) ? num : null;
	}
	function updateSkew(serverSeconds) {
		if (!serverSeconds) return;
		skewMs = Date.now() - serverSeconds * 1000;
	}
	function toClientMs(serverSeconds) {
		if (!serverSeconds) return NaN;
		return serverSeconds * 1000 + skewMs;
	}
	function upcomingExpiryMs() {
		const idleMs = meta.idleExpiresAt ? toClientMs(meta.idleExpiresAt) : Infinity;
		const absoluteMs = meta.absoluteTimeout ? toClientMs(meta.absoluteExpiresAt) : Infinity;
		return Math.min(idleMs, absoluteMs);
	}
	function formatCountdown(ms) {
		const total = Math.max(0, Math.floor(ms / 1000));
		const minutes = Math.floor(total / 60);
		const seconds = total % 60;
		return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
	}
	function ensureWarningEl() {
		if (warningEl) return warningEl;
		const wrapper = document.createElement('div');
		wrapper.id = 'sessionExpiryNotice';
		wrapper.className = 'fixed inset-0 z-[190] hidden items-center justify-center px-4';
		wrapper.innerHTML = [
			'<div class="absolute inset-0 bg-slate-900/60"></div>',
			'<div class="relative w-full max-w-sm mx-auto">',
				'<div class="bg-white rounded-lg shadow-xl p-5 space-y-4">',
					'<div>',
						'<h2 class="text-lg font-semibold text-gray-900">Session Expiring Soon</h2>',
						'<p class="text-sm text-gray-600" data-session-status>For your security, you will be signed out in <span class="font-semibold" data-countdown>10:00</span>.</p>',
					'</div>',
					'<div class="flex flex-col sm:flex-row sm:justify-end gap-2">',
						'<button type="button" class="btn btn-outline" data-session-logout>Log Out</button>',
						'<button type="button" class="btn btn-primary" data-session-stay>Stay Signed In</button>',
					'</div>',
				'</div>',
			'</div>'
		].join('');
		const overlay = wrapper.firstElementChild;
		const stayBtn = wrapper.querySelector('[data-session-stay]');
		const logoutBtn = wrapper.querySelector('[data-session-logout]');
		countdownEl = wrapper.querySelector('[data-countdown]');
		statusEl = wrapper.querySelector('[data-session-status]');
		if (overlay) {
			overlay.addEventListener('click', () => hideWarning());
		}
		if (stayBtn) {
			stayBtn.addEventListener('click', () => {
				optimisticExtend();
				if (typeof window.__keepalivePing === 'function') {
					window.__keepalivePing(true);
				}
			});
		}
		if (logoutBtn) {
			logoutBtn.addEventListener('click', () => expire());
		}
		document.body.appendChild(wrapper);
		warningEl = wrapper;
		return wrapper;
	}
	function hideWarning() {
		if (!warningEl) return;
		warningEl.classList.add('hidden');
		visible = false;
		if (countdownInterval) {
			clearInterval(countdownInterval);
			countdownInterval = null;
		}
	}
	function renderCountdown() {
		const expiresMs = upcomingExpiryMs();
		const remaining = expiresMs - Date.now();
		if (remaining <= 0) {
			expire();
			return;
		}
		if (!countdownEl) return;
		countdownEl.textContent = formatCountdown(remaining);
	}
	function showWarning() {
		const el = ensureWarningEl();
		if (!el) return;
		if (statusEl) {
			statusEl.innerHTML = 'For your security, you will be signed out in <span class="font-semibold" data-countdown>10:00</span>.';
			countdownEl = statusEl.querySelector('[data-countdown]');
		}
		el.classList.remove('hidden');
		visible = true;
		renderCountdown();
		if (countdownInterval) clearInterval(countdownInterval);
		countdownInterval = setInterval(renderCountdown, 1000);
	}
	function clearTimers() {
		if (warnTimer) {
			clearTimeout(warnTimer);
			warnTimer = null;
		}
		if (logoutTimer) {
			clearTimeout(logoutTimer);
			logoutTimer = null;
		}
	}
	function scheduleTimers() {
		clearTimers();
		const expiresMs = upcomingExpiryMs();
		if (!Number.isFinite(expiresMs)) return;
		const remaining = expiresMs - Date.now();
		if (remaining <= 0) {
			expire();
			return;
		}
		if (remaining > warnThresholdMs && visible) {
			hideWarning();
		}
		const warnIn = remaining - warnThresholdMs;
		if (warnIn <= 0) {
			showWarning();
		} else {
			warnTimer = setTimeout(showWarning, warnIn);
		}
		logoutTimer = setTimeout(() => expire(), remaining + 1000);
	}
	function expire() {
		hideWarning();
		clearTimers();
		const base = (window.__baseUrl || '').replace(/\/$/, '');
		const origin = window.location.origin || '';
		const target = origin + base + '/logout?reason=timeout';
		window.location.href = target;
	}
	function optimisticExtend() {
		const serverNow = Math.floor((Date.now() - skewMs) / 1000);
		const next = {
			serverNow,
			idleTimeout: meta.idleTimeout,
			absoluteTimeout: meta.absoluteTimeout,
			idleExpiresAt: serverNow + (meta.idleTimeout || 0),
		};
		if (meta.absoluteTimeout && meta.absoluteTimeout > 0) {
			next.absoluteExpiresAt = serverNow + meta.absoluteTimeout;
		}
		applySessionData(next, true);
	}
	function applySessionData(data, optimistic = false) {
		if (!data) return;
		const next = { ...meta };
		const serverNow = toNumber(data.serverNow ?? data.server_now);
		if (serverNow !== null) {
			next.serverNow = serverNow;
		}
		const idleTimeout = toNumber(data.idleTimeout ?? data.idle_timeout);
		if (idleTimeout !== null) {
			next.idleTimeout = idleTimeout;
		}
		const absoluteTimeout = toNumber(data.absoluteTimeout ?? data.absolute_timeout);
		if (absoluteTimeout !== null && absoluteTimeout !== undefined) {
			next.absoluteTimeout = absoluteTimeout;
		}
		const idleExpires = toNumber(data.idleExpiresAt ?? data.idle_expires_at);
		if (idleExpires !== null) {
			next.idleExpiresAt = idleExpires;
		} else if (optimistic && next.serverNow !== undefined && next.idleTimeout) {
			next.idleExpiresAt = next.serverNow + next.idleTimeout;
		}
		const absoluteExpires = toNumber(data.absoluteExpiresAt ?? data.absolute_expires_at);
		if (absoluteExpires !== null) {
			next.absoluteExpiresAt = absoluteExpires;
		} else if (optimistic && next.serverNow !== undefined && next.absoluteTimeout) {
			next.absoluteExpiresAt = next.serverNow + next.absoluteTimeout;
		}
		meta = next;
		if (meta.serverNow) {
			updateSkew(meta.serverNow);
		}
		window.__sessionMeta = { ...meta };
		scheduleTimers();
	}
	window.__sessionUpdate = (data) => applySessionData(data, false);
	applySessionData(meta, false);
})();

// Login History modal: delegated click handler avoids inline JS escaping
(function(){
	function ensureModal(){
		let m = document.getElementById('loginHistModal');
		if (m) return m;
		m = document.createElement('div');
		m.id = 'loginHistModal';
		m.className = 'fixed inset-0 z-50 hidden';
		m.innerHTML = [
			'<div class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity" data-close></div>',
			'<div class="absolute inset-0 flex items-center justify-center p-4">',
				'<div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl transform transition-all">',
					'<div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gradient-to-r from-blue-50 to-indigo-50 rounded-t-xl">',
						'<div class="flex items-center gap-3">',
							'<svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">',
								'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
							'</svg>',
							'<div>',
								'<h3 class="text-lg font-semibold text-gray-800">Login History</h3>',
								'<p class="text-xs text-gray-600">Recent account access records</p>',
							'</div>',
						'</div>',
						'<button class="text-gray-400 hover:text-gray-600 hover:bg-white/50 rounded-lg p-2 transition-colors" data-close aria-label="Close">',
							'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">',
								'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
							'</svg>',
						'</button>',
					'</div>',
					'<div class="p-6 max-h-[70vh] overflow-auto bg-gray-50"><div id="loginHistList"></div></div>',
				'</div>',
			'</div>'
		].join('');
		document.body.appendChild(m);
		m.addEventListener('click', (e)=>{ if (e.target.closest('[data-close]')) m.classList.add('hidden'); });
		document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && !m.classList.contains('hidden')) m.classList.add('hidden'); });
		return m;
	}
	function showHistory(list){
		const m = ensureModal();
		const container = m.querySelector('#loginHistList');
		container.innerHTML = '';
		if (!Array.isArray(list) || list.length === 0) {
			container.innerHTML = `
				<div class="text-center py-12 text-gray-500">
					<svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
					</svg>
					<p class="text-sm font-medium">No login records found</p>
					<p class="text-xs text-gray-400 mt-1">This account has no recorded login activity</p>
				</div>`;
		} else {
			// Detect structured objects with time & ip
			const structured = typeof list[0] === 'object' && list[0] !== null && ('t' in list[0] || 'ts' in list[0]);
			if (!structured) {
				const ul = document.createElement('ul');
				ul.className = 'space-y-2';
				list.forEach(txt => {
					const li = document.createElement('li');
					li.className = 'p-3 bg-white rounded-lg border border-gray-200 text-sm';
					li.textContent = String(txt||'');
					ul.appendChild(li);
				});
				container.appendChild(ul);
			} else {
				// Build modern card-based layout
				const wrapper = document.createElement('div');
				wrapper.className = 'space-y-3';
				
				list.forEach((r, idx) => {
					const card = document.createElement('div');
					card.className = 'bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow';
					
					const t = (r.t || r.ts || '').toString();
					const ip = (r.ip || 'Unknown');
					let ua = (r.ua || 'Unknown User Agent') + '';
					
					// Parse user agent for browser info
					let browser = 'Unknown Browser';
					let os = 'Unknown OS';
					if (ua.toLowerCase().includes('chrome')) browser = 'Chrome';
					else if (ua.toLowerCase().includes('firefox')) browser = 'Firefox';
					else if (ua.toLowerCase().includes('safari')) browser = 'Safari';
					else if (ua.toLowerCase().includes('edge')) browser = 'Edge';
					
					if (ua.toLowerCase().includes('windows')) os = 'Windows';
					else if (ua.toLowerCase().includes('mac')) os = 'macOS';
					else if (ua.toLowerCase().includes('linux')) os = 'Linux';
					else if (ua.toLowerCase().includes('android')) os = 'Android';
					else if (ua.toLowerCase().includes('iphone') || ua.toLowerCase().includes('ipad')) os = 'iOS';
					
					card.innerHTML = `
						<div class="flex items-start justify-between mb-3">
							<div class="flex items-center gap-2">
								<div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center">
									<span class="text-lg">${idx === 0 ? '<svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>' : '<svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>'}</span>
								</div>
								<div>
									<div class="font-semibold text-gray-800 text-sm">${escapeHtml(t)}</div>
									<div class="text-xs text-gray-500">${idx === 0 ? 'Most recent login' : 'Login #' + (idx + 1)}</div>
								</div>
							</div>
							${idx === 0 ? '<span class="px-2 py-1 bg-green-100 text-green-700 text-xs font-medium rounded-full">Latest</span>' : ''}
						</div>
						<div class="grid grid-cols-1 gap-2 text-xs">
							<div class="flex items-center gap-2 text-gray-600">
								<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
								</svg>
								<span class="font-medium">IP:</span>
								<span class="font-mono text-gray-700">${escapeHtml(ip)}</span>
							</div>
							<div class="flex items-center gap-2 text-gray-600">
								<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
								</svg>
								<span class="font-medium">Device:</span>
								<span>${browser} on ${os}</span>
							</div>
							${ua.length > 50 ? `
								<details class="mt-1">
									<summary class="cursor-pointer text-blue-600 hover:text-blue-700 font-medium">View full user agent</summary>
									<div class="mt-2 p-2 bg-gray-100 rounded text-xs font-mono text-gray-700 break-all">${escapeHtml(ua)}</div>
								</details>
							` : ''}
						</div>
					`;
					wrapper.appendChild(card);
				});
				
				container.appendChild(wrapper);
			}
		}
		m.classList.remove('hidden');
	}

	// Action History (per account) dynamic fetch
	function attachActionHistory(){
		document.querySelectorAll('.btn-action-history').forEach(btn=>{
			if (btn.__ahBound) return; btn.__ahBound = true;
			btn.addEventListener('click', ()=>{
				const userId = btn.getAttribute('data-user-id');
				if (!userId) return;
				fetchActionHistory(userId, 1);
			});
		});
	}

	async function fetchActionHistory(userId, page){
		const m = ensureModal();
		const container = m.querySelector('#loginHistList');
		container.innerHTML = '<li class="text-gray-500">Loading...</li>';
		try {
			const res = await fetch(window.location.pathname + '?action_history=1&uid=' + encodeURIComponent(userId) + '&page=' + page, {headers:{'X-Requested-With':'fetch'}});
			if (!res.ok) throw new Error('HTTPS '+res.status);
			const data = await res.json();
			const {rows=[], page:pg=1, pages=1} = data;
			const tbl = document.createElement('table');
			tbl.className='min-w-full text-xs';
			tbl.innerHTML='<thead><tr class="bg-gray-50 text-left"><th class=p-1>Time</th><th class=p-1>Action</th><th class=p-1>Status</th><th class=p-1>Module</th></tr></thead><tbody></tbody>';
			const tb = tbl.querySelector('tbody');
			rows.forEach(r=>{
				const tr=document.createElement('tr');
				tr.innerHTML='<td class="p-1 whitespace-nowrap font-mono">'+escapeHtml(r.time||'')+'</td><td class=p-1>'+escapeHtml(r.action||'')+'</td><td class=p-1>'+escapeHtml(r.status||'')+'</td><td class=p-1>'+escapeHtml(r.module||'')+'</td>';
				tb.appendChild(tr);
			});
			const nav = document.createElement('div');
			nav.className='mt-2 flex gap-1 flex-wrap';
			for (let i=1;i<=pages;i++){
				const b=document.createElement('button');
				b.type='button';
				b.className='btn btn-sm '+(i===pg?' bg-gray-200':'');
				b.textContent=i;
				b.addEventListener('click', ()=>fetchActionHistory(userId, i));
				nav.appendChild(b);
			}
			container.innerHTML='';
			container.appendChild(tbl);
			container.appendChild(nav);
			m.classList.remove('hidden');
		} catch (e){
			container.innerHTML='<li class="text-red-600">Failed to load history</li>';
		}
	}

	attachActionHistory();
	document.addEventListener('spa:loaded', attachActionHistory);
	function bind(scope=document){
		scope.addEventListener('click', (e) => {
			const btn = e.target.closest('.btn-login-history');
			if (!btn) return;
			e.preventDefault();
			let data = [];
			try { data = JSON.parse(btn.getAttribute('data-hist') || '[]'); } catch {}
			showHistory(data);
		});
	}
	document.addEventListener('DOMContentLoaded', () => bind(document));
	document.addEventListener('spa:loaded', () => bind(document));
})();

// Sidebar menu group collapsible behavior
(function(){
	function init(scope=document){
		const root = scope.getElementById ? scope : document;
		const groups = root.querySelectorAll('.nav-group');
		groups.forEach(g => {
			const key = 'navgrp.' + (g.getAttribute('data-group') || '');
			const btn = g.querySelector('[data-group-toggle]');
			const content = g.querySelector('.group-content');
			if (!btn || !content) return;
			if (btn.dataset.bound) return; btn.dataset.bound = '1';
			// restore state
			try {
				const saved = localStorage.getItem(key);
				const expanded = saved === null ? true : (saved === '1');
				content.style.display = expanded ? '' : 'none';
				btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
				// rotate chevron when collapsed
				const svg = btn.querySelector('svg');
				if (svg) svg.style.transform = expanded ? '' : 'rotate(-90deg)';
			} catch {}
			btn.addEventListener('click', () => {
				const isHidden = content.style.display === 'none';
				content.style.display = isHidden ? '' : 'none';
				btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
				try { localStorage.setItem(key, isHidden ? '1' : '0'); } catch {}
				const svg = btn.querySelector('svg');
				if (svg) svg.style.transform = isHidden ? '' : 'rotate(-90deg)';
			});
		});
	}
	document.addEventListener('DOMContentLoaded', () => init(document));
	document.addEventListener('spa:loaded', () => init(document));
})();

// Floating overlay notifications: reusable, stacked, fade in/out
(function(){
	function ensureHost(){
		let host = document.getElementById('notifHost');
		if (!host) {
			host = document.createElement('div');
			host.id = 'notifHost';
			host.className = 'fixed top-4 right-4 left-4 md:left-auto z-[70] flex flex-col items-center md:items-end gap-2 pointer-events-none';
			document.body.appendChild(host);
		}
		return host;
	}
	function bindNotice(el){
		if (el.dataset.bound) return; el.dataset.bound = '1';
		el.style.opacity = '0';
		el.style.transform = 'translateY(-8px)';
		el.style.transition = 'opacity 200ms ease, transform 200ms ease';
		requestAnimationFrame(() => { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; });
		const closeBtn = el.querySelector('[data-close]');
		const doClose = () => {
			el.style.opacity = '0'; el.style.transform = 'translateY(-8px)';
			setTimeout(() => { el.remove(); }, 220);
		};
		closeBtn && closeBtn.addEventListener('click', doClose);
		const auto = el.dataset.autoclose === '1';
		const timeout = parseInt(el.dataset.timeout || '3500', 10);
		if (auto) setTimeout(doClose, isNaN(timeout) ? 3500 : timeout);
	}
	function make(kind, message, options={}){
		const host = ensureHost();
		const box = document.createElement('div');
		const base = 'notif pointer-events-auto shadow-lg rounded-lg px-3 py-2 text-sm flex items-center justify-between min-w-[280px] max-w-[520px] border';
		const theme = (kind === 'success')
			? ' bg-emerald-50 text-emerald-800 border-emerald-200'
			: (kind === 'error')
				? ' bg-red-50 text-red-800 border-red-200'
				: ' bg-gray-50 text-gray-800 border-gray-200';
		box.className = base + theme;
		box.setAttribute('role', 'alert');
		box.dataset.autoclose = (options.autoclose ?? true) ? '1' : '0';
		if (options.timeout) box.dataset.timeout = String(options.timeout);
		box.innerHTML = '<div class="pr-2"></div>' +
						'<button class="ml-4 opacity-70 hover:opacity-100" data-close aria-label="Close notification">&times;</button>';
		box.querySelector('div').textContent = message || '';
		host.appendChild(box);
		bindNotice(box);
		return box;
	}
	// Public API
	window.notifyOverlay = {
		success(msg, opts){ return make('success', msg, opts); },
		error(msg, opts){ return make('error', msg, opts); },
		info(msg, opts){ return make('info', msg, opts); },
		show(kind, msg, opts){ return make(kind, msg, opts); }
	};

	// Initialize any server-rendered notices on load/Spa navigation
	function initNotifications(scope=document){
		const host = (scope.getElementById ? scope.getElementById('notifHost') : document.getElementById('notifHost')) || ensureHost();
		host.querySelectorAll('.notif').forEach(bindNotice);
	}
	document.addEventListener('DOMContentLoaded', () => initNotifications(document));
	document.addEventListener('spa:loaded', () => initNotifications(document));
})();

// Simple validators: optional email domain rule via data-email-domain; Philippine phone input helper
function attachValidators(scope=document) {
	// Email domain validator
	scope.querySelectorAll('input[type="email"]').forEach(inp => {
		const getDomainRule = () => inp.dataset.emailDomain || inp.closest('form')?.dataset.emailDomain || '';
		inp.addEventListener('blur', () => {
			const rule = getDomainRule();
			const v = (inp.value||'').trim();
			if (rule) {
				const rx = new RegExp('@' + rule.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', 'i');
				if (v && !rx.test(v)) {
					inp.classList.add('input-error');
					showFieldError(inp, 'Email must be @' + rule);
				} else {
					inp.classList.remove('input-error');
					clearFieldError(inp);
				}
			}
		});
	});
	// Philippine phone UI + validation
		scope.querySelectorAll('input[type="tel"]').forEach(inp => {
		if (inp.dataset.enhancedPhone) return; // avoid double init
		inp.dataset.enhancedPhone = '1';
		// Wrap input with prefix UI if not already wrapped
		const wrap = document.createElement('div'); wrap.className = 'ph-phone';
		const pref = document.createElement('div'); pref.className = 'ph-prefix';
		pref.innerHTML = '<span class="flag">🇵🇭</span><span>+63</span>';
		const clone = inp.cloneNode(true); clone.classList.add('ph-input'); clone.placeholder = clone.placeholder?.replace(/^\+?63/, '').trim();
		inp.replaceWith(wrap); wrap.appendChild(pref); wrap.appendChild(clone);
		const validate = () => {
			let v = (clone.value||'').replace(/[^0-9]/g,'');
			// Accept formats: 10 digits starting with 9 (mobile), or 10/11 digits for landline; keep simple mobile rule here: 10 digits starting 9
			if (v.startsWith('0')) v = v.slice(1);
			if (!/^9\d{9}$/.test(v)) {
				clone.classList.add('input-error');
				showFieldError(clone, 'Enter a valid PH mobile number (9XXXXXXXXX)');
			} else {
				clone.classList.remove('input-error');
				clearFieldError(clone);
			}
		};
		clone.addEventListener('blur', validate);
		clone.addEventListener('input', () => { clearFieldError(clone); clone.classList.remove('input-error'); });
		// Store reference for submit formatting
		clone.dataset.phPhone = '1';
	});
}

function showFieldError(input, msg) {
	let err = input.nextElementSibling;
	if (!err || !err.classList.contains('field-error')) {
		err = document.createElement('div'); err.className = 'field-error';
		input.insertAdjacentElement('afterend', err);
	}
	err.textContent = msg;
}
function clearFieldError(input) {
	const err = input.nextElementSibling;
	if (err && err.classList.contains('field-error')) err.remove();
}

attachValidators(document);
document.addEventListener('spa:loaded', () => attachValidators(document));

// On form submit, block when validation errors present and format PH phone as +63XXXXXXXXXX
document.addEventListener('submit', (e) => {
	const form = e.target.closest('form');
	if (!form) return;
	// Email domain enforcement
	const domain = form.dataset.emailDomain || '';
	let firstInvalid = null;
	if (domain) {
		form.querySelectorAll('input[type="email"]').forEach(inp => {
			const v = (inp.value||'').trim();
			if (v) {
				const rx = new RegExp('@' + domain.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', 'i');
				if (!rx.test(v)) {
					inp.classList.add('input-error');
					showFieldError(inp, 'Email must be @' + domain);
					firstInvalid = firstInvalid || inp;
				}
			}
		});
	}
	// PH phone validation
	form.querySelectorAll('input[data-ph-phone="1"]').forEach(inp => {
		let v = (inp.value||'').replace(/[^0-9]/g,'');
		if (v.startsWith('0')) v = v.slice(1);
		if (!/^9\d{9}$/.test(v)) {
			inp.classList.add('input-error');
			showFieldError(inp, 'Enter a valid PH mobile number (9XXXXXXXXX)');
			firstInvalid = firstInvalid || inp;
		} else {
			// format to +63 for submit
			inp.value = '+63' + v;
		}
	});
	if (firstInvalid) { e.preventDefault(); firstInvalid.focus(); }
});

// ===== Quick Authorization Override (client-side UX) =====
(function(){
	const modal = document.getElementById('authzModal');
	const notice = document.getElementById('authzNotice');
	const startBtn = document.getElementById('authzStart');
	const form = document.getElementById('authzForm');
	const email = document.getElementById('authzEmail');
	const pass = document.getElementById('authzPassword');
	const targetFormIdEl = document.getElementById('authzTargetFormId');
	const reqLevelEl = document.getElementById('authzRequiredLevel');
	const moduleEl = document.getElementById('authzModule');
	const requirementEl = document.getElementById('authzRequirement');
	const requirementFormEl = document.getElementById('authzRequirementForm');
	const actionLabelEl = document.getElementById('authzActionLabel');
	const actionLabelFormEl = document.getElementById('authzActionLabelForm');
	const actionInput = document.getElementById('authzAction');
	if (!modal || !notice || !form) return;

	const rank = { none:0, read:1, write:2, admin:3, manage:3 };
	const levelNames = { none:'No', read:'Read', write:'Write', admin:'Admin', manage:'Admin' };

	const toTitle = (str='') => str.replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
	function formatRequirement(module, requiredLevel){
		const mod = (module || '').trim();
		const lvlKey = (requiredLevel || '').toLowerCase();
		if (!mod && !lvlKey) return '';
		const moduleLabel = mod ? toTitle(mod) : 'Module';
		const levelLabel = levelNames[lvlKey] ? levelNames[lvlKey] + ' level' : requiredLevel;
		return `Requires ${moduleLabel} ${levelLabel} access`;
	}

	function openAuthz(forForm, module, requiredLevel, startWithForm=false, actionLabel=''){
		const currLvl = (window.__userAccess && window.__userAccess[module]) || '';
		const rCurr = rank[(currLvl||'').toLowerCase()] ?? 0;
		const rReq = rank[(requiredLevel||'').toLowerCase()] ?? 2;
		const force = startWithForm || !!(forForm && forForm.hasAttribute('data-authz-force'));
		const label = (actionLabel || forForm?.getAttribute('data-authz-action') || '').trim();
		const requirementText = formatRequirement(module, requiredLevel);
		if (actionLabelEl) {
			actionLabelEl.textContent = label || 'Authorization Required';
			actionLabelEl.classList.toggle('hidden', !label);
		}
		if (actionLabelFormEl) {
			actionLabelFormEl.textContent = label || 'Authorization Required';
			actionLabelFormEl.classList.toggle('hidden', !label);
		}
		if (requirementEl) {
			requirementEl.textContent = requirementText;
			requirementEl.classList.toggle('hidden', !requirementText);
		}
		if (requirementFormEl) {
			requirementFormEl.textContent = requirementText;
			requirementFormEl.classList.toggle('hidden', !requirementText);
		}
		if (actionInput) {
			actionInput.value = label;
		}
		if (startBtn) {
			startBtn.style.display = (force || rCurr === 0) ? 'none' : '';
		}
		if (force) {
			notice.classList.add('hidden');
			form.classList.remove('hidden');
			setTimeout(() => email?.focus(), 10);
		} else {
			form.classList.add('hidden');
			notice.classList.remove('hidden');
		}
		targetFormIdEl.value = forForm.id || (forForm.id = 'f_' + Math.random().toString(36).slice(2));
		reqLevelEl.value = requiredLevel;
		moduleEl.value = module;
		modal.classList.remove('hidden');
		email.value = '';
		pass.value = '';
	}
	function closeAuthz(){ modal.classList.add('hidden'); }
	modal.addEventListener('click', (e) => {
		if (e.target.matches('[data-authz-close]')) closeAuthz();
	});
	startBtn?.addEventListener('click', () => {
		notice.classList.add('hidden');
		form.classList.remove('hidden');
		setTimeout(() => email?.focus(), 10);
	});
	form.addEventListener('submit', async (e) => {
		e.preventDefault();
		const tfId = targetFormIdEl.value;
		const tf = document.getElementById(tfId);
		if (!tf) { closeAuthz(); return; }
		const submitBtn = form.querySelector('button[type="submit"]');
		const original = submitBtn.innerHTML;
		submitBtn.disabled = true;
		submitBtn.innerHTML = '<span class="spinner-mini" aria-hidden="true"></span><span class="ml-2">Authorizing…</span>';
		const start = Date.now();
		// Remove previously injected hidden inputs before adding new ones
		tf.querySelectorAll('input[data-authz-injected="1"]').forEach(el => el.remove());
		const addHidden = (name, value) => {
			const input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			input.dataset.authzInjected = '1';
			tf.appendChild(input);
			return input;
		};
		addHidden('override_email', email.value);
		addHidden('override_password', pass.value);
		if (tf.hasAttribute('data-authz-force')) {
			addHidden('override_force', '1');
		}
		const currentActionLabel = actionInput ? actionInput.value : '';
		if (currentActionLabel) {
			addHidden('override_action', currentActionLabel);
		}
		const submitName = tf.dataset.authzSubmitName || '';
		if (submitName) {
			addHidden(submitName, tf.dataset.authzSubmitValue || '');
		}
		closeAuthz();
		const elapsed = Date.now() - start;
		const wait = Math.max(1000 - elapsed, 0);
		setTimeout(() => {
			try {
				tf.dataset.confirmed = '1';
				delete tf.dataset.authzSubmitName;
				delete tf.dataset.authzSubmitValue;
				delete tf.dataset.authzSubmitAction;
				tf.submit();
			} catch(err) {
				submitBtn.disabled = false;
				submitBtn.innerHTML = original;
			}
		}, wait);
	});

	// Intercept restricted forms marked with data-authz
	document.addEventListener('submit', (e) => {
		const f = e.target.closest('form[data-authz-module]');
		if (!f) return;
		const module = f.getAttribute('data-authz-module') || '';
		const required = f.getAttribute('data-authz-required') || 'write';
		const force = f.hasAttribute('data-authz-force');
		const submitter = e.submitter || (document.activeElement && document.activeElement.form === f ? document.activeElement : null);
		const tagName = submitter?.tagName || '';
		const type = (submitter?.type || '').toLowerCase();
		const isSubmitControl = submitter && ((tagName === 'BUTTON' && type !== 'button') || (tagName === 'INPUT' && type === 'submit'));
		if (isSubmitControl && submitter.name) {
			f.dataset.authzSubmitName = submitter.name;
			f.dataset.authzSubmitValue = submitter.value;
		} else if (submitter) {
			delete f.dataset.authzSubmitName;
			delete f.dataset.authzSubmitValue;
		}
		if (submitter) {
			const submitAction = submitter.getAttribute('data-authz-action') || submitter.dataset.authzAction || (submitter.textContent || '').trim();
			if (submitAction) {
				f.dataset.authzSubmitAction = submitAction;
			}
		}
		if (f.hasAttribute('data-confirm') && f.dataset.confirmed !== '1') {
			return;
		}
		const currLvl = (window.__userAccess && window.__userAccess[module]) || '';
		const rCurr = rank[(currLvl||'').toLowerCase()] ?? 0;
		const rReq = rank[(required||'').toLowerCase()] ?? 2;
		if (rCurr >= rReq && !force) {
			delete f.dataset.authzSubmitAction;
			if (!force) {
				delete f.dataset.authzSubmitName;
				delete f.dataset.authzSubmitValue;
			}
			return;
		}
		e.preventDefault();
		const label = f.dataset.authzSubmitAction || f.getAttribute('data-authz-action') || '';
		openAuthz(f, module, required, force, label);
	});
})();

	// ===== System Configuration Modal Binding =====
	(function(){
		function bindSystemConfig(){
			const modal = document.getElementById('rateModal');
			if (!modal || modal.dataset.jsBound === '1') return;
			modal.dataset.jsBound = '1';
			const form = document.getElementById('rateForm');
			const titleEl = modal.querySelector('[data-rate-modal-title]');
			const categorySelect = document.getElementById('rateCategory');
			const codeInput = document.getElementById('rateCode');
			const labelInput = document.getElementById('rateLabel');
			const defaultInput = document.getElementById('rateDefault');
			const overrideInput = document.getElementById('rateOverride');
			const startInput = document.getElementById('rateStart');
			const endInput = document.getElementById('rateEnd');
			const notesInput = document.getElementById('rateNotes');
			const isNewInput = document.getElementById('rateIsNew');
			const addBtn = document.getElementById('btnAddRate');

			if (!form || !categorySelect || !codeInput || !labelInput || !defaultInput || !startInput || !isNewInput) {
				return;
			}

			const todayIso = new Date().toISOString().slice(0,10);

			function openModal(){
				modal.classList.remove('hidden');
				modal.classList.add('flex');
				setTimeout(() => codeInput.focus(), 50);
			}
			function closeModal(){
				modal.classList.add('hidden');
				modal.classList.remove('flex');
				form.reset();
				form.dataset.confirmed = '0';
			}

			modal.addEventListener('click', (e) => {
				if (e.target.dataset.close !== undefined) {
					closeModal();
				}
			});
			modal.querySelectorAll('[data-close]').forEach(btn => {
				btn.addEventListener('click', (e) => { e.preventDefault(); closeModal(); });
			});
			window.addEventListener('keydown', (e) => {
				if (!modal.classList.contains('hidden') && e.key === 'Escape') {
					closeModal();
				}
			});

			function populateModal(data, mode){
				form.reset();
				form.dataset.confirmed = '0';
				if (titleEl) {
					titleEl.textContent = mode === 'create' ? 'Add Rate' : 'Adjust Rate';
				}
				const actionLabel = mode === 'create'
					? 'Create payroll rate configuration'
					: `Update payroll rate ${data.code ? String(data.code).toUpperCase() : ''}`.trim();
				form.setAttribute('data-authz-action', actionLabel);
				isNewInput.value = mode === 'create' ? '1' : '0';
				categorySelect.value = data.category || '';
				codeInput.value = data.code || '';
				codeInput.readOnly = mode !== 'create';
				codeInput.classList.toggle('bg-gray-100', codeInput.readOnly);
				labelInput.value = data.label || '';
				defaultInput.value = data.default || '';
				overrideInput && (overrideInput.value = data.override || '');
				startInput.value = data.start || '';
				endInput && (endInput.value = data.end || '');
				notesInput && (notesInput.value = '');
				if (!startInput.value) {
					startInput.value = data.nextStart || todayIso;
				}
				if (mode === 'create' && categorySelect) {
					categorySelect.focus();
				}
				openModal();
			}

			function attachButtons(scope){
				scope.querySelectorAll('.rate-adjust').forEach(btn => {
					if (btn.dataset.bound === '1') return;
					btn.dataset.bound = '1';
					btn.addEventListener('click', () => {
						populateModal({
							category: btn.dataset.category,
							code: btn.dataset.code,
							label: btn.dataset.label,
							default: btn.dataset.default,
							override: btn.dataset.override,
							start: btn.dataset.start,
							end: btn.dataset.end,
							nextStart: btn.dataset.nextStart
						}, 'update');
					});
				});
				scope.querySelectorAll('.rate-reuse').forEach(btn => {
					if (btn.dataset.bound === '1') return;
					btn.dataset.bound = '1';
					btn.addEventListener('click', () => {
						populateModal({
							category: btn.dataset.category,
							code: btn.dataset.code,
							label: btn.dataset.label,
							default: btn.dataset.default,
							override: btn.dataset.override,
							start: btn.dataset.nextStart || todayIso,
							end: '',
							nextStart: btn.dataset.nextStart || todayIso
						}, 'update');
					});
				});
			}

			attachButtons(document);

			if (addBtn && addBtn.dataset.bound !== '1') {
				addBtn.dataset.bound = '1';
				addBtn.addEventListener('click', () => {
					populateModal({ category: '', code: '', label: '', default: '', override: '', start: todayIso, end: '', nextStart: todayIso }, 'create');
				});
			}
		}
		document.addEventListener('DOMContentLoaded', bindSystemConfig);
		document.addEventListener('spa:loaded', bindSystemConfig);
	})();

// Payroll: batch job status polling
(function(){
	function pollJobs(scope=document){
		const jobEls = scope.querySelectorAll('[data-job-id]');
		if (!jobEls || jobEls.length === 0) return;
		const base = (window.__baseUrl || '').replace(/\/$/, '');
		const origin = window.location.origin || '';
		const tick = async () => {
			for (const el of jobEls) {
				const jobId = el.getAttribute('data-job-id');
				if (!jobId) continue;
				try {
					const res = await fetch(origin + base + '/modules/payroll/batch_job_status?job_id=' + encodeURIComponent(jobId), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
					if (!res.ok) return;
					const data = await res.json();
					if (data && data.ok && data.job) {
						const s = String(data.job.status || '').toLowerCase();
						let label = s || '—';
						if (s === 'queued') label = 'Queued';
						if (s === 'running') label = 'Running… ' + (data.job.progress || 0) + '%';
						if (s === 'completed') label = 'Completed';
						if (s === 'failed') label = 'Failed';
						el.textContent = label;
					}
				} catch(e){ if (window.__DEBUG_APP) console.error('[BatchPoll]', e); }
			}
		};
		tick();
		clearInterval(window.__jobPollTimer);
		window.__jobPollTimer = setInterval(tick, 5000);
	}
	document.addEventListener('DOMContentLoaded', () => pollJobs(document));
	document.addEventListener('spa:loaded', () => pollJobs(document));
})();
