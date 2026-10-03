<?php

namespace Base\Service\Calendar;

/**
 * The "add to Google Calendar" link of a date: Google's own template page,
 * filled in. Hours go in UTC with the date's timezone beside them (ctz),
 * whole days as dates, the last one excluded as Google wants it.
 */
class GoogleCalendarLink
{
    public const ENDPOINT = 'https://calendar.google.com/calendar/render';

    public function for(CalendarEntry $entry): string
    {
        $start = $entry->start;
        if ($entry->allDay) {
            $dates = $start->format('Ymd').'/'.$entry->effectiveEnd()->modify('+1 day')->format('Ymd');
        } else {
            $utc = new \DateTimeZone('UTC');
            $dates = $start->setTimezone($utc)->format('Ymd\THis\Z').'/'.$entry->effectiveEnd()->setTimezone($utc)->format('Ymd\THis\Z');
        }

        return self::ENDPOINT.'?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $entry->title,
            'dates' => $dates,
            'details' => trim($entry->description."\n\n".$entry->url),
            'location' => (string) $entry->location,
            'ctz' => $entry->timezone()->getName(),
        ], '', '&', \PHP_QUERY_RFC3986);
    }
}
