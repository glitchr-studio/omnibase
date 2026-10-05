---
title: Account security
order: 40
---

# Account security

`Base\Service\SecurityPolicy` is the one place that says what an account may
change about its own security; the settings page (`/settings`), the actions
behind its buttons and the enrolment prompt all ask it. In a template it is
the global `security_policy`.

## What the administrator sets

Settings, saved in the back office (each has its own default while unsaved):

| Setting | Default | |
|---|---|---|
| `base.settings.security.two_factor` | on | a second factor (one-time code, code by email) is offered |
| `base.settings.security.two_factor.mandatory` | off | **every** account must have one |
| `base.settings.security.passkeys` | on | passkeys are offered as a sign-in method |
| `base.settings.security.new_device_email` | off | a sign-in from an unknown browser is confirmed by email |
| `base.settings.security.new_device_prompt` | off | that sign-in offers, once, to set up a one-time code |

## A second factor for some roles

"Mandatory" in the settings is for everyone or no one. An application that
requires a second factor of some accounts only - the staff of a practice, not
its patients - says which roles:

```yaml
# config/packages/base.yaml
base:
    security:
        two_factor:
            required_roles: [ROLE_STAFF]   # held directly or through security.role_hierarchy
            postpone: true                 # false: no "not now" on the enrolment prompt
```

The list is one value: defined again - in the file's `when@dev` or
`when@test` block, or in another file - the later definition **replaces** it
(Symfony would append the items of a list; this one opts out), so an
environment can require it of other roles, or of none:

```yaml
when@test:
    base:
        security:
            two_factor:
                required_roles: []         # nobody is sent to the enrolment page in the tests
```

A definition that does not name `required_roles` (only `postpone`, say) keeps
the list as it was.

An account holding one of these roles and no second factor is sent to
`/settings/security-required` (`user_settings_enrolment`) by
`SecurityEnrolmentSubscriber` - on a plain page load only: a form post, an
XHR, a download are never interrupted, nor the settings, sign-in and sign-out
pages. It cannot switch its last factor off afterwards. Every other account
stays free to choose. While `base.settings.security.two_factor` is off,
nothing is required of anyone.

`postpone: true` (the default) keeps the prompt's "not now": the answer lasts
the session, and the prompt returns at the next sign-in. With `false` the
button is gone and the account is sent back to the prompt on every page until
it has a second factor. An administrator impersonating the account is never
the one asked to enrol.

```php
$policy->isTwoFactorMandatory();        // for the whole site (the administrator's setting)
$policy->isTwoFactorMandatory($user);   // for this account: the setting, or one of its roles
$policy->isTwoFactorRequiredByRole($user);
$policy->needsEnrolment($user);         // mandatory for it, and it has none
$policy->canDisableTwoFactor($user);
$policy->canSkipEnrolment($user);
```

A passkey replaces the password; it is not counted as a second factor.

## In the demo environment

A declared demonstration account ([The demo environment](demo.md)) is shared
by every visitor: nothing above is required of it - not the second factor of
its roles, not "mandatory for everyone" - and it changes none of its
credentials (`isDemoAccount($user)`, `canChangeCredentials($user)`,
`canEnableTwoFactor($user)`). Every other account, and every environment
other than `demo`, is as described here.
