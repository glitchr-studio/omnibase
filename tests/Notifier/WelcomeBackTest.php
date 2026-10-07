<?php

namespace Tests\Base\Notifier;

use Base\Entity\User\Token;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Base\Http\HttpTestTrait;

/**
 * The "welcome back" e-mail and message name the account: "Bon retour, {0} !"
 * was sent as it is written, its parameter never given.
 */
class WelcomeBackTest extends KernelTestCase
{
    use HttpTestTrait;

    protected function setUp(): void
    {
        $this->bootHost();
    }

    protected function tearDown(): void
    {
        $this->removeUsers();
        parent::tearDown();
    }

    public function testTheSubjectAndTheMessageNameTheAccount(): void
    {
        $request = Request::create('/');
        $request->setSession(static::getContainer()->get('session.factory')->createSession());
        static::getContainer()->get('request_stack')->push($request);

        $user = $this->createUser();
        $token = new Token('welcome-back', 3600);
        $token->setUser($user);

        foreach (['fr', 'en'] as $locale) {
            static::getContainer()->get('translator')->setLocale($locale);
            $notification = static::getContainer()->get('base.notifier')->userWelcomeBack($user, $token);

            $subject = $notification->getHtmlParameters()['subject'];
            $this->assertStringNotContainsString('{0}', $subject, "$locale: the subject is filled");
            $this->assertStringContainsString((string) $user, $subject);
            $this->assertStringNotContainsString('{0}', (string) $notification->getContent(), "$locale: so is the message");
        }
    }
}
