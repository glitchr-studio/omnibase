<?php

namespace Tests\Base\Form;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;

/**
 * A field's label, help and placeholder given as keys of the form's
 * translation_domain are printed translated by omnibase's form theme.
 *
 * Every field has the "fields" domain by default, so it never inherited its
 * form's: `'label' => 'login.identifier'` in a form of the "forms" domain was
 * looked up in "fields" and the label came out empty (omnibase/mailbox's
 * compose form, 'form.subject' in "mailbox").
 */
class TranslationDomainRenderTest extends KernelTestCase
{
    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        $request = Request::create('/');
        $request->setLocale('en');
        static::getContainer()->get('request_stack')->push($request);
        static::getContainer()->get('translator')->setLocale('en');
    }

    private function view(): FormView
    {
        return static::getContainer()->get('form.factory')
            ->createNamedBuilder('sample', FormType::class, null, ['translation_domain' => 'forms', 'csrf_protection' => false])
            // Keys of the form's domain: forms.login.*
            ->add('identifier', TextType::class, ['label' => 'login.identifier', 'help' => 'login.password', 'attr' => ['placeholder' => 'login.email']])
            // A key of the field's own default domain: fields.select.placeholder
            ->add('own', TextType::class, ['label' => 'select.placeholder'])
            // The domain named in the key, as the bundles did to go around it
            ->add('named', TextType::class, ['label' => '@forms.login.submit'])
            // Not a key: printed as written
            ->add('plain', TextType::class, ['label' => 'Just some words'])
            ->add('nested', FormType::class, ['label' => false])
            ->getForm()
            ->add('untranslated', TextType::class, ['label' => 'login.identifier', 'translation_domain' => false])
            ->createView();
    }

    private function row(FormView $field): string
    {
        return static::getContainer()->get('twig')->createTemplate('{{ form_row(field) }}')->render(['field' => $field]);
    }

    public function testAKeyOfTheFormsDomainIsTranslated(): void
    {
        $view = $this->view();
        $html = $this->row($view['identifier']);

        $this->assertSame('forms', $view['identifier']->vars['translation_domain']);
        $this->assertMatchesRegularExpression('#<label[^>]*>\s*Identifier\s*</label>#', $html);
        $this->assertStringContainsString('placeholder="E-mail address"', $html);
        $this->assertStringContainsString('title="Password"', $html, 'the help, in the tooltip');
    }

    public function testAFieldWhoseTextsAreInItsOwnDomainIsLeftThere(): void
    {
        $view = $this->view();

        $this->assertSame('fields', $view['own']->vars['translation_domain']);
        $this->assertSame('fields', $view['plain']->vars['translation_domain']);
        $this->assertStringContainsString('Just some words', $this->row($view['plain']));
        $this->assertFalse($view['untranslated']->vars['translation_domain']);
    }

    public function testAKeyNamingItsDomainStillWorks(): void
    {
        $view = $this->view();

        $this->assertSame('fields', $view['named']->vars['translation_domain']);
        $this->assertMatchesRegularExpression('#<label[^>]*>\s*Sign In\s*</label>#', $this->row($view['named']));
    }
}
