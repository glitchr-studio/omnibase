<?php

namespace Base\Form;

use Exception;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The forms of a request, by name. Reset between two requests (kernel.reset):
 * a kernel that serves several - a test client's, a worker's - kept the
 * sign-in form under "form:login", and the sign-up page, which asks for a
 * processor of that name, rendered the sign-in form.
 */
class FormProxy implements FormProxyInterface, ResetInterface
{
    /**
     * @var FormFactoryInterface
     */
    protected FormFactoryInterface $formFactory;

    /**
     * @var array[FormProcessorInterface]
     */
    protected array $formProcessors = [];

    public function __construct(FormFactoryInterface $formFactory)
    {
        $this->formFactory = $formFactory;
    }

    /** @var array */
    protected array $forms = [];

    /**
     * @return array
     */
    public function all()
    {
        return $this->forms;
    }

    public function empty(): bool
    {
        return empty($this->forms);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->forms);
    }

    public function getData(string $name, ?string $childName = null): mixed
    {
        $data = null;
        if ($childName) {
            $data = $this->forms[$name]?->get($childName)?->getData();
        }

        if ($data == null) {
            $data = $this->forms[$name]?->getData();
        }

        return $data;
    }

    public function setData(string $name, mixed $data): self
    {
        $this->forms[$name]?->setData($data);
        return $this;
    }


    public function get(string $name): ?FormInterface
    {
        return $this->forms[$name] ?? null;
    }

    public function add(string $name, ?FormInterface $form): self
    {
        if ($this->get($name) != null) {
            throw new Exception("Form identifier \"$name\" already exists.");
        }

        $this->forms[$name] = $form;
        return $this;
    }

    public function remove(string $name): self
    {
        if ($this->has($name)) {
            unset($this->forms[$name]);
        }

        return $this;
    }

    public function create(string $name, string $type = FormType::class, mixed $data = null, array $options = [], array $listeners = []): FormInterface
    {
        if (array_key_exists($name, $this->forms)) {
            throw new Exception("Form \"$name\" already exists.");
        }

        $this->forms[$name] = $this->formFactory->create($type, $data, $options, $listeners);
        return $this->forms[$name];
    }

    public function submit(string $name, string|array|null $submittedData, bool $clearMissing = true): ?FormInterface
    {
        return $this->get($name)?->submit($submittedData, $clearMissing);
    }

    public function getProcessor(string $name): ?FormProcessorInterface
    {
        return $this->formProcessors[$name] ?? null;
    }

    public function createProcessor(string $name, string $formTypeClass = FormType::class, array $options = [], array $listeners = []): ?FormProcessorInterface
    {
        // a processor of that name made for another type of form is not this one: the pages that share
        // a name ("form:login": the sign-in, the sign-in by a link, the sign-up) each get their own
        $existing = $this->formProcessors[$name] ?? null;
        if (null !== $existing && $formTypeClass !== get_class($existing->getForm()->getConfig()->getType()->getInnerType())) {
            unset($this->formProcessors[$name], $this->forms[$name]);
        }

        $this->formProcessors[$name] = $this->formProcessors[$name] ?? new FormProcessor($this->get($name) ?? $this->create($name, $formTypeClass, null, $options, $listeners));
        return $this->formProcessors[$name];
    }

    public function reset(): void
    {
        $this->forms = [];
        $this->formProcessors = [];
    }
}
