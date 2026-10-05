---
title: E-mails
order: 53
---

# E-mails

## omnibase's frame

`@Base/notifier/email.html.twig` is the frame of omnibase's e-mails (Inky,
styles inlined). A notification renders it with its own context; a plain
`TemplatedEmail` extends it and gives nothing but its blocks:

```twig
{# templates/email/appointment.html.twig #}
{% extends '@Base/notifier/email.html.twig' %}
{% block subject %}{{ 'booking.email.subject'|trans({date: date}) }}{% endblock %}
{% block content %}<p>{{ 'booking.email.lead'|trans }}</p>{% endblock %}
```

```php
$mailer->send((new TemplatedEmail())
    ->to($client->getEmail())
    ->htmlTemplate('email/appointment.html.twig')
    ->context(['date' => $date]));
```

- **Every variable has a default**: `importance`, `markdown`, `raw`,
  `content`, `action_url`, `action_text`, `exception`, `footer_text`. (The
  frame used to stop on "Variable importance does not exist" outside a
  notification.)
- **Blocks**: `subject`, `title`, `style`, `header`, `lead`, `content`,
  `action`, `exception`, `footer`, `footer_content`.
- **The logo is optional**: the `header` block prints one only when there is
  one - the `logo` variable of the context, else the back office's setting
  (`base.settings.logo.email`, then `base.settings.logo`). A site without a
  logo sends its e-mails without one.

A site with its own frame (`templates/email.html.twig`, as the scaffold
copies) keeps it: omnibase's own templates extend `email.html.twig`, which is
the site's when it has one.

## The subject is written in the template

`{% block subject %}` of a `TemplatedEmail`'s template is the message's
subject (`Base\Subscriber\TemplatedEmailSubjectSubscriber`, before the body
is rendered): rendered with the e-mail's context, tags removed, entities
decoded. It was only printed in the body, and the message left without a
subject.

A subject set in PHP wins - `->subject('Rappel')` - and is handed to the
template as the `subject` variable, which the frame prints as its title and
lead instead of the block.
