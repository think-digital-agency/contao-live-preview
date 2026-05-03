/**
 * Contao Live Preview – vanilla JS, no build step.
 *
 * Contao 5 uses @hotwired/turbo for backend navigation. Turbo replaces
 * <body> on every navigation, which means:
 *   - DOMContentLoaded fires only on the first hard load
 *   - turbo:render fires after EVERY navigation (body swap)
 *   - All DOM references must be re-acquired after each turbo:render
 *   - Global event listeners (on document/window) survive body swap and
 *     must NOT be re-added on every render
 *
 * URL disambiguation (verified against real Contao 5.7 backend URLs):
 *   ?do=article&table=tl_content&act=edit&id=X  → id = content element
 *   ?do=article&table=tl_content&id=X           → id = article (list view)
 *   ?do=article&table=tl_article&act=edit&id=X  → id = article
 *   ?do=article&id=X                            → id = article
 *   ?do=page&id=X                               → id = page
 */

(function () {
    'use strict';

    const RESOLVE_ENDPOINT = '/contao/live-preview/resolve';
    const LS_OPEN_KEY      = 'clp_sidebar_open';
    const LS_WIDTH_KEY     = 'clp_sidebar_width';
    const DEFAULT_WIDTH    = 420;
    const MIN_WIDTH        = 280;
    const MAX_WIDTH        = 800;
    const RESOLVE_DEBOUNCE = 250;

    // -------------------------------------------------------------------------
    // Per-page DOM references (re-acquired after every turbo:render)
    // -------------------------------------------------------------------------
    let sidebar, frame, urlDisplay;

    // -------------------------------------------------------------------------
    // Persistent state (survives turbo navigation)
    // -------------------------------------------------------------------------
    let isOpen         = false;
    let currentContext = null;
    let resolveTimer   = null;

    // Track whether global (document/window) listeners are already attached —
    // these survive turbo:render so we only add them once.
    let globalListenersBound = false;

    // Observer instances that must be re-created after body swap
    let saveFlashObserver = null;

    // -------------------------------------------------------------------------
    // Entry points
    // -------------------------------------------------------------------------

    // DOMContentLoaded: fires once on hard load
    document.addEventListener('DOMContentLoaded', onPageReady);

    // turbo:render: fires after every Turbo body swap (navigation)
    // This fires AFTER the new body is in the DOM, so DOM refs are safe here.
    document.addEventListener('turbo:render', onPageReady);

    function onPageReady() {
        sidebar    = document.getElementById('clp-right');
        frame      = document.getElementById('clp-frame');
        urlDisplay = document.getElementById('clp-url-display');

        if (!sidebar || !frame) return;

        // Restore open state from localStorage — applies clp-open to the new body
        const saved = localStorage.getItem(LS_OPEN_KEY);
        isOpen = saved !== null ? saved === '1' : window.innerWidth >= 1400;
        applyState(false); // false = don't re-resolve, we'll do that below

        injectToggleButton();
        bindResizer();

        if (!globalListenersBound) {
            globalListenersBound = true;
            document.addEventListener('submit', handleFormSubmit);
        }

        // Re-attach body-level observer (body was replaced)
        observeSaveFlash();

        // Reset context so the new URL gets resolved (context from prev page is stale)
        currentContext = null;
        triggerResolve();
    }

    // -------------------------------------------------------------------------
    // Toggle button injected into #tmenu
    // -------------------------------------------------------------------------

    function injectToggleButton() {
        // Remove any leftover from previous render
        const existing = document.getElementById('clp-toggle-item');
        if (existing) existing.remove();

        const tmenu = document.getElementById('tmenu');
        if (!tmenu) return;

        const li = document.createElement('li');
        li.id = 'clp-toggle-item';

        const btn = document.createElement('button');
        btn.id   = 'clp-toggle-btn';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Live Preview');
        btn.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
            + '</svg>'
            + '<span>Preview</span>';
        btn.addEventListener('click', toggleSidebar);

        li.appendChild(btn);

        // Insert before the burger menu item
        const burger = tmenu.querySelector('li.burger');
        burger ? tmenu.insertBefore(li, burger) : tmenu.appendChild(li);

        // Reflect current state on the fresh button
        btn.classList.toggle('active', isOpen);
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    function applyState(andResolve = true) {
        document.body.classList.toggle('clp-open', isOpen);
        localStorage.setItem(LS_OPEN_KEY, isOpen ? '1' : '0');

        const btn = document.getElementById('clp-toggle-btn');
        if (btn) btn.classList.toggle('active', isOpen);

        if (andResolve && isOpen && currentContext && !frame.src) {
            resolveAndShow(currentContext);
        }
    }

    function toggleSidebar() {
        isOpen = !isOpen;
        applyState(true);
    }

    // -------------------------------------------------------------------------
    // Context detection
    // -------------------------------------------------------------------------

    function parseContext() {
        const p   = new URLSearchParams(window.location.search);
        const doV = p.get('do') || '';
        const tbl = p.get('table') || '';
        const id  = parseInt(p.get('id') || '0', 10);
        const act = p.get('act') || '';

        if (id <= 0) return null;

        // Content element only when act=edit + table=tl_content
        if (tbl === 'tl_content' && act === 'edit') {
            return { table: 'tl_content', id };
        }

        // Content list view: table=tl_content, no act → id is the ARTICLE
        if (doV === 'article' && tbl === 'tl_content' && !act) {
            return { table: 'tl_article', id };
        }

        // Article (direct edit, or table=tl_article)
        if (doV === 'article' && id > 0) {
            return { table: 'tl_article', id };
        }

        // Page
        if (doV === 'page' && id > 0) {
            return { table: 'tl_page', id };
        }

        return null;
    }

    function contextKey(ctx) {
        return ctx ? ctx.table + ':' + ctx.id : '';
    }

    // -------------------------------------------------------------------------
    // Resolve + iframe update
    // -------------------------------------------------------------------------

    function triggerResolve() {
        clearTimeout(resolveTimer);
        resolveTimer = setTimeout(() => {
            const ctx = parseContext();
            if (contextKey(ctx) === contextKey(currentContext)) return;
            currentContext = ctx;
            if (!ctx) { clearFrame(); return; }
            if (!isOpen) return;
            resolveAndShow(ctx);
        }, RESOLVE_DEBOUNCE);
    }

    async function resolveAndShow(ctx) {
        const params = new URLSearchParams({ table: ctx.table, id: String(ctx.id) });

        try {
            const res = await fetch(RESOLVE_ENDPOINT + '?' + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) { clearFrame(); return; }

            const data = await res.json();

            if (data.previewUrl) {
                if (urlDisplay) urlDisplay.textContent = data.previewUrl;
                const openBtn = document.getElementById('clp-open-tab');
                if (openBtn) {
                    openBtn.disabled = false;
                    openBtn.onclick = () => window.open(data.previewUrl, '_blank', 'noopener');
                }
                // Only update iframe if URL actually changed
                if (frame.src !== data.previewUrl) {
                    frame.src = data.previewUrl;
                }
            } else {
                clearFrame();
            }
        } catch {
            clearFrame();
        }
    }

    function clearFrame() {
        if (frame) frame.src = '';
        if (urlDisplay) urlDisplay.textContent = '';
        const openBtn = document.getElementById('clp-open-tab');
        if (openBtn) openBtn.disabled = true;
    }

    function reloadPreview() {
        if (!frame || !frame.src) return;
        try {
            frame.contentWindow.location.reload();
        } catch {
            const src = frame.src;
            frame.src = '';
            frame.src = src;
        }
    }

    // -------------------------------------------------------------------------
    // Save detection
    // -------------------------------------------------------------------------

    function handleFormSubmit(e) {
        if (!e.target.querySelector?.('[name="FORM_SUBMIT"]')) return;
        // Context stays the same after save, just reload the preview
        setTimeout(reloadPreview, 900);
    }

    function observeSaveFlash() {
        if (saveFlashObserver) saveFlashObserver.disconnect();

        saveFlashObserver = new MutationObserver((mutations) => {
            for (const m of mutations) {
                for (const node of m.addedNodes) {
                    if (node.nodeType !== 1) continue;
                    if (node.classList?.contains('tl_confirm') || node.querySelector?.('.tl_confirm')) {
                        currentContext = null; // force re-resolve after redirect
                        setTimeout(reloadPreview, 400);
                        return;
                    }
                }
            }
        });

        saveFlashObserver.observe(document.body, { childList: true, subtree: true });
    }

    // -------------------------------------------------------------------------
    // Resize handle
    // -------------------------------------------------------------------------

    function bindResizer() {
        const el = document.getElementById('clp-resizer');
        if (!el) return;

        let startX = 0, startW = 0;

        el.addEventListener('mousedown', (e) => {
            // Read current width from CSS variable (more reliable than bounding rect
            // which can differ from flex-basis in edge cases)
            const cssVal = sidebar.style.getPropertyValue('--clp-width')
                        || getComputedStyle(sidebar).getPropertyValue('--clp-width')
                        || String(DEFAULT_WIDTH);
            startW = parseInt(cssVal, 10) || DEFAULT_WIDTH;
            startX = e.clientX;

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp, { once: true });
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });

        function onMove(e) {
            // Resizer is on the LEFT edge of the right sidebar.
            // Dragging LEFT (smaller clientX) = wider sidebar.
            const delta = startX - e.clientX;
            const w = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startW + delta));
            sidebar.style.setProperty('--clp-width', w + 'px');
            localStorage.setItem(LS_WIDTH_KEY, String(w));
        }

        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.body.style.userSelect = '';
        }
    }

    // Restore saved width (called once via CSS, but also here for JS-side width tracking)
    (function restoreWidth() {
        const w = parseInt(localStorage.getItem(LS_WIDTH_KEY) || '0', 10);
        if (w >= MIN_WIDTH && w <= MAX_WIDTH) {
            // Will be picked up by onPageReady → bindResizer's first call
            // We pre-set it here so it's available before the sidebar element exists
            document.documentElement.style.setProperty('--clp-saved-width', w + 'px');
        }
    })();

})();
