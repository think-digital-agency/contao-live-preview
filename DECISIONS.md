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
2. The injected frontend script handles it: `fetch(window.location.href)` → `DOMParser` → iterate selector chain until a match exists in both docs → `live.replaceWith(fresh)` → restore scroll → highlight → post `clp:refreshed`

The selector chain (`data-contao-*` → `#article-{id}` → `#{cssId}`) ensures the swap works regardless of which selectors the theme provides.

**Consequences:**
- (+) No iframe reload — no flicker, no asset re-fetching, scroll position preserved
- (+) No new server-side endpoint — trivially same-origin
- (-) Fetches a full page HTML response even though only the article node is used (acceptable for a backend-only tool)
- (-) `tl_page` edits (no `articleId`) do not trigger a visual refresh

---

## ADR-006: Rehydration via localStorage for save state across backend navigations

**Date:** 2026-05  
**Status:** Accepted

**Context:**
Contao 5 backend uses `@hotwired/turbo` for navigation. After form saves, Turbo normally performs a body-swap (Turbo Drive), which preserves `data-turbo-permanent` elements including the live preview sidebar and its iframe. However, all backend CSS/JS assets carry `data-turbo-track="reload"`. If any asset URL changes (e.g. after a deployment with fingerprinted assets), Turbo triggers a **full page reload** instead of a body-swap, destroying the iframe.

**Decision:**
Add a two-phase state persistence mechanism:
1. **Before save** (`handleFormSubmit`): write full context state to `localStorage['clp_pending_save']`.
2. **After any page init** (`onPageReady` → `tryRehydrate`): read and consume the state.
   - Body-swap path: pre-set state vars + `frameNeedsReload = true` so the refresh timer fires correctly
   - Full-reload path: load saved `iframeUrl`, restore scroll on load, fire highlight

State expires after 30 seconds. Cleared by `clp:refreshed` message.

**Consequences:**
- (+) Scroll position and highlight restored after both body-swap AND full reload
- (+) Fixes timing race where `refreshPreview()` fires before `resolveAndShow()` returns
- (-) `scrollX/Y` read via `frame.contentWindow` — fails silently if cross-origin (will be 0,0)

---

## ADR-007: Theme-independent marker injection via Contao hooks

**Date:** 2026-05  
**Status:** Accepted

**Context:**
The partial DOM swap (`clp:refresh`) required `data-contao-table="tl_article"` and `data-contao-id` on article wrappers. Originally only Design+'s `mod_article.html.twig` provided these. Any other theme would silently get no DOM swap (no error, just a stale iframe after save).

**Decision:**
Add two Contao hook listeners that auto-inject `data-contao-*` attributes when the page is loaded with `?_clp=1`:

- `parseFrontendTemplate` hook (`InjectArticleMarkersListener`): fires in `Module::generate()` after template render, including Twig-rendered articles. Extracts the numeric ID from Contao's default `id="article-{N}"` attribute. Falls back gracefully when `noMarkup` or a custom CSS ID is set (JS fallback selectors still handle highlight).
- `getContentElement` hook (`InjectContentElementMarkersListener`): fires for legacy `ContentElement` subclasses and RSCE. Injects `data-contao-table`, `data-contao-id`, and `data-contao-label`. Twig-first `#[AsContentElement]` CEs bypass the hook (documented limitation).

Both listeners check `str_contains($buffer, 'data-contao-table=')` before injecting, so themes that already provide the attributes (Design+) are never double-injected.

**Consequences:**
- (+) Bundle works out of the box with any Contao theme
- (+) Design+ theme keeps its existing template approach — no regression
- (-) Twig-first `#[AsContentElement]` CEs are not covered by the CE hook

---

## ADR-008: Dual-mode CE + article highlighting

**Date:** 2026-05  
**Status:** Accepted

**Context:**
When editing a content element, both the CE and its parent article are relevant context. Showing only the CE makes it hard to locate the element on the page; showing only the article loses the CE precision.

**Decision:**
When both a CE selector and an article selector are provided and resolve to different DOM elements (dual mode):
- CE: solid blue outline (`clp-sel`) + CE-type badge (e.g. "ICON LISTE") with edit icon
- Article: dashed blue outline (`clp-sel-secondary`) + "ARTIKEL" badge with edit icon

When only one resolves (article-only or CE=article), a single solid outline with a single badge.

Badges are `position:absolute` in `<body>`, positioned at the element's top-left corner + 2px offset. Repositioned on `window.resize` (triggered by sidebar drag or zoom change). Both edit icons send `clp:edit` postMessage to the backend.

**Consequences:**
- (+) Editor always sees both which CE is active and which article contains it
- (+) Edit icon provides single-click navigation to either record
- (-) Two simultaneous outlines can look busy on very compact layouts

---

## ADR-009: Interactive hover highlighting

**Date:** 2026-05  
**Status:** Accepted

**Context:**
Editors need to discover which articles and CEs exist on a page without having to click through each one in the backend. A hover inspection mode (like browser DevTools element picker) gives this at zero additional UI cost.

**Decision:**
`mouseover` / `mouseout` listeners on `document` detect the nearest `[data-contao-table]` ancestor of the hovered element via `Element.closest()`. Hover effects:
- Fuchsia dashed outline (`.clp-hover`, z-index 9998)
- Fuchsia badge (`.clp-hover-badge`, z-index 2147483647 — always on top) with the same edit icon

Active (blue) elements are excluded from hover. Hover badge is kept alive while the cursor is over it (edit icon remains clickable) by checking `relatedTarget` in `mouseout` against the data container element (`_hoverEl.contains(rel)`). Same-origin link clicks in the iframe are intercepted and `?_clp=1` is appended so the script survives page navigation.

**Consequences:**
- (+) Instant discoverability of all annotated elements without backend clicks
- (+) Edit icon in hover badge navigates directly to the record
- (-) Only elements with `data-contao-table` are discoverable (Twig-first CEs without the hook are invisible to hover)

---

## ADR-010: Edit badge navigation via `clp:edit` postMessage

**Date:** 2026-05  
**Status:** Accepted

**Context:**
Both active and hover badges carry a pencil edit icon. Clicking it should navigate the backend to the corresponding article or CE edit form without requiring the editor to manually locate the record in the backend tree.

**Decision:**
The edit icon button (`.clp-badge-edit`, `pointer-events:auto` inside `pointer-events:none` badge) fires `window.parent.postMessage({ type: 'clp:edit', table, id }, '*')`. The backend `live-preview.js` listens for `clp:edit` and builds the Contao edit URL:
- `tl_content` → `?do=article&table=tl_content&act=edit&id={ceId}` (CE edit form)
- `tl_article` → `?do=article&table=tl_content&id={articleId}` (article content list)

Navigation via `Turbo.visit()` if available, else `window.location.href`.

**Consequences:**
- (+) Single click in the preview jumps to the right backend record
- (+) Works for hover elements too — no need to locate them in the backend tree first
- (-) `clp:edit` is sent to `'*'` origin — acceptable since the backend is same-origin and the payload contains only numeric IDs and table names

---

## ADR-011: Grid column visual target unwrapping

**Date:** 2026-05  
**Status:** Accepted (theme-specific heuristic, documented as such)

**Context:**
In Design+'s grid system, article and CE wrappers are often a `col-*` grid column div containing a single child element (the actual article/CE content). Applying the outline to the column wrapper makes the highlight appear too wide and visually imprecise.

**Decision:**
`clpVisTarget(el)`: if the data element has a class starting with `col-` and has exactly one child, return the child as the visual target. Outline and badge are applied to the child. The data element is unchanged for DOM queries, hover exclusion, and DOM swap targeting.

Two separate reference pairs are maintained: `_el`/`_elVis` (data/visual) and `_elCe`/`_elCeVis`, `_hoverEl`/`_hoverElVis`. `mouseout` boundary check uses the container (`_hoverEl.contains(rel)`) so cursor movement between child and parent doesn't flicker.

**Consequences:**
- (+) Visually precise highlight in Design+ grid layouts
- (+) Graceful fallback: if no `col-*` class or multiple children, `clpVisTarget` returns the element unchanged — works in any theme
- (-) The `col-*` check is a hardcoded heuristic; themes with different grid class naming won't benefit

---

## ADR-012: DOM-first CE label resolution

**Date:** 2026-05  
**Status:** Accepted

**Context:**
CE type labels (`$GLOBALS['TL_LANG']['CTE']`) are sometimes not available in the backend request context (e.g. third-party bundle CEs that only register their language file during frontend rendering). The API controller fell back to the raw type key (`dma_simplegrid_wrapper_start`), which appeared in the badge as `DMA_SIMPLEGRID_WRAPPER_START`.

The `getContentElement` hook runs in fully-bootstrapped frontend context where all language files are loaded — `data-contao-label` in the DOM is always correct.

**Decision:**
In the `clp:highlight` frontend handler, `getCeLabel(el)` is called on the found CE element instead of using `e.data.label` from the API. `getCeLabel()` reads `data-contao-label` first (set by the hook listener in frontend context), falls back to parsing the `ce_*` CSS class. The API label is only used if the CE element is not found in the DOM.

The controller fallback is changed from `$type` to `''` — raw type keys never appear in the API response. Both PHP label resolvers share a `cleanLabel()` method that strips suffix words (`Anfang`, `Start`, `Ende`, `Wrapper` and colon-separated combinations) that add no badge value.

**Consequences:**
- (+) Correct human-readable labels even for CEs whose language files aren't loaded in backend context
- (+) Single cleanup method applied consistently for both active and hover labels
- (+) No additional requests — DOM attribute is already present
- (-) If a CE is not found in the DOM (e.g. below the fold before scroll), label falls back to API value (empty string for unknown types → no badge text shown)

---

## ADR-013: Twig-first CE annotation via RESPONSE post-processor

**Date:** 2026-05
**Status:** Accepted

**Context:**
Twig-first content elements registered with `#[AsContentElement]` (all Contao 5 core CEs: text, image, gallery, downloads, …) bypass the `getContentElement` hook. They produce no `data-contao-*` attributes, making them invisible to hover highlighting and the active-CE badge. For a theme-independent bundle this is a critical gap.

No Symfony service is exposed to decorate the CE fragment renderer without deep coupling to Contao internals. The rendered HTML however always contains `class="ce_{type} ..."` on the CE wrapper — a pattern Contao has maintained since version 2.

**Decision:**
Add `InjectTwigContentElementMarkersListener` on `KernelEvents::RESPONSE` (priority -195, before the script injection at -200). When `?_clp=1` is present and `$GLOBALS['objPage']` is set, the listener:
1. Loads all visible CEs for the current page via DBAL, ordered by `a.sorting, c.sorting`.
2. Scans the rendered HTML with `preg_match_all` for opening tags matching `ce_{type}` CSS class that do not yet carry `data-contao-table=`.
3. Matches each DB row to the Nth HTML occurrence of its type (type+position matching). CEs with a custom `cssId` use exact `id="cssId"` matching instead.
4. Injects `data-contao-table="tl_content"`, `data-contao-id`, and `data-contao-label` into each matched tag via `substr_replace` (applied in reverse offset order to keep byte positions stable).

**Consequences:**
- (+) All core Contao 5 CEs become hover-highlightable and get correct badges
- (+) No Contao internals patched — pure KernelEvents + DBAL
- (+) Legacy CEs (already annotated by the hook listener) are skipped via `str_contains` guard
- (-) Multi-column layouts may misalign type+position matching when side-column HTML precedes main-column HTML — cssId matching is always correct
- (-) Nested CEs (e.g. accordion items rendered inside an accordion wrapper) appear as additional occurrences of `ce_{type}`, shifting position counters for subsequent CEs of the same type

---

## ADR-014: `postMessage` with `'*'` target origin

**Date:** 2026-05
**Status:** Accepted — intentional, documented

**Context:**
The frontend iframe sends `clp:edit` and `clp:refreshed` messages to `window.parent` (the backend). Using `window.location.origin` as the target would restrict delivery to the exact frontend origin. In multi-domain setups (frontend on `www.example.com`, backend on `admin.example.com`) this would silently drop messages.

**Decision:**
Keep `'*'` as the target origin for all frontend→backend postMessages. The payload contains only numeric IDs and table names (`tl_content`, `tl_article`) — no sensitive data. The backend listener already validates message type before acting.

**Consequences:**
- (+) Works in any single- or multi-domain Contao setup without configuration
- (-) Any page that can embed the frontend iframe and craft a `clp:edit` message could trigger a `Turbo.visit()` in the backend — acceptable risk given the payload contains only public identifiers and the backend enforces `ROLE_USER` on all edit routes

---

## ADR-015: `LabelCleanerTrait` for shared label utilities

**Date:** 2026-05
**Status:** Accepted

**Context:**
`cleanLabel()` and the pattern of loading the CTE language file were duplicated across `PreviewResolverController` and `InjectContentElementMarkersListener`. Adding a third consumer (`InjectTwigContentElementMarkersListener`) made the duplication untenable.

**Decision:**
Extract into `Service\LabelCleanerTrait` with two methods: `cleanLabel(string): string` (regex stripping) and `resolveLabel(string $type, ContaoFramework $framework): string` (language-file load + cleanLabel). All three consumers use the trait via `use LabelCleanerTrait;`.

**Consequences:**
- (+) Single source of truth for label cleanup logic
- (+) Trait approach avoids a static utility class, keeping dependencies injectable per-class
- (-) PHP traits are not the most discoverable pattern; the trait is in `Service/` to signal its shared-utility role
