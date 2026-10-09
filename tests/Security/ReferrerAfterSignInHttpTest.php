<?php

namespace Tests\Base\Security;

use Base\Security\LoginFormAuthenticator;
use Base\Subscriber\ReferrerSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Http\HttpTestTrait;

/**
 * After signing in, the visitor goes back to the last page they read - not
 * to the last thing their browser asked: the sign-in sent chapaland's
 * visitors to an avatar's SVG, served by a controller and asked after the
 * page. ReferrerSubscriber read base.access_restrictions.route_exceptions,
 * a parameter that does not exist (base.access_restriction...), and
 * remembered every request.
 */
class ReferrerAfterSignInHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    /** @var array<string, string> the visitor's cookies, as a browser keeps them */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->bootHost();

        $firewall = static::getContainer()->get('security.firewall.map')->getFirewallConfig(Request::create('/login', 'POST'));
        if (!in_array(LoginFormAuthenticator::class, $firewall?->getAuthenticators() ?? [], true)) {
            self::markTestSkipped('The host application\'s firewall does not sign /login in with Base\Security\LoginFormAuthenticator.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeUsers();
        parent::tearDown();
    }

    /** @param array<string, string> $server */
    private function browse(string $path, string $method = 'GET', array $parameters = [], array $server = []): Response
    {
        $request = Request::create($path, $method, $parameters, [], [], $server);
        foreach ($this->cookies as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    public function testTheSignInGoesBackToTheLastPageNotToTheLastImage(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $this->entityManager()->clear();

        $page = $this->browse('/welcome', server: ['HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml']);
        $this->assertSame(200, $page->getStatusCode(), 'the page read');
        $this->assertNotEmpty($this->cookies, 'its session');

        // What the page's markup asks next: an image, served by the site's code (here a page's address asked as an
        // <img>, the way an avatar's SVG is), a feed, a fragment of HTML a widget fetches.
        $this->browse('/', server: ['HTTP_SEC_FETCH_DEST' => 'image', 'HTTP_ACCEPT' => 'image/avif,image/webp,*/*']);
        $this->browse('/sitemap.xml', server: ['HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $signedIn = $this->browse('/login', 'POST', ['security_login' => [
            'identifier' => $user->getEmail(),
            'password' => 'test-'.explode('@', $user->getEmail())[0],
        ]]);

        $this->assertSame(302, $signedIn->getStatusCode(), 'signed in');
        $this->assertSame('/welcome', parse_url((string) $signedIn->headers->get('Location'), \PHP_URL_PATH), 'back to the page read, not to the image nor the feed');
    }

    public function testATransparentjsSwapIsAPageAFragmentIsNot(): void
    {
        $xhr = ['HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => '*/*'];
        $page = new Response('<!DOCTYPE html><html><head></head><body>A page</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $fragment = new Response('<li>An item</li>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $this->assertTrue(ReferrerSubscriber::isPageNavigation(Request::create('/a', 'GET', [], [], [], $xhr), $page), 'transparentjs\'s swap: a whole page');
        $this->assertFalse(ReferrerSubscriber::isPageNavigation(Request::create('/a', 'GET', [], [], [], $xhr), $fragment), 'a widget\'s fragment');
        $this->assertTrue(ReferrerSubscriber::isPageNavigation(Request::create('/a', 'GET', [], [], [], ['HTTP_SEC_FETCH_DEST' => 'document']), $page));
        $this->assertTrue(ReferrerSubscriber::isPageNavigation(Request::create('/a'), $page), 'a client that says nothing of the destination');
        $this->assertFalse(ReferrerSubscriber::isPageNavigation(Request::create('/a', 'GET', [], [], [], ['HTTP_SEC_FETCH_DEST' => 'image']), $page), 'asked as an image');
        $this->assertFalse(ReferrerSubscriber::isPageNavigation(Request::create('/a'), new Response('<svg/>', 200, ['Content-Type' => 'image/svg+xml'])), 'an SVG');
        $this->assertFalse(ReferrerSubscriber::isPageNavigation(Request::create('/a', 'POST'), $page), 'a form sent');
        $this->assertFalse(ReferrerSubscriber::isPageNavigation(Request::create('/a'), new Response('', 302, ['Content-Type' => 'text/html'])), 'a redirection');
        $this->assertFalse(ReferrerSubscriber::isPageNavigation(Request::create('/a', 'GET', [], [], [], ['HTTP_SEC_PURPOSE' => 'prefetch']), $page), 'a prefetch');
    }

    public function testTheRouteExceptionsAreRead(): void
    {
        $exceptions = static::getContainer()->getParameter('base.access_restriction.route_exceptions');

        $this->assertNotEmpty($exceptions, 'the parameter the subscribers read exists');
        $this->assertTrue(static::getContainer()->get(ReferrerSubscriber::class)->isException('security_login'));
        $this->assertFalse(static::getContainer()->get(ReferrerSubscriber::class)->isException('demo_welcome'));
        $this->assertFalse(static::getContainer()->get(ReferrerSubscriber::class)->isException(null));
    }
}
