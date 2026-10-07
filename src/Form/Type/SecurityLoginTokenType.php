<?php

namespace Base\Form\Type;

use Base\Form\Common\AbstractType;
use Base\Form\Model\SecurityLoginTokenModel;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Util\StringUtil;

class SecurityLoginTokenType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return "_base_" . StringUtil::fqcnToBlockPrefix(static::class) ?: '';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SecurityLoginTokenModel::class,
            'allow_login_token' => false,
            // The forms' guard (Base\Service\FormGuard): a trap, the time, the captcha - no list, as the
            // forgotten password: the same answer for every address.
            'guard' => ['action' => 'login_link', 'reputation' => false],
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars["allow_login_token"] = $options["allow_login_token"];  
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', TextType::class, [
                'attr' => [
                    'id' => "inputEmail",  // used in Symfony kernel
                    'value' => $options["email"] ?? ""
                ]
            ]);
    }
}
