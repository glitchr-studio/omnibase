<?php

namespace Base\Service;

use Base\Entity\Hours\SpecialDay;
use Base\Repository\Hours\SpecialDayRepository;
use Base\Repository\Hours\WeekDayHoursRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * When the place is open - one service for the footer, a banner, the time
 * slots a customer may wish for, the day an order is for and the page's
 * JSON-LD.
 *
 * The usual week is the one saved (Base\Entity\Hours\WeekDayHours), else the
 * one configured (base.opening_hours.week); special days
 * (Base\Entity\Hours\SpecialDay) close a date or open it at other hours.
 * Generalised from Nakaya's App\Service\OpeningHours.
 */
class OpeningHours implements ResetInterface
{
    protected \DateTimeZone $zone;

    /** @var SpecialDay[]|null loaded once per request */
    protected ?array $special = null;

    /** @var array<int, array<array{0: string, 1: string}>>|null loaded once per request */
    protected ?array $week = null;

    /**
     * @param array<int, array<array{0: string, 1: string}>> $defaultWeek ISO day => hours, until a week is saved
     */
    public function __construct(
        protected readonly ?SpecialDayRepository $specialDays = null,
        protected readonly ?WeekDayHoursRepository $weekDays = null,
        #[Autowire('%base.opening_hours.timezone%')] string $timezone = 'Europe/Paris',
        #[Autowire('%base.opening_hours.week%')] protected readonly array $defaultWeek = [],
        #[Autowire('%base.opening_hours.cutoff%')] protected readonly ?string $cutoff = null,
    ) {
        $this->zone = new \DateTimeZone($timezone);
    }

    /** A worker serves many requests: each reads the hours again. */
    public function reset(): void
    {
        $this->special = null;
        $this->week = null;
    }

    public function timezone(): \DateTimeZone
    {
        return $this->zone;
    }

    /** @return array<int, array<array{0: string, 1: string}>> the usual week, all seven days, ISO day => hours */
    public function week(): array
    {
        if (null === $this->week) {
            try {
                $set = $this->weekDays?->week() ?? [];
            } catch (\Throwable) {
                $set = []; // no table yet
            }
            $week = [];
            for ($n = 1; $n <= 7; ++$n) {
                $week[$n] = $set ? ($set[$n] ?? []) : ($this->defaultWeek[$n] ?? []);
            }
            $this->week = $week;
        }

        return $this->week;
    }

    /** @param array<int, array<array{0: string, 1: string}>> $week - for tests, or after a change in this request */
    public function withWeek(array $week): static
    {
        $full = [];
        for ($n = 1; $n <= 7; ++$n) {
            $full[$n] = $week[$n] ?? [];
        }
        $this->week = $full;

        return $this;
    }

    /** @param SpecialDay[] $days - for tests, or after a change in this request */
    public function withSpecialDays(array $days): static
    {
        $this->special = $days;

        return $this;
    }

    /** @return SpecialDay[] the special days still to come (or running today) */
    public function specialDays(): array
    {
        if (null === $this->special) {
            try {
                $this->special = $this->specialDays?->upcoming($this->local(null)->modify('-1 day')) ?? [];
            } catch (\Throwable) {
                $this->special = []; // no table yet: the usual week
            }
        }

        return $this->special;
    }

    public function specialOn(\DateTimeInterface $day): ?SpecialDay
    {
        foreach ($this->specialDays() as $special) {
            if ($special->covers($day)) {
                return $special;
            }
        }

        return null;
    }

    /** @return array<array{0: string, 1: string}> the day's opening hours, special days included */
    public function hoursOn(\DateTimeInterface $day): array
    {
        $special = $this->specialOn($day);
        if ($special) {
            return $special->getHours() ?? [];
        }

        return $this->week()[(int) $day->format('N')] ?? [];
    }

    /**
     * The special days visitors should hear about: running today, or
     * starting within $days.
     *
     * @return SpecialDay[]
     */
    public function notices(?\DateTimeInterface $at = null, int $days = 14, int $limit = 3): array
    {
        $today = $this->local($at)->format('Y-m-d');
        $horizon = $this->local($at)->modify("+$days days")->format('Y-m-d');
        $notices = array_filter($this->specialDays(), static fn (SpecialDay $s) => $s->getEndsOn() >= $today && $s->getStartsOn() <= $horizon);

        return \array_slice(array_values($notices), 0, $limit);
    }

    /**
     * schema.org's openingHoursSpecification and specialOpeningHoursSpecification,
     * to merge into the page's LocalBusiness JSON-LD: Google Search reads the
     * special days from there.
     */
    public function schema(): array
    {
        $names = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $regular = [];
        foreach ($this->week() as $n => $slots) {
            foreach ($slots as [$open, $close]) {
                $regular[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $names[$n], 'opens' => $open, 'closes' => $close];
            }
        }

        $special = [];
        foreach ($this->specialDays() as $day) {
            $span = ['validFrom' => $day->getStartsOn(), 'validThrough' => $day->getEndsOn()];
            // Closed: opens and closes at midnight, as schema.org spells it.
            foreach ($day->getHours() ?? [['00:00', '00:00']] as [$open, $close]) {
                $special[] = ['@type' => 'OpeningHoursSpecification', 'opens' => $open, 'closes' => $close] + $span;
            }
        }

        return ['openingHoursSpecification' => $regular, 'specialOpeningHoursSpecification' => $special];
    }

    /**
     * The footer's lines: the open days, those in a row with the same hours
     * together ("Wednesday to Friday", "Saturday", "Sunday").
     *
     * @return array<array{from: int, until: int, slots: array}> ISO days
     */
    public function summary(): array
    {
        $lines = [];
        foreach ($this->week() as $n => $slots) {
            if (!$slots) {
                continue;
            }
            $last = array_key_last($lines);
            if (null !== $last && $lines[$last]['until'] === $n - 1 && $lines[$last]['slots'] === $slots) {
                $lines[$last]['until'] = $n;
            } else {
                $lines[] = ['from' => $n, 'until' => $n, 'slots' => $slots];
            }
        }

        return $lines;
    }

    public function isOpenDay(\DateTimeInterface $day): bool
    {
        return [] !== $this->hoursOn($day);
    }

    public function isOpenAt(?\DateTimeInterface $at = null): bool
    {
        $at = $this->local($at);
        $hm = $at->format('H:i');
        foreach ($this->hoursOn($at) as [$open, $close]) {
            if ($open <= $hm && $hm < $close) {
                return true;
            }
        }

        return false;
    }

    /** The next day the place opens, $day itself when it does. Null: closed for a year. */
    public function nextOpenDay(\DateTimeInterface $day): ?\DateTimeImmutable
    {
        $day = $this->local($day)->setTime(0, 0);
        for ($i = 0; $i < 366; ++$i) {
            if ($this->isOpenDay($day)) {
                return $day;
            }
            $day = $day->modify('+1 day');
        }

        return null;
    }

    /**
     * The day an order (a reservation, a pick-up) made now is for: today
     * until the cut-off (base.opening_hours.cutoff; without one, until the
     * day's last closing), then the next day the place opens.
     */
    public function orderDay(?\DateTimeInterface $at = null): \DateTimeImmutable
    {
        $at = $this->local($at);
        $day = $at->setTime(0, 0);
        $hours = $this->hoursOn($day);
        $cutoff = $this->cutoff ?? ($hours ? end($hours)[1] : '00:00');
        if (!$hours || $at->format('H:i') >= $cutoff) {
            return $this->nextOpenDay($day->modify('+1 day')) ?? $day->modify('+1 day');
        }

        return $day;
    }

    /**
     * Windows of $step minutes a customer may wish for on $day
     * ("12:00-12:30"), inside the opening hours and not before $at plus
     * $notice minutes.
     *
     * @return string[]
     */
    public function slots(\DateTimeInterface $day, ?\DateTimeInterface $at = null, int $step = 30, int $notice = 30): array
    {
        $day = $this->local($day)->setTime(0, 0);
        $earliest = $this->local($at)->modify("+$notice minutes");
        $slots = [];
        foreach ($this->hoursOn($day) as [$open, $close]) {
            $from = new \DateTimeImmutable($day->format('Y-m-d').' '.$open, $this->zone);
            $end = new \DateTimeImmutable($day->format('Y-m-d').' '.$close, $this->zone);
            for ($t = $from; $t < $end; $t = $t->modify("+$step minutes")) {
                if ($t < $earliest) {
                    continue;
                }
                $slots[] = $t->format('H:i').'-'.$t->modify("+$step minutes")->format('H:i');
            }
        }

        return $slots;
    }

    protected function local(?\DateTimeInterface $at): \DateTimeImmutable
    {
        return ($at ? \DateTimeImmutable::createFromInterface($at) : new \DateTimeImmutable())->setTimezone($this->zone);
    }
}
