<?php

namespace Base\Repository\Hours;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\Hours\SpecialDay;

/**
 * @method SpecialDay|null find($id, $lockMode = null, $lockVersion = null)
 * @method SpecialDay[]    findAll()
 */
class SpecialDayRepository extends ServiceEntityRepository
{
    /** @return SpecialDay[] those not over by $from, soonest first */
    public function upcoming(\DateTimeInterface $from, int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.endsOn >= :from')->setParameter('from', $from->format('Y-m-d'))
            ->orderBy('s.startsOn', 'ASC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
