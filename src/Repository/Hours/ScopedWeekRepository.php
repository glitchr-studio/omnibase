<?php

namespace Base\Repository\Hours;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\Hours\ScopedWeek;

/**
 * @method ScopedWeek|null find($id, $lockMode = null, $lockVersion = null)
 * @method ScopedWeek[]    findAll()
 */
class ScopedWeekRepository extends ServiceEntityRepository
{
    public function ofScope(string $scope): ?ScopedWeek
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.scope = :scope')->setParameter('scope', $scope)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return array<int, array<array{0: string, 1: string}>>|null the place's week, all seven days; null: it follows the site's */
    public function week(string $scope): ?array
    {
        return $this->ofScope($scope)?->getWeek();
    }

    /** @param array<int, array<array{0: string, 1: string}>> $week ISO day => hours */
    public function save(string $scope, array $week): ScopedWeek
    {
        $row = $this->ofScope($scope);
        $row ? $row->setWeek($week) : $row = new ScopedWeek($scope, $week);

        $em = $this->getEntityManager();
        $em->persist($row);
        $em->flush();

        return $row;
    }

    /** Back to the site's week. */
    public function forget(string $scope): void
    {
        if ($row = $this->ofScope($scope)) {
            $em = $this->getEntityManager();
            $em->remove($row);
            $em->flush();
        }
    }
}
