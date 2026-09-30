<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\FrontendTemplate;
use Contao\Input;
use Contao\Module;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Appends a plain `clp-news-{id}` marker class to every news teaser/article
 * template when the page is loaded inside the live-preview iframe (?_clp=1).
 *
 * This only appends a bare CSS class token, not the final data-contao-*
 * attributes — news-bundle's Twig templates concatenate the `class` variable
 * into an already-open class attribute, e.g.
 * `class="layout_full block{{ class }}"` in news_full.html.twig (same in
 * news_latest.html.twig, used for teasers). Writing full `key="value"`
 * attribute syntax into that variable breaks the markup: the first quote
 * inside the injected string closes the `class` attribute early and
 * everything after it is parsed as garbage, so data-contao-table ends up
 * missing from the rendered HTML entirely. A bare class token has no such
 * problem. InjectNewsResponseMarkersListener does the real attribute
 * injection afterwards, on the fully rendered HTML.
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
            || null === $template->id // skip without valid id
            || str_contains((string) $template->class, 'clp-news-') // skip if already marked
        ) {
            return;
        }

        $template->class = ((string) $template->class) . ' clp-news-' . $template->id;
    }
}
