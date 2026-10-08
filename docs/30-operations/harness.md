---
title: The Docker harness
order: 50
---

# The Docker harness

`docker/` builds this checkout into one Symfony application with every
`omnibase/*` plugin (admin, marketplace, forge, forum, mailbox, docs), paying
through `glitchr/omnitrade`, shipping through `glitchr/omnibus` and signing through
`glitchr/omnisign` (a DocuSeal gateway, its recorded answers in the tests), on SQLite.
It is where the bundles' suites run: there is no PHP on the host.

```sh
cd docker
docker compose -f compose.yml -f compose.checkouts.yml run --rm omnibase test              # glitchr/omnibase's suite
docker compose -f compose.yml -f compose.checkouts.yml run --rm omnibase test admin        # a plugin's: admin, marketplace, forge...
docker compose -f compose.yml -f compose.checkouts.yml run --rm omnibase test forge --filter License
docker compose -f compose.yml -f compose.checkouts.yml run --rm omnibase check             # container and templates linted
docker compose -f compose.yml -f compose.checkouts.yml up                                  # the demo, http://localhost:8000/
```

`compose.checkouts.yml` mounts the checkouts beside this one (`../admin`,
`../marketplace`...) over the installed packages: what is edited is what runs,
no rebuild needed. Without it, the plugins are the Packagist ones.

## What `test` sets up

- **The test environment.** `APP_ENV=test`; PHPUnit starts from
  `tests/harness.php`, which loads `.env` then `.env.test` (as an application's
  own `tests/bootstrap.php` does), then the suite's own bootstrap (it registers
  the suite's test namespace). `docker/app/.env.test` holds test values for
  every variable the bundles' configuration reads: `KERNEL_CLASS`,
  `DATABASE_URL`, `DEFAULT_URI`, `CORS_ALLOW_ORIGIN`,
  `GOOGLE_RECAPTCHA_SITE_KEY` / `GOOGLE_RECAPTCHA_SECRET` (Google's public test
  keys), `MAILER_DSN`, `MAILER_CONTACT`, `MESSENGER_TRANSPORT_DSN`, the Stripe
  keys (empty). A suite that dies before its first test on a missing variable
  wants a line there.
- **A fresh database.** `var/test.db` (SQLite) is deleted, and its schema
  created from every mapped entity, at each run; the test container cache
  (`var/cache/test`) too.

The suites that need a host application (`KernelTestCase`: core's analytics,
admin's widgets, the forge's entities) run here as they do inside an
application. Those that need rows to look at skip when the fresh database has
none.

## SQLite

The harness's database is SQLite; the applications run MySQL/MariaDB. Code
that writes SQL by hand says so per platform: the analytics counters
(`Base\Repository\Analytics\*`) upsert with `ON DUPLICATE KEY UPDATE` /
`INSERT IGNORE` on MySQL and `ON CONFLICT` elsewhere
(`VisitRepository::insertIgnore()`), and count distinct pairs over a `SELECT
DISTINCT` subquery rather than MySQL's `COUNT(DISTINCT a, b, c)`.

## Rebuilding the image

The image installs the plugins and the sibling families from Packagist, their
development branches (`glitchr/omnitrade` 1.x with its catalogue,
`omnitrade/stripe`, `glitchr/omnibus` 2.x and two carriers). Docker keeps that
layer until this checkout changes; to fetch the families' latest commits:

```sh
docker compose -f compose.yml -f compose.checkouts.yml build --build-arg OMNI_REFRESH=$(date +%s) omnibase
```

`docker/app/` (configuration, `.env.test`, `tests/harness.php`) is copied into
the image: a change there needs a build too.
