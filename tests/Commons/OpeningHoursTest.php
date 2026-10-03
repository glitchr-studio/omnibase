<?php

namespace Tests\Base\Commons;

use Base\Entity\Hours\SpecialDay;
use Base\Entity\Hours\WeekDayHours;
use Base\Service\OpeningHours;
use PHPUnit\Framework\TestCase;

class OpeningHoursTest extends TestCase
{
    private const WEEK = [
        3 => [['09:00', '13:00'], ['16:30', '19:00']],
        4 => [['09:00', '13:00'], ['16:30', '19:00']],
        5 => [['09:00', '13:00'], ['16:30', '19:00']],
        6 => [['09:00', '18:00']],
        7 => [['09:00', '13:00']],
    ];

    private function hours(?string $cutoff = '18:00'): OpeningHours
    {
        return (new OpeningHours(null, null, 'Europe/Paris', self::WEEK, $cutoff))->withSpecialDays([]);
    }

    private function on(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('Europe/Paris'));
    }

    public function testTheConfiguredWeekServesUntilOneIsSaved(): void
    {
        $hours = $this->hours();
        $this->assertSame([], $hours->week()[1]);
        $this->assertSame([['09:00', '18:00']], $hours->week()[6]);
        $this->assertTrue($hours->isOpenAt($this->on('2026-10-07 10:00'))); // a Wednesday
        $this->assertFalse($hours->isOpenAt($this->on('2026-10-07 14:00'))); // the break
        $this->assertFalse($hours->isOpenDay($this->on('2026-10-05'))); // a Monday
    }

    public function testASpecialDayClosesOrOpensOtherwise(): void
    {
        $closed = new SpecialDay($this->on('2026-10-07'), $this->on('2026-10-08'), 'Congés');
        $other = (new SpecialDay($this->on('2026-10-10')))->setHours([['10:00', '14:00']]);
        $hours = $this->hours()->withSpecialDays([$closed, $other]);

        $this->assertSame([], $hours->hoursOn($this->on('2026-10-08')));
        $this->assertSame([['10:00', '14:00']], $hours->hoursOn($this->on('2026-10-10')));
        $this->assertCount(2, $hours->notices($this->on('2026-10-06 12:00')));
    }

    public function testTheOrderDayMovesOnPastTheCutoffAndOverClosedDays(): void
    {
        $hours = $this->hours();
        $this->assertSame('2026-10-07', $hours->orderDay($this->on('2026-10-07 11:00'))->format('Y-m-d'));
        $this->assertSame('2026-10-08', $hours->orderDay($this->on('2026-10-07 18:30'))->format('Y-m-d'));
        // Sunday evening: Monday and Tuesday closed, Wednesday it is.
        $this->assertSame('2026-10-14', $hours->orderDay($this->on('2026-10-11 19:00'))->format('Y-m-d'));
        // Without a cutoff: until the day's last closing.
        $this->assertSame('2026-10-07', $this->hours(null)->orderDay($this->on('2026-10-07 18:30'))->format('Y-m-d'));
    }

    public function testSlotsSummaryAndSchema(): void
    {
        $hours = $this->hours();
        $slots = $hours->slots($this->on('2026-10-07'), $this->on('2026-10-07 11:50'));
        $this->assertSame('12:30-13:00', $slots[0]);
        $this->assertContains('16:30-17:00', $slots);

        $this->assertSame([['from' => 3, 'until' => 5, 'slots' => self::WEEK[3]], ['from' => 6, 'until' => 6, 'slots' => self::WEEK[6]], ['from' => 7, 'until' => 7, 'slots' => self::WEEK[7]]], $hours->summary());

        $schema = $hours->withSpecialDays([new SpecialDay($this->on('2026-12-25'))])->schema();
        $this->assertSame(['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Wednesday', 'opens' => '09:00', 'closes' => '13:00'], $schema['openingHoursSpecification'][0]);
        $this->assertSame('00:00', $schema['specialOpeningHoursSpecification'][0]['opens']);
        $this->assertSame('2026-12-25', $schema['specialOpeningHoursSpecification'][0]['validThrough']);
    }

    public function testHoursAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WeekDayHours(3, [['13:00', '09:00']]);
    }
}
