<?php

namespace Tests\Base\Security;

use Base\Service\HotParameterBagInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Http\HttpTestTrait;

/**
 * The sign-in by a link (base.user.login_with_token) answers the same
 * whether the address has an account or not: the same status, the same
 * redirection, the same messages left for the next page - "a link is sent to
 * the address given, if an account goes with it". The link itself goes by
 * e-mail, and only there. It told an account apart: the link's notification
 * was sent to the browser too, twice, for an address that has an account and
 * for no other.
 *
 * Each page is asked of a kernel of its own, as in ForgottenPasswordHttpTest;
 * the option is put on in each of them.
 */
class SignInByLinkHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->bootHost();
        $this->allow();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->bootHost();
        $this->removeUsers();

        parent::tearDown();
    }

    /** The option on, in this kernel: through the hot parameter bag, as a setting typed in the back office is. */
    private function allow(): void
    {
        $bag = static::getContainer()->get('parameter_bag');
        if (!$bag instanceof HotParameterBagInterface) {
            self::markTestSkipped('The host application has no hot parameter bag to put base.user.login_with_token on.');
        }
        $bag->add(['base.user.login_with_token' => true]);
        if (true !== $bag->get('base.user.login_with_token')) {
            self::markTestSkipped('base.user.login_with_token cannot be put on here.');
        }
    }

    /** @param array<string, mixed>|null $post */
    private function browse(string $path, ?array $post = null): Response
    {
        self::ensureKernelShutdown();
        $this->bootHost();
        $this->allow();

        $request = Request::create($path, null === $post ? 'GET' : 'POST', $post ?? []);
        foreach ($this->cookies as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $response = static::$kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /**
     * The messages waiting in the visitor's session: what a layout prints on the next page (the
     * harness's prints none, so the pages alone would not show them).
     *
     * @return array<string, list<string>>
     */
    private function flashes(): array
    {
        self::ensureKernelShutdown();
        $this->bootHost();

        $session = static::getContainer()->get('session.factory')->createSession();
        if (!isset($this->cookies[$session->getName()])) {
            return [];
        }
        $session->setId($this->cookies[$session->getName()]);
        $session->start();

        return $session->getFlashBag()->peekAll();
    }

    /**
     * The page read, its form sent with this address - by a visitor of their own.
     *
     * @return array{0: int, 1: string, 2: array<string, list<string>>} the answer's status, where it sends, the messages left for the next page
     */
    private function ask(string $email): array
    {
        $this->cookies = [];
        $page = $this->browse('/login/token');
        $this->assertSame(200, $page->getStatusCode(), 'the page of the sign-in by a link opens');

        $fields = [];
        preg_match_all('/<input[^>]*name="(_base_security_login_token)\[([^\]"]+)\]"[^>]*>/', (string) $page->getContent(), $inputs, \PREG_SET_ORDER);
        foreach ($inputs as [$tag, $form, $name]) {
            $fields[$name] = preg_match('/value="([^"]*)"/', $tag, $value) ? html_entity_decode($value[1]) : '';
        }
        $this->assertArrayHasKey('email', $fields, 'the form asks for an address');

        // With the token the test environment's captcha (omnishield's fixed gateway) prints in the page, outside the form.
        $answer = $this->browse('/login/token', ['_base_security_login_token' => ['email' => $email] + $fields, 'omnishield-token' => 'omnishield-fixed-token']);

        return [$answer->getStatusCode(), (string) $answer->headers->get('Location'), $this->flashes()];
    }

    public function testTheAnswerIsTheSameWhetherTheAddressHasAnAccountOrNot(): void
    {
        $account = $this->createUser()->getEmail();
        $withALink = $this->createUser()->getEmail();
        $nobody = 'nobody'.bin2hex(random_bytes(4)).'@example.org';

        $this->ask($withALink); // it holds a live link from now on
        $answers = [
            'an account' => $this->ask($account),
            'an account that already has a link' => $this->ask($withALink),
            'an address nobody has' => $this->ask($nobody),
        ];

        $this->assertLessThan(400, $answers['an account'][0]);
        $this->assertSame($answers['an address nobody has'], $answers['an account'], 'status, redirection, messages: nothing tells an account from nobody');
        $this->assertSame($answers['an address nobody has'], $answers['an account that already has a link']);

        $confirmation = static::getContainer()->get('translator')->trans('@notifications.loginToken.confirmation');
        $messages = array_merge(...array_values($answers['an address nobody has'][2] ?: [[]]));
        $this->assertSame([$confirmation], $messages, 'one message, the same for every address');
    }

    public function testAnAccountThatAsksIsSentItsLink(): void
    {
        $user = $this->createUser();
        $this->ask($user->getEmail());

        // The difference is in the mailbox, not on the page: the account holds a sign-in link now.
        $this->entityManager()->clear();
        $account = $this->entityManager()->getRepository($user::class)->find($user->getId());
        $this->assertNotNull($account->getToken('login-token'), 'the account was sent a link');
    }

    /**
     * The sign-in page offers the sign-in by a link when the option is on - by a route of that name.
     * It named "security_loginByToken", which no route is: with the option on, the page could not be
     * drawn. (The harness's own sign-in page is drawn blank, so the core's own routes - security_* -
     * that its security pages name are checked against the router instead; oauth_*, 2fa_* and the
     * like are the application's.)
     */
    public function testEveryRouteTheSecurityPagesNameExists(): void
    {
        $router = static::getContainer()->get('router');
        $missing = [];
        foreach (glob(\dirname(__DIR__, 2).'/templates/security/*.twig') as $template) {
            preg_match_all('/(?:path|url)\(\s*[\'"](security_[A-Za-z0-9_.]+)[\'"]/', (string) file_get_contents($template), $names);
            foreach (array_unique($names[1]) as $name) {
                if (null === $router->getRouteCollection()->get($name)) {
                    $missing[] = basename($template).': '.$name;
                }
            }
        }

        $this->assertSame([], $missing, 'routes named by the security pages that do not exist');
        $this->assertSame('/login/token', $router->generate('security_loginWithToken'), 'the page of the sign-in by a link');
    }
}
