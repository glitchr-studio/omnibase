<?php

namespace Base\Service;

use Base\Attributes\Attribute\Sitemap;
use Base\Attributes\AttributeReader;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\Model\SitemapEntry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\Router;
use Twig\Environment;

class Sitemapper implements SitemapperInterface
{
    /**
     * @var Environment
     */
    protected $twig;

    /**
     * @var AttributeReader
     */
    protected $attributeReader;

    /**
     * @var AdvancedRouterInterface
     */
    protected $router;

    /**
     * @var LocalizerInterface
     */
    protected $localizer;

    /**
     * @var MimeTypes
     */
    protected $mimeTypes;

    /** How many pages one route with enumerated parameters may add. */
    protected const MAX_ENUMERATED = 200;

    private $computeFlag = true;
    protected string $hostname = "";
    protected array $urlset = [];

    protected ?LoggerInterface $logger;

    public function __construct(Environment $twig, AttributeReader $attributeReader, AdvancedRouterInterface $router, LocalizerInterface $localizer, ?LoggerInterface $logger = null)
    {
        $this->twig = $twig;
        $this->router = $router;
        $this->localizer = $localizer;
        $this->attributeReader = $attributeReader;
        $this->logger = $logger;

        $this->mimeTypes = new MimeTypes();
    }

    public function getSitemap(Route $route): ?Sitemap
    {
        $controller = $route->getDefault("_controller");
        if ($controller === null) {
            return null;
        }

        // An invokable controller is registered as the bare class, with no
        // "::method" - so this destructuring warned "Undefined array key 1",
        // and in an environment that turns warnings into exceptions that is a
        // 500 on /sitemap.xml, the one URL robots.txt points a crawler at.
        //
        // And a controller is not always a string: a route declared in PHP may
        // give [Class::class, 'method'] - explode() died on the array, the same
        // 500 for the whole sitemap - or a closure, which carries no attribute.
        if (is_array($controller)) {
            $class = is_object($controller[0] ?? null) ? $controller[0]::class : ($controller[0] ?? null);
            $method = $controller[1] ?? "__invoke";
        } elseif (is_string($controller)) {
            $parts = explode("::", $controller, 2);
            $class = $parts[0];
            $method = $parts[1] ?? "__invoke";
        } else {
            return null;
        }

        if (!is_string($class) || !is_string($method) || !class_exists($class)) {
            return null;
        }

        $attributes = $this->attributeReader->getAttributes($class, Sitemap::class, [AttributeReader::TARGET_METHOD]);
        $attributes = $attributes[AttributeReader::TARGET_METHOD][$class][$method] ?? [];

        $sitemap = end($attributes);
        return $sitemap === false ? null : $sitemap;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function setHostname(string $hostname): self
    {
        $this->hostname = $hostname;
        return $this;
    }

    /**
     * One page into the sitemap: a route (its name, or the Route) and the
     * parameters of the page.
     *
     * Nothing here is fatal. A route that does not exist, an address that
     * matches nothing, a route whose action carries no #[Sitemap]: the page
     * is left out and the reason logged (warning, "sitemap" in the message).
     * One listener registering a route nobody declared used to throw, and
     * the whole /sitemap.xml - every other page of the site with it -
     * answered 500.
     */
    public function register(string|Route $routeOrName, array $routeParameters = [], ?string $lastMod = null): self
    {
        if (is_string($routeOrName)) {
            $route = $this->router->getRoute($routeOrName);
            if (!$route) {
                return $this->skipped(sprintf('route "%s" does not exist', $routeOrName));
            }
        } else {
            $route = $routeOrName;
        }

        // A route that takes parameters cannot be matched by its own path:
        // "/{slug}" is not a URL, the matcher finds nothing and the entry was
        // dropped without a word - which is every URL a SitemapEvent listener
        // registers, the ones the attributes cannot reach on their own. Put
        // the caller's parameters in first, and match a real address.
        $path = $route->getPath();
        foreach ($routeParameters as $parameter => $value) {
            if (str_starts_with((string) $parameter, "_")) {
                continue;
            }
            $path = str_replace("{" . $parameter . "}", rawurlencode((string) $value), $path);
        }

        $routeMatch = $this->router->getRouteMatch($path);
        if (!$routeMatch) {
            return $this;
        }

        $sitemap = $this->getSitemap($route);
        if (!$sitemap) {
            $controller = $routeMatch["_controller"] ?? $route->getDefault("_controller") ?? $route->getPath();

            return $this->skipped(sprintf(
                'no #[Sitemap] on "%s" (%s)',
                is_array($controller) ? implode("::", array_map(fn($c) => is_object($c) ? $c::class : (string) $c, $controller)) : (is_object($controller) ? $controller::class : (string) $controller),
                is_string($routeOrName) ? 'route "' . $routeOrName . '"' : $route->getPath()
            ));
        }

        $routeName = $sitemap->getGroup() ?? $route->getDefaults()["_canonical_route"] ?? $routeMatch["_route"] ?? null;
        if (!$routeName) {
            return $this;
        }

        $this->computeFlag = false;

        $routeDefaults = $route->getDefaults();
        $routeParameters = array_filter($routeParameters, fn($p) => !str_starts_with($p, "_"), ARRAY_FILTER_USE_KEY);
        if (array_key_exists("_locale", $routeDefaults)) {
            $routeParameters["_locale"] = $routeDefaults["_locale"];
        }

        $url = $this->router->generate($routeName, $routeParameters, Router::ABSOLUTE_URL);

        $sitemapEntry = new SitemapEntry($url);
        $sitemapEntry->setPriority($sitemap->getPriority());
        // The attribute is written once per route, so its lastmod is the same
        // for every page the route serves - which tells a crawler that the
        // whole site changed today, every day. A caller that knows when this
        // particular entity was last touched says so here.
        $sitemapEntry->setLastMod($lastMod ?? $sitemap->getLastMod());
        $sitemapEntry->setChangeFreq($sitemap->getChangeFreq());

        $locale = $this->localizer->getLocale($routeParameters["_locale"] ?? null);
        $sitemapEntry->setLocale($locale);

        $routeParameters = array_filter($routeParameters, fn($p) => !str_starts_with($p, "_"), ARRAY_FILTER_USE_KEY);
        if (!array_key_exists($routeName . "." . md5(serialize($routeParameters)), $this->urlset)) {
            $this->urlset[$routeName . "." . md5(serialize($routeParameters))] = $sitemapEntry;
        }

        if (array_key_exists("_locale", $routeDefaults)) {
            $this->urlset[$routeName . "." . md5(serialize($routeParameters))]->addAlternate($sitemapEntry);
        }

        return $this;
    }

    public function registerUrl(string $url, ?string $lastMod = null): self
    {
        $routeParameters = $this->router->getRouteMatch($url);
        if (!$routeParameters) {
            return $this->skipped(sprintf('no route matches "%s"', $url));
        }

        return $this->register($routeParameters["_route"], $routeParameters, $lastMod);
    }

    /**
     * A page left out of the sitemap, said once per reason and per request
     * (a listener registers the same route for every record it has).
     */
    protected function skipped(string $reason): self
    {
        if (!isset($this->skipped[$reason])) {
            $this->skipped[$reason] = true;
            $this->logger?->warning('Sitemap: page left out, ' . $reason . '.');
        }

        return $this;
    }

    /** @var array<string, true> the reasons already logged */
    protected array $skipped = [];

    public function registerAttributes(): self
    {
        $this->computeFlag = false;
        foreach ($this->router->getRouteCollection() as $routeName => $route) {
            $sitemap = $this->getSitemap($route);
            if (!$sitemap) {
                continue;
            }

            $variables = array_values(array_filter(
                $route->compile()->getPathVariables(),
                fn($variable) => !str_starts_with($variable, "_")
            ));

            if (!$variables) {
                $this->register($route);

                continue;
            }

            // A parameter whose requirement spells out the values it accepts
            // ("xml|txt", "faq|parents|presse") is one this can fill in on its
            // own: each combination is a page of its own, and they were all
            // missing from the sitemap because the route takes a parameter at
            // all. A parameter open to anything - a slug, an identifier - is
            // still the application's to enumerate (SitemapEvent).
            $choices = [];
            foreach ($variables as $variable) {
                $values = self::enumerateRequirement($route->getRequirement($variable), $route->getDefault($variable));
                if (!$values) {
                    $choices = null;
                    break;
                }

                $choices[$variable] = $values;
            }

            if (!$choices) {
                continue;
            }

            foreach (self::combine($choices) as $routeParameters) {
                $this->register($route, $routeParameters);
            }
        }

        return $this;
    }

    /**
     * The values a requirement spells out ("xml|txt"), or none when it is a
     * pattern: only a plain list of literals can be turned into pages.
     *
     * @return string[]
     */
    protected static function enumerateRequirement(?string $requirement, mixed $default = null): array
    {
        if ($requirement === null || $requirement === "") {
            return $default === null ? [] : [(string) $default];
        }

        $requirement = trim($requirement, "^$");
        if (!preg_match('/^[\w\-]+(\|[\w\-]+)*$/u', $requirement)) {
            return [];
        }

        return explode("|", $requirement);
    }

    /**
     * Every combination of the values each parameter can take, up to a sane
     * number: a route with several open lists must not fill the sitemap on
     * its own.
     *
     * @return array<int, array<string, string>>
     */
    protected static function combine(array $choices, int $limit = self::MAX_ENUMERATED): array
    {
        $rows = [[]];
        foreach ($choices as $name => $values) {
            $combined = [];
            foreach ($rows as $row) {
                foreach ($values as $value) {
                    if (count($combined) >= $limit) {
                        return $combined;
                    }

                    $combined[] = $row + [$name => $value];
                }
            }

            $rows = $combined;
        }

        return $rows;
    }

    public function get(SitemapEntry|string|null $sitemapOrRouteName = null): ?SitemapEntry
    {
        $ret = null;

        $sitemapOrRouteName ??= $this->getSitemap($this->router->getRoute());
        if (!$sitemapOrRouteName) {
            return null;
        }

        array_map_recursive(function ($sitemap) use (&$ret, $sitemapOrRouteName) {
            if ($ret !== null) {
                return;
            }

            if ($sitemapOrRouteName instanceof SitemapEntry) {
                $ret = $sitemapOrRouteName === $sitemap ? $sitemap : null;
                return;
            }

            $ret = $sitemap;
        }, is_string($sitemapOrRouteName) ? $this->urlset[explode(".", $sitemapOrRouteName)[0]] ?? [] : $this->urlset);

        return $ret;
    }

    /**
     * @param SitemapEntry|string|null $sitemapOrRouteName
     * @return array|null
     */
    public function getAlternates(SitemapEntry|string|null $sitemapOrRouteName = null)
    {
        $alternates = [];

        $sitemapOrRouteName ??= $this->router->getRouteName();
        $sitemap = $this->get($sitemapOrRouteName);

        if ($sitemap === null) {
            return $sitemapOrRouteName instanceof SitemapEntry ? $sitemapOrRouteName->getAlternates() : null;
        }

        $urlset = $this->doCompute();
        foreach ($urlset as $entry) {
            if (!empty($alternates)) {
                break;
            }
            if ($entry === $sitemap) {
                $alternates = $sitemap->getAlternates();
                continue;
            }

            foreach ($entry->getAlternates() as $alternate) {
                if ($alternate->getLoc() === $entry->getLoc()) {
                    $alternates = $entry->getAlternates();
                    if (($pos = array_search($alternate, $alternates)) !== false) {
                        unset($alternates[$pos]);
                    }

                    array_unshift($alternates, $sitemap);
                }

                if (!empty($alternates)) {
                    break;
                }
            }
        }

        return array_unique($alternates);
    }

    /**
     * @return array
     */
    protected function doCompute()
    {
        if ($this->computeFlag === false) {
            return $this->urlset;
        }

        $this->urlset = [];

        $entries = array_inflate(".", $this->urlset);
        foreach ($entries as $group => $entry) {
            if ($entry instanceof SitemapEntry) {
                $this->urlset[$group] = $entry;
                continue;
            }

            array_map_recursive(function ($sitemap) use ($group) {
                if (!array_key_exists($group, $this->urlset)) {
                    $this->urlset[$group] = $sitemap;
                }

                $this->urlset[$group]->addAlternate($sitemap);
            }, $entry);
        }

        return $this->urlset;
    }

    public function serve(string $name, array $context = []): Response
    {
        $urlset = array_reverse(array_map(fn($s) => $s->toArray($this->hostname), $this->doCompute()));

        $extension = explode(".", basename($name, ".twig"));
        $extension = end($extension) ?? "txt";
        $mimeTypes = $this->mimeTypes->getMimeTypes($extension);

        $response = new Response(
            $this->twig->render(
                $name,
                array_merge($context, [
                    'urlset' => $urlset,
                    'hostname' => $this->hostname
                ])
            )
        );

        if ($mimeTypes) {
            $response->headers->set('Content-Type', first($mimeTypes));
        }

        return $response;
    }
}
