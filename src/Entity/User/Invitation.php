<?php

namespace Base\Entity\User;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * An e-mail that opens something: whoever follows its link before it runs
 * out gets an account (or signs in with theirs), attached to what it was
 * made for. Only the token's hash is kept; the token itself is sent, never
 * stored (Base\Service\Invitations).
 *
 * A mapped superclass: each bundle keeps its own table and says what an
 * invitation opens (omnibase/estate's Invitation: an associate, a tenant, a
 * contractor, a member of the management, in estate_invitation).
 */
#[ORM\MappedSuperclass]
abstract class Invitation
{
    #[ORM\Column(length: 180)]
    protected string $email;

    #[ORM\Column(length: 160)]
    protected string $name = '';

    #[ORM\Column(length: 64, unique: true)]
    protected string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $invitedBy = null;

    public function __construct(string $email, string $name, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->email = mb_strtolower(trim($email));
        $this->name = $name;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    abstract public function getId(): ?int;

    public function __toString(): string { return $this->email; }
    public function getEmail(): string { return $this->email; }
    public function getName(): string { return $this->name; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function accept(): static { $this->acceptedAt = new \DateTimeImmutable(); return $this; }
    public function getInvitedBy(): ?User { return $this->invitedBy; }
    public function setInvitedBy(?User $invitedBy): static { $this->invitedBy = $invitedBy; return $this; }
    public function isOpen(): bool { return null === $this->acceptedAt && $this->expiresAt > new \DateTimeImmutable(); }
}
