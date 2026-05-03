# TODO.md – ContaoLivePreviewBundle

## Done ✓

- [x] Bundle skeleton: `ContaoLivePreviewBundle.php`, `ContaoLivePreviewExtension.php`, `Plugin.php`
- [x] `outputBackendTemplate` hook listener with CSS/JS/HTML injection
- [x] Sidebar Twig template: toolbar (URL display, open-tab, refresh, toggle), iframe, spinner, no-context placeholder
- [x] AJAX resolver endpoint: `GET /contao/live-preview/resolve`
- [x] DBAL resolver service: `tl_content → tl_article → tl_page` chain
- [x] `do=article` and `do=page` context support in controller and resolver
- [x] Preview URL construction via `/preview.php/{lang}/{alias}.html`
- [x] Vanilla JS: context parsing from backend URL params (`do`, `table`, `id`, `act`)
- [x] Vanilla JS: AJAX resolve with debounce (300ms)
- [x] Vanilla JS: iframe management (set src, reload, loading indicator)
- [x] Vanilla JS: save detection (form submit + `.tl_confirm` MutationObserver)
- [x] Vanilla JS: navigation detection (title mutation + `#main` childList + popstate)
- [x] Vanilla JS: sidebar toggle with `localStorage` persistence
- [x] Vanilla JS: drag-to-resize with `localStorage` persistence
- [x] Vanilla JS: floating FAB toggle (visible when sidebar is collapsed)
- [x] CSS: fixed sidebar, toolbar, responsive breakpoints (≥1400px layout shift, <1400px overlay)
- [x] `services.yaml`, `routes.yaml`, `composer.json`
- [x] CLAUDE.md, ARCHITECTURE.md, DECISIONS.md, INSTALL.md

## Backlog

- [ ] **Feature:** Full `FrontendPreviewAuthenticator` token-based preview (ADR-003 mitigation)
- [ ] **Feature:** Support `do=news` context (tl_news → tl_news_archive → tl_page)
- [ ] **Feature:** Support `do=calendar` context (tl_calendar_events → tl_calendar → tl_page)
- [ ] **Feature:** Backend toolbar button (eye icon in the top Contao toolbar via `BACKEND_MENU_BUILD`)
- [ ] **Feature:** Resizable sidebar — save width in backend user settings (tl_user) instead of localStorage
- [ ] **Config:** Make sidebar default width configurable via `contao.yaml` extension config
- [ ] **Config:** Allow disabling auto-open on wide screens via config or backend user preference
- [ ] **Testing:** Smoke test: does `be_main` hook fire and sidebar HTML appear in the rendered output?
- [ ] **Testing:** Integration test for `PreviewResolverController`: mock DBAL, assert JSON response
- [ ] **Testing:** Test `tl_content` with `ptable=tl_article` vs `ptable=tl_news` (custom ptable support)

## In Progress

_(nothing currently — initial implementation complete)_

## Blocked

- [ ] Verify `/preview.php` session sharing works on this dev setup (requires manual browser test — cannot be verified without running the Contao instance)
