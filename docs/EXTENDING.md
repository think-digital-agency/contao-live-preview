# Extending Contao Live Preview

The Live Preview exposes two kinds of extension points:

- **JavaScript registries** (`CLP_BE`, `CLP_FE`) for frontend/backend JS
  behaviour — badge actions, message handlers, payload augmentation.
- **PHP extension points** for resolving custom backend tables to a preview
  URL — a tagged resolver chain and a `clp:resolve` event.

All `postMessage` traffic uses the protocol documented in
[PROTOCOL.md](./PROTOCOL.md) — every message carries `version: 1`.

| Global          | Runs in                                                                                                              | Purpose                                                                                                                                                          |
|-----------------|------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `window.CLP_BE` | Contao backend (parent window) — `public/js/live-preview.js`                                                         | Register/replace/remove **message handlers** for messages arriving from the preview iframe.                                                                      |
| `window.CLP_FE` | Inside the preview iframe (only when `?_clp=1` is set) — the inline script injected by `InjectPreviewScriptListener` | Register/replace/remove/reorder **badge actions** on highlight/hover badges; register/replace/remove **incoming-message handlers** for messages from the parent. |

Both registries are plain global objects (no build step). They communicate over
the `postMessage` protocol documented in [PROTOCOL.md](./PROTOCOL.md) — every
message carries `version: 1`.

## Lifecycles

- **`CLP_BE`** is created once and survives Contao's Turbo body-swap
  navigation (the backend script is loaded in `<head>` and guarded by
  `window.__clpLoaded`). Registrations made by a third-party backend script
  persist across navigations — register once on load.
- **`CLP_FE`** is re-created on every iframe load (a fresh preview page is a
  fresh document). A third-party frontend script must re-register its actions
  on every load.

## Loading your extension script

The two registries live in **different documents**, so your JS must be loaded
into the matching environment. The most common mistake is calling `CLP_FE`
from a script that runs in the backend window (where `CLP_FE` is undefined) or
calling `CLP_BE` from a frontend script.

| Registry | Where it lives                                                 | How to get your JS there                                                          |
|----------|------------------------------------------------------------------|-------------------------------------------------------------------------------------|
| `CLP_BE` | The Contao **backend** (parent window)                         | Inject into backend (in the `<head>`) via the new `injectLivePreview` hook        |
| `CLP_FE` | The **preview iframe** (a frontend page loaded with `?_clp=1`) | Inject into iframe (at the end of `<body>` via the new `injectPreviewScript` hook |

### Backend script (`CLP_BE`)

Register a listener on the `injectLivePreview` hook and append your
`<script>` tag to the buffer. See the Contao
docs for [registering hooks](https://docs.contao.org/dev/framework/hooks/).
The hook passes the template buffer and expects it back as the return value (edited or not).

```php
// src/EventListener/InjectLivePreviewListener.php
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Symfony\Component\Asset\Packages;

#[AsHook('injectLivePreview')]
class InjectLivePreviewListener
{
    public function __construct(
        private readonly Packages $packages,
    ) {}

    public function __invoke(string $buffer): string
    {
        $url = $this->packages->getUrl('bundles/acme/js/my-live-preview-ext.js');

        return str_replace(
            '</head>',
            '<script src="' . htmlspecialchars($url, \ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n</head>",
            $buffer,
        );
    }
}
```

Although the script has the `defer` attribute and runs
after the bundle's `live-preview.js`, you still need to make sure that the bundle's handlers are registered BEFORE you register your custom handlers (so they don't get possibly overwritten directly).
In your custom `live-preview.js`, you can test the global `window.CLP_BE.isInitialized` to do this, e.g., with `setInterval`/`clearInterval`:

```js
const t = Date.now();
const wait = setInterval(() => {
  if (window.CLP_BE.isInitialised) {
    clearInterval(wait);
    registerMyCustomHandlers();
  } else if (Date.now() - t > 5000) { // timeout after 5 seconds
    clearInterval(wait);
  }
}, 50);
```

Then you can register the custom backend handlers:

```js
  CLP_BE.on('clp:acme:my-action', function (d) { /* … */ });
  CLP_BE.augment('clp:highlight', function (p, info) { /* … */ }, 'acme:locale');
```

The final custom `live-preview.js` could then look like this:

```js
(function () {
    'use strict';

    if (!window.CLP_BE) return;

    const t = Date.now();
    const wait = setInterval(() => {
      if (window.CLP_BE.isInitialised) {
        clearInterval(wait);
        registerMyCustomHandlers();
      } else if (Date.now() - t > 5000) {
        // timeout after 5 seconds
        console.error('Could not register custom live-preview BE handlers: Initialization timed out.');
        clearInterval(wait);
      }
    }, 50);
    
    const registerMyCustomHandlers = () => {
        CLP_BE.on('clp:acme:my-action', function (d) { /* … */ });
        CLP_BE.augment('clp:highlight', function (p, info) { /* … */ }, 'acme:locale');
    }
});
```

Because `CLP_BE` persists across Turbo navigations, registering on every load is
harmless (id-keyed → replaces in place), but guard against duplicate side
effects if your script does anything beyond registry calls.

### Frontend script (`CLP_FE`)

`CLP_FE` is only present when the frontend page is loaded inside the preview
iframe, which this bundle signals with `?_clp=1`. Register a listener on the `injectPreviewScript` hook to inject your script before `</body>`. Again, see the Contao
docs for [registering hooks](https://docs.contao.org/dev/framework/hooks/).

```php
// src/EventListener/InjectPreviewScriptListener.php
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Symfony\Component\HttpFoundation\Response;

#[AsHook('injectPreviewScript')]
class InjectPreviewScriptListener
{
    public function __invoke(Response $response): Response
    {
        $content = $response->getContent();
        if (false === $content || !str_contains($content, '</body>')) return;

        // Inline script — no extra HTTP request.
        $js = <<<'HTML'
<script>
    (function(){
        if(!window.CLP_FE) return;
        CLP_FE.set('acme:my-action',function(ctx,post,ui){});
  })();
</script>
HTML;

        $response->setContent(str_replace('</body>', $js . '</body>', $content));
    }
}
```

> **Note:** The `if (!window.CLP_FE) return;` guard theoretically defers registration to a later load, if the registry is not available yet, but should essentially not be necessary, since we are using a hook.

> Re-registering on every iframe load is required (each preview page is a fresh
> document) — that is expected, not a leak.

### Quick rule

- Backend behaviour (handle messages, augment payloads) → **`CLP_BE`**, script
  in the backend response.
- Frontend behaviour (badge actions, observe incoming messages, listen for
  `CustomEvent`s) → **`CLP_FE`**, script in the frontend response gated on
  `?_clp=1`.
- If a global is undefined, you are almost certainly in the wrong environment.

## `CLP_FE` — Adding, removing and moving badge actions

```js
CLP_FE.set(id, provider(ctx,post,ui), options?);     // add OR replace (same id keeps position+section)
CLP_FE.remove(id, removeProvider(ctx)?);     // remove an action (optionally via provider function for conditional removal)
CLP_FE.move(id, position?);             // reorder within the action's section
```

### CLP_FE.set

New badge actions can be added via the `set` method and need the action's `id`
and a `provider` function (`provider(ctx, post, ui)`) which returns a `<button>`
(or `null` to render nothing for that context). The bundle renders every
registered action, grouped into two sections: **primary** actions (e.g. the
edit pencil) and **secondary** actions (e.g. duplicate / insert-after). The
badge renders all primaries, one separator, then all secondaries.

`options` (optional): `{ section?, position? }`

- `section`: `'primary'` | `'secondary'` (default `'secondary'`). Decides **which
  section** the action renders in. An action's section is independent of its
  button's CSS class — section groups, class styles.
- `position`: `'first'` | `'last'` | `{ before: id }` | `{ after: id }` | `<number>`.
  Default `'last'`. The number is a **0-based index** into the action's section.
  (If the anchor id is in another section, `section` is ignored and the action
  is moved next to the anchor.)

`set` on an existing id **replaces the provider in place** (position and section
unchanged) unless `position`/`section` is also passed, in which case it moves /
re-sections as well.

#### The `ui` button helper (third provider arg)

Don't hand-roll `<button>` markup — use `ui.button(opts)`:

```js
CLP_FE.set('acme:image-meta', function (ctx, post, ui) {
  if (ctx.table !== 'tl_content' || ctx.ceType !== 'image') return null;
  return ui.button({
    icon: '🖼',                       // HTML string (intended to be an icon)
    title: 'Edit image metadata',     // → title attribute (tooltip)
    postOptions: { type: 'acme:image-meta', id: ctx.id },  // posted on click
  });
});
```

`ui.button(opts)` options:

| option         | required  | description                                                                                                                                                                                                                                                                                                                                                                                                                            |
|----------------|-----------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `icon`         | **yes**   | HTML string for the button's content (intended to be an icon: svg/img/emoji). Becomes `innerHTML`.                                                                                                                                                                                                                                                                                                                                     |
| `title`        | no        | The button `title` attribute (tooltip). Only set when provided (falsy → no attribute).                                                                                                                                                                                                                                                                                                                                                 |
| `class`        | no        | Extra CSS class **appended** to the base class. The base is always set (`clp-badge-action` by default, `clp-badge-edit` for the primary style) so layout stays consistent — pass `class` only to *add* styling, never to replace the base. Purely styling; does NOT decide the badge section.                                                                                                                                          |
| `postOptions`  | no        | Message payload posted on click; `post()` stamps `version: 1` automatically.                                                                                                                                                                                                                                                                                                                                                           |
| `href`         | no        | If set, renders an `<a href>` instead of `<button>` so the browser shows the link preview bottom-left. **Cosmetic only** — if `postOptions` is also set, click `preventDefault`s + posts (the `href` is just for the preview); if only `href` is set, it's a pure link. Only useful where the provider can supply a real URL; the core edit/duplicate/insert-after actions don't use it (their URLs are BE-computed from the message). |
| `callback`     | no        | `function(ev, ctx)` run on click **before** `postOptions` is posted. The click always `stopPropagation`s (and `preventDefault`s for links), so it won't bubble/navigate. Use for FE-only behaviour (open a modal, toggle inline UI) without a BE round-trip; combine with `postOptions` to also post afterwards.                                                                                                                       |

You may still return a raw `<button>` (or `<a>`) instead of using `ui.button` —
the action's **registered `section`** (not the element's class) decides which
section it renders in. `ui.button` is just a convenience that handles the
markup, the base class, `stopPropagation`, and `post()` for you.

### The context object (`ctx`)

Every provider is called with the same `ctx`. **It is forward-compatible**:
extra fields may be added in later versions; providers MUST tolerate fields
they don't use. Current fields:

```ts
type ClpBadgeCtx = {
  table:       'tl_content' | 'tl_article' | 'tl_module' | string; // + 'tl_news' etc. with feature branches
  id:          number;        // 0 when the badge has no record id (rare)
  parentTable: string;        // nearest marker ancestor's table (e.g. 'tl_news' for news CEs, 'tl_content' for group children), else ''
  el:          HTMLElement | null;  // the data element ([data-contao-table=…])
  vis:         HTMLElement | null;  // the visual target (col-only-child resolution applied)
  ceType:      string | null;       // STABLE CE type key, e.g. 'image','text'; null for non-CE
  ceLabel:     string | null;       // human-readable (possibly translated) label
};
```

`ceType` is the **stable type key** extracted from the CE wrapper's CSS class
(`ce_<type>` for legacy elements, `content-<type>` for Twig-first elements) —
it is **not** affected by the backend language, so use it to gate actions by
element type. `ceLabel` is the human-readable (possibly translated) label for
display. This is the field you gate on to target specific content element types.

> **Theme dependency:** `ceType` relies on the CE wrapper carrying a
> `ce_<type>` / `content-<type>` class. If a custom theme strips these classes,
> `ceType` is `null` — gate providers on `ceType !== null` (or fall back to
> inspecting `el` yourself). The `data-contao-table` / `data-contao-id`
> markers the badge uses for identity are always present (injected by this
> bundle), so `ctx.table` / `ctx.id` are reliable regardless of theme.

> **Resolver data on `ctx`:** the full PHP-resolver response (`pageId`,
> `newsId`, `newsAlias`, `newsTitle`, `contentElementParentTable`, …) is passed
> through the `clp:highlight` payload and thus appears on `ctx` — providers
> tolerate unknown fields, so any resolver field is available for gating
> without an FE change. For data the resolver *doesn't* return, a third party
> can add fields via `CLP_BE.augment` (see below). `version`/`type` and the
> core highlight fields are never overwritten by resolver data.

### The `post` helper

`post(msg)` sends a message to the parent window and **stamps `version: 1`
automatically**. Always use it instead of raw `window.parent.postMessage`.

```js
post({ type: 'clp:acme:toggle', id: ctx.id });
```

### CLP_FE.remove

To delete an action for ALL elements (global, **no fall-back**), simply use `CLP_FE.remove(id)`.

You can also pass a second argument (`removeHandler`) to only remove actions for specific elements.
In this case the `removeHandler` needs to return `true` for those elements.
The element context (`ctx`) is passed as first argument to the `removeHandler` function.
```js
// Remove the duplicate action for image CEs only.
CLP_FE.remove('clp:duplicate', function (ctx) {
  return (ctx.ceType === 'image');
});
```

### CLP_FE.move

To reorder actions within a section, you can use `CLP_FE.move(id,position?)`. It uses the same `position` format as `set` and falls back to 'last' (within its current section), if no position is given.

```js
// Move the duplicate action to the end of its current section.
CLP_FE.move('clp:duplicate');
// Move the duplicate action to the beginning of the 'primary' section.
CLP_FE.move('clp:duplicate', { section: 'primary', position:'first' });
// Move the edit action after the duplicate action.
CLP_FE.move('clp:edit', { position: { after: 'clp:duplicate' } });
```

### Incoming-message handlers (`CLP_FE.on`)

`CLP_FE` also dispatches **incoming** messages from the parent window (e.g.
`clp:highlight`, `clp:refresh`) through an id-keyed handler
registry, parallel to `CLP_BE`:

```js
CLP_FE.on(type, fn, id?)  // register/replace a handler for an incoming message type
CLP_FE.off(type, id?)     // remove a handler
```

Handlers are **id-keyed** (same semantics as `CLP_BE`): `id` defaults to `type`
when omitted, so a single handler per type is the common case. Registering under
the same `(type, id)` replaces; a new `id` adds a parallel handler. Throwing
handlers are skipped.

Core FE handler ids (registered by the bundle):

| type            | id              | Effect                                                                               |
|-----------------|-----------------|----------------------------------------------------------------------------------------|
| `clp:highlight` | `clp:highlight` | Scroll to + outline the edited article/CE, render badges.                            |
| `clp:refresh`   | `clp:refresh`   | Partial DOM swap of the article node from a fresh fetch; posts `clp:refreshed` back. |

You may **replace** any of them (same id) or **remove** them. Feature branches
may register additional core handlers (e.g. the grid-lines branch adds
`clp:grid`).

#### `CustomEvent` re-dispatch for unknown `clp:*` messages

If a `clp:*` message arrives that has **no** registered `CLP_FE.on` handler,
the bundle re-dispatches it as a `CustomEvent` on `document`:

```js
document.dispatchEvent(new CustomEvent(messageType, { detail: messageData }));
```

This means a third-party FE script can listen for its own message types (or for
core types it chooses not to handle via `CLP_FE.on`) without adding its own
`message` listener:

```js
// Listen for a custom message type the parent posts into the iframe.
document.addEventListener('clp:acme:highlight-extra', function (e) {
  console.log('payload:', e.detail);
});

// Listen for a core type to observe (not replace) — the CustomEvent fires
// only when NO CLP_FE.on handler ran, so this is an observe-only seam for
// types the bundle does handle. To observe a handled type, register a
// parallel CLP_FE.on handler under a new id instead.
```

> **Note:** the `CustomEvent` fires *only* for `clp:*` types with no registered
> handler. To observe a type the bundle already handles (e.g. `clp:highlight`),
> register a parallel `CLP_FE.on('clp:highlight', fn, 'acme:observe')` under a
> new id — both the core handler and yours run.

### Stable core ids

The bundle registers these by default:

| id                 | section     | Renders                        | Gating                                 |
|--------------------|-------------|---------------------------------|-----------------------------------------|
| `clp:edit`         | `primary`   | The edit-pencil button         | `ctx.table && ctx.id`                  |
| `clp:duplicate`    | `secondary` | The duplicate button           | `ctx.table === 'tl_content' && ctx.id` |
| `clp:insert-after` | `secondary` | The "new element after" button | `ctx.table === 'tl_content' && ctx.id` |

These ids are part of the extension contract: you may **replace** (register
under the same id) or **remove** any of them. Replacing an id keeps its
position in its section. A badge with no registered actions shows only the
label — that is a valid state, so e.g. `CLP_FE.remove('clp:edit')` is supported
if you want a label-only badge.

### Worked examples

```js
// 1. ADD a secondary action for image CEs only.
CLP_FE.set('acme:image-meta', function (ctx, post, ui) {
  if (ctx.table !== 'tl_content' || ctx.ceType !== 'image') return null;
  return ui.button({ icon: '🖼', title: 'Edit image metadata',
                     postOptions: { type: 'acme:image-meta', id: ctx.id } });
});

// 2. ADD a primary action, placed right after the edit pencil.
CLP_FE.set('acme:inspect', function (ctx, post, ui) {
  return ui.button({ icon: '🔍', title: 'Inspect',
                     postOptions: { type: 'acme:inspect', id: ctx.id } });
}, { section: 'primary', position: { after: 'clp:edit' } });

// 3. REPLACE the core duplicate action (section + position kept).
CLP_FE.set('clp:duplicate', function (ctx, post, ui) {
  if (ctx.table !== 'tl_content' || !ctx.id) return null;
  return ui.button({ icon: '⧉', title: 'Duplicate (with options)',
                     postOptions: { type: 'acme:duplicate-opts', id: ctx.id } });
});

// 4. REMOVE the core insert-after action entirely.
CLP_FE.remove('clp:insert-after');

// 5. REORDER — move a custom secondary action before the duplicate action.
CLP_FE.move('acme:image-meta', { before: 'clp:duplicate' });
```

### Handling the message on the backend side

A `clp:acme:*` message sent from the iframe is just another `postMessage` —
handle it with `CLP_BE`:

```js
CLP_BE.on('clp:acme:image-meta', function (d) {
  // navigate the backend, open a modal, etc.
  if (window.Turbo) Turbo.visit(buildUrl(d.id)); else window.location.href = buildUrl(d.id);
});
```

## `CLP_BE` — message handlers

```js
CLP_BE.on(type, fn, id?)  // register/replace a handler for a message type, keyed by id
CLP_BE.off(type, id?)     // remove a handler
```

Handlers are **id-keyed**, not append-only: `id` defaults to `type` when
omitted (the common case — one handler per type). Registering under the same
`(type, id)` replaces the existing handler; registering under a new `id` adds a
**parallel** handler without touching the core one. Both run. This is the
difference between *replacing* and *observing*.

Core handler ids (registered by the bundle):

| type               | id                 | Effect                               |
|--------------------|--------------------|-----------------------------------------|
| `clp:refreshed`    | `clp:refreshed`    | Clears the pending-save state.       |
| `clp:edit`         | `clp:edit`         | Navigates to the matching edit view. |
| `clp:duplicate`    | `clp:duplicate`    | Triggers Contao's copy action.       |
| `clp:insert-after` | `clp:insert-after` | Triggers Contao's create action.     |

### Examples

```js
// Replace the core edit handler (id defaults to type → replaces, no parallel handler).
CLP_BE.on('clp:edit', function (d) {
  // your custom edit navigation
});

// Observe clp:refreshed without replacing the core handler (new id → parallel).
CLP_BE.on('clp:refreshed', function (d) {
  navigator.sendBeacon('/acme/telemetry', JSON.stringify({ event: 'clp-refresh', articleId: d.articleId }));
}, 'acme:telemetry');
```

### `CLP_BE.augment` — outgoing payload augmentation

```js
CLP_BE.augment(type, fn, id?)  // register/replace an augmenter for an outgoing message type
CLP_BE.unaugment(type, id?)    // remove an augmenter
```

An augmenter `fn(payload, info)` is called **before** the bundle posts a
message of `type` to the iframe. It receives the payload object (mutable) and a
read-only `info` object, and may either **mutate `payload` in place** or
**return a new object** (which replaces the payload). This is the seam for
adding fields to the `clp:highlight` payload (and therefore to FE badge
providers' `ctx`) with data the PHP resolver doesn't return, or with data that
depends on BE-side JS state.

`info` currently carries:
```ts
{
  context: { table, id } | null,   // the current backend edit context
  articleId: number | null,
  resolveData: object | null,      // the full PHP-resolver response, if resolved
}
```

**Safety:** the bundle re-stamps `version` and `type` on the payload *after*
augmentation, so an augmenter cannot accidentally break the protocol contract.
Augmenters that throw are skipped (defensive, like handlers).

#### Example

```js
// Add a custom field to every clp:highlight payload, computed from BE-side state.
CLP_BE.augment('clp:highlight', function (payload, info) {
  payload.acmeLocale = document.documentElement.lang || 'de';
  // no return needed — mutation in place is fine
}, 'acme:locale');

// Add a field only for news contexts, using resolver data.
CLP_BE.augment('clp:highlight', function (payload, info) {
  if (info.context?.table === 'tl_news' && info.resolveData?.newsId) {
    payload.acmeNewsArchiveId = window.__acmeArchiveForNews(info.resolveData.newsId);
  }
}, 'acme:news-archive');
```

The added fields appear on every FE badge provider's `ctx` for that highlight
(providers tolerate unknown fields). Combine with `CLP_FE` to act on them.

## PHP — resolving custom tables

The backend resolves the current edit context (`table` + `id`) to a frontend
page via a **tagged resolver chain**. A third-party bundle adds support for its
own tables (calendar, events, …) by registering a
`PreviewUrlResolverInterface` service with the
`contao_live_preview.preview_url_resolver` tag.

The chain iterates all tagged resolvers in **priority order, descending**, and
returns the **first non-null result**. The bundle's core resolver is registered
at priority `-1000` (always last).

```php
// src/Service/CalendarPreviewUrlResolver.php
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolverInterface;

class CalendarPreviewUrlResolver implements PreviewUrlResolverInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function resolve(string $table, int $id): ?array
    {
        if ('tl_calendar_events' !== $table) {
            return null; // not our table — let other resolvers handle it
        }

        // … resolve to a tl_page row and return the page data …
        return ['pageId' => $pageId, 'alias' => $alias];
    }

    public function resolveRootPage(): ?array
    {
        return null; // fallback to the core resolver
    }
}
```

```yaml
# config/services.yaml
App\Service\CalendarPreviewUrlResolver:
    tags:
        - { name: contao_live_preview.preview_url_resolver, priority: 10 }
```

Return `null` from `resolve()` for any table your resolver doesn't own, so the
chain falls through to the next resolver. Use any priority higher than `-1000`
to run before the core resolver.

### `clp:resolve` event — dynamic resolution

For resolution that depends on **request state** a resolver service can't know
(the current request's query params, the session, a per-request override), the
controller dispatches a `ResolvePreviewEvent` (`clp:resolve`) **before** the
resolver chain. A listener that calls `$event->setPageData(...)` takes
precedence and the chain is skipped entirely.

```php
// src/EventListener/DynamicResolveListener.php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use ThinkDigital\ContaoLivePreview\Service\ResolvePreviewEvent;

#[AsEventListener(event: ResolvePreviewEvent::NAME)]
class DynamicResolveListener
{
    public function __invoke(ResolvePreviewEvent $event): void
    {
        if ($event->getTable() !== 'tl_my_thing' || $event->isResolved()) {
            return;
        }

        // … dynamic resolution based on request/session …
        $event->setPageData(['pageId' => $pageId, 'alias' => $alias]);
    }
}
```

Listeners should check `$event->isResolved()` and bail out early so the first
listener that resolves wins (and later listeners are cheap). The event and the
tagged chain are complementary: use a **resolver service** for static
table→page mappings, the **event** for request-dependent resolution.

`setPageData(null)` is a valid, confirmed "not found" — it still marks the
event resolved (`isResolved()` returns `true`), so the controller returns a
404 instead of falling through to the resolver chain. Use it when your
listener recognizes the table but determines there is nothing to preview;
simply never calling `setPageData()` is how you say "not my table, try the
next listener/the chain" instead.

## Namespace ownership

- Unprefixed `clp:*` message types and `clp:*` registry ids are owned by this
  bundle. They are documented here and in [PROTOCOL.md](./PROTOCOL.md) and are
  the stable contract you may replace/remove.
- Third-party message types and registry ids **should use a distinct prefix**
  (e.g. `acme:image-meta`, `clp:acme:toggle`) to avoid collisions with future
  core ids.

## Gotchas

- **Defensive handling only applies to messages**, not to registry calls. A
  throwing provider is swallowed by `CLP_FE.each`, but a third party should
  still keep providers defensive.
- **FE scripts must re-register on every iframe load.** Gating your injection
  on `?_clp=1` (same as this bundle) is the reliable way.
- The bundle's **frontend script is injected as an inline `<script>` before
  `</body>`**. A third-party FE script injected at the same point runs after
  the core registrations; ordering between multiple third-party scripts is
  determined by script-injection order (i.e. listener priority). Use `move` if
  you need a deterministic badge-action order regardless of injection order.
- `CLP_BE` handler order for a given type is **registration order**; it rarely
  matters (handlers are independent). If you need ordered parallel handlers in
  the future, request a `move` API on `CLP_BE`.
