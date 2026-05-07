# Contao Live Preview

[![Packagist](https://img.shields.io/packagist/v/think-digital-agency/contao-live-preview.svg)](https://packagist.org/packages/think-digital-agency/contao-live-preview)
[![License](https://img.shields.io/packagist/l/think-digital-agency/contao-live-preview.svg)](LICENSE)

Adds a collapsible, context-aware live frontend preview sidebar to the **Contao 5 backend**.

When an editor opens a page, article, or content element in the backend, an iframe on the right shows the corresponding frontend page. Saving a record triggers an article-level DOM swap in the iframe — no full reload, scroll position stays intact.

## Features

- **Context-aware preview** — automatically shows the right frontend page for the current backend context (page / article / content element)
- **Partial DOM swap after save** — article content is refreshed without reloading the iframe; scroll position preserved
- **Hover inspection** — hover over any article or content element in the preview to see a fuchsia badge with a direct edit link
- **Edit badges** — click the pencil icon in any badge to jump straight to that record in the backend
- **Works with any Contao theme** — automatically injects the required `data-contao-*` attributes via Contao hooks; no theme changes needed
- **Resizable sidebar** — drag to resize; zoom in/out for small screens
- **Turbo-compatible** — sidebar persists across Turbo navigations via `data-turbo-permanent`; no iframe flash

## Requirements

- PHP 8.2 or higher
- Contao 5.3 or higher

## Installation

```bash
composer require think-digital-agency/contao-live-preview
php bin/console assets:install
php bin/console cache:clear
```

The bundle is automatically registered via the Contao Manager plugin. No additional configuration required.

## Usage

Open any page, article, or content element in the Contao backend. A **"Live Preview"** button appears in the top right of the backend header. Click it to open the preview sidebar.

The preview automatically updates when you:
- Navigate to a different page/article/CE in the backend
- Save a record (article DOM swap, no reload)
- Click a link inside the preview iframe (preserves the `?_clp=1` marker)

## Extending: Custom Table Support

Implement `PreviewUrlResolverInterface` to add support for custom tables (news, calendar, etc.):

```php
// src/Service/ExtendedPreviewUrlResolver.php
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolver;
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolverInterface;

class ExtendedPreviewUrlResolver implements PreviewUrlResolverInterface
{
    public function __construct(
        private readonly PreviewUrlResolver $inner,
        private readonly Connection $db,
    ) {}

    public function resolve(string $table, int $id): ?array
    {
        if ('tl_news' === $table) {
            $row = $this->db->fetchAssociative(
                'SELECT a.pid FROM tl_news n JOIN tl_news_archive a ON a.id = n.pid WHERE n.id = ?',
                [$id],
            );
            return $row ? $this->inner->resolve('tl_page', (int) $row['pid']) : null;
        }
        return $this->inner->resolve($table, $id);
    }
}
```

```yaml
# config/services.yaml
ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolverInterface:
    alias: App\Service\ExtendedPreviewUrlResolver
```

Then register the new context in `parseContext()` of your theme's JS (or override the bundle JS).

## Known Limitations

- **Twig-first CEs in multi-column layouts**: content elements without a custom CSS-ID may be misidentified when side columns appear before the main column in the HTML output. Set a CSS-ID on affected elements as a workaround.
- **Nested CEs** (e.g. accordion items): inner CE position counting may be offset. CSS-ID matching is always reliable.
- **`noMarkup` articles**: article swap and highlight require the standard `id="article-{N}"` wrapper; `noMarkup` suppresses it.
- **`tl_page` context**: saving a page record does not trigger a DOM swap (no article context); a manual sidebar reload is needed.

## License

LGPL-3.0-or-later — see [LICENSE](LICENSE).
