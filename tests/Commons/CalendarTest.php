<?php

namespace Tests\Base\Commons;

use Base\Service\Calendar\CalendarEntry;
use Base\Service\Calendar\GoogleCalendarLink;
use Base\Service\Calendar\Ics;
use PHPUnit\Framework\TestCase;

class CalendarTest extends TestCase
{
    private function concert(): CalendarEntry
    {
        $berlin = new \DateTimeZone('Europe/Berlin');

        return new CalendarEntry(
            uid: '7@example.org',
            title: 'Perspectives concertantes — NDR, Hamburg',
            start: new \DateTimeImmutable('2026-11-14 20:00', $berlin),
            end: new \DateTimeImmutable('2026-11-14 22:00', $berlin),
            description: "Glière — Harp Concerto\nDebussy; Ravel",
            location: 'Elbphilharmonie, Hamburg',
            url: 'https://example.org/agenda/perspectives',
            updatedAt: new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')),
        );
    }

    public function testACalendarIsValidRfc5545(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $festival = new CalendarEntry('festival@example.org', 'Festival', new \DateTimeImmutable('2027-07-05', $paris), new \DateTimeImmutable('2027-07-07', $paris), allDay: true, cancelled: true);
        $ics = (new Ics())->calendar([$this->concert(), $festival], 'Anna, harp');

        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            $this->assertLessThanOrEqual(75, \strlen($line));
        }
        $lines = Ics::unfold($ics);
        $this->assertContains('X-WR-CALNAME:Anna\, harp', $lines);
        $this->assertContains('DTSTART;TZID=Europe/Berlin:20261114T200000', $lines);
        $this->assertContains('TZID:Europe/Berlin', $lines);
        $this->assertContains('DESCRIPTION:Glière — Harp Concerto\nDebussy\; Ravel', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20270708', $lines);
        $this->assertContains('STATUS:CANCELLED', $lines);
        $this->assertContains('DTSTAMP:20261001T120000Z', $lines);
    }

    public function testNoEndLastsTheDefaultDuration(): void
    {
        $entry = new CalendarEntry('x@y', 'Recital', new \DateTimeImmutable('2026-12-01 18:30', new \DateTimeZone('UTC')));
        $lines = Ics::unfold((new Ics())->event($entry));
        $this->assertContains('DTSTART:20261201T183000Z', $lines);
        $this->assertContains('DURATION:PT2H', $lines);
    }

    public function testTheGoogleLinkGoesInUtcWithItsTimezone(): void
    {
        parse_str((string) parse_url((new GoogleCalendarLink())->for($this->concert()), \PHP_URL_QUERY), $query);
        $this->assertSame('20261114T190000Z/20261114T210000Z', $query['dates']);
        $this->assertSame('Europe/Berlin', $query['ctz']);
        $this->assertSame('Elbphilharmonie, Hamburg', $query['location']);
        $this->assertStringEndsWith('https://example.org/agenda/perspectives', $query['details']);
    }
}
