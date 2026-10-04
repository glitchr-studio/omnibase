<?php

namespace Base\Database\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeType;

/**
 * UtcDateTimeImmutableType's mutable twin (`utc_datetime`): a \DateTime
 * written in UTC and read back as UTC, whatever zone PHP is in.
 *
 * With base.database.use_custom (the default) omnibase's plain `datetime` is
 * UTC already; this type says so by name, and holds without that option. It
 * leaves the object it is given untouched (DateTimeTypeUTC moves the entity's
 * own \DateTime to UTC as it writes it).
 */
final class UtcDateTimeType extends DateTimeType
{
    public const NAME = 'utc_datetime';

    /** DBAL 3 asks a type its name, and marks the column with it (DBAL 4 does neither). */
    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = \DateTime::createFromInterface($value)->setTimezone(Utc::zone());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTime
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTime::createFromInterface($value)->setTimezone(Utc::zone());
        }

        // Unreadable in UTC, it is unreadable: the parent says so with the DBAL's own exception.
        return \DateTime::createFromFormat($platform->getDateTimeFormatString(), (string) $value, Utc::zone())
            ?: (date_create((string) $value, Utc::zone()) ?: parent::convertToPHPValue($value, $platform));
    }
}
