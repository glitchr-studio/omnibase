<?php

namespace Base\Subscriber;

use Base\Demo\DemoAccountRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * The super-administrator in a demonstration (registered in the `demo`
 * environment only): never a demonstration account, and never signed in with
 * the password the fixtures gave it - a public one ("glitchr" / "glitchr").
 *
 * Its password is base.demo.superadmin_password, a secret
 * (DEMO_SUPERADMIN_PASSWORD by default): what is typed is compared with it,
 * the password stored in the database is not looked at. While the secret is
 * not set, an account whose roles reach ROLE_SUPERADMIN cannot sign in at
 * all, by any means.
 */
class DemoSuperAdminSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DemoAccountRegistry $accounts,
        #[Autowire('%base.demo.superadmin_password%')] private readonly ?string $password = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the user is known (UserProviderListener, 1024) and checked
        // (UserCheckerListener, 256), before the stored password is (CheckCredentialsListener, 0).
        return [CheckPassportEvent::class => ['onCheckPassport', 128]];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(UserBadge::class)) {
            return;
        }

        try {
            $user = $passport->getUser();
        } catch (AuthenticationException) {
            return; // nobody of that name: the sign-in fails as usual
        }

        if (!$this->accounts->isSuperAdmin($user)) {
            return;
        }

        if (null === $this->password || '' === $this->password) {
            throw new CustomUserMessageAuthenticationException('This account is not available in the demonstration.');
        }

        if (!$passport->hasBadge(PasswordCredentials::class)) {
            return; // a passkey or a "remember me" the super-administrator set up once signed in
        }

        /** @var PasswordCredentials $credentials */
        $credentials = $passport->getBadge(PasswordCredentials::class);
        if ($credentials->isResolved()) {
            return;
        }

        if (!hash_equals($this->password, $credentials->getPassword())) {
            throw new BadCredentialsException('The presented password is invalid.');
        }

        $credentials->markResolved();
    }
}
