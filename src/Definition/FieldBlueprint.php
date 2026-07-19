<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Definition;

use BadMethodCallException;
use EvanSchleret\FormForge\Exceptions\InvalidFieldDefinitionException;
use EvanSchleret\FormForge\Support\RichTextSanitizer;

class FieldBlueprint
{
    private ?FormBlueprint $form;

    private string $type;

    private ?string $temporalMode = null;

    private ?int $hourCycle = null;

    private string $name;

    private ?string $fieldKey = null;

    private ?string $label = null;

    private bool $required = false;

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

    private ?string $consentLabel = null;

    private ?string $display = null;

    private array $accept = [];

    private array $addressFields = [];

    private ?int $maxSize = null;

    private ?int $maxFiles = null;

    private ?int $maxTotalSize = null;

    private ?string $storageDisk = null;

    private ?string $storageDirectory = null;

    private ?string $visibility = null;

    public function __construct(?FormBlueprint $form, string $type, string $name)
    {
        $type = FieldType::normalize($type);
        FieldType::assert($type);

        $name = trim($name);

        if ($name === '') {
            throw new InvalidFieldDefinitionException('Field name cannot be empty.');
        }

        $this->form = $form;
        $this->type = $type;
        $this->name = $name;

        if ($type === FieldType::TEMPORAL) {
            $this->temporalMode = 'date';
        }

        if ($type === FieldType::ADDRESS) {
            $this->addressFields = $this->defaultAddressFields();
        }
    }

    public static function fromSchemaArray(array $schema): self
    {
        $rawType = (string) ($schema['type'] ?? '');
        $field = new self(
            null,
            $rawType,
            (string) ($schema['name'] ?? ''),
        );

        $field->fieldKey = isset($schema['field_key']) ? trim((string) $schema['field_key']) : null;
        $field->label = isset($schema['label'])
            ? self::nullIfEmpty(self::sanitizeRichText($schema['label']))
            : null;
        $field->required = (bool) ($schema['required'] ?? false);
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
        $field->consentLabel = isset($schema['consent_label']) ? trim((string) $schema['consent_label']) : null;
        $field->display = isset($schema['display']) ? trim((string) $schema['display']) : null;
        if (array_key_exists('temporal_mode', $schema) && is_string($schema['temporal_mode']) && FieldType::normalize($rawType) === FieldType::TEMPORAL) {
            $field->temporalMode = self::normalizeTemporalMode($schema['temporal_mode']);
        }

        if ($field->temporalMode === null && FieldType::temporalMode($rawType) !== null) {
            $field->temporalMode = FieldType::temporalMode($rawType);
        }

        if (array_key_exists('hour_cycle', $schema) && is_int($schema['hour_cycle']) && in_array($schema['hour_cycle'], [12, 24], true)) {
            $field->hourCycle = $schema['hour_cycle'];
        }

        if ($field->temporalMode === 'time' && $field->hourCycle === null) {
            $field->hourCycle = 24;
        }

        $field->accept = array_values(array_filter(array_map('strval', (array) ($schema['accept'] ?? [])), static fn (string $value): bool => trim($value) !== ''));
        if (array_key_exists('address_fields', $schema) && is_array($schema['address_fields'])) {
            $field->addressFields = $field->normalizeAddressFields($schema['address_fields']);
        }
        $field->maxSize = isset($schema['max_size']) ? (int) $schema['max_size'] : null;
        $field->maxFiles = isset($schema['max_files']) ? (int) $schema['max_files'] : null;
        $field->maxTotalSize = isset($schema['max_total_size']) ? (int) $schema['max_total_size'] : null;

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

    public function temporalMode(): ?string
    {
        return $this->temporalMode;
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
        $label = self::sanitizeRichText($label);

        if ($label === '') {
            throw new InvalidFieldDefinitionException("Field label for [{$this->name}] cannot be empty.");
        }

        $this->label = $label;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

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

    public function consentLabel(string $consentLabel): self
    {
        $consentLabel = trim($consentLabel);

        if ($consentLabel === '') {
            throw new InvalidFieldDefinitionException("consentLabel cannot be empty on field [{$this->name}].");
        }

        $this->consentLabel = $consentLabel;

        return $this;
    }

    public function display(string $display): self
    {
        $display = trim($display);
        $this->display = $display === '' ? null : $display;

        return $this;
    }

    public function temporalModeValue(string $mode): self
    {
        $this->temporalMode = self::normalizeTemporalMode($mode);

        if ($this->temporalMode === 'time' && $this->hourCycle === null) {
            $this->hourCycle = 24;
        }

        return $this;
    }

    public function hourCycle(int $hourCycle): self
    {
        if (! in_array($hourCycle, [12, 24], true)) {
            throw new InvalidFieldDefinitionException("hourCycle must be 12 or 24 on field [{$this->name}].");
        }

        $this->hourCycle = $hourCycle;

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

    public function maxTotalSize(int $maxTotalSize): self
    {
        if ($maxTotalSize <= 0) {
            throw new InvalidFieldDefinitionException("maxTotalSize must be positive on field [{$this->name}].");
        }

        $this->maxTotalSize = $maxTotalSize;

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

    public function maxTotalSizeValue(): ?int
    {
        return $this->maxTotalSize;
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

    public function consentLabelValue(): ?string
    {
        return $this->consentLabel;
    }

    public function displayValue(): ?string
    {
        return $this->display;
    }

    public function hourCycleValue(): ?int
    {
        return $this->hourCycle;
    }

    public function addressFieldsValue(): array
    {
        return $this->addressFields;
    }

    public function addressFields(array $addressFields): self
    {
        $this->addressFields = $this->normalizeAddressFields($addressFields);

        return $this;
    }

    public function toSchemaArray(string $formKey, string $version): array
    {
        $schema = [
            'field_key' => $this->fieldKey ?? $this->autoFieldKey($formKey),
            'type' => $this->type,
            'name' => $this->name,
            'label' => $this->label ?? ucfirst(str_replace('_', ' ', $this->name)),
            'required' => $this->required,
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

        if ($this->consentLabel !== null) {
            $schema['consent_label'] = $this->consentLabel;
        }

        if ($this->display !== null) {
            $schema['display'] = $this->display;
        }

        if ($this->temporalMode !== null) {
            $schema['temporal_mode'] = $this->temporalMode;
        }

        if ($this->hourCycle !== null && $this->temporalMode === 'time') {
            $schema['hour_cycle'] = $this->hourCycle;
        }

        if ($this->options !== []) {
            $schema['options'] = $this->options;
        }

        if ($this->addressFields !== []) {
            $schema['address_fields'] = $this->addressFields;
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

            if ($this->maxTotalSize !== null) {
                $schema['max_total_size'] = $this->maxTotalSize;
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

    private static function sanitizeRichText(mixed $value): string
    {
        return RichTextSanitizer::sanitize(is_string($value) ? $value : (string) $value);
    }

    private static function nullIfEmpty(string $value): ?string
    {
        return trim($value) === '' ? null : $value;
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

    private static function normalizeTemporalMode(string $mode): string
    {
        return in_array($mode, ['date', 'time'], true)
            ? $mode
            : 'date';
    }

    private function normalizeAddressFields(array $addressFields): array
    {
        $normalized = [];

        foreach ($addressFields as $addressField) {
            if (! is_array($addressField)) {
                continue;
            }

            $key = trim((string) ($addressField['key'] ?? ''));
            $hasLabel = array_key_exists('label', $addressField)
                && (is_string($addressField['label']) || $addressField['label'] === null);
            $label = $hasLabel ? trim((string) $addressField['label']) : '';

            if ($key === '') {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'visible' => (bool) ($addressField['visible'] ?? true),
                'required' => (bool) ($addressField['required'] ?? false),
            ];
        }

        return $normalized;
    }

    private function defaultAddressFields(): array
    {
        return [
            ['key' => 'line1', 'label' => trans('formforge::messages.address_line1'), 'visible' => true, 'required' => true],
            ['key' => 'line2', 'label' => trans('formforge::messages.address_line2'), 'visible' => false, 'required' => false],
            ['key' => 'city', 'label' => trans('formforge::messages.address_city'), 'visible' => true, 'required' => true],
            ['key' => 'state', 'label' => trans('formforge::messages.address_state'), 'visible' => false, 'required' => false],
            ['key' => 'zip', 'label' => trans('formforge::messages.address_zip'), 'visible' => true, 'required' => true],
            ['key' => 'country', 'label' => trans('formforge::messages.address_country'), 'visible' => true, 'required' => true],
        ];
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

        if ($this->required && $this->type !== FieldType::CONSENT && $this->type !== FieldType::ADDRESS) {
            $rules[] = 'required';
        }

        switch ($this->type) {
            case FieldType::TEXT:
                $rules[] = 'string';
                break;

            case FieldType::NUMBER:
                $rules[] = 'numeric';
                break;

            case FieldType::RADIO:
                break;

            case FieldType::CONSENT:
                $rules[] = 'boolean';
                if ($this->required) {
                    $rules[] = 'accepted';
                }
                break;

            case FieldType::CHECKBOX_GROUP:
                $rules[] = 'array';
                break;

            case FieldType::ADDRESS:
                $rules[] = 'array';
                break;

            case FieldType::TEMPORAL:
                if ($this->temporalMode === 'date') {
                    $rules[] = 'date_format:Y-m-d';
                } elseif ($this->temporalMode === 'time') {
                    $rules[] = 'date_format:H:i:s';
                } else {
                    $rules[] = 'date_format:Y-m-d';
                }
                break;

            case FieldType::DATE:
                $rules[] = 'date_format:Y-m-d';
                break;

            case FieldType::TIME:
                $rules[] = 'date_format:H:i:s';
                break;

            case FieldType::FILE:
                $rules[] = $this->multiple ? 'array' : 'file';
                break;
        }

        if ($this->min !== null) {
            if ($this->type === FieldType::TEMPORAL && $this->temporalMode === 'date') {
                $rules[] = 'after_or_equal:' . $this->min;
            } elseif ($this->type === FieldType::DATE) {
                $rules[] = 'after_or_equal:' . $this->min;
            } else {
                $rules[] = 'min:' . $this->min;
            }
        }

        if ($this->max !== null) {
            if ($this->type === FieldType::TEMPORAL && $this->temporalMode === 'date') {
                $rules[] = 'before_or_equal:' . $this->max;
            } elseif ($this->type === FieldType::DATE) {
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
