---
title: Forms
order: 50
---

# Forms

omnibase's form theme (`templates/form/form_div_layout.html.twig`, added to
`twig.form_themes` by the bundle) renders every form: the `form2` blocks, the
label strip, the custom field types.

## Labels, help and placeholders are translation keys

Texts are translated by `Base\Service\Translator`. A key may name its domain
(`@mailbox.form.subject`), or be looked up in the field's `translation_domain`.

Every field has the domain **`fields`** by default
(`Base\Form\Extension\FormTypeExtension`), where omnibase's own field types
keep their words. A form type that declares its own domain is followed by its
fields:

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults(['translation_domain' => 'mailbox']);
}

public function buildForm(FormBuilderInterface $builder, array $options): void
{
    $builder->add('subject', TextType::class, [
        'label' => 'form.subject',                         // mailbox.form.subject
        'help' => 'form.subject_help',
        'attr' => ['placeholder' => 'form.subject_placeholder'],
    ]);
}
```

The rule, applied when the view is finished
(`FormTypeExtension::inheritTranslationDomain()`): when none of a field's
label, help and placeholder exists in the field's own domain and one of them
exists in the domain of a form above it, the field is rendered in that
domain. Otherwise nothing changes: a field whose texts are in `fields` stays
there, a key that names its domain is translated in it, and
`'translation_domain' => false` prints the texts as written.

(A field used to ignore its form's domain: the key was looked up in `fields`,
found nothing, and the label was printed empty. The bundles named the domain
in every key to go around it; they no longer need to.)
