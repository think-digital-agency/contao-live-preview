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

## ADR-004: Sidebar on `<html>` with `position: fixed`, layout shift via `padding-right`

**Date:** 2026-05 (revised 2026-05)  
**Status:** Accepted — supersedes earlier flex-child approach

**Context:**
Earlier revisions injected the sidebar as a third flex child of Contao's `#container`, relying on `data-turbo-permanent` to survive Turbo body swaps. Two problems emerged:
1. `data-turbo-permanent` requires the element to stay in `<body>`. Turbo moves it to each incoming body, but a full page reload (triggered when fingerprinted assets change) destroys the entire DOM including permanent elements — the iframe blanked out.
2. The flex-child approach caused `#left` (nav column) to compress when the sidebar opened.

**Decision:**
1. Inject the sidebar HTML after `</main>` via the `outputBackendTemplate` hook (template injects it inside `<body>` for initial render).
2. On first JS execution, move `#clp-right` from `<body>` to `document.documentElement` (`<html>`). `<html>` is never replaced by Turbo body swaps — the sidebar and its live iframe are untouchable by navigation.
3. A `turbo:before-render` listener strips any server-injected duplicate `#clp-right` from the incoming body before Turbo inserts it, preventing a ghost sidebar on every navigation.
4. The sidebar uses `position: fixed; top: 0; right: 0; bottom: 0` and is always the full viewport height.
5. When open (desktop ≥ 1201px), both `#header` and `#container` receive `padding-right: var(--clp-saved-width)` so neither overlaps with the sidebar. Both use a CSS transition when the `clp-animate` class is present.
6. A `window.__clpLoaded` guard prevents the IIFE from executing a second time if the script ever appears in a body context.

**Consequences:**
- (+) Sidebar survives full page reloads — iframe never blanks
- (+) `#header` and `#container` shift together, creating a clean full-height split
- (+) `position: fixed` makes the sidebar independent of scroll position — stays visible on long pages
- (+) No dependency on Contao's `#container` flex structure
- (-) Moving the element to `<html>` is non-standard; relies on browsers accepting arbitrary children on `<html>` (all modern browsers do)
- (-) On screens ≤ 1200px the sidebar is a full overlay (no layout shift) — acceptable at small widths

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

## ADR-016: Root-page fallback when no backend context is active

**Date:** 2026-05
**Status:** Accepted

**Context:**
When an editor opens backend areas with no resolvable page context (dashboard, system settings, user manager, etc.) the preview sidebar was blank — `parseContext()` returned `null`, `clearFrame()` set `frame.src = ''`, and the URL display was empty. The sidebar had no visual value in these views.

**Decision:**
Replace the `clearFrame()` path with a call to `resolveAndShow(null)`. When the controller receives a request without `table`/`id` parameters, it calls `PreviewUrlResolverInterface::resolveRootPage()` instead of returning `400`. That method queries the first published `type='root'` page and its first published `type='regular'` child, returning the child's URL with empty `highlightSelectors` and `articleSelectors`.

A `UNRESOLVED` sentinel object replaces the `null` initialisation of `currentContext`. This ensures the dedup guard (`contextKey` comparison) always passes on the first call after every navigation, while still deduplicating repeated resolves for the same no-context state.

**Consequences:**
- (+) Preview sidebar always shows something useful — editors get a live view of the site even from unrelated backend sections
- (+) No new endpoint or route — the existing resolve endpoint handles both cases
- (+) Dedup logic is preserved: the fallback is fetched once per navigation, not on every `triggerResolve` tick
- (-) The fallback always picks the first root tree / first regular child — in multi-language or multi-domain setups this may not be the "most relevant" preview. No configuration option yet.
- (-) `resolveRootPage()` is now part of `PreviewUrlResolverInterface`. Third-party decorators must implement the method (typically by delegating to the inner resolver).

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


---

## ADR-017: Frontend module hover-edit via `getFrontendModule` hook

**Date:** 2026-05
**Status:** Accepted

**Context:**
Layout modules (navigation, header, footer, etc.) were not covered by the hover-edit system. Content elements and articles already had hover badges via the `getContentElement` and `parseFrontendTemplate` hooks. Modules needed the same treatment so editors can directly jump from a hovered module to its backend edit form.

The main challenge: themes that do not emit `mod_*` CSS classes on module wrappers (e.g. Design+, which uses BEM classes like `m-navigation`, `m-header__logo`) make post-hoc HTML matching impossible. We cannot reliably identify module boundaries after the full page is assembled.

**Decision:**
Use the `getFrontendModule` hook (`#[AsHook('getFrontendModule')]`) in `InjectModuleMarkersListener`. The hook fires inside `Controller::getFrontendModule()` and receives the `ModuleModel` (with `id` and `type`) alongside the rendered HTML buffer. This allows injecting `data-contao-table="tl_module"`, `data-contao-id`, and `data-contao-label` into the first opening tag of the module's own buffer, before the theme has wrapped it in any custom structure.

`Controller::getFrontendModule()` is called for:
- All layout column modules (via `PageRegular`'s preload pass at `page/getPreloadedModules`)
- `{{insert_module::N}}` insert-tag expansions
- `{{ frontend_module(N) }}` Twig function calls (via `FragmentRuntime::renderModule()`)

Module labels come from `$GLOBALS['TL_LANG']['FMD']` (Frontend Module Definitions), distinct from `$GLOBALS['TL_LANG']['CTE']` used for content elements.

The backend JS `clp:edit` handler gains a `tl_module` branch: navigates to `?do=themes&table=tl_module&act=edit&id={N}`.

**Rejected alternatives:**
- `parseFrontendTemplate` hook + `parseTemplate` ID capture: more complex (requires a shared stack service), and `parseFrontendTemplate` is coupled to `FrontendTemplate::parse()` rather than the module pipeline.
- Response-level HTML post-processing (like `InjectTwigContentElementMarkersListener`): infeasible without `mod_*` class markers — the Design+ theme emits only BEM classes on module wrappers.

**Consequences:**
- (+) Works for all themes regardless of whether `mod_*` CSS classes are present
- (+) No position-based matching required — the ID is injected atomically at render time
- (+) Covers both legacy PHP modules and Twig-first `#[AsFrontendModule]` controllers (both go through `Controller::getFrontendModule()`)
- (-) Does not cover modules rendered via custom PHP that calls the template directly without going through `Controller::getFrontendModule()`

---

## ADR-018: Badge quick-action buttons (duplicate, new-after) for `tl_content`

**Date:** 2026-05
**Status:** Accepted

**Context:**
Editors frequently duplicate a content element or insert a new one after an existing one. Both require navigating through the Contao backend list, finding the correct row, and clicking the action icon — several clicks away when already looking at the element in the preview.

**Decision:**
Add two icon buttons directly to the active-CE badge (`.clp-badge`) for `tl_content` elements, rendered by `_mkBadge()` in `InjectPreviewScriptListener`:
- **⧉ Duplicate** (`clp:duplicate`): builds `act=copy&mode=4&id={id}` and fires `Turbo.visit()`
- **+ New after** (`clp:insert-after`): builds `act=create&mode=4&pid={id}` and fires `Turbo.visit()`

The buttons send postMessages to the parent backend window. The `live-preview.js` message handler resolves the Contao `request_token` via `window.Contao.request_token` (with `requestToken` camelCase fallback for older versions) and appends it to the URL as `rt=`.

The `turbo:before-visit` listener detects these navigations (`act=copy`, `act=create`) as content-modifying and sets `pendingContentChange = true`, ensuring the iframe reloads on the subsequent `onPageReady`.

**Consequences:**
- (+) One-click duplicate and new-from-preview, without leaving the current backend view
- (+) CSRF token (`rt`) correctly attached — Contao rejects the request otherwise
- (+) Auto-refresh after the redirect — no stale iframe after the action
- (-) Only rendered for `tl_content` — other tables (tl_article, tl_module) don't get quick-action buttons
- (-) Buttons only appear on the active badge, not the hover badge — intentional to keep hover non-destructive

---

## ADR-019: `pendingContentChange` flag for content-modifying GET navigations

**Date:** 2026-05
**Status:** Accepted

**Context:**
Contao's delete, toggle, cut, paste, and copy actions are plain GET requests (no form submit). The existing save-cycle mechanism (`handleFormSubmit` → `pendingSave` → `clp:refresh`) only fires for POST form submissions. After a GET action, Contao redirects back to the list page, but the iframe still shows stale content — the deleted/moved/toggled element remains visible until the next unrelated save.

The `clp:refresh` DOM-swap path was not suitable here: it depends on `currentArticleId` and `articleSelectors` that may point to a now-deleted element; replying on the stale selectors could silently do nothing or swap in the wrong node.

**Decision:**
1. A `turbo:before-visit` listener inspects the URL of every Turbo navigation. If `act` is one of `delete`, `deleteAll`, `copy`, `copyAll`, `cut`, `cutAll`, `toggle`, `toggleAll`, `paste`, `pasteAll`, the flag `pendingContentChange` is set to `true`.
2. In `onPageReady()`, after `tryRehydrate()`, if the flag is set: reset it, build a fresh iframe URL from `getCleanSrc()` with a `_t=Date.now()` cache-buster, and assign directly to `frame.src`. Then attach a one-time `load` handler to fire `sendHighlight`.
3. The cache-buster prevents the browser from serving the cached response that still contains the old element.

**Consequences:**
- (+) Iframe updates on the first automatic refresh — no second manual reload needed
- (+) Full iframe reload is unconditionally reliable regardless of which selectors were active
- (+) Covers all content-modifying GET actions, not just delete
- (-) Full reload (flash) instead of seamless DOM swap — acceptable since the page structure changed anyway
- (-) Drag-and-drop reordering (plain XHR, no Turbo navigation) is not covered — rare edge case, acceptable to leave uncovered

---

## ADR-020: CSS variable chaining for Contao header version compatibility

**Date:** 2026-05
**Status:** Accepted

**Context:**
Contao 5.7.4 redesigned the backend header from an orange bar (`--header-bg: #f47c00`) to a white bar (`--header-bg: #fff`) and removed the `--header-text` CSS custom property entirely. The toggle button had been styled with `color: var(--header-text, #fff)` — on the new white header this rendered as white text on white background (invisible).

**Decision:**
Use CSS custom property chaining as an implicit version detector — no JS, no media queries, no version checks:

```css
color: var(--header-text, var(--text, #222));
background-color: var(--header-bg-hover, var(--nav-bg-hover, #eaeaec));
```

- `--header-text` is defined in Contao ≤ 5.7.3 (value: `#fff`) → white text on orange, dark hover overlay
- `--header-text` is absent in Contao ≥ 5.7.4 → CSS falls through to `--text` (`#222`) → dark text on white, light grey hover

The button shape (pill `border-radius: 20px`, `padding: 8px 12px`) matches Contao 5.7.4's new `#tmenu a` style. On older versions the pill renders slightly differently from the native items but remains fully readable.

**Consequences:**
- (+) Zero configuration — works on both versions automatically
- (+) No version number checks — adapts to whatever the installed Contao provides
- (-) If a future Contao version reintroduces `--header-text` with a different meaning, the fallback chain would misbehave — low risk given the variable was removed, not repurposed
