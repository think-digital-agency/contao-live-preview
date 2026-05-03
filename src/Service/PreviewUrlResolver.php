<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\Service;

use Doctrine\DBAL\Connection;

class PreviewUrlResolver
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
            'SELECT pid FROM tl_content WHERE id = ?',
            [$id],
        );

        if (!$row) {
            return null;
        }

        return $this->resolveFromArticle((int) $row['pid']);
    }

    private function resolveFromArticle(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pid FROM tl_article WHERE id = ?',
            [$id],
        );

        if (!$row) {
            return null;
        }

        return $this->resolveFromPage((int) $row['pid']);
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
            'pageId'   => (int) $row['id'],
            'alias'    => (string) $row['alias'],
            'language' => (string) $row['language'],
            'dns'      => (string) $row['dns'],
        ];
    }
}
