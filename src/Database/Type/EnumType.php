<?php

namespace Base\Database\Type;

use Base\Service\Model\ColorizeInterface;
use Base\Service\Model\IconizeInterface;
use Base\Service\Model\SelectInterface;
use Base\Service\Translator;
use Base\Service\TranslatorInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Exception;
use Generator;
use InvalidArgumentException;
use ReflectionClass;
use UnexpectedValueException;

abstract class EnumType extends Type implements SelectInterface
{
    protected static array $icons = [];

    public static function getIcons(): array
    {
        $class = static::class;
        if (array_key_exists($class, self::$icons)) {
            return self::$icons[$class] ?? [];
        }

        $icons = [];
        if (class_implements_interface(static::class, IconizeInterface::class)) {
            $icons = static::class::__iconizeStatic();
        }

        while ($class) {
            if (class_implements_interface($class, IconizeInterface::class)) {
                // An icon may be given to any value the class has, its own or inherited (an application's
                // enumeration names its roles' and the core's in one place); one given to a value it does
                // not have is a mistake.
                if (($unknownKeys = array_keys(array_key_removes($class::__iconizeStatic() ?? [], ...$class::getPermittedValues(true))))) {
                    throw new UnexpectedValueException("The following keys \"" . implode(",", $unknownKeys) . "\" are in the list of the available icons on class \"" . get_called_class() . "\", which has no such value.");
                }

                $icons = array_union($icons, $class::__iconizeStatic());
                self::$icons[$class] = $icons;
            }

            $class = get_parent_class($class);
        }

        self::$icons[static::class] = $icons;
        return $icons ?? [];
    }

    public static function getColor(string $id): ?string
    {
        if (is_instanceof(static::class, ColorizeInterface::class)) {
            $colors = static::__colorizeStatic()[$id] ?? [];
            return $colors ? first($colors) : null;
        }

        return null;
    }

    public static function getIcon(string $id, int $index = -1): ?string
    {
        // closest() already resolves a negative $index to the first entry;
        // the bypass this used to have for $index < 0 returned the whole
        // icon array instead of a single string, violating the return type
        // for every multi-icon entry (i.e. every real IconizeInterface use).
        return array_map(fn($values) => !is_array($values) ? $values : closest($values, $index), self::getIcons())[$id] ?? null;
    }

    public static function getText(string $id, ?TranslatorInterface $translator = null): ?string
    {
        return $translator ? $translator->transEnum($id, get_called_class(), Translator::NOUN_SINGULAR) : $id;
    }

    public static function getHtml(string $id): ?string
    {
        return null;
    }

    public static function getData(string $id): ?array
    {
        return null;
    }

    public function getName(): string
    {
        return self::getStaticName();
    }

    /**
     * @return array|false|string|string[]|null
     */
    public static function getStaticName()
    {
        $array = explode('\\', get_called_class());
        return camel2snake(end($array));
    }

    /**
     * @param string $key
     * @param bool $inheritance
     * @return bool
     * @throws Exception
     */
    public static function hasKey(string $key, bool $inheritance = true)
    {
        return array_key_exists($key, self::getPermittedValues($inheritance, true));
    }

    /**
     * @param string $key
     * @param bool $inheritance
     * @return mixed|null
     * @throws Exception
     */
    public static function getValue(string $key, bool $inheritance = true)
    {
        return self::getPermittedValues($inheritance, true)[$key] ?? null;
    }

    /**
     * @param string $value
     * @param bool $inheritance
     * @return bool
     * @throws Exception
     */
    public static function hasValue(string $value, bool $inheritance = true)
    {
        return in_array($value, self::getPermittedValues($inheritance, true));
    }

    public static function getPermittedValues(bool $inheritance = true, bool $preserve_keys = false): array
    {
        $refl = new ReflectionClass(get_called_class());
        if ($inheritance) {
            $values = $refl->getConstants();
        } else {
            $values = array_diff($refl->getConstants(), $refl->getParentClass()->getConstants());
        }

        if ($preserve_keys) {
            asort($values);
        } else {
            $values = array_values($values);
            sort($values);
        }

        if (!in_array($refl->getName(), [EnumType::class, SetType::class]) && $refl->getName() != Type::class && !$values) {
            throw new Exception("\"" . get_called_class() . "\" is empty");
        }

        return $values;
    }

    public static function getPermittedValuesByGroup(bool $inheritance = true, bool $preserve_keys = false): array
    {
        $valuesByGroup = [];
        $values = self::getPermittedValues($inheritance, $preserve_keys);

        $pathway = array_map(fn($a) => explode("_", $a), $values);
        foreach ($pathway as $i => $_) {
            $value = $values[$i];
            $group = &$valuesByGroup;

            $kLast = count($_) - 1;
            foreach ($_ as $k => $path) {
                if ($k == $kLast) {
                    $group[$path] = $value;
                } else {
                    if (array_key_exists($path, $group)) {
                        $group[$path] = is_array($group[$path]) ? $group[$path] : ["_self" => $group[$path]];
                    }

                    $group[$path] = $group[$path] ?? [];
                    $group = &$group[$path];
                }
            }
        }

        $bubbleUp = count($valuesByGroup) == 1;
        return array_transforms(function ($k, $v, $callback) use (&$bubbleUp): Generator {

            if ($bubbleUp && is_array($v)) {
                $bubbleUp = count($v) == 1;
                foreach ($v as $kk => $vv) {
                    $vv = is_array($vv) ? array_transforms($callback, $vv) : $vv;
                    if (is_array($vv) && count($vv) == 1) {
                        $key = array_keys($vv)[0] ?? null;
                        $kkp = explode("::", $key);

                        $kk .= "_" . ($kkp[1] ?? $kkp[0]);
                        $vv = $vv[$key];
                    }

                    yield static::class . "::" . $k . "_" . $kk => $vv;
                }

                return null;
            }

            return [$k, is_array($v) ? array_transforms($callback, $v) : $v];
        }, $valuesByGroup);
    }

    public static function getPermittedValuesByClass(bool $preserve_groups = true, bool $preserve_keys = false): array
    {
        $refl = new ReflectionClass(get_called_class());
        if (in_array($refl->getName(), [EnumType::class, SetType::class])) {
            return [];
        }

        $fnPermittedValues = $preserve_groups ? "getPermittedValuesByGroup" : "getPermittedValues";

        $values = [$refl->getName() => $refl->getName()::$fnPermittedValues(false, $preserve_keys)];
        while (($refl = $refl->getParentClass()) && !in_array($refl->getName(), [EnumType::class, SetType::class]) && $refl->getName() != Type::class) {
            $values[$refl->getName()] = $refl->getName()::$fnPermittedValues(false, $preserve_keys);
        }

        return $values;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if ($platform instanceof SqlitePlatform) {
            return "TEXT";
        }

        $values = array_map(fn($val) => "'" . $val . "'", $this->getPermittedValues());

        return "ENUM(" . implode(", ", $values) . ")";
    }

    /**
     * @param $value
     * @param AbstractPlatform $platform
     * @return mixed
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        return $value;
    }

    /**
     * @param $value
     * @param AbstractPlatform $platform
     * @return mixed
     * @throws Exception
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        if (is_array($value)) {
            throw new InvalidArgumentException("Enum type \"" . get_class($this) . "\" is not expecting an array \"['" . (implode("', '", $value)) . "'] received.");
        }
        if ($value !== null && !in_array($value, $this->getPermittedValues())) {
            throw new InvalidArgumentException("Invalid '" . (is_array($value) ? implode(", ", $value) : $value) . "' value. (Expected values are: " . implode(", ", $this->getPermittedValues()) . ")");
        }

        return $value;
    }
}
