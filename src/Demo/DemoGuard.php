<?php

namespace Base\Demo;

use Base\Exception\DemoRefusedException;
use Doctrine\Persistence\ConnectionRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the `demo` environment refuses to start on (BaseBundle::boot() asks,
 * for a page as for a command):
 *
 *  - debug on: the demonstration behaves as production does, and a debug
 *    kernel shows its traces to visitors;
 *  - the production database: `demo:reset` empties the database it runs on
 *    every night. The application says which one production's is
 *    (base.demo.production_database, a DSN); a connection that reaches the
 *    same server, port and database name is refused, whoever signs in to it.
 *    Left unset, nothing can be compared: refused too.
 */
class DemoGuard
{
    public function __construct(
        private readonly DemoMode $mode,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
        #[Autowire('%base.demo.production_database%')] private readonly ?string $productionDatabase = null,
        private readonly ?ConnectionRegistry $doctrine = null,
    ) {
    }

    /** @throws DemoRefusedException */
    public function check(): void
    {
        if (!$this->mode->isActive()) {
            return;
        }

        if ($this->debug) {
            throw new DemoRefusedException('The demo environment does not start in debug: set APP_DEBUG=0 (bin/console --env=demo --no-debug).');
        }

        $production = self::target((string) $this->productionDatabase);
        if (null === $production) {
            throw new DemoRefusedException('The demo environment does not start without base.demo.production_database (the DSN of the production database, e.g. "mysql://%env(DOCTRINE_DATABASE_HOST)%:3306/%env(DOCTRINE_DATABASE)%"): it could not tell that it is not running on it.');
        }

        foreach ($this->doctrine?->getConnections() ?? [] as $name => $connection) {
            if (self::target($connection->getParams()) === $production) {
                throw new DemoRefusedException(sprintf('The demo environment does not start on the production database: the "%s" connection reaches %s, which base.demo.production_database names. Give the demonstration its own (doctrine.dbal.connections.%s.dbname_suffix: _demo under when@demo).', $name, $production, $name));
            }
        }
    }

    /**
     * Where a connection leads - "host:port/name", or a file's path for
     * SQLite - whoever signs in to it: from a DSN or from DBAL's parameters.
     * Null when it names no database.
     *
     * @param string|array<string, mixed> $database
     */
    public static function target(string|array $database): ?string
    {
        if (\is_string($database)) {
            $dsn = trim($database);
            if ('' === $dsn) {
                return null;
            }
            // sqlite:///path/to/file.db, sqlite:///%kernel.project_dir%/var/data.db
            if (preg_match('#^(?:pdo[-_])?sqlite3?:(?://)?(.*)$#i', $dsn, $m)) {
                $path = preg_replace('#[?\#].*$#', '', $m[1]);

                return '' === $path || '/:memory:' === $path || ':memory:' === $path ? null : 'sqlite:'.self::path('/'.ltrim($path, '/'));
            }
            $parts = parse_url(str_contains($dsn, '//') ? $dsn : '//'.$dsn);
            if (false === $parts) {
                return null;
            }
            $database = [
                'driver' => $parts['scheme'] ?? null,
                'host' => $parts['host'] ?? null,
                'port' => $parts['port'] ?? null,
                'dbname' => isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : null,
            ];
        }

        $driver = strtolower((string) ($database['driver'] ?? ''));
        if (str_contains($driver, 'sqlite')) {
            $path = (string) ($database['path'] ?? '');

            return '' === $path || !empty($database['memory']) ? null : 'sqlite:'.self::path($path);
        }

        $name = (string) ($database['dbname'] ?? '');
        if ('' === $name) {
            return null;
        }

        $host = strtolower((string) ($database['host'] ?? 'localhost'));
        if (\in_array($host, ['127.0.0.1', '::1', ''], true)) {
            $host = 'localhost';
        }
        $port = (int) ($database['port'] ?? 0);
        if (0 === $port) {
            $port = match (true) {
                str_contains($driver, 'pgsql'), str_contains($driver, 'postgres') => 5432,
                str_contains($driver, 'sqlsrv'), str_contains($driver, 'mssql') => 1433,
                default => 3306,
            };
        }

        return $host.':'.$port.'/'.$name;
    }

    private static function path(string $path): string
    {
        return realpath($path) ?: preg_replace('#/+#', '/', $path);
    }
}
