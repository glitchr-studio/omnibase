<?php

namespace Base\Form\Type;

use Base\Form\Model\SecurityRegistrationModel;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormBuilderInterface;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Util\StringUtil;

class SecurityRegistrationType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return "_base_" . StringUtil::fqcnToBlockPrefix(static::class) ?: '';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SecurityRegistrationModel::class,
            // The forms' guard (Base\Service\FormGuard): a trap, the time, the lists - a disposable
            // e-mail is refused on its field - and the captcha when glitchr/omnishield has one.
            'guard' => ['action' => 'signup'],
        ]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class)
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'validation_groups' => ['new']
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => true,
                'first_options' => [
                    'validation_groups' => ["new"],
                    'attr' => [
                        "autocomplete" => "new-password"
                    ]
                ],
                'second_options' => [
                    'attr' => [
                        "autocomplete" => "new-password"
                    ]
                ],
            ]);
    }
}
