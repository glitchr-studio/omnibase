<?php

namespace Base\Entity\Layout;

use Base\Repository\Layout\TextOverrideRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A text of the site, as the team rewrote it from the back office: it
 * replaces the one in translations/ for that key, domain and language
 * (Base\Translation\OverridingTranslator), on every page and in every
 * e-mail. Deleting it brings the original back. Same syntax as the
 * original: {placeholders}, ICU plurals. Moved here from the apps'
 * App\Entity\TextOverride (table textOverride there; this one is
 * layoutTextOverride, so an app not moved yet keeps its own).
 */
#[ORM\Entity(repositoryClass: TextOverrideRepository::class)]
#[ORM\Table(name: 'layoutTextOverride')]
#[ORM\UniqueConstraint(name: 'layout_text_override_key', columns: ['domain', 'textKey', 'locale'])]
#[UniqueEntity(fields: ['domain', 'key', 'locale'], message: 'This text is already rewritten in this language: edit that one.')]
class TextOverride
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 32)]
    protected string $domain = 'messages';

    #[ORM\Column(name: 'textKey', length: 190)]
    #[Assert\NotBlank]
    protected string $key = '';

    #[ORM\Column(length: 8)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 8)]
    protected string $locale = 'fr';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    protected string $value = '';

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->key.' ('.$this->locale.')';
    }

    public function getId(): ?int { return $this->id; }
    public function getDomain(): string { return $this->domain; }
    public function setDomain(string $domain): static { $this->domain = $domain; return $this; }
    public function getKey(): string { return $this->key; }
    public function setKey(string $key): static { $this->key = trim($key); return $this; }
    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $value): static { $this->value = $value; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
