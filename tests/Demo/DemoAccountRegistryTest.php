<?php

namespace Tests\Base\Demo;

use Base\Demo\DemoAccount;
use Base\Demo\DemoAccountProviderInterface;
use Base\Demo\DemoAccountRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The declared demonstration accounts gathered: a bundle's and the
 * application's, the application's kept for the same identifier, the
 * excluded ones dropped - and never a super-administrator.
 */
class DemoAccountRegistryTest extends TestCase
{
    /** @param DemoAccount[] $accounts */
    private function provider(array $accounts): DemoAccountProviderInterface
    {
        return new class($accounts) implements DemoAccountProviderInterface {
            public function __construct(private array $accounts)
            {
            }

            public function getDemoAccounts(): iterable
            {
                return $this->accounts;
            }
        };
    }

    public function testTheDeclaredAccountsAreGatheredInOrder(): void
    {
        $registry = new DemoAccountRegistry([
            $this->provider([new DemoAccount('patient', 'Patient', position: 20), new DemoAccount('docteur', 'Médecin', 'Son agenda.', group: 'Praticiens', groupRoles: ['ROLE_PRACTITIONER'], position: 10)]),
            $this->provider([new DemoAccount('client', 'Client')]),
        ]);

        $this->assertSame(['client', 'docteur', 'patient'], array_keys($registry->all()), 'by position, then as declared');
        $this->assertSame('Médecin', $registry->get('docteur')->label);
        $this->assertSame('docteur', $registry->get('docteur')->getPassword(), 'the password is the identifier unless one is given');
        $this->assertSame('docteur@example.org', $registry->get('docteur')->getEmail());
        $this->assertSame(['ROLE_USER', 'ROLE_PRACTITIONER'], $registry->get('docteur')->getAllRoles());
        $this->assertTrue($registry->has('patient'));
        $this->assertFalse($registry->has('nobody'));
        $this->assertNull($registry->get(null));
    }

    public function testALaterDeclarationReplacesAnEarlierOneAndExcludedAccountsAreDropped(): void
    {
        $registry = new DemoAccountRegistry([
            $this->provider([new DemoAccount('docteur', 'Praticien'), new DemoAccount('kine', 'Kinésithérapeute')]),
            $this->provider([new DemoAccount('docteur', 'Dr Tilleul')]),
        ], ['kine']);

        $this->assertSame(['docteur'], array_keys($registry->all()));
        $this->assertSame('Dr Tilleul', $registry->get('docteur')->label);
    }

    public function testAnAccountIsRecognisedByItsIdentifier(): void
    {
        $registry = new DemoAccountRegistry([$this->provider([new DemoAccount('patient', 'Patient')])]);

        $this->assertSame('patient', $registry->of(new InMemoryUser('patient', null))?->identifier);
        $this->assertNull($registry->of(new InMemoryUser('someone', null)));
        $this->assertNull($registry->of(null));
    }

    public function testASuperAdministratorIsNeverADemonstrationAccount(): void
    {
        $registry = new DemoAccountRegistry([$this->provider([new DemoAccount('glitchr', 'Glitch Art', roles: ['ROLE_SUPERADMIN'])])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be a demonstration account');
        $registry->all();
    }

    public function testNorAnAccountWhoseRolesReachItThroughAGroupOrTheHierarchy(): void
    {
        $hierarchy = new RoleHierarchy(['ROLE_EDITOR' => ['ROLE_SUPERADMIN'], 'ROLE_SUPERADMIN' => ['ROLE_ADMIN']]);

        $this->assertTrue((new DemoAccountRegistry([], [], $hierarchy))->reachesSuperAdmin(['ROLE_EDITOR']));
        $this->assertFalse((new DemoAccountRegistry([], [], $hierarchy))->reachesSuperAdmin(['ROLE_ADMIN']));
        $this->assertTrue((new DemoAccountRegistry([], [], $hierarchy))->isSuperAdmin(new InMemoryUser('root', null, ['ROLE_EDITOR'])));

        $registry = new DemoAccountRegistry([$this->provider([new DemoAccount('agence', 'Agence', group: 'Éditeurs', groupRoles: ['ROLE_EDITOR'])])], [], $hierarchy);
        $this->expectException(\LogicException::class);
        $registry->all();
    }
}
