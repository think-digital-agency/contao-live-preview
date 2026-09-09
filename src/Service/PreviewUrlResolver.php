<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Service;

use Contao\Controller;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;

class PreviewUrlResolver implements PreviewUrlResolverInterface
{
    /** @var list<string>|null lazily loaded schema table list for the injection guard */
    private ?array $knownTables = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
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
            default      => $this->resolveFromChildTable($table, $id, 0),
        };
    }

    /**
     * Generic fallback for custom child tables (e.g. a bundle's own DCA nested
     * under an article, a page or a content element). Walks the DCA parent chain
     * — `config.ptable` for static parents, the record's own `ptable` column when
     * `config.dynamicPtable` is set — until it reaches tl_content / tl_article /
     * tl_page, then delegates to the matching resolver above.
     *
     * Only tables that actually declare a parent relationship in their DCA
     * (`config.ptable` or `config.dynamicPtable`) and carry a real `pid` column
     * are followed — so a root table reached mid-walk (tl_theme via tl_module,
     * …) or a table with no parent (tl_news) ends the walk with null rather
     * than a SQL error.
     *
     * Returns null (→ controller shows the root page, never a wrong record) when
     * the table is unknown, declares no parent, or the chain is broken. A
     * third-party bundle with a non-standard storage model can register a tagged
     * PreviewUrlResolverInterface service (ADR-021) or override the alias.
     */
    private function resolveFromChildTable(string $table, int $id, int $depth): ?array
    {
        if ($depth > 10 || $id <= 0 || !$this->isKnownTable($table)) {
            return null;
        }

        $this->framework->initialize();

        /** @var Adapter<Controller> $controller */
        $controller = $this->framework->getAdapter(Controller::class);
        $controller->loadDataContainer($table);

        $config        = $GLOBALS['TL_DCA'][$table]['config'] ?? [];
        $dynamicPtable = (bool) ($config['dynamicPtable'] ?? false);
        $staticPtable  = (string) ($config['ptable'] ?? '');

        // Not a DCA child table (no parent declared) → end of the line.
        if (!$dynamicPtable && '' === $staticPtable) {
            return null;
        }

        $tableColumns = $this->columnNames($table);
        $needed       = $dynamicPtable ? ['pid', 'ptable'] : ['pid'];

        foreach ($needed as $col) {
            if (!\in_array($col, $tableColumns, true)) {
                return null;
            }
        }

        $columns = implode(', ', $needed);

        try {
            $row = $this->connection->fetchAssociative(
                "SELECT {$columns} FROM {$table} WHERE id = ?",
                [$id],
            );
        } catch (\Throwable) {
            return null;
        }

        if (!$row || (int) $row['pid'] <= 0) {
            return null;
        }

        $parentTable = $dynamicPtable ? (string) ($row['ptable'] ?: '') : $staticPtable;
        $parentId    = (int) $row['pid'];

        return match (true) {
            'tl_content' === $parentTable => $this->resolveFromContent($parentId),
            'tl_article' === $parentTable => $this->resolveFromArticle($parentId),
            'tl_page'    === $parentTable => $this->resolveFromPage($parentId),
            '' !== $parentTable           => $this->resolveFromChildTable($parentTable, $parentId, $depth + 1),
            default                       => null,
        };
    }

    private function isKnownTable(string $table): bool
    {
        if (!preg_match('/^tl_[a-z0-9_]+$/', $table)) {
            return false;
        }

        $this->knownTables ??= $this->connection->createSchemaManager()->listTableNames();

        return \in_array($table, $this->knownTables, true);
    }

    /**
     * @return list<string>
     */
    private function columnNames(string $table): array
    {
        try {
            return array_keys($this->connection->createSchemaManager()->listTableColumns($table));
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveFromContent(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pid, ptable, type FROM tl_content WHERE id = ?',
            [$id],
        );

        if (!$row) {
            return null;
        }

        // Walk up through nested element groups (ptable='tl_content') until we
        // reach the owning article. Safety cap of 10 prevents infinite loops.
        $effectivePid = (int) $row['pid'];
        $ptable       = (string) ($row['ptable'] ?: 'tl_article');
        $depth        = 0;

        while ('tl_content' === $ptable && $depth++ < 10) {
            $parent = $this->connection->fetchAssociative(
                'SELECT pid, ptable FROM tl_content WHERE id = ?',
                [$effectivePid],
            );

            if (!$parent) {
                return null;
            }

            $effectivePid = (int) $parent['pid'];
            $ptable       = (string) ($parent['ptable'] ?: 'tl_article');
        }

        if ('tl_article' !== $ptable) {
            return null;
        }

        $result = $this->resolveFromArticle($effectivePid);

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

    public function resolveRootPage(): ?array
    {
        // First published root page (ordered by sorting so multi-language setups
        // consistently pick the primary language tree).
        $root = $this->connection->fetchAssociative(
            "SELECT id FROM tl_page WHERE type = 'root' AND published = '1' ORDER BY sorting ASC LIMIT 1",
        );

        if (!$root) {
            return null;
        }

        // First published regular page that is a direct child of that root.
        $child = $this->connection->fetchAssociative(
            "SELECT id FROM tl_page WHERE pid = ? AND type = 'regular' AND published = '1' ORDER BY sorting ASC LIMIT 1",
            [(int) $root['id']],
        );

        if (!$child) {
            return null;
        }

        return $this->resolveFromPage((int) $child['id']);
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
