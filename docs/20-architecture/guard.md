---
title: The forms' guard
order: 36
---

# The forms' guard

One option on a form, every check in its order:

```php
$this->createForm(ContactType::class, $model, ['guard' => true]);
$this->createForm(ContactType::class, $model, ['guard' => ['action' => 'contact', 'min_delay' => 5]]);
```

| Step | What | Answer |
|---|---|---|
| 1. the trap | `guard_website`, a field off-screen for people, filled by robots | refused, on the form (`guard.trapped`) |
| 2. the time | `guard_opened`, when the form was shown, signed with the application's secret | refused when sent faster than `min_delay` (`guard.too_fast`), or with a stamp that is not the site's (`guard.stale`) |
| 3. the lists | whoever sends it - the address, the e-mail, the name - asked of the gateways of `base.guard.reputation` | a disposable e-mail on its field (`guard.disposable`); anything else known on the form (`guard.known`) |
| 4. the captcha | `guard_captcha`, glitchr/omniguard's `ChallengeType` on the gateway of `base.guard.challenge` | its own constraint, on its field |
| 5. what was written | data implementing `SpamProtectionInterface`, classified by `SpamChecker` (Akismet) | told to the data (`getSpamCallback()`); blatant spam refused (`guard.spam`) |

Step 5 is `spam_protection`, on by default outside the back office as it always was: it runs
only once the form is otherwise valid, so nothing is sent to the classifier for a form already
refused. A robot caught by steps 1 to 3 does not reach the captcha's provider either.

The messages are the `forms` domain's `guard.*` (French and English), the captcha's the
`validators` domain's - none says *tu*.

## The option

| Key | Default | |
|---|---|---|
| `trap` | `true` | the trap field |
| `min_delay` | `base.guard.min_delay` (3) | seconds; 0: not checked |
| `challenge` | `true` | the captcha: `true` for `base.guard.challenge`, a gateway's name, `false` for none |
| `action` | the form's name | what the form is for, signed into the captcha's token |
| `reputation` | `true` | ask the lists |
| `email`, `name` | `email`, `name` | the fields - or the data's properties - holding the sender's e-mail and name |

## Without glitchr/omniguard

glitchr/omniguard is suggested, not required (it is not on Packagist yet). Without it - or
without a gateway configured - the trap and the time alone, and no error. With it:

```php
// config/bundles.php
Omniguard\Bridge\Symfony\OmniguardBundle::class => ['all' => true],
```

```yaml
# config/packages/omniguard.yaml
omniguard:
    gateways:
        forms: { factory: altcha, options: { hmac_key: '%env(ALTCHA_HMAC_KEY)%' } }
        emails: { factory: disposable }
    challenge: { gateway: forms }

when@test:
    omniguard:
        gateways:
            forms: { factory: fixed }      # the hidden field omniguard-token carries omniguard-fixed-token
```

```yaml
# config/packages/base.yaml
base:
    guard:
        challenge: ~             # the captcha: null for omniguard.challenge.gateway, a gateway's name, false for none
        fallback: ~              # the captcha of a visitor who refused the third party the captcha reaches
        reputation: [emails]     # the lists asked about the sender
        classifier: ~            # the classifier behind SpamChecker; null: Akismet with api.spam.akismet
        unreachable: accept      # a list or a classifier that does not answer: accept, or reject
        min_delay: 3
        sign_in_after: 3         # failed sign-ins from an address before the sign-in asks the captcha
```

The captcha's own conduct when its provider does not answer is omniguard's
(`omniguard.challenge.unreachable`).

## With glitchr/ux-google

Where `google.recaptcha.enable` is true, ux-google's extension adds its own captcha (`_captcha`)
to every form: the option `guard` then adds no second one - the trap, the time, the lists and the
classifier stay. The places that ask ux-google's reCAPTCHA themselves (`CommentType`'s
`recaptcha`, omnibase/faq's `AskType`, the sign-in's badge) are left as they are.

## Guarded by default

| Form | `guard` | |
|---|---|---|
| `ContactType` | `action: contact` | `guard: false` for a form a site guards otherwise |
| `SecurityRegistrationType` (sign-up) | `action: signup` | a disposable e-mail refused on its field |
| `SecurityResetPasswordType`, `SecurityLoginTokenType` | `reputation: false` | the same answer for every address |
| `CommentType` | the lists and the captcha | its own trap (`url`) and time (`opened`) stay, read by `CommentGuard`; no captcha when it asks ux-google's (`recaptcha`) |

The captcha is checked by the guard itself, in its order and whatever the form's validation
groups (the sign-up validates in `new` alone, where a field's constraint is not asked).

## The sign-in

Symfony's `login_throttling` is the first line (five tries a minute). From
`base.guard.sign_in_after` failed sign-ins from an address (3, counted for 15 minutes in
`cache.app`, forgotten after a success), the sign-in form carries the captcha and a sign-in
without a valid token is refused before its password is checked (`Base\Security\SignInGuard`).
Not the rescue door, not the demonstration's one click; nothing where ux-google's reCAPTCHA is on.

## Consent

ALTCHA, the default, reaches nobody: omniguard's Symfony bridge serves its widget's script from
the site (omniguard/altcha ships it: `/omniguard/altcha/3.3.0/altcha.min.js`), so the page loads
nothing from anyone else and there is no consent to ask. Nothing to add on a site but
omniguard's bundle; `omniguard.serve_scripts: false` would put the CDN back.

A captcha whose widget reaches a third party (`Widget::reachesOthers()`: Turnstile, reCAPTCHA,
ALTCHA's script when a site names a CDN) waits for the visitor's consent - omnibase/consent's feature
`CAPTCHA`, declared by `Consent.use()` - kept inert in a `<template>` until then. Beside it, the
fallback that reaches nobody (`base.guard.fallback`, ALTCHA) is shown, and stays after a refusal:
a refusal does not open the form. The guard asks the third party's token when there is one, the
fallback's otherwise. Without a fallback, a widget that sets cookies (reCAPTCHA) waits all the
same; one that sets none is loaded at once. A page without omnibase/consent's script shows the fallback alone.

## In a site's tests

The test client submits a form the moment it reads it, and a suite signs in wrong on purpose:

```yaml
when@test:
    base:
        guard:
            min_delay: 0          # the stamp is still checked: post the one the page prints
            sign_in_after: 0
```

With `factory: fixed` as the test captcha, the page prints a hidden `omniguard-token` field
holding `omniguard-fixed-token`, outside the form: a test client that submits the page's form
sends it; a request built by hand adds it.

## The comment forms

`Base\Service\CommentGuard` is the comment forms' face of `FormGuard`: `CommentType`'s `url`
trap and `opened` time, and a second comment from the same address within
`base.comments.flood_interval`. omnibase/blog and omnibase/video call it.
