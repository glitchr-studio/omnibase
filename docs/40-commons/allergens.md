---
title: Allergens
order: 45
---

# Allergens

`Base\Enum\Allergen` lists the fourteen allergens a food business declares in
the European Union (Regulation (EU) No 1169/2011, Annex II), in the Annex's
order: gluten, crustaceans, eggs, fish, peanuts, soybeans, milk, nuts, celery,
mustard, sesame, sulphites, lupin, molluscs.

```php
#[ORM\Column(type: 'json')]
private array $allergens = [];                       // ['gluten', 'milk']

Allergen::fromList($dish->getAllergens());            // [Allergen::GLUTEN, Allergen::MILK], in the Annex's order
Allergen::MILK->number();                             // 7
$builder->add('allergens', ChoiceType::class, ['choices' => Allergen::choices(), 'multiple' => true, 'expanded' => true, 'choice_translation_domain' => 'enums']);
```

```twig
{% for a in allergens %}<abbr title="{{ ('@enums.' ~ a.trans)|trans }}">{{ a.number }}</abbr>{% endfor %}
```

Names in the `enums` domain (`allergen.<value>`), French and English.
