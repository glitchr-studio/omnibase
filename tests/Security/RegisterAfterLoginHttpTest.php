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
    private function signUp(string $email): Response
    {
        $this->cookies = [];
        $this->browse('/login');
        $page = $this->browse('/register', null, '/login');
        $this->assertSame(200, $page->getStatusCode(), $this->said($page));

        preg_match('/name="_base_security_registration\[_csrf_token\]"[^>]*value="([^"]+)"/', (string) $page->getContent(), $token);
        $this->signedUp[] = $email;

        return $this->browse('/register', ['_base_security_registration' => [
            'email' => $email,
            'plainPassword' => ['first' => 'A-long-Passphrase-42!', 'second' => 'A-long-Passphrase-42!'],
            'agreeTerms' => '1',
            '_csrf_token' => html_entity_decode($token[1] ?? ''),
        ]], '/register');
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
