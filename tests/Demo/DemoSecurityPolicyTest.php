<?php

namespace Tests\Base\Demo;

use Base\Demo\DemoAccount;
use Base\Demo\DemoAccountProviderInterface;
use Base\Demo\DemoAccountRegistry;
use Base\Demo\DemoMode;
use Base\Service\SecurityPolicy;
use Base\Service\SettingBagInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * A demonstration account under the account-security policy: in `demo`
 * nothing is required of it and it changes none of its credentials; anywhere
 * else the same account is an ordinary one.
 */
class DemoSecurityPolicyTest extends TestCase
{
    private function policy(string $environment, bool $mandatoryForAll = false): SecurityPolicy
    {
        $settings = $this->createMock(SettingBagInterface::class);
        $settings->method('getScalar')->willReturnCallback(static fn (string $path) => SecurityPolicy::TWO_FACTOR_MANDATORY === $path ? $mandatoryForAll : null);

        $provider = new class implements DemoAccountProviderInterface {
            public function getDemoAccounts(): iterable
            {
                yield new DemoAccount('docteur', 'Médecin', group: 'Praticiens', groupRoles: ['ROLE_PRACTITIONER']);
            }
        };

        return new SecurityPolicy(
            $settings,
            new RoleHierarchy(['ROLE_PRACTITIONER' => ['ROLE_STAFF']]),
            ['ROLE_STAFF'],
            false,
            new DemoMode($environment),
            new DemoAccountRegistry([$provider]),
        );
    }

    public function testInDemoNothingIsRequiredOfADemonstrationAccount(): void
    {
        $docteur = new InMemoryUser('docteur', null, ['ROLE_USER', 'ROLE_PRACTITIONER']);
        $policy = $this->policy('demo');

        $this->assertTrue($policy->isDemoAccount($docteur));
        $this->assertFalse($policy->isTwoFactorRequiredByRole($docteur));
        $this->assertFalse($policy->isTwoFactorMandatory($docteur));
        $this->assertFalse($policy->needsEnrolment($docteur), 'the staff\'s mandatory second factor is lifted for it');
        $this->assertFalse($policy->canEnableTwoFactor($docteur), 'and it sets none up');
        $this->assertFalse($policy->canChangeCredentials($docteur));

        $this->assertFalse($this->policy('demo', true)->needsEnrolment($docteur), 'the administrator\'s "mandatory for everyone" too');
    }

    public function testInDemoAnotherAccountOfTheSameRoleIsStillAsked(): void
    {
        $colleague = new InMemoryUser('remplacante', null, ['ROLE_USER', 'ROLE_PRACTITIONER']);
        $policy = $this->policy('demo');

        $this->assertFalse($policy->isDemoAccount($colleague));
        $this->assertTrue($policy->needsEnrolment($colleague));
        $this->assertTrue($policy->canEnableTwoFactor($colleague));
        $this->assertTrue($policy->canChangeCredentials($colleague));
    }

    public function testOutsideDemoTheSameAccountIsAnOrdinaryOne(): void
    {
        $docteur = new InMemoryUser('docteur', null, ['ROLE_USER', 'ROLE_PRACTITIONER']);
        foreach (['prod', 'dev', 'test'] as $environment) {
            $policy = $this->policy($environment);
            $this->assertFalse($policy->isDemoAccount($docteur), $environment);
            $this->assertTrue($policy->needsEnrolment($docteur), $environment);
            $this->assertTrue($policy->canEnableTwoFactor($docteur), $environment);
            $this->assertTrue($policy->canChangeCredentials($docteur), $environment);
        }
    }
}
