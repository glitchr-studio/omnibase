<?php

namespace Tests\Base\Bundle;

use Base\Bundle\AbstractBaseBundle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Base\Fixtures\RepositoryScan\AbstractBaseRepository;
use Tests\Base\Fixtures\RepositoryScan\ConcreteRepository;
use Tests\Base\Fixtures\RepositoryScan\ContractRepository;
use Tests\Base\Fixtures\RepositoryScan\PlainRepository;
use Tests\Base\Fixtures\RepositoryScan\SharedRepository;

/**
 * The warm-up maps a bundle's src/Repository (setMapping) and every class
 * whose name ended with "Repository" was then registered as a Doctrine
 * repository service - an abstract base repository too, which the container
 * cannot build. Only instantiable service repositories are registered now;
 * abstract classes, interfaces and traits are not.
 */
class RepositoryRegistrationTest extends TestCase
{
    private const FIXTURES = 'Tests\\Base\\Fixtures\\RepositoryScan';
    private const ALIASES = 'Tests\\Base\\Fixtures\\RepositoryScanAlias';

    /** @var array<string, mixed> */
    private array $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = (new ReflectionClass(AbstractBaseBundle::class))->getStaticProperties();
    }

    protected function tearDown(): void
    {
        $class = new ReflectionClass(AbstractBaseBundle::class);
        foreach (['aliasList', 'aliasRepositoryList', 'classes', 'files', 'unloadable'] as $name) {
            $class->setStaticPropertyValue($name, $this->snapshot[$name]);
        }
    }

    public function testOnlyAConcreteServiceRepositoryIsARepositoryService(): void
    {
        $this->assertTrue(AbstractBaseBundle::isRepositoryService(ConcreteRepository::class));

        $this->assertFalse(AbstractBaseBundle::isRepositoryService(AbstractBaseRepository::class));
        $this->assertFalse(AbstractBaseBundle::isRepositoryService(ContractRepository::class));
        $this->assertFalse(AbstractBaseBundle::isRepositoryService(SharedRepository::class));
        $this->assertFalse(AbstractBaseBundle::isRepositoryService(PlainRepository::class));
        $this->assertFalse(AbstractBaseBundle::isRepositoryService(self::FIXTURES.'\\MissingRepository'));
    }

    public function testTheMappingRegistersTheConcreteRepositoryAlone(): void
    {
        $bundle = (new ReflectionClass(RepositoryFixtureBundle::class))->newInstanceWithoutConstructor();
        \assert($bundle instanceof AbstractBaseBundle);

        $bundle->setMapping(\dirname(__DIR__).'/Fixtures/RepositoryScan', self::FIXTURES, self::ALIASES);

        $repositories = (new ReflectionClass(AbstractBaseBundle::class))->getStaticPropertyValue('aliasRepositoryList');
        $aliases = (new ReflectionClass(AbstractBaseBundle::class))->getStaticPropertyValue('aliasList');

        $this->assertSame(self::ALIASES.'\\ConcreteRepository', $repositories[ConcreteRepository::class] ?? null);
        foreach ([AbstractBaseRepository::class, ContractRepository::class, SharedRepository::class, PlainRepository::class] as $class) {
            $this->assertArrayNotHasKey($class, $repositories, $class.' must not be registered as a repository service');
        }

        // The abstract base is still aliased, as any class of the bundle: an
        // application's repository may extend it under its App\ name.
        $this->assertSame(self::ALIASES.'\\AbstractBaseRepository', $aliases[AbstractBaseRepository::class] ?? null);
        $this->assertTrue(class_exists(self::ALIASES.'\\AbstractBaseRepository', false));
    }
}

/** The smallest concrete bundle: setMapping() is all the test calls. */
class RepositoryFixtureBundle extends AbstractBaseBundle
{
}
