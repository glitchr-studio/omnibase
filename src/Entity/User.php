<?php

namespace Base\Entity;

use Base\Entity\User\Address;
use Base\Exception\MissingLocaleException;

use App\Entity\Thread\Like;
use App\Entity\Thread\Mention;

use Base\Entity\User\Connection;
use Base\Entity\User\Passkey;

use App\Entity\User\Token;
use Base\Entity\User\Group;
use Base\Entity\User\Penalty;
use Base\Entity\User\Sanction;
use Base\Entity\User\Permission;
use App\Entity\User\Notification;
use Base\Database\Attribute\OrderColumn;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Base\Validator\Constraints as AssertBase;

use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface as EmailTwoFactorInterface;
use Scheb\TwoFactorBundle\Model\TrustedDeviceInterface;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Database\Attribute\Timestamp;
use Base\Database\Attribute\Uploader;
use Base\Database\Attribute\Hashify;

use Base\Service\Localizer;
use Base\Notifier\Recipient\Recipient;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Base\Service\Model\IconizeInterface;

use Base\Traits\BaseTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Exception;
use Base\Database\Attribute\Cache;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserRepository;
use App\Enum\UserRole;
use App\Enum\UserState;
use Base\Service\Model\AutocompleteInterface;

use Base\Traits\UserInfoTrait;
use ApiPlatform\Metadata\ApiResource;

#[ORM\Entity(repositoryClass:UserRepository::class)]
#[ORM\InheritanceType( "JOINED" )]
#[Cache(usage:"NONSTRICT_READ_WRITE", associations:"ALL")]

#[ORM\DiscriminatorColumn( name: "class", type: "string" )]
#[DiscriminatorEntry( value: "common" )]

// An address somebody already signed up with: said as a sentence, which the sign-up page follows with the ways in.
#[AssertBase\UniqueEntity(fields:["email"], message: "@validators.user.email.unique", groups:["new", "edit"])]

#[ApiResource]
class User implements UserInterface, TwoFactorInterface, EmailTwoFactorInterface, BackupCodeInterface, TrustedDeviceInterface, PasswordAuthenticatedUserInterface, IconizeInterface, AutocompleteInterface
{
    use BaseTrait;
    use UserInfoTrait;

    public function __autocomplete(): string { return $this->__toString(); }
    public function __autocompleteData(): array { return []; }

    public function __toString() { return $this->getEmail() ?? $this->getId(); }

    /**
     * How a member is named in a record of what they did (revisions, trash):
     * the application's username when it has one, else the display name.
     * Not getUsername() on the base class: the admin builds URLs from any
     * getUsername() it finds, and only a real column resolves back.
     */
    public static function nameOf(?self $user): ?string
    {
        if (null === $user) {
            return null;
        }

        return method_exists($user, 'getUsername') ? $user->getUsername() : (string) $user;
    }
    public function __iconize(): ?array
    {
        return array_map(fn($r) => UserRole::getIcon($r, 0), array_filter($this->getRoles()));
    }

    public static function __iconizeStatic(): ?array
    {
        return ["fa-solid fa-user"];
    }

    public static $userIdentifier = "email";
    public static function getUserIdentifierField(): string
    {
        return static::$userIdentifier;
    }

    public function getUserIdentifier(): string
    {
        $userIdentifier = null;

        $accessor = PropertyAccess::createPropertyAccessor();
        if ($accessor->isReadable($this, static::$userIdentifier)) {
            $userIdentifier = $accessor->getValue($this, static::$userIdentifier);
        }

        if ($accessor->isReadable($this, static::$userIdentifier) && !$userIdentifier) {
            $userIdentifier = $accessor->getValue($this, static::$userIdentifier);
        }

        if ($userIdentifier === null) {
            throw new Exception("User identifier is NULL. Is this user initialized or database persistent ?");
        }

        return $userIdentifier;
    }

    /**
     * @return bool
     */
    public function equals($other): bool
    {
        return ($other->getId() == $this->getId());
    }

    /**
     * @return string
     * @throws Exception
     */
    public function __construct(?string $email = null)
    {
        $this->email = $email;

        $role = strtoupper(class_basename(get_called_class()));
        $this->roles = [UserRole::hasKey($role) ? UserRole::getValue($role) : UserRole::USER];
        $this->states = [UserState::ENABLED, UserState::NEWCOMER];

        $this->tokens = new ArrayCollection();
        $this->permissions = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->sanctions = new ArrayCollection();

        $this->connections = new ArrayCollection();
        $this->passkeys = new ArrayCollection();

        $this->threads = new ArrayCollection();
        $this->followedThreads = new ArrayCollection();
        $this->mentions = new ArrayCollection();
        $this->likes = new ArrayCollection();

        $this->addresses = new ArrayCollection();

        $this->setTimezone();
        $this->setLocale();
    }

    public function getRecipient(): Recipient
    {
        $email = $this->getUserIdentifier() . " <" . $this->getEmail() . ">";
        $phone = $this->getPhone() ?? '';
        $locale = $this->getLocale();
        $timezone = $this->getTimezone();

        return new Recipient($email, $phone, $locale, $timezone);
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function isGranted($role): bool { return $this->getService()->isGranted($role, $this); }

    public function killSession() { $this->logout(); }
    public function logout()
    {
        $token = $this->getTokenStorage()->getToken();
        if ($token === null || $token->getUser() !== $this) {
            $this->kick();
        } else {
            $this->getTokenStorage()->setToken(null);
            setcookie("REMEMBERME", '', time() - 1);
            setcookie("REMEMBERME", '', time() - 1, "/", $this->getRouter()->getDomain());
        }
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type:"integer")]
    protected $id;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return $this
     */
    public function setId($id): self
    {
        $this->id = $id;
        return $this;
    }

    #[ORM\Column(name:"secret", type:"string", nullable:true)]
    protected $secret;

    public const TOTP_LENGTH = 6;
    public const TOTP_TIMEOUT = 30;

    /**
     * @return mixed
     */
    public function getTotpSecret()
    {
        return $this->secret;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return (bool)$this->secret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->getUserIdentifier();
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if ($this->secret == null) {
            return null;
        }
        return new TotpConfiguration($this->secret, TotpConfiguration::ALGORITHM_SHA1, User::TOTP_TIMEOUT, User::TOTP_LENGTH);
    }

    /**
     * @return $this
     */
    public function setTotpSecret($secret): self
    {
        $this->secret = $secret;
        return $this;
    }

    #[ORM\Column(type:"json", nullable:true)]
    protected $backupCodes = [];

    public function isBackupCode(string $code): bool
    {
        foreach ($this->backupCodes ?? [] as $hash) {
            if (password_verify($code, $hash)) {
                return true;
            }
        }
        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $this->backupCodes = array_values(array_filter(
            $this->backupCodes ?? [],
            fn($hash) => !password_verify($code, $hash)
        ));
    }

    public function getBackupCodesCount(): int
    {
        return count($this->backupCodes ?? []);
    }

    /**
     * @param string[] $plainCodes
     */
    public function setBackupCodes(array $plainCodes): self
    {
        $this->backupCodes = array_map(fn($code) => password_hash($code, PASSWORD_DEFAULT), $plainCodes);
        return $this;
    }

    #[ORM\Column(type:"boolean")]
    protected $emailAuthEnabled = false;

    #[ORM\Column(type:"string", nullable:true)]
    protected $emailAuthCode;

    public function isEmailAuthEnabled(): bool
    {
        return $this->emailAuthEnabled;
    }

    public function setEmailAuthEnabled(bool $emailAuthEnabled): self
    {
        $this->emailAuthEnabled = $emailAuthEnabled;
        return $this;
    }

    public function getEmailAuthRecipient(): string
    {
        return $this->getEmail();
    }

    public function getEmailAuthCode(): ?string
    {
        return $this->emailAuthCode;
    }

    public function setEmailAuthCode(string $authCode): void
    {
        $this->emailAuthCode = $authCode;
    }

    #[ORM\Column(type:"integer")]
    protected $trustedTokenVersion = 0;

    public function getTrustedTokenVersion(): int
    {
        return $this->trustedTokenVersion;
    }

    /**
     * Bump this to invalidate every previously-trusted device for this user (e.g. after disabling 2FA).
     */
    public function invalidateTrustedDevices(): self
    {
        $this->trustedTokenVersion++;
        return $this;
    }

    #[ORM\Column(type:"string", length:255, unique:true)]
    #[Assert\Email(groups:["new", "edit"])]
    protected $email;

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower($email);

        return $this;
    }

    #[ORM\Column(type:"string", length:15, nullable:true)]
    protected $phone;

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    #[ORM\Column(type:"text", nullable:true)]
    #[Uploader(max_size:"5MB", mime_types:["image/*"], fetch:true)]
    #[AssertBase\File(max_size:"5MB", mime_types:["image/*"])]
    protected $avatar;

    /**
     * @return array|mixed|File|null
     * @throws Exception
     */
    public function getAvatar()
    {
        return Uploader::getPublic($this, "avatar");
    }

    /**
     * @return array|mixed|File|null
     * @throws FilesystemException
     */
    public function getAvatarFile()
    {
        return Uploader::get($this, "avatar");
    }

    /**
     * @return $this
     */
    public function setAvatar($avatar)
    {
        $this->avatar = $avatar;
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

    #[ORM\Column(type:"string", length:16, nullable:true)]
    #[Assert\Locale(canonicalize: true)]
    protected $locale;

    public function getLocale(): ?string
    {
        return $this->locale ?? Localizer::__toLocale($this->getTranslator()->getLocale());
    }

    public function setLocale(?string $locale = null): self
    {
        if (empty($locale)) {
            $locale = $this->locale ?? null;
        }
        $this->locale = $locale ?? Localizer::__toLocale($this->getTranslator()->getLocale());

        if (!$this->locale) {
            throw new MissingLocaleException("Missing locale.");
        }

        return $this;
    }

    /**
     * @var string Plain password should be empty unless you want to change it
     */
    #[Assert\NotCompromisedPassword]
    #[AssertBase\Password(min_strength:4, min_length:8)]
    protected $plainPassword;

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $password): void
    {
        $this->plainPassword = $password;
        $this->updatedAt = new DateTime("now"); // Plain password is not an ORM variable..
    }

    public function __serialize(): array
    {
        $this->erasePlainPassword();
        return (array) $this;
    }

    /**
     * @return $this
     */
    public function erasePlainPassword()
    {
        $this->plainPassword = null;
        return $this;
    }

    #[\Deprecated]
    public function eraseCredentials(): void { } // @TODO: deprecated to be removed in Symfony >7.3
    
    /**
     * @var string The hashed password
     */
    #[ORM\Column(type:"string", nullable:true)]
    #[Assert\NotBlank(groups:["new", "edit"], allowNull:true)]
    #[Hashify(reference:"plainPassword", algorithm:"auto")]
    protected $password;

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    #[ORM\Column(type: "user_role")]
    #[Assert\NotBlank(groups: ["new", "edit"])]
    #[OrderColumn(orderBy: "rolesPositions")]
    protected $roles = [];
    protected $rolesPositions;

    /**
     * Slug of the external identity provider this account originates from (e.g. "google"),
     * or null for a locally-registered (password-based) account.
     */
    #[ORM\Column(type:"string", length:32, nullable:true)]
    protected $identityProvider = null;

    public function getIdentityProvider(): ?string
    {
        return $this->identityProvider;
    }

    public function setIdentityProvider(?string $identityProvider): self
    {
        $this->identityProvider = $identityProvider;
        return $this;
    }

    public function isFederated(): bool
    {
        return $this->identityProvider !== null;
    }

    public function isPersistent(): bool
    {
        return (!$this->isFederated() || $this->id > 0);
    }

    private ?array $effectiveRoles = null;

    /**
     * Effective roles for security checks: this user's own roles unioned
     * with every group they belong to. Memoized per-instance (busted by
     * setRoles()/addGroup()/removeGroup()) since this is called on every
     * auth check and getGroups() would otherwise trigger a lazy load each time.
     */
    public function getRoles(): array
    {
        if (null !== $this->effectiveRoles) {
            return $this->effectiveRoles;
        }

        $roles = $this->getOwnRoles();
        foreach ($this->getGroups() as $group) {
            $roles = array_merge($roles, $group->getRoles());
        }

        return $this->effectiveRoles = array_values(array_unique(array_filter($roles)));
    }

    /** Only this user's own roles - what the `roles` column stores, what edit forms bind to. */
    public function getOwnRoles(): array
    {
        if (empty($this->roles)) {
            $this->roles[] = UserRole::USER;
        }

        return $this->roles;
    }

    public function setRoles(array $roles): self
    {
        if (empty($roles)) {
            $roles[] = UserRole::USER;
        }

        $this->roles = array_filter(array_unique($roles));
        $this->effectiveRoles = null;
        return $this;
    }

    /** Alias so form binding on 'ownRoles' has a matching setter - writes only the own column, same as setRoles(). */
    public function setOwnRoles(array $roles): self
    {
        return $this->setRoles($roles);
    }

    #[ORM\ManyToMany(targetEntity: Group::class, inversedBy:"members", orphanRemoval:true, cascade:["persist", "remove"])]
    protected $groups;

    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): self
    {
        if (!$this->groups->contains($group)) {
            $this->groups[] = $group;
        }

        $this->effectiveRoles = null;
        return $this;
    }

    public function removeGroup(Group $group): self
    {
        $this->groups->removeElement($group);

        $this->effectiveRoles = null;
        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Permission::class, inversedBy:"uid", orphanRemoval:true, cascade:["persist", "remove"])]
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
     * Every penalty this user has ever been given, spent ones included.
     *
     * A Sanction rather than the many-to-many this replaces, because a join
     * row cannot say who issued a penalty, why, or when it lapses - see
     * Base\Entity\User\Sanction.
     */
    #[ORM\OneToMany(targetEntity: Sanction::class, mappedBy: "user", orphanRemoval: true, cascade: ["persist", "remove"])]
    protected $sanctions;

    public function getSanctions(): Collection
    {
        return $this->sanctions;
    }

    /** Only the ones still standing: not lifted, not lapsed. */
    public function getActiveSanctions(?DateTimeInterface $at = null): Collection
    {
        return $this->sanctions->filter(fn (Sanction $sanction) => $sanction->isActive($at));
    }

    public function addSanction(Sanction $sanction): self
    {
        if (!$this->sanctions->contains($sanction)) {
            $this->sanctions[] = $sanction;
            $sanction->setUser($this);
        }

        return $this;
    }

    public function removeSanction(Sanction $sanction): self
    {
        $this->sanctions->removeElement($sanction);

        return $this;
    }

    /**
     * The catalogue entries behind the sanctions still standing.
     *
     * Kept under the old name so callers asking "what penalties does this
     * user have" still read naturally, but it now answers with what is in
     * force rather than with everything ever recorded.
     *
     * @return Penalty[]
     */
    public function getPenalties(?DateTimeInterface $at = null): array
    {
        return array_values(array_filter(array_map(
            fn (Sanction $sanction) => $sanction->getPenalty(),
            $this->getActiveSanctions($at)->toArray()
        )));
    }

    /**
     * What this account is worth right now.
     *
     * The sum of every sanction still in force, plus whatever the groups it
     * belongs to carry. Signed, because the catalogue is: a penalty may cost
     * points and a credit may award them, so one number answers both "is this
     * account in trouble" and "has it earned its way in somewhere".
     *
     * Group penalties count because membership is the point of a group - a
     * group under sanction puts every member under it, without having to
     * write the same sanction out per head.
     *
     * Not cached. The obvious cache is per-request, and the obvious bug is a
     * moderator lifting a sanction and the score not moving until the next
     * page load; callers that need it hot should hold it themselves.
     */
    public function getScore(?DateTimeInterface $at = null): int
    {
        $score = 0;

        foreach ($this->sanctions as $sanction) {
            $score += $sanction->getWeight($at);
        }

        foreach ($this->getGroups() as $group) {
            $score += $group->getScore();
        }

        return $score;
    }

    /**
     * Where that score puts them, as a discrete level.
     *
     * Thresholds are the lower bound of each level and must stay ascending.
     * A public constant rather than a setting because nothing reads it yet:
     * when levels start gating anything real this wants to move behind
     * base.settings, and one constant is a smaller thing to move than a
     * scattering of inline numbers.
     */
    public const LEVELS = [0, 100, 250, 500, 1000];

    public function getLevel(?DateTimeInterface $at = null): int
    {
        $score = $this->getScore($at);

        $level = 0;
        foreach (static::LEVELS as $index => $threshold) {
            if ($score >= $threshold) {
                $level = $index;
            }
        }

        return $level;
    }

    #[ORM\OneToMany(targetEntity:Notification::class, mappedBy:"user", orphanRemoval:true, cascade:["persist", "remove"])]
    protected $notifications;

    /**
     * @return ArrayCollection
     */
    public function getNotifications()
    {
        return $this->notifications;
    }

    public function addNotification(Notification $notification): self
    {
        if (!$this->notifications->contains($notification)) {
            $this->notifications[] = $notification;
        }

        return $this;
    }

    public function removeNotification(Notification $notification): self
    {
        if ($this->notifications->removeElement($notification)) {
            // set the owning side to null (unless already changed)
            if ($notification->getUser() === $this) {
                $notification->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function isTester()
    {
        if (!$this->getService()->isDebug()) {
            return false;
        }

        foreach ($this->getService()->getParameterBag("base.notifier.test_recipients") as $testRecipient) {
            if (preg_match("/" . $testRecipient . "/", $this->getEmail())) {
                return true;
            }
        }

        return false;
    }

    #[ORM\OneToMany(targetEntity:Token::class, mappedBy:"user", orphanRemoval:true, cascade:["persist", "remove"])]
    protected $tokens;

    public function getExpiredTokens(): Collection
    {
        return $this->getTokens(Token::EXPIRED);
    }

    public function getValidTokens(): Collection
    {
        return $this->getTokens(Token::VALID);
    }

    /**
     * @return Collection
     */
    public function getTokens($type = Token::ALL): Collection
    {
        return $this->tokens->filter(function ($token) use ($type) {
            return match ($type) {
                Token::VALID => $token->isValid(),
                Token::EXPIRED => !$token->isValid(),
                default => true,
            };

        });
    }

    public function removeExpiredTokens(): self
    {
        $expiredTokens = $this->getExpiredTokens();
        foreach ($expiredTokens as $token) {
            $this->removeToken($token);
        }

        return $this;
    }

    public function getExpiredToken(string $name): ?Token
    {
        return $this->getToken($name, Token::EXPIRED);
    }

    public function getValidToken(string $name): ?Token
    {
        return $this->getToken($name, Token::VALID);
    }

    /**
     * @param string $name
     * @return Token|null
     */
    public function getToken(string $name, $type = Token::ALL): ?Token
    {
        $tokens = $this->getTokens($type);
        foreach ($tokens as $token) {
            if ($token->getName() == $name) {
                return $token;
            }
        }

        return null;
    }

    public function addToken(Token $token): self
    {
        if (!$this->tokens->contains($token)) {
            $this->removeTokenByName($token->getName());

            $this->tokens[] = $token;
            $token->setUser($this);
        }

        return $this;
    }

    public function removeTokenByName(?string $name): self
    {
        $tokens = $this->getTokens();
        foreach ($tokens as $token) {
            if ($token->getName() == $name) {
                $this->removeToken($token);
            }
        }

        return $this;
    }

    public function removeToken(Token $token): self
    {
        if ($this->tokens->removeElement($token)) {
            // set the owning side to null (unless already changed)
            if ($token->getUser() === $this) {
                $token->setUser(null);
            }
        }

        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Thread::class, mappedBy:"owners")]
    protected $threads;

    public function getThreads(): Collection
    {
        return $this->threads;
    }

    public function addThread(Thread $thread): self
    {
        if (!$this->threads->contains($thread)) {
            $this->threads[] = $thread;
            $thread->addOwner($this);
        }

        return $this;
    }

    public function removeThread(Thread $thread): self
    {
        if ($this->threads->removeElement($thread)) {
            $thread->removeOwner($this);
        }

        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Thread::class, mappedBy: "followers")]
    protected $followedThreads;

    public function isFollowing(Thread $followedThread): bool
    {
        return $this->followedThreads->contains($followedThread);
    }

    public function getFollowedThreads(): Collection
    {
        return $this->followedThreads;
    }

    public function addFollowedThread(Thread $thread): self
    {
        if (!$this->followedThreads->contains($thread)) {
            $this->followedThreads[] = $thread;
        }

        return $this;
    }

    public function removeFollowedThread(Thread $thread): self
    {
        $this->followedThreads->removeElement($thread);

        return $this;
    }


    #[ORM\OneToMany(targetEntity:Mention::class, mappedBy:"mentionee", orphanRemoval:true, cascade:["persist", "remove"])]
    protected $mentions;
    public function getMentions(): Collection
    {
        return $this->mentions;
    }

    public function addMentions(Mention $mentions): self
    {
        if (!$this->mentions->contains($mentions)) {
            $this->mentions[] = $mentions;
            $mentions->setMentionee($this);
        }

        return $this;
    }

    public function removeMentions(Mention $mentions): self
    {
        if ($this->mentions->removeElement($mentions)) {
            // set the owning side to null (unless already changed)
            if ($mentions->getMentionee() === $this) {
                $mentions->setMentionee(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity:Like::class, mappedBy:"user", orphanRemoval:true, cascade:["persist", "remove"])]
    protected $likes;

    public function getLikes(): Collection
    {
        return $this->likes;
    }

    public function addLike(Like $like): self
    {
        if (!$this->likes->contains($like)) {
            $this->likes[] = $like;
            $like->setUser($this);
        }

        return $this;
    }

    public function removeLike(Like $like): self
    {
        if ($this->likes->removeElement($like)) {
            // set the owning side to null (unless already changed)
            if ($like->getUser() === $this) {
                $like->setUser(null);
            }
        }

        return $this;
    }


    #[ORM\Column(type:"user_state")]
    protected $states = [];

    public function getStates(): array
    {
        return $this->states;
    }

    public function setStates(array $states): self
    {
        $this->states = array_unique($states);
        return $this;
    }

    public function newcomer(bool $newState = true): self
    {
        return $this->setIsNewcomer($newState);
    }

    public function elder(bool $newState = true): self
    {
        return $this->setIsNewcomer(!$newState);
    }

    public function isNewcomer(): bool
    {
        return in_array(UserState::NEWCOMER, $this->states);
    }

    public function isElder(): bool
    {
        return !$this->isNewcomer();
    }

    public function setIsNewcomer(bool $newState): self
    {
        $this->states = $newState ? array_values_insert($this->states, UserState::NEWCOMER) : array_values_remove($this->states, UserState::NEWCOMER);
        return $this;
    }

    public function approve(bool $newState = true): self
    {
        return $this->setIsApproved($newState);
    }

    public function disregarded(bool $newState = true): self
    {
        return $this->setIsApproved(!$newState);
    }

    public function isApproved(): bool
    {
        return in_array(UserState::APPROVED, $this->states);
    }

    public function isDisregarded(): bool
    {
        return !$this->isApproved();
    }

    public function setIsApproved(bool $newState): self
    {
        $this->states = $newState ? array_values_insert($this->states, UserState::APPROVED) : array_values_remove($this->states, UserState::APPROVED);
        return $this;
    }

    public function verify(bool $newState = true): self
    {
        return $this->setIsVerified($newState);
    }

    public function isVerified(): bool
    {
        return in_array(UserState::VERIFIED, $this->states);
    }

    /**
     * @param bool $newState
     * @return $this
     */
    public function setIsVerified(bool $newState)
    {
        $this->states = $newState ? array_values_insert($this->states, UserState::VERIFIED) : array_values_remove($this->states, UserState::VERIFIED);
        return $this;
    }

    public function enable(bool $newState = true): self
    {
        return $this->setIsEnabled($newState);
    }

    public function disable(bool $newState = true): self
    {
        return $this->setIsEnabled(!$newState);
    }

    public function isEnabled(): bool
    {
        return in_array(UserState::ENABLED, $this->states);
    }

    public function isDisabled(): bool
    {
        return !$this->isEnabled();
    }

    public function setIsEnabled(bool $newState): self
    {
        $this->states = $newState ? array_values_insert($this->states, UserState::ENABLED) : array_values_remove($this->states, UserState::ENABLED);
        return $this;
    }

    public function lock(bool $newState = true): self
    {
        return $this->setIsLocked($newState);
    }

    public function unlock(bool $newState = true): self
    {
        return $this->setIsLocked(!$newState);
    }

    public function isLocked(): bool
    {
        return in_array(UserState::LOCKED, $this->states);
    }

    public function setIsLocked(bool $newState): self
    {
        $this->states = $newState ? array_values_insert($this->states, UserState::LOCKED) : array_values_remove($this->states, UserState::LOCKED);
        return $this;
    }

    public function ban(bool $newState = true): self
    {
        return $this->setIsBanned($newState);
    }

    public function unban(bool $newState = true): self
    {
        return $this->setIsBanned(!$newState);
    }

    public function isBanned(): bool
    {
        return in_array(UserState::BANNED, $this->states);
    } // TO IMPLEMENT..

    public function setIsBanned(bool $newState = true): self
    {
        $this->states = $newState ? array_values_insert($this->states, UserState::BANNED) : array_values_remove($this->states, UserState::BANNED);
        return $this;
    }

    public function kick(bool $newState = true): self
    {
        return $this->setIsKicked($newState);
    }

    public function unkick(bool $newState = true): self
    {
        return $this->setIsKicked(!$newState);
    }

    public function isKicked(): bool
    {
        return in_array(UserState::KICKED, $this->states);
    }

    public function setIsKicked(bool $newState = true): self
    {
        $this->states = $newState ? array_values_insert($this->states, UserState::KICKED) : array_values_remove($this->states, UserState::KICKED);
        return $this;
    }

    public function ghost(bool $newState = true): self
    {
        return $this->setIsGhost($newState);
    }

    public function isGhost(): bool
    {
        return in_array(UserState::GHOST, $this->states);
    }

    public function setIsGhost(bool $newState): self
    {
        $this->states = $newState ? array_values_insert($this->states, UserState::GHOST) : array_values_remove($this->states, UserState::GHOST);
        return $this;
    }

    #[ORM\Column(type:"datetime", nullable:true)]
    protected $birthdate;
    public function getBirthdate(): ?DateTimeInterface
    {
        return $this->birthdate;
    }
    public function setBirthdate(?DateTimeInterface $birthdate): self
    {
        $this->birthdate = $birthdate;
        return $this;
    }

    public function getAge(): ?int
    {
        if ($this->birthdate === null) {
            return null;
        }

        $today = new DateTime('now');
        $age = $today->diff($this->birthdate);

        return $age->y;
    }

    #[ORM\Column(type:"datetime")]
    #[Timestamp(on:"create")]
    protected $createdAt;
    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    #[ORM\Column(type:"datetime")]
    #[Timestamp(on:["create", "update"])]
    protected $updatedAt;
    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    #[ORM\Column(type:"datetime", nullable:true)]
    protected $activeAt;
    public function getActiveAt(): ?DateTimeInterface
    {
        return $this->activeAt;
    }

    public function poke(?DateTimeInterface $activeAt): self
    {
        $this->activeAt = $activeAt;
        return $this;
    }

    public static function getActiveDelay(): int
    {
        return self::getParameterBag()->get("base.user.active_delay");
    }

    public function isActive(): bool
    {
        return $this->getActiveAt() && $this->getActiveAt() > new DateTime($this->getActiveDelay() . ' seconds ago');
    }

    public static function getOnlineDelay(): int
    {
        return self::getParameterBag()->get("base.user.online_delay");
    }

    public function isOnline(): bool
    {
        return $this->getActiveAt() && $this->getActiveAt() > new DateTime($this->getOnlineDelay() . ' seconds ago');
    }

    #[ORM\OneToMany(targetEntity:Address::class, mappedBy:"user")]
    protected $addresses;

    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    public function getAddress(int $i = 0): ?Address
    {
        return $this->addresses[$i] ?? null;
    }

    public function addAddress(Address $address): self
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses[] = $address;
            $address->setUser($this);
        }

        return $this;
    }

    public function removeAddress(Address $address): self
    {
        if ($this->addresses->removeElement($address)) {
            // set the owning side to null (unless already changed)
            if ($address->getUser() === $this) {
                $address->setUser(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity:Connection::class, mappedBy:"user", orphanRemoval:true)]
    protected $connections;
    public function getConnections(): Collection
    {
        return $this->connections;
    }

    public function addConnection(Connection $connection): self
    {
        $this->connections[] = $connection;

        if (!$this->connections->contains($connection)) {
            $this->connections[] = $connection;
            $connection->setUser($this);
        }

        return $this;
    }

    public function removeConnection(Connection $connection): self
    {
        if ($this->connections->removeElement($connection)) {

            // set the owning side to null (unless already changed)
            if ($connection->getUser() === $this) {
                $connection->setUser(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity:Passkey::class, mappedBy:"user", orphanRemoval:true, cascade:["persist", "remove"])]
    protected $passkeys;
    public function getPasskeys(): Collection
    {
        return $this->passkeys;
    }

    public function addPasskey(Passkey $passkey): self
    {
        if (!$this->passkeys->contains($passkey)) {
            $this->passkeys[] = $passkey;
            $passkey->setUser($this);
        }

        return $this;
    }

    public function removePasskey(Passkey $passkey): self
    {
        if ($this->passkeys->removeElement($passkey)) {
            if ($passkey->getUser() === $this) {
                $passkey->setUser(null);
            }
        }

        return $this;
    }
}
