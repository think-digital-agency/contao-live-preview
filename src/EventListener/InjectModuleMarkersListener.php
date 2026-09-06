<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Symfony\Contracts\Translation\TranslatorInterface;
use Contao\ModuleModel;
use Symfony\Component\HttpFoundation\RequestStack;
use ThinkDigital\ContaoLivePreview\Service\LabelCleanerTrait;

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
 * Labels are resolved via the translator (`FMD.<type>.0` / `CTE.<type>.0`,
 * `contao_default` domain) in LabelCleanerTrait — same as the content-element
 * listeners.
 */
#[AsHook('getFrontendModule')]
class InjectModuleMarkersListener
{
    use LabelCleanerTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
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

        $label = $this->resolveLabel((string) ($model->type ?? ''), $this->translator);

        return preg_replace(
            '/(<[a-z][a-z0-9]*\b)/i',
            '$1 data-contao-table="tl_module" data-contao-id="' . $id . '" data-contao-label="' . htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"',
            $buffer,
            1,
        ) ?? $buffer;
    }

}
