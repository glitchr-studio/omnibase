<?php

namespace Tests\Base\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Tests\Base\Http\HttpTestTrait;

/**
 * The back office's sign-in (/rescue) says why the last attempt was refused,
 * as the front one does: the failure handler leaves the reason in the
 * session, and the page passed `error => null` to its template - wrong
 * identifiers and too many attempts alike ended on a silent form.
 */
class RescueErrorHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    protected function setUp(): void
    {
        $this->bootHost();
    }

    private function rescueAfter(AuthenticationException $refused): string
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $refused);
        $session->save();

        $request = Request::create('/rescue');
        $request->cookies->set($session->getName(), $session->getId());
        $response = static::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }

    public function testWrongIdentifiersAreSaid(): void
    {
        $translator = static::getContainer()->get('translator');
        $html = $this->rescueAfter(new BadCredentialsException());

        self::assertStringContainsString(htmlspecialchars($translator->trans('Invalid credentials.', [], 'security'), \ENT_QUOTES), $html);
    }

    public function testTooManyAttemptsAreSaid(): void
    {
        $translator = static::getContainer()->get('translator');
        $refused = new TooManyLoginAttemptsAuthenticationException(3);
        $html = $this->rescueAfter($refused);

        self::assertStringContainsString(htmlspecialchars($translator->trans($refused->getMessageKey(), $refused->getMessageData(), 'security'), \ENT_QUOTES), $html);
    }
}
