<?php

namespace Base\DatabaseSubscriber;

use Base\Demo\DemoAccount;
use Base\Demo\DemoAccountRegistry;
use Base\Entity\User;
use Base\Enum\UserState;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A demonstration account stays what its declaration says, whoever asks and
 * through whichever form (registered in the `demo` environment only): its
 * identifier, its address, its password, its second factor are put back
 * before anything is written, it is not disabled, it is not deleted.
 *
 * The pages of omnibase that change these refuse first, with a message
 * (SecurityPolicy::isDemoAccount()); this is what holds for the others - the
 * application's own "my account" page, the back office's users. A visitor
 * signed in as "docteur" who could change that password would lock the next
 * one out until the night's reset.
 *
 * Only during a request: the fixtures and `demo:reset` write these accounts
 * from the console.
 */
class DemoAccountLockSubscriber
{
    /** The columns put back as they were (the identifier's and the password's are handled apart). */
    private const LOCKED_FIELDS = ['email', 'secret', 'backupCodes', 'emailAuthEnabled'];

    private bool $told = false;

    public function __construct(
        private readonly DemoAccountRegistry $accounts,
        private readonly RequestStack $requestStack,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function preFlush(PreFlushEventArgs $event): void
    {
        if (null === $this->requestStack->getMainRequest()) {
            return;
        }

        $manager = $event->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof User && $this->declarationOf($manager, $entity)) {
                throw new AccessDeniedHttpException($this->message('deleted'));
            }
        }

        $refused = false;
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof User && ($account = $this->declarationOf($manager, $entity))) {
                    $refused = $this->restore($manager, $entity, $account) || $refused;
                }
            }
        }

        if ($refused) {
            $this->tell();
        }
    }

    /** The declaration of this account as the database knows it: by the identifier it was loaded with, not the one just typed. */
    private function declarationOf(EntityManagerInterface $manager, User $user): ?DemoAccount
    {
        $original = $manager->getUnitOfWork()->getOriginalEntityData($user);
        if (!$original) {
            return null; // not written yet: an account being created is nobody's demonstration account
        }

        return $this->accounts->get((string) ($original[$user::getUserIdentifierField()] ?? ''));
    }

    /** Puts back what may not change; true when something had. */
    private function restore(EntityManagerInterface $manager, User $user, DemoAccount $account): bool
    {
        $metadata = $manager->getClassMetadata($user::class);
        $original = $manager->getUnitOfWork()->getOriginalEntityData($user);
        $refused = false;

        foreach (array_unique([$user::getUserIdentifierField(), ...self::LOCKED_FIELDS]) as $field) {
            if (!$metadata->hasField($field) || !\array_key_exists($field, $original)) {
                continue;
            }
            if ($metadata->getFieldValue($user, $field) != $original[$field]) {
                $metadata->setFieldValue($user, $field, $original[$field]);
                $refused = true;
            }
        }

        // A new password typed in a form (hashed when the row is written, #[Hashify])...
        $plain = $user->getPlainPassword();
        if (null !== $plain && '' !== $plain) {
            $user->erasePlainPassword();
            $refused = $refused || $plain !== $account->getPassword();
        }

        // ...or a hash set directly. The same password hashed again (a stronger algorithm, at sign-in) is no change.
        if (\array_key_exists('password', $original) && $user->getPassword() !== $original['password']) {
            $hash = (string) $user->getPassword();
            if ('' === $hash || !$this->hashers->getPasswordHasher($user)->verify($hash, $account->getPassword())) {
                $metadata->setFieldValue($user, 'password', $original['password']);
                $refused = true;
            }
        }

        // Disabled ("delete my account") while it was not.
        $states = (array) ($original['states'] ?? []);
        if (\in_array(UserState::ENABLED, $states) && $user->isDisabled()) {
            $user->enable();
            $refused = true;
        }

        return $refused;
    }

    private function tell(): void
    {
        if ($this->told) {
            return;
        }

        $request = $this->requestStack->getMainRequest();
        if (!$request?->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->add('warning', $this->message('locked'));
        $this->told = true;
    }

    private function message(string $key): string
    {
        return $this->translator?->trans('@notifications.demo.'.$key) ?? 'A demonstration account cannot be changed.';
    }

    /** One message a request: the service lives across requests in a worker. */
    public function reset(): void
    {
        $this->told = false;
    }
}
