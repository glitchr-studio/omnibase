<?php

namespace Tests\Base\Form;

use Base\Form\Model\ContactModel;
use Base\Form\Type\ContactType;
use Base\Form\Type\PrivacyType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * The data-protection notice and its optional box, as ContactType adds them
 * (privacy, privacy_consent, privacy_parameters) and as any form can
 * (PrivacyType): off by default, a notice alone, the box required.
 */
class PrivacyTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator())];
    }

    private function contact(array $options = []): \Symfony\Component\Form\FormInterface
    {
        return $this->factory->create(ContactType::class, new ContactModel(), $options + [
            'attachments' => false,
            'buttons' => false,
        ]);
    }

    private function message(): array
    {
        return ['name' => 'Ada', 'email' => 'ada@example.org', 'subject' => '', 'message' => 'Hello there.'];
    }

    public function testOffByDefault(): void
    {
        $this->assertFalse($this->contact()->has('privacy'));
    }

    public function testTheNoticeAloneAsksForNothing(): void
    {
        $form = $this->contact(['privacy' => true]);

        $this->assertTrue($form->has('privacy'));
        $this->assertFalse($form->get('privacy')->has('accept'));
        $this->assertFalse($form->get('privacy')->getConfig()->getMapped());

        $view = $form->createView();
        $this->assertSame(PrivacyType::NOTICE, $view['privacy']->vars['notice']);
        $this->assertFalse($view['privacy']->vars['consent']);
        $this->assertContains('base_privacy', $view['privacy']->vars['block_prefixes']);

        $form->submit($this->message());
        $this->assertTrue($form->isValid());
    }

    public function testASiteNoticeWithItsParameters(): void
    {
        $view = $this->contact([
            'privacy' => '@messages.contact.privacy',
            'privacy_parameters' => ['url' => '/privacy'],
        ])->createView();

        $this->assertSame('@messages.contact.privacy', $view['privacy']->vars['notice']);
        $this->assertSame(['url' => '/privacy'], $view['privacy']->vars['notice_parameters']);
    }

    public function testTheBoxMustBeTickedBeforeSending(): void
    {
        $form = $this->contact(['privacy' => true, 'privacy_consent' => true]);
        $this->assertTrue($form->get('privacy')->has('accept'));
        $this->assertTrue($form->get('privacy')->get('accept')->isRequired());

        $form->submit($this->message());
        $this->assertFalse($form->isValid());
        $errors = $form->get('privacy')->get('accept')->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('privacy.consent_required', $errors[0]->getMessageTemplate());

        $ticked = $this->contact(['privacy' => true, 'privacy_consent' => true]);
        $ticked->submit($this->message() + ['privacy' => ['accept' => '1']]);
        $this->assertTrue($ticked->isValid());
        $this->assertTrue($ticked->get('privacy')->get('accept')->getData());
        // Not mapped: the model is the message, nothing more.
        $this->assertSame('Ada', $ticked->getData()->name);
    }

    public function testTheBoxWithoutANotice(): void
    {
        $view = $this->contact(['privacy_consent' => true])->createView();

        $this->assertNull($view['privacy']->vars['notice']);
        $this->assertTrue($view['privacy']->vars['consent']);
        $this->assertSame(PrivacyType::CONSENT, $view['privacy']['accept']->vars['label']);
    }

    public function testAnyFormCanCarryIt(): void
    {
        $form = $this->factory->createBuilder()
            ->add('privacy', PrivacyType::class, ['consent' => true, 'consent_label' => '@shop.terms', 'notice' => false])
            ->getForm();

        $view = $form->createView();
        $this->assertNull($view['privacy']->vars['notice']);
        $this->assertSame('@shop.terms', $view['privacy']['accept']->vars['label']);

        $form->submit(['privacy' => ['accept' => '1']]);
        $this->assertTrue($form->isValid());
    }
}
