<?php

namespace Base\DependencyInjection\Compiler\Pass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The rewritten texts (Base\Translation\OverridingTranslator) are cached in
 * the application's shared Redis pool when it declares one (cache.redis):
 * the web, the worker and cron then agree after an edit. Otherwise cache.app.
 */
final class TextOverrideCachePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->has('cache.redis') && $container->hasAlias('base.text_override.cache') && 'cache.app' === (string) $container->getAlias('base.text_override.cache')) {
            $container->setAlias('base.text_override.cache', 'cache.redis');
        }
    }
}
