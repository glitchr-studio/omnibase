<?php

namespace Tests\Base\Form;

use Base\Form\Model\ContactModel;
use Base\Form\Type\ContactType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The notice as omnibase's form theme prints it (block base_privacy_row), in
 * a host application's kernel: translated, followed by the box when consent
 * is asked.
 */
class PrivacyTypeRenderTest extends KernelTestCase
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

    private function render(array $options): string
    {
        $container = static::getContainer();
        $form = $container->get('form.factory')->createNamed('contact', ContactType::class, new ContactModel(), $options + [
            'attachments' => false,
            'buttons' => false,
            'csrf_protection' => false,
        ]);

        return $container->get('twig')
            ->createTemplate('{{ form_row(form.privacy) }}')
            ->render(['form' => $form->createView()]);
    }

    public function testTheNoticeIsPrintedTranslated(): void
    {
        $html = $this->render(['privacy' => true]);

        $this->assertMatchesRegularExpression('/class="form-privacy[ "]/', $html);
        $this->assertStringContainsString('is used only to answer you', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    public function testTheBoxFollowsTheNotice(): void
    {
        $html = $this->render(['privacy' => true, 'privacy_consent' => true]);

        $this->assertStringContainsString('is used only to answer you', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('name="contact[privacy][accept]"', $html);
        $this->assertStringContainsString('I agree that this information is used to answer me.', $html);
    }
}
