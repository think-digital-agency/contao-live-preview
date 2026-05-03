/**
 * Contao Live Preview – vanilla JS, no build step.
 *
 * URL disambiguation (verified against real Contao 5 backend URLs):
 *
 *   ?do=article&id=X&table=tl_content&act=edit  → id = content element (tl_content)
 *   ?do=article&id=X&table=tl_content           → id = article        (tl_article, content list view)
 *   ?do=article&id=X&table=tl_article&act=edit  → id = article        (tl_article)
 *   ?do=article&id=X                            → id = article        (tl_article)
 *   ?do=page&id=X                               → id = page           (tl_page)
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

    let sidebar, frame, urlDisplay, openTabBtn, resizer;
    let currentContext = null;
    let resolveTimer   = null;
    let isOpen         = false;

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', () => {
        sidebar    = document.getElementById('clp-right');
        frame      = document.getElementById('clp-frame');
        urlDisplay = document.getElementById('clp-url-display');
        resizer    = document.getElementById('clp-resizer');

        if (!sidebar || !frame) return;

        injectToggleButton();
        restoreState();
        bindResizer();
        observeNavigation();
        document.addEventListener('submit', handleFormSubmit);
        observeSaveFlash();

        triggerResolve();
    });

    // -------------------------------------------------------------------------
    // Header toggle button
    // Appends a <li> into #tmenu (the top-right Contao nav) before the burger.
    // -------------------------------------------------------------------------

    function injectToggleButton() {
        const tmenu = document.getElementById('tmenu');
        if (!tmenu) return;

        const li = document.createElement('li');
        li.id = 'clp-toggle-item';

        const btn = document.createElement('button');
        btn.id = 'clp-toggle-btn';
        btn.type = 'button';
        btn.title = 'Live Preview';
        btn.setAttribute('aria-label', 'Live Preview umschalten');
        // Contao uses inline SVG icons in the theme; we do the same.
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><span>Preview</span>';
        btn.addEventListener('click', toggleSidebar);

        li.appendChild(btn);

        // Insert before the last <li class="burger"> if present, else append.
        const burger = tmenu.querySelector('li.burger');
        if (burger) {
            tmenu.insertBefore(li, burger);
        } else {
            tmenu.appendChild(li);
        }

        openTabBtn = document.getElementById('clp-open-tab');
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    function restoreState() {
        const saved = localStorage.getItem(LS_OPEN_KEY);
        // Default open on wide screens, closed on narrow
        isOpen = saved !== null ? saved === '1' : window.innerWidth >= 1400;

        const savedWidth = parseInt(localStorage.getItem(LS_WIDTH_KEY) || '0', 10);
        if (savedWidth >= MIN_WIDTH && savedWidth <= MAX_WIDTH) {
            sidebar.style.setProperty('--clp-width', savedWidth + 'px');
        }

        applyState();
    }

    function applyState() {
        document.body.classList.toggle('clp-open', isOpen);
        localStorage.setItem(LS_OPEN_KEY, isOpen ? '1' : '0');

        const btn = document.getElementById('clp-toggle-btn');
        if (btn) {
            btn.classList.toggle('active', isOpen);
            btn.title = isOpen ? 'Live Preview schließen' : 'Live Preview öffnen';
        }
    }

    function toggleSidebar() {
        isOpen = !isOpen;
        applyState();
        if (isOpen && currentContext && !frame.src) {
            triggerResolve();
        }
    }

    // -------------------------------------------------------------------------
    // Context detection
    // -------------------------------------------------------------------------

    function parseContext() {
        const p    = new URLSearchParams(window.location.search);
        const doV  = p.get('do') || '';
        const tbl  = p.get('table') || '';
        const id   = parseInt(p.get('id') || '0', 10);
        const act  = p.get('act') || '';

        if (id <= 0) return null;

        // Content element: only when table=tl_content AND act=edit
        if (tbl === 'tl_content' && act === 'edit') {
            return { table: 'tl_content', id };
        }

        // Content-element list view: table=tl_content but no act → id is the ARTICLE
        if (doV === 'article' && tbl === 'tl_content' && act === '') {
            return { table: 'tl_article', id };
        }

        // Article (edit or any other act, or tl_article table explicit)
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
        return ctx ? ctx.table + ':' + ctx.id : 'null';
    }

    // -------------------------------------------------------------------------
    // Navigation observation
    // -------------------------------------------------------------------------

    function observeNavigation() {
        const title = document.querySelector('head title');
        if (title) {
            new MutationObserver(scheduleResolve).observe(title, { childList: true });
        }
        window.addEventListener('popstate', scheduleResolve);
    }

    function scheduleResolve() {
        clearTimeout(resolveTimer);
        resolveTimer = setTimeout(triggerResolve, RESOLVE_DEBOUNCE);
    }

    // -------------------------------------------------------------------------
    // Resolve → iframe
    // -------------------------------------------------------------------------

    async function triggerResolve() {
        const ctx = parseContext();

        if (contextKey(ctx) === contextKey(currentContext)) return;
        currentContext = ctx;

        if (!ctx) {
            clearFrame();
            return;
        }

        if (!isOpen) return; // resolve later when user opens sidebar

        await resolveAndShow(ctx);
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
                showUrl(data.previewUrl);
                frame.src = data.previewUrl;
            } else {
                clearFrame();
            }
        } catch {
            clearFrame();
        }
    }

    function showUrl(url) {
        if (urlDisplay) urlDisplay.textContent = url;
        const btn = document.getElementById('clp-open-tab');
        if (btn) {
            btn.onclick = () => window.open(url, '_blank', 'noopener');
            btn.disabled = false;
        }
    }

    function clearFrame() {
        frame.src = '';
        if (urlDisplay) urlDisplay.textContent = '';
        const btn = document.getElementById('clp-open-tab');
        if (btn) btn.disabled = true;
    }

    function reloadPreview() {
        if (!frame.src) return;
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
        setTimeout(reloadPreview, 900);
    }

    function observeSaveFlash() {
        new MutationObserver((mutations) => {
            for (const m of mutations) {
                for (const node of m.addedNodes) {
                    if (node.nodeType !== 1) continue;
                    if (node.classList?.contains('tl_confirm') || node.querySelector?.('.tl_confirm')) {
                        scheduleResolve();
                        setTimeout(reloadPreview, 400);
                        return;
                    }
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    // -------------------------------------------------------------------------
    // Resize handle
    // -------------------------------------------------------------------------

    function bindResizer() {
        if (!resizer) return;
        let startX, startW;

        resizer.addEventListener('mousedown', (e) => {
            startX = e.clientX;
            startW = sidebar.getBoundingClientRect().width;
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp, { once: true });
            e.preventDefault();
        });

        function onMove(e) {
            const w = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startW + (startX - e.clientX)));
            sidebar.style.setProperty('--clp-width', w + 'px');
            localStorage.setItem(LS_WIDTH_KEY, String(w));
        }

        function onUp() {
            document.removeEventListener('mousemove', onMove);
        }
    }

})();
