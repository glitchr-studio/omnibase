<?php

namespace Tests\Base\Database\Type;

use Base\Database\Type\Utc;
use Base\Database\Type\UtcDateTimeImmutableType;
use Base\Database\Type\UtcDateTimeType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use PHPUnit\Framework\TestCase;

/**
 * utc_datetime_immutable / utc_datetime: a moment is written in UTC and read
 * back as the same moment, whatever zone PHP was put in between the two
 * requests (omnibase sets it per visitor). Doctrine's own datetime_immutable,
 * shown beside it, moves the moment with the zone.
 */
class UtcDateTimeTypeTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    private function platform(): AbstractPlatform
    {
        return new SQLitePlatform();
    }

    public function testAMomentIsWrittenInUtcWhateverItsZone(): void
    {
        $type = new UtcDateTimeImmutableType();
        $paris = new \DateTimeImmutable('2026-10-07 09:00:00', new \DateTimeZone('Europe/Paris'));

        $this->assertSame('2026-10-07 07:00:00', $type->convertToDatabaseValue($paris, $this->platform()));
        $this->assertSame('2026-10-07 07:00:00', $type->convertToDatabaseValue(\DateTime::createFromImmutable($paris), $this->platform()));
        $this->assertNull($type->convertToDatabaseValue(null, $this->platform()));
    }

    public function testItIsReadBackAsTheSameMomentInAnotherRequestsZone(): void
    {
        $type = new UtcDateTimeImmutableType();
        $paris = new \DateTimeImmutable('2026-10-07 09:00:00', new \DateTimeZone('Europe/Paris'));

        date_default_timezone_set('Europe/Paris');
        $stored = $type->convertToDatabaseValue($paris, $this->platform());

        date_default_timezone_set('Asia/Tokyo'); // another visitor
        $read = $type->convertToPHPValue($stored, $this->platform());

        $this->assertInstanceOf(\DateTimeImmutable::class, $read);
        $this->assertSame('UTC', $read->getTimezone()->getName());
        $this->assertSame($paris->getTimestamp(), $read->getTimestamp());
        $this->assertNull($type->convertToPHPValue(null, $this->platform()));

        // Doctrine's own type reads the stored wall clock in the reader's zone: another moment.
        $plain = (new DateTimeImmutableType())->convertToPHPValue($stored, $this->platform());
        $this->assertNotSame($paris->getTimestamp(), $plain->getTimestamp());
    }

    public function testTheMutableTwinLeavesTheGivenObjectAlone(): void
    {
        $type = new UtcDateTimeType();
        $tokyo = new \DateTime('2026-10-07 09:00:00', new \DateTimeZone('Asia/Tokyo'));

        $this->assertSame('2026-10-07 00:00:00', $type->convertToDatabaseValue($tokyo, $this->platform()));
        $this->assertSame('Asia/Tokyo', $tokyo->getTimezone()->getName());

        date_default_timezone_set('America/New_York');
        $read = $type->convertToPHPValue('2026-10-07 00:00:00', $this->platform());
        $this->assertInstanceOf(\DateTime::class, $read);
        $this->assertSame($tokyo->getTimestamp(), $read->getTimestamp());
    }

    public function testAnUnreadableValueIsRefused(): void
    {
        $this->expectException(\Doctrine\DBAL\Types\ConversionException::class);
        (new UtcDateTimeImmutableType())->convertToPHPValue('not a date', $this->platform());
    }

    public function testUtcHelpers(): void
    {
        $this->assertSame('UTC', Utc::now()->getTimezone()->getName());
        $paris = new \DateTimeImmutable('2026-10-07 09:00:00', new \DateTimeZone('Europe/Paris'));
        $this->assertSame('2026-10-07 07:00:00', Utc::from($paris)->format('Y-m-d H:i:s'));
        $this->assertNull(Utc::from(null));
    }
}
