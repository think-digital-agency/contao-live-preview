# CLAUDE.md – ContaoLivePreviewBundle

## Project Identity

**Package:** `vendor/contao-live-preview-bundle`
**Target:** Contao 5.5+ / Symfony 7.x
**Purpose:** Adds a collapsible, context-aware live frontend preview sidebar to the Contao 5 backend. When an editor opens a page, article, or content element, an iframe on the right side shows the corresponding frontend page. Saving a record triggers an article-level DOM swap in the iframe — no full reload, scroll position stays intact. Any `[data-contao-table]` element in the iframe can be hovered to see a fuchsia badge with a direct edit link, or clicked to jump straight to that record in the backend.

**Location:** `packages/contao-live-preview-bundle/` inside the Design+ theme project. Installed via a `path` Composer repository.

---

## Architecture Decisions

| Decision | Choice | Rationale |
|---|---|---|
| JS | Vanilla IIFE, no build step | Dependency-free; works in any Contao project without npm |
| Injection | `outputBackendTemplate` hook on `be_main` | Scoped — never fires for popups, pickers, or login |
| Popup exclusion | Server-side: `?popup=1` / `?picker` | Cleanest gate; no client-side fallback needed |
| Sidebar persistence | `data-turbo-permanent` on `#clp-right` | Turbo moves sidebar (with live iframe) to every new body — zero flash |
| Flash prevention | `turbo:before-render` pre-stamps `clp-open` on incoming body | Prevents one-paint sidebar-hidden flash after body swap |
| Preview URL | `PageModel::findWithDetails()->getAbsoluteUrl()` | Inherits urlPrefix, language, domain from root page hierarchy |
| Partial refresh | `clp:refresh` → self-fetch + DOMParser swap | No iframe reload — scroll preserved, only article node replaced |
| Rehydration | `clp_pending_save` localStorage | Restores scroll + highlight after Turbo full-reload (asset fingerprint change) |
| Resize | Pointer Events + `setPointerCapture` | `pointermove`/`pointerup` received even when cursor leaves window |
| Extensibility | `PreviewUrlResolverInterface` | Third-party bundles alias the interface to add custom table support |
| Highlight injection | `?_clp=1` + `KernelEvents::RESPONSE` | Frontend script injected ephemerally — zero impact on normal page output |
| Article markers | `parseFrontendTemplate` hook | Auto-injects `data-contao-*` on article wrapper for any theme |
| CE markers + label (legacy) | `getContentElement` hook | Auto-injects `data-contao-*` + `data-contao-label` on CE wrapper; covers legacy `ContentElement` + RSCE |
| CE markers + label (Twig-first) | `KernelEvents::RESPONSE` HTML annotation | Post-renders `ce_{type}`-classed wrappers via DB lookup + type+position matching; covers `#[AsContentElement]` CEs |
| Label deduplication | `LabelCleanerTrait` | Shared `cleanLabel()` + `resolveLabel()` used by controller + both CE listeners |
| Asset URLs | `Symfony\Component\Asset\Packages` | Respects `base_path` (subdir installs) and `version` (cache-busting) |
| Active highlight | Solid/dashed blue outline + badge | CE solid blue; article dashed blue in dual mode |
| Hover highlight | Fuchsia dashed outline + badge | Any `[data-contao-table]` element; edit icon navigates backend |
| Edit navigation | `clp:edit` postMessage → `Turbo.visit()` | Badge edit icon → backend navigates to article content list or CE edit form |
| CE label source | DOM-first: `data-contao-label` attribute | Frontend hook context has all language files; API fallback unreliable for 3rd-party CEs |
| Visual target | `clpVisTarget()` — col-* unwrap | Outline on inner child when grid wrapper has exactly one child (Design+ pattern) |

---

## Key File Map

| File | Purpose |
|---|---|
| `src/ContaoLivePreviewBundle.php` | Bundle entry point; `getPath()` returns bundle root so Twig finds `templates/` |
| `src/DependencyInjection/ContaoLivePreviewExtension.php` | Loads `services.yaml` |
| `src/ContaoManager/Plugin.php` | Registers bundle + routes with Contao Manager |
| `src/EventListener/InjectLivePreviewListener.php` | `outputBackendTemplate` hook — injects CSS/JS/sidebar into `be_main`; skips popups |
| `src/EventListener/InjectPreviewScriptListener.php` | `KernelEvents::RESPONSE` — injects highlight + hover + link-intercept script before `</body>` when `?_clp=1` |
| `src/EventListener/InjectArticleMarkersListener.php` | `parseFrontendTemplate` hook — auto-injects `data-contao-table`/`data-contao-id` on article wrapper; skips if theme already provides them |
| `src/EventListener/InjectContentElementMarkersListener.php` | `getContentElement` hook — auto-injects `data-contao-table`/`data-contao-id`/`data-contao-label` on CE wrapper; covers legacy `ContentElement` + RSCE |
| `src/EventListener/InjectTwigContentElementMarkersListener.php` | `KernelEvents::RESPONSE` (priority -195) — annotates Twig-first `#[AsContentElement]` CE wrappers via DB lookup + type+position matching |
| `src/Service/LabelCleanerTrait.php` | Shared `cleanLabel()` + `resolveLabel()` used by controller + both CE listeners |
| `src/Service/PreviewUrlResolverInterface.php` | Extension point: implement and alias to add custom table support |
| `src/Service/PreviewUrlResolver.php` | DBAL chain: `tl_content → tl_article → tl_page`; reads `type` for CE label resolution |
| `src/Controller/PreviewResolverController.php` | `GET /contao/live-preview/resolve` → full JSON context incl. `highlightSelectors`, `articleSelectors`, `contentElementLabel` |
| `src/Resources/config/services.yaml` | Autowired services + explicit interface alias |
| `config/routes.yaml` | Route for the resolve endpoint (active); `#[Route]` attribute on controller intentionally absent |
| `templates/backend/live_preview_sidebar.html.twig` | `<aside data-turbo-permanent>` with resizer, toolbar, iframe |
| `public/js/live-preview.js` | Context detection, resolve, iframe management, save detection, resize, `clp:edit` handler |
| `public/css/live-preview.css` | Flex layout overrides, sidebar, toolbar, toggle button, responsive |

---

## Data Flow

```
Turbo navigation (or hard load)
  │
  ├── turbo:before-render  → stamp clp-open on incoming body (no flash)
  └── turbo:render / DOMContentLoaded
        │
        ▼
      onPageReady()
        re-injects #tmenu toggle button
        resets currentContext → triggerResolve()
        │
        ▼
      parseContext()
        reads ?do= ?table= ?id= ?act= from window.location
        returns { table, id } or null
        │
        ▼
      resolveAndShow()  →  GET /contao/live-preview/resolve?table=…&id=…
                                  │
                                  ▼
                            PreviewResolverController
                            • releases session lock (session.save())
                            • delegates to PreviewUrlResolverInterface::resolve()
                                  │
                                  ▼
                            PreviewUrlResolver::resolve()
                            tl_content → pid → tl_article → pid → tl_page
                                  │
                                  ▼
                            buildPreviewUrl() + resolveContentElementLabel()
                            PageModel::findWithDetails()->getAbsoluteUrl()
                            cleanLabel() strips Anfang/Start/Ende/Wrapper suffixes
                                  │
                                  ▼
                            JSON {
                              pageId, pageAlias, previewUrl,
                              articleId, articleTitle,
                              highlightSelectors, articleSelectors,
                              contentElementId, contentElementType, contentElementLabel
                            }
        │
        ├── URL changed  → frame.src = previewUrl + ?_clp=1
        │                  → on load: frameNeedsReload=true, sendHighlight()
        └── URL same     → frameNeedsReload=true, sendHighlight() immediately

sendHighlight()
  postMessage clp:highlight → {
    selectors, articleSelectors, scrollBehavior,
    label, articleLabel,
    articleId, contentElementId          ← IDs for edit-icon click targets
  }

clp:highlight (injected frontend script)
  • findEl(selectors) → CE element (or article if context is tl_article)
  • findEl(articleSelectors) → article element
  • dual mode (CE ≠ article):
      CE → clpVisTarget → solid blue outline + CE badge (getCeLabel reads data-contao-label)
      article → clpVisTarget → dashed blue outline + ARTIKEL badge
  • single mode: one solid outline + one badge

User saves (form submit)
  │
  └── handleFormSubmit() → savePendingState() → tryRehydrate() on next onPageReady

refreshPreview()
  postMessage clp:refresh → { articleId, selectors: articleSelectors, label: 'ARTIKEL' }

clp:refresh (frontend)
  1. fetch(window.location.href)
  2. DOMParser — iterate selector chain until match in both docs
  3. live.replaceWith(fresh) — restore scroll — highlight — post clp:refreshed

Badge edit icon click (active or hover badge)
  postMessage clp:edit → { table, id }
  backend: Turbo.visit(?do=article&table=tl_content[&act=edit]&id=N)

Hover (frontend)
  mouseover → closest [data-contao-table] → clpVisTarget
    → fuchsia dashed outline + fuchsia badge (getCeLabel)
  mouseout  → clear when cursor leaves container element OR badge
  click a[href] → intercept → append ?_clp=1 → navigate (preserves script across pages)
```

### Frontend DOM Requirements

The partial refresh and hover highlighting require `data-contao-*` attributes on wrappers:

```html
<!-- Article wrapper -->
<div data-contao-table="tl_article" data-contao-id="42" id="article-42" ...>
  <!-- Content element wrapper -->
  <div data-contao-table="tl_content" data-contao-id="77" data-contao-label="Icon Liste" ...>
```

**Who provides them:**

| Attributes | Provider | Notes |
|---|---|---|
| `tl_article` data-attrs | `InjectArticleMarkersListener` | All themes; skipped if theme already provides them |
| `tl_article` data-attrs | Design+ `mod_article.html.twig` | Direct template approach; hook skips via `str_contains` guard |
| `tl_content` data-attrs + label | `InjectContentElementMarkersListener` | Legacy `ContentElement` subclasses + RSCE; Twig-first `#[AsContentElement]` not covered |

Fallback chain when data-attrs absent: `#article-{id}` → `#article-{alias}` → `#{cssId}`. DOM-swap skipped when no stable anchor exists; highlight via CSS-ID still works.

### Message Types (postMessage)

| Type | Direction | Key Payload Fields | Purpose |
|---|---|---|---|
| `clp:highlight` | backend → frontend | `selectors`, `articleSelectors`, `scrollBehavior`, `label`, `articleLabel`, `articleId`, `contentElementId` | Scroll + dual/single outline + badges |
| `clp:refresh` | backend → frontend | `articleId`, `selectors`, `label` | Self-fetch + article DOM swap + highlight |
| `clp:refreshed` | frontend → backend | `articleId` | Acknowledgement after DOM swap (or fetch error) |
| `clp:edit` | frontend → backend | `table`, `id` | Badge edit icon clicked — navigate backend to record |

---

## Extending with Custom Table Support

Implement `PreviewUrlResolverInterface` in your bundle and alias it:

```php
// YourBundle/Service/ExtendedPreviewUrlResolver.php
class ExtendedPreviewUrlResolver implements PreviewUrlResolverInterface
{
    public function __construct(
        private readonly PreviewUrlResolver $inner,
        private readonly Connection $db,
    ) {}

    public function resolve(string $table, int $id): ?array
    {
        if ('tl_news' === $table) {
            $row = $this->db->fetchAssociative(
                'SELECT a.pid FROM tl_news n JOIN tl_news_archive a ON a.id = n.pid WHERE n.id = ?',
                [$id],
            );
            return $row ? $this->inner->resolve('tl_page', (int) $row['pid']) : null;
        }

        return $this->inner->resolve($table, $id);
    }
}
```

```yaml
# YourBundle/Resources/config/services.yaml
Vendor\ContaoLivePreviewBundle\Service\PreviewUrlResolverInterface:
    alias: YourVendor\YourBundle\Service\ExtendedPreviewUrlResolver
```

Add the new context to `parseContext()` in `live-preview.js`:
```js
if (doV === 'news' && id > 0) {
    return { table: 'tl_news', id };
}
```

---

## Known Gotchas

- **`cache:warmup` required** after any template/config change. `cache:clear` alone does not register the `@ContaoLivePreview` Twig namespace.
- **Session lock**: the resolve controller calls `$session->save()` immediately to release the PHP file-session lock, preventing Turbo navigation requests from blocking behind the AJAX call.
- **Popup exclusion**: checked server-side via `?popup=1` and `?picker`. If Contao adds new popup conventions, add them to `InjectLivePreviewListener`.
- **Asset symlink**: Symfony derives the symlink name as `contaolivepreview` (lowercase, no separators). CSS/JS paths in the listener use this name. Run `bin/console assets:install` after first install.
- **Non-routable pages**: `buildPreviewUrl()` walks up the ancestor tree when it encounters `error_404`, `folder`, or `root` page types.
- **clp:refresh stale cache**: the frontend fetches `window.location.href` after save. If a CDN or reverse proxy caches frontend pages aggressively and hasn't invalidated yet, the DOM swap may show stale content.
- **tl_page context**: `currentArticleId` is null → `refreshPreview()` returns early. Page-level edits require a manual sidebar reload.
- **Full page reload detection**: `getCleanSrc()` returns `''` on iframe empty (full reload) vs. non-empty (survived Turbo body-swap). `tryRehydrate()` uses this to pick the correct rehydration path.
- **`clp_pending_save` TTL**: expires after 30 s. State discarded if backend navigation takes longer.
- **Twig-first CE type+position matching**: `InjectTwigContentElementMarkersListener` annotates CEs by matching the Nth `ce_{type}` HTML element to the Nth DB record of that type (sorted by article+content sorting). This breaks in multi-column layouts where the HTML column order differs from the DB order, and when nested CEs (e.g. accordion content) add extra occurrences of a type. CEs with a `cssId` set are always matched exactly via `id="cssId"` — use cssId on affected CEs as a workaround.
- **CE label backend vs. frontend**: the resolve controller may not find a CTE label for third-party CEs if their language file isn't loaded in backend context. The controller returns `''` (never the raw type key); the frontend falls back to `data-contao-label` from the DOM, which is always correct because the hook runs in fully-bootstrapped frontend context.
- **Badge reposition**: badges are `position:absolute` in `<body>` and are repositioned on `window.resize` (triggered by sidebar drag or zoom change). They do NOT reposition on scroll — `scrollY + getBoundingClientRect().top` is document-absolute and stays correct.
- **Visual target (col-* unwrap)**: `clpVisTarget()` checks for `col-*` class + single child. If a grid wrapper has multiple children (edge case), the wrapper itself is used as visual target — no data is lost.

---

## Backlog

- [ ] Support for `do=news`, `do=calendar` etc. — via the `PreviewUrlResolverInterface` extension point
- [ ] Per-user sidebar width stored in `tl_user` (currently localStorage only)
- [ ] `FrontendPreviewAuthenticator` integration to show unpublished content reliably
- [ ] **Open-source release** — rename namespace `Vendor\ContaoLivePreviewBundle` → `ThinkDigital\ContaoLivePreview`, package name → `think-digital-agency/contao-live-preview`, add LICENSE (LGPL-3.0), README.md, translate UI labels (currently German), remove CLAUDE.md; extract to own GitHub repo via `git filter-repo --subdirectory-filter`; register on Packagist; Contao Extension Store picks up automatically.
- [ ] Per-user sidebar width stored in `tl_user` (currently localStorage only)
- [ ] `FrontendPreviewAuthenticator` integration to show unpublished content reliably
- [ ] Support for `do=news`, `do=calendar` etc. — via the `PreviewUrlResolverInterface` extension point
- [x] Article-level partial refresh with selector chain fallback (any theme)
- [x] Theme-independent marker injection via `parseFrontendTemplate` + `getContentElement` hooks
- [x] Twig-first `#[AsContentElement]` CE annotation via `KernelEvents::RESPONSE` post-processor
- [x] Dual-mode CE + article highlighting (solid/dashed blue, separate badges)
- [x] Edit badges with pencil icon — click navigates backend to article content list or CE edit form
- [x] Interactive hover highlighting — fuchsia dashed outline + badge for any `[data-contao-table]` element
- [x] CE type labels from `data-contao-label` DOM attribute (DOM-first, always correct)
- [x] Visual target unwrapping for `col-*` single-child grid wrappers (Design+ pattern)
- [x] Link intercept — `?_clp=1` preserved across iframe navigation
- [x] Badge reposition on sidebar resize and zoom change
