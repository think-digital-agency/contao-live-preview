<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\NewsModel;
use Contao\PageModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use ThinkDigital\ContaoLivePreview\Service\LabelCleanerTrait;
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolverInterface;
use ThinkDigital\ContaoLivePreview\Service\ResolvePreviewEvent;

// Route is defined in config/routes.yaml and loaded via ContaoManager\Plugin (RoutingPluginInterface).
// The #[Route] attribute is intentionally absent — Symfony does not auto-scan bundle controllers.
#[IsGranted('ROLE_USER')]
class PreviewResolverController extends AbstractController
{
    use LabelCleanerTrait;

    public function __construct(
        private readonly PreviewUrlResolverInterface $resolver,
        private readonly ContaoFramework $framework,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ContentUrlGenerator $urlGenerator,
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
            $pageData = $this->resolver->resolveRootPage();
            if (null === $pageData) {
                return $this->json(['error' => 'No root page found'], 404);
            }
            $previewUrl = $this->buildPreviewUrl($pageData['pageId']);

            return $this->json([
                'pageId'              => $pageData['pageId'],
                'pageAlias'           => $pageData['alias'],
                'articleId'           => null,
                'articleTitle'        => '',
                'contentElementId'    => null,
                'contentElementType'  => '',
                'contentElementLabel' => '',
                'previewUrl'          => $previewUrl,
                'highlightSelectors'  => [],
                'articleSelectors'    => [],
            ]);
        }

        // clp:resolve event — escape hatch for dynamic resolution that depends
        // on request state. A listener that calls setPageData() takes
        // precedence over the resolver chain. See docs/EXTENDING.md.
        $event = new ResolvePreviewEvent($table, $id);
        $this->eventDispatcher->dispatch($event, ResolvePreviewEvent::NAME);

        $pageData = $event->isResolved() ? $event->getPageData() : $this->resolver->resolve($table, $id);

        if (null === $pageData) {
            return $this->json(['error' => 'Page not found'], 404);
        }

        $articleId        = $pageData['articleId'] ?? null;
        $articleAlias     = (string) ($pageData['articleAlias'] ?? '');
        $articleCssId     = (string) ($pageData['articleCssId'] ?? '');
        $contentElementId    = $pageData['contentElementId']   ?? null;
        $contentElementType  = (string) ($pageData['contentElementType'] ?? '');
        $contentElementLabel = '' !== $contentElementType
            ? $this->resolveLabel($contentElementType, $this->translator)
            : '';
        $newsId    = $pageData['newsId']  ?? null;
        $newsAlias  = (string) ($pageData['newsAlias']  ?? '');
        $newsTitle  = (string) ($pageData['newsTitle']  ?? '');

        // A news record's own reader page (buildPreviewUrl($pageData['pageId'])) is
        // the archive's bare jumpTo target, e.g. /news-blog/details.html — Contao's
        // news reader only shows an actual article when the item's alias/auto_item
        // is part of the URL. Without it the iframe shows an empty reader and every
        // tl_news highlightSelector below matches nothing (#20 never actually worked
        // end-to-end because of this, independently of the marker-injection fix).
        // buildNewsPreviewUrl() asks Contao's own routing (NewsResolver, the same
        // mechanism {{news_url::42}} uses) for the real item URL; falls back to the
        // bare reader page if that fails (e.g. the archive's reader page is gone).
        $previewUrl = (\is_int($newsId) && $newsId > 0)
            ? ($this->buildNewsPreviewUrl($newsId) ?: $this->buildPreviewUrl($pageData['pageId']))
            : $this->buildPreviewUrl($pageData['pageId']);

        // When a content element belongs to a news record (ptable='tl_news'),
        // the edit URL must use do=news instead of do=article.
        $contentElementParentTable = '';
        if (\is_int($contentElementId) && $contentElementId > 0 && \is_int($newsId) && $newsId > 0 && null === $articleId) {
            $contentElementParentTable = 'tl_news';
        }

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
        // news selector when context is tl_news, otherwise same as articleSelectors.
        // JS scrolls to the primary target. When CE + article differ, the frontend
        // highlights both simultaneously: CE gets the solid blue outline, article
        // gets dashed blue + badge.
        if (\is_int($contentElementId) && $contentElementId > 0) {
            $highlightSelectors = ['[data-contao-table="tl_content"][data-contao-id="' . $contentElementId . '"]'];
        } elseif (\is_int($newsId) && $newsId > 0) {
            $highlightSelectors = ['[data-contao-table="tl_news"][data-contao-id="' . $newsId . '"]'];
        } else {
            $highlightSelectors = $articleSelectors;
        }

        return $this->json([
            'pageId'             => $pageData['pageId'],
            'pageAlias'          => $pageData['alias'],
            'articleId'          => $articleId,
            'articleTitle'       => (string) ($pageData['articleTitle'] ?? ''),
            'contentElementId'    => $contentElementId,
            'contentElementType'  => $contentElementType,
            'contentElementLabel' => $contentElementLabel,
            'newsId'             => $newsId,
            'newsAlias'          => $newsAlias,
            'newsTitle'          => $newsTitle,
            'contentElementParentTable' => $contentElementParentTable,
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
            'news'    => 'tl_news',
            default   => '',
        };
    }

    private function buildPreviewUrl(int $pageId): string
    {
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

    /**
     * The news item's own frontend URL (reader page + its alias/auto_item, url
     * suffix, …) via Contao's routing — the same resolver chain {{news_url::}}
     * and the sitemap use — rather than hand-building the path. '' on any
     * failure (no such news record, archive's jumpTo page not routable, …) so
     * the caller can fall back to the plain reader-page URL.
     */
    private function buildNewsPreviewUrl(int $newsId): string
    {
        /** @var \Contao\Model\Registry $newsAdapter */
        $newsAdapter = $this->framework->getAdapter(NewsModel::class);

        /** @var NewsModel|null $news */
        $news = $newsAdapter->findById($newsId);

        if (null === $news) {
            return '';
        }

        try {
            return $this->urlGenerator->generate($news, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return '';
        }
    }
}
