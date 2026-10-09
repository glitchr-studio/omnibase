<?php

namespace Tests\Base\Twig;

use Base\Twig\Renderer\Adapter\WebpackTagRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The scripts of a page run in its order: the core's base-async, which sets
 * window.jQuery, before a site's entries that read it as an external - or
 * the site threw "jQuery is not defined" whenever its file came back first.
 */
final class ScriptOrderTest extends TestCase
{
    /** The files a site's base.html.twig renders, in its order: encore_entry_script_tags('base'), ('form'), ('app'). */
    private const PAGE = [
        '/bundles/base/runtime.js',
        '/bundles/base/base-async.js',
        '/bundles/base/base-defer.js',
        '/bundles/base/form-defer.js',
        '/assets/runtime.js',
        '/assets/app-async.js',
        '/assets/layout1-async.js',
        '/assets/app-defer.js',
    ];

    public function testEveryEntryOfThePageRunsInItsOrder(): void
    {
        $tags = array_map(static fn (string $file) => ['value' => $file] + WebpackTagRenderer::scriptAttributes($file), self::PAGE);

        $deferred = array_values(array_filter($tags, static fn (array $tag) => $tag['defer']));
        self::assertSame([], array_values(array_filter($tags, static fn (array $tag) => $tag['async'])), 'nothing left to arrive in any order');
        self::assertSame(
            ['/bundles/base/base-async.js', '/bundles/base/base-defer.js', '/bundles/base/form-defer.js', '/assets/app-async.js', '/assets/layout1-async.js', '/assets/app-defer.js'],
            array_column($deferred, 'value'),
            'deferred in the page\'s order: the jQuery provider first',
        );
        // The runtimes, named for no one, keep the defaults: run where they stand, before every deferred file.
        self::assertSame(['defer' => false, 'async' => false], WebpackTagRenderer::scriptAttributes('/assets/runtime.js'));
    }

    public function testAsyncComesBackOnlyWhenTheSiteSaysOrderedFalse(): void
    {
        self::assertSame(['defer' => false, 'async' => true], WebpackTagRenderer::scriptAttributes('/assets/app-async.js', ['ordered' => false]));
        self::assertSame(['defer' => true, 'async' => false], WebpackTagRenderer::scriptAttributes('/assets/app-defer.js', ['ordered' => false]));
    }

    public function testTheDefaultsStillApplyToTheFilesNamedForNoOne(): void
    {
        self::assertSame(['defer' => true, 'async' => false], WebpackTagRenderer::scriptAttributes('/assets/runtime.js', ['defer' => true]));
        self::assertSame(['defer' => true, 'async' => false], WebpackTagRenderer::scriptAttributes('/assets/runtime.js', ['async' => true]), 'async by default is in order too');
        self::assertSame(['defer' => false, 'async' => true], WebpackTagRenderer::scriptAttributes('/assets/runtime.js', ['async' => true, 'ordered' => false]));
    }
}
