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
│       └── live-preview.js                     # Context detection, iframe, save hooks, clp:edit/duplicate/insert-after
├── src/
│   ├── ContaoLivePreviewBundle.php             # Bundle entry point
│   ├── ContaoManager/
│   │   └── Plugin.php                          # BundlePlugin + RoutingPlugin
│   ├── Controller/
│   │   └── PreviewResolverController.php       # AJAX endpoint
│   ├── DependencyInjection/
│   │   └── ContaoLivePreviewExtension.php      # DI extension, loads services.yaml
│   ├── EventListener/
│   │   ├── InjectLivePreviewListener.php       # outputBackendTemplate hook; asset URLs via Packages, backend URL via contao_backend route
│   │   ├── InjectPreviewScriptListener.php     # KernelEvents::RESPONSE (-200) — injects highlight+hover script
│   │   ├── InjectArticleMarkersListener.php    # parseFrontendTemplate hook — auto-injects article data-attrs
│   │   ├── InjectContentElementMarkersListener.php # getContentElement hook — legacy CEs + RSCE
│   │   ├── InjectTwigContentElementMarkersListener.php # KernelEvents::RESPONSE (-195) — Twig-first CEs
│   │   └── InjectModuleMarkersListener.php     # getFrontendModule hook — layout modules (Header, Nav, Footer, …)
│   ├── Resources/
│   │   └── config/
│   │       └── services.yaml                   # Autowired services + resolver-chain tag/alias
│   └── Service/
│       ├── LabelCleanerTrait.php               # Shared cleanLabel() + resolveLabel() for CE listeners + controller
│       ├── PreviewUrlResolverInterface.php     # Extension point for third-party bundles (tagged into the chain)
│       ├── ChainPreviewUrlResolver.php         # Runs all tagged resolvers by priority, first non-null wins
│       └── PreviewUrlResolver.php              # DBAL parent-chain resolver (+ generic DCA ptable walk); chain fallback
└── templates/
    └── backend/
        └── live_preview_sidebar.html.twig      # Sidebar HTML (data-turbo-permanent)
```

---

## Symfony Service Graph

| Service | Class | Dependencies |
|---|---|---|
| `InjectLivePreviewListener` | `EventListener\InjectLivePreviewListener` | `Twig\Environment`, `RequestStack`, `Packages`, `UrlGeneratorInterface` |
| `InjectPreviewScriptListener` | `EventListener\InjectPreviewScriptListener` | — |
| `InjectArticleMarkersListener` | `EventListener\InjectArticleMarkersListener` | `RequestStack` |
| `InjectContentElementMarkersListener` | `EventListener\InjectContentElementMarkersListener` | `RequestStack`, `ContaoFramework` |
| `InjectTwigContentElementMarkersListener` | `EventListener\InjectTwigContentElementMarkersListener` | `RequestStack`, `ContaoFramework`, `Connection` |
| `InjectModuleMarkersListener` | `EventListener\InjectModuleMarkersListener` | `RequestStack`, `ContaoFramework` |
| `PreviewResolverController` | `Controller\PreviewResolverController` | `PreviewUrlResolverInterface` (→ `ChainPreviewUrlResolver`), `ContaoFramework` |
| `ChainPreviewUrlResolver` | `Service\ChainPreviewUrlResolver` | `!tagged_iterator contao_live_preview.preview_url_resolver`, `PreviewUrlResolver` |
| `PreviewUrlResolver` | `Service\PreviewUrlResolver` | `Doctrine\DBAL\Connection`, `ContaoFramework` |

All services are autowired and autoconfigured via the `Vendor\ContaoLivePreviewBundle\` resource scan.

**Resolver chain (ADR-021):** `PreviewUrlResolverInterface` is aliased to `ChainPreviewUrlResolver`, which runs every service tagged `contao_live_preview.preview_url_resolver` in priority order and returns the first non-null result. `PreviewUrlResolver` (the bundle's own) is pinned to priority `-1000` so it runs last. A third-party bundle adds a table (or overrides an existing one) by implementing `PreviewUrlResolverInterface` — autoconfiguration tags it; `#[AsTaggedItem(priority: N)]` sets its order. Re-aliasing the interface to a single custom service still works as the full-control escape hatch.

Most custom child tables need no resolver at all — `PreviewUrlResolver::resolveFromChildTable()` walks the DCA `ptable` chain (`config.ptable`, or the record's `ptable` column when `config.dynamicPtable` is set) up to `tl_content` / `tl_article` / `tl_page` automatically.

---

## Routes

| Method | Path | Controller | Auth | Scope |
|---|---|---|---|---|
| GET | `/contao/live-preview/resolve` | `PreviewResolverController` | `ROLE_USER` | `_scope: backend` |

Query parameters:
- `table` — source database table (`tl_content`, `tl_article`, `tl_page`, or any custom child table resolvable via its DCA `ptable` chain)
- `id` — record ID

Response: `application/json`
```json
{
  "pageId": 42,
  "pageAlias": "home",
  "articleId": 151,
  "articleTitle": "Home Icons",
  "previewUrl": "http://localhost:8080/de/home.html",
  "highlightSelectors": [
    "[data-contao-table=\"tl_content\"][data-contao-id=\"77\"]"
  ],
  "articleSelectors": [
    "[data-contao-table=\"tl_article\"][data-contao-id=\"151\"]",
    "#article-151"
  ],
  "contentElementId": 77,
  "contentElementType": "rsce_iconList",
  "contentElementLabel": "Icon Liste"
}
```

- `highlightSelectors` — primary scroll+highlight target: CE selector when context is `tl_content`, otherwise same as `articleSelectors`
- `articleSelectors` — always targets the article wrapper; used for DOM swap and as secondary highlight in dual mode
- `contentElementId` / `contentElementType` / `contentElementLabel` — `null` / `''` when context is `tl_article` or `tl_page`
- `contentElementLabel` — cleaned DCA label from `$GLOBALS['TL_LANG']['CTE']`; suffix words (Anfang/Start/Ende/Wrapper) stripped; empty string if no label found (never the raw type key)

The `previewUrl` is built via `PageModel::findWithDetails($pageId)->getAbsoluteUrl()`. Non-routable page types (`error_404`, `folder`, `root`) are handled by walking up to the nearest routable ancestor.

**Custom child tables:** when `table` is none of the three known tables, `resolveFromChildTable()` loads its DCA and walks the parent chain — `config.ptable` for a static parent, the record's own `ptable` column when `config.dynamicPtable` is set — recursing (depth cap 10) until it reaches `tl_content` / `tl_article` / `tl_page`. The table name is validated (`/^tl_[a-z0-9_]+$/` + schema-manager existence check) before interpolation. A broken or absent chain returns `null` → the controller falls back to the root page (never a wrong record). The JS side (`parseContext`) passes the real table through for any `act=edit` URL instead of coercing unknown tables to `tl_article` (which previously jumped the preview to a same-id article, possibly on another domain — issue #9).

---

## Contao Hooks

| Hook | Listener | Priority | Purpose |
|---|---|---|---|
| `outputBackendTemplate` | `InjectLivePreviewListener` | — | Injects sidebar HTML + CSS/JS into `be_main`. Skips `?popup=1` / `?picker`. |
| `parseFrontendTemplate` | `InjectArticleMarkersListener` | — | Auto-injects `data-contao-table="tl_article"` + `data-contao-id` on article wrapper when `?_clp=1`. Skips if theme already provides them. |
| `getContentElement` | `InjectContentElementMarkersListener` | — | Auto-injects `data-contao-table="tl_content"` + `data-contao-id` + `data-contao-label` on CE wrapper when `?_clp=1`. Covers legacy `ContentElement` subclasses and RSCE. Twig-first `#[AsContentElement]` CEs bypass this hook and are handled by `InjectTwigContentElementMarkersListener`. |
| `getFrontendModule` | `InjectModuleMarkersListener` | — | Auto-injects `data-contao-table="tl_module"` + `data-contao-id` + `data-contao-label` on frontend module wrappers when `?_clp=1`. Fires inside `Controller::getFrontendModule()` which covers all layout modules (preloaded by `PageRegular`) and explicit `{{insert_module::N}}` / `{{ frontend_module(N) }}` calls. Module labels from `$GLOBALS['TL_LANG']['FMD']`. |

`KernelEvents::RESPONSE` (priority -195): `InjectTwigContentElementMarkersListener` annotates Twig-first CE wrappers via DBAL lookup + type+position matching. Runs before `-200` so data attributes are present when the inline script is injected.

`KernelEvents::RESPONSE` (priority -200): `InjectPreviewScriptListener` injects the highlight + hover inline script before `</body>` when `?_clp=1` and content type is `text/html`.

---

## Database Tables (read-only)

| Table | Fields read | Purpose |
|---|---|---|
| `tl_content` | `id`, `pid`, `type` | Walk up to parent article; `type` used for CE label resolution |
| `tl_article` | `id`, `pid`, `alias`, `cssID`, `title` | Walk up to parent page; alias + cssID for fallback selectors |
| `tl_page` | `id`, `alias` | Identify page; URL via Contao `PageModel` |

---

## Frontend DOM Contract

The partial refresh and highlight mechanisms require article and content element wrappers to carry `data-contao-*` attributes:

```html
<!-- Article wrapper -->
<div data-contao-table="tl_article" data-contao-id="42" id="article-42" ...>
  <!-- Content element wrapper -->
  <div data-contao-table="tl_content" data-contao-id="77" data-contao-label="Icon Liste" ...>
```

**Who provides them:**

| Attribute set | Provider | Condition |
|---|---|---|
| `tl_article` attrs | `InjectArticleMarkersListener` (hook) | All themes; skipped if theme already provides them |
| `tl_article` attrs | `mod_article.html.twig` (Design+ theme) | Design+ only — hook skips via `str_contains` guard |
| `tl_content` attrs + label | `InjectContentElementMarkersListener` (hook) | Legacy `ContentElement` subclasses incl. RSCE |
| `tl_module` attrs + label | `InjectModuleMarkersListener` (hook) | All legacy Module subclasses; layout + insert_module |

**Selector priority for highlight / DOM swap:**
1. `[data-contao-table="tl_article"][data-contao-id="N"]` — primary (unambiguous)
2. `#article-{id}` — Contao default CSS ID
3. `#article-{alias}` — alias-based ID
4. `#{cssId}` — custom CSS ID from the backend field

---

## Inline Frontend Script (`InjectPreviewScriptListener`)

Injected before `</body>` on every `?_clp=1` frontend response. Handles:

| Message in | Type | Action |
|---|---|---|
| `clp:highlight` | postMessage from backend | Scroll to element, apply blue outline + label badge. Dual mode: CE solid blue + article dashed blue. |
| `clp:refresh` | postMessage from backend | Fetch current page, extract article node via selector chain, `replaceWith()`, restore scroll, highlight. |

| Message out | Type | Trigger |
|---|---|---|
| `clp:refreshed` | postMessage to parent | After DOM swap (or on fetch error). |
| `clp:edit` | postMessage to parent | Click on edit icon in any badge (active or hover). Payload: `{ table, id }`. |
| `clp:duplicate` | postMessage to parent | Click on ⧉ duplicate icon in active CE badge. Payload: `{ id }`. Backend navigates to `act=copy&mode=4`. |
| `clp:insert-after` | postMessage to parent | Click on + new-after icon in active CE badge. Payload: `{ id }`. Backend navigates to `act=create&mode=4`. |

**Hover highlighting:** `mouseover` / `mouseout` on `document` detect any `[data-contao-table]` element under the cursor and show a fuchsia dashed outline + badge. Active (blue) elements are excluded. Hover badge stays visible when cursor moves over it (edit icon is clickable). Same-origin link clicks are intercepted to preserve `?_clp=1` across iframe navigation.

**Visual target unwrapping:** when the matched element has a `col-*` class and exactly one child, the outline and badge are applied to the child element instead — the `data-contao-*` data element is unchanged for DOM queries and hover exclusion.

---

## localStorage Keys

| Key | Value | Purpose |
|---|---|---|
| `clp_sidebar_open` | `'0'` / `'1'` | Persist open/closed state |
| `clp_sidebar_width` | integer (px) | Persist drag-resized sidebar width |
| `clp_zoom` | float string (`'0.5'`–`'1.5'`) | Persist zoom level |
| `clp_pending_save` | JSON (see below) | Transient save state; consumed by `tryRehydrate()` on next page init; expires after 30 s |

`clp_pending_save` JSON shape:
```json
{
  "articleId": 151,
  "label": "Home Icons",
  "contentElementType": "rsce_iconList",
  "contentElementLabel": "Icon Liste",
  "iframeUrl": "http://localhost:8080/de/home.html",
  "selectors": ["[data-contao-table=\"tl_content\"][data-contao-id=\"77\"]"],
  "articleSelectors": ["[data-contao-table=\"tl_article\"][data-contao-id=\"151\"]", "#article-151"],
  "scrollX": 0,
  "scrollY": 420,
  "ts": 1746612345678
}
```
