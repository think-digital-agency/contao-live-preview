<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use ThinkDigital\ContaoLivePreview\Service\LabelCleanerTrait;

/**
 * Injects data-contao-table="tl_content", data-contao-id="{N}", and
 * data-contao-label="{Human label}" into Twig-first content element wrappers.
 *
 * Twig-first CEs registered via #[AsContentElement] bypass the getContentElement
 * hook entirely — InjectContentElementMarkersListener cannot reach them. Also,
 * Container CEs (Card, Accordion, Elementgruppe, …) that wrap nested fragments
 * are not annotated by InjectContentElementMarkersListener because the hook
 * fires for the parent CE after its children have already been rendered, so the
 * parent buffer already contains data-contao-table= markers from the children
 * and the hook skips it (str_contains check). This listener runs after the full
 * page is rendered (KernelEvents::RESPONSE) and annotates such CE wrappers by
 * matching Contao's CSS class conventions against DBAL records for the current
 * page, ordered by article + content sorting.
 *
 * Matching strategy:
 *   1. cssId set on CE → exact match via id="cssId" attribute (reliable)
 *   2. No cssId → Nth occurrence of the CE type class in the HTML matches the Nth
 *      CE of that type in DB order (type+position matching). Both legacy
 *      ce_{type} (underscores) and modern content-{type} (hyphens) class
 *      conventions are matched.
 *
 * Nested content elements (those within containers using nestedFragments such as
 * Card, Accordion, Tabs, or element_group) are now loaded recursively and included
 * in the position-based matching. Both the parent and its children are flattened in
 * depth-first-search order (parent before children, children sorted by sorting
 * field), matching the HTML rendering order produced by
 * {% for fragment in nested_fragments %}{{ content_element(fragment) }}{% endfor %}.
 *
 * Known limitation:
 *   - Multi-column layouts where side-column HTML precedes main-column HTML may
 *     misalign the type+position matching. cssId-matched CEs are always correct.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -195)]
class InjectTwigContentElementMarkersListener
{
    use LabelCleanerTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if ('backend' === $request->attributes->get('_scope')) {
            return;
        }

        if (!$request->query->getBoolean('_clp')) {
            return;
        }

        $response = $event->getResponse();

        if (!str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }

        $content = $response->getContent();

        if (false === $content || !str_contains($content, '</body>')) {
            return;
        }

        $this->framework->initialize();

        // $GLOBALS['objPage'] is set by Contao during frontend rendering.
        $pageModel = $GLOBALS['objPage'] ?? null;
        if (null === $pageModel || !isset($pageModel->id)) {
            return;
        }

        $pageId = (int) $pageModel->id;
        if ($pageId <= 0) {
            return;
        }

        $rows = $this->loadContentElements($pageId);
        if ([] === $rows) {
            return;
        }

        $modified = $this->annotate($content, $rows);

        if ($modified !== $content) {
            $response->setContent($modified);
        }
    }

    /**
     * @return list<array{id: int, type: string, cssId: string}>
     */
    private function loadContentElements(int $pageId): array
    {
        // Load top-level elements (direct children of articles on this page).
        $rawRows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.type, c.cssID
             FROM tl_content c
             INNER JOIN tl_article a ON a.id = c.pid
             WHERE a.pid = :pageId AND c.invisible != :one AND a.published = :one
             ORDER BY a.sorting ASC, c.sorting ASC',
            ['pageId' => $pageId, 'one' => '1'],
        );

        if ([] === $rawRows) {
            return [];
        }

        $topLevelIds = array_map(fn(array $row): int => (int) $row['id'], $rawRows);

        // Build a parent → children map for all nesting levels.
        // Nested content elements (inside a Card, Accordion, etc.) have
        // ptable = 'tl_content' and are NOT fetched by the top-level query.
        // We load them iteratively (up to 10 levels deep) and record their
        // parent-child relationship so we can later flatten them in the
        // same DFS order as the HTML rendering.
        $parentChildrenMap = [];
        $currentParentIds = $topLevelIds;
        $maxDepth = 10;

        while ($maxDepth-- > 0) {
            $nested = $this->connection->fetchAllAssociative(
                'SELECT id, type, cssID, pid
                 FROM tl_content
                 WHERE pid IN (?) AND ptable = \'tl_content\' AND invisible != 1
                 ORDER BY sorting ASC',
                [$currentParentIds],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER],
            );

            if ([] === $nested) {
                break;
            }

            $currentParentIds = [];
            foreach ($nested as $row) {
                $pid = (int) $row['pid'];
                $parentChildrenMap[$pid][] = [
                    'id'    => (int) $row['id'],
                    'type'  => (string) $row['type'],
                    'cssID' => (string) ($row['cssID'] ?? ''),
                ];
                $currentParentIds[] = (int) $row['id'];
            }
        }

        // Flatten in DFS order (parent, then children by sorting).
        // This matches the actual HTML rendering order produced by
        // {% for fragment in nested_fragments %}{{ content_element(fragment) }}{% endfor %}.
        $result = [];
        foreach ($rawRows as $row) {
            $this->collectElement($row, $parentChildrenMap, $result);
        }

        return $result;
    }

    private function collectElement(array $row, array &$parentChildrenMap, array &$result): void
    {
        $id = (int) $row['id'];
        $cssIdData = @unserialize((string) ($row['cssID'] ?? ''));
        $result[] = [
            'id'    => $id,
            'type'  => (string) $row['type'],
            'cssId' => \is_array($cssIdData) && '' !== ($cssIdData[0] ?? '') ? (string) $cssIdData[0] : '',
        ];

        if (isset($parentChildrenMap[$id])) {
            foreach ($parentChildrenMap[$id] as $childRow) {
                $this->collectElement($childRow, $parentChildrenMap, $result);
            }
        }
    }

    /**
     * @param list<array{id: int, type: string, cssId: string}> $rows
     */
    private function annotate(string $content, array $rows): string
    {
        // --- Pass 1: find all CE wrapper opening-tags in the HTML ---
        // Matches any opening tag that has a class attribute containing either
        // the legacy ce_{type} (underscores) or the modern content-{type}
        // (hyphens) class convention used by Contao 5.x Twig CEs.
        // The full match includes from < to > (inclusive) so we can check for
        // data-contao-table= within the same tag and get the byte offset.
        $pattern = '/(<[a-z][a-z0-9]*\b[^>]*\bclass="[^"]*\b(?:ce_|content-)[a-z][a-z0-9_-]*\b[^"]*"[^>]*>)/i';
        if (!preg_match_all($pattern, $content, $tagMatches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        // Group by extracted ce_type, preserving order. Skip already-marked tags.
        // typeOccurrences: type => [{offset, length, fullTag}, ...]
        $typeOccurrences = [];
        foreach ($tagMatches as $m) {
            $fullTag = $m[1][0];
            $offset  = $m[1][1];

            // Skip if this opening tag is already annotated (legacy hook or theme).
            if (str_contains($fullTag, 'data-contao-table=')) {
                continue;
            }

            // Extract the type from either ce_{type} or content-{type} class.
            if (!preg_match('/\b(?:ce_|content-)([a-z][a-z0-9_]*)\b/i', $fullTag, $typeMatch)) {
                continue;
            }

            // Convert type extracted from CSS selector (kebab-case) to match DBAL record (snake-case).
            $type = str_replace('-', '_', $typeMatch[1]);
            $typeOccurrences[$type][] = [
                'offset'  => $offset,
                'length'  => \strlen($fullTag),
                'fullTag' => $fullTag,
            ];
        }

        if ([] === $typeOccurrences) {
            return $content;
        }

        // --- Pass 2: match DB rows to HTML occurrences ---
        // Injections are keyed by offset so we can sort descending and apply without drift.
        $injections = []; // offset => [offset, length, newTag]
        $typeCounters = []; // type => next-index into typeOccurrences[type]

        foreach ($rows as $row) {
            $type  = $row['type'];
            $ceId  = $row['id'];
            $cssId = $row['cssId'];

            $label = $this->resolveLabel($type, $this->translator);
            $attrString = ' data-contao-table="tl_content"'
                . ' data-contao-id="' . $ceId . '"'
                . ' data-contao-label="' . htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';

            if ('' !== $cssId) {
                // Exact match via id="cssId" attribute — reliable regardless of column order.
                $exactPattern = '/(<[a-z][a-z0-9]*\b(?=[^>]*\bid="' . preg_quote($cssId, '/') . '")[^>]*>)/i';
                if (preg_match($exactPattern, $content, $em, \PREG_OFFSET_CAPTURE)) {
                    $tagFull = $em[1][0];
                    $tagOffset = $em[1][1];
                    if (!str_contains($tagFull, 'data-contao-table=')) {
                        $newTag = preg_replace('/(<[a-z][a-z0-9]*\b)/i', '$1' . $attrString, $tagFull, 1) ?? $tagFull;
                        $injections[$tagOffset] = [$tagOffset, \strlen($tagFull), $newTag];
                    }
                }
                continue;
            }

            // Position-based: Nth occurrence of ce_{type} in DOM order.
            if (!isset($typeOccurrences[$type])) {
                continue;
            }

            $idx = $typeCounters[$type] ?? 0;
            $typeCounters[$type] = $idx + 1;

            if (!isset($typeOccurrences[$type][$idx])) {
                continue;
            }

            $occ    = $typeOccurrences[$type][$idx];
            $newTag = preg_replace('/(<[a-z][a-z0-9]*\b)/i', '$1' . $attrString, $occ['fullTag'], 1) ?? $occ['fullTag'];
            $injections[$occ['offset']] = [$occ['offset'], $occ['length'], $newTag];
        }

        if ([] === $injections) {
            return $content;
        }

        // Apply replacements from end to start to keep byte offsets valid.
        krsort($injections);
        foreach ($injections as [$offset, $length, $newTag]) {
            $content = substr_replace($content, $newTag, $offset, $length);
        }

        return $content;
    }
}
