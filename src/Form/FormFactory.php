<?php

namespace Base\Form;

use Base\Database\Entity\EntityHydratorInterface;
use Base\Database\Mapping\ClassMetadataManipulator;
use Base\Form\Common\FormModelInterface;
use Base\Form\Common\FormTypeInterface;
use Base\Form\Traits\FormGuessTrait;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Form\FormInterface;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormRegistryInterface;

use Symfony\Component\Form\FormFactory as SymfonyFormFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FormFactory extends SymfonyFormFactory implements FormFactoryInterface
{
    use FormGuessTrait;

    /**
     * @var ClassMetadataManipulator
     */
    protected ClassMetadataManipulator $classMetadataManipulator;
    /**
     * @var EntityHydratorInterface
     */
    protected EntityHydratorInterface $entityHydrator;
    /**
     * @var ValidatorInterface
     */
    protected ValidatorInterface $validator;

    public function __construct(FormRegistryInterface $registry, ValidatorInterface $validator, ClassMetadataManipulator $classMetadataManipulator, EntityHydratorInterface $entityHydrator)
    {
        parent::__construct($registry);

        $this->classMetadataManipulator = $classMetadataManipulator;
        $this->entityHydrator = $entityHydrator;
        $this->validator = $validator;
    }

    public function createBuilder(string $type = FormType::class, mixed $data = null, array $options = []): FormBuilderInterface
    {
        // I recommend not using entity data..
        // NB: https://blog.martinhujer.cz/symfony-forms-with-request-objects/
        if ($this->classMetadataManipulator->isEntity($data)) {
            throw new Exception("An entity \"" . get_class($data) . "\" is passed as data in \"" . $type . "\".\nThis is not recommended due to possible database flush conflict. Please use a DTO model.");
        }

        return parent::createBuilder($type, $data, $options);
    }

    public function create(string $type = FormType::class, mixed $data = null, array $options = [], array $listeners = []): FormInterface
    {
        $formModelClass = null;
        $useModel = ($options["use_model"] ?? false);
        if ($useModel) {
            if (array_key_exists("data_class", $options)) {
                $formModelClass = $options["data_class"] ?? null;
            } elseif ($useModel && class_implements_interface($type, FormTypeInterface::class)) {
                $formModelClass = $type::getModelClass();
            }
            //else if(class_implements_interface($type, SymfonyFormTypeInterface::class))

            $formModelClass ??= str_replace("\\Type\\", "\\Model\\", str_rstrip($type, "Type") . "Model");
            if (!class_exists($formModelClass)) {
                throw new Exception("Form model \"$formModelClass\" not found. Please disable option `use_model` if you don't want to use DTO model scheme");
            }
            if ($formModelClass && !class_implements_interface($formModelClass, FormModelInterface::class)) {
                throw new Exception("Form model \"$formModelClass\" must implement \"" . FormModelInterface::class . "\". Please disable option `use_model` if you don't want to use DTO model scheme");
            }

            if ($formModelClass) {
                if (!$data) {
                    $data = cast_empty($formModelClass);
                } elseif (is_array($data)) {
                    $data = cast_from_array($data, $formModelClass);
                } else {
                    $data = cast($data, $formModelClass);
                }
            }
        }

        $formBuilder = $this->createBuilder($type, $data, $options);
        if ($useModel && $formModelClass) {
            $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
                $form = $event->getForm();
                $dataClass = $form->getConfig()->getOption("data_class");
                $validationGroups = $form->getConfig()->getOption("validation_groups");
                if (!$validationGroups) {
                    $validationGroups[] = "Default";
                    $validationGroups[] = class_basename($dataClass);
                }

                //
                // Validate DTO model
                $metadata = $this->validator->getMetadataFor($dataClass);

                $constraints = [];
                foreach ($validationGroups ?? [] as $group) {
                    $constraints = array_merge($constraints, $metadata->findConstraints($group));
                }

                $errors = $this->validator->validate($form->getData(), array_unique_object($constraints), $form->getConfig()->getOption("validation_groups"));

                foreach ($errors as $error) {
                    // The violation goes with its error (its cause): what reads the form can tell which constraint spoke.
                    $form->addError(new FormError($error->getMessage(), $error->getMessageTemplate(), $error->getParameters(), $error->getPlural(), $error));
                }
            });
        }

        // Validate expected outgoing entity
        $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $validationEntity = $form->getConfig()->getOption("validation_entity");
            $validationGroups = $form->getConfig()->getOption("validation_groups");
            if (!$validationGroups) {
                $validationGroups[] = "Default";
                $validationGroups[] = class_basename($validationEntity);
            }

            if ($this->classMetadataManipulator->isEntity($validationEntity)) {
                $metadata = $this->validator->getMetadataFor($validationEntity);

                $constraints = [];
                foreach ($validationGroups ?? [] as $group) {
                    $constraints = array_merge($constraints, $metadata->findConstraints($group));
                }

                $entity = $this->entityHydrator->hydrate($validationEntity, $form->getData(), []);
                $errors = $this->validator->validate($entity, array_unique_object($constraints), $form->getConfig()->getOption("validation_groups"));

                foreach ($errors as $error) {
                    // The violation goes with its error (its cause): what reads the form can tell which constraint spoke.
                    $form->addError(new FormError($error->getMessage(), $error->getMessageTemplate(), $error->getParameters(), $error->getPlural(), $error));
                }
            }
        });

        foreach ($listeners as $eventName => $eventListener) {
            $listeners = [];
            if (!is_array($eventListener)) {
                $eventListener = [$eventListener, 0];
            }

            foreach ($eventListener as $_) {
                if (!is_array($_)) {
                    $_ = [$_, 0];
                }

                $listener = $_[0] ?? null;
                $priority = $_[1] ?? 0;

                if (!is_callable($listener)) {
                    throw new Exception("Invalid listener (" . $eventName . ") information provided for Form \"" . $type . "\".");
                }

                $listeners[] = [$listener, $priority];
            }

            foreach ($listeners as $_) {
                list($listener, $priority) = $_;
                $formBuilder->addEventListener($eventName, $listener, $priority);
            }
        }

        return $formBuilder->getForm();
    }
}
