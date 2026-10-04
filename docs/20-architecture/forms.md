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

## A PHP enum in a select

`Base\Field\Type\SelectType` bound to a property that holds a PHP enum finds
its choices by itself - the cases - and hands the case back to the record:

```php
$builder->add('state', SelectType::class);                                   // Doctrine enumType:, or a property typed with the enum
$builder->add('states', SelectType::class, ['class' => CommentState::class, 'multiple' => true]);
```

The select holds a case by its backed value (its name when the enum is not
backed, `Base\Form\Common\NativeEnum::id()`). A case is labelled, in this
order: by itself when the enum implements Symfony's `TranslatableInterface`;
by the key `<short class name in snake case>.<value>` of the `enums` domain
(`comment_state.pending`); by its name made readable. A value that names no
case is an invalid choice, and the record keeps what it had.

A field that gives `choices` (or a `choice_loader`) keeps them as they are:
the enum is not guessed over them, and `['Label' => 'value']` shows the label
(translated when it is a key such as `@agenda.role.soloist`).

`ClassMetadataManipulator::getFields()` - what `AssociationType` builds an
embedded form from - maps such a column to a `SelectType` as well; it used to
be a `TextType`, which cannot print a case.

## An entity in a select

`SelectType` with an entity `class` (or bound to a Doctrine association) is
fed by the autocompletion and loads what was chosen from the class's
repository: `cacheById()` on omnibase's repositories, `find()` / `findBy()`
on a plain Doctrine one.

`AssociationType` embeds the related record's own form, always. The back
office decides, for an `AssociationField`, between that form and a picker:
see omnibase/admin's `docs/crud-fields.md`.
