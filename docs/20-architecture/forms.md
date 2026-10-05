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

## A select works before its script does

`SelectType` is drawn by select2, but the `<select>` the server prints is a
whole one: on a page whose script did not run - blocked, failed, not loaded
yet - it can be read, changed and sent.

- **Its options.** The choices of a static list or of an enum, groups as
  `<optgroup>`, with the labels select2 shows; for an autocompleted list, the
  records already chosen. select2 empties the select before filling it from
  its own data, as it always did: nothing is listed twice.
- **Its selection.** The record's value is the `selected` option - also in
  the lists that printed their options already (a `choice_loader`:
  `CurrencyType`), where none was selected and the browser sent the first of
  the list in place of the record's currency.
- **An empty first option** in a select of one value, carrying the
  placeholder: without it the browser picks the first choice for a record
  that has none. It is the option select2 itself asks for to show a
  placeholder.

(The select was printed with no option at all and filled by select2 alone:
without the script a product's availability arrived null.)

### `required` is checked by the server

A required `SelectType` sent empty is an error of the form - "This value
should not be blank.", in the visitor's language (`validators` domain) - on
the field itself, where its row prints it. The browser's `required`
attribute was the only check, and the back office's forms are `novalidate`.

The empty value is not written into the record, which keeps what it had: the
field fails as a transformation does (it is not synchronized), so a setter
that takes no null - `setStatus(PublicationStatus $status)` - is not called
with one. That call answered 500 ("Expected argument of type ..., null
given") in place of the form's error.

- `required` is what the field says, `true` unless said otherwise
  (`'required' => false`, `->setRequired(false)` on a CRUD field): a select
  on a nullable property that may stay empty has to say so.
- A list of several may be empty, as before, unless
  `'required_when_multiple' => true`.
- A disabled field is not asked.

The field's errors no longer go up to the form (`error_bubbling` is `false`,
as for Symfony's own compound choice and date fields): a constraint of the
property is printed beside the select too, not at the top of the form.
