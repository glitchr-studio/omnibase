<?php

namespace Base\Entity\Layout;

use Base\Database\Type\UtcDateTimeImmutableType;
use Base\Repository\Layout\RedirectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An address the site no longer answers, and where it leads now: the pages
 * of a site taken over (/produit/enseigne-lumineuse/ -> /savoir-faire/enseigne),
 * a page renamed. Asked only when a request ends in "not found"
 * (Base\Subscriber\RedirectionSubscriber): a page that exists is never
 * shadowed, and a page that is found costs nothing.
 *
 * The source is a path, kept without its trailing slash ("/produit/enseigne");
 * with a query string ("/?p=123") it is matched with that query. Ending in
 * "*" it covers everything beneath ("/categorie-produit/*"), and a "*" in
 * the target then carries what the star stood for.
 *
 * 301 by default: the old address is gone for good, and search engines move
 * what they know of it to the new one. 302 for a detour that will not last.
 */
#[ORM\Entity(repositoryClass: RedirectionRepository::class)]
#[ORM\Table(name: 'layoutRedirection')]
#[ORM\UniqueConstraint(name: 'layout_redirection_source', columns: ['source'])]
#[UniqueEntity(fields: ['source'], message: 'This address is already redirected: edit that one.')]
class Redirection
{
    public const PERMANENT = 301;
    public const TEMPORARY = 302;
    public const STATUSES = [self::PERMANENT, self::TEMPORARY];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 190)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 190)]
    protected string $source = '';

    #[ORM\Column(length: 2048)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 2048)]
    protected string $target = '';

    #[ORM\Column(type: 'smallint')]
    #[Assert\Choice(choices: self::STATUSES)]
    protected int $status = self::PERMANENT;

    #[ORM\Column(type: 'boolean')]
    protected bool $enabled = true;

    /** How many visitors it carried. */
    #[ORM\Column(type: 'integer')]
    protected int $hits = 0;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    protected ?\DateTimeImmutable $lastHitAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    protected \DateTimeImmutable $createdAt;

    public function __construct(string $source = '', string $target = '', int $status = self::PERMANENT)
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ('' !== $source) {
            $this->setSource($source);
        }
        if ('' !== $target) {
            $this->setTarget($target);
        }
        $this->setStatus($status);
    }

    public function __toString(): string
    {
        return $this->source.' → '.$this->target;
    }

    /**
     * An address as it is stored and compared: its path, without the site's
     * host, without its trailing slash, with its query when it has one.
     * "https://old.example/produit/enseigne/?x=1#top" is "/produit/enseigne?x=1".
     */
    public static function normalize(string $address): string
    {
        $address = trim($address);
        if ('' === $address) {
            return '';
        }

        $parts = parse_url($address) ?: [];
        $path = '/'.ltrim(rawurldecode($parts['path'] ?? '/'), '/');
        if ('/' !== $path) {
            $path = rtrim($path, '/');
        }
        $query = $parts['query'] ?? '';

        return $path.('' !== $query ? '?'.$query : '');
    }

    public function getId(): ?int { return $this->id; }

    public function getSource(): string { return $this->source; }

    public function setSource(string $source): static
    {
        $this->source = self::normalize($source);

        return $this;
    }

    /** Covers everything beneath its path ("/categorie-produit/*"). */
    public function isPrefix(): bool
    {
        return str_ends_with($this->source, '*');
    }

    public function getTarget(): string { return $this->target; }

    /** A path of the site ("/savoir-faire/enseigne") or a whole address ("https://..."). */
    public function setTarget(string $target): static
    {
        $target = trim($target);
        if ('' !== $target && !preg_match('#^https?://#i', $target)) {
            $target = '/'.ltrim($target, '/');
        }
        $this->target = $target;

        return $this;
    }

    public function getStatus(): int { return $this->status; }

    public function setStatus(int $status): static
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('A redirection is permanent (301) or temporary (302).');
        }
        $this->status = $status;

        return $this;
    }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this; }

    public function getHits(): int { return $this->hits; }
    public function getLastHitAt(): ?\DateTimeImmutable { return $this->lastHitAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Where this redirection sends $address (normalized), or null when it is
     * not for it. A prefix carries what its star stood for into the target's.
     */
    public function resolve(string $address): ?string
    {
        if (!$this->enabled || '' === $this->target) {
            return null;
        }

        if (!$this->isPrefix()) {
            return $address === $this->source ? $this->target : null;
        }

        $prefix = substr($this->source, 0, -1);
        $path = strtok($address, '?');
        if (!str_starts_with($path.'/', $prefix)) { // "/categorie/*" covers "/categorie" itself too
            return null;
        }
        $rest = (string) substr($path, \strlen($prefix));

        return str_contains($this->target, '*') ? str_replace('*', ltrim($rest, '/'), $this->target) : $this->target;
    }
}
