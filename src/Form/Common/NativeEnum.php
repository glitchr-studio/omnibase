<?php

namespace Base\Form\Common;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A PHP enum as a list of choices: what a select holds for a case (its value,
 * or its name when the enum is not backed), the case back from it, and the
 * words that name it.
 */
final class NativeEnum
{
    public static function is(mixed $class): bool
    {
        return \is_string($class) && enum_exists($class);
    }

    /** What the select holds for a case: the backed value, else the case's name. */
    public static function id(\UnitEnum $case): string
    {
        return $case instanceof \BackedEnum ? (string) $case->value : $case->name;
    }

    /**
     * The case a select's value names; null for anything else.
     *
     * @param class-string<\UnitEnum> $class
     */
    public static function of(string $class, mixed $id): ?\UnitEnum
    {
        if ($id instanceof $class) {
            return $id;
        }
        if (!\is_scalar($id)) {
            return null;
        }

        foreach ($class::cases() as $case) {
            if (self::id($case) === (string) $id) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @param class-string<\UnitEnum> $class
     *
     * @return list<string>
     */
    public static function ids(string $class): array
    {
        return array_map(self::id(...), $class::cases());
    }

    /**
     * The translation key of a case in the "enums" domain: the enum's short
     * name in snake case, then the select's value in lower case
     * (Base\Enum\CommentState::PENDING is "comment_state.pending").
     */
    public static function key(\UnitEnum $case): string
    {
        $short = substr(strrchr('\\'.$case::class, '\\'), 1);
        $short = strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $short));

        return $short.'.'.strtolower(self::id($case));
    }

    /**
     * A case's label: what the enum says itself when it is translatable
     * (Symfony's TranslatableInterface), else its key in the "enums" domain,
     * else its name made readable (NOT_CONTRACTED: "Not contracted").
     */
    public static function label(\UnitEnum $case, ?TranslatorInterface $translator = null, ?string $locale = null): string
    {
        if ($case instanceof TranslatableInterface && $translator) {
            return $case->trans($translator, $locale);
        }

        if ($translator) {
            $key = self::key($case);
            $label = $translator->trans($key, [], 'enums', $locale);
            if ('' !== $label && $label !== $key && !str_ends_with($label, $key)) {
                return $label;
            }
        }

        return ucfirst(strtolower(str_replace('_', ' ', $case->name)));
    }
}
