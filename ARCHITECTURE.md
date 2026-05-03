# ARCHITECTURE.md – ContaoLivePreviewBundle

## Directory Tree

```
packages/contao-live-preview-bundle/
├── composer.json
├── config/
│   └── routes.yaml                             # Route: GET /contao/live-preview/resolve
├── public/
│   ├── css/
│   │   └── live-preview.css                    # Sidebar layout & styles
│   └── js/
│       └── live-preview.js                     # Context detection, iframe, save hooks
├── src/
│   ├── ContaoLivePreviewBundle.php             # Bundle entry point
│   ├── ContaoManager/
│   │   └── Plugin.php                          # BundlePlugin + RoutingPlugin
│   ├── Controller/
│   │   └── PreviewResolverController.php       # AJAX endpoint
│   ├── DependencyInjection/
│   │   └── ContaoLivePreviewExtension.php      # DI extension, loads services.yaml
│   ├── EventListener/
│   │   └── InjectLivePreviewListener.php       # outputBackendTemplate hook
│   ├── Resources/
│   │   └── config/
│   │       └── services.yaml                   # Autowired services + interface alias
│   └── Service/
│       ├── PreviewUrlResolverInterface.php     # Extension point for third-party bundles
│       └── PreviewUrlResolver.php              # DBAL parent-chain resolver
└── templates/
    └── backend/
        └── live_preview_sidebar.html.twig      # Sidebar HTML (data-turbo-permanent)
```

---

## Symfony Service Graph

| Service | Class | Dependencies |
|---|---|---|
| `InjectLivePreviewListener` | `EventListener\InjectLivePreviewListener` | `Twig\Environment`, `RequestStack` |
| `PreviewResolverController` | `Controller\PreviewResolverController` | `PreviewUrlResolverInterface`, `ContaoFramework` |
| `PreviewUrlResolver` | `Service\PreviewUrlResolver` | `Doctrine\DBAL\Connection` |

All services are autowired and autoconfigured via the `Vendor\ContaoLivePreviewBundle\` resource scan. `PreviewUrlResolverInterface` is explicitly aliased to `PreviewUrlResolver` in `services.yaml` so third-party bundles can override it.

---

## Routes

| Method | Path | Controller | Auth | Scope |
|---|---|---|---|---|
| GET | `/contao/live-preview/resolve` | `PreviewResolverController` | `ROLE_USER` | `_scope: backend` |

Query parameters:
- `table` — source database table (`tl_content`, `tl_article`, `tl_page`)
- `id` — record ID

Response: `application/json`
```json
{
  "pageId": 42,
  "pageAlias": "home",
  "previewUrl": "http://localhost:8080/de/home.html"
}
```

The `previewUrl` is built via `PageModel::findWithDetails($pageId)->getAbsoluteUrl()`, which inherits urlPrefix, language, and domain from the full page hierarchy. Non-routable page types (`error_404`, `folder`, `root`) are skipped by walking up to the nearest routable ancestor.

---

## Contao Hooks

| Hook | Listener | Purpose |
|---|---|---|
| `outputBackendTemplate` | `InjectLivePreviewListener` | Injects sidebar HTML + CSS/JS into `be_main` output buffer. Skips when `?popup=1` or `?picker` is present. |

---

## Database Tables (read-only)

| Table | Fields read | Purpose |
|---|---|---|
| `tl_content` | `id`, `pid` | Walk up to parent article |
| `tl_article` | `id`, `pid` | Walk up to parent page |
| `tl_page` | `id`, `alias` | Identify the page; URL built via Contao's PageModel, not raw fields |

---

## localStorage Keys

| Key | Value | Purpose |
|---|---|---|
| `clp_sidebar_open` | `'0'` / `'1'` | Persist open/closed state across sessions |
| `clp_sidebar_width` | integer (px) | Persist drag-resized sidebar width |
