<?php

namespace Base\Form\Type;

use Symfony\Component\Form\FormBuilderInterface;

use Symfony\Component\Form\Extension\Core\Type\TextType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SecurityResetPasswordType extends AbstractType
{
    /**
     * The forms' guard (Base\Service\FormGuard): a trap, the time, the captcha - no list: the page
     * answers the same for every address, and asks nothing of the address but where to write.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['guard' => ['action' => 'reset_password', 'reputation' => false]]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', TextType::class, [
                'validation_groups' => ["new"],
                'mapped' => false,
            ]);
    }
}
