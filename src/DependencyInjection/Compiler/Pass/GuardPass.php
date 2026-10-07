<?php

namespace Base\DependencyInjection\Compiler\Pass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Base\Service\FormGuard's default captcha is glitchr/omniguard's
 * (omniguard.challenge.gateway) - a parameter that exists only when
 * omniguard's bundle is registered: read here, once every extension has
 * loaded, rather than named in the service's definition.
 */
final class GuardPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('Base\Service\FormGuard')) {
            return;
        }
        $default = $container->hasParameter('omniguard.challenge.gateway') ? $container->getParameter('omniguard.challenge.gateway') : null;
        $container->getDefinition('Base\Service\FormGuard')->setArgument('$defaultChallenge', \is_string($default) ? $default : null);
    }
}
