<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusApiCatalog\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\LinkTarget;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;
use Koh\SyliusAgentContracts\Registry\ApiCatalogRegistry;
use Koh\SyliusApiCatalog\Controller\ApiCatalogController;
use Koh\SyliusApiCatalog\EventListener\LinkHeaderContributor;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApiCatalogTest extends TestCase
{
    public function testLinksetUsesItsOwnMediaType(): void
    {
        $response = ($this->controller())(new Request());

        self::assertSame('application/linkset+json; charset=utf-8', $response->headers->get('Content-Type'));
    }

    public function testEntriesAreSerialisedPerRfc9727(): void
    {
        /** @var array{linkset: list<array<string, mixed>>} $document */
        $document = json_decode((string) ($this->controller())(new Request())->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $entry = $document['linkset'][0];

        self::assertSame('https://shop.example.com/_mcp', $entry['anchor']);
        self::assertSame('https://shop.example.com/openapi.json', $entry['service-desc'][0]['href']);
        self::assertSame('application/json', $entry['service-desc'][0]['type']);
    }

    /** An empty linkset claims the origin exposes nothing — not the same as "no API installed". */
    public function testEmptyRegistryIs404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        ($this->controller([]))(new Request());
    }

    /**
     * Agents poll the catalog to notice when an origin's APIs change, so most
     * requests to it are re-checks by a client that already has the document.
     * The controller has to hand the request to the responder for that to turn
     * into a 304 — forget it and every poll rebuilds and re-sends the linkset,
     * which is a cost paid forever and visible nowhere.
     */
    public function testAReturningAgentIsAnsweredWith304(): void
    {
        $etag = ($this->controller())(new Request())->getEtag();
        self::assertNotNull($etag);

        $conditional = new Request();
        $conditional->headers->set('If-None-Match', $etag);

        self::assertSame(304, ($this->controller())($conditional)->getStatusCode());
    }

    public function testHtmlPagesAdvertiseTheCatalog(): void
    {
        $response = $this->contribute();

        self::assertStringContainsString(
            '<https://shop.example.com/.well-known/api-catalog>; rel="api-catalog"',
            (string) $response->headers->get('Link'),
        );
    }

    public function testRegisteredTargetsBecomeTypedLinkRelations(): void
    {
        $link = (string) $this->contribute()->headers->get('Link');

        self::assertStringContainsString('rel="service-desc"; type="application/json"', $link);
        self::assertStringContainsString('rel="service-doc"; type="text/markdown"', $link);
    }

    /**
     * Sylius emits rel="preload" and API Platform the Hydra documentation link
     * on the same response. Replacing the header breaks both.
     */
    public function testExistingLinkHeadersSurvive(): void
    {
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $response->headers->set('Link', '</app.css>; rel=preload; as=style');

        $this->contributor()->contribute(new Request(), $response, $this->context());

        $all = $response->headers->all('link');

        self::assertCount(2, $all);
        self::assertStringContainsString('rel=preload', implode(' ', $all));
        self::assertStringContainsString('rel="api-catalog"', implode(' ', $all));
    }

    public function testJsonResponsesAreLeftAlone(): void
    {
        $json = new Response('{}', 200, ['Content-Type' => 'application/json']);

        self::assertFalse($this->contributor()->supports(new Request(), $json));
    }

    public function testRedirectsAreLeftAlone(): void
    {
        $redirect = new Response('', 301, ['Content-Type' => 'text/html']);

        self::assertFalse($this->contributor()->supports(new Request(), $redirect));
    }

    public function testLinkHeadersCanBeTurnedOff(): void
    {
        $contributor = $this->contributor(enabled: false);

        self::assertFalse($contributor->supports(new Request(), $this->html()));
    }

    /** Walking every provider per page view would charge ordinary shoppers for a discovery feature. */
    public function testHeaderIsAssembledOncePerChannel(): void
    {
        $counting = new class implements ApiCatalogEntryProviderInterface {
            public int $calls = 0;

            public function getApiCatalogEntries(AgentContext $context): iterable
            {
                ++$this->calls;

                yield new ApiCatalogEntry(anchor: $context->url('/_mcp'));
            }
        };

        $contributor = new LinkHeaderContributor(new ApiCatalogRegistry([$counting]), new ArrayAdapter());

        $contributor->contribute(new Request(), $this->html(), $this->context());
        $contributor->contribute(new Request(), $this->html(), $this->context());

        self::assertSame(1, $counting->calls);
    }

    private function contribute(): Response
    {
        $response = $this->html();
        $this->contributor()->contribute(new Request(), $response, $this->context());

        return $response;
    }

    private function contributor(bool $enabled = true): LinkHeaderContributor
    {
        return new LinkHeaderContributor(
            new ApiCatalogRegistry([$this->provider()]),
            new ArrayAdapter(),
            3600,
            $enabled,
        );
    }

    /** @param list<ApiCatalogEntryProviderInterface>|null $providers */
    private function controller(?array $providers = null): ApiCatalogController
    {
        return new ApiCatalogController(
            new ApiCatalogRegistry($providers ?? [$this->provider()]),
            $this->contextFactory(),
            new AgentJsonResponder(),
        );
    }

    private function provider(): ApiCatalogEntryProviderInterface
    {
        return new class implements ApiCatalogEntryProviderInterface {
            public function getApiCatalogEntries(AgentContext $context): iterable
            {
                yield new ApiCatalogEntry(
                    anchor: $context->url('/_mcp'),
                    serviceDesc: [new LinkTarget($context->url('/openapi.json'), 'application/json')],
                    serviceDoc: [new LinkTarget($context->url('/auth.md'), 'text/markdown')],
                    title: 'MCP endpoint',
                );
            }
        };
    }

    private function html(): Response
    {
        return new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function contextFactory(): AgentContextFactoryInterface
    {
        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn($this->context());

        return $factory;
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
