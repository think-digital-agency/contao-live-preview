# CLAUDE.md – ContaoLivePreviewBundle

## Project Identity

**Package:** `vendor/contao-live-preview-bundle`
**Target:** Contao 5.5+ / Symfony 7.x
**Purpose:** Adds a collapsible, context-aware live frontend preview sidebar to the Contao 5 backend. When an editor opens a page, article, or content element, an iframe on the right side shows the corresponding frontend page. Saving a record triggers an article-level DOM swap in the iframe — no full reload, scroll position stays intact.

**Location:** `packages/contao-live-preview-bundle/` inside the Design+ theme project. Installed via a `path` Composer repository.

---

## Architecture Decisions

| Decision | Choice | Rationale |
|---|---|---|
| JS | Vanilla IIFE, no build step | Keeps the bundle dependency-free and easy to drop into any Contao project |
| Injection | `outputBackendTemplate` hook on `be_main` | Tightly scoped — never fires for popups, pickers, or login |
| Popup exclusion | Server-side check: `?popup=1` / `?picker` | Cleanest gate; no client-side fallback needed |
| Sidebar persistence | `data-turbo-permanent` on `#clp-right` | Turbo moves the sidebar (with live iframe) to every new body — zero white-flash on navigation |
| Flash prevention | `turbo:before-render` pre-stamps `clp-open` on incoming body | Without this, `body.clp-open` is absent for one paint after body swap |
| Preview URL | `PageModel::findWithDetails()->getAbsoluteUrl()` | Inherits urlPrefix, language, and domain from the root page hierarchy |
| Partial refresh after save | `clp:refresh` postMessage → frontend self-fetch + DOMParser swap | No full iframe reload — scroll position preserved, only the edited article node is replaced |
| Resize | Pointer Events + `setPointerCapture` | Receives `pointermove`/`pointerup` even when cursor leaves the browser window |
| Extensibility | `PreviewUrlResolverInterface` | Third-party bundles alias the interface to add support for news, events, custom models |
| Highlight injection | `?_clp=1` + `KernelEvents::RESPONSE` listener | Frontend script injected ephemerally into the response — zero impact on normal page output |

---

## Key File Map

| File | Purpose |
|---|---|
| `src/ContaoLivePreviewBundle.php` | Bundle entry point; `getPath()` returns bundle root so Twig finds `templates/` |
| `src/DependencyInjection/ContaoLivePreviewExtension.php` | Loads `services.yaml` |
| `src/ContaoManager/Plugin.php` | Registers bundle + routes with Contao Manager |
| `src/EventListener/InjectLivePreviewListener.php` | `outputBackendTemplate` hook — injects CSS/JS/sidebar into `be_main`; skips popups |
| `src/EventListener/InjectPreviewScriptListener.php` | `KernelEvents::RESPONSE` — injects highlight+refresh script into frontend pages when `?_clp=1` |
| `src/Service/PreviewUrlResolverInterface.php` | Extension point: implement and alias to add custom table support |
| `src/Service/PreviewUrlResolver.php` | DBAL chain: `tl_content → tl_article → tl_page` |
| `src/Controller/PreviewResolverController.php` | `GET /contao/live-preview/resolve` → `{pageId, articleId, pageAlias, previewUrl, highlightSelectors}` |
| `src/Resources/config/services.yaml` | Autowired services + explicit interface alias |
| `config/routes.yaml` | Route for the resolve endpoint |
| `templates/backend/live_preview_sidebar.html.twig` | `<aside data-turbo-permanent>` with resizer, toolbar, iframe |
| `public/js/live-preview.js` | Context detection, resolve, iframe management, save detection, resize |
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
        observes new document.body for .tl_confirm
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
                            • releases session lock immediately (session.save())
                            • delegates to PreviewUrlResolverInterface::resolve()
                                  │
                                  ▼
                            PreviewUrlResolver::resolve()
                            tl_content → pid → tl_article → pid → tl_page
                                  │
                                  ▼
                            buildPreviewUrl()
                            PageModel::findWithDetails()->getAbsoluteUrl()
                            walks up to nearest routable page type
                                  │
                                  ▼
                            JSON { pageId, articleId, pageAlias, previewUrl, highlightSelectors }
        │
        ├── if URL changed  → frame.src = previewUrl + ?_clp=1 (fresh load)
        │                      → on load: frameNeedsReload=true, sendHighlight() via postMessage
        └── if URL same     → frameNeedsReload=true, sendHighlight() immediately

User saves (form submit)
  │
  ├── handleFormSubmit()      → setTimeout(refreshPreview, 900ms)
  └── observeSaveFlash()      → detects .tl_confirm → setTimeout(refreshPreview, 400ms)

refreshPreview()
  • guards: frameNeedsReload must be true AND currentArticleId must be set
  • sends postMessage({ type: 'clp:refresh', articleId, selectors }) to iframe
  • NO iframe src change — scroll position preserved natively

clp:refresh handler (injected frontend script, ?_clp=1)
  1. fetch(window.location.href)           — same-origin, no CORS
  2. DOMParser parses full page HTML
  3. find [data-contao-table="tl_article"][data-contao-id="{articleId}"] in parsed doc
  4. replace live DOM node with fresh node
  5. restore scroll position, apply highlight animation
  6. post clp:refreshed back to parent window
```

### Frontend DOM Requirements

The partial refresh mechanism requires the frontend article template to emit:
```html
<div data-contao-table="tl_article" data-contao-id="42" id="article-42" ...>
```

This is provided by `templates/theme-design/mod_article.html.twig` (Design+ theme).
For other themes, add these attributes to the article wrapper in `mod_article.html.twig`.

### Message Types (postMessage)

| Type | Direction | Payload | Purpose |
|---|---|---|---|
| `clp:highlight` | backend → frontend | `{ selectors, scrollBehavior }` | Scroll to element + flash orange outline |
| `clp:refresh` | backend → frontend | `{ articleId, selectors }` | Fetch page, swap article DOM node, then highlight |
| `clp:refreshed` | frontend → backend | `{ articleId }` | Acknowledgement after DOM swap completes |

---

## Extending with Custom Table Support

Implement `PreviewUrlResolverInterface` in your bundle and alias it:

```php
// YourBundle/Service/ExtendedPreviewUrlResolver.php
class ExtendedPreviewUrlResolver implements PreviewUrlResolverInterface
{
    public function __construct(
        private readonly PreviewUrlResolver $inner, // the built-in resolver
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
- **Popup exclusion**: checked server-side via `?popup=1` and `?picker`. If Contao adds new popup conventions in future versions, add them to `InjectLivePreviewListener`.
- **Asset symlink**: Symfony derives the symlink name as `contaolivepreview` (lowercase, no separators). CSS/JS paths in the listener use this name. Run `bin/console assets:install` after first install.
- **Non-routable pages**: `buildPreviewUrl()` walks up the ancestor tree when it encounters `error_404`, `folder`, or `root` page types.
- **clp:refresh and frontend caching**: `refreshPreview()` sends a `clp:refresh` postMessage; the frontend then fetches `window.location.href` (which includes `?_clp=1`). If Contao's page cache has not been invalidated by the save yet, the fetched HTML may be stale. Contao normally clears affected page caches on save — if a CDN or reverse proxy caches frontend pages aggressively, the DOM swap may show stale content.
- **tl_page context**: when editing a page (no article), `currentArticleId` is null. `refreshPreview()` returns early — no DOM swap, no visual update. This is intentional: page-level edits (metadata, title, layout) affect the whole page and there's no stable article node to swap.

---

## Backlog

- [ ] Support for `do=news`, `do=calendar` etc. — via the `PreviewUrlResolverInterface` extension point
- [x] Frontend element scroll + highlight: `?_clp=1` triggers `InjectPreviewScriptListener` which injects a `postMessage` listener + CSS animation. Backend sends `{ type: 'clp:highlight', selectors: [...] }`. Frontend scrolls and fades-out an orange outline on the article. Content elements scroll to their parent article. Primary selector uses `data-contao-table`/`data-contao-id` (stable), CSS-ID selectors kept as fallback.
- [x] Article-level partial refresh: save triggers `clp:refresh` postMessage → frontend self-fetch + DOMParser swap, no full iframe reload, scroll position preserved. Requires `data-contao-table="tl_article"` + `data-contao-id` on the article wrapper (emitted by Design+ `mod_article.html.twig`).
- [ ] Per-content-element highlight: needs theme to emit `data-contao-table="tl_content"` + `data-contao-id` on `ce_*` wrappers; `refreshPreview` would then swap at content-element granularity
- [ ] Per-user sidebar width stored in `tl_user` (currently localStorage only)
- [ ] `FrontendPreviewAuthenticator` integration to show unpublished content reliably
- [ ] **Open-source release** — rename namespace `Vendor\ContaoLivePreviewBundle` → `ThinkDigital\ContaoLivePreview`, package name → `think-digital-agency/contao-live-preview`, add LICENSE (LGPL-3.0), README.md, remove CLAUDE.md; extract to own GitHub repo (`think-digital-agency/contao-live-preview`) via `git filter-repo --subdirectory-filter`; register on Packagist with webhook; Contao Extension Store picks up automatically. Full step-by-step plan in session history (2026-05-04).
