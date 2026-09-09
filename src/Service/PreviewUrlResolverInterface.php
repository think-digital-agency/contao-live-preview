<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('contao_live_preview.preview_url_resolver')]
interface PreviewUrlResolverInterface
{
    /**
     * Resolves a backend table + record ID to the frontend page data needed
     * to build a preview URL.
     *
     * Every service implementing this interface automatically joins the
     * resolver chain (tag `contao_live_preview.preview_url_resolver`). The
     * chain calls each resolver in priority order (set it with
     * `#[AsTaggedItem(priority: N)]`, higher first) and takes the first
     * non-null result; the bundle's own resolver runs last. Return `null` for
     * any table your bundle does not own. Example: a news bundle resolving
     * tl_news → tl_news_archive → tl_page.
     *
     * At minimum return `pageId` + `alias`; the extra keys enrich the
     * highlight (all optional, the controller defaults them):
     *   - articleId, articleAlias, articleTitle, articleCssId
     *   - contentElementId, contentElementType
     *
     * @return array{pageId: int, alias: string, articleId?: int|null, articleAlias?: string, articleTitle?: string, articleCssId?: string, contentElementId?: int|null, contentElementType?: string|null}|null
     */
    public function resolve(string $table, int $id): ?array;

    /**
     * Resolves the first routable page of the first published root tree.
     * Used as fallback preview when no specific backend context is active.
     *
     * Only the bundle's own resolver needs a real implementation here — the
     * chain always delegates this call to it. A table-specific third-party
     * resolver may simply `return null`.
     *
     * @return array{pageId: int, alias: string}|null
     */
    public function resolveRootPage(): ?array;
}
