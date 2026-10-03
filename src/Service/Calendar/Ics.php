<?php

namespace Base\Service\Calendar;

/**
 * Dates as an iCalendar file (RFC 5545), written by hand: a calendar a phone
 * subscribes to, or one date to add. Lines end with CRLF and are folded at
 * 75 octets, texts are escaped, an hour is given in the date's timezone
 * (with the VTIMEZONE that defines it) or in UTC, a date of whole days as
 * dates. Moved here from omnibase/agenda, on CalendarEntry.
 */
class Ics
{
    public const PROD_ID = '-//omnibase//calendar//EN';

    /** @param iterable<CalendarEntry> $entries */
    public function calendar(iterable $entries, string $name = 'Agenda', string $prodId = self::PROD_ID): string
    {
        $entries = \is_array($entries) ? array_values($entries) : iterator_to_array($entries, false);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::escape($prodId),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($name),
        ];
        foreach ($this->timezones($entries) as $timezone) {
            array_push($lines, ...$timezone);
        }
        foreach ($entries as $entry) {
            array_push($lines, ...$this->lines($entry));
        }
        $lines[] = 'END:VCALENDAR';

        return self::join($lines);
    }

    /** One date as a VEVENT block, to put in a VCALENDAR. */
    public function event(CalendarEntry $entry): string
    {
        return self::join($this->lines($entry));
    }

    /** A text value: backslash, semicolon, comma and line break escaped. */
    public static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', ''], $text);
    }

    /** A line of more than 75 octets continues on the next ones, each opened by a space; never inside a character. */
    public static function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }
        $folded = [];
        $current = '';
        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (\strlen($current) + \strlen($char) > 75) {
                $folded[] = $current;
                $current = ' ';
            }
            $current .= $char;
        }
        $folded[] = $current;

        return implode("\r\n", $folded);
    }

    /** Lines folded at 75 octets read back as one (RFC 5545 §3.1). @return list<string> */
    public static function unfold(string $ics): array
    {
        $ics = preg_replace("/\r\n[ \t]|\n[ \t]/", '', $ics);

        return array_values(array_filter(preg_split("/\r\n|\n|\r/", $ics), fn ($line) => '' !== $line));
    }

    /** @return list<string> the VEVENT's lines, unfolded */
    protected function lines(CalendarEntry $entry): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = $entry->start;
        $end = $entry->end;
        $stamp = \DateTimeImmutable::createFromInterface($entry->updatedAt ?? new \DateTimeImmutable())->setTimezone($utc);

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.self::escape($entry->uid),
            'DTSTAMP:'.$stamp->format('Ymd\THis\Z'),
            'LAST-MODIFIED:'.$stamp->format('Ymd\THis\Z'),
            'SEQUENCE:'.($entry->cancelled ? 1 : 0),
        ];

        if ($entry->allDay) {
            // Whole days: DTEND is the day after the last one.
            $lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.($end ?? $start)->modify('+1 day')->format('Ymd');
        } else {
            $lines[] = 'DTSTART'.self::moment($start);
            $lines[] = $end && $end > $start ? 'DTEND'.self::moment($end) : 'DURATION:'.$entry->defaultDuration;
        }

        $lines[] = 'SUMMARY:'.self::escape($entry->title);
        if ('' !== $entry->description) {
            $lines[] = 'DESCRIPTION:'.self::escape($entry->description);
        }
        if (null !== $entry->location && '' !== $entry->location) {
            $lines[] = 'LOCATION:'.self::escape($entry->location);
        }
        if (null !== $entry->latitude && null !== $entry->longitude) {
            $lines[] = sprintf('GEO:%F;%F', $entry->latitude, $entry->longitude);
        }
        if ($entry->url) {
            $lines[] = 'URL:'.$entry->url;
        }
        $lines[] = 'STATUS:'.($entry->cancelled ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** ":20261003T180000Z" in UTC, ";TZID=Europe/Berlin:20261003T200000" anywhere else. */
    protected static function moment(\DateTimeImmutable $at): string
    {
        $timezone = $at->getTimezone()->getName();

        return \in_array($timezone, ['UTC', 'Z', '+00:00'], true)
            ? ':'.$at->format('Ymd\THis\Z')
            : ';TZID='.$timezone.':'.$at->format('Ymd\THis');
    }

    /**
     * A VTIMEZONE for each timezone the timed dates name: its changes of
     * offset from a year before the first date to a year after the last.
     *
     * @param list<CalendarEntry> $entries
     *
     * @return list<list<string>>
     */
    protected function timezones(array $entries): array
    {
        $spans = [];
        foreach ($entries as $entry) {
            $name = $entry->timezone()->getName();
            if ($entry->allDay || \in_array($name, ['UTC', 'Z', '+00:00'], true)) {
                continue;
            }
            $from = $entry->start->getTimestamp();
            $to = ($entry->end ?? $entry->start)->getTimestamp();
            $spans[$name] = [min($spans[$name][0] ?? $from, $from), max($spans[$name][1] ?? $to, $to)];
        }

        $blocks = [];
        foreach ($spans as $name => [$from, $to]) {
            $transitions = (new \DateTimeZone($name))->getTransitions($from - 366 * 86400, $to + 366 * 86400) ?: [];
            $lines = ['BEGIN:VTIMEZONE', 'TZID:'.$name];
            if (\count($transitions) < 2) {
                // No change of hour in sight: one fixed offset.
                $offset = $transitions[0]['offset'] ?? 0;
                array_push($lines, 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:'.self::offset($offset), 'TZOFFSETTO:'.self::offset($offset), 'END:STANDARD');
            }
            for ($i = 1; $i < \count($transitions); ++$i) {
                $before = $transitions[$i - 1]['offset'];
                $kind = $transitions[$i]['isdst'] ? 'DAYLIGHT' : 'STANDARD';
                array_push(
                    $lines,
                    'BEGIN:'.$kind,
                    // The wall clock just before it changes.
                    'DTSTART:'.gmdate('Ymd\THis', $transitions[$i]['ts'] + $before),
                    'TZOFFSETFROM:'.self::offset($before),
                    'TZOFFSETTO:'.self::offset($transitions[$i]['offset']),
                    'TZNAME:'.$transitions[$i]['abbr'],
                    'END:'.$kind,
                );
            }
            $lines[] = 'END:VTIMEZONE';
            $blocks[] = $lines;
        }

        return $blocks;
    }

    /** 7200 → "+0200". */
    protected static function offset(int $seconds): string
    {
        return sprintf('%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
    }

    /** @param list<string> $lines */
    protected static function join(array $lines): string
    {
        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }
}
