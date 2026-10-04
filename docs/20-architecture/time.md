---
title: Time and time zones
order: 30
---

# Time and time zones

omnibase sets PHP's time zone **per visitor**: `BaseBundle::boot()` reads the
visitor's zone in the `USER/INFO` cookie (written by the page's script) and calls
`date_default_timezone_set()` with it, UTC without one. `new \DateTime()`,
`date()` and Twig's `|date` are therefore the visitor's clock: "today" is the
visitor's day.

What a column holds depends on its Doctrine type:

| Type | Column | Written | Read back |
|---|---|---|---|
| `datetime`, `datetimetz` | DATETIME | in UTC (`Base\Database\Type\DateTimeTypeUTC`, with `base.database.use_custom`) | as UTC |
| `datetime_immutable` | DATETIME | the wall clock of the object's zone | in the **request's** zone |
| **`utc_datetime_immutable`** | DATETIME | in UTC | as UTC |
| **`utc_datetime`** | DATETIME | in UTC | as UTC |
| `date`, `date_immutable` | DATE | a calendar day, no zone | the same day |

`datetime_immutable` is Doctrine's own: the same instant is stored at
different hours by a visitor in Paris and one in Tokyo, and a row written by
one comes back as another moment to the other. It is right for a **wall
clock** (a reservation at 20:00 in the restaurant, whoever looks at it) and
wrong for a **moment** (when a message was sent, when a link expires).

## A moment: `utc_datetime_immutable`

```php
use Base\Database\Type\UtcDateTimeImmutableType;

#[ORM\Column(type: UtcDateTimeImmutableType::NAME)]   // 'utc_datetime_immutable'
private \DateTimeImmutable $sentAt;

#[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
private ?\DateTimeImmutable $expiresAt = null;
```

The bundle registers the type (and `utc_datetime`, its `\DateTime` twin):
nothing to configure. Whatever zone the object given carries, the column gets
the moment in UTC; what comes back is a `\DateTimeImmutable` in UTC, the same
moment for every request. To show it, `{{ message.sentAt|date('d/m/Y H:i') }}`
converts to PHP's zone (the visitor's); in PHP,
`->setTimezone(new \DateTimeZone('Europe/Paris'))`.

In a query, bind the parameter with the type, or as a UTC moment
(`Base\Database\Type\Utc`):

```php
$qb->andWhere('m.sentAt >= :since')
   ->setParameter('since', $since, UtcDateTimeImmutableType::NAME);
// or
   ->setParameter('since', Utc::from($since));          // the same moment, in UTC
Utc::now();                                             // the present, in UTC
```

A `\DateTimeInterface` bound without a type is formatted in its own zone, and
compared as text with a column that holds UTC.

## Existing columns

Nothing changes for a column that is not given the type: `datetime_immutable`
keeps Doctrine's behaviour, `datetime` stays UTC.

Moving a column from `datetime_immutable` to `utc_datetime_immutable` changes
no schema (both are the platform's DATETIME: `doctrine:migrations:diff` finds
nothing on DBAL 4), but it changes what the stored values **mean**: rows
written by requests in another zone than UTC hold that zone's wall clock. A
site whose visitors have no `timezone` cookie yet (PHP was in UTC) has nothing
to convert; otherwise the migration that accompanies the change shifts the
rows from the zone they were written in:

```sql
UPDATE office_appointment SET startsAt = CONVERT_TZ(startsAt, 'Europe/Paris', 'UTC');
```

## For bundles

`Base\Restaurant\Model\Instant` and `Base\Office\Database\UtcDateTimeImmutableType`
answered the same need before the core did; a bundle now names the core's type
and drops its own (`utc_datetime_immutable` is the same name as office's: its
columns need no change).
