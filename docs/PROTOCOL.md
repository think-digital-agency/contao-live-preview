# Contao Live Preview — postMessage Protocol (v1)

The Live Preview consists of two scripts that communicate over `postMessage`
through an `<iframe>`:

- **Backend script** — `public/js/live-preview.js`, loaded in the Contao backend
  (the parent window). It renders the sidebar, resolves the current edit
  context, and drives the iframe.
- **Frontend script** — an inline `<script>` injected before `</body>` into the
  previewed page **only when it is loaded with `?_clp=1`** (see
  `InjectPreviewScriptListener`). It lives inside the iframe and applies
  highlights, performs the partial DOM swap on save, and reports user actions
  (edit / duplicate / insert-after) back to the parent.

## Versioning

Every message carries a `version` field (a positive integer). **v1** is the
initial frozen contract.

- A receiver that gets a message with an unexpected `version` **MUST ignore it**
  rather than throwing.
- Unknown `clp:*` message types **MUST be ignored** (no throw). This is the
  basis for forward-compatible additions in later versions.
- Future minor additions may add fields to existing messages; receivers MUST
  tolerate extra/missing fields and fall back to sensible defaults.
- A breaking change bumps the `version`.

For v1→v1 traffic, process normally.

## Messages — Backend → iframe

### `clp:highlight`
Scroll to and outline the currently edited article or content element, and
render a label badge.

```ts
{
  version: 1;
  type: 'clp:highlight';
  selectors: string[];             // ordered CSS selectors; first match wins
  articleSelectors: string[];     // article wrapper selectors (secondary target)
  scrollBehavior: 'smooth' | 'instant';
  label: string;                   // badge label for the primary target
  articleLabel: string;             // badge label for the article wrapper
  articleId: number | null;
  contentElementId: number | null;
}
```

### `clp:refresh`
Partial DOM swap of the article node from a fresh fetch of the current page
URL. Preserves scroll position. On completion (or on failure) the iframe posts
`clp:refreshed` back.

```ts
{
  version: 1;
  type: 'clp:refresh';
  articleId: number | null;
  selectors: string[];             // article selectors to locate the swap node
  label: string;
}
```

## Messages — iframe → parent

### `clp:refreshed`
Confirms that the `clp:refresh` DOM swap completed (or failed). The parent uses
this to clear its pending-save state.

```ts
{
  version: 1;
  type: 'clp:refreshed';
  articleId: number | null;
}
```

### `clp:edit`
The user clicked the edit button on a highlight/hover badge. The parent
navigates the backend to the matching edit view, choosing the correct `do`
based on `table` and `parentTable`.

```ts
{
  version: 1;
  type: 'clp:edit';
  table: string;                   // 'tl_content' | 'tl_article' | 'tl_module' | 'tl_news'
  id: number;
  parentTable: string;             // 'tl_news' when the CE belongs to a news record, else ''
}
```

### `clp:duplicate`
The user clicked the duplicate action on a content element badge. The parent
triggers Contao's copy action for that element.

```ts
{
  version: 1;
  type: 'clp:duplicate';
  id: number;
}
```

### `clp:insert-after`
The user clicked "new element after" on a content element badge. The parent
triggers Contao's create action positioned after that element.

```ts
{
  version: 1;
  type: 'clp:insert-after';
  id: number;
}
```

## Extension contract

This file documents only the **core** messages owned and emitted by this
bundle. Third-party bundles may introduce their own `clp:*` message types
without modifying this file.

- **Defensive ignoring is the contract.** Receivers (both the backend script and
  the injected frontend script) MUST ignore messages whose `type` or `version`
  they do not recognise — never throw. This is the only guarantee a third party
  relies on, and it is what makes the protocol forward-compatible: an external
  bundle can listen for and post its own `clp:*` messages without this bundle's
  involvement.
- **Namespace ownership.** The unprefixed `clp:` core types listed above are
  owned by this bundle. Third-party bundles that add new messages should use a
  distinct, prefixed namespace (e.g. `clp:acme:*` or `acme:clp:*`) to avoid
  collisions with future core types, and document them in their own bundle.
- **Field stability.** When extending an existing core message, add fields but
  do not remove or repurpose existing ones within the same `version`.
- **Listening in.** Third parties that need to observe core traffic can attach
  their own `message` listeners (parent window) or `document` listeners
  (iframe). They MUST call `e.stopImmediatePropagation()` only when they
  genuinely intend to override a core handler; otherwise they must leave the
  event propagating so core handling continues.
