# DECISIONS.md – Architecture Decision Records

---

## ADR-001: Vanilla JS over Stimulus

**Date:** 2025-05-03
**Status:** Accepted

**Context:**
Contao 5 ships the Stimulus framework (via `@hotwired/stimulus`) as part of `contao/core-bundle`. Using Stimulus would give lifecycle callbacks, controller auto-wiring, and clean separation of concerns. However, integrating Stimulus requires either a build step (Webpack Encore / Vite) or loading it as a pre-built script. The Design+ theme project has no JavaScript build pipeline and adding one for a ~300-line utility script is disproportionate overhead.

**Decision:**
Use a vanilla JavaScript IIFE (immediately invoked function expression) loaded as a plain `<script>` tag. No transpilation, no bundler, no npm dependencies.

**Consequences:**
- (+) Zero build tooling — the file is served directly from `public/js/`
- (+) Works immediately after `composer require` + `assets:install`
- (-) No TypeScript, no module system, no auto-imports
- (-) If the script grows substantially (>1000 lines), refactoring into Stimulus or ES modules with a build step should be reconsidered

---

## ADR-002: Template injection via `outputBackendTemplate` hook

**Date:** 2025-05-03
**Status:** Accepted

**Context:**
Two approaches exist for injecting HTML into the Contao backend:
1. **`outputBackendTemplate` Contao hook** — fires once per backend template render, receives the template name and output buffer
2. **`KernelEvents::RESPONSE` Symfony event listener** — fires on every HTTP response, requires manually checking `Content-Type`, the request route, and the response body

**Decision:**
Use the `outputBackendTemplate` hook with a check for `$template === 'be_main'`.

**Consequences:**
- (+) Scoped automatically to the main backend layout — no risk of injecting into `be_popup`, `be_login`, or API responses
- (+) Follows the Contao convention; other backend customisations in this project also use hooks
- (-) Hook only fires for PHP-template-rendered responses. If a future Contao version migrates `be_main` to a pure Twig template rendered outside the `BackendTemplate` class, this hook will stop firing. Migration path: switch to `KernelEvents::RESPONSE` with a `_scope === 'backend'` check.
- (-) The hook fires on every `be_main` render, not just on page load. This is fine since the injection is idempotent (CSS/JS are deduplicated by the browser).

---

## ADR-003: Preview URL via `/preview.php` instead of explicit token

**Date:** 2025-05-03
**Status:** Accepted (with known limitation)

**Context:**
Contao 5 has a `FrontendPreviewAuthenticator` service that can set a preview flag on the frontend session. The "proper" approach for a backend tool to preview frontend pages is:
1. Call `FrontendPreviewAuthenticator::authenticateFrontendGuest()` to create a preview session
2. Get the resulting preview token
3. Pass `?_preview_token=…` (or similar) to the frontend URL

However, this involves a second HTTP request from PHP, token management, and potential session isolation issues. In the Contao 5.7 source, the preview entry point `preview.php` already handles this by checking if a valid backend session cookie is present — if so, it activates preview mode automatically (same Symfony app, shared session store).

**Decision:**
Use `/preview.php/{language}/{alias}.html` as the iframe URL. No explicit token generation.

**Consequences:**
- (+) Simple — only requires knowing the page alias and language
- (+) Inherits the backend user's existing session → unpublished content is visible
- (+) No PHP token generation code to maintain
- (-) Relies on backend and frontend sharing the same session (same origin, same Symfony kernel) — breaks if the frontend is on a separate domain or a CDN-fronted setup
- (-) Contao `preview.php` behaviour is not a documented API contract; it could change
- **Mitigation:** If this breaks, upgrade to explicit `FrontendPreviewAuthenticator` token injection in `PreviewResolverController`.

---

## ADR-004: CSS fixed sidebar vs. split-pane vs. floating overlay

**Date:** 2025-05-03
**Status:** Accepted

**Context:**
Options for rendering the preview panel:
1. **CSS fixed sidebar** — position: fixed on the right; push main content with margin-right
2. **Split-pane JS library** — divides the backend into two resizable panes
3. **Floating overlay** — always on top, no layout adjustment

**Decision:**
Use a CSS fixed sidebar with `margin-right` on `#wrapper`, toggled via a CSS custom property (`--clp-sidebar-width`).

**Consequences:**
- (+) Pure CSS — no JavaScript layout libraries
- (+) The `--clp-sidebar-width` custom property drives both the margin and the sidebar visibility in a single variable change
- (+) Drag-to-resize is achievable with a simple mousedown delta calculation, no library needed
- (+) Contao's `#wrapper` is the correct single layout container to offset (verified in Contao 5.7 `be_main`)
- (-) On viewports narrower than 1400px, the sidebar overlays content rather than pushing it (to avoid horizontal overflow). This is intentional but means the sidebar obscures part of the backend on laptop screens.
- (-) If Contao changes `#wrapper` to a different selector or uses a different layout model (e.g. CSS Grid with named areas), the margin hack will break. The fallback is to use `padding-right` on `body` instead.
