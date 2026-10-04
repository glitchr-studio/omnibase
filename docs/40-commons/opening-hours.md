---
title: Opening hours
order: 41
---

# Opening hours

`Base\Service\OpeningHours` says when the place is open: the usual week
(`Base\Entity\Hours\WeekDayHours`, one row per ISO day, else
`base.opening_hours.week`) and the days off it (`Base\Entity\Hours\SpecialDay`:
closed, or open at other hours, for one day or a run of days).

```php
public function __construct(private OpeningHours $hours) {}

$this->hours->isOpenAt();                    // now, in base.opening_hours.timezone
$this->hours->hoursOn($day);                 // [['09:00', '13:00'], ['16:30', '19:00']], special days included
$this->hours->orderDay();                    // the day an order made now is for
$this->hours->slots($day, step: 30);         // ['12:00-12:30', ...] not before now + 30 min
$this->hours->summary();                     // the footer's lines: days in a row with the same hours together
$this->hours->notices(days: 14);             // special days to announce
```

Saving the week and a day off:

```php
$weekDays->save([3 => [['09:00', '13:00']], 6 => [['09:00', '18:00']]]); // WeekDayHoursRepository
$em->persist((new SpecialDay(new \DateTime('2026-12-24'), new \DateTime('2026-12-26'), 'Noël')));
```

In a template, `opening_hours()` is the service:

```twig
{% for line in opening_hours().summary() %}…{% endfor %}
<script type="application/ld+json">{{ ({'@context': 'https://schema.org', '@type': 'LocalBusiness', name: site.name}|merge(opening_hours().schema()))|json_encode|raw }}</script>
```

`schema()` gives schema.org's `openingHoursSpecification` and
`specialOpeningHoursSpecification` (a closed day opens and closes at 00:00):
Google reads the days off from there.

## Several places

A site with one place needs nothing more. With several (shops, practices,
rooms), each place is a **scope**: a key its owner chooses (`'store:12'`), or
an entity (`OpeningHours::scopeOf($store)` is its class and id).

```php
$hours = $this->hours->for($store);          // or ->for('store:12'): the same service, for that place
$hours->isOpenAt(); $hours->hoursOn($day); $hours->schema(); // ... every method above

$scopedWeeks->save('store:12', [1 => [['08:00', '12:00']]]);  // ScopedWeekRepository: the place's own week
$scopedWeeks->forget('store:12');                             // back to the site's
$em->persist(new SpecialDay($from, $until, 'Travaux', 'store:12'));
```

```twig
{% for line in opening_hours(store).summary() %}…{% endfor %}
```

- **The week**: the place's own (`Base\Entity\Hours\ScopedWeek`, one row per
  place) when it has one, else the site's.
- **The special days**: the place's own and the site's; on a date both cover,
  the place's wins (a shop open on a day the company is closed).
- The injected service and `opening_hours()` without an argument stay the
  whole site's: `for()` returns a new instance.

An update of `glitchr/omnibase` adds the table `hoursScopedWeek` and a
nullable `scope` column (indexed) to `hoursSpecialDay`; `hoursWeekDay` is
untouched.

Tests and a change made in the same request: `withWeek([...])` and
`withSpecialDays([...])` replace what would be read from the database.
