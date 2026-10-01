# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [3.1.0] - 2026-09-30

### Added
- **Extensibility API**: `window.CLP_BE` (backend) and `window.CLP_FE` (iframe) registries let third-party JS add/replace/remove badge actions and message handlers without patching this bundle — `.on()`/`.off()`/`.dispatch()` for messages, `.augment()`/`.unaugment()` for outgoing payloads (`CLP_BE`), `.set()`/`.remove()`/`.move()`/`.each()` for badge actions (`CLP_FE`). New `ResolvePreviewEvent` (`clp:resolve`) lets PHP listeners resolve a preview URL dynamically, ahead of the resolver chain. New `injectLivePreview` / `injectPreviewScript` hooks for injecting custom scripts. Versioned `postMessage` protocol documented in `docs/PROTOCOL.md`; extension guide in `docs/EXTENDING.md`.
- **Grid / box-model overlay**: a toggle button in the sidebar shows margin/border/padding/content guide lines (DevTools-style) for the hovered element in the preview iframe. State persists across reloads via `localStorage`.
- **News post preview**: content elements, teasers and archive views for `tl_news` now resolve to the correct frontend page and highlight/hover like articles — edit, duplicate and insert-after badges route to the `do=news` backend views.
- **Element group duplicate / insert-after fix**: these actions now use Contao's `mode=1` (paste-after) + `&ptable=tl_content` for content elements nested in an element group, instead of `mode=4`, which Contao rejects as "not creatable" for group children.
- Nested content-element marker detection (`InjectTwigContentElementMarkersListener`) now walks up to 10 levels deep, so element groups nested inside element groups are marked and highlighted correctly.
- Grid/box-model overlay toggle icon replaced with a crop-marks glyph, clearer at 14px than the previous grid-lines icon.

### Changed
- Badge placement is now a fixed rule instead of reactive collision handling: container badges (article, and any group-type content element — Accordion, Card, Tabs, Elementgruppe — that has marked children of its own) always render outside/above their box; leaf badges (a plain content element, or a `tl_news` record) always render inside/top-left, even on a box shorter than the badge. Previously every badge decided inside-vs-above per its own box height, and an overlap between two independently-placed badges was corrected afterwards by nudging one of them up by the other's height (`clpDeconflict()`) — workable for a single collision, but stacked unpredictably with more than two badges near the same corner. The two badge kinds no longer share the same vertical band in the common case, so that reactive nudge is now rarely needed; it stays in place as a fallback for the remaining edge case (e.g. a group directly inside another group/article with no padding between them, where two "above" badges can still meet).
- Active and hover badges now share one margin (flush against the outline, no gap) instead of two different values — a badge no longer jumps position when the same element transitions between hover and active; only an actual collision ever moves one away from that baseline now.
- Hovering a content element nested directly in a group or article now also shows the group's/article's own hover outline and badge, not just the element's. A leaf element usually fills its container's entire box, which made the container itself nearly impossible to hover on its own. An element that is already the active (blue-badged) one is never also given this hover treatment, whether it's the one being hovered or the parent being boosted.
- Badge action icons (edit, duplicate, insert-after) now show a darker rounded background behind the icon on hover, not just the existing opacity change — matches the hover affordance style of similar icon-button groups elsewhere.

### Fixed
- Preview badge outline is no longer clipped by an `overflow: hidden` ancestor — outline is now drawn on a `::before` pseudo-element instead of the element's own `outline` property.
- Hovering an element whose badge would overlap the currently-selected element's badge now offsets automatically instead of rendering on top of it.
- Highlighting a top-level `tl_news` record (previewing a news post directly, not a content element inside one) now shows a working edit badge — it previously fell through to the generic single-highlight path, which rendered a badge with no click handler.
- News post and teaser wrappers are now correctly annotated with `data-contao-*` markers in the rendered HTML. news-bundle's Twig templates concatenate the wrapper's `class` variable into an already-open `class="..."` attribute (e.g. `class="layout_full block{{ class }}"`); writing full `key="value"` attribute syntax into that variable broke the markup, so the markers never actually reached the DOM and news highlighting silently did nothing in either direction. `InjectNewsMarkersListener` now appends only a plain, quote-free marker class pre-render; the new `InjectNewsResponseMarkersListener` converts it into real attributes after the page is fully rendered.
- Hovering the currently-active (blue-badged) element or article no longer also shows a second, overlapping pink hover badge that shifted the active badge's position.
- Hovering a `position: fixed` module (e.g. a sticky header or offcanvas bar) no longer makes it jump into the document flow and flicker. The outline `::before` pseudo-element needs its parent to be positioned (not `static`) to line up correctly, but the CSS rule that provided this forced `position: relative` unconditionally — silently overriding `position: fixed` and pulling the element out of its fixed stacking context. This regressed a fix already shipped in 2026-05 (commit `84f2908`) that the element-group merge reintroduced. `position: relative` is now applied only when the element's own computed position is actually `static`.
- The hover outline (pink, dashed) is drawn 2px further inside the element edge than the selection outline (blue) was, an inconsistency introduced by the `::before` migration above. Both now use the same `-2px` inset.
- News post preview URL: previewing a `tl_news` record (or a content element inside one) now opens the item's actual reader URL (e.g. `/news-blog/details/my-article.html`) instead of the archive's bare reader page, which showed an empty page and made every `tl_news` highlight selector match nothing — the news preview feature added earlier in this release never actually worked end-to-end because of this. Resolved via Contao's own `ContentUrlGenerator` (the same service `{{news_url::}}` uses), with a fallback to the bare reader page if resolution fails.
- Hovering the currently-active element could still show a second, overlapping hover badge in some cases even after the mouseover-handler fix above, because Contao 6 also still fires the legacy `getContentElement` hook for native `#[AsContentElement]` controllers — `InjectContentElementMarkersListener` marks the CE's outer grid-column wrapper while `InjectTwigContentElementMarkersListener` independently marks the inner `ce_`/`content-` tag, producing two marked DOM nodes for one CE and breaking the hover handler's object-identity check. The Twig listener now skips a CE it finds already marked anywhere in the response.
- Editing a content element could highlight, and route the edit badge to, an unrelated record — including a `tl_news` item, navigating the backend to a random news edit screen instead of the element actually being edited. `InjectTwigContentElementMarkersListener::loadContentElements()` joined `tl_content` to `tl_article` on `pid` alone, without checking `ptable`; a content element belonging to `tl_news` (or any other parent table) whose numeric `pid` happened to match a real article's id on the same page was pulled in as if it were that article's content, shifting the type/position matching for every following same-type element on the page. The query now also requires `c.ptable = 'tl_article'`.
- Editing a content element nested inside a container that only opens via its own page-load JS (an `openOnLoad` accordion panel, in the case this was found in) could render its badge pinned to the top-left corner of the viewport and leave the page unscrolled, instead of jumping to and outlining the element. `clp:highlight`'s geometry read raced that JS: `document.readyState` can already be `'complete'` while the container is still collapsed, and measured directly, its target settled anywhere from 224ms to 474ms later with no fixed schedule. Badge positioning (not the outline, which is CSS-driven and self-corrects) now waits out a baseline long enough to clear that measured range before reading geometry.
- Switching from one content element's badge to another's in the same article (via the edit badge, or re-selecting a different element) made the article's own badge visibly flash away and reappear, even though the article itself hadn't changed. Two compounding causes: the article badge was unconditionally removed and recreated on every `clp:highlight`, regardless of whether the article actually differed from before; and the settle-delay added for the fix above applied to every `clp:highlight`, not only the first one on a freshly loaded page, so the recreated badge took the full baseline to reappear instead of doing so immediately. The article badge is now only touched when the article has actually changed, and the settle delay only applies once per page load — a later `clp:highlight` on an already-settled page positions badges immediately.

### Removed
- `InjectTwigContentElementMarkersListener` — the two bugs above and point 8's double-marking fix all traced back to this one class (regex HTML parsing + DB-position matching, standing in for Twig-first content elements the legacy `getContentElement` hook was assumed not to reach). Verified — leaf CEs, custom fragment-controller types, group CEs, and nested children in both open and collapsed panels, with the listener disabled — that `InjectContentElementMarkersListener` alone already marks all of them correctly in Contao 6; the assumption motivating the Twig-specific listener no longer held. See `docs/DECISIONS.md` ADR-022 point 13.

## [3.0.5] - 2026-09-09

### Fixed
- Badge "edit" / "duplicate" / "insert after" now navigate to the canonical backend entry point (the `contao_backend` route, injected server-side) instead of `window.location.pathname`. Triggering an action while on a backend sub-route (e.g. `/contao/template-studio`) previously built a URL like `/contao/template-studio?do=…` and hit the "Page Not Found" fallback.
- File manager and other tree views (`.tree-view`): the filter panel (`.tl_panel` / `.content-filter`) now slides out to the right under the sidebar when the preview is open, same as the edit-all view — previously it stayed a fixed column and squeezed the tree.

## [3.0.4] - 2026-09-09

### Fixed
- The sidebar (which lives on `<html>` and survives Turbo body swaps) no longer covers or loops on pages that are not the normal backend chrome — the standalone error page, the login screen, the install tool. `live-preview.js` now goes dormant there: it collapses the sidebar and skips context resolve + iframe reload (previously every failed navigation triggered another `/contao/live-preview/resolve` request and an iframe reload). It reactivates on the next real backend page.

## [3.0.3] - 2026-09-09

### Added
- Third-party preview resolvers now compose: every `PreviewUrlResolverInterface` service is tagged into a priority chain (`ChainPreviewUrlResolver`), first non-null result wins, the bundle's own resolver runs last. Two bundles can add tables (or override a core one) without fighting over the service alias. Re-aliasing the interface stays as the full-control escape hatch. (ADR-021)

### Fixed
- `resolve()` no longer throws a 500 (`Unknown column 'pid'`) for a known table that is not a DCA child table — e.g. `tl_module` / `tl_layout` (whose `ptable` chain hits the parent-less `tl_theme`), or a table reached with `?table=…&act=edit` that has no `pid` column. The child-table walk now requires a declared parent (`config.ptable` / `config.dynamicPtable`) and verifies the columns exist before querying, with a `try/catch` backstop. Regression from 3.0.2 (#9).
- Preview overlay: in dual-highlight mode (content element + article), the article badge no longer hides behind the element badge when the article/group has no padding — overlapping badges are stacked. A badge on a box shorter than itself now renders just above the box instead of overflowing it. (B3 point 4)
- `?_clp=1` is kept across in-frame navigation for `target="_blank"` internal links (now open a real tab instead of being trapped in the iframe), form submissions (search / filters), and is correctly skipped for `download` / `mailto:` / `tel:` / modified clicks. Previously these dropped the marker/hover/refresh machinery until the next backend resolve. (B3 point 5)

## [3.0.2] - 2026-09-09

### Added
- Custom child tables are now resolvable in the preview without any integration code. When the edited table is not `tl_content` / `tl_article` / `tl_page`, `PreviewUrlResolver` walks its DCA `ptable` chain (`config.ptable`, or the record's `ptable` column when `config.dynamicPtable` is set) up to a known parent and previews that page. The `PreviewUrlResolverInterface` alias override remains for non-standard storage models. (#9, ADR-021)

### Fixed
- The stylus / hover-edit and hot-refresh no longer jump the preview to an unrelated same-ID article (a different domain in multisite setups) when editing a record in a custom child table. `parseContext()` passes the real table through to the resolver instead of coercing unknown tables to `tl_article`. (#9)
- Element / module / article wrappers no longer lose their own `data-contao-*` markers when the rendered buffer contains a nested `data-contao-table` somewhere inside (an embedded marked element, or — for articles — any already-marked content element). The already-marked guard in `InjectContentElementMarkersListener`, `InjectModuleMarkersListener` and `InjectArticleMarkersListener` now checks only the wrapper's opening tag, matching `InjectTwigContentElementMarkersListener`. The article guard in particular was previously tripped on every non-empty article. (#10)

## [3.0.1] - 2026-09-06

### Fixed
- Element/module labels in the preview overlay now resolve via the translator (`CTE.<type>.0` / `FMD.<type>.0`), so Contao 6 fragment elements (accordion, slider/masonry wrappers, …) and bundle/theme types show a proper name instead of the generic fallback.
- Twig content-element marker detection also matches Contao 6's `content-<type>` wrapper class (in addition to the legacy `ce_<type>`).

## [3.0.0] - 2026-09-06

### Changed
- **Contao 6 support.** `contao/core-bundle` constraint widened to `^5.3 || ^6.0`. No API changes — the bundle already used stable Manager-plugin and event APIs; verified functional on Contao 6.0 / Symfony 8.1 (`?_clp=1` preview + marker injection).

## [2.3.21] - 2026-06-16

### Fixed
- `grid-template-columns` override for `.tl_show_all` now uses `!important` — Contao's `#main .tl_show_all:has(>.content-filter)` ID selector was winning the specificity battle and the filter panel was not being displaced

## [2.3.20] - 2026-06-15

### Fixed
- Backend edit-all view (`.tl_show_all`): content column now expands to full width when the sidebar is open — the filter panel slides out to the right under the preview sidebar instead of squishing the layout. Animated with the same easing as the sidebar itself (`clp-animate` guard).
- `overflow-x: clip` on `body.clp-open` (desktop) prevents a horizontal scrollbar from appearing without breaking `position: sticky` elements (unlike `overflow: hidden`, `clip` does not create a new scroll container).
- Long submit-button labels (`tl_submit`) are now truncated with ellipsis when the sidebar is open to prevent overflow in narrow backend forms.

## [2.3.19] - 2026-06-15

### Fixed
- Re-release as a clean tag — Packagist rejected v2.3.17 and v2.3.18 due to force-pushed tags (stable versions are immutable on Packagist). No functional changes vs v2.3.17.

## [2.3.18] - 2026-06-15

### Fixed
- Re-release of v2.3.17 as a new tag — Packagist rejected the force-pushed v2.3.17 tag (stable versions are immutable). No functional changes vs v2.3.17.

## [2.3.17] - 2026-06-15

### Fixed
- **Element group context (Issue #6)**: `resolveFromContent()` now reads `ptable` and walks up through nested element groups (`ptable = 'tl_content'`) until it reaches the owning article — previewing CEs inside element groups now works correctly
- **Page layout context (Issue #6)**: backend layout edit form (`tl_layout`) is now treated like module editing — sidebar stays on the current page and refreshes after save instead of falling back to root page
- **Element group list view**: `?do=article&table=tl_content&id=X&ptable=tl_content` URL (element group child list) is now correctly identified as a group context, preventing blank preview on full backend page reload

## [2.3.16] - 2026-06-14

### Documentation
- Update app icon (new PSD + PNG)
- Improve package-metadata descriptions and keywords for Contao Extension Store (de + en)
- Track `docs/app-icon.psd` in version control

## [2.3.14] - 2026-05-27

### Documentation
- Rewrite README: modern language throughout, no iFrame jargon, "Hot-Refresh" terminology, tightened feature list

## [2.3.13] - 2026-05-26

### Fixed
- Preview responses now use `Cache-Control: private, no-cache` instead of `max-age=60` — browser always revalidates before using cached content, eliminating stale preview on back-navigation without a save

## [2.3.12] - 2026-05-26

### Fixed
- Iframe showed cached (stale) content after a full-page backend reload (Contao 5.3 post-save): `tryRehydrate()` full-reload path now adds a `_t` cache-buster when setting `frame.src`

## [2.3.11] - 2026-05-22

### Documentation
- Add CHANGELOG.md (full history from v1.0.0)
- README: Downloads badge + short English intro block
- `composer.json`: updated description to English, benefit-focused
- Move ARCHITECTURE.md and DECISIONS.md to `docs/`, remove INSTALL.md

## [2.3.10] - 2026-05-22

### Fixed
- Live Preview not updating on Contao ≤5.5 (Turbo v7): add `turbo:load` as entry-point event alongside `turbo:render` — Turbo v7 does not fire `turbo:render`, only `turbo:load`
- Add 5 s safety-timeout in `refreshPreview()`: if `clp:refreshed` never arrives (e.g. inline frontend script absent), fall back to a full iframe reload with cache-buster

## [2.3.9] - 2026-05-17

### Fixed
- Toolbar height 1 px too tall: subtract 1 px to account for the toolbar's own `border-bottom`

## [2.3.8] - 2026-05-17

### Added
- JS measures actual `#header` height and writes it as `--clp-header-h` CSS variable — toolbar height and overlay offset now auto-adapt to any Contao version without hardcoded pixel values

## [2.3.7] - 2026-05-14

### Fixed
- Box-shadow in overlay mode no longer bleeds over the Contao header (`clip-path`)
- Sidebar aligns 1 px below the header border-bottom in overlay mode

## [2.3.6] - 2026-05-13

### Documentation
- Update ARCHITECTURE.md, DECISIONS.md, INSTALL.md for v2.3.x

## [2.3.5] - 2026-05-13

### Fixed
- Toolbar bottom border now uses `--content-border` for better visual separation

## [2.3.4] - 2026-05-13

### Fixed
- Toggle button and hover styling now work correctly on both Contao ≤5.7.3 (orange header) and ≥5.7.4 (white header) via CSS custom property chaining — no JS version detection needed

## [2.3.3] - 2026-05-13

### Fixed
- Toggle button text was unreadable on Contao 5.7.4's new white backend header

## [2.3.2] - 2026-05-13

### Fixed
- Toolbar height now matches the Contao backend header height (44 px default)

## [2.3.1] - 2026-05-13

### Added
- **Duplicate (⧉) and insert-after (+) quick actions** on the active content element badge — create a copy or a new element in one click, without navigating back to the list

### Fixed
- Iframe now refreshes correctly after content-modifying actions (delete, toggle, copy, cut, paste) that redirect back to the list without a form submit

## [2.3.0] - 2026-05-13

### Added
- Sidebar is now mounted on `<html>` instead of `<body>` — Turbo body-swaps no longer destroy or involuntarily reload the sidebar iframe
- **Module editing**: navigate directly to the frontend module edit form by clicking a module badge in the preview
- `turbo:before-render` strips the server-injected duplicate `#clp-right` from every incoming body before the swap

### Fixed
- Multiple IIFE instances on the same page no longer conflict (`window.__clpLoaded` guard)

## [2.2.5] - 2026-05-12

### Fixed
- White flash of the src-less placeholder iframe during Turbo body-swap: hidden via `#clp-frame:not([src]) { visibility: hidden }`

## [2.2.3] - 2026-05-12

### Fixed
- Race condition: `resolveAndShow` no longer resets `frame.src` during an active save cycle (`pendingSave` guard with 3 s safety TTL)

## [2.2.1] - 2026-05-11

### Fixed
- Enlarged click area on the edit-pencil icon in hover and highlight badges

## [2.2.0] - 2026-05-10

### Added
- Smooth slide animation when opening or closing the sidebar; no animation on page restore to avoid flicker

## [2.1.1] - 2026-05-08

### Added
- Hover-edit for layout modules (`tl_module`) — hovering a module in the preview shows a badge with a direct link to the module edit form
- Visual grip indicator (three-dot handle) on the sidebar resize handle
- Fixed-element badge positioning for `position: fixed` elements

## [2.1.0] - 2026-05-08

### Added
- Root-page fallback: when no backend context is active, the preview shows the site's root page instead of staying blank

## [1.0.0] - 2026-05-08

Initial public release.

### Added
- Context-aware preview sidebar in the Contao 5 backend (pages, articles, content elements)
- Partial iframe DOM swap on save (`clp:refresh`) — scroll position and layout preserved, no full reload
- Rehydration system via `localStorage` — save state survives full page reloads (e.g. after asset changes)
- Hover inspection: badge showing element type and name; click opens edit form
- Dual highlighting: active content element (blue outline) + parent article (dashed outline)
- Resizable sidebar with persisted width and zoom control
- `PreviewUrlResolverInterface` for extending the resolver to custom tables (News, Calendar, …)
