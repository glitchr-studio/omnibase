<?php

namespace Tests\Base\Database\Type;

use Base\Database\Type\UtcDateTimeImmutableType;
use Base\Database\Type\UtcDateTimeType;
use Doctrine\DBAL\Types\Type;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The bundle registers both types: an entity names them without any configuration. */
class UtcDateTimeTypeRegistrationTest extends KernelTestCase
{
    public function testTheBundleRegistersTheTypes(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        static::getContainer()->get('doctrine')->getConnection(); // DoctrineBundle registers its types with the connection

        $this->assertInstanceOf(UtcDateTimeImmutableType::class, Type::getType(UtcDateTimeImmutableType::NAME));
        $this->assertInstanceOf(UtcDateTimeType::class, Type::getType(UtcDateTimeType::NAME));
    }
}
