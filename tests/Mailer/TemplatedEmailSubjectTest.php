<?php

namespace Tests\Base\Mailer;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A plain TemplatedEmail on omnibase's e-mail frame: the template's
 * {% block subject %} is the message's subject (it was sent without one),
 * and the frame renders with nothing but the template's blocks - no
 * notification context, no logo.
 */
class TemplatedEmailSubjectTest extends KernelTestCase
{
    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires a host application (the omnibase harness).');
        }

        self::bootKernel();
        static::getContainer()->get('request_stack')->push(Request::create('/'));
        static::getContainer()->get('twig')->getLoader()->addPath(\dirname(__DIR__).'/Fixtures/Mail', 'MailFixtures');
    }

    private function email(): TemplatedEmail
    {
        return (new TemplatedEmail())->from('cabinet@example.org')->to('client@example.org')
            ->htmlTemplate('@MailFixtures/appointment.html.twig')
            ->context(['day' => 'Monday', 'hour' => '9:30']);
    }

    public function testTheSubjectBlockIsTheSubject(): void
    {
        $email = $this->email();
        static::getContainer()->get('mailer')->send($email);

        $sent = self::getMailerMessages();
        $this->assertCount(1, $sent);
        $this->assertSame('Your appointment of Monday & after', $sent[0]->getSubject());

        $html = (string) $sent[0]->getHtmlBody();
        $this->assertStringContainsString('See you at 9:30.', $html);
        $this->assertStringContainsString('Your appointment of Monday', $html, 'the frame prints it as its lead');
        $this->assertStringNotContainsString('<img', $html, 'no logo is set: none is asked for');
    }

    public function testASubjectSetInPhpIsKept(): void
    {
        static::getContainer()->get('mailer')->send($this->email()->subject('Rappel'));

        $sent = self::getMailerMessages();
        $this->assertSame('Rappel', $sent[0]->getSubject());
        $this->assertMatchesRegularExpression('#<title>\s*Rappel\s*</title>#', (string) $sent[0]->getHtmlBody());
    }

    public function testTheFrameRendersWithItsBlocksAlone(): void
    {
        $html = static::getContainer()->get('twig')->render('@MailFixtures/appointment.html.twig', ['day' => 'Monday', 'hour' => '9:30']);

        $this->assertStringContainsString('See you at 9:30.', $html);
    }

    public function testTheLogoIsPrintedWhenThereIsOne(): void
    {
        $html = static::getContainer()->get('twig')->render('@MailFixtures/appointment.html.twig', ['day' => 'Monday', 'hour' => '9:30', 'logo' => 'bundles/base/logo.png']);

        $this->assertStringContainsString('<img', $html);
    }
}
