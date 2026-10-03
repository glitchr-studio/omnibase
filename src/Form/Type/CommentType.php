<?php

namespace Base\Form\Type;

use Base\Form\Model\CommentModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The comment form - small: a name, a message, an e-mail if one wants to be
 * answered - on Base\Form\Model\CommentModel, which omnibase's
 * FormTypeSpamExtension sends to Akismet. On top of it, for the robots that
 * never reach Akismet: a trap field (`url`, hidden from people, filled by
 * robots), the time the form was opened (`opened`), and - when
 * glitchr/ux-google is installed and `recaptcha` is on - Google reCAPTCHA
 * v3. Base\Service\CommentGuard reads the trap and the time.
 *
 * Options: signed_in (no name nor e-mail asked), recaptcha, max_length,
 * placeholders (name, email, content: translation keys), trap_class.
 */
class CommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $placeholders = $options['placeholders'] + [
            'name' => '@forms.comment.fields.name',
            'email' => '@forms.comment.fields.email',
            'content' => '@forms.comment.fields.content',
        ];

        if (!$options['signed_in']) {
            $builder
                ->add('name', TextType::class, ['label' => false, 'attr' => ['placeholder' => $placeholders['name'], 'autocomplete' => 'name', 'maxlength' => 120]])
                ->add('email', EmailType::class, ['label' => false, 'required' => false, 'attr' => ['placeholder' => $placeholders['email'], 'autocomplete' => 'email', 'maxlength' => 180]]);
        }
        $builder
            ->add('content', TextareaType::class, ['label' => false, 'attr' => ['placeholder' => $placeholders['content'], 'rows' => 3, 'maxlength' => $options['max_length']]])
            ->add('parent', HiddenType::class, ['mapped' => false, 'required' => false])
            // The trap: off-screen for people (and for screen readers), filled by robots.
            ->add('url', TextType::class, ['mapped' => false, 'required' => false, 'label' => false,
                'row_attr' => ['class' => $options['trap_class'], 'aria-hidden' => 'true', 'style' => 'position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']])
            ->add('opened', HiddenType::class, ['mapped' => false, 'data' => (string) time()]);

        if ($options['recaptcha'] && class_exists(\Google\Form\Type\ReCaptchaV3Type::class)) {
            $builder->add('captcha', \Google\Form\Type\ReCaptchaV3Type::class, ['mapped' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CommentModel::class,
            'signed_in' => false,
            'recaptcha' => false,
            'max_length' => 4000,
            'placeholders' => [],
            'trap_class' => 'base-trap',
            'validation_groups' => ['Default'],
        ]);
        $resolver->setAllowedTypes('signed_in', 'bool');
        $resolver->setAllowedTypes('recaptcha', 'bool');
        $resolver->setAllowedTypes('max_length', 'int');
        $resolver->setAllowedTypes('placeholders', 'array');
        $resolver->setAllowedTypes('trap_class', 'string');
    }
}
