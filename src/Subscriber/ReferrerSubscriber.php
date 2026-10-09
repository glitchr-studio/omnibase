<?php

namespace Base\Subscriber;

use Base\Routing\AdvancedRouterInterface;
use Base\Service\ParameterBagInterface;
use Base\Service\ReferrerInterface;
use Base\Service\SpeculativeRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Where a visitor comes back to after signing in: the last page they read.
 *
 * Only a page is remembered - a GET answered 2xx with an HTML document,
 * asked as a document (Sec-Fetch-Dest: document or iframe) or by
 * transparentjs's swap (an XHR whose answer is a whole HTML page, as
 * omnibase's PageViewSubscriber counts it). An image, a stylesheet, a feed,
 * a JSON or an HTML fragment fetched by a script is not: an avatar served by
 * a controller used to be the last thing asked before the sign-in, and the
 * sign-in sent the visitor to that SVG (seen on chapaland). Nor is a
 * speculative fetch, a route of base.access_restriction.route_exceptions
 * (the sign-in pages themselves...) or a secured page.
 */
class ReferrerSubscriber implements EventSubscriberInterface
{
    /** The fetch destinations of a page the visitor reads; "empty" is a script's fetch, judged by its answer. */
    private const PAGE_DESTINATIONS = ['document', 'iframe', 'frame', 'embed', 'object', 'empty'];

    public function __construct(
        protected ReferrerInterface $referrer,
        protected AdvancedRouterInterface $router,
        protected ParameterBagInterface $parameterBag,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => [['onKernelRequest', 4]],
            ResponseEvent::class => [['onKernelResponse', 0]],
        ];
    }

    /**
     * @param $event
     * @return mixed
     */
    public function getCurrentRouteName($event)
    {
        return $event->getRequest()->attributes->get('_route');
    }

    public function isException(?string $route): bool
    {
        if (null === $route || '' === $route) {
            return false;
        }

        foreach ($this->parameterBag->get('base.access_restriction.route_exceptions') ?? [] as $pattern) {
            if (preg_match($pattern, $route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this request and its answer are a page the visitor read.
     */
    public static function isPageNavigation(Request $request, Response $response): bool
    {
        if (!$request->isMethod('GET') || SpeculativeRequest::is($request)) {
            return false;
        }
        if (!$response->isSuccessful() || !str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return false;
        }

        $destination = strtolower((string) $request->headers->get('Sec-Fetch-Dest', ''));
        if ('' !== $destination && !\in_array($destination, self::PAGE_DESTINATIONS, true)) {
            return false;   // an image, a script, a stylesheet, a font...
        }

        // A script's fetch (or a browser that says nothing): a page only when its answer is a whole document,
        // as transparentjs's swap gets - not a fragment of HTML for a widget.
        $scripted = 'empty' === $destination || $request->isXmlHttpRequest();
        if ($scripted || '' === $destination) {
            $accept = strtolower((string) $request->headers->get('Accept', ''));
            if ('' !== $accept && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*')) {
                return false;
            }
            if ($scripted) {
                $content = $response->getContent();

                return \is_string($content) && false !== stripos(substr($content, 0, 2048), '<html');
            }
        }

        return true;
    }

    public function onKernelRequest(RequestEvent $event)
    {
        if (!$this->isRecordable($event)) {
            return;
        }

        $referrerRoute = $this->router->getRouteName(strval($this->referrer));
        if ($this->isException($referrerRoute)) {
            $this->referrer->clear();
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->isRecordable($event)) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isException($this->getCurrentRouteName($event)) || $this->router->isSecured($request)) {
            return;
        }
        if (!self::isPageNavigation($request, $event->getResponse())) {
            return;
        }

        $this->referrer->setUrl($request->getUri());
    }

    private function isRecordable(KernelEvent $event): bool
    {
        return $event->isMainRequest()
            && !$this->router->isWdt()
            && !$this->router->isUX()
            && !$this->router->isAPI()
            && $event->getRequest()->hasSession();
    }
}
