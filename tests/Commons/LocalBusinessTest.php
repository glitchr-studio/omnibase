<?php

namespace Tests\Base\Commons;

use Base\Entity\Hours\SpecialDay;
use Base\Service\LocalBusiness;
use Base\Service\OpeningHours;
use Base\Service\SettingBagInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class LocalBusinessTest extends TestCase
{
    private const CONFIG = [
        'type' => 'LocalBusiness', 'name' => null, 'url' => null, 'telephone' => '03 00 00 00 00', 'email' => null,
        'image' => 'assets/ico/logo.png', 'price_range' => null,
        'address' => ['street' => '1 rue Lindebuckel', 'postal_code' => '67300', 'locality' => 'Schiltigheim', 'region' => null, 'country' => 'FR'],
        'geo' => ['latitude' => 48.6, 'longitude' => 7.75],
        'same_as' => ['https://www.facebook.com/panostrasbourg/'],
        'area_served' => ['Strasbourg'],
        'extra' => ['parentOrganization' => ['@type' => 'Organization', 'name' => 'PANO']],
    ];

    /** @param array<string, mixed> $settings */
    private function business(array $settings = [], array $config = self::CONFIG): LocalBusiness
    {
        $bag = $this->createStub(SettingBagInterface::class);
        $bag->method('getScalar')->willReturnCallback(static fn ($path) => $settings[$path] ?? null);

        $requests = new RequestStack();
        $requests->push(Request::create('https://soifdepub.test/realisations'));

        $hours = (new OpeningHours(null, null, 'Europe/Paris', [1 => [['09:00', '12:00'], ['13:00', '17:00']]]))
            ->withSpecialDays([new SpecialDay(new \DateTimeImmutable('2026-12-25'))]);

        return new LocalBusiness($hours, $bag, $requests, $config);
    }

    public function testTheSettingsTheConfigurationAndTheHours(): void
    {
        $schema = $this->business(['base.settings.title' => 'PANO Strasbourg', 'base.settings.slogan' => 'Les experts en signalétique', 'base.settings.phone' => '03 55 40 13 81'])->schema();

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('LocalBusiness', $schema['@type']);
        $this->assertSame('https://soifdepub.test/#business', $schema['@id']);
        $this->assertSame('PANO Strasbourg', $schema['name']);
        $this->assertSame('Les experts en signalétique', $schema['slogan']);
        $this->assertSame('https://soifdepub.test/', $schema['url']);
        $this->assertSame('03 55 40 13 81', $schema['telephone'], 'the back office wins over the configuration');
        $this->assertSame('https://soifdepub.test/assets/ico/logo.png', $schema['image']);
        $this->assertSame(['@type' => 'PostalAddress', 'streetAddress' => '1 rue Lindebuckel', 'postalCode' => '67300', 'addressLocality' => 'Schiltigheim', 'addressCountry' => 'FR'], $schema['address']);
        $this->assertSame(['@type' => 'GeoCoordinates', 'latitude' => 48.6, 'longitude' => 7.75], $schema['geo']);
        $this->assertSame(['https://www.facebook.com/panostrasbourg/'], $schema['sameAs']);
        $this->assertSame('PANO', $schema['parentOrganization']['name']);

        $this->assertSame(['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Monday', 'opens' => '09:00', 'closes' => '12:00'], $schema['openingHoursSpecification'][0]);
        $this->assertSame('2026-12-25', $schema['specialOpeningHoursSpecification'][0]['validFrom']);
    }

    public function testWhatNobodyGivesIsLeftOutAndTheCallerWins(): void
    {
        $bare = ['type' => 'Restaurant'] + array_fill_keys(['name', 'url', 'telephone', 'email', 'image', 'price_range'], null)
            + ['address' => [], 'geo' => [], 'same_as' => [], 'area_served' => [], 'extra' => []];
        $schema = $this->business([], $bare)->schema(['servesCuisine' => 'Japanese', 'url' => null]);

        $this->assertSame('Restaurant', $schema['@type']);
        $this->assertSame('Japanese', $schema['servesCuisine']);
        foreach (['name', 'telephone', 'email', 'image', 'address', 'geo', 'sameAs', 'areaServed', 'priceRange', 'url'] as $absent) {
            $this->assertArrayNotHasKey($absent, $schema);
        }
    }

    public function testTheScriptCannotBeBrokenOutOf(): void
    {
        $html = $this->business(['base.settings.title' => 'A </script><b> & Co'])->jsonLd();

        $this->assertStringStartsWith('<script type="application/ld+json">{', $html);
        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertSame('A </script><b> & Co', json_decode(substr($html, 35, -9), true)['name']);
        $this->assertStringContainsString('"https://schema.org"', $html, 'addresses are readable');
    }
}
