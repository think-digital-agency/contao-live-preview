<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

trait LabelCleanerTrait
{
    /**
     * Resolves a human label for a content-element / module type.
     *
     * Contao 6 exposes these as translator keys `CTE.<type>.0` / `FMD.<type>.0`
     * in the `contao_default` domain — this covers core fragment elements
     * (whose labels live in `default.xlf`) as well as bundle/theme labels
     * registered in `languages/<lang>/default.php`. Reading
     * `$GLOBALS['TL_LANG']` directly only worked for legacy-registered types and
     * required the right language file to be loaded first.
     */
    private function resolveLabel(string $type, TranslatorInterface $translator): string
    {
        if ('' === $type) {
            return '';
        }

        foreach ([['CTE', 'contao_default'], ['FMD', 'contao_modules']] as [$group, $domain]) {
            $key = "$group.$type.0";
            $label = $translator->trans($key, [], $domain);

            if ('' !== $label && $label !== $key) {
                return $this->cleanLabel($label);
            }
        }

        return '';
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
