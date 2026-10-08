<?php

namespace Base\Service;

use Base\Entity\Signature\Envelope;
use Base\Event\SignatureEvent;
use Doctrine\ORM\EntityManagerInterface;
use Omnisign\Exception\InvalidNotificationException;
use Omnisign\GatewayInterface;
use Omnisign\Model\Document;
use Omnisign\Model\Envelope as Request;
use Omnisign\Model\Event;
use Omnisign\Model\File;
use Omnisign\Model\Level;
use Omnisign\Model\Signer;
use Omnisign\Model\SignerState;
use Omnisign\Model\SignerStatus;
use Omnisign\Model\Status;
use Omnisign\Registry;
use Omnisign\Request\Download;
use Omnisign\Request\Notify;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Documents signed through glitchr/omnisign, for any entity of the
 * application: an envelope sent (send()), signed by e-mail or in the
 * site's page (signingUrl()), followed (refresh(), or the provider's
 * webhook: notify()), and once completed its signed document and evidence
 * kept in the private storage (base.signatures.storage: the uploads', out
 * of public/). Each move is a SignatureEvent; the application answers on
 * what the envelope is about.
 *
 *     $envelope = $signatures->send($quote, new Omnisign\Model\Envelope('Devis D-42', [$document], [$client], $fields, embedded: true));
 *     return $this->redirect($signatures->signingUrl($envelope, 'client', $returnUrl));
 *     ...
 *     $signatures->refresh($envelope);          // on the way back; the webhook does it too
 *     $signatures->signed($envelope);           // the signed PDF, once completed
 *
 * Registered only when glitchr/omnisign is installed (its bundle gives the
 * gateways: omnisign.gateways.<name>); the entity is mapped only then too.
 */
class Signatures
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FlysystemInterface $flysystem,
        private readonly ?Registry $registry = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%base.signatures.gateway%')] private readonly ?string $gateway = null,
        #[Autowire('%base.signatures.storage%')] private readonly ?string $storage = null,
        #[Autowire('%base.uploader.storage%')] private readonly ?string $uploads = null,
    ) {
    }

    /** The gateway envelopes go through by default: base.signatures.gateway, or the only one configured. */
    public function gateway(?string $name = null): ?string
    {
        $names = $this->registry?->names() ?? [];
        $name ??= $this->gateway ?? (1 === \count($names) ? $names[0] : null);

        return null !== $name && \in_array($name, $names, true) ? $name : null;
    }

    public function isEnabled(?string $name = null): bool
    {
        return null !== $this->gateway($name);
    }

    /**
     * Sent to be signed, about $subject: created on the gateway and sent -
     * by the provider's e-mail, or kept for the site's page (embedded) -
     * and kept as an Envelope.
     */
    public function send(object $subject, Request $request, ?string $gateway = null): Envelope
    {
        $name = $this->gateway($gateway) ?? throw new \LogicException(sprintf('No omnisign gateway "%s" (omnisign.gateways, base.signatures.gateway).', $gateway ?? $this->gateway ?? ''));
        [$class, $id] = $this->identify($subject);
        $envelope = new Envelope($class, $id, $request->title, $name);
        $sent = $this->provider($envelope)->send($this->provider($envelope)->create($request));
        $this->apply($envelope, $sent, $request);
        $this->entityManager->persist($envelope);
        $this->entityManager->flush();

        return $envelope;
    }

    /** Where $signer signs in the site's page; asked again each time (the providers' links expire). */
    public function signingUrl(Envelope $envelope, string $signer, ?string $returnUrl = null): string
    {
        return $this->provider($envelope)->signingUrl($this->request($envelope), $signer, $returnUrl)->url;
    }

    /** Asks the provider where it stands; kept, its events dispatched, its files kept once completed. */
    public function refresh(Envelope $envelope): Envelope
    {
        if ($envelope->isOver() && ($envelope->getSignedFile() || !$envelope->isCompleted())) {
            return $envelope;
        }
        $this->apply($envelope, $this->provider($envelope)->fetch($this->request($envelope)));
        $this->entityManager->flush();

        return $envelope;
    }

    public function remind(Envelope $envelope, ?string $signer = null): void
    {
        $this->provider($envelope)->remind($this->request($envelope), $signer);
    }

    public function cancel(Envelope $envelope, ?string $reason = null): Envelope
    {
        $this->apply($envelope, $this->provider($envelope)->cancel($this->request($envelope), $reason));
        $this->entityManager->flush();

        return $envelope;
    }

    /**
     * A provider's callback: its signature checked by the gateway, the
     * envelope it names asked again and kept.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return Envelope|false|null the envelope updated; null when it names none of the site's; false when its signature does not hold
     */
    public function notify(string $gateway, string $body, array $headers): Envelope|false|null
    {
        if (null === $this->gateway($gateway) || !$this->registry->get($gateway)->supports(Notify::class)) {
            return null;
        }
        try {
            $notification = $this->registry->get($gateway)->notify($body, $headers);
        } catch (InvalidNotificationException) {
            return false;
        }
        $envelope = null === $notification->reference ? null : $this->entityManager->getRepository(Envelope::class)->findOneBy(['gateway' => $gateway, 'reference' => $notification->reference]);
        if (null === $envelope) {
            return null;
        }
        if (Event::OTHER !== $notification->event) {
            $this->refresh($envelope);
        }

        return $envelope;
    }

    /** @return list<Envelope> the envelopes about $subject, newest first */
    public function of(object $subject): array
    {
        [$class, $id] = $this->identify($subject);

        return $this->entityManager->getRepository(Envelope::class)->findBy(['subjectClass' => $class, 'subjectId' => $id], ['id' => 'DESC']);
    }

    /** The signed document, once completed and kept; null before. */
    public function signed(Envelope $envelope): ?string
    {
        return null === $envelope->getSignedFile() ? null : $this->flysystem->read($envelope->getSignedFile(), $this->storage());
    }

    /** The evidence of its signing (an audit trail, a certificate), once kept. */
    public function evidence(Envelope $envelope): ?string
    {
        return null === $envelope->getEvidenceFile() ? null : $this->flysystem->read($envelope->getEvidenceFile(), $this->storage());
    }

    /** The entity the envelope is about, when it still exists. */
    public function subject(Envelope $envelope): ?object
    {
        return class_exists($envelope->getSubjectClass()) ? $this->entityManager->find($envelope->getSubjectClass(), $envelope->getSubjectId()) : null;
    }

    private function provider(Envelope $envelope): GatewayInterface
    {
        return ($this->registry ?? throw new \LogicException('No omnisign registry: register Omnisign\Bridge\Symfony\OmnisignBundle.'))->get($envelope->getGateway());
    }

    /** What the gateway answered, on the envelope: its state, its signers', its events; its files once completed. */
    private function apply(Envelope $envelope, Request $answer, ?Request $sent = null): void
    {
        $before = $envelope->getStatus();
        $signedBefore = array_column(array_filter($envelope->getSigners(), static fn (array $s) => SignerStatus::SIGNED->value === $s['status']), 'key');
        $envelope->update($answer->reference, $answer->status->value, $this->serialize($answer, $sent ?? $this->request($envelope)), $answer->completedAt);

        if (Status::COMPLETED === $answer->status && null === $envelope->getSignedFile()) {
            $this->keep($envelope, $answer);
        }
        $subject = null;
        foreach ($envelope->getSigners() as $signer) {
            if (SignerStatus::SIGNED->value === $signer['status'] && !\in_array($signer['key'], $signedBefore, true)) {
                $this->dispatcher?->dispatch(new SignatureEvent($envelope, $subject ??= $this->subjectOrNull($envelope), $signer['key']), SignatureEvent::SIGNED);
            }
        }
        $event = [Status::COMPLETED->value => SignatureEvent::COMPLETED, Status::DECLINED->value => SignatureEvent::DECLINED, Status::EXPIRED->value => SignatureEvent::EXPIRED, Status::CANCELED->value => SignatureEvent::CANCELED][$envelope->getStatus()] ?? null;
        if (null !== $event && $before !== $envelope->getStatus()) {
            $this->dispatcher?->dispatch(new SignatureEvent($envelope, $subject ?? $this->subjectOrNull($envelope)), $event);
        }
    }

    /** The signed document and its evidence, written to the private storage. */
    private function keep(Envelope $envelope, Request $answer): void
    {
        $provider = $this->provider($envelope);
        if (!$provider->supports(Download::class)) {
            return;
        }
        $download = $provider->download($answer);
        $dir = sprintf('signatures/%s/%s', date('Y'), bin2hex(random_bytes(8)));
        $signed = null;
        foreach ($download->documents as $i => $file) {
            $path = $dir.'/'.self::filename($file->filename, 'document-'.($i + 1).'.pdf');
            $this->flysystem->write($path, $file->content, $this->storage());
            $signed ??= $path;
        }
        $evidence = null;
        if (null !== $download->evidence) {
            $evidence = $dir.'/'.self::filename($download->evidence->filename, 'evidence.pdf');
            $this->flysystem->write($evidence, $download->evidence->content, $this->storage());
        }
        $envelope->keep($signed, $evidence);
        $this->logger?->info('Signature: {title} completed on {gateway}, kept in {dir}.', ['title' => $envelope->getTitle(), 'gateway' => $envelope->getGateway(), 'dir' => $dir]);
    }

    /** base.signatures.storage, or the uploads' (base.uploader.storage: local.uploads, var/storage, out of public/). */
    private function storage(): string
    {
        return $this->storage ?: ($this->uploads ?: 'local.uploads');
    }

    private static function filename(string $name, string $default): string
    {
        $name = preg_replace('~[^A-Za-z0-9._-]+~', '-', basename($name)) ?? '';

        return '' === trim($name, '.-') ? $default : $name;
    }

    private function subjectOrNull(Envelope $envelope): ?object
    {
        try {
            return $this->subject($envelope);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{string, string} the entity's class (Doctrine's name) and id */
    private function identify(object $subject): array
    {
        $metadata = $this->entityManager->getClassMetadata($subject::class);
        $ids = $metadata->getIdentifierValues($subject);
        if ([] === $ids) {
            throw new \LogicException(sprintf('A %s without an id cannot be signed for: flush it first.', $subject::class));
        }

        return [$metadata->getName(), implode('-', array_map('strval', $ids))];
    }

    /**
     * The envelope as omnisign knows it, from what was kept: enough to ask the
     * provider again (its reference, the signers and their references, the
     * documents' keys and names) - not the documents' content.
     *
     * @return array<string, mixed>
     */
    private function serialize(Request $answer, Request $sent): array
    {
        return [
            'title' => $sent->title,
            'level' => $sent->level->value,
            'embedded' => $sent->embedded,
            'ordered' => $sent->ordered,
            'template' => $sent->template,
            'key' => $sent->key,
            'redirectUrl' => $sent->redirectUrl,
            'documents' => array_map(static fn (Document $d) => ['key' => $d->key, 'filename' => $d->file->filename, 'mimeType' => $d->file->mimeType, 'signable' => $d->signable], $sent->documents),
            'signers' => array_map(static fn (Signer $s) => ['key' => $s->key, 'name' => $s->name, 'email' => $s->email, 'phone' => $s->phone, 'locale' => $s->locale, 'order' => $s->order, 'authentication' => $s->authentication, 'role' => $s->role], $sent->signers),
            'states' => array_map(static fn (SignerState $s) => ['key' => $s->key, 'reference' => $s->reference, 'status' => $s->status->value, 'signedAt' => $s->signedAt?->format(\DATE_ATOM), 'reason' => $s->reason, 'link' => $s->link, 'linkExpiresAt' => $s->linkExpiresAt?->format(\DATE_ATOM)], $answer->states),
            'documentReferences' => $answer->documentReferences,
            'data' => self::scalars($answer->data),
        ];
    }

    private function request(Envelope $envelope): Request
    {
        $e = $envelope->getEnvelope();
        $states = [];
        foreach ($e['states'] ?? [] as $key => $s) {
            $states[$key] = new SignerState($s['key'], $s['reference'] ?? null, SignerStatus::tryFrom($s['status'] ?? '') ?? SignerStatus::WAITING, isset($s['signedAt']) ? new \DateTimeImmutable($s['signedAt']) : null, $s['link'] ?? null, isset($s['linkExpiresAt']) ? new \DateTimeImmutable($s['linkExpiresAt']) : null, $s['reason'] ?? null);
        }

        return new Request(
            $e['title'] ?? $envelope->getTitle(),
            array_map(static fn (array $d) => new Document($d['key'], new File('', $d['filename'], $d['mimeType'] ?? 'application/pdf'), $d['signable'] ?? true), $e['documents'] ?? []),
            array_map(static fn (array $s) => new Signer($s['key'], $s['name'], $s['email'], $s['phone'] ?? null, $s['locale'] ?? 'fr', $s['order'] ?? 1, $s['authentication'] ?? null, $s['role'] ?? null), $e['signers'] ?? []),
            level: Level::tryFrom($e['level'] ?? '') ?? Level::SIMPLE,
            ordered: $e['ordered'] ?? false,
            embedded: $e['embedded'] ?? false,
            redirectUrl: $e['redirectUrl'] ?? null,
            template: $e['template'] ?? null,
            key: $e['key'] ?? null,
            reference: $envelope->getReference(),
            status: Status::tryFrom($envelope->getStatus()) ?? Status::UNKNOWN,
            states: $states,
            documentReferences: $e['documentReferences'] ?? [],
            completedAt: $envelope->getCompletedAt(),
            data: $e['data'] ?? [],
        );
    }

    /** @return array<string, mixed> the provider's data, as JSON keeps it */
    private static function scalars(array $data): array
    {
        return json_decode((string) json_encode($data, \JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [];
    }
}
