<?php

namespace Base\Repository\Analytics;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\Analytics\PageView;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * @extends ServiceEntityRepository<PageView>
 */
class PageViewRepository extends ServiceEntityRepository
{
    /**
     * Atomic increment, not a load-modify-flush cycle - two concurrent
     * requests hitting the same page the same HOUR must both count, not
     * race and silently drop one. Native upsert rather than a Doctrine
     * entity round-trip: ON DUPLICATE KEY UPDATE on MySQL/MariaDB (the
     * applications' database), ON CONFLICT on SQLite and PostgreSQL (the
     * omnibase harness runs on SQLite). $source is part of the unique key
     * now (see PageView's docblock), so human/bot/ai hits on the same page
     * the same hour land in three separate rows rather than one blended
     * count.
     *
     * $date is bucketed down to the top of its hour here (minutes/seconds
     * zeroed) rather than trusting every caller to already pass an
     * hour-aligned value - Analytics::track() passes the raw "now", not
     * something pre-bucketed, same as it used to pass a raw "today" before
     * PageView moved from daily to hourly grain.
     */
    public function incrementView(string $path, \DateTimeImmutable $date, string $source = PageView::SOURCE_HUMAN): void
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

        $upsert = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? "ON DUPLICATE KEY UPDATE views = views + 1"
            : "ON CONFLICT (path, date, source) DO UPDATE SET views = {$table}.views + 1";

        $connection->executeStatement(
            "INSERT INTO {$table} (path, date, source, views) VALUES (:path, :date, :source, 1) {$upsert}",
            ["path" => mb_substr($path, 0, 255), "date" => $hour->format("Y-m-d H:i:s"), "source" => $source],
        );
    }

    /**
     * Sums the daily rows in range - $path null means every page (a
     * site-wide total), $since null means all-time (no lower bound),
     * $source null means every source (human + bot + ai combined, the
     * pre-existing "total hits" semantics); pass a PageView::SOURCE_*
     * constant to see just that bucket.
     */
    public function countViews(?string $path = null, ?\DateTimeImmutable $since = null, ?string $source = null): int
    {
        $qb = $this->createQueryBuilder("pv")
            ->select("COALESCE(SUM(pv.views), 0)");

        if ($path !== null) {
            $qb->andWhere("pv.path = :path")->setParameter("path", $path);
        }
        if ($since !== null) {
            $qb->andWhere("pv.date >= :since")->setParameter("since", $since);
        }
        if ($source !== null) {
            $qb->andWhere("pv.source = :source")->setParameter("source", $source);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * The earliest recorded DAY, site-wide - what "all time" actually
     * means for dailyBreakdown()'s $days=null case (there's no data
     * before this, so generating a series further back would just be
     * rows of zeroes). Null if nothing has ever been tracked.
     *
     * Truncated to midnight even though the underlying column is now
     * hour-grained - Analytics::dailyBreakdown()'s "all time" day-count
     * (a plain calendar diff against today) would be thrown off by
     * whatever hour the earliest row happens to carry otherwise.
     */
    public function earliestDate(): ?\DateTimeImmutable
    {
        $date = $this->createQueryBuilder("pv")
            ->select("MIN(pv.date)")
            ->getQuery()
            ->getSingleScalarResult();

        // Stored in UTC, so read back as UTC.
        return $date ? new \DateTimeImmutable(substr((string) $date, 0, 10), new \DateTimeZone("UTC")) : null;
    }

    /**
     * One row per calendar day in range, oldest first - the raw series a
     * dashboard trend chart plots directly, no client-side date bucketing
     * needed. $path null means every page summed together (the original,
     * site-wide semantics); pass an exact path to scope the series to one
     * page instead (e.g. a single Article's own traffic trend).
     *
     * Raw SQL (not the QueryBuilder/DQL the rest of this class mostly
     * uses) because this needs to fold the table's own hourly rows back
     * down to one row per DAY via DATE(date) - DQL has no built-in DATE()
     * function, and this app registers no custom one, so a GROUP BY pv.date
     * here would silently return one row per HOUR instead of per day.
     *
     * @return array<string, int> date (Y-m-d) => views
     */
    /**
     * @param string|string[]|null $path a single path (exact match), a list
     *        of paths (any-of match - e.g. every Article's own __toLink(),
     *        for a whole-class rollup rather than one instance), or null
     *        for site-wide
     */
    public function dailyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $params, $types] = $this->pathFilter($path);
        $table = $this->getClassMetadata()->getTableName();
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            "SELECT DATE(date) AS date, SUM(views) AS views FROM {$table}
             WHERE date >= :since {$where}
             GROUP BY DATE(date) ORDER BY DATE(date) ASC",
            \array_merge(["since" => $since->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s")], $params),
            \array_merge(["since" => "string"], $types),
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row["date"]] = (int) $row["views"];
        }

        return $breakdown;
    }

    /**
     * Same shape/semantics as dailyBreakdown(), but grouped by source too -
     * one nested array per day instead of one flat total, so a chart can
     * plot "human traffic" and "bot/AI traffic" as separate lines instead
     * of one number that hides the split. Every PageView::SOURCE_* key is
     * always present per day (0 when that source had no hits that day),
     * same "never make the caller guess at a missing key" guarantee
     * dailyBreakdown()/Analytics::dailyBreakdown() already give callers.
     *
     * $path null means every page (site-wide); pass an exact path to scope
     * this to one page instead, same convention as dailyBreakdown().
     *
     * @return array<string, array<string, int>> date (Y-m-d) => [source => views]
     */
    /**
     * @param string|string[]|null $path see dailyBreakdown()'s own docblock
     */
    public function dailyBreakdownBySource(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $params, $types] = $this->pathFilter($path);
        $table = $this->getClassMetadata()->getTableName();
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            "SELECT DATE(date) AS date, source, SUM(views) AS views FROM {$table}
             WHERE date >= :since {$where}
             GROUP BY DATE(date), source ORDER BY DATE(date) ASC",
            \array_merge(["since" => $since->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s")], $params),
            \array_merge(["since" => "string"], $types),
        );

        $sources = [PageView::SOURCE_HUMAN, PageView::SOURCE_BOT, PageView::SOURCE_AI];

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row["date"]] ??= array_fill_keys($sources, 0);
            $breakdown[$row["date"]][$row["source"]] = (int) $row["views"];
        }

        return $breakdown;
    }

    /**
     * One row per HOUR in range, oldest first - the same idea as
     * dailyBreakdown() one grain finer, for the one range ("today") short
     * enough that an hour-by-hour curve is actually legible. No zero-fill
     * here (unlike Analytics::hourlyBreakdown(), which is the one that
     * fills in every hour up to now) - this stays a thin raw-rows read,
     * same division of responsibility dailyBreakdown() already has with
     * Analytics::dailyBreakdown().
     *
     * @return array<string, int> hour ("Y-m-d H:i:s") => views
     */
    /**
     * @param string|string[]|null $path see dailyBreakdown()'s own docblock
     */
    public function hourlyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $params, $types] = $this->pathFilter($path);
        $table = $this->getClassMetadata()->getTableName();
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            "SELECT date, SUM(views) AS views FROM {$table}
             WHERE date >= :since {$where}
             GROUP BY date ORDER BY date ASC",
            \array_merge(["since" => $since->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s")], $params),
            \array_merge(["since" => "string"], $types),
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row["date"]] = (int) $row["views"];
        }

        return $breakdown;
    }

    /**
     * Same shape/semantics as hourlyBreakdown(), but grouped by source too
     * - the hourly counterpart to dailyBreakdownBySource().
     *
     * @return array<string, array<string, int>> hour ("Y-m-d H:i:s") => [source => views]
     */
    /**
     * @param string|string[]|null $path see dailyBreakdown()'s own docblock
     */
    public function hourlyBreakdownBySource(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $params, $types] = $this->pathFilter($path);
        $table = $this->getClassMetadata()->getTableName();
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            "SELECT date, source, SUM(views) AS views FROM {$table}
             WHERE date >= :since {$where}
             GROUP BY date, source ORDER BY date ASC",
            \array_merge(["since" => $since->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s")], $params),
            \array_merge(["since" => "string"], $types),
        );

        $sources = [PageView::SOURCE_HUMAN, PageView::SOURCE_BOT, PageView::SOURCE_AI];

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row["date"]] ??= array_fill_keys($sources, 0);
            $breakdown[$row["date"]][$row["source"]] = (int) $row["views"];
        }

        return $breakdown;
    }

    /**
     * Shared WHERE-fragment/params/types builder for the raw-SQL breakdown
     * queries above - one path (exact match), a list (any-of, IN(...)), or
     * null (no filter, site-wide) all land on the same three-way return
     * shape so every caller above can just array_merge() it straight into
     * its own since/params.
     *
     * @param string|string[]|null $path
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function pathFilter(string|array|null $path): array
    {
        if (\is_array($path)) {
            return [" AND path IN (:paths)", ["paths" => $path], ["paths" => ArrayParameterType::STRING]];
        }
        if ($path !== null) {
            return [" AND path = :path", ["path" => $path], ["path" => "string"]];
        }

        return ["", [], []];
    }
}
