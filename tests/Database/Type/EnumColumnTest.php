<?php

namespace Tests\Base\Database\Type;

use Base\Database\Type\SetType;
use Base\DatabaseSubscriber\EnumSubscriber;
use Base\Service\Model\IconizeInterface;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EnumColumnTestRoles extends SetType implements IconizeInterface
{
    public const USER = 'ROLE_USER';
    public const ADMIN = 'ROLE_ADMIN';

    public function __iconize(): ?array { return null; }

    public static function __iconizeStatic(): ?array
    {
        return [self::USER => ['fa-solid fa-user'], self::ADMIN => ['fa-solid fa-crown']];
    }
}

/** An application's roles: the core's plus its own, the icons of all of them named in one place. */
class EnumColumnTestAppRoles extends EnumColumnTestRoles implements IconizeInterface
{
    public const PRO = 'ROLE_PRO';

    public static function __iconizeStatic(): ?array
    {
        return parent::__iconizeStatic() + [self::PRO => ['fa-solid fa-feather']];
    }
}

class EnumColumnTestTypo extends EnumColumnTestRoles implements IconizeInterface
{
    public const PRO = 'ROLE_PRO';

    public static function __iconizeStatic(): ?array
    {
        return ['ROLE_PROO' => ['fa-solid fa-feather']];
    }
}

/**
 * A SET or ENUM column says its values in its comment, so a migration sees
 * them change: the type was found again from "(DC2Type:user_role)" and its
 * declaration rebuilt from the code, and a role added to an application's
 * App\Enum\UserRole never reached the column (make:migration: "no change";
 * genealogist's ROLE_PRO refused). And an enumeration that extends another
 * may name the icons of the values it inherits.
 */
final class EnumColumnTest extends KernelTestCase
{
    /** @param class-string<SetType> $class the type, registered as Doctrine registers an enumeration */
    private static function type(string $class): Type
    {
        $name = strtolower(substr($class, strrpos($class, '\\') + 1));
        if (!Type::hasType($name)) {
            Type::addType($name, $class);
        }

        return Type::getType($name);
    }

    private function comment(Type $type): string
    {
        self::bootKernel();
        $schema = new Schema([new Table('roles_test', [new Column('roles', $type)])]);
        (new EnumSubscriber())->postGenerateSchema(new GenerateSchemaEventArgs(static::getContainer()->get(EntityManagerInterface::class), $schema));

        return (string) $schema->getTable('roles_test')->getColumn('roles')->getComment();
    }

    public function testTheColumnsCommentSaysItsValues(): void
    {
        $core = $this->comment(self::type(EnumColumnTestRoles::class));
        $app = $this->comment(self::type(EnumColumnTestAppRoles::class));

        self::assertStringContainsString('ROLE_ADMIN', $core);
        self::assertStringContainsString('ROLE_PRO', $app);
        self::assertNotSame($core, $app, 'a value added changes the column');

        // as the schema tool compares them: the column read back from the database has the old comment
        $platform = new MySQLPlatform();
        $old = new Column('roles', self::type(EnumColumnTestAppRoles::class), ['comment' => $core]);
        $new = new Column('roles', self::type(EnumColumnTestAppRoles::class), ['comment' => $app]);
        self::assertFalse($platform->columnsEqual($old, $new), 'a migration is made');
        self::assertMatchesRegularExpression('/\(DC2Type:[^)]+\)/', $app, 'the type is still found from the comment');
    }

    public function testAnEnumerationMayNameTheIconsOfTheValuesItInherits(): void
    {
        $icons = EnumColumnTestAppRoles::getIcons();
        self::assertArrayHasKey('ROLE_PRO', $icons);
        self::assertArrayHasKey('ROLE_ADMIN', $icons);
    }

    public function testAnIconForNoValueIsStillAnError(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        EnumColumnTestTypo::getIcons();
    }
}
