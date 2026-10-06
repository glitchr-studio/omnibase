<?php

namespace Tests\Base\Translation;

use Base\Service\Translator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Base\Http\HttpTestTrait;

/**
 * The site's level of politeness (base.translator.politeness) on the ways a
 * site's texts are really asked, in the host application: omnibase's
 * translator is the "translator" service itself, so Twig's |trans, the
 * validator's messages and the service all answer at that level - and with
 * omnibase's own French catalogues, whose base texts say "tu".
 */
class PolitenessHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    private Translator $translator;

    protected function setUp(): void
    {
        $this->bootHost();
        $this->translator = static::getContainer()->get(Translator::class);
        $this->translator->setLocale('fr');
    }

    private function twig(string $template): string
    {
        return trim(static::getContainer()->get('twig')->createTemplate($template)->render());
    }

    public function testTheSettingIsTheServicesLevelAndNothingByDefault(): void
    {
        $container = static::getContainer();

        $this->assertTrue($container->hasParameter('base.translator.politeness'));
        $this->assertSame(Translator::politenessLevel($container->getParameter('base.translator.politeness')), $this->translator->getPoliteness());

        // What every other service is given as "the translator" passes through omnibase's.
        $service = $container->get('translator');
        $this->assertInstanceOf(TranslatorInterface::class, $service);
        $this->translator->setPoliteness('polite');
        $this->assertSame('Vous êtes déjà connecté !', $service->trans('login.already', [], 'notifications', 'fr'));
        $this->translator->setPoliteness(null);
        $this->assertSame('Tu es déjà connecté !', $service->trans('login.already', [], 'notifications', 'fr'));
    }

    public function testWithoutALevelOmnibasesTextsAreTheOnesOfBefore(): void
    {
        $this->translator->setPoliteness(null);

        $this->assertSame('Réinitialiser ton mot de passe', $this->translator->trans('@emails.resetPassword.subject'));
        $this->assertSame('Tu es déjà connecté !', $this->twig("{{ '@notifications.login.already'|trans }}"));
        $this->assertSame('Sécurise ton compte', $this->twig("{{ 'settings.enrolmentTitle'|trans({}, 'forms') }}"));
    }

    public function testPoliteThroughTheServiceAndThroughTwig(): void
    {
        $this->translator->setPoliteness('polite');

        // An e-mail's subject, a notification, a form's text - and a text that has no variant.
        $this->assertSame('Réinitialiser votre mot de passe', $this->translator->trans('@emails.resetPassword.subject'));
        $this->assertSame('Vous êtes déjà connecté !', $this->twig("{{ '@notifications.login.already'|trans }}"));
        $this->assertSame('Sécurisez votre compte', $this->twig("{{ 'settings.enrolmentTitle'|trans({}, 'forms') }}"));
        $this->assertSame('Vous êtes déjà connecté !', $this->twig("{% trans from 'notifications' %}login.already{% endtrans %}"), 'the tag as the filter');
        $this->assertSame('Se connecter', $this->twig("{{ '@forms.login.submit'|trans }}"), 'no variant: the base text');
        $this->assertSame('Bravo Anne, vous êtes à présent connecté.', $this->twig("{{ '@notifications.login.success.normal'|trans(['Anne']) }}"));

        // A template asks for its own level.
        $this->assertSame('Tu es déjà connecté !', $this->twig("{{ '@notifications.login.already'|trans({politeness: 'plain'}) }}"));

        // Formal: omnibase writes none, the polite ones answer.
        $this->translator->setPoliteness('formal');
        $this->assertSame('Vous êtes déjà connecté !', $this->twig("{{ '@notifications.login.already'|trans }}"));

        // English says "you": nothing to choose, the text is the one written.
        $this->translator->setPoliteness('polite');
        $english = $this->translator->trans('@notifications.login.already', [], null, 'en');
        $this->assertNotSame('', $english);
        $this->assertStringNotContainsString('Vous', $english);
    }

    public function testAValidationMessageIsAnsweredAtTheSitesLevel(): void
    {
        // A constraint's message is a key like another (here one of omnibase's that has a variant).
        $violations = fn () => static::getContainer()->get('validator')->validate(false, new IsTrue(message: '@notifications.verifyEmail.alreadySent'));

        $this->translator->setPoliteness(null);
        $this->assertSame('Merci de vérifier ton compte, tu devrais avoir reçu un e-mail pour cela !', $violations()[0]->getMessage());

        $this->translator->setPoliteness('polite');
        $this->assertSame('Merci de vérifier votre compte, vous devriez avoir reçu un e-mail pour cela !', $violations()[0]->getMessage());
    }
}
