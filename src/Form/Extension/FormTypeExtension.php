<?php

namespace Base\Form\Extension;

use Base\Database\Mapping\ClassMetadataManipulator;
use Base\Enum\UserRole;
use Base\Form\Common\FormModelInterface;
use Base\Form\FormFactory;
use Base\Form\FormProxyInterface;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\ParameterBagInterface;
use Base\Service\VersionManager;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Component\Translation\TranslatorBagInterface;

class FormTypeExtension extends AbstractTypeExtension
{
    protected FormFactory $formFactory;

    protected AdvancedRouterInterface $router;

    protected ParameterBagInterface $parameterBag;

    protected FormProxyInterface $formProxy;

    protected ClassMetadataManipulator $classMetadataManipulator;

    protected AuthorizationCheckerInterface $authorizationChecker;

    protected VersionManager $versionManager;

    /** @var (TranslatorBagInterface&LocaleAwareInterface)|null */
    protected $translator;

    public function __construct(AdvancedRouterInterface $router, AuthorizationCheckerInterface $authorizationChecker, ParameterBagInterface $parameterBag, FormFactory $formFactory, FormProxyInterface $formProxy, ClassMetadataManipulator $classMetadataManipulator, VersionManager $versionManager, $translator = null)
    {
        // The catalogues, to tell where a field's texts are (inheritTranslationDomain()).
        $this->translator = $translator instanceof TranslatorBagInterface && method_exists($translator, 'getLocale') ? $translator : null;
        $this->versionManager = $versionManager;
        $this->parameterBag = $parameterBag;
        $this->authorizationChecker = $authorizationChecker;

        $this->router = $router;
        $this->formFactory = $formFactory;
        $this->formProxy = $formProxy;
        $this->classMetadataManipulator = $classMetadataManipulator;
    }

    /**
     * {@inheritdoc}
     */
    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'form2' => $this->parameterBag->get('base.twig.use_form2'),
            'easyadmin' => $this->parameterBag->get('base.twig.use_ea'),
            'form_flow' => true,
            'form_flow_id' => '_flow_token',
            'validation_entity' => null,
            'use_model' => false,
            'translation_domain' => "fields"
        ]);

        $resolver->setNormalizer('form_flow_id', function (Options $options, $value) {
            $formType = null;
            if (class_implements_interface($options['data_class'], FormModelInterface::class)) {
                $formType = camel2snake(str_rstrip(class_basename($options['data_class']::getTypeClass()), 'Type'));
            }

            return '_flow_token' == $value ? $formType : $value;
        });
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $this->inheritTranslationDomain($view);
        $this->browseView($view, $form, $options);
    }

    /**
     * A field's texts given as keys of its form's domain are translated there.
     *
     * Every field gets the "fields" domain by default (configureOptions()
     * above), which is not null: a field never inherited the
     * translation_domain its form declares, as Symfony's would. A form of the
     * "mailbox" domain with `'label' => 'form.subject'` had that key looked up
     * in "fields", found nothing, and the theme printed an empty label (and
     * help, and placeholder) - each bundle went around it by naming the domain
     * in every key ("@mailbox.form.subject").
     *
     * So, for the theme: when none of the field's own texts (label, help,
     * placeholder) exists in its domain and one does in the domain of a form
     * above it, the field is rendered in that domain. A field whose texts are
     * found where they were looked up is left as it is, as is one that names
     * its domain in the key (@domain.key) or switches translation off.
     */
    protected function inheritTranslationDomain(FormView $view): void
    {
        $domain = $view->vars['translation_domain'] ?? null;
        if (null === $this->translator || null === $view->parent || false === $domain) {
            return;
        }

        $keys = [];
        foreach ([$view->vars['label'] ?? null, $view->vars['help'] ?? null, $view->vars['attr']['placeholder'] ?? null, $view->vars['placeholder'] ?? null] as $text) {
            if (\is_string($text) && '' !== $text && !str_starts_with($text, '@')) {
                $keys[] = $text;
            }
        }
        if (!$keys) {
            return;
        }

        foreach ($keys as $key) {
            if ($this->translates($key, $domain)) {
                return;
            }
        }

        for ($parent = $view->parent; null !== $parent; $parent = $parent->parent) {
            $inherited = $parent->vars['translation_domain'] ?? null;
            if (!\is_string($inherited) || '' === $inherited || $inherited === $domain) {
                continue;
            }
            foreach ($keys as $key) {
                if ($this->translates($key, $inherited)) {
                    $view->vars['translation_domain'] = $inherited;

                    return;
                }
            }
        }
    }

    private function translates(string $key, ?string $domain): bool
    {
        try {
            return $this->translator->getCatalogue($this->translator->getLocale())->has($key, $domain ?? 'messages');
        } catch (\Throwable) {
            return false;
        }
    }

    public function browseView(FormView $view, FormInterface $form, array $options)
    {
        if ($options['form2']) {
            $this->applyForm2($view);
        }
        if ($options['easyadmin']) {
            $this->applyEA($view, $form);
        }

        if ($this->authorizationChecker->isGranted(UserRole::ADMIN) && $this->router->isAdmin()) {
            $this->markDbProperties($view, $form, $options);
            $this->markOptions($view, $form, $options);
            $this->markVersionable($view, $form);
        }

        foreach ($view->children as $field => $childView) {
            if (!$form->has($field)) {
                continue;
            }

            $childForm = $form->get($field);
            $childOptions = $childForm->getConfig()->getOptions();
            $childOptions['form2'] = $options['form2'];
            $childOptions['easyadmin'] = $options['easyadmin'];

            $this->browseView($childView, $childForm, $childOptions);
        }
    }

    public function applyForm2(FormView $view)
    {
        // Add to all form custom base style..
        // It is named form2 and blocks are available in ./templates/form/form_div_layout.html.twig
        if (in_array('form', $view->vars['block_prefixes']) &&
            !in_array('form2', $view->vars['block_prefixes'])) {
            array_splice($view->vars['block_prefixes'], 1, 0, ['form2']);
        }
    }

    public function applyEA(FormView $view, FormInterface $form)
    {
        // EA 5 removed ea_crud_form from field views; reconstruct it from ea_vars + form config attributes for backward compat
        if (empty($view->vars['ea_crud_form']) && !empty($view->vars['ea_vars'])) {
            $eaVars = $view->vars['ea_vars'];
            $view->vars['ea_crud_form'] = [
                'form_panel'    => $form->getConfig()->getAttribute('ea_form_fieldset'),
                'form_fieldset' => $form->getConfig()->getAttribute('ea_form_fieldset'),
                'form_tab'      => $form->getConfig()->getAttribute('ea_form_tab'),
                'ea_field'      => $eaVars->getField(),
                'ea_entity'     => $eaVars->getEntity(),
            ];
        }

        if (!empty($view->vars['ea_crud_form'])) {
            if (!$form->getParent()) {
                if (!array_key_exists('class', $view->vars['attr'])) {
                    $view->vars['attr']['class'] = '';
                }

                $view->vars['attr']['class'] .= ' row ';
            }
        }

        $fieldDto = $view->vars['ea_crud_form']['ea_field'] ?? null;
        if ($fieldDto) {
            $columns = $fieldDto->getColumns() ?? $fieldDto->getDefaultColumns() ?? '';
            if (!array_key_exists('class', $view->vars['row_attr'])) {
                $view->vars['row_attr']['class'] = '';
            }

            $view->vars['row_attr']['class'] .= ' ' . $columns;
        }
    }

    public function markAsDbColumns(FormView $view, FormInterface $form, array $options)
    {
        $dataClass = $options['class'] ?? $form->getConfig()->getDataClass();
        if ($this->classMetadataManipulator->isEntity($dataClass)) {
            $classMetadata = $this->classMetadataManipulator->getClassMetadata($dataClass);
            foreach ($classMetadata->getFieldNames() as $fieldName) {
                $childView = $view->children[$fieldName] ?? null;
                if ($childView) {
                    $childView->vars['is_dbcolumn'] = true;
                }
            }

            foreach ($classMetadata->getAssociationNames() as $fieldName) {
                $childView = $view->children[$fieldName] ?? null;
                if ($childView) {
                    $childView->vars['is_dbcolumn'] = true;
                }
            }
        }
    }

    public function markDbProperties(FormView $view, FormInterface $form, array $options)
    {
        $dataClass = $options['class'] ?? $form->getConfig()->getDataClass();
        if ($this->classMetadataManipulator->isEntity($dataClass)) {
            $classMetadata = $this->classMetadataManipulator->getClassMetadata($dataClass);
            foreach ($view->children as $childView) { // Alias is marked by default and remove if field found..
                $childView->vars['is_alias'] = true;
            }

            foreach ($classMetadata->getFieldNames() as $fieldName) {
                $childView = $view->children[$fieldName] ?? null;
                if ($childView) {
                    $childView->vars['is_dbcolumn'] = true;
                }

                unset($childView->vars['is_alias']);
            }

            foreach ($classMetadata->getAssociationNames() as $fieldName) {
                $childView = $view->children[$fieldName] ?? null;
                if ($childView) {
                    $childView->vars['is_dbcolumn'] = true;
                }

                unset($childView->vars['is_alias']);
            }
        }
    }

    /**
     * Hand each versioned field its own history, so form_div_layout can put a
     * badge next to the label of the fields that actually have one.
     *
     * Runs per form node rather than once at the root: an article's translated
     * fields live on a ThreadIntl sub-form, one per locale, and each of those
     * has to be matched against the locale-prefixed keys of the parent's
     * revisions. VersionManager handles both the anchoring and the memoisation
     * that keeps this from being one query per locale tab.
     */
    public function markVersionable(FormView $view, FormInterface $form)
    {
        $data = $form->getData();
        if (!is_object($data) || !$this->classMetadataManipulator->isEntity($data)) {
            return;
        }

        $history = $this->versionManager->fieldHistory($data);
        if (empty($history)) {
            return;
        }

        // The badge is rendered by the bundle's own form theme, which must not
        // hardcode an admin route: generate it here, and leave it null when
        // the admin bundle is not installed. A null url makes the history
        // read-only rather than making the badge disappear - knowing WHEN
        // something changed is useful even where restoring is not offered.
        $url = null;
        try {
            $url = $this->router->generate('admin_revision_value', ['id' => '__ID__']);
        } catch (\Throwable) {
            $url = null;
        }

        foreach ($history as $property => $entries) {
            $childView = $view->children[$property] ?? null;
            if ($childView === null || empty($entries)) {
                continue;
            }

            $childView->vars['revisions'] = $entries;
            $childView->vars['revisions_url'] = $url;
        }
    }

    public function markOptions(FormView $view, FormInterface $form, array $options)
    {
        if ($this->formFactory->guessSortable($form, $options)) {
            $view->vars['is_sortable'] = true;
        }
        if ($this->formFactory->guessMultiple($form, $options)) {
            $view->vars['is_multiple'] = true;
        }
        if (false === $this->classMetadataManipulator->isCollectionOwner($form)) {
            $view->vars['is_inherited'] = true;
        }
        if ($this->formFactory->guessOrderDirection($form, $options)) {
            $view->vars['is_descending'] = true;
        }
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // if (!$options["form_flow"]) return;
        // if (!$builder->getForm()->isRoot()) return;

        // /**
        //  * @var FormFlowInterface
        //  */
        // $form = $builder->getForm();
        // $step = $form->getStep($options);
        // $token = $form->getToken($options);

        // $builder->add($options['form_flow_name'], HiddenType::class, [
        //             'mapped' => false,
        //             "attr" => ["value" => $token."#". $step]
        //         ]);

        // if(array_key_exists($step, $form->flowCallbacks))
        //     call_user_func($form->flowCallbacks[$step], $builder, $options);
    }
}
