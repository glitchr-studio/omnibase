<?php

namespace Base\Console\Command;

use Base\Demo\DemoAccountFactory;
use Base\Demo\DemoGuard;
use Base\Demo\DemoMode;
use Base\Exception\DemoRefusedException;
use Base\Subscriber\SecondLevelCacheConsoleSubscriber;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Lock\LockFactory;

/**
 * The demonstration back to what the fixtures say: what the `cron` container
 * runs every night in the `demo` environment, and nowhere else.
 *
 *  1. refuses outside `demo`, and on what DemoGuard refuses (debug, the
 *     production database);
 *  2. under a lock (symfony/lock): two resets never overlap;
 *  3. empties the directories of base.demo.reset.purge - the files the
 *     visitors sent to a storage of the application's own;
 *  4. reloads the fixtures (doctrine:fixtures:load, the groups of
 *     base.demo.reset.groups when some are named), then creates the declared
 *     demonstration accounts they left out;
 *  5. deletes the uploaded files no row names any more
 *     (uploader:entities --delete-orphans, unless base.demo.reset.orphans is off);
 *  6. empties the caches that would go on answering yesterday's rows: every
 *     cache pool, Doctrine's second-level cache.
 *
 * Each step is logged; the exit code is not 0 when one failed.
 */
#[AsCommand(name: 'demo:reset', description: 'Reloads the demonstration: fixtures, uploaded files, caches (demo environment only)')]
class DemoResetCommand extends Command
{
    public const LOCK = 'base-demo-reset';

    /**
     * @param string[] $purge  directories emptied before the fixtures are loaded (base.demo.reset.purge)
     * @param string[] $groups the fixture groups loaded (base.demo.reset.groups); none: every fixture
     */
    public function __construct(
        private readonly DemoMode $mode,
        private readonly DemoGuard $guard,
        private readonly DemoAccountFactory $accounts,
        private readonly ?ManagerRegistry $doctrine = null,
        private readonly ?LockFactory $lockFactory = null,
        private readonly ?SecondLevelCacheConsoleSubscriber $secondLevelCache = null,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%base.demo.reset.purge%')] private readonly array $purge = [],
        #[Autowire('%base.demo.reset.orphans%')] private readonly bool $orphans = true,
        #[Autowire('%base.demo.reset.groups%')] private readonly array $groups = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('keep-files', null, InputOption::VALUE_NONE, 'Leave the uploaded files as they are (the database and the caches only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->mode->isActive()) {
            $output->writeln(sprintf('<error>demo:reset runs in the "%s" environment only: it empties the database it is given.</error>', DemoMode::ENVIRONMENT));

            return self::FAILURE;
        }

        try {
            $this->guard->check();
        } catch (DemoRefusedException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        }

        if (null === $this->lockFactory) {
            $output->writeln('<error>demo:reset needs symfony/lock (composer require symfony/lock): two resets must never run at once.</error>');

            return self::FAILURE;
        }

        $lock = $this->lockFactory->createLock(self::LOCK, 3600);
        if (!$lock->acquire()) {
            $this->say($output, 'another reset is running: nothing done.', 'warning');

            return self::FAILURE;
        }

        $started = microtime(true);
        try {
            $this->say($output, 'reset started.');

            if (!$input->getOption('keep-files')) {
                foreach ($this->purge as $directory) {
                    $this->say($output, sprintf('%d file(s) removed from %s.', $this->emptyDirectory($directory), $directory));
                }
            }

            if (self::SUCCESS !== $status = $this->call('doctrine:fixtures:load', $this->groups ? ['--group' => $this->groups] : [], $output)) {
                $this->say($output, 'the fixtures did not load (is DoctrineFixturesBundle registered for demo in config/bundles.php?).', 'error');

                return $status;
            }
            $this->say($output, 'fixtures reloaded.');

            if ($missing = $this->accounts->missing()) {
                $this->accounts->load();
                $this->doctrine?->getManager()->flush();
                $this->say($output, sprintf('demonstration account(s) created: %s.', implode(', ', $missing)));
            }

            if ($this->orphans && !$input->getOption('keep-files')) {
                $status = $this->call('uploader:entities', ['--delete-orphans' => true], $output);
                $this->say($output, self::SUCCESS === $status ? 'orphan uploads deleted.' : 'the orphan uploads could not be deleted.', self::SUCCESS === $status ? 'info' : 'warning');
            }

            $status = $this->call('cache:pool:clear', ['--all' => true], $output);
            $this->secondLevelCache?->evict();
            $this->say($output, self::SUCCESS === $status ? 'cache pools and second-level cache emptied.' : 'the cache pools could not be emptied.', self::SUCCESS === $status ? 'info' : 'warning');

            $this->say($output, sprintf('reset done in %.1f s.', microtime(true) - $started));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->say($output, sprintf('reset failed: %s', $e->getMessage()), 'error');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $arguments */
    private function call(string $command, array $arguments, OutputInterface $output): int
    {
        try {
            $found = $this->getApplication()?->find($command);
        } catch (CommandNotFoundException) {
            $found = null;
        }
        if (null === $found) {
            $this->say($output, sprintf('command "%s" not found.', $command), 'warning');

            return self::FAILURE;
        }

        $input = new ArrayInput($arguments);
        $input->setInteractive(false);

        return $found->run($input, $output);
    }

    /** Everything inside a directory, the directory kept (it may be a mounted volume); the number of files removed. */
    private function emptyDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $files = iterator_count((new Finder())->in($directory)->files()->ignoreDotFiles(false)->ignoreVCS(false));
        $entries = iterator_to_array((new Finder())->in($directory)->depth(0)->ignoreDotFiles(false)->ignoreVCS(false), false);
        (new Filesystem())->remove($entries);

        return $files;
    }

    private function say(OutputInterface $output, string $message, string $level = 'info'): void
    {
        $output->writeln(sprintf('%s [demo:reset] %s', gmdate('Y-m-d\TH:i:s\Z'), $message));
        $this->logger?->log($level, 'demo:reset: '.$message);
    }
}
