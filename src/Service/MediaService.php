<?php

namespace Base\Service;

use Base\Imagine\Filter\Basic\CropFilter;
use Base\Imagine\Filter\Basic\ThumbnailFilter;
use Base\Imagine\Filter\Format\BitmapFilterInterface;

use Base\Imagine\Filter\Format\BitmapFilter;
use Base\Imagine\Filter\Format\WebpFilter;
use Base\Imagine\Filter\Format\SvgFilter;
use Base\Imagine\Filter\FormatFilterInterface;
use Base\Routing\AdvancedRouterInterface;
use Imagine\Image\Palette\RGB;
use League\Flysystem\UnableToCreateDirectory;
use LogicException;
use Twig\Environment;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Profiler\Profiler;

use Imagine\Filter\FilterInterface;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;

use Base\Controller\UX\MediaController;

class MediaService extends FileService implements MediaServiceInterface
{
    /**
     * @var ImagineInterface
     */
    protected ImagineInterface $imagine;

    /**
     * @var Profiler|null
     */
    protected ?Profiler $profiler;

    /**
     * @var ImagineInterface
     */
    protected ImagineInterface $imagineBitmap;

    /**
     * @var ImagineInterface
     */
    protected ImagineInterface $imagineSvg;

    /**
     * @var MediaController|null
     */
    protected ?MediaController $mediaController = null;

    /** @var ?int */
    protected ?int $timeout;
    /** @var ?int */
    protected ?int $fallback;
    /** @var string */
    protected string $maxResolution;
    /** @var string */
    protected string $maxQuality;
    /** @var array */
    protected array $noImage;
    /** @var ?bool */
    protected ?bool $debug;

    /** @var ?bool */
    protected ?bool $enableWebp;

    /** @var ?LoggerInterface */
    protected ?LoggerInterface $logger;

    /** @var string[] active storage id per public mount, e.g. ["s3.uploads", "s3.wysiwyg"] */
    protected array $mountStorages = [];

    public function __construct(
        Environment             $twig,
        AdvancedRouterInterface $router,
        ObfuscatorInterface     $obfuscator,
        FlysystemInterface      $flysystem,
        ParameterBagInterface   $parameterBag,
        ImagineInterface        $imagineBitmap,
        ImagineInterface        $imagineSvg,
        ?Profiler               $profiler,
        ?LoggerInterface        $logger = null
    )
    {
        parent::__construct($twig, $router, $obfuscator, $flysystem, $parameterBag);

        $this->profiler = $profiler;
        $this->logger = $logger;

        $this->imagineBitmap = $imagineBitmap;
        $this->imagineSvg = $imagineSvg;

        $this->timeout = $parameterBag->get("base.images.timeout");
        $this->fallback = $parameterBag->get("base.images.fallback");

        // Active storages per public mount, used to resolve STORAGE-INDEPENDENT
        // canonical tokens (/uploads/..., /wysiwyg/...) at serve time — see
        // obfuscate() and getStorageForMount(). Storage ids follow the
        // "<backend>.<mount>" convention (local.uploads / s3.uploads, ...).
        $this->mountStorages = array_filter([
            $parameterBag->get("base.uploader.storage"),
            $parameterBag->get("base.twig.editor.storage"),
        ], fn($s) => is_string($s) && str_contains($s, "."));
        $this->maxResolution = $parameterBag->get("base.images.max_resolution");
        $this->maxQuality = $parameterBag->get("base.images.max_quality");
        $this->enableWebp = $parameterBag->get("base.images.enable_webp");
        $this->noImage = $parameterBag->get("base.images.no_image") ?? [];
        $this->debug = $parameterBag->get("base.images.debug");

        $this->twig = $twig;
    }

    /**
     * @param MediaController $mediaController
     * @return $this
     */
    public function setController(MediaController $mediaController)
    {
        $this->mediaController = $mediaController;
        return $this;
    }

    /**
     * @return array|bool|float|int|string|\UnitEnum|null
     */
    public function getMaximumQuality()
    {
        return $this->maxQuality;
    }

    /**
     * @return array|bool|float|int|string|\UnitEnum|null
     */
    public function isWebpEnabled()
    {
        return $this->enableWebp;
    }

    public function audio(array|string|null $path, array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        if ($path === null) {
            return null;
        }

        $output = [];

        $pathList = is_array($path) ? $path : [$path];
        foreach ($pathList as $_path) {
            $output[] = $this->generate("ux_serve", [], $_path, $config);
        }

        return is_array($path) ? $output : first($output);
    }

    public function video(array|string|null $path, array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        if ($path === null) {
            return null;
        }

        $output = [];

        $pathList = is_array($path) ? $path : [$path];
        foreach ($pathList as $_path) {
            $output[] = $this->generate("ux_serve", [], $_path, $config);
        }

        return is_array($path) ? $output : first($output);
    }

    public function soundify(null|array|string $path, array $attributes = []): ?string
    {
        if (!$path) {
            return "";
        }

        $sources = $this->audio($path);
        $sources = is_array($sources) ? $sources : [$sources];

        return $this->twig->render("@Base/media/audio.html.twig", [
            "sources" => $sources,
            "attr" => $attributes,
        ]);
    }

    public function vidify(null|array|string $path, array $attributes = []): ?string
    {
        if (!$path) {
            return "";
        }

        $sources = $this->video($path);
        $sources = is_array($sources) ? $sources : [$sources];

        return $this->twig->render("@Base/media/video.html.twig", [
            "sources" => $sources,
            "attr" => $attributes,
        ]);
    }

    public function image(array|string|null $path, array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        $supports_webp = array_pop_key("webp", $config) ?? browser_supports_webp();

        $extension   = array_pop_key("extension", $config) ?? $this->getExtension($path);
        $extension ??= $supports_webp ? WebpFilter::getStandardExtension() : BitmapFilter::getStandardExtension();
        if ($extension) {
            $supports_webp &= ($extension != "svg");
        }

        if ($path === null) {
            return null;
        }

        $output = [];

        $storage = $config["storage"] ?? null;
        $pathList = is_array($path) ? $path : [$path];
        foreach ($pathList as $p) {

            $output[] = $supports_webp ?
                    $this->generate("ux_imageWebp", [], $p, array_merge($config, ["filters" => $filters])) :
                    $this->generate("ux_imageExtension", [], $p, array_merge($config, ["filters" => $filters, "extension" => $extension]));
        }

        return is_array($path) ? $output : first($output);
    }

    public function imageBase64(null|array|string $path): null|array|string
    {
        if (!$path) {
            return null;
        }

        if (is_array($path)) {
            return array_map(fn($s) => $this->imageBase64($s), $path);
        }

        return base64_image($path, $this->getExtension($path));
    }

    public function imageSet(null|array|string $path, ...$srcset): null|array|string
    {
        if (!$path) {
            return null;
        }

        if (is_array($path)) {
            return array_map(fn($s) => $this->imageSet($s), $path);
        }

        $srcset = array_map(fn($src) => array_pad(is_array($src) ? $src : [$src, $src], 2, null), $srcset);
        $srcset = implode(", ", array_map(fn($src) => $this->thumbnail($path, $src[0], $src[1]) . " " . $src[0] . "w " . $src[1] . "h", $srcset));
        return str_strip(($attributes["srcset"] ?? $attributes["data-srcset"] ?? "") . "," . $srcset, ",");
    }

    public function imagify(null|array|string $path, array $attributes = [], ...$srcset): null|array|string
    {
        if (!$path) {
            return $path;
        }
        if (is_array($path)) {
            return array_map(fn($s) => $this->imagify($s, $attributes), $path);
        }

        $lazyload = array_pop_key("lazy", $attributes);
        $lazybox = array_pop_key("lazy-box", $attributes);

        $srcset = array_map(fn($src) => array_pad(is_array($src) ? $src : [$src, $src], 2, null), $srcset);
        $srcset = implode(", ", array_map(fn($src) => $this->thumbnail($path, $src[0], $src[1]) . " " . $src[0] . "w " . $src[1] . "h", $srcset));
        $attributes[$lazyload ? "data-srcset" : "srcset"] = str_strip(($attributes["srcset"] ?? $attributes["data-srcset"] ?? "") . "," . $srcset, ",");

        return $this->twig->render("@Base/media/image.html.twig", [
            "path" => is_url($path) ? $path : $this->image($path),
            "attr" => $attributes,
            "lazyload" => $lazyload,
            "lazybox" => $lazybox
        ]);
    }

    public function crop(array|string|null $path, int $x = 0, int $y = 0, ?int $width = null, ?int $height = null, string $position = "leftop", array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        $filters[] = new CropFilter(
            $x,
            $y,
            $width,
            $height,
            $position
        );

        $config = array_key_removes($config, "width", "height", "x", "y", "position");
        return $this->image($path, $config, $filters);
    }

    public function thumbnailInset(array|string|null $path, ?int $width = null, ?int $height = null, array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        return $this->thumbnail($path, $width, $height, array_merge($config, ["mode" => ImageInterface::THUMBNAIL_INSET]), $filters);
    }

    public function thumbnailOutbound(array|string|null $path, ?int $width = null, ?int $height = null, array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        return $this->thumbnail($path, $width, $height, array_merge($config, ["mode" => ImageInterface::THUMBNAIL_OUTBOUND]), $filters);
    }

    public function thumbnailNoclone(array|string|null $path, ?int $width = null, ?int $height = null, array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        return $this->thumbnail($path, $width, $height, array_merge($config, ["mode" => ImageInterface::THUMBNAIL_FLAG_NOCLONE]), $filters);
    }

    public function thumbnailUpscale(array|string|null $path, ?int $width = null, ?int $height = null, array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        return $this->thumbnail($path, $width, $height, array_merge($config, ["mode" => ImageInterface::THUMBNAIL_FLAG_UPSCALE]), $filters);
    }

    public function thumbnail(array|string|null $path, ?int $width = null, ?int $height = null, array $config = [], FilterInterface|array $filters = []): array|string|null
    {
        $filters[] = new ThumbnailFilter(
            $width,
            $height ?? null,
            $config["mode"] ?? ImageInterface::THUMBNAIL_INSET,
            $config["resampling"] ?? ImageInterface::FILTER_UNDEFINED
        );

        $config = array_key_removes($config, "width", "height", "mode", "resampling");
        return $this->image($path, $config, $filters);
    }

    public function obfuscate(string|null $path, array $config = [], FilterInterface|array $filters = []): ?string
    {
        if ($path === null) {
            return null;
        }

        // EARLY CHECK: If path matches a media route, extract the subdivided data
        $routeMatch = $this->router->getRouteMatch($path);
        if ($routeMatch && isset($routeMatch["_route"]) && str_starts_with($routeMatch["_route"], "ux_image")) {
            // Already an obfuscated URL - extract the data parameter
            $data = $routeMatch["data"] ?? null;
            if ($data) {
                $extraFilters = array_merge($config["filters"] ?? [], is_array($filters) ? $filters : [$filters]);
                if (empty($extraFilters)) {
                    // Bare re-obfuscation of an existing identifier: stable, return as-is.
                    return $data;
                }

                // The caller is DERIVING from an existing identifier (e.g.
                // thumbnail() applied to an /images URL, as templates do with
                // entity media on remote storages). Returning the token as-is
                // would silently DROP the new filters — a full-size image
                // served where a thumbnail was requested. Decode the source
                // config, merge, and fall through to the canonical re-encode
                // below (which also migrates legacy absolute-path tokens to
                // the canonical storage-independent form).
                $decoded = $this->resolve($data);
                if (!$decoded || !array_key_exists("path", $decoded)) {
                    return $data;
                }

                $path = $decoded["path"];
                $config["path"] = $path;
                $config["filters"] = array_merge($decoded["filters"] ?? [], $config["filters"] ?? []);
                $config["options"] = array_merge($decoded["options"] ?? [], $config["options"] ?? []);
                if (!array_key_exists("storage", $config) && array_key_exists("storage", $decoded)) {
                    $config["storage"] = $decoded["storage"];
                }
            }
        }

        $path = "/" . str_strip($path, $this->router->getAssetUrl(""));

        $config["path"] = $path;
        $config["options"] = array_merge(["quality" => $this->getMaximumQuality()], $config["options"] ?? []);
        $config["local_cache"] = $config["local_cache"] ?? null;
        if (!empty($filters)) {
            $config["filters"] = $filters;
        }

        while (($pathConfig = $this->obfuscator->decode(basename($path), MediaService::USE_SHORT))) {
            $path = $pathConfig["path"] ?? $path;
            $config["path"] = $path;
            $config["filters"] = array_merge_recursive($pathConfig["filters"] ?? [], $config["filters"] ?? []);
            $config["options"] = array_merge_recursive($pathConfig["options"] ?? [], $config["options"] ?? []);
            $config["local_cache"] = $pathConfig["local_cache"] ?? $config["local_cache"];
        }

        //
        // Canonicalize the source so the identifier is STORAGE-INDEPENDENT:
        // the same origin yields the same /images hash whether the media
        // lives on the local filesystem (public/ symlink -> absolute path) or
        // on a remote storage (S3/MinIO, storage-relative path + explicit
        // "storage" in config). The canonical form is the public-relative
        // path ("/wysiwyg/<uuid>.jpg", "/uploads/_/<entity>/..."), and the
        // "storage" key is dropped from the hashed payload — the ACTIVE
        // storage for the mount is resolved at serve time instead (see
        // filter()/getStorageForMount()), so already-issued URLs keep working
        // across local<->S3 switches. Paths that fit neither shape (arbitrary
        // files, exotic storages) keep their config untouched (legacy tokens
        // remain fully decodable either way).
        $storage = $config["storage"] ?? null;
        $mount = is_string($storage) && str_contains($storage, ".") ? explode(".", $storage, 2)[1] : null;
        $publicDir = $this->flysystem->getPublicDir();

        if (str_starts_with($config["path"], $publicDir . "/")) {
            $config["path"] = substr($config["path"], strlen($publicDir));
            unset($config["storage"]);
        } elseif ($mount !== null) {
            if (!str_starts_with($config["path"], "/" . $mount . "/")) {
                $config["path"] = "/" . $mount . "/" . ltrim($config["path"], "/");
            }
            unset($config["storage"]);
        }

        $data = $this->obfuscator->encode($config, MediaService::USE_SHORT);
        $data = MediaService::USE_SHORT ? path_subdivide(str_replace("-", "", $data), 5, 2) : path_subdivide($data, self::CACHE_SUBDIVISION, self::CACHE_SUBDIVISION_LENGTH);

        return $data;
    }

    public function lightbox(
        null|array|string $path,
        array             $attributes = [],
        null|array|string      $lightboxId = null,
        null|array|string      $lightboxTitle = null,
        array             $lightboxAttributes = [],
                          ...$srcset
    ): null|array|string
    {
        $lightboxPathType = gettype($path);
        $lightboxIdType = gettype($lightboxId);
        $lightboxTitleType = gettype($lightboxTitle);

        if ($path != null && $lightboxPathType !== $lightboxIdType && $lightboxIdType !== gettype(null)) {
            throw new Exception("Unexpected `lightboxId` type parameter received: " . $lightboxIdType . " (expected:" . $lightboxPathType . ")");
        }
        if ($path != null && $lightboxPathType !== $lightboxTitleType && $lightboxTitleType !== gettype(null)) {
            throw new Exception("Unexpected `lightboxTitle` type parameter received: " . $lightboxIdType . " (expected:" . $lightboxPathType . ")");
        }

        if (!$path) {
            return $path;
        }
        if (is_array($path)) {
            return array_map(fn($k) => $this->lightbox($path[$k], $attributes, $lightboxId[$k], $lightboxTitle[$k], $lightboxAttributes), array_keys($path));
        }

        $routeName = $this->router->getRouteName($path);
        if ($routeName && !str_starts_with($routeName, "ux_")) {
            $path = $this->image($path);
        }

        $lightboxAttributes["loading"] ??= "lazy";
        $lightboxAttributes["class"] = trim(($lightboxAttributes["class"] ?? "") . " lightbox-wrapper");
        $lightboxAttributes["data-lightbox"] = $lightboxId ?? "lightbox";
        if ($lightboxTitle !== null) {
            $lightboxAttributes["data-title"] = $lightboxTitle;
        }

        $lazyload = array_pop_key("lazy", $attributes);
        $lazybox = array_pop_key("lazy-box", $attributes);

        $srcset = array_map(fn($src) => array_pad(is_array($src) ? $src : [$src, $src], 2, null), $srcset);
        $srcset = implode(", ", array_map(fn($src) => $this->thumbnail($path, $src[0], $src[1]) . " " . $src[0] . "w " . $src[1] . "h", $srcset));
        $attributes[$lazyload ? "data-srcset" : "srcset"] = str_strip(($attributes["srcset"] ?? $attributes["data-srcset"] ?? "") . "," . $srcset, ",");

        return $this->twig->render("@Base/media/image-lightbox.html.twig", [
            "path" => is_url($path) ? $path : $this->image($path),
            "attr" => $attributes,
            "attr_lightbox" => $lightboxAttributes,
            "lazyload" => $lazyload,
            "lazybox" => $lazybox
        ]);
    }

    public function generate(string $proxyRoute, array $proxyRouteParameters = [], ?string $path = null, array $config = []): ?string
    {
        if (!$path) {
            return null;
        }

        $warmup = array_pop_key("warmup", $config);

        $config["filters"] ??= [];
        $routeUrl = parent::generate($proxyRoute, $proxyRouteParameters, $path, $config);

        // Call controller to warmup image
        if ($warmup && $this->mediaController !== null) {

            $routeMatch = $this->router->getRouteMatch($routeUrl);

            list($className, $controllerName) = array_pad(explode("::", $routeMatch["_controller"] ?? ""), 2, null);
            if (is_instanceof($className, get_class($this->mediaController)) && $controllerName) {
            
                $routeParameters = array_key_removes($routeMatch, "_route", "_controller");
                $this->mediaController->{$controllerName}(...$routeParameters);
            }
        }

        return $routeUrl;
    }

    public function resolve(string $data, FilterInterface|array $filters = []): array
    {
        $config = parent::resolve($data) ?? [];
        $config["filters"] = array_merge($config["filters"] ?? [], $filters);
        return $config;
    }

    public function serve(?string $file, int $status = 200, array $headers = []): ?Response
    {
        if (!file_exists($file ?? "")) {

            if (!$this->fallback) {
                throw is_length_safe($file) ?
                    new NotFoundHttpException($file ? "Image \"" . $file . "\" not found." : "Empty path provided.") :
                    new LogicException("Image \"" . str_shorten($file, 50, SHORTEN_MIDDLE) . "\" overflowed the PHP_MAXPATHLEN (= " . constant("PHP_MAXPATHLEN") . ") limit. Maybe use a compress option (\"gzcompress\",\"gzdeflate\",\"gzencode\") ?");
            }

            $this->logger?->warning("MediaService: serving no-image placeholder, could not resolve \"{path}\".", [
                "path" => $_SERVER["REQUEST_URI"] ?? "(unknown)",
            ]);

            $file = $this->getNoImage($this->getExtension($file) ?? BitmapFilter::getStandardExtension());

            // The requested identifier failed to resolve (e.g. an orphaned obfuscator
            // hash) — this fallback response must never be cached under the requested
            // URL, since a future successful resolve (e.g. after content is fixed)
            // must not be masked by a stale cached placeholder at the same URL. Callers
            // can also detect this via the X-Media-Fallback header (status stays 200:
            // browsers only render an <img> body from a 2xx response, so a 404 here
            // would just replace our placeholder with the browser's own broken-image icon).
            array_pop_key("http_cache", $headers);
            $headers["Cache-Control"] = "no-store, no-cache, must-revalidate";
            $headers["X-Media-Fallback"] = "true";
        }

        $useProfiler = $headers["profiler"] ?? true;
        if ($this->profiler !== null && !$useProfiler) {
            $this->profiler->disable();
        }

        return parent::serve($file, $status, $headers);
    }

    public function isCached(?string $path, array $config = [], FilterInterface|array $filters = []): bool
    {
        if (!is_array($filters)) {
            $filters = [$filters];
        }

        //
        // Resolve nested paths
        $options = $this->resolve($path, $filters);
        $path = $config["path"] ?? $options["path"] ?? $path; // Cache directory location
        $filters = $config["filters"] ?? $options["filters"] ?? [];
        $storage = $config["storage"] ?? $options["storage"] ?? null;
        $output = $config["output"] ?? $options["output"] ?? realpath($path);

        //
        // Apply image resolution limitation
        if (!is_instanceof($this->maxResolution, ThumbnailFilter::class)) {
            throw new NotFoundHttpException("Resolution filter \"" . $this->maxResolution . "\" must inherit from " . ThumbnailFilter::class);
        }

        //
        // Extract last filter
        $filters = array_filter($filters, fn($f) => class_implements_interface($f, FilterInterface::class));
        $formatter = end($filters);
        if ($formatter === null) {
            throw new NotFoundHttpException("Last filter is missing.");
        }

        //
        // Apply size limitation to bitmap only
        if (class_implements_interface($formatter, BitmapFilterInterface::class)) {
            $definitionFilters = array_filter($formatter->getFilters(), fn($f) => $f instanceof ThumbnailFilter);
            if (empty($definitionFilters)) {
                $formatter->addFilter(new $this->maxResolution());
            }

            if (!class_implements_interface($formatter, FormatFilterInterface::class)) {
                throw new NotFoundHttpException("Last filter \"" . (get_class($formatter)) . "\" must implement \"" . FormatFilterInterface::class . "\"");
            }
        }

        $filtersButLast = array_slice($filters, 0, count($filters) - 1);
        foreach ($filtersButLast as $filter) {
            if (class_implements_interface($filter, FormatFilterInterface::class)) {
                throw new NotFoundHttpException("Only last filter must implement \"" . FormatFilterInterface::class . "\"");
            }
        }

        $pathRelative = $this->flysystem->stripPrefix($output, $storage);
        $pathCache = $pathRelative;

        // NB: Encode path using hash only: make sure the path is matching route generator
        // ... Otherwise, the controller will take over
        $pathExtras   = array_map(fn ($f) => is_stringeable($f) ? strval($f) : null, $filters);
        $pathCache    = path_suffix($pathRelative, $pathExtras  );

        //
        // Compute a response.. (if cache not found)
        if ($config["local_cache"] ?? true) {
            $localCache = array_pop_key("local_cache", $config);
            if (!is_string($localCache)) {
                $localCache = $this->localCache;
            }
            if (!$this->flysystem->hasStorage($this->localCache)) {
                throw new InvalidArgumentException("\"" . $this->localCache . "\" storage not found in your Flysystem configuration.");
            }

            return $this->flysystem->fileExists($pathCache, $localCache);
        }

        return false;
    }

    /**
     * Stream a source file from a remote flysystem storage (S3/MinIO) into a temp
     * file so imagine can open it — imagine only reads local paths/URLs, never a
     * remote storage. The token may carry the source in several path forms
     * (storage-relative like "_/entity/field/uuid", a public URL with a leading
     * mount segment like "/wysiwyg/uuid", etc.), so we try the plausible
     * storage-relative candidates and use the first that exists. Returns the temp
     * file path (caller must unlink) or null if the source can't be found.
     */
    /**
     * Resolve the ACTIVE storage for a canonical public-relative source path
     * ("/uploads/...", "/wysiwyg/...") by matching the leading mount segment
     * against the configured storage ids ("<backend>.<mount>" convention:
     * local.uploads / s3.uploads share the "uploads" mount). This is what
     * makes storage-independent tokens serveable: the token carries no
     * storage, the mount decides at request time.
     */
    private function getStorageForMount(string $path): ?string
    {
        $mount = explode("/", ltrim($path, "/"), 2)[0] ?? null;
        if (!$mount) {
            return null;
        }

        foreach ($this->mountStorages as $storage) {
            if (explode(".", $storage, 2)[1] === $mount) {
                return $storage;
            }
        }

        return null;
    }

    private function fetchRemoteSource(string $path, string $storage): ?string
    {
        $candidates = [];
        $stripped = $this->flysystem->stripPrefix($path, $storage);
        $candidates[] = ltrim((string) $stripped, "/");
        $candidates[] = ltrim($path, "/");
        // Drop a single leading mount segment (e.g. "wysiwyg/", "uploads/").
        if (preg_match('#^/?[^/]+/(.+)$#', $path, $m)) {
            $candidates[] = $m[1];
        }

        foreach (array_unique(array_filter($candidates)) as $rel) {
            try {
                if (!$this->flysystem->fileExists($rel, $storage)) {
                    continue;
                }
                $contents = $this->flysystem->read($rel, $storage);
            } catch (\Throwable $e) {
                continue;
            }
            if ($contents === null) {
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), "media_");
            if ($tmp === false) {
                return null;
            }
            // Preserve the extension so imagine can detect the format (webp/svg/…).
            $ext = pathinfo($rel, PATHINFO_EXTENSION);
            if ($ext !== "") {
                $tmpExt = $tmp . "." . $ext;
                @rename($tmp, $tmpExt);
                $tmp = $tmpExt;
            }
            file_put_contents($tmp, $contents);

            return $tmp;
        }

        return null;
    }

    public function filter(?string $path, array $config = [], FilterInterface|array $filters = []): ?string
    {
        if (!is_array($filters)) {
            $filters = [$filters];
        }

        //
        // Resolve nested paths
        $options = $this->resolve($path, $filters);

        $path = $config["path"] ?? $options["path"] ?? $path; // Cache directory location
        $filters = $config["filters"] ?? $options["filters"] ?? [];
        $storage = $config["storage"] ?? $options["storage"] ?? null;
        $output = $config["output"] ?? $options["output"] ?? realpath($path);

        // Apply image resolution limitation
        if (!is_instanceof($this->maxResolution, ThumbnailFilter::class)) {
            throw new NotFoundHttpException("Resolution filter \"" . $this->maxResolution . "\" must inherit from " . ThumbnailFilter::class);
        }

        //
        // Extract last filter
        $filters = array_filter($filters, fn($f) => class_implements_interface($f, FilterInterface::class));
        $formatter = end($filters);

        if ($formatter === false) {
            throw new NotFoundHttpException("No filter provided at least one must be provided (and must implement \"" . FormatFilterInterface::class . "\").");
        }
        if (!$formatter instanceof FormatFilterInterface) {
            throw new NotFoundHttpException("The last filter must implement \"" . FormatFilterInterface::class . "\"). A \"" . get_class($formatter) . "\" received.");
        }

        if ($this->debug) {
            return $this->getNoImage($this->getExtension($path) ?? $formatter->getStandardExtension());
        }
        
        //
        // Apply size limitation to bitmap only
        if (class_implements_interface($formatter, BitmapFilterInterface::class)) {
            $definitionFilters = array_filter($formatter->getFilters(), fn($f) => $f instanceof ThumbnailFilter);
            if (empty($definitionFilters)) {
                $formatter->addFilter(new $this->maxResolution());
            }

            if (!class_implements_interface($formatter, FormatFilterInterface::class)) {
                throw new NotFoundHttpException("Last filter \"" . (get_class($formatter)) . "\" must implement \"" . FormatFilterInterface::class . "\"");
            }
        }

        $filtersButLast = array_slice($filters, 0, count($filters) - 1);
        foreach ($filtersButLast as $filter) {
            if (class_implements_interface($filter, FormatFilterInterface::class)) {
                throw new NotFoundHttpException("Only last filter must implement \"" . FormatFilterInterface::class . "\"");
            }
        }

        
        // Encode path using hashid only: make sure the path is matching route generator
        // ... Otherwise, the controller will take over. Lines below make sure suffix is applied including filter operations
        $pathRelative = $this->flysystem->stripPrefix($output, $storage);
        $pathExtras   = array_map(fn ($f) => is_stringeable($f) ? strval($f) : null, $filters);
        $pathCache    = path_suffix($pathRelative, $pathExtras  );

        if (!$pathRelative) {

            if (!$this->fallback) {
                throw new NotFoundHttpException($path ? "Image not found behind system path \"$path\"." : "Empty path provided.");
            }

            return $this->getNoImage($this->getExtension($path) ?? $formatter->getStandardExtension());
        }

        //
        // Compute a response.. (if cache not found)
        if ($config["local_cache"] ?? false) {

            $localCache = array_pop_key("local_cache", $config);
            if (!is_string($localCache)) {
                $localCache = $this->localCache;
            }
            if (!$this->flysystem->hasStorage($this->localCache)) {
                throw new InvalidArgumentException("\"" . $this->localCache . "\" storage not found in your Flysystem configuration.");
            }

            if (!$this->flysystem->fileExists($pathCache, $localCache)) {
                
                $maxExecutionTime = ini_get('max_execution_time');
                set_time_limit($this->timeout);

                $filteredPath = $this->filter($path, array_merge($config, ["local_cache" => false]), $filters) ?? $path;
                if (!file_exists($filteredPath) || in_array($filteredPath, array_column($this->noImage, "path"), true)) {

                    set_time_limit($maxExecutionTime);

                    if (!$this->fallback) {
                        throw new NotFoundHttpException($pathCache ? "Image \"$pathCache\" not found." : "Empty path provide in ".$storage.".");
                    }

                    // The source failed to resolve or filter: NEVER write the
                    // no-image placeholder into the derivative cache — the miss
                    // may be transient (source temporarily unreachable), and a
                    // poisoned cache entry would keep serving the placeholder
                    // (with public HTTP caching, since the cached file exists)
                    // even after the source recovers. Returning null lets
                    // serve(null) emit the placeholder with explicit no-store
                    // semantics instead.
                    return null;
                }

                try {

                    $this->flysystem->mkdir(dirname($pathCache), $localCache);
                    $this->flysystem->write($pathCache, file_get_contents($filteredPath), $localCache);

                    // if on disk
                    $prefixedRelativePath = $this->flysystem->prefixPath($pathRelative, $localCache);
                    $prefixDir = dirname($prefixedRelativePath);
                    $prefixedCache = $this->flysystem->prefixPath($pathCache, $localCache);
                    $prefixedRelativeCache = relative_path($prefixedCache, $prefixDir);

                    if(file_exists($prefixedCache) && !file_exists($prefixedRelativePath)) {
                        // Two requests for the same picture: the second link is not an error
                        symlink_atomic($prefixedRelativeCache, $prefixedRelativePath);
                    }

                } catch (UnableToCreateDirectory $e) {
                    $localDir = $this->flysystem->prefixPath("", $localCache);
                    mkdir_length_safe($localDir . "/" . dirname($pathCache));
                }

                set_time_limit($maxExecutionTime);

                if ($formatter->getPath() === null) {
                    unlink_tmpfile($filteredPath);
                }
            }

            return $this->flysystem->prefixPath($pathCache, $localCache);
        }

        //
        // Use proper imagine service depending on the format
        $imagine = $formatter instanceof SvgFilter ? $this->imagineSvg : $this->imagineBitmap;

        //
        // Resolve the source to a locally-openable path. Local sources (absolute
        // paths behind public/ symlinks, or URLs) open directly — this is a no-op
        // fast path. Canonical storage-independent tokens (public-relative
        // "/mount/rest", no storage in config) first try the local public mount
        // (symlinked local storages), then fall back to the ACTIVE storage for
        // that mount. Remote sources (S3/MinIO) have no local file, so stream
        // the source from the flysystem storage into a temp file. The derivative
        // is still cached locally afterwards (see the local_cache branch above),
        // so a remote source is fetched at most once per (source, filter)
        // combination and every later hit serves statically.
        $openPath = $path;
        $tmpSource = null;
        if ($path !== null && !is_file($path) && !is_url($path)) {
            $publicPath = str_starts_with($path, "/") ? $this->flysystem->getPublicDir() . $path : null;
            if ($publicPath !== null && is_file($publicPath)) {
                $openPath = $publicPath;
            } else {
                $storage ??= $this->getStorageForMount($path);
                if ($storage !== null && $this->flysystem->isRemote($storage)) {
                    $tmpSource = $this->fetchRemoteSource($path, $storage);
                    if ($tmpSource !== null) {
                        $openPath = $tmpSource;
                    }
                }
            }
        }

        //
        // GD does not support other palette than RGB..
        // if($this->imagine instanceof \Imagine\Gd\Imagine && is_cmyk($pathPublic))
        //   cmyk2rgb($pathPublic); // @TODO: Not working yet.. to be investivated
        try {
            $image = $imagine->open($openPath);
        } catch (Exception $e) {
            if ($tmpSource !== null) {
                @unlink($tmpSource);
            }
            return $this->fallback ? $this->getNoImage($this->getExtension($path) ?? $formatter->getStandardExtension()) : null;
        }

        // Source fully loaded into memory — the temp copy is no longer needed.
        if ($tmpSource !== null) {
            @unlink($tmpSource);
        }

        try {
            if ($formatter instanceof BitmapFilterInterface) {
                $image->usePalette(new RGB());
                $image->strip();
            }
        } catch (Exception $e) {
            if (!$this->fallback) {
                throw $e;
            }
        }

        // Apply filters
        foreach ($filters as $filter) {
            $oldImage = $image;
            try {
                $image = $filter->apply($oldImage);
            } catch (Exception $e) {
                if (!$this->fallback) {
                    throw $e;
                }
                $image = $oldImage;
            }

            if (spl_object_id($image) != spl_object_id($oldImage)) {
                $oldImage->__destruct();
            }
        }

        // Last filter is in charge of saving the final image
        // So we can safely destroy it
        $image->__destruct();

        // Fallback
        if (!file_exists($formatter->getPath()) && $this->fallback) {
            
            return $this->getNoImage($this->getExtension($path) ?? $formatter->getStandardExtension());
        }

        return $formatter->getPath();
    }

    /**
     * @param string|FormatFilterInterface|null $extensionOrFormatter
     * @return mixed
     * @throws Exception
     */
    public function getNoImage(null|string|FormatFilterInterface $extensionOrFormatter = null)
    {
        if (is_string($extensionOrFormatter)) {
            $extension = $extensionOrFormatter;
        }

        $extension ??= BitmapFilter::getStandardExtension();
        if ($extensionOrFormatter instanceof FormatFilterInterface) {
            $extension = $extensionOrFormatter::getStandardExtension();
        }

        $noImage = first(array_search_by($this->noImage, "extension", $extension));
        if (!$noImage) {
            throw new Exception(
                "Replacement image not defined for \"" . strtoupper($extension) . "\"." . PHP_EOL .
                "Please define `base.images.no_image." . $extension . "` or disable `base.images.fallback`"
            );
        }

        return $noImage["path"];
    }
}
