<?php

namespace Base\DatabaseSubscriber;

use Base\BaseBundle;
use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Database\Entity\Extension\TranslationInterface;
use Base\Database\Event\DoctrineQueryEventArgs;
use Base\Database\Event\ResolveDiscriminatorEventArgs;
use Base\Database\Mapping\NamingStrategy;
use Base\Exception\MissingDiscriminatorMapException;
use Base\Exception\MissingDiscriminatorValueException;
use Base\Service\LocalizerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Base\Database\Walker\TranslatableWalker;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Exception;

class IntlSubscriber
{
    protected EntityManagerInterface $entityManager;

    protected LocalizerInterface $localizer;

    /**
     * @return LocalizerInterface
     */
    public function getLocalizer()
    {
        return $this->localizer;
    }

    public function __construct(EntityManagerInterface $entityManager, LocalizerInterface $localizer)
    {
        $this->entityManager = $entityManager;
        $this->localizer = $localizer;
    }

    public function onQuery(DoctrineQueryEventArgs $args)
    {
        if (!class_implements_interface($args->getClassMetadata()->getName(), TranslatableInterface::class)) return;
        
        $args->getQuery()->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, TranslatableWalker::class);
    }

    public function resolveDiscriminator(ResolveDiscriminatorEventArgs $resolveDiscriminatorEventArgs)
    {
        $classMetadata = $resolveDiscriminatorEventArgs->getClassMetadata();
        if (!is_subclass_of($classMetadata->getName(), TranslationInterface::class)) return;

        $classMetadataFactory = $this->entityManager->getMetadataFactory();
        if (!str_ends_with($classMetadata->getName(), NamingStrategy::TABLE_I18N_SUFFIX)) {
            throw new Exception("Invalid class name for \"" . $classMetadata->getName() . "\"");
        }

        $translatableClass = $classMetadata->getName()::getTranslatableEntityClass();
        $translatableMetadata = $classMetadataFactory->getMetadataFor($translatableClass);

        //
        // Handle translation discriminator map
        if (!$classMetadata->discriminatorMap) {
            $classMetadata->discriminatorMap = array_filter(array_map(function ($className) {
                return (is_subclass_of($className, TranslatableInterface::class))
                    ? $className::getTranslationEntityClass(false)
                    : null;
            }, $translatableMetadata->discriminatorMap), fn($c) => $c !== null);
        }

        //
        // Handle translation subclasses
        $subClasses = [];
        foreach ($translatableMetadata->subClasses as $translatableSubclass) {
            $translationClass = $translatableSubclass::getTranslationEntityClass();
            if ($translationClass !== null && $translationClass != $classMetadata->getName()) {
                $subClasses[] = $translationClass;
            }
        }

        // Apply values..
        $classMetadata->subClasses = array_unique($subClasses);
        $classMetadata->inheritanceType = $translatableMetadata->inheritanceType;
        $classMetadata->discriminatorColumn = $translatableMetadata->discriminatorColumn;
        if ($classMetadata->discriminatorMap) {
            if (!in_array($classMetadata->getName(), $classMetadata->discriminatorMap)) {
                throw new MissingDiscriminatorMapException(
                    "Discriminator map missing for \"" . $classMetadata->getName() .
                    "\". Did you forgot to implement \"" . TranslatableInterface::class .
                    "\" in \"" . $classMetadata->getName()::getTranslatableEntityClass() . "\"."
                );
            }

            $classMetadata->discriminatorValue = array_flip($translatableMetadata->discriminatorMap)[$translatableMetadata->getName()] ?? null;
            if (!$classMetadata->discriminatorValue) {
                throw new MissingDiscriminatorValueException("Discriminator value missing for \"" . $className->getName() . "\".");
            }
        }
    }

    public function postLoad(LifecycleEventArgs $args)
    {
        $uow = $this->entityManager->getUnitOfWork();
        
        $object = $args->getObject();

        if (is_subclass_of($object, TranslationInterface::class)) {
            if ($object->isEmpty()) { // Mark as removal for mispersistent translations..
                $uow->scheduleOrphanRemoval($object);
            }
        }
    }

    /**
     * @param $intl
     * @return void
     * @throws \Exception
     */
    public function upgradeIntl($intl)
    {
        $translations = [];
        if ($intl instanceof TranslationInterface) {
            $translations[] = $intl;
        }
        if ($intl instanceof TranslatableInterface) {
            $translations = $intl->getTranslations()->toArray();
        }

        foreach ($translations as $translation) {
            $translatable = $translation->getTranslatable();
            if (!$translation instanceof ($translatable::getTranslationEntityClass())) {
                throw new \Exception('Upgrade class type required.');
            }
        }
    }

    /**
     * The same as preFlush(), for an entity the flush itself finds new
     * through a cascade (it was in no scheduled insertion before).
     */
    public function prePersist(PrePersistEventArgs $args)
    {
        $entity = $args->getObject();
        if ($entity instanceof TranslatableInterface && method_exists($entity, 'commitPendingTranslations')) {
            $entity->commitPendingTranslations();
        }
    }

    /**
     * A translation translate() handed out for a locale the entity had none
     * in is held aside until written in (TranslatableTrait): the written ones
     * join their entity's collection here, before the unit of work computes
     * what to insert, so `$entity->translate('de')->setTitle(...)` then
     * flush() persists the German title as it always did.
     */
    public function preFlush(PreFlushEventArgs $args)
    {
        $uow = $this->entityManager->getUnitOfWork();

        $entities = $uow->getScheduledEntityInsertions();
        foreach ($uow->getIdentityMap() as $identities) { // keyed by root class: a translatable may be a subclass
            foreach ($identities as $entity) {
                $entities[] = $entity;
            }
        }

        foreach ($entities as $entity) {
            if (!$entity instanceof TranslatableInterface || !method_exists($entity, 'commitPendingTranslations')) {
                continue;
            }
            // An uninitialized proxy has handed nothing out (any call would have loaded it)
            if (method_exists($uow, 'isUninitializedObject') && $uow->isUninitializedObject($entity)) {
                continue;
            }

            $entity->commitPendingTranslations();
        }
    }

    public function onFlush(OnFlushEventArgs $args)
    {
        $uow = $this->entityManager->getUnitOfWork();

        $scheduledEntities = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $scheduledEntities[] = $entity;
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $scheduledEntities[] = $entity;
        }
        foreach ($uow->getScheduledCollectionUpdates() as $entity) {
            $scheduledEntities[] = $entity->getOwner();
        }

        // Retrieve translatable objects
        $scheduledEntities = array_filter(
            array_unique_object($scheduledEntities),
            fn($e) => $e instanceof TranslationInterface || $e instanceof TranslatableInterface
        );

        // Normalize and turn into orphan intl entities if empty
        foreach (array_unique_object($scheduledEntities) as $entity) {
            $this->normalize($entity);
        }
    }

    /**
     * @param TranslationInterface|TranslatableInterface $entity
     * @return $this
     */
    protected function normalize(TranslationInterface|TranslatableInterface $entity)
    {
        $uow = $this->entityManager->getUnitOfWork();

        if ($entity instanceof TranslatableInterface) {
            foreach ($entity->getTranslations() as $locale => $translation) {
                // An empty translation (translate() makes one for a locale a
                // getter asked about) is never persisted: out of the collection
                // too, or the collection's second-level cache meets an entity
                // the unit of work does not know.
                if ($translation->isEmpty() && !$translation->getId()) {
                    $entity->removeTranslation($translation);
                    if ($this->entityManager->contains($translation)) {
                        $this->entityManager->detach($translation);
                    }
                    continue;
                }
                if (null === $translation->getLocale()) {
                    $translation->setLocale($locale);
                }
                if (null !== $translation->getLocale() && $translation->getLocale() !== $translation->getLocale($locale)) {
                    throw new \InvalidArgumentException('Unexpected locale "' . $translation->getLocale() . '" found with respect to collection key "' . $locale . '".');
                }

                if (!$translation->getTranslatable()) {
                    $translation->setTranslatable($entity);
                }
            }
        }

        if ($entity instanceof TranslationInterface) {
            if ($entity->isEmpty()) {

                $translatable = $entity->getTranslatable();
                if ($translatable) {
                    $translatable->removeTranslation($entity);
                }

                // A saved one is deleted - it was detached first, so the remove()
                // after it never ran: the emptied value kept its row and came
                // back on the next load. In onFlush, through the unit of work.
                // A new one is only forgotten.
                if ($entity->getId()) {
                    $uow->scheduleForDelete($entity);
                } else {
                    $this->entityManager->detach($entity);
                }

            } else {
                $uow->cancelOrphanRemoval($entity);
            }
        }

        return $this;
    }

    /**
     * Adds mapping to the translatable and translations.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $loadClassMetadataEventArgs): void
    {
        $classMetadata = $loadClassMetadataEventArgs->getClassMetadata();

        if (null === $classMetadata->reflClass) {
            return;
        } // Class has not yet been fully built, ignore this event

        if ($classMetadata->isMappedSuperclass) {
            return;
        }

        if (is_subclass_of($classMetadata->reflClass->getName(), TranslatableInterface::class)) {
            $this->mapTranslatable($classMetadata);
        }
        if (is_subclass_of($classMetadata->reflClass->getName(), TranslationInterface::class)) {
            $this->mapTranslation($classMetadata);
        }
    }

    /**
     * Convert string FETCH mode to required string.
     */
    private function convertFetchString($fetchMode): int
    {
        if (is_int($fetchMode)) {
            return $fetchMode;
        }

        switch ($fetchMode) {
            case 'EAGER':
                return ClassMetadata::FETCH_EAGER;

            case 'EXTRA_LAZY':
                return ClassMetadata::FETCH_EXTRA_LAZY;

            default:
            case 'LAZY':
                return ClassMetadata::FETCH_LAZY;
        }

        return ClassMetadata::FETCH_LAZY;
    }

    private function mapTranslatable(ClassMetadata $classMetadata): void
    {
        $targetEntity = $classMetadata->getReflectionClass()->getMethod('getTranslationEntityClass')->invoke(null);
        
        if (!$classMetadata->hasAssociation('translations')) {

            $classMetadata->cache = [
                'region' => $this->entityManager->getConfiguration()->getNamingStrategy()->classToTableName($classMetadata->rootEntityName),
                'usage' => ClassMetadata::CACHE_USAGE_NONSTRICT_READ_WRITE,
            ];

            $classMetadata->mapOneToMany([
                'fieldName' => 'translations',
                'mappedBy' => 'translatable',
                'cache' => [
                    'region' => $this->entityManager->getConfiguration()->getNamingStrategy()->classToTableName($classMetadata->rootEntityName) . '__translations',
                    'usage' => ClassMetadata::CACHE_USAGE_NONSTRICT_READ_WRITE,
                ],
                'indexBy' => TranslatableWalker::LOCALE,
                'cascade' => ['persist', 'refresh', 'remove'],
                'fetch' => $this->convertFetchString('LAZY'),
                'targetEntity' => $targetEntity,
                'orphanRemoval' => true,
            ]);
        }
    }

    private function mapTranslation(ClassMetadata $classMetadata): void
    {
        $targetEntity = $classMetadata->getReflectionClass()->getMethod('getTranslatableEntityClass')->invoke(null);
        $targetClassMetadata = $this->entityManager->getClassMetadata($targetEntity);

        if (!$classMetadata->hasAssociation('translatable')) {

            $classMetadata->cache = [
                'region' => $this->entityManager->getConfiguration()->getNamingStrategy()->classToTableName($classMetadata->rootEntityName),
                'usage' => ClassMetadata::CACHE_USAGE_NONSTRICT_READ_WRITE,
            ];

            $classMetadata->mapManyToOne([
                'fieldName' => 'translatable',
                'inversedBy' => 'translations',
                'cache' => [
                    'region' => $this->entityManager->getConfiguration()->getNamingStrategy()->classToTableName($classMetadata->rootEntityName) . '__translatable',
                    'usage' => ClassMetadata::CACHE_USAGE_NONSTRICT_READ_WRITE,
                ],
                'cascade' => ['persist', 'refresh'],
                'fetch' => $this->convertFetchString('LAZY'),
                'joinColumns' => [[
                    'name' => TranslatableWalker::FOREIGN_KEY,
                    'referencedColumnName' => 'id',
                    'onDelete' => 'CASCADE',
                ]],
                'targetEntity' => $classMetadata->getReflectionClass()
                    ->getMethod('getTranslatableEntityClass')
                    ->invoke(null),
            ]);
        }

        $classMetadata->cache = $targetClassMetadata->cache;
        if (array_key_exists('region', $classMetadata->cache ?? [])) {
            $classMetadata->cache['region'] .= '_translation';
        }

        $namingStrategy = $this->entityManager->getConfiguration()->getNamingStrategy();
        $name = $namingStrategy->classToTableName($classMetadata->rootEntityName) . '_' . TranslatableWalker::SALT;

        if ($classMetadata->getName() == $classMetadata->rootEntityName) {
            $classMetadata->table['uniqueConstraints'][$name] ??= [];
            $classMetadata->table['uniqueConstraints'][$name]['columns'] = array_unique(array_merge(
                $classMetadata->table['uniqueConstraints'][$name]['columns'] ?? [],
                [TranslatableWalker::FOREIGN_KEY, TranslatableWalker::LOCALE]
            ));
        }

        if (!$classMetadata->hasField(TranslatableWalker::LOCALE) && !$classMetadata->hasAssociation(TranslatableWalker::LOCALE)) {
            $classMetadata->mapField(['fieldName' => TranslatableWalker::LOCALE, 'type' => 'string', 'length' => 5]);
        }
    }
}
