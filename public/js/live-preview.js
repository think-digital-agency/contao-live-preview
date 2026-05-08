/**
 * Contao Live Preview – vanilla JS, no build step.
 *
 * Contao 5 uses @hotwired/turbo for backend navigation. Turbo replaces
 * <body> on every navigation, which means:
 *   - DOMContentLoaded fires only on the first hard load
 *   - turbo:render fires after EVERY navigation (body swap)
 *   - Global event listeners (on document/window) survive body swap and
 *     must NOT be re-added on every render
 *
 * #clp-right carries data-turbo-permanent so Turbo moves it (with its live
 * iframe) to every new body — the preview never blanks during navigation.
 *
 * turbo:before-render pre-applies body.clp-open to the *incoming* body so
 * the sidebar is never momentarily display:none during the swap.
 *
 * Save → backend navigation → two possible paths:
 *   A. Turbo body-swap (normal): iframe survives via data-turbo-permanent.
 *      refreshPreview() fires at 900ms via the form-submit timer.
 *   B. Full page reload (e.g. after deployment, when data-turbo-track="reload"
 *      assets change): iframe is recreated empty. The rehydration system
 *      (localStorage clp_pending_save) restores the state after reload.
 *
 * URL disambiguation (verified against real Contao 5.7 backend URLs):
 *   ?do=article&table=tl_content&act=edit&id=X  → id = content element
 *   ?do=article&table=tl_content&id=X           → id = article (list view)
 *   ?do=article&table=tl_article&act=edit&id=X  → id = article
 *   ?do=article&id=X                            → id = article
 *   ?do=page&id=X                               → id = page
 *   ?do=themes&table=tl_module&act=edit&id=X    → id = frontend module
 */

(function () {
    'use strict';

    const RESOLVE_ENDPOINT = '/contao/live-preview/resolve';
    const UNRESOLVED       = {};          // sentinel — distinct from null ("showing fallback")
    const LS_OPEN_KEY      = 'clp_sidebar_open';
    const LS_WIDTH_KEY     = 'clp_sidebar_width';
    const LS_SAVE_KEY      = 'clp_pending_save';
    const LS_ZOOM_KEY      = 'clp_zoom';
    const DEFAULT_WIDTH    = 420;
    const MIN_WIDTH        = 280;
    const RESOLVE_DEBOUNCE = 250;
    const SAVE_STATE_TTL   = 30_000; // ms — discard stale state after 30 s

    // -------------------------------------------------------------------------
    // DOM references — acquired once; survive Turbo nav via data-turbo-permanent
    // -------------------------------------------------------------------------
    let sidebar, frame, frameWrap, urlDisplay, vwInput, vhInput, zoomSelect;

    // -------------------------------------------------------------------------
    // Persistent state
    // -------------------------------------------------------------------------
    let isOpen         = false;
    let currentContext = UNRESOLVED;
    let resolveTimer   = null;

    // true only when the iframe already shows the correct URL and a partial
    // refresh is needed (same-URL save). false when frame.src was just updated
    // (fresh navigation in progress — refreshPreview must not interrupt it).
    let frameNeedsReload = false;

    // Ordered list of CSS selectors to try for scroll+highlight after each load.
    // JS picks the first one that matches a DOM element.
    // Empty array when context is tl_page (no article to highlight).
    let highlightSelectors = [];

    // Article-level selectors — always target the article wrapper.
    // Used for clp:refresh DOM swap and as the secondary highlight target
    // (outline + badge) when the primary highlight targets a content element.
    let articleSelectors = [];

    // Contao type key of the currently selected content element (e.g. 'text', 'image').
    // null when context is tl_article or tl_page.
    let currentContentElementType  = null;
    // Human-readable DCA label for the content element type (e.g. 'Icon Liste').
    // Falls back to the raw type key when no label is available.
    let currentContentElementLabel = null;

    // Numeric article ID resolved for the current context. Used to target the
    // correct DOM node for partial refresh without a full iframe reload.
    // null when context is tl_page (no article).
    let currentArticleId    = null;
    let currentArticleTitle = null;

    // 'smooth' for article context (user navigated to a different article — animate the scroll).
    // 'instant' for content-element context and after saves (position barely changes — no animation).
    let scrollBehavior = 'smooth';

    let globalListenersBound = false;
    let refreshTimer         = null;
    // True after the first clp:refresh fires for the current page load.
    // Prevents a second DOM swap + highlight from a late observer or late resolveAndShow.
    // Reset to false on every onPageReady so the next navigation-only highlight works.
    let refreshSent          = false;

    // -------------------------------------------------------------------------
    // Entry points
    // -------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', onPageReady);
    document.addEventListener('turbo:render', onPageReady);

    function onPageReady() {
        // #clp-right is permanent — acquire refs only on the very first call.
        if (!sidebar)    sidebar    = document.getElementById('clp-right');
        if (!frame)      frame      = document.getElementById('clp-frame');
        if (!frameWrap)  frameWrap  = document.getElementById('clp-frame-wrap');
        if (!urlDisplay) urlDisplay = document.getElementById('clp-url-display');
        if (!vwInput)    vwInput    = document.getElementById('clp-vw');
        if (!vhInput)    vhInput    = document.getElementById('clp-vh');
        if (!zoomSelect) zoomSelect = document.getElementById('clp-zoom');

        if (!sidebar || !frame) return;

        // Each navigation is a fresh highlight cycle — allow one highlight per page.
        refreshSent = false;

        // Rehydration must run before isOpen is stamped and before triggerResolve,
        // because it may set frame.src and pre-populate currentArticleId.
        tryRehydrate();

        // Sync isOpen from localStorage and stamp the current body.
        // turbo:before-render already stamped the incoming body, so this is a
        // no-op in the normal navigation case but handles hard reload correctly.
        const saved = localStorage.getItem(LS_OPEN_KEY);
        isOpen = saved !== null ? saved === '1' : window.innerWidth >= 1400;
        document.body.classList.toggle('clp-open', isOpen);

        // #tmenu is in the replaced body — re-inject the toggle button every nav.
        injectToggleButton();

        if (!globalListenersBound) {
            globalListenersBound = true;

            // Pre-apply clp-open to the *incoming* body before Turbo swaps it in.
            // Without this, body.clp-open is absent for one paint → sidebar flash.
            document.addEventListener('turbo:before-render', (e) => {
                e.detail.newBody.classList.toggle('clp-open', isOpen);
            });

            document.addEventListener('submit', handleFormSubmit);

            // clp:refreshed from the iframe confirms the DOM swap completed.
            // clp:edit opens the article or content element in the backend editor.
            window.addEventListener('message', (e) => {
                if (e.data?.type === 'clp:refreshed') {
                    localStorage.removeItem(LS_SAVE_KEY);
                    return;
                }
                if (e.data?.type === 'clp:edit') {
                    const { table, id } = e.data;
                    if (!table || !id) return;
                    let params;
                    if (table === 'tl_content') {
                        params = new URLSearchParams({ do: 'article', table: 'tl_content', act: 'edit', id: String(id) });
                    } else if (table === 'tl_article') {
                        params = new URLSearchParams({ do: 'article', table: 'tl_content', id: String(id) });
                    } else if (table === 'tl_module') {
                        params = new URLSearchParams({ do: 'themes', table: 'tl_module', act: 'edit', id: String(id) });
                    } else return;
                    const url = window.location.pathname + '?' + params.toString();
                    if (window.Turbo) { Turbo.visit(url); } else { window.location.href = url; }
                }
            });

            // Re-clamp sidebar width whenever the browser window is resized.
            // Uses the last persisted normal-mode width as the target; overlay
            // mode ignores that and applies its own fixed width.
            window.addEventListener('resize', () => {
                if (!isOpen) return;
                const savedW = parseInt(
                    localStorage.getItem(LS_WIDTH_KEY) || String(DEFAULT_WIDTH), 10,
                ) || DEFAULT_WIDTH;
                setSidebarWidth(savedW);
            });

            // Resizer lives inside the permanent sidebar — bind event listeners once.
            bindResizer();
            initViewportControls();
        }

        // Context from the previous page is stale — resolve for the new URL.
        currentContext = UNRESOLVED;
        triggerResolve();
    }

    // -------------------------------------------------------------------------
    // Toggle button injected into #tmenu
    // -------------------------------------------------------------------------

    function injectToggleButton() {
        const existing = document.getElementById('clp-toggle-item');
        if (existing) existing.remove();

        const tmenu = document.getElementById('tmenu');
        if (!tmenu) return;

        const li = document.createElement('li');
        li.id = 'clp-toggle-item';

        const btn = document.createElement('button');
        btn.id   = 'clp-toggle-btn';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Live-Preview');
        btn.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'
            + '</svg>'
            + '<span>Live-Preview</span>';
        btn.addEventListener('click', toggleSidebar);

        li.appendChild(btn);

        const burger = tmenu.querySelector('li.burger');
        burger ? tmenu.insertBefore(li, burger) : tmenu.appendChild(li);

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

        // When opening, always resolve — the iframe may show a stale page if the
        // user navigated while the sidebar was closed.
        if (andResolve && isOpen && currentContext !== UNRESOLVED) {
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

        if (tbl === 'tl_content' && act === 'edit') {
            return { table: 'tl_content', id };
        }

        if (doV === 'article' && tbl === 'tl_content' && !act) {
            return { table: 'tl_article', id };
        }

        if (doV === 'article' && id > 0) {
            return { table: 'tl_article', id };
        }

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
            // currentContext starts as UNRESOLVED (never equal to any ctx key) so
            // the first resolve after every navigation always proceeds.
            // null means "showing fallback" — deduped like any other context.
            if (currentContext !== UNRESOLVED && contextKey(ctx) === contextKey(currentContext)) return;
            currentContext = ctx;
            if (!isOpen) return;
            resolveAndShow(ctx); // null → fallback (root page, no highlights)
        }, RESOLVE_DEBOUNCE);
    }

    // Returns the iframe's current canonical URL with internal params stripped.
    // Used to compare against the resolved preview URL without false positives.
    function getCleanSrc() {
        if (!frame || !frame.src) return '';
        try {
            const u = new URL(frame.src);
            u.searchParams.delete('_clp');
            return u.toString();
        } catch {
            return frame.src;
        }
    }

    // Appends ?_clp=1 to a URL so InjectPreviewScriptListener injects the
    // postMessage listener into the frontend page response.
    function addClpParam(url) {
        try {
            const u = new URL(url);
            u.searchParams.set('_clp', '1');
            return u.toString();
        } catch {
            return url;
        }
    }

    function sendHighlight(behavior) {
        if (!highlightSelectors.length || !frame?.contentWindow) return;
        // When called as a load event listener, behavior is an Event object — ignore it.
        const b = (behavior === 'instant' || behavior === 'smooth') ? behavior : scrollBehavior;
        const isCe = currentContext?.table === 'tl_content';
        // CE label: DCA label or raw type, always uppercase (e.g. "ICON LISTE", "TEXT")
        const ceLabel = isCe
            ? (currentContentElementLabel || currentContentElementType || '').toUpperCase()
            : '';
        // Article label: always just "ARTIKEL"
        const articleLabel = 'ARTIKEL';
        try {
            frame.contentWindow.postMessage({
                type:             'clp:highlight',
                selectors:        highlightSelectors,
                articleSelectors,
                scrollBehavior:   b,
                label:            isCe ? ceLabel : articleLabel,
                articleLabel,
                articleId:        currentArticleId,
                contentElementId: isCe ? currentContext?.id : null,
            }, '*');
        } catch { }
    }

    async function resolveAndShow(ctx) {
        const params = ctx
            ? new URLSearchParams({ table: ctx.table, id: String(ctx.id) })
            : new URLSearchParams();

        try {
            const res = await fetch(RESOLVE_ENDPOINT + '?' + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) return;

            const data = await res.json();

            if (data.previewUrl) {
                highlightSelectors        = data.highlightSelectors  || [];
                articleSelectors          = data.articleSelectors    || [];
                currentArticleId          = data.articleId           || null;
                currentArticleTitle       = data.articleTitle        || null;
                currentContentElementType  = data.contentElementType  || null;
                currentContentElementLabel = data.contentElementLabel || null;
                // Smooth scroll when navigating to a different article; instant when
                // switching between content elements (position barely changes).
                scrollBehavior = ctx?.table === 'tl_content' ? 'instant' : 'smooth';

                if (urlDisplay) urlDisplay.textContent = data.previewUrl;
                const openBtn = document.getElementById('clp-open-tab');
                if (openBtn) {
                    openBtn.disabled = false;
                    openBtn.onclick = () => window.open(data.previewUrl, '_blank', 'noopener');
                }

                if (getCleanSrc() !== data.previewUrl) {
                    // URL changed → navigate to new page; refreshPreview must not interrupt.
                    frame.src = addClpParam(data.previewUrl);
                    frameNeedsReload = false;
                    frame.addEventListener('load', () => {
                        // Page is now loaded — subsequent saves can trigger a partial refresh.
                        frameNeedsReload = true;
                        sendHighlight();
                    }, { once: true });
                } else {
                    // Same URL — content may be stale after a save; allow refreshPreview.
                    frameNeedsReload = true;
                    // Send highlight only for navigation-only (no pending save).
                    // If a save is pending, clp:refresh (from tryRehydrate) handles the
                    // highlight. Check localStorage directly — more reliable than refreshTimer
                    // which may have already fired by the time this async fn returns.
                    if (!localStorage.getItem(LS_SAVE_KEY) && !refreshSent) sendHighlight();
                }
            }
        } catch {
            // Network/parse error — keep existing iframe content.
        }
    }

    function clearFrame() {
        if (frame) frame.src = '';
        frameNeedsReload = false;
        if (urlDisplay) urlDisplay.textContent = '';
        const openBtn = document.getElementById('clp-open-tab');
        if (openBtn) openBtn.disabled = true;
    }

    // Schedules exactly one refreshPreview call, cancelling any pending timer.
    // Both handleFormSubmit and observeSaveFlash go through here so they can't
    // pile up independent timers that each trigger a DOM swap + highlight.
    function scheduleRefresh(delay) {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(() => { refreshTimer = null; refreshPreview(); }, delay);
    }

    function refreshPreview() {
        if (!frameNeedsReload || !currentArticleId || refreshSent) return;
        refreshSent      = true;
        frameNeedsReload = false;

        // Send clp:refresh to the iframe. The injected frontend script will:
        //   1. fetch(window.location.href) — same-origin, no CORS
        //   2. DOMParser extracts the article node from the response
        //   3. Replaces the live DOM node (scroll position stays intact)
        //   4. Applies highlight, then posts clp:refreshed back
        try {
            frame.contentWindow.postMessage({
                type:      'clp:refresh',
                articleId: currentArticleId,
                selectors: articleSelectors.length ? articleSelectors : highlightSelectors,
                label:     'ARTIKEL',
            }, '*');
        } catch { }
    }

    // -------------------------------------------------------------------------
    // Rehydration — save/restore state across backend navigations
    //
    // Contao 5 normally uses Turbo body-swap after saves, so #clp-right
    // (data-turbo-permanent) survives and the iframe keeps its content.
    // However, if data-turbo-track="reload" assets change (e.g. after a
    // deployment), Turbo triggers a full page reload — the iframe is destroyed.
    //
    // Before every save we write state to localStorage. On the next onPageReady:
    //   • iframe has src  → body-swap path: pre-set flags so the 900ms
    //                       refreshPreview timer fires even when resolve is slow
    //   • iframe is empty → full-reload path: load the saved URL, restore scroll,
    //                       re-highlight immediately
    // -------------------------------------------------------------------------

    function savePendingState() {
        if (!currentArticleId || !getCleanSrc()) return; // nothing worth saving

        let scrollX = 0, scrollY = 0;
        try { scrollX = frame.contentWindow.scrollX || 0; } catch { }
        try { scrollY = frame.contentWindow.scrollY || 0; } catch { }

        try {
            localStorage.setItem(LS_SAVE_KEY, JSON.stringify({
                articleId:             currentArticleId,
                label:                 currentArticleTitle || '',
                contentElementType:    currentContentElementType,
                contentElementLabel:   currentContentElementLabel,
                iframeUrl:             getCleanSrc(),
                selectors:            highlightSelectors,
                articleSelectors,
                scrollX,
                scrollY,
                ts: Date.now(),
            }));
        } catch { }
    }

    function tryRehydrate() {
        let state;
        try {
            const raw = localStorage.getItem(LS_SAVE_KEY);
            if (!raw) return;
            state = JSON.parse(raw);
        } catch {
            localStorage.removeItem(LS_SAVE_KEY);
            return;
        }

        if (!state || Date.now() - state.ts > SAVE_STATE_TTL) {
            localStorage.removeItem(LS_SAVE_KEY);
            return;
        }

        const iframeLoaded = !!getCleanSrc(); // false when iframe was recreated fresh

        if (iframeLoaded) {
            // Body-swap path: iframe survived. Clear state NOW so further navigations
            // (e.g. Contao redirect chain after "Save and Close") don't re-trigger.
            // clp:refreshed will call removeItem again — that's a harmless no-op.
            localStorage.removeItem(LS_SAVE_KEY);
            if (state.articleId)                        currentArticleId          = state.articleId;
            if (state.label !== undefined)              currentArticleTitle       = state.label || null;
            if (state.contentElementType !== undefined)  currentContentElementType  = state.contentElementType  || null;
            if (state.contentElementLabel !== undefined)  currentContentElementLabel = state.contentElementLabel || null;
            if (state.selectors?.length)                highlightSelectors        = state.selectors;
            if (state.articleSelectors?.length)         articleSelectors          = state.articleSelectors;
            frameNeedsReload = true;
            scheduleRefresh(250);
            return;
        }

        // Full-reload path: iframe is empty. Load the saved URL, then restore.
        localStorage.removeItem(LS_SAVE_KEY);

        if (!state.iframeUrl) return;

        currentArticleId          = state.articleId           || null;
        currentArticleTitle       = state.label               || null;
        currentContentElementType  = state.contentElementType  || null;
        currentContentElementLabel = state.contentElementLabel || null;
        highlightSelectors        = state.selectors           || [];
        articleSelectors          = state.articleSelectors    || [];
        frameNeedsReload   = false; // will be set true on load

        frame.src = addClpParam(state.iframeUrl);
        frame.addEventListener('load', () => {
            frameNeedsReload = true;
            if (state.scrollX || state.scrollY) {
                try {
                    frame.contentWindow.scrollTo({
                        top:      state.scrollY,
                        left:     state.scrollX,
                        behavior: 'instant',
                    });
                } catch { }
            }
            sendHighlight('instant');
        }, { once: true });
    }

    // -------------------------------------------------------------------------
    // Save detection
    // -------------------------------------------------------------------------

    function handleFormSubmit(e) {
        if (!e.target.querySelector?.('[name="FORM_SUBMIT"]')) return;
        // Write state to localStorage. The refresh is driven entirely from
        // tryRehydrate() on the next onPageReady — no timer needed here.
        savePendingState();
    }

    // -------------------------------------------------------------------------
    // Viewport controls — width input, height display, zoom select
    // Bound once (sidebar is data-turbo-permanent).
    // -------------------------------------------------------------------------

    function isOverlayMode() {
        return window.innerWidth <= 1200;
    }

    // Sets sidebar width — shared by the resizer drag, the W input, and the
    // window-resize handler. Handles both normal and overlay (≤1200px) modes.
    function setSidebarWidth(w) {
        let finalW;

        if (isOverlayMode()) {
            // Overlay mode: fixed max of 1024px, no resize by the user.
            finalW = Math.min(1024, window.innerWidth);
            sidebar.style.setProperty('--clp-width', finalW + 'px');
            // Do NOT persist — we want the normal-mode width preserved in localStorage.
            if (vwInput) { vwInput.value = finalW; vwInput.disabled = true; }
        } else {
            const leftEl = document.getElementById('left');
            const leftW  = leftEl ? leftEl.offsetWidth : 220;
            const maxW   = Math.max(MIN_WIDTH, window.innerWidth - leftW - 660);
            finalW = Math.round(Math.min(maxW, Math.max(MIN_WIDTH, w)));
            sidebar.style.setProperty('--clp-width', finalW + 'px');
            localStorage.setItem(LS_WIDTH_KEY, String(finalW));
            if (vwInput) { vwInput.value = finalW; vwInput.disabled = false; }
        }

        applyViewport();
    }

    function applyViewport() {
        if (!frame || !frameWrap) return;

        const zoom     = zoomSelect ? parseFloat(zoomSelect.value || '1') : 1;
        // Inline style is only present after the first setSidebarWidth() call.
        // On first load after reload, fall back to localStorage, then the default.
        const sidebarW = parseInt(
            sidebar.style.getPropertyValue('--clp-width')
            || localStorage.getItem(LS_WIDTH_KEY)
            || String(DEFAULT_WIDTH),
            10,
        ) || DEFAULT_WIDTH;
        const wrapH    = frameWrap.offsetHeight;

        if (zoom === 1) {
            // Natural fill — no transform, no explicit dimensions.
            frame.style.width           = '100%';
            frame.style.height          = '100%';
            frame.style.maxWidth        = '';
            frame.style.transform       = '';
            frame.style.transformOrigin = '';
        } else {
            // Scale the iframe down visually while enlarging it inversely so it
            // fills the wrapper exactly. overflow:hidden on the wrapper clips it.
            frame.style.width           = Math.round(sidebarW / zoom) + 'px';
            frame.style.height          = wrapH > 0 ? Math.round(wrapH / zoom) + 'px' : '100%';
            frame.style.maxWidth        = 'none';
            frame.style.transform       = 'scale(' + zoom + ')';
            frame.style.transformOrigin = 'top left';
        }

        if (vhInput) vhInput.value = wrapH;
    }

    function initViewportControls() {
        const hasSavedWidth = !!localStorage.getItem(LS_WIDTH_KEY);

        if (!hasSavedWidth && !isOverlayMode()) {
            // First open ever — pick the smartest initial width + zoom:
            //   • Ideal: sidebar ≥ 1366px at 100% zoom (up to 1536px)
            //   • Fallback: largest possible sidebar at 75% zoom
            const leftEl      = document.getElementById('left');
            const leftW       = leftEl ? leftEl.offsetWidth : 220;
            const maxSidebarW = Math.max(MIN_WIDTH, window.innerWidth - leftW - 660);

            if (maxSidebarW >= 1366) {
                if (zoomSelect) zoomSelect.value = '1';
                localStorage.setItem(LS_ZOOM_KEY, '1');
                setSidebarWidth(Math.min(1536, maxSidebarW));
            } else {
                if (zoomSelect) zoomSelect.value = '0.75';
                localStorage.setItem(LS_ZOOM_KEY, '0.75');
                setSidebarWidth(maxSidebarW);
            }
        } else {
            // Restore persisted state.
            const savedZoom = localStorage.getItem(LS_ZOOM_KEY) || '1';
            if (zoomSelect) zoomSelect.value = savedZoom;

            const savedW = parseInt(localStorage.getItem(LS_WIDTH_KEY) || String(DEFAULT_WIDTH), 10) || DEFAULT_WIDTH;
            setSidebarWidth(savedW); // clamps + sets vwInput + calls applyViewport
        }

        // applyViewport() was already called by setSidebarWidth().

        if (vwInput) {
            vwInput.addEventListener('change', () => {
                const v = parseInt(vwInput.value || '0', 10);
                if (v >= MIN_WIDTH) {
                    setSidebarWidth(v);
                } else {
                    // Clamp: restore to current actual width
                    vwInput.value = parseInt(localStorage.getItem(LS_WIDTH_KEY) || String(DEFAULT_WIDTH), 10);
                }
                applyViewport();
            });
            vwInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') vwInput.blur(); });
        }

        if (zoomSelect) {
            zoomSelect.addEventListener('change', () => {
                localStorage.setItem(LS_ZOOM_KEY, zoomSelect.value);
                applyViewport();
            });
        }

        // Keep vhInput live and recompute zoom dimensions whenever wrapper resizes.
        if (frameWrap) {
            new ResizeObserver(() => applyViewport()).observe(frameWrap);
        }
    }

    // -------------------------------------------------------------------------
    // Resize handle — Pointer Events API with setPointerCapture so that
    // pointermove/pointerup are received even when the cursor leaves the window.
    // Bound once because the resizer lives in the permanent sidebar element.
    // -------------------------------------------------------------------------

    function bindResizer() {
        const el = document.getElementById('clp-resizer');
        if (!el) return;

        let startX = 0, startW = 0;

        el.addEventListener('pointerdown', (e) => {
            if (e.button !== 0) return;   // left button only
            if (isOverlayMode()) return;  // no resize in overlay mode

            const cssVal = sidebar.style.getPropertyValue('--clp-width')
                        || getComputedStyle(sidebar).getPropertyValue('--clp-width')
                        || String(DEFAULT_WIDTH);
            startW = parseInt(cssVal, 10) || DEFAULT_WIDTH;
            startX = e.clientX;

            // Capture routes all subsequent pointer events to this element,
            // even when the cursor moves outside the browser window.
            el.setPointerCapture(e.pointerId);
            document.documentElement.style.userSelect = 'none';
            e.preventDefault();
        });

        el.addEventListener('pointermove', (e) => {
            if (!el.hasPointerCapture(e.pointerId)) return; // not dragging

            // Resizer is on the LEFT edge; dragging left = wider sidebar.
            const delta = startX - e.clientX;
            setSidebarWidth(startW + delta);
        });

        el.addEventListener('pointerup', endDrag);
        el.addEventListener('pointercancel', endDrag);

        function endDrag(e) {
            el.releasePointerCapture(e.pointerId);
            document.documentElement.style.userSelect = '';
        }
    }

    // Pre-paint width restore — also done inline in the template for the very
    // first paint; this covers deferred-script timing.
    (function restoreWidth() {
        const w = parseInt(localStorage.getItem(LS_WIDTH_KEY) || '0', 10);
        const maxW = Math.floor(window.innerWidth * 0.8);
        if (w >= MIN_WIDTH && w <= maxW) {
            document.documentElement.style.setProperty('--clp-saved-width', w + 'px');
        }
    })();

})();
