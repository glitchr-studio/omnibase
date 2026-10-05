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
| `symfony.lock` | nothing - **it is copied with `composer.json`** (see below); `composer.lock` is not, the first `composer install` writes it |
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

### `symfony.lock` comes with `composer.json`

`symfony.lock` is Flex's record of the recipes already applied, one entry per
package. Without it Flex takes every package of the first `composer install`
for a new one and **plays every recipe again**: it rewrites
`config/bundles.php`, drops its default `config/packages/*.yaml`,
`config/routes/*.yaml`, `.env` blocks, `src/Kernel.php`, `public/index.php`
and `bin/console` over the files just copied from Apfelschule - the site then
boots on Symfony's defaults instead of omnibase's configuration (or does not
boot: two Doctrine configurations, a second security firewall).

So the copy takes `symfony.lock` (it is tracked: `git ls-files` and
`git archive` include it). After the first install, `git status` must show no
change under `config/`; a package added later is a `composer require`, whose
recipe adds its entry to the lock - commit it with the rest.

A copy made without it is repaired before the first install: take
Apfelschule's `symfony.lock`, remove the entries of the packages the new site
does not require (or leave them: an entry without its package is ignored).
If the recipes already ran, `git checkout -- config/ .env src/Kernel.php
public/index.php bin/console` on a copy that was committed first, else copy
those paths again.

### Fonts: `@fontsource` 5.3 and its `exports`

The sites install their fonts from `@fontsource/<font>` (`^5.2` in
`package.json`) and import the files they use in
`assets/styles/app-async.scss`:

```scss
@import "@fontsource/andika/latin-400.css";      // <subset>-<weight>[-italic].css
@import "@fontsource/andika/latin-700.css";
```

From 5.3.0 a fontsource package declares an `exports` map, and the bundler
(webpack's resolver, which sass-loader and css-loader use) refuses every path
the map does not list. What it lists, and so what still resolves by package
name: the package itself (`@fontsource/andika`: `index.css`), the stylesheets
at its root (`/latin-400.css`, `/400.css`, `/latin.css`...), the font files
(`/files/andika-latin-400-normal.woff2`), and `/scss` (the metadata, from
Sass only).

What no longer resolves by package name - "Package path ./scss/mixins is not
exported from package …/@fontsource/andika" - is anything else inside the
package, the Sass mixins first:

```scss
@use "@fontsource/andika/scss/mixins" as andika;                          // 5.3: refused
@use "../../node_modules/@fontsource/andika/scss/mixins.scss" as andika;  // by relative path: resolves
```

A **relative path** through `node_modules` is not a package request: the
`exports` map does not apply to it, and it resolves whatever the version.
Use it for the paths the map leaves out; keep the package name for the
stylesheets above, which it lists. (Checked on 5.3.0 with webpack's
enhanced-resolve 5.26, sass-loader 16 and css-loader 7 - the versions the
sites build with.)

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

### Secrets {#secrets}

`bin/console secrets:set`, `secrets:generate-keys` and `secrets:remove` write
under `config/secrets/<env>/`. **The web container cannot**: it mounts the
project read-only (`.:/srv/app:ro`; only `var/`, `vendor/` and `public/` are
writable), and the command fails on "Unable to create the secrets directory
(/srv/app/config/secrets/dev)" - a `make` shortcut that runs in `<app>-web-1`
fails the same way.

Run them where the project is writable: the one-shot **composer** service of
`docker-compose.setup.yml`, which mounts `.:/srv/app:rw` and reads the same
`.env` and `.env.local`:

```sh
# the key pair of an environment (once per environment; see Sealed fields)
docker compose -f docker-compose.yml -f docker-compose.setup.yml run --rm --entrypoint "" composer \
    php bin/console secrets:generate-keys

# a secret, read from standard input so that it stays out of the shell's history
printf %s "$STRIPE_SECRET" | docker compose -f docker-compose.yml -f docker-compose.setup.yml run --rm -T --entrypoint "" composer \
    php bin/console secrets:set STRIPE_API_KEY -

# production's: with the public key alone, on any machine
docker compose -f docker-compose.yml -f docker-compose.setup.yml run --rm --entrypoint "" composer \
    php bin/console secrets:set STRIPE_API_KEY --env=prod
```

(`--entrypoint ""` skips the service's own entrypoint, which runs a whole
`composer install`. On a machine with PHP, `php bin/console secrets:set …`
from the project's directory does the same.)

Then: commit `config/secrets/<env>/` **except** `prod.decrypt.private.php`
(git-ignored: check `.gitignore` has a line for
`config/secrets/prod*/prod*.decrypt.private.php`), and clear the cache of the
running site (`docker exec <app>-web-1 php bin/console cache:clear`): the
secrets are read when the container is compiled. Reading them works in the
web container: `make secrets` (`secrets:list --reveal`).

The same key pair seals the fields marked `#[Vault]` - the API keys typed in
the back office - and without it they are refused, not stored in clear: see
[Sealed fields](20-architecture/vault.md).

## The demonstration: what a site adds {#demo}

`APP_ENV=demo` runs the site as a demonstration ([The demo
environment](20-architecture/demo.md)): production's behaviour, a database of
its own (`<database>_demo`), one button for each role on the sign-in page, a
reset every night. Everything is in glitchr/omnibase; a site adds these lines.

**`.env.demo`** (committed: defaults only, as `.env.prod`; no secret):

```sh
HTTPS=on
HTTP_REDUCTION=true
HTTP_DOMAIN='["demo.example.org", "localhost"]'
HTTP_SUBDOMAIN='["", "www"]'
HTTP_MACHINE='[""]'
HTTP_BASEDIR=/
HTTP_PORT='[80,8126]'
HTTPS_PORT='[443,8643]'
USE_SYS_TMPDIR=false
# DEMO_SUPERADMIN_PASSWORD is NOT here: the super-administrator's password in
# the demonstration is a secret of the server (.env.demo.local, git-ignored,
# or a real variable). Unset, that account cannot sign in.
```

A real variable beats every `.env*` file, and the containers get `.env` as
real variables: `.env.demo` cannot change what `.env` defines. What differs in
demo is said under `when@demo`.

**`config/bundles.php`** - the fixtures are loaded in demo:

```php
Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class => ['dev' => true, 'test' => true, 'demo' => true],
```

(`doctrine/doctrine-fixtures-bundle` is in `require-dev`:
`deployments/docker/composer/entrypoint.sh` installs the dev packages for
demo too - a `demo*) composer install --no-interaction ;;` beside `test*)`.)

**`config/services.yaml`** - the fixtures are services where the bundle is:

```yaml
when@dev: &fixtures
    services:
        App\DataFixtures\:
            resource: '../src/DataFixtures/'
            autowire: true
            autoconfigure: true

when@demo: *fixtures
```

**`config/packages/doctrine.yaml`** - production's pools, a database of its own:

```yaml
when@prod: &prod
    framework:
        cache:
            pools:
                doctrine.result_cache_pool: { adapter: cache.app }
                doctrine.system_cache_pool: { adapter: cache.system }

when@demo:
    <<: *prod
    doctrine:
        dbal:
            connections:
                default:
                    dbname_suffix: '_demo'
```

**`config/packages/base.yaml`** - which database production's is (the
demonstration refuses to start on it, and without this line):

```yaml
when@demo:
    base:
        demo:
            production_database: 'mysql://%env(DOCTRINE_DATABASE_HOST)%:%env(DOCTRINE_DATABASE_PORT)%/%env(DOCTRINE_DATABASE)%'
            reset:
                purge: []      # storages of the site's own that demo:reset empties
```

**Every other `when@prod`** (`monolog.yaml`, ...): `when@prod: &prod`, then
`when@demo: *prod` at the end of the file. **`config/packages/mailer.yaml`**:
`when@demo: { framework: { mailer: { dsn: 'null://null' } } }` (omnibase
refuses every message in demo whatever the DSN; this keeps anything else
from trying).

**`deployments/docker/cron/crontab`** - the same file in every environment,
so the line checks:

```cron
30  3  * * *  [ "$APP_ENV" = demo ] && php /srv/app/bin/console demo:reset >> /srv/app/var/log/cron.demo-reset.log 2>&1
```

**`config/packages/flysystem.yaml`** - the demonstration's files in
directories of its own, which `demo:reset` empties: nothing of another
environment is ever there (a development machine runs both from one checkout):

```yaml
when@demo:
    flysystem:
        storages:
            local.uploads: { local: { directory: '%kernel.project_dir%/var/storage/demo/uploads' } }
            local.wysiwyg: { local: { directory: '%kernel.project_dir%/var/storage/demo/wysiwyg' } }
            # and every other storage visitors write to (omnibase/office's local.share...)
```

```yaml
# config/packages/base.yaml, under when@demo's base.demo
            reset:
                purge: ['%kernel.project_dir%/var/storage/demo']
                orphans: false     # everything is in the directory above: no search among the uploads
```

**`Makefile`**:

```make
## Configure environment for the demonstration (production's behaviour, the database <name>_demo)
env-demo:
	@APP_ENV=demo APP_DEBUG=0 $(MAKE) env

## Create the demonstration's database (<name>_demo), its schema and its fixtures
database-demo:
	@$(DOCKER_DATABASE) mysql -h localhost -u root -p$(MYSQL_ROOT_PASSWORD) -e "CREATE DATABASE IF NOT EXISTS $(MYSQL_DATABASE)_demo; GRANT ALL ON $(MYSQL_DATABASE)_demo.* TO '$(MYSQL_USER)'@'%'; FLUSH PRIVILEGES;"
	@$(DOCKER_WEB) php bin/console doctrine:migrations:migrate --env=demo --no-debug --no-interaction
	@$(DOCKER_WEB) php bin/console doctrine:fixtures:load --env=demo --no-debug --no-interaction
```

**The keys** - what the fixtures need to seal (omnibase/office's
`SHARE_MASTER_KEY`, omnibase/mailbox's `MAILBOX_KEY`) is given in `.env.demo`
as **demonstration keys, known to everyone and used nowhere else**, as
`.env.test` does (base64 of `demo-key-demo-key-demo-key-00001`): what they
seal is the fixtures' fictional data, emptied every night. The one real
secret of a demonstration, `DEMO_SUPERADMIN_PASSWORD`, is the server's:
`.env.demo.local`, a real variable, or the demo vault (`secrets:generate-keys
--env=demo` then `secrets:set DEMO_SUPERADMIN_PASSWORD --env=demo`, see
[Secrets](#secrets); its private key stays on the server, as production's -
`.gitignore`: `config/secrets/demo*/demo*.decrypt.private.php`). That key
pair is also what `#[Vault]` needs if the super-administrator types API keys
in the demonstration's back office ([Sealed fields](20-architecture/vault.md)).

**The templates** - one line each:

```twig
{# templates/base.html.twig (or the layout): first thing in <body> #}
{{ include('@Base/demo/_banner.html.twig') }}

{# templates/security/login.html.twig: under the form #}
{{ include('@Base/demo/_accounts.html.twig') }}
```

**The accounts** - a bundle of the trade declares its roles; the site adds
its own in `src/Demo/DemoAccounts.php` (`DemoAccountProviderInterface`), and
its fixtures take them from `Base\Demo\DemoAccountFactory` instead of
writing them a second time.

**`src/Kernel.php`** - only if it lists the environments it allows
(`getAllowedEnvs()`, Symfony 8.1's skeleton): add `'demo'`.

Then:

```sh
make env-demo && make up       # production's stack, APP_ENV=demo
make database-demo             # once
```

On a development machine, to look at it without leaving the dev stack: the
web container alone in demo, by an override of two lines
(`services: { web: { environment: { APP_ENV: demo, APP_DEBUG: "0" } } }`,
`docker compose ... -f that-file.yml up -d web`), then `make database-demo`;
`make up` brings dev back. The links `public/uploads` and `public/images`
follow whichever environment cleared its cache last.

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

1. Copy the files of the first part - tracked files only, `symfony.lock` among them, neither `.env.local` nor `.htpasswd`; rename (`APP_NAME`, ports, `composer.json`, `package.json`); give the two networks their subnets.
2. `make install-dev` (composer and yarn in the setup containers, `COMPOSER_ALLOW_SUPERUSER=1`): see the order above.
3. Generate the key pairs (`secrets:generate-keys`, dev and test: see [Secrets](#secrets)), then `make doctrine-migration`, `make doctrine-migrate` and `make database-test`.
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
