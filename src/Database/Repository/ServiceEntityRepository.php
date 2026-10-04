<?php

namespace Base\Database\Repository;

use Base\Database\Mapping\ClassMetadataCompletor;
use Base\Database\Mapping\ClassMetadataManipulator;
use Base\Database\Entity\EntityHydrator;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Mapping\ClassMetadata;
use Exception;

/**
 * @method Entity[]    findBy*(...array $customs,
 *      array $criteria, array ??array $orderBy = null, $limit = null, $offset = null)
 */
class ServiceEntityRepository extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository
{
    /**
     * @var ClassMetadata
     */
    protected ClassMetadata $classMetadata;

    /**
     * @var ClassMetadataCompletor
     */
    protected ClassMetadataCompletor $classMetadataCompletor;

    /**
     * @var ServiceEntityParser
     */
    protected ServiceEntityParser $serviceParser;

    /**
     * @return array|string|string[]|null
     */
    public static function getFqcnEntityName()
    {
        return preg_replace(
            ['/\\\\Repository\\\\/', '/Repository$/'],
            ["\\\\Entity\\\\", ""],
            static::class
        );
    }

    public function __construct(ManagerRegistry $doctrine, ?string $entityName = null)
    {
        parent::__construct($doctrine, $entityName ?? $this->getFqcnEntityName());

        $entityManager = $this->getEntityManager();
        $classMetadataManipulator = new ClassMetadataManipulator($doctrine, $entityManager);
        
        $this->classMetadata = $entityManager->getClassMetadata($entityName ?? $this->getFqcnEntityName());
        $this->classMetadataCompletor = $classMetadataManipulator->getClassMetadataCompletor($entityName ?? $this->getFqcnEntityName());

        $entityHydrator = new EntityHydrator($entityManager, $classMetadataManipulator);
        $this->serviceParser = new ServiceEntityParser(
            $this, $entityManager, $classMetadataManipulator, 
            $entityHydrator
        );
    }

    /**
     * @return ClassMetadata|null
     */
    public function getClassMetadata(): ClassMetadata
    {
        return $this->classMetadata;
    }

    /**
     * @return ClassMetadataCompletor|null
     */
    public function getClassMetadataCompletor(): ?ClassMetadataCompletor
    {
        return $this->classMetadataCompletor;
    }

    /**
     * @param $method
     * @param $arguments
     * @return mixed
     */
    public function __call($method, $arguments): mixed
    {
        return $this->serviceParser->parse($method, $arguments);
    }

    /**
     * @param $id
     * @param $lockMode
     * @param $lockVersion
     * @return object|null
     */
    public function find($id, $lockMode = null, $lockVersion = null): ?object
    {
        return $this->findOneById($id, $lockMode, $lockVersion);
    }

    public function findAll(): array
    {
        return $this->__call(__METHOD__, [])->getResult();
    }

    /**
     * @param array $criteria
     * @param array|null $orderBy
     * @param $limit
     * @param $offset
     * @return array|object[]
     */
    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        return $this->__call(__METHOD__, [$criteria, $orderBy, $limit, $offset])->getResult();
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->__call(__METHOD__, [$criteria, $orderBy]);
    }

    /**
     * Query-by-Example: build the criteria from the *set* fields of a (usually
     * non-persisted) select entity, then delegate to findBy(). Lets you query with
     * a hydrated object instead of a raw array:
     *
     *     $select = (new Article())->setState('published');
     *     $repo->findByExample($select);   // == findBy(['state' => 'published'])
     *
     * Only mapped scalar fields and to-one associations participate. Uninitialized
     * and null properties are skipped — a select cannot express "IS NULL"; use the
     * finder DSL (e.g. findByStateNull()) for that. Throws when the select yields no
     * criteria, to avoid an accidental whole-table fetch (use findAll() instead).
     *
     * @return array<object>
     * @throws Exception
     */
    public function findByExample(object $select, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        $criteria = $this->criteriaFromSelect($select);
        if (empty($criteria)) {
            throw new Exception(sprintf(
                'findByExample() received a select (%s) with no queryable set fields — refusing to match the whole "%s" table. Use findAll() instead.',
                get_class($select),
                $this->getFqcnEntityName()
            ));
        }

        return $this->findBy($criteria, $orderBy, $limit, $offset);
    }

    /**
     * Reflect over a select entity and collect its set, mapped fields into a
     * findBy()-compatible criteria array. Shared by findByExample() and by the
     * finder DSL's "Model" clause (e.g. findByModelAndIdGreaterThan($select, 0)).
     *
     * @return array<string,mixed>
     */
    public function criteriaFromSelect(object $select): array
    {
        $metadata = $this->classMetadata;
        $criteria = [];

        foreach ((new \ReflectionClass($select))->getProperties() as $property) {
            $name = $property->getName();

            $isField = $metadata->hasField($name);
            $isToOne = $metadata->hasAssociation($name) && $metadata->isSingleValuedAssociation($name);
            if (!$isField && !$isToOne) {
                continue; // not a queryable mapped field / to-one association
            }

            $property->setAccessible(true);
            if (!$property->isInitialized($select)) {
                continue; // never set on this select
            }

            $value = $property->getValue($select);
            if ($value === null) {
                continue; // a select cannot express "IS NULL"
            }

            $criteria[$name] = $value;
        }

        return $criteria;
    }

    /**
     * The number of rows matching $criteria (all of them for []), as
     * Doctrine's count() promises: an int, never the grouped rows.
     */
    public function count(array $criteria = []): int
    {
        return (int) $this->__call(__METHOD__, [$criteria]);
    }

    /**
     * Flushes the unit of work - and clears the EntityManager only when asked:
     * cleared by default (as it was), a request's signed-in user and every
     * entity it held were detached for the rest of it, so the next flush
     * touching them duplicated rows or failed on "a new entity was found
     * through the relationship" (as SecuritySubscriber's did). A batch that
     * wants its memory back passes true.
     */
    public function flush(bool $autoclear = false)
    {
        $this->getEntityManager()->flush();
        if($autoclear) $this->clear();
    }

    public function clear()
    {
        $this->getEntityManager()->clear();
    }

    /**
     * @param $entity
     * @return void
     * @throws Exception
     */
    public function persist($entity, bool $flush = false): void
    {
        $entityClass = \property_exists($this, '_entityName') ? $this->_entityName : $this->getFqcnEntityName(); // Doctrine ORM 2 vs. 3
        if (!is_object($entity) || (!$entity instanceof $entityClass && !is_subclass_of($entity, $entityClass))) {
            $class = (is_object($entity) ? get_class($entity) : "null");
            throw new Exception("Repository \"" . static::class . "\" is expected \"" . $entityClass . "\" entity, you passed \"" . $class . "\"");
        }

        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove($entity, bool $flush = false): void
    {
        $entityClass = \property_exists($this, '_entityName') ? $this->_entityName : $this->getFqcnEntityName(); // Doctrine ORM 2 vs. 3
        if (!is_object($entity) || (!$entity instanceof $entityClass && !is_subclass_of($entity, $entityClass))) {
            $class = (is_object($entity) ? get_class($entity) : "null");
            throw new Exception("Repository \"" . static::class . "\" is expected \"" . $entityClass . "\" entity, you passed \"" . $class . "\"");
        }

        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
