<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;

trait LabelCleanerTrait
{
    private function resolveLabel(string $type, ContaoFramework $framework): string
    {
        if ('' === $type) {
            return '';
        }

        /** @var System $system */
        $system = $framework->getAdapter(System::class);
        $system->loadLanguageFile('modules');

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
