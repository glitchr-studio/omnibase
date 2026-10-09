<?php

namespace Tests\Base\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Http\HttpTestTrait;

/**
 * A visitor reads the sign-in page, follows "no account yet? sign up" with
 * the same session, and signs up. Where the application's User carries a
 * username (a column of its own, unique and not null - every application's
 * does), the sign-up form does not ask for one: the account was inserted
 * without it, and the visitor met a 500 in place of their new account.
 *
 * Each page is asked of a kernel of its own, as each is of a PHP process of
 * its own: nothing a service kept from the page before is there.
 */
class RegisterAfterLoginHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    /** @var array<string, string> the visitor's cookies, kept from one page to the next */
    private array $cookies = [];

    /** @var string[] the addresses signed up here */
    private array $signedUp = [];

    protected function setUp(): void
    {
        $this->bootHost();
        $this->cookies = [];
    }

    protected function tearDown(): void
    {
        if ($this->signedUp) {
            self::ensureKernelShutdown();
            $this->bootHost();
            $em = $this->entityManager();
            foreach ($this->signedUp as $email) {
                if ($user = $em->getRepository('App\\Entity\\User')->findOneBy(['email' => $email])) {
                    $em->remove($user);
                }
            }
            $em->flush();
            $this->signedUp = [];
        }

        parent::tearDown();
    }

    /** @param array<string, mixed>|null $post */
    private function browse(string $path, ?array $post = null, ?string $referer = null): Response
    {
        self::ensureKernelShutdown();
        $this->bootHost();

        $request = Request::create($path, null === $post ? 'GET' : 'POST', $post ?? [], [], [], $referer ? ['HTTP_REFERER' => 'http://localhost'.$referer] : []);
        foreach ($this->cookies as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $response = static::$kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    private function said(Response $response): string
    {
        return $response->getStatusCode().' '.$response->headers->get('Location').' '.substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $response->getContent()))), 0, 900);
    }

    /** The sign-up page read, its form filled and sent, as a visitor does - with a new session each time. */
    private function signUp(string $email, array $more = ['omnishield-token' => 'omnishield-fixed-token']): Response
    {
        $this->cookies = [];
        $this->browse('/login');
        $page = $this->browse('/register', null, '/login');
        $this->assertSame(200, $page->getStatusCode(), $this->said($page));

        preg_match('/name="_base_security_registration\[_csrf_token\]"[^>]*value="([^"]+)"/', (string) $page->getContent(), $token);
        // The guard's stamp (when the form was shown) and the test environment's captcha token, as the page prints them.
        preg_match('/name="_base_security_registration\[guard_opened\]"[^>]*value="([^"]+)"/', (string) $page->getContent(), $stamp);
        $this->signedUp[] = $email;

        return $this->browse('/register', ['_base_security_registration' => [
            'email' => $email,
            'plainPassword' => ['first' => 'A-long-Passphrase-42!', 'second' => 'A-long-Passphrase-42!'],
            'agreeTerms' => '1',
            '_csrf_token' => html_entity_decode($token[1] ?? ''),
            'guard_opened' => html_entity_decode($stamp[1] ?? ''),
        ]] + $more, '/register');
    }

    private function account(string $email): ?object
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository('App\\Entity\\User')->findOneBy(['email' => $email]);
    }

    public function testTheSignUpPageOpensAfterTheSignInPageInTheSameSession(): void
    {
        $this->assertSame(200, $this->browse('/login')->getStatusCode());
        $this->assertNotEmpty($this->cookies, 'the sign-in page opened a session');

        $register = $this->browse('/register', null, '/login');
        $this->assertSame(200, $register->getStatusCode(), $this->said($register));

        $login = $this->browse('/login', null, '/register');
        $this->assertSame(200, $login->getStatusCode(), $this->said($login));
    }

    public function testAVisitorSignsUpAfterReadingTheSignInPage(): void
    {
        $name = 'signup'.bin2hex(random_bytes(4));
        $email = $name.'@example.org';

        $response = $this->signUp($email);
        $this->assertLessThan(500, $response->getStatusCode(), $this->said($response));
        $this->assertSame(302, $response->getStatusCode(), $this->said($response));

        // Signed up, signed in: the next page is asked as the new account.
        $next = $this->browse((string) parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH), null, '/register');
        $this->assertLessThan(500, $next->getStatusCode(), $this->said($next));
        $this->assertSame($email, static::getContainer()->get('security.token_storage')->getToken()?->getUser()?->getEmail());

        $account = $this->account($email);
        $this->assertNotNull($account);
        if (method_exists($account, 'getUsername')) {
            $this->assertSame($name, $account->getUsername(), 'named after the local part of its address');
        }
    }

    public function testAnAddressAlreadyTakenIsSaidOnThePageWithTheWaysIn(): void
    {
        $email = 'taken'.bin2hex(random_bytes(4)).'@example.org';
        $this->assertSame(302, $this->signUp($email)->getStatusCode());

        // Somebody signs up with that address again: the form comes back, and says why.
        $again = $this->signUp($email);
        $this->assertContains($again->getStatusCode(), [200, 422], $this->said($again));
        $html = (string) $again->getContent();
        $this->assertSame(1, preg_match('~<div class="invalid-feedback"[^>]*>(.*?)</div>~s', $html, $error), 'the page says what is wrong: '.$this->said($again));

        // A sentence of the catalogue, in the page's language - not the constraint's generic '"x" is already used'.
        $translator = static::getContainer()->get('translator');
        $said = trim(html_entity_decode(strip_tags($error[1])));
        $this->assertSame($translator->trans('@validators.user.email.unique'), $said);
        $this->assertSame('Un compte existe déjà avec cette adresse.', $translator->trans('@validators.user.email.unique', [], null, 'fr'));
        $this->assertSame('An account already exists with this address.', $translator->trans('@validators.user.email.unique', [], null, 'en'));
        $this->assertStringNotContainsString($email, $said);

        // Followed by the two ways in, by their routes.
        $router = static::getContainer()->get('router');
        $this->assertSame(1, preg_match('~<p class="signup-account-exists[^"]*">(.*?)</p>~s', $html, $ways), 'the ways in are offered');
        $this->assertStringContainsString('href="'.$router->generate('security_login').'"', $ways[1]);
        $this->assertStringContainsString('href="'.$router->generate('security_resetPassword').'"', $ways[1]);
        $this->assertStringContainsString($translator->trans('@forms.register.signIn'), html_entity_decode($ways[1]));
        $this->assertStringContainsString($translator->trans('@forms.register.forgotPassword'), html_entity_decode($ways[1]));

        $this->assertStringContainsString('value="'.$email.'"', $html, 'what was typed is kept');
        $this->assertCount(1, $this->entityManager()->getRepository('App\\Entity\\User')->findBy(['email' => $email]));
    }

    public function testASignUpRefusedForAnotherReasonOffersNoWayIn(): void
    {
        // The page as read, then as given back for two passwords that differ.
        $this->browse('/login');
        $page = $this->browse('/register', null, '/login');
        $this->assertStringNotContainsString('signup-account-exists', (string) $page->getContent());

        preg_match('/name="_base_security_registration\[_csrf_token\]"[^>]*value="([^"]+)"/', (string) $page->getContent(), $token);
        // The guard's stamp (when the form was shown) and the test environment's captcha token, as the page prints them.
        preg_match('/name="_base_security_registration\[guard_opened\]"[^>]*value="([^"]+)"/', (string) $page->getContent(), $stamp);
        $refused = $this->browse('/register', ['_base_security_registration' => [
            'email' => 'new'.bin2hex(random_bytes(4)).'@example.org',
            'plainPassword' => ['first' => 'A-long-Passphrase-42!', 'second' => 'Another-Passphrase-43!'],
            'agreeTerms' => '1',
            '_csrf_token' => html_entity_decode($token[1] ?? ''),
            'guard_opened' => html_entity_decode($stamp[1] ?? ''),
        ], 'omnishield-token' => 'omnishield-fixed-token'], '/register');

        $this->assertContains($refused->getStatusCode(), [200, 422], $this->said($refused));
        $this->assertStringNotContainsString('signup-account-exists', (string) $refused->getContent());
    }

    /**
     * The sign-up is guarded by default (Base\Service\FormGuard): the harness's lists refuse a
     * disposable domain, on the e-mail field, in words; without the captcha's token it is refused too.
     */
    public function testTheSignUpIsGuarded(): void
    {
        if (!class_exists(\Omnishield\Registry::class)) {
            self::markTestSkipped('glitchr/omnishield is not installed.');
        }
        $translator = static::getContainer()->get('translator');

        $disposable = $this->signUp($throwaway = 'someone'.bin2hex(random_bytes(3)).'@mailinator.com');
        $this->assertContains($disposable->getStatusCode(), [200, 422], $this->said($disposable));
        $this->assertNull($this->account($throwaway), 'no account for a disposable address');
        $this->assertStringContainsString(htmlspecialchars($translator->trans('@forms.guard.disposable'), \ENT_QUOTES), (string) $disposable->getContent(), 'said, in words');

        $email = 'robot'.bin2hex(random_bytes(3)).'@example.org';
        $noToken = $this->signUp($email, []);
        $this->assertContains($noToken->getStatusCode(), [200, 422], $this->said($noToken));
        $this->assertNull($this->account($email), 'no account without the captcha');
        $this->assertStringContainsString(htmlspecialchars($translator->trans('Please confirm that you are not a robot.', [], 'validators'), \ENT_QUOTES), (string) $noToken->getContent());
    }

    public function testTwoAddressesWithTheSameLocalPartGetTwoNames(): void
    {
        if (!method_exists('App\\Entity\\User', 'getUsername')) {
            self::markTestSkipped('The host application\'s User has no username.');
        }
        $name = 'twin'.bin2hex(random_bytes(4));

        $this->assertSame(302, $this->signUp($name.'@example.org')->getStatusCode());
        $second = $this->signUp(strtoupper($name).'@example.net');
        $this->assertSame(302, $second->getStatusCode(), $this->said($second));

        $this->assertSame($name, $this->account($name.'@example.org')->getUsername());
        $twin = $this->account(strtoupper($name).'@example.net') ?? $this->account($name.'@example.net');
        $this->assertNotNull($twin, 'the second address signed up');
        $this->assertSame($name.'2', $twin->getUsername(), 'the name was taken: numbered');
    }
}
