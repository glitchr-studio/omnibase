<?php

namespace Base\Subscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Two of the demonstration's safeguards (registered in the `demo`
 * environment only, config/services/demo.php):
 *
 *  - no page of a demonstration is indexed: `X-Robots-Tag: noindex, nofollow`
 *    on every response;
 *  - no e-mail leaves it. Every message is refused where the mailer hands it
 *    over - as it is queued and as it is sent - whatever transport MAILER_DSN
 *    names: a visitor signed in as "patient" types any address in a form,
 *    and a demonstration must not write to it. What would have gone is
 *    logged (recipients and subject, not the body).
 */
class DemoSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -1024],
            // Before every other listener of the mailer: nothing has to be prepared for a message that goes nowhere.
            MessageEvent::class => ['onMessage', 1024],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }

    public function onMessage(MessageEvent $event): void
    {
        if ($event->isRejected()) {
            return;
        }

        $message = $event->getMessage();
        $this->logger?->info('Demonstration: e-mail not sent.', [
            'to' => array_map(static fn ($address): string => $address->getAddress(), $event->getEnvelope()->getRecipients()),
            'subject' => $message instanceof Email ? $message->getSubject() : null,
            'queued' => $event->isQueued(),
        ]);

        $event->reject();
    }
}
