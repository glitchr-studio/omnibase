<?php

namespace Base\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * The data-protection notice (GDPR) under a form that collects personal
 * data, and if asked the box to tick before sending - one place for every
 * site and bundle rather than a checkbox and a sentence each:
 *
 *     $builder->add('privacy', PrivacyType::class, [
 *         'notice' => '@forms.privacy.notice',      // a translation key, or false: no notice
 *         'notice_parameters' => ['url' => $privacyPageUrl],
 *         'consent' => true,                         // the box, required (IsTrue)
 *         'consent_label' => '@forms.privacy.consent',
 *     ]);
 *
 * Not mapped: nothing is stored on the model. With `consent`, the form is
 * valid only once the box `accept` is ticked. The notice is a translation
 * (trusted text: rendered as HTML, so it may link to the privacy page).
 * ContactType adds it through its `privacy` options.
 */
class PrivacyType extends AbstractType
{
    public const NOTICE = '@forms.privacy.notice';
    public const CONSENT = '@forms.privacy.consent';

    public function getBlockPrefix(): string
    {
        return 'base_privacy';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped' => false,
            'label' => false,
            'notice' => self::NOTICE,
            'notice_parameters' => [],
            'consent' => false,
            'consent_label' => self::CONSENT,
            'consent_message' => 'privacy.consent_required',
            'required' => static fn (Options $options) => $options['consent'],
            'error_bubbling' => false,
        ]);

        $resolver->setAllowedTypes('notice', ['string', 'bool', 'null']);
        $resolver->setNormalizer('notice', static fn (Options $options, $notice) => true === $notice ? self::NOTICE : ($notice ?: null));
        $resolver->setAllowedTypes('notice_parameters', 'array');
        $resolver->setAllowedTypes('consent', 'bool');
        $resolver->setAllowedTypes('consent_label', 'string');
        $resolver->setAllowedTypes('consent_message', 'string');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['consent']) {
            $builder->add('accept', CheckboxType::class, [
                'label' => $options['consent_label'],
                'required' => true,
                'constraints' => [new IsTrue(message: $options['consent_message'])],
            ]);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['notice'] = $options['notice'];
        $view->vars['notice_parameters'] = $options['notice_parameters'];
        $view->vars['consent'] = $options['consent'];
    }
}
