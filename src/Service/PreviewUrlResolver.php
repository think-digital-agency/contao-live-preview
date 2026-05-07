<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\Service;

use Doctrine\DBAL\Connection;

class PreviewUrlResolver implements PreviewUrlResolverInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Resolves a backend table + record ID to a tl_page row.
     *
     * @return array{pageId: int, alias: string, language: string, dns: string}|null
     */
    public function resolve(string $table, int $id): ?array
    {
        return match ($table) {
            'tl_content' => $this->resolveFromContent($id),
            'tl_article' => $this->resolveFromArticle($id),
            'tl_page'    => $this->resolveFromPage($id),
            default      => null,
        };
    }

    private function resolveFromContent(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pid, type FROM tl_content WHERE id = ?',
            [$id],
        );

        if (!$row) {
            return null;
        }

        $result = $this->resolveFromArticle((int) $row['pid']);

        if (null !== $result) {
            $result['contentElementId']   = $id;
            $result['contentElementType'] = (string) ($row['type'] ?? '');
        }

        return $result;
    }

    private function resolveFromArticle(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pid, alias, cssID, title FROM tl_article WHERE id = ?',
            [$id],
        );

        if (!$row) {
            return null;
        }

        $result = $this->resolveFromPage((int) $row['pid']);

        if (null !== $result) {
            $result['articleId']    = $id;
            $result['articleAlias'] = (string) ($row['alias'] ?? '');
            $result['articleTitle'] = (string) ($row['title'] ?? '');

            // cssID is stored as a:2:{i:0;s:N:"id";i:1;s:N:"class";}
            $cssIdData = @unserialize((string) ($row['cssID'] ?? ''));
            $result['articleCssId'] = \is_array($cssIdData) && '' !== ($cssIdData[0] ?? '')
                ? (string) $cssIdData[0]
                : '';
        }

        return $result;
    }

    private function resolveFromPage(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, alias, language, dns FROM tl_page WHERE id = ?',
            [$id],
        );

        if (!$row) {
            return null;
        }

        return [
            'pageId'            => (int) $row['id'],
            'alias'             => (string) $row['alias'],
            'language'          => (string) $row['language'],
            'dns'               => (string) $row['dns'],
            'articleId'         => null, // overwritten by resolveFromArticle
            'articleAlias'      => '',   // overwritten by resolveFromArticle
            'articleCssId'      => '',   // overwritten by resolveFromArticle
            'contentElementId'   => null, // overwritten by resolveFromContent
            'contentElementType' => null, // overwritten by resolveFromContent
        ];
    }
}
