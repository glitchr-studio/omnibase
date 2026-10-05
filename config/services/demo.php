<?php

use Base\Demo\DemoMode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * The `demo` environment (docs/20-architecture/demo.md).
 *
 * What declares and creates the demonstration accounts exists everywhere:
 * the fixtures of dev and test build the same accounts. What acts on them -
 * the one-click sign-in, the locks, the headers, the mail that goes nowhere -
 * is registered when the kernel runs `demo`, and only then: in any other
 * environment these services are not in the container at all.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services();
    $services->defaults()
        ->public(false)
        ->autowire()
        ->autoconfigure();

    $services->set('Base\Demo\DemoMode')->public();
    $services->set('Base\Demo\DemoAccountRegistry')->public()
        ->arg('$roleHierarchy', service('security.role_hierarchy')->nullOnInvalid());
    $services->set('Base\Demo\DemoAccountFactory')->public()
        ->arg('$doctrine', service('doctrine'));
    $services->set('Base\Demo\DemoGuard')->public()
        ->arg('$doctrine', service('doctrine')->nullOnInvalid());

    $services->set('Base\Twig\Extension\DemoTwigExtension')
        ->tag('twig.extension');

    // Registered everywhere, so that it can say why it refuses outside `demo`.
    $services->set('Base\Console\Command\DemoResetCommand')
        ->arg('$doctrine', service('doctrine')->nullOnInvalid())
        ->arg('$lockFactory', service('lock.factory')->nullOnInvalid())
        ->arg('$secondLevelCache', service('Base\Subscriber\SecondLevelCacheConsoleSubscriber')->nullOnInvalid())
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->tag('console.command');

    if (!$builder->hasParameter('kernel.environment') || DemoMode::ENVIRONMENT !== $builder->getParameter('kernel.environment')) {
        return;
    }

    // The sign-in in one click (its route is declared for `demo` only too).
    $services->set('Base\Controller\DemoController')
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->call('setContainer', [service('Psr\Container\ContainerInterface')]);

    // noindex on every response; no e-mail leaves.
    $services->set('Base\Subscriber\DemoSubscriber')
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->tag('kernel.event_subscriber')
        ->tag('monolog.logger', ['channel' => 'demo']);

    // The super-administrator signs in with the secret, or not at all.
    $services->set('Base\Subscriber\DemoSuperAdminSubscriber')
        ->tag('kernel.event_subscriber');

    // A demonstration account keeps its identifier, address, password and second factor.
    $services->set('Base\DatabaseSubscriber\DemoAccountLockSubscriber')
        ->arg('$translator', service('translator')->nullOnInvalid())
        ->tag('doctrine.event_listener', ['event' => 'preFlush'])
        ->tag('kernel.reset', ['method' => 'reset']);
};
