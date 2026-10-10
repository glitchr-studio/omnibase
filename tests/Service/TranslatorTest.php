<?php

namespace Tests\Base\Service;

use Base\BaseBundle;
use Base\Bundle\AbstractBaseBundle;
use Base\Enum\Quadrant\Quadrant;
use Base\Enum\Quadrant\Quadrant8;
use Base\Service\Localizer;
use Base\Service\ParameterBagInterface;
use Base\Service\Translator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator as SymfonyTranslator;

/**
 * Wraps a real Symfony Translator (ArrayLoader-backed, real catalogue lookups
 * and parameter substitution) instead of mocking every catalogue interaction
 * — the same "prefer a bare real dependency" approach used for SharerTest's
 * Twig ArrayLoader.
 */
class TranslatorTest extends TestCase
{
    private function resetLocalizerStatics(): void
    {
        $ref = new ReflectionClass(Localizer::class);
        $ref->setStaticPropertyValue('defaultLocale', null);
        $ref->setStaticPropertyValue('fallbackLocales', null);
    }

    protected function setUp(): void
    {
        $this->resetLocalizerStatics();
        $ref = new ReflectionClass(Localizer::class);
        $ref->setStaticPropertyValue('defaultLocale', 'en-GB');
        $ref->setStaticPropertyValue('fallbackLocales', ['fr-FR']);
    }

    protected function tearDown(): void
    {
        $this->resetLocalizerStatics();

        $ref = new ReflectionClass(AbstractBaseBundle::class);
        $ref->setStaticPropertyValue('_instance', null);
        $ref->setStaticPropertyValue('bundles', null);
    }

    /**
     * parseClass(PARSE_NAMESPACE) reads Base\BaseBundle::getInstance() to
     * build its bundle-specific entity-namespace replacements. In a real
     * app Symfony's kernel always constructs BaseBundle directly during
     * bundle registration, seeding the singleton before anything calls
     * getInstance(). Nothing does that in a bare test process — and
     * getInstance()'s own auto-construct fallback is "new self()" inside
     * SingletonTrait, which — since traits don't carry their own late
     * static binding — resolves against AbstractBaseBundle (where the
     * trait is used), not BaseBundle, and fatals trying to instantiate an
     * abstract class. Seed it directly first, exactly like the kernel
     * would, to sidestep that pre-existing gap rather than trip over it.
     */
    private function seedBaseBundleSingleton(): void
    {
        new BaseBundle();
    }

    /**
     * Resources are registered under the bare language code ("en", not
     * "en_GB") — matching how this bundle's own translation files are
     * actually named (messages+intl-icu.en.yaml, no country code). This
     * matters: Symfony's own region -> language fallback (e.g. "en_GB" ->
     * "en") only bridges cleanly when the real catalogue lives at the bare
     * level, which is what let us find a genuine bug — see
     * testProductionFallbackUsesTheDefaultLocaleTranslation's docblock.
     */
    private function makeTranslator(bool $isDebug = true): Translator
    {
        $symfony = new SymfonyTranslator('en');
        $symfony->addLoader('array', new ArrayLoader());
        $symfony->addResource('array', [
            'hello' => 'Hello!',
            'foo.bar' => 'FooBar value',
            'nested' => '{foo.bar}',
            'greet.hello' => 'Hi {name}!',
            'alias.key' => 'foo.bar',
            'base.years' => 'year(s)',
            'base.months' => 'month(s)',
            'base.days' => 'day(s)',
            'base.hours' => 'hour(s)',
            'base.minutes' => 'minute(s)',
            'base.seconds' => 'second(s)',
        ], 'en', 'messages');
        $symfony->addResource('array', [
            'widget.title' => 'A Widget',
            'widget._properties.title' => 'the title',
        ], 'en', 'entities');
        $symfony->addResource('array', [
            'quadrant.north' => 'North',
        ], 'en', 'enums');
        $symfony->addResource('array', [
            'registers.national-archives-uk.name' => 'The National Archives',
            'sign-in.forgotten-password' => 'Forgotten password',
        ], 'en', 'messages');

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('isDebug')->willReturn($isDebug);

        $translator = new Translator($symfony, $kernel, $this->createMock(ParameterBagInterface::class));
        $translator->setLocale('en');

        return $translator;
    }

    public function testLocaleAndFallbackLocalesDelegateToTheWrappedTranslator(): void
    {
        $translator = $this->makeTranslator();

        $this->assertSame('en', $translator->getLocale());
        $translator->setLocale('fr');
        $this->assertSame('fr', $translator->getLocale());

        $this->assertSame([], $translator->getFallbackLocales());
        $this->assertTrue($translator->getCatalogue('en')->has('hello'));
    }

    public function testTransExists(): void
    {
        $translator = $this->makeTranslator();

        $this->assertTrue($translator->transExists('hello'));
        $this->assertTrue($translator->transExists('@entities.widget.title'));
        $this->assertFalse($translator->transExists('missing.key'));
    }

    public function testTransQuietReturnsNullWhenMissing(): void
    {
        $translator = $this->makeTranslator();

        $this->assertSame('Hello!', $translator->transQuiet('hello'));
        $this->assertNull($translator->transQuiet('missing.key'));
    }

    public function testTransLooksUpAPlainNonDotId(): void
    {
        $this->assertSame('Hello!', $this->makeTranslator()->trans('hello'));
    }

    public function testTransLooksUpADotNotationId(): void
    {
        $this->assertSame('FooBar value', $this->makeTranslator()->trans('foo.bar'));
    }

    public function testTransResolvesAnAtDomainTag(): void
    {
        $this->assertSame('A Widget', $this->makeTranslator()->trans('@entities.widget.title'));
    }

    /**
     * A key whose segments hold hyphens (a register named national-archives-uk): its
     * @domain is read like any other, where the id was printed as it was. What is no
     * key stays as it is: a sentence that starts with @, an e-mail address, a key
     * whose segment starts or ends with a hyphen.
     */
    public function testAnAtDomainKeyWithHyphensIsTranslated(): void
    {
        $translator = $this->makeTranslator();
        $this->assertSame('The National Archives', $translator->trans('@messages.registers.national-archives-uk.name'));
        $this->assertSame('Forgotten password', $translator->trans('@messages.sign-in.forgotten-password'));
        $this->assertSame('The National Archives', $translator->trans('registers.national-archives-uk.name'));

        $this->assertSame('@everyone, the archives are open.', $translator->trans('@everyone, the archives are open.'));
        $this->assertSame('jean-pierre@example.org', $translator->trans('jean-pierre@example.org'));
        $this->assertSame('@messages.registers.-uk.name', $translator->trans('@messages.registers.-uk.name'));
        $this->assertSame('A Widget', $translator->trans('@entities.widget.title'), 'a key without hyphens, as before');
    }

    public function testTransSubstitutesBracketedParameters(): void
    {
        $this->assertSame('Hi World!', $this->makeTranslator()->trans('greet.hello', ['name' => 'World']));
    }

    /**
     * A dot-id whose catalogue value is itself the name of another
     * translatable key: the "nested translations" loop keeps resolving
     * until transExists() stops matching.
     */
    public function testTransFollowsAChainOfNestedTranslationKeys(): void
    {
        $this->assertSame('FooBar value', $this->makeTranslator()->trans('alias.key'));
    }

    /**
     * Current behavior: the "recursive" handling in trans() only re-resolves
     * dot/bracket structure found in the requested ID itself, not references
     * embedded inside an already-resolved translation VALUE. "nested"'s
     * catalogue value is the literal string "{foo.bar}", which is returned
     * as-is — it is not interpolated a second time.
     */
    public function testTransDoesNotInterpolateReferencesEmbeddedInAValue(): void
    {
        $this->assertSame('{foo.bar}', $this->makeTranslator()->trans('nested'));
    }

    /**
     * Debug mode no longer gets a raw-key carve-out.
     *
     * This test used to assert that a missing dot-id came back as
     * "missing.key" while developing, and it stopped being true at
     * 6d7edd0d9 ("Translator: fall back to the default language in debug
     * too"): a back-office switched to a language with partial catalogues
     * showed raw keys on every button and title, on beta, to the people
     * reviewing it there. The commit deliberately made debug behave like
     * production and did not update this file, which is why the suite went
     * red. Rewritten to state the behaviour that was chosen rather than the
     * one it replaced.
     *
     * A PLAIN id (no dot) is unaffected and still comes back as itself -
     * those never took the dot-id path in the first place, so "fall back
     * like production" was never a change for them.
     */
    public function testMissingDotIdsAreEmptyInDebugModeToo(): void
    {
        $translator = $this->makeTranslator(isDebug: true);

        $this->assertSame('', $translator->trans('missing.key'));
        $this->assertSame('unknown_plain_id', $translator->trans('unknown_plain_id'));
    }

    /**
     * Current behavior, found by direct experimentation rather than reading
     * the code alone: a totally-missing dot-id comes back as "" rather than
     * the raw id — even when no locale override was requested. That's
     * because the "fall back to the default locale" check compares the
     * (nullable) $locale parameter directly against
     * Localizer::getDefaultLocale(), and null is never == a non-empty
     * locale string — so the fallback branch fires on every call that
     * doesn't pass an explicit locale, not just genuine locale mismatches.
     * Documented here, not fixed: changing this would alter what every
     * already-live page shows for any missing translation.
     *
     * Kept alongside the debug-mode case above now that the two agree: this
     * one is the guard on the non-debug path specifically, so a future
     * change that reintroduces a split gets caught on both sides.
     */
    public function testMissingTranslationsBecomeEmptyStringOutsideDebugMode(): void
    {
        $translator = $this->makeTranslator(isDebug: false);

        $this->assertSame('', $translator->trans('missing.key'));
    }

    /**
     * Outside debug mode, a dot-id missing from an explicitly-requested
     * locale falls back to the same key's translation in the default
     * locale rather than staying untranslated.
     */
    public function testProductionFallbackUsesTheDefaultLocaleTranslation(): void
    {
        $translator = $this->makeTranslator(isDebug: false);

        // "fr" has no catalogue at all, so this can only resolve through the
        // production fallback to the default locale ("en", bare — matching
        // Localizer::getDefaultLocale()'s "en-GB" normalized down to its
        // language fallback).
        $this->assertSame('FooBar value', $translator->trans('foo.bar', [], null, 'fr-FR'));
    }

    /**
     * Regression test for a real infinite loop found while designing the
     * test above: transExists() normalizes its locale to underscore form
     * before checking the catalogue, but trans()'s own lookups used to pass
     * the locale through unnormalized. A translation registered ONLY under
     * a region-specific key (unlike this bundle's real catalogues, which are
     * all bare-language) exposed the gap: transExists("en-GB") would find it
     * (it normalizes to "en_GB" first) while the direct lookup wouldn't (no
     * "en-GB" catalogue exists, and Symfony's own region fallback doesn't
     * bridge dash<->underscore siblings of the SAME region, only region ->
     * bare-language parents) — so $trans never changed and the "nested
     * translations" while loop spun forever. Fixed by normalizing once,
     * consistently, before any catalogue lookup.
     */
    public function testProductionFallbackDoesNotHangOnARegionSpecificOnlyTranslation(): void
    {
        $symfony = new SymfonyTranslator('en_GB');
        $symfony->addLoader('array', new ArrayLoader());
        $symfony->addResource('array', ['foo.bar' => 'FooBar value'], 'en_GB', 'messages');

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('isDebug')->willReturn(false);

        $translator = new Translator($symfony, $kernel, $this->createMock(ParameterBagInterface::class));
        $translator->setLocale('en_GB');

        $this->assertSame('FooBar value', $translator->trans('foo.bar', [], null, 'fr-FR'));
    }

    public function testTransRouteWrapsTheControllersDomain(): void
    {
        $translator = $this->makeTranslator(isDebug: true);

        // transRoute() builds "@controllers.foo.title" and hands it to
        // trans(). No such translation is defined, and a missing dot-id now
        // resolves to "" in debug as well as in production (see
        // testMissingDotIdsAreEmptyInDebugModeToo) - this used to assert the
        // unresolved key, back when debug had its own raw-key carve-out.
        //
        // transRouteExists() is what callers should branch on, and it still
        // says false - which is the half of this test that actually protects
        // anything, since "" is also what a defined-but-empty translation
        // would give.
        $this->assertSame('', $translator->transRoute('foo'));
        $this->assertFalse($translator->transRouteExists('foo'));
    }

    public function testTransTimeFormatsADuration(): void
    {
        $translator = $this->makeTranslator();

        // 90061s = 1 day, 1 hour, 1 minute, 1 second (no years/months).
        $this->assertSame('year(s)  month(s) 1 day(s) 1 hour(s) 1 minute(s) 1 second(s)', $translator->transTime(90061));
    }

    public function testTransTimeOfZeroIsEmpty(): void
    {
        $this->assertSame('', $this->makeTranslator()->transTime(0));
    }

    public function testParseClassExtendsWalksTheClassHierarchy(): void
    {
        $translator = $this->makeTranslator();

        $this->assertSame('quadrant.enum_type.type', $translator->parseClass(Quadrant::class, Translator::PARSE_EXTENDS));
        $this->assertSame('quadrant8.quadrant.enum_type.type', $translator->parseClass(Quadrant8::class, Translator::PARSE_EXTENDS));
    }

    public function testTransEnumTranslatesAPermittedValue(): void
    {
        $translator = $this->makeTranslator();

        $this->assertSame('North', $translator->transEnum(Quadrant::N, Quadrant::class));
    }

    public function testTransEnumExists(): void
    {
        $translator = $this->makeTranslator();

        $this->assertTrue($translator->transEnumExists(Quadrant::N, Quadrant::class));
        $this->assertFalse($translator->transEnumExists('bogus', Quadrant::class));
    }

    /**
     * parseClass()'s default PARSE_NAMESPACE branch also strips
     * bundle-specific entity namespaces (derived at runtime from
     * Base\BaseBundle::getInstance()->getBundles(), a reflection scan over
     * whatever Base\*Bundle classes happen to be autoloaded in THIS
     * process) — deliberately not exercised here, since it's dependent on
     * autoloading order across the whole test run. Every case below only
     * uses the three fixed, always-present prefixes (Proxies\__CG__\,
     * App\Entity\, Base\Entity\) or no matching prefix at all, so the
     * bundle-derived part of the replacement table never matches and can't
     * affect the result.
     */
    public function testParseClassNamespaceStripsKnownEntityPrefixes(): void
    {
        $this->seedBaseBundleSingleton();
        $translator = $this->makeTranslator();

        $this->assertSame('widget', $translator->parseClass('App\\Entity\\Widget'));
        $this->assertSame('widget', $translator->parseClass('Base\\Entity\\Widget'));
        $this->assertSame('widget', $translator->parseClass('Proxies\\__CG__\\App\\Entity\\Widget'));
        $this->assertSame('category.category_widget', $translator->parseClass('App\\Entity\\Category\\CategoryWidget'));
    }

    public function testParseClassNamespaceKeepsNonEntityNamespacesIntact(): void
    {
        $this->seedBaseBundleSingleton();
        $translator = $this->makeTranslator();

        $this->assertSame('app.service.mailer_service', $translator->parseClass('App\\Service\\MailerService'));
    }

    public function testTransEntityStripsTheNamespaceAndSnakeCasesTheProperty(): void
    {
        $this->seedBaseBundleSingleton();
        $translator = $this->makeTranslator();

        $this->assertSame('The title', $translator->transEntity('App\\Entity\\Widget', 'title'));
    }

    public function testTransEntityExists(): void
    {
        $this->seedBaseBundleSingleton();
        $translator = $this->makeTranslator();

        $this->assertTrue($translator->transEntityExists('App\\Entity\\Widget', 'title'));
        $this->assertFalse($translator->transEntityExists('App\\Entity\\Widget', 'bogus_property'));
    }

    /**
     * transPerms() tries every ordering of the requested politeness/
     * genderness/noun suffixes (get_permutations(), not a single fixed
     * concatenation order) before giving up — registering the translation
     * under one specific combined suffix and requesting the options in a
     * different declared order still resolves it.
     */
    public function testTransEntityCombinesMultiplePermutationOptions(): void
    {
        $this->seedBaseBundleSingleton();

        $symfony = new SymfonyTranslator('en');
        $symfony->addLoader('array', new ArrayLoader());
        $symfony->addResource('array', [
            'ghost._properties.name._feminine._plural' => 'les fantômes',
        ], 'en', 'entities');

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('isDebug')->willReturn(true);

        $translator = new Translator($symfony, $kernel, $this->createMock(ParameterBagInterface::class));
        $translator->setLocale('en');

        $this->assertSame(
            'Les fantômes',
            $translator->transEntity('App\\Entity\\Ghost', 'name', [Translator::GENDERNESS_FEMININE, Translator::NOUN_PLURAL])
        );
    }

    /**
     * transPerms() only throws when literally no option was requested at
     * all (an empty array — not even the NOUN_SINGULAR default every public
     * entry point normally supplies) and nothing matched any permutation.
     */
    public function testTransEntityThrowsWhenNoTranslationFoundAndNoOptionsGiven(): void
    {
        $this->seedBaseBundleSingleton();
        $translator = $this->makeTranslator();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/No translation found/');

        $translator->transEntity('App\\Entity\\Ghost', 'missing', []);
    }
}
