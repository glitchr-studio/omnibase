<?php

namespace Base\Field;

use Base\Field\Type\AssociationType;
use Base\Field\Option\TextAlign;
use Symfony\Contracts\Translation\TranslatableInterface;

final class AssociationField implements FieldInterface
{
    use FieldTrait;

    public const OPTION_RENDER_FORMAT = 'renderFormat';
    public const OPTION_ICONS = 'icons';
    public const OPTION_CLASS = 'class';

    public const OPTION_CRUD_CONTROLLER = 'crudControllerFqcn';
    public const OPTION_DISPLAY_LIMIT = 'displayLimit';
    /** @see SelectField::OPTION_ENTITY_DISPLAY - same option, same meaning. */
    public const OPTION_ENTITY_DISPLAY = 'entityDisplay';
    public const OPTION_ICON_ALIGN = 'iconAlign';

    public const OPTION_SHOW_FIRST = 'showFirst';
    public const OPTION_SHOW = 'show';

    public const NO_SHOW = 0;
    public const SHOW_NAME_ONLY = 1;
    public const SHOW_ICON_ONLY = 2;
    public const SHOW_ALL = 3;

    public const OPTION_ALLOW_ADD = 'allowAdd';
    public const OPTION_ALLOW_DELETE = 'allowDelete';
    public const OPTION_LENGTH = 'length';

    public const OPTION_RELATED_URL = 'relatedUrl';
    public const OPTION_DOCTRINE_ASSOCIATION_TYPE = 'associationType';

    public static function new(string $propertyName, TranslatableInterface|string|bool|null $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplateName('crud/field/association')
            ->setFormType(AssociationType::class)
            ->addCssClass('field-entity')
            ->addCssClass('file-widget')
            ->setTextAlign(TextAlign::CENTER)
            ->setFormTypeOptionIfNotSet('class', null)
            ->setCustomOption(self::OPTION_RENDER_FORMAT, 'count')
            ->setCustomOption(self::OPTION_CRUD_CONTROLLER, null)
            ->setCustomOption(self::OPTION_RELATED_URL, null)
            ->setCustomOption(self::OPTION_DOCTRINE_ASSOCIATION_TYPE, null);
    }

    /**
     * The related record's own form, inside the form of the record being
     * edited. By default the back office does that only for what the record
     * owns (one-to-many, one-to-one); a record that exists on its own
     * (many-to-one, many-to-many, a user account) is chosen in a list.
     * `embed(false)` asks for the list whatever the association.
     *
     * @return $this
     */
    public function embed(bool $embed = true): self
    {
        $this->setFormTypeOption('embed', $embed);

        return $this;
    }

    public function justDisplay(): self
    {
        return $this->allowDelete(false)->allowAdd(false)->autoload(false);
    }

    /**
     * @param $autoload
     * @return $this
     */
    public function autoload($autoload = true): self
    {
        $this->setFormTypeOption('autoload', $autoload);

        return $this;
    }

    public function setFields(array $fields): self
    {
        $this->setFormTypeOption('fields', $fields);

        return $this;
    }

    public function allowAdd(bool $allowAdd = true): self
    {
        $this->setFormTypeOption('allow_add', $allowAdd);

        return $this;
    }

    /**
     * @param string $textAlign
     * @return $this
     */
    public function setTextAlign(string $textAlign)
    {
        $this->setIconAlign($textAlign);
        $this->dto->setTextAlign($textAlign);

        return $this;
    }

    /**
     * @param string $iconAlign
     * @return $this
     */
    public function setIconAlign(string $iconAlign)
    {
        $this->setCustomOption(self::OPTION_ICON_ALIGN, $iconAlign);

        return $this;
    }

    /**
     * @param bool $allow
     * @return $this
     */
    public function allowMultipleChoices(bool $allow = true)
    {
        $this->setFormTypeOptionIfNotSet('multiple', $allow);

        return $this;
    }

    /**
     * @param bool $allow
     * @return $this
     */
    public function allowDelete(bool $allow = true)
    {
        $this->setFormTypeOption('allow_delete', $allow);

        return $this;
    }

    /**
     * @param bool $horizontal
     * @return AssociationField
     */
    public function turnHorizontal(bool $horizontal)
    {
        return $this->showVertical(!$horizontal);
    }

    /**
     * @param bool $vertical
     * @return AssociationField
     */
    public function showVertical(bool $vertical = true)
    {
        return $this->setFormTypeOption('vertical', $vertical);
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setDisplayLimit(int $limit = 2)
    {
        $this->setCustomOption(self::OPTION_DISPLAY_LIMIT, $limit);

        return $this;
    }

    /**
     * @param string $mode one of FieldValueResolver::DISPLAY_*
     * @return $this
     */
    public function setEntityDisplay(string $mode = FieldValueResolver::DISPLAY_USERNAME)
    {
        $this->setCustomOption(self::OPTION_ENTITY_DISPLAY, $mode);

        return $this;
    }

    /**
     * @param array $icons
     * @return $this
     */
    public function setIcons(array $icons)
    {
        $this->setCustomOption(self::OPTION_ICONS, $icons);

        return $this;
    }

    /**
     * @param string|null $class
     * @return $this
     */
    public function setClass(?string $class = null)
    {
        $this->setFormTypeOption(self::OPTION_CLASS, $class);

        return $this;
    }

    /**
     * @param int $show
     * @return $this
     */
    public function showFirst(int $show = self::SHOW_ALL)
    {
        $this->setCustomOption(self::OPTION_SHOW_FIRST, $show);

        return $this;
    }

    /**
     * @param int $show
     * @return $this
     */
    public function show(int $show = self::SHOW_ALL)
    {
        $this->setCustomOption(self::OPTION_SHOW, $show);

        return $this;
    }

    /**
     * @param bool $useHtml
     * @return $this
     */
    public function useHtml(bool $useHtml = true)
    {
        $this->setFormTypeOption('html', $useHtml);

        return $this;
    }

    /**
     * @return $this
     */
    public function renderAsCount()
    {
        $this->setCustomOption(self::OPTION_RENDER_FORMAT, 'count');

        return $this;
    }

    /**
     * @return $this
     */
    public function renderAsText()
    {
        $this->setCustomOption(self::OPTION_RENDER_FORMAT, 'text');

        return $this;
    }

    public function setLength(int $length): self
    {
        $this->setFormTypeOption(self::OPTION_LENGTH, max(0, $length));

        return $this;
    }

    /**
     * @param $embed
     * @return $this
     */
    public function showEmbedded($embed = true): self
    {
        $this->setFormTypeOption('group', $embed);

        return $this;
    }

    /**
     * @param $embed
     * @return $this
     */
    public function showEmbeddedRow($embed = true): self
    {
        $this->setFormTypeOption('row_group', $embed);

        return $this;
    }

    /**
     * @param $collapsed
     * @return $this
     */
    public function showCollapsed($collapsed = true): self
    {
        $this->setFormTypeOption('entry_collapsed', $collapsed);

        return $this;
    }
}
