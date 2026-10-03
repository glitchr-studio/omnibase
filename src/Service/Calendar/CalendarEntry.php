<?php

namespace Base\Service\Calendar;

/**
 * One date as a calendar sees it, whatever it was in the site: a concert
 * (omnibase/agenda's Event), an appointment, a class, a pick-up. What
 * Ics and GoogleCalendarLink write from - a bundle maps its own entity to
 * one (see omnibase/agenda's Event::toCalendarEntry()).
 *
 * $start and $end carry the timezone the date happens in (the hall's, the
 * office's): an hour is written in it, a whole day ($allDay) as a date. With
 * no end, a timed date lasts $defaultDuration.
 */
final class CalendarEntry
{
    public const DEFAULT_DURATION = 'PT2H';

    public function __construct(
        public readonly string $uid,
        public readonly string $title,
        public readonly \DateTimeImmutable $start,
        public readonly ?\DateTimeImmutable $end = null,
        public readonly bool $allDay = false,
        public readonly string $description = '',
        public readonly ?string $location = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $url = null,
        public readonly bool $cancelled = false,
        public readonly ?\DateTimeInterface $updatedAt = null,
        public readonly string $defaultDuration = self::DEFAULT_DURATION,
    ) {
    }

    public function timezone(): \DateTimeZone
    {
        return $this->start->getTimezone();
    }

    /** The end a calendar should show: the one given, else the start plus the default duration (a whole day: that day). */
    public function effectiveEnd(): \DateTimeImmutable
    {
        if ($this->allDay) {
            return $this->end ?? $this->start;
        }

        return $this->end && $this->end > $this->start ? $this->end : $this->start->add(new \DateInterval($this->defaultDuration));
    }
}
