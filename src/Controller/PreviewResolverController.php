<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\System;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vendor\ContaoLivePreviewBundle\Service\PreviewUrlResolverInterface;

#[Route(
    '/contao/live-preview/resolve',
    name: 'contao_live_preview_resolve',
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['GET'],
)]
#[IsGranted('ROLE_USER')]
class PreviewResolverController extends AbstractController
{
    public function __construct(
        private readonly PreviewUrlResolverInterface $resolver,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->framework->initialize();

        // Release session lock immediately so concurrent Turbo navigation requests
        // are not blocked waiting for this AJAX call to finish.
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $table = $request->query->getString('table');
        $id    = $request->query->getInt('id');

        $do = $request->query->getString('do');
        if ('' === $table && '' !== $do) {
            $table = $this->tableFromDo($do);
        }

        if ('' === $table || $id <= 0) {
            return $this->json(['error' => 'Missing table or id'], 400);
        }

        $pageData = $this->resolver->resolve($table, $id);

        if (null === $pageData) {
            return $this->json(['error' => 'Page not found'], 404);
        }

        $previewUrl = $this->buildPreviewUrl($pageData['pageId']);

        $articleId        = $pageData['articleId'] ?? null;
        $articleAlias     = (string) ($pageData['articleAlias'] ?? '');
        $articleCssId     = (string) ($pageData['articleCssId'] ?? '');
        $contentElementId    = $pageData['contentElementId']   ?? null;
        $contentElementType  = (string) ($pageData['contentElementType'] ?? '');
        $contentElementLabel = '' !== $contentElementType
            ? $this->resolveContentElementLabel($contentElementType)
            : '';

        // Article-level selectors — used for DOM swap (clp:refresh) and as secondary
        // highlight target (outline + badge). Ordered by specificity.
        $articleSelectors = [];
        if (\is_int($articleId) && $articleId > 0) {
            $articleSelectors[] = '[data-contao-table="tl_article"][data-contao-id="' . $articleId . '"]';
            $articleSelectors[] = '#article-' . $articleId;
        }
        if ('' !== $articleAlias && $articleAlias !== (string) $articleId) {
            $articleSelectors[] = '#article-' . $articleAlias;
        }
        if ('' !== $articleCssId) {
            $articleSelectors[] = '#' . $articleCssId;
        }

        // Primary highlight selectors — CE selector when context is tl_content,
        // otherwise same as articleSelectors. JS scrolls to the primary target.
        // When CE + article differ, the frontend highlights both simultaneously:
        // CE gets the light-blue background, article gets the outline + badge.
        $highlightSelectors = \is_int($contentElementId) && $contentElementId > 0
            ? ['[data-contao-table="tl_content"][data-contao-id="' . $contentElementId . '"]']
            : $articleSelectors;

        return $this->json([
            'pageId'             => $pageData['pageId'],
            'pageAlias'          => $pageData['alias'],
            'articleId'          => $articleId,
            'articleTitle'       => (string) ($pageData['articleTitle'] ?? ''),
            'contentElementId'    => $contentElementId,
            'contentElementType'  => $contentElementType,
            'contentElementLabel' => $contentElementLabel,
            'previewUrl'          => $previewUrl,
            'highlightSelectors' => $highlightSelectors,
            'articleSelectors'   => $articleSelectors,
        ]);
    }

    private function tableFromDo(string $do): string
    {
        return match ($do) {
            'page'    => 'tl_page',
            'article' => 'tl_article',
            default   => '',
        };
    }

    /**
     * Uses Contao's PageModel to build the correct absolute frontend URL.
     * findWithDetails() walks up the page hierarchy to inherit language, urlPrefix,
     * domain etc. — avoiding the need to manually traverse tl_page to the root.
     */
    private function resolveContentElementLabel(string $type): string
    {
        /** @var System $system */
        $system = $this->framework->getAdapter(System::class);
        $system->loadLanguageFile('modules');

        // Fall back to '' rather than the raw type key — unknown types are handled
        // gracefully in JS (getCeLabel reads data-contao-label from the DOM instead).
        return $this->cleanLabel((string) ($GLOBALS['TL_LANG']['CTE'][$type][0] ?? ''));
    }

    /**
     * Strips common Contao element suffix words that add no value in a badge.
     * Handles colon-separated ("SimpleGrid: Wrapper Start") and space-separated
     * ("Akkordeon Anfang") forms.
     */
    private function cleanLabel(string $label): string
    {
        $label = (string) preg_replace(
            '/\s*:?\s*\b(?:Wrapper\s+)?(?:Anfang|Start|Ende|End)\b\s*$|:?\s*\bWrapper\b\s*$/i',
            '',
            $label,
        );

        return trim((string) preg_replace('/\s{2,}/', ' ', $label));
    }

    private function buildPreviewUrl(int $pageId): string
    {
        // framework already initialized in __invoke

        /** @var \Contao\Model\Registry $pageAdapter */
        $pageAdapter = $this->framework->getAdapter(PageModel::class);

        /** @var PageModel|null $page */
        $page = $pageAdapter->findWithDetails($pageId);

        if (null === $page) {
            return '';
        }

        // Non-routable page types (error_404, error_403, folder, root) cannot be
        // opened directly. Walk up to the nearest routable ancestor instead.
        $routableTypes = ['regular', 'redirect', 'forward'];
        $candidate = $page;

        while (null !== $candidate && !\in_array($candidate->type, $routableTypes, true)) {
            $candidate = $candidate->pid > 0
                ? $pageAdapter->findWithDetails((int) $candidate->pid)
                : null;
        }

        if (null === $candidate) {
            return '';
        }

        try {
            return $candidate->getAbsoluteUrl();
        } catch (\Throwable) {
            return '';
        }
    }
}
