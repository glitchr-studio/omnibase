<?php

namespace Tests\Base\Commons;

use Base\Entity\Hours\ScopedWeek;
use Base\Entity\Hours\SpecialDay;
use Base\Entity\Hours\WeekDayHours;
use Base\Repository\Hours\ScopedWeekRepository;
use Base\Repository\Hours\WeekDayHoursRepository;
use Base\Service\OpeningHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Opening hours per place: for('store:12') answers for that place - its own
 * week when it has one, its own special days before the site's - and the
 * site's own hours are what they were.
 */
class OpeningHoursScopeTest extends KernelTestCase
{
    private const SITE = [3 => [['09:00', '13:00']], 6 => [['09:00', '18:00']]];

    private ?EntityManagerInterface $em = null;

    protected function tearDown(): void
    {
        if ($this->em) {
            $this->em->clear();
            foreach ([ScopedWeek::class, SpecialDay::class, WeekDayHours::class] as $class) {
                $this->em->createQuery('DELETE FROM '.$class.' e')->execute();
            }
        }
        parent::tearDown();
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Paris'));
    }

    public function testAScopeIsAKeyOrAnEntity(): void
    {
        $hours = new OpeningHours(null, null, 'Europe/Paris', self::SITE);
        $this->assertNull($hours->scope());
        $this->assertSame('store:12', $hours->for('store:12')->scope());
        $this->assertNull($hours->for('')->scope());
        $this->assertNull($hours->scope(), 'the service itself is never scoped');

        $store = new class { public function getId(): int { return 12; } };
        $this->assertSame($store::class.':12', OpeningHours::scopeOf($store));
        $this->assertSame('Tests\\Store:7', OpeningHours::scopeOf(new \Proxies\__CG__\Tests\Store()));
    }

    public function testAPlaceWithoutAWeekFollowsTheSites(): void
    {
        $weeks = $this->createStub(ScopedWeekRepository::class);
        $weeks->method('week')->willReturnCallback(static fn (string $scope) => 'store:12' === $scope ? array_fill(1, 7, [['10:00', '12:00']]) : null);
        $hours = (new OpeningHours(null, null, 'Europe/Paris', self::SITE, null, $weeks))->withSpecialDays([]);

        $this->assertSame([['10:00', '12:00']], $hours->for('store:12')->withSpecialDays([])->week()[1]);
        $this->assertSame(self::SITE[3], $hours->for('store:13')->withSpecialDays([])->week()[3]);
        $this->assertSame([], $hours->week()[1], 'the site keeps its week');
    }

    public function testOnTheDatabase(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        foreach ([ScopedWeek::class, SpecialDay::class, WeekDayHours::class] as $class) {
            $this->em->createQuery('DELETE FROM '.$class.' e')->execute();
        }

        $container->get(WeekDayHoursRepository::class)->save(self::SITE);
        $weeks = $container->get(ScopedWeekRepository::class);
        $weeks->save('store:12', [1 => [['08:00', '12:00']]]);
        $weeks->save('store:12', [1 => [['08:00', '12:00']], 2 => [['14:00', '19:00']]]); // the same row, changed

        $soon = new \DateTimeImmutable('+3 days');
        $later = new \DateTimeImmutable('+5 days');
        $this->em->persist(new SpecialDay($soon, null, 'Everyone closed'));
        $this->em->persist((new SpecialDay($soon, null, 'Open at the store', 'store:12'))->setHours([['10:00', '11:00']]));
        $this->em->persist(new SpecialDay($later, null, 'The store only', 'store:12'));
        $this->em->flush();
        $this->em->clear();

        /** @var OpeningHours $site */
        $site = $container->get(OpeningHours::class);
        $site->reset();
        $store = $site->for('store:12');
        $other = $site->for('store:13');

        // The weeks: the store's own, the site's for the others.
        $this->assertCount(1, $this->em->getRepository(ScopedWeek::class)->findAll());
        $this->assertSame([['14:00', '19:00']], $store->week()[2]);
        $this->assertSame([], $store->week()[3]);
        $this->assertSame(self::SITE[3], $other->week()[3]);
        $this->assertSame(self::SITE[3], $site->week()[3]);

        // The special days: the site sees its own only; the store its own first, then the site's.
        $this->assertCount(1, $site->specialDays());
        $this->assertSame([], $site->hoursOn($soon));
        $this->assertCount(3, $store->specialDays());
        $this->assertSame([['10:00', '11:00']], $store->hoursOn($soon));
        $this->assertSame([], $store->hoursOn($later));
        $this->assertCount(1, $other->specialDays());
        $this->assertSame([], $other->hoursOn($soon));

        // Back to the site's week.
        $weeks->forget('store:12');
        $this->assertSame(self::SITE[3], $site->for('store:12')->week()[3]);
    }
}

namespace Proxies\__CG__\Tests;

class Store
{
    public function getId(): int
    {
        return 7;
    }
}
