<?php

namespace Base\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * schema.org's LocalBusiness for the page's JSON-LD: who the business is,
 * where, how to reach it, when it is open. Search engines read the address,
 * the phone and the hours (the days off too) from there.
 *
 * Each property is taken, in this order, from what the caller gives, from the
 * back office's settings (base.settings.title, .slogan, .phone,
 * .address.*), from the configuration (base.local_business.*). The hours are
 * Base\Service\OpeningHours::schema(), for the whole site or for one place.
 * A property nobody gives is left out.
 *
 * In a template: {{ local_business_jsonld() }} (docs/40-commons/local-business.md).
 */
class LocalBusiness
{
    public const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP;

    /** @param array<string, mixed> $config base.local_business */
    public function __construct(
        protected readonly OpeningHours $hours,
        protected readonly ?SettingBagInterface $settings = null,
        protected readonly ?RequestStack $requests = null,
        #[Autowire('%base.local_business%')] protected readonly array $config = [],
    ) {
    }

    /**
     * @param array<string, mixed>  $overrides schema.org properties that win over everything (null removes one)
     * @param string|object|null    $scope     one place's hours (OpeningHours::for()); null: the site's
     *
     * @return array<string, mixed>
     */
    public function schema(array $overrides = [], string|object|null $scope = null): array
    {
        $url = $this->config['url'] ?? $this->home();
        $address = array_filter([
            'streetAddress' => $this->setting('address.street') ?? $this->config['address']['street'] ?? null,
            'postalCode' => $this->setting('address.postal_code') ?? $this->config['address']['postal_code'] ?? null,
            'addressLocality' => $this->setting('address.locality') ?? $this->config['address']['locality'] ?? null,
            'addressRegion' => $this->setting('address.region') ?? $this->config['address']['region'] ?? null,
            'addressCountry' => $this->setting('address.country') ?? $this->config['address']['country'] ?? null,
        ], static fn ($value) => null !== $value && '' !== $value);

        $latitude = $this->config['geo']['latitude'] ?? null;
        $longitude = $this->config['geo']['longitude'] ?? null;

        $hours = null === $scope ? $this->hours : $this->hours->for($scope);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $this->config['type'] ?? 'LocalBusiness',
            '@id' => $url ? rtrim($url, '/').'/#business' : null,
            'name' => $this->config['name'] ?? $this->setting('title'),
            'slogan' => $this->setting('slogan'),
            'url' => $url,
            'telephone' => $this->setting('phone') ?? $this->config['telephone'] ?? null,
            'email' => $this->config['email'] ?? null,
            'image' => $this->absolute($this->config['image'] ?? null),
            'priceRange' => $this->config['price_range'] ?? null,
            'address' => $address ? ['@type' => 'PostalAddress'] + $address : null,
            'geo' => null !== $latitude && null !== $longitude ? ['@type' => 'GeoCoordinates', 'latitude' => $latitude, 'longitude' => $longitude] : null,
            'areaServed' => $this->config['area_served'] ?? [],
            'sameAs' => array_values(array_filter($this->config['same_as'] ?? [])),
        ] + $hours->schema();

        $schema = array_replace($schema, \is_array($this->config['extra'] ?? null) ? $this->config['extra'] : [], $overrides);

        return array_filter($schema, static fn ($value) => null !== $value && '' !== $value && [] !== $value);
    }

    /** The <script type="application/ld+json"> of the page. */
    public function jsonLd(array $overrides = [], string|object|null $scope = null): string
    {
        return '<script type="application/ld+json">'.json_encode($this->schema($overrides, $scope), self::JSON_FLAGS).'</script>';
    }

    /** A setting of the back office (base.settings.<path>), when it is a text someone filled in. */
    protected function setting(string $path): ?string
    {
        try {
            $value = $this->settings?->getScalar('base.settings.'.$path);
        } catch (\Throwable) {
            return null; // no settings table yet
        }

        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    protected function home(): ?string
    {
        $request = $this->requests?->getMainRequest();

        return $request ? $request->getSchemeAndHttpHost().$request->getBaseUrl().'/' : null;
    }

    /** A path of the site as a whole address; an address is left as it is. */
    protected function absolute(?string $path): ?string
    {
        if (null === $path || '' === $path || preg_match('#^(https?:)?//#i', $path)) {
            return $path ?: null;
        }
        $request = $this->requests?->getMainRequest();

        return $request ? $request->getSchemeAndHttpHost().$request->getBasePath().'/'.ltrim($path, '/') : $path;
    }
}
