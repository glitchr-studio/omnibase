---
title: Scaffolding a site
order: 15
---

# Scaffolding a site

A new omnibase site starts as a copy of **Apfelschule** (`~/Sites/apfelschule`),
the reference application: Symfony 8, PHP 8.4, Docker, glitchr/omnibase 3.x with
omnibase/admin, omnibase/marketplace and the `omnibase/*` bundles of its trade.
This page is the exact list: what is copied and then adapted, and what is no
longer copied because a package provides it.

## What a new site copies

### The project's frame (copied as is, then names and ports changed)

| Path | Then |
|---|---|
| `Makefile` | `APP_NAME`, ports (plan 0 §2: one HTTP/HTTPS pair per site); its `docker exec` shortcuts take a TTY only when there is one (see below) |
| `docker-compose.yml`, `docker-compose.override.yml`, `docker-compose.setup.yml`, `docker-compose.override.setup.yml`, `docker-compose.admin.yml` | the `../omnibase`, `../canva`, `../padlet` mounts only for the path packages the site uses |
| `deployments/docker/*` (web, proxy, database, assets, composer, cron; `collab` if it co-edits live) | nothing |
| `.env`, `.env.dev`, `.env.prod`, `.env.test` | `APP_NAME`, `DEFAULT_URI`, the dev ports, `MAILER_CONTACT` |
| `.editorconfig`, `.gitignore`, `.php-cs-fixer.dist.php`, `AGENTS.md` | nothing |
| `bin/`, `public/index.php`, `src/Kernel.php`, `config/bootstrap.php`, `config/preload.php` | nothing |
| `composer.json` | its name and description; the `require` of its bundles; path `repositories` for the packages not on Packagist yet |
| `package.json`, `webpack.config.js`, `postcss.config.js` | its name; `@glitchr/stickyjs` `^1.4`, `@glitchr/transparentjs` at the commit the others pin |
| `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Mock/NoNetwork.php` | nothing |
| `initdb.sql` | the database name |

**Never copied: `.env.local` and `.htpasswd`.** Both are git-ignored and belong
to one deployment: `.env.local` holds the other site's secrets (its Stripe and
mail keys, its database password) and would point the new site at them;
`.htpasswd` holds the other site's beta credentials. A copy made with `cp -R`
or `rsync` takes them along with the rest: copy from `git ls-files` (or
`git archive HEAD | tar -x -C ../newsite`), or exclude the two by name. The new
site writes its own - `.env.local` by hand, `.htpasswd` with `make htpasswd`
(the proxy mounts `./.htpasswd`: without the file Docker creates a *directory*
of that name, and the protected hosts cannot check a password).

### Docker networks: a subnet per site

Each site has two networks (`extranet`, `intranet`), and Docker gives each a
subnet of its default address pools. With many sites up at once the pools run
out and `up` fails on "could not find an available, non-overlapping IPv4
address pool among the defaults to assign to the network" (or "all predefined
address pools have been fully subnetted"). A site therefore fixes its two
subnets in `docker-compose.override.yml`, numbered after its ports so that no
two sites share one:

```yaml
# docker-compose.override.yml
networks:
  extranet:
    ipam:
      config:
        - subnet: ${NETWORK_EXTRANET_SUBNET:-10.110.126.0/24}   # HTTP port 8126: 126 and 127
  intranet:
    ipam:
      config:
        - subnet: ${NETWORK_INTRANET_SUBNET:-10.110.127.0/24}
```

`NETWORK_EXTRANET_SUBNET` and `NETWORK_INTRANET_SUBNET` (`.env.local`) move
them on a host where those ranges are taken. A subnet changes only when its
network is recreated: `make down`, then `make up`. See also
[Stopping a site](#stopping-a-site).

### Configuration (copied, then trimmed to the site's bundles)

`config/bundles.php`, `config/services.yaml` (its `app.site.*` parameters),
`config/routes.yaml` (one import per bundle's client controllers),
`config/routes_paths.yaml` (the localized paths), `config/routes/*`
(`base_admin.yaml` is what routes the back office, its system pages included),
and `config/packages/*`: `base.yaml`, `admin.yaml`, `doctrine.yaml`,
`security.yaml`, `framework.yaml`, `cache.yaml` (its `cache.redis` pool),
`marketplace.yaml` and `omnitrade.yaml` for a shop, `consent.yaml`, one file per
bundle, and the rest as they are.

### The site's own code (copied as a model, rewritten)

| Path | What it is |
|---|---|
| `src/Entity/User.php`, `src/Repository/UserRepository.php` | the account. **In a site with a shop, `User` implements `Base\Marketplace\Model\MerchantInterface`** (see below) |
| `src/Site/Identity.php` | the Twig global `site` (name, town, e-mail, legal notice) |
| `src/Controller/MainController.php` | home, about, contact (on `Base\Form\Type\ContactType`, see below), legal pages |
| `src/Controller/AccountController.php` | "Mon compte" |
| `src/Controller/Admin/DashboardController.php` | the back office's menu and tiles |
| `src/DataFixtures/` | the site's fixtures (a `dev` payment method for the marketplace's DevGateway) |
| `src/Tools/*` | only if the site uses Canva or Padlet (`PadletKey` also declares its API key field) |
| `migrations/` | **not** copied: a new site starts its own (`make doctrine-migration`) |
| `templates/base.html.twig`, `templates/email.html.twig`, `templates/layout/layout1.html.twig` | the page frame |
| `templates/client/**`, `templates/security/**`, `templates/email/**`, `templates/partials/_tools.html.twig` | the pages, the sign-in, the e-mails, the tools bar (Accès, cookies through `@Consent/_cookie.html.twig`) |
| `translations/messages+intl-icu.*.yaml` | the site's texts |
| `assets/app-async.js`, `assets/layout1-async.js`, `assets/bootstrap.js`, `assets/controllers.json`, `assets/styles/*` | styles and Stimulus |
| `assets/app-defer.js` | **reduced to `Base.boot({...})`** and the site's own effects (its curtain, its menu) |
| `tests/Functional/*` | the site's tests, `SharedBricksTest.php` included |

### A shop's `User` is its merchant

omnibase/marketplace's products, stores and orders are related to "whoever
sells", `Base\Marketplace\Model\MerchantInterface`, which Doctrine binds to a
class of the application. A site that installs the marketplace declares both;
without them Doctrine cannot map those entities (the target entity
`Base\Marketplace\Model\MerchantInterface` is not found) and no migration can
be generated:

```php
// src/Entity/User.php
use Base\Marketplace\Model\MerchantInterface;

class User extends \Base\Entity\User implements MerchantInterface
{
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $companyName = null;

    public function getCompanyName(): ?string { return $this->companyName; }   // the interface's one method
}
```

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        resolve_target_entities:
            Base\Marketplace\Model\MerchantInterface: App\Entity\User
```

A shop with a merchant class of its own (`App\Entity\User\Merchant`, a
subclass of `User`) names that class instead. A site without the marketplace
needs neither.

### Glitch Art's signature: `mirror: true` on the left

The footer's credit is `@Base/partials/_credits.html.twig`, black or white
only ([Front end](40-commons/front-end.md)). Its detail line opens towards the
inside of the page: by default on the left of the signature, for a signature
set on the **right** of its footer. A site that sets the signature on the
**left** passes `mirror: true`, or the detail opens outwards, over the page's
margin:

```twig
{% include '@Base/partials/_credits.html.twig' with {mirror: true} %}                  {# signature on the left #}
{% include '@Base/partials/_credits.html.twig' with {color: 'white', outline: true} %}  {# on the right, on a photo #}
```

## What a new site no longer copies

| No longer in the site | Use instead |
|---|---|
| `src/EventSubscriber/NestHeaderSubscriber.php` | omnibase/admin's `NestHeaderSubscriber`: every page under `/admin` (the site's own screens there included), and any route with `defaults: ['_nest' => true]` |
| `src/Controller/Admin/SystemController.php` | omnibase/admin's `SystemController` (`admin_settings`, `admin_apikey`, `admin_settings_quick`); the site's own fields in a class implementing `Base\Admin\Settings\SettingsSectionInterface` |
| `src/Form/ContactType.php`, `src/Model/ContactMessage.php` | `Base\Form\Type\ContactType` with `['phone' => true, 'subject' => false, 'attachments' => false, 'buttons' => false, 'trap' => true]`, `Base\Form\Model\ContactModel` (`isRobot()`) - created with `createNamed('contact', …)` to keep the field names |
| A consent checkbox and a data-protection sentence under a form | `ContactType`'s `privacy`, `privacy_consent`, `privacy_parameters` options, or `Base\Form\Type\PrivacyType` in any form ([Pages and texts](40-commons/pages-and-texts.md)) |
| `src/Twig/ImageExtension.php` | glitchr/omnibase's `\|picture` filter |
| `src/Entity/TextOverride.php`, `src/Repository/TextOverrideRepository.php`, `src/Translation/OverridingTranslator.php`, `src/Translation/TextOverrideCacheListener.php`, `src/Controller/Admin/Crud/TextOverrideCrudController.php` | `Base\Entity\Layout\TextOverride` (table `layoutTextOverride`), `Base\Translation\*`, omnibase/admin's `TextOverrideCrudController` |
| `src/Market/DevGateway.php` | omnibase/marketplace's `DevGateway`, registered in debug only |
| `templates/partials/_credits.html.twig` and its SCSS (`.as-credits`, `.nk-credits`, `.mh-credits`, `.gl-credits`…) | `@Base/partials/_credits.html.twig` (`color: black\|white`, `outline`, `mirror`, `rule`, a `credits_detail` block) |
| The boot code of `assets/app-defer.js` (closestScrollable guard, `<style>` headlock, nest-frame escape, `Sticky.ready`, `StickyStops.attach`, `Transparent.ready`, the current link) | `Base.boot()` from `vendor/glitchr/omnibase/assets/boot.js` |
| A copy of opening hours, ICS / Google Calendar, invitations, signed downloads, QR sheets, allergens, comments, URL embeds, a polling script | glitchr/omnibase's shared bricks: [docs/40-commons](40-commons/index.md) |
| A hand-written `LocalBusiness` JSON-LD around `opening_hours().schema()` | `{{ local_business_jsonld() }}` ([LocalBusiness JSON-LD](40-commons/local-business.md)) |
| A screen for the hours and the days off; a table of old addresses and its listener | omnibase/admin's `/admin/hours`; `Base\Entity\Layout\Redirection` and omnibase/admin's "Redirections" ([Redirections](40-commons/redirections.md)) |
| A column type, or a helper, keeping moments in UTC | the Doctrine type `utc_datetime_immutable` ([Time and time zones](20-architecture/time.md)) |

## First start, in this order

```sh
make install-dev          # = make env-dev, make build, make up - build BEFORE up
make doctrine-migration   # the site's first migration
make doctrine-migrate
make database-test        # the test database
docker exec <app>-web-1 php vendor/bin/phpunit
```

`make build` comes before any `up`. `/srv/app/public` is a named volume
(`<app>-public`) that no image seeds: the composer step of `make build` copies
`./public` (its `index.php`) into it when the volume is **empty**, then
composer's `assets:install` links the bundles' assets
(`public/bundles/*`). A stack started first - `make up`, a bare `docker compose
up` - creates the volume with the `public/assets` mountpoint alone: not empty,
so never seeded, and every page answers "File not found". Apfelschule's
Makefile repairs that case itself since 2026-10-04 (`seed-public`, run by `make
build`: a volume that exists without `index.php` gets `./public`); with an
older Makefile, by hand:

```sh
docker run --rm -v <app>-public:/to -v "$PWD/public":/from:ro alpine cp /from/index.php /to/
docker exec <app>-web-1 php bin/console assets:install public --symlink --relative
```

## Stopping a site

`make down` (`docker compose down`, **without `-v`**): the containers and the
site's two networks go, the volumes (database, public, vendor, storage) stay,
and `make up` brings the site back as it was. Not `docker compose stop`: it
keeps the networks, each holding a subnet of Docker's address pool, and with
enough stopped sites the next `up` fails on "could not find an available,
non-overlapping IPv4 address pool among the defaults to assign to the
network". `-v` deletes the volumes: the database with them.

## Steps

1. Copy the files of the first part - tracked files only, neither `.env.local` nor `.htpasswd`; rename (`APP_NAME`, ports, `composer.json`, `package.json`); give the two networks their subnets.
2. `make install-dev` (composer and yarn in the setup containers, `COMPOSER_ALLOW_SUPERUSER=1`): see the order above.
3. `make doctrine-migration`, then `make doctrine-migrate` and `make database-test`.
   These run from a script or an agent as well as from a terminal: the
   Makefile's `DOCKER_WEB`, `DOCKER_DATABASE`, `DOCKER_PROXY` and `DOCKER_ASSETS`
   pass `docker exec` the `TTY_FLAG` (`-ti` when standard input is a terminal,
   `-i` otherwise), and so do `make shell` / `make shell-debug` given `ARGS`.
   Apfelschule's `DOCKER_DATABASE` and `DOCKER_PROXY` used a bare `-ti`, and
   `make database-test` stopped on "the input device is not a TTY" outside a
   terminal (fixed 2026-10-04): a site copied from an older Makefile changes
   those two lines the same way.
4. Write the site's `SettingsSectionInterface` classes for its own settings and keys.
5. `docker exec <app>-web-1 php vendor/bin/phpunit`: green before the first commit.

Apfelschule moved onto the shared bricks in `migrations/Version20261004090000.php`
(textOverride → layoutTextOverride, blog_comment → threadComment, the hours
tables): the model for the existing sites when they are attached one by one.
