<?php

namespace Base\Demo;

/**
 * A demonstration account, as a bundle or the application declares it
 * (DemoAccountProviderInterface): who signs in, as what, and what that role
 * sees. The fixtures create the accounts from these declarations
 * (DemoAccountFactory); in the `demo` environment the sign-in page offers one
 * button for each (docs/20-architecture/demo.md).
 *
 * A super-administrator is never a demonstration account: the registry
 * refuses a declaration whose roles reach ROLE_SUPERADMIN.
 */
final class DemoAccount
{
    /**
     * @param string      $identifier  what the account signs in with (the field the application's User names in $userIdentifier)
     * @param string      $label       the button's text: a translation key ("@health.demo.practitioner.label") or the text itself
     * @param string      $description one sentence on what this role sees: a translation key or the text itself
     * @param string[]    $roles       the account's own roles
     * @param string|null $group       the name of a Base\Entity\User\Group the account belongs to (it holds the group's roles)
     * @param string[]    $groupRoles  the roles of that group, when the fixtures have to create it
     * @param string|null $password    null: the identifier (demonstration accounts are public)
     * @param string|null $email       null: <identifier>@example.org
     * @param int         $position    the order on the sign-in page, lowest first
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $label,
        public readonly string $description = '',
        public readonly array $roles = ['ROLE_USER'],
        public readonly ?string $group = null,
        public readonly array $groupRoles = [],
        public readonly ?string $password = null,
        public readonly ?string $email = null,
        public readonly int $position = 0,
    ) {
        if ('' === trim($identifier)) {
            throw new \InvalidArgumentException('A demonstration account needs an identifier.');
        }
    }

    public function getPassword(): string
    {
        return $this->password ?? $this->identifier;
    }

    public function getEmail(): string
    {
        return $this->email ?? (str_contains($this->identifier, '@') ? $this->identifier : $this->identifier.'@example.org');
    }

    /** @return string[] the account's own roles and its group's */
    public function getAllRoles(): array
    {
        return array_values(array_unique([...$this->roles, ...$this->groupRoles]));
    }
}
