<?php

namespace Tests\Base\Security;

use Base\Service\SecurityPolicy;
use Base\Service\SettingBagInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * A second factor required of some roles (base.security.two_factor.required_roles),
 * beside the administrator's "everyone or no one".
 */
class SecurityPolicyTest extends TestCase
{
    /** @param array<string, mixed> $settings what the administrator saved */
    private function policy(array $requiredRoles = [], array $settings = [], bool $postpone = true): SecurityPolicy
    {
        $bag = $this->createStub(SettingBagInterface::class);
        $bag->method('getScalar')->willReturnCallback(static fn ($path) => $settings[$path] ?? null);

        return new SecurityPolicy($bag, new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_STAFF'], 'ROLE_STAFF' => ['ROLE_USER']]), $requiredRoles, $postpone);
    }

    public function testWithoutRequiredRolesTheAdministratorsSettingAloneDecides(): void
    {
        $staff = new InMemoryUser('staff', null, ['ROLE_STAFF']);

        $policy = $this->policy();
        $this->assertFalse($policy->isTwoFactorMandatory());
        $this->assertFalse($policy->isTwoFactorMandatory($staff));
        $this->assertFalse($policy->needsEnrolment($staff));
        $this->assertTrue($policy->canDisableTwoFactor($staff));

        $everyone = $this->policy([], [SecurityPolicy::TWO_FACTOR_MANDATORY => '1']);
        $this->assertTrue($everyone->isTwoFactorMandatory());
        $this->assertTrue($everyone->needsEnrolment($staff));
        $this->assertFalse($everyone->canDisableTwoFactor());
    }

    public function testARequiredRoleMakesItMandatoryForItsHoldersOnly(): void
    {
        $policy = $this->policy(['ROLE_STAFF']);
        $staff = new InMemoryUser('staff', null, ['ROLE_STAFF']);
        $admin = new InMemoryUser('admin', null, ['ROLE_ADMIN']); // reaches ROLE_STAFF through the hierarchy
        $patient = new InMemoryUser('patient', null, ['ROLE_USER']);

        $this->assertFalse($policy->isTwoFactorMandatory(), 'not for the whole site');
        $this->assertTrue($policy->isTwoFactorMandatory($staff));
        $this->assertTrue($policy->isTwoFactorMandatory($admin));
        $this->assertFalse($policy->isTwoFactorMandatory($patient));

        $this->assertTrue($policy->needsEnrolment($staff));
        $this->assertFalse($policy->needsEnrolment($patient));
        $this->assertFalse($policy->needsEnrolment(null));

        $this->assertFalse($policy->canDisableTwoFactor($staff));
        $this->assertTrue($policy->canDisableTwoFactor($patient));
        $this->assertFalse($policy->canPostponeEnrolmentPermanently($staff));
        $this->assertTrue($policy->canPostponeEnrolmentPermanently($patient));
    }

    public function testNothingIsRequiredWhileTwoFactorIsSwitchedOff(): void
    {
        $policy = $this->policy(['ROLE_STAFF'], [SecurityPolicy::TWO_FACTOR => '0']);
        $staff = new InMemoryUser('staff', null, ['ROLE_STAFF']);

        $this->assertFalse($policy->isTwoFactorMandatory($staff));
        $this->assertFalse($policy->needsEnrolment($staff));
    }

    public function testNotNowIsOfferedUnlessTheApplicationAllowsNoLater(): void
    {
        $staff = new InMemoryUser('staff', null, ['ROLE_STAFF']);
        $patient = new InMemoryUser('patient', null, ['ROLE_USER']);

        $this->assertTrue($this->policy(['ROLE_STAFF'])->canSkipEnrolment($staff));

        $strict = $this->policy(['ROLE_STAFF'], [], false);
        $this->assertFalse($strict->canSkipEnrolment($staff));
        $this->assertTrue($strict->canSkipEnrolment($patient), 'nothing to skip: nothing is asked of them');
    }
}
