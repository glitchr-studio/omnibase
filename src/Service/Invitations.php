<?php

namespace Base\Service;

use Base\Entity\User;
use Base\Entity\User\Invitation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Invitation by token: nobody signs up, someone is invited. The token is
 * random, sent once and never stored - only its SHA-256 is kept, so a
 * leaked table opens nothing. Following the link creates the account (the
 * link proved the address) or attaches the one that exists.
 *
 * A bundle extends it to say what an invitation opens and what accepting
 * does (omnibase/estate's Invitations); the token, the lookup, the expiry and
 * the account are here.
 */
class Invitations
{
    public const TOKEN_BYTES = 24;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly string $userClass = 'App\Entity\User',
    ) {
    }

    /** A new token: 48 hexadecimal characters. */
    public static function token(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function expiry(int $days): \DateTimeImmutable
    {
        return new \DateTimeImmutable('+'.$days.' days');
    }

    /** Saved, its token's hash only. */
    public function save(Invitation $invitation): Invitation
    {
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        return $invitation;
    }

    /**
     * The open invitation a token belongs to; null once accepted or run out.
     *
     * @template T of Invitation
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function findOpen(string $class, string $token): ?Invitation
    {
        $invitation = $this->entityManager->getRepository($class)->findOneBy(['tokenHash' => self::hash($token)]);

        return $invitation?->isOpen() ? $invitation : null;
    }

    /** Marked accepted. What it opens is the extending bundle's business. */
    public function markAccepted(Invitation $invitation): void
    {
        $invitation->accept();
        $this->entityManager->flush();
    }

    /** A new account for an invitation's e-mail, verified: the link proved the address. */
    public function createUser(Invitation $invitation, string $username, string $plainPassword): User
    {
        /** @var User $user */
        $user = new ($this->userClass)();
        if (method_exists($user, 'setUsername')) {
            $user->setUsername($username);
        }
        $user->setEmail($invitation->getEmail());
        $user->setPlainPassword($plainPassword);
        $user->setRoles(['ROLE_USER']);
        $user->verify();
        if (method_exists($user, 'approve')) {
            $user->approve(true);
        }
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /** The account that already has the invitation's e-mail, if any. */
    public function existingUser(Invitation $invitation): ?User
    {
        return $this->entityManager->getRepository($this->userClass)->findOneBy(['email' => $invitation->getEmail()]);
    }
}
