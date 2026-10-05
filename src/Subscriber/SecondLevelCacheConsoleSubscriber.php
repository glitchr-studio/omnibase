<?php

namespace Base\Subscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Doctrine's second-level cache emptied after a command that rewrites the
 * database behind the ORM's back: doctrine:fixtures:load purges the tables
 * with DELETE / TRUNCATE and numbers the new rows from 1 again, so the cache
 * went on answering yesterday's entity for today's id (a product under
 * another one's name, a deleted page still shown) until it expired.
 */
class SecondLevelCacheConsoleSubscriber implements EventSubscriberInterface
{
    /** The commands after which the cached entities may no longer be the database's. */
    public const COMMANDS = [
        'doctrine:fixtures:load',
        'doctrine:schema:drop',
        'doctrine:schema:create',
        'doctrine:schema:update',
        'doctrine:database:drop',
        'doctrine:database:import',
        'doctrine:migrations:migrate',
        'doctrine:migrations:execute',
        'dbal:run-sql',
        'doctrine:query:sql',
    ];

    public function __construct(private readonly ?ManagerRegistry $doctrine = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::TERMINATE => ['onTerminate', -64]];
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if (!\in_array($event->getCommand()?->getName(), self::COMMANDS, true)) {
            return;
        }

        $this->evict();
    }

    /** Every region of every entity manager that has a second-level cache; the number of managers emptied. */
    public function evict(): int
    {
        $emptied = 0;
        foreach ($this->doctrine?->getManagers() ?? [] as $manager) {
            if (!$manager instanceof EntityManagerInterface) {
                continue;
            }

            try {
                $cache = $manager->getCache();
                if (!$cache) {
                    continue;
                }

                $cache->evictEntityRegions();
                $cache->evictCollectionRegions();
                $cache->evictQueryRegions();
                ++$emptied;
            } catch (\Throwable) {
                // a cache that cannot be reached must not turn a command that succeeded into a failure
            }
        }

        return $emptied;
    }
}
