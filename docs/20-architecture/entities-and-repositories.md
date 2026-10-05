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

## A taxon's slug is unique per type

`Base\Entity\Thread\Taxon` is the root of a JOINED hierarchy: a FAQ's
`Section`, a classroom's `Subject`, a menu's `MenuSection`, a site's own
`Category` all share the table `threadTaxon` and its `slug` column. The slug
is unique **among the taxa of one type** - the unique index is on
`(class, slug)`, `class` being the discriminator - so a menu's section and a
blog's category may both be `desserts`. (It was unique on the column alone:
the second one became `desserts-2`, whatever its type.)

`#[Slugify(reference: 'translations.label', perType: true)]` is what numbers
a slug only against the entities of the same class; any hierarchy that shares
a slug column may use it.

**The migration an application generates** after updating omnibase
(`bin/console doctrine:migrations:diff`), on `threadTaxon`: the unique index
on `slug` is dropped, a unique index `thread_taxon_type_slug` on
`(class, slug)` and a plain index `thread_taxon_slug` on `slug` are created.
No row changes; the new constraint is weaker than the old one, so it always
applies. Until that migration has run, omnibase sees the old index and keeps
numbering slugs for all types together: nothing fails in between.

A taxon is found by its slug through the repository of its type
(`SectionRepository::findOneBySlug()`): `TaxonRepository` itself may now find
several types under one slug.
