# INSTALL.md – Local Development Setup

## Prerequisites

- Contao 5.5+ with Symfony 7.x
- PHP 8.4 (this project uses `php:8.4-fpm-alpine` via Docker)
- Composer 2.x

---

## Step 1: Verify bundle location

The bundle lives at:
```
rl-contao-theme-design/packages/contao-live-preview-bundle/
```

---

## Step 2: Add path repository to `composer.json`

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/contao-live-preview-bundle"
        }
    ]
}
```

---

## Step 3: Require the bundle

```bash
composer require vendor/contao-live-preview-bundle:*@dev
```

Composer symlinks the bundle into `vendor/vendor/contao-live-preview-bundle/`.

---

## Step 4: Install public assets

```bash
docker compose exec php bin/console assets:install --symlink
```

Symfony derives the symlink name from the bundle class `ContaoLivePreviewBundle` → lowercase, no separators → **`contaolivepreview`**.

Result: `public/bundles/contaolivepreview/` → `packages/contao-live-preview-bundle/public/`

The CSS/JS paths in `InjectLivePreviewListener` already use this name.

---

## Step 5: Clear and warm up the cache

```bash
docker compose exec php bin/console cache:clear
docker compose exec php bin/console cache:warmup
```

**Both steps are required.** `cache:warmup` registers the `@ContaoLivePreview` Twig namespace. `cache:clear` alone is not sufficient.

---

## Step 6: Test

1. Open the backend: http://localhost:8080/contao
2. Navigate to **Artikel** and open any content element for editing
3. The Preview sidebar should appear on the right
4. The iframe should load the corresponding frontend page URL (shown in the toolbar)
5. Verify popups are clean: click the pencil icon to open article properties — the popup must NOT contain the sidebar

---

## Troubleshooting

**Sidebar does not appear**
- Verify `assets:install` ran: `ls -la public/bundles/contaolivepreview/`
- Check browser console for 404 on `/bundles/contaolivepreview/css/live-preview.css`
- Run `cache:clear && cache:warmup` — `cache:clear` alone is not enough

**iframe shows blank white (no URL in toolbar)**
- Open DevTools → Network → check if `/contao/live-preview/resolve` returns 200
- If 401: session expired, re-login
- If 404: check routing with `bin/console debug:router | grep live-preview`
- If 200 but empty `previewUrl`: the page type may not be routable — check `tl_page.type`

**iframe shows correct URL but content is blank**
- Hard-reload backend (`Cmd+Shift+R`) to pick up latest JS (no cache-busting on the JS file)

**"Twig template not found"**
- Run `bin/console cache:warmup` — the `@ContaoLivePreview` namespace is registered during warmup

**Resolve endpoint returns 401**
- Ensure you are logged in (`ROLE_USER` required)
- Test: `curl -b cookies.txt 'http://localhost:8080/contao/live-preview/resolve?table=tl_page&id=1'`

---

## Production Installation (once published to Packagist)

```bash
composer require vendor/contao-live-preview-bundle
bin/console assets:install
bin/console cache:clear && bin/console cache:warmup
```
