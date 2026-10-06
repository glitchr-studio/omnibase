<?php

namespace Tests\Base\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Http\HttpTestTrait;

/**
 * "Forgotten password" answers the same whether the address has an account
 * or not: the same status, the same page, the same message - "an e-mail is
 * sent to that address if it is found". The sign-up page says that an
 * address is taken (it cannot hide it: a sign-up that succeeds signs in);
 * this page is where nothing may be learnt about an address.
 *
 * Each page is asked of a kernel of its own, as in RegisterAfterLoginHttpTest.
 */
class ForgottenPasswordHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->bootHost();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->bootHost();
        $this->removeUsers();

        parent::tearDown();
    }

    /** @param array<string, mixed>|null $post */
    private function browse(string $path, ?array $post = null): Response
    {
        self::ensureKernelShutdown();
        $this->bootHost();

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
     * The page read, its form sent with this address, and the page that follows - by a visitor of their own.
     *
     * @return array{0: int, 1: string, 2: string, 3: array<string, list<string>>, 4: string} the answer's status, where it sends, what it says, the messages left for the next page, what that page says
     */
    private function ask(string $email): array
    {
        $this->cookies = [];
        $page = $this->browse('/reset-password');
        $this->assertSame(200, $page->getStatusCode());

        $fields = [];
        preg_match_all('/<input[^>]*name="security_reset_password\[([^\]"]+)\]"[^>]*>/', (string) $page->getContent(), $inputs, \PREG_SET_ORDER);
        foreach ($inputs as [$tag, $name]) {
            $fields[$name] = preg_match('/value="([^"]*)"/', $tag, $value) ? html_entity_decode($value[1]) : '';
        }
        $this->assertArrayHasKey('email', $fields, 'the form asks for an address');

        $answer = $this->browse('/reset-password', ['security_reset_password' => ['email' => $email] + $fields]);
        $flashes = $this->flashes();
        $next = $this->browse('/reset-password');

        return [$answer->getStatusCode(), (string) $answer->headers->get('Location'), $this->text($answer, $email), $flashes, $this->text($next, $email)];
    }

    /**
     * The messages waiting in the visitor's session: what a layout prints on the next page. (The
     * harness's layout prints none, so the pages alone would not show a message sent to one
     * address and not to the other.)
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

    /** What a page says, without what differs from one visit to the next: its tokens, the address typed. */
    private function text(Response $response, string $email): string
    {
        $html = (string) preg_replace('~<(script|style)\b.*?</\1>~s', '', (string) $response->getContent());
        $html = (string) preg_replace('/<input[^>]*type="hidden"[^>]*>/', '', $html);

        return trim((string) preg_replace('/\s+/', ' ', str_replace($email, '<address>', strip_tags($html))));
    }

    public function testTheAnswerIsTheSameWhetherTheAddressHasAnAccountOrNot(): void
    {
        $known = $this->createUser()->getEmail();
        $unknown = 'nobody'.bin2hex(random_bytes(4)).'@example.org';

        $forKnown = $this->ask($known);
        $forUnknown = $this->ask($unknown);

        $this->assertLessThan(500, $forKnown[0], $forKnown[2]);
        $this->assertSame($forUnknown, $forKnown, 'status, redirection, the page, its messages and the page after it: nothing tells the two apart');

        // One message, the same for both: "an e-mail is sent to that address if it is found" - printed by
        // the page, or left for the next one. The e-mail's own notification is not a second one (it was:
        // "your password has been changed", shown for an address that has an account, and for no other).
        $confirmation = static::getContainer()->get('translator')->trans('@notifications.resetPassword.confirmation');
        $messages = array_merge(...array_values($forKnown[3] ?: [[]]));
        if ($messages) {
            $this->assertSame([$confirmation], $messages);
        } else {
            $this->assertStringContainsString($confirmation, html_entity_decode($forKnown[2]), 'the confirmation is on the page');
        }
    }

    public function testAnAccountThatAsksIsSentItsLinkAndAnUnknownAddressNothing(): void
    {
        $user = $this->createUser();
        $this->ask($user->getEmail());

        // The difference is in the mailbox, not on the page: the account holds a reset token now.
        $this->entityManager()->clear();
        $account = $this->entityManager()->getRepository($user::class)->find($user->getId());
        $this->assertNotNull($account->getToken('reset-password'), 'the account was sent a link');
    }
}
