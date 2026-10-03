<?php

namespace Base\Entity\Hours;

use Base\Repository\Hours\SpecialDayRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A day (or a run of days) off the usual week: closed (holidays, a bank
 * holiday), or open at other hours ([["10:00","14:00"]]). Read by
 * Base\Service\OpeningHours everywhere (the day an order is for, the time
 * slots, the footer, a banner, the JSON-LD's specialOpeningHoursSpecification).
 *
 * Its own table (hoursSpecialDay), see WeekDayHours.
 */
#[ORM\Entity(repositoryClass: SpecialDayRepository::class)]
#[ORM\Table(name: 'hoursSpecialDay')]
#[ORM\Index(columns: ['endsOn'], name: 'hours_special_day_ends_idx')]
class SpecialDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /** Calendar days, compared as 'Y-m-d' strings (no timezone to get wrong). */
    #[ORM\Column(type: 'string', length: 10)]
    protected string $startsOn;

    #[ORM\Column(type: 'string', length: 10)]
    protected string $endsOn;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    protected ?string $reason = null;

    /** @var array<array{0: string, 1: string}>|null open at these hours instead; null = closed */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $hours = null;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeInterface $from, ?\DateTimeInterface $until = null, ?string $reason = null)
    {
        $this->startsOn = $from->format('Y-m-d');
        $this->endsOn = ($until ?? $from)->format('Y-m-d');
        if ($this->endsOn < $this->startsOn) {
            [$this->startsOn, $this->endsOn] = [$this->endsOn, $this->startsOn];
        }
        $this->reason = $reason ?: null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getStartsOn(): string { return $this->startsOn; }
    public function getEndsOn(): string { return $this->endsOn; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $this->reason = $reason ?: null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getHours(): ?array { return $this->hours; }

    /** @param array<array{0: string, 1: string}>|null $hours */
    public function setHours(?array $hours): static
    {
        foreach ($hours ?? [] as [$open, $close]) {
            if (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close) || $close <= $open) {
                throw new \InvalidArgumentException('Hours are [["HH:MM", "HH:MM"], ...], closing after opening.');
            }
        }
        $this->hours = $hours ?: null;

        return $this;
    }

    public function isClosed(): bool { return null === $this->hours; }

    public function covers(\DateTimeInterface $day): bool
    {
        $d = $day->format('Y-m-d');

        return $this->startsOn <= $d && $d <= $this->endsOn;
    }

    public function isOneDay(): bool { return $this->startsOn === $this->endsOn; }

    public function from(): \DateTimeImmutable { return new \DateTimeImmutable($this->startsOn); }
    public function until(): \DateTimeImmutable { return new \DateTimeImmutable($this->endsOn); }
}
