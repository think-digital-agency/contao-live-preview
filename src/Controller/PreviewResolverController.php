<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
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

        $articleId    = $pageData['articleId'] ?? null;
        $articleAlias = (string) ($pageData['articleAlias'] ?? '');
        $articleCssId = (string) ($pageData['articleCssId'] ?? '');

        // Ordered selector chain: JS tries each in sequence, uses the first match.
        // 1. [data-contao-table="tl_article"][data-contao-id="{id}"] — primary, stable, no guessing
        // 2. #article-{numericId}  — Contao's default CSS ID fallback
        // 3. #article-{alias}      — alias-based CSS ID fallback
        // 4. #{cssId}              — manually set CSS ID in the backend
        $selectors = [];
        if (\is_int($articleId) && $articleId > 0) {
            $selectors[] = '[data-contao-table="tl_article"][data-contao-id="' . $articleId . '"]';
            $selectors[] = '#article-' . $articleId;
        }
        if ('' !== $articleAlias && $articleAlias !== (string) $articleId) {
            $selectors[] = '#article-' . $articleAlias;
        }
        if ('' !== $articleCssId) {
            $selectors[] = '#' . $articleCssId;
        }

        return $this->json([
            'pageId'             => $pageData['pageId'],
            'pageAlias'          => $pageData['alias'],
            'articleId'          => $articleId,
            'previewUrl'         => $previewUrl,
            'highlightSelectors' => $selectors,
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
    private function buildPreviewUrl(int $pageId): string
    {
        $this->framework->initialize();

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
