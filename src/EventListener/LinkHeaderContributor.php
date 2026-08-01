<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusApiCatalog\EventListener;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Provider\ResponseHeaderContributorInterface;
use Koh\SyliusAgentContracts\Registry\ApiCatalogRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Advertises the catalog through Link headers on HTML pages.
 *
 * RFC 9727 §3 names this as the discovery route for the catalog, which is why
 * it lives in the same package as the document: an agent that lands on any page
 * finds the machine-readable surface without knowing to look for
 * /.well-known/api-catalog.
 *
 * The header line is cached per channel and locale. Walking every provider on
 * every page view would put the cost of a discovery feature onto ordinary
 * shoppers.
 */
final class LinkHeaderContributor implements ResponseHeaderContributorInterface
{
    public function __construct(
        private readonly ApiCatalogRegistry $registry,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl = 3600,
        private readonly bool $enabled = true,
    ) {
    }

    public function supports(Request $request, Response $response): bool
    {
        return $this->enabled
            && !$response->isRedirection()
            && str_contains($response->headers->get('Content-Type', 'text/html'), 'text/html');
    }

    public function contribute(Request $request, Response $response, AgentContext $context): void
    {
        $header = $this->header($context);
        if ('' === $header) {
            return;
        }

        // Append. Sylius sets rel="preload" and API Platform sets the Hydra
        // documentation link on the same response; replacing breaks both.
        $response->headers->set('Link', $header, false);
    }

    private function header(AgentContext $context): string
    {
        $item = $this->cache->getItem($context->cacheKey('koh_api_catalog.link'));
        if ($item->isHit()) {
            $cached = $item->get();
            if (\is_string($cached)) {
                return $cached;
            }
        }

        $links = [\sprintf(
            '<%s>; rel="api-catalog"; type="%s"',
            $context->url('/.well-known/api-catalog'),
            \Koh\SyliusApiCatalog\Controller\ApiCatalogController::CONTENT_TYPE,
        )];

        foreach ($this->registry->all($context) as $entry) {
            foreach (['service-desc' => $entry->serviceDesc, 'service-doc' => $entry->serviceDoc, 'describedby' => $entry->describedBy] as $rel => $targets) {
                foreach ($targets as $target) {
                    $links[] = null === $target->type
                        ? \sprintf('<%s>; rel="%s"', $target->href, $rel)
                        : \sprintf('<%s>; rel="%s"; type="%s"', $target->href, $rel, $target->type);
                }
            }
        }

        $header = implode(', ', array_unique($links));

        $item->set($header)->expiresAfter($this->ttl);
        $this->cache->save($item);

        return $header;
    }
}
