<?php

namespace Base\Twig\Extension;

use Base\Service\LocalBusiness;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * schema.org's LocalBusiness in a template (docs/40-commons/local-business.md):
 *
 *     {{ local_business_jsonld() }}                        the page's <script type="application/ld+json">
 *     {{ local_business_jsonld({priceRange: '€€'}, store) }}   properties of its own, one place's hours
 *     {% set business = local_business() %}                the properties, to merge into a graph of the page's own
 */
class LocalBusinessTwigExtension extends AbstractExtension
{
    public function __construct(protected readonly LocalBusiness $business)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('local_business', fn (array $overrides = [], string|object|null $scope = null): array => $this->business->schema($overrides, $scope)),
            new TwigFunction('local_business_jsonld', fn (array $overrides = [], string|object|null $scope = null): string => $this->business->jsonLd($overrides, $scope), ['is_safe' => ['html']]),
        ];
    }
}
