<?php

namespace Tests\Base\Service;

use Base\BaseBundle;
use Base\Bundle\AbstractBaseBundle;
use Base\Service\Localizer;
use Base\Service\ParameterBagInterface;
use Base\Service\Translator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Translation\Translator as SymfonyTranslator;

/**
 * How the site addresses people (base.translator.politeness): a text is read
 * under its key followed by the level - "key._plain", "key._polite",
 * "key._formal" - when the catalogue has that variant, formal falling back
 * on polite, and as it is written otherwise. Without a level nothing
 * changes; a call that gives its own level is answered at that one.
 *
 * A real Symfony translator under omnibase's, as in TranslatorTest.
 */
class TranslatorPolitenessTest extends TestCase
{
    protected function setUp(): void
    {
        $ref = new ReflectionClass(Localizer::class);
        $ref->setStaticPropertyValue('defaultLocale', 'fr-FR');
        $ref->setStaticPropertyValue('fallbackLocales', []);
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(Localizer::class);
        $ref->setStaticPropertyValue('defaultLocale', null);
        $ref->setStaticPropertyValue('fallbackLocales', null);

        $ref = new ReflectionClass(AbstractBaseBundle::class);
        $ref->setStaticPropertyValue('_instance', null);
        $ref->setStaticPropertyValue('bundles', null);
    }

    private function symfony(string $class = SymfonyTranslator::class): SymfonyTranslator
    {
        $symfony = new $class('fr');
        $symfony->setFallbackLocales(['fr']);
        $symfony->addLoader('array', new ArrayLoader());

        // French: the base text says "tu" - it is the plain level.
        $symfony->addResource('array', [
            'login.already' => 'Tu es déjà connecté !',
            'login.already._polite' => 'Vous êtes déjà connecté !',
            'login.failed' => 'Les identifiants sont incorrects',
            'account.closed' => 'Ton compte est fermé.',
            'account.closed._polite' => 'Votre compte est fermé.',
            'account.closed._formal' => 'Nous vous informons que votre compte est fermé.',
            'hello.you' => 'Salut {name} !',
            'hello.you._polite' => 'Bonjour {name}.',
            'alias.already' => 'login.already',
            'only.french' => 'Clique ici',
            'only.french._polite' => 'Cliquez ici',
        ], 'fr', 'messages');
        $symfony->addResource('array', [
            'verify.subject' => 'Vérifie ton email',
            'verify.subject._polite' => 'Vérifiez votre adresse e-mail',
        ], 'fr', 'emails');
        $symfony->addResource('array', [
            'widget._properties.title' => 'ton titre',
            'widget._properties.title._polite' => 'votre titre',
        ], 'fr', 'entities');

        // German: one text has no polite variant, another is not written at all.
        $symfony->addResource('array', [
            'login.already' => 'Du bist bereits angemeldet!',
            'account.closed' => 'Dein Konto ist geschlossen.',
            'account.closed._polite' => 'Ihr Konto ist geschlossen.',
        ], 'de', 'messages');

        // Japanese: the base text is the polite form (です / ます); a plain and an honorific one beside it.
        $symfony->addResource('array', [
            'saved' => '保存しました。',
            'saved._plain' => '保存した。',
            'saved._formal' => '保存いたしました。',
            'login.already' => 'すでにログインしています。',
        ], 'ja', 'messages');

        return $symfony;
    }

    private function translator(?string $politeness = null, string $locale = 'fr', ?SymfonyTranslator $symfony = null): Translator
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('isDebug')->willReturn(false);

        $translator = new Translator($symfony ?? $this->symfony(), $kernel, $this->createMock(ParameterBagInterface::class), $politeness);
        $translator->setLocale($locale);

        return $translator;
    }

    public function testWithoutALevelTheTextsAreTheOnesWritten(): void
    {
        $translator = $this->translator();

        $this->assertNull($translator->getPoliteness());
        $this->assertSame('Tu es déjà connecté !', $translator->trans('login.already'));
        $this->assertSame('Ton compte est fermé.', $translator->trans('@messages.account.closed'));
        $this->assertSame('Vérifie ton email', $translator->trans('@emails.verify.subject'));
        $this->assertSame('Salut Anne !', $translator->trans('hello.you', ['name' => 'Anne']));
        $this->assertSame('Vous êtes déjà connecté !', $translator->trans('login.already._polite'), 'a variant asked by its own key is a text like another');
    }

    public function testPoliteReadsTheVariantWhenThereIsOneAndTheBaseTextOtherwise(): void
    {
        $translator = $this->translator('polite');

        $this->assertSame(Translator::POLITENESS_POLITE, $translator->getPoliteness());
        $this->assertSame('Vous êtes déjà connecté !', $translator->trans('login.already'));
        $this->assertSame('Les identifiants sont incorrects', $translator->trans('login.failed'), 'no variant: the base text');
        $this->assertSame('Votre compte est fermé.', $translator->trans('account.closed'), 'polite does not reach for the formal one');

        // However the text is asked: its domain as a tag, as an argument, in a translatable message; its parameters.
        $this->assertSame('Vérifiez votre adresse e-mail', $translator->trans('@emails.verify.subject'));
        $this->assertSame('Vérifiez votre adresse e-mail', $translator->trans('verify.subject', [], 'emails'));
        $this->assertSame('Vérifiez votre adresse e-mail', $translator->trans(new TranslatableMessage('verify.subject', [], 'emails')));
        $this->assertSame('Bonjour Anne.', $translator->trans('hello.you', ['name' => 'Anne']));
        $this->assertSame('Vous êtes déjà connecté !', $translator->transQuiet('login.already'));

        // A text that refers to another one is answered at the same level.
        $this->assertSame('Vous êtes déjà connecté !', $translator->trans('alias.already'));

        // What is not a key is left alone.
        $this->assertSame('Bonjour tout le monde.', $translator->trans('Bonjour tout le monde.'));
    }

    public function testFormalFallsBackOnPoliteThenOnTheBaseText(): void
    {
        $translator = $this->translator('formal');

        $this->assertSame('Nous vous informons que votre compte est fermé.', $translator->trans('account.closed'), 'its own variant');
        $this->assertSame('Vous êtes déjà connecté !', $translator->trans('login.already'), 'no formal variant: the polite one');
        $this->assertSame('Les identifiants sont incorrects', $translator->trans('login.failed'), 'neither: the base text');
    }

    public function testPlainReadsItsVariantOrTheBaseTextNeverThePoliteOne(): void
    {
        $this->assertSame('Tu es déjà connecté !', $this->translator('plain')->trans('login.already'), 'French: the base text is the plain one');
        $this->assertSame('保存した。', $this->translator('plain', 'ja')->trans('saved'));
    }

    public function testJapaneseHasThreeLevelsAroundAPoliteBaseText(): void
    {
        $this->assertSame('保存しました。', $this->translator(null, 'ja')->trans('saved'), 'as written: です / ます');
        $this->assertSame('保存した。', $this->translator('plain', 'ja')->trans('saved'), '常体');
        $this->assertSame('保存しました。', $this->translator('polite', 'ja')->trans('saved'), '丁寧語: no variant needed, it is the base text');
        $this->assertSame('保存いたしました。', $this->translator('formal', 'ja')->trans('saved'), '敬語');
        $this->assertSame('すでにログインしています。', $this->translator('formal', 'ja')->trans('login.already'), 'not written at that level: the base text');
    }

    public function testTheLanguageComesBeforeTheLevel(): void
    {
        $translator = $this->translator('polite', 'de');

        $this->assertSame('Ihr Konto ist geschlossen.', $translator->trans('account.closed'));
        $this->assertSame('Du bist bereits angemeldet!', $translator->trans('login.already'), 'German has no polite variant: its own text, not the French polite one');
        $this->assertSame('Cliquez ici', $translator->trans('only.french'), 'not written in German at all: the fallback language, at the level asked');
    }

    public function testACallThatGivesItsOwnLevelIsAnsweredAtThatOne(): void
    {
        $polite = $this->translator('polite');
        $this->assertSame('Tu es déjà connecté !', $polite->trans('login.already', [Translator::TRANSLATION_POLITENESS => Translator::POLITENESS_PLAIN]), 'plain asked on a polite site');
        $this->assertSame('Tu es déjà connecté !', $polite->trans('login.already', ['politeness' => 'none']), 'the text as written');
        $this->assertSame('Nous vous informons que votre compte est fermé.', $polite->trans('@messages.account.closed', ['politeness' => 'formal']));
        $this->assertSame('Salut Anne !', $polite->trans('hello.you', ['name' => 'Anne', 'politeness' => 'plain']), 'the level is not a parameter of the text');
        $this->assertSame('Tu es déjà connecté !', $polite->trans('alias.already', ['politeness' => 'plain']), 'and holds for the texts this one refers to');
        $this->assertSame('Vous êtes déjà connecté !', $polite->trans('login.already'), 'the next call is the site\'s again');

        $none = $this->translator();
        $this->assertSame('Vous êtes déjà connecté !', $none->trans('login.already', ['politeness' => 'polite']));
        $this->assertSame('Vous êtes déjà connecté !', $none->trans('login.already', ['politeness' => Translator::POLITENESS_FORMAL]), 'with its fallback');
        $this->assertSame('Tu es déjà connecté !', $none->trans('login.already'));
    }

    public function testALevelIsOneOfThree(): void
    {
        $this->assertSame(Translator::POLITENESS_POLITE, Translator::politenessLevel('polite'));
        $this->assertSame(Translator::POLITENESS_FORMAL, Translator::politenessLevel('_formal'));
        $this->assertSame(Translator::POLITENESS_PLAIN, Translator::politenessLevel(' Plain '));
        $this->assertNull(Translator::politenessLevel(null));
        $this->assertNull(Translator::politenessLevel(''));
        $this->assertSame([Translator::POLITENESS_FORMAL, Translator::POLITENESS_POLITE], Translator::politenessChain(Translator::POLITENESS_FORMAL));
        $this->assertSame([Translator::POLITENESS_PLAIN], Translator::politenessChain(Translator::POLITENESS_PLAIN));
        $this->assertSame([], Translator::politenessChain(null));

        $this->expectException(\InvalidArgumentException::class);
        $this->translator('vouvoiement');
    }

    public function testTheLevelCanBeChangedFromThenOn(): void
    {
        $translator = $this->translator();

        $this->assertSame('Vous êtes déjà connecté !', $translator->setPoliteness('polite')->trans('login.already'));
        $this->assertSame('Tu es déjà connecté !', $translator->setPoliteness(null)->trans('login.already'));
    }

    public function testAnEntitysWordsFollowTheLevelAndAnOptionOfTheCallWins(): void
    {
        new BaseBundle(); // parseClass() asks the bundle for its entity namespaces

        $this->assertSame('Ton titre', $this->translator()->transEntity('App\\Entity\\Widget', 'title'));
        $this->assertSame('Votre titre', $this->translator('polite')->transEntity('App\\Entity\\Widget', 'title'), 'the site\'s level');
        $this->assertSame('Votre titre', $this->translator()->transEntity('App\\Entity\\Widget', 'title', [Translator::POLITENESS_POLITE]), 'the call\'s');
        $this->assertSame('Votre titre', $this->translator()->transEntity('App\\Entity\\Widget', 'title', [Translator::POLITENESS_FORMAL]), 'formal: the polite one');
        $this->assertSame('Ton titre', $this->translator('polite')->transEntity('App\\Entity\\Widget', 'title', [Translator::POLITENESS_PLAIN]), 'plain asked on a polite site');
    }

    public function testATextRewrittenInTheBackOfficeWinsOverAVariantOfTheFiles(): void
    {
        // The translator below, as Base\Translation\OverridingTranslator: it knows what the team rewrote.
        $symfony = $this->symfony(RewritingTranslator::class);
        $symfony->rewritten = ['messages|fr|login.already' => 'Vous voilà déjà parmi nous.'];
        $translator = $this->translator('polite', 'fr', $symfony);

        $this->assertSame('Vous voilà déjà parmi nous.', $translator->trans('login.already'), 'the team\'s text, not the files\' polite one');
        $this->assertSame('Votre compte est fermé.', $translator->trans('account.closed'), 'what is not rewritten keeps its variant');

        $symfony->rewritten['messages|fr|login.already._polite'] = 'Vous êtes déjà des nôtres.';
        $this->assertSame('Vous êtes déjà des nôtres.', $translator->trans('login.already'), 'a rewritten variant is the variant');
        $this->assertSame('Vous voilà déjà parmi nous.', $this->translator(null, 'fr', $symfony)->trans('login.already'));
    }
}

/** A Symfony translator that keeps rewritten texts, as omnibase's OverridingTranslator does. */
class RewritingTranslator extends SymfonyTranslator
{
    /** @var array<string, string> "domain|lang|key" => text */
    public array $rewritten = [];

    public function isRewritten(string $id, ?string $domain = null, ?string $locale = null): bool
    {
        return isset($this->rewritten[($domain ?? 'messages').'|'.substr((string) ($locale ?? $this->getLocale()), 0, 2).'|'.$id]);
    }

    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->rewritten[($domain ?? 'messages').'|'.substr((string) ($locale ?? $this->getLocale()), 0, 2).'|'.$id] ?? parent::trans($id, $parameters, $domain, $locale);
    }
}
