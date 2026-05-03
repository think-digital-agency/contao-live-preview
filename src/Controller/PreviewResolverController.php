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
use Vendor\ContaoLivePreviewBundle\Service\PreviewUrlResolver;

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
        private readonly PreviewUrlResolver $resolver,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
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

        return $this->json([
            'pageId'     => $pageData['pageId'],
            'pageAlias'  => $pageData['alias'],
            'previewUrl' => $previewUrl,
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

        return $page->getAbsoluteUrl();
    }
}
