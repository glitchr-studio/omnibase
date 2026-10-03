<?php

namespace Base\Repository\Hours;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\Hours\WeekDayHours;

/**
 * @method WeekDayHours|null find($id, $lockMode = null, $lockVersion = null)
 * @method WeekDayHours[]    findAll()
 */
class WeekDayHoursRepository extends ServiceEntityRepository
{
    /** @return array<int, array<array{0: string, 1: string}>> ISO day => hours; empty when never set */
    public function week(): array
    {
        $week = [];
        foreach ($this->findAll() as $day) {
            $week[$day->getWeekday()] = $day->getHours();
        }
        ksort($week);

        return $week;
    }

    /** @param array<int, array<array{0: string, 1: string}>> $week all seven days, ISO day => hours */
    public function save(array $week): void
    {
        $em = $this->getEntityManager();
        for ($n = 1; $n <= 7; ++$n) {
            $day = $this->find($n) ?? new WeekDayHours($n);
            $day->setHours($week[$n] ?? []);
            $em->persist($day);
        }
        $em->flush();
    }
}
