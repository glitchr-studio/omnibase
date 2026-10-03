<?php

namespace Base\Form\Type;

use Base\Field\Type\FileType;
use Base\Field\Type\SubmitType;
use Base\Form\Model\ContactModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ResetType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The contact form, usable as it is by every site:
 *
 *     $form = $this->createForm(ContactType::class, $model = new ContactModel(), [
 *         'phone' => true, 'subject' => false, 'attachments' => false, 'buttons' => false, 'trap' => true,
 *     ]);
 *     if ($form->isSubmitted() && $form->isValid() && !$model->isRobot()) { ...send... }
 *
 * Options: phone (off), subject (on), attachments (on), buttons - the
 * submit and reset buttons (on), trap - the `website` field robots fill,
 * off-screen for people (off). Labels: the "forms" domain's contact.fields.*,
 * or a label given to form_row().
 */
class ContactType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return "_base_" . StringUtil::fqcnToBlockPrefix(static::class) ?: '';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactModel::class,
            'phone' => false,
            'subject' => true,
            'attachments' => true,
            'buttons' => true,
            'trap' => false,
        ]);
        foreach (['phone', 'subject', 'attachments', 'buttons', 'trap'] as $option) {
            $resolver->setAllowedTypes($option, 'bool');
        }
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['attr' => ['autocomplete' => 'name']]);
        $builder->add('email', EmailType::class, ['attr' => ['autocomplete' => 'email']]);
        if ($options['phone']) {
            $builder->add('phone', TelType::class, ['required' => false, 'attr' => ['autocomplete' => 'tel']]);
        }
        if ($options['subject']) {
            $builder->add('subject', TextType::class, ["required" => false]);
        }
        $builder->add('message', TextareaType::class, ['attr' => ['rows' => 6]]);
        if ($options['attachments']) {
            $builder->add('attachments', FileType::class, ["required" => false, "multiple" => true, "dropzone" => null]);
        }
        if ($options['trap']) {
            // Off-screen for people (and for screen readers), filled by robots.
            $builder->add('website', TextType::class, ['required' => false, 'label' => false,
                'row_attr' => ['class' => 'base-trap', 'aria-hidden' => 'true', 'style' => 'position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']]);
        }
        if ($options['buttons']) {
            $builder->add('submit', SubmitType::class, ["confirmation" => true]);
            $builder->add('reset', ResetType::class);
        }
    }
}
