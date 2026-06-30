<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

use EvanSchleret\FormForge\Definition\FieldType;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\UnknownFieldsException;

final class FormSchemaExportableFields
{
    public static function exportableFields(array $schema): array
    {
        return self::flattenExportableFields($schema);
    }

    public static function flattenExportableFields(array $schema): array
    {
        $normalized = FormSchemaLayout::normalize($schema);
        $fields = [];

        foreach ((array) ($normalized['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fields = array_merge($fields, self::flattenField($field));
        }

        return $fields;
    }

    public static function normalizeIdentifierValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return self::normalizeIdentifier((string) $value);
    }

    public static function resolveExportableField(array $schema, string $identifier): ?array
    {
        $needle = self::normalizeIdentifier($identifier);

        if ($needle === '') {
            return null;
        }

        foreach (self::flattenExportableFields($schema) as $field) {
            if (in_array($needle, $field['lookup_keys'] ?? [], true)) {
                return $field;
            }
        }

        return null;
    }

    public static function validateExportableHeaders(array $schema, array $headers): array
    {
        $fields = self::flattenExportableFields($schema);
        $lookup = self::lookupIndex($fields);
        $matched = [];
        $resolved = [];
        $unknown = [];
        $normalizedHeaders = [];

        foreach ($headers as $header) {
            if (! is_scalar($header)) {
                continue;
            }

            $normalized = self::normalizeIdentifier((string) $header);

            if ($normalized === '') {
                $unknown[] = $normalized;
                $normalizedHeaders[] = $normalized;
                continue;
            }

            $normalizedHeaders[] = $normalized;

            if (! isset($lookup[$normalized])) {
                $unknown[] = $normalized;
                continue;
            }

            $field = $lookup[$normalized];
            $resolved[$normalized] = $field;
            $matched[(string) ($field['id'] ?? $normalized)] = $field;
        }

        $missing = [];

        foreach ($fields as $field) {
            if (! (bool) ($field['required'] ?? false)) {
                continue;
            }

            $id = (string) ($field['id'] ?? '');

            if ($id === '' || isset($matched[$id])) {
                continue;
            }

            $missing[] = (string) ($field['path'] ?? $id);
        }

        return [
            'valid' => $unknown === [] && $missing === [],
            'headers' => $normalizedHeaders,
            'expected' => array_values(array_map(static fn (array $field): string => (string) ($field['path'] ?? ''), $fields)),
            'matched' => array_values($matched),
            'resolved' => $resolved,
            'missing' => $missing,
            'unknown' => $unknown,
        ];
    }

    public static function mapExportableRow(array $schema, array $row, bool $strict = true): array
    {
        $fields = self::flattenExportableFields($schema);
        $lookup = self::lookupIndex($fields);
        $payload = [];
        $unknown = [];
        $seen = [];

        foreach ($row as $header => $value) {
            if (! is_scalar($header)) {
                continue;
            }

            $normalized = self::normalizeIdentifier((string) $header);

            if ($normalized === '' || ! isset($lookup[$normalized])) {
                $unknown[] = $normalized;
                continue;
            }

            $field = $lookup[$normalized];
            $fieldId = (string) ($field['id'] ?? $normalized);
            $seen[$fieldId] ??= [];
            $seen[$fieldId][] = $normalized;

            if (($field['composite'] ?? false) === true) {
                $fieldName = (string) ($field['field_name'] ?? '');
                $subfield = $field['subfield'] ?? null;
                $subKey = is_array($subfield) ? (string) ($subfield['key'] ?? '') : '';

                if ($fieldName === '' || $subKey === '') {
                    continue;
                }

                if (! isset($payload[$fieldName]) || ! is_array($payload[$fieldName])) {
                    $payload[$fieldName] = [];
                }

                $payload[$fieldName][$subKey] = $value;
                continue;
            }

            $fieldName = (string) ($field['field_name'] ?? '');

            if ($fieldName === '') {
                continue;
            }

            $payload[$fieldName] = $value;
        }

        $conflicts = [];

        foreach ($seen as $fieldId => $keys) {
            $keys = array_values(array_unique($keys));

            if (count($keys) <= 1) {
                continue;
            }

            $conflicts[] = implode(' vs ', $keys);
        }

        if ($conflicts !== []) {
            throw new FormForgeException(trans('formforge::validation.conflicting_payload_keys', [
                'keys' => implode(', ', $conflicts),
            ]));
        }

        if ($strict && $unknown !== []) {
            throw UnknownFieldsException::fromFields(array_values(array_unique($unknown)));
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function flattenField(array $field): array
    {
        $type = FieldType::normalize((string) ($field['type'] ?? ''));
        $name = trim((string) ($field['name'] ?? ''));

        if ($name === '' || $type === '' || (bool) ($field['disabled'] ?? false)) {
            return [];
        }

        if ($type === FieldType::ADDRESS) {
            return self::flattenAddressField($field);
        }

        return [self::buildSimpleDescriptor($field, $type, $name)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function flattenAddressField(array $field): array
    {
        $fieldType = FieldType::normalize((string) ($field['type'] ?? ''));
        $name = trim((string) ($field['name'] ?? ''));

        if ($name === '') {
            return [];
        }

        $addressFields = $field['address_fields'] ?? [];

        if (! is_array($addressFields)) {
            $addressFields = [];
        }

        $flattened = [];

        foreach ($addressFields as $subfield) {
            if (! is_array($subfield)) {
                continue;
            }

            if (! (bool) ($subfield['visible'] ?? true)) {
                continue;
            }

            $subKey = trim((string) ($subfield['key'] ?? ''));

            if ($subKey === '') {
                continue;
            }

            $flattened[] = self::buildAddressDescriptor($field, $fieldType, $name, $subfield, $subKey);
        }

        return $flattened;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildSimpleDescriptor(array $field, string $type, string $name): array
    {
        $fieldKey = trim((string) ($field['field_key'] ?? ''));
        $label = self::normalizeLabel($field['label'] ?? null, $name);
        $rules = self::normalizeRuleList($field['rules'] ?? []);

        return [
            'id' => $fieldKey !== '' ? $fieldKey : $name,
            'path' => $name,
            'label' => $label,
            'type' => $type,
            'field_key' => $fieldKey !== '' ? $fieldKey : null,
            'field_name' => $name,
            'page_key' => self::nullableTrimmed($field['page_key'] ?? null),
            'required' => self::isRequiredField($field, $rules),
            'visible' => true,
            'disabled' => false,
            'composite' => false,
            'parent' => null,
            'subfield' => null,
            'rules' => $rules,
            'meta' => is_array($field['meta'] ?? null) ? $field['meta'] : [],
            'lookup_keys' => self::lookupKeys([
                $name,
                $fieldKey,
                (string) ($field['key'] ?? ''),
                (string) ($field['id'] ?? ''),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildAddressDescriptor(array $field, string $type, string $name, array $subfield, string $subKey): array
    {
        $fieldKey = trim((string) ($field['field_key'] ?? ''));
        $fieldLabel = self::normalizeLabel($field['label'] ?? null, $name);
        $subfieldLabel = self::normalizeLabel($subfield['label'] ?? null, $subKey);
        $fieldPath = $name . '.' . $subKey;
        $fieldId = trim(($fieldKey !== '' ? $fieldKey : $name) . '.' . $subKey);

        return [
            'id' => $fieldId,
            'path' => $fieldPath,
            'label' => $subfieldLabel !== '' ? $subfieldLabel : $fieldPath,
            'type' => $type,
            'field_key' => $fieldKey !== '' ? $fieldKey : null,
            'field_name' => $name,
            'page_key' => self::nullableTrimmed($field['page_key'] ?? null),
            'required' => (bool) ($subfield['required'] ?? false),
            'visible' => (bool) ($subfield['visible'] ?? true),
            'disabled' => (bool) ($field['disabled'] ?? false),
            'composite' => true,
            'parent' => [
                'id' => $fieldKey !== '' ? $fieldKey : $name,
                'path' => $name,
                'label' => $fieldLabel,
                'type' => $type,
                'field_key' => $fieldKey !== '' ? $fieldKey : null,
                'field_name' => $name,
            ],
            'subfield' => [
                'key' => $subKey,
                'label' => $subfieldLabel,
                'required' => (bool) ($subfield['required'] ?? false),
                'visible' => (bool) ($subfield['visible'] ?? true),
            ],
            'rules' => self::normalizeRuleList($subfield['rules'] ?? self::addressSubfieldRules($subfield)),
            'meta' => is_array($field['meta'] ?? null) ? $field['meta'] : [],
            'lookup_keys' => self::lookupKeys([
                $fieldPath,
                $fieldId,
            ]),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private static function lookupIndex(array $fields): array
    {
        $lookup = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            foreach ((array) ($field['lookup_keys'] ?? []) as $alias) {
                if (! is_string($alias) || $alias === '') {
                    continue;
                }

                $lookup[$alias] = $field;
            }
        }

        return $lookup;
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private static function lookupKeys(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $candidate = self::normalizeIdentifier($value);

            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeIdentifier(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value);

        return trim(is_string($value) ? $value : '');
    }

    private static function normalizeLabel(mixed $value, string $fallback): string
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value !== '') {
                return $value;
            }
        }

        return self::humanizeIdentifier($fallback);
    }

    private static function humanizeIdentifier(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = str_replace('_', ' ', $value);
        $value = preg_replace('/([a-zA-Z])([0-9]+)/', '$1 $2', $value) ?? $value;

        return ucfirst(trim($value));
    }

    /**
     * @param array<int, string> $rules
     * @return array<int, string>
     */
    private static function normalizeRuleList(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

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

        return array_values(array_unique($normalized));
    }

    private static function isRequiredField(array $field, array $rules): bool
    {
        if ((bool) ($field['required'] ?? false)) {
            return true;
        }

        return in_array('required', $rules, true);
    }

    private static function addressSubfieldRules(array $subfield): array
    {
        return (bool) ($subfield['required'] ?? false)
            ? ['required', 'string']
            : ['nullable', 'string'];
    }

    private static function nullableTrimmed(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
