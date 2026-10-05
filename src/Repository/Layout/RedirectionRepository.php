<?php

namespace Base\Repository\Layout;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Type\UtcDateTimeImmutableType;
use Base\Entity\Layout\Redirection;

/**
 * @method Redirection|null find($id, $lockMode = null, $lockVersion = null)
 * @method Redirection[]    findAll()
 */
class RedirectionRepository extends ServiceEntityRepository
{
    /**
     * The redirection for an address (Redirection::normalize()): the one
     * written for it with its query, then for its path alone, then the longest
     * prefix ("/a/b/*" before "/a/*") that covers it.
     */
    public function forAddress(string $address): ?Redirection
    {
        $path = strtok($address, '?') ?: '/';
        $candidates = array_values(array_unique([$address, $path]));

        /** @var Redirection[] $exact */
        $exact = $this->createQueryBuilder('r')
            ->andWhere('r.enabled = true')
            ->andWhere('r.source IN (:sources)')->setParameter('sources', $candidates)
            ->getQuery()->getResult();
        foreach ($candidates as $candidate) {
            foreach ($exact as $redirection) {
                if ($redirection->getSource() === $candidate) {
                    return $redirection;
                }
            }
        }

        // The database compared with its collation - MySQL's default takes
        // "/Produit/Enseigne" for "/produit/enseigne" - and the comparison
        // above, letter for letter, then threw away the row it had found: the
        // old address answered 404. What the database found for the address
        // is the redirection, whatever the case; the same letters still win.
        foreach ($candidates as $candidate) {
            foreach ($exact as $redirection) {
                if (mb_strtolower($redirection->getSource()) === mb_strtolower($candidate)) {
                    return $redirection;
                }
            }
        }

        /** @var Redirection[] $prefixes */
        $prefixes = $this->createQueryBuilder('r')
            ->andWhere('r.enabled = true')
            ->andWhere('r.source LIKE :star')->setParameter('star', '%*')
            ->getQuery()->getResult();
        usort($prefixes, static fn (Redirection $a, Redirection $b) => \strlen($b->getSource()) <=> \strlen($a->getSource()));
        foreach ($prefixes as $redirection) {
            if (null !== $redirection->resolve($address)) {
                return $redirection;
            }
        }

        return null;
    }

    /** One more visitor carried: counted in the database, whatever else the request holds. */
    public function hit(Redirection $redirection): void
    {
        $this->getEntityManager()->createQuery('UPDATE '.Redirection::class.' r SET r.hits = r.hits + 1, r.lastHitAt = :now WHERE r.id = :id')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), UtcDateTimeImmutableType::NAME)
            ->setParameter('id', $redirection->getId())
            ->execute();
    }
}
