<?php

namespace Tests\Base\Database\Attribute;

use Base\Database\Attribute\Slugify;
use Base\Entity\Thread\Taxon;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A taxon's slug is unique among the taxa of its type, not on the whole
 * table every type shares: a menu's section and a blog's category may both
 * be "desserts" (the second one became "desserts-2").
 */
class SlugifyPerTypeTest extends KernelTestCase
{
    private function holder(Slugify $slugify, array $rows, object $entity, string $slug): ?object
    {
        $repository = new class($rows) {
            public function __construct(private array $rows)
            {
            }

            public function findBy(array $criteria): array
            {
                return array_values(array_filter($this->rows, fn ($row) => (method_exists($row, 'getSlug') ? $row->getSlug() : $row->slug) === $criteria['slug']));
            }

            public function findOneBy(array $criteria): ?object
            {
                return $this->findBy($criteria)[0] ?? null;
            }
        };

        return (new \ReflectionMethod(Slugify::class, 'findHolder'))->invoke($slugify, $repository, $entity, 'slug', $slug);
    }

    public function testAnotherTypeDoesNotHoldTheSlug(): void
    {
        $section = new class { public string $slug = 'desserts'; };
        $category = new class { public string $slug = 'desserts'; };
        $other = new ($category::class)();

        $perType = new Slugify(reference: 'label', perType: true);
        $this->assertNull($this->holder($perType, [$section], $category, 'desserts'), 'a section named desserts leaves the slug free for a category');
        $this->assertSame($other, $this->holder($perType, [$section, $other], $category, 'desserts'), 'another category does not');
        $this->assertSame($category, $this->holder($perType, [$section, $category], $category, 'desserts'), 'the entity itself is recognised');

        $global = new Slugify(reference: 'label');
        $this->assertSame($section, $this->holder($global, [$section], $category, 'desserts'), 'without perType, the column is unique for all');
    }

    public function testTheTableIsUniqueOnTypeAndSlug(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires a host application (the omnibase harness).');
        }

        self::bootKernel();
        $metadata = static::getContainer()->get('doctrine')->getManager()->getClassMetadata(Taxon::class);

        $this->assertFalse($metadata->getFieldMapping('slug')->unique ?? false, 'no longer unique on its own');
        $this->assertSame(['class', 'slug'], $metadata->table['uniqueConstraints']['thread_taxon_type_slug']['columns']);
        $this->assertSame(['slug'], $metadata->table['indexes']['thread_taxon_slug']['columns'], 'still indexed: a taxon is found by its slug');
    }

    public function testUntilTheOldIndexIsDroppedTheSlugStaysUniqueForAll(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires a host application (the omnibase harness).');
        }

        self::bootKernel();
        $connection = static::getContainer()->get('doctrine')->getConnection();
        $slugify = new Slugify(reference: 'translations.label', perType: true);
        $asked = new \ReflectionMethod(Slugify::class, 'columnIsStillUniqueAlone');
        $memo = new \ReflectionProperty(Slugify::class, 'uniqueAlone');

        $memo->setValue(null, []);
        $this->assertFalse($asked->invoke($slugify, new Taxon(), 'slug'), 'the schema of today: unique on (class, slug)');

        // An application that updated omnibase and has not migrated yet.
        $connection->executeStatement('CREATE UNIQUE INDEX UNIQ_OLD_TAXON_SLUG ON threadTaxon (slug)');
        try {
            $memo->setValue(null, []);
            $this->assertTrue($asked->invoke($slugify, new Taxon(), 'slug'));

            $section = new Taxon();
            (new \ReflectionProperty(Taxon::class, 'slug'))->setValue($section, 'desserts');
            $category = new class extends Taxon {};
            $this->assertSame($section, $this->holder($slugify, [$section], $category, 'desserts'), 'another type still holds the slug: the old index would refuse a second one');
        } finally {
            // Through the schema manager: "DROP INDEX name" alone is SQLite's
            // syntax, MySQL wants "... ON table" - there the statement failed and
            // left the index behind in the application's database.
            $connection->createSchemaManager()->dropIndex('UNIQ_OLD_TAXON_SLUG', 'threadTaxon');
            $memo->setValue(null, []);
        }
    }

    public function testTwoTaxaOfOneTypeStillDiffer(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires a host application (the omnibase harness).');
        }

        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        $label = 'Desserts '.bin2hex(random_bytes(3));
        $first = new Taxon($label);
        $em->persist($first);
        $em->flush();
        $second = new Taxon($label);
        $em->persist($second);
        $em->flush();

        try {
            $this->assertNotEmpty($first->getSlug());
            $this->assertSame($first->getSlug().'-2', $second->getSlug());
        } finally {
            $em->remove($first);
            $em->remove($second);
            $em->flush();
        }
    }
}
