<?php

namespace Base\Entity\Thread;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Database\Attribute\Timestamp;
use Base\Entity\Thread;
use App\Entity\User;
use Base\Enum\CommentState;
use Base\Enum\SpamScore;
use Base\Repository\Thread\CommentRepository;
use Base\Service\Model\SpamProtectionInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a visitor leaves under a thread (a blog post, a sequence, a video) -
 * or, with no thread, in the site's visitors' book: a name, an e-mail (never
 * shown), a site, a text, and for a reply the comment it answers. Signed in,
 * the author is kept too. Moved here from omnibase/blog.
 *
 * It implements SpamProtectionInterface: the form's model sends the text to
 * Akismet before it is saved (base.spam.akismet), and the score lands in
 * getSpamCallback(): a doubtful comment waits (PENDING), a clean one is
 * APPROVED when $autoApprove says so.
 *
 * JOINED, with a discriminator: a bundle that needs more on its comments (a
 * video's time code) extends it with a #[DiscriminatorEntry] of its own.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'threadComment')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'class', type: 'string')]
#[DiscriminatorEntry(value: 'comment')]
#[ORM\Index(columns: ['createdAt'], name: 'thread_comment_created_idx')]
#[ORM\Index(columns: ['ip', 'createdAt'], name: 'thread_comment_ip_idx')]
class Comment implements SpamProtectionInterface
{
    /** Set before the spam check: whether a clean comment goes online at once. */
    public bool $autoApprove = true;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /** The thread commented; null: the visitors' book. */
    #[ORM\ManyToOne(targetEntity: Thread::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?Thread $thread = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?User $author = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    protected ?string $name = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    protected ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 255)]
    protected ?string $website = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 4000)]
    protected ?string $content = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    protected ?Comment $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    protected Collection $replies;

    #[ORM\Column(type: 'string', length: 16, enumType: CommentState::class)]
    protected CommentState $state = CommentState::PENDING;

    /** What Akismet said (SpamScore), kept so a moderator sees why a comment waited. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    protected ?int $spamScore = null;

    #[ORM\Column(length: 45, nullable: true)]
    protected ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $userAgent = null;

    #[ORM\Column(type: 'datetime')]
    #[Timestamp(on: 'create')]
    protected ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $moderatedAt = null;

    public function __construct(?Thread $thread = null, ?User $author = null)
    {
        $this->thread = $thread;
        $this->replies = new ArrayCollection();
        if ($author) {
            $this->author = $author;
            $this->name = (string) $author;
            $this->email = $author->getEmail();
        }
    }

    public function __toString(): string
    {
        return mb_strimwidth((string) $this->content, 0, 60, '…');
    }

    public function getId(): ?int { return $this->id; }

    public function getThread(): ?Thread { return $this->thread; }
    public function setThread(?Thread $thread): static { $this->thread = $thread; return $this; }

    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $author): static { $this->author = $author; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): static { $this->name = $name ? trim($name) : null; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email ? mb_strtolower(trim($email)) : null; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $website): static { $this->website = $website ? trim($website) : null; return $this; }

    public function getContent(): ?string { return $this->content; }
    public function setContent(?string $content): static { $this->content = $content ? trim($content) : null; return $this; }

    public function getParent(): ?Comment { return $this->parent; }
    public function setParent(?Comment $parent): static
    {
        // One level of replies: answering a reply answers its comment.
        $this->parent = $parent?->getParent() ?? $parent;

        return $this;
    }

    /** @return Collection<int, Comment> */
    public function getReplies(): Collection { return $this->replies; }

    /** @return list<Comment> the replies online */
    public function getVisibleReplies(): array
    {
        return array_values($this->replies->filter(fn (Comment $reply) => $reply->isVisible())->toArray());
    }

    public function getState(): CommentState { return $this->state; }
    public function setState(CommentState $state): static
    {
        $this->state = $state;
        $this->moderatedAt = new \DateTime();

        return $this;
    }

    public function isVisible(): bool { return $this->state->isVisible(); }
    public function isPending(): bool { return CommentState::PENDING === $this->state; }

    public function approve(): static { return $this->setState(CommentState::APPROVED); }
    public function markAsSpam(): static { return $this->setState(CommentState::SPAM); }
    public function trash(): static { return $this->setState(CommentState::TRASH); }

    public function getSpamScore(): ?int { return $this->spamScore; }

    public function getIp(): ?string { return $this->ip; }
    public function setIp(?string $ip): static { $this->ip = $ip; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): static { $this->userAgent = $userAgent ? mb_strimwidth($userAgent, 0, 255) : null; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getModeratedAt(): ?\DateTimeInterface { return $this->moderatedAt; }

    /** How the comment is signed: the account's name, or the name typed. */
    public function getDisplayName(): string
    {
        return $this->author ? (string) $this->author : (string) $this->name;
    }

    // ── SpamProtectionInterface ───────────────────────────────────────

    public function getSpamBlameable(): ?User { return $this->author; }
    public function getSpamText(): ?string { return $this->content; }
    public function getSpamDate(): \DateTime { return \DateTime::createFromInterface($this->createdAt ?? new \DateTime()); }

    /**
     * The classifier's verdict (Akismet). NOT_SPAM goes online when the site
     * auto-approves, else waits (PENDING); spam is kept aside (SPAM), for a
     * person to read - and to report as ham if it was not; NO_TEXT (no
     * classifier, or an empty text) is treated as clean - the form already
     * refused an empty text.
     */
    public function getSpamCallback(int $score): void
    {
        $this->spamScore = $score;
        $scores = SpamScore::__toInt();
        $this->state = match (true) {
            $score >= $scores[SpamScore::MAYBE_SPAM] => CommentState::SPAM,
            $this->autoApprove => CommentState::APPROVED,
            default => CommentState::PENDING,
        };
    }
}
