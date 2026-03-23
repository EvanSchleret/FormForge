<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Definition\FieldType;
use EvanSchleret\FormForge\Exceptions\UnknownFieldsException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubmissionValidator
{
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
            $rules[$name . '.*'] = ['array'];
            $rules[$name . '.*.upload_token'] = ['nullable', 'string'];
            $rules[$name . '.*.path'] = ['required_without:' . $name . '.*.upload_token', 'string'];
            $rules[$name . '.*.disk'] = ['nullable', 'string'];
            $rules[$name . '.*.original_name'] = ['nullable', 'string'];
            $rules[$name . '.*.stored_name'] = ['nullable', 'string'];
            $rules[$name . '.*.mime_type'] = ['nullable', 'string'];
            $rules[$name . '.*.extension'] = ['nullable', 'string'];
            $rules[$name . '.*.size'] = ['nullable', 'integer', 'min:0'];
            $rules[$name . '.*.checksum'] = ['nullable', 'string'];
            $rules[$name . '.*.metadata'] = ['nullable', 'array'];

            return;
        }

        $rules[$name . '.upload_token'] = ['nullable', 'string'];
        $rules[$name . '.path'] = ['required_without:' . $name . '.upload_token', 'string'];
        $rules[$name . '.disk'] = ['nullable', 'string'];
        $rules[$name . '.original_name'] = ['nullable', 'string'];
        $rules[$name . '.stored_name'] = ['nullable', 'string'];
        $rules[$name . '.mime_type'] = ['nullable', 'string'];
        $rules[$name . '.extension'] = ['nullable', 'string'];
        $rules[$name . '.size'] = ['nullable', 'integer', 'min:0'];
        $rules[$name . '.checksum'] = ['nullable', 'string'];
        $rules[$name . '.metadata'] = ['nullable', 'array'];
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
