<?php

namespace Base\Twig\Renderer\Adapter;

use Base\Cache\Abstract\AbstractLocalCacheInterface;
use Base\Service\LocalizerInterface;
use Base\Service\ParameterBagInterface;
use Base\Traits\SimpleCacheTrait;
use Base\Twig\AssetPackage;
use Base\Twig\Renderer\AbstractTagRenderer;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpArrayAdapter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookup;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupCollectionInterface;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupInterface;
use Symfony\WebpackEncoreBundle\Exception\EntrypointNotFoundException;
use Symfony\WebpackEncoreBundle\Exception\UndefinedBuildException;
use Twig\Environment;

class WebpackTagRenderer extends AbstractTagRenderer implements AbstractLocalCacheInterface
{
    use SimpleCacheTrait;

    /**
     * @var ?EntrypointLookupCollectionInterface
     */
    protected ?EntrypointLookupCollectionInterface $entrypointLookupCollection;

    protected Packages $packages;

    protected Process $clearProcess;

    protected string $publicDir;

    protected string $cacheDir;

    private bool $saveDeferred = false;
    private ?CacheItemPoolInterface $cache = null;

    protected bool $debug;

    public function __construct(
        Environment                          $twig,
        LocalizerInterface                   $localizer,
        SluggerInterface                     $slugger,
        ParameterBagInterface                $parameterBag,
        ?EntrypointLookupCollectionInterface $entrypointLookupCollection,
        Packages                             $packages,
        string                               $publicDir,
        string                               $cacheDir,
        ?string                              $buildDir = null
    )
    {
        $this->entrypointLookupCollection = $entrypointLookupCollection;
        if (null == $this->entrypointLookupCollection) {
            return;
        }

        parent::__construct($twig, $localizer, $slugger, $parameterBag);
        $this->publicDir = $publicDir;
        $this->packages = $packages;

        // This class already inherits from AbstractTagRenderer..
        $this->cacheDir = $cacheDir;

        $phpCacheFile = $cacheDir . '/pools/simple/php/' . str_replace(['\\', '/'], ['__', '_'], static::class) . '.php';
        $this->setCache(new PhpArrayAdapter($phpCacheFile, new FilesystemAdapter('', 0, $this->getCacheFile())));

        $this->debug = $parameterBag->get('kernel.debug');
        $this->warmUp($cacheDir, $buildDir);

        $this->clearProcess = new Process(['php', 'bin/console', 'cache:clear']);
        $this->clearProcess->setWorkingDirectory( $parameterBag->get('kernel.project_dir'));
    }

    /**
     * @return string
     */
    public function getCacheFile()
    {
        return $this->cacheDir . '/pools/simple/fs/' . str_replace(['\\', '/'], ['__', '_'], static::class);
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->entrypoints = $this->getCache('/Entrypoints', $this->entrypoints ?? []);
        $this->entrypointHashes = $this->getCache('/Entrypoints/Hashes', $this->entrypointHashes ?? []);
        $this->optionalEntryNames = $this->getCache('/Optional/Entrynames', $this->optionalEntryNames ?? []);
        $this->renderedCssSource = $this->getCache('/Source', $this->renderedCssSource ?? []);
        $this->renderedLinkTags = $this->getCache('/Link', $this->renderedLinkTags ?? []);
        $this->renderedScriptTags = $this->getCache('/Script', $this->renderedScriptTags ?? []);

        return [];
    }

    protected array $entrypoints = [];
    protected array $entryLinkTags = [];
    protected array $entryScriptTags = [];

    protected array $entrypointHashes;
    protected array $breakpoints = [];

    public function getMedia(string $filename): ?string
    {
        foreach ($this->breakpoints as $media => $names) {
            foreach ($names as $name) {
                if (str_contains($filename, '-' . $name)) {
                    return $media;
                }
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public function getBreakpoints()
    {
        return $this->breakpoints;
    }

    /**
     * @param string $name
     * @param string $media
     * @return $this
     */
    public function addBreakpoint(string $name, string $media)
    {
        $this->breakpoints[$media] = array_merge($this->breakpoints[$media] ?? [], [$name]);

        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function removeBreakpoint(string $name)
    {
        array_remove($this->breakpoints, $name);

        return $this;
    }

    protected array $alternatives = [];

    /**
     * @return array
     */
    public function getAlternatives()
    {
        return $this->alternatives;
    }

    /**
     * @param string $alternative
     * @return $this
     */
    public function addAlternative(string $alternative)
    {
        if (!array_key_exists($alternative, $this->alternatives)) {
            $this->alternatives[] = $alternative;
        }

        return $this;
    }

    /**
     * @param string $alternative
     * @return $this
     */
    public function removeAlternative(string $alternative)
    {
        array_remove($this->alternatives, $alternative);

        return $this;
    }

    public function getEntryLinkTags(): array
    {
        return $this->entryLinkTags;
    }

    public function getEntryScriptTags(): array
    {
        return $this->entryScriptTags;
    }

    public function getEntrypoints(): array
    {
        return $this->entrypoints;
    }

    /**
     * Reads the entries of the application's build (its entrypoints.json) and of the core's
     * (public/bundles/base/entrypoints.json), the breakpoints and the alternatives (-async, -defer).
     *
     * @return bool whether both builds were there with entries: a build being written (Encore's
     *              watcher empties its output first) is not complete, and what was read of it is
     *              never kept beyond the request (see WebpackCacheWarmer::doWarmUp())
     */
    public function discoverEntrypoints(?string $appJsonPath, array $breakpoints = []): bool
    {
        $complete = true;
        foreach (['_default' => $appJsonPath, '_base' => rtrim($this->publicDir, '/') . '/bundles/base/entrypoints.json'] as $name => $path) {
            $entrypoints = null;
            if ($path && is_file($path)) {
                $json = json_decode((string) @file_get_contents($path), true);
                $entrypoints = \is_array($json['entrypoints'] ?? null) ? $json['entrypoints'] : null;
            }
            if (!$entrypoints) {
                $complete = false;
                continue;
            }

            $this->addEntrypoint($name, $path);
            $tags = array_unique(array_map(fn($t) => str_rstrip($t, ['-async', '-defer']), array_keys($entrypoints)));
            foreach ($tags as $tag) {
                '_base' === $name ? $this->addTag($tag, '_base') : $this->addTag($tag);
                if (str_contains($tag, '.')) {
                    $this->markAsOptional($tag);
                }
            }
        }

        foreach ($breakpoints as $breakpoint) {
            $this->addBreakpoint($breakpoint['name'], $breakpoint['media'] ?? 'all');
        }
        $this->addAlternative('async');
        $this->addAlternative('defer');

        return $complete;
    }

    private bool $discovered = false;

    /**
     * No entry known - the cache was made while a build was being written, and kept none:
     * read them now, for this request (a page then has its stylesheets as soon as the build
     * is done, without a cache:clear).
     */
    protected function discoverIfNone(): void
    {
        if ($this->discovered || [] !== $this->entrypoints || null === $this->entrypointLookupCollection) {
            return;
        }
        $this->discovered = true;

        try {
            $lookup = $this->entrypointLookupCollection->getEntrypointLookup('_default');
            $appJsonPath = first(array_filter((array) $lookup, fn($k) => str_ends_with($k, 'entrypointJsonPath'), ARRAY_FILTER_USE_KEY)) ?: null;
        } catch (\Throwable) {
            $appJsonPath = null;    // no build of the application's: the core's alone
        }

        $this->discoverEntrypoints(\is_string($appJsonPath) ? $appJsonPath : null, $this->parameterBag->get('base.twig.breakpoints') ?? []);
    }

    public function getEntry(string $entrypointName): ?EntrypointLookupInterface
    {
        return $this->entrypoints[$entrypointName] ?? null;
    }

    /**
     * @param string $value
     * @param string $entrypointJsonPath
     * @param CacheItemPoolInterface|null $cache
     * @param string|null $cacheKey
     * @param bool $strictMode
     * @return $this
     */
    public function addEntrypoint(string $value, string $entrypointJsonPath, ?CacheItemPoolInterface $cache = null, ?string $cacheKey = null, bool $strictMode = true)
    {
        if (null == $this->entrypointLookupCollection) {
            throw new \LogicException('You cannot use "' . __CLASS__ . '::' . __METHOD__ . '" as the "symfony/webpack-encore-bundle" package is not installed. Try running "composer require symfony/webpack-encore-bundle".');
        }

        $this->entrypoints[$value] = new EntrypointLookup($entrypointJsonPath, $cache, $cacheKey, $strictMode);

        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function removeEntrypoint(string $value)
    {
        if (null == $this->entrypointLookupCollection) {
            throw new \LogicException('You cannot use "' . __CLASS__ . '::' . __METHOD__ . '" as the "symfony/webpack-encore-bundle" package is not installed. Try running "composer require symfony/webpack-encore-bundle".');
        }

        if (array_key_exists($value, $this->entrypoints)) {
            unset($this->entrypoints[$value]);
        }

        return $this;
    }

    public function hasEntry(string $entryName, string $entrypointName = '_default'): bool
    {
        if (null === $this->entrypointLookupCollection) {
            return false;
        }

        $entrypointLookup = $this->entrypointLookupCollection->getEntrypointLookup($entrypointName);
        if (!$entrypointLookup instanceof EntrypointLookup) {
            throw new \LogicException(sprintf('Cannot use entryExists() unless the entrypoint lookup is an instance of "%s"', EntrypointLookup::class));
        }

        try {
            return $entrypointLookup->entryExists($entryName);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * @param string $value
     * @param string|null $packageName
     * @param string|null $entrypointName
     * @param string|null $htmlAttributes
     * @return $this
     */
    public function addTag(string $value, ?string $packageName = null, ?string $entrypointName = null, ?string $htmlAttributes = null)
    {
        $this->addLinkTag($value, $packageName, $entrypointName, $htmlAttributes);
        $this->addScriptTag($value, $packageName, $entrypointName, $htmlAttributes);

        return $this;
    }

    protected function refreshCacheIfNeeded()
    {
        if ($this->debug) {

            foreach ($this->entrypoints as $id => $entrypoint) {
                $entrypointJsonPath = read_property($entrypoint, 'entrypointJsonPath');
                $entrypointHash = hash_file('md5', $entrypointJsonPath);

                $this->entrypointHashes[$entrypointJsonPath] ??= $entrypointHash;
                if (!is_cli() && $entrypointHash != $this->entrypointHashes[$entrypointJsonPath]) {
                    $this->entrypointHashes[$entrypointJsonPath] = $entrypointHash;

                    if($this->parameterBag->get("base.autoclear")) $this->clearProcess->mustRun();
                    else {

                        throw new \RuntimeException("Entrypoint '" . $id . "' got modified.. please refresh your cache");
                    }
                }
            }
        }
    }

    protected array $optionalEntryNames = [];

    public function loadEntry(array|string|null $value)
    {
        $this->markAsOptional($value, false);
    }

    public function markAsOptional(array|string|null $value, bool $isOptional = true)
    {
        if(!$value) return;
        
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $value) {
            $this->optionalEntryNames[$value] = $isOptional;
        }
    }

    public function isOptional(string $value): bool
    {
        return ($this->optionalEntryNames[$value] ?? true) == true;
    }

    /**
     * @param string $value
     * @param string|null $packageName
     * @param string|null $entrypointName
     * @param string|null $htmlAttributes
     * @return $this
     */
    public function addLinkTag(string $value, ?string $packageName = null, ?string $entrypointName = null, ?string $htmlAttributes = null)
    {
        $this->refreshCacheIfNeeded();

        $entryName = $this->slugify($value);
        if (!array_key_exists($entryName, $this->entryLinkTags)) {
            $this->entryLinkTags[$entryName] = array_filter([
                'value' => $value,
                'webpack_package_name' => $packageName,
                'webpack_entrypoint_name' => $entrypointName,
                'html_attributes' => $htmlAttributes,
            ]);
        }

        return $this;
    }

    protected function slugify(string $entryName, ?string $alternative = null): string
    {
        $entryNameArray = [];
        foreach (explode('.', $entryName) as $id => $entry) {
            $entryNameArray[] = $this->slugger->slug($entry) . (0 == $id && $alternative ? '-' . $alternative : '');
        }

        return implode('.', $entryNameArray);
    }

    /**
     * @param string $value
     * @param string|null $packageName
     * @param string|null $entrypointName
     * @param string|null $htmlAttributes
     * @return $this
     */
    public function addScriptTag(string $value, ?string $packageName = null, ?string $entrypointName = null, ?string $htmlAttributes = null)
    {
        $this->refreshCacheIfNeeded();

        $entryName = $this->slugify($value);
        if (!array_key_exists($entryName, $this->entryScriptTags)) {
            $this->entryScriptTags[$entryName] = array_filter([
                'value' => $value,
                'webpack_package_name' => $packageName,
                'webpack_entrypoint_name' => $entrypointName,
                'html_attributes' => $htmlAttributes,
            ]);
        }

        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function removeTags(string $value)
    {
        $this->removeLinkTag($value);
        $this->removeScriptTag($value);

        return $this;
    }

    /**
     * @param string $entryName
     * @return $this
     */
    public function removeLinkTag(string $entryName)
    {
        $entryValue = [];
        foreach (explode('.', $this->slugify($entryName)) as $slugValue) {
            $entryValue[] = $slugValue;
            $linkTag = implode('.', $entryValue);
            if (array_key_exists($linkTag, $this->entryScriptTags)) {
                unset($this->entryScriptTags[$linkTag]);
            }
        }

        return $this;
    }

    /**
     * @param string $entryName
     * @return $this
     */
    public function removeScriptTag(string $entryName)
    {
        if (null == $this->entrypointLookupCollection) {
            return $this;
        }

        $entryValue = [];
        foreach (explode('.', $this->slugify($entryName)) as $slugValue) {
            $entryValue[] = $slugValue;
            $scriptTag = implode('.', $entryValue);
            if (array_key_exists($scriptTag, $this->entryScriptTags)) {
                unset($this->entryScriptTags[$scriptTag]);
            }
        }

        return $this;
    }

    protected array $renderedCssSource = [];
    protected array $renderedScriptTags = [];
    protected array $renderedLinkTags = [];

    /**
     * @param $entrypoints
     * @return void
     */
    public function reset($entrypoints = null)
    {
        if ($entrypoints && !is_array($entrypoints)) {
            $entrypoints = [$entrypoints];
        }
        foreach ($entrypoints ?? $this->getEntrypoints() as $entrypoint) {
            $entrypoint->reset();
        }
    }

    public function renderOptionalCssSource(string $entryName, ?string $packageName = null, ?string $entrypointName = null, ?string $htmlAttributes = null): string
    {
        $renderedCssSource = [];
        foreach ($this->optionalEntryNames as $id => $tag) {
            if ($tag) {
                continue;
            }
            if (str_starts_with($id, $entryName) && $entryName != $id) {
                $renderedCssSource[] = $this->renderCssSource($id, $packageName, $entrypointName, $htmlAttributes);
            }
        }

        return implode(PHP_EOL, $renderedCssSource);
    }

    public function renderCssSource(string|array $entryName, ?string $packageName = null, ?string $entrypointName = null, ?string $htmlAttributes = null): string
    {
        $this->discoverIfNone();

        $entryName = is_array($entryName) ? $value['value'] ?? null : $entryName;
        if (!$entryName) {
            return '';
        }

        $entryName = $this->slugify($entryName);
        if (array_key_exists($entryName, $this->renderedCssSource)) {
            return $this->renderedCssSource[$entryName] . $this->renderOptionalCssSource($entryName);
        }

        $this->addLinkTag($entryName, $packageName, $entrypointName, $htmlAttributes);

        $source = '';

        foreach ($this->getEntrypoints() as $entrypoint) {
            try {
                $files = $entrypoint->getCssFiles($entryName);
            } catch (UndefinedBuildException|EntrypointNotFoundException $e) {
                continue;
            }

            foreach ($files as $file) {
                $source .= file_get_contents($this->publicDir . '/' . $file);
            }

            $this->removeLinkTag($entryName);
            $entrypoint->reset();

            $this->renderedCssSource[$entryName] = $source;

            return $this->renderedCssSource[$entryName] . $this->renderOptionalCssSource($entryName);
        }

        throw new EntrypointNotFoundException('Failed to find "' . $entryName . '" in the lookup collection: ' . implode(', ', array_keys($this->getEntrypoints())));
    }

    public function renderOptionalLinkTags(null|string|array $entryName = null, ?string $packageName = null, ?string $entrypointName = null, array $htmlAttributes = []): string
    {
        $renderedLinkTags = [];
        foreach ($this->optionalEntryNames as $id => $tag) {
            if ($tag) {
                continue;
            }
            if (str_starts_with($id, $entryName) && $entryName != $id) {
                $renderedLinkTags[] = $this->renderLinkTags($id, $packageName, $entrypointName, $htmlAttributes);
            }
        }

        return implode(PHP_EOL, $renderedLinkTags);
    }

    public function renderLinkTags(null|string|array $entryName = null, ?string $packageName = null, ?string $entrypointName = null, array $htmlAttributes = []): string
    {
        $this->discoverIfNone();

        if (null == $this->entrypointLookupCollection) {
            return '';
        }

        $entryName = is_array($entryName) ? $entryName['value'] ?? null : $entryName;
        if (!$entryName) {
            return '';
        }

        $entryName = $this->slugify($entryName);
        if (array_key_exists($entryName, $this->renderedLinkTags)) {
            return $this->renderedLinkTags[$entryName] . $this->renderOptionalLinkTags($entryName);
        }

        if (!array_key_exists($entryName, $this->entryLinkTags)) {
            $this->addLinkTag($entryName, $packageName, $entrypointName, html_attributes($htmlAttributes));
        }

        $tags = [];
        foreach ($this->getEntrypoints() as $entrypoint) {
            $files = [];

            $entryValue = [];
            foreach (explode('.', $this->slugify($entryName)) as $slugValue) {
                try {
                    $entryValue[] = $slugValue;
                    $files = array_merge($files, $entrypoint->getCssFiles(implode('.', $entryValue)));
                } catch (UndefinedBuildException|EntrypointNotFoundException $e) {
                }
            }

            foreach ($this->alternatives as $alternative) {
                $entryValue = [];
                foreach (explode('.', $this->slugify($entryName, $alternative)) as $slugValue) {
                    try {
                        $entryValue[] = $slugValue;
                        $files = array_merge($files, $entrypoint->getCssFiles(implode('.', $entryValue)));
                    } catch (UndefinedBuildException|EntrypointNotFoundException $e) {
                    }
                }
            }

            /**
             * @var AssetPackage $this
             */
            $basePackage = $this->packages->getPackage(AssetPackage::PACKAGE_NAME);
            $appPackage = $this->packages->getPackage();
            for ($i = 0, $N = count($files); $i < $N; ++$i) {
                foreach ($this->breakpoints as $_) {
                    foreach ($_ as $name) {
                        $file = explode('.', $files[$i])[0];
                        $file = path_suffix($file, $name, '-') . '.css';
                        if (str_starts_with($file, $basePackage->getBasePath())) {
                            $file = $basePackage->getUrl('./' . $basePackage->stripPrefix($file));
                        } else {
                            $file = $appPackage->getUrl('./' . str_lstrip($file, '/assets/')); // @TODO Use webpack encore output path injection
                        }

                        if (file_exists($this->publicDir . $file)) {
                            $files[] = $file;
                        }
                    }
                }
            }

            if (!$files) {
                continue;
            }

            $tags = array_filter(array_map(fn($e) => [
                'value' => $e,
                'defer' => str_contains($e, '-defer') || $this->defaultLinkAttributes['defer'],
                'async' => str_contains($e, '-async') || $this->defaultLinkAttributes['async'],
                'media' => $this->getMedia($e),
            ], $files));

            $this->removeLinkTag($entryName);

            $this->renderedLinkTags[$entryName] = $this->twig->render('@Base/webpack/link_tags.html.twig', ['tags' => $tags]);

            return $this->renderedLinkTags[$entryName] . $this->renderOptionalLinkTags($entryName);
        }

        // Link is not mandatory.. (e.g. when no css needed)
        //throw new EntrypointNotFoundException('Failed to find "' . $entryName . '" in the lookup collection: ' . implode(', ', array_keys($this->getEntrypoints())));
        return '';
    }

    public function renderOptionalScriptTags(null|string|array $entryName = null, ?string $packageName = null, ?string $entrypointName = null, array $htmlAttributes = []): string
    {
        $renderedScriptTags = [];
        foreach ($this->optionalEntryNames as $id => $tag) {
            if ($tag) {
                continue;
            }
            if (str_starts_with($id, $entryName) && $entryName != $id) {
                $renderedScriptTags[] = $this->renderScriptTags($id, $packageName, $entrypointName, $htmlAttributes);
            }
        }

        return implode(PHP_EOL, $renderedScriptTags);
    }

    /**
     * How a script file is loaded: `defer` for a file named `-defer`, `async`
     * for one named `-async`, the defaults (base.twig.script_attributes) for
     * the others.
     *
     * Unless the defaults say `ordered: false`, a file named `-async` is
     * loaded `defer` too: deferred scripts run in the order of the page, async
     * ones whenever they arrive. The core's `base-async` entry is what sets
     * `window.jQuery`, and a site's entries read it as an external
     * (`addExternals({ jquery: 'jQuery' })`, its own `app-async` included):
     * left async, nothing made the core's run first, and the site's threw
     * "jQuery is not defined" when its file came back sooner. The page renders
     * `base` before the site's entries, so in order the global is always there.
     *
     * @param array{defer?: bool, async?: bool, ordered?: bool} $defaults
     *
     * @return array{defer: bool, async: bool}
     */
    public static function scriptAttributes(string $file, array $defaults = []): array
    {
        $isAsync = str_contains($file, '-async');
        $isDefer = str_contains($file, '-defer');
        $async = $isAsync || (!$isDefer && (bool) ($defaults['async'] ?? false));
        $defer = $isDefer || (!$isAsync && (bool) ($defaults['defer'] ?? false));

        if ($async && ($defaults['ordered'] ?? true)) {
            return ['defer' => true, 'async' => false];
        }

        return ['defer' => $defer, 'async' => $async];
    }

    public function renderScriptTags(null|string|array $entryName = null, ?string $packageName = null, ?string $entrypointName = null, array $htmlAttributes = []): string
    {
        $this->discoverIfNone();

        if (null == $this->entrypointLookupCollection) {
            return '';
        }

        $entryName = is_array($entryName) ? $entryName['value'] ?? null : $entryName;
        if (!$entryName) {
            return '';
        }

        $entryName = $this->slugify($entryName);
        if (array_key_exists($entryName, $this->renderedScriptTags)) {
            return $this->renderedScriptTags[$entryName] . $this->renderOptionalScriptTags($entryName);
        }

        if (!array_key_exists($entryName, $this->entryScriptTags)) {
            $this->addScriptTag($entryName, $packageName, $entrypointName, html_attributes($htmlAttributes));
        }

        foreach ($this->getEntrypoints() as $entrypoint) {
            $files = [];

            $entryValue = [];
            foreach (explode('.', $this->slugify($entryName)) as $slugValue) {
                try {
                    $entryValue[] = $slugValue;
                    $files = array_merge($files, $entrypoint->getJavaScriptFiles(implode('.', $entryValue)));
                } catch (UndefinedBuildException|EntrypointNotFoundException $e) {
                }
            }

            foreach ($this->alternatives as $alternative) {
                $entryValue = [];
                foreach (explode('.', $this->slugify($entryName, $alternative)) as $slugValue) {
                    try {
                        $entryValue[] = $slugValue;
                        $files = array_merge($files, $entrypoint->getJavaScriptFiles(implode('.', $entryValue)));
                    } catch (UndefinedBuildException|EntrypointNotFoundException $e) {
                    }
                }
            }

            if (!$files) {
                continue;
            }

            $tags = array_filter(array_map(fn($e) => ['value' => $e] + self::scriptAttributes($e, $this->defaultScriptAttributes), $files));

            $this->removeScriptTag($entryName);

            $this->renderedScriptTags[$entryName] = $this->twig->render('@Base/webpack/script_tags.html.twig', ['tags' => $tags]);

            return $this->renderedScriptTags[$entryName] . $this->renderOptionalScriptTags($entryName);
        }

        // Script is not mandatory.. (e.g. when calling entryCssSource)
        // throw new EntrypointNotFoundException("Failed to find \"".$entryName."\" in the lookup collection: ".implode(", ", array_keys($this->getEntrypoints())));
        return '';
    }

    public function render(string $name, ?array $context = [], ?string $packageName = null, ?string $entrypointName = null, array $htmlAttributes = []): string
    {
        return $this->renderLinkTags($name, $packageName, $entrypointName, $htmlAttributes) . PHP_EOL .
            $this->renderScriptTags($name, $packageName, $entrypointName, $htmlAttributes);
    }

    public function renderFallback(Response $response): Response
    {
        $content = $response->getContent();
        foreach ($this->getEntryLinkTags() as $tag) {
            if ($this->isOptional($tag['value'])) {
                continue;
            }
            if (array_key_exists($tag['value'], $this->renderedLinkTags)) {
                continue;
            }

            $content = preg_replace('/<\/head\b[^>]*>/', $this->renderLinkTags($tag) . '$0', $content, 1);
        }

        foreach ($this->getEntryScriptTags() as $tag) {
            if ($this->isOptional($tag['value'])) {
                continue;
            }
            if (array_key_exists($tag['value'], $this->renderedScriptTags)) {
                continue;
            }

            $content = preg_replace('/<\/body\b[^>]*>/', $this->renderScriptTags($tag) . '$0', $content, 1);
        }

        $response->setContent($content);

        return $response;
    }
}
