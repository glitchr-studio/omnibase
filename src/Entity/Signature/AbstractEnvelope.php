<?php

namespace Base\Entity\Signature;

use Doctrine\ORM\Mapping as ORM;

/**
 * What an envelope keeps (Base\Entity\Signature\Envelope): what it is
 * about - any entity, by its class and id -, the glitchr/omnisign gateway
 * it went through and that gateway's reference, where it stands, its
 * signers, and the signed document and its evidence once kept in the
 * private storage. A mapped superclass: no table of its own; Envelope is
 * an entity only when glitchr/omnisign is installed.
 */
#[ORM\MappedSuperclass]
abstract class AbstractEnvelope
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELED = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /** The entity it is about: its class (Doctrine's name of it) and its id. */
    #[ORM\Column(type: 'string', length: 255)]
    protected string $subjectClass;

    #[ORM\Column(type: 'string', length: 64)]
    protected string $subjectId;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $title;

    /** The omnisign gateway's name (omnisign.gateways.<name>). */
    #[ORM\Column(type: 'string', length: 32)]
    protected string $gateway;

    /** The gateway's reference of the envelope: a signature request, an envelope, a submission. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $reference = null;

    /** Omnisign\Model\Status's value: draft, sent, completed, declined, expired, canceled. */
    #[ORM\Column(type: 'string', length: 16)]
    protected string $status = self::STATUS_DRAFT;

    /**
     * What it takes to ask the gateway again: the signers (key, name, e-mail, their state there), the
     * documents' keys and file names, the gateway's own references and data.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    protected array $envelope = [];

    /** The signed document, in the private storage (base.signatures.storage): its path there. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $signedFile = null;

    /** The evidence of its signing (audit trail, certificate) in the private storage. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $evidenceFile = null;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $completedAt = null;

    public function __construct(string $subjectClass, string $subjectId, string $title, string $gateway)
    {
        $this->subjectClass = $subjectClass;
        $this->subjectId = $subjectId;
        $this->title = $title;
        $this->gateway = $gateway;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubjectClass(): string
    {
        return $this->subjectClass;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isCompleted(): bool
    {
        return self::STATUS_COMPLETED === $this->status;
    }

    /** Over: nothing more will happen to it (completed, declined, expired, canceled). */
    public function isOver(): bool
    {
        return \in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_DECLINED, self::STATUS_EXPIRED, self::STATUS_CANCELED], true);
    }

    /** @return array<string, mixed> */
    public function getEnvelope(): array
    {
        return $this->envelope;
    }

    /** @return list<array{key: string, name: string, email: string, status: string, signedAt: string|null}> */
    public function getSigners(): array
    {
        $signers = [];
        foreach ($this->envelope['signers'] ?? [] as $signer) {
            $state = $this->envelope['states'][$signer['key']] ?? [];
            $signers[] = ['key' => $signer['key'], 'name' => $signer['name'], 'email' => $signer['email'], 'status' => $state['status'] ?? 'waiting', 'signedAt' => $state['signedAt'] ?? null];
        }

        return $signers;
    }

    /**
     * Where it stands, as the gateway answered.
     *
     * @param array<string, mixed> $envelope
     */
    public function update(?string $reference, string $status, array $envelope, ?\DateTimeImmutable $completedAt = null): static
    {
        $this->reference = $reference ?? $this->reference;
        $this->status = $status;
        $this->envelope = $envelope;
        $this->completedAt ??= $completedAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSignedFile(): ?string
    {
        return $this->signedFile;
    }

    public function getEvidenceFile(): ?string
    {
        return $this->evidenceFile;
    }

    /** The signed document and its evidence, kept: their paths in the private storage. */
    public function keep(?string $signedFile, ?string $evidenceFile): static
    {
        $this->signedFile = $signedFile;
        $this->evidenceFile = $evidenceFile;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
