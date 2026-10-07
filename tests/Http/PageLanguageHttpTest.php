<?php

namespace Tests\Base\Http;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page's language: its address (a route of one language), else the
 * visitor's choice or the language their last page was shown in (LANG),
 * else - on a first visit only - the browser's (Accept-Language), else the
 * site's. The harness speaks English and French; /language and /langue are
 * one page at an address per language, /welcome one address for both.
 *
 * The browser's language was read on every page from the USER/INFO cookie
 * the site's script writes after the first one: a visitor shown a French
 * page was moved to their browser's language from the second page on - and
 * a first visit was always in the site's language, whatever was asked.
 * Each page is asked of a kernel of its own, cookies carried by hand.
 */
class PageLanguageHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    /** The cookie the site's script writes with the browser's settings (UserInfoTrait). */
    private const USER_INFO = 'USER/INFO';

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->bootHost();
        if (!\in_array('fr', static::getContainer()->get(\Base\Service\LocalizerInterface::class)->getAvailableLocaleLangs(), true)) {
            self::markTestSkipped('The host application does not speak French.');
        }
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[self::USER_INFO]);
        parent::tearDown();
    }

    private function visit(string $path, ?string $browser = null, array $server = []): Response
    {
        self::ensureKernelShutdown();
        $this->bootHost();

        $request = Request::create($path, 'GET', [], $this->cookies, [], $server);
        $request->headers->remove('Accept-Language');
        if (null !== $browser) {
            $request->headers->set('Accept-Language', $browser);
        }
        $response = static::$kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /** @return array{string, string} the language the page was shown in, and where it links itself */
    private static function shown(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), substr(strip_tags((string) $response->getContent()), 0, 600));
        self::assertSame(1, preg_match('/<a id="here" href="([^"]+)">[^<]*<\/a> \(([a-z]{2}_[A-Z]{2})\)/', (string) $response->getContent(), $found));

        return [substr($found[2], 0, 2), $found[1]];
    }

    public function testTheBrowsersLanguageWelcomesTheFirstPageAndThePagesAfterStayInIt(): void
    {
        self::assertSame(['fr', '/langue'], self::shown($this->visit('/welcome', 'fr-FR,fr;q=0.9,en;q=0.5')), 'a first visit, in the browser\'s language - and its links in it');
        self::assertStringStartsWith('fr', $this->cookies['LANG'] ?? '', 'remembered');

        // The site's script has written the browser's settings since; the browser asks for English now.
        $_COOKIE[self::USER_INFO] = json_encode(['locale' => 'en-US']);
        self::assertSame(['fr', '/langue'], self::shown($this->visit('/welcome', 'en-US,en;q=0.9')), 'the second page stays in French');
        self::assertSame(['fr', '/langue'], self::shown($this->visit('/welcome', 'en-US,en;q=0.9')));
    }

    public function testWithoutAWordFromTheBrowserItIsTheSitesLanguage(): void
    {
        self::assertSame(['en', '/language'], self::shown($this->visit('/welcome')));
        self::assertSame(['en', '/language'], self::shown($this->visit('/welcome', 'de-DE,de;q=0.9')), 'a language the site does not speak');
    }

    public function testTheAddressDecidesAndIsRemembered(): void
    {
        $this->cookies['LANG'] = 'fr-FR';
        self::assertSame(['en', '/language'], self::shown($this->visit('/language', 'fr')), 'the English address is English');
        self::assertSame(['en', '/language'], self::shown($this->visit('/welcome', 'fr')), 'and the next page too');
        self::assertSame(['fr', '/langue'], self::shown($this->visit('/langue')));
        self::assertSame(['fr', '/langue'], self::shown($this->visit('/welcome', 'en')));
    }

    public function testTheVisitorsChoiceIsKept(): void
    {
        self::assertSame(['en', '/language'], self::shown($this->visit('/welcome', 'en')));

        $switch = $this->visit('/locale/fr', 'en', ['HTTP_REFERER' => 'http://localhost/welcome']);
        self::assertTrue($switch->isRedirect('/welcome'), (string) $switch->headers->get('Location'));
        self::assertStringStartsWith('fr', $this->cookies['LANG'] ?? '');
        self::assertSame(['fr', '/langue'], self::shown($this->visit('/welcome', 'en')));

        // From a page with an address per language: to its address in the language chosen.
        $switch = $this->visit('/locale/en', 'fr', ['HTTP_REFERER' => 'http://localhost/langue']);
        self::assertTrue($switch->isRedirect('/language'), (string) $switch->headers->get('Location'));
        self::assertSame(['en', '/language'], self::shown($this->visit('/welcome', 'fr')));
    }
}
