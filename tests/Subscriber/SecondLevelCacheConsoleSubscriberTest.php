<?php

namespace Tests\Base\Subscriber;

use Base\Subscriber\SecondLevelCacheConsoleSubscriber;
use Doctrine\ORM\Cache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * After doctrine:fixtures:load (the tables purged by SQL, the ids numbered
 * from 1 again) the second-level cache is emptied: it kept answering the
 * purged entities.
 */
class SecondLevelCacheConsoleSubscriberTest extends TestCase
{
    private function terminate(string $command, ?Cache $cache): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getCache')->willReturn($cache);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagers')->willReturn(['default' => $manager]);

        (new SecondLevelCacheConsoleSubscriber($doctrine))->onTerminate(
            new ConsoleTerminateEvent(new Command($command), new ArrayInput([]), new NullOutput(), 0)
        );
    }

    public function testTheRegionsAreEmptiedAfterTheFixtures(): void
    {
        $cache = $this->createMock(Cache::class);
        $cache->expects($this->once())->method('evictEntityRegions');
        $cache->expects($this->once())->method('evictCollectionRegions');
        $cache->expects($this->once())->method('evictQueryRegions');

        $this->terminate('doctrine:fixtures:load', $cache);
    }

    public function testAnotherCommandLeavesTheCacheAlone(): void
    {
        $cache = $this->createMock(Cache::class);
        $cache->expects($this->never())->method('evictEntityRegions');

        $this->terminate('cache:clear', $cache);
    }

    public function testWithoutASecondLevelCacheNothingHappens(): void
    {
        $this->terminate('doctrine:fixtures:load', null);
        (new SecondLevelCacheConsoleSubscriber())->evict();

        $this->assertArrayHasKey(ConsoleEvents::TERMINATE, SecondLevelCacheConsoleSubscriber::getSubscribedEvents());
    }

    public function testAnUnreachableCacheDoesNotFailTheCommand(): void
    {
        $cache = $this->createStub(Cache::class);
        $cache->method('evictEntityRegions')->willThrowException(new \RuntimeException('redis is away'));

        $this->terminate('doctrine:fixtures:load', $cache);
        $this->addToAssertionCount(1);
    }
}
