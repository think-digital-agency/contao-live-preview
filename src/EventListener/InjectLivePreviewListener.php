<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Twig\Environment;

/**
 * Injects the live preview sidebar into the Contao backend main template.
 *
 * Uses the outputBackendTemplate hook so injection is scoped to be_main only,
 * avoiding interference with popups (be_popup) or login pages (be_login).
 */
#[AsHook('outputBackendTemplate')]
class InjectLivePreviewListener
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(string $buffer, string $template): string
    {
        if ('be_main' !== $template) {
            return $buffer;
        }

        $sidebarHtml = $this->twig->render('@ContaoLivePreview/backend/live_preview_sidebar.html.twig');

        $cssTag = '<link rel="stylesheet" href="/bundles/contaolivepreview/css/live-preview.css">';
        $jsTag  = '<script src="/bundles/contaolivepreview/js/live-preview.js" defer></script>';

        // Inject CSS into <head>
        $buffer = str_replace('</head>', $cssTag."\n</head>", $buffer);

        // Inject sidebar + JS before </body>
        $buffer = str_replace('</body>', $sidebarHtml."\n".$jsTag."\n</body>", $buffer);

        return $buffer;
    }
}
