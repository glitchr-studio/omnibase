<?php

namespace Tests\Base\Service;

use Base\Attributes\Attribute\Sitemap;
use Base\Event\SitemapEvent;
use Base\Service\Sitemapper;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Tests\Base\Http\HttpTestTrait;

/**
 * A page registered for a route whose action carries no #[Sitemap] is left
 * out of the sitemap, and said in the log: Sitemapper::register() threw, and
 * one listener (a blog's posts, a scholar's publications) put the whole
 * /sitemap.xml in 500 - every other page of the site with it.
 */
class SitemapperUndeclaredRouteTest extends KernelTestCase
{
    use HttpTestTrait;

    private const UNDECLARED = 'security_login';   // /login: a route of the bundle, no #[Sitemap] on its action

    protected function setUp(): void
    {
        $this->bootHost();
    }

    /** A sitemapper of its own, with a logger that keeps what it is told. */
    private function sitemapper(?AbstractLogger &$logger = null): Sitemapper
    {
        $logger = new class extends AbstractLogger {
            /** @var array<int, array{string, string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };

        $container = static::getContainer();
        $container->get('request_stack')->push(Request::create('http://localhost/sitemap.xml'));   // the router's context

        return (new Sitemapper($container->get('twig'), $container->get('base.attribute_reader'), $container->get('advanced_router'), $container->get('localizer'), $logger))
            ->setHostname('http://localhost');
    }

    public function testAnUndeclaredRouteIsLeftOutAndLogged(): void
    {
        $sitemap = $this->sitemapper($logger);

        $this->assertSame($sitemap, $sitemap->register(self::UNDECLARED), 'no exception');

        $response = $sitemap->serve('sitemap.xml.twig');
        $this->assertStringNotContainsString('/login', (string) $response->getContent());

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0][0]);
        $this->assertStringContainsString('#[Sitemap]', $logger->records[0][1]);
        $this->assertStringContainsString(self::UNDECLARED, $logger->records[0][1]);
    }

    public function testTheReasonIsSaidOnceHoweverManyPagesTheRouteHas(): void
    {
        $sitemap = $this->sitemapper($logger);

        foreach (['a', 'b', 'c'] as $slug) {
            $sitemap->register(self::UNDECLARED, ['slug' => $slug]);
        }

        $this->assertCount(1, $logger->records);
    }

    public function testARouteThatDoesNotExistAndAnAddressThatMatchesNothing(): void
    {
        $sitemap = $this->sitemapper($logger);

        $sitemap->register('no_such_route_anywhere');
        $sitemap->registerUrl('/no/such/address/anywhere');

        $this->assertCount(2, $logger->records);
        $this->assertStringContainsString('no_such_route_anywhere', $logger->records[0][1]);
        $this->assertStringContainsString('/no/such/address/anywhere', $logger->records[1][1]);
        $this->assertSame(200, $sitemap->serve('sitemap.xml.twig')->getStatusCode());
    }

    public function testADeclaredRouteIsStillRegistered(): void
    {
        $sitemap = $this->sitemapper($logger);

        // The address of /login, served by an action that declares itself.
        $sitemap->register(new Route('/login', ['_controller' => SitemapDeclaredFixture::class . '::page']));
        $sitemap->register(self::UNDECLARED);

        $content = (string) $sitemap->serve('sitemap.xml.twig')->getContent();
        $this->assertStringContainsString('/login', $content);
        $this->assertStringContainsString('0.9', $content, 'the attribute\'s priority');
        $this->assertCount(1, $logger->records, 'only the undeclared one is reported');
    }

    public function testAControllerGivenAsAnArrayOrAClosureIsRead(): void
    {
        $sitemap = $this->sitemapper($logger);

        $this->assertNotNull($sitemap->getSitemap(new Route('/login', ['_controller' => [SitemapDeclaredFixture::class, 'page']])));
        $this->assertNull($sitemap->getSitemap(new Route('/login', ['_controller' => [SitemapDeclaredFixture::class, 'other']])));
        $this->assertNull($sitemap->getSitemap(new Route('/login', ['_controller' => fn () => new Response()])));
        $this->assertNull($sitemap->getSitemap(new Route('/login')));
    }

    public function testTheSitemapAnswersWhenAListenerRegistersAnUndeclaredRoute(): void
    {
        static::getContainer()->get('event_dispatcher')->addListener(SitemapEvent::BUILD, function (SitemapEvent $event): void {
            $event->getSitemapper()->register(self::UNDECLARED);
            $event->getSitemapper()->register('no_such_route_anywhere', ['slug' => 'x']);
            $event->getSitemapper()->registerUrl('/no/such/address/anywhere');
        });

        foreach (['/sitemap.xml' => '<urlset', '/sitemap.txt' => ''] as $path => $expected) {
            $response = $this->request($path);

            $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), $path);
            $this->assertStringContainsString($expected, (string) $response->getContent());
        }
    }
}

class SitemapDeclaredFixture
{
    #[Sitemap(priority: 0.9, changefreq: 'weekly')]
    public function page(): Response
    {
        return new Response();
    }

    public function other(): Response
    {
        return new Response();
    }
}
