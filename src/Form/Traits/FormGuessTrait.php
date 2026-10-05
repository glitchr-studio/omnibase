<?php

namespace Base\Form\Traits;

use Base\Attributes\AttributeReader;
use Base\Database\Attribute\Alias;
use Base\Database\Attribute\OrderColumn;
use Base\Form\Common\NativeEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Mapping\MappingException;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormInterface;

trait FormGuessTrait
{
    public function guessClass(FormInterface|FormEvent $form, ?array $options = null): ?string
    {
        if ($form instanceof FormEvent) {
            $data = $form->getData();
            $form = $form->getForm();
        } else {
            $data = $form->getData();
        }

        $options = $options ?? $form->getConfig()->getOptions();

        $class = null;
        $options['guess_priority'] = $options['guess_priority'] ?? [
            FormGuessInterface::GUESS_FROM_FORM,
            FormGuessInterface::GUESS_FROM_PHPDOC,
            FormGuessInterface::GUESS_FROM_DATA,
            FormGuessInterface::GUESS_FROM_VIEW,
        ];

        foreach ($options['guess_priority'] as $priority) {
            switch ($priority) {
                case FormGuessInterface::GUESS_FROM_FORM:
                    $class = $options['class'] ?? null;
                    if ($class) {
                        break;
                    }

                    $parentDataClass = null;
                    $formParent = $form->getParent();

                    // Simple case, data view from current form (handle ORM Proxy management)
                    $dataClass = $form->getConfig()->getOption('data_class');

                    while (null !== $formParent) {
                        $parentDataClass = $formParent->getConfig()->getOption('data_class')
                            ?? get_class($formParent->getConfig()->getType()->getInnerType())
                            ?? null;

                        if ($this->classMetadataManipulator->isEntity($parentDataClass)) {
                            $class = $this->classMetadataManipulator->getTargetClass($parentDataClass, $form->getName());
                        }

                        // A property typed with a PHP enum, on an entity (a column
                        // Doctrine does not know as one) or on a plain model.
                        $class ??= $this->guessEnumOfProperty($parentDataClass, $form->getName());

                        if ($class) {
                            break;
                        }

                        $formParent = $formParent->getParent();
                    }

                    break;

                case FormGuessInterface::GUESS_FROM_DATA:
                    if ($data instanceof PersistentCollection) {
                        $class = $data->getTypeClass()->getName();
                    } elseif ($data instanceof ArrayCollection || is_array($data)) {
                        $class = null;
                    } else {
                        $class = is_object($data) ? get_class($data) : null;
                    }

                    break;

                case FormGuessInterface::GUESS_FROM_VIEW:
                    // Simple case, data view from current form (handle ORM Proxy management)
                    if (null !== $dataClass = $form->getConfig()->getDataClass()) {
                        if (false === $pos = strrpos($dataClass, '\\__CG__\\')) {
                            return $dataClass;
                        }

                        return substr($dataClass, $pos + 8);
                    }

                    // Advanced case, loop parent form to get closest data view assuming data is inherited (e.g. TranslationType)
                    // NB: This is not a access to the corresponding expected guess.. but the closest (e.g.)
                    $formParent = $form->getParent();
                    while (null !== $formParent) {
                        if (null === ($data = $formParent->getConfig()->getDataClass())) {
                            $formParent = $formParent->getParent();
                            continue;
                        }

                        if (is_subclass_of($data, Collection::class) || is_array($data)) {
                            $formParent = $formParent->getParent();
                            continue;
                        }

                        if ($this->classMetadataManipulator->isEntity($data)) {
                            $class = $this->classMetadataManipulator->getTargetClass($data, $form->getName());
                        }

                        $formParent = $formParent->getParent();
                    }

                    break;

                case FormGuessInterface::GUESS_FROM_PHPDOC:
                    // To be implemented..

                    break;
            }

            if ($class) {
                break;
            }
        }

        return $class ?? $options['class'] ?? null;
    }

    /**
     * The PHP enum a class's property is typed with (`private Status $status`,
     * `?Status`), null for any other property.
     */
    public function guessEnumOfProperty(?string $class, string $property): ?string
    {
        if (!$class || !class_exists($class) || !property_exists($class, $property)) {
            return null;
        }

        $type = (new \ReflectionProperty($class, $property))->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return NativeEnum::is($type->getName()) ? $type->getName() : null;
    }

    /**
     * @param FormInterface|FormEvent|FormBuilderInterface $form
     * @param array|null $options
     * @return bool|mixed
     * @throws MappingException
     */
    public function guessMultiple(FormInterface|FormEvent|FormBuilderInterface $form, ?array $options = null)
    {
        if ($form instanceof FormEvent) {
            $form = $form->getForm();
        }

        if (!array_key_exists('multiple', $options)) {
            $options['multiple'] = false;
        }

        if (null === $options['multiple']) {
            $target = null;
            $parentForm = $form->getParent();
            if ($parentForm) {
                $options = $parentForm->getConfig()->getOptions();
                $target = $options['class'] ?? $options['data_class'] ?? $options['abstract_class'] ?? null;
            }

            if (null == $target) {
                $options = $options ?? $form->getConfig()->getOptions();
                $target = $options['class'] ?? $options['data_class'] ?? $options['abstract_class'] ?? null;
            }

            if ($this->classMetadataManipulator->isEntity($target)) {
                $targetField = $form->getName();
                if ($this->classMetadataManipulator->hasAssociation($target, $targetField)) {
                    return $this->classMetadataManipulator->isToManySide($target, $targetField);
                } elseif ($this->classMetadataManipulator->hasField($target, $targetField)) {
                    $typeOfField = $this->classMetadataManipulator->getTypeOfField($target, $targetField);
                    if (null !== $this->classMetadataManipulator->getEnumClass($target, $targetField)) {
                        // A PHP enum: one case, or a list of them (enumType: on a simple_array or json column)
                        return in_array($typeOfField, ['simple_array', 'json', 'array'], true);
                    }
                    $doctrineType = $this->classMetadataManipulator->getDoctrineType($typeOfField);

                    if ($this->classMetadataManipulator->isSetType($doctrineType)) {
                        return true;
                    } elseif ($this->classMetadataManipulator->isEnumType($doctrineType)) {
                        return false;
                    } else {
                        return 'array' == $typeOfField || 'json' == $typeOfField;
                    }
                }
            } elseif ($this->classMetadataManipulator->isSetType($target)) {
                return true;
            } elseif ($this->classMetadataManipulator->isEnumType($target)) {
                return false;
            }
        }

        return $options['multiple'] ?? false;
    }

    /**
     * @param FormInterface|FormEvent|FormBuilderInterface $form
     * @param array|null $options
     * @return false|mixed
     * @throws MappingException
     */
    public function guessNullable(FormInterface|FormEvent|FormBuilderInterface $form, ?array $options = null)
    {
        if ($form instanceof FormEvent) {
            $form = $form->getForm();
        }

        if (!array_key_exists('nullable', $options)) {
            $options['allow_null'] = false;
        }

        if (null === $options['allow_null']) {
            $target = null;
            $parentForm = $form->getParent();
            if ($parentForm) {
                $options = $parentForm->getConfig()->getOptions();
                $target = $options['class'] ?? $options['data_class'] ?? $options['abstract_class'] ?? null;
            }

            if (null == $target) {
                $options = $options ?? $form->getConfig()->getOptions();
                $target = $options['class'] ?? $options['data_class'] ?? $options['abstract_class'] ?? null;
            }

            if ($this->classMetadataManipulator->isEntity($target)) {
                $targetField = $form->getName();
                    $this->classMetadataManipulator->getMapping($target, $targetField)['allow_null'] ?? false;
            }
        }

        return $options['allow_null'] ?? false;
    }

    /**
     * @param FormInterface|FormEvent|FormBuilderInterface $form
     * @param array|null $options
     * @return bool|mixed
     * @throws \Exception
     */
    public function guessSortable(FormInterface|FormEvent|FormBuilderInterface $form, ?array $options = null)
    {
        if ($form instanceof FormEvent) {
            $form = $form->getForm();
        }

        $options = $options ?? $form->getConfig()->getOptions();
        if (!array_key_exists('sortable', $options)) {
            $options['sortable'] = false;
        }
        if (!array_key_exists('multivalue', $options)) {
            $options['multivalue'] = false;
        }

        if ($options['multivalue']) {
            return false;
        }
        if (null === $options['sortable']) {
            $target = null;
            $parentForm = $form->getParent();
            if ($parentForm) {
                $options = $parentForm->getConfig()->getOptions();
                $target = $options['class'] ?? $options['data_class'] ?? $options['abstract_class'] ?? null;
            }

            if (null == $target) {
                $options = $options ?? $form->getConfig()->getOptions();
                $target = $options['class'] ?? $options['data_class'] ?? $options['abstract_class'] ?? null;
            }

            $attributes = AttributeReader::getInstance()->getAttributes($target, [OrderColumn::class, Alias::class], [AttributeReader::TARGET_PROPERTY]);
            $options['sortable'] = !empty(array_filter_recursive($attributes['property'][$target][$form->getName()] ?? []));
            if (!$options['sortable']) {

                $columnAlias = $attributes['property'][$target][$form->getName()][Alias::class] ?? null;
                if ($columnAlias) {
                    $aliasedColumn = $columnAlias->getAlias();
                    $options['sortable'] = !empty(array_filter_recursive($attributes['property'][$target][$aliasedColumn][OrderColumn::class] ?? []));
                }
            }
        }

        return $options['sortable'] ?? false;
    }

    /**
     * Guess the order direction (ASC/DESC) for a sortable field, using OrderColumn or OrderBy mapping.
     *
     * @param FormInterface|FormEvent|FormBuilderInterface $form
     * @param array|null $options
     * @return string
     * @throws \Exception
     */
    public function guessOrderDirection(FormInterface|FormEvent|FormBuilderInterface $form, ?array $options = null)
    {
        if ($form instanceof FormEvent) {
            $form = $form->getForm();
        }

        $options = $options ?? $form->getConfig()->getOptions();
        if (!array_key_exists('sortable', $options)) {
            $options['sortable'] = false;
        }
        if (!array_key_exists('multivalue', $options)) {
            $options['multivalue'] = false;
        }

        if ($options['multivalue']) {
            return false;
        }

        $order = null;

        if (null === $options['sortable']) {
            $target = null;
            $parentForm = $form->getParent();
            if ($parentForm) {
                $options = $parentForm->getConfig()->getOptions();
                $target = $options['class'] ?? $options['data_class'] ?? $options['abstract_class'] ?? null;
            }

            if (null == $target) {
                $options = $options ?? $form->getConfig()->getOptions();
                $target = $options['class'] ?? $options['data_class'] ?? $options['abstract_class'] ?? null;
            }

            $attributes = AttributeReader::getInstance()->getAttributes(
                $target,
                [OrderColumn::class, Alias::class],
                [AttributeReader::TARGET_PROPERTY]
            );

            $orderColumn = $attributes['property'][$target][$form->getName()][OrderColumn::class] ?? null;
            if ($orderColumn) {
                $order = strtoupper($orderColumn->getOrder() ?? 'ASC');
            } else {
                $columnAlias = $attributes['property'][$target][$form->getName()][Alias::class] ?? null;
                if ($columnAlias) {
                    $aliasedColumn = $columnAlias->getAlias();
                    $orderColumn = $attributes['property'][$target][$aliasedColumn][OrderColumn::class] ?? null;
                    if ($orderColumn) {
                        $order = strtoupper($orderColumn->getOrder() ?? 'ASC');
                    }
                }
            }

            // Try to guess from Doctrine OrderBy mapping if attribute not found
            if (!$order && $this->classMetadataManipulator && $this->classMetadataManipulator->isEntity($target)) {
                $mapping = $this->classMetadataManipulator->getMapping($target, $form->getName());
                if (isset($mapping->orderBy) && is_array($mapping->orderBy)) {
                    // Get the first order direction for this field
                    $orderDirections = array_values($mapping->orderBy);
                    if (count($orderDirections)) {
                        // A string ('ASC'), or an enum (Doctrine's Order / SortDirection) depending on the mapping driver
                        $direction = $orderDirections[0];
                        $direction = $direction instanceof \BackedEnum ? $direction->value : ($direction instanceof \UnitEnum ? $direction->name : $direction);
                        $order = strtoupper((string) $direction);
                    }
                }
            }
        }

        // Return 'DESC' if found, otherwise 'ASC' or null
        return $order ?: 'ASC';
    }

    /**
     * @param FormInterface|FormBuilderInterface $form
     * @param array|null $options
     * @return mixed|string[]|null
     */
    public function guessChoices(FormInterface|FormBuilderInterface $form, ?array $options = null)
    {
        $options = $options ?? $form->getConfig()->getOptions();
        if (!array_key_exists('choices', $options)) {
            $options['choices'] = null;
        }

        if (!$options['choices']) {
            $class = $options['class'];

            $permittedValues = null;
            if (NativeEnum::is($class)) {
                // A PHP enum: its cases, by what the select holds for each (see NativeEnum::id())
                return NativeEnum::ids($class);
            } elseif ($this->classMetadataManipulator->isEnumType($class)) {
                $permittedValues = $class::getPermittedValuesByClass();
            } elseif ($this->classMetadataManipulator->isSetType($class)) {
                $permittedValues = $class::getPermittedValuesByClass();
            } elseif (array_key_exists('choice_loader', $options) && $options['choice_loader'] instanceof ChoiceLoaderInterface) {
                $permittedValues = $options['choice_loader']->loadChoiceList()->getStructuredValues();
            }

            if (null === $permittedValues) {
                return null;
            }

            return 1 == count($permittedValues) ? begin($permittedValues) : $permittedValues;
        }

        return $options['choices'];
    }

    /**
     * @param FormInterface|FormBuilderInterface $form
     * @param array|null $options
     * @return bool|mixed
     * @throws MappingException
     */
    public function guessChoiceAutocomplete(FormInterface|FormBuilderInterface $form, ?array $options = null)
    {
        $options = $options ?? $form->getConfig()->getOptions();
        if (!array_key_exists('choices', $options)) {
            $options['choices'] = [];
        }
        if (!array_key_exists('autocomplete', $options)) {
            $options['autocomplete'] = null;
        }

        if ($options['choices']) {
            return false;
        }
        if (null === $options['autocomplete'] && $options['class']) {
            $target = $options['class'];
            if ($this->classMetadataManipulator->isEntity($target)) {
                return true;
            }
            if (NativeEnum::is($target)) {
                return false;
            }
            if ($this->classMetadataManipulator->isEnumType($target)) {
                return false;
            }
            if ($this->classMetadataManipulator->isSetType($target)) {
                return false;
            }
        }

        return $options['autocomplete'] ?? false;
    }

    /**
     * @param FormInterface|FormBuilderInterface $form
     * @param array|null $options
     * @param $data
     * @return array|mixed
     */
    public function guessChoiceFilter(FormInterface|FormBuilderInterface $form, ?array $options = null, $data = null)
    {
        $options = $options ?? $form->getConfig()->getOptions();
        if (!array_key_exists('choices_filter', $options)) {
            $options['choices_filter'] = false;
        }

        if (false === $options['choice_filter']) {
            return [];
        }
        if (null === $options['choice_filter']) {
            $options['choice_filter'] = [];
            if ($data) {
                if ($data instanceof Collection || is_array($data)) {
                    foreach ($data as $entry) {
                        if (is_object($entry)) {
                            $options['choice_filter'][] = get_class($entry);
                        }
                    }
                } elseif (is_object($data)) {
                    $options['choice_filter'][] = get_class($data);
                }
            }

            if (!$options['choice_filter'] && $options['class']) {
                $options['choice_filter'][] = $options['class'];
            }
        }

        return $options['choice_filter'] ?? [];
    }
}
