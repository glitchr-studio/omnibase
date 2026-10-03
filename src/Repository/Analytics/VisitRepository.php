<?php

namespace Base\Repository\Analytics;

use Base\Entity\Analytics\Visit;
use Base\Database\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * @extends ServiceEntityRepository<Visit>
 */
class VisitRepository extends ServiceEntityRepository
{
    /**
     * Insert-if-absent, not insert-or-update: a presence row only ever
     * needs to exist once per (hour, subject) - a second sighting the same
     * hour is a silent no-op, not an update. INSERT IGNORE (rather than a
     * SELECT-then-INSERT round trip) makes that race-safe under concurrent
     * requests from the same subject too.
     *
     * $date is bucketed down to the top of its hour here, same reasoning
     * as PageViewRepository::incrementView()'s own bucketing.
     */
    public function recordPresence(string $subjectType, string $subjectId, \DateTimeImmutable $date): void
    {
        // UTC before bucketing, whatever the process default is: BaseBundle
        // sets that from the visitor's timezone cookie on every request, so
        // formatting the raw value stored each visitor's local hour. Before
        // the hour is cut, too - a half-hour zone would otherwise land between
        // two UTC buckets.
        $date = $date->setTimezone(new \DateTimeZone("UTC"));

        $table = $this->getClassMetadata()->getTableName();
        $connection = $this->getEntityManager()->getConnection();
        $hour = $date->setTime((int) $date->format("H"), 0, 0);

        $connection->executeStatement(
            self::insertIgnore($connection, "{$table} (date, subject_type, subject_id) VALUES (:date, :type, :id)"),
            ["date" => $hour->format("Y-m-d H:i:s"), "type" => $subjectType, "id" => mb_substr($subjectId, 0, 64)],
        );
    }

    /**
     * "INSERT IGNORE INTO $into" on MySQL/MariaDB; elsewhere (SQLite, the
     * harness's database, and PostgreSQL) the same through ON CONFLICT DO
     * NOTHING - one row per unique key, a duplicate silently skipped.
     */
    public static function insertIgnore(\Doctrine\DBAL\Connection $connection, string $into): string
    {
        return $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? "INSERT IGNORE INTO {$into}"
            : "INSERT INTO {$into} ON CONFLICT DO NOTHING";
    }

    /**
     * True unique count over the window (not an approximation): each
     * subject contributes at most one row per day it was seen, so
     * COUNT(DISTINCT subject_id) across the date range never double-counts
     * a subject who returned on multiple days within it.
     */
    public function countUnique(string $subjectType, ?\DateTimeImmutable $since = null): int
    {
        $qb = $this->createQueryBuilder("v")
            ->select("COUNT(DISTINCT v.subjectId)")
            ->andWhere("v.subjectType = :type")
            ->setParameter("type", $subjectType);

        if ($since !== null) {
            $qb->andWhere("v.date >= :since")->setParameter("since", $since);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Retention: of the subjects seen in [$previousSince, $currentSince), how
     * many were seen again from $currentSince on.
     *
     * Both numbers come from one scan rather than two queries, so they cannot
     * disagree about who "previous" was. A subject first seen in the current
     * window is not in either count - retention is about coming BACK, and a
     * newcomer has nothing to come back to.
     *
     * @return array{previous: int, returning: int}
     */
    public function returning(string $subjectType, \DateTimeImmutable $previousSince, \DateTimeImmutable $currentSince): array
    {
        $table = $this->getClassMetadata()->getTableName();
        $connection = $this->getEntityManager()->getConnection();

        $row = $connection->fetchAssociative(
            "SELECT COUNT(DISTINCT p.subject_id) AS previous,
                    COUNT(DISTINCT CASE WHEN EXISTS (
                        SELECT 1 FROM {$table} c
                        WHERE c.subject_type = p.subject_type AND c.subject_id = p.subject_id AND c.date >= :current
                    ) THEN p.subject_id END) AS returning
             FROM {$table} p
             WHERE p.subject_type = :type AND p.date >= :previous AND p.date < :current",
            [
                "type" => $subjectType,
                "previous" => $previousSince->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s"),
                "current" => $currentSince->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s"),
            ],
        );

        return [
            "previous" => (int) ($row["previous"] ?? 0),
            "returning" => (int) ($row["returning"] ?? 0),
        ];
    }

    /**
     * One row per calendar day in range - unlike countUnique() (a single
     * total over the whole window), a subject seen on multiple days
     * within range legitimately contributes to EACH of those days here;
     * that's the correct semantics for "how many distinct subjects were
     * active on day X", which is what a trend chart needs.
     *
     * Raw SQL, same reason as PageViewRepository::dailyBreakdown(): folding
     * this table's own hourly rows back down to one row per DAY needs
     * DATE(date), which DQL has no built-in function for.
     *
     * @return array<string, int> date (Y-m-d) => distinct subject count
     */
    public function dailyBreakdown(string $subjectType, \DateTimeImmutable $since): array
    {
        $table = $this->getClassMetadata()->getTableName();
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            "SELECT DATE(date) AS date, COUNT(DISTINCT subject_id) AS count FROM {$table}
             WHERE subject_type = :type AND date >= :since
             GROUP BY DATE(date) ORDER BY DATE(date) ASC",
            ["type" => $subjectType, "since" => $since->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s")],
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row["date"]] = (int) $row["count"];
        }

        return $breakdown;
    }

    /**
     * One row per HOUR in range - the hourly counterpart to
     * dailyBreakdown(), for the one range ("today") an hour-by-hour curve
     * is actually legible for. No zero-fill here, same division of
     * responsibility as PageViewRepository::hourlyBreakdown() vs
     * Analytics::hourlyBreakdown().
     *
     * @return array<string, int> hour ("Y-m-d H:i:s") => distinct subject count
     */
    public function hourlyBreakdown(string $subjectType, \DateTimeImmutable $since): array
    {
        $table = $this->getClassMetadata()->getTableName();
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            "SELECT date, COUNT(DISTINCT subject_id) AS count FROM {$table}
             WHERE subject_type = :type AND date >= :since
             GROUP BY date ORDER BY date ASC",
            ["type" => $subjectType, "since" => $since->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s")],
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row["date"]] = (int) $row["count"];
        }

        return $breakdown;
    }
}
