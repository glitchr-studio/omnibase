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

Tests and a change made in the same request: `withWeek([...])` and
`withSpecialDays([...])` replace what would be read from the database.
