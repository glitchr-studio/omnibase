<?php

namespace Tests\Base\Routing;

use Base\Routing\AdvancedRouterInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * An absolute URL generated where no request gives the host (a test, a
 * command, a worker sending an e-mail): the host is the site's, not the
 * scheme - url('security_login') answered "https://https/login", and so did
 * every address generated after it (url('_switch_locale') in a test).
 */
class AbsoluteUrlWithoutRequestTest extends KernelTestCase
{
    private array $server = [];

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires a host application (the omnibase harness).');
        }

        $this->server = $_SERVER;
        unset($_SERVER['HTTP_HOST']);
        $_SERVER['HTTPS'] = 'on';
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        parent::tearDown();
    }

    public function testTheHostIsNotTheScheme(): void
    {
        $router = static::getContainer()->get(AdvancedRouterInterface::class);
        if (!$router->useAdvancedFeatures()) {
            self::markTestSkipped('The advanced router is off in this application.');
        }

        $name = null;
        foreach (['security_login', 'app_login', 'user_profile'] as $candidate) {
            if ($router->getRouteCollection()->get($candidate)) {
                $name = $candidate;
                break;
            }
        }
        if (!$name) {
            self::markTestSkipped('No plain route to generate.');
        }

        $first = $router->generate($name, [], UrlGeneratorInterface::ABSOLUTE_URL);
        $then = $router->generate('_switch_locale', ['_locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ([$first, $then] as $url) {
            $host = parse_url($url, \PHP_URL_HOST);
            $this->assertNotContains($host, ['http', 'https'], $url);
            $this->assertNotEmpty($host, $url);
        }
        $this->assertSame(parse_url($first, \PHP_URL_HOST), parse_url($then, \PHP_URL_HOST));
        $this->assertSame($host, $router->getContext()->getHost(), 'and the context is left with the site\'s host');
    }
}
