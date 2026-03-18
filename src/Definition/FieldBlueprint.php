<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Definition;

use BadMethodCallException;
use EvanSchleret\FormForge\Exceptions\InvalidFieldDefinitionException;

class FieldBlueprint
{
    private ?FormBlueprint $form;

    private string $type;

    private string $name;

    private ?string $fieldKey = null;

    private ?string $label = null;

    private bool $required = false;

    private bool $nullable = false;

    private mixed $default = null;

    private ?string $placeholder = null;

    private ?string $helpText = null;

    private array $rules = [];

    private array $replaceRules = [];

    private array $meta = [];

    private int|float|string|null $min = null;

    private int|float|string|null $max = null;

    private int|float|string|null $step = null;

    private array $options = [];

    private bool $multiple = false;

    private bool $disabled = false;

    private bool $readonly = false;

    private array $accept = [];

    private ?int $maxSize = null;

    private ?int $maxFiles = null;

    private ?string $storageDisk = null;

    private ?string $storageDirectory = null;

    private ?string $visibility = null;

    public function __construct(?FormBlueprint $form, string $type, string $name)
    {
        FieldType::assert($type);

        $name = trim($name);

        if ($name === '') {
            throw new InvalidFieldDefinitionException('Field name cannot be empty.');
        }

        $this->form = $form;
        $this->type = $type;
        $this->name = $name;
    }

    public static function fromSchemaArray(array $schema): self
    {
        $field = new self(
            null,
            (string) ($schema['type'] ?? ''),
            (string) ($schema['name'] ?? ''),
        );

        $field->fieldKey = isset($schema['field_key']) ? trim((string) $schema['field_key']) : null;
        $field->label = isset($schema['label']) ? trim((string) $schema['label']) : null;
        $field->required = (bool) ($schema['required'] ?? false);
        $field->nullable = (bool) ($schema['nullable'] ?? false);
        $field->default = $schema['default'] ?? null;
        $field->placeholder = isset($schema['placeholder']) ? (string) $schema['placeholder'] : null;
        $field->helpText = isset($schema['help_text']) ? (string) $schema['help_text'] : null;
        $field->rules = self::normalizeRuleList($schema['rules'] ?? []);
        $field->meta = is_array($schema['meta'] ?? null) ? $schema['meta'] : [];
        $field->min = $schema['min'] ?? null;
        $field->max = $schema['max'] ?? null;
        $field->step = $schema['step'] ?? null;
        $field->options = is_array($schema['options'] ?? null) ? $field->normalizeOptions($schema['options']) : [];
        $field->multiple = (bool) ($schema['multiple'] ?? false);
        $field->disabled = (bool) ($schema['disabled'] ?? false);
        $field->readonly = (bool) ($schema['readonly'] ?? false);
        $field->accept = array_values(array_filter(array_map('strval', (array) ($schema['accept'] ?? [])), static fn (string $value): bool => trim($value) !== ''));
        $field->maxSize = isset($schema['max_size']) ? (int) $schema['max_size'] : null;
        $field->maxFiles = isset($schema['max_files']) ? (int) $schema['max_files'] : null;

        if (is_array($schema['storage'] ?? null)) {
            $field->storageDisk = isset($schema['storage']['disk']) ? trim((string) $schema['storage']['disk']) : null;
            $field->storageDirectory = isset($schema['storage']['directory']) ? trim((string) $schema['storage']['directory'], '/') : null;
            $field->visibility = isset($schema['storage']['visibility']) ? trim((string) $schema['storage']['visibility']) : null;
        }

        return $field;
    }

    public function attachToForm(FormBlueprint $form): self
    {
        $this->form = $form;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->form === null || ! method_exists($this->form, $name)) {
            throw new BadMethodCallException("Method [{$name}] does not exist on field blueprint.");
        }

        return $this->form->{$name}(...$arguments);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function fieldKey(?string $fieldKey): self
    {
        $fieldKey = $fieldKey === null ? null : trim($fieldKey);

        if ($fieldKey === null || $fieldKey === '') {
            throw new InvalidFieldDefinitionException("Field key for [{$this->name}] cannot be empty.");
        }

        $this->fieldKey = $fieldKey;

        return $this;
    }

    public function label(string $label): self
    {
        $label = trim($label);

        if ($label === '') {
            throw new InvalidFieldDefinitionException("Field label for [{$this->name}] cannot be empty.");
        }

        $this->label = $label;

        return $this;
    }

    public function required(bool $required = true): self
    {
        if ($required && $this->nullable) {
            throw new InvalidFieldDefinitionException("Field [{$this->name}] cannot be required and nullable at the same time.");
        }

        $this->required = $required;

        return $this;
    }

    public function nullable(bool $nullable = true): self
    {
        if ($nullable && $this->required) {
            throw new InvalidFieldDefinitionException("Field [{$this->name}] cannot be nullable and required at the same time.");
        }

        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function helpText(string $helpText): self
    {
        $this->helpText = $helpText;

        return $this;
    }

    public function rules(string|array ...$rules): self
    {
        $this->rules = array_values(array_unique([...$this->rules, ...$this->normalizeRules($rules)]));

        return $this;
    }

    public function replaceRules(string|array ...$rules): self
    {
        $this->replaceRules = array_values(array_unique($this->normalizeRules($rules)));

        return $this;
    }

    public function hasReplaceRules(): bool
    {
        return $this->replaceRules !== [];
    }

    public function replaceRulesValue(): array
    {
        return $this->replaceRules;
    }

    public function rulesValue(): array
    {
        return $this->rules;
    }

    public function meta(array $meta): self
    {
        $this->meta = $this->mergeArrays($this->meta, $meta);

        return $this;
    }

    public function metaValue(): array
    {
        return $this->meta;
    }

    public function min(int|float|string $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function max(int|float|string $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function step(int|float|string $step): self
    {
        $this->step = $step;

        return $this;
    }

    public function options(array $options): self
    {
        $this->options = $this->normalizeOptions($options);

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function disabled(bool $disabled = true): self
    {
        $this->disabled = $disabled;

        return $this;
    }

    public function readonly(bool $readonly = true): self
    {
        $this->readonly = $readonly;

        return $this;
    }

    public function accept(string|array $accept): self
    {
        $values = is_array($accept) ? $accept : [$accept];
        $normalized = [];

        foreach ($values as $value) {
            $candidate = trim((string) $value);

            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        $this->accept = array_values(array_unique($normalized));

        return $this;
    }

    public function maxSize(int $maxSize): self
    {
        if ($maxSize <= 0) {
            throw new InvalidFieldDefinitionException("maxSize must be positive on field [{$this->name}].");
        }

        $this->maxSize = $maxSize;

        return $this;
    }

    public function maxFiles(int $maxFiles): self
    {
        if ($maxFiles <= 0) {
            throw new InvalidFieldDefinitionException("maxFiles must be positive on field [{$this->name}].");
        }

        $this->maxFiles = $maxFiles;

        return $this;
    }

    public function storageDisk(string $storageDisk): self
    {
        $storageDisk = trim($storageDisk);

        if ($storageDisk === '') {
            throw new InvalidFieldDefinitionException("storageDisk cannot be empty on field [{$this->name}].");
        }

        $this->storageDisk = $storageDisk;

        return $this;
    }

    public function storageDirectory(string $storageDirectory): self
    {
        $storageDirectory = trim($storageDirectory, '/');

        if ($storageDirectory === '') {
            throw new InvalidFieldDefinitionException("storageDirectory cannot be empty on field [{$this->name}].");
        }

        $this->storageDirectory = $storageDirectory;

        return $this;
    }

    public function visibility(string $visibility): self
    {
        $visibility = trim($visibility);

        if (! in_array($visibility, ['public', 'private'], true)) {
            throw new InvalidFieldDefinitionException("Visibility [{$visibility}] is invalid for field [{$this->name}].");
        }

        $this->visibility = $visibility;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function optionsValue(): array
    {
        return $this->options;
    }

    public function minValue(): int|float|string|null
    {
        return $this->min;
    }

    public function maxValue(): int|float|string|null
    {
        return $this->max;
    }

    public function stepValue(): int|float|string|null
    {
        return $this->step;
    }

    public function acceptValue(): array
    {
        return $this->accept;
    }

    public function maxSizeValue(): ?int
    {
        return $this->maxSize;
    }

    public function maxFilesValue(): ?int
    {
        return $this->maxFiles;
    }

    public function storageDiskValue(): ?string
    {
        return $this->storageDisk;
    }

    public function storageDirectoryValue(): ?string
    {
        return $this->storageDirectory;
    }

    public function visibilityValue(): ?string
    {
        return $this->visibility;
    }

    public function toSchemaArray(string $formKey, string $version): array
    {
        $schema = [
            'field_key' => $this->fieldKey ?? $this->autoFieldKey($formKey),
            'type' => $this->type,
            'name' => $this->name,
            'label' => $this->label ?? ucfirst(str_replace('_', ' ', $this->name)),
            'required' => $this->required,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'rules' => $this->resolveRules(),
            'meta' => $this->meta,
        ];

        if ($this->placeholder !== null) {
            $schema['placeholder'] = $this->placeholder;
        }

        if ($this->helpText !== null) {
            $schema['help_text'] = $this->helpText;
        }

        if ($this->min !== null) {
            $schema['min'] = $this->min;
        }

        if ($this->max !== null) {
            $schema['max'] = $this->max;
        }

        if ($this->step !== null) {
            $schema['step'] = $this->step;
        }

        if ($this->multiple) {
            $schema['multiple'] = true;
        }

        if ($this->disabled) {
            $schema['disabled'] = true;
        }

        if ($this->readonly) {
            $schema['readonly'] = true;
        }

        if ($this->options !== []) {
            $schema['options'] = $this->options;
        }

        if ($this->type === FieldType::FILE) {
            if ($this->accept !== []) {
                $schema['accept'] = $this->accept;
            }

            if ($this->maxSize !== null) {
                $schema['max_size'] = $this->maxSize;
            }

            if ($this->maxFiles !== null) {
                $schema['max_files'] = $this->maxFiles;
            }

            $schema['storage'] = [
                'disk' => $this->storageDisk,
                'directory' => $this->storageDirectory,
                'visibility' => $this->visibility,
            ];
        }

        return $schema;
    }

    private function autoFieldKey(string $formKey): string
    {
        return 'fk_' . substr(hash('sha256', $formKey . '|' . $this->name), 0, 12);
    }

    private function normalizeRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $rule) {
            if (is_array($rule)) {
                foreach ($rule as $nested) {
                    $candidate = trim((string) $nested);

                    if ($candidate !== '') {
                        $normalized[] = $candidate;
                    }
                }

                continue;
            }

            $candidate = trim((string) $rule);

            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    private static function normalizeRuleList(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $rule) {
            $candidate = trim((string) $rule);

            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function mergeArrays(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_array($value) && isset($left[$key]) && is_array($left[$key])) {
                $left[$key] = $this->mergeArrays($left[$key], $value);
                continue;
            }

            $left[$key] = $value;
        }

        return $left;
    }

    private function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? $key;
                $label = $option['label'] ?? (string) $value;

                $item = [
                    'value' => $value,
                    'label' => (string) $label,
                ];

                if (isset($option['description'])) {
                    $item['description'] = (string) $option['description'];
                }

                if (array_key_exists('disabled', $option)) {
                    $item['disabled'] = (bool) $option['disabled'];
                }

                if (isset($option['meta']) && is_array($option['meta'])) {
                    $item['meta'] = $option['meta'];
                }

                $normalized[] = $item;

                continue;
            }

            if (is_string($key)) {
                $normalized[] = ['value' => $key, 'label' => (string) $option];
                continue;
            }

            $normalized[] = ['value' => $option, 'label' => (string) $option];
        }

        return $normalized;
    }

    private function resolveRules(): array
    {
        if ($this->replaceRules !== []) {
            return $this->replaceRules;
        }

        $rules = $this->autoRules();

        foreach ($this->rules as $rule) {
            if (! in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function autoRules(): array
    {
        $rules = [];

        if ($this->required) {
            $rules[] = 'required';
        }

        if ($this->nullable) {
            $rules[] = 'nullable';
        }

        switch ($this->type) {
            case FieldType::TEXT:
            case FieldType::TEXTAREA:
                $rules[] = 'string';
                break;

            case FieldType::EMAIL:
                $rules[] = 'string';
                $rules[] = 'email';
                break;

            case FieldType::NUMBER:
                $rules[] = 'numeric';
                break;

            case FieldType::SELECT:
            case FieldType::SELECT_MENU:
            case FieldType::RADIO:
                break;

            case FieldType::CHECKBOX:
            case FieldType::SWITCH:
                $rules[] = 'boolean';
                break;

            case FieldType::CHECKBOX_GROUP:
                $rules[] = 'array';
                break;

            case FieldType::DATE:
                $rules[] = 'date_format:Y-m-d';
                break;

            case FieldType::TIME:
                $rules[] = 'date_format:H:i:s';
                break;

            case FieldType::DATETIME:
                $rules[] = 'date';
                break;

            case FieldType::DATE_RANGE:
            case FieldType::DATETIME_RANGE:
                $rules[] = 'array';
                break;

            case FieldType::FILE:
                $rules[] = $this->multiple ? 'array' : 'file';
                break;
        }

        if ($this->min !== null) {
            if ($this->type === FieldType::DATE || $this->type === FieldType::DATETIME) {
                $rules[] = 'after_or_equal:' . $this->min;
            } else {
                $rules[] = 'min:' . $this->min;
            }
        }

        if ($this->max !== null) {
            if ($this->type === FieldType::DATE || $this->type === FieldType::DATETIME) {
                $rules[] = 'before_or_equal:' . $this->max;
            } else {
                $rules[] = 'max:' . $this->max;
            }
        }

        if ($this->step !== null && $this->type === FieldType::NUMBER) {
            $rules[] = 'multiple_of:' . $this->step;
        }

        if ($this->type === FieldType::FILE && $this->multiple && $this->maxFiles !== null) {
            $rules[] = 'max:' . $this->maxFiles;
        }

        return array_values(array_unique($rules));
    }
}
