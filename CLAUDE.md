# CLAUDE.md – ContaoLivePreviewBundle

## Project Identity

**Package:** `vendor/contao-live-preview-bundle`
**Target:** Contao 5.5+ / Symfony 7.x
**Purpose:** Adds a collapsible, context-aware live frontend preview sidebar to the Contao 5 backend. When an editor opens a page, article, or content element, an iframe on the right side shows the corresponding frontend page. Saving a record refreshes the iframe in-place (cache-busted, scroll position preserved).

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
| Reload after save | Cache-buster `?_r=<timestamp>` on iframe src | More reliable than `location.reload()` (works on loading iframes, bypasses bfcache) |
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
| `src/EventListener/InjectPreviewScriptListener.php` | `KernelEvents::RESPONSE` — injects highlight script into frontend pages when `?_clp=1` |
| `src/Service/PreviewUrlResolverInterface.php` | Extension point: implement and alias to add custom table support |
| `src/Service/PreviewUrlResolver.php` | DBAL chain: `tl_content → tl_article → tl_page` |
| `src/Controller/PreviewResolverController.php` | `GET /contao/live-preview/resolve` → `{pageId, pageAlias, previewUrl}` |
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
                            JSON { pageId, pageAlias, previewUrl }
        │
        ├── if URL changed  → frame.src = previewUrl + ?_clp=1 (fresh load, frameNeedsReload=false)
        │                      → on load: sendHighlight() via postMessage
        └── if URL same     → frameNeedsReload = true (stale content, reload on next save)
                               → sendHighlight() immediately (page already loaded)

User saves (form submit)
  │
  ├── handleFormSubmit()      → setTimeout(reloadPreview, 900ms)
  └── observeSaveFlash()      → detects .tl_confirm → setTimeout(reloadPreview, 400ms)

reloadPreview()
  • guards: frameNeedsReload must be true
  • sets frame.src = previewUrl + ?_r=<timestamp> + ?_clp=1
  • on load: restores scroll position (if saved), then sendHighlight()
```

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
- **`_r` param and frontend caching**: the cache-buster param bypasses bfcache and Contao page cache. If a frontend CDN or reverse proxy is in use, the `_r` param creates unique cache keys — acceptable for a backend-only tool.

---

## Backlog

- [ ] Support for `do=news`, `do=calendar` etc. — via the `PreviewUrlResolverInterface` extension point
- [x] Frontend element scroll + highlight: `?_clp=1` triggers `InjectPreviewScriptListener` which injects a `postMessage` listener + CSS animation. Backend sends `{ type: 'clp:highlight', selector: '#article-{id}' }`. Frontend scrolls and fades-out an orange outline on the article. Content elements scroll to their parent article.
- [ ] Per-content-element highlight: needs theme to emit `data-clp-id` on `ce_*` wrappers (separate concern)
- [ ] Per-user sidebar width stored in `tl_user` (currently localStorage only)
- [ ] `FrontendPreviewAuthenticator` integration to show unpublished content reliably
