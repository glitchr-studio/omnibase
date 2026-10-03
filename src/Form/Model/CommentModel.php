<?php

namespace Base\Form\Model;

use Base\Entity\Thread;
use Base\Entity\Thread\Comment;
use App\Entity\User;
use Base\Service\Model\SpamProtectionInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the comment form (Base\Form\Type\CommentType) edits - not the entity
 * (omnibase's FormFactory refuses an entity as form data): the name, the
 * e-mail, the site, the text. It implements SpamProtectionInterface so
 * omnibase's FormTypeSpamExtension sends it to Akismet; the score lands
 * here, and toComment() carries it onto the Comment to save.
 */
class CommentModel implements SpamProtectionInterface
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 255)]
    public ?string $website = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 4000)]
    public ?string $content = null;

    public ?User $author = null;
    public ?int $spamScore = null;

    public static function forUser(?User $user): static
    {
        $model = new static();
        if ($user) {
            $model->author = $user;
            $model->name = (string) $user;
            $model->email = $user->getEmail();
        }

        return $model;
    }

    public function getSpamBlameable(): ?User { return $this->author; }
    public function getSpamText(): ?string { return $this->content; }
    public function getSpamDate(): \DateTime { return new \DateTime(); }
    public function getSpamCallback(int $score): void { $this->spamScore = $score; }

    /**
     * The entity to save, Akismet's score applied (the state follows from it
     * and from $autoApprove).
     *
     * @param class-string<Comment> $class a subclass of Comment, for a bundle that extends it
     */
    public function toComment(?Thread $thread, bool $autoApprove, string $class = Comment::class): Comment
    {
        /** @var Comment $comment */
        $comment = new $class($thread, $this->author);
        $comment->autoApprove = $autoApprove;
        if (!$this->author) {
            $comment->setName($this->name);
            $comment->setEmail($this->email);
            $comment->setWebsite($this->website);
        }
        $comment->setContent($this->content);
        $comment->getSpamCallback($this->spamScore ?? 0);

        return $comment;
    }
}
