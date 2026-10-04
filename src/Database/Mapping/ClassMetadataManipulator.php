<?php

namespace Base\Database\Mapping;

use Base\Cache\Abstract\AbstractLocalCache;
use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Database\Type\EnumType;
use Base\Database\Type\SetType;
use Base\Field\Type\DateTimePickerType;
use Base\Field\Type\AssociationType;
use Base\Field\Type\SelectType;
use Base\Field\Type\TranslationType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use InvalidArgumentException;

use Base\Database\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\Persistence\Proxy;
use RuntimeException;

use Doctrine\ORM\Mapping\OwningSideMapping;
use Doctrine\ORM\Mapping\InverseSideMapping;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use function count;
use function in_array;

class ClassMetadataManipulator extends AbstractLocalCache
{
    /**
     * @var ManagerRegistry
     * */
    protected ManagerRegistry $doctrine;

    /**
     * @var EntityManagerInterface
     * */
    protected EntityManagerInterface $entityManager;

    /**
     * @var array
     */
    protected array $globalExcludedFields;

    protected static ?string $cacheDir = null;
    protected static ?string $buildDir = null;

    public function __construct(ManagerRegistry $doctrine, EntityManagerInterface $entityManager, ?string $cacheDir = null, ?string $buildDir = null, array $globalExcludedFields = ['id', 'translatable', 'locale'])
    {
        $this->doctrine = $doctrine;
        $this->entityManager = $entityManager;
        $this->globalExcludedFields = $globalExcludedFields;

        self::$cacheDir ??= $cacheDir;
        self::$buildDir ??= $buildDir ?? self::$cacheDir;
        if ($cacheDir) {
            parent::__construct(self::$cacheDir, self::$buildDir);
        }
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    /**
     * @param $entityOrClassOrMetadata
     * @return bool
     * @throws \Doctrine\Persistence\Mapping\MappingException
     */
    public function isEntity($entityOrClassOrMetadata): bool
    {
        if (is_object($entityOrClassOrMetadata)) {
            $entityOrClassOrMetadata = ($entityOrClassOrMetadata instanceof Proxy) ? get_parent_class($entityOrClassOrMetadata) : get_class($entityOrClassOrMetadata);
        }

        if (!is_string($entityOrClassOrMetadata) || !class_exists($entityOrClassOrMetadata)) {
            return false;
        }

        return !$this->getEntityManager()->getMetadataFactory()->isTransient($entityOrClassOrMetadata) || is_instanceof($entityOrClassOrMetadata, Proxy::class);
    }

    /**
     * @param $entityOrClassOrMetadata
     * @return bool
     */
    public function isEnumType($entityOrClassOrMetadata): bool
    {
        if ($entityOrClassOrMetadata instanceof ClassMetadata) {
            $entityOrClassOrMetadata = $entityOrClassOrMetadata->name;
        } elseif (is_object($entityOrClassOrMetadata)) {
            $entityOrClassOrMetadata = get_class($entityOrClassOrMetadata);
        }

        return is_subclass_of($entityOrClassOrMetadata, EnumType::class);
    }

    /**
     * A PHP enum (backed or not), as opposed to omnibase's own EnumType / SetType column types.
     */
    public function isNativeEnum(mixed $class): bool
    {
        if ($class instanceof \UnitEnum) {
            return true;
        }

        return is_string($class) && enum_exists($class);
    }

    /**
     * The PHP enum a column holds: Doctrine's `enumType:` (which Doctrine also
     * reads by itself from a property typed with a backed enum), null for any
     * other column.
     */
    public function getEnumClass(null|string|object $entityOrClassOrMetadata, string $fieldName): ?string
    {
        if (!$this->isEntity($entityOrClassOrMetadata) && !$entityOrClassOrMetadata instanceof ClassMetadata) {
            return null;
        }

        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        $fieldName = $this->getFieldName($classMetadata, $fieldName) ?? $fieldName;
        if (!$classMetadata->hasField($fieldName)) {
            return null;
        }

        $enumType = $classMetadata->getFieldMapping($fieldName)->enumType ?? null;

        return is_string($enumType) && enum_exists($enumType) ? $enumType : null;
    }

    public const DEFAULT_TRACKING = 0;

    protected int $globalTrackingPolicy = self::DEFAULT_TRACKING;

    /**
     * @return int
     */
    public function getGlobalTrackingPolicy()
    {
        return $this->globalTrackingPolicy;
    }

    /**
     * @param int $policy
     * @return $this
     * @throws Exception
     */
    public function setGlobalTrackingPolicy(int $policy)
    {
        $trackingPolicies = [self::DEFAULT_TRACKING, ClassMetadata::CHANGETRACKING_DEFERRED_IMPLICIT, ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT];
        if (!in_array($policy, $trackingPolicies)) {
            throw new Exception("Invalid global tracking policy \"$policy\" provided");
        }

        $this->globalTrackingPolicy = $policy;
        return $this;
    }

    protected array $trackingPolicy = [];

    /**
     * @param $className
     * @return int|mixed
     */
    public function getTrackingPolicy($className)
    {
        return $this->trackingPolicy[$className] ?? $this->globalTrackingPolicy;
    }

    /**
     * @param $className
     * @param int $policy
     * @return $this
     * @throws Exception
     */
    public function setTrackingPolicy($className, int $policy)
    {
        $trackingPolicies = [self::DEFAULT_TRACKING, ClassMetadata::CHANGETRACKING_DEFERRED_IMPLICIT, ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT];
        if (!in_array($policy, $trackingPolicies)) {
            throw new Exception("Invalid tracking policy \"$policy\" provided for \"$className\"");
        }

        $this->trackingPolicy[$className] = $policy;
        return $this;
    }

    /**
     * @param $entityOrClassOrMetadata
     * @return bool
     */
    public function isSetType($entityOrClassOrMetadata): bool
    {
        if ($entityOrClassOrMetadata instanceof ClassMetadata) {
            $entityOrClassOrMetadata = $entityOrClassOrMetadata->name;
        } elseif (is_object($entityOrClassOrMetadata)) {
            $entityOrClassOrMetadata = get_class($entityOrClassOrMetadata);
        }

        return is_subclass_of($entityOrClassOrMetadata, SetType::class);
    }

    /**
     * @param string|null $type
     * @return Type|string|null
     */
    public function getDoctrineType(?string $type)
    {
        if (!$type) {
            return $type;
        }

        try {
            $doctrineType = Type::getType($type);
        } catch (Exception $e) {
            // throw new LogicException("Have you modified an entity (or an enum), or imported a new database ? Please doom the cache if so. Also make sure to use custom db features from base component", $e->getCode(), $e);
            return null;
        }

        return $doctrineType;
    }

    /**
     * @param $entity
     * @return string
     * @throws MappingException
     */
    public function getPrimaryKey($entity)
    {
        return $this->getClassMetadata($entity)->getSingleIdentifierFieldName();
    }

    /**
     * @param $entity
     * @return string|null
     */
    public function getDiscriminatorColumn($entity): ?string
    {
        return $this->getClassMetadata($entity) ? $this->getClassMetadata($entity)->discriminatorColumn["fieldName"] : null;
    }

    /**
     * @param $entity
     * @return string|null
     */
    public function getDiscriminatorValue($entity): ?string
    {
        return $this->getClassMetadata($entity) ? $this->getClassMetadata($entity)->discriminatorValue : null;
    }

    /**
     * @param $entity
     * @return array
     */
    public function getDiscriminatorMap($entity): array
    {
        return $this->getClassMetadata($entity) ? $this->getClassMetadata($entity)->discriminatorMap : [];
    }

    /**
     * @param $entity
     * @return string|null
     */
    public function getRootEntityName($entity): ?string
    {
        return $this->getClassMetadata($entity) ? $this->getClassMetadata($entity)->rootEntityName : null;
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @return EntityRepository|null
     */
    public function getRepository(null|string|object $entityOrClassOrMetadata)
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        return $classMetadata ? $this->getEntityManager()->getRepository($classMetadata->name) : null;
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @return \Doctrine\ORM\Mapping\ClassMetadata|ClassMetadata|ClassMetadata|null
     */
    public function getClassMetadata(null|string|object $entityOrClassOrMetadata)
    {
        if ($entityOrClassOrMetadata === null) {
            return null;
        }
        if ($entityOrClassOrMetadata instanceof PersistentCollection) {
            return $entityOrClassOrMetadata->getTypeClass();
        }
        if ($entityOrClassOrMetadata instanceof ClassMetadata) {
            return $entityOrClassOrMetadata;
        }

        if (is_object($entityOrClassOrMetadata) && !$this->isEntity($entityOrClassOrMetadata)) {
            return null;
        }

        $entityOrClassOrMetadataName = is_object($entityOrClassOrMetadata) ? get_class($entityOrClassOrMetadata) : $entityOrClassOrMetadata;
        $classMetadata = class_exists($entityOrClassOrMetadataName) ? $this->doctrine->getManagerForClass($entityOrClassOrMetadataName)?->getClassMetadata($entityOrClassOrMetadataName) : null;
        if (!$classMetadata) {
            throw new InvalidArgumentException("Entity expected, '" . $entityOrClassOrMetadataName . "' is not an entity.");
        }

        return $classMetadata;
    }

    public function getFields(null|string|object $entityOrClassOrMetadata, array $fields = [], array $excludedFields = []): array
    {
        if (!empty($fields) && !is_associative($fields)) {
            throw new Exception("Associative array expected for 'fields' parameter, '" . gettype($fields) . "' received");
        }

        $fieldKeys = array_keys($fields);

        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        $validFields = array_fill_keys(array_merge($classMetadata->getFieldNames()), []);
        $validFields += $this->getAssociationFormType($classMetadata, $classMetadata->getAssociationNames());

        // Auto detect some fields..
        foreach ($validFields as $fieldName => $field) {
            if (str_starts_with($fieldName, "_")) {
                continue;
            }
            if ($fieldName == "id") {
                $validFields[$fieldName] = ["form_type" => HiddenType::class];
            } elseif ($fieldName == "uuid") {
                $validFields[$fieldName] = ["form_type" => HiddenType::class];
            } elseif ($fieldName == "translations") {
                $validFields[$fieldName] = [
                    "form_type" => TranslationType::class,
                    "translatable_class" => $classMetadata->getName(),
                    "translation_class" => $classMetadata->getName()::getTranslationEntityClass(),
                ];
            } elseif ($this->getTypeOfField($entityOrClassOrMetadata, $fieldName) == "datetime") {
                $validFields[$fieldName] = ["form_type" => DateTimePickerType::class];
            } elseif ($this->getTypeOfField($entityOrClassOrMetadata, $fieldName) == "array" || $this->getTypeOfField($entityOrClassOrMetadata, $fieldName) == "json") {
                $validFields[$fieldName] = ["form_type" => SelectType::class, "tags" => true];
            } elseif ($this->getTypeOfField($entityOrClassOrMetadata, $fieldName) == "integer") {
                $validFields[$fieldName] = ["form_type" => NumberType::class];
            } elseif ($this->getTypeOfField($entityOrClassOrMetadata, $fieldName) == "string") {
                $validFields[$fieldName] = ["form_type" => TextType::class];
            } elseif ($this->getTypeOfField($entityOrClassOrMetadata, $fieldName) == "text") {
                $validFields[$fieldName] = ["form_type" => TextareaType::class];
            } elseif (($enumType = $this->getDoctrineType($this->getTypeOfField($entityOrClassOrMetadata, $fieldName))) instanceof EnumType) {
                $validFields[$fieldName] = ["form_type" => SelectType::class, "class" => get_class($enumType)];
            } elseif (($setType = $this->getDoctrineType($this->getTypeOfField($entityOrClassOrMetadata, $fieldName))) instanceof SetType) {
                $validFields[$fieldName] = ["form_type" => SelectType::class, "class" => get_class($setType)];
            }
        }

        $fields = array_map(fn($f) => is_array($f) ? $f : ["form_type" => $f], $fields);
        foreach ($fields as $fieldName => $field) {
            if (is_array($field) && !empty($field)) {
                $fields[$fieldName]["form_type"] = $field["form_type"] ?? $validFields[$fieldName]["form_type"] ?? null;

                $validFields[$fieldName] = $fields[$fieldName] ?? $validFields[$fieldName] ?? [];

                unset($fields[$fieldName]);
            }
        }

        $validFields = $this->filteringFields($validFields, $excludedFields);
        if (empty($fields)) {
            return array_filter(array_replace(array_fill_keys($fieldKeys, null), $validFields));
        }

        $unmappedFields = $this->filteringRemainingFields($validFields, $fields, $excludedFields, $entityOrClassOrMetadata);
        foreach ($fields as $fieldName => $field) {
            if (in_array($fieldName, $excludedFields, true)) {
                continue;
            }

            if (isset($unmappedFields[$fieldName])) {
                continue;
            }

            if (null === $field) {
                continue;
            }

            if (false === ($field['display'] ?? true)) {
                unset($validFields[$fieldName]);
                continue;
            }

            // Override with priority
            $validFields[$fieldName] = $field + $validFields[$fieldName];
        }

        $aliasNames = [];
        foreach ($classMetadata->fieldNames as $aliasName => $fieldName) {
            if ($aliasName != $fieldName) {
                $aliasNames[$aliasName] = $fieldName;
            }
        }

        $fields = $validFields + $unmappedFields;
        $aliasFields = array_filter($fields, fn($k) => in_array($k, $aliasNames), ARRAY_FILTER_USE_KEY);

        $fields = array_transforms(fn($k, $v): ?array => array_key_exists($k, $aliasNames) ? [$aliasNames[$k], $aliasFields[$aliasNames[$k]]] : [$k, $v], $fields);

        return array_filter(array_replace(array_fill_keys($fieldKeys, null), $fields));
    }

    private function filteringFields(array $fields, array $excludedFields): array
    {
        $excludedFields = array_merge($this->globalExcludedFields, $excludedFields);

        $validFields = [];
        foreach ($fields as $fieldName => $field) {
            if (in_array($fieldName, $excludedFields, true)) {
                continue;
            }

            $validFields[$fieldName] = $field;
        }

        return $validFields;
    }

    private function filteringRemainingFields(array $validFields, array $fields, array $excludedFields, null|string|object $entityOrClassOrMetadata): array
    {
        $unmappedFields = [];

        $validFieldKeys = array_keys($validFields);
        $unknowsFields = [];

        foreach ($fields as $fieldName => $field) {
            if (in_array($fieldName, $excludedFields)) {
                continue;
            }
            if (in_array($fieldName, $validFieldKeys, true)) {
                continue;
            }

            if (false === ($field['mapped'] ?? true)) {
                $unmappedFields[$fieldName] = $field;
                continue;
            }

            $unknowsFields[] = $fieldName;
        }

        if (count($unknowsFields) > 0) {
            throw new RuntimeException(sprintf("Field(s) '%s' doesn't exist in %s", implode(', ', $unknowsFields), $entityOrClassOrMetadata));
        }

        return $unmappedFields;
    }

    private function getAssociationFormType(ClassMetadata $classMetadata, array $associationNames): array
    {
        $fields = [];

        foreach ($associationNames as $assocName) {
            if (!$classMetadata->isAssociationInverseSide($assocName)) {
                continue;
            }

            $entityOrClassOrMetadata = $classMetadata->getAssociationTargetClass($assocName);

            if ($classMetadata->isSingleValuedAssociation($assocName)) {
                $nullable = ($classMetadata instanceof ClassMetadata) && isset($classMetadata->discriminatorColumn['nullable']) && $classMetadata->discriminatorColumn['nullable'];
                $fields[$assocName] = [
                    'type' => AssociationType::class,
                    'data_class' => $entityOrClassOrMetadata,
                    'required' => !$nullable,
                    'allow_recursive' => false
                ];

                continue;
            }

            $fields[$assocName] = [

                'type' => CollectionType::class,
                'entry_type' => AssociationType::class,
                'entry_options' => [
                    'data_class' => $entityOrClassOrMetadata
                ],
                'allow_add' => true,
                'by_reference' => false,
                'allow_recursive' => false
            ];
        }

        return $fields;
    }

    /**
     * @param $entity
     * @param string|array $fieldPath
     * @return mixed
     * @throws Exception
     */
    public function getFieldValue($entity, string|array $fieldPath): mixed
    {
        if ($fieldPath == "") {
            return $entity;
        }

        // Prepare field path information
        if (is_string($fieldPath)) {
            $fieldPath = explode(".", $fieldPath);
        }
        if (empty($fieldPath)) {
            throw new Exception("No field path provided for \"" . get_class($entity) . "\" ");
        }

        if (!$entity) {
            return null;
        }

        // Doctrine ORM 3.4+ proxies associations with NATIVE lazy objects
        // (PHP 8.4): reading a property through Reflection deliberately does
        // NOT initialize them (that is how Doctrine's own hydration bypasses
        // lazy loading). Every reflection-based read below would therefore
        // see NULL on an untouched association (e.g. a template rendering
        // Photo::image -> Image::source straight away). Force initialization
        // before reading; classic Proxy instances get the same treatment.
        if (is_object($entity)) {
            $reflClass = new \ReflectionClass($entity);
            if (method_exists($reflClass, 'isUninitializedLazyObject') && $reflClass->isUninitializedLazyObject($entity)) {
                $reflClass->initializeLazyObject($entity);
            } elseif ($entity instanceof \Doctrine\Persistence\Proxy && !$entity->__isInitialized()) {
                $entity->__load();
            }
        }

        $entityName = get_class($entity);

        // Extract leading field && get metadata
        $fieldName = array_shift($fieldPath);

        // Check entity validity
        if (!is_object($entity)) {
            if (!empty($fieldName)) {
                throw new Exception("Failed to find property \"" . implode('.', array_merge([$fieldName], $fieldPath)) . "\" using \"" . (is_object($entity) ? get_class($entity) : "NULL") . "\" data");
            } else {
                return null;
            }
        }

        // Extract key information
        $fieldRegex = ["/(\w*)\[(\w*)\]/", "/(\w*)%(\w*)%/", "/(\w*){(\w*)}/", "/(\w*)\((\w*)\)/"];
        $fieldKey = preg_replace($fieldRegex, "$2", $fieldName, 1);
        if ($fieldKey) {
            $fieldName = preg_replace($fieldRegex, "$1", $fieldName, 1);
        } else {
            $fieldKey = null;
        }

        // Go get class metadata
        $classMetadata = $this->getClassMetadata($entity);
        if (!$classMetadata) {
            return null;
        }

        if ($entity instanceof Proxy) {
            $entity->__load();
        }

        if (class_implements_interface($entity, TranslatableInterface::class) && $fieldName == "translations") {
            $fieldValue = $entity->getTranslations();
            $fieldName = implode(".", $fieldPath);
            $fieldPath = "";
        }

        if ($this->hasField($entity, $fieldName)) {
            $fieldValue = $classMetadata->getFieldValue($entity, $fieldName);
        } else {
            $propertyAccessor = PropertyAccess::createPropertyAccessor();
            $fieldValue = $propertyAccessor->getValue($entity, $fieldName);
        }

        if (is_array($fieldValue)) {
            $entity = $fieldKey ? $fieldValue[$fieldKey] ?? null : null;
            $fieldValue = $fieldValue ?? begin($fieldValue);
        } elseif (class_implements_interface($fieldValue, Collection::class)) {
            $fieldValue = $fieldValue->containsKey($fieldKey) ? $fieldValue->get($fieldKey ?? 0) : null;
        }

        if (!is_object($fieldValue)) {
            if (empty($fieldPath)) {
                return $fieldValue;
            }
            throw new Exception("Failed to resolve property path \"" . implode('.', array_merge([$fieldName], $fieldPath)) . "\" in \"" . $entityName . "\"");
        }

        // If field path is not empty
        if (!empty($fieldPath)) {
            return $this->getFieldValue($fieldValue, implode(".", $fieldPath));
        }

        // Access property
        return $fieldValue;
    }

    /**
     * @param $entity
     * @param string|array $fieldPath
     * @param $value
     * @return $this|false
     */
    public function setFieldValue($entity, string|array $fieldPath, $value)
    {
        $classMetadata = $this->getClassMetadata($entity);
        if (!$classMetadata) {
            return false;
        }

        $fieldName = $this->getFieldName($entity, $fieldPath) ?? $fieldPath;
        $classMetadata->setFieldValue($entity, $fieldName, $value);

        return $this;
    }

    public function hasField(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        $fieldName = $this->getFieldName($classMetadata, $fieldName) ?? null;
        return ($fieldName != null);
    }

    public function hasProperty(null|string|object $entityOrClassOrMetadata, string $fieldName): ?string
    {
        return property_exists($entityOrClassOrMetadata, $fieldName);
    }

    /**
     * @param $entity
     * @param string $property
     * @return mixed|null
     */
    public function getPropertyValue($entity, string $property)
    {
        if (!$entity) {
            return null;
        }

        $classMetadata = $this->getClassMetadata($entity);
        $fieldName = $classMetadata->getFieldName($property);

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        return $this->hasProperty($entity, $fieldName) ? $propertyAccessor->getValue($entity, $fieldName) : null;
    }

    /**
     * @param $entity
     * @param string $property
     * @param $value
     * @return $this
     */
    public function setPropertyValue($entity, string $property, $value)
    {
        $classMetadata = $this->getClassMetadata($entity);
        $fieldName = $classMetadata->getFieldName($property);

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $propertyAccessor->setValue($entity, $fieldName, $value);

        return $this;
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @param string $property
     * @return false|mixed|string|null
     * @throws MappingException
     */
    public function getType(null|string|object $entityOrClassOrMetadata, string $property)
    {
        return $this->hasAssociation($entityOrClassOrMetadata, $property)
            ? $this->getTypeOfAssociation($entityOrClassOrMetadata, $property)
            : $this->getTypeOfField($entityOrClassOrMetadata, $property);
    }

    /**
     * @param FormInterface|FormEvent $form
     * @return mixed|null
     */
    public function getClosestEntity(FormInterface|FormEvent $form)
    {
        if ($form instanceof FormEvent) {
            $form = $form->getForm();
        }

        while (!$this->isEntity($form->getData())) {
            $form = $form->getParent();
            if ($form === null) {
                return null;
            }
        }

        return $form->getData();
    }

    /**
     * @param FormInterface|FormEvent $form
     * @return mixed|null
     */
    public function getClosestEntityCollection(FormInterface|FormEvent $form)
    {
        if ($form instanceof FormEvent) {
            $form = $form->getForm();
        }

        do {
            $form = $form->getParent();
            if ($form === null) {
                return null;
            }
        } while (!$this->isEntity($form->getParent()?->getData()));

        return $form->getData();
    }

    public function isCollectionOwner(object $entityOrForm, ?Collection $collection = null): ?bool
    {
        $entity = $this->isEntity($entityOrForm) ? $entityOrForm : $this->getClosestEntity($entityOrForm);
        if ($entity == null) {
            return null;
        }

        $collection ??= $this->isEntity($entityOrForm) ? null : $this->getClosestEntityCollection($entityOrForm);
        if (!$collection instanceof Collection) {
            return null;
        }

        if ($collection instanceof PersistentCollection) {
            if ($collection->getOwner() === null) {
                return null;
            }

            $isTranslatable = class_implements_interface($collection->getOwner(), TranslationInterface::class);
            if ($isTranslatable && $entity == $collection->getOwner()->getTranslatable()) {
                return true;
            }

            return $entity === $collection->getOwner();
        }

        return in_class($entity, $collection) || $collection instanceof ArrayCollection;
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @param $fieldPath
     * @return mixed|string|null
     * @throws Exception
     */
    public function getDeclaringEntity(null|string|object $entityOrClassOrMetadata, $fieldPath)
    {
        $fieldMapping = $this->getFieldMapping($entityOrClassOrMetadata, $fieldPath);
        if ($fieldMapping != null && $fieldMapping->declared) {
            return $fieldMapping->declared;
        }

        if (($dot = strpos($fieldPath, ".")) > 0) {
            $fieldPath = trim(substr($fieldPath, 0, $dot));
            $fieldPath = $this->getFieldName($entityOrClassOrMetadata, $fieldPath) ?? $fieldPath;

            return $this->getTargetClass($entityOrClassOrMetadata, $fieldPath);
        }

        return $this->getClassMetadata($entityOrClassOrMetadata)?->getName();
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @param $fieldName
     * @return string|null
     * @throws Exception
     */
    public function getTargetClass(null|string|object $entityOrClassOrMetadata, $fieldName)
    {
        // Associations can help to guess the expected returned values
        $fieldName = $this->getFieldName($entityOrClassOrMetadata, $fieldName) ?? $fieldName;
        if ($this->hasAssociation($entityOrClassOrMetadata, $fieldName)) {
            return $this->getAssociationTargetClass($entityOrClassOrMetadata, $fieldName);
        } elseif ($this->hasField($entityOrClassOrMetadata, $fieldName)) {
            // A PHP enum (enumType:, or a property typed with one)
            if (null !== $enumClass = $this->getEnumClass($entityOrClassOrMetadata, $fieldName)) {
                return $enumClass;
            }

            // Doctrine types as well.. (e.g. EnumType or SetType)
            $fieldType = $this->getTypeOfField($entityOrClassOrMetadata, $fieldName);

            $doctrineType = $this->getDoctrineType($fieldType);
            if ($this->isEnumType($doctrineType) || $this->isSetType($doctrineType)) {
                return get_class($doctrineType);
            }
        }

        return null;
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @param string $property
     * @return false|string|null
     * @throws Exception
     */
    public function getTypeOfField(null|string|object $entityOrClassOrMetadata, string $property)
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        if (($dot = strpos($property, ".")) > 0) {
            $field = trim(substr($property, 0, $dot));
            $field = $this->getFieldName($entityOrClassOrMetadata, $field) ?? $field;

            $property = trim(substr($property, $dot + 1));

            if (!$classMetadata->hasAssociation($field)) {
                throw new Exception("No association found for field \"$field\" in \"" . get_class($entityOrClassOrMetadata) . "\"");
            }

            $entityOrClassOrMetadata = $this->getTypeOfField($entityOrClassOrMetadata, $field);
            if ($entityOrClassOrMetadata instanceof ArrayCollection) {
                $entityOrClassOrMetadata = $entityOrClassOrMetadata->first();
            } elseif (is_array($entityOrClassOrMetadata)) {
                $entityOrClassOrMetadata = current($entityOrClassOrMetadata) ?? null;
            }

            return $this->getTypeOfField($entityOrClassOrMetadata, $property);
        }

        return ($classMetadata->hasField($property) ? $classMetadata->getTypeOfField($property) : null);
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @param string $property
     * @return false|mixed|null
     * @throws MappingException
     */
    public function getTypeOfAssociation(null|string|object $entityOrClassOrMetadata, string $property)
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        if (($dot = strpos($property, ".")) > 0) {
            $field = trim(substr($property, 0, $dot));
            $field = $this->getFieldName($entityOrClassOrMetadata, $field) ?? $field;

            $property = trim(substr($property, $dot + 1));

            if (!$classMetadata->hasAssociation($field)) {
                throw new Exception("No association found for field \"$field\" in \"" . get_class($entityOrClassOrMetadata) . "\"");
            }

            $entityOrClassOrMetadata = $this->getTypeOfAssociation($entityOrClassOrMetadata, $field);
            if ($entityOrClassOrMetadata instanceof ArrayCollection) {
                $entityOrClassOrMetadata = $entityOrClassOrMetadata->first();
            } elseif (is_array($entityOrClassOrMetadata)) {
                $entityOrClassOrMetadata = current($entityOrClassOrMetadata) ?? null;
            }

            return $this->getTypeOfAssociation($entityOrClassOrMetadata, $property);
        }

        return ($classMetadata->hasAssociation($property) ? $classMetadata->getAssociationMapping($property)["type"] ?? null : null);
    }

    public function fetchEntityName(string $entityName, array|string $fieldPath): ?string
    {
        return $this->fetchEntityMapping($entityName, $fieldPath)["targetEntity"] ?? null;
    }

    public function fetchEntityMapping(string $entityName, array|string $fieldPath): null|FieldMapping|AssociationMapping
    {
        $fieldPath = is_array($fieldPath) ? $fieldPath : explode(".", $fieldPath);
        $fieldName = head($fieldPath);
        $classMetadata = $this->doctrine->getManagerForClass($entityName)->getClassMetadata($entityName);

        if ($classMetadata->hasAssociation($this->getFieldName($classMetadata, $fieldName))) {
            $entityMapping = $classMetadata->associationMappings[$this->getFieldName($classMetadata, $fieldName)];
        } elseif ($classMetadata->hasField($this->getFieldName($classMetadata, $fieldName))) {
            $entityMapping = $classMetadata->fieldMappings[$this->getFieldName($classMetadata, $fieldName)];
        } else {
            return null;
        }

        $fieldName = $fieldPath ? head($fieldPath) : $fieldName;
        $fieldPath = tail($fieldPath, $this->isToManySide($entityName, $fieldName) ? -2 : -1);
        if (!$fieldPath) {
            return $entityMapping;
        }

        // Doctrine 3.x returns typed mapping objects (AssociationMapping/
        // FieldMapping) instead of plain arrays - only association mappings
        // carry a targetEntity, so check via instanceof rather than
        // array_key_exists (which fatals on an object) or isset() on a
        // property that plain FieldMapping objects don't declare at all.
        if ($entityMapping instanceof AssociationMapping) {
            return $this->fetchEntityMapping($entityMapping->targetEntity, implode(".", $fieldPath));
        }
        if (is_array($entityMapping) && array_key_exists("targetEntity", $entityMapping)) {
            return $this->fetchEntityMapping($entityMapping["targetEntity"], implode(".", $fieldPath));
        }

        return null; // Fallback, invalid path provided
    }

    public function isAlias(null|object|string $entityOrClassOrMetadata, array|string $fieldPath): bool
    {
        return $this->getFieldName($entityOrClassOrMetadata, $fieldPath) != $fieldPath;
    }

    public function getBackFieldName(null|object|string $entityOrClassOrMetadata, array|string $fieldName): ?string
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata->hasAssociation($fieldName)) {
            return null;
        }

        $fieldName = $this->getFieldName($entityOrClassOrMetadata, $fieldName) ?? $fieldName;
        return $classMetadata->associationMappings[$fieldName]["inversedBy"] ?? null;
    }

    public function getFieldNameMappedBy(null|object|string $entityOrClassOrMetadata, string $mappedBy): ?string
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return null;
        }

        foreach ($classMetadata->associationMappings as $fieldName => $associationMapping) {

            if (!$associationMapping instanceof OwningSideMapping) continue;
            if (($associationMapping["mappedBy"] ?? null) == $mappedBy) {
                return $fieldName;
            }
        }

        return null;
    }

    public function getFieldNameInversedBy(null|object|string $entityOrClassOrMetadata, string $inversedBy): ?string
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return null;
        }

        foreach ($classMetadata->associationMappings as $fieldName => $associationMapping) {

            if (!$associationMapping instanceof InverseSideMapping) continue;
            if ($associationMapping["inversedBy"] ?? null == $inversedBy) {
                return $fieldName;
            }
        }

        return null;
    }

    public function getFieldName(null|object|string $entityOrClassOrMetadata, array|string $fieldName): ?string
    {
        return $this->getFieldNames($entityOrClassOrMetadata)[$fieldName] ?? null;
    }

    public function getFieldNames(null|object|string $entityOrClassOrMetadata): ?array
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return null;
        }

        $associationFields = array_keys($classMetadata->associationMappings);
        $classMetadataEnhanced = $this->getClassMetadataCompletor($entityOrClassOrMetadata);
        return array_merge($classMetadataEnhanced->aliasNames ?? [], $classMetadata->fieldNames, array_combine($associationFields, $associationFields));
    }

    public function resolveFieldPath(null|object|string $entityOrClassOrMetadata, array|string $fieldPath): ?string
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        $entityName = $classMetadata->getName();
        $fieldPath = is_array($fieldPath) ? $fieldPath : explode(".", $fieldPath);
        $fieldName = head($fieldPath);

        $classMetadata = $this->getClassMetadata($entityName);
        $columnNames = $this->getFieldNames($classMetadata);

        while (!array_key_exists($fieldName, $columnNames)) {
            if (!get_parent_class($classMetadata->getName())) {
                break;
            }

            $classMetadata = $this->getClassMetadata(get_parent_class($classMetadata->getName()));
            $columnNames = $this->getFieldNames($classMetadata);
        }

        $fieldName = $columnNames[$fieldName] ?? $fieldName;
        if (!$fieldName) {
            return null;
        }

        $fieldPath = tail($fieldPath, $this->isToManySide($entityName, $fieldName) ? -2 : -1);
        if (!$fieldPath) {
            return $fieldName;
        }

        $filePath = $this->hasAssociation($entityName, $fieldName) ? $this->resolveFieldPath($this->getAssociationTargetClass($entityName, $fieldName), $fieldPath) : null;
        if (!$filePath) {
            return null;
        }

        return $fieldName . "." . $filePath;
    }

    public function getAssociationTargetClass(null|string|object $entityOrClassOrMetadata, string $fieldName): string
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        $fieldName = $this->getFieldName($classMetadata, $fieldName) ?? $fieldName;
        return $classMetadata->getAssociationTargetClass($fieldName);
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @param string|null $fieldName
     * @return bool
     */
    public function hasAssociation(null|string|object $entityOrClassOrMetadata, ?string $fieldName)
    {
        if ($fieldName === null) {
            return false;
        }

        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        $fieldName = $this->getFieldName($classMetadata, $fieldName) ?? $fieldName;
        return $classMetadata->hasAssociation($fieldName);
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @param string|null $fieldName
     * @return bool
     */
    public function hasRecursiveAssociation(null|string|object $entityOrClassOrMetadata, ?string $fieldName)
    {
        if ($fieldName === null) {
            return false;
        }
        if ($this->hasAssociation($entityOrClassOrMetadata, $fieldName)) {
            $associationMapping = $this->getAssociationMapping($entityOrClassOrMetadata, $fieldName);
            return $associationMapping["targetEntity"] == $associationMapping["sourceEntity"];
        }

        return false;
    }

    public function getFieldMapping(null|string|object $entityOrClassOrMetadata, string $fieldName): null|FieldMapping|AssociationMapping
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return null;
        }

        return $this->fetchEntityMapping($classMetadata->getName(), $fieldName);
    }

    public function getAssociationMapping(null|string|object $entityOrClassOrMetadata, string $fieldName): ?AssociationMapping
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return null;
        }

        $fieldName = $this->getFieldName($classMetadata, $fieldName) ?? $fieldName;
        return $classMetadata->getAssociationMapping($fieldName) ?? null;
    }

    public function getUniqueKeys(null|string|object $entityOrClassOrMetadata, bool $inherits = false): ?array
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return null;
        }

        $uniqueKeys = [];

        do {
            foreach (($classMetadata->fieldNames) as $fieldName) {
                if ($this->getMapping($entityOrClassOrMetadata, $fieldName)["unique"] ?? false) {
                    $uniqueKeys[] = $fieldName;
                }
            }

            $parentName = get_parent_class($classMetadata->getName());
            $classMetadata = $parentName ? $this->getClassMetadata($parentName) : null;
        } while ($classMetadata && $classMetadata->getName() && $inherits);

        return $uniqueKeys;
    }

    public function hasTranslation(null|string|object $entityOrClassOrMetadata): bool
    {
        return $this->hasAssociation($entityOrClassOrMetadata, "translations");
    }

    public function getTranslationMapping(null|string|object $entityOrClassOrMetadata, string $fieldName): ?FieldMapping
    {
        if ($this->hasAssociation($entityOrClassOrMetadata, "translations")) {
            return $this->getMapping($this->getClassMetadata($entityOrClassOrMetadata)->getAssociationMapping("translations")["targetEntity"], $fieldName);
        }

        return null;
    }

    public function getMapping(null|string|object $entityOrClassOrMetadata, string $fieldName): null|FieldMapping|AssociationMapping
    {
        if ($this->hasAssociation($entityOrClassOrMetadata, $fieldName)) {
            return $this->getAssociationMapping($entityOrClassOrMetadata, $fieldName);
        } elseif ($this->hasField($entityOrClassOrMetadata, $fieldName)) {
            return $this->getFieldMapping($entityOrClassOrMetadata, $fieldName);
        } elseif ($this->hasTranslation($entityOrClassOrMetadata)) {
            return $this->getTranslationMapping($entityOrClassOrMetadata, $fieldName);
        }

        return null;
    }

    public function getAssociationMappings(null|string|object $entityOrClassOrMetadata): ?array
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        return $classMetadata?->getAssociationMappings();

    }

    public function isOwningSide(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        if (!$this->hasAssociation($entityOrClassOrMetadata, $fieldName)) {
            return false;
        }
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        $fieldName = $this->getFieldName($classMetadata, $fieldName) ?? $fieldName;
        return !$classMetadata->isAssociationInverseSide($fieldName);
    }

    public function isInverseSide(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        if (!$this->hasAssociation($entityOrClassOrMetadata, $fieldName)) {
            return false;
        }
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        $fieldName = $this->getFieldName($classMetadata, $fieldName) ?? $fieldName;
        return $classMetadata->isAssociationInverseSide($fieldName);
    }

    public function isToOneSide(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        return in_array($this->getAssociationType($entityOrClassOrMetadata, $fieldName), [ClassMetadata::ONE_TO_ONE, ClassMetadata::MANY_TO_ONE], true);
    }

    public function isToManySide(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        return in_array($this->getAssociationType($entityOrClassOrMetadata, $fieldName), [ClassMetadata::ONE_TO_MANY, ClassMetadata::MANY_TO_MANY], true);
    }

    public function isManyToSide(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        return in_array($this->getAssociationType($entityOrClassOrMetadata, $fieldName), [ClassMetadata::MANY_TO_ONE, ClassMetadata::MANY_TO_MANY], true);
    }

    public function isOneToSide(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        return in_array($this->getAssociationType($entityOrClassOrMetadata, $fieldName), [ClassMetadata::ONE_TO_MANY, ClassMetadata::ONE_TO_ONE], true);
    }

    public function isManyToMany(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        return $this->getAssociationType($entityOrClassOrMetadata, $fieldName) === ClassMetadata::MANY_TO_MANY;
    }

    public function isOneToMany(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        return $this->getAssociationType($entityOrClassOrMetadata, $fieldName) === ClassMetadata::ONE_TO_MANY;
    }

    public function isManyToOne(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        return $this->getAssociationType($entityOrClassOrMetadata, $fieldName) === ClassMetadata::MANY_TO_ONE;
    }

    public function isOneToOne(null|string|object $entityOrClassOrMetadata, string $fieldName): bool
    {
        return $this->getAssociationType($entityOrClassOrMetadata, $fieldName) === ClassMetadata::ONE_TO_ONE;
    }

    /**
     * @param string|object|null $entityOrClassOrMetadata
     * @param string $fieldName
     * @return false|int|mixed
     * @throws MappingException
     */
    public function getAssociationType(null|string|object $entityOrClassOrMetadata, string $fieldName)
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        if (!$classMetadata) {
            return false;
        }

        if (!$this->hasAssociation($classMetadata, $fieldName)) {
            return false;
        }

        $fieldName = $this->getFieldName($classMetadata, $fieldName) ?? $fieldName;
        return $classMetadata->getAssociationMapping($fieldName)['type'] ?? 0;
    }

    protected static array $completors = [];

    /**
     * @param object|string $className
     * @return ClassMetadataCompletor|mixed
     */
    protected function getCompletorFor(object|string $className)
    {
        $className = is_object($className) ? get_class($className) : $className;
        if (array_key_exists($className, self::$completors) && !isset(self::$completors->payload)) {
            return self::$completors[$className];
        }

        self::$completors[$className] = new ClassMetadataCompletor($className, []);
        return self::$completors[$className];
    }
    
    /**
     * @return array|string[]
     */
    public function getAllClassNames()
    {
        $classMetadataFactory = $this->entityManager->getMetadataFactory();
        if ($classMetadataFactory instanceof ClassMetadataFactory) {
            return $classMetadataFactory->getAllClassNames();
        }

        return [];
    }

    public function getClassMetadataCompletor(null|string|object $entityOrClassOrMetadata): ?ClassMetadataCompletor
    {
        $classMetadata = $this->getClassMetadata($entityOrClassOrMetadata);
        return $classMetadata ? $this->getCompletorFor($classMetadata->name) : null;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        self::$completors = $this->getCache("/Completors", function () {

            foreach ($this->getAllClassNames() as $className) {
                $this->getCompletorFor($className);
            }

            return self::$completors;
        }) ?? [];

        return [];
    }

    /**
     * Runs the attribute-driven loadClassMetadata pipeline for every entity so that
     * completors get their `aliasNames`, `entityHierarchy`, etc. fields populated,
     * then persists the enriched completors to the /Completors cache entry.
     *
     * Must be called from a context where both this service and the AttributeReader
     * are fully constructed (i.e. a Symfony cache warmer), never from __construct
     * (that would recurse through the AttributeReader -> ClassMetadataManipulator
     * dependency).
     */
    public function enrichAndSaveCompletors(\Base\Attributes\AttributeReader $attributeReader): void
    {
        self::$completors = [];

        foreach ($this->getAllClassNames() as $className) {
            $classMetadata = $this->getClassMetadata($className);
            if ($classMetadata === null) {
                $this->getCompletorFor($className);
                continue;
            }

            $this->getCompletorFor($className);
            $this->runAttributeLoadClassMetadata($classMetadata, $attributeReader);
        }

        $this->saveCompletors();
        $this->commitCache();
    }

    /**
     * Mirrors AttributeSubscriber::loadClassMetadata(): iterates class/method/property
     * attributes and invokes $attribute->loadClassMetadata(...) on each, which is the
     * code path that writes aliasNames / entityHierarchy onto the completor.
     */
    protected function runAttributeLoadClassMetadata(ClassMetadata $classMetadata, \Base\Attributes\AttributeReader $attributeReader): void
    {
        $className = $classMetadata->name;
        $attributes = $attributeReader->getAttributes($className);

        $classAttributes = $attributes[\Base\Attributes\AttributeReader::TARGET_CLASS][$className] ?? [];
        foreach ($classAttributes as $attribute) {
            if (!is_subclass_of($attribute, \Base\Attributes\AbstractAttribute::class)) {
                continue;
            }
            if (!in_array(\Base\Attributes\AttributeReader::TARGET_CLASS, $attributeReader->getAttributeTargets($attribute))) {
                continue;
            }
            if (!$attribute->supports(\Base\Attributes\AttributeReader::TARGET_CLASS, $className, $classMetadata)) {
                continue;
            }
            $attribute->loadClassMetadata($classMetadata, \Base\Attributes\AttributeReader::TARGET_CLASS, $className);
        }

        $methodAttributes = $attributes[\Base\Attributes\AttributeReader::TARGET_METHOD][$className] ?? [];
        foreach ($methodAttributes as $method => $_) {
            foreach ($_ as $attribute) {
                if (!is_subclass_of($attribute, \Base\Attributes\AbstractAttribute::class)) {
                    continue;
                }
                if (!in_array(\Base\Attributes\AttributeReader::TARGET_METHOD, $attributeReader->getAttributeTargets($attribute))) {
                    continue;
                }
                if (!$attribute->supports(\Base\Attributes\AttributeReader::TARGET_METHOD, $method, $classMetadata)) {
                    continue;
                }
                $attribute->loadClassMetadata($classMetadata, \Base\Attributes\AttributeReader::TARGET_METHOD, $method);
            }
        }

        $propertyAttributes = $attributes[\Base\Attributes\AttributeReader::TARGET_PROPERTY][$className] ?? [];
        foreach ($propertyAttributes as $property => $_) {
            foreach ($_ as $attribute) {
                if (!is_subclass_of($attribute, \Base\Attributes\AbstractAttribute::class)) {
                    continue;
                }
                if (!in_array(\Base\Attributes\AttributeReader::TARGET_PROPERTY, $attributeReader->getAttributeTargets($attribute))) {
                    continue;
                }
                if (!$attribute->supports(\Base\Attributes\AttributeReader::TARGET_PROPERTY, $property, $classMetadata)) {
                    continue;
                }
                $attribute->loadClassMetadata($classMetadata, \Base\Attributes\AttributeReader::TARGET_PROPERTY, $property);
            }
        }
    }

    public function saveCompletors()
    {
        $this->setCache("/Completors", self::$completors, null, true);
    }
}
