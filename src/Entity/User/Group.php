<?php

namespace Base\Entity\User;

use App\Entity\User;
use Base\Entity\User\Penalty;
use Base\Entity\User\Permission;
use Base\Service\Model\IconizeInterface;
use DateTimeInterface;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Base\Database\Attribute\Cache;

use Base\Entity\User\Attribute\Action\GrantPermissionAdapter;
use Base\Entity\User\Attribute\Action\GrantRoleAdapter;
use Base\Entity\User\Attribute\GroupAction;
use Base\Entity\User\Attribute\GroupRule;
use Base\Entity\User\Attribute\GroupScope;
use Doctrine\ORM\Mapping as ORM;
use Base\Repository\User\GroupRepository;
use Base\Database\Attribute\DiscriminatorEntry;

/**
 * Open to extension the way User is: JOINED inheritance on a `class`
 * discriminator, so an application can add its own group (a leader, a motto,
 * a join mode...) as a subclass in its own table, while roles, permissions,
 * rules, scopes and actions keep working on the base row. An application
 * without a subclass sees no change but the discriminator column.
 */
#[ORM\Entity(repositoryClass:GroupRepository::class)]
#[ORM\InheritanceType("JOINED")]
#[ORM\DiscriminatorColumn(name: "class", type: "string")]
#[DiscriminatorEntry(value: "common")]
#[Cache(usage:"NONSTRICT_READ_WRITE", associations:"ALL")]
class Group implements IconizeInterface
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return [self::DEFAULT_ICON];
    }

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->permissions = new ArrayCollection();
        $this->penalties = new ArrayCollection();
        $this->rules = new ArrayCollection();
        $this->scopes = new ArrayCollection();
        $this->actions = new ArrayCollection();

        // createdAt is NOT NULL and nothing else ever set it, so persisting a
        // freshly constructed Group failed outright with an integrity
        // violation - found the first time one was created in code rather
        // than by a fixture.
        $this->createdAt = new DateTime("now");
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type:"integer")]
    protected $id;
    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\Column(type:"json")]
    protected $roles = [];

    public function getRoles(): array
    {
        return array_unique($this->roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    #[ORM\Column(type:"string", length:255)]
    protected $name;
    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    
    #[ORM\ManyToMany(targetEntity:User::class, mappedBy:"groups")]
    protected $members;
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(User $member): self
    {
        if (!$this->members->contains($member)) {
            $this->members[] = $member;
            $member->addGroup($this);
        }

        return $this;
    }

    public function removeMember(User $member): self
    {
        if ($this->members->removeElement($member)) {
            $member->removeGroup($this);
        }

        return $this;
    }

    #[ORM\Column(type:"datetime")]
    protected $createdAt;
    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    #[ORM\ManyToMany(targetEntity:Permission::class, inversedBy:"gid")]
    protected $permissions;

    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function addPermission(Permission $permission): self
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions[] = $permission;
        }

        return $this;
    }

    public function removePermission(Permission $permission): self
    {
        $this->permissions->removeElement($permission);

        return $this;
    }
    
    /**
     * Conditions on joining, and who they are about.
     *
     * Evaluated the way the marketplace evaluates a discount's - scopes OR-ed,
     * rules AND-ed - so the two halves keep the meanings they have everywhere
     * else in this engine: a scope says whether the group concerns you at all,
     * a rule says whether you qualify.
     *
     * @see isOpenTo()
     */
    #[ORM\OneToMany(targetEntity: GroupRule::class, mappedBy: "group", orphanRemoval: true, cascade: ["persist", "remove"])]
    protected $rules;

    public function getRules(): Collection
    {
        return $this->rules;
    }

    public function addRule(GroupRule $rule): self
    {
        if (!$this->rules->contains($rule)) {
            $this->rules[] = $rule;
            $rule->setGroup($this);
        }

        return $this;
    }

    public function removeRule(GroupRule $rule): self
    {
        $this->rules->removeElement($rule);

        return $this;
    }

    #[ORM\OneToMany(targetEntity: GroupScope::class, mappedBy: "group", orphanRemoval: true, cascade: ["persist", "remove"])]
    protected $scopes;

    public function getScopes(): Collection
    {
        return $this->scopes;
    }

    public function addScope(GroupScope $scope): self
    {
        if (!$this->scopes->contains($scope)) {
            $this->scopes[] = $scope;
            $scope->setGroup($this);
        }

        return $this;
    }

    public function removeScope(GroupScope $scope): self
    {
        $this->scopes->removeElement($scope);

        return $this;
    }

    #[ORM\OneToMany(targetEntity: GroupAction::class, mappedBy: "group", orphanRemoval: true, cascade: ["persist", "remove"])]
    protected $actions;

    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function addAction(GroupAction $action): self
    {
        if (!$this->actions->contains($action)) {
            $this->actions[] = $action;
            $action->setGroup($this);
        }

        return $this;
    }

    public function removeAction(GroupAction $action): self
    {
        $this->actions->removeElement($action);

        return $this;
    }

    /**
     * Everything this group would award $subject, or nothing if it would not
     * admit them.
     *
     * Computes; it does not grant. The marketplace's manager accumulates what
     * its actions return and applies the total itself
     * (`$discount += $action->apply($product)`), and the same separation is
     * what lets this be used to SHOW somebody what reaching the next level
     * would give them - a preview that grants nothing.
     *
     * Awards are merged and de-duplicated per kind, because two actions on one
     * group may legitimately award the same role, and the caller wants the set
     * rather than the tally.
     *
     * Returns e.g. ["roles" => ["ROLE_X"], "permissions" => ["ALLIANCE.SENIOR"]].
     * The keys are the shapes the shipped adapters return; an adapter awarding
     * something else lands under "other" rather than being silently dropped.
     */
    public function awardsFor(mixed $subject): array
    {
        if (!$this->isOpenTo($subject)) {
            return [];
        }

        $awards = [];
        foreach ($this->actions as $action) {
            $awarded = $action->apply($subject);
            if ($awarded === null) {
                continue;
            }

            $kind = match (true) {
                $action->getAdapter() instanceof GrantRoleAdapter => "roles",
                $action->getAdapter() instanceof GrantPermissionAdapter => "permissions",
                default => "other",
            };

            foreach (is_array($awarded) ? $awarded : [$awarded] as $one) {
                $awards[$kind][] = $one;
            }
        }

        foreach ($awards as $kind => $values) {
            $awards[$kind] = array_values(array_unique($values, SORT_REGULAR));
        }

        return $awards;
    }

    /**
     * Whether this group would admit $subject as things stand.
     *
     * Says nothing about whether they are already a member: this answers
     * "have they earned it", so a group can be offered when it becomes
     * reachable and a membership can be re-checked when a score moves.
     *
     * No scopes means the group concerns everybody - the right default for one
     * whose entry is purely a matter of score. No rules means nothing is being
     * asked of them, so it admits anyone in scope rather than nobody: a group
     * still being configured must not read as "closed".
     */
    public function isOpenTo(mixed $subject): bool
    {
        $inScope = $this->scopes->isEmpty();
        foreach ($this->scopes as $scope) {
            // OR, and stop at the first hit: one scope bringing them into
            // range is enough, and the rest may be doing real work to answer.
            if ($scope->contains($subject)) {
                $inScope = true;
                break;
            }
        }

        if (!$inScope) {
            return false;
        }

        foreach ($this->rules as $rule) {
            // AND, and stop at the first miss.
            if (!$rule->compliesWith($subject)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What belonging to this group costs, or earns.
     *
     * A group carries penalties directly rather than through Sanction: a
     * penalty on a group is a standing property of the group, not an incident
     * with a date and an author, and it applies to whoever is a member at the
     * time rather than to the people who were members when it was issued.
     *
     * @see User::getScore() which adds this to each member's own total.
     */
    public function getScore(): int
    {
        $score = 0;
        foreach ($this->penalties as $penalty) {
            $score += $penalty->getWeight();
        }

        return $score;
    }

    #[ORM\ManyToMany(targetEntity:Penalty::class, inversedBy:"gid")]
    protected $penalties;

    public function getPenalties(): Collection
    {
        return $this->penalties;
    }

    public function addPenalty(Penalty $penalty): self
    {
        if (!$this->penalties->contains($penalty)) {
            $this->penalties[] = $penalty;
        }

        return $this;
    }

    public function removePenalty(Penalty $penalty): self
    {
        $this->penalties->removeElement($penalty);

        return $this;
    }

    #[ORM\Column(type:"string", length:7, nullable:true)]
    protected $color;

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    /** The icon a group gets until it is given one: the column is NOT NULL. */
    public const DEFAULT_ICON = "fa-solid fa-users";

    // The default is the property's, not the column's: a `new Group()` is
    // persisted as it is (the column had none, and the insert failed), and no
    // schema changes.
    #[ORM\Column(type:"string", length:255)]
    protected $icon = self::DEFAULT_ICON;

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = null === $icon || '' === trim($icon) ? self::DEFAULT_ICON : $icon;

        return $this;
    }
}
