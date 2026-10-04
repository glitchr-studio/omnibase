<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * The addresses a site no longer answers (docs/40-commons/redirections.md):
 * Base\Entity\Layout\Redirection, looked up when a request ends in "not found".
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()
        ->public(false)
        ->autowire()
        ->autoconfigure();

    $services->set('Base\Service\Redirections')->public()
        ->arg('$logger', service('logger')->nullOnInvalid());

    $services->set('Base\Subscriber\RedirectionSubscriber');
};
