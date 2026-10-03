<?php

namespace Tests\Base\Subscriber;

use Base\Subscriber\FlashBagSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Regression coverage for the "session started on every response" bug: the
 * subscriber used to call getSession() before checking the response type,
 * starting a session for every plain HTML response even though flash-bag
 * injection only ever applies to JsonResponse. That single call is enough to
 * mint a session cookie for anonymous visitors, defeating any cache layer
 * keyed on "no session cookie present".
 */
class FlashBagSubscriberTest extends TestCase
{
    private function makeEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    public function testNonJsonResponseNeverStartsASession(): void
    {
        $request = Request::create('/');
        $sessionRequested = false;
        $request->setSessionFactory(function () use (&$sessionRequested) {
            $sessionRequested = true;

            return new Session(new MockArraySessionStorage());
        });

        $response = new Response('<html></html>');

        (new FlashBagSubscriber())->onKernelResponse($this->makeEvent($request, $response));

        $this->assertFalse($sessionRequested, 'getSession() must not be called for a non-JSON response.');
    }

    public function testJsonResponseWithoutFlashesIsUntouched(): void
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = new JsonResponse(['response' => 'ok']);

        (new FlashBagSubscriber())->onKernelResponse($this->makeEvent($request, $response));

        $this->assertSame(['response' => 'ok'], json_decode($response->getContent(), true));
    }

    public function testJsonResponseGetsPendingFlashesInjected(): void
    {
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $session->getBag('flashes'); // registers the FlashBag under Session's default name
        /** @var FlashBag $flashBag */
        $flashBag = $session->getFlashBag();
        $flashBag->add('success', 'Saved.');
        $request->setSession($session);
        // A returning visitor: the subscriber reads only a session the
        // request came back with (Request::hasPreviousSession()).
        $request->cookies->set($session->getName(), 'returning');

        $response = new JsonResponse(['response' => 'ok']);

        (new FlashBagSubscriber())->onKernelResponse($this->makeEvent($request, $response));

        $data = json_decode($response->getContent(), true);
        $this->assertSame(['success' => ['Saved.']], $data['flashbag']);
        $this->assertSame('ok', $data['response']);
    }

    public function testNonArrayJsonPayloadIsWrappedBeforeInjectingFlashes(): void
    {
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $session->getBag('flashes');
        $session->getFlashBag()->add('info', 'Heads up.');
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'returning');

        $response = new JsonResponse('a bare string payload');

        (new FlashBagSubscriber())->onKernelResponse($this->makeEvent($request, $response));

        $data = json_decode($response->getContent(), true);
        $this->assertSame('a bare string payload', $data['response']);
        $this->assertSame(['info' => ['Heads up.']], $data['flashbag']);
    }

    public function testNoSessionCookieMeansNoFlashesRead(): void
    {
        // Without a session the request came back with, reading the flashes
        // would start one for every API call.
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('success', 'Saved.');
        $request->setSession($session);

        $response = new JsonResponse(['response' => 'ok']);

        (new FlashBagSubscriber())->onKernelResponse($this->makeEvent($request, $response));

        $this->assertSame(['response' => 'ok'], json_decode($response->getContent(), true));
        $this->assertSame(['success' => ['Saved.']], $session->getFlashBag()->peekAll());
    }

    public function testAPubliclyCacheableResponseNeverCarriesFlashes(): void
    {
        // A shared cache would keep one member's flash and hand it to others.
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('success', 'Bravo.');
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'returning');

        $response = new JsonResponse(['response' => 'ok']);
        $response->setPublic();

        (new FlashBagSubscriber())->onKernelResponse($this->makeEvent($request, $response));

        $this->assertSame(['response' => 'ok'], json_decode($response->getContent(), true));
        $this->assertSame(['success' => ['Bravo.']], $session->getFlashBag()->peekAll());
    }
}
