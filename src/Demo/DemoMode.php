<?php

namespace Base\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether the kernel runs the demonstration: the `demo` environment, and no
 * other. There is no switch to turn it on elsewhere - what it lifts (the
 * staff's second factor) and what it offers (signing in as anyone declared)
 * has no place on a production site.
 */
final class DemoMode
{
    public const ENVIRONMENT = 'demo';

    public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment)
    {
    }

    public function isActive(): bool
    {
        return self::ENVIRONMENT === $this->environment;
    }
}
