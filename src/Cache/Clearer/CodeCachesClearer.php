<?php

namespace Base\Cache\Clearer;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\CacheClearer\CacheClearerInterface;

/**
 * cache:clear is enough: it empties the pools that remember what the code
 * says - the Doctrine mapping's metadata, the DQL's parsed queries, the
 * router's compiled routes. An application keeps them in pools of its own
 * (filesystem, the default clearer, which cache:clear does not call): a
 * column added to an entity read as missing, a route added was "No valid
 * route found", until cache:pool:clear --all.
 */
final class CodeCachesClearer implements CacheClearerInterface
{
    /** @param iterable<?CacheItemPoolInterface> $pools */
    public function __construct(private readonly iterable $pools)
    {
    }

    public function clear(string $cacheDir): void
    {
        $done = [];
        foreach ($this->pools as $pool) {
            if (null === $pool || isset($done[spl_object_id($pool)])) {
                continue;
            }
            $done[spl_object_id($pool)] = true;
            $pool->clear();
        }
    }
}
