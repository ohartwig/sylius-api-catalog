<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusApiCatalog\Tests\EventListener;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\LinkTarget;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;
use Koh\SyliusAgentContracts\Registry\ApiCatalogRegistry;
use Koh\SyliusApiCatalog\EventListener\LinkHeaderContributor;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cached half of catalog discovery.
 *
 * Caching a per-channel document is where multi-channel shops break, and the
 * failure is silent: every response is well-formed, it just describes somebody
 * else's shop. The existing suite proved the header is assembled once; these
 * tests pin down that it is assembled once *per channel and locale*.
 */
final class LinkHeaderContributorTest extends TestCase
{
    /**
     * The classic multi-channel bug. A cache key without the channel lets the
     * first channel to be hit warm the entry and every other channel then
     * advertises its URLs -- a live shop pointing agents at a different shop's
     * API, with nothing in the logs to show for it.
     */
    public function testEachChannelGetsItsOwnHeaderFromASharedCache(): void
    {
        $cache = new ArrayAdapter();
        $contributor = new LinkHeaderContributor($this->registry(), $cache);

        $de = $this->html();
        $contributor->contribute(new Request(), $de, $this->context('WEB_DE', 'https://de.example.com'));

        $fr = $this->html();
        $contributor->contribute(new Request(), $fr, $this->context('WEB_FR', 'https://fr.example.com'));

        self::assertStringContainsString('https://de.example.com/.well-known/api-catalog', (string) $de->headers->get('Link'));
        self::assertStringNotContainsString('fr.example.com', (string) $de->headers->get('Link'));

        self::assertStringContainsString('https://fr.example.com/.well-known/api-catalog', (string) $fr->headers->get('Link'));
        self::assertStringNotContainsString('de.example.com', (string) $fr->headers->get('Link'));
    }

    /**
     * Locale is the second half of the key. Entries are resolved per locale --
     * a German documentation link is not the French one -- so a key that stops
     * at the channel serves whichever locale happened to warm it first.
     */
    public function testEachLocaleGetsItsOwnHeaderFromASharedCache(): void
    {
        $cache = new ArrayAdapter();
        $provider = new class implements ApiCatalogEntryProviderInterface {
            public function getApiCatalogEntries(AgentContext $context): iterable
            {
                yield new ApiCatalogEntry(
                    anchor: $context->url('/_mcp'),
                    serviceDoc: [new LinkTarget($context->url('/docs/'.$context->localeCode.'.md'), 'text/markdown')],
                );
            }
        };
        $contributor = new LinkHeaderContributor(new ApiCatalogRegistry([$provider]), $cache);

        $de = $this->html();
        $contributor->contribute(new Request(), $de, $this->context('WEB', 'https://shop.example.com', 'de_DE'));

        $fr = $this->html();
        $contributor->contribute(new Request(), $fr, $this->context('WEB', 'https://shop.example.com', 'fr_FR'));

        self::assertStringContainsString('/docs/de_DE.md', (string) $de->headers->get('Link'));
        self::assertStringContainsString('/docs/fr_FR.md', (string) $fr->headers->get('Link'));
    }

    /**
     * A target without a media type must emit a bare rel, not `type=""`.
     * An empty type parameter is not the same claim as making none, and a
     * client that honours it will refuse the document it names.
     */
    public function testATargetWithoutAMediaTypeEmitsABareRelation(): void
    {
        $provider = $this->providerYielding(new ApiCatalogEntry(
            anchor: 'https://shop.example.com/_mcp',
            describedBy: [new LinkTarget('https://shop.example.com/described.txt')],
        ));

        $link = (string) $this->contribute($provider)->headers->get('Link');

        self::assertStringContainsString('<https://shop.example.com/described.txt>; rel="describedby"', $link);
        self::assertStringNotContainsString('type=""', $link);
    }

    /** describedby is the third relation and had no coverage of its own. */
    public function testDescribedByTargetsCarryTheirType(): void
    {
        $provider = $this->providerYielding(new ApiCatalogEntry(
            anchor: 'https://shop.example.com/_mcp',
            describedBy: [new LinkTarget('https://shop.example.com/schema.json', 'application/schema+json')],
        ));

        self::assertStringContainsString(
            '<https://shop.example.com/schema.json>; rel="describedby"; type="application/schema+json"',
            (string) $this->contribute($provider)->headers->get('Link'),
        );
    }

    /**
     * Two bundles may legitimately name the same OpenAPI document. Emitting it
     * twice is not wrong, only wasteful on every page of the shop, and the
     * header is the one place where that waste is paid by shoppers.
     */
    public function testRepeatedTargetsAreEmittedOnce(): void
    {
        $target = new LinkTarget('https://shop.example.com/openapi.json', 'application/json');
        $registry = new ApiCatalogRegistry([
            $this->providerYielding(new ApiCatalogEntry(anchor: 'https://shop.example.com/a', serviceDesc: [$target])),
            $this->providerYielding(new ApiCatalogEntry(anchor: 'https://shop.example.com/b', serviceDesc: [$target])),
        ]);

        $response = $this->html();
        (new LinkHeaderContributor($registry, new ArrayAdapter()))
            ->contribute(new Request(), $response, $this->context());

        self::assertSame(1, substr_count((string) $response->headers->get('Link'), 'openapi.json'));
    }

    /**
     * A cache entry holding an empty string must not become an empty Link
     * header. `Link:` with no value is malformed, and a strict client is
     * entitled to reject the whole response over it.
     */
    public function testAnEmptyCachedHeaderIsNotEmitted(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('koh_api_catalog.link.WEB_DE.de_DE');
        $item->set('');
        $cache->save($item);

        $response = $this->html();
        (new LinkHeaderContributor($this->registry(), $cache))
            ->contribute(new Request(), $response, $this->context());

        self::assertFalse($response->headers->has('Link'));
    }

    /** A cached value of the wrong type is rebuilt rather than emitted. */
    public function testANonStringCacheEntryIsRebuilt(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('koh_api_catalog.link.WEB_DE.de_DE');
        $item->set(['not', 'a', 'header']);
        $cache->save($item);

        $response = $this->html();
        (new LinkHeaderContributor($this->registry(), $cache))
            ->contribute(new Request(), $response, $this->context());

        self::assertStringContainsString('rel="api-catalog"', (string) $response->headers->get('Link'));
    }

    /**
     * The configured TTL has to reach the cache item. A hard-coded lifetime
     * here would make the documented option inert, and discovery data that
     * outlives its configuration is exactly what the option exists to prevent.
     */
    public function testTheConfiguredTtlIsAppliedToTheCacheItem(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(120)->willReturnSelf();

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->expects(self::once())->method('save')->with($item);

        (new LinkHeaderContributor($this->registry(), $cache, 120))
            ->contribute(new Request(), $this->html(), $this->context());
    }

    /**
     * Symfony fills in text/html after the listeners have run, so a response
     * that has not set one yet is still an HTML page. Treating a missing header
     * as "not HTML" would silently drop the advertisement from every controller
     * that leaves the default in place.
     */
    public function testAResponseWithoutAContentTypeIsTreatedAsHtml(): void
    {
        $response = new Response('<html></html>');
        $response->headers->remove('Content-Type');

        self::assertTrue(
            (new LinkHeaderContributor($this->registry(), new ArrayAdapter()))->supports(new Request(), $response),
        );
    }

    private function contribute(ApiCatalogEntryProviderInterface $provider): Response
    {
        $response = $this->html();
        (new LinkHeaderContributor(new ApiCatalogRegistry([$provider]), new ArrayAdapter()))
            ->contribute(new Request(), $response, $this->context());

        return $response;
    }

    private function providerYielding(ApiCatalogEntry $entry): ApiCatalogEntryProviderInterface
    {
        return new class($entry) implements ApiCatalogEntryProviderInterface {
            public function __construct(private ApiCatalogEntry $entry)
            {
            }

            public function getApiCatalogEntries(AgentContext $context): iterable
            {
                yield $this->entry;
            }
        };
    }

    private function registry(): ApiCatalogRegistry
    {
        return new ApiCatalogRegistry([
            $this->providerYielding(new ApiCatalogEntry(anchor: 'https://shop.example.com/_mcp')),
        ]);
    }

    private function html(): Response
    {
        return new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function context(
        string $channelCode = 'WEB_DE',
        string $baseUrl = 'https://shop.example.com',
        string $locale = 'de_DE',
    ): AgentContext {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($channelCode);

        return new AgentContext($channel, $baseUrl, $locale, [$locale]);
    }
}
