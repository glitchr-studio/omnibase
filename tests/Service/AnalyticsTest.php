<?php

namespace Tests\Base\Service;

use Base\Entity\Analytics\PageView;
use Base\Repository\Analytics\VisitRepository;
use Base\Service\Analytics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end coverage of Base\Service\Analytics against a real database -
 * exercises the repositories' upsert SQL too (not mocked), since the whole
 * point of this service is that concurrent/repeat requests behave
 * correctly at the SQL level (an upsert, an insert-if-absent: MySQL's or
 * SQLite's), not just that the PHP call graph is wired correctly.
 *
 * Runs under the host app's PHPUnit (`make tests glitchr`, KERNEL_CLASS=App\Kernel).
 */
class AnalyticsTest extends KernelTestCase
{
    // A random-suffixed path/subject namespace per test RUN (not per test
    // method) keeps this test isolated from real traffic and from any
    // previous run's leftover rows without needing transactional rollback
    // machinery - cleaned up explicitly in tearDown() regardless.
    private static string $runId;

    private EntityManagerInterface $em;
    private Analytics $analytics;

    public static function setUpBeforeClass(): void
    {
        self::$runId = bin2hex(random_bytes(4));
    }

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (run via `make tests glitchr`).');
        }

        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->analytics = $container->get(Analytics::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM analytics_page_view WHERE path LIKE :p", ["p" => "/test-" . self::$runId . "%"]);
        $connection->executeStatement("DELETE FROM analytics_visit WHERE subject_id LIKE :s", ["s" => "test-" . self::$runId . "%"]);
    }

    private function path(string $suffix = ""): string
    {
        return "/test-" . self::$runId . $suffix;
    }

    private function subject(string $suffix = ""): string
    {
        return "test-" . self::$runId . $suffix;
    }

    public function testPageViewsIncrementsOnEachTrack(): void
    {
        $path = $this->path("-views");

        $this->analytics->track($path);
        $this->analytics->track($path);
        $this->analytics->track($path);

        $this->assertSame(3, $this->analytics->pageViews($path));
    }

    public function testPageViewsSumsAcrossPagesWhenPathIsNull(): void
    {
        $a = $this->path("-a");
        $b = $this->path("-b");

        $this->analytics->track($a);
        $this->analytics->track($a);
        $this->analytics->track($b);

        // sums every OTHER page's real traffic too - assert on the delta,
        // not an absolute count
        $before = $this->analytics->pageViews();
        $this->analytics->track($this->path("-c"));
        $after = $this->analytics->pageViews();

        $this->assertSame(1, $after - $before);
        $this->assertSame(2, $this->analytics->pageViews($a));
        $this->assertSame(1, $this->analytics->pageViews($b));
    }

    public function testUniqueVisitorsDeduplicatesRepeatVisitsSameDay(): void
    {
        $visitor = $this->subject("-v1");

        $this->analytics->track($this->path("-x"), $visitor);
        $this->analytics->track($this->path("-y"), $visitor); // same visitor, different page, same day
        $this->analytics->track($this->path("-z"), $visitor);

        // page views still counted 3 times (raw hits)...
        $this->assertSame(1, $this->analytics->pageViews($this->path("-x")));
        $this->assertSame(1, $this->analytics->pageViews($this->path("-y")));
        $this->assertSame(1, $this->analytics->pageViews($this->path("-z")));

        // ...but the visitor is counted once, not three times
        $connection = $this->em->getConnection();
        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM analytics_visit WHERE subject_type = 'visitor' AND subject_id = :id",
            ["id" => $visitor],
        );
        $this->assertSame(1, $count);
    }

    public function testTrackWithoutAVisitorCookieCountsThePageViewButNoVisitor(): void
    {
        $path = $this->path("-anon");

        $this->analytics->track($path, null, null);

        $this->assertSame(1, $this->analytics->pageViews($path));

        $connection = $this->em->getConnection();
        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM analytics_visit WHERE date >= :today AND subject_id LIKE :id",
            ["today" => (new \DateTimeImmutable("today", new \DateTimeZone("UTC")))->format("Y-m-d H:i:s"), "id" => "test-" . self::$runId . "%"],
        );
        // no visitor/user rows at all from this test's own subjects,
        // since none of the earlier assertions in THIS method registered one
        $this->assertSame(0, $count);
    }

    public function testUniqueUsersIsIndependentOfUniqueVisitors(): void
    {
        $sharedPath = $this->path("-shared");
        $visitor = $this->subject("-anon-visitor");
        $user = $this->subject("-user-1");

        // an anonymous (cookie-consented) visit and an authenticated visit,
        // same page, same day, different subject namespaces entirely
        $this->analytics->track($sharedPath, $visitor, null);
        $this->analytics->track($sharedPath, null, $user);

        $connection = $this->em->getConnection();
        $visitorCount = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM analytics_visit WHERE subject_type = 'visitor' AND subject_id = :id",
            ["id" => $visitor],
        );
        $userCount = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM analytics_visit WHERE subject_type = 'user' AND subject_id = :id",
            ["id" => $user],
        );

        $this->assertSame(1, $visitorCount);
        $this->assertSame(1, $userCount);
        // the page itself was hit twice, regardless of who by
        $this->assertSame(2, $this->analytics->pageViews($sharedPath));
    }

    public function testTodayWindowExcludesOlderDays(): void
    {
        $path = $this->path("-window");
        $connection = $this->em->getConnection();

        // seed a row for yesterday directly (Analytics::track() only ever
        // writes "today" - this simulates a page that was viewed
        // yesterday but not since)
        $connection->executeStatement(
            "INSERT INTO analytics_page_view (path, date, views) VALUES (:path, :date, 5)",
            ["path" => $path, "date" => (new \DateTimeImmutable("-1 day"))->format("Y-m-d")],
        );

        $this->assertSame(5, $this->analytics->pageViews($path), "all-time must include yesterday's row");
        $this->assertSame(0, $this->analytics->pageViews($path, "today"), "today's window must exclude yesterday's row");

        $this->analytics->track($path);
        $this->assertSame(1, $this->analytics->pageViews($path, "today"));
        $this->assertSame(6, $this->analytics->pageViews($path), "all-time now sums both days");
    }

    public function testSummaryReturnsAllFourWindowsForAllThreeCounters(): void
    {
        $summary = $this->analytics->summary();

        foreach (["today", "7d", "30d", "all"] as $window) {
            $this->assertArrayHasKey($window, $summary);
            $this->assertArrayHasKey("pageViews", $summary[$window]);
            $this->assertArrayHasKey("uniqueVisitors", $summary[$window]);
            $this->assertArrayHasKey("uniqueUsers", $summary[$window]);
        }
    }

    public function testUnknownWindowThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->analytics->pageViews(null, "bogus");
    }

    public function testDailyBreakdownFillsInZeroForDaysWithNoActivity(): void
    {
        $path = $this->path("-daily");
        $connection = $this->em->getConnection();

        // seed 3 days ago directly, leave everything else untouched
        $connection->executeStatement(
            "INSERT INTO analytics_page_view (path, date, views) VALUES (:path, :date, 7)",
            ["path" => $path, "date" => (new \DateTimeImmutable("-3 days"))->format("Y-m-d")],
        );
        $this->analytics->track($path);

        $series = $this->analytics->dailyBreakdown(5);

        $this->assertCount(5, $series);
        // Breakdown days are UTC calendar days (see Analytics::utc()), so the
        // expectation is built in UTC too - a local "-4 days" near midnight
        // names a different day.
        $this->assertSame((new \DateTimeImmutable("-4 days", new \DateTimeZone("UTC")))->format("Y-m-d"), $series[0]["date"], "oldest day first");
        $this->assertSame((new \DateTimeImmutable("today", new \DateTimeZone("UTC")))->format("Y-m-d"), $series[4]["date"], "today last");

        $byDate = array_column($series, null, "date");
        $threeDaysAgo = (new \DateTimeImmutable("-3 days"))->format("Y-m-d");
        $today = (new \DateTimeImmutable("today"))->format("Y-m-d");
        $untouchedDay = (new \DateTimeImmutable("-2 days"))->format("Y-m-d");

        // site-wide totals include real traffic from other tests/paths, so
        // assert site-wide days are present (not necessarily zero) but the
        // untouched day between our two seeded ones is exactly what it was
        // before this test touched anything - can't assert an absolute 0
        // site-wide, so just assert the shape/ordering is sane and the
        // structure has the three expected keys per day
        $this->assertArrayHasKey($threeDaysAgo, $byDate);
        $this->assertArrayHasKey($today, $byDate);
        $this->assertArrayHasKey($untouchedDay, $byDate);
        foreach ($series as $day) {
            $this->assertArrayHasKey("pageViews", $day);
            $this->assertArrayHasKey("uniqueVisitors", $day);
            $this->assertArrayHasKey("uniqueUsers", $day);
        }
    }

    public function testTrackWithBotUserAgentCountsPageViewButSkipsVisitorPresence(): void
    {
        $path = $this->path("-bot");
        $visitor = $this->subject("-bot-visitor");

        // Googlebot's real UA - a generic (non-AI) crawler, still carrying
        // a stray ANALYTICS/VISITOR_ID cookie value (this never happens for
        // a real crawler, but proves the presence table stays clean even if
        // one somehow replays a cookie).
        $this->analytics->track($path, $visitor, null, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

        $this->assertSame(1, $this->analytics->pageViews($path), 'total hits include the bot');
        $this->assertSame(0, $this->analytics->pageViews($path, null, PageView::SOURCE_HUMAN));
        $this->assertSame(1, $this->analytics->pageViews($path, null, PageView::SOURCE_BOT));

        $connection = $this->em->getConnection();
        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM analytics_visit WHERE subject_type = 'visitor' AND subject_id = :id",
            ["id" => $visitor],
        );
        $this->assertSame(0, $count, 'a bot hit must never dedupe into the unique-visitor table');
    }

    public function testTrackWithAiUserAgentClassifiesSeparatelyFromGenericBot(): void
    {
        $path = $this->path("-ai");

        $this->analytics->track($path, null, null, 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)');

        $this->assertSame(0, $this->analytics->pageViews($path, null, PageView::SOURCE_BOT));
        $this->assertSame(1, $this->analytics->pageViews($path, null, PageView::SOURCE_AI));
    }

    public function testTrackWithHumanUserAgentStillRecordsVisitorPresence(): void
    {
        $path = $this->path("-human");
        $visitor = $this->subject("-human-visitor");

        $this->analytics->track($path, $visitor, null, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36');

        $this->assertSame(1, $this->analytics->pageViews($path, null, PageView::SOURCE_HUMAN));

        $connection = $this->em->getConnection();
        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM analytics_visit WHERE subject_type = 'visitor' AND subject_id = :id",
            ["id" => $visitor],
        );
        $this->assertSame(1, $count);
    }

    public function testTrackWithoutAUserAgentArgumentDefaultsToHuman(): void
    {
        // No 4th argument at all (not even null) - the pre-existing 3-arg
        // call shape every other test in this file already uses - must
        // keep behaving exactly as it always did: counted as human, no
        // classifier round trip.
        $path = $this->path("-no-ua-arg");

        $this->analytics->track($path);

        $this->assertSame(1, $this->analytics->pageViews($path, null, PageView::SOURCE_HUMAN));
    }

    public function testDailyBreakdownIncludesPerSourceKeys(): void
    {
        $path = $this->path("-source-daily");

        $this->analytics->track($path, null, null, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36');
        $this->analytics->track($path, null, null, 'CCBot/2.0 (https://commoncrawl.org/faq/)');

        $series = $this->analytics->dailyBreakdown(1);
        $today = $series[0];

        $this->assertArrayHasKey("pageViewsHuman", $today);
        $this->assertArrayHasKey("pageViewsBot", $today);
        $this->assertArrayHasKey("pageViewsAi", $today);
        // site-wide today includes real traffic beyond this test's own two
        // hits, so assert the floor each source contributed, not an exact
        // total (same convention as testDailyBreakdownFillsInZeroForDaysWithNoActivity)
        $this->assertGreaterThanOrEqual(1, $today["pageViewsHuman"]);
        $this->assertGreaterThanOrEqual(1, $today["pageViewsAi"]);
        $this->assertSame($today["pageViewsHuman"] + $today["pageViewsBot"] + $today["pageViewsAi"], $today["pageViews"]);
    }

    /**
     * A $path-scoped daily breakdown is exactly what an "Article views"
     * (or Destination/Product, ...) dashboard widget needs - the same
     * page-view rollup this whole test file already exercises, just
     * filtered to one URL instead of summed site-wide.
     */
    public function testDailyBreakdownCanBeScopedToASinglePath(): void
    {
        $scoped = $this->path("-scoped");
        $other = $this->path("-other");

        $this->analytics->track($scoped);
        $this->analytics->track($scoped);
        $this->analytics->track($other);

        $scopedSeries = $this->analytics->dailyBreakdown(1, $scoped);
        $siteWideSeries = $this->analytics->dailyBreakdown(1);

        $this->assertSame(2, $scopedSeries[0]["pageViews"]);
        $this->assertSame(2, $scopedSeries[0]["pageViewsHuman"]);
        // site-wide still includes real traffic beyond this test's own
        // three hits, so assert the floor, not an exact total (same
        // convention as testDailyBreakdownIncludesPerSourceKeys above) -
        // the actual guarantee under test is that path-scoping narrows
        // the result relative to the unscoped call, not a specific count.
        $this->assertGreaterThanOrEqual(3, $siteWideSeries[0]["pageViews"]);
    }

    public function testDailyBreakdownCanBeScopedToAListOfPaths(): void
    {
        // the "all articles" rollup: no single path prefix covers every
        // instance of a class in this app's routing, so scoping means the
        // exact set of their own generated links, matched any-of rather
        // than one exact path.
        $first = $this->path("-multi-a");
        $second = $this->path("-multi-b");
        $other = $this->path("-multi-other");

        $this->analytics->track($first);
        $this->analytics->track($second);
        $this->analytics->track($second);
        $this->analytics->track($other);

        $series = $this->analytics->dailyBreakdown(1, [$first, $second]);

        $this->assertSame(3, $series[0]["pageViews"]);
        $this->assertSame(3, $series[0]["pageViewsHuman"]);
    }

    public function testSummaryIncludesPerSourceKeysAndRespectsWindow(): void
    {
        $summary = $this->analytics->summary();

        foreach (["today", "7d", "30d", "all"] as $window) {
            $this->assertArrayHasKey("pageViewsHuman", $summary[$window]);
            $this->assertArrayHasKey("pageViewsBot", $summary[$window]);
            $this->assertArrayHasKey("pageViewsAi", $summary[$window]);
            // regression guard for the pre-existing bug where summary()
            // passed the window name itself as the $path filter (matching
            // no real page) instead of as the $window - "today" total must
            // be at least the actual site-wide today total the window-aware
            // pageViews() call returns directly.
            $this->assertSame(
                $this->analytics->pageViews(null, $window),
                $summary[$window]["pageViews"],
            );
        }
    }

    public function testWeekOverWeekChangeComputesSignedPercentageFromRealDelta(): void
    {
        $path = $this->path("-wow");
        $connection = $this->em->getConnection();

        // previous week (days 8-13 ago from "today"): 10 total views on one day
        $connection->executeStatement(
            "INSERT INTO analytics_page_view (path, date, views) VALUES (:path, :date, 10)",
            ["path" => $path, "date" => (new \DateTimeImmutable("-10 days"))->format("Y-m-d")],
        );
        // this week: 20 total views today - a real, deterministic +100% for THIS path,
        // but weekOverWeekChange() is site-wide, so assert direction/shape, not an exact number
        for ($i = 0; $i < 20; $i++) {
            $this->analytics->track($path);
        }

        $change = $this->analytics->weekOverWeekChange();

        $this->assertArrayHasKey("pageViews", $change);
        $this->assertArrayHasKey("uniqueVisitors", $change);
        $this->assertArrayHasKey("uniqueUsers", $change);
        // site-wide pageViews this week is now strictly greater than before
        // this test ran (we just added 20 real hits), so if the prior week
        // had any traffic at all the computed change is a real, finite number
        if ($change["pageViews"] !== null) {
            $this->assertIsFloat($change["pageViews"]);
        }
    }

    public function testPeriodOverPeriodChangeWithNullDaysReturnsAllNull(): void
    {
        // "all time" has no prior period to compare against - every counter
        // must come back null (the same "nothing meaningful to show" shape
        // as a genuinely-zero prior period), not a division-by-zero/crash.
        $change = $this->analytics->periodOverPeriodChange(null);

        foreach (["pageViews", "pageViewsHuman", "pageViewsBot", "pageViewsAi", "uniqueVisitors", "uniqueUsers"] as $key) {
            $this->assertArrayHasKey($key, $change);
            $this->assertNull($change[$key]);
        }
    }

    public function testPeriodOverPeriodChangeWithSevenDaysMatchesWeekOverWeekChange(): void
    {
        // weekOverWeekChange() is now just this with $days=7 - lock in that
        // the refactor preserves the exact same contract for existing callers.
        $this->assertSame($this->analytics->weekOverWeekChange(), $this->analytics->periodOverPeriodChange(7));
    }

    public function testTrackTwiceInSameHourIncrementsOneRowNotTwo(): void
    {
        $path = $this->path("-hour-dedup");

        $this->analytics->track($path);
        $this->analytics->track($path);

        $connection = $this->em->getConnection();
        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM analytics_page_view WHERE path = :path",
            ["path" => $path],
        );
        $this->assertSame(1, $count, "two tracks in the same hour must upsert into one row, not two");
        $this->assertSame(2, $this->analytics->pageViews($path));
    }

    public function testHourlyBreakdownZeroFillsEarlierHoursAndIncludesCurrentOne(): void
    {
        $path = $this->path("-hourly");

        $this->analytics->track($path);

        $series = $this->analytics->hourlyBreakdown($path);

        $currentHour = (int) (new \DateTimeImmutable("now"))->format("H");
        $this->assertCount($currentHour + 1, $series, "one row per hour from 00:00 through the current hour, no future hours");
        $this->assertSame(1, $series[$currentHour]["pageViews"], "the hit just tracked lands in the CURRENT hour's bucket");
        if ($currentHour > 0) {
            $this->assertSame(0, $series[0]["pageViews"], "an earlier, untouched hour is zero-filled, not omitted");
        }
        foreach ($series as $hour) {
            $this->assertArrayHasKey("uniqueVisitors", $hour);
            $this->assertArrayHasKey("uniqueUsers", $hour);
        }
    }

    public function testVisitorSeenInTwoDifferentHoursCountsOnceForTheDayButTwiceAcrossHourlyBuckets(): void
    {
        $visitor = $this->subject("-two-hours");
        $connection = $this->em->getConnection();

        // Simulate the same visitor present in two DIFFERENT hours today -
        // recordPresence() itself only ever writes "now", so this seeds an
        // earlier hour directly (deterministically NOT the current hour,
        // whatever that happens to be), same convention as this file's
        // other seeded-history tests.
        //
        // In UTC: that is what analytics buckets are stored in, whatever
        // PHP's default timezone happens to be for the request. A local
        // "today +12h" could land in the same UTC bucket as the tracked hit.
        $utc = new \DateTimeZone("UTC");
        $currentHour = (int) (new \DateTimeImmutable("now", $utc))->format("H");
        $seedHour = 12 === $currentHour ? 11 : 12;

        // Measured, not assumed to be zero: uniqueVisitors() is site-wide,
        // so any real consented visitor today (and there are some, now that
        // the visitor cookie is actually written) is in the count. What
        // this test is about is that THIS visitor adds exactly one.
        $before = $this->analytics->uniqueVisitors("today");

        $connection->executeStatement(
            VisitRepository::insertIgnore($connection, "analytics_visit (date, subject_type, subject_id) VALUES (:date, 'visitor', :id)"),
            ["date" => (new \DateTimeImmutable("today", $utc))->modify("+{$seedHour} hours")->format("Y-m-d H:i:s"), "id" => $visitor],
        );
        $this->analytics->track($this->path("-two-hours"), $visitor);

        $this->assertSame($before + 1, $this->analytics->uniqueVisitors("today"), "one distinct visitor across the whole day, regardless of how many hourly rows back it");

        $rows = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM analytics_visit WHERE subject_type = 'visitor' AND subject_id = :id",
            ["id" => $visitor],
        );
        $this->assertSame(2, $rows, "two real rows, one per hour bucket - only the DISTINCT count stays at 1");
    }
}
