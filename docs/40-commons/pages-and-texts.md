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
