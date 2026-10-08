<?php

namespace Base\Field\Type;

use Base\Admin\Controller\AbstractCrudController;
use Base\Database\Mapping\ClassMetadataManipulator;
use Base\Enum\UserRole;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Form\Common\NativeEnum;
use Base\Form\FormFactory;
use Base\Service\LocalizerInterface;
use Base\Service\MediaServiceInterface;
use Base\Service\Model\Autocomplete;
use Base\Service\Localizer;
use Base\Service\ObfuscatorInterface;
use Base\Service\ParameterBagInterface;
use Base\Service\Translator;
use Base\Service\TranslatorInterface;
use Base\Twig\Environment;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\OwningSideMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Mapping\MappingException;
use Base\Admin\Config\Action;
use Base\Routing\AdminUrlGeneratorInterface;
use Exception;
use Generator;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\Mapping\ToManyOwningSideMapping;

use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\ChoiceList\View\ChoiceGroupView;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Traversable;

class SelectType extends AbstractType implements DataMapperInterface
{
    /** @var ClassMetadataManipulator */
    protected ClassMetadataManipulator $classMetadataManipulator;

    /** @var Environment */
    protected Environment $twig;

    /** @var FormFactory */
    protected FormFactory $formFactory;

    /** @var CsrfTokenManagerInterface */
    protected CsrfTokenManagerInterface $csrfTokenManager;

    /** @var TranslatorInterface */
    protected TranslatorInterface $translator;

    /** @var EntityManagerInterface */
    protected EntityManagerInterface $entityManager;

    /** @var ObfuscatorInterface */
    protected ObfuscatorInterface $obfuscator;

    /** @var ParameterBagInterface */
    protected ParameterBagInterface $parameterBag;

    /** @var AuthorizationChecker */
    protected AuthorizationChecker $authorizationChecker;

    /** @var LocalizerInterface */
    protected LocalizerInterface $localizer;

    /** @var AdminUrlGeneratorInterface */
    protected AdminUrlGeneratorInterface $adminUrlGenerator;

    /** @var Autocomplete */
    protected Autocomplete $autocomplete;

    /** @var PropertyAccessorInterface */
    protected PropertyAccessorInterface $propertyAccessor;

    /** @var RouterInterface */
    protected RouterInterface $router;

    public function __construct(
        FormFactory               $formFactory,
        EntityManagerInterface    $entityManager,
        TranslatorInterface       $translator,
        ClassMetadataManipulator  $classMetadataManipulator,
        CsrfTokenManagerInterface $csrfTokenManager,
        Localizer                 $localizer,
        AdminUrlGeneratorInterface         $adminUrlGenerator,
        Environment               $twig,
        AuthorizationChecker      $authorizationChecker,
        ObfuscatorInterface       $obfuscator,
        ParameterBagInterface     $parameterBag,
        RouterInterface           $router,
        ?MediaServiceInterface    $mediaService = null
    )
    {
        $this->classMetadataManipulator = $classMetadataManipulator;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->twig = $twig;
        $this->translator = $translator;
        $this->entityManager = $entityManager;
        $this->obfuscator = $obfuscator;
        $this->parameterBag = $parameterBag;
        $this->authorizationChecker = $authorizationChecker;

        $this->formFactory = $formFactory;
        $this->localizer = $localizer;
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->router = $router;

        // WITH the media service, unlike every other `new Autocomplete()` in
        // this bundle. Without it resolve() cannot thumbnail anything, so the
        // avatar was silently dropped for the entries rendered server-side -
        // i.e. exactly the ones already selected. Only the AJAX controller
        // passed it, which is why a picked author showed a role icon in its
        // chip while the same person showed a photo in the dropdown right
        // below it.
        $this->autocomplete = new Autocomplete($this->translator, $mediaService);
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    /** What a required select sent empty answers (Symfony's NotBlank message, `validators` domain). */
    public const REQUIRED_MESSAGE = 'This value should not be blank.';

    public function getBlockPrefix(): string
    {
        return 'select2';
    }

    protected static $icons = [];

    public static function getIcons(): array
    {
        return self::$icons[static::class] ?? [];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([

            'class' => null,
            'class_priority' => [
                FormFactory::GUESS_FROM_FORM,
                FormFactory::GUESS_FROM_PHPDOC,
                FormFactory::GUESS_FROM_DATA,
                FormFactory::GUESS_FROM_VIEW,
            ],

            //'query_builder'   => null,　// To be implemented if necessary... (currently relying on Autocomplete model and Association*Type..)

            // The field's errors stay on the field - a required select left
            // empty, a constraint of the property - where its row prints them
            // (as Symfony's own compound choice and date fields do): a compound
            // form hands its errors to the form above by default.
            'error_bubbling' => false,

            "disable" => false,
            'choices' => null,
            'choice_loader' => null,
            'choice_thumbnails' => [],
            'choice_filter' => false,

            'choice_value' => function ($value) {
                return $value;
            },   // Return key code
            'choice_label' => function ($value, $label, $id) {
                return $label;
            },   // Return translated label

            'select2' => [],
            "select2-template" => null,
            "select2-templateResult" => null,
            "select2-templateSelection" => null,
            'theme' => "bootstrap4",
            'empty_data' => null,

            // Generic parameters
            'placeholder' => "@fields.select.placeholder",
            'capitalize' => null,
            'language' => null,
            'required' => null,
            // Opt back IN to a browser-level required on a multi-select. The
            // default is off (see the choice child below) because "nothing
            // selected" is valid for almost every collection, but a few really
            // are mandatory - an Article must have an author - and those say so
            // here rather than losing the constraint silently.
            'required_when_multiple' => false,
            'multiple' => null,
            'multivalue' => false,

            'vertical' => false,
            'maximum' => 0,
            'tabulation' => "1.75em",
            'tags' => false,
            'highlight' => null,
            'minimumInputLength' => 0,
            'tokenSeparators' => [' ', ',', ';'],
            'closeOnSelect' => null,
            'selectOnClose' => false,
            'minimumResultsForSearch' => 0,
            "dropdownCssClass" => null,
            "containerCssClass" => null,

            'use_html' => false,

            // Show entries as profile pictures instead of their __iconize()
            // icon, where one is available. Off by default: for a User that
            // icon is their role, and replacing it with a photo drops that
            // from the picker for whoever happens to have uploaded one. Turn
            // it on for the fields where telling people apart matters more
            // than their role - authors, owners.
            'avatar' => false,

            'href' => null,
            'webpack_entry' => "form.select2",

            // Autocomplete
            'autocomplete' => null,
            'autocomplete_endpoint' => "ux_autocomplete",
            'autocomplete_endpoint_parameters' => [],
            'autocomplete_fields' => [],
            'autocomplete_data' => null,
            'autocomplete_processResults' => null,
            'autocomplete_delay' => 500,
            'autocomplete_type' => $this->router->isDebug() ? "GET" : "POST",

            // Sortable option
            'sortable' => null
        ]);

        $resolver->setNormalizer('required', function (Options $options, $value) {
            if ($value === null) {
                return $options["tags"] != true;
            }
            return $value;
        });

        $resolver->setNormalizer('highlight', function (Options $options, $value) {
            return ($options["tags"] != true) && $value;
        });

        $resolver->setNormalizer('tokenSeparators', function (Options $options, $value) {
            if (is_array($options["tags"]) && $options["tags"]) {
                return $options["tags"];
            }
            return $value;
        });

        $resolver->setNormalizer('class', function (Options $options, $value) {
            // An entity, or a PHP enum ('class' => Status::class: its cases are the choices)
            if (!NativeEnum::is($value) && !$this->classMetadataManipulator->isEntity($value)) {
                return null;
            }
            return $value;
        });
    }

    /**
     * What the field chooses among: an entity, one of omnibase's EnumType /
     * SetType, or a PHP enum - read from the `class` option, else from the
     * property the field is bound to (a Doctrine association, an `enumType:`
     * column, a property typed with an enum).
     *
     * A PHP enum that was only guessed is left out when the field brings its
     * own choices: those are what is stored, as they are given (the labels
     * and string values a form wrote before enums were guessed).
     */
    protected function guessClass(FormInterface|FormEvent $form, ?array $options = null): ?string
    {
        $class = $this->formFactory->guessClass($form, $options);
        if (!NativeEnum::is($class)) {
            return $class;
        }

        $config = ($form instanceof FormEvent ? $form->getForm() : $form)->getConfig();
        if ($config->getOption('class') === $class) {
            return $class;
        }

        return null === $config->getOption('choices') && null === $config->getOption('choice_loader') ? $class : null;
    }

    /**
     * The entities of these identifiers. omnibase's repositories answer
     * cacheById(); a plain Doctrine repository (ServiceEntityRepository,
     * EntityRepository) is asked through findBy() on the identifier.
     *
     * @return object[]
     */
    protected function findEntities(string $class, array $ids): array
    {
        $ids = array_values(array_filter(
            array_map(fn($id) => is_object($id) ? (method_exists($id, 'getId') ? $id->getId() : null) : $id, $ids),
            static fn($id) => null !== $id && '' !== $id
        ));
        if (!$ids) {
            return [];
        }

        $repository = $this->entityManager->getRepository($class);
        if ($repository instanceof ServiceEntityRepository) {
            return $repository->cacheById($ids, [])->getResult();
        }

        $identifier = $this->entityManager->getClassMetadata($class)->getSingleIdentifierFieldName();

        return $repository->findBy([$identifier => $ids]);
    }

    protected function findEntity(string $class, mixed $id): ?object
    {
        if (null === $id || '' === $id || [] === $id) {
            return null;
        }

        $repository = $this->entityManager->getRepository($class);
        if ($repository instanceof ServiceEntityRepository) {
            return $repository->cacheOneById($id);
        }

        return $repository->find($id);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->setDataMapper($this);
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use (&$options) {
            
            $form = $event->getForm();
            $options = $form->getConfig()->getOptions();

            // Guess class without data in the first place..
            // To make sure the form can return something in the worst case
            $options["guess_priority"] = array_intersect(
                [FormFactory::GUESS_FROM_FORM, FormFactory::GUESS_FROM_PHPDOC],
                $options["class_priority"]
            );

            // Guess some options
            $options["class"] = $this->guessClass($event, $options);
            $options["sortable"] = $this->formFactory->guessSortable($event, $options);
            $options["multiple"] = $this->formFactory->guessMultiple($form, $options);

            $options["choice_filter"] = $this->formFactory->guessChoiceFilter($form, $options);
            if ($options["choices"] === null && $options["choice_loader"] === null) {
                $options["choices"] = $this->formFactory->guessChoices($form, $options);
                $options["autocomplete"] = $this->formFactory->guessChoiceAutocomplete($form, $options);

                /* Override options.. I couldn't done that without accessing data */
                // It might be good to get read of that and be able to use normalizer.. as expected
                if (!$options["tags"] && $options["choices"] === null && !$options["autocomplete"]) {
                    throw new Exception("No choices, or autocomplete option, could be guessed without using data information for \"" . $form->getName() . "\"");
                }
            }

            $formOptions = [
                'choices' => [],
                'choice_loader' => $options["choice_loader"],
                'choice_label' => $options["choice_label"],
                'choice_value' => $options["choice_value"],
                'multiple' => $options["multiple"]
            ];

                // A multi-select is a collection: "nothing selected" is valid.
                // Symfony hands `required` down from the parent, and the
                // parent's own default resolves to true while `multiple` is
                // still unguessed - so every to-many select emitted a browser
                // `required` on this child <select> and the create form could
                // not be submitted until something was picked (Tags, Followers,
                // Subscribers, Permissions, Newsletters, Children...). A
                // genuinely mandatory collection is a validation constraint,
                // which can say why; this attribute just blocks submit
                // silently. Set on the CHILD because that is the element that
                // carries the attribute - a parent view var never reaches it.
                if ($options["multiple"] && !$options["required_when_multiple"]) {
                    $formOptions['required'] = false;
                }

            $form->add('choice', ChoiceType::class, $formOptions);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use (&$options) {
            $form = $event->getForm();
            $data = $event->getData();

            // Guess including class_priority
            $options["guess_priority"] = $options["class_priority"];
            $options["class"] = $this->guessClass($event, $options);

            $dataChoice = $data["choice"] ?? null;
            $dataChoices = $options["multiple"] ? $dataChoice ?? [] : [];
            if (!$options["multiple"] && $dataChoice) {
                $dataChoices[] = $dataChoice;
            }

            if (NativeEnum::is($options["class"])) {

                // A PHP enum: the submitted values that name a case (anything else is an invalid choice)
                $choices = [];
                foreach ($dataChoices as $id) {
                    if (null === $case = NativeEnum::of($options["class"], $id)) {
                        continue;
                    }

                    $label = NativeEnum::label($case, $this->translator);
                    $choices[array_key_exists($label, $choices) ? $label . "/" . NativeEnum::id($case) : $label] = NativeEnum::id($case);
                }

            } elseif ($options["class"]) {
                $innerType = get_class($form->getConfig()->getType()->getInnerType());
                $dataset = $form->getData() instanceof Collection ? $form->getData()->toArray() : (!is_array($form->getData()) ? [$form->getData()] : $form->getData());
                if ($this->classMetadataManipulator->isEntity($options["class"])) {
                    if ($dataset) {
                        $dataset = $this->findEntities($options["class"], $dataset);
                    }
                }

                $formattedData = array_transforms(function ($key, $choices, $callback, $i, $d) use ($innerType, &$options): Generator {
                    if ($choices === null) {
                        return null;
                    }

                    // Recursive categories
                    if (is_array($choices)) {
                        list($class, $text) = array_pad(explode("::", $key), 2, null);

                        if ($this->classMetadataManipulator->isEntity($class)) {
                            $text = $this->translator->transEntity($class, null, Translator::NOUN_PLURAL);
                        } elseif ($this->classMetadataManipulator->isEnumType($class)) {
                            $text = $this->translator->transEnum($text, $class, Translator::NOUN_PLURAL);
                        } elseif ($this->classMetadataManipulator->isSetType($class)) {
                            $text = $this->translator->transEnum($text, $class, Translator::NOUN_PLURAL);
                        }

                        $text = empty($text) ? $key : $text;
                        $self = array_pop_key("_self", $choices);

                        if (!$self) {
                            yield null => ["text" => $text, "children" => array_transforms($callback, $choices, $d)];
                        } else {
                            $yields = $callback(null, $self, $callback, $i, $d++);
                            foreach ($yields as $yield) {
                                yield null => $yield;
                            }

                            foreach (array_transforms($callback, $choices, $d) as $yield) {
                                yield null => $yield;
                            }
                        }
                    } else {
                        // Format values
                        $entryFormat = FORMAT_IDENTITY;
                        if ($options["capitalize"] !== null) {
                            $entryFormat = $options["capitalize"] ? FORMAT_TITLECASE : FORMAT_SENTENCECASE;
                        }

                        $entry = $this->autocomplete->resolve($choices, $options["class"] ?? $innerType, [
                            "html" => $options["use_html"],
                            "format" => $entryFormat
                        ]);

                        if ($entry === null) {
                            return null;
                        }

                        if (!$options["class"]) {
                            $entry["text"] = $key;
                        }
                        yield $entry["id"] => $entry["text"];
                    }
                }, $dataset ?? []);

                // Search missing information
                $missingData = [];
                $knownData = array_keys($formattedData);

                if ($this->classMetadataManipulator->isEntity($options["class"])) {
                    foreach ($dataChoices as $data) {
                        if (!in_array($data, $knownData)) {
                            $missingData[] = $this->findEntity($options["class"], $data);
                        }
                    }
                }

                $innerType = $form->getConfig()->getType()->getInnerType();
                $formattedData += array_transforms(function ($key, $choices, $callback, $i, $d) use ($innerType, &$options): Generator {
                    // Recursive categories
                    if (is_array($choices)) {
                        list($class, $text) = array_pad(explode("::", $key), 2, null);

                        if ($this->classMetadataManipulator->isEntity($class)) {
                            $text = $this->translator->transEntity($class, null, Translator::NOUN_PLURAL);
                        } elseif ($this->classMetadataManipulator->isEnumType($class)) {
                            $text = $this->translator->transEnum($text, $class, Translator::NOUN_PLURAL);
                        } elseif ($this->classMetadataManipulator->isSetType($class)) {
                            $text = $this->translator->transEnum($text, $class, Translator::NOUN_PLURAL);
                        }

                        $text = empty($text) ? $key : $text;
                        $self = array_pop_key("_self", $choices);

                        if (!$self) {
                            yield null => ["text" => $text, "children" => array_transforms($callback, $choices, $d)];
                        } else {
                            $yields = $callback(null, $self, $callback, $i, $d++);
                            foreach ($yields as $yield) {
                                yield null => $yield;
                            }

                            foreach (array_transforms($callback, $choices, $d) as $yield) {
                                yield null => $yield;
                            }
                        }

                    } else {

                        // Format values
                        $format = FORMAT_IDENTITY;
                        if ($options["capitalize"] !== null) {
                            $format = $options["capitalize"] ? FORMAT_TITLECASE : FORMAT_SENTENCECASE;
                        }

                        $entry = $this->autocomplete->resolve($choices, $options["class"] ?? $innerType, [
                            "html" => $options["use_html"],
                            "format" => $format
                        ]);

                        if ($entry === null) {
                            return null;
                        }

                        if (!$options["class"]) {
                            $entry["text"] = $key;
                        }
                        yield $entry["id"] => $entry["text"];
                    }
                }, $missingData ?? []);

                //
                // Compute in choice list format
                $choices = [];
                foreach ($dataChoices as $id) {
                    $label = $formattedData[$id] ?? null;
                    if (!array_key_exists($label, $choices)) {
                        $choices[$label] = $id;
                    } else {

                        $ii = 2;
                        for ($i = 2; array_key_exists($label . "/" . $i, $choices); $i++) {
                            $ii++;
                        }

                        $choices[$label . "/" . $ii] = $id;
                    }
                }

            } else {
                $choices = $dataChoices;
            }

            $formOptions = [
                'choices' => array_unique($choices),
                'multiple' => $options["multiple"]
            ];

            // Same as the PRE_SET_DATA branch above: this rebuild must not
            // reinstate the required attribute the other one dropped.
            if ($options["multiple"] && !$options["required_when_multiple"]) {
                $formOptions['required'] = false;
            }

            $form->remove('choice')->add('choice', ChoiceType::class, $formOptions);
        });

        // A required select left empty is an error of the form. The browser's
        // own `required` was the only check: a form sent without it (the back
        // office's forms are `novalidate`, a page whose script did not run, a
        // request made by hand) stored null where a value was asked - or died
        // on it, when the record's setter takes none. A list of several stays
        // free to be empty unless `required_when_multiple` says otherwise, as
        // its `required` attribute does.
        //
        // Said as a failed transformation: the field is then not synchronized,
        // so the form above does not write the empty value into the record
        // (which keeps what it had), and the form's validator prints the
        // message on the field, in the visitor's language.
        $builder->addViewTransformer(new CallbackTransformer(
            fn($value) => $value,
            function ($value) use (&$options) {
                if ('' === $value) {
                    $value = null;     // as a form without transformer reads it
                }

                $empty = null === $value || [] === $value || ($value instanceof Collection && $value->isEmpty());
                if (!$empty || !$options["required"] || ($options["multiple"] && !$options["required_when_multiple"])) {
                    return $value;
                }

                $failure = new TransformationFailedException('A required select was sent empty.');
                $failure->setInvalidMessage(self::REQUIRED_MESSAGE);

                throw $failure;
            }
        ));

        // Without the validator (symfony/validator not installed) nobody reads
        // the failure: the field says it itself.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $failure = $form->getTransformationFailure();
            if (null === $failure || self::REQUIRED_MESSAGE !== $failure->getInvalidMessage() || $form->getRoot()->getConfig()->hasOption('constraints')) {
                return;
            }

            $form->addError(new FormError($this->translator->trans(self::REQUIRED_MESSAGE, [], 'validators'), self::REQUIRED_MESSAGE, [], null, $failure));
        });
    }

    /**
     * @param $viewData
     * @param Traversable $forms
     * @return void
     */
    public function mapDataToForms($viewData, Traversable $forms): void
    { /* done in buildView using select2 extend */ }

    /**
     * @param Traversable $forms
     * @param $viewData
     * @return void
     * @throws MappingException
     */
    public function mapFormsToData(Traversable $forms, &$viewData): void
    {
        $choiceType = current(iterator_to_array($forms));
        if ($this->classMetadataManipulator->isCollectionOwner($choiceType) === false) {
            return;
        }

        // A value that is no choice (it names no case of the enum): the field
        // carries the error, the record keeps what it had.
        if (!$choiceType->isSynchronized()) {
            return;
        }

        $options = $choiceType->getParent()->getConfig()->getOptions();
        $options["class"] = $this->guessClass($choiceType->getParent());

        if (!$options["multiple"]) {
            $dataChoices = $choiceType->getViewData();
        } else {
            $dataChoices = $options["multivalue"] ? array_map(fn($c) => explode("/", $c)[0], $choiceType->getViewData()) : array_unique($choiceType->getViewData());
        }

        $multiple = $options["multiple"];
        
        //
        // A PHP enum: the case(s) the submitted value(s) name
        if (NativeEnum::is($options["class"])) {

            $dataChoices = is_array($dataChoices)
                ? array_values(array_filter(array_map(fn($id) => NativeEnum::of($options["class"], $id), $dataChoices)))
                : NativeEnum::of($options["class"], $dataChoices);
        }

        //
        // Retrieve existing entities
        if ($this->classMetadataManipulator->isEntity($options["class"])) {

            $options["multiple"] = $options["multiple"] ?? $this->formFactory->guessMultiple($choiceType->getParent(), $options);
            if (!$options["multiple"]) {

                $dataChoices = $this->findEntity($options["class"], $dataChoices);

            } else {

                $orderBy = array_flip($dataChoices);
                $default = count($orderBy);

                // A placeholder <option value=""> (or a client serializing an
                // empty multi-select as [""]) must not reach hydration: an
                // empty id matches no entity, survives the id->entity swap
                // below as a raw string, and Doctrine then rejects the
                // collection ("Expected value of type User, got string").
                $dataChoices = array_values(array_filter($dataChoices, static fn ($id) => null !== $id && '' !== $id));

                $entities = [];
                if ($dataChoices) {
                    $entities = $this->findEntities($options["class"], $dataChoices);
                }

                foreach ($dataChoices as $pos => $id) {
                    foreach ($entities as $entity) {
                        if ($entity->getId() == $id) {
                            $dataChoices[$pos] = $entity;
                        }
                    }
                }
        
                usort($dataChoices, fn($a, $b) => (is_object($a) ? ($orderBy[$a->getId()] ?? $default) : $default) <=> (is_object($b) ? ($orderBy[$b->getId()] ?? $default) : $default));
            }
        }

        $options["multiple"] = $multiple !== null ? $multiple : null;
        $options["multiple"] = $this->formFactory->guessMultiple($choiceType->getParent(), $options);

        if ($viewData instanceof PersistentCollection) {

            $isOwningSide = $viewData->getMapping() instanceof ToManyOwningSideMapping;
            $oldData = $viewData->toArray();

            $mapping = $viewData->getMapping();
            if (!is_array($dataChoices)) {
                $dataChoices = [$dataChoices];
            }

            $mapping = $viewData->getMapping();
            if ($mapping instanceof InverseSideMapping) {
                
                foreach (array_diff_object($oldData, $dataChoices) as $entry) {

                    $mappedBy = $mapping->mappedBy;
                    $owningSide = $this->propertyAccessor->getValue($entry, $mappedBy);
                    if (!$owningSide instanceof Collection) {
                        $this->propertyAccessor->setValue($entry, $mappedBy, null);
                    } elseif ($owningSide->contains($viewData->getOwner())) {
                        $owningSide->removeElement($viewData->getOwner());
                    }
                }
            }

            // Only clear & re-add when the collection’s contents or order actually changed
            if ($oldData !== $dataChoices) {

                $viewData->clear();
                foreach ($dataChoices as $entry) {

                    $viewData->add($entry);
                    if (!$isOwningSide && $mappedBy) {

                        $owningSide = $this->propertyAccessor->getValue($entry, $mappedBy);
                        if (!$owningSide instanceof Collection) {
                            $this->propertyAccessor->setValue($entry, $mappedBy, $viewData->getOwner());
                        } elseif (!$owningSide->contains($viewData->getOwner())) {
                            $owningSide->add($viewData->getOwner());
                        }
                    }
                }
            }

        } elseif ($viewData instanceof Collection) {

            $viewData->clear();
            if (!is_iterable($dataChoices)) {
                $dataChoices = $dataChoices ? [$dataChoices] : [];
            }

            foreach ($dataChoices as $data) {
                $viewData->add($data);
            }

        } elseif ($options["multiple"]) {

            $viewData = [];
            foreach ($dataChoices as $data) {
                $viewData[] = $data;
            }

        } else {
            $viewData = $dataChoices;
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        /* Override options.. I couldn't done that without accessing data */
        $options["class"] = $this->guessClass($form, $options);
        $options["multiple"] = $this->formFactory->guessMultiple($form, $options);
        $options["sortable"] = $this->formFactory->guessSortable($form, $options);

        // A MULTI-select is a collection, and "nothing selected" is a valid
        // value for one. The `required` normalizer above defaults to true and
        // runs while `multiple` is still null - it is only guessed here - so
        // every to-many select shipped a browser-level `required` and the
        // create form refused to submit until something was picked: Tags on a
        // gallery, Followers on a comment, Subscribers on a newsletter,
        // Permissions on a group, Newsletters on a subscriber, Children on a
        // calendar/guestbook. Same reasoning as AssociationType's own
        // normalizer: a genuinely mandatory collection is a validation
        // constraint (which can say why), not an HTML attribute that blocks
        // submit silently. Applied here because this is the first point where
        // multiplicity is actually known.
        if ($options["multiple"]) {
            $view->vars["required"] = false;
        }

        $options["choice_filter"] = $this->formFactory->guessChoiceFilter($form, $options);
        if ($options["choices"] === null && $options["choice_loader"] === null) {
            $options["choices"] = $this->formFactory->guessChoices($form, $options);
            $options["autocomplete"] = $this->formFactory->guessChoiceAutocomplete($form, $options);
        }

        $data = $form->getData();

        // Set default data
        if ($options["multiple"]) {
            if ($options["empty_data"] === null) {
                $options["empty_data"] = new ArrayCollection();
            } elseif (is_array($options["empty_data"])) {
                $options["empty_data"] = new ArrayCollection($options["empty_data"]);
            } elseif (!$options["empty_data"] instanceof Collection) {
                $options["empty_data"] = new ArrayCollection([$options["empty_data"]]);
            }

            if ($data instanceof Collection && $data->isEmpty()) {
                foreach ($options["empty_data"] as $emptyData) {
                    if ($options["class"] && $emptyData instanceof $options["class"]) {
                        $data->add($emptyData);
                    }
                }
            }

        } elseif ($data === null) {

            if (is_array($options["empty_data"])) {
                $data = $options["empty_data"];
            } elseif ($options["empty_data"] instanceof Collection) {
                $data = $options["empty_data"]->first();
            } else {
                $data = $options["empty_data"];
            }

            if ($options["class"] && !$data instanceof $options["class"]) {
                $data = null;
            }
        }

        if (NativeEnum::is($options["class"])) {

            // A PHP enum: the select holds each case by its value (its name when the enum is not backed)
            $toId = fn($d) => (null !== $case = NativeEnum::of($options["class"], $d)) ? NativeEnum::id($case) : null;
            $data = is_iterable($data)
                ? array_values(array_filter(array_map($toId, is_array($data) ? $data : iterator_to_array($data, false)), fn($id) => null !== $id))
                : $toId($data);
        }

        if (!$form->isSubmitted() && $this->classMetadataManipulator->isEntity($options["class"]) && ($data && !$data instanceof Collection)) {

            if ($options["multiple"]) {
                // One entity, or one bare id (an attribute's stored value, "3"), is a list of one.
                if ($this->classMetadataManipulator->isEntity($data) || !is_iterable($data)) {
                    $data = [$data];
                }
                $data = is_array($data) ? $data : iterator_to_array($data, false);
                $data = array_map(fn($d) => $this->classMetadataManipulator->isEntity($d) ? $d->getId() : $d, $data);
                $orderBy = array_flip($data ?? []);
                $default = count($orderBy);

                $data = $this->findEntities($options["class"], $data);
                usort($data, fn($a, $b) => ($orderBy[$a->getId()] ?? $default) <=> ($orderBy[$b->getId()] ?? $default));

            } else {
                $data = $this->classMetadataManipulator->isEntity($data) ? $data : $this->findEntity($options["class"], $data);
            }

            if (!$form->isSubmitted()) {
                $form->setData($data);
            }
        }

        if ($options["select2"] !== null) {

            // Double-check for "multiple" option
            // * If database can accept multiples, it can also accept single elements
            // * But database with single entry cannot accept multiple elements.. So I arbitrarily keep only the first element..
            $multipleExpected = $data instanceof Collection || is_array($data);
            if (!$options["multiple"] && $multipleExpected && $data !== null) {
                $data = $data instanceof Collection ? $data->toArray() : $data;
                $data = first($data);
            }

            if ($options["multiple"] && !$multipleExpected && $data !== null) {
                $data = null;
            }

            //
            // Prepare variables
            $tokenName = "select2";
            $token = $this->csrfTokenManager->getToken($tokenName)->getValue();

            $array = [
                "class" => $options["class"],
                "fields" => $options["autocomplete_fields"],
                "filters" => $options["choice_filter"],
                'capitalize' => $options["capitalize"],
                "html" => $options["use_html"],
                "avatar" => $options["avatar"],
                "token_name" => $tokenName,
                "token" => $token
            ];

            $hash = $this->obfuscator->encode($array, ObfuscatorInterface::USE_SHORT);

            //
            // Prepare select2 options
            $selectOpts = $options["select2"];
            $selectOpts["multiple"] = $options["multiple"] ? "multiple" : "";
            if ($options["select2-template"]) {
                $selectOpts["template"] = $options["select2-template"];
            }
            if ($options["select2-templateResult"]) {
                $selectOpts["templateResult"] = $options["select2-templateResult"];
            }
            if ($options["select2-templateSelection"]) {
                $selectOpts["templateSelection"] = $options["select2-templateSelection"];
            }
            if ($options["autocomplete"]) {
                $selectOpts["ajax"] = [
                    "url" => $this->router->generate($options["autocomplete_endpoint"], array_merge($options["autocomplete_endpoint_parameters"], ["data" => $hash])),
                    "type" => $options["autocomplete_type"],
                    "delay" => $options["autocomplete_delay"],
                    "dataType" => "json",
                    "html" => $options["use_html"],
                    "cache" => true
                ];

                if (!array_key_exists("autocomplete_data", $selectOpts) && $options["autocomplete_data"] !== null) {
                    $selectOpts["ajax"]["data"] = $options["autocomplete_data"];
                }
                if (!array_key_exists("autocomplete_processResults", $selectOpts) && $options["autocomplete_processResults"] !== null) {
                    $selectOpts["ajax"]["processResults"] = $options["autocomplete_processResults"];
                }
            }

            if (!array_key_exists("minimumResultsForSearch", $selectOpts)) {
                $selectOpts["minimumResultsForSearch"] = $options["minimumResultsForSearch"];
            }
            if (!array_key_exists("closeOnSelect", $selectOpts)) {
                $selectOpts["closeOnSelect"] = $options["closeOnSelect"] ?? !$options["multiple"];
            }
            if (!array_key_exists("selectOnClose", $selectOpts)) {
                $selectOpts["selectOnClose"] = $options["selectOnClose"];
            }
            if (!array_key_exists("dropdownCssClass", $selectOpts) && $options["dropdownCssClass"] !== null) {
                $selectOpts["dropdownCssClass"] = $options["dropdownCssClass"];
            }

            $selectOpts["containerCssClass"] = $selectOpts["containerCssClass"] ?? "";
            $selectOpts["dropdownCssClass"] = $selectOpts["dropdownCssClass"] ?? "";
            if ($options["vertical"]) {
                $selectOpts["containerCssClass"] .= " select2-selection--vertical";
            }

            if ($options["tags"] && !$options["autocomplete"] && empty($options["choices"])) {
                $selectOpts["containerCssClass"] .= " select2-selection--wrap";
                $selectOpts["dropdownCssClass"] .= " select2-selection--hide";
            }

            $view->vars["highlight"] = $options["highlight"];
            if ($options["tags"]) {
                $view->vars["tokenSeparators"] = $options["tokenSeparators"];
            }

            if (!array_key_exists("placeholder", $selectOpts) && $options["placeholder"] !== null) {
                $selectOpts["placeholder"] = $this->translator->trans($options["placeholder"] ?? "", [], "@fields");
            }

            if (!array_key_exists("multivalue", $selectOpts) && $options["multivalue"] !== null) {
                $selectOpts["multivalue"] = $options["multivalue"];
            }

            if (!array_key_exists("language", $selectOpts)) {
                $selectOpts["language"] = $this->localizer->getLocaleLang($this->localizer->getLocale($options["language"]));
            }

            if (!array_key_exists("tokenSeparators", $selectOpts)) {
                $selectOpts["tokenSeparators"] = $selectOpts["tokenSeparators"] ?? $options["tokenSeparators"];
            }
            if (!array_key_exists("allowClear", $selectOpts)) {
                $selectOpts["allowClear"] = array_key_exists("required", $options) && !$options["required"];
            }
            if (!array_key_exists("maximum", $selectOpts)) {
                $selectOpts["maximum"] = (array_key_exists("maximum", $options) && $options["maximum"] > 0) ? $options["maximum"] : "";
            }
            if (!array_key_exists("tags", $selectOpts)) {
                $selectOpts["tags"] = array_key_exists("tags", $options) && $options["tags"];
            }

            if (!array_key_exists("theme", $selectOpts)) {
                $selectOpts["theme"] = $options["theme"];
            }

            //
            // Format preselected values
            $dataset = [];
            if ($options["choice_loader"] === null) {
                $dataset = $data instanceof Collection ? $data->toArray() : null;
                $dataset ??= !is_array($data) ? [$data] : $data;
            }

            $selectedData = $data instanceof Collection ? $data->toArray() : null;
            $selectedData ??= $data !== null && !is_array($data) ? [$data] : $data ?? [];

            $innerType = get_class($form->getConfig()->getType()->getInnerType());
            $formattedData = array_transforms(function ($key, $choices, $callback, $i, $d) use ($innerType, $dataset, &$options, &$selectedData): Generator {
                if (is_array($choices)) {
                    list($class, $text) = array_pad(explode("::", $key), 2, null);

                    if ($this->classMetadataManipulator->isEntity($class)) {
                        $text = $this->translator->transEntity($class, null, Translator::NOUN_PLURAL);
                    } elseif ($this->classMetadataManipulator->isEnumType($class)) {
                        $text = $this->translator->transEnum($text, $class, Translator::NOUN_PLURAL);
                    } elseif ($this->classMetadataManipulator->isSetType($class)) {
                        $text = $this->translator->transEnum($text, $class, Translator::NOUN_PLURAL);
                    } else {
                        $text = is_string($key) ? $key : $text;
                    }

                    $self = array_pop_key("_self", $choices);
                    if (!$self) {
                        yield null => ["text" => $text, "children" => array_transforms($callback, $choices, $d)];
                    } else {
                        $yields = $callback(null, $self, $callback, $i, $d++);
                        foreach ($yields as $yield) {
                            yield null => $yield;
                        }

                        foreach (array_transforms($callback, $choices, $d) as $yield) {
                            yield null => $yield;
                        }
                    }
                } else {
                    // Format values
                    $entry = $choices;
                    $entryFormat = FORMAT_IDENTITY;
                    if ($options["capitalize"] !== null) {
                        $entryFormat = $options["capitalize"] ? FORMAT_TITLECASE : FORMAT_SENTENCECASE;
                    }

                    $entry = $this->autocomplete->resolve($entry, $options["class"] ?? $innerType, [
                        "html" => $options["use_html"],
                        "format" => $entryFormat,
                        "avatar" => $options["avatar"]
                    ]);

                    if (!$entry) {
                        return null;
                    }

                    // Special text formatting
                    $fallback = is_string($key) ? $key : (is_string($choices) ? castcase($choices, $entryFormat) : $choices);
                    $entry["text"] = $entry["text"] ?? $fallback;

                    // Choices given as ['Label' => 'value'] (Symfony's own shape) are shown
                    // by their label, translated when it is a key ('@agenda.role.soloist'):
                    // the value was printed in its place, the key never read. A key that is
                    // the value itself (array_combine($codes, $codes)) is no label: the
                    // entry keeps the text its type gives (SelectInterface::getText()).
                    if (!$options["class"] && is_string($key) && $key !== "" && $key !== $choices) {
                        $entry["text"] = castcase($this->translator->trans($key), $entryFormat);
                    }

                    // Check if entry selected
                    $entry["depth"] = $d;
                    $entry["selected"] = false;
                    foreach ($dataset as $data) {
                        $entry["selected"] |= ($choices === $data);
                    }

                    yield $i => $entry;
                }
            }, $options["choices"] ?? $dataset ?? []);

            $selectOpts["data"] = $formattedData;
            $selectOpts["selected"] = $selectedData;

            // The same entries, for the <option>s the server prints itself (finishView()).
            $view->vars["select2-entries"] = $formattedData;

            //
            // Set controller url
            $crudController = AbstractCrudController::getCrudControllerFqcn($options["class"]);

            $href = $options["href"];
            if ($href === null && $crudController && $this->authorizationChecker->isGranted(UserRole::ADMIN)) {
                $href = $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController($crudController)
                    ->setAction(Action::EDIT)
                    ->setEntityId("{0}")
                    ->generateUrl();
            }

            //
            // Default select2 initialializer
            $view->vars["select2"] = json_encode($selectOpts);
            $view->vars["select2-href"] = $href;
            $view->vars["tabulation"] = $options["tabulation"];
            $view->vars["disabled"] = $options["disable"];

            // NB: Sorting elements is not working at the moment for multivalue SelectType, reason why I disable it here..
            $view->vars["select2-sortable"] = $options["sortable"] && $options["multivalue"] == false;
        }

        $view->vars["choices"] = array_filter($options["choices"] ?? $dataset ?? []);
        $view->vars["data"] = $selectedData;

        $view->vars["choice_thumbnails"] = is_callable($options["choice_thumbnails"])
            ? array_map(fn($c) => $options["choice_thumbnails"]($c), $view->vars["choices"] ?? [])
            : $options["choice_thumbnails"] ?? [];

        foreach ($view->vars["choice_thumbnails"] as $key => $choice) {
            $view->vars["choice_thumbnails"][$key] = $this->classMetadataManipulator->isEntity($choice) ? $choice->getId() : $choice;
        }
        foreach ($view->vars["choices"] as $key => $choice) {
            $view->vars["choices"][$key] = $this->classMetadataManipulator->isEntity($choice) ? $choice->getId() : $choice;
        }
        foreach ($view->vars["data"] as $key => $choice) {
            $view->vars["data"][$key] = $this->classMetadataManipulator->isEntity($choice) ? $choice->getId() : $choice;
        }

    }

    /**
     * The <select> the server prints holds its options and its selection.
     *
     * The inner choice was drawn empty and filled by select2 alone from
     * data-select2-options: on a page whose script did not run (blocked,
     * failed, not loaded yet) there was nothing to choose, a product's
     * availability arrived null, and a list that did print its options - a
     * choice_loader's, the currencies - printed none of them selected, so the
     * browser sent the first one (AFA) in place of the record's value.
     *
     * - The entries select2 receives - the choices of a static list, of an
     *   enum; the records already chosen of an autocompleted one - are
     *   printed as <option>s, groups as <optgroup>s, with the labels select2
     *   shows. select2 empties the select before filling it from its data, as
     *   it always did: nothing is listed twice.
     * - The value of the record is the selected option, for those and for the
     *   options a choice_loader gave.
     * - A select of one value starts with an empty option carrying the
     *   placeholder: without it the browser - and select2, which asks for
     *   this very option - picks the first choice when the record has none.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $choice = $view->children["choice"] ?? null;
        if (null === $choice) {
            return;
        }

        $toValue = fn(mixed $id): ?string => is_scalar($id) ? (string) $id : ($id instanceof \UnitEnum ? NativeEnum::id($id) : null);

        $selected = array_values(array_filter(array_map($toValue, $view->vars["data"] ?? []), fn($id) => null !== $id && '' !== $id));

        $toViews = function (array $entries) use (&$toViews, &$selected, $toValue): array {
            $views = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (array_key_exists("children", $entry)) {
                    $group = $toViews((array) $entry["children"]);
                    if ($group) {
                        // Keyed by its label: the form theme prints an <optgroup>'s label from the key.
                        $label = (string) ($entry["text"] ?? "");
                        while (array_key_exists($label, $views)) {
                            $label .= " ";
                        }
                        $views[$label] = new ChoiceGroupView(trim($label), $group);
                    }
                    continue;
                }

                $value = $toValue($entry["id"] ?? null);
                if (null === $value || '' === $value) {
                    continue;
                }

                $label = $entry["text"] ?? null;
                if (!is_scalar($label) || '' === trim((string) $label)) {
                    $label = is_string($entry["html"] ?? null) ? trim(html_entity_decode(strip_tags($entry["html"]))) : $value;
                }

                if (!empty($entry["selected"]) && !in_array($value, $selected, true)) {
                    $selected[] = $value;
                }
                $views[] = new ChoiceView($entry["id"], $value, (string) $label);
            }

            return $views;
        };

        $entries = $toViews($view->vars["select2-entries"] ?? []);
        unset($view->vars["select2-entries"]);
        if ($entries) {
            $choice->vars["choices"] = $entries;
            $choice->vars["preferred_choices"] = [];
            $choice->vars["choice_translation_domain"] = false;     // the labels are words already
        }

        $multiple = (bool) ($choice->vars["multiple"] ?? false);
        $choice->vars["value"] = $multiple ? $selected : ($selected[0] ?? "");
        $choice->vars["is_selected"] = $multiple
            ? fn($value, array $values): bool => in_array((string) $value, $values, true)
            : fn($value, $current): bool => (string) $value === (string) $current;

        if (!$multiple && null === ($choice->vars["placeholder"] ?? null) && null !== $options["placeholder"] && false !== $options["placeholder"]) {
            $choice->vars["placeholder"] = $this->translator->trans($options["placeholder"], [], "@fields");
            $choice->vars["translation_domain"] = false;
            $choice->vars["placeholder_in_choices"] = false;

            // `required` reaches the <select> through the attributes the
            // widget's template hands down (form.vars.required): the inner
            // choice printing its own as well, now that it has a placeholder,
            // would write the attribute twice.
            $choice->vars["required"] = false;
        }
    }
}
