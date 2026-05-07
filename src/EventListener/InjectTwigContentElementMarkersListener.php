<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\CoreBundle\Framework\ContaoFramework;
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
 * hook entirely — InjectContentElementMarkersListener cannot reach them. This
 * listener runs after the full page is rendered (KernelEvents::RESPONSE) and
 * annotates CE wrappers by matching Contao's standard ce_{type} CSS class against
 * DBAL records for the current page, ordered by article + content sorting.
 *
 * Matching strategy:
 *   1. cssId set on CE → exact match via id="cssId" attribute (reliable)
 *   2. No cssId → Nth occurrence of ce_{type} class in the HTML matches the Nth
 *      CE of that type in DB order (type+position matching)
 *
 * Known limitations (documented in CLAUDE.md):
 *   - Multi-column layouts where side-column HTML precedes main-column HTML may
 *     misalign the type+position matching. cssId-matched CEs are always correct.
 *   - Nested CEs (e.g. accordion content) appear as additional occurrences of
 *     ce_{type} and shift the position counter. Inject guards (already-marked
 *     check) prevent double-injection but may misalign remaining counters.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -195)]
class InjectTwigContentElementMarkersListener
{
    use LabelCleanerTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
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
        $rawRows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.type, c.cssID
             FROM tl_content c
             INNER JOIN tl_article a ON a.id = c.pid
             WHERE a.pid = :pageId AND c.invisible != :one AND a.published = :one
             ORDER BY a.sorting ASC, c.sorting ASC',
            ['pageId' => $pageId, 'one' => '1'],
        );

        $result = [];
        foreach ($rawRows as $row) {
            $cssIdData = @unserialize((string) ($row['cssID'] ?? ''));
            $result[] = [
                'id'    => (int) $row['id'],
                'type'  => (string) $row['type'],
                'cssId' => \is_array($cssIdData) && '' !== ($cssIdData[0] ?? '') ? (string) $cssIdData[0] : '',
            ];
        }

        return $result;
    }

    /**
     * @param list<array{id: int, type: string, cssId: string}> $rows
     */
    private function annotate(string $content, array $rows): string
    {
        // --- Pass 1: find all CE wrapper opening-tags in the HTML ---
        // Matches any opening tag that has a class attribute containing ce_{type}.
        // The full match includes from < to > (inclusive) so we can check for
        // data-contao-table= within the same tag and get the byte offset.
        $pattern = '/(<[a-z][a-z0-9]*\b[^>]*\bclass="[^"]*\bce_[a-z_][a-z0-9_]*\b[^"]*"[^>]*>)/i';
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

            if (!preg_match('/\bce_([a-z_][a-z0-9_]*)\b/i', $fullTag, $typeMatch)) {
                continue;
            }

            $type = $typeMatch[1];
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

            $label = $this->resolveLabel($type, $this->framework);
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
