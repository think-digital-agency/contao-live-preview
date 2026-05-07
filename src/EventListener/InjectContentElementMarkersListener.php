<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\EventListener;

use Contao\ContentModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Injects data-contao-table="tl_content", data-contao-id="{N}", and
 * data-contao-label="{Human label}" into the content element wrapper when the
 * page is loaded inside the live-preview iframe (?_clp=1).
 *
 * Works for legacy ContentElement subclasses (including RSCE). Twig-first
 * content elements registered via #[AsContentElement] bypass this hook.
 */
#[AsHook('getContentElement')]
class InjectContentElementMarkersListener
{
    private bool $langLoaded = false;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function __invoke(ContentModel $element, string $buffer): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request?->query->getBoolean('_clp')) {
            return $buffer;
        }

        // Skip if already marked (e.g. by the theme).
        if (str_contains($buffer, 'data-contao-table=')) {
            return $buffer;
        }

        $id = (int) $element->id;
        if ($id <= 0) {
            return $buffer;
        }

        $label = $this->resolveLabel((string) ($element->type ?? ''));

        return preg_replace(
            '/(<[a-z][a-z0-9]*\b)/i',
            '$1 data-contao-table="tl_content" data-contao-id="' . $id . '" data-contao-label="' . htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"',
            $buffer,
            1,
        ) ?? $buffer;
    }

    private function resolveLabel(string $type): string
    {
        if ('' === $type) {
            return '';
        }

        if (!$this->langLoaded) {
            $this->langLoaded = true;
            /** @var System $system */
            $system = $this->framework->getAdapter(System::class);
            $system->loadLanguageFile('modules');
        }

        return $this->cleanLabel((string) ($GLOBALS['TL_LANG']['CTE'][$type][0] ?? ''));
    }

    private function cleanLabel(string $label): string
    {
        $label = (string) preg_replace(
            '/\s*:?\s*\b(?:Wrapper\s+)?(?:Anfang|Start|Ende|End)\b\s*$|:?\s*\bWrapper\b\s*$/i',
            '',
            $label,
        );

        return trim((string) preg_replace('/\s{2,}/', ' ', $label));
    }
}
