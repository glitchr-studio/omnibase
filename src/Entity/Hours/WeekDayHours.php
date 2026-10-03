<?php

namespace Base\Entity\Hours;

use Base\Repository\Hours\WeekDayHoursRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The usual hours of one day of the week: [["09:00","13:00"],["16:30","19:00"]],
 * or none (closed). No row at all: the week the site was configured with
 * (base.opening_hours.week), read by Base\Service\OpeningHours. Special days
 * (SpecialDay) override a date.
 *
 * Its own table (hoursWeekDay): an application that still keeps a copy of its
 * own (Nakaya's weekDayHours) is not disturbed; moving onto this one is a
 * RENAME TABLE.
 */
#[ORM\Entity(repositoryClass: WeekDayHoursRepository::class)]
#[ORM\Table(name: 'hoursWeekDay')]
class WeekDayHours
{
    /** ISO day: 1 = Monday ... 7 = Sunday */
    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    protected int $weekday;

    /** @var array<array{0: string, 1: string}> */
    #[ORM\Column(type: 'json')]
    protected array $hours = [];

    public function __construct(int $weekday, array $hours = [])
    {
        if ($weekday < 1 || $weekday > 7) {
            throw new \InvalidArgumentException('A weekday is 1 (Monday) to 7 (Sunday).');
        }
        $this->weekday = $weekday;
        $this->setHours($hours);
    }

    public function getWeekday(): int { return $this->weekday; }
    /** omnibase's listeners ask every entity for one. */
    public function getId(): int { return $this->weekday; }
    public function getHours(): array { return $this->hours; }

    /** @param array<array{0: string, 1: string}> $hours in order, closing after opening */
    public function setHours(array $hours): static
    {
        $last = '00:00';
        foreach ($hours as [$open, $close]) {
            if (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close) || $close <= $open || $open < $last) {
                throw new \InvalidArgumentException('Hours are [["HH:MM", "HH:MM"], ...], in order, closing after opening.');
            }
            $last = $close;
        }
        $this->hours = array_values($hours);

        return $this;
    }
}
