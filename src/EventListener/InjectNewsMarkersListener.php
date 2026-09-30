<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\FrontendTemplate;
use Contao\Input;
use Contao\Module;
use Contao\StringUtil;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Markup;

/**
 * Injects data-contao-table="tl_news", data-contao-id="{N}", and
 * data-contao-label="Nachrichten" into news teaser wrappers when the page is
 * loaded inside the live-preview iframe (?_clp=1).
 */
#[AsHook('parseArticles')]
class InjectNewsMarkersListener
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ScopeMatcher $scopeMatcher,
    ) {
    }

    public function __invoke(FrontendTemplate $template, array $row, Module $module): void
    {
        if (
            $this->scopeMatcher->isBackendRequest($this->requestStack->getCurrentRequest()) // skip in backend
            || !Input::get('_clp') // only show in live preview
            || str_contains($template->class, 'data-contao-table=') // skip if already annotated
            || null === $template->id // skip without valid id
        ) {
            return;
        }

        // Create data marker attributes
        $attributes = new HtmlAttributes([
            'data-contao-table' => 'tl_news',
            'data-contao-id' => $template->id,
            'data-contao-label' => $GLOBALS['TL_LANG']['CLP']['news'],
        ]);

        // Explicitly encode class attribute and append data markers
        $strClass = StringUtil::specialcharsAttribute($template->class) . substr($attributes->toString(), 0, -1);

        // Inject (encoded) class with appended markers using \Twig\Markup (to prevent escaping).
        $template->class = new Markup($strClass, 'UTF-8');
    }
}