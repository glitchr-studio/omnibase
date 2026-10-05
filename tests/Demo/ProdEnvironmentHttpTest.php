<?php

namespace Tests\Base\Demo;

use Base\Console\Command\DemoResetCommand;
use Base\Service\SecurityPolicy;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Base\Demo\Fixtures\TestDemoAccounts;

/**
 * The same application, the same declared accounts, in `prod`: none of the
 * demonstration is there - no panel, no route to sign in at a click, no
 * banner, no header, no lifted second factor, and a demo:reset that refuses.
 */
class ProdEnvironmentHttpTest extends DemoKernelTestCase
{
    protected function setUp(): void
    {
        $this->bootIn('prod');
        $this->loadDemoAccounts();
        $this->entityManager()->clear();
    }

    public function testTheSignInPageOffersNoDemonstrationAccount(): void
    {
        $response = $this->browse('/login');
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('data-demo-accounts', $html);
        $this->assertStringNotContainsString('Staff of the practice', $html);
        $this->assertStringNotContainsString('/login/demo', $html);
        $this->assertFalse($response->headers->has('X-Robots-Tag'), 'a production site is indexed');
    }

    public function testNothingSignsInAtAClick(): void
    {
        $response = $this->browse('/login/demo', 'POST', ['account' => TestDemoAccounts::MEMBER, '_token' => 'whatever']);
        $this->assertContains($response->getStatusCode(), [404, 405], 'the route does not exist');

        $this->assertFalse(static::getContainer()->has('Base\\Controller\\DemoController'));
        $this->assertFalse(static::getContainer()->has('Base\\Subscriber\\DemoSubscriber'));
        $this->assertFalse(static::getContainer()->has('Base\\Subscriber\\DemoSuperAdminSubscriber'));
        $this->assertFalse(static::getContainer()->has('Base\\DatabaseSubscriber\\DemoAccountLockSubscriber'));

        $this->browse('/settings');
        $this->assertNull(static::getContainer()->get('security.token_storage')->getToken()?->getUser());
    }

    public function testTheBannerPrintsNothing(): void
    {
        $twig = static::getContainer()->get('twig');

        $this->assertSame('', trim($twig->render('@Base/demo/_banner.html.twig')));
        $this->assertSame('', trim($twig->render('@Base/demo/_accounts.html.twig')));
    }

    public function testADeclaredAccountIsAnOrdinaryOne(): void
    {
        $policy = static::getContainer()->get(SecurityPolicy::class);
        $em = $this->entityManager();
        $staff = $em->getRepository('App\\Entity\\User')->findOneBy(['email' => TestDemoAccounts::STAFF]);

        $this->assertFalse($policy->isDemoAccount($staff));
        $this->assertTrue($policy->canChangeCredentials($staff));
        $this->assertTrue($policy->needsEnrolment($staff), 'the second factor of its role is asked as of anyone');

        // Its password changes as anyone's does, during a request too.
        $hash = $staff->getPassword();
        $this->duringARequest('/mon-compte', function () use ($staff, $em): void {
            $staff->setPlainPassword('A-new-password-nobody-else-knows-1!');
            $em->flush();
        });
        $em->clear();
        $this->assertNotSame($hash, $em->find('App\\Entity\\User', $staff->getId())->getPassword());
    }

    public function testDemoResetRefuses(): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $command = $application->find('demo:reset');
        $this->assertInstanceOf(DemoResetCommand::class, $command instanceof LazyCommand ? $command->getCommand() : $command, 'registered everywhere, so that it can say why it refuses');

        $tester = new CommandTester($command);
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('runs in the "demo" environment only', $tester->getDisplay());
    }
}
