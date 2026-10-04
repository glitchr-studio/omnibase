---
title: Entities and repositories
order: 25
---

# Entities and repositories

## Repositories

`Base\Database\Repository\ServiceEntityRepository` is Doctrine's service
repository plus the finder language (`findByStateAndLocale()`,
`cacheOneBySlug()`, `countByDomain()`...).

**Counting gives an integer.** `count([])`, `count(['state' => 'draft'])`,
`countByState('draft')` and `distinctCountForLocaleByDomain('messages')` each
run one `SELECT COUNT(...)` and return an `int`, as Doctrine's `count()`
promises. Only a grouped count (a `$groupBy` or a `$selectAs` argument) returns
rows, each with its `count` (`ThreadRepository::countForChildrenIn()`).

**What is registered as a repository.** A bundle maps its `src/Repository`
(`setMapping()` in its Bundle class): every class there is aliased under the
application's namespace, and the ones that are repository services are
registered (`doctrine.repository_service`, built with `doctrine`). A repository
service is a class whose name ends with `Repository`, that can be instantiated
and implements Doctrine's `ServiceEntityRepositoryInterface`
(`AbstractBaseBundle::isRepositoryService()`). An abstract base repository, an
interface or a trait in that directory is aliased, never registered: a bundle
may keep `AbstractCatalogueRepository` beside the repositories that extend it.

## Translations

An entity using `TranslatableTrait` reads and writes its translated fields
through `translate()`:

```php
$post->translate('de')->setTitle('Apfel');   // written in German, persisted at the next flush
$post->translate('ja')->getTitle();          // null when nothing is written in Japanese; creates nothing
$post->translate()->getTitle();              // what the current page shows
```

- `translate($locale)` is that locale's translation. When the entity has none,
  it is a new, empty one the entity holds aside (out of `getTranslations()`)
  until a value is written in it: **a read creates no translation**. A written
  one joins the collection at the next `getTranslations()` or flush
  (`commitPendingTranslations()`, called by `IntlSubscriber` before each flush).
- `translate()` without a locale is the translation to show on the current
  page: the page's language when something is written in it, else the same
  language in another region (`pt-BR` on a `pt-PT` page), else the **default
  locale's**, else the first available locale's, else any written one.
- The magic getters (`$post->getTitle()`, `post.title` in Twig) read
  `translate()`, then the default locale when the value is null.

An `isEmpty()` translation is never persisted (`IntlSubscriber::onFlush`).

## Countries

`{{ code|country_name }}` names a country from its ISO 3166-1 code, in the
page's language; `{{ code|country_name('de') }}` in the one given
(`Localizer::getCountryName()`, on `symfony/intl`). `JP` is "Japon" on a French
page; alpha-3 (`JPN`) is read too; an unknown code is printed as it is. The
country of a locale (`fr-BE` → "Belgium") is `|locale_country_name`.
