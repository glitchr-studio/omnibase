<?php

namespace Tests\Base\Security;

use Base\Security\LoginFormAuthenticator;
use Base\Security\SignInGuard;
use Base\Service\FormGuard;
use Omniguard\Registry;
use Omniguard\Testing\FixedGateway;
use Omniguard\Testing\FixedGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The sign-in's captcha after a few failures from an address: not before,
 * refused without a token, passed with one, forgotten after a success - and
 * never where ux-google's reCAPTCHA guards the sign-in.
 */
class SignInGuardTest extends TestCase
{
    private RequestStack $requests;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        if (!class_exists(Registry::class)) {
            self::markTestSkipped('glitchr/omniguard is not installed.');
        }
        $this->requests = new RequestStack();
        $this->cache = new ArrayAdapter();
    }

    private function guard(bool $google = false): SignInGuard
    {
        $registry = new Registry([new FixedGatewayFactory()], ['forms' => ['factory' => 'fixed']]);

        return new SignInGuard(new FormGuard('secret', ['sign_in_after' => 2], $registry, null, 'forms'), $this->cache, $google, null, $this->requests);
    }

    /** @param array<string, mixed> $post */
    private function signIn(array $post = []): Request
    {
        $request = Request::create('/login', 'POST', $post + ['_base_security_login' => ['identifier' => 'anne', 'password' => 'wrong']], [], [], ['REMOTE_ADDR' => '203.0.113.5']);
        $request->attributes->set('_route', LoginFormAuthenticator::LOGIN_ROUTE);
        $this->requests->push($request);

        return $request;
    }

    private function failOnce(SignInGuard $guard, Request $request): void
    {
        $guard->onLoginFailure(new LoginFailureEvent(new BadCredentialsException(), $this->createMock(LoginFormAuthenticator::class), $request, null, 'main', new SelfValidatingPassport(new UserBadge('anne'))));
    }

    private function check(SignInGuard $guard): void
    {
        $guard->onCheckPassport(new CheckPassportEvent($this->createMock(LoginFormAuthenticator::class), new SelfValidatingPassport(new UserBadge('anne'))));
    }

    public function testTheCaptchaComesAfterTheFailures(): void
    {
        $guard = $this->guard();
        $request = $this->signIn();

        $this->assertNull($guard->required($request), 'no failure yet');
        $this->check($guard);
        $this->failOnce($guard, $request);
        $this->assertNull($guard->required($request), 'one');
        $this->failOnce($guard, $request);
        $this->assertSame('forms', $guard->required($request), 'two: the captcha');
        $this->assertNull($guard->required(Request::create('/login', server: ['REMOTE_ADDR' => '198.51.100.9'])), 'another address is not counted');

        // Without its token the sign-in is refused before its password is checked...
        try {
            $this->check($guard);
            $this->fail('a sign-in without the captcha went through');
        } catch (CustomUserMessageAuthenticationException $e) {
            $this->assertSame(SignInGuard::MESSAGE, $e->getMessageKey());
        }

        // ...with it, it goes on; a success forgets the count.
        $request = $this->signIn([FixedGateway::FIELD => FixedGateway::TOKEN]);
        $this->check($guard);
        $guard->onLoginSuccess(new LoginSuccessEvent($this->createMock(LoginFormAuthenticator::class), new SelfValidatingPassport(new UserBadge('anne')), $this->createMock(TokenInterface::class), $request, null, 'main'));
        $this->assertNull($guard->required($request));
    }

    public function testNotWhereUxGoogleGuardsTheSignIn(): void
    {
        $guard = $this->guard(google: true);
        $request = $this->signIn();
        $this->failOnce($guard, $request);
        $this->failOnce($guard, $request);
        $this->failOnce($guard, $request);

        $this->assertNull($guard->required($request));
        $this->check($guard);
        $this->addToAssertionCount(1);
    }
}
