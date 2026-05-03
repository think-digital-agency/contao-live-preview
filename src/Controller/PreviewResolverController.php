<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
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

        // Also support resolving by `do` parameter (page, article)
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

        $previewUrl = $this->buildPreviewUrl($pageData, $request);

        return $this->json([
            'pageId'     => $pageData['pageId'],
            'pageAlias'  => $pageData['alias'],
            'language'   => $pageData['language'],
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
     * Builds the iframe preview URL.
     *
     * Uses /preview.php if available (Contao standard entry point for preview mode),
     * otherwise falls back to constructing the URL from alias + language prefix.
     * The backend user is already authenticated, so the preview.php route with a
     * valid Contao backend session will render unpublished content.
     *
     * @param array{pageId: int, alias: string, language: string, dns: string} $pageData
     */
    private function buildPreviewUrl(array $pageData, Request $request): string
    {
        $baseUrl = $request->getSchemeAndHttpHost();

        // Contao standard: /preview.php serves the frontend with preview authentication.
        // We pass the page alias so the frontend router can resolve it.
        $language = $pageData['language'];
        $alias    = $pageData['alias'];

        // Prefer the Contao preview entry point which inherits the backend session
        // (same origin, both served from the same Symfony app).
        if ('' !== $language) {
            return $baseUrl.'/preview.php/'.$language.'/'.$alias.'.html';
        }

        return $baseUrl.'/preview.php/'.$alias.'.html';
    }
}
