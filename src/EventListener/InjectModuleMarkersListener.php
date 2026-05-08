<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ModuleModel;
use Contao\System;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Injects data-contao-table="tl_module", data-contao-id="{N}", and
 * data-contao-label="{Human label}" into the frontend module wrapper when the
 * page is loaded inside the live-preview iframe (?_clp=1).
 *
 * The getFrontendModule hook fires inside Controller::getFrontendModule() for
 * every module rendered through the standard Contao layout pipeline, including
 * preloaded layout modules (PageRegular preloads all column modules before
 * assembling the template). Legacy Module subclasses and Twig-first
 * #[AsFrontendModule] controllers both go through this path.
 *
 * Module labels come from $GLOBALS['TL_LANG']['FMD'] (Frontend Module
 * Definitions), distinct from $GLOBALS['TL_LANG']['CTE'] (Content Type
 * Elements) used by InjectContentElementMarkersListener.
 */
#[AsHook('getFrontendModule')]
class InjectModuleMarkersListener
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function __invoke(ModuleModel $model, string $buffer): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request?->query->getBoolean('_clp')) {
            return $buffer;
        }

        if ('' === trim($buffer)) {
            return $buffer;
        }

        // Skip if already marked (e.g. by the theme or another listener).
        if (str_contains($buffer, 'data-contao-table=')) {
            return $buffer;
        }

        $id = (int) $model->id;
        if ($id <= 0) {
            return $buffer;
        }

        $label = $this->resolveLabel((string) ($model->type ?? ''));

        return preg_replace(
            '/(<[a-z][a-z0-9]*\b)/i',
            '$1 data-contao-table="tl_module" data-contao-id="' . $id . '" data-contao-label="' . htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"',
            $buffer,
            1,
        ) ?? $buffer;
    }

    private function resolveLabel(string $type): string
    {
        if ('' === $type) {
            return '';
        }

        /** @var System $system */
        $system = $this->framework->getAdapter(System::class);
        $system->loadLanguageFile('modules');

        return (string) ($GLOBALS['TL_LANG']['FMD'][$type][0] ?? $type);
    }
}
