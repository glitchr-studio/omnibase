<?php

namespace Base\Enum;

/**
 * The fourteen allergens a food business must declare in the European Union
 * (Regulation (EU) No 1169/2011, Annex II), in the Annex's order. A plain
 * string enum: stored as is (a JSON list of values on a dish, a varchar),
 * labelled through the "enums" translation domain (allergen.<value>).
 *
 *     #[ORM\Column(type: 'json')] private array $allergens = [];   // ['gluten', 'milk']
 *     Allergen::fromList($dish->getAllergens())
 *     {{ ('@enums.allergen.' ~ a.value)|trans }}
 */
enum Allergen: string
{
    case GLUTEN = 'gluten';
    case CRUSTACEANS = 'crustaceans';
    case EGGS = 'eggs';
    case FISH = 'fish';
    case PEANUTS = 'peanuts';
    case SOYBEANS = 'soybeans';
    case MILK = 'milk';
    case NUTS = 'nuts';
    case CELERY = 'celery';
    case MUSTARD = 'mustard';
    case SESAME = 'sesame';
    case SULPHITES = 'sulphites';
    case LUPIN = 'lupin';
    case MOLLUSCS = 'molluscs';

    /** Its number in Annex II (1 to 14), as menus often print it. */
    public function number(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /** The translation key of its name, in the "enums" domain. */
    public function trans(): string
    {
        return 'allergen.'.$this->value;
    }

    /**
     * Stored values back to cases, unknown ones dropped, in the Annex's order.
     *
     * @param iterable<string|self> $values
     *
     * @return list<self>
     */
    public static function fromList(iterable $values): array
    {
        $found = [];
        foreach ($values as $value) {
            $case = $value instanceof self ? $value : self::tryFrom((string) $value);
            if ($case) {
                $found[$case->value] = $case;
            }
        }

        return array_values(array_filter(self::cases(), fn (self $case) => isset($found[$case->value])));
    }

    /** @return array<string, string> translation key => value, for a ChoiceType (choice_translation_domain: 'enums') */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->trans()] = $case->value;
        }

        return $choices;
    }
}
