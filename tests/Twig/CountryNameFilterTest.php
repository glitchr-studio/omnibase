<?php

namespace Tests\Base\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * `|country_name` takes a country's ISO 3166-1 code and names it in the
 * page's language (or the one given). It used to read its input as a locale:
 * "JP" went through the locale's country and came out "Belgique".
 */
class CountryNameFilterTest extends KernelTestCase
{
    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
    }

    protected function tearDown(): void
    {
        if (static::$booted) {
            static::getContainer()->get('translator')->setLocale('en');
        }
        parent::tearDown();
    }

    private function render(string $template, string $pageLocale = 'en', array $context = []): string
    {
        $container = static::getContainer();
        $request = Request::create('/');
        $request->setLocale($pageLocale);
        $container->get('request_stack')->push($request);
        $container->get('translator')->setLocale($pageLocale);

        return $container->get('twig')->createTemplate($template)->render($context);
    }

    public function testACodeIsNamedInThePageLanguage(): void
    {
        $this->assertSame('Japon', $this->render("{{ 'JP'|country_name }}", 'fr'));
        $this->assertSame('Japan', $this->render("{{ 'JP'|country_name }}", 'en'));
        $this->assertSame('Belgien', $this->render("{{ 'BE'|country_name }}", 'de'));
    }

    public function testTheLanguageCanBeGiven(): void
    {
        // As omnibase/admin's country field calls it: code|country_name(app.request.locale)
        $this->assertSame('日本', $this->render("{{ 'JP'|country_name('ja') }}"));
        $this->assertSame('Japon', $this->render("{{ 'jp'|country_name('fr-FR') }}"));
        $this->assertSame('Japon', $this->render("{{ 'JPN'|country_name('fr_FR') }}"));
    }

    public function testAnUnknownOrEmptyCodeIsLeftAsItIs(): void
    {
        $this->assertSame('ZZ', $this->render("{{ 'ZZ'|country_name }}"));
        $this->assertSame('', $this->render('{{ code|country_name }}', 'en', ['code' => null]));
    }

    public function testTheCountryOfALocaleHasItsOwnFilter(): void
    {
        $this->assertSame('Belgium', $this->render("{{ 'fr-BE'|locale_country_name }}"));
    }
}
