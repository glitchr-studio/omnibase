<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->public(false);

    // ParameterBag services
    $services->alias('Base\Service\ParameterBagInterface', 'Base\Service\ParameterBag');

    $services->set('Base\Service\ParameterBag')
        ->parent('parameter_bag')
        ->decorate('parameter_bag')
        ->public(true);

    $services->set('Base\Service\HotParameterBag')
        ->parent('parameter_bag')
        ->decorate('parameter_bag')
        ->public(true);

    // Translator services
    $services->alias('Base\Service\TranslatorInterface', 'Base\Service\Translator');

    $services->set('Base\Service\Translator')
        ->decorate('translator')
        ->public(true)
        ->tag('twig.runtime')
        ->args([
            new Reference('.inner'),
            new Reference('kernel'),
            new Reference('parameter_bag'),
            // How the site addresses people (base.translator.politeness: plain, polite, formal - or nothing).
            '%base.translator.politeness%',
        ]);

    // Twig AppVariable
    $services->set('Base\Twig\AppVariable')
        ->decorate('twig.app_variable')
        ->public(true)
        ->tag('twig.runtime')
        ->args([
            new Reference('.inner'),
            new Reference('twig.random_variable'),
            new Reference('twig.site_variable'),
            new Reference('twig.email_variable'),
            new Reference('twig.admin_variable'),
            new Reference('setting_bag'),
            new Reference('parameter_bag'),
            new Reference('referrer'),
            new Reference('twig'),
            new Reference('localizer'),
            new Reference('themizer'),
        ]);

    // Twig Loader
    $services->set('Base\Twig\Loader\FilesystemLoader')
        ->decorate('twig.loader.native_filesystem')
        ->args([
            new Reference('.inner'),
            new Reference('parameter_bag'),
            '%kernel.project_dir%',
        ]);

    // Form services
    $services->alias('Base\Form\FormFactoryInterface', 'Base\Form\FormFactory');

    $services->set('Base\Form\FormFactory')
        ->parent('form.factory')
        ->decorate('form.factory')
        ->args([
            new Reference('validator'),
            new Reference('base.database.metadata_manipulator'),
            new Reference('base.database.entity_hydrator'),
        ]);

    // Inspector services
    $services->set('Base\Inspector\HtmlErrorRenderer')
        ->parent('error_handler.error_renderer.html')
        ->decorate('error_handler.error_renderer.html');

    $services->set('Base\Inspector\FileLinkFormatter')
        ->parent('debug.file_link_formatter')
        ->decorate('debug.file_link_formatter')
        ->public(false)
        ->tag('kernel.file_link_formatter');

    // Twig Environment
    $services->set('Base\Twig\Environment')
        ->parent('twig')
        ->decorate('twig')
        ->args([
            new Reference('request_stack'),
            new Reference('localizer'),
            new Reference('advanced_router'),
            new Reference('parameter_bag'),
        ]);

    // API Platform: an App\ override replaces the Base\ resource it extends.
    // Between the attribute/class-name collectors (priority 0) and the cache
    // (-10), so the cached collection is the filtered one.
    $services->set('Base\ApiPlatform\Metadata\Resource\Factory\OverriddenResourceNameCollectionFactory')
        ->decorate('api_platform.metadata.resource.name_collection_factory', null, -5)
        ->args([
            new Reference('.inner'),
        ]);

    // Console commands
    $services->set('Base\Console\Command\CacheClearCommand')
        ->parent('Base\Console\Command')
        ->decorate('console.command.cache_clear')
        ->tag('console.command')
        ->args([
            new Reference('.inner'),
            new Reference('command.sessions.clear'),
            new Reference('flysystem'),
            new Reference('base.notifier'),
            new Reference('advanced_router'),
        ])
        ->bind('$projectDir', '%kernel.project_dir%')
        ->bind('$cacheDir', '%kernel.cache_dir%');
};
