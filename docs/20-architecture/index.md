---
title: Architecture
order: 20
---

# Architecture

The bundle is organised around a few long-lived pieces:

- **Entities** — `Thread` and its subtypes carry the shared title/slug/content
  model that articles, comments and pages all reuse.
- **Settings** — a compiled snapshot of configuration, read through
  `SettingBag`.
- **Admin** — a from-scratch administration bundle (`base-bundle-admin`).
- **Account security** — what the administrator sets, and a second factor
  required of some roles: [Account security](account-security.md).
- **The demo environment** — `APP_ENV=demo`: declared demonstration
  accounts, one click to sign in, a nightly reset, and what it refuses:
  [The demo environment](demo.md).
- **Forms** — the theme, and where a field's texts are translated:
  [Forms](forms.md).
- **Sealed fields** — `#[Vault]`, its key pair, and what happens without
  one: [Sealed fields](vault.md).
- **Errors** — which status an error is answered under, and the 404 that are
  meant: [Errors and refusals](errors.md).
- **Time** — PHP's zone is the visitor's; a moment is stored in UTC
  (`utc_datetime_immutable`), see [Time and time zones](time.md).
- **The forms' guard** — a trap, the time, the lists, a captcha, the classifier, one option
  (`guard`): [The forms' guard](guard.md).
- **Politeness** — *tu* or *vous*, です / ます or 敬語: one setting of the
  site (`base.translator.politeness`) and a variant per text
  (`key._polite`), see [How the site addresses people](politeness.md).

## The warm-up: every class of every bundle

On its first boot (and after a `cache:clear`), `BaseBundle::warmUp()` maps
each `Base\*` bundle's directories onto their mirrors (`Base\X\Entity\Y` ↔
`Base\Entity\X\Y`, `Base\Entity\Y` ↔ `App\Entity\Y`...) and, to do so, loads
every class it finds there. The result is kept in
`var/cache/<env>/pools/base/bundle.php`.

A bundle may hold classes for a package it only suggests - a digest source
implementing `omnibase/newsletter`'s interface, an exporter extending a
provider's class. Without that package, such a class cannot be declared
(`Interface "..." not found`, or Class, Trait, Enum). The warm-up passes it by:
it is not aliased, and the rest of the directory is. Nothing registers it
without its package (the bundle's service configuration loads it only when the
package is there), so nothing misses it. Any other error while loading a class
- a parse error, a broken bundle - still stops the boot.

`AbstractBaseBundle::classLoads($class)` is that check (true, false, or the
error rethrown); `AbstractBaseBundle::getUnloadableClasses()` lists what the
warm-up passed by in this process, with the reason - the first place to look
when an alias is missing.

## Rebuilding an entity's previous state

The attributes that act on a change (`#[Uploader]` above all) compare an entity
with its state before the change: `AbstractAttribute::getOldEntity()` rebuilds
it, without its constructor, from Doctrine's original data through
`object_hydrate()`. Doctrine keeps an enum field there as its backing value
(`"draft"`, not `Status::Draft`); `object_hydrate()` turns it back into the
case (`BackedEnum::tryFrom()`, or the case of that name for a pure enum)
whenever the property is typed with an enum, and leaves the property out when
no case matches. `property_enum_value(ReflectionProperty, $value)` is that
conversion, for any other code that fills typed properties by reflection.
