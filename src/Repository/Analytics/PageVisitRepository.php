<?php

namespace Base\Repository\Analytics;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\Analytics\PageVisit;
use Doctrine\DBAL\ArrayParameterType;

/**
 * @extends ServiceEntityRepository<PageVisit>
 */
class PageVisitRepository extends ServiceEntityRepository
{
    /**
     * Insert-if-absent, bucketed to the hour - same contract as
     * VisitRepository::recordPresence(), with the page added.
     */
    public function recordPresence(string $path, string $subjectType, string $subjectId, \DateTimeImmutable $date): void
    {
        // UTC before bucketing, whatever the process default is: BaseBundle
        // sets that from the visitor's timezone cookie on every request, so
        // formatting the raw value stored each visitor's local hour. Before
        // the hour is cut, too - a half-hour zone would otherwise land between
        // two UTC buckets.
        $date = $date->setTimezone(new \DateTimeZone("UTC"));

        $table = $this->getClassMetadata()->getTableName();
        $hour = $date->setTime((int) $date->format("H"), 0, 0);

        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            VisitRepository::insertIgnore($connection, "{$table} (date, path, subject_type, subject_id) VALUES (:date, :path, :type, :id)"),
            [
                "date" => $hour->format("Y-m-d H:i:s"),
                "path" => mb_substr($path, 0, 255),
                "type" => $subjectType,
                "id" => mb_substr($subjectId, 0, 64),
            ],
        );
    }

    /**
     * Unique views over the window: distinct (page, subject) pairs. A reader
     * reloading one page is one unique view; the same reader on two pages is
     * two. Rows are hourly, so this counts DISTINCT pairs, never rows - over
     * a DISTINCT subquery, since COUNT(DISTINCT a, b, c) is MySQL's alone.
     *
     * @param string|string[]|null $path
     */
    public function countUnique(?\DateTimeImmutable $since = null, string|array|null $path = null): int
    {
        [$where, $params, $types] = $this->filters($since, $path);

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM (SELECT DISTINCT path, subject_type, subject_id FROM {$this->getClassMetadata()->getTableName()}{$where}) pairs",
            $params,
            $types,
        );
    }

    /**
     * One entry per calendar day: distinct (page, subject) pairs seen that
     * day. A reader coming back to the same page on another day counts again
     * on that day - the right answer for a trend line, as with
     * VisitRepository::dailyBreakdown().
     *
     * @param string|string[]|null $path
     *
     * @return array<string, int> date (Y-m-d) => unique views
     */
    public function dailyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $params, $types] = $this->filters($since, $path);

        return $this->pairs(
            "SELECT visit_day AS date, COUNT(*) AS count
             FROM (SELECT DISTINCT DATE(date) AS visit_day, path, subject_type, subject_id FROM {$this->getClassMetadata()->getTableName()}{$where}) pairs
             GROUP BY visit_day ORDER BY visit_day ASC",
            $params,
            $types,
        );
    }

    /**
     * @param string|string[]|null $path
     *
     * @return array<string, int> hour ("Y-m-d H:i:s") => unique views
     */
    public function hourlyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $params, $types] = $this->filters($since, $path);

        return $this->pairs(
            "SELECT date, COUNT(*) AS count
             FROM (SELECT DISTINCT date, path, subject_type, subject_id FROM {$this->getClassMetadata()->getTableName()}{$where}) pairs
             GROUP BY date ORDER BY date ASC",
            $params,
            $types,
        );
    }

    /**
     * @param string|string[]|null $path
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function filters(?\DateTimeImmutable $since, string|array|null $path): array
    {
        $clauses = [];
        $params = [];
        $types = [];

        if ($since !== null) {
            $clauses[] = "date >= :since";
            $params["since"] = $since->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s");
        }

        if (is_array($path)) {
            if ($path === []) {
                // An empty list of pages is "no page", not "every page".
                $clauses[] = "1 = 0";
            } else {
                $clauses[] = "path IN (:paths)";
                $params["paths"] = array_values($path);
                $types["paths"] = ArrayParameterType::STRING;
            }
        } elseif ($path !== null) {
            $clauses[] = "path = :path";
            $params["path"] = $path;
        }

        return [$clauses ? " WHERE " . implode(" AND ", $clauses) : "", $params, $types];
    }

    /**
     * @return array<string, int>
     */
    private function pairs(string $sql, array $params, array $types): array
    {
        $breakdown = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types) as $row) {
            $breakdown[$row["date"]] = (int) $row["count"];
        }

        return $breakdown;
    }
}
