<?php

namespace Tests\Base\Bundle;

use Base\Bundle\AbstractBaseBundle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Base\Fixtures\Warmup\Loadable;
use Tests\Base\Fixtures\Warmup\OptionalExtension;
use Tests\Base\Fixtures\Warmup\OptionalImplementation;

/**
 * BaseBundle::warmUp() maps every directory of every bundle (setMapping),
 * which loads every class it finds. A class written for a package the bundle
 * only suggests (Base\Agenda\Digest\AgendaDigestSource implements
 * omnibase/newsletter's interface) used to stop the kernel at boot with
 * `Interface "..." not found` when that package was absent. It is skipped
 * now, and the rest of the directory is still mapped.
 */
class WarmupOptionalDependencyTest extends TestCase
{
    private const FIXTURES = 'Tests\\Base\\Fixtures\\Warmup';
    private const ALIASES = 'Tests\\Base\\Fixtures\\WarmupAlias';

    /** @var array<string, mixed> */
    private array $snapshot;

    protected function setUp(): void
    {
        // Process-wide statics every other test relies on (the alias lists,
        // the file and class caches): kept, and put back as they were.
        $this->snapshot = (new ReflectionClass(AbstractBaseBundle::class))->getStaticProperties();
    }

    protected function tearDown(): void
    {
        $class = new ReflectionClass(AbstractBaseBundle::class);
        foreach (['aliasList', 'aliasRepositoryList', 'classes', 'files', 'unloadable'] as $name) {
            $class->setStaticPropertyValue($name, $this->snapshot[$name]);
        }
    }

    private function bundle(): AbstractBaseBundle
    {
        // Without its constructor: it would claim the bundles' singleton slot.
        $bundle = (new ReflectionClass(WarmupFixtureBundle::class))->newInstanceWithoutConstructor();
        \assert($bundle instanceof AbstractBaseBundle);

        return $bundle;
    }

    public function testAClassWhoseInterfaceOrParentIsMissingIsSkippedNotFatal(): void
    {
        $this->bundle()->setMapping(\dirname(__DIR__).'/Fixtures/Warmup', self::FIXTURES, self::ALIASES);

        // The loadable class of the same directory is still mapped...
        $this->assertTrue(class_exists(self::ALIASES.'\\Loadable', false));
        $this->assertSame(Loadable::class, (new ReflectionClass(self::ALIASES.'\\Loadable'))->getName());

        // ...the two others are not, and say why.
        $this->assertFalse(class_exists(self::ALIASES.'\\OptionalImplementation', false));
        $this->assertFalse(class_exists(self::ALIASES.'\\OptionalExtension', false));

        $unloadable = AbstractBaseBundle::getUnloadableClasses();
        $this->assertArrayHasKey(OptionalImplementation::class, $unloadable);
        $this->assertStringContainsString('Interface "Tests\\Base\\Fixtures\\Absent\\ProviderInterface" not found', $unloadable[OptionalImplementation::class]);
        $this->assertArrayHasKey(OptionalExtension::class, $unloadable);
        $this->assertStringContainsString('Class "Tests\\Base\\Fixtures\\Absent\\Provider" not found', $unloadable[OptionalExtension::class]);
    }

    public function testClassLoadsAnswersFalseForAMissingDependencyAndTrueOtherwise(): void
    {
        $this->assertTrue(AbstractBaseBundle::classLoads(Loadable::class));
        $this->assertFalse(AbstractBaseBundle::classLoads(OptionalImplementation::class));
        $this->assertFalse(AbstractBaseBundle::classLoads('Tests\\Base\\Fixtures\\Warmup\\DoesNotExist'));
    }

    public function testAnyOtherErrorStillSurfaces(): void
    {
        $loader = static function (string $class): void {
            if ('Tests\\Base\\Fixtures\\Warmup\\Broken' === $class) {
                throw new \Error('Call to undefined function nowhere()');
            }
        };
        spl_autoload_register($loader);

        try {
            $this->expectException(\Error::class);
            $this->expectExceptionMessage('Call to undefined function nowhere()');
            AbstractBaseBundle::classLoads('Tests\\Base\\Fixtures\\Warmup\\Broken');
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}

/** The smallest concrete bundle: setMapping() is all the test calls. */
class WarmupFixtureBundle extends AbstractBaseBundle
{
}
