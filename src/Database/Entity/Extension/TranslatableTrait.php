<?php

namespace Base\Database\Entity\Extension;

use Base\Database\Mapping\NamingStrategy;
use Base\Database\Entity\Extension\TranslationInterface;

use Base\Service\BaseService;
use Base\Service\Localizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\Exception\AccessException;

trait TranslatableTrait
{
    private static $translationClass;

    public static function getEntityFqcn(): string
    {
        return self::getTranslationEntityClass()::getTranslatableEntityClass();
    }

    public static function getTranslationEntityClass(
        bool $withInheritance = true, // This is required in some cases, where you must access main class without inheritance
        bool $selfClass = false // Proxies\__CG__ error, if not true during discriminator map building (TranslationType)
    ): ?string
    {
        $class = ($selfClass ? self::class : static::class);

        $prefix = "Proxies\__CG__\\";
        if (str_starts_with($class, $prefix)) {
            $class = substr($class, strlen($prefix));
        }

        if ($withInheritance) {
            self::$translationClass = $class . NamingStrategy::TABLE_I18N_SUFFIX;
            while (!class_exists(self::$translationClass) || !is_subclass_of(self::$translationClass, TranslationInterface::class)) {
                if (!get_parent_class($class)) {
                    throw new Exception("No translation entity found for " . $class);
                }

                $class = get_parent_class($class);
                self::$translationClass = $class . NamingStrategy::TABLE_I18N_SUFFIX;
            }

            return self::$translationClass;
        }

        $translationClass = $class . NamingStrategy::TABLE_I18N_SUFFIX;
        if (!class_exists($translationClass) || !is_subclass_of($translationClass, TranslationInterface::class)) {
            return null;
        }

        return $translationClass;
    }

    /**
     * @var TranslationInterface[]|Collection
     */
    protected $translations = null;

    /**
     * The translations translate() handed out for a locale the entity has none
     * in, by locale: kept out of the collection until something is written in
     * them (see translate()). Not mapped.
     *
     * @var array<string, TranslationInterface>
     */
    protected array $pendingTranslations = [];

    /**
     * @return TranslationInterface|ArrayCollection|Collection
     */
    public function getTranslations()
    {
        if ($this->translations === null) {
            $this->translations = new ArrayCollection();
        }

        $this->commitPendingTranslations();

        return $this->translations;
    }

    /**
     * Moves into the collection the translations translate() handed out that
     * have since been written in; the empty ones stay aside. Called by
     * getTranslations() and translate(), and before each flush by
     * IntlSubscriber::preFlush, so a value written through
     * translate($locale)->setX() is persisted like before.
     *
     * @return $this
     */
    public function commitPendingTranslations()
    {
        foreach ($this->pendingTranslations as $locale => $translation) {
            if ($translation->isEmpty()) {
                continue;
            }

            unset($this->pendingTranslations[$locale]);
            if ($this->translations === null) {
                $this->translations = new ArrayCollection();
            }
            if (!$this->translations->containsKey($locale)) {
                $this->addTranslation($translation);
            }
        }

        return $this;
    }

    /**
     * @param TranslationInterface $translation
     * @return $this
     */
    public function removeTranslation(TranslationInterface $translation)
    {
        foreach ($this->pendingTranslations as $locale => $pending) {
            if ($pending === $translation) {
                unset($this->pendingTranslations[$locale]);
            }
        }

        if ($this->getTranslations()->contains($translation)) {
            $this->getTranslations()->removeElement($translation);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function clearTranslations()
    {
        $this->pendingTranslations = [];
        foreach ($this->getTranslations() as $translation) {
            $this->translations->removeElement($translation);
        }

        return $this;
    }


    /**
     * @param TranslationInterface $translation
     * @return $this
     */
    public function addTranslation(TranslationInterface $translation)
    {
        $this->getTranslations()->set(Localizer::normalizeLocale($translation->getLocale()), $translation);
        $translation->setTranslatable($this);

        return $this;
    }

    /**
     * The translation to read or write in a language.
     *
     * - translate($locale) is that locale's translation. When the entity has
     *   none, a new one for that locale - which the entity holds aside, out of
     *   its collection, until a value is written in it: a mere read (a getter
     *   asking about a language nothing was written in) creates nothing, and
     *   a write (translate('de')->setTitle(...)) is kept as it always was
     *   (commitPendingTranslations()).
     * - translate() (no locale) is the one to show on the current page: the
     *   page's language when something is written in it, else the same
     *   language in another region, else the default locale's, else the
     *   first available locale's, else any written one; only when nothing is
     *   written at all, a new one for the page's language, as above.
     *
     * A read in a language used to add an empty translation for it to the
     * collection, and a later translate() on a page in that language found
     * it: the default language was lost (Attribute::resolve(null) after
     * resolve('ja') gave null).
     *
     * @param string|null $locale
     * @return TranslationInterface|mixed|null
     * @throws Exception
     */
    public function translate(?string $locale = null)
    {
        $localizer = BaseService::getLocalizer();
        if (!$localizer) {
            return null;
        }

        $defaultLocale = $localizer->getDefaultLocale();
        $availableLocales = $localizer->getAvailableLocales();

        $locale = intval($locale) < 0 ? $defaultLocale : $locale;
        $normLocale = $localizer->getLocale($locale); // Locale normalizer
        $translations = $this->getTranslations(); // pending ones written in since are committed here

        $translation = $translations[$normLocale] ?? null;
        if ($translation && ($locale !== null || !$translation->isEmpty())) {
            return $translation;
        }

        if ($locale === null) {
            $written = array_filter($translations->toArray(), fn($t) => $t && !$t->isEmpty());

            // The same language in another region (fr-CA's page, fr-FR's text)
            $lang = $localizer->getLocaleLang($normLocale);
            foreach ($written as $key => $candidate) {
                if (substr((string) $key, 0, 2) === $lang) {
                    return $candidate;
                }
            }

            // The default locale, then the available ones in their order
            foreach (array_unique(array_filter(array_merge([$defaultLocale], $availableLocales))) as $fallbackLocale) {
                $fallbackLocale = $localizer->getLocale($fallbackLocale);
                if (isset($written[$fallbackLocale])) {
                    return $written[$fallbackLocale];
                }
            }

            // Any written one
            if ($written) {
                return reset($written);
            }

            // Nothing written anywhere: the page's own, empty as it is
            if ($translation) {
                return $translation;
            }
        }

        // None in this locale: a new one, held aside until written in
        if (!array_key_exists($normLocale, $this->pendingTranslations)) {
            $translationClass = self::getTranslationEntityClass();
            $translation = new $translationClass();
            $translation->setLocale($normLocale);
            $translation->setTranslatable($this);

            $this->pendingTranslations[$normLocale] = $translation;
        }

        return $this->pendingTranslations[$normLocale];
    }

    /**
     * @param string $method
     * @param array $arguments
     * @throws Exception
     */
    public function __call(string $method, array $arguments): mixed
    {
        $className = self::class;
        $translationClassName = $this->getTranslationEntityClass();
        $parentClass = get_parent_class(self::class);
        
        //
        // Call magic setter
        if (str_starts_with($method, "set")) {
            $property = lcfirst(substr($method, 3));

            if (empty($arguments)) {
                throw new AccessException("Missing argument for setter property \"$property\" in " . $className);
            }

            try {
                return $this->__set($property, ...$arguments);
            } catch (AccessException $e) {
                // Parent fallback setter
                if ($parentClass && method_exists($parentClass, "__set")) {
                    return parent::__set($property, ...$arguments);
                }
            }
        }

        //
        // Figure out is property exist
        $property = null;
        if (property_exists($className, $method)) {
            $property = $method;
        } elseif (property_exists($translationClassName, $method)) {
            $property = $method;
        } elseif (str_starts_with($method, "get") && property_exists($className, lcfirst(substr($method, 3)))) {
            $property = lcfirst(substr($method, 3));
        } elseif (str_starts_with($method, "get") && property_exists($translationClassName, lcfirst(substr($method, 3)))) {
            $property = lcfirst(substr($method, 3));
        } elseif (str_starts_with($method, "is") && property_exists($className, lcfirst(substr($method, 2)))) {
            $property = lcfirst(substr($method, 2));
        } elseif (str_starts_with($method, "is") && property_exists($translationClassName, lcfirst(substr($method, 2)))) {
            $property = lcfirst(substr($method, 2));
        }

        //
        // Call magic getter
        if ($property) {

            try {
                return $this->__get($property);
            } catch (AccessException $e) {
                // Parent fallback getter
                if ($parentClass && method_exists($className, "__get")) {
                    return parent::__get($property);
                }
            }

        } elseif ($translationClassName && method_exists($translationClassName, $method)) {
            return $this->translate()->$method(...$arguments);
        }

        //
        // Parent fallback for magic __call
        if ($parentClass && method_exists($parentClass, "__call")) {
	        return $parentClass::__call($method, $arguments);
        }

        if (!method_exists($className, $method)) {
            throw new AccessException("Method \"$method\" not found in class \"" . get_class($this) . "\" or its corresponding translation class \"" . $this->getTranslationEntityClass() . "\".");
        }

        return null;
    }

    /**
     * @param $property
     * @param $value
     * @return $this
     * @throws Exception
     */
    public function __set(string $property, mixed $value): void
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $property = snake2camel($property);
        $entity = $this;

        // Special case for proxies..
        if(str_starts_with(get_class($entity), "Proxies\\__CG__")) {

            write_property($entity, $property, $value);
            return;
        }

        //
        // Setter method in called class
        if (method_exists($entity, "set" . mb_ucfirst($property))) {
            $entity->{"set" . mb_ucfirst($property)}($value);
            return;
        } elseif (property_exists($entity, $property)) {
            if (!$accessor->isWritable($entity, $property)) {
                throw new AccessException("Property \"$property\" not writable in " . get_class($entity));
            }

            $accessor->setValue($entity, $property, $value);
            return;
        }

        //
        // Setter method for current locale
        $entityIntl = $entity->translate();
        if (method_exists($entityIntl, "set" . mb_ucfirst($property))) {
            
            $entityIntl->{"set" . mb_ucfirst($property)}($value);
            return;

        } elseif (property_exists($entityIntl, $property)) {
            if (!$accessor->isWritable($entityIntl, $property)) {
                throw new AccessException("Property \"$property\" not writable in " . get_class($entityIntl));
            }

            $accessor->setValue($entityIntl, $property, $value);
            return;
        }

        // Prevent "ea_" property exception conflict.. Damn'it.. ! >()
        if (str_starts_with($property, "ea_")) {
            return;
        }

        throw new AccessException("Can't get a way to write property \"$property\" in class \"" . get_class($entity) . "\" or its corresponding translation class \"" . $entity->getTranslationEntityClass() . "\".");
    }

    /**
     * @param $property
     * @return mixed|null
     * @throws Exception
     */
    public function __get(string $property): mixed
    {        
        $accessor = PropertyAccess::createPropertyAccessor();
        $property = snake2camel($property);

        //
        // Getter method in called class
        $entity = $this;
        if (method_exists($entity, $property)) {
            return $entity->{$property}();
        } elseif (method_exists($entity, "get" . mb_ucfirst($property))) {
            return $entity->{"get" . mb_ucfirst($property)}();
        } elseif (property_exists($entity, $property) && $accessor->isReadable($entity, $property)) {
            return $accessor->getValue($entity, $property);
        }

        //
        // Proxy getter method for current locale
        $defaultLocale = BaseService::getLocalizer()?->getDefaultLocale();
        $entityIntl = $entity->translate();
        if(!$entityIntl) {
            return null;
        }

        $value = null;
        if (method_exists($entityIntl, $property)) {
            $value = $entityIntl->{$property}();
        } elseif (method_exists($entityIntl, "get" . mb_ucfirst($property))) {
            $value = $entityIntl->{"get" . mb_ucfirst($property)}();
        } elseif (property_exists($entityIntl, $property) && $accessor->isReadable($entityIntl, $property)) {
            $value = $accessor->getValue($entityIntl, $property);
        }

        // If current locale is empty.. then try to access value from default locale
        // (unless is was already the default locale)
        if ($value !== null) {
            return $value;
        }

        //
        // Proxy getter method for default locale
        if ($entityIntl->getLocale() == $defaultLocale) {
            return $value;
        }

        $entityIntl = $entity->translate($defaultLocale);
        if (method_exists($entityIntl, $property)) {
            return $entityIntl->{$property}();
        } elseif (method_exists($entityIntl, "get" . mb_ucfirst($property))) {
            return $entityIntl->{"get" . mb_ucfirst($property)}();
        } elseif (property_exists($entityIntl, $property) && $accessor->isReadable($entityIntl, $property)) {
            return $accessor->getValue($entityIntl, $property);
        }

        // Exception for EA variables (cf. EA's FormField)
        if (str_starts_with($property, "ea_")) {
            return null;
        }

        throw new AccessException("Can't get a way to read property \"$property\" in class \"" . get_class($entity) . "\" or its corresponding translation class \"" . $entity->getTranslationEntityClass() . "\".");
    }

    public function __isset(string $property): bool
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $property = snake2camel($property);

        $entity = $this;

        // Check if getter method exists in entity
        if (method_exists($entity, $property) || method_exists($entity, "get" . mb_ucfirst($property))) {
            return true;
        }

        // Check if property exists and is readable in entity
        if (property_exists($entity, $property) && $accessor->isReadable($entity, $property)) {
            return true;
        }

        // Proxy: check translation entity for current locale
        $entityIntl = $entity->translate();

        if (method_exists($entityIntl, $property) || method_exists($entityIntl, "get" . mb_ucfirst($property))) {
            return true;
        }

        if (property_exists($entityIntl, $property) && $accessor->isReadable($entityIntl, $property)) {
            return true;
        }

        // If still not found and current locale isn't default, try fallback to default locale
        $defaultLocale = BaseService::getLocalizer()->getDefaultLocale();
        if ($entityIntl->getLocale() !== $defaultLocale) {
            $entityIntl = $entity->translate($defaultLocale);

            if (method_exists($entityIntl, $property) || method_exists($entityIntl, "get" . mb_ucfirst($property))) {
                return true;
            }

            if (property_exists($entityIntl, $property) && $accessor->isReadable($entityIntl, $property)) {
                return true;
            }
        }

        // Special case for EasyAdmin-like variables
        if (str_starts_with($property, "ea_")) {
            return false;
        }

        // Not found anywhere
        return false;
    }
}
