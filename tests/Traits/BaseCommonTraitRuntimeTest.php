<?php

namespace Tests\Base\Traits;

use Base\Service\BaseService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * A second kernel in the same process must not run on the first one's
 * services.
 *
 * BaseCommonTrait memoises every service it resolves into a static. It used
 * to keep them for the life of the PROCESS while setRuntime() swapped only
 * the locator, so after a kernel reboot the accessors kept handing out the
 * dead kernel's services. Found by a functional test that persisted entities
 * across several KernelTestCase tests: "The kernel service is synthetic",
 * "Undefined array key preQuery" and "Column uuid cannot be null", depending
 * on which stale service got reached first.
 */
class BaseCommonTraitRuntimeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $snapshot;

    protected function setUp(): void
    {
        // These statics are process-global and every other test in the run
        // depends on them: snapshot all of them, restore them verbatim.
        $this->snapshot = (new ReflectionClass(BaseService::class))->getStaticProperties();

        // And each test starts from nothing resolved: a kernel test run before
        // this one (a page rendered, a user created) leaves its translator in
        // the static, which runtimeGet() then answered in place of the one
        // the test installs - these tests passed or failed with the order of
        // the suite.
        $class = new ReflectionClass(BaseService::class);
        foreach ((new ReflectionClass(\Base\Traits\BaseCommonTrait::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $class->setStaticPropertyValue($property->getName(), null);
        }
    }

    protected function tearDown(): void
    {
        $class = new ReflectionClass(BaseService::class);
        foreach ($this->snapshot as $name => $value) {
            $class->setStaticPropertyValue($name, $value);
        }
    }

    public function testANewRuntimeReplacesWhatTheOldOneResolved(): void
    {
        $old = $this->createMock(TranslatorInterface::class);
        $new = $this->createMock(TranslatorInterface::class);

        $this->install(null);
        BaseService::setRuntime($this->locator(['translator' => $old]));
        $this->assertSame($old, BaseService::runtimeGet('translator', 'translator'));

        BaseService::setRuntime($this->locator(['translator' => $new]));
        $this->assertSame($new, BaseService::runtimeGet('translator', 'translator'), 'Still serving the previous kernel\'s translator.');
    }

    public function testReinstallingTheSameRuntimeKeepsWhatItResolved(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $runtime = $this->locator(['translator' => $translator]);

        $this->install(null);
        BaseService::setRuntime($runtime);
        BaseService::runtimeGet('translator', 'translator');

        BaseService::setRuntime($runtime);
        $this->assertSame($translator, $this->staticValue('translator'), 'Same kernel, nothing to forget.');
    }

    /**
     * The first installation happens with nothing stale yet; anything filled
     * in before it (an eagerly constructed BaseService calling set*()) is
     * current and must survive.
     */
    public function testTheFirstRuntimeKeepsValuesSetBeforeIt(): void
    {
        $prefilled = $this->createMock(TranslatorInterface::class);

        $this->install(null);
        BaseService::setTranslator($prefilled);
        BaseService::setRuntime($this->locator(['translator' => $this->createMock(TranslatorInterface::class)]));

        $this->assertSame($prefilled, BaseService::runtimeGet('translator', 'translator'));
    }

    /**
     * $twig, $settings and $parameterBag used to be non-nullable typed
     * statics. PHP cannot unset a static property, so once assigned they
     * could never be cleared and would have stayed stale whatever
     * setRuntime() did.
     */
    public function testFormerlyNonNullableStaticsAreClearedToo(): void
    {
        $this->install(null);
        BaseService::setRuntime($this->locator([]));
        BaseService::setTwig($this->createMock(Environment::class));

        BaseService::setRuntime($this->locator([]));
        $this->assertNull($this->staticValue('twig'));
    }

    /**
     * @param array<string, object> $services
     */
    private function locator(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            public function __construct(private array $services)
            {
            }

            public function get(string $id): mixed
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }

    /** Sets the runtime without going through setRuntime()'s reset logic. */
    private function install(?ContainerInterface $runtime): void
    {
        (new ReflectionClass(BaseService::class))->setStaticPropertyValue('runtime', $runtime);
    }

    private function staticValue(string $name): mixed
    {
        return (new ReflectionClass(BaseService::class))->getStaticPropertyValue($name);
    }
}
