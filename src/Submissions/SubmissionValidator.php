<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use Closure;
use EvanSchleret\FormForge\Definition\FieldType;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\UnknownFieldsException;
use EvanSchleret\FormForge\Support\FormSchemaExportableFields;
use EvanSchleret\FormForge\Support\ValidationLocaleResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubmissionValidator
{
    public function __construct(
        private readonly ValidationLocaleResolver $localeResolver = new ValidationLocaleResolver(),
    ) {
    }

    public function exportableFields(array $schema): array
    {
        return FormSchemaExportableFields::exportableFields($schema);
    }

    public function flattenExportableFields(array $schema): array
    {
        return FormSchemaExportableFields::flattenExportableFields($schema);
    }

    public function resolveExportableField(array $schema, string $identifier): ?array
    {
        return FormSchemaExportableFields::resolveExportableField($schema, $identifier);
    }

    public function validateExportableHeaders(array $schema, array $headers): array
    {
        return FormSchemaExportableFields::validateExportableHeaders($schema, $headers);
    }

    public function mapExportableRow(array $schema, array $row, bool $strict = true): array
    {
        return FormSchemaExportableFields::mapExportableRow($schema, $row, $strict);
    }

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
                'temporal_mode' => $this->nullableTrimmed($field['temporal_mode'] ?? null),
                'required' => (bool) ($field['required'] ?? false),
                'rules' => $this->normalizeRules($field['rules'] ?? []),
                'options' => $this->normalizeOptions($field['options'] ?? []),
                'meta' => is_array($field['meta'] ?? null) ? $field['meta'] : [],
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

    public function validateField(array $schema, string $fieldName, mixed $value, ?string $locale = null): array
    {
        return $this->withLocale($locale, function () use ($schema, $fieldName, $value): array {
            $fieldName = trim($fieldName);

            if ($fieldName === '') {
                throw new FormForgeException(trans('formforge::validation.field_name_required'));
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
        });
    }

    public function validate(array $schema, array $payload, ?string $locale = null): array
    {
        $payload = $this->normalizeInputPayload($schema, $payload);
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

        return $this->withLocale($locale, static fn (): array => Validator::make($payload, $rules)->validate());
    }

    public function validateFields(array $schema, array $payload, array $onlyFields = [], ?string $locale = null): array
    {
        $payload = $this->normalizeInputPayload($schema, $payload);

        return $this->withLocale($locale, function () use ($schema, $payload, $onlyFields): array {
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
                        $errors[$identifier] = [trans('formforge::validation.unknown_field_identifier')];
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
        });
    }

    public function normalizeInputPayload(array $schema, array $payload): array
    {
        $mode = (string) config('formforge.validation.input_key_mode', 'both');
        $acceptName = in_array($mode, ['name_only', 'both'], true);
        $acceptFieldKey = in_array($mode, ['field_key_only', 'both'], true);

        $fieldByName = [];
        $nameByFieldKey = [];
        $globalAliasToName = [];
        $conflicts = [];

        foreach ($this->describeFields($schema) as $field) {
            $name = (string) ($field['name'] ?? '');
            $fieldKey = $field['field_key'] ?? null;

            if ($name === '') {
                continue;
            }

            $fieldByName[$name] = $field;

            if (is_string($fieldKey) && $fieldKey !== '') {
                $nameByFieldKey[$fieldKey] = $name;
            }

            foreach ($field['lookup_keys'] as $alias) {
                if (! isset($globalAliasToName[$alias])) {
                    $globalAliasToName[$alias] = $name;
                    continue;
                }

                if ($globalAliasToName[$alias] !== $name) {
                    $conflicts[] = $alias;
                }
            }
        }

        if ($conflicts !== []) {
            throw new FormForgeException(trans('formforge::validation.conflicting_payload_keys', [
                'keys' => implode(', ', array_values(array_unique($conflicts))),
            ]));
        }

        $normalized = [];
        $seenSourceKeys = [];

        foreach ($payload as $inputKey => $value) {
            if (! is_string($inputKey)) {
                continue;
            }

            $resolvedName = null;

            if ($acceptName && isset($fieldByName[$inputKey])) {
                $resolvedName = $inputKey;
            } elseif ($acceptFieldKey && isset($nameByFieldKey[$inputKey])) {
                $resolvedName = $nameByFieldKey[$inputKey];
            }

            if ($resolvedName === null) {
                $normalized[$inputKey] = $value;
                continue;
            }

            if (! isset($seenSourceKeys[$resolvedName])) {
                $seenSourceKeys[$resolvedName] = [];
            }

            $seenSourceKeys[$resolvedName][] = $inputKey;

            if ($acceptName && $inputKey === $resolvedName) {
                $normalized[$resolvedName] = $value;
                continue;
            }

            if (! array_key_exists($resolvedName, $normalized)) {
                $normalized[$resolvedName] = $value;
            }
        }

        $payloadConflicts = [];

        foreach ($seenSourceKeys as $name => $keys) {
            $keys = array_values(array_unique($keys));

            if (count($keys) <= 1) {
                continue;
            }

            if (in_array($name, $keys, true)) {
                continue;
            }

            $payloadConflicts[] = implode(' vs ', $keys);
        }

        if ($payloadConflicts !== []) {
            throw new FormForgeException(trans('formforge::validation.conflicting_payload_keys', [
                'keys' => implode(', ', $payloadConflicts),
            ]));
        }

        return $normalized;
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
            $type = FieldType::normalize((string) ($field['type'] ?? ''));

            if ($name === '' || $type === '') {
                continue;
            }

            $baseRules = $this->normalizeRules($field['rules'] ?? []);

            $temporalMode = (string) ($field['temporal_mode'] ?? FieldType::temporalMode((string) ($field['type'] ?? '')) ?? 'date');

            if ($type === FieldType::ADDRESS) {
                $this->compileAddressRules($rules, $name, $field, $baseRules);
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

            if ($this->supportsMetaValidation($type, $temporalMode)) {
                $baseRules = array_merge($baseRules, $this->compileMetaValidationRules($field, $temporalMode));
            }

            $rules[$name] = $baseRules;
        }

        return $rules;
    }

    private function compileAddressRules(array &$rules, string $name, array $field, array $baseRules): void
    {
        if (! in_array('array', $baseRules, true)) {
            $baseRules[] = 'array';
        }

        if ($this->supportsMetaValidation((string) ($field['type'] ?? ''))) {
            $baseRules = array_merge($baseRules, $this->compileMetaValidationRules($field));
        }

        $rules[$name] = $baseRules;

        $addressFields = $this->normalizeAddressFields($field['address_fields'] ?? null);

        foreach ($addressFields as $addressField) {
            if (! ($addressField['visible'] ?? true)) {
                continue;
            }

            $subKey = trim((string) ($addressField['key'] ?? ''));

            if ($subKey === '') {
                continue;
            }

            $subRules = ((bool) ($addressField['required'] ?? false))
                ? ['required', 'string']
                : ['nullable', 'string'];

            $rules[$name . '.' . $subKey] = $subRules;
        }
    }

    private function supportsMetaValidation(string $type, ?string $temporalMode = null): bool
    {
        if ($type === FieldType::TEXT || $type === FieldType::ADDRESS) {
            return true;
        }

        if ($type !== FieldType::TEMPORAL || ! is_string($temporalMode)) {
            return false;
        }

        return in_array($temporalMode, ['date', 'time'], true);
    }

    private function compileMetaValidationRules(array $field, ?string $temporalMode = null): array
    {
        $validation = $this->metaValidationConfig($field);

        if ($validation === null) {
            return [];
        }

        $fieldType = (string) ($field['type'] ?? '');

        return [function (string $attribute, mixed $value, Closure $fail) use ($validation, $fieldType, $field, $temporalMode): void {
            if ($fieldType !== FieldType::ADDRESS && $fieldType !== FieldType::TEMPORAL && ! is_string($value)) {
                return;
            }

            $results = [];

            foreach ($validation['rules'] as $rule) {
                $expectedValue = $value;

                if ($fieldType === FieldType::ADDRESS) {
                    $expectedValue = $this->addressRuleValue($value, $rule['target'] ?? null);
                } elseif ($fieldType === FieldType::TEMPORAL) {
                    $result = $this->evaluateTemporalMetaValidationRule($value, $rule, (string) ($temporalMode ?? 'date'));

                    if ($result !== null) {
                        $results[] = $result;
                    }

                    continue;
                }

                $result = $this->evaluateMetaValidationRule($expectedValue, $rule);

                if ($result !== null) {
                    $results[] = $result;
                }
            }

            if ($results === []) {
                return;
            }

            $matched = $validation['match'] === 'any'
                ? in_array(true, $results, true)
                : ! in_array(false, $results, true);

            if (! $matched) {
                $fail(trans('formforge::validation.field_validation_failed'));
            }
        }];
    }

    private function metaValidationConfig(array $field): ?array
    {
        $meta = $field['meta'] ?? null;

        if (! is_array($meta)) {
            return null;
        }

        $validation = $meta['validation'] ?? null;

        if (! is_array($validation)) {
            return null;
        }

        $match = $validation['match'] ?? null;
        if (! in_array($match, ['all', 'any'], true)) {
            return null;
        }

        $rules = $validation['rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            return null;
        }

        $normalizedRules = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $operator = trim((string) ($rule['operator'] ?? ''));
            $validationKey = trim((string) ($rule['validation_key'] ?? ''));

            if ($validationKey === '' || ! in_array($operator, ['min', 'max', 'after', 'before', 'between', 'not_between', 'regex', 'eq', 'neq', 'contains', 'not_contains'], true)) {
                continue;
            }

            $normalizedRules[] = [
                'validation_key' => $validationKey,
                'target' => isset($rule['target']) ? $this->nullableTrimmed($rule['target']) : null,
                'operator' => $operator,
                'value' => $rule['value'] ?? null,
                'unit' => $rule['unit'] ?? null,
            ];
        }

        if ($normalizedRules === []) {
            return null;
        }

        return [
            'match' => $match,
            'rules' => $normalizedRules,
        ];
    }

    private function evaluateMetaValidationRule(mixed $value, array $rule): ?bool
    {
        $operator = (string) ($rule['operator'] ?? '');
        $expected = $rule['value'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        if ($operator === 'min') {
            return $this->stringLength($value) >= $this->ruleNumberValue($expected, 0);
        }

        if ($operator === 'max') {
            return $this->stringLength($value) <= $this->ruleNumberValue($expected, 0);
        }

        if ($operator === 'eq') {
            return $value === $this->ruleStringValue($expected);
        }

        if ($operator === 'neq') {
            return $value !== $this->ruleStringValue($expected);
        }

        if ($operator === 'contains') {
            return str_contains($value, $this->ruleStringValue($expected));
        }

        if ($operator === 'not_contains') {
            return ! str_contains($value, $this->ruleStringValue($expected));
        }

        if ($operator === 'regex') {
            $pattern = $this->compileRegexPattern($this->ruleStringValue($expected));

            if ($pattern === null) {
                return null;
            }

            $result = @preg_match($pattern, $value);

            if ($result === false) {
                return null;
            }

            return $result === 1;
        }

        return null;
    }

    private function evaluateTemporalMetaValidationRule(mixed $value, array $rule, string $temporalMode): ?bool
    {
        if (! is_string($value)) {
            return null;
        }

        $operator = (string) ($rule['operator'] ?? '');
        $expected = $rule['value'] ?? null;

        $actualComparable = $this->temporalComparableValue($value, $temporalMode);

        if ($actualComparable === null) {
            return null;
        }

        if ($operator === 'between' || $operator === 'not_between') {
            $range = $this->temporalValidationRangeValue($expected);

            if ($range === null) {
                return null;
            }

            $startComparable = $this->temporalComparableValue($range['start'], $temporalMode);
            $endComparable = $this->temporalComparableValue($range['end'], $temporalMode);

            if ($startComparable === null || $endComparable === null) {
                return null;
            }

            $isBetween = $actualComparable >= $startComparable && $actualComparable <= $endComparable;

            return $operator === 'between' ? $isBetween : ! $isBetween;
        }

        $expectedComparable = $this->temporalComparableValue($this->ruleStringValue($expected), $temporalMode);

        if ($expectedComparable === null) {
            return null;
        }

        if ($operator === 'after') {
            return $actualComparable > $expectedComparable;
        }

        if ($operator === 'before') {
            return $actualComparable < $expectedComparable;
        }

        if ($operator === 'min') {
            return $actualComparable >= $expectedComparable;
        }

        if ($operator === 'max') {
            return $actualComparable <= $expectedComparable;
        }

        if ($operator === 'eq') {
            return $actualComparable === $expectedComparable;
        }

        if ($operator === 'neq') {
            return $actualComparable !== $expectedComparable;
        }

        return null;
    }

    private function addressRuleValue(mixed $value, mixed $target): mixed
    {
        if (! is_array($value) || ! is_string($target) || $target === '') {
            return null;
        }

        return $value[$target] ?? null;
    }

    private function temporalValidationRangeValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return [
                'start' => isset($value['start']) ? $this->ruleStringValue($value['start']) : '',
                'end' => isset($value['end']) ? $this->ruleStringValue($value['end']) : '',
            ];
        }

        return null;
    }

    private function temporalComparableValue(string $value, string $temporalMode): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if ($temporalMode === 'time') {
            if (! preg_match('/^(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,3}))?)?$/', $trimmed, $matches)) {
                return null;
            }

            $hours = (int) ($matches[1] ?? 0);
            $minutes = (int) ($matches[2] ?? 0);
            $seconds = (int) ($matches[3] ?? 0);
            $milliseconds = (int) str_pad((string) ($matches[4] ?? '0'), 3, '0');

            return ((($hours * 60) + $minutes) * 60 + $seconds) * 1000 + $milliseconds;
        }

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $matches)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]), 'UTC');

            return ($date->getTimestamp() * 1000) + intdiv((int) $date->format('u'), 1000);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ruleStringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    private function ruleNumberValue(mixed $value, int|float $fallback): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed !== '' && is_numeric($trimmed)) {
                return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
            }
        }

        return $fallback;
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function compileRegexPattern(string $pattern): ?string
    {
        foreach (['~', '#', '%', '!', '@', '/'] as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter . $pattern . $delimiter . 'u';
            }
        }

        return null;
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
        $this->compileMetadataFileRules($rules, $name, $field, $baseRules, $multiple);
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

            if (isset($field['max_total_size']) && (int) $field['max_total_size'] > 0) {
                $rules[$name][] = function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $total = array_reduce($value, static fn (int $sum, mixed $file): int => $sum + (int) ($file?->getSize() ?? 0), 0);

                    if ($total > (int) $field['max_total_size']) {
                        $fail(trans('formforge::messages.upload_max_total_size'));
                    }
                };
            }

            return;
        }

        if (! in_array('file', $baseRules, true)) {
            $baseRules[] = 'file';
        }

        $rules[$name] = array_values(array_unique([...$baseRules, ...$acceptRules, ...$sizeRules]));
    }

    private function compileMetadataFileRules(array &$rules, string $name, array $field, array $baseRules, bool $multiple): void
    {
        if (! in_array('array', $baseRules, true)) {
            $baseRules[] = 'array';
        }

        $rules[$name] = $baseRules;

        if ($multiple) {
            if (isset($field['max_files'])) {
                $baseRules[] = 'max:' . (int) $field['max_files'];
            }

            $rules[$name . '.*'] = ['exclude_without:' . $name, 'array'];
            $rules[$name . '.*.upload_token'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.path'] = ['exclude_without:' . $name, 'required_without:' . $name . '.*.upload_token', 'string'];
            $rules[$name . '.*.disk'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.original_name'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.stored_name'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.mime_type'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.extension'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $sizeRules = ['exclude_without:' . $name, 'nullable', 'integer', 'min:0'];
            if (isset($field['max_size']) && (int) $field['max_size'] > 0) {
                $sizeRules[] = 'max:' . (int) $field['max_size'];
            }
            $rules[$name . '.*.size'] = $sizeRules;
            $rules[$name . '.*.checksum'] = ['exclude_without:' . $name, 'nullable', 'string'];
            $rules[$name . '.*.metadata'] = ['exclude_without:' . $name, 'nullable', 'array'];

            if (isset($field['max_total_size']) && (int) $field['max_total_size'] > 0) {
                $rules[$name][] = function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $total = array_reduce($value, static fn (int $sum, mixed $item): int => $sum + (int) (is_array($item) ? ($item['size'] ?? 0) : 0), 0);

                    if ($total > (int) $field['max_total_size']) {
                        $fail(trans('formforge::messages.upload_max_total_size'));
                    }
                };
            }

            return;
        }

        $rules[$name . '.upload_token'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.path'] = ['exclude_without:' . $name, 'required_without:' . $name . '.upload_token', 'string'];
        $rules[$name . '.disk'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.original_name'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.stored_name'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.mime_type'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $rules[$name . '.extension'] = ['exclude_without:' . $name, 'nullable', 'string'];
        $sizeRules = ['exclude_without:' . $name, 'nullable', 'integer', 'min:0'];
        if (isset($field['max_size']) && (int) $field['max_size'] > 0) {
            $sizeRules[] = 'max:' . (int) $field['max_size'];
        }
        $rules[$name . '.size'] = $sizeRules;
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

    private function withLocale(?string $locale, callable $callback): mixed
    {
        $translator = app('translator');
        $previous = $translator->getLocale();
        $resolved = $this->localeResolver->resolve($locale);
        $translator->setLocale($resolved);

        try {
            return $callback();
        } finally {
            $translator->setLocale($previous);
        }
    }

    private function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values($options);
    }

    private function normalizeAddressFields(mixed $addressFields): array
    {
        $defaults = [
            ['key' => 'line1', 'label' => trans('formforge::messages.address_line1'), 'visible' => true, 'required' => false],
            ['key' => 'line2', 'label' => trans('formforge::messages.address_line2'), 'visible' => true, 'required' => false],
            ['key' => 'city', 'label' => trans('formforge::messages.address_city'), 'visible' => true, 'required' => false],
            ['key' => 'state', 'label' => trans('formforge::messages.address_state'), 'visible' => true, 'required' => false],
            ['key' => 'zip', 'label' => trans('formforge::messages.address_zip'), 'visible' => true, 'required' => false],
            ['key' => 'country', 'label' => trans('formforge::messages.address_country'), 'visible' => true, 'required' => false],
        ];

        if (! is_array($addressFields) || $addressFields === []) {
            return $defaults;
        }

        $normalized = [];

        foreach ($addressFields as $index => $addressField) {
            if (! is_array($addressField)) {
                continue;
            }

            $key = trim((string) ($addressField['key'] ?? ''));
            $hasLabel = array_key_exists('label', $addressField)
                && (is_string($addressField['label']) || $addressField['label'] === null);
            $label = $hasLabel ? trim((string) $addressField['label']) : '';

            if ($key === '') {
                $key = (string) ($defaults[$index]['key'] ?? "field_{$index}");
            }

            if (! $hasLabel) {
                $label = (string) ($defaults[$index]['label'] ?? $key);
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'visible' => (bool) ($addressField['visible'] ?? true),
                'required' => (bool) ($addressField['required'] ?? false),
            ];
        }

        return $normalized === [] ? $defaults : $normalized;
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

            if ((string) ($field['type'] ?? '') === FieldType::ADDRESS) {
                $required = $this->hasRequiredAddressField($field);
            }

            if ($name === '' || $required || ! array_key_exists($name, $payload)) {
                continue;
            }

            if ($this->hasRequiredAddressField($field) && $this->isNullishOptionalValue($payload[$name])) {
                continue;
            }

            if ($this->isNullishOptionalValue($payload[$name])) {
                unset($payload[$name]);
            }
        }

        return $payload;
    }

    private function hasRequiredAddressField(array $field): bool
    {
        if ((string) ($field['type'] ?? '') !== FieldType::ADDRESS) {
            return false;
        }

        $addressFields = $this->normalizeAddressFields($field['address_fields'] ?? null);

        foreach ($addressFields as $addressField) {
            if (! ($addressField['visible'] ?? true)) {
                continue;
            }

            if ((bool) ($addressField['required'] ?? false)) {
                return true;
            }
        }

        return false;
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
