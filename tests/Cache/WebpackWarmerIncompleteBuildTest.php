<?php

namespace Tests\Base\Cache;

use Base\Cache\Warmer\WebpackCacheWarmer;
use Base\Twig\Renderer\Adapter\WebpackTagRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookup;

/**
 * A build being written is not remembered. The web container restarted while
 * Encore's watcher rebuilt the assets: entrypoints.json was not there yet (or
 * held no entry), the cache warmer kept that empty list and the tags it
 * rendered, and every page came out without its stylesheets until
 * cache:pool:clear --all. Now an incomplete build writes no cache, and the
 * renderer reads the builds again on a request that finds no entry.
 */
class WebpackWarmerIncompleteBuildTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }
        self::bootKernel();
        if (!static::getContainer()->getParameter('base.twig.use_custom')) {
            self::markTestSkipped('The host does not render its tags through omnibase (base.twig.use_custom).');
        }
        $this->dir = sys_get_temp_dir().'/webpack-warmer-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$this->dir.'/build', $this->dir.'/cache']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private function renderer(): WebpackTagRenderer
    {
        $renderer = clone static::getContainer()->get(WebpackTagRenderer::class);
        foreach (['entrypoints' => [], 'renderedLinkTags' => [], 'renderedScriptTags' => [], 'renderedCssSource' => [], 'entryLinkTags' => [], 'entryScriptTags' => []] as $property => $value) {
            (new \ReflectionProperty(WebpackTagRenderer::class, $property))->setValue($renderer, $value);
        }

        return $renderer;
    }

    private function warmer(WebpackTagRenderer $renderer): WebpackCacheWarmer
    {
        return new WebpackCacheWarmer(static::getContainer()->get('parameter_bag'), $renderer, new EntrypointLookup($this->dir.'/build/entrypoints.json'), $this->dir.'/cache', static::getContainer()->getParameter('kernel.project_dir').'/public');
    }

    private function cacheFiles(): array
    {
        return glob($this->dir.'/cache/pools/simple/php/*') ?: [];
    }

    private function build(?array $entrypoints): void
    {
        $file = $this->dir.'/build/entrypoints.json';
        null === $entrypoints ? @unlink($file) : file_put_contents($file, json_encode(['entrypoints' => $entrypoints]));
    }

    public function testABuildNotThereYetIsNotKept(): void
    {
        $this->build(null);
        $warmer = $this->warmer($this->renderer());

        $this->assertFalse($warmer->isComplete());
        $this->assertSame([], $warmer->warmUp($this->dir.'/cache'));
        $this->assertSame([], $this->cacheFiles(), 'nothing written');
    }

    public function testABuildWithoutEntriesIsNotKept(): void
    {
        $this->build([]);
        $warmer = $this->warmer($this->renderer());

        $this->assertFalse($warmer->isComplete());
        $warmer->warmUp($this->dir.'/cache');
        $this->assertSame([], $this->cacheFiles());
    }

    public function testABuildDoneIsKept(): void
    {
        if (!is_file(static::getContainer()->getParameter('kernel.project_dir').'/public/bundles/base/entrypoints.json')) {
            self::markTestSkipped('The core\'s build is not installed in the host (assets:install).');
        }
        $this->build(['app-async' => ['js' => ['/assets/app-async.js'], 'css' => ['/assets/app-async.css']]]);
        $renderer = $this->renderer();
        $warmer = $this->warmer($renderer);

        $this->assertTrue($warmer->isComplete());
        $this->assertArrayHasKey('_default', $renderer->getEntrypoints());
        $this->assertArrayHasKey('_base', $renderer->getEntrypoints());
        $warmer->warmUp($this->dir.'/cache');
        $this->assertNotSame([], $this->cacheFiles(), 'the entries and their tags, kept');
    }

    public function testARequestThatFindsNoEntryReadsTheBuildsAgain(): void
    {
        $renderer = $this->renderer();
        $this->assertSame([], $renderer->getEntrypoints(), 'what a cache made during a build left');

        $renderer->renderScriptTags('base');

        $this->assertNotSame([], $renderer->getEntrypoints(), 'read again on the request, the build being done');
    }
}
