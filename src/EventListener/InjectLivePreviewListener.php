<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Injects the live preview sidebar into the Contao backend main template.
 *
 * Strategy:
 *   1. Render the <aside id="clp-right"> and place it right before </div> that
 *      closes #container. We find this by looking for </main>\n......</div> which
 *      is the reliable closing sequence in Contao's be_main template.
 *   2. The JS then finds it already in-place inside #container (no DOM move needed).
 *   3. CSS and JS tags go into <head> / before </body> as usual.
 *
 * Asset URLs are resolved via Symfony's Packages service, which respects
 * framework.assets.base_path (subdirectory installs) and framework.assets.version
 * (cache busting) if configured.
 */
#[AsHook('outputBackendTemplate')]
class InjectLivePreviewListener
{
    public function __construct(
        private readonly Environment  $twig,
        private readonly RequestStack $requestStack,
        private readonly Packages     $packages,
    ) {
    }

    public function __invoke(string $buffer, string $template): string
    {
        if ('be_main' !== $template) {
            return $buffer;
        }

        // Never inject into popups, pickers, or any modal-style backend window.
        // Contao signals these contexts via query parameters:
        //   popup=1  → article properties, wizard dialogs, etc.
        //   picker   → file picker, link picker, page picker, etc.
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request) {
            if ($request->query->getBoolean('popup') || $request->query->has('picker')) {
                return $buffer;
            }
        }

        $sidebarHtml = $this->twig->render('@ContaoLivePreview/backend/live_preview_sidebar.html.twig');

        $publicDir = \dirname(__DIR__, 2) . '/public';
        $cssVer    = @filemtime($publicDir . '/css/live-preview.css') ?: 1;
        $jsVer     = @filemtime($publicDir . '/js/live-preview.js') ?: 1;

        $cssHref = htmlspecialchars($this->packages->getUrl('bundles/contaolivepreview/css/live-preview.css'), \ENT_QUOTES, 'UTF-8') . '?v=' . $cssVer;
        $jsHref  = htmlspecialchars($this->packages->getUrl('bundles/contaolivepreview/js/live-preview.js'), \ENT_QUOTES, 'UTF-8') . '?v=' . $jsVer;
        $cssTag  = '<link rel="stylesheet" href="' . $cssHref . '">';
        $jsTag   = '<script src="' . $jsHref . '" defer></script>';

        // Inject CSS into <head>
        $buffer = str_replace('</head>', $cssTag . "\n</head>", $buffer);

        // Inject <aside> inside #container, right after </main>.
        // Contao's be_main renders </main> followed (after whitespace) by </div> for #container.
        // Inserting after </main> places the aside as the last flex child of #container.
        $buffer = str_replace('</main>', '</main>' . "\n" . $sidebarHtml, $buffer);

        // Inject JS before </body>
        $buffer = str_replace('</body>', $jsTag . "\n</body>", $buffer);

        return $buffer;
    }
}
