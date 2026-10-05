<?php

namespace Base\Subscriber;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bridge\Twig\Mime\WrappedTemplatedEmail;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Twig\Environment;

/**
 * A TemplatedEmail's subject written in its template:
 *
 *     {% block subject %}{{ 'booking.email.subject'|trans({date: date}) }}{% endblock %}
 *
 * omnibase's e-mail templates name their subject that way, and a
 * notification reads it. A plain TemplatedEmail sent through Symfony's
 * mailer did not: the block was rendered in the body and the message left
 * without a subject. Here the block is rendered with the e-mail's context
 * and becomes the subject, before the body is rendered.
 *
 * A subject set in PHP (->subject('...')) is kept: it is also handed to the
 * template as the `subject` variable, which omnibase's frame prints instead
 * of the block.
 */
class TemplatedEmailSubjectSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Before Symfony's MessageListener (0), which renders the body.
        return [MessageEvent::class => ['onMessage', 16]];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof TemplatedEmail || null === ($template = $message->getHtmlTemplate() ?? $message->getTextTemplate())) {
            return;
        }

        $context = $message->getContext();
        if (null !== $message->getSubject() && '' !== $message->getSubject()) {
            if (!\array_key_exists('subject', $context)) {
                $message->context($context + ['subject' => $message->getSubject()]);
            }

            return;
        }

        try {
            $loaded = $this->twig->load($template);
            $vars = $context + ['email' => new WrappedTemplatedEmail($this->twig, $message)];
            if (!$loaded->hasBlock('subject', $vars)) {
                return;
            }

            $subject = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($loaded->renderBlock('subject', $vars)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
        } catch (\Throwable) {
            return;     // the body's own rendering will say what is wrong with the template
        }

        if ('' !== $subject) {
            $message->subject($subject);
        }
    }
}
