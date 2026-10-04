<?php

namespace Tests\Base\Database\Repository;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\Layout\TextOverride;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ServiceEntityRepository::count() is Doctrine's: an int. Its query used to
 * select the entity beside COUNT() and group by the id, so count([]) came
 * back as one row per entity, an array where an int is promised.
 *
 * Runs against the host application's database (the omnibase harness: a
 * fresh SQLite one), on omnibase's own TextOverride rows, written here and
 * removed after.
 */
class CountTest extends KernelTestCase
{
    private const DOMAIN = 'count-test';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->purge();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->purge();
        }
        parent::tearDown();
    }

    private function purge(): void
    {
        $this->em->createQuery('DELETE FROM ' . TextOverride::class . ' t WHERE t.domain = :d')
            ->setParameter('d', self::DOMAIN)
            ->execute();
    }

    private function repository(): ServiceEntityRepository
    {
        $repository = $this->em->getRepository(TextOverride::class);
        $this->assertInstanceOf(ServiceEntityRepository::class, $repository);

        return $repository;
    }

    private function write(string $key, string $locale): void
    {
        $this->em->persist((new TextOverride())->setDomain(self::DOMAIN)->setKey($key)->setLocale($locale)->setValue($key));
    }

    public function testCountGivesAnIntegerForAllRowsAndForCriteria(): void
    {
        $repository = $this->repository();
        $before = $repository->count([]);
        $this->assertIsInt($before);

        $this->write('a', 'fr');
        $this->write('b', 'fr');
        $this->write('c', 'en');
        $this->em->flush();

        $this->assertSame($before + 3, $repository->count([]));
        $this->assertSame(3, $repository->count(['domain' => self::DOMAIN]));
        $this->assertSame(2, $repository->count(['domain' => self::DOMAIN, 'locale' => 'fr']));
        $this->assertSame(0, $repository->count(['domain' => self::DOMAIN, 'locale' => 'ja']));
    }

    public function testTheFinderCountsGiveAnIntegerToo(): void
    {
        $this->write('a', 'fr');
        $this->write('b', 'en');
        $this->em->flush();

        $repository = $this->repository();
        $this->assertSame(2, $repository->countByDomain(self::DOMAIN));
        $this->assertSame(1, $repository->countByDomainAndLocale(self::DOMAIN, 'en'));
        $this->assertSame(2, $repository->distinctCountForLocaleByDomain(self::DOMAIN));
    }
}
