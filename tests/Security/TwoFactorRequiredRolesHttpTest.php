<?php

namespace Tests\Base\Security;

use Base\Service\SecurityPolicy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Tests\Base\Http\HttpTestTrait;

/**
 * base.security.two_factor.required_roles, as a visitor meets it: an account
 * holding a required role and no second factor is sent to
 * /settings/security-required, which answers (it used to send back to
 * /settings: "mandatory" was for everyone or no one); another account is
 * left alone.
 */
class TwoFactorRequiredRolesHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    protected function tearDown(): void
    {
        $this->removeUsers();
        parent::tearDown();
    }

    private function requireOf(array $roles, bool $postpone = true): void
    {
        $this->bootHost();
        $container = static::getContainer();
        $container->set(SecurityPolicy::class, new SecurityPolicy(
            $container->get('setting_bag'),
            new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_USER']]),
            $roles,
            $postpone,
        ));
    }

    public function testAnAccountOfARequiredRoleIsSentToTheEnrolmentPageWhichAnswers(): void
    {
        $this->requireOf(['ROLE_ADMIN']);
        $staff = $this->createUser(['ROLE_ADMIN']);

        $response = $this->request('/profile', $staff);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/settings/security-required', (string) $response->headers->get('Location'));

        $response = $this->request('/settings/security-required', $staff);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('/settings/2fa', (string) $response->getContent());
        $this->assertStringContainsString('/settings/security-required/skip', (string) $response->getContent(), '"not now" is offered by default');
    }

    public function testAnotherAccountIsLeftAlone(): void
    {
        $this->requireOf(['ROLE_ADMIN']);
        $member = $this->createUser(['ROLE_USER']);

        $response = $this->request('/settings/security-required', $member);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/settings', (string) $response->headers->get('Location'));

        $response = $this->request('/settings', $member);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNoLaterWhenTheApplicationAllowsNone(): void
    {
        $this->requireOf(['ROLE_ADMIN'], false);
        $staff = $this->createUser(['ROLE_ADMIN']);

        $response = $this->request('/settings/security-required', $staff);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('/settings/security-required/skip', (string) $response->getContent());
    }
}
