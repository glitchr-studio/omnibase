<?php

// Every omnibase/* plugin, on the bundles a base application runs on.
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Scheb\TwoFactorBundle\SchebTwoFactorBundle::class => ['all' => true],
    League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
    Well\Known\WellKnownBundle::class => ['all' => true],
    Nelmio\SecurityBundle\NelmioSecurityBundle::class => ['all' => true],
    Nelmio\CorsBundle\NelmioCorsBundle::class => ['all' => true],
    HWI\Bundle\OAuthBundle\HWIOAuthBundle::class => ['all' => true],
    Symfony\WebpackEncoreBundle\WebpackEncoreBundle::class => ['all' => true],
    Google\GoogleBundle::class => ['all' => true],
    ApiPlatform\Symfony\Bundle\ApiPlatformBundle::class => ['all' => true],
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    // The guard of the forms: captchas, address lists, content classifiers (glitchr/omniguard).
    Omniguard\Bridge\Symfony\OmniguardBundle::class => ['all' => true],
    Base\BaseBundle::class => ['all' => true],
    // The back office; the shop and the forge sell and deliver through it.
    Base\Admin\AdminBundle::class => ['all' => true],
    Base\Marketplace\MarketplaceBundle::class => ['all' => true],
    // Public registers the shop's checkout asks (VAT numbers, French companies).
    Omnistate\Bridge\Symfony\OmnistateBundle::class => ['all' => true],
    // The repository viewer the forge's project dashboard opens.
    Git\GitBundle::class => ['all' => true],
    Base\Forge\ForgeBundle::class => ['all' => true],
    Base\Forum\ForumBundle::class => ['all' => true],
    Base\Mailbox\MailboxBundle::class => ['all' => true],
    // What the shop pays and ships through: the sibling families' bundles.
    Omnitrade\Bridge\Symfony\OmnitradeBundle::class => ['all' => true],
    Omnibus\Bridge\Symfony\OmnibusBundle::class => ['all' => true],
    // The back office's contextual help.
    Base\Wikidoc\WikidocBundle::class => ['all' => true],
];
