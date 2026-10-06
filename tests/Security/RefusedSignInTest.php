<?php

namespace Tests\Base\Security;

use Base\EntitySubscriber\ConnectionSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Tests\Base\Http\HttpTestTrait;

/**
 * A sign-in that fails is noted on its account's connection - when there is
 * an account to note it on. An application that limits the attempts
 * (Symfony's login_throttling) refuses the sixth before the passport was
 * given the means to load its user: asking the passport for it then threw a
 * LogicException, and the visitor met a 500 in place of "too many attempts".
 */
class RefusedSignInTest extends KernelTestCase
{
    use HttpTestTrait;

    protected function setUp(): void
    {
        $this->bootHost();
    }

    protected function tearDown(): void
    {
        $this->removeUsers();
        parent::tearDown();
    }

    private function failure(\Throwable $why, UserBadge $badge): LoginFailureEvent
    {
        return new LoginFailureEvent($why, $this->createMock(AuthenticatorInterface::class), Request::create('/login', 'POST'), null, 'main', new SelfValidatingPassport($badge));
    }

    public function testAnAttemptRefusedBeforeItsUserIsLoadedIsNotAnError(): void
    {
        $subscriber = static::getContainer()->get(ConnectionSubscriber::class);

        // Too many attempts: the passport has no loader yet.
        $subscriber->onLoginFailure($this->failure(new TooManyLoginAttemptsAuthenticationException(1), new UserBadge('somebody')));

        // An identifier nobody has: the loader says so.
        $subscriber->onLoginFailure($this->failure(new BadCredentialsException(), new UserBadge('nobody', static fn () => throw new UserNotFoundException())));

        $this->addToAssertionCount(2);
    }

    public function testAWrongPasswordIsStillNotedOnItsAccount(): void
    {
        $user = $this->createUser();
        $request = Request::create('/login', 'POST');
        $request->setSession(static::getContainer()->get('session.factory')->createSession());
        static::getContainer()->get('request_stack')->push($request);

        static::getContainer()->get(ConnectionSubscriber::class)->onLoginFailure($this->failure(new BadCredentialsException(), new UserBadge($user->getUserIdentifier(), static fn () => $user)));

        $this->entityManager()->clear();
        $connections = $this->entityManager()->getRepository(\Base\Entity\User\Connection::class)->findBy(['user' => $user->getId()]);
        $this->assertNotEmpty($connections, 'the failed attempt is on the account\'s connections');
    }
}
