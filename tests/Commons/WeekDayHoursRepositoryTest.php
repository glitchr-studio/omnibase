<?php

namespace Tests\Base\Commons;

use Base\Entity\Hours\WeekDayHours;
use Base\Repository\Hours\WeekDayHoursRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * WeekDayHoursRepository::save() on a real database: a first week is
 * inserted, a second one updates the same seven rows. It used to look each
 * day up by an "id" the entity does not have (its key is the weekday).
 */
class WeekDayHoursRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM '.WeekDayHours::class.' d')->execute();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $this->em->createQuery('DELETE FROM '.WeekDayHours::class.' d')->execute();
        }
        parent::tearDown();
    }

    public function testAWeekIsSavedThenChanged(): void
    {
        /** @var WeekDayHoursRepository $weekDays */
        $weekDays = static::getContainer()->get(WeekDayHoursRepository::class);
        $this->assertSame([], $weekDays->week());

        $weekDays->save([3 => [['09:00', '13:00']], 6 => [['09:00', '18:00']]]);
        $this->em->clear();
        $week = $weekDays->week();
        $this->assertCount(7, $week);
        $this->assertSame([['09:00', '13:00']], $week[3]);
        $this->assertSame([], $week[1]);

        $weekDays->save([3 => [['10:00', '12:00'], ['14:00', '18:00']]]);
        $this->em->clear();
        $week = $weekDays->week();
        $this->assertCount(7, $week);
        $this->assertSame([['10:00', '12:00'], ['14:00', '18:00']], $week[3]);
        $this->assertSame([], $week[6]);
    }
}
