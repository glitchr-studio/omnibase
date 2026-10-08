<?php

namespace Base\Event;

use Base\Entity\Signature\Envelope;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An envelope moved (Base\Service\Signatures): completed, declined,
 * expired, canceled - or a signer signed. The application answers on what
 * it is about: a quote accepted once signed, a lease in force.
 *
 *     #[AsEventListener(event: SignatureEvent::COMPLETED)]
 *     public function onSigned(SignatureEvent $event): void
 *     {
 *         if (Quote::class === $event->getEnvelope()->getSubjectClass()) { ... }
 *     }
 */
final class SignatureEvent extends Event
{
    public const COMPLETED = 'base.signature.completed';
    public const DECLINED = 'base.signature.declined';
    public const EXPIRED = 'base.signature.expired';
    public const CANCELED = 'base.signature.canceled';
    /** A signer signed; the others may not have yet. */
    public const SIGNED = 'base.signature.signed';

    public function __construct(private readonly Envelope $envelope, private readonly ?object $subject = null, private readonly ?string $signer = null)
    {
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    /** The entity it is about, when it still exists. */
    public function getSubject(): ?object
    {
        return $this->subject;
    }

    /** For SIGNED: the signer's key. */
    public function getSigner(): ?string
    {
        return $this->signer;
    }
}
