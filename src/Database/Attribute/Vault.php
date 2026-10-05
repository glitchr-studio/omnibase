<?php

namespace Base\Database\Attribute;

use Base\Attributes\AbstractAttribute;
use Base\Attributes\AttributeReader;
use Base\Database\Traits\VaultTrait;
use Base\Database\Entity\Extension\TranslationInterface;
use Base\Database\Walker\TranslatableWalker;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Base\Exception\VaultKeyNotFoundException;
use Exception;

use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\Marshaller\SodiumMarshaller;
use Symfony\Component\PropertyAccess\PropertyAccess;


use function is_file;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Vault extends AbstractAttribute
{
    /**
     * @var string
     */
    public string $vault;

    /**
     * @var array
     */
    public array $fields;

    /**
     * @var array
     */
    public array $unique;

    public function __construct(string $vault = "vault", array $fields = [], array $unique = [])
    {
        $this->vault = $vault;
        $this->fields = $fields;
        $this->unique = $unique;
    }

    /**
     * @param string $target
     * @param string|null $targetValue
     * @param $object
     * @return bool
     * @throws Exception
     */
    public function supports(string $target, ?string $targetValue = null, $object = null): bool
    {
        if ($object instanceof ClassMetadata) {
            if (!$this->vault) {
                throw new Exception("Vault field for environment context missing, please provide a valid field \"" . $this->vault . "\"");
            }

            if (!$object->getFieldName($this->vault)) {
                throw new Exception("Field \"" . $this->vault . "\" is missing, did you forget to import \"" . VaultTrait::class . "\" ?");
            }
        }

        return ($target == AttributeReader::TARGET_CLASS);
    }

    /**
     * Where the key pair of a vault is: Symfony's own secrets vault of that
     * environment (`bin/console secrets:generate-keys`).
     */
    public function getKeyPath(?string $vault = null): string
    {
        $vault ??= $this->getEnvironment();

        return $this->getProjectDir() . "/config/secrets/{$vault}/{$vault}.decrypt.private.php";
    }

    /**
     * The key pair of a vault: config/secrets/<vault>/<vault>.decrypt.private.php,
     * else - for the running environment - the SYMFONY_DECRYPTION_SECRET
     * variable (the same key, base64-encoded: how a production server is
     * given it without the file).
     */
    public function loadKeys(?string $vault = null): array
    {
        $vault ??= $this->getEnvironment();

        $path = $this->getKeyPath($vault);
        if (is_file($path)) {
            $keypair = include $path;
        } elseif ($vault === $this->getEnvironment() && ($secret = $_SERVER['SYMFONY_DECRYPTION_SECRET'] ?? $_ENV['SYMFONY_DECRYPTION_SECRET'] ?? null)) {
            $keypair = base64_decode((string) $secret, true);
        } else {
            throw new VaultKeyNotFoundException($vault, $path);
        }

        if (!is_string($keypair) ||
            strlen($keypair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            throw new Exception('Invalid sodium keypair');
        }

        return [$keypair];
    }

    public function getMarshaller(?string $vault = null): ?MarshallerInterface
    {
        try {
            $keys = $this->loadKeys($vault);
        } catch (Exception $e) {
            return null;
        }

        return new SodiumMarshaller($keys);
    }

    /**
     * base.vault.allow_plaintext: the behaviour of before - without a key
     * pair, a secured field is stored as it is. Off unless a site turns it on.
     */
    public function allowsPlaintext(): bool
    {
        try {
            return (bool) $this->getParameterBag('base.vault.allow_plaintext');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a secured field can be stored in this vault: its key pair is
     * there, or the site accepted clear text.
     */
    public function canSeal(?string $vault = null): bool
    {
        return null !== $this->getMarshaller($vault) || $this->allowsPlaintext();
    }

    /**
     * Fails closed: without the vault's key pair the value is refused
     * (VaultKeyNotFoundException), never stored in clear - unless the site
     * said so (base.vault.allow_plaintext).
     *
     * @param MarshallerInterface|null $marshaller
     * @param string|null $value
     * @throws VaultKeyNotFoundException
     */
    public function seal(?MarshallerInterface $marshaller, mixed $value, ?string $vault = null): string
    {
        if (is_array($value) || is_object($value)) {
            $value = serialize($value);
        }

        if ($marshaller === null) {
            if ($this->allowsPlaintext()) {
                return (string) $value;
            }

            throw new VaultKeyNotFoundException($vault ?? $this->getEnvironment(), $this->getKeyPath($vault));
        }

        $failed = [];
        $values = $marshaller->marshall([$value], $failed);
        if ($failed || !isset($values[0])) {
            if ($this->allowsPlaintext()) {
                return (string) $value;
            }

            throw new VaultKeyNotFoundException($vault ?? $this->getEnvironment(), $this->getKeyPath($vault), 'the value could not be sealed');
        }

        return (string) base64_encode($values[0]);
    }

    /**
     * @param MarshallerInterface|null $marshaller
     * @param string|null $value
     * @return mixed|null
     */
    public function reveal(?MarshallerInterface $marshaller, ?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        try { $value = $marshaller?->unmarshall(base64_decode($value)) ?? $value; }
        catch (Exception $e) { }

        // seal() serialize()s arrays/objects, so reveal() genuinely returns
        // whatever was sealed — a string, or the unserialized array/object.
        // The declared type was `?string` (contradicting this method's own
        // `@return mixed|null` docblock); with the previously-broken
        // is_serialized() that mismatch was masked because nothing was ever
        // unserialized. Now that is_serialized() works, the type must be mixed
        // or an array/object @Vault field would hit "Array to string conversion".
        return is_serialized($value) ? unserialize($value) : $value;
    }

    public function loadClassMetadata(ClassMetadata $classMetadata, string $target, ?string $targetValue = null): void
    {
        if ($classMetadata->reflClass === null) {
            return;
        } // Class has not yet been fully built, ignore this event

        if ($classMetadata->isMappedSuperclass) {
            return;
        }

        $namingStrategy = $this->getEntityManager()->getConfiguration()->getNamingStrategy();
        if ($this->unique) {
            $name = $namingStrategy->classToTableName($classMetadata->name) . '_unique';
            $classMetadata->table['uniqueConstraints'][$name]["columns"] = array_unique(array_merge(
                $classMetadata->table['uniqueConstraints'][$name]["columns"] ?? [],
                $this->unique
            ));
        }

        if (is_instanceof($classMetadata->name, TranslationInterface::class)) {
            $name = $namingStrategy->classToTableName($classMetadata->rootEntityName) . '_' . TranslatableWalker::SALT;
            if ($classMetadata->getName() == $classMetadata->rootEntityName) {
                $classMetadata->table['uniqueConstraints'][$name] ??= [];
                $classMetadata->table['uniqueConstraints'][$name]["columns"] = array_unique(array_merge(
                    $classMetadata->table['uniqueConstraints'][$name]["columns"] ?? [],
                    [$this->vault]
                ));
            }
        }
    }

    public function preFlush(PreFlushEventArgs $event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null): void
    {
        $vault = $entity->getVault();

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        foreach ($this->fields as $field) {
            if (!$entity->isSecured()) {
                continue;
            }

            if ($propertyAccessor->isReadable($entity, $field)) {
                $value = $propertyAccessor->getValue($entity, $field);
                if ($value === null) {
                    continue;
                }

                if ($entity->getSealedVaultBag($field) == $value) {
                    continue;
                }
                if ($entity->getPlainVaultBag($field) == $value) {
                    $propertyAccessor->setValue($entity, $field, $entity->getSealedVaultBag($field));
                    continue;
                }

                $this->getEntityManager()->getUnitOfWork()->scheduleForUpdate($entity);
            }
        }
    }

    public function preUpdate(LifecycleEventArgs $event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null): void
    {
        $this->preLifecycleEvent($event, $classMetadata, $entity, $property);
    }

    public function prePersist(LifecycleEventArgs $event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null): void
    {
        $this->preLifecycleEvent($event, $classMetadata, $entity, $property);
    }

    /**
     * @param $event
     * @param ClassMetadata $classMetadata
     * @param mixed $entity
     * @param string|null $property
     * @return void
     */
    public function preLifecycleEvent($event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null): void
    {
        $vault = $entity->getVault();
        $marshaller = $this->getMarshaller($vault);

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        foreach ($this->fields as $field) {

            if (!$entity->isSecured()) {
                continue;
            }

            if ($propertyAccessor->isReadable($entity, $field)) {

                $plainValue = $propertyAccessor->getValue($entity, $field);
                if ($plainValue === null) {
                    continue;
                }

                $sealedValue = $this->seal($marshaller, $plainValue, $vault);
                $propertyAccessor->setValue($entity, $field, $sealedValue);
                $entity->setVaultBag($field, $sealedValue, $plainValue);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null)
    {
        $this->postLifecycleEvent($event, $classMetadata, $entity, $property);
    }

    public function postLoad(LifecycleEventArgs $event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null)
    {
        $this->postLifecycleEvent($event, $classMetadata, $entity, $property);
    }

    /**
     * @param $event
     * @param ClassMetadata $classMetadata
     * @param mixed $entity
     * @param string|null $property
     * @return void
     */
    public function postLifecycleEvent($event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null)
    {
        $vault = $entity->getVault();
        $marshaller = $this->getMarshaller($vault);

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        foreach ($this->fields as $field) {

            if (!$entity->isSecured()) {
                continue;
            }

            if ($propertyAccessor->isReadable($entity, $field)) {

                $sealedValue = $propertyAccessor->getValue($entity, $field);
                if (!is_string($sealedValue) || empty($sealedValue)) {
                    $sealedValue = null;
                }

                if (is_string($sealedValue)) {

                    $plainValue = $this->reveal($marshaller, $sealedValue);
                    $propertyAccessor->setValue($entity, $field, $plainValue);
                    $entity->setVaultBag($field, $sealedValue, $plainValue);
                }
            }
        }
    }
}
