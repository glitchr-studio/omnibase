<?php

namespace Tests\Base\Demo;

use Base\Demo\DemoGuard;
use Base\Demo\DemoMode;
use Base\Exception\DemoRefusedException;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What the `demo` environment refuses to start on: debug, the production
 * database (the same server, port and name, whoever signs in), or no way to
 * tell. Nothing is asked in any other environment.
 */
class DemoGuardTest extends TestCase
{
    /** @param array<string, mixed> $params */
    private function doctrine(array $params): ConnectionRegistry
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn($params);
        $registry = $this->createMock(ConnectionRegistry::class);
        $registry->method('getConnections')->willReturn(['default' => $connection]);

        return $registry;
    }

    private const DEMO = ['driver' => 'pdo_mysql', 'host' => 'database', 'port' => '3306', 'user' => 'site', 'password' => 'example', 'dbname' => 'site_demo'];
    private const PROD = ['driver' => 'pdo_mysql', 'host' => 'database', 'port' => '3306', 'user' => 'site', 'password' => 'example', 'dbname' => 'site'];

    public function testADatabaseOfItsOwnAndNoDebug(): void
    {
        (new DemoGuard(new DemoMode('demo'), false, 'mysql://database:3306/site', $this->doctrine(self::DEMO)))->check();
        $this->addToAssertionCount(1);
    }

    public function testDebugIsRefused(): void
    {
        $this->expectException(DemoRefusedException::class);
        $this->expectExceptionMessage('APP_DEBUG=0');
        (new DemoGuard(new DemoMode('demo'), true, 'mysql://database:3306/site', $this->doctrine(self::DEMO)))->check();
    }

    public function testTheProductionDatabaseIsRefusedWhoeverSignsInToIt(): void
    {
        $this->expectException(DemoRefusedException::class);
        $this->expectExceptionMessage('production database');
        (new DemoGuard(new DemoMode('demo'), false, 'mysql://another-user:another-password@DATABASE/site', $this->doctrine(self::PROD)))->check();
    }

    public function testNoProductionDatabaseNamedIsRefused(): void
    {
        $this->expectException(DemoRefusedException::class);
        $this->expectExceptionMessage('base.demo.production_database');
        (new DemoGuard(new DemoMode('demo'), false, null, $this->doctrine(self::DEMO)))->check();
    }

    public function testNothingIsAskedOutsideDemo(): void
    {
        foreach (['prod', 'dev', 'test'] as $environment) {
            (new DemoGuard(new DemoMode($environment), true, null, $this->doctrine(self::PROD)))->check();
        }
        $this->addToAssertionCount(3);
    }

    public function testWhereAConnectionLeads(): void
    {
        $this->assertSame('database:3306/site', DemoGuard::target('mysql://user:secret@database/site?serverVersion=8.0'));
        $this->assertSame('database:3306/site', DemoGuard::target(self::PROD));
        $this->assertSame('database:3306/site', DemoGuard::target('//database:3306/site'), 'no driver, no credentials: the place alone');
        $this->assertSame('localhost:3306/site', DemoGuard::target('mysql://127.0.0.1/site'));
        $this->assertSame('db.example.org:5432/site', DemoGuard::target('postgresql://db.example.org/site'));
        $this->assertNotSame(DemoGuard::target(self::PROD), DemoGuard::target(self::DEMO));
        $this->assertNotSame(DemoGuard::target('mysql://database:3306/site'), DemoGuard::target('mysql://database:3307/site'));
        $this->assertSame(DemoGuard::target('sqlite:////srv/app/var/data.db'), DemoGuard::target(['driver' => 'pdo_sqlite', 'path' => '/srv/app/var/data.db']));
        $this->assertNull(DemoGuard::target(''));
        $this->assertNull(DemoGuard::target('mysql://database'), 'a server, no database');
        $this->assertNull(DemoGuard::target(['driver' => 'pdo_sqlite', 'memory' => true]));
    }
}
