<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

use EvanSchleret\FormForge\Exceptions\InvalidFieldDefinitionException;

final class FormSchemaLayout
{
    public const TARGET_PAGE = 'page';
    public const TARGET_FIELD = 'field';

    public const ACTION_SHOW = 'show';
    public const ACTION_HIDE = 'hide';
    public const ACTION_SKIP = 'skip';
    public const ACTION_REQUIRE = 'require';
    public const ACTION_DISABLE = 'disable';

    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    public const OP_EQ = 'eq';
    public const OP_NEQ = 'neq';
    public const OP_IN = 'in';
    public const OP_NOT_IN = 'not_in';
    public const OP_GT = 'gt';
    public const OP_GTE = 'gte';
    public const OP_LT = 'lt';
    public const OP_LTE = 'lte';
    public const OP_CONTAINS = 'contains';
    public const OP_NOT_CONTAINS = 'not_contains';
    public const OP_IS_EMPTY = 'is_empty';
    public const OP_NOT_EMPTY = 'not_empty';

    public static function normalize(array $schema): array
    {
        $key = trim((string) ($schema['key'] ?? ''));
        $version = trim((string) ($schema['version'] ?? ''));
        $title = trim((string) ($schema['title'] ?? $key));

        $pagesRaw = self::normalizePagesInput(
            pages: $schema['pages'] ?? null,
            fields: $schema['fields'] ?? null,
            title: $title,
            key: $key,
            version: $version,
        );

        $pageKeys = [];
        $fieldNames = [];
        $fieldKeys = [];
        $fieldPageMap = [];
        $fieldNameToKey = [];
        $pages = [];

        foreach ($pagesRaw as $pageIndex => $pageRaw) {
            if (! is_array($pageRaw)) {
                throw new InvalidFieldDefinitionException('Each page must be an object.');
            }

            $pageKey = self::normalizeKeyValue($pageRaw['page_key'] ?? null, "pg_{$pageIndex}_{$key}");

            if (in_array($pageKey, $pageKeys, true)) {
                throw new InvalidFieldDefinitionException("Duplicate page_key [{$pageKey}].");
            }

            $pageKeys[] = $pageKey;

            $pageTitle = trim((string) ($pageRaw['title'] ?? ''));

            if ($pageTitle === '') {
                $pageTitle = 'Page ' . ($pageIndex + 1);
            }

            $fieldsRaw = self::normalizePageFieldsInput($pageRaw);
            $fields = [];

            foreach ($fieldsRaw as $fieldIndex => $fieldRaw) {
                if (! is_array($fieldRaw)) {
                    throw new InvalidFieldDefinitionException("Page [{$pageKey}] contains an invalid field entry.");
                }

                $name = trim((string) ($fieldRaw['name'] ?? ''));
                $type = trim((string) ($fieldRaw['type'] ?? ''));

                if ($name === '' || $type === '') {
                    throw new InvalidFieldDefinitionException("Page [{$pageKey}] fields must contain name and type.");
                }

                if (in_array($name, $fieldNames, true)) {
                    throw new InvalidFieldDefinitionException("Duplicate field name [{$name}] in schema.");
                }

                $fieldNames[] = $name;
                $fieldKeyDefault = self::defaultFieldKey($key, $name, $fieldIndex);
                $fieldKey = self::normalizeKeyValue($fieldRaw['field_key'] ?? null, $fieldKeyDefault);

                if (in_array($fieldKey, $fieldKeys, true)) {
                    throw new InvalidFieldDefinitionException("Duplicate field_key [{$fieldKey}] in schema.");
                }

                $fieldKeys[] = $fieldKey;
                $fieldPageMap[$fieldKey] = $pageKey;
                $fieldNameToKey[$name] = $fieldKey;

                $field = $fieldRaw;
                $field['field_key'] = $fieldKey;
                $field['page_key'] = $pageKey;
                unset($field['section_key']);
                $field['required'] = (bool) ($fieldRaw['required'] ?? false);
                $field['disabled'] = (bool) ($fieldRaw['disabled'] ?? false);
                unset($field['nullable'], $field['readonly']);

                if (! isset($field['label']) || trim((string) $field['label']) === '') {
                    $field['label'] = ucfirst(str_replace('_', ' ', $name));
                }

                if (! isset($field['meta']) || ! is_array($field['meta'])) {
                    $field['meta'] = [];
                }

                if (! isset($field['rules']) || ! is_array($field['rules'])) {
                    $field['rules'] = [];
                }

                $fields[] = $field;
            }

            $pages[] = [
                'page_key' => $pageKey,
                'title' => $pageTitle,
                'description' => self::normalizeOptionalString($pageRaw['description'] ?? null),
                'meta' => is_array($pageRaw['meta'] ?? null) ? $pageRaw['meta'] : [],
                'fields' => $fields,
            ];
        }

        $flatFields = self::flattenFieldsFromPages($pages);

        $conditions = self::normalizeConditions(
            conditions: $schema['conditions'] ?? [],
            pageKeys: $pageKeys,
            fieldKeys: $fieldKeys,
            fieldPageMap: $fieldPageMap,
            fieldNameToKey: $fieldNameToKey,
        );

        $normalized = $schema;
        $normalized['title'] = $title;
        $normalized['pages'] = $pages;
        $normalized['fields'] = $flatFields;
        $normalized['conditions'] = $conditions;
        $normalized['drafts'] = self::normalizeDrafts($schema['drafts'] ?? []);

        return $normalized;
    }

    public static function resolve(array $schema, array $payload = [], bool $debug = false): array
    {
        $normalized = self::normalize($schema);
        $conditions = $normalized['conditions'] ?? [];
        $pages = $normalized['pages'] ?? [];

        $pageVisible = [];
        $pageHiddenHard = [];
        $fieldVisible = [];
        $fieldHiddenHard = [];
        $fieldRequired = [];
        $fieldDisabled = [];
        $fieldNameByKey = [];
        $fieldsByPage = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageKey = (string) ($page['page_key'] ?? '');

            if ($pageKey === '') {
                continue;
            }

            $pageVisible[$pageKey] = true;
            $pageHiddenHard[$pageKey] = false;
            $fieldsByPage[$pageKey] = [];

            foreach ((array) ($page['fields'] ?? []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $fieldKey = (string) ($field['field_key'] ?? '');
                $fieldName = (string) ($field['name'] ?? '');

                if ($fieldKey === '' || $fieldName === '') {
                    continue;
                }

                $fieldsByPage[$pageKey][] = $fieldKey;
                $fieldNameByKey[$fieldKey] = $fieldName;
                $fieldVisible[$fieldKey] = true;
                $fieldHiddenHard[$fieldKey] = false;
                $fieldRequired[$fieldKey] = (bool) ($field['required'] ?? false);
                $fieldDisabled[$fieldKey] = (bool) ($field['disabled'] ?? false);
            }
        }

        $conditionDebug = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $targetType = (string) ($condition['target_type'] ?? '');
            $targetKey = (string) ($condition['target_key'] ?? '');
            $action = (string) ($condition['action'] ?? '');
            $matchMode = (string) ($condition['match'] ?? self::MATCH_ALL);
            $clauses = $condition['when'] ?? [];

            if (! is_array($clauses) || $clauses === []) {
                continue;
            }

            $evaluations = [];

            foreach ($clauses as $clause) {
                if (! is_array($clause)) {
                    continue;
                }

                $fieldKey = (string) ($clause['field_key'] ?? '');
                $operator = (string) ($clause['operator'] ?? self::OP_EQ);
                $expected = $clause['value'] ?? null;
                $fieldName = $fieldNameByKey[$fieldKey] ?? null;
                $actual = $fieldName !== null ? ($payload[$fieldName] ?? null) : null;
                $result = self::evaluate($actual, $operator, $expected);

                $evaluations[] = $result;
            }

            if ($evaluations === []) {
                continue;
            }

            $matched = $matchMode === self::MATCH_ANY
                ? in_array(true, $evaluations, true)
                : ! in_array(false, $evaluations, true);

            if ($debug) {
                $conditionDebug[] = [
                    'condition_key' => (string) ($condition['condition_key'] ?? ''),
                    'target_type' => $targetType,
                    'target_key' => $targetKey,
                    'action' => $action,
                    'matched' => $matched,
                    'evaluations' => $evaluations,
                ];
            }

            if (! $matched) {
                continue;
            }

            self::applyAction(
                targetType: $targetType,
                targetKey: $targetKey,
                action: $action,
                pageVisible: $pageVisible,
                pageHiddenHard: $pageHiddenHard,
                fieldVisible: $fieldVisible,
                fieldHiddenHard: $fieldHiddenHard,
                fieldRequired: $fieldRequired,
                fieldDisabled: $fieldDisabled,
                fieldsByPage: $fieldsByPage,
            );
        }

        $effectivePages = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageKey = (string) ($page['page_key'] ?? '');

            if ($pageKey === '' || ! ($pageVisible[$pageKey] ?? false)) {
                continue;
            }

            $effectiveFields = [];

            foreach ((array) ($page['fields'] ?? []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $fieldKey = (string) ($field['field_key'] ?? '');

                if ($fieldKey === '' || ! ($fieldVisible[$fieldKey] ?? false)) {
                    continue;
                }

                $effectiveField = $field;
                $effectiveField['required'] = (bool) ($fieldRequired[$fieldKey] ?? false);
                $effectiveField['disabled'] = (bool) ($fieldDisabled[$fieldKey] ?? false);
                $effectiveFields[] = $effectiveField;
            }

            $effectivePage = $page;
            $effectivePage['fields'] = $effectiveFields;
            $effectivePages[] = $effectivePage;
        }

        $resolved = $normalized;
        $resolved['pages'] = $effectivePages;
        $resolved['fields'] = self::flattenFieldsFromPages($effectivePages);

        if ($debug) {
            $resolved['debug'] = [
                'conditions' => $conditionDebug,
                'visible' => [
                    'pages' => array_values(array_keys(array_filter($pageVisible, static fn (bool $state): bool => $state))),
                    'fields' => array_values(array_keys(array_filter($fieldVisible, static fn (bool $state): bool => $state))),
                ],
            ];
        }

        return $resolved;
    }

    public static function flattenFields(array $schema): array
    {
        return self::flattenFieldsFromPages((array) ($schema['pages'] ?? []));
    }

    private static function normalizePagesInput(mixed $pages, mixed $fields, string $title, string $key, string $version): array
    {
        if (is_array($pages) && $pages !== []) {
            return $pages;
        }

        if (! is_array($fields) || $fields === []) {
            return [];
        }

        return [[
            'page_key' => self::defaultPageKey($key, $version),
            'title' => $title,
            'fields' => $fields,
        ]];
    }

    private static function normalizePageFieldsInput(array $pageRaw): array
    {
        $fields = $pageRaw['fields'] ?? null;

        if (is_array($fields)) {
            return $fields;
        }

        $sections = $pageRaw['sections'] ?? null;

        if (! is_array($sections) || $sections === []) {
            return [];
        }

        $flattened = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionFields = $section['fields'] ?? [];

            if (! is_array($sectionFields)) {
                continue;
            }

            foreach ($sectionFields as $field) {
                if (is_array($field)) {
                    $flattened[] = $field;
                }
            }
        }

        return $flattened;
    }

    private static function normalizeConditions(
        mixed $conditions,
        array $pageKeys,
        array $fieldKeys,
        array $fieldPageMap,
        array $fieldNameToKey,
    ): array {
        if (! is_array($conditions)) {
            return [];
        }

        $normalized = [];

        foreach ($conditions as $index => $conditionRaw) {
            if (! is_array($conditionRaw)) {
                throw new InvalidFieldDefinitionException('Each condition must be an object.');
            }

            $targetType = trim((string) ($conditionRaw['target_type'] ?? ($conditionRaw['target']['type'] ?? '')));
            $targetKey = trim((string) ($conditionRaw['target_key'] ?? ($conditionRaw['target']['key'] ?? '')));
            $action = trim((string) ($conditionRaw['action'] ?? ''));
            $match = trim((string) ($conditionRaw['match'] ?? self::MATCH_ALL));

            if (! in_array($targetType, [self::TARGET_PAGE, self::TARGET_FIELD], true)) {
                throw new InvalidFieldDefinitionException("Condition [{$index}] target_type is invalid.");
            }

            if ($targetType === self::TARGET_PAGE && ! in_array($targetKey, $pageKeys, true)) {
                throw new InvalidFieldDefinitionException("Condition [{$index}] targets unknown page_key [{$targetKey}].");
            }

            if ($targetType === self::TARGET_FIELD && ! in_array($targetKey, $fieldKeys, true)) {
                throw new InvalidFieldDefinitionException("Condition [{$index}] targets unknown field_key [{$targetKey}].");
            }

            if (! in_array($action, [self::ACTION_SHOW, self::ACTION_HIDE, self::ACTION_SKIP, self::ACTION_REQUIRE, self::ACTION_DISABLE], true)) {
                throw new InvalidFieldDefinitionException("Condition [{$index}] action [{$action}] is invalid.");
            }

            if (! in_array($match, [self::MATCH_ALL, self::MATCH_ANY], true)) {
                throw new InvalidFieldDefinitionException("Condition [{$index}] match [{$match}] is invalid.");
            }

            $clausesRaw = $conditionRaw['when'] ?? ($conditionRaw['rules'] ?? []);

            if (! is_array($clausesRaw) || $clausesRaw === []) {
                throw new InvalidFieldDefinitionException("Condition [{$index}] must define at least one rule in when[].");
            }

            $clauses = [];

            foreach ($clausesRaw as $clauseIndex => $clauseRaw) {
                if (! is_array($clauseRaw)) {
                    throw new InvalidFieldDefinitionException("Condition [{$index}] rule [{$clauseIndex}] is invalid.");
                }

                $fieldKey = trim((string) ($clauseRaw['field_key'] ?? ''));

                if ($fieldKey === '') {
                    $fieldName = trim((string) ($clauseRaw['field'] ?? ($clauseRaw['name'] ?? '')));
                    $fieldKey = $fieldNameToKey[$fieldName] ?? '';
                }

                if ($fieldKey === '' || ! in_array($fieldKey, $fieldKeys, true)) {
                    throw new InvalidFieldDefinitionException("Condition [{$index}] rule [{$clauseIndex}] references an unknown field.");
                }

                $operator = trim((string) ($clauseRaw['operator'] ?? ($clauseRaw['op'] ?? self::OP_EQ)));

                if (! in_array($operator, [
                    self::OP_EQ,
                    self::OP_NEQ,
                    self::OP_IN,
                    self::OP_NOT_IN,
                    self::OP_GT,
                    self::OP_GTE,
                    self::OP_LT,
                    self::OP_LTE,
                    self::OP_CONTAINS,
                    self::OP_NOT_CONTAINS,
                    self::OP_IS_EMPTY,
                    self::OP_NOT_EMPTY,
                ], true)) {
                    throw new InvalidFieldDefinitionException("Condition [{$index}] rule [{$clauseIndex}] operator [{$operator}] is invalid.");
                }

                if ($targetType === self::TARGET_FIELD && $targetKey === $fieldKey) {
                    throw new InvalidFieldDefinitionException("Condition [{$index}] cannot self-reference target field [{$targetKey}].");
                }

                if (
                    $targetType === self::TARGET_PAGE
                    && in_array($action, [self::ACTION_HIDE, self::ACTION_SKIP, self::ACTION_SHOW], true)
                    && ($fieldPageMap[$fieldKey] ?? null) === $targetKey
                ) {
                    throw new InvalidFieldDefinitionException("Condition [{$index}] creates a page cycle on [{$targetKey}].");
                }

                $clauses[] = [
                    'field_key' => $fieldKey,
                    'operator' => $operator,
                    'value' => $clauseRaw['value'] ?? null,
                ];
            }

            $conditionKey = self::normalizeKeyValue(
                $conditionRaw['condition_key'] ?? null,
                'cd_' . substr(hash('sha256', $index . '|' . $targetType . '|' . $targetKey), 0, 12),
            );

            $normalized[] = [
                'condition_key' => $conditionKey,
                'target_type' => $targetType,
                'target_key' => $targetKey,
                'action' => $action,
                'match' => $match,
                'when' => $clauses,
            ];
        }

        return $normalized;
    }

    private static function applyAction(
        string $targetType,
        string $targetKey,
        string $action,
        array &$pageVisible,
        array &$pageHiddenHard,
        array &$fieldVisible,
        array &$fieldHiddenHard,
        array &$fieldRequired,
        array &$fieldDisabled,
        array $fieldsByPage,
    ): void {
        if ($targetType === self::TARGET_PAGE) {
            if ($action === self::ACTION_HIDE || $action === self::ACTION_SKIP) {
                $pageVisible[$targetKey] = false;
                $pageHiddenHard[$targetKey] = true;

                return;
            }

            if ($action === self::ACTION_SHOW) {
                if (! ($pageHiddenHard[$targetKey] ?? false)) {
                    $pageVisible[$targetKey] = true;
                }

                return;
            }

            foreach ($fieldsByPage[$targetKey] ?? [] as $fieldKey) {
                if ($action === self::ACTION_REQUIRE) {
                    $fieldRequired[$fieldKey] = true;
                    continue;
                }

                if ($action === self::ACTION_DISABLE) {
                    $fieldDisabled[$fieldKey] = true;
                }
            }

            return;
        }

        if ($action === self::ACTION_HIDE || $action === self::ACTION_SKIP) {
            $fieldVisible[$targetKey] = false;
            $fieldHiddenHard[$targetKey] = true;

            return;
        }

        if ($action === self::ACTION_SHOW) {
            if (! ($fieldHiddenHard[$targetKey] ?? false)) {
                $fieldVisible[$targetKey] = true;
            }

            return;
        }

        if ($action === self::ACTION_REQUIRE) {
            $fieldRequired[$targetKey] = true;

            return;
        }

        if ($action === self::ACTION_DISABLE) {
            $fieldDisabled[$targetKey] = true;
        }
    }

    private static function evaluate(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            self::OP_EQ => $actual === $expected,
            self::OP_NEQ => $actual !== $expected,
            self::OP_IN => is_array($expected) && in_array($actual, $expected, true),
            self::OP_NOT_IN => is_array($expected) && ! in_array($actual, $expected, true),
            self::OP_GT => self::toComparableNumber($actual) > self::toComparableNumber($expected),
            self::OP_GTE => self::toComparableNumber($actual) >= self::toComparableNumber($expected),
            self::OP_LT => self::toComparableNumber($actual) < self::toComparableNumber($expected),
            self::OP_LTE => self::toComparableNumber($actual) <= self::toComparableNumber($expected),
            self::OP_CONTAINS => self::contains($actual, $expected),
            self::OP_NOT_CONTAINS => ! self::contains($actual, $expected),
            self::OP_IS_EMPTY => self::isEmpty($actual),
            self::OP_NOT_EMPTY => ! self::isEmpty($actual),
            default => false,
        };
    }

    private static function toComparableNumber(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return 0.0;
    }

    private static function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, $actual, true);
        }

        if (is_string($actual) && is_string($expected)) {
            return str_contains($actual, $expected);
        }

        return false;
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private static function flattenFieldsFromPages(array $pages): array
    {
        $fields = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            foreach ((array) ($page['fields'] ?? []) as $field) {
                if (is_array($field)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    private static function normalizeDrafts(mixed $drafts): array
    {
        if (! is_array($drafts)) {
            return [
                'enabled' => (bool) config('formforge.drafts.default_enabled', false),
            ];
        }

        return [
            'enabled' => (bool) ($drafts['enabled'] ?? config('formforge.drafts.default_enabled', false)),
        ];
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalizeKeyValue(mixed $value, string $fallback): string
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    private static function defaultFieldKey(string $formKey, string $name, int $index): string
    {
        return 'fk_' . substr(hash('sha256', $formKey . '|' . $name . '|' . $index), 0, 12);
    }

    private static function defaultPageKey(string $key, string $version): string
    {
        return 'pg_' . substr(hash('sha256', $key . '|' . $version . '|page|0'), 0, 10);
    }
}
