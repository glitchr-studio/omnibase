<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use \Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;


use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->public(false);

    // Form proxy
    $services->set('Base\Form\FormProxy')
        ->public(true)
        ->args([service('form.factory')])
        ->tag('kernel.reset', ['method' => 'reset']);

    // Session storage
    $services->set('Base\Security\Session\DynamicSessionStorageFactory')
        ->public(true)
        ->parent('session.storage.factory.native');

    // Access token handler
    $services->set('Base\Security\AccessTokenHandler')
        ->args([service('Base\Repository\User\TokenRepository')]);

    // Referrer
    $services->set('Base\Service\Referrer')
        ->public(true)
        ->args([
            service('request_stack'),
            service('advanced_router'),
        ]);

    // Analytics (page views + visitor/user unique counters)
    $services->set('Base\Service\Analytics\UserAgentClassifier')
        ->public(false);

    $services->set('Base\Service\Analytics')
        ->public(true)
        ->args([
            service('Base\Repository\Analytics\PageViewRepository'),
            service('Base\Repository\Analytics\VisitRepository'),
            service('Base\Service\Analytics\UserAgentClassifier'),
            service('Base\Repository\Analytics\PageVisitRepository'),
        ]);

    // CacheClearSessionsCommand
    $services->set('Base\Console\Command\CacheClearSessionsCommand')
        ->parent('Base\Console\Command')
        ->public(true)
        ->tag('console.command')
        ->bind('$projectDir', '%kernel.project_dir%');

    // Controllers
    $controllers = [
        'Base\Controller\MainController' => ['base.service', 'setting_bag'],
        'Base\Controller\ProfilerController' => ['base.notifier', 'App\Repository\UserRepository'],
        'Base\Controller\Client\ContactController' => ['form.proxy', 'base.notifier'],
        'Base\Controller\LocalizerController' => ['localizer', 'doctrine.orm.entity_manager', 'advanced_router', 'referrer', 'translator'],
        'Base\Controller\UX\MediaController' => ['request_stack', 'flysystem', 'base.service.image', 'Base\Repository\Layout\ImageCropRepository'],
        'Base\Controller\UX\AutocompleteController' => ['Base\Service\Obfuscator', 'request_stack', 'trading_market', 'translator', 'doctrine.orm.entity_manager', 'Base\Service\Paginator', 'Base\Database\Mapping\ClassMetadataManipulator', 'base.service.image'],
        'Base\Controller\WidgetController' => ['Base\Repository\Layout\Widget\PageRepository', 'Base\Repository\Layout\Widget\AttachmentRepository'],
        'Base\Controller\ShortLinkController' => ['advanced_router', 'Base\Repository\Layout\ShortLinkRepository'],
        // The like/follow/publish API. Was silently unregistered (constructor
        // arguments, no service): every /api/thread/{slug}/like answered 500
        // in production - "the like doesn't work".
        'Base\Controller\Api\ThreadController' => ['doctrine.orm.entity_manager', 'translator', 'Base\Repository\ThreadRepository', 'Base\Repository\Thread\LikeRepository'],
        'Base\Controller\Client\NotificationController' => ['doctrine.orm.entity_manager', 'Base\Repository\User\NotificationRepository', 'Base\Service\Push\WebPushService', 'security.csrf.token_manager', 'Base\Notifier\NotificationLinkerInterface'],
    ];

    foreach ($controllers as $id => $args) {
        $definition = $services->set($id)
            ->tag('controller.service_arguments')
            ->tag('container.service_subscriber')
            ->call('setContainer', [service('Psr\Container\ContainerInterface')]);
        foreach ($args as $i => $arg) {
            $definition->arg($i, service($arg));
        }
        if ($id === 'Base\Controller\UX\MediaController') {
            $definition->arg(4, new Reference('profiler', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }
        if ($id === 'Base\Controller\UX\AutocompleteController') {
            $definition->arg(8, new Reference('profiler', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }
    }

    // The editor's endpoints (uploads, mentions, autosave, collab ticket):
    // never registered either - constructor arguments, no service - so every
    // /ux/editorjs/* answered 500, the editor's uploads and autosave included.
    // Autowired: its arguments are all typed.
    $services->set('Base\Controller\UX\EditorController')
        ->autowire()
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->call('setContainer', [service('Psr\Container\ContainerInterface')]);

    // Subscribers
    $subscribers = [
        'Base\Subscriber\RouterSubscriber' => ['security.authorization_checker', 'advanced_router', 'parameter_bag'],
        'Base\Subscriber\ProfilerSubscriber' => ['advanced_router'],
        'Base\Subscriber\TwigSubscriber' => ['twig.html_renderer', 'twig.webpack_renderer', 'security.authorization_checker', 'parameter_bag', 'advanced_router'],
        'Base\Subscriber\HotParameterBagSubscriber' => ['parameter_bag', 'setting_bag'],
        'Base\Subscriber\AnalyticsSubscriber' => ['security.token_storage', 'advanced_router', 'translator', 'twig', 'App\Repository\UserRepository', 'ga.service'],
        'Base\Subscriber\PageViewSubscriber' => ['Base\Service\Analytics', 'Symfony\Bundle\SecurityBundle\Security', '$excludedPrefixes' => ['/admin', '/_', '/api']],
    ];

    foreach ($subscribers as $id => $args) {
        $definition = $services->set($id)
            ->tag('kernel.event_subscriber');
        foreach ($args as $k => $v) {
            if (is_int($k)) {
                $definition->arg($k, service($v));
            } else {
                $definition->bind($k, $v);
            }
        }
    }

    // Request-scoped statics for processes that outlive a request (FrankenPHP
    // worker mode, messenger consumers). Both tags on purpose: kernel.reset never
    // runs on a cloned kernel, kernel.request never runs between messenger
    // messages - see the class docblock.
    $services->set('Base\Subscriber\EntityDispatcherFlushSubscriber')
        ->args([tagged_iterator('base.entity_dispatcher')])
        ->tag('kernel.event_subscriber');

    $services->set('Base\Subscriber\RequestScopedStateSubscriber')
        ->tag('kernel.event_subscriber')
        ->tag('kernel.reset', ['method' => 'reset']);

    // AdvancedRouter
    $services->set('Base\Routing\AdvancedRouter')
        ->tag('twig.runtime')
        ->args([
            service('router'),
            service('request_stack'),
            service('security.firewall.map'),
            service('parameter_bag'),
            service('localizer'),
            service('twig.extension.assets'),
            service('cache.adapter'),
        ])
        ->bind('$debug', '%kernel.debug%')
        ->bind('$environment', '%kernel.environment%');

    // Tracks config/routes_paths.yaml (the Base\Attributes\Attribute\Route
    // translationKey dictionary) as a routing resource, so the route cache
    // invalidates when it changes even though no loader reads routes from it.
    $services->set('Base\Routing\Loader\RouteTranslationsResourceLoader')
        ->args([service('file_locator'), '%kernel.environment%'])
        ->tag('routing.loader');

    // SettingBag
    $services->alias('Base\Service\SettingBagInterface', 'Base\Service\SettingBag');
    $services->set('Base\Service\SettingBag')
        ->public(true)
        ->tag('twig.runtime')
        // Compiles+persists the settings snapshot at warmup (SettingBag
        // implements WarmableInterface) so the first request after a
        // deploy/cache:clear hits a warm cache instead of compiling on-demand.
        ->tag('kernel.cache_warmer')
        ->args([
            service('parameter_bag'),
            service('doctrine.orm.entity_manager'),
            service('Base\Repository\Layout\SettingRepository'),
            service('localizer'),
            service('cache.adapter'),
        ])
        ->bind('$environment', '%kernel.environment%');

    // Reads the administrator's account-security settings and answers "may
    // this user change that themselves". Autowired on SettingBagInterface,
    // which is aliased just above.
    $services->set('Base\\Service\\SecurityPolicy')
        ->autowire()
        ->public(true);

    // Steers a signed-in user to the enrolment prompt while the administrator
    // requires a second factor they do not have.
    $services->set('Base\\Subscriber\\SecurityEnrolmentSubscriber')
        ->autowire()
        ->tag('kernel.event_subscriber');

    // Emails the account holder when their account is signed into from a
    // browser it has not been signed into before.
    $services->set('Base\\Subscriber\\NewDeviceSubscriber')
        ->autowire()
        ->tag('kernel.event_subscriber');

    // Keeps the SettingBag snapshot in sync with every Setting/SettingIntl
    // write, including the admin CRUD's plain flush() (which bypasses
    // SettingBag::set() entirely). service_closure, NOT service: Doctrine
    // instantiates listeners while initializing the event manager, and
    // constructing SettingBag there (repository -> getClassMetadata) triggers
    // a re-entrant resolveDiscriminator dispatch on the half-initialized
    // event manager.
    $services->set('Base\EntitySubscriber\SettingSubscriber')
        ->tag('doctrine.event_listener', ['event' => 'postPersist'])
        ->tag('doctrine.event_listener', ['event' => 'postUpdate'])
        ->tag('doctrine.event_listener', ['event' => 'postRemove'])
        ->args([service_closure('Base\Service\SettingBagInterface')]);

    // Entity dispatchers shipped by this bundle.
    //
    // BaseExtension::load() calls registerForAutoconfiguration() on
    // EventDispatcherInterface and attaches the six doctrine.event_listener
    // tags to it - but autoconfiguration only ever applies to services that are
    // actually DEFINED somewhere, and these two never were. A consuming app's
    // own dispatchers get picked up by its `App\` resource block, which is why
    // App-side ones worked and these silently did not: neither class was in the
    // container at all, so thread.* and user.* were never dispatched.
    //
    // Concretely, that meant ThreadEvent::PUBLISHABLE never fired, so anything
    // listening for it - such as an app subscriber that mails subscribers when
    // a scheduled article comes due - never ran, with no error to show for it.
    //
    // autoconfigure() is required (the defaults() block above only sets
    // public(false)); autowire() satisfies AbstractEventDispatcher's
    // constructor, exactly as the app-side dispatchers are already resolved.
    $services->set('Base\EntityDispatcher\Event\ThreadEventDispatcher')
        ->autowire()
        ->autoconfigure();

    $services->set('Base\EntityDispatcher\Event\UserEventDispatcher')
        ->autowire()
        ->autoconfigure();

    // The one service that turns eligibility into a capability.
    //
    // Group::isOpenTo() and awardsFor() deliberately grant nothing, so
    // something has to close the gap between "has earned this" and "has it".
    // In fr.latoucheoriginale.www that something is a single service,
    // MarketplaceManager, and this is its counterpart for people.
    $services->set('Base\Service\GroupManager')
        ->autowire()
        ->autoconfigure()
        ->public();

    // Reading and emptying the trash, i.e. everything that has to see what the
    // trash filter hides. Public because the admin trash page and the purge
    // command are its only callers and both fetch it by id.
    $services->set('Base\Service\TrashManager')
        ->public()
        ->args([service('doctrine.orm.entity_manager')]);
    $services->alias('trash_manager', 'Base\Service\TrashManager')->public();

    // Reads the modification history back - for the per-field badge in form
    // labels and for the entity-level history page.
    $services->set('Base\Service\VersionManager')
        ->public()
        ->args([service('doctrine.orm.entity_manager'), '%base.extension.max_revisions%']);
    $services->alias('version_manager', 'Base\Service\VersionManager')->public();

    // ...and the subscriber that HANDLES those events, which is the same bug
    // one layer up: fixing the dispatchers above made thread.publishable fire,
    // but this class was never defined either, so the only listeners left were
    // the consuming app's own. Nothing set the thread's state.
    //
    // The visible symptom was a scheduled article that came due: the app
    // subscriber mailed every newsletter subscriber, this one never flipped
    // STATE_FUTURE to STATE_PUBLISH, and so the article stayed publishable.
    // ThreadPublishableCommand only calls poke(), which touches updatedAt and
    // relies on this subscriber for the actual transition - it even reports
    // "These are now published" - so the cron re-sent the whole newsletter to
    // every subscriber on its next run, every five minutes, indefinitely.
    // Measured on beta before the fix: two runs, two newsletters, 24 queued
    // mails for one article, and the state still STATE_FUTURE.
    //
    // Author and mention notifications live in the same handler, so they were
    // silently dead for the same reason.
    $services->set('Base\EntitySubscriber\ThreadSubscriber')
        ->autowire()
        ->autoconfigure();

    // Notifier
    $services->alias('App\Notifier\Notifier', 'Base\Notifier\Notifier');
    $services->alias('Base\Notifier\NotifierInterface', 'Base\Notifier\Notifier');
    $services->set('Base\Notifier\Notifier')
        ->parent('Base\Notifier\Abstract\BaseNotifier')
        ->public(true);

    // No BaseNotifierInterface alias here: aliasing an ABSTRACT definition is
    // unresolvable by design, and once RemoveAbstractDefinitionsPass drops the
    // target the dangling alias crashes framework.test's
    // TestServiceContainerRealRefPass ("Undefined array key"). Autowire against
    // NotifierInterface (aliased to the concrete notifier above) instead.
    $services->set('Base\Notifier\Abstract\BaseNotifier')
        ->abstract(true)
        ->args([
            service('notifier'),
            service('notifier.channel_policy'),
            service('doctrine.orm.entity_manager'),
            service('parameter_bag'),
            service('translator'),
            service('localizer'),
            service('advanced_router'),
            service('twig'),
            service('setting_bag'),
        ])
        ->bind('$debug', '%kernel.debug%');

    // LocalCache / CacheWarmers
    $cacheServices = [
        'Base\Cache\Abstract\AbstractLocalCache' => ['$cacheDir' => '%kernel.cache_dir%'],
        'Base\Cache\Warmer\SpreadsheetCacheWarmer' => ['$cacheDir' => '%kernel.cache_dir%'],
        'Base\Cache\Warmer\IconCacheWarmer' => ['base.service.icon', '$cacheDir' => '%kernel.cache_dir%'],
        'Base\Cache\Warmer\LocalizerCacheWarmer' => ['localizer', '$cacheDir' => '%kernel.cache_dir%'],
        'Base\Cache\Warmer\ThemizerCacheWarmer' => ['themizer', '$cacheDir' => '%kernel.cache_dir%'],
        'Base\Cache\Warmer\WebpackCacheWarmer' => ['parameter_bag', 'twig.webpack_renderer', 'webpack_encore.entrypoint_lookup[_default]', '$cacheDir' => '%kernel.cache_dir%', '$publicDir' => '%kernel.project_dir%/public'],
        'Base\Cache\Warmer\AttributeCacheWarmer' => ['base.attribute_reader', '$cacheDir' => '%kernel.cache_dir%'],
        'Base\Cache\Warmer\MetadataCacheWarmer' => ['base.database.metadata_manipulator', 'base.attribute_reader', '$cacheDir' => '%kernel.cache_dir%'],
    ];

    foreach ($cacheServices as $id => $args) {
        $definition = $services->set($id);
        if (str_contains($id, 'Warmer')) {
            $definition->tag('kernel.cache_warmer');
        }
        foreach ($args as $k => $v) {
            if (is_int($k)) {
                $definition->arg($k, service($v));
            } else {
                $definition->bind($k, $v);
            }
        }
        if (str_contains($id, 'Warmer')) {
            $definition->public(true);
        }
    }

    // Lazy runtime locator backing BaseTrait/BaseCommonTrait static accessors.
    // A compile-time ServiceLocator: constructing it costs nothing (it holds
    // closures), and each service is only instantiated on the FIRST actual
    // static accessor call. Seeded in BaseBundle::boot(), so the statics work
    // in every context (HTTP, console, bare kernel boots) without eagerly
    // building BaseService's full dependency graph. Keys mirror the ids
    // BaseTrait::runtimeGet() requests.
    $services->set('base.runtime', \Symfony\Component\DependencyInjection\ServiceLocator::class)
        ->public(true)
        ->tag('container.service_locator')
        ->args([[
            'base.service' => new Reference('base.service'),
            'setting_bag' => new Reference('setting_bag'),
            'doctrine' => new Reference('doctrine'),
            'base.database.metadata_manipulator' => new Reference('base.database.metadata_manipulator'),
            'security.token_storage' => new Reference('security.token_storage'),
            'request_stack' => new Reference('request_stack'),
            'base.database.entity_hydrator' => new Reference('base.database.entity_hydrator'),
            'base.service.image' => new Reference('base.service.image'),
            'obfuscator' => new Reference('obfuscator'),
            'base.service.icon' => new Reference('base.service.icon'),
            'localizer' => new Reference('localizer'),
            'advanced_router' => new Reference('advanced_router'),
            'security.firewall.map' => new Reference('security.firewall.map'),
            'twig' => new Reference('twig'),
            'base.notifier' => new Reference('base.notifier'),
            'translator' => new Reference('translator'),
            'slugger' => new Reference('slugger'),
            'trading_market' => new Reference('trading_market'),
            'parameter_bag' => new Reference('parameter_bag'),
        ]]);

    // BaseService
    $services->set('Base\Service\BaseService')
        ->public(true)
        ->args([
            service('kernel'),
            service('request_stack'),
            service('security.firewall.map'),
            service('twig'),
            service('slugger'),
            service('doctrine'),
            service('security.authorization_checker'),
            service('security.token_storage'),
            service('security.csrf.token_manager'),
            service('parameter_bag'),
            service('base.notifier'),
            service('form.factory'),
            service('localizer'),
            service('trading_market'),
            service('obfuscator'),
            service('setting_bag'),
            service('base.service.image'),
            service('base.service.icon'),
            service('translator'),
            service('advanced_router'),
            service('base.database.entity_hydrator'),
            service('base.database.metadata_manipulator'),
            new Reference('profiler', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);

    // Services inheriting from AbstractLocalCache
    $services->set('Base\Service\Localizer')
        ->parent('Base\Cache\Abstract\AbstractLocalCache')
        ->public(true)
        ->tag('twig.runtime')
        ->args([service('parameter_bag'), service('translator')]);

    $services->set('Base\Service\Themizer')
        ->parent('Base\Cache\Abstract\AbstractLocalCache')
        ->public(true)
        ->args([service('parameter_bag'), service('security.token_storage')]);

    $services->set('Base\Cache\SimpleCache')
        ->parent('Base\Cache\Abstract\AbstractLocalCache')
        ->public(true);

    // Maintenance / Launcher / Paginator / Breadgrinder / WidgetProvider
    $services->set('Base\Service\MaintenanceProvider')
        ->public(true)
        ->args([service('advanced_router'), service('setting_bag'), service('security.authorization_checker'), service('parameter_bag'), service('localizer'), service('security.token_storage')]);

    $services->set('Base\Service\Launcher')
        ->public(true)
        ->args([service('advanced_router'), service('parameter_bag'), service('setting_bag'), service('security.authorization_checker'), service('security.token_storage')]);

    $services->set('Base\Service\Paginator')
        ->public(true)
        ->args([service('advanced_router'), service('parameter_bag')]);

    $services->set('Base\Service\Breadgrinder')
        ->public(true)
        ->args([service('advanced_router'), service('translator'), service('parameter_bag')]);

    $services->set('Base\Service\WidgetProvider')
        ->public(true)
        ->tag('twig.runtime')
        ->args([service('doctrine.orm.entity_manager')]);

    // Form extensions
    $services->set('Base\Form\Extension\FormTypeTranslateExtension')->tag('form.type_extension');

    // The forms' guard: the option `guard` (trap, time, lists, captcha) and `spam_protection`
    // (the classifier), Base\Service\FormGuard. FormTypeSpamExtension is folded into it.
    $services->set('Base\Form\Extension\FormTypeGuardExtension')
        ->tag('form.type_extension')
        ->args([
            service('Base\Service\FormGuard'),
            service('spam_checker'),
            service('advanced_router'),
            service('translator')->nullOnInvalid(),
            service('request_stack')->nullOnInvalid(),
        ]);

    // Outgoing-mail trap: a hold-everything switch for the minutes after a
    // hotfix, when you want to see what the site is about to send before it
    // sends it. File-backed under var/ rather than the database or settings,
    // because it has to work in exactly the situations where you distrust the
    // application - and var/cache is wiped on deploy, var/ is not.
    $services->set('Base\Mailer\MailTrap')
        ->public(true)
        ->args(['%kernel.project_dir%/var/mail-trap']);

    $services->set('Base\Subscriber\MailTrapSubscriber')
        ->args([service('Base\Mailer\MailTrap')])
        ->tag('kernel.event_subscriber');

    // Console
    $services->set('Base\Console\Console')->public(true)->args([service('kernel')]);
    $services->set('Base\Console\Command')->public(true)->args([service('localizer'), service('translator'), service('doctrine.orm.entity_manager'), service('parameter_bag')]);

    $commandServices = [
        'Base\Console\Command\NotifierCommand',
        'Base\Console\Command\DoctrineSchemaCharsetCommand',
        'Base\Console\Command\DoctrineArrayUpgradeCommand',
        'Base\Console\Command\BaseMappingCommand',
        'Base\Console\Command\ThreadPublishableCommand',
        'Base\Console\Command\EntityDiscriminatorCommand',
        'Base\Console\Command\NotifierMailTestCommand',
        'Base\Console\Command\TimeMachineSnapshotCommand',
        'Base\Console\Command\TimeMachineSnapshotBackupCommand',
        'Base\Console\Command\TimeMachineSnapshotRestoreCommand',
        'Base\Console\Command\MailTrapCommand',
        'Base\Console\Command\TrashPurgeCommand',
    ];

    foreach ($commandServices as $command) {
        $definition = $services->set($command)
            ->parent('Base\Console\Command')
            ->tag('console.command');
        // Add specific arguments for certain commands
        if ($command === 'Base\Console\Command\EntityDiscriminatorCommand') {
            $definition->arg(0, service('base.database.metadata_manipulator'));
        }
        if ($command === 'Base\Console\Command\NotifierMailTestCommand') {
            $definition->args([service('base.notifier'), service('App\Repository\UserRepository')]);
        }
        if (str_contains($command, 'TimeMachineSnapshot')) {
            $definition->args([service('time_machine'), service('flysystem')]);
        }
        if ($command === 'Base\Console\Command\TrashPurgeCommand') {
            $definition->call('setTrashManager', [service('Base\Service\TrashManager')]);
        }
        // Setters, not args(): args() would replace the parent definition's
        // own four arguments, which every Base\Console\Command constructor
        // still expects.
        //
        // mailer.transports (the real transport chain), not the Mailer
        // service: releasing a held message through Mailer would hand it
        // straight back to the armed trap and capture it again.
        if ($command === 'Base\Console\Command\MailTrapCommand') {
            $definition
                ->call('setMailTrap', [service('Base\Mailer\MailTrap')])
                ->call('setMailerTransport', [service('mailer.transports')]);
        }
    }

    // TimeMachine service
    $services->set('Base\Service\TimeMachine')
        ->parent('Backup\Manager\Manager')
        ->public(true)
        ->args([service('flysystem'), service('doctrine'), service('parameter_bag')]);

    // Console commands
    $services->set('Base\Console\Command\UserNotificationCommand')
        ->parent('Base\Console\Command')
        ->tag('console.command');

    $services->set('Base\Console\Command\UploaderEntitiesCommand')
        ->parent('Base\Console\Command')
        ->tag('console.command');

    $services->set('Base\Console\Command\UploaderImagesCommand')
        ->parent('Base\Console\Command\UploaderEntitiesCommand')
        ->tag('console.command')
        ->args([
            new Reference('base.service.image'),
            new Reference('Base\Controller\UX\MediaController'),
        ]);

    $services->set('Base\Console\Command\UploaderImagesCropCommand')
        ->parent('Base\Console\Command\UploaderImagesCommand')
        ->tag('console.command');

    $services->set('Base\Console\Command\TranslationControllersCommand')
        ->parent('Base\Console\Command')
        ->tag('console.command');

    $services->set('Base\Console\Command\TranslationSettingsCommand')
        ->parent('Base\Console\Command')
        ->tag('console.command')
        ->args([new Reference('setting_bag')]);

    $services->set('Base\Console\Command\IconEntitiesCommand')
        ->parent('Base\Console\Command')
        ->tag('console.command')
        ->args([new Reference('base.database.metadata_manipulator')]);

    $services->set('Base\Console\Command\DoctrineDatabaseImportCommand')
        ->parent('Base\Console\Command')
        ->tag('console.command')
        ->args([
            new Reference('base.database.entity_hydrator'),
            new Reference('base.database.metadata_manipulator'),
            new Reference('base.notifier'),
        ]);
};
