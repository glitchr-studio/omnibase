# Base bundle

Bedrock Symfony bundle for Glitchr projects: it pre-configures a full application
stack (admin, security, media, i18n, workflow, API) so host apps only add their
domain code. The `Base\` namespace deliberately mirrors the host apps' `App\`
structure — a host class can usually extend or decorate its `Base\` counterpart
one-to-one.

Requires PHP ≥ 8.1 and Symfony 6, 7 or 8. Licensed LGPL-3.0-or-later
(see `COPYING` / `COPYING.LESSER`).

## Docker: every plugin, running

`docker/` builds this checkout into one Symfony application with every `omnibase/*` plugin -
the back office, the shop, the forge, the forum, the mailbox, the docs - on SQLite, with a demo
page that seeds members and a shop and signs you in:

```sh
cd docker
docker compose up                                        # → http://localhost:8000/
docker compose run --rm omnibase check                   # container and templates linted, routes counted
docker compose -f compose.yml -f compose.checkouts.yml up   # the checkouts beside this one instead of Packagist
docker compose run --rm test                             # this bundle's test suite
```

## Installation

The package is resolved from GitLab (`gitlab.glitchr.dev/public-repository/symfony/bundle/base/component`):

```bash
composer require glitchr/omnibase:^3.0
```

Enable in `config/bundles.php` (Flex usually does it):

```php
Base\BaseBundle::class => ['all' => true],
```

Bundle configuration lives under the `base:` key; the tree is defined in
`src/DependencyInjection/BaseConfiguration.php`.

## Try it in one command

A self-contained demo (bare Symfony skeleton + this bundle + SQLite, one flat
page touring the `App\ ↔ Base\` mirroring, the shipped entity layer, the
setting bag, the obfuscator and the translator) ships in the root
[Dockerfile](Dockerfile):

```bash
docker build -t base-bundle-demo .
docker run --rm -p 8000:8000 base-bundle-demo
# → http://localhost:8000/
```

The demo app under [example/app/](example/app/) doubles as a minimal
reference: the smallest `bundles.php`, `base.yaml`, `doctrine.yaml`,
`security.yaml` and `flysystem.yaml` a host application needs.

## What's inside (map of `src/`)

| Area | Directories | Purpose |
|------|-------------|---------|
| Admin | `Admin/`, `Field/`, `Form/` | EasyAdmin integration: custom CRUD fields (~40, e.g. `WysiwygField`, `CropperField`, `TranslationField`) with their configurators, form types and extensions |
| Persistence | `Database/`, `Entity/`, `Repository/`, `EntityDispatcher/`, `*Subscriber/` | Doctrine attributes (`Hierarchify`, `Versionable`, `Vault`, …), base entities (User, Thread, Layout, …), lifecycle dispatching |
| HTTP | `Controller/`, `Routing/`, `Response/` | Public/admin/UX controllers, advanced router, sitemap |
| Security | `Security/`, `Validator/` | Voters, 2FA (scheb), access tokens, dynamic session storage |
| Services | `Service/`, `Cache/`, `Notifier/`, `Serializer/` | `BaseService` facade, media/file/flysystem services, localizer, notifier channels |
| Presentation | `Twig/`, `templates/`, `translations/` | Twig extensions/components, EasyAdmin + client templates, translations |
| Assets | `assets/`, `public/`, `Imagine/` | Encore entrypoints (async/defer), built assets, image filters |
| Tooling | `Console/`, `Inspector/`, `Attributes/` | Console commands, profiler data collectors, attribute reader |

Extension points are autoconfigured by tag — implement the interface, the tag is
applied automatically (see `src/DependencyInjection/BaseExtension.php`):
`base.entity_extension`, `base.annotation`, `base.icon_provider`,
`base.service.sharer`, `base.simple_cache`, …

## Service wiring

Service definitions live in `config/`:

- `services.php` — registrations (split per domain, loaded by `BaseExtension`)
- `services-decoration.php` — decorations of framework/third-party services
- `services-public.php` — services forced public
- `services-fix.php` — targeted workarounds

## Development

The bundle is developed inside a host app's `vendor/glitchr/omnibase`
checkout (composer VCS install keeps `.git`) and pushed from there.

```bash
make build     # yarn install + Encore (watch in debug, prod otherwise)
make linter    # php-cs-fixer + phpstan (level max)
make tests     # phpunit
```

CI (`.gitlab-ci.yml`) runs cs-fixer, PHPStan and PHPUnit on every push.

## Releases

One development branch per major (`1.x`, `2.x`, `3.x` — the current one is the
main branch; the next major starts `4.x`), tags per release (`3.0.0`, `3.1.0`,
…) — tag after each meaningful batch so consumers can pin stable versions:

```bash
git tag -a 3.1.0 -m "..." && git push origin 3.1.0
```

The legacy `1.0`/`2.0`/`3.0` branches carry the pre-2026 unslimmed history and
are frozen — do not push to them.

See `CHANGELOG.md`.
