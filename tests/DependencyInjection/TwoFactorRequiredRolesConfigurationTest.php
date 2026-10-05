<?php

namespace Tests\Base\DependencyInjection;

use Base\DependencyInjection\BaseConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * base.security.two_factor.required_roles defined twice - config/packages/base.yaml,
 * then its when@dev / when@test block, or another file: the last definition
 * is the list. Symfony appends the items of a list node by default, which
 * left an application no way to require a second factor of fewer roles (or
 * of none) in one environment.
 */
class TwoFactorRequiredRolesConfigurationTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $twoFactor what each definition says under base.security.two_factor */
    private function process(array ...$twoFactor): array
    {
        $configs = array_map(fn (array $definition) => ['security' => ['two_factor' => $definition]], $twoFactor);

        return (new Processor())->processConfiguration(new BaseConfiguration(), $configs)['security']['two_factor'];
    }

    public function testNoneByDefault(): void
    {
        $this->assertSame([], (new Processor())->processConfiguration(new BaseConfiguration(), [])['security']['two_factor']['required_roles']);
        $this->assertSame([], $this->process(['postpone' => false])['required_roles']);
    }

    public function testOneDefinition(): void
    {
        $this->assertSame(['ROLE_STAFF', 'ROLE_ADMIN'], $this->process(['required_roles' => ['ROLE_STAFF', 'ROLE_ADMIN']])['required_roles']);
    }

    public function testALaterDefinitionReplacesTheList(): void
    {
        $this->assertSame(['ROLE_ADMIN'], $this->process(['required_roles' => ['ROLE_STAFF']], ['required_roles' => ['ROLE_ADMIN']])['required_roles']);
    }

    public function testALaterEmptyListRequiresItOfNoRole(): void
    {
        $this->assertSame([], $this->process(['required_roles' => ['ROLE_STAFF']], ['required_roles' => []])['required_roles']);
    }

    public function testADefinitionThatDoesNotNameTheListKeepsIt(): void
    {
        $config = $this->process(['required_roles' => ['ROLE_STAFF']], ['postpone' => false]);

        $this->assertSame(['ROLE_STAFF'], $config['required_roles']);
        $this->assertFalse($config['postpone']);
    }
}
