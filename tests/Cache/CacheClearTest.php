<?php

namespace Tests\Base\Cache;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * cache:clear is enough: the pools that remember what the code says - the
 * Doctrine mapping's metadata, the DQL's parsed queries, the router's
 * compiled routes (Base\Routing\AdvancedRouter) - are emptied with the rest.
 * Kept in pools of the application's (filesystem, default clearer), they
 * outlived it: a column added to an entity was "not found" until
 * cache:pool:clear --all (genealogist), a route added "No valid route found"
 * (nakaya).
 */
final class CacheClearTest extends KernelTestCase
{
    public function testTheMappingsAndTheRoutesPoolsAreEmptiedByTheCacheClearer(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $pools = array_filter([
            'metadata' => $container->get('doctrine')->getManager()->getConfiguration()->getMetadataCache(),
            'query' => $container->get('doctrine')->getManager()->getConfiguration()->getQueryCache(),
            'routes' => $container->get('Base\Routing\AdvancedRouter')->getCache(),
        ]);
        self::assertArrayHasKey('metadata', $pools);
        self::assertArrayHasKey('routes', $pools);
        foreach ($pools as $name => $pool) {
            $item = $pool->getItem('cache_clear_test');
            $pool->save($item->set($name));
            self::assertTrue($pool->getItem('cache_clear_test')->isHit(), $name);
        }

        // what cache:clear calls, whatever the state of the cache directory
        $container->get('cache_clearer')->clear($container->getParameter('kernel.cache_dir'));

        foreach ($pools as $name => $pool) {
            self::assertFalse($pool->getItem('cache_clear_test')->isHit(), $name.' emptied');
        }
    }
}
