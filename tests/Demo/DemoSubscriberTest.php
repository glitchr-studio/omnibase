<?php

namespace Tests\Base\Demo;

use Base\Demo\DemoAccountRegistry;
use Base\Subscriber\DemoSubscriber;
use Base\Subscriber\DemoSuperAdminSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * The demonstration's safeguards one by one: no page indexed, no e-mail
 * sent, and a super-administrator who signs in with the secret or not at
 * all.
 */
class DemoSubscriberTest extends TestCase
{
    public function testEveryResponseSaysNoindex(): void
    {
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Response('ok'));
        (new DemoSubscriber())->onResponse($event);

        $this->assertSame('noindex, nofollow', $event->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testNoEmailLeavesQueuedOrNot(): void
    {
        $email = (new Email())->from('site@example.org')->to('visitor@example.org')->subject('Votre rendez-vous')->text('...');
        foreach ([true, false] as $queued) {
            $event = new MessageEvent($email, new Envelope(new Address('site@example.org'), [new Address('visitor@example.org')]), 'smtp://mail.example.org', $queued);
            (new DemoSubscriber())->onMessage($event);
            $this->assertTrue($event->isRejected(), $queued ? 'refused as it is queued' : 'refused as it is sent');
        }
    }

    private function check(?string $secret, Passport $passport): void
    {
        (new DemoSuperAdminSubscriber(new DemoAccountRegistry(), $secret))
            ->onCheckPassport(new CheckPassportEvent($this->createMock(AuthenticatorInterface::class), $passport));
    }

    private function passport(array $roles, string $typed): Passport
    {
        $user = new InMemoryUser('glitchr', 'stored-hash', $roles);

        return new Passport(new UserBadge('glitchr', static fn () => $user), new PasswordCredentials($typed));
    }

    public function testWithoutTheSecretASuperAdministratorDoesNotSignIn(): void
    {
        foreach ([null, ''] as $secret) {
            try {
                $this->check($secret, $this->passport(['ROLE_SUPERADMIN'], 'glitchr'));
                $this->fail('signed in without a secret');
            } catch (CustomUserMessageAuthenticationException $e) {
                $this->assertSame('This account is not available in the demonstration.', $e->getMessageKey());
            }
        }

        // By no other means either: a link, a remembered session.
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->check(null, new SelfValidatingPassport(new UserBadge('glitchr', static fn () => new InMemoryUser('glitchr', null, ['ROLE_SUPERADMIN']))));
    }

    public function testWithTheSecretItsPasswordIsTheSecretNotTheStoredOne(): void
    {
        $passport = $this->passport(['ROLE_SUPERADMIN'], 'not-a-real-secret-example');
        $this->check('not-a-real-secret-example', $passport);
        $this->assertTrue($passport->getBadge(PasswordCredentials::class)->isResolved(), 'checked here: the stored password is not looked at');

        $this->expectException(BadCredentialsException::class);
        $this->check('not-a-real-secret-example', $this->passport(['ROLE_SUPERADMIN'], 'glitchr'));
    }

    public function testAnyOtherAccountIsLeftToTheUsualCheck(): void
    {
        $passport = $this->passport(['ROLE_ADMIN'], 'whatever');
        $this->check(null, $passport);

        $this->assertFalse($passport->getBadge(PasswordCredentials::class)->isResolved());
    }
}
