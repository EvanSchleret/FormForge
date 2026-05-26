<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Definition\FieldType;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\UnknownFieldsException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubmissionValidator
{
    public function describeFields(array $schema): array
    {
        $fields = Arr::get($schema, 'fields', []);

        if (! is_array($fields)) {
            return [];
        }

        $descriptors = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));
            $type = trim((string) ($field['type'] ?? ''));

            if ($name === '' || $type === '') {
                continue;
            }

            $fieldKey = $this->nullableTrimmed($field['field_key'] ?? null);
            $key = $this->nullableTrimmed($field['key'] ?? null);
            $id = $this->nullableTrimmed($field['id'] ?? null);
            $label = $this->nullableTrimmed($field['label'] ?? null);

            $descriptors[] = [
                'name' => $name,
                'field_key' => $fieldKey,
                'key' => $key,
                'id' => $id,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'rules' => $this->normalizeRules($field['rules'] ?? []),
                'options' => $this->normalizeOptions($field['options'] ?? []),
                'default' => $field['default'] ?? null,
                'lookup_keys' => $this->lookupKeys($name, $fieldKey, $key, $id),
            ];
        }

        return $descriptors;
    }

    public function resolveField(array $schema, string $identifier): ?array
    {
        $needle = trim($identifier);

        if ($needle === '') {
            return null;
        }

        foreach ($this->describeFields($schema) as $field) {
            if (in_array($needle, $field['lookup_keys'], true)) {
                return $field;
            }
        }

        return null;
    }

    public function validateField(array $schema, string $fieldName, mixed $value): array
    {
        $fieldName = trim($fieldName);

        if ($fieldName === '') {
            throw new FormForgeException('Field name is required.');
        }

        $field = $this->resolveField($schema, $fieldName);

        if (! is_array($field)) {
            throw UnknownFieldsException::fromFields([$fieldName]);
        }

        $payload = [$field['name'] => $value];
        $payload = $this->sanitizeNullishOptionalPayload([$field], $payload);
        $rules = $this->compileRules([$field]);

        $validator = Validator::make($payload, $rules);

        if ((bool) config('formforge.validation.field.stop_on_first_failure', false)) {
            $validator->stopOnFirstFailure();
        }

        if (! $validator->passes()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->toArray(),
                'validated' => [],
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
            'validated' => $validator->validated(),
        ];
    }

    public function validate(array $schema, array $payload): array
    {
        $fields = Arr::get($schema, 'fields', []);

        if (! is_array($fields)) {
            $fields = [];
        }

        $knownNames = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');

            if ($name !== '') {
                $knownNames[] = $name;
            }
        }

        $unknown = array_values(array_diff(array_keys($payload), $knownNames));
        $rejectUnknown = (bool) config('formforge.validation.reject_unknown_fields', true);

        if ($unknown !== [] && $rejectUnknown) {
            throw UnknownFieldsException::fromFields($unknown);
        }

        if ($unknown !== []) {
            foreach ($unknown as $unknownKey) {
                unset($payload[$unknownKey]);
            }
        }

        $payload = $this->sanitizeNullishOptionalPayload($fields, $payload);

        $rules = $this->compileRules($fields);

        return Validator::make($payload, $rules)->validate();
    }

    public function validateFields(array $schema, array $payload, array $onlyFields = []): array
    {
        $descriptors = $this->describeFields($schema);
        $byName = [];

        foreach ($descriptors as $field) {
            $byName[$field['name']] = $field;
        }

        $resolved = [];
        $errors = [];

        if ($onlyFields === []) {
            foreach ($payload as $key => $_value) {
                if (! is_string($key)) {
                    continue;
                }

                $field = $this->resolveField($schema, $key);

                if (is_array($field)) {
                    $resolved[$field['name']] = $field;
                }
            }
        } else {
            foreach ($onlyFields as $requested) {
                $identifier = trim((string) $requested);

                if ($identifier === '') {
                    continue;
                }

                $field = $this->resolveField($schema, $identifier);

                if (! is_array($field)) {
                    $errors[$identifier] = ['Unknown field identifier.'];
                    continue;
                }

                $resolved[$field['name']] = $field;
            }
        }

        if ($resolved === []) {
            return [
                'valid' => $errors === [],
                'errors' => $errors,
                'validated' => [],
            ];
        }

        $subsetPayload = [];

        foreach ($resolved as $name => $field) {
            foreach ($field['lookup_keys'] as $lookup) {
                if (array_key_exists($lookup, $payload)) {
                    $subsetPayload[$name] = $payload[$lookup];
                    break;
                }
            }
        }

        $fieldsForValidation = [];
        foreach (array_keys($resolved) as $name) {
            if (isset($byName[$name])) {
                $fieldsForValidation[] = $byName[$name];
            }
        }

        $subsetPayload = $this->sanitizeNullishOptionalPayload($fieldsForValidation, $subsetPayload);
        $rules = $this->compileRules($fieldsForValidation);
        $validator = Validator::make($subsetPayload, $rules);

        if ((bool) config('formforge.validation.field.stop_on_first_failure', false)) {
            $validator->stopOnFirstFailure();
        }

        if (! $validator->passes()) {
            $errors = array_merge($errors, $validator->errors()->toArray());
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'validated' => $errors === [] ? $validator->validated() : [],
        ];
    }

    private function compileRules(array $fields): array
    {
        $rules = [];
        $uploadMode = (string) config('formforge.uploads.mode', 'managed');

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? '');

            if ($name === '' || $type === '') {
                continue;
            }

            $baseRules = $this->normalizeRules($field['rules'] ?? []);

            if (FieldType::isRange($type)) {
                $this->compileRangeRules($rules, $name, $type, $field, $baseRules);
                continue;
            }

            if ($type === FieldType::FILE) {
                $this->compileFileRules($rules, $name, $field, $baseRules, $uploadMode);
                continue;
            }

            if (FieldType::isOptionBased($type)) {
                $this->compileOptionRules($rules, $name, $type, $field, $baseRules);
                continue;
            }

            $rules[$name] = $baseRules;
        }

        return $rules;
    }

    private function compileRangeRules(array &$rules, string $name, string $type, array $field, array $baseRules): void
    {
        if (! in_array('array', $baseRules, true)) {
            $baseRules[] = 'array';
        }

        $rules[$name] = $baseRules;

        $required = (bool) ($field['required'] ?? false);
        $format = $type === FieldType::DATE_RANGE ? 'date_format:Y-m-d' : 'date';

        $leafRules = [];

        if ($required) {
            $leafRules[] = 'required';
        }

        $leafRules[] = $format;

        $rules[$name . '.start'] = $leafRules;

        $endRules = [...$leafRules];
        $endRules[] = 'after_or_equal:' . $name . '.start';

        $rules[$name . '.end'] = $endRules;
    }

    private function compileOptionRules(array &$rules, string $name, string $type, array $field, array $baseRules): void
    {
        $values = $this->extractOptionValues($field);

        if ($type === FieldType::CHECKBOX_GROUP) {
            if (! in_array('array', $baseRules, true)) {
                $baseRules[] = 'array';
            }

            $rules[$name] = $baseRules;

            if ($values !== []) {
                $rules[$name . '.*'] = [Rule::in($values)];
            }

            return;
        }

        if ($values !== []) {
            $baseRules[] = Rule::in($values);
        }

        $rules[$name] = $baseRules;
    }

    private function compileFileRules(array &$rules, string $name, array $field, array $baseRules, string $uploadMode): void
    {
        $multiple = (bool) ($field['multiple'] ?? false);

        if ($uploadMode === 'managed') {
            $this->compileManagedFileRules($rules, $name, $field, $baseRules, $multiple);
            return;
        }

        $baseRules = $this->sanitizeMetadataBaseRules($baseRules);
        $this->compileMetadataFileRules($rules, $name, $baseRules, $multiple);
    }

    private function compileManagedFileRules(array &$rules, string $name, array $field, array $baseRules, bool $multiple): void
    {
        $acceptRules = $this->acceptRules($field);
        $sizeRules = $this->sizeRules($field);

        if ($multiple) {
            if (! in_array('array', $baseRules, true)) {
                $baseRules[] = 'array';
            }

            if (isset($field['max_files'])) {
                $baseRules[] = 'max:' . (int) $field['max_files'];
            }

            $rules[$name] = $baseRules;
            $rules[$name . '.*'] = array_values(array_unique(['file', ...$acceptRules, ...$sizeRules]));

            return;
        }

        if (! in_array('file', $baseRules, true)) {
            $baseRules[] = 'file';
        }

        $rules[$name] = array_values(array_unique([...$baseRules, ...$acceptRules, ...$sizeRules]));
    }

    private function compileMetadataFileRules(array &$rules, string $name, array $baseRules, bool $multiple): void
    {
        if (! in_array('array', $baseRules, true)) {
            $baseRules[] = 'array';
        }

        $rules[$name] = $baseRules;

        if ($multiple) {
            $rules[$name . '.*'] = ['exclude_without:' . $name, 'array'];
            $rules[$name . '.*.upload_token'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.path'] = ['exclude_without:' . $name, 'required_without:' . $name . '.*.upload_token', 'string'];
            $rules[$name . '.*.disk'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.original_name'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.stored_name'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.mime_type'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.extension'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.size'] = ['exclude_without:' . $name, 'nullable', 'integer', 'min:0'];
            $rules[$name . '.*.checksum'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.metadata'] = ['exclude_without:' . $name, 'nullable', 'array'];

            return;
        }

        $rules[$name . '.upload_token'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.path'] = ['exclude_without:' . $name, 'required_without:' . $name . '.upload_token', 'string'];
        $rules[$name . '.disk'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.original_name'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.stored_name'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.mime_type'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.extension'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.size'] = ['exclude_without:' . $name, 'nullable', 'integer', 'min:0'];
        $rules[$name . '.checksum'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.metadata'] = ['exclude_without:' . $name, 'nullable', 'array'];
    }

    private function normalizeRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $candidate = trim($rule);

                if ($candidate !== '' && strtolower($candidate) !== 'nullable') {
                    $normalized[] = $candidate;
                }

                continue;
            }

            $normalized[] = $rule;
        }

        return $normalized;
    }

    private function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values($options);
    }

    private function lookupKeys(string $name, ?string $fieldKey, ?string $key, ?string $id): array
    {
        $values = [$name, $fieldKey, $key, $id];

        return array_values(array_unique(array_filter($values, static fn (?string $value): bool => is_string($value) && $value !== '')));
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function sanitizeNullishOptionalPayload(array $fields, array $payload): array
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            $required = (bool) ($field['required'] ?? false);

            if ($name === '' || $required || ! array_key_exists($name, $payload)) {
                continue;
            }

            if ($this->isNullishOptionalValue($payload[$name])) {
                unset($payload[$name]);
            }
        }

        return $payload;
    }

    private function isNullishOptionalValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (! is_array($value)) {
            return false;
        }

        if ($value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->isNullishOptionalValue($item)) {
                return false;
            }
        }

        return true;
    }

    private function extractOptionValues(array $field): array
    {
        $options = $field['options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        $values = [];

        foreach ($options as $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $values[] = $option['value'];
                continue;
            }

            if (is_string($option) || is_int($option) || is_float($option) || is_bool($option)) {
                $values[] = $option;
            }
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    private function acceptRules(array $field): array
    {
        $accept = $field['accept'] ?? [];

        if (! is_array($accept) || $accept === []) {
            return [];
        }

        $extensions = [];
        $mimetypes = [];
        $rules = [];

        foreach ($accept as $raw) {
            $value = trim((string) $raw);

            if ($value === '' || $value === '*') {
                continue;
            }

            if ($value === 'image/*') {
                $rules[] = 'image';
                continue;
            }

            if (str_starts_with($value, '.')) {
                $extensions[] = ltrim(strtolower($value), '.');
                continue;
            }

            if (str_contains($value, '/')) {
                $mimetypes[] = $value;
                continue;
            }

            $extensions[] = strtolower($value);
        }

        $extensions = array_values(array_unique(array_filter($extensions)));
        $mimetypes = array_values(array_unique(array_filter($mimetypes)));

        if ($extensions !== []) {
            $rules[] = 'extensions:' . implode(',', $extensions);
        }

        if ($mimetypes !== []) {
            $rules[] = 'mimetypes:' . implode(',', $mimetypes);
        }

        return array_values(array_unique($rules));
    }

    private function sizeRules(array $field): array
    {
        if (! isset($field['max_size'])) {
            return [];
        }

        $bytes = (int) $field['max_size'];

        if ($bytes <= 0) {
            return [];
        }

        $kilobytes = (int) ceil($bytes / 1024);

        return ['max:' . $kilobytes];
    }

    private function sanitizeMetadataBaseRules(array $baseRules): array
    {
        $filtered = [];

        foreach ($baseRules as $rule) {
            if (! is_string($rule)) {
                $filtered[] = $rule;
                continue;
            }

            if ($rule === 'file' || $rule === 'image') {
                continue;
            }

            if (str_starts_with($rule, 'extensions:') || str_starts_with($rule, 'mimetypes:')) {
                continue;
            }

            $filtered[] = $rule;
        }

        return $filtered;
    }
}
