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

## The comment forms

`Base\Service\CommentGuard` is the comment forms' face of `FormGuard`: `CommentType`'s `url`
trap and `opened` time, and a second comment from the same address within
`base.comments.flood_interval`. omnibase/blog and omnibase/video call it.
