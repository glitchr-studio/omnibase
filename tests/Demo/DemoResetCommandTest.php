<?php

namespace Tests\Base\Demo;

use Base\Console\Command\DemoResetCommand;
use Base\Demo\DemoAccountFactory;
use Base\Demo\DemoAccountRegistry;
use Base\Demo\DemoGuard;
use Base\Demo\DemoMode;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * demo:reset: outside `demo` it does nothing and says so; in `demo` it runs
 * under a lock, empties the directories it is given, reloads the fixtures and
 * empties the cache pools - in that order.
 */
class DemoResetCommandTest extends TestCase
{
    /** @var string[] the commands run, in order */
    private array $ran = [];
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory && is_dir($this->directory)) {
            array_map('unlink', glob($this->directory.'/{,.}[!.]*', \GLOB_BRACE) ?: []);
            @rmdir($this->directory);
        }
    }

    private function tester(string $environment, ?LockFactory $locks = null, bool $debug = false, array $purge = [], int $fixtures = Command::SUCCESS): CommandTester
    {
        $mode = new DemoMode($environment);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnections')->willReturn([]);
        $factory = $this->createMock(DemoAccountFactory::class);
        $factory->method('missing')->willReturn([]);

        $command = new DemoResetCommand($mode, new DemoGuard($mode, $debug, 'mysql://production.invalid/site'), $factory, $doctrine, $locks, null, null, $purge);

        $application = new Application();
        $application->setAutoExit(false);
        $add = method_exists($application, 'addCommand') ? 'addCommand' : 'add'; // symfony/console 7.4 renamed it
        $application->$add($command);
        foreach (['doctrine:fixtures:load' => $fixtures, 'uploader:entities' => Command::SUCCESS, 'cache:pool:clear' => Command::SUCCESS] as $name => $status) {
            $application->$add(new class($name, $status, $this->ran) extends Command {
                public function __construct(string $name, private int $status, private array &$ran)
                {
                    parent::__construct($name);
                    $this->ignoreValidationErrors();
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $this->ran[] = $this->getName();

                    return $this->status;
                }
            });
        }

        return new CommandTester($command);
    }

    public function testItRefusesOutsideTheDemoEnvironment(): void
    {
        foreach (['prod', 'dev', 'test'] as $environment) {
            $tester = $this->tester($environment, class_exists(LockFactory::class) ? new LockFactory(new InMemoryStore()) : null);

            $this->assertSame(Command::FAILURE, $tester->execute([]), $environment);
            $this->assertStringContainsString('runs in the "demo" environment only', $tester->getDisplay());
        }
        $this->assertSame([], $this->ran, 'nothing was reloaded, nothing emptied');
    }

    public function testItRefusesInDebug(): void
    {
        $tester = $this->tester('demo', null, true);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('APP_DEBUG=0', $tester->getDisplay());
        $this->assertSame([], $this->ran);
    }

    public function testItRefusesWithoutALock(): void
    {
        $tester = $this->tester('demo');

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('symfony/lock', $tester->getDisplay());
        $this->assertSame([], $this->ran);
    }

    public function testInDemoItEmptiesReloadsAndClearsUnderALock(): void
    {
        if (!class_exists(LockFactory::class)) {
            self::markTestSkipped('Requires symfony/lock.');
        }

        $this->directory = sys_get_temp_dir().'/base-demo-reset-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents($this->directory.'/sent-by-a-visitor.pdf', '%PDF');
        file_put_contents($this->directory.'/.hidden', 'x');

        $tester = $this->tester('demo', new LockFactory(new InMemoryStore()), false, [$this->directory]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        $this->assertSame(['doctrine:fixtures:load', 'uploader:entities', 'cache:pool:clear'], $this->ran);
        $this->assertDirectoryExists($this->directory, 'the directory itself stays: it may be a mounted volume');
        $this->assertSame([], array_diff(scandir($this->directory), ['.', '..']), 'what visitors sent is gone');
        $this->assertStringContainsString('2 file(s) removed', $tester->getDisplay());
        $this->assertStringContainsString('reset done', $tester->getDisplay());
    }

    public function testTwoResetsNeverOverlap(): void
    {
        if (!class_exists(LockFactory::class)) {
            self::markTestSkipped('Requires symfony/lock.');
        }

        $store = new InMemoryStore();
        $running = (new LockFactory($store))->createLock(DemoResetCommand::LOCK);
        $this->assertTrue($running->acquire());

        $tester = $this->tester('demo', new LockFactory($store));
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('another reset is running', $tester->getDisplay());
        $this->assertSame([], $this->ran);

        $running->release();
    }

    public function testFixturesThatDoNotLoadStopTheReset(): void
    {
        if (!class_exists(LockFactory::class)) {
            self::markTestSkipped('Requires symfony/lock.');
        }

        $tester = $this->tester('demo', new LockFactory(new InMemoryStore()), false, [], Command::FAILURE);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame(['doctrine:fixtures:load'], $this->ran, 'no file deleted, no cache emptied after a failed load');
    }
}
