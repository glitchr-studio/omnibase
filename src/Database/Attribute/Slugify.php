<?php

namespace Base\Database\Attribute;

use Base\Attributes\AbstractAttribute;
use Base\Attributes\AttributeReader;
use Base\Database\Attribute\Extension\ExtensionOptionInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\String\Slugger\AsciiSlugger;


/**
 * Class Slugify
 * package Base\Database\Attribute\Slugify
 */

 #[\Attribute(\Attribute::TARGET_PROPERTY)]
class Slugify extends AbstractAttribute implements ExtensionOptionInterface
{
    protected $slugger;
    protected bool $unique;
    protected bool $capital;
    protected bool $nullable;

    protected ?array $keep;
    protected bool $sync;

    /**
     * Unique among the entities of the same class only, in a hierarchy that
     * shares the column (Taxon: a menu's "desserts" and a blog's "desserts").
     */
    protected bool $perType;

    protected string $separator;
    protected ?string $referenceColumn;

    public function __construct(
        ?string $reference = null, 
        bool $unique = true, 
        bool $sync = false, 
        bool $nullable = false, 
        string $separator = "-", 
        array $keep = [],
        bool $capital = false,
        ?string $locale = null,
        ?array $map = null,
        bool $perType = false)
    {
        $this->referenceColumn = $reference;
        $this->perType = $perType;

        $this->unique = $unique;
        $this->sync = $sync;
        $this->nullable = $nullable;

        $this->separator = $separator;
        $this->keep = $keep;
        $this->capital = $capital;
        $this->slugger = new AsciiSlugger($locale,$map);
    }

    /**
     * @return mixed|string|null
     */
    public function getReferenceColumn()
    {
        return $this->referenceColumn;
    }

    /**
     * @param $event
     * @param $entity
     * @param $property
     * @return array
     * @throws \Exception
     */
    public function getInvalidSlugs($event, $entity, $property)
    {
        $uow = $event->getObjectManager()->getUnitOfWork();

        $candidateEntities = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity2) {
            $candidateEntities[] = $entity2;
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity2) {
            $candidateEntities[] = $entity2;
        }

        $invalidSlugs = [];
        foreach ($candidateEntities as $entity2) {
            if ($entity === $entity2) {
                break;
            } // FIFO
            if (!property_exists($entity2, $property)) {
                continue;
            }

            $propertyDeclarer = property_declarer($entity, $property);
            $propertyDeclarer2 = property_declarer($entity2, $property);
            if ($propertyDeclarer != $propertyDeclarer2 && !is_instanceof($propertyDeclarer, $propertyDeclarer2)) {
                continue;
            }
            if ($this->perType && $this->typeOf($entity) !== $this->typeOf($entity2) && !$this->columnIsStillUniqueAlone($entity, $property)) {
                continue;
            }

            $invalidSlugs[] = $this->getFieldValue($entity2, $property);
        }

        $firstEntity = begin($candidateEntities);
        if ($firstEntity === $entity) {
            $firstSlug = $this->getFieldValue($entity, $property);
            $invalidSlugs = array_filter($invalidSlugs, fn($s) => $s !== $firstSlug);
        }

        return $invalidSlugs;
    }

    /**
     * @param $entity
     * @param string|null $input
     * @param string $suffix
     * @return string|null
     * @throws \Exception
     */
    public function slug($entity, ?string $input = null, string $suffix = ''): ?string
    {
        // Check if field already set.. get field value or by default class name
        if (!$input && $this->referenceColumn) {
            $input = $this->getPropertyValue($entity, $this->referenceColumn) ?? $this->getFieldValue($entity, $this->referenceColumn);
        }
        if (!$input && $this->nullable) {
            return null;
        }

        if (!$input) {
            $input = camel2snake(class_basename($entity), '-')."-".$entity->getId()."-".\rand_str(6);
        }
        $input .= !empty($suffix) ? $this->separator . $suffix : '';

        if (!$this->keep) {
            $slug = $this->slugger->slug($input, $this->separator);
        } else {
            $pos = 0;
            $posList = [];

            $pos = -1;
            while ($pos = strmultipos($input, $this->keep, $pos + 1)) {
                $posList[] = $input[$pos];
            }

            $slug = explodeByArray($this->keep, $input);
            $slug = array_map(fn($i) => $this->slugger->slug($i, $this->separator), $slug);
            $slug = implodeByArray($posList, $slug);
        }

        return $this->capital ? $slug : strtolower($slug);
    }

    /**
     * @param $entity
     * @param string $property
     * @param string|null $defaultInput
     * @param array $invalidSlugs
     * @return string|null
     */
    public function getSlug($entity, string $property, ?string $defaultInput = null, array $invalidSlugs = []): ?string
    {
        /**
         * @var ServiceRepositoryInterface $this
         */
        $repository = $this->getPropertyOwnerRepository($entity, $property);
        $defaultSlug = $this->slug($entity, $defaultInput);
        $slug = $defaultSlug;
        
        if (!$slug) {
            return null;
        }

        if (!$this->unique) {
            return $slug;
        }

        for ($i = 2; ( $persistentEntity = $this->findHolder($repository, $entity, $property, $slug) ) || in_array($slug, $invalidSlugs); ++$i) {

            if($persistentEntity === $entity ) break;
            $slug = $defaultSlug . $this->separator . $i;
        }

        return $slug;
    }

    /**
     * Who already holds this slug: any entity of the column's owner, or -
     * perType - one of the very class of the entity being saved.
     */
    protected function findHolder($repository, $entity, string $property, string $slug): ?object
    {
        if (!$this->perType || $this->columnIsStillUniqueAlone($entity, $property)) {
            return $repository->findOneBy([$property => $slug]);
        }

        $type = $this->typeOf($entity);
        $same = null;
        foreach ($repository->findBy([$property => $slug]) as $holder) {
            if ($holder === $entity) {
                return $holder;
            }
            if ($this->typeOf($holder) === $type) {
                $same ??= $holder;
            }
        }

        return $same;
    }

    /** @var array<string, bool> */
    protected static array $uniqueAlone = [];

    /**
     * Whether the database still holds the unique index of before on the
     * column alone: an application that has not run its migration yet keeps
     * slugs unique for all types (a second "desserts" would be refused by
     * that index), and gets them per type once the index is gone. Asked once
     * per process and table.
     */
    protected function columnIsStillUniqueAlone(object $entity, string $property): bool
    {
        try {
            $classMetadata = $this->getClassMetadata(property_declarer($entity, $property));
            $table = $classMetadata->getTableName();
            $column = $classMetadata->getColumnName($property);
            $key = $table . "." . $column;
            if (array_key_exists($key, self::$uniqueAlone)) {
                return self::$uniqueAlone[$key];
            }

            self::$uniqueAlone[$key] = false;
            $schemaManager = $this->getEntityManager()->getConnection()->createSchemaManager();
            foreach ($schemaManager->listTableIndexes($table) as $index) {
                if ($index->isUnique() && !$index->isPrimary() && array_map('strtolower', $index->getUnquotedColumns()) === [strtolower($column)]) {
                    self::$uniqueAlone[$key] = true;
                }
            }

            return self::$uniqueAlone[$key];
        } catch (\Throwable) {
            return false;
        }
    }

    protected function typeOf(object $entity): string
    {
        return $entity instanceof \Doctrine\Persistence\Proxy ? get_parent_class($entity) : get_class($entity);
    }

    /**
     * @param string $target
     * @param string|null $targetValue
     * @param $object
     * @return bool
     */
    public function supports(string $target, ?string $targetValue = null, $object = null): bool
    {
        return AttributeReader::TARGET_PROPERTY == $target;
    }

    /**
     * @param OnFlushEventArgs $event
     * @param ClassMetadata $classMetadata
     * @param $entity
     * @param string|null $property
     * @return void
     * @throws \Exception
     */
    public function onFlush(OnFlushEventArgs $event, ClassMetadata $classMetadata, $entity, ?string $property = null)
    {
        $propertyDeclarer = property_declarer($entity, $property);
        $classMetadata = $this->getClassMetadata($propertyDeclarer);
        $invalidSlugs = $this->getInvalidSlugs($event, $entity, $property);

        if ($this->sync) {
            $slug = $this->getFieldValue($entity, $property);

            $oldEntity = $this->getOldEntity($entity);
            $oldSlug = $this->getFieldValue($oldEntity, $property);

            if ($slug == $oldSlug) {
                
                $labelModified = ! $this->referenceColumn ? null :
                    $this->getPropertyValue($oldEntity, $this->referenceColumn) !== $this->getPropertyValue($entity, $this->referenceColumn);

                if ($labelModified) {
                    $slug = $this->getSlug($entity, $property);
                }
            }

        } else {
            $currentSlug = $this->getFieldValue($entity, $property);
            $slug = $this->getSlug($entity, $property, $currentSlug, $invalidSlugs);
        }

        $this->setFieldValue($entity, $property, $slug);
        if ($this->getUnitOfWork()->getEntityChangeSet($entity)) {
            $this->getUnitOfWork()->recomputeSingleEntityChangeSet($classMetadata, $entity);
        }
    }
}
