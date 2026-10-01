<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts the plain `clp-news-{id}` marker class (appended pre-render by
 * InjectNewsMarkersListener) into real data-contao-table / data-contao-id /
 * data-contao-label attributes on the same wrapper tag, and strips the
 * marker class again.
 *
 * Runs post-render (after Twig has produced the final HTML) because
 * news-bundle's templates concatenate the `class` variable into an
 * already-open class="..." attribute — see InjectNewsMarkersListener for
 * why the attributes can't be written directly from the parseArticles hook.
 * No DB lookup or position matching is needed here: the marker class already
 * carries the news ID.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -196)]
class InjectNewsResponseMarkersListener
{
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

        if (false === $content || !str_contains($content, 'clp-news-')) {
            return;
        }

        $pattern = '/<[a-z][a-z0-9]*\b[^>]*\bclass="[^"]*\bclp-news-(\d+)\b[^"]*"[^>]*>/i';

        if (!preg_match_all($pattern, $content, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return;
        }

        $label = (string) ($GLOBALS['TL_LANG']['CLP']['news'] ?? 'News');
        $injections = [];

        foreach ($matches as $m) {
            $fullTag = $m[0][0];
            $offset  = $m[0][1];
            $newsId  = $m[1][0];

            $withoutMarker = preg_replace('/\s*\bclp-news-' . $newsId . '\b/', '', $fullTag, 1) ?? $fullTag;

            $attrString = ' data-contao-table="tl_news"'
                . ' data-contao-id="' . $newsId . '"'
                . ' data-contao-label="' . htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';

            $newTag = preg_replace('/(<[a-z][a-z0-9]*\b)/i', '$1' . $attrString, $withoutMarker, 1) ?? $withoutMarker;

            $injections[$offset] = [$offset, \strlen($fullTag), $newTag];
        }

        // Apply from end to start to keep byte offsets valid.
        krsort($injections);
        foreach ($injections as [$offset, $length, $newTag]) {
            $content = substr_replace($content, $newTag, $offset, $length);
        }

        $response->setContent($content);
    }
}
