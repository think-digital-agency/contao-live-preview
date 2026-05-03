# CLAUDE.md – ContaoLivePreviewBundle

## Project Identity

**Package:** `vendor/contao-live-preview-bundle`
**Target:** Contao 5.3 LTS+ / Symfony 6.4+
**Purpose:** Adds a collapsible, context-aware live frontend preview sidebar to the Contao 5 backend. When an editor opens a page, article, or content element, an iframe on the right side of the screen shows the corresponding frontend page in real time. Saving a record refreshes the iframe without reloading the backend.

**Location:** Lives at `packages/contao-live-preview-bundle/` inside the Design+ theme project (`rl-contao-theme-design`). Installed into the main Contao app via a `path` Composer repository — see INSTALL.md.

---

## Architecture Decisions (quick reference)

| Decision | Choice | Rationale |
|---|---|---|
| JS approach | Vanilla JS, no build step | Contao ships Stimulus but adding the build pipeline to a theme project adds friction. Vanilla IIFE works fine for a ~300-line script. See ADR-001. |
| Template injection | `outputBackendTemplate` hook | Contao 5 hook is scoped cleanly to `be_main`; no risk of injecting into popups or login pages. KernelEvents::RESPONSE would require a response body check and is harder to scope. See ADR-002. |
| Preview URL | `/preview.php/{language}/{alias}.html` | Contao's built-in preview entry point inherits the backend session cookie (same origin). No token generation needed. Full `FrontendPreviewAuthenticator` integration is a backlog item. See ADR-003. |
| Sidebar implementation | CSS fixed panel + CSS custom property | Pure CSS sidebar with `margin-right` on `#wrapper`. Avoids iframes inside split-pane libraries. Drag-to-resize handled with a mousedown listener. See ADR-004. |

---

## Key File Map

| File | Purpose | Feature |
|---|---|---|
| `src/ContaoLivePreviewBundle.php` | Bundle entry point, sets `getPath()` to bundle root | Core |
| `src/DependencyInjection/ContaoLivePreviewExtension.php` | Loads `services.yaml` | Core |
| `src/ContaoManager/Plugin.php` | Contao Manager plugin: registers bundle + routes | Core |
| `src/EventListener/InjectLivePreviewListener.php` | `outputBackendTemplate` hook → injects CSS, JS, sidebar HTML into `be_main` | Feature 1 |
| `src/Service/PreviewUrlResolver.php` | DBAL resolution: `tl_content → tl_article → tl_page` | Feature 3 |
| `src/Controller/PreviewResolverController.php` | GET `/contao/live-preview/resolve` → JSON `{pageId, pageAlias, previewUrl}` | Feature 3 |
| `src/Resources/config/services.yaml` | Autowired service registration | Core |
| `config/routes.yaml` | Route definition for the resolve endpoint | Feature 3 |
| `templates/backend/live_preview_sidebar.html.twig` | Sidebar HTML (toolbar, iframe, placeholders) | Feature 1 |
| `public/js/live-preview.js` | Context detection, AJAX resolve, iframe management, save detection, resize | Features 2, 4, 5, 6 |
| `public/css/live-preview.css` | Sidebar layout, toolbar, responsive breakpoints | Feature 1, 6 |

---

## Data Flow

```
Backend URL change (or page load)
  │
  ▼
live-preview.js: parseContext()
  Reads ?do=, ?table=, ?id=, ?act= from window.location.search
  Returns { table: 'tl_content'|'tl_article'|'tl_page', id: N }
  │
  ▼
resolvePreviewUrl()  →  GET /contao/live-preview/resolve?table=…&id=…
  │                         │
  │                         ▼
  │                   PreviewResolverController
  │                         │
  │                         ▼
  │                   PreviewUrlResolver::resolve()
  │                     tl_content → pid → tl_article → pid → tl_page
  │                     Returns { pageId, alias, language, dns }
  │                         │
  │                         ▼
  │                   Controller builds /preview.php/{lang}/{alias}.html
  │                   Returns JSON { pageId, pageAlias, previewUrl }
  │
  ▼
setIframeSrc(previewUrl) — updates #clp-frame src
  │
  ▼
User edits form and clicks Save
  │
  ├── handleFormSubmit() → setTimeout(reloadPreview, 900ms)
  └── observeSaveFlash() → MutationObserver watches for .tl_confirm
        → scheduleResolve() + setTimeout(reloadPreview, 400ms)
```

---

## Known Gotchas & Constraints

### Contao 5 specifics
- **Asset symlinks:** Running `bin/console assets:install` is required after first install to symlink `public/` assets into `public/bundles/contaolivepreview/`. Symfony derives the symlink name from the bundle class: `ContaoLivePreviewBundle` → `contaolivepreview`. The CSS/JS paths in `InjectLivePreviewListener` already use this correct name.
- **`outputBackendTemplate` hook:** Only fires for PHP-rendered `.html5`-style templates rendered via `Contao\BackendTemplate`. Contao 5's `be_main` is still rendered this way. If a future Contao version migrates `be_main` to a Twig template the hook will no longer fire and injection must switch to a `KernelEvents::RESPONSE` listener.
- **`cache:warmup` required:** After any template change or first install, `bin/console cache:clear && bin/console cache:warmup` is mandatory. Without warmup, the `@ContaoLivePreview` Twig namespace is not registered and the sidebar template render will throw.
- **Twig namespace:** The bundle's `templates/` directory is registered as `@ContaoLivePreview` by Contao's bundle loader. The `InjectLivePreviewListener` renders `@ContaoLivePreview/backend/live_preview_sidebar.html.twig`.

### Browser security
- **iframe `sandbox` attribute:** The iframe uses `sandbox="allow-same-origin allow-scripts allow-forms allow-popups"`. Since both the backend and frontend are served from the same origin (`localhost:8080`), same-origin access works. On production with separate subdomains (e.g. `cms.example.com` vs `www.example.com`) same-origin `sandbox` breaks — `allow-same-origin` must be kept AND the preview URL must be on the same origin, which it is via `/preview.php`.
- **CSP:** If a `Content-Security-Policy` header restricts `frame-src`, the iframe will be blocked. The existing `nelmio_security` config in this project sets `clickjacking: ALLOW` globally, so this should not be an issue here.

### JS DOM assumptions
- `#wrapper` — Contao backend's main layout wrapper (used for `margin-right` offset)
- `#main` — Contao backend main content container (MutationObserver target)
- `.tl_confirm` — Contao's success flash message class (save detection)
- `[name="FORM_SUBMIT"]` — hidden field present in all Contao edit forms

---

## How to Extend

### Add support for a new `do=` context

In `live-preview.js`, extend `parseContext()`:
```js
if (doVal === 'my_module' && id > 0) {
    return { table: 'tl_my_table', id, do: doVal };
}
```

In `PreviewUrlResolver::resolve()`, add a new match arm:
```php
'tl_my_table' => $this->resolveFromMyTable($id),
```

Add the `resolveFromMyTable()` method using DBAL to walk up to `tl_page`.

### Add a new parent-chain resolution

Follow the same DBAL pattern as `resolveFromContent()`:
1. SELECT `pid` from source table
2. Pass to the next resolver in the chain
3. Final resolver must return the `tl_page` row array

---

## Current Status

### Implemented ✓
- Bundle skeleton (PHP classes, DI extension, Contao Manager plugin)
- `outputBackendTemplate` hook → injects sidebar CSS, JS, HTML into `be_main`
- AJAX resolver endpoint: `GET /contao/live-preview/resolve`
- DBAL resolver: `tl_content → tl_article → tl_page`, `tl_article → tl_page`, `tl_page`
- Preview URL construction via `/preview.php/{lang}/{alias}.html`
- Vanilla JS: context parsing, AJAX resolve, iframe management
- Save detection: form submit + `.tl_confirm` MutationObserver
- Navigation detection: title MutationObserver + `#main` childList + popstate
- Sidebar CSS: fixed panel, toolbar, responsive breakpoints, smooth toggle
- Drag-to-resize handle with localStorage persistence
- Floating toggle button (visible when collapsed)
- `localStorage` persistence for open/closed state and sidebar width
- Twig sidebar template with toolbar, iframe, loading spinner, no-context placeholder

### In Progress
- (nothing currently in progress — initial implementation complete)

### Not Yet Started
- [ ] Resizable sidebar width stored per-user in backend (currently localStorage only)
- [ ] Support for `do=news` (tl_news → tl_page via tl_news_archive)
- [ ] Full `FrontendPreviewAuthenticator` token-based preview (shows unpublished content reliably)
- [ ] Backend toggle button in the Contao toolbar (via `BACKEND_MENU_BUILD` event)
- [ ] Integration test coverage

### Open Questions / Blockers
- The Symfony asset bundle symlink name must be verified after first `assets:install` run — the lowercase truncation of `ContaoLivePreviewBundle` may produce a different path than `contaoliveprevie`.
- Preview of unpublished content requires the frontend session to have the Contao preview flag set. Using `/preview.php` should work if the backend user's session is shared with the frontend (same Symfony app), but this needs verification on the actual dev environment.
