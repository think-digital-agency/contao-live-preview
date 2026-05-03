<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\Service;

interface PreviewUrlResolverInterface
{
    /**
     * Resolves a backend table + record ID to the frontend page data needed
     * to build a preview URL.
     *
     * Third-party bundles can register their own implementation as a Symfony
     * service and alias this interface to it. Example: a news bundle could
     * add support for tl_news → tl_news_archive → tl_page resolution.
     *
     * @return array{pageId: int, alias: string}|null
     */
    public function resolve(string $table, int $id): ?array;
}
