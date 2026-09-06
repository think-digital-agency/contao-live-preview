<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\ContentModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use ThinkDigital\ContaoLivePreview\Service\LabelCleanerTrait;

/**
 * Injects data-contao-table="tl_content", data-contao-id="{N}", and
 * data-contao-label="{Human label}" into the content element wrapper when the
 * page is loaded inside the live-preview iframe (?_clp=1).
 *
 * Works for legacy ContentElement subclasses (including RSCE). Twig-first
 * content elements registered via #[AsContentElement] bypass this hook and are
 * handled by InjectTwigContentElementMarkersListener instead.
 */
#[AsHook('getContentElement')]
class InjectContentElementMarkersListener
{
    use LabelCleanerTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
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

        $label = $this->resolveLabel((string) ($element->type ?? ''), $this->translator);

        return preg_replace(
            '/(<[a-z][a-z0-9]*\b)/i',
            '$1 data-contao-table="tl_content" data-contao-id="' . $id . '" data-contao-label="' . htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"',
            $buffer,
            1,
        ) ?? $buffer;
    }
}
