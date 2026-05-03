# ARCHITECTURE.md – ContaoLivePreviewBundle

## Directory Tree

```
packages/contao-live-preview-bundle/
├── composer.json
├── config/
│   └── routes.yaml                         # Route: GET /contao/live-preview/resolve
├── public/
│   ├── css/
│   │   └── live-preview.css                # Sidebar layout & styles
│   └── js/
│       └── live-preview.js                 # Context detection, iframe, save hooks
├── src/
│   ├── ContaoLivePreviewBundle.php         # Bundle entry point
│   ├── ContaoManager/
│   │   └── Plugin.php                      # BundlePlugin + RoutingPlugin
│   ├── Controller/
│   │   └── PreviewResolverController.php   # AJAX endpoint
│   ├── DependencyInjection/
│   │   └── ContaoLivePreviewExtension.php  # DI extension, loads services.yaml
│   ├── EventListener/
│   │   └── InjectLivePreviewListener.php   # outputBackendTemplate hook
│   ├── Resources/
│   │   └── config/
│   │       └── services.yaml               # Autowired service registration
│   └── Service/
│       └── PreviewUrlResolver.php          # DBAL parent-chain resolver
└── templates/
    └── backend/
        └── live_preview_sidebar.html.twig  # Sidebar HTML
```

---

## Symfony Service Graph

| Service | Class | Dependencies | Notes |
|---|---|---|---|
| `InjectLivePreviewListener` | `EventListener\InjectLivePreviewListener` | `Twig\Environment` | Tagged via `#[AsHook]` attribute |
| `PreviewResolverController` | `Controller\PreviewResolverController` | `PreviewUrlResolver`, `ContaoFramework` | Tagged as controller via autoconfigure |
| `PreviewUrlResolver` | `Service\PreviewUrlResolver` | `Doctrine\DBAL\Connection` | Pure service, no Contao framework needed |

All services are autowired and autoconfigured via the `Vendor\ContaoLivePreviewBundle\` resource scan in `services.yaml`.

---

## Routes

| Method | Path | Controller | Auth | Scope |
|---|---|---|---|---|
| GET | `/contao/live-preview/resolve` | `PreviewResolverController` | `ROLE_USER` (backend) | `_scope: backend` |

Query parameters:
- `table` — the source database table (e.g. `tl_content`, `tl_article`, `tl_page`)
- `id` — the record ID
- `do` _(optional)_ — the Contao backend `do` param; used as fallback to infer `table`

Response: `application/json`
```json
{
  "pageId": 42,
  "pageAlias": "home",
  "language": "de",
  "previewUrl": "/preview.php/de/home.html"
}
```

---

## Contao Hooks & Event Listeners

| Hook / Event | Listener Class | Priority | Purpose |
|---|---|---|---|
| `outputBackendTemplate` | `InjectLivePreviewListener` | default | Injects sidebar HTML + CSS/JS tags into `be_main` output buffer |

The hook is registered via `#[AsHook('outputBackendTemplate')]` attribute, which Contao 5 resolves through the `contao.hook` service tag (applied automatically by `autoconfigure: true`).

---

## Database Tables Used

The bundle **reads only** — it never writes to the database.

### `tl_content`
| Field | Usage |
|---|---|
| `id` | Lookup by edit ID |
| `pid` | Parent article ID → used to walk up to `tl_article` |

### `tl_article`
| Field | Usage |
|---|---|
| `id` | Lookup by resolved pid or direct edit ID |
| `pid` | Parent page ID → used to walk up to `tl_page` |

### `tl_page`
| Field | Usage |
|---|---|
| `id` | Final resolved page ID, returned in JSON |
| `alias` | Used to build the frontend preview URL path |
| `language` | Used as URL language prefix |
| `dns` | Stored but not currently used in URL construction (preview.php uses app router) |

---

## Frontend Preview URL Format

```
/preview.php/{language}/{alias}.html
```

Example: page with `alias=home`, `language=de` → `/preview.php/de/home.html`

If `language` is empty: `/preview.php/{alias}.html`

**Why `/preview.php`:** Contao ships a `preview.php` front controller that bypasses the frontend authentication check and shares the Symfony session. Since the backend and frontend are the same Symfony app on the same origin, the backend session cookie is valid for `/preview.php` requests, which means the Contao `FrontendPreviewAuthenticator` will mark the request as a preview request and render unpublished content.

**No explicit token parameter** is used currently. If future Contao versions require an explicit token, `FrontendPreviewAuthenticator::authenticateFrontendGuest()` must be called from the controller and the resulting token embedded in the URL.

---

## CSS Architecture

### Selectors & layout strategy

| Selector | Purpose |
|---|---|
| `:root` | CSS custom properties: `--clp-sidebar-width`, colors, z-index |
| `#wrapper` | Contao backend main layout wrapper — gets `margin-right: var(--clp-sidebar-width)` |
| `.clp-sidebar` | The fixed sidebar panel (position: fixed, right: 0) |
| `.clp-sidebar--collapsed` | Modifier: `transform: translateX(100%)` hides sidebar off-screen |
| `.clp-sidebar__toolbar` | 44px toolbar row (dark background) |
| `.clp-sidebar__url-input` | Read-only URL display inside toolbar |
| `.clp-sidebar__body` | flex-column container: takes remaining height |
| `.clp-sidebar__iframe` | The preview `<iframe>`, fills body |
| `.clp-sidebar__loading` | Overlay with spinner, shown while iframe loads |
| `.clp-sidebar__placeholder` | Shown when no context is resolvable |
| `.clp-floating-toggle` | Fixed FAB (bottom-right), visible only when sidebar is collapsed |
| `.clp-sidebar__resizer` | 8px invisible hit-target on left edge for drag-to-resize |

### Contao backend classes depended upon
- `#wrapper` — must exist in the Contao 5 backend `be_main` template (verified for Contao 5.7)
- `.tl_confirm` — Contao success flash class (used by JS, not CSS)

### Responsive breakpoints
- `≥ 1400px` — sidebar pushes content via `margin-right` (full layout mode)
- `< 1400px` — sidebar overlays content (no margin-right; avoids horizontal overflow)
- `< 768px` — sidebar takes full viewport width

---

## JS Architecture

### DOM events observed

| Event | Target | Purpose |
|---|---|---|
| `DOMContentLoaded` | `document` | Initialise, restore state, bind events |
| `submit` | `document` | Detect Contao form saves; schedule iframe reload |
| `popstate` | `window` | Detect history navigation |
| `mousedown/mousemove/mouseup` | `document` | Drag-to-resize sidebar handle |

### MutationObserver targets

| Target | Observation | Purpose |
|---|---|---|
| `<title>` in `<head>` | `childList` | Detect backend partial navigation (Contao JS updates title) |
| `#main` | `childList` (not subtree) | Detect main content replacement after save/navigation |
| `document.body` | `childList, subtree: true` | Detect `.tl_confirm` flash message after save |

### localStorage keys

| Key | Value | Purpose |
|---|---|---|
| `clp_sidebar_open` | `'0'` or `'1'` | Persists open/collapsed state across sessions |
| `clp_sidebar_width` | integer string (px) | Persists drag-resized width |

### Resolve debounce
Navigation changes are debounced by 300ms before the AJAX resolve call fires. This prevents multiple simultaneous requests when Contao's JS makes several DOM changes in rapid succession.
