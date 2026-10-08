<?php

namespace Base;

if(!isset($_SERVER["APP_TIMER"])) {
    $_SERVER["APP_TIMER"] = microtime(true);
}

use App\Entity\User;
use Base\Database\Type\DateTimeTypeUTC as DateTimeType;
use Base\Database\Type\ArrayType;
use Doctrine\DBAL\Types\Type;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

use Base\Attributes\AttributeReader;
use Base\Bundle\AbstractBaseBundle;
use Base\Console\Command\CacheClearCommand;
use Base\Demo\DemoGuard;
use Base\Demo\DemoMode;
use Base\DependencyInjection\Dumper\CliDumper;
use Base\DependencyInjection\Dumper\HtmlDumper;
use Base\Service\BaseService;
use Base\Traits\SingletonTrait;

class BaseBundle extends AbstractBaseBundle
{
    // Re-declaring the trait here (already present on AbstractBaseBundle)
    // gives BaseBundle its OWN $_instance storage instead of sharing the
    // abstract parent's - see AbstractBaseBundle's constructor for the
    // full explanation. Every other concrete bundle extending
    // AbstractBaseBundle (Base\Admin\AdminBundle, Base\Wikidoc\WikidocBundle)
    // must do the same.
    use SingletonTrait;

    public const VERSION = '1.0.0';

    public function __construct()
    {
        if (!$this->hasInstance()) {
            self::$_instance = $this;
        }
    }

    /**
     * @return string
     */
    public function getCacheDir(): string
    {
        return $this->container->getParameter('kernel.cache_dir');
    }

    /**
     * @return string
     */
    public function getPublicDir(): string 
    {
        return $this->getProjectDir() . "/public";
    }

    /**
     * @return string
     */
    public function getSourceDir(): string
    {
        return $this->getProjectDir() . "/src";
    }

    /**
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->container->getParameter('kernel.environment');
    }

    protected bool $boot = false;

    /**
     * @return bool
     */
    public function isBooted()
    {
        return $this->boot;
    }

    protected bool $doctrineReadiness = false;
    public function isDoctrineReady(): bool
    {
        return $this->doctrineReadiness;
    }

    //
    // Some subscribers are not called when modifying codes.
    // The purpose of this broken cache feature is to prevent running without these subscribers
    protected bool $invalidCache = true; // Turned off in subscribers if everything fine.

    /**
     * @return bool
     */
    public function isInvalid(): bool
    {
        return $this->invalidCache;
    }

    public function markCacheAsValid(): void
    {
        $this->invalidCache = false;
    }

    public function warmUp()
    {
        $needsWarmup = !file_exists($this->getCacheDir() . "/pools/base/bundle.php");
        self::$cache               = new PhpArrayAdapter($this->getCacheDir() . "/pools/base/bundle.php", new FilesystemAdapter("", 0, $this->getCacheDir() . "/pools/base/fallback"));
        self::$files               = self::$files               ?? self::$cache->getItem('base.files')->get() ?? [];
        self::$classes             = self::$classes             ?? self::$cache->getItem('base.classes')->get() ?? [];
        self::$aliasList           = self::$aliasList           ?? self::$cache->getItem('base.alias_list')->get() ?? [];
        self::$aliasRepositoryList = self::$aliasRepositoryList ?? self::$cache->getItem('base.alias_repository_list')->get() ?? [];

        // A pool file that exists but carries no aliases is not a warm cache,
        // it is a cache being (re)written by a concurrent request - seen live
        // as `Class "App\Repository\Thread\MentionRepository" not found` when two
        // requests rebuilt the container at once. Rescan rather than boot
        // with no aliases at all.
        if (!$needsWarmup && empty(self::$aliasList) && empty(self::$aliasRepositoryList)) {
            $needsWarmup = true;
        }

        // warmUp() runs more than once in a process (the dev kernel reboots after
        // a cache rebuild), and class_alias() on an alias that already exists
        // is a warning - turned into a 500 on whatever page was being served.
        // an application's own class, not loaded yet, is never replaced (declareAlias)
        foreach (self::$aliasList as $class => $alias) {
            self::declareAlias($class, $alias);
        }
        foreach (self::$aliasRepositoryList as $class => $alias) {
            self::declareAlias($class, $alias);
        }

        // One builder at a time. Without this, every request that finds the
        // pool missing (a cache:clear just swapped the cache dir, a fresh
        // deploy) scans the bundles concurrently and each rewrites the pool;
        // the rewrite itself is atomic (tempnam + rename), but the requests
        // in between booted from whatever they had read. Waiting on the lock
        // and re-reading the pool means only the first request pays for the
        // scan and the others start from its result.
        $lock = null;
        if ($needsWarmup) {
            $lock = $this->acquirePoolLock();
            if ($lock && $this->reloadPool()) {
                $needsWarmup = false;
            }
        }

        if ($needsWarmup) {

            foreach(array_reverse($this->getBundles()) as $baseBundle)
            {
                if($baseBundle == BaseBundle::class) continue;

                $classRefl = new \ReflectionClass($baseBundle);
                $classPath = dirname($classRefl->getFileName());

                $baseNamespace = dirname_namespace($baseBundle);
                foreach(self::getDirectories($classPath, 1) as $namepath)
                {
                    $namespace = basename($namepath);

                    $baseClass = $baseNamespace . "\\". $namespace;
                    $baseClassArray = explode("\\", $baseClass);
                    array_swap($baseClassArray, 1, 2);
                    
                    $baseClassSwap = implode("\\", $baseClassArray);
                    $this->setMapping($classPath . "/".$namespace     , $baseClass     , $baseClassSwap);
                }
            }

            $this->setMapping($this->getBundleDir() . "/src/Entity"    , "Base\Entity"    , "App\Entity");
            $this->setMapping($this->getBundleDir() . "/src/Repository", "Base\Repository", "App\Repository");
            $this->setMapping($this->getBundleDir() . "/src/Enum"      , "Base\Enum"      , "App\Enum");

            $this->setMapping($this->getBundleDir() . "/src/Tests"     , "Base\Tests"     , "App\Tests");
            $this->setMapping($this->getBundleDir() . "/src/Enum"      , "Base\Enum"      , "App\Enum");
            $this->setMapping($this->getBundleDir() . "/src/Notifier"  , "Base\Notifier"  , "App\Notifier");
            $this->setMapping($this->getBundleDir() . "/src/Form"      , "Base\Form"      , "App\Form");
            $this->setMapping($this->getBundleDir() . "/src/Entity"    , "Base\Entity"    , "App\Entity");
            $this->setMapping($this->getBundleDir() . "/src/Repository", "Base\Repository", "App\Repository");
            
            self::getAllClasses($this->getBundleDir() . "/src/Database/Attribute");
            self::getAllClasses($this->getBundleDir() . "/src/Attributes/Attribute");
            
            self::getAllClasses($this->getBundleDir() . "/src/Enum");
            self::getAllClasses($this->getProjectDir() . "/src/Enum");
            
            self::$cache->warmUp([
                "base.files" => self::$files ?? [],
                "base.classes" => self::$classes ?? [],
                "base.alias_list" => self::$aliasList ?? [],
                "base.alias_repository_list" => self::$aliasRepositoryList ?? []
            ]);
        }

        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Blocks until this process holds the pool lock, or returns null when the
     * lock cannot be taken (read-only cache dir): the caller then scans on its
     * own, which is the pre-lock behaviour, never a failure.
     *
     * @return resource|null
     */
    private function acquirePoolLock()
    {
        $dir = $this->getCacheDir() . "/pools/base";
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return null;
        }
        $fp = @fopen($dir . "/bundle.lock", "c");
        if (!$fp) {
            return null;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }
        return $fp;
    }

    /**
     * After waiting on the lock, another request may have built the pool:
     * re-read it (a fresh adapter, the previous one memoised the miss) and
     * register its aliases. True when the pool is usable.
     */
    private function reloadPool(): bool
    {
        $file = $this->getCacheDir() . "/pools/base/bundle.php";
        if (!file_exists($file)) {
            return false;
        }
        $cache = new PhpArrayAdapter($file, new FilesystemAdapter("", 0, $this->getCacheDir() . "/pools/base/fallback"));
        $aliasList = $cache->getItem('base.alias_list')->get() ?? [];
        $aliasRepositoryList = $cache->getItem('base.alias_repository_list')->get() ?? [];
        if (empty($aliasList) && empty($aliasRepositoryList)) {
            return false;
        }

        self::$cache = $cache;
        self::$files = $cache->getItem('base.files')->get() ?? [];
        self::$classes = $cache->getItem('base.classes')->get() ?? [];
        self::$aliasList = $aliasList;
        self::$aliasRepositoryList = $aliasRepositoryList;
        // an application's own class, not loaded yet, is never replaced (declareAlias)
        foreach (self::$aliasList as $class => $alias) {
            self::declareAlias($class, $alias);
        }
        foreach (self::$aliasRepositoryList as $class => $alias) {
            self::declareAlias($class, $alias);
        }
        return true;
    }

    public function boot(): void
    {
        if (!extension_loaded('imagick')) {
           throw new EnvNotFoundException('Application requires `imagick`, but it is not enabled.');
        }

        if (!extension_loaded('igbinary')) {
           throw new EnvNotFoundException('Application requires `igbinary`, but it is not enabled.');
        }

        // FIRST, before any service is constructed: registering the
        // App\* => Base\* class aliases is what makes an application entity
        // loadable at all (App\Entity\Article\Tag extends
        // App\Entity\Thread\Tag, which only exists as an alias). Anything
        // below that reaches Doctrine metadata - the AttributeReader graph
        // does - loads those entity files, and with no aliases yet that is a
        // fatal "Attempted to load class Tag from namespace
        // App\Entity\Thread". It stayed hidden for as long as the metadata
        // pool was warm, since a cache hit never loads the classes; deleting
        // var/cache/<env>/pools left the application unbootable, console
        // included, with no way to warm it back up.
        if (!self::$cache) {
            $this->warmUp();
        }

        // Seed the lazy runtime for BaseTrait/BaseCommonTrait static accessors.
        // boot() runs for HTTP, console AND bare kernel boots, so the statics
        // work everywhere without eagerly constructing BaseService's full
        // dependency graph — the locator only holds closures; each service is
        // instantiated on its first actual accessor call.
        if ($this->container->has('base.runtime')) {
            BaseService::setRuntime($this->container->get('base.runtime'));
        }
        BaseService::setProjectDir($this->container->getParameter('kernel.project_dir'));
        BaseService::setEnvironment($this->container->getParameter('kernel.environment'));

        // The demonstration refuses to start in debug or on the production
        // database (docs/20-architecture/demo.md): asked here, for a page as
        // for a command, before anything is read or written.
        if (DemoMode::ENVIRONMENT === $this->container->getParameter('kernel.environment') && $this->container->has(DemoGuard::class)) {
            $this->container->get(DemoGuard::class)->check();
        }

        // Doctrine constructs SQLFilter classes (e.g. TrashFilter) itself, bypassing
        // the DI container entirely, so they can only reach AttributeReader through
        // its getInstance() singleton. Nothing else on the console/command path
        // (no Twig, no controller) necessarily asks the container for it first, so
        // without this the singleton is still null the first time a filtered query
        // runs. The constructor is metadata-free since the Attribute refactor, so
        // constructing it here is cheap.
        if ($this->container->has(AttributeReader::class)) {
            $this->container->get(AttributeReader::class);
        }

        if (class_exists(\Symfony\Component\VarDumper\VarDumper::class)) {

            $htmlDumper = new HtmlDumper();
            $cliDumper = new CliDumper();

            \Symfony\Component\VarDumper\VarDumper::setHandler(function ($var) use ($htmlDumper, $cliDumper) {

                static $startTime = null;
                if ($startTime === null) {
                    $startTime = microtime(true);
                }

                if(is_cli()) {
                    $dumper = $cliDumper;
                } else {
                    $dumper = $htmlDumper;
                }

                $dumper->dump(
                    (new \Symfony\Component\VarDumper\Cloner\VarCloner())->cloneVar($var)
                );
            });
        }

        
        if ($this->container->getParameter("base.database.use_custom")) {
                
            // Start session here to access client information
            $timezone = null;
            if (method_exists(User::class, "getCookie")) $timezone = User::getCookie("timezone");
            if (!in_array($timezone, timezone_identifiers_list())) $timezone = "UTC";

            // Set default time to UTC everywhere
            date_default_timezone_set($timezone);
            // Instants are stored in UTC. A date is a calendar day with no time
            // and no zone: Doctrine's own "date" type (DATE, Y-m-d). It was
            // overridden with the UTC datetime too, which made a "date" field a
            // DATETIME holding the visitor's midnight in UTC (15:00 the day
            // before, for a visitor in Tokyo), and a real DATE column never
            // compared equal to its mapping.
            Type::overrideType('datetime', DateTimeType::class);
            Type::overrideType('datetimetz', DateTimeType::class);

            // Backward compatibility (see doctrine:array:upgrade)
            if(Type::hasType('array')) Type::overrideType('array', ArrayType::class);
            else Type::addType('array', ArrayType::class);

            $classList = array_merge(
                self::getAllClasses(self::getBundleDir() . "/src/Enum"),
                self::getAllClasses($this->getProjectDir() . "/src/Enum")
            );

            foreach ($classList as $className) {

                // A plain PHP enum (Base\Enum\Allergen, CommentState) is no Doctrine type.
                if (!method_exists($className, "getStaticName")) continue;

                if(!Type::hasType($className::getStaticName())) {
                    Type::addType($className::getStaticName(), $className);
                }

                $type = Type::getType($className::getStaticName());
                if($type == $className) {
                    throw new EnvNotFoundException('Doctrine type `'.$className::getStaticName().'` already exists, conflict detected between '. $className." and ". get_class($type));
                }
            }

            $entityManager = $this->container->get('doctrine.orm.entity_manager'); 
            $entityManager->getFilters()->enable("trash_filter");
            $entityManager->getFilters()->enable("vault_filter")->setEnvironment($this->getEnvironment());
        }

        CacheClearCommand::$testFile ??= $this->getCacheDir().".txt";
        $this->boot = true;
    }

    public function isBuilt() { return $this->container !== null; }
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
	    $this->container = $container;

        if (!self::$cache) {
            $this->warmUp();
        }

        /* Register compiler passes */
        $finder = new \Symfony\Component\Finder\Finder();
        $finder->files()
            ->in($this->getBundleDir() . '/src/DependencyInjection/Compiler/Pass')
            ->name('*Pass.php')
            ->notName('AbstractPass.php');

        foreach ($finder as $file) {
            $class = 'Base\\DependencyInjection\\Compiler\\Pass\\' . $file->getBasename('.php');
            if (class_exists($class) && !in_array($class, [__CLASS__])) {
                $priority = method_exists($class, 'getPriority') ? $class::getPriority() : 0;
                $container->addCompilerPass(new $class(), priority: $priority);
            }
        }

        /* Register aliased repositories */
        foreach (self::$aliasRepositoryList as $baseRepository => $aliasedRepository) {

            // A pool written before abstract repositories were set aside may
            // still list one: never registered (the container cannot build it).
            if (!self::isRepositoryService($baseRepository)) {
                continue;
            }

            $container->register($baseRepository)
                      ->addTag("doctrine.repository_service")
                      ->addArgument(new Reference('doctrine'));

            if ($aliasedRepository) {
                $container->register($aliasedRepository)
                          ->addTag("doctrine.repository_service")
                          ->addArgument(new Reference('doctrine'));
            }
        }
    }
}
