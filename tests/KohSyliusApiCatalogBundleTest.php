<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusApiCatalog\Tests;

use Koh\SyliusApiCatalog\Controller\ApiCatalogController;
use Koh\SyliusApiCatalog\EventListener\LinkHeaderContributor;
use Koh\SyliusApiCatalog\KohSyliusApiCatalogBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * The wiring, exercised through Symfony's real extension and routing loaders.
 *
 * Configuration fails silently by nature: a dropped tag or a changed path
 * produces no error, just a bundle that stops doing its job while the shop
 * carries on serving pages. The catalog path in particular is fixed by RFC 9727
 * and by every agent that has already been told where to look, so it is not a
 * detail the package may quietly revise.
 */
final class KohSyliusApiCatalogBundleTest extends TestCase
{
    /** config/ sits at the package root, not under src/. */
    public function testThePathResolvesTheShippedConfiguration(): void
    {
        $path = (new KohSyliusApiCatalogBundle())->getPath();

        self::assertFileExists($path.'/config/services.php');
        self::assertFileExists($path.'/config/routes.php');
    }

    public function testTheDefaultsAreTheDocumentedOnes(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->getParameter('koh_api_catalog.link_headers'));
        self::assertSame(3600, $container->getParameter('koh_api_catalog.ttl'));
    }

    public function testTheConfigurationIsHonoured(): void
    {
        $container = $this->load(['link_headers' => false, 'ttl' => 60]);

        self::assertFalse($container->getParameter('koh_api_catalog.link_headers'));
        self::assertSame(60, $container->getParameter('koh_api_catalog.ttl'));
    }

    public function testTheBundleRegistersItsServices(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasDefinition(ApiCatalogController::class));
        self::assertTrue($container->hasDefinition(LinkHeaderContributor::class));
    }

    /**
     * The route points at the controller as a service, so the controller has to
     * be public and carry controller.service_arguments. Without either the
     * route resolves to nothing and /.well-known/api-catalog 404s -- at runtime,
     * with no hint that the cause is the container.
     */
    public function testTheControllerIsReachableAsAServiceController(): void
    {
        $definition = $this->load([])->getDefinition(ApiCatalogController::class);

        self::assertTrue($definition->isPublic());
        self::assertNotEmpty($definition->getTag('controller.service_arguments'));
    }

    /** The shared kernel.response listener finds the contributor by this tag. */
    public function testTheContributorIsTagged(): void
    {
        self::assertNotEmpty(
            $this->load([])->getDefinition(LinkHeaderContributor::class)->getTag('koh_agent.response_header'),
        );
    }

    /**
     * The contributor's switch and TTL come from configuration, so the wiring
     * has to pass the parameters through. Hard-coding either would leave the
     * documented options with no effect at all.
     */
    public function testTheContributorIsWiredToTheConfiguredParameters(): void
    {
        $arguments = $this->load([])->getDefinition(LinkHeaderContributor::class)->getArguments();

        self::assertSame('%koh_api_catalog.ttl%', (string) $arguments[2]);
        self::assertSame('%koh_api_catalog.link_headers%', (string) $arguments[3]);
    }

    /** A typo in a shop's config must fail the build, not fall back to a default. */
    public function testAMistypedOptionIsRejected(): void
    {
        try {
            $this->load(['ttl' => 'forever']);
            self::fail('a non-integer ttl must not be accepted');
        } catch (InvalidTypeException $e) {
            self::assertStringContainsString('ttl', $e->getMessage());
        }
    }

    /**
     * RFC 9727 fixes the path. Agents are told this location once and cache it,
     * so moving it is not a refactor -- it is a broken contract with every
     * client already pointed at the origin.
     */
    public function testTheCatalogIsServedAtTheWellKnownPath(): void
    {
        $route = $this->routes()->get('koh_api_catalog');

        self::assertNotNull($route, 'the koh_api_catalog route is missing');
        self::assertSame('/.well-known/api-catalog', $route->getPath());
        self::assertSame(ApiCatalogController::class, $route->getDefault('_controller'));
    }

    /**
     * HEAD belongs beside GET: agents probe for the catalog before fetching it,
     * and a bare GET route answers those probes with 405.
     */
    public function testTheRouteAnswersGetAndHead(): void
    {
        $methods = $this->routes()->get('koh_api_catalog')?->getMethods();

        self::assertSame(['GET', 'HEAD'], $methods);
    }

    private function routes(): RouteCollection
    {
        $path = (new KohSyliusApiCatalogBundle())->getPath().'/config';

        return (new PhpFileLoader(new FileLocator([$path])))->load('routes.php');
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        // ContainerConfigurator reads these while importing config/services.php.
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new KohSyliusApiCatalogBundle())->getContainerExtension();
        self::assertNotNull($extension);
        self::assertSame('koh_api_catalog', $extension->getAlias());

        $extension->load([$config], $container);

        return $container;
    }
}
