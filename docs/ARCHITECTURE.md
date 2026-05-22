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
│   │   ├── InjectLivePreviewListener.php       # outputBackendTemplate hook; asset URLs via Packages service
│   │   ├── InjectPreviewScriptListener.php     # KernelEvents::RESPONSE (-200) — injects highlight+hover script
│   │   ├── InjectArticleMarkersListener.php    # parseFrontendTemplate hook — auto-injects article data-attrs
│   │   ├── InjectContentElementMarkersListener.php # getContentElement hook — legacy CEs + RSCE
│   │   ├── InjectTwigContentElementMarkersListener.php # KernelEvents::RESPONSE (-195) — Twig-first CEs
│   │   └── InjectModuleMarkersListener.php     # getFrontendModule hook — layout modules (Header, Nav, Footer, …)
│   ├── Resources/
│   │   └── config/
│   │       └── services.yaml                   # Autowired services + interface alias
│   └── Service/
│       ├── LabelCleanerTrait.php               # Shared cleanLabel() + resolveLabel() for CE listeners + controller
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
| `InjectLivePreviewListener` | `EventListener\InjectLivePreviewListener` | `Twig\Environment`, `RequestStack`, `Packages` |
| `InjectPreviewScriptListener` | `EventListener\InjectPreviewScriptListener` | — |
| `InjectArticleMarkersListener` | `EventListener\InjectArticleMarkersListener` | `RequestStack` |
| `InjectContentElementMarkersListener` | `EventListener\InjectContentElementMarkersListener` | `RequestStack`, `ContaoFramework` |
| `InjectTwigContentElementMarkersListener` | `EventListener\InjectTwigContentElementMarkersListener` | `RequestStack`, `ContaoFramework`, `Connection` |
| `InjectModuleMarkersListener` | `EventListener\InjectModuleMarkersListener` | `RequestStack`, `ContaoFramework` |
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
