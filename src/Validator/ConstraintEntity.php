<?php

namespace Base\Validator;

#[\Attribute(\Attribute::TARGET_CLASS)]
abstract class ConstraintEntity extends Constraint
{
    /** @var array<int,string>|string */
    public array|string $fields;

    /** @var mixed */
    public mixed $entity = null;

    /**
     * @param array|string $fields   Required: the field or combination of fields
     * @param mixed        $entity   Optional: entity class or name
     */
    public function __construct(
        array|string $fields,
        mixed $entity = null,
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        // Normalise fields
        $this->fields = $fields;
        $this->entity = $entity;

        // A message the constraint was given is the one it says. Without one it
        // says the generic "@validators.<constraint>", as it always did: the
        // per-field key built below is replaced by the parent's default.
        $given = $message;

        // Generate default message if none provided
        if ($message === null) {
            $constraintName = explode("\\", static::class);
            $constraintName = preg_replace('/Entity$/', '', array_pop($constraintName));

            // First field: either array[0] or single string
            $firstField = is_array($fields)
                ? ($fields[0] ?? 'unknown')
                : $fields;

            $message = camel2snake($firstField) . "." . camel2snake($constraintName);
        }

        $this->message = $message;

        // MUST be last (Symfony 7+ requirement)
        parent::__construct(message: $given, groups: $groups, payload: $payload);
    }

    public function getRequiredOptions(): array
    {
        return ['fields'];
    }

    public function getDefaultOption(): ?string
    {
        return 'fields';
    }

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}