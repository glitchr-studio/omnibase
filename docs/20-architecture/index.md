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
