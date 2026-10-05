<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

/*
 * The shared bricks (docs/40-commons): written once here, used by the
 * omnibase/* bundles and the applications instead of their own copies.
 * Autowired: their arguments are typed, the configuration comes through
 * #[Autowire('%base.…%')].
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()
        ->public(false)
        ->autowire()
        ->autoconfigure();

    $services->set('Base\Service\OpeningHours')->public()
        ->tag('kernel.reset', ['method' => 'reset']);
    // A TemplatedEmail's {% block subject %} becomes its subject.
    $services->set('Base\Subscriber\TemplatedEmailSubjectSubscriber')
        ->args([service('twig')])
        ->tag('kernel.event_subscriber');
    // The second-level cache emptied after doctrine:fixtures:load and the like.
    $services->set('Base\Subscriber\SecondLevelCacheConsoleSubscriber')
        ->args([service('doctrine')->nullOnInvalid()])
        ->tag('kernel.event_subscriber');
    $services->set('Base\Service\Calendar\Ics')->public();
    $services->set('Base\Service\Calendar\GoogleCalendarLink')->public();
    $services->set('Base\Service\Invitations')->public()
        ->arg('$userClass', 'App\Entity\User');
    $services->set('Base\Service\DownloadLinks')->public()
        ->arg('$signer', service('uri_signer'));
    $services->set('Base\Service\Qr\QrCode')->public();
    $services->set('Base\Service\Qr\QrSheet')->public();
    $services->set('Base\Service\CommentGuard')->public();
    $services->set('Base\Service\Embeds')->public()
        ->arg('$cache', service('cache.app')->nullOnInvalid())
        ->arg('$logger', service('logger')->nullOnInvalid());

    $services->set('Base\Twig\Extension\CommonsTwigExtension')
        ->arg('$media', service('Base\Service\MediaService')->nullOnInvalid())
        ->tag('twig.extension');

    // The texts rewritten in the back office, between omnibase's translator
    // (Base\Service\Translator, priority 0) and Symfony's. The shared Redis
    // pool when the application has one (web, worker and cron agree).
    $services->set('Base\Translation\OverridingTranslator')
        ->autowire(false)->autoconfigure(false)
        ->decorate('translator', null, 1)
        ->args([
            service('.inner'),
            service_closure('Base\Repository\Layout\TextOverrideRepository'),
            service('base.text_override.cache'),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias('base.text_override.cache', 'cache.app');

    $services->set('Base\Translation\TextOverrideCacheListener')
        ->autowire(false)->autoconfigure(false)
        ->args([service_closure('Base\Translation\OverridingTranslator')])
        ->tag('doctrine.event_listener', ['event' => 'postPersist'])
        ->tag('doctrine.event_listener', ['event' => 'postUpdate'])
        ->tag('doctrine.event_listener', ['event' => 'postRemove'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush']);
};
