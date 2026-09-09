<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Service;

/**
 * Runs every registered {@see PreviewUrlResolverInterface} in priority order
 * (tag `contao_live_preview.preview_url_resolver`) and returns the first
 * non-null result. The bundle's own {@see PreviewUrlResolver} is pinned to the
 * lowest priority, so a third-party bundle can both add new tables and override
 * the default resolution for an existing one — without fighting over the
 * service alias.
 *
 * A third-party resolver only needs to implement `resolve()` and return `null`
 * for tables it does not own; `resolveRootPage()` may just return `null` (the
 * chain always delegates it to the core resolver).
 */
final class ChainPreviewUrlResolver implements PreviewUrlResolverInterface
{
    /**
     * @param iterable<PreviewUrlResolverInterface> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers,
        private readonly PreviewUrlResolver $core,
    ) {
    }

    public function resolve(string $table, int $id): ?array
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver === $this) {
                continue;
            }

            $result = $resolver->resolve($table, $id);

            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    public function resolveRootPage(): ?array
    {
        return $this->core->resolveRootPage();
    }
}
