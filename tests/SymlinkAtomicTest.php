<?php

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * symlink_atomic(): the link cache:clear puts in public/ for a storage, made
 * by several processes at once (web and worker starting together) without
 * any of them failing on "symlink(): File exists".
 */
class SymlinkAtomicTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__).'/src/Resources/Functions.php';

        $this->dir = sys_get_temp_dir().'/symlink-atomic-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage', 0777, true);
        mkdir($this->dir.'/other');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) {
            is_link($path) || is_file($path) ? unlink($path) : rmdir($path);
        }
        rmdir($this->dir);
    }

    public function testTheLinkIsMadeAndMadeAgain(): void
    {
        $link = $this->dir.'/public';

        $this->assertTrue(symlink_atomic('storage', $link));
        $this->assertSame('storage', readlink($link));

        // A second process arriving after the first: no warning, the same link.
        set_error_handler(fn (int $no, string $message) => throw new \ErrorException($message, 0, $no));
        try {
            $this->assertTrue(symlink_atomic('storage', $link));
        } finally {
            restore_error_handler();
        }
        $this->assertSame('storage', readlink($link));
        $this->assertSame([], glob($this->dir.'/*.tmp'), 'nothing is left beside it');
    }

    public function testALinkToSomethingElseIsReplaced(): void
    {
        $link = $this->dir.'/public';
        symlink('other', $link);

        $this->assertTrue(symlink_atomic('storage', $link));
        $this->assertSame('storage', readlink($link));
    }

    public function testADirectoryInItsPlaceIsNotTouched(): void
    {
        $this->assertFalse(symlink_atomic('storage', $this->dir.'/other'));
        $this->assertTrue(is_dir($this->dir.'/other') && !is_link($this->dir.'/other'));
    }

    public function testSeveralProcessesAtOnce(): void
    {
        $link = $this->dir.'/public';
        $code = sprintf(
            'require %s; set_error_handler(static fn (int $no) => (error_reporting() & $no) ? exit(3) : true); usleep(max(0, (int) ((%%F - microtime(true)) * 1e6))); exit(symlink_atomic("storage", %s) ? 0 : 2);',
            var_export(\dirname(__DIR__).'/src/Resources/Functions.php', true),
            var_export($link, true)
        );

        for ($round = 0; $round < 12; ++$round) {
            @unlink($link);
            $at = microtime(true) + 0.3;        // they all start together
            $processes = [];
            for ($i = 0; $i < 8; ++$i) {
                $processes[] = proc_open([\PHP_BINARY, '-r', sprintf($code, $at)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            }
            foreach ($processes as $process) {
                $this->assertSame(0, proc_close($process), 'round '.$round.': a process failed on the link another one made');
            }
            $this->assertSame('storage', readlink($link));
        }
        $this->assertSame([], glob($this->dir.'/*.tmp'));
    }
}
