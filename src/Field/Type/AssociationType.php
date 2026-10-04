<?php

namespace Base\Field\Type;

use Base\Database\Mapping\ClassMetadataManipulator;
use Base\Database\Entity\EntityHydrator;
use Base\Form\FormFactory;
use Base\Service\Model\Autocomplete;
use Base\Service\TranslatorInterface;
use Base\Traits\BaseTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\OwningSideMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Mapping\MappingException;
use Exception;
use RuntimeException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Doctrine\ORM\Mapping\ToManyOwningSideMapping;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Traversable;

class AssociationType extends AbstractType implements DataMapperInterface
{
    use BaseTrait;

    /**
     * @var ClassMetadataManipulator|null
     */
    protected ?ClassMetadataManipulator $classMetadataManipulator = null;

    /**
     * @var FormFactory|null
     */
    protected ?FormFactory $formFactory = null;

    /**
     * @var TranslatorInterface
     */
    protected TranslatorInterface $translator;

    /**
     * @var Autocomplete
     */
    protected Autocomplete $autocomplete;

    /**
     * @var PropertyAccessorInterface
     */
    protected PropertyAccessorInterface $propertyAccessor;

    /**
     * @var EntityHydrator
     */
    protected $entityHydrator;

    public function getBlockPrefix(): string
    {
        return 'association';
    }

    public function __construct(FormFactory $formFactory, ClassMetadataManipulator $classMetadataManipulator, EntityHydrator $entityHydrator, TranslatorInterface $translator)
    {
        $this->classMetadataManipulator = $classMetadataManipulator;
        $this->entityHydrator = $entityHydrator;
        $this->translator = $translator;
        $this->formFactory = $formFactory;

        $this->autocomplete = new Autocomplete($this->translator);
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => null,
            'form_type' => null,
            // Read by the back office (Base\Admin\Form\FieldFormBuilder), not here: whether an
            // AssociationField is this embedded form (true), a picker (false), or what its
            // association says (null). This type, used by name, always embeds.
            'embed' => null,
            'autoload' => true,
            'href' => null,
            'html' => false,

            'entry_collapsed' => true,
            // Forwarded to the inner CollectionType (see its own comment): caps
            // how many entries are built, and only takes effect when the
            // collection cannot delete.
            'max_entries' => null,
            'entry_offset' => 0,
            'entry_label' => function ($i, $label) {
                if ($i === "__prototype__") {
                    return false;
                }

                if (!is_object($label)) {
                    return $this->translator->trans("@fields.collection.entry") . " #" . (((int)$i) + 1);
                }

                $_label = $this->translator->transEntity($label) . " #" . (((int)$i) + 1);
                if (is_stringeable($label)) {
                    $_label .= " : " . ((string) $label);
                }
                return $_label;
            },

            'entry_required' => true,

            'fields' => [],
            'keep_indexes' => true,
            'length' => 0,
            'excluded_fields' => [],

            'recursive' => null,
            "multiple" => false,
            'group' => true,
            'row_group' => true,

            'allow_add' => true,
            'allow_delete' => true,
            'allow_entity' => true,
            'allow_null' => null
        ]);

        $resolver->setNormalizer('required', function (Options $options, $value) {
            // A to-MANY association is a collection, and "no entries" is a
            // legitimate value for one. Returning entry_required (default true)
            // unconditionally put a browser-level `required` on every such
            // field, so the create form could not be submitted until the
            // operator picked something: Comments/Followers/Replies on a
            // comment, Children on a calendar and a guestbook, Subscribers on
            // a newsletter, Tags on a gallery, Permissions on a group,
            // Hyperlinks on a user - none of which are mandatory. It also
            // overrode an explicit ->setRequired(false), which is why the
            // Article fields had to be fought individually.
            //
            // A genuinely mandatory collection belongs in validation (a Count
            // constraint), which reports WHY it is refusing; an HTML required
            // attribute on a multi-select just blocks submit with no
            // explanation. entry_required still governs the single-valued
            // case, where it means "this one related record is mandatory".
            if ($options["multiple"]) {
                return false;
            }

            return $options["entry_required"];
        });

        $resolver->setNormalizer('data_class', function (Options $options, $value) {
            if ($options["multiple"]) {
                return null;
            }
            return $value ?? null;
        });

        $resolver->setNormalizer('allow_add', function (Options $options, $value) {
            if ($options["group"]) {
                return $value ?? null;
            }
            return false;
        });
        $resolver->setNormalizer('allow_delete', function (Options $options, $value) {
            if ($options["group"]) {
                return $value ?? null;
            }
            return false;
        });
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['href'] = $options["href"];
        $view->vars['html'] = $options["html"];
        $view->vars["multiple"] = $options["multiple"];
        $view->vars["group"] = $options["group"];
        $view->vars["row_group"] = $options["row_group"];
        $view->vars["allow_add"] = $options["allow_add"];
        $view->vars["allow_delete"] = $options["allow_delete"];
        $view->vars["keep_indexes"] = $options["keep_indexes"];
        $view->vars['length'] = $options["length"];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->setDataMapper($this);
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options) {
            $form = $event->getForm();
            $data = $event->getData();

            $options = $form->getConfig()->getOptions();
            $options["class"] = $this->formFactory->guessClass($event, $options);

            $length = $options["group"] ? $options["length"] : max(1, $options["length"]);
            $options["multiple"] = $this->formFactory->guessMultiple($form, $options);

            if ($options["multiple"]) {
                $dataClass = $options["class"];
                unset($options["class"]);

                if (!is_array($data) && !$data instanceof Collection) {
                    $data = [$data];
                }

                $collectionOptions = [
                    "data_class" => null,
                    // `?? false`: with no label to inherit, Symfony humanises the
                    // child's own name and the inner group renders "Collection" -
                    // a second, wrong label under the field's real one ("Comments").
                    // The parent already labels the field, so an unlabelled parent
                    // means "don't label me twice", not "make one up".
                    "label" => $options["label"] ?? false,
                    "html" => $options["html"],
                    'by_reference' => false,
                    'allow_object' => true,
                    'length' => $length,
                    "group" => $options["group"],
                    "row_group" => $options["row_group"],
                    'entry_collapsed' => $options["entry_collapsed"],
                    'max_entries' => $options["max_entries"],
                    'entry_offset' => $options["entry_offset"],
                    'entry_type' => AssociationType::class,
                    'entry_label' => $options["entry_label"],
                    'entry_options' => array_merge($options, [
                        'href' => $options["href"] ?? null,
                        'allow_entity' => $options["allow_entity"],
                        'data_class' => $dataClass,
                        'multiple' => false,
                        'keep_indexes' => $options["keep_indexes"],
                    ]),
                ];

                if ($options["allow_add"] !== null) {
                    $collectionOptions['allow_add'] = $options["group"] ? $options["allow_add"] : false;
                }
                if ($options["allow_delete"] !== null) {
                    $collectionOptions['allow_delete'] = $options["group"] ? $options["allow_delete"] : false;
                }

                $form->add("_collection", CollectionType::class, $collectionOptions);
            } else {
                $dataClass = $options["class"] ?? $this->formFactory->guessClass($event, $options);
                if (!$dataClass) {
                    throw new RuntimeException(
                        'Unable to get "class" or compute "data_class" from form "' . $form->getName() . '" or any of its parents. ' .
                        'Please define "class" option in the main AssociationType you defined or make sure there is a way to guess the expected output information'
                    );
                }

                $fields = $this->classMetadataManipulator->getFields($dataClass, $options["fields"], $options["excluded_fields"]);
                if (!$options["autoload"]) {
                    $fields = array_filter($fields, fn($k) => array_key_exists($k, $options["fields"]), ARRAY_FILTER_USE_KEY);
                }

                foreach ($fields as $fieldName => $field) {
                    if ($options["recursive"] && array_key_exists($form->getName(), $field)) {
                        $field = $field[$form->getName()];
                    }

                    $fieldType = $field['form_type'] ?? null;
                    unset($field['form_type']);

                    $isNullable = $this->classMetadataManipulator->getMapping($dataClass, $fieldName)->nullable ?? false;
                    if (!array_key_exists("required", $field) && $isNullable) {
                        $field['required'] = !$isNullable;
                    }

                    $fieldEntity = $field['allow_entity'] ?? $options["allow_entity"] ?? false;
                    unset($field['allow_entity']);

                    if ($fieldType !== null && $fieldEntity && $fieldType != AssociationType::class) {
                        $form->add($fieldName, $fieldType, $field);
                    }
                }

                if ($options["keep_indexes"]) {
                    $form->add("_index", HiddenType::class, ["mapped" => false, "required" => false]);
                }
            }
        });
    }

    /**
     * @param $viewData
     * @param Traversable $forms
     * @return void
     * @throws Exception
     */
    public function mapDataToForms($viewData, Traversable $forms): void
    {
        // there is no data yet, so nothing to prepopulate
        if (null === $viewData) {
            return;
        }

        $data = $viewData;

        if ($data instanceof Collection) {
            $form = current(iterator_to_array($forms));
            $form->setData($data);
        } elseif (is_object($entity = $data)) {
            $childForms = iterator_to_array($forms);
            foreach ($childForms as $fieldName => $childForm) {
                if (!$childForm->getConfig()->getOption("mapped")) {
                    continue;
                }

                $value = $this->propertyAccessor->getValue($entity, $fieldName);
                if (empty($value)) {
                    $value = null;
                }

                $childFormType = get_class($childForm->getConfig()->getType()->getInnerType());

                if (is_instanceof($childFormType, ArrayType::class)) {
                    if (is_serialized($value)) {
                        $value = unserialize($value);
                    } else {
                        $value = $value !== null && !is_array($value) ? [$value] : $value;
                    }
                }

                if (is_instanceof($childFormType, NumberType::class)) {
                    $value = floatval($value);
                }

                if (is_instanceof($childFormType, BooleanType::class)) {
                    $value = boolval($value);
                }

                if (is_instanceof($childFormType, PercentType::class)) {
                    $value = intval($value);
                }
                if (is_instanceof($childFormType, IntegerType::class)) {
                    $value = intval($value);
                }

                if (is_instanceof($childFormType, CollectionType::class)) {
                    $value ??= [];
                    if ($value instanceof Collection) {
                        $value = $value->toArray();
                    }

                    if (!is_array($value)) {
                        $value = [$value];
                    }
                }

                $childForm->setData($value);
            }
        }
    }

    /**
     * @param Traversable $forms
     * @param $viewData
     * @return void
     * @throws MappingException
     */
    public function mapFormsToData(Traversable $forms, &$viewData): void
    {
        $form = current(iterator_to_array($forms));
        $formParent = $form->getParent();
        if ($formParent?->getData() instanceof PersistentCollection &&
            $this->classMetadataManipulator->isCollectionOwner($formParent, $formParent?->getData()) === false) {
            return;
        }

        $options = $formParent->getConfig()->getOptions();
        $options["class"] = $options["class"] ?? $this->formFactory->guessClass($formParent, $options);
        $options["multiple"] = $options["multiple"] ?? $this->formFactory->guessMultiple($formParent, $options);
        $options["allow_null"] = $options["allow_null"] ?? $this->formFactory->guessNullable($formParent, $options);

        $entries = new ArrayCollection();
        foreach (iterator_to_array($forms) as $fieldName => $childForm) {
            $entries[$fieldName] = $childForm->getData();
        }

        if (!$options["multiple"] && $this->classMetadataManipulator->isEntity($options["class"])) {
            $classMetadata = $this->classMetadataManipulator->getClassMetadata($options["class"]);
            if (!$classMetadata) {
                throw new Exception("Entity \"" . $options["class"] . "\" not found.");
            }

            foreach ($entries as $fieldName => $entry) {
                $formType = $options["fields"][$fieldName]["form_type"] ?? null;
                if ($formType == CollectionType::class) {
                    unset($entries[$fieldName]);
                }
            }

            $aggregateModel = EntityHydrator::CLASS_METHODS;
            if (!$options["allow_null"]) {
                $aggregateModel |= EntityHydrator::IGNORE_NULLS;
            }

            $viewData = $this->entityHydrator->hydrate(
                is_object($viewData) ? $viewData : $options["class"],
                $entries instanceof Collection ? $entries->toArray() : $entries,
                [],
                $aggregateModel
            );

        } elseif ($viewData instanceof PersistentCollection) {

            $fieldName = $viewData->getMapping()->fieldName;
            $isOwningSide = $viewData->getMapping() instanceof ToManyOwningSideMapping;
            if ($entries->containsKey("_collection")) {
                $entries = $entries->get("_collection");
            }

            $mapping = $viewData->getMapping();
            if ($mapping instanceof InverseSideMapping) {

                $mappedBy = $mapping->mappedBy;
                $oldData = $viewData->toArray();
    
                foreach (array_diff_object($oldData, $entries->toArray()) as $entry) {
                    $owningSide = $this->propertyAccessor->getValue($entry, $mappedBy);
                    if (!$owningSide instanceof Collection) {
                        $this->propertyAccessor->setValue($entry, $mappedBy, null);
                    } elseif ($owningSide->contains($viewData->getOwner())) {
                        $owningSide->removeElement($viewData->getOwner());
                    }
                }
            }

            if ($this->classMetadataManipulator->getEntityManager()->getCache()) {

                $mapping = $viewData->getMapping(); // Evict caches and collection caches.
                foreach (array_unique_object(array_union($oldData, $entries->toArray())) as $data) {

                    $this->classMetadataManipulator->getEntityManager()->getCache()->evictEntity(get_class($data), $data->getId());
                    if ($mapping instanceof ToManyOwningSideMapping) {
                        $this->classMetadataManipulator->getEntityManager()->getCache()->evictCollection(get_class($data), $mapping->inversedBy, $data->getId());
                    }
                    if (!$isOwningSide && $mappedBy) {
                        $this->classMetadataManipulator->getEntityManager()->getCache()->evictCollection($mapping->targetEntity, $mappedBy, $viewData->getOwner());
                    }
                }
            }

            $viewData->clear();

            foreach ($entries as $entry) {

                $viewData->add($entry);
                $mapping = $viewData->getMapping();
                if ($mapping instanceof InverseSideMapping) {

                    $mappedBy = $mapping->mappedBy;
                    $owningSide = $this->propertyAccessor->getValue($entry, $mappedBy);
                    if (!$owningSide instanceof Collection) {
                        $this->propertyAccessor->setValue($entry, $mappedBy, $viewData->getOwner());
                    } elseif (!$owningSide->contains($viewData->getOwner())) {
                        $owningSide->add($viewData->getOwner());
                    }
                }
            }

        } elseif ($options["multiple"]) {
            $viewData = new ArrayCollection();
            foreach (iterator_to_array($forms) as $fieldName => $childForm) {
                foreach ($childForm as $key => $value) {
                    $viewData[$key] = $value->getViewData();
                }
            }
        } else {
            $viewData = current(iterator_to_array($forms))->getViewData();
        }
    }
}
