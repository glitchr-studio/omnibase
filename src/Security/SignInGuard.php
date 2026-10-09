<?php

namespace Base\Security;

use Base\Service\FormGuard;
use Omnishield\Exception\InvalidKeyException;
use Omnishield\Exception\ProviderException;
use Omnishield\Model\Attempt;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The sign-in's captcha, after a few failures. Symfony's login_throttling is
 * the first line (five tries a minute, per identifier and address); this one
 * counts the failures from an address - whatever identifiers they tried -
 * and from base.guard.sign_in_after of them on, the sign-in form carries the
 * captcha (SecurityController::Login) and a sign-in without a valid token is
 * refused before its password is even checked. A success forgets the count.
 *
 * Only the site's own sign-in form (security_login): not the rescue door, not
 * the demonstration's one click. Nothing where glitchr/ux-google's reCAPTCHA
 * already guards the sign-in (google.recaptcha.enable), nor without
 * glitchr/omnishield and a captcha.
 */
class SignInGuard implements EventSubscriberInterface
{
    public const ACTION = 'login';
    public const MESSAGE = 'Please confirm that you are not a robot.';
    private const WINDOW = 900; // seconds a failure is remembered

    public function __construct(
        protected readonly FormGuard $guard,
        protected readonly ?CacheItemPoolInterface $cache = null,
        protected readonly bool $google = false,
        protected readonly ?LoggerInterface $logger = null,
        protected readonly ?RequestStack $requests = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After login_throttling (2080) and before the password is checked (0).
            CheckPassportEvent::class => ['onCheckPassport', 300],
            LoginFailureEvent::class => ['onLoginFailure'],
            LoginSuccessEvent::class => ['onLoginSuccess'],
        ];
    }

    /** The captcha's gateway when a sign-in from this request must pass it; null otherwise. */
    public function required(?Request $request): ?string
    {
        $after = $this->guard->signInAfter();
        if ($this->google || $after <= 0 || null === $request?->getClientIp()) {
            return null;
        }
        $gateway = $this->guard->challengeGateway();

        return null !== $gateway && $this->failures($request->getClientIp()) >= $after ? $gateway : null;
    }

    public function failures(string $ip): int
    {
        if (null === $this->cache) {
            return 0;
        }
        $item = $this->cache->getItem($this->key($ip));

        return $item->isHit() ? (int) $item->get() : 0;
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $ip = $event->getRequest()->getClientIp();
        if (null === $this->cache || null === $ip) {
            return;
        }
        $item = $this->cache->getItem($this->key($ip));
        $this->cache->save($item->set(($item->isHit() ? (int) $item->get() : 0) + 1)->expiresAfter(self::WINDOW));
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $ip = $event->getRequest()->getClientIp();
        if (null !== $this->cache && null !== $ip) {
            $this->cache->deleteItem($this->key($ip));
        }
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requests?->getCurrentRequest();
        if (null === $request || LoginFormAuthenticator::LOGIN_ROUTE !== $request->attributes->get('_route') || !$request->isMethod('POST') || $event->getAuthenticator() instanceof RescueFormAuthenticator) {
            return;
        }
        if (null === $gateway = $this->required($request)) {
            return;
        }

        $challenge = $this->guard->getRegistry()->challenge($gateway);
        $field = $challenge->widget(self::ACTION)->field;
        $form = $request->request->all('_base_security_login') ?: $request->request->all('security_login') ?: [];
        $token = $request->request->get($field) ?? ($form[FormGuard::CHALLENGE_FIELD] ?? '');

        try {
            $verdict = $challenge->verify(new Attempt(\is_string($token) ? $token : '', $request->getClientIp(), self::ACTION));
        } catch (InvalidKeyException $e) {
            $this->logger?->error('Sign-in guard: the captcha refused the site\'s key: {message}', ['message' => $e->getMessage()]);

            return;
        } catch (ProviderException $e) {
            $this->logger?->warning('Sign-in guard: the captcha did not answer: {message}', ['message' => $e->getMessage()]);
            if (!$this->guard->rejectsUnreachable()) {
                return;
            }
            throw new CustomUserMessageAuthenticationException(self::MESSAGE);
        }
        if (!$verdict->passed) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE);
        }
    }

    private function key(string $ip): string
    {
        return 'base.sign_in_failures.'.hash('sha256', $ip);
    }
}
