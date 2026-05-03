/**
 * Contao Live Preview – vanilla JS, no build step required.
 *
 * Flow:
 *   URL/navigation change
 *     → parseContext()
 *     → resolvePreviewUrl()  (AJAX to /contao/live-preview/resolve)
 *     → setIframeSrc()
 *   Form submit / save flash detected
 *     → reloadPreview()
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    const RESOLVE_ENDPOINT = '/contao/live-preview/resolve';
    const LS_OPEN_KEY      = 'clp_sidebar_open';
    const LS_WIDTH_KEY     = 'clp_sidebar_width';
    const DEFAULT_WIDTH    = 420;
    const MIN_WIDTH        = 280;
    const MAX_WIDTH        = 900;
    // Debounce delay after a navigation / URL change before re-resolving
    const RESOLVE_DEBOUNCE = 300;
    // Fallback delay after form submit to trigger iframe reload
    const SAVE_DELAY       = 900;
    // Wide-screen breakpoint: sidebar opens automatically when >= this width
    const AUTO_OPEN_WIDTH  = 1400;

    // -------------------------------------------------------------------------
    // DOM references (set after DOMContentLoaded)
    // -------------------------------------------------------------------------

    let sidebar, frame, urlInput, openTabBtn, refreshBtn, toggleBtn,
        floatingToggle, loadingEl, noContextEl, resizer;

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    let currentPreviewUrl = '';
    let resolveTimer      = null;
    let isOpen            = false;
    let currentContext    = null; // { table, id, do }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', () => {
        sidebar        = document.getElementById('clp-sidebar');
        frame          = document.getElementById('clp-frame');
        urlInput       = document.getElementById('clp-url-display');
        openTabBtn     = document.getElementById('clp-open-tab');
        refreshBtn     = document.getElementById('clp-refresh');
        toggleBtn      = document.getElementById('clp-toggle');
        floatingToggle = document.getElementById('clp-floating-toggle');
        loadingEl      = document.getElementById('clp-loading');
        noContextEl    = document.getElementById('clp-no-context');
        resizer        = document.getElementById('clp-resizer');

        if (!sidebar || !frame) {
            return;
        }

        restoreState();
        bindEvents();
        observeNavigation();
        triggerResolve();
    });

    // -------------------------------------------------------------------------
    // State persistence
    // -------------------------------------------------------------------------

    function restoreState() {
        const savedOpen = localStorage.getItem(LS_OPEN_KEY);

        if (savedOpen === null) {
            // Auto-open on wide screens on first visit
            isOpen = window.innerWidth >= AUTO_OPEN_WIDTH;
        } else {
            isOpen = savedOpen === '1';
        }

        const savedWidth = parseInt(localStorage.getItem(LS_WIDTH_KEY), 10);
        if (savedWidth >= MIN_WIDTH && savedWidth <= MAX_WIDTH) {
            setSidebarWidth(savedWidth);
        }

        applySidebarState();
    }

    function applySidebarState() {
        if (isOpen) {
            sidebar.classList.remove('clp-sidebar--collapsed');
            toggleBtn.setAttribute('aria-expanded', 'true');
            toggleBtn.title = 'Vorschau einklappen';
            floatingToggle.hidden = true;
            document.documentElement.style.setProperty('--clp-sidebar-width', getSidebarWidth() + 'px');
        } else {
            sidebar.classList.add('clp-sidebar--collapsed');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.title = 'Vorschau ausklappen';
            floatingToggle.hidden = false;
            document.documentElement.style.setProperty('--clp-sidebar-width', '0px');
        }

        localStorage.setItem(LS_OPEN_KEY, isOpen ? '1' : '0');
    }

    function getSidebarWidth() {
        const w = parseInt(getComputedStyle(sidebar).getPropertyValue('--clp-width').trim(), 10);
        return isNaN(w) ? DEFAULT_WIDTH : w;
    }

    function setSidebarWidth(px) {
        sidebar.style.setProperty('--clp-width', px + 'px');
        document.documentElement.style.setProperty('--clp-sidebar-width', isOpen ? px + 'px' : '0px');
        localStorage.setItem(LS_WIDTH_KEY, String(px));
    }

    // -------------------------------------------------------------------------
    // Event bindings
    // -------------------------------------------------------------------------

    function bindEvents() {
        // Sidebar toggle buttons
        toggleBtn.addEventListener('click', toggleSidebar);
        floatingToggle.addEventListener('click', () => {
            isOpen = true;
            applySidebarState();
            if (currentPreviewUrl) {
                setIframeSrc(currentPreviewUrl);
            } else {
                triggerResolve();
            }
        });

        // Open in new tab
        openTabBtn.addEventListener('click', () => {
            if (currentPreviewUrl) {
                window.open(currentPreviewUrl, '_blank', 'noopener');
            }
        });

        // Manual refresh
        refreshBtn.addEventListener('click', reloadPreview);

        // Detect save via form submit
        document.addEventListener('submit', handleFormSubmit);

        // Detect save via flash message mutation
        observeSaveFlash();

        // Resize handle
        bindResizer();
    }

    function toggleSidebar() {
        isOpen = !isOpen;
        applySidebarState();
        if (isOpen && !frame.src && currentPreviewUrl) {
            setIframeSrc(currentPreviewUrl);
        }
    }

    // -------------------------------------------------------------------------
    // Context detection
    // -------------------------------------------------------------------------

    /**
     * Parses the current backend URL and returns a context descriptor.
     * Returns null if no resolvable context is found.
     */
    function parseContext() {
        const params = new URLSearchParams(window.location.search);
        const doVal  = params.get('do') || '';
        const table  = params.get('table') || '';
        const id     = parseInt(params.get('id') || '0', 10);
        const act    = params.get('act') || '';

        // Creating a new record — no stable page to resolve yet
        if (act === 'create' && id === 0) {
            return null;
        }

        // Editing a content element inside tl_article
        if (table === 'tl_content' && id > 0) {
            return { table: 'tl_content', id, do: doVal };
        }

        // Editing an article or something nested under it
        if (doVal === 'article' && id > 0 && table !== 'tl_content') {
            return { table: 'tl_article', id, do: doVal };
        }

        // Editing a page directly
        if (doVal === 'page' && id > 0) {
            return { table: 'tl_page', id, do: doVal };
        }

        // Fallback: if there's a known table and id, try to resolve anyway
        if (table && id > 0) {
            return { table, id, do: doVal };
        }

        return null;
    }

    function contextChanged(a, b) {
        if (a === null && b === null) return false;
        if (a === null || b === null) return true;
        return a.table !== b.table || a.id !== b.id;
    }

    // -------------------------------------------------------------------------
    // Navigation observation
    // -------------------------------------------------------------------------

    function observeNavigation() {
        // Watch document title — Contao backend JS updates it on partial navigation
        const titleEl = document.querySelector('head title');
        if (titleEl) {
            new MutationObserver(() => scheduleResolve()).observe(titleEl, { childList: true });
        }

        // Watch the main content area for DOM replacement (AjaxRequest / turbo-like navigation)
        const mainContent = document.getElementById('main') || document.querySelector('.main_headline');
        if (mainContent) {
            new MutationObserver(() => scheduleResolve()).observe(mainContent, {
                childList: true,
                subtree: false,
            });
        }

        // Also listen for popstate (history navigation)
        window.addEventListener('popstate', scheduleResolve);
    }

    function scheduleResolve() {
        clearTimeout(resolveTimer);
        resolveTimer = setTimeout(triggerResolve, RESOLVE_DEBOUNCE);
    }

    // -------------------------------------------------------------------------
    // AJAX resolve
    // -------------------------------------------------------------------------

    function triggerResolve() {
        const ctx = parseContext();

        if (!contextChanged(ctx, currentContext)) {
            return;
        }

        currentContext = ctx;

        if (ctx === null) {
            showNoContext();
            return;
        }

        resolvePreviewUrl(ctx);
    }

    async function resolvePreviewUrl(ctx) {
        showLoading(true);

        const params = new URLSearchParams({
            table: ctx.table,
            id: String(ctx.id),
        });

        try {
            const response = await fetch(RESOLVE_ENDPOINT + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                showNoContext();
                return;
            }

            const data = await response.json();

            if (data.previewUrl) {
                currentPreviewUrl = data.previewUrl;
                urlInput.value    = data.previewUrl;
                openTabBtn.disabled  = false;
                refreshBtn.disabled  = false;

                if (isOpen) {
                    setIframeSrc(data.previewUrl);
                }
            } else {
                showNoContext();
            }
        } catch (err) {
            showNoContext();
        } finally {
            showLoading(false);
        }
    }

    // -------------------------------------------------------------------------
    // iframe management
    // -------------------------------------------------------------------------

    function setIframeSrc(url) {
        if (!url) return;

        showNoContext(false);
        loadingEl.hidden = false;
        frame.hidden     = false;

        frame.addEventListener('load', () => { loadingEl.hidden = true; }, { once: true });
        frame.src = url;
    }

    function reloadPreview() {
        if (!currentPreviewUrl) return;

        try {
            frame.contentWindow.location.reload();
        } catch (_) {
            // Cross-origin fallback: re-set src
            setIframeSrc(currentPreviewUrl);
        }
    }

    function showNoContext(show = true) {
        noContextEl.hidden = !show;
        frame.hidden       = show;
        loadingEl.hidden   = true;

        if (show) {
            currentPreviewUrl   = '';
            urlInput.value      = '';
            openTabBtn.disabled = true;
            refreshBtn.disabled = true;
        }
    }

    function showLoading(show) {
        loadingEl.hidden = !show;
        if (show) {
            noContextEl.hidden = true;
        }
    }

    // -------------------------------------------------------------------------
    // Save detection
    // -------------------------------------------------------------------------

    function handleFormSubmit(e) {
        const form = e.target;
        if (!form || typeof form.querySelector !== 'function') return;
        if (!form.querySelector('[name="FORM_SUBMIT"]')) return;

        // Schedule a preview reload after the backend redirects back
        // The MutationObserver on the title / main content will also fire,
        // but we add a fallback timeout so the iframe reloads even if the
        // Contao flash message doesn't appear (e.g. "Apply" button).
        setTimeout(reloadPreview, SAVE_DELAY);
    }

    function observeSaveFlash() {
        // Contao renders success messages as .tl_confirm after save + redirect.
        // We watch the body for such an element appearing.
        new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                for (const node of mutation.addedNodes) {
                    if (node.nodeType !== 1) continue;
                    if (
                        node.classList.contains('tl_confirm') ||
                        node.querySelector('.tl_confirm')
                    ) {
                        // Re-resolve context first (URL may have changed after save),
                        // then reload after a short delay to let Contao finish rendering.
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
        let startX, startWidth;

        resizer.addEventListener('mousedown', (e) => {
            startX     = e.clientX;
            startWidth = parseInt(getComputedStyle(sidebar).width, 10);

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp, { once: true });
            e.preventDefault();
        });

        function onMouseMove(e) {
            // Resizer is on the left edge of the sidebar; dragging left = wider
            const delta    = startX - e.clientX;
            const newWidth = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startWidth + delta));
            setSidebarWidth(newWidth);
        }

        function onMouseUp() {
            document.removeEventListener('mousemove', onMouseMove);
        }
    }
})();
