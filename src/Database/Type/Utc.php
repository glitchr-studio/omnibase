<?php

namespace Base\Database\Type;

/**
 * Moments in UTC, for the code around a `utc_datetime_immutable` column: the
 * present, and any date as the same moment in UTC - what a query parameter
 * compared with such a column must be when it is bound without its type.
 *
 *     ->setParameter('since', Utc::from($since))                                  // or, typed:
 *     ->setParameter('since', $since, UtcDateTimeImmutableType::NAME)
 */
final class Utc
{
    private static ?\DateTimeZone $zone = null;

    public static function zone(): \DateTimeZone
    {
        return self::$zone ??= new \DateTimeZone('UTC');
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::zone());
    }

    /** Any date, as the same moment in UTC. */
    public static function from(?\DateTimeInterface $moment): ?\DateTimeImmutable
    {
        return $moment ? \DateTimeImmutable::createFromInterface($moment)->setTimezone(self::zone()) : null;
    }
}
