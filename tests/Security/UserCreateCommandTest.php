<?php

namespace Tests\Base\Security;

use Base\Entity\User\Token;
use Base\Subscriber\PasswordChangeSubscriber;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Base\Http\HttpTestTrait;

/**
 * user:create (glitchr's checkout of the core, 2026-10-02): an account made from
 * the command line, verified and approved, its password generated and shown
 * once, to be changed at its first sign-in - its pages lead to
 * /change-password while its "change-password" token is there. And a reset
 * link takes a valid "reset-password" token only: any token's value, expired,
 * revoked or of another kind, was taken.
 */
final class UserCreateCommandTest extends KernelTestCase
{
    use HttpTestTrait;

    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        if ($this->made) {
            self::ensureKernelShutdown();
            $this->bootHost();
            $em = $this->entityManager();
            foreach ($this->made as $email) {
                if ($user = $em->getRepository('App\\Entity\\User')->findOneBy(['email' => $email])) {
                    $em->remove($user);
                }
            }
            $em->flush();
        }
        $this->removeUsers();
        parent::tearDown();
    }

    private function create(array $arguments): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('user:create'));
        $tester->execute($arguments);

        return $tester;
    }

    public function testAnAccountIsMadeItsPasswordToChangeAtTheFirstSignIn(): void
    {
        $this->bootHost();
        $name = 'made'.bin2hex(random_bytes(3));
        $this->made[] = $email = $name.'@example.org';

        $tester = $this->create(['username' => $name, 'email' => $email, '--role' => ['ADMIN'], '--no-email' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(1, preg_match('/Password: ([A-Za-z0-9-]{19})/', $tester->getDisplay(), $password), 'shown once');

        $this->entityManager()->clear();
        $user = $this->entityManager()->getRepository('App\\Entity\\User')->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertTrue($user->isVerified());
        self::assertTrue($user->isApproved());
        self::assertTrue(static::getContainer()->get('security.user_password_hasher')->isPasswordValid($user, $password[1]));
        self::assertNotNull($user->getValidToken(PasswordChangeSubscriber::TOKEN), 'to be changed');

        // its pages lead to the password to choose
        $response = $this->request('/settings', $user);
        self::assertTrue($response->isRedirect(), (string) $response->getStatusCode());
        self::assertStringEndsWith('/change-password', (string) $response->headers->get('Location'));
        self::assertSame(200, $this->request('/change-password', $user)->getStatusCode());
    }

    public function testATakenAddressOrAnUnknownRoleIsRefused(): void
    {
        $this->bootHost();
        $existing = $this->createUser();
        self::assertSame(Command::FAILURE, $this->create(['username' => 'other'.bin2hex(random_bytes(3)), 'email' => $existing->getEmail(), '--no-email' => true])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->create(['username' => 'role'.bin2hex(random_bytes(3)), 'email' => 'role'.bin2hex(random_bytes(3)).'@example.org', '--role' => ['NOBODY'], '--no-email' => true])->getStatusCode());
    }

    public function testAResetLinkTakesAValidResetTokenOnly(): void
    {
        $this->bootHost();
        $user = $this->createUser();
        $em = $this->entityManager();
        $other = (new Token('login', 3600))->setUser($user);
        $revoked = (new Token('reset-password', 3600))->setUser($user)->revoke();
        $valid = (new Token('reset-password', 3600))->setUser($user);
        $em->flush();

        foreach (['another kind' => $other, 'revoked' => $revoked] as $what => $token) {
            $response = $this->request('/reset-password/'.$token->get());
            self::assertTrue($response->isRedirect(), "$what: refused ({$response->getStatusCode()})");
            self::assertStringNotContainsString('/reset-password/', (string) $response->headers->get('Location'), $what);
        }
        self::assertSame(200, $this->request('/reset-password/'.$valid->get())->getStatusCode(), 'a valid one opens the form');
    }
}
