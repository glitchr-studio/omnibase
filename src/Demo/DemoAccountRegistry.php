<?php

namespace Base\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Every demonstration account declared by the bundles and the application
 * (DemoAccountProviderInterface), by identifier. The application's
 * declarations come last and replace a bundle's; base.demo.exclude drops the
 * ones a site has no use for.
 *
 * It exists in every environment - the fixtures of dev and test create the
 * same accounts as the demonstration's - but only the `demo` environment
 * does anything with it at sign-in (DemoMode).
 */
class DemoAccountRegistry
{
    public const SUPER_ADMIN = 'ROLE_SUPERADMIN';

    /** @var array<string, DemoAccount>|null */
    private ?array $accounts = null;

    /**
     * @param iterable<DemoAccountProviderInterface> $providers
     * @param string[]                               $exclude   identifiers left out (base.demo.exclude)
     */
    public function __construct(
        #[AutowireIterator('base.demo_account_provider')] private readonly iterable $providers = [],
        #[Autowire('%base.demo.exclude%')] private readonly array $exclude = [],
        private readonly ?RoleHierarchyInterface $roleHierarchy = null,
    ) {
    }

    /** @return array<string, DemoAccount> by identifier, in the order of the sign-in page */
    public function all(): array
    {
        if (null !== $this->accounts) {
            return $this->accounts;
        }

        $providers = [...$this->providers];
        // The application's own last: its declaration is the one kept.
        usort($providers, static fn (object $a, object $b): int => str_starts_with($a::class, 'App\\') <=> str_starts_with($b::class, 'App\\'));

        $accounts = [];
        foreach ($providers as $provider) {
            foreach ($provider->getDemoAccounts() as $account) {
                if ($this->reachesSuperAdmin($account->getAllRoles())) {
                    throw new \LogicException(sprintf('"%s" (declared by %s) cannot be a demonstration account: its roles reach %s. The super-administrator signs in with its own password, never through the demonstration.', $account->identifier, $provider::class, self::SUPER_ADMIN));
                }
                if (\in_array($account->identifier, $this->exclude, true)) {
                    continue;
                }
                // Replaced in place: the application's version keeps the bundle's rank unless it gives its own.
                $accounts[$account->identifier] = $account;
            }
        }

        $rank = array_flip(array_keys($accounts));
        uasort($accounts, static fn (DemoAccount $a, DemoAccount $b): int => [$a->position, $rank[$a->identifier]] <=> [$b->position, $rank[$b->identifier]]);

        return $this->accounts = $accounts;
    }

    public function get(?string $identifier): ?DemoAccount
    {
        return null === $identifier ? null : ($this->all()[$identifier] ?? null);
    }

    public function has(?string $identifier): bool
    {
        return null !== $this->get($identifier);
    }

    /** The declaration this account was created from, if it is a demonstration account. */
    public function of(?UserInterface $user): ?DemoAccount
    {
        if (null === $user) {
            return null;
        }

        try {
            return $this->get($user->getUserIdentifier());
        } catch (\Throwable) {
            // an account being built, with no identifier yet
            return null;
        }
    }

    /**
     * Do these roles reach ROLE_SUPERADMIN, directly or through the role
     * hierarchy (a ROLE_EDITOR above it, in the sites where Glitch Art is one)?
     *
     * @param array<string|\BackedEnum> $roles
     */
    public function reachesSuperAdmin(array $roles): bool
    {
        $roles = array_map(static fn ($role): string => $role instanceof \BackedEnum ? (string) $role->value : (string) $role, $roles);
        if ($this->roleHierarchy) {
            $roles = $this->roleHierarchy->getReachableRoleNames($roles);
        }

        return \in_array(self::SUPER_ADMIN, $roles, true);
    }

    public function isSuperAdmin(?UserInterface $user): bool
    {
        return null !== $user && $this->reachesSuperAdmin($user->getRoles());
    }
}
