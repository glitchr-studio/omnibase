<?php

namespace Base\Bundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use ReflectionClass;
use ErrorException;

use Symfony\Component\Finder\Finder;

use Base\Traits\SingletonTrait;

abstract class AbstractBaseBundle extends Bundle
{
    use SingletonTrait;

    public function __construct()
    {
        // static:: (not self::) matters here: this constructor is defined
        // ONCE on the abstract class, but each concrete bundle subclass
        // (BaseBundle, Base\Admin\AdminBundle, Base\Wikidoc\WikidocBundle...)
        // re-declares `use SingletonTrait;` itself so it gets its OWN
        // $_instance storage slot (traits give each USING class independent
        // static properties - see SingletonTraitTest - but subclasses that
        // only inherit the trait via this abstract parent, without
        // re-declaring it, would all share ONE slot instead). self:: would
        // always target THIS class's own copy regardless of which concrete
        // bundle was actually instantiated, silently overwriting one
        // bundle's singleton with another's - exactly what happened once a
        // second bundle (AdminBundle) started extending this class.
        if (!$this->hasInstance()) {
            static::$_instance = $this;
        }

        // Here, not in warmUp(): the kernel instantiates the bundles first, and
        // a container that is loaded from cache but not yet warmed runs its
        // cache warmers before any bundle is built or booted - with no alias
        // declared at all (IconCacheWarmer -> MentionEnhancer ->
        // App\Repository\Thread\MentionRepository, on every cache:clear).
        self::registerAliasAutoloader();
    }

    /**
     * @return string
     */
    public static function getBundleDir()
    {
        return dirname((new ReflectionClass(static::class))->getFileName(), 2);
    }

    public static function getProjectDir(): string
    {
        return dirname(self::getBundleDir(), 3);
    }

    protected static ?array $bundles = null;
    public function getBundles()
    {
        if(self::$bundles === null) {

            self::$bundles = array_filter(
                $this->getDeclaredClasses("Base", 2), 
                fn($v) => str_ends_with($v, "Bundle") && $v != self::class
            );
        }

        return self::$bundles;
    }

    public function hasBundle(string $bundleName)
    {
        $bundles = $this->getBundles();
        $bundleNames = array_map(fn($c) => camel2snake(str_rstrip(basename_namespace($c), "Bundle")), $bundles);

        return in_array($bundleName, $bundles) || in_array($bundleName, $bundleNames);
    }

    public function getDeclaredClasses(string $namespace = "", int $level = -1)
    {
        $namespace .= '\\';
        return array_values(array_unique(array_filter(get_declared_classes(), function($item) use ($namespace, $level) 
        { 
            if(substr($item, 0, strlen($namespace)) !== $namespace) return false;
            if($level < 0) return true;

            return $level >= substr_count(substr($item, strlen($namespace)), "\\");
        })));
    }

    public function getDeclaredNamespaces(string $namespace = "", int $level = 1)
    {
        $namespace .= '\\';
        return array_values(array_unique(array_transforms(function($k, $v) use ($namespace,$level) : ?array{

            if(!str_starts_with($v, $namespace)) return null;
            return [$k, dirname_namespace($v, $level)];

        }, get_declared_classes())));
    }

    public function setMapping(string $path, string $inputNamespace = "", string $outputNamespace = "")
    {
        $classList = $this->getAllClasses($path, $inputNamespace);
    
        $aliasList = [];
        foreach ($classList as $class) {
            $aliasList[$inputNamespace . "\\" . $class] = str_rstrip($outputNamespace, "\\"). "\\" . $class;
        }

        $this->setAlias($aliasList);
    }

    public function generateStub(string $path, string $inputNamespace = "", string $outputNamespace = ""): void
    {
        $output = $this->getProjectDir()."/var/stubs";
        if (!is_dir($output)) {
            mkdir($output, 0777, true);
        }

        foreach ($this->getAllClasses($path, $inputNamespace) as $rootClass) {

            $outputClass = $outputNamespace . "\\" . $rootClass;
            $inputClass  = $inputNamespace  . "\\" . $rootClass;
            if (class_exists($outputClass, false)) {
                if (!is_subclass_of($outputClass, $inputClass)) {
                    throw new \LogicException("According to the base convention, $outputClass must extend $inputClass.");
                }
                continue;
            }

            $outputClassPath = str_replace('\\', '/', $outputClass);
            $stubPath = $output . '/' . $outputClassPath . '.php';
            
            $namespace = str_replace('/', '\\', dirname($outputClassPath));
            
            $stubCode = "<?php\n\nnamespace " . $namespace . ";\n\n";
            $stubCode .= "if (!class_exists('$outputClass')) {\n";
            $stubCode .= "    class " . basename($outputClassPath) . " extends \\" . $inputClass . " {}\n";
            $stubCode .= "}\n";

            @mkdir(dirname($stubPath), 0777, true);
            file_put_contents($stubPath, $stubCode);
        }
    }

    protected static ?array $aliasList = null;
    protected static ?array $aliasRepositoryList = null;

    /** The namespaces BaseBundle::warmUp() maps Base\X => App\X, for the fallback below. */
    protected const ALIASED_NAMESPACES = ["Entity", "Repository", "Enum", "Tests", "Notifier", "Form"];

    protected static bool $aliasAutoloader = false;

    /**
     * A last-resort autoloader for the App\* aliases.
     *
     * The aliases are declared once per process, from the pool that
     * BaseBundle::warmUp() reads (or the scan that rebuilds it). A pool that is
     * stale or incomplete - a concurrent rebuild, cache:clear moving the cache
     * dir under a process - used to leave one alias out, and whatever first
     * needed it died: `Class "App\Repository\Thread\MentionRepository" not
     * found` from the container, which instantiates the service by that name
     * (config/services/media.php).
     *
     * It also covers the window before warmUp() has run at all: see the
     * constructor. Composer is asked first; this only runs for a class nobody
     * else could load. It answers from the known alias lists, then from the convention
     * itself (App\Entity\X is Base\Entity\X, ...), and declares the alias.
     * An alias the lists declare whose Base class is missing is a LogicException.
     */
    public static function registerAliasAutoloader(): void
    {
        if (self::$aliasAutoloader) {
            return;
        }
        self::$aliasAutoloader = true;

        spl_autoload_register(static function (string $class): void {
            $input = array_search($class, self::$aliasList ?? [], true)
                ?: array_search($class, self::$aliasRepositoryList ?? [], true);
            $declared = (bool) $input;

            if (!$input && str_starts_with($class, "App\\")) {
                $namespace = explode("\\", $class)[1] ?? "";
                if (in_array($namespace, self::ALIASED_NAMESPACES, true)) {
                    $input = "Base\\" . substr($class, 4);
                }
            }

            if (!$input) {
                return;
            }

            $exists = class_exists($input) || interface_exists($input) || trait_exists($input);

            // A declared alias whose Base class is gone is a broken bundle, not
            // a class that does not exist: say which, rather than a bare "not
            // found" on the App name. By convention alone, a missing Base class
            // only means there is no such class (TranslatableTrait probes
            // App\...\XIntl names that legitimately do not exist).
            if (!$exists && $declared) {
                throw new \LogicException(sprintf('"%s" is declared as an alias of "%s", which does not exist.', $class, $input));
            }

            if ($exists && !class_exists($class, false) && !interface_exists($class, false) && !trait_exists($class, false)) {
                class_alias($input, $class);
            }
        });
    }

    /**
     * @param $arrayOrObjectOrClass
     * @return array|array[]|false|false[]|mixed|string|string[]
     */
    public function getAlias($arrayOrObjectOrClass)
    {
        if (!$arrayOrObjectOrClass) {
            return $arrayOrObjectOrClass;
        }
        if (is_array($arrayOrObjectOrClass)) {
            return array_map(fn($a) => $this->getAlias($a), $arrayOrObjectOrClass);
        }

        $arrayOrObjectOrClass = is_object($arrayOrObjectOrClass) ? get_class($arrayOrObjectOrClass) : $arrayOrObjectOrClass;
        if (!class_exists($arrayOrObjectOrClass)) {
            return false;
        }

        return self::$aliasList[$arrayOrObjectOrClass] ?? $arrayOrObjectOrClass;
    }

    public function hasAlias(mixed $objectOrClass): bool
    {
        if (!is_object($objectOrClass) && !is_string($objectOrClass)) {
            return false;
        }

        $class = is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass;
        if (!class_exists($class)) {
            return false;
        }

        return $this->getAlias($class) != $class;
    }

    /**
     * @param $aliasRepository
     * @return mixed
     */
    public function getAliasRepository($aliasRepository)
    {
        return self::$aliasRepositoryList[$aliasRepository] ?? $aliasRepository;
    }

    /**
     * The classes the warm-up met but could not load, keyed by class: the
     * error that stopped them (see classLoads()).
     */
    protected static array $unloadable = [];

    public static function getUnloadableClasses(): array
    {
        return self::$unloadable;
    }

    /**
     * Whether $class can be loaded - false, not fatal, when it cannot.
     *
     * The warm-up walks every class of every directory of a bundle, and a
     * bundle may hold classes for a package it only suggests: a digest source
     * implementing omnibase/newsletter's interface, an exporter extending a
     * provider's class. Declaring such a class without that package throws
     * `Interface "..." not found` (or Class, Trait, Enum), which used to take
     * the whole kernel down at boot. That class is simply not aliased; nothing
     * that is registered uses it without its package. Any other error (a parse
     * error, a broken bundle) still surfaces.
     */
    public static function classLoads(string $class): bool
    {
        try {
            return class_exists($class);
        } catch (ErrorException $e) {
            return false;
        } catch (\Error $e) {
            if (!preg_match('/^(Class|Interface|Trait|Enum) "[^"]+" not found/', $e->getMessage())) {
                throw $e;
            }

            self::$unloadable[ltrim($class, "\\")] = $e->getMessage();
            return false;
        }
    }

    /**
     * Whether $class is registered as a Doctrine repository service (tagged
     * doctrine.repository_service, built with `doctrine`): a class whose name
     * ends with "Repository", that can be instantiated and that is a service
     * repository (Doctrine's ServiceEntityRepositoryInterface, which
     * omnibase's ServiceEntityRepository is).
     *
     * Every class of a bundle's src/Repository named so used to be
     * registered, an abstract base repository included - and the container
     * then had a service it could not build. Abstract classes, interfaces and
     * traits are aliased like any class of the bundle, never registered.
     */
    public static function isRepositoryService(string $class): bool
    {
        if (!str_ends_with($class, "Repository")) {
            return false;
        }

        try {
            if (!self::classLoads($class)) { // false for an interface or a trait too
                return false;
            }

            $reflection = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            return false;
        }

        return $reflection->isInstantiable()
            && $reflection->implementsInterface(\Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface::class);
    }

    public function setAlias(array $classes)
    {
        foreach ($classes as $input => $output) {

            // Autowire base repositories
            $inputExists = self::classLoads($input);
            $outputExists = $inputExists && self::classLoads($output);

            // An output that already exists AS AN ALIAS of the input (declared
            // earlier in this process, or by the fallback autoloader below
            // answering the class_exists() just above) is still this mapping,
            // and must still be recorded - skipping it wrote a pool with holes,
            // and the next process booted without those aliases.
            $outputIsAlias = false;
            if ($inputExists && $outputExists) {
                try { $outputIsAlias = (new \ReflectionClass($output))->getName() === ltrim($input, "\\"); }
                catch (\ReflectionException $e) { }
            }

            $known = array_key_exists($input, self::$aliasList ?? []) || array_key_exists($input, self::$aliasRepositoryList ?? []);
            if ($inputExists && (!$outputExists || $outputIsAlias) && !$known) {

                if (!$outputExists) {
                    class_alias($input, $output);
                }
                if (self::isRepositoryService($input)) {
                    self::$aliasRepositoryList[$input] = $output;
                } else {
                    self::$aliasList[$input] = $output;
                }
            }
        }
    }

    /**
     * @param $class
     * @return void
     */
    public function setAliasEntity($class)
    {
        if (is_array($class)) {
            $classes = $class;
            foreach ($classes as $class) {
                $this->setAlias([
                    "Base\\Entity\\" . $class => "App\\Entity\\" . $class,
                    "Base\\Entity\\" . $class . "Repository" => "App\\Entity\\" . $class . "Repository"
                ]);
            }

            return;
        }

        $this->setAlias([
            "Base\\Entity\\" . $class => "App\\Entity\\" . $class,
            "Base\\Entity\\" . $class . "Repository" => "App\\Entity\\" . $class . "Repository"
        ]);
    }

    protected static ?array $classes = null;

    /**
     * @param $path
     * @param $prefix
     * @param $level
     * @return array
     */
    public static function getAllClasses(string $path, string $prefix = "", int $level = -1): array
    {
        $fullpath = realpath($path) . " " . $prefix ." (".$level.")";
        if (!array_key_exists($fullpath, self::$classes ?? [])) {
        
            self::$classes[$fullpath] = self::$classes[$fullpath] ?? [];
            foreach (self::getFiles($path, $level) as $filename) {

                if (filesize($filename) == 0) {
                    continue;
                }

                if (str_ends_with($filename, "Interface.php")) {
                    continue;
                }

                self::$classes[$fullpath][] = self::getFullNamespace($filename, $prefix) . self::getClassname($filename);
            }

            self::$classes[$fullpath] = array_unique(self::$classes[$fullpath]);
        }

        return self::$classes[$fullpath];
    }

    protected static ?array $namespaces = null;

    /**
     * @param $path
     * @param $prefix
     * @param $level
     * @return array
     */
    public function getAllNamespaces(string $path, string $prefix = "", int $level = -1): array
    {    
        $fullpath = realpath($path) . " " . $prefix ." (".$level.")";
        if (!array_key_exists($fullpath, self::$namespaces ?? [])) {

            self::$namespaces[$fullpath] = self::$namespaces[$fullpath] ?? [];
            foreach ($this->getFiles($path, $level) as $filename) {
                if (filesize($filename) == 0) {
                    continue;
                }

                self::$namespaces[$fullpath][] = rtrim($this->getFullNamespace($filename, $prefix), "\\");
            }

            self::$namespaces[$fullpath] = array_unique(self::$namespaces[$fullpath]);
        }

        return self::$namespaces[$fullpath];
    }

    /**
     * @param $path
     * @param $prefix
     * @param $level
     * @return array
     */
    public function getAllNamespacesAndClasses(string $path, string $prefix = "", int $level = -1): array
    {
        return array_merge($this->getAllNamespaces($path, $prefix, $level), $this->getAllClasses($path, $prefix, $level));
    }

    /**
     * @param $filename
     * @return string|null
     */
    public static function getClassname(string $filename)
    {
        $directoriesAndFilename = explode('/', $filename);
        $filename = array_pop($directoriesAndFilename);
        $nameAndExtension = explode('.', $filename);
        return array_shift($nameAndExtension);
    }

    /**
     * @param $filename
     * @param $prefix
     * @return string
     */
    public static function getFullNamespace(string $filename, string $prefix = "")
    {
        $lines = file($filename);
        $array = preg_grep('/^namespace /', $lines);
        $namespace = array_shift($array);

        $match = [];
        if (preg_match('/^namespace (\\\\?)' . addslashes($prefix) . '(\\\\?)(.*);$/', $namespace, $match)) {
            $array = array_pop($match);
            if (!empty($array)) {
                return $array . "\\";
            }
        }

        return "";
    }

    protected static $cache = null;
    protected static ?array $files = null;

    /**
     * @param $path
     * @param $level
     * @return array|mixed
     */
    public static function getFiles(string $path, int $level = -1)
    {
        $path = realpath($path);
        if (!file_exists($path)) {
            return [];
        }

        if (array_key_exists($path, self::$files ?? [])) {
            return self::$files[$path];
        }

        if(is_file($path) && !is_dir($path)) {

            $files = [];
            $files[] = $path;

        } else {

            $finder = Finder::create();
            if($level > -1) $finder->depth(" < ".$level);

            $finderFiles = $finder->files()->in($path)->name('*.php');
            $files = [];
            foreach ($finderFiles as $finderFile) {
                $files[] = $finderFile->getRealpath();
            }
        }

        self::$files[$path] = $files;
        return $files;
    }

    /**
     * @param $path
     * @param $level
     * @return array|mixed
     */
    public function getDirectories(string $path, int $level = -1)
    {
        $path = realpath($path);
        if (!is_dir($path)) {
            return [];
        }

        if (array_key_exists($path, self::$files ?? [])) {
            return self::$files[$path];
        }

        $finder = Finder::create();
        if($level > -1) $finder->depth(" < ".$level);

        $finderFiles = $finder->directories()->in($path);
        $files = [];
        foreach ($finderFiles as $finderFile) {
            $files[] = $finderFile->getRealpath();
        }

        self::$files[$path] = $files;
        return $files;
    }
}
