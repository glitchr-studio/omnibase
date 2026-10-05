<?php

namespace Tests\Base\Demo\Fixtures;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\RouteCollection;

/**
 * The host application (the harness, or the application whose suite this
 * runs in) booted in an environment of the test's choosing - `demo`, `prod` -
 * with what a test needs laid over its configuration: the test container,
 * sessions in files, two declared demonstration accounts, a second factor
 * required of ROLE_ADMIN.
 *
 * A kernel of its own rather than a child of App\Kernel: an application may
 * list the environments it allows (getAllowedEnvs(), private), and `demo`
 * is one it adds when it adopts the demonstration, not before.
 */
final class DemoTestKernel extends Kernel
{
    use MicroKernelTrait {
        configureContainer as private configureHostContainer;
        loadRoutes as private loadHostRoutes;
    }

    /**
     * omnibase's own sign-in pages before any other bundle's page at the same
     * address: the harness carries HWIOAuthBundle's recipe, whose /login
     * comes first there. Nothing changes in an application, where
     * security_login is already the route of /login.
     */
    public function loadRoutes(LoaderInterface $loader): RouteCollection
    {
        $collection = $this->loadHostRoutes($loader);
        foreach ($collection->all() as $name => $route) {
            if (str_starts_with($name, 'security_')) {
                $collection->add($name, $route, 1);
            }
        }

        return $collection;
    }

    public function getProjectDir(): string
    {
        return \dirname((new \ReflectionClass('App\\Kernel'))->getFileName(), 2);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment.'_demo_test';
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureHostContainer($container, $loader, $builder);

        $container->extension('framework', [
            'test' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);
        $container->extension('base', [
            'demo' => [
                // Runtime values: one compiled container serves every case.
                'production_database' => '%env(default:demo_test.production_database:DEMO_TEST_PRODUCTION_DATABASE)%',
            ],
            'security' => ['two_factor' => ['required_roles' => ['ROLE_ADMIN']]],
        ]);
        $container->parameters()->set('demo_test.production_database', 'mysql://production.invalid:3306/production');
        $container->services()->set(TestDemoAccounts::class)->autoconfigure();
    }
}
