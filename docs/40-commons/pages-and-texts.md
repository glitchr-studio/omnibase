---
title: Pages and texts
order: 47
---

# Framing an address: `embed_url()`

```twig
{% set frame = embed_url(link) %}
{% if frame and frame.src %}
    <div style="aspect-ratio: {{ frame.ratio }}"><iframe src="{{ frame.src }}" title="{{ frame.title ?? frame.provider }}" loading="lazy" allowfullscreen></iframe></div>
{% else %}
    <a href="{{ link }}">{{ link }}</a>
{% endif %}
```

`frame` is `{provider, src, ratio, height, title, html, url, known}` (`height`: a
player of a fixed height across the width, SoundCloud's, has no ratio). Known providers
are read from the address alone (`known: true`): YouTube (as
youtube-nocookie.com), Vimeo, Canva, Padlet, LearningApps, Genially, Google
Slides. Any other address is asked to its site through the `embed/embed`
library (oEmbed, Open Graph, a requirement of glitchr/omnibase), cached a day per address, and only the iframe's address is
kept. `embed_url(link, false)` never asks.

# Contact form

`Base\Form\Type\ContactType` on `Base\Form\Model\ContactModel`, as it is:

```php
$form = $this->createForm(ContactType::class, $message = new ContactModel(), ['phone' => true, 'subject' => false, 'attachments' => false, 'buttons' => false, 'trap' => true]);
$form->handleRequest($request);
if ($form->isSubmitted() && $form->isValid()) {
    if (!$message->isRobot()) { $mailer->send(...); }   // the trap filled: thanked, nothing sent
}
```

Options: `phone` (off), `subject` (on), `attachments` (on), `buttons`
(submit and reset, on), `trap` (the off-screen `website` field, off). The model
validates the name, the e-mail and the message.

## The data-protection notice, and the box to tick

A form that collects personal data says what they are used for (GDPR, art.
13), and may ask for a box to be ticked before it is sent. One brick for every
site and bundle, `Base\Form\Type\PrivacyType`, which `ContactType` adds
through three options:

| Option | Default | |
|---|---|---|
| `privacy` | `false` | `true`: omnibase's notice (`@forms.privacy.notice`); a translation key: the site's own; `false`: none |
| `privacy_consent` | `false` | the box `privacy[accept]`, required: the form is invalid until it is ticked (`IsTrue`, message `privacy.consent_required` in `validators`) |
| `privacy_parameters` | `[]` | the notice's parameters - a link to the privacy page, for instance |

```php
$form = $forms->createNamed('contact', ContactType::class, $message = new ContactModel(), [
    'phone' => true, 'subject' => false, 'attachments' => false, 'buttons' => false, 'trap' => true,
    'privacy' => '@messages.contact.privacy',              // its text links {url}, the privacy page
    'privacy_parameters' => ['url' => $this->generateUrl('app_legal')],
    'privacy_consent' => true,
]);
```

```twig
{{ form_row(form.message) }}
{{ form_row(form.privacy) }}   {# the notice, then the box when asked #}
```

Any other form - a quote request, an application, a registration - adds the
same field rather than its own checkbox and sentence:

```php
$builder->add('privacy', PrivacyType::class, [
    'notice' => '@forms.privacy.notice',   // or the bundle's key, or false
    'notice_parameters' => [],
    'consent' => true,
    'consent_label' => '@forms.privacy.consent',
    'consent_message' => 'privacy.consent_required',
]);
```

The field is not mapped: the model keeps the message, not the tick (read it
with `$form->get('privacy')->get('accept')->getData()` if it must be kept).
The notice is printed by omnibase's form theme (block `base_privacy_row`: a
`div.form-privacy` holding `p.form-privacy-notice`, then the box) as HTML,
since it is a translation the site wrote. The default texts exist in English
and French (`forms`: `privacy.notice`, `privacy.consent`); a site in another
language, or with other words, gives its own keys.

# Pictures: `|picture`

```twig
<img src="{{ post.cover|picture(640) }}">   {# an upload, resized; a URL or /path as is; else public/assets/<it> #}
```

# Texts rewritten in the back office

`Base\Entity\Layout\TextOverride` (table `layoutTextOverride`): a key, a
domain, a language and the new text. `Base\Translation\OverridingTranslator`
decorates the translator (between omnibase's and Symfony's): a rewritten key
wins over `translations/`, ICU syntax included. The texts are cached (the
application's `cache.redis` pool when it has one, else `cache.app`) and the
cache is emptied when one is saved. The screen is omnibase/admin's "Textes du
site" (`Base\Admin\Controller\Crud\TextOverrideCrudController`).
