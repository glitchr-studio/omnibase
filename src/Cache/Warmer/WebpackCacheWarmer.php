<?php

namespace Base\Cache\Warmer;

use Base\Cache\Abstract\AbstractLocalCacheWarmer;
use Base\Service\ParameterBagInterface;
use Base\Twig\Renderer\Adapter\WebpackTagRenderer;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupInterface;

/**
 * The entries of the application's build and of the core's, and their tags
 * rendered once, kept in the cache - unless a build was being written when
 * the cache was made (the web container restarted while Encore's watcher
 * rebuilds: entrypoints.json absent, or without entries). What was read then
 * is not kept: it used to stay until cache:pool:clear --all, every page
 * without its stylesheets. The renderer reads the builds again on a request
 * that finds none (WebpackTagRenderer::discoverIfNone()).
 */
class WebpackCacheWarmer extends AbstractLocalCacheWarmer
{
    private bool $complete = false;

    public function __construct(ParameterBagInterface $parameterBag, WebpackTagRenderer $webpackTagRenderer, ?EntrypointLookupInterface $entrypointLookup, string $cacheDir, string $publicDir)
    {
        if (!$parameterBag->get('base.twig.use_custom')) {
            return;
        }
        if (!$entrypointLookup) {
            return;
        }

        $appJsonPath = array_filter((array)$entrypointLookup, fn($k) => str_ends_with($k, 'entrypointJsonPath'), ARRAY_FILTER_USE_KEY);
        $appJsonPath = first($appJsonPath);

        $this->complete = $webpackTagRenderer->discoverEntrypoints(\is_string($appJsonPath) ? $appJsonPath : null, $parameterBag->get('base.twig.breakpoints') ?? []);

        // Encore rest rendering
        $webpackTagRenderer->renderFallback(new Response());
        $webpackTagRenderer->reset();

        parent::__construct($webpackTagRenderer, $cacheDir);
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    protected function doWarmUp(string $cacheDir, ArrayAdapter $arrayAdapter, ?string $buildDir = null): bool
    {
        // A build being written is no build to remember.
        if (!$this->complete) {
            return false;
        }

        return parent::doWarmUp($cacheDir, $arrayAdapter, $buildDir);
    }
}
