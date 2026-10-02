<?php

namespace Base\Subscriber;

use Base\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * An account made with a password it did not choose (user:create) carries a "change-password"
 * token: until its owner has chosen one, every page sends them to security_changePassword.
 *
 * As narrow as SecurityEnrolmentSubscriber, for the same reason: only a plain top-level GET of
 * an HTML page is turned back, never the pages needed to answer - the change itself, logging
 * out, the profiler.
 */
class PasswordChangeSubscriber implements EventSubscriberInterface
{
    public const TOKEN = 'change-password';

    private const EXEMPT_PREFIXES = [
        '/change-password',
        '/logout',
        '/login',
        '/_',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the enrolment prompt (4): a password first, then a second factor.
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('GET') || $request->isXmlHttpRequest()) {
            return;
        }

        $formats = $request->getAcceptableContentTypes();
        if ([] !== $formats && !in_array('text/html', $formats, true) && !in_array('*/*', $formats, true)) {
            return;
        }

        $path = $request->getPathInfo();
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->getValidToken(self::TOKEN)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('security_changePassword')));
    }
}
