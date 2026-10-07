<?php

namespace Base\DependencyInjection\Compiler\Pass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Base\Service\FormGuard's default captcha is glitchr/omniguard's
 * (omniguard.challenge.gateway) - a parameter that exists only when
 * omniguard's bundle is registered: read here, once every extension has
 * loaded, rather than named in the service's definition. So is
 * glitchr/ux-google's switch, for the sign-in's captcha (SignInGuard).
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

        // omniguard.challenge.unreachable, as omniguard gives it to its own constraint's validator.
        $validator = 'Omniguard\Bridge\Symfony\Validator\PassesChallengeValidator';
        if ($container->hasDefinition($validator) && \count($container->getDefinition($validator)->getArguments()) > 3) {
            $container->getDefinition('Base\Service\FormGuard')->setArgument('$acceptUnreachableChallenge', (bool) $container->getDefinition($validator)->getArgument(3));
        }

        // Where glitchr/ux-google's reCAPTCHA guards the sign-in, the sign-in asks no second captcha.
        if ($container->hasDefinition('Base\Security\SignInGuard')) {
            $google = $container->hasParameter('google.recaptcha.enable') && (bool) $container->getParameterBag()->resolveValue($container->getParameter('google.recaptcha.enable'));
            $container->getDefinition('Base\Security\SignInGuard')->setArgument('$google', $google);
        }
    }
}
