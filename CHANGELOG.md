# Changelog

All notable changes to this bundle are documented here, per release tag.
Versions follow the branch-per-major scheme: branch `3.x` → tags `3.0.0`,
`3.1.0`, … (the next major continues on `4.x`).

## [Unreleased]

### Changed
- A guarded form shows its captcha only after a few refused tries: `base.guard.captcha_after` (3) and the option `guard.captcha_after`, counted per form and visitor (address, else session) in `cache.app` for 15 minutes, forgotten after a form sent; below, no widget printed and no token asked. `0` keeps the captcha always shown, as before.
- The forms' guard speaks to `glitchr/omnishield`, the family formerly named `glitchr/omniguard` (renamed on 2026-10-10: the vendor `omniguard` on Packagist belongs to another project): `omnishield/*` in `suggest` and `require-dev`, `Omnishield\` classes, the configuration `omnishield:`, the test token `omnishield-fixed-token`. `FormGuard`, the option `guard` and `base.guard` keep their names.

### Changed
- License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later. `COPYING` and `COPYING.LESSER` removed, `LICENSE` holds the MIT text.

### Added
- The `demo` environment (`docs/20-architecture/demo.md`): demonstration
  accounts declared by the bundles and the application
  (`Base\Demo\DemoAccountProviderInterface`), created by the fixtures
  (`DemoAccountFactory`), offered at a click on the sign-in page
  (`@Base/demo/_accounts.html.twig`, a POST with a CSRF token); the banner
  (`@Base/demo/_banner.html.twig`); `demo:reset` under `symfony/lock`; no
  e-mail sent, `X-Robots-Tag: noindex`, credentials of a demonstration
  account locked, the staff's mandatory second factor lifted for it, the
  super-administrator signed in by a secret or not at all, and a kernel that
  refuses to start in debug or on the production database. Nothing of it is
  registered in any other environment.
- Standalone Docker demo (root `Dockerfile` + `example/app/`): bare Symfony
  skeleton + this bundle + SQLite, one flat page touring the App\ ↔ Base\
  mirroring, entity layer, setting bag, obfuscator and translator. The
  `example/app/` overlay doubles as a minimal host-app reference config.

### Fixed
- `composer.json` now declares dependencies the bundle's DI actually
  hard-references: `hwi/oauth-bundle`, `symfony/webpack-encore-bundle`,
  `symfony/monolog-bundle`, `glitchr/ux-google`, plus `ext-imagick` /
  `ext-igbinary` (required unconditionally in `BaseBundle::boot()`).

## [3.0.0] — 2026-07-04

First tagged release of the 3.0 line (branched from 2.0 in 2025).

### Added
- Offline mode: YouTube-style offline page takeover.
- Turbo enabled across admin; admin UX fixes.

### Changed
- Upgraded to Symfony 8.0 (from 6.x/7.x line); EasyAdmin 5 compatibility.
- Asset pipeline optimized (async/defer entrypoints).

### Fixed
- Stale `dirty-form` `onbeforeunload` handler lingering across Turbo visits
  in the admin (spurious "Leave site?" dialogs).
- CSP: removed `csp_nonce('script')` from admin layout and CRUD templates.
- SF8 compatibility: replaced removed `Request::get()` usages.
- EasyAdmin CRUD form deprecations, admin context factory/override updates.

### Repository
- License texts added (`COPYING`, `COPYING.LESSER` — LGPL-3.0-or-later).
- `.php-cs-fixer.cache` untracked; `.gitignore` cleaned up.
- Tests bootstrap (PHPUnit 10) and GitLab CI (php-cs-fixer, PHPStan, PHPUnit).
- History slimmed: built assets under `public/` removed from past revisions.

## [1.3] — 2023-08-19 · [1.2] — 2023-06-27 · [1.1] — 2023-04-24 · [1.0] — 2023-04-03

Historical tags of the 1.0 line (Symfony 6). See `git log 1.0..1.3` for details.
