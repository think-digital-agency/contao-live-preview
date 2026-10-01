<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Service;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by PreviewResolverController before the resolver chain runs,
 * as an escape hatch for dynamic resolution that depends on request state a
 * registered resolver service can't know (e.g. the current request's query
 * params, the session, a specific CE type's custom logic).
 *
 * A listener resolves the context by calling setPageData(); the controller uses
 * the first set result and skips the resolver chain. If no listener sets a
 * result, the resolver chain is used as the fallback.
 *
 * This mirrors the CLP_BE.augment hook on the PHP side: a third party can
 * resolve a custom table without registering a PreviewUrlResolverInterface
 * service. See docs/EXTENDING.md.
 */
class ResolvePreviewEvent extends Event
{
    public const NAME = 'clp:resolve';

    private ?array $pageData = null;
    private bool $resolved = false;

    public function __construct(
        private readonly string $table,
        private readonly int $id,
    ) {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Has a listener already called setPageData() — with a result or with
     * null to say "I looked, this doesn't exist"? When true, the resolver
     * chain is skipped either way; a listener that wants a confirmed "not
     * found" instead of a silent fallback to the chain calls setPageData(null).
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * @param array{pageId: int, alias: string}|null $pageData
     */
    public function setPageData(?array $pageData): void
    {
        $this->pageData = $pageData;
        $this->resolved = true;
    }

    /**
     * @return array{pageId: int, alias: string}|null
     */
    public function getPageData(): ?array
    {
        return $this->pageData;
    }
}