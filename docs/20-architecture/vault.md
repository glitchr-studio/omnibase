---
title: Sealed fields (#[Vault])
order: 55
---

# Sealed fields: `#[Vault]`

`Base\Database\Attribute\Vault` seals the fields of an entity before they
reach the database, with libsodium (a sealed box, through Symfony's
`SodiumMarshaller`), and opens them when the entity is loaded. omnibase uses
it for the settings typed on the back office's "API keys" page
(`Base\Entity\Layout\SettingIntl::$value`); an application uses it for its
own secrets:

```php
use Base\Database\Attribute\Vault;
use Base\Database\Entity\Extension\VaultTrait;

#[ORM\Entity]
#[Vault(fields: ['apiKey'])]
class AssistantKey
{
    use VaultTrait;          // the `vault` column: null (not secured) or the environment whose key sealed the row

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $apiKey = null;
}

$key->setSecure(true);       // sealed from the next flush on, with the running environment's key
```

A row is sealed when its `vault` column names an environment (`setSecure(true)`,
`SettingBag::secure($path)`); a row whose `vault` is null is stored as it is.

## The key pair

The key is the one of **Symfony's secrets vault** for the environment:
`config/secrets/<env>/<env>.decrypt.private.php` (and its public half,
`<env>.encrypt.public.php`). One command creates both:

```sh
php bin/console secrets:generate-keys                 # the running environment (dev)
php bin/console secrets:generate-keys --env=test      # the test suite seals too
php bin/console secrets:generate-keys --env=prod
```

- **dev and test**: commit both files, as Symfony's documentation says for
  these environments (they protect nothing that is not in the repository
  already).
- **prod**: commit `prod.encrypt.public.php` only. The private key
  (`prod.decrypt.private.php`) goes to the server by hand, or as the
  `SYMFONY_DECRYPTION_SECRET` environment variable:

  ```sh
  php -r 'echo base64_encode(require "config/secrets/prod/prod.decrypt.private.php");'
  ```

  `#[Vault]` reads the file first, then that variable. **Losing the private
  key loses every sealed value**: keep a copy where the backups are not.
- **In Docker**, the web container mounts the project read-only: it cannot
  write `config/secrets/`. Run the command where the project is writable -
  the one-shot composer service - see [Scaffolding a site](../scaffold.md#secrets):

  ```sh
  docker compose -f docker-compose.yml -f docker-compose.setup.yml run --rm --entrypoint "" composer \
      php bin/console secrets:generate-keys
  ```

A row remembers the environment that sealed it: a database copied from
production to a development machine keeps its sealed values unreadable there
(they come back as the sealed text), which is the point.

## Without the key pair: refused, not stored in clear

When a secured field is about to be written and the vault has no key pair,
the flush fails with `Base\Exception\VaultKeyNotFoundException`:

> The "dev" vault has no key pair (…/config/secrets/dev/dev.decrypt.private.php):
> a secured value would be stored in clear, so it is refused. Generate the
> pair with "php bin/console secrets:generate-keys --env=dev" […]

The message names the vault, the file and the command, never the value.
omnibase/admin's "API keys" page checks first (`Vault::canSeal()`) and shows
the same explanation instead of saving.

Until omnibase 3.x of 2026-10-05 the value was written **unsealed, without a
word** - a site without keys believed its API keys were encrypted. A site
that wants that behaviour back says so:

```yaml
# config/packages/base.yaml
base:
    vault:
        allow_plaintext: true      # no key pair: secured fields are stored in clear
```

Reading never fails: a value stored in clear before the keys existed is read
as it is, and sealed at its next save.

### What an existing site does

1. Generate the pairs (dev, test, prod) as above.
2. Open the "API keys" page and save it once: the rows stored in clear are
   sealed. Or, in a migration or a command:
   `$settingBag->secure('api.stripe.secret')` for each path.
3. Nothing to migrate in the schema: the `vault` column and the fields keep
   their types.
