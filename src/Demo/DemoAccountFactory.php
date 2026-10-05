<?php

namespace Base\Demo;

use Base\Entity\User\Group;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The declared demonstration accounts as rows: what the fixtures call instead
 * of writing each account by hand, and what `demo:reset` calls after them so
 * that every declared account exists.
 *
 *     $docteur = $this->demoAccounts->account('docteur', $manager);   // found, or created from its declaration
 *     $this->demoAccounts->load($manager);                            // every declared account
 *
 * An account the database already has is returned as it is: fixtures that
 * create theirs themselves keep them, identifier included. Nothing is
 * flushed here - the fixture does.
 */
class DemoAccountFactory
{
    /** @var array<string, object> the groups persisted here and not flushed yet, by name */
    private array $groups = [];

    /** @var array<string, object> the accounts persisted here and not flushed yet, by identifier */
    private array $users = [];

    /** @param class-string $userClass */
    public function __construct(
        private readonly DemoAccountRegistry $registry,
        private readonly ManagerRegistry $doctrine,
        #[Autowire('App\Entity\User')] private readonly string $userClass = 'App\Entity\User',
    ) {
    }

    /** The row of a declared account, null when the database has none. */
    public function find(string $identifier, ?ObjectManager $manager = null): ?object
    {
        $manager ??= $this->doctrine->getManagerForClass($this->userClass);
        $pending = $this->users[$identifier] ?? null;
        if ($pending && $manager->contains($pending)) {
            return $pending;
        }

        return $manager->getRepository($this->userClass)->findOneBy([$this->identifierField() => $identifier]);
    }

    /**
     * The account of that identifier: the database's, or a new one made from
     * its declaration (verified, approved, in its group - created with its
     * roles when missing).
     */
    public function account(string $identifier, ?ObjectManager $manager = null): object
    {
        $account = $this->registry->get($identifier);
        if (null === $account) {
            throw new \InvalidArgumentException(sprintf('No demonstration account "%s" is declared (declared: %s).', $identifier, implode(', ', array_keys($this->registry->all())) ?: 'none'));
        }

        $manager ??= $this->doctrine->getManagerForClass($this->userClass);
        $user = $this->find($identifier, $manager);
        if (null === $user) {
            $user = new ($this->userClass)();
            $field = $this->identifierField();
            if ('email' !== $field) {
                $user->{'set'.ucfirst($field)}($account->identifier);
            } elseif (method_exists($user, 'setUsername')) {
                // Signed in by its address, named all the same (a column the applications' User adds).
                $user->setUsername(strstr($account->identifier.'@', '@', true));
            }
            $user->setEmail($account->getEmail());
            $user->setPlainPassword($account->getPassword());
            $user->setRoles($account->roles);
            $user->verify();
            if (method_exists($user, 'approve')) {
                $user->approve(true);
            }
            $manager->persist($user);
            $this->users[$identifier] = $user;
        }

        if (null !== $account->group) {
            $group = $this->group($manager, $account->group, $account->groupRoles);
            if (!$user->getGroups()->contains($group)) {
                $group->addMember($user);
            }
        }

        return $user;
    }

    /**
     * Every declared account, created when missing.
     *
     * @return array<string, object> by identifier
     */
    public function load(?ObjectManager $manager = null): array
    {
        $users = [];
        foreach ($this->registry->all() as $identifier => $account) {
            $users[$identifier] = $this->account($identifier, $manager);
        }

        return $users;
    }

    /**
     * The identifiers of the declared accounts the database lacks.
     *
     * @return string[]
     */
    public function missing(?ObjectManager $manager = null): array
    {
        return array_values(array_filter(array_keys($this->registry->all()), fn (string $identifier): bool => null === $this->find($identifier, $manager)));
    }

    /** @param string[] $roles */
    private function group(ObjectManager $manager, string $name, array $roles): object
    {
        $group = $this->groups[$name] ?? null;
        if (!$group || !$manager->contains($group)) {
            $group = $manager->getRepository(Group::class)->findOneBy(['name' => $name]);
        }
        if (!$group) {
            $group = (new Group())->setName($name)->setRoles($roles);
            // The column is NOT NULL and has no default.
            $group->setIcon('fa-solid fa-users');
            $manager->persist($group);
        }

        return $this->groups[$name] = $group;
    }

    private function identifierField(): string
    {
        return method_exists($this->userClass, 'getUserIdentifierField') ? $this->userClass::getUserIdentifierField() : 'email';
    }
}
