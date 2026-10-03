---
title: Calendar
order: 42
---

# Calendar

`Base\Service\Calendar\Ics` writes iCalendar files (RFC 5545: CRLF, lines
folded at 75 octets without cutting a character, escaped texts, a VTIMEZONE
for each timezone used, whole days as dates) and `GoogleCalendarLink` the
"add to Google Calendar" link. Both read a `CalendarEntry`, so any date of any
bundle can be written: map your entity to one.

```php
use Base\Service\Calendar\CalendarEntry;

$entry = new CalendarEntry(
    uid: $appointment->getId().'@'.$host,
    title: 'Consultation',
    start: $appointment->getStartsAt(),          // in the timezone it happens in
    end: $appointment->getEndsAt(),              // null: start + defaultDuration (PT2H)
    description: "Bring your card\nSecond floor",
    location: '12 rue de la Paix, Paris',
    url: $urls->generate('app_appointment', [...], UrlGeneratorInterface::ABSOLUTE_URL),
    cancelled: $appointment->isCancelled(),
);

return new Response($ics->calendar([$entry], 'Mes rendez-vous'), 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
$link = $google->for($entry);
```

`Ics::escape()`, `Ics::fold()` and `Ics::unfold()` are public for whoever
writes or reads a line by hand. `omnibase/agenda` maps its `Event` through
`Event::toCalendarEntry()`.
