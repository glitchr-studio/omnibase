<?php

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * object_hydrate() rebuilds the "old entity" AbstractAttribute::getOldEntity()
 * hands the #[Uploader] listener, from Doctrine's original data - where an
 * enum field is its backing value, a string or an int. Put as such into an
 * enum-typed property, that was a TypeError on every flush of an entity with
 * both an #[Uploader] and an enum field.
 */
class ObjectHydrateTest extends TestCase
{
    public function testABackingValueBecomesItsCase(): void
    {
        $entity = object_hydrate(new HydratedEntity(), [
            'status' => 'published',
            'priority' => '2',
            'nullableStatus' => 'draft',
            'title' => 'Hello',
        ]);

        $this->assertSame(HydratedStatus::Published, $entity->status);
        $this->assertSame(HydratedPriority::High, $entity->priority);
        $this->assertSame(HydratedStatus::Draft, $entity->nullableStatus);
        $this->assertSame('Hello', $entity->title);
    }

    public function testAnIntBackingValueAndAnEnumAlreadyGivenAreKept(): void
    {
        $entity = object_hydrate(new HydratedEntity(), [
            'priority' => 1,
            'status' => HydratedStatus::Draft,
        ]);

        $this->assertSame(HydratedPriority::Low, $entity->priority);
        $this->assertSame(HydratedStatus::Draft, $entity->status);
    }

    public function testAPureEnumIsFoundByItsCaseName(): void
    {
        $entity = object_hydrate(new HydratedEntity(), ['shape' => 'Round']);

        $this->assertSame(HydratedShape::Round, $entity->shape);
    }

    public function testAValueNoCaseMatchesIsLeftOutNotFatal(): void
    {
        $entity = object_hydrate(new HydratedEntity(), ['status' => 'archived', 'priority' => 'urgent']);

        $this->assertSame(HydratedStatus::Draft, $entity->status);
        $this->assertSame(HydratedPriority::Low, $entity->priority);
    }

    public function testAUnionThatTakesTheStringKeepsIt(): void
    {
        $entity = object_hydrate(new HydratedEntity(), ['label' => 'published']);

        $this->assertSame('published', $entity->label);
    }
}

enum HydratedStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum HydratedPriority: int
{
    case Low = 1;
    case High = 2;
}

enum HydratedShape
{
    case Square;
    case Round;
}

class HydratedEntity
{
    public HydratedStatus $status = HydratedStatus::Draft;
    public ?HydratedStatus $nullableStatus = null;
    public HydratedPriority $priority = HydratedPriority::Low;
    public ?HydratedShape $shape = null;
    public HydratedStatus|string|null $label = null;
    public ?string $title = null;
}
