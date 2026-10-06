---
title: The demo environment
order: 45
---

# The `demo` environment

A fourth environment beside dev, test and prod: `APP_ENV=demo` runs a site as
a **demonstration** - production's behaviour (no debug), a database of its
own, and accounts that are always there, one for each role of the trade. A
visitor opens the sign-in page, clicks "Médecin" or "Patient", and is in.

Everything below lives in glitchr/omnibase and is active when
`kernel.environment` is `demo`, and only then. In dev, test and prod none of
it is in the container: no route, no listener, no header. What an application
adds to run it is listed in [Scaffolding a site](../scaffold.md#demo).

## Declaring the accounts

A bundle declares the roles of its trade, the application its own, by
implementing `Base\Demo\DemoAccountProviderInterface` (autoconfigured, tag
`base.demo_account_provider`):

```php
namespace App\Demo;

use Base\Demo\DemoAccount;
use Base\Demo\DemoAccountProviderInterface;

final class DemoAccounts implements DemoAccountProviderInterface
{
    public function getDemoAccounts(): iterable
    {
        yield new DemoAccount(
            identifier: 'client',                      // what it signs in with (User::$userIdentifier)
            label: 'demo.client.label',                // the button: a translation key, or the text itself
            description: 'demo.client.description',    // one sentence on what this role sees
            roles: ['ROLE_USER'],                      // its own roles
            position: 20,                              // the order on the sign-in page
        );
        yield new DemoAccount('docteur', '@health.demo.practitioner.label', '@health.demo.practitioner.description',
            group: 'Praticiens', groupRoles: ['ROLE_PRACTITIONER']);   // a Base\Entity\User\Group and its roles
    }
}
```

- The password is the identifier unless `password:` says otherwise, the
  address `<identifier>@example.org` unless `email:` does. A demonstration
  account is public: that is what it is for.
- `Base\Demo\DemoAccountRegistry` gathers every declaration. The
  application's replaces a bundle's for the same identifier;
  `base.demo.exclude: [kine]` drops the ones a site has no use for.
- **A super-administrator is never a demonstration account.** A declaration
  whose roles reach `ROLE_SUPERADMIN` - its own, its group's, or through
  `security.role_hierarchy` (a `ROLE_EDITOR` above it) - is refused with a
  `LogicException` as soon as the registry is read, in every environment.

### In the fixtures

`Base\Demo\DemoAccountFactory` turns the declarations into rows, so that dev,
test and demo have the same accounts and nobody writes them twice:

```php
final class SiteFixtures extends Fixture
{
    public function __construct(private readonly DemoAccountFactory $accounts) {}

    public function load(ObjectManager $manager): void
    {
        $docteur = $this->accounts->account('docteur', $manager);   // the database's, or created from its declaration
        $this->accounts->load($manager);                            // every declared account

        // ...the practice's team, agenda and files, attached to $docteur
        $manager->flush();                                          // the factory flushes nothing
    }
}
```

An account the database already has is returned as it is: fixtures that
create theirs themselves keep them, identifier included. A group is created
with `groupRoles` when the database has none of that name, and left alone
otherwise.

## On the sign-in page

`@Base/demo/_accounts.html.twig` prints "Comptes de démonstration": one button
for each declared account that exists. omnibase's own sign-in page includes
it; an application with its own `templates/security/login.html.twig` adds the
line under its form:

```twig
{{ include('@Base/demo/_accounts.html.twig') }}
```

Each button is a **POST form with a CSRF token** to `security_loginDemo`
(`/login/demo`) - never a link: a GET that signs in is followed by a prefetch,
a crawler, a link preview. The route is declared for the `demo` environment
only. `Base\Controller\DemoController` signs in a declared account and
nothing else: an identifier nobody declared, or an account promoted to
super-administrator since, is sent back to the sign-in page.

The partial prints nothing outside `demo`. Its look hangs on
`.base-demo-accounts` (it inherits the page's colours). A button and its
sentence stand side by side; in a narrow place - a sign-in card of some
twenty rem - the sentence goes under its button, full width (the flex line
folds when less than 14rem are left beside the button: no script, no query
on the window). To rewrite it,
`templates/bundles/BaseBundle/demo/_accounts.html.twig`. In a template of your
own: `demo_mode()` (true in `demo`) and `demo_accounts()` (the list).

## The banner

`@Base/demo/_banner.html.twig` prints "Démonstration — données remises à zéro
chaque nuit" (fr, en: the `demo` translation domain). A layout includes it
once, first thing in `<body>`; omnibase/admin's layout does, so the back
office shows it too:

```twig
<body>
    {{ include('@Base/demo/_banner.html.twig') }}
```

Two custom properties set its colours (`--demo-banner-bg`,
`--demo-banner-fg`); `templates/bundles/BaseBundle/demo/_banner.html.twig`
rewrites it.

## `demo:reset`

```sh
php bin/console demo:reset                # the fixtures, the uploaded files, the caches
php bin/console demo:reset --keep-files   # the database and the caches only
```

In order: it **refuses outside `demo`** (and on what the guard below
refuses); takes a lock (`symfony/lock`, the `lock.factory` service: two resets
never overlap); empties the directories of `base.demo.reset.purge`; runs
`doctrine:fixtures:load`; creates the declared accounts the fixtures left out;
deletes the uploaded files no row names any more (`uploader:entities
--delete-orphans`, unless `base.demo.reset.orphans: false`); empties every
cache pool and Doctrine's second-level cache. Each step is written to the
output and to the log; the exit code is not 0 when one failed.

It is written for the `cron` container. The crontab is the same in every
environment, so the line checks the environment itself:

```cron
30 3 * * *  [ "$APP_ENV" = demo ] && php /srv/app/bin/console demo:reset >> /srv/app/var/log/cron.demo-reset.log 2>&1
```

```yaml
# config/packages/base.yaml
when@demo:
    base:
        demo:
            reset:
                purge:                                   # storages of the application's own
                    - '%kernel.project_dir%/var/storage/documents'
```

The sessions open at that moment end: the accounts are new rows.

**Give the demonstration storages of its own** (`when@demo` in
`flysystem.yaml`: `var/storage/demo/uploads`...), name that directory in
`purge`, and turn the orphan search off (`orphans: false`): everything a
visitor sent is then in one place that no other environment writes to. The
orphan search reads the uploads through `public/uploads`, a link that points
at the storage of whichever environment cleared its cache last - right on a
server that runs the demonstration alone, not on a machine that runs dev from
the same checkout.

## The safeguards

| | In `demo` |
|---|---|
| **E-mail** | None leaves. `Base\Subscriber\DemoSubscriber` refuses every message where the mailer hands it over (`MessageEvent::reject()`), as it is queued and as it is sent, **whatever `MAILER_DSN` says**; recipients and subject go to the `demo` log channel. |
| **Indexing** | `X-Robots-Tag: noindex, nofollow` on every response. |
| **A demonstration account's credentials** | Its identifier, address, password, one-time code, e-mail code and backup codes do not change; it is not disabled nor deleted. |
| **The staff's second factor** | `base.security.two_factor.required_roles` and the administrator's "mandatory for everyone" are lifted **for the demonstration accounts**; any other account is asked as usual. |
| **The super-administrator** | Signs in with `base.demo.superadmin_password` (the `DEMO_SUPERADMIN_PASSWORD` secret) or not at all. |
| **Starting** | Refused in debug, and on the production database. |
| **Payments** | omnibase/marketplace offers its `dev` gateway and none of glitchr/omnitrade's (its `docs/dev-gateway-and-keys.md`). |

### E-mail: refused, not looped back

The two ways were a null transport and the "technical loopback" of dev
(every message rewritten to one mailbox). The demonstration takes the first,
and enforces it in the bundle rather than leaving it to `MAILER_DSN`: a
visitor signed in as "patient" types any address in a form, and a loopback
still delivers - to a mailbox someone then has to empty, and to a real
person the day the loopback is misconfigured. A message that is never handed
to a transport cannot reach anyone. An application still sets
`framework.mailer.dsn: 'null://null'` under `when@demo`, so that nothing is
even attempted by a library that would bypass Symfony's mailer.

What would have gone is in the log, at the `info` level of the `demo`
channel (production's `fingers_crossed` handler keeps it for the context of
an error; `bin/console ... -vv` shows it):

```
demo.INFO: Demonstration: e-mail not sent. {"to":["patient@example.org"],"subject":"Votre rendez-vous","queued":false}
```

### What a demonstration account cannot change

Every visitor signs in with the same account: whoever changed its password
would lock the next one out until the night's reset.

- omnibase's pages refuse with a message (`@notifications.demo.locked`): the
  second factor (`/settings/2fa`, its switches), closing the account
  (`/account-goodbye`), a password reset asked for or followed. The settings
  page says so first. They ask `SecurityPolicy::isDemoAccount($user)` /
  `canChangeCredentials($user)`: use the same in a page of your own.
- For every other form - the application's "my account" page, the back
  office's users - `Base\DatabaseSubscriber\DemoAccountLockSubscriber` puts
  the values back before the row is written (and flashes the same message),
  and answers 403 to a deletion. Only during a request: the fixtures and
  `demo:reset` write these accounts from the console.

```php
$policy->isDemoAccount($user);          // a declared account, in the demo environment
$policy->canChangeCredentials($user);   // false for it
$policy->canEnableTwoFactor($user);     // false for it
$policy->needsEnrolment($user);         // false for it, whatever its roles
```

### The super-administrator

The fixtures give Glitch Art's account a password everyone knows
("glitchr" / "glitchr"); a demonstration is public. In `demo`,
`Base\Subscriber\DemoSuperAdminSubscriber` compares what is typed with
`base.demo.superadmin_password` - by default
`%env(default::DEMO_SUPERADMIN_PASSWORD)%` - and never looks at the stored
password. Unset or empty, an account whose roles reach `ROLE_SUPERADMIN`
cannot sign in by any means ("This account is not available in the
demonstration."). It is not listed on the sign-in page and not signed in by
`/login/demo` either way.

`DEMO_SUPERADMIN_PASSWORD` is a secret of the deployment: a real environment
variable or `.env.demo.local` (git-ignored) on the demonstration's server.
Never `.env.demo`, which is committed.

### What it refuses to start on

`Base\Demo\DemoGuard`, asked by `BaseBundle::boot()` - for a page as for a
command - throws `Base\Exception\DemoRefusedException` when:

- **debug is on**: `APP_DEBUG=0` (`bin/console --env=demo --no-debug`);
- **a connection reaches the production database**: the application names it,
  and the guard compares server, port and database name (not the user: the
  same database under another account is the same database);

  ```yaml
  when@demo:
      doctrine:
          dbal:
              connections:
                  default:
                      dbname_suffix: '_demo'            # <database>_demo, as the tests have <database>_test
      base:
          demo:
              production_database: 'mysql://%env(DOCTRINE_DATABASE_HOST)%:%env(DOCTRINE_DATABASE_PORT)%/%env(DOCTRINE_DATABASE)%'
  ```

- **`base.demo.production_database` is unset**: nothing could be compared,
  and `demo:reset` empties the database it is given.

## One configuration for prod and demo

The demonstration behaves as production does: what a file sets under
`when@prod` is shared, not copied. A YAML anchor does it within a file:

```yaml
# config/packages/monolog.yaml
when@prod: &prod
    monolog:
        handlers: ...

when@demo: *prod
```

and a merge key when demo adds something of its own:

```yaml
# config/packages/doctrine.yaml
when@prod: &prod
    framework:
        cache:
            pools: ...

when@demo:
    <<: *prod
    doctrine:
        dbal:
            connections:
                default:
                    dbname_suffix: '_demo'
```

(`<<` merges the top-level keys only: a key demo repeats replaces prod's
whole block of that name.) A setting that has to differ in dev and test only
is better turned round: the value at the root of the file, overridden under
`when@dev` and `when@test` - then prod and demo both read the root.

A real environment variable beats every `.env*` file, and the containers
receive `.env` and `.env.local` as real variables: `.env.demo` cannot change
a value `.env` defines. What differs in demo is therefore said in the
configuration (`when@demo`), and `.env.demo` holds only what `.env` leaves
out (the hosts, as `.env.prod` does).

## Reference

```yaml
base:
    demo:
        production_database: ~            # DSN of production's database; required in demo
        superadmin_password: '%env(default::DEMO_SUPERADMIN_PASSWORD)%'
        exclude: []                       # declared identifiers this site leaves out
        reset:
            purge: []                     # directories demo:reset empties before the fixtures
            groups: []                    # fixture groups it loads (--group); none: every fixture
            orphans: true                 # uploader:entities --delete-orphans after them
```

| | |
|---|---|
| `Base\Demo\DemoAccount`, `DemoAccountProviderInterface` | a declaration, and who declares |
| `Base\Demo\DemoAccountRegistry` | every declaration; `get()`, `of($user)`, `isSuperAdmin($user)` |
| `Base\Demo\DemoAccountFactory` | the rows: `account()`, `load()`, `missing()` |
| `Base\Demo\DemoMode` | `isActive()`: the `demo` environment, and no other |
| `Base\Demo\DemoGuard` | what demo refuses to start on |
| `Base\Console\Command\DemoResetCommand` | `demo:reset` |
| `tests/Demo/` | the suite; `Fixtures\DemoTestKernel` boots a host application in `demo` or `prod` |
