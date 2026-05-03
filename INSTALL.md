# INSTALL.md – Local Development Setup

## Prerequisites

- Contao 5.3+ installed and running (this project: `rl-contao-theme-design`)
- PHP 8.2+
- Composer 2.x

---

## Step 1: Verify bundle location

The bundle lives at:
```
rl-contao-theme-design/packages/contao-live-preview-bundle/
```

This is relative to the Contao installation root, so the path for Composer is:
```
./packages/contao-live-preview-bundle
```

---

## Step 2: Add path repository to Contao's `composer.json`

In the root `composer.json` of the Contao project, add a `repositories` section (or extend it if it already exists):

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

Composer will symlink (or copy) the bundle into `vendor/vendor/contao-live-preview-bundle/`.

---

## Step 4: Install public assets

```bash
docker compose exec php bin/console assets:install --symlink
```

This creates `public/bundles/contaoliveprevie/` pointing to the bundle's `public/` directory.

> **Asset symlink name:** Symfony derives the symlink from the bundle class name `ContaoLivePreviewBundle` → lowercase → `contaolivepreview`. The paths are already correct in `InjectLivePreviewListener.php`. No action needed.

---

## Step 5: Clear and warm up the cache

```bash
docker compose exec php bin/console cache:clear
docker compose exec php bin/console cache:warmup
```

Both steps are required. `cache:warmup` registers the `@ContaoLivePreview` Twig namespace.

---

## Step 6: Test

1. Open the Contao backend: http://localhost:8080/contao
2. Navigate to **Pages** and click on a page to edit it
3. The live preview sidebar should appear on the right side of the backend
4. The iframe should load the corresponding frontend page

---

## Troubleshooting

**Sidebar does not appear**
- Check that the hook fired: add `dump($template)` in `InjectLivePreviewListener` and run any backend page
- Verify `assets:install` ran and the symlink exists: `ls -la public/bundles/`
- Check browser console for 404 on CSS/JS files

**iframe shows blank / 403**
- Navigate to the preview URL directly in a new tab: `/preview.php/de/home.html`
- If it shows a login page, the session sharing between backend and `preview.php` is not working — see ADR-003 for the mitigation path

**"Twig template not found" error**
- Run `cache:warmup` — the `@ContaoLivePreview` namespace is registered during warmup, not cache:clear

**Resolve endpoint returns 401**
- Ensure you are logged in to the backend (`ROLE_USER` is required)
- Test directly: `curl -b "PHPSESSID=..." http://localhost:8080/contao/live-preview/resolve?table=tl_page&id=1`

---

## Production Installation (once published to Packagist)

```bash
composer require vendor/contao-live-preview-bundle
bin/console assets:install
bin/console cache:clear && bin/console cache:warmup
```
