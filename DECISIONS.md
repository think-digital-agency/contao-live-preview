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
