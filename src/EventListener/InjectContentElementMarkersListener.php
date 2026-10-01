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
 * Works for legacy ContentElement subclasses (including RSCE) and, in
 * Contao 6, native #[AsContentElement] fragment controllers too — the
 * getContentElement hook still fires for those as a compatibility shim,
 * contrary to what an earlier version of this bundle assumed (a dedicated
 * Twig-first listener existed for exactly that reason and was removed once
 * this was verified, including for nested/group CEs — see ADR-022 point 13).
 * Covers leaf and container/group CEs alike: the already-marked guard below
 * only checks the opening tag, so a container whose children are already
 * marked by the time this hook runs for the container itself still gets its
 * own wrapper marked correctly.
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

        $id = (int) $element->id;
        if ($id <= 0) {
            return $buffer;
        }

        // Skip only if the *opening tag* is already marked (e.g. by the theme).
        // Scanning the whole buffer would also match a nested data-contao-table=
        // deeper in the element markup and drop the wrapper's own markers (#10) —
        // mirror InjectTwigContentElementMarkersListener, which checks the tag.
        if (preg_match('/<[a-z][a-z0-9]*\b[^>]*>/i', $buffer, $openingTag)
            && str_contains($openingTag[0], 'data-contao-table=')
        ) {
            return $buffer;
        }

        $label = $this->resolveLabel((string) ($element->type ?? ''), $this->translator);

        return preg_replace(
            '/(<[a-z][a-z0-9]*\b)/i',
            '$1 data-contao-table="tl_content" data-contao-id="' . $id . '" data-contao-type="' . $element->type . '" data-contao-label="' . htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"',
            $buffer,
            1,
        ) ?? $buffer;
    }
}
