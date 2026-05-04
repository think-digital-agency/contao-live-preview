# DECISIONS.md – Architecture Decision Records

---

## ADR-001: Vanilla JS over Stimulus

**Date:** 2025-05-03  
**Status:** Accepted

**Context:**
Contao 5 ships Stimulus. Using it would give lifecycle callbacks and clean separation of concerns, but requires a build pipeline. The Design+ theme project has no JS build step and adding one for a ~250-line utility script is disproportionate.

**Decision:**
Vanilla IIFE loaded as a plain `<script defer>` tag. No transpilation, no bundler, no npm dependencies.

**Consequences:**
- (+) Zero build tooling — file served directly from `public/js/`
- (+) Works immediately after `composer require` + `assets:install`
- (-) No TypeScript or module system; if the script grows past ~600 lines consider migrating to Stimulus

---

## ADR-002: Template injection via `outputBackendTemplate` hook

**Date:** 2025-05-03  
**Status:** Accepted — extended with popup guard (2026-05)

**Context:**
Two approaches for injecting HTML into the backend:
1. `outputBackendTemplate` hook — fires per template render, receives template name and buffer
2. `KernelEvents::RESPONSE` — fires on every response; requires manually checking scope and body

**Decision:**
Use the hook with `$template === 'be_main'`. Extended with a server-side check for `?popup=1` and `?picker` to exclude popup windows and pickers from injection.

**Consequences:**
- (+) Scoped to the main backend layout — never fires for login, API, or popup responses
- (+) The popup/picker guard is in PHP, so no HTML/CSS/JS ever reaches those windows
- (-) If a future Contao version moves `be_main` to a Twig-only template, the hook stops firing. Migration path: switch to `KernelEvents::RESPONSE` with a `_scope === 'backend'` and no-popup check.

---

## ADR-003: Preview URL via `PageModel::getAbsoluteUrl()`

**Date:** 2026-05 (revised from original 2025-05-03)  
**Status:** Accepted

**Context:**
The original implementation used `/preview.php/{language}/{alias}.html` as the iframe URL. This failed because:
- `language` is only stored on the root page, not on every child page
- The `/preview.php` front controller does not exist in standard Contao 5 installations in this path form
- The approach required manually assembling URL parts that Contao already computes internally

**Decision:**
Use `PageModel::findWithDetails($pageId)->getAbsoluteUrl()`. `findWithDetails()` walks the full page hierarchy to inherit urlPrefix, urlSuffix, language, and domain from the root page. The result is a fully-qualified absolute URL (`http://example.com/de/home.html`).

Non-routable page types (`error_404`, `error_403`, `folder`, `root`) are handled by walking up the ancestry tree until a routable type (`regular`, `redirect`, `forward`) is found.

**Consequences:**
- (+) Correct URL for all page types, multi-language setups, and custom urlPrefix/Suffix configurations
- (+) Reuses Contao's own URL generation logic — no manual assembly
- (+) Non-routable pages degrade gracefully instead of returning a broken URL
- (-) Requires `ContaoFramework::initialize()` in the controller to bootstrap Contao's model layer

---

## ADR-004: Sidebar as flex child of `#container` with `data-turbo-permanent`

**Date:** 2026-05 (revised from original 2025-05-03)  
**Status:** Accepted

**Context:**
The original implementation used `position: fixed` on the sidebar with `margin-right` on `#wrapper`. This had two problems:
1. Contao's `#container` is `display: flex` with `#left` (nav) and `#main` (content) as children. A fixed-positioned sidebar doesn't participate in this flex flow, causing layout overlap.
2. Turbo's body swap destroyed and recreated the sidebar on every navigation, causing the iframe to blank out.

**Decision:**
1. Inject the sidebar as a third flex child of `#container` (after `</main>`). CSS adjustments: `#left` gets `flex-shrink: 0`, `#main` gets `flex: 1 1 0%` and `width: auto !important`.
2. Mark the sidebar `<aside>` with `data-turbo-permanent`. Turbo moves the element (with its live iframe) to every new body — the preview never blanks during navigation.
3. Use a `turbo:before-render` listener to pre-stamp `clp-open` on the incoming body, preventing a one-paint flash where the sidebar would be `display: none`.

**Consequences:**
- (+) Sidebar participates in the flex layout — `#left` never compresses, only `#main` shrinks
- (+) iframe content survives every navigation — no blank flash
- (+) Resize handle uses Pointer Events + `setPointerCapture` — drag works even when cursor leaves the browser window
- (-) Depends on Contao's `#container` being `display: flex` with the specific `#left`/`#main` structure (verified for Contao 5.7)
- (-) On screens < 1200px the sidebar switches to `position: fixed` overlay to avoid crushing `#main` below usable width

---

## ADR-005: Article-level DOM swap via self-fetch instead of full iframe reload

**Date:** 2026-05  
**Status:** Accepted

**Context:**
After a backend save, the preview must show the updated frontend content. The previous approach was: set `frame.src = previewUrl + ?_r=<timestamp>` to force a full page reload in the iframe. This had two problems:
1. Visible flicker: the iframe blanks out, reloads all assets, re-renders, and scroll position is lost
2. Complexity: required capturing the scroll position *before* the Turbo navigation that follows a form submit, then restoring it asynchronously after the iframe loaded

**Decision:**
Replace full iframe reload with an article-level DOM swap:
1. Backend sends `{ type: 'clp:refresh', articleId, selectors }` via postMessage
2. The injected frontend script (from `InjectPreviewScriptListener`) handles it:
   - `fetch(window.location.href)` — same-origin, no CORS required, credentials included
   - `DOMParser` parses the full HTML response (no execution of scripts)
   - Extracts `[data-contao-table="tl_article"][data-contao-id="{articleId}"]` from the parsed doc
   - Replaces the live DOM node with `Element.replaceWith()`
   - Restores scroll position, applies highlight animation
   - Posts `clp:refreshed` acknowledgement back to the backend
3. The frontend article template (`mod_article.html.twig`) must emit `data-contao-table` + `data-contao-id` attributes

**Why self-fetch (not a server-side fragment endpoint):**
The iframe is already loaded on the correct frontend origin. `fetch(window.location.href)` is a trivially same-origin request — no CORS, no auth tokens, no extra server route needed. A dedicated fragment endpoint would require rendering the article in isolation outside the normal Contao frontend pipeline, which is fragile and complex.

**Consequences:**
- (+) No iframe reload — no flicker, no asset re-fetching
- (+) Scroll position preserved without any pre-submit capture logic
- (+) No new server-side endpoint needed
- (+) Falls through gracefully: if `[data-contao-id]` is not found in fetched doc, DOM is unchanged
- (-) Fetches a full page HTML response (typically 20–100 KB) even though only the article HTML is used — acceptable for a backend-only tool
- (-) Depends on `data-contao-table`/`data-contao-id` in the theme template; themes without these attributes fall back to CSS-ID-based highlight only (no DOM swap)
- (-) `tl_page` edits (no `articleId`) do not trigger any visual refresh — the editor must manually reload the sidebar if they want to see page-level changes (metadata, title, layout)
