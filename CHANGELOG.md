# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
