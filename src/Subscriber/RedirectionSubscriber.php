<?php

namespace Base\Subscriber;

use Base\Service\Redirections;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A page that is not found is looked up in the redirections
 * (Base\Entity\Layout\Redirection) before the 404 is answered: the old
 * addresses of a site taken over lead to its new pages.
 *
 * Only then - no route matched, or a controller found no record: a page the
 * site answers is never redirected, and costs no query. Ahead of the
 * listeners that log and render the error (priority 16), so a redirected
 * address is not reported as an error.
 */
class RedirectionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Redirections $redirections)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 16]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        if ($response = $this->redirections->responseFor($event->getRequest())) {
            $event->allowCustomResponseCode(); // 301 or 302, not the error's 404
            $event->setResponse($response);
        }
    }
}
