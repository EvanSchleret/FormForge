<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Management;

use EvanSchleret\FormForge\Definition\FormBlueprint;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\Models\FormCategory;
use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Ownership\OwnershipManager;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use EvanSchleret\FormForge\Support\FormSchemaLayout;
use EvanSchleret\FormForge\Support\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FormMutationService
{
    public function __construct(
        private readonly FormDefinitionRepository $repository,
        private readonly FormCategoryService $categories,
        private readonly OwnershipManager $ownership,
    ) {
    }

    public function create(array $input, ?Model $actor = null, ?OwnershipReference $owner = null): FormDefinition
    {
        $title = trim((string) ($input['title'] ?? ''));
        $fields = $input['fields'] ?? [];
        $pages = $input['pages'] ?? [];
        $conditions = $input['conditions'] ?? [];
        $drafts = $input['drafts'] ?? [];
        $categoryModel = array_key_exists('category', $input)
            ? $this->resolveOptionalCategoryModel($input['category'], $owner)
            : $this->categories->ensureForForms(null, $owner);
        $api = $this->normalizedArray($input['api'] ?? []);
        $meta = $this->normalizedArray($input['meta'] ?? []);
        $key = $this->buildGeneratedKey();
        $versionNumber = 1;
        $formUuid = $key;

        $schema = $this->normalizeSchema(
            key: $key,
            versionNumber: $versionNumber,
            title: $title,
            fields: $fields,
            pages: $pages,
            conditions: $conditions,
            drafts: $drafts,
            category: $categoryModel?->key,
            api: $api,
            published: false,
        );

        return DB::transaction(function () use ($key, $schema, $versionNumber, $formUuid, $actor, $meta, $categoryModel, $owner): FormDefinition {
            return $this->createRevision(
                key: $key,
                schema: $schema,
                formUuid: $formUuid,
                versionNumber: $versionNumber,
                published: false,
                actor: $actor,
                meta: $meta,
                category: $categoryModel,
                owner: $owner,
            );
        });
    }

    public function patch(string $key, array $input, ?Model $actor = null, ?OwnershipReference $owner = null): FormDefinition
    {
        $latest = $this->activeOrFail($key, $owner);
        $currentSchema = is_array($latest->schema) ? $latest->schema : [];
        $effectiveOwner = $owner ?? $this->ownership->fromModel($latest);
        $nextVersion = $this->repository->nextVersionNumber($key, true, $effectiveOwner);

        $title = array_key_exists('title', $input)
            ? trim((string) $input['title'])
            : (string) ($currentSchema['title'] ?? '');

        $fields = array_key_exists('fields', $input)
            ? $this->normalizedArray($input['fields'])
            : ($currentSchema['fields'] ?? []);

        $pages = array_key_exists('pages', $input)
            ? $this->normalizedArray($input['pages'])
            : $this->normalizedArray($currentSchema['pages'] ?? []);

        $conditions = array_key_exists('conditions', $input)
            ? $this->normalizedArray($input['conditions'])
            : $this->normalizedArray($currentSchema['conditions'] ?? []);

        $drafts = array_key_exists('drafts', $input)
            ? $this->normalizedArray($input['drafts'])
            : $this->normalizedArray($currentSchema['drafts'] ?? []);

        $categoryModel = array_key_exists('category', $input)
            ? $this->resolveOptionalCategoryModel($input['category'], $effectiveOwner)
            : $this->resolveCurrentCategoryModel($latest, $currentSchema, $effectiveOwner);

        $api = array_key_exists('api', $input)
            ? $this->normalizedArray($input['api'])
            : $this->normalizedArray($currentSchema['api'] ?? []);

        $meta = array_key_exists('meta', $input)
            ? $this->normalizedArray($input['meta'])
            : $this->normalizedArray($latest->meta ?? []);

        $schema = $this->normalizeSchema(
            key: $key,
            versionNumber: $nextVersion,
            title: $title,
            fields: $fields,
            pages: $pages,
            conditions: $conditions,
            drafts: $drafts,
            category: $categoryModel?->key,
            api: $api,
            published: false,
        );

        return DB::transaction(function () use ($latest, $schema, $nextVersion, $actor, $meta, $categoryModel, $effectiveOwner): FormDefinition {
            return $this->createRevision(
                key: (string) $latest->key,
                schema: $schema,
                formUuid: (string) $latest->form_uuid,
                versionNumber: $nextVersion,
                published: false,
                actor: $actor,
                meta: $meta,
                category: $categoryModel,
                owner: $effectiveOwner,
            );
        });
    }

    public function publish(string $key, ?Model $actor = null, ?OwnershipReference $owner = null): FormDefinition
    {
        $latest = $this->activeOrFail($key, $owner);

        if ((bool) $latest->is_published) {
            return $latest;
        }

        $currentSchema = is_array($latest->schema) ? $latest->schema : [];
        $title = trim((string) ($currentSchema['title'] ?? ''));
        $pages = $this->normalizedArray($currentSchema['pages'] ?? []);
        $fields = $currentSchema['fields'] ?? [];

        if (! $this->isPublishable($title, $pages, $fields)) {
            throw new FormForgeException('Form cannot be published: title, at least one page, and one field are required.');
        }

        $effectiveOwner = $owner ?? $this->ownership->fromModel($latest);
        $nextVersion = $this->repository->nextVersionNumber($key, true, $effectiveOwner);

        $categoryModel = $this->resolveCurrentCategoryModel($latest, $currentSchema, $effectiveOwner);
        $schema = $this->normalizeSchema(
            key: (string) $latest->key,
            versionNumber: $nextVersion,
            title: $title,
            fields: $fields,
            pages: $pages,
            conditions: $this->normalizedArray($currentSchema['conditions'] ?? []),
            drafts: $this->normalizedArray($currentSchema['drafts'] ?? []),
            category: $categoryModel?->key,
            api: $this->normalizedArray($currentSchema['api'] ?? []),
            published: true,
        );

        return DB::transaction(function () use ($latest, $schema, $nextVersion, $actor, $categoryModel, $effectiveOwner): FormDefinition {
            return $this->createRevision(
                key: (string) $latest->key,
                schema: $schema,
                formUuid: (string) $latest->form_uuid,
                versionNumber: $nextVersion,
                published: true,
                actor: $actor,
                meta: $this->normalizedArray($latest->meta ?? []),
                category: $categoryModel,
                owner: $effectiveOwner,
            );
        });
    }

    public function unpublish(string $key, ?Model $actor = null, ?OwnershipReference $owner = null): FormDefinition
    {
        $latest = $this->activeOrFail($key, $owner);

        if (! (bool) $latest->is_published) {
            return $latest;
        }

        $currentSchema = is_array($latest->schema) ? $latest->schema : [];
        $effectiveOwner = $owner ?? $this->ownership->fromModel($latest);
        $nextVersion = $this->repository->nextVersionNumber($key, true, $effectiveOwner);

        $categoryModel = $this->resolveCurrentCategoryModel($latest, $currentSchema, $effectiveOwner);
        $schema = $this->normalizeSchema(
            key: (string) $latest->key,
            versionNumber: $nextVersion,
            title: trim((string) ($currentSchema['title'] ?? '')),
            fields: $currentSchema['fields'] ?? [],
            pages: $this->normalizedArray($currentSchema['pages'] ?? []),
            conditions: $this->normalizedArray($currentSchema['conditions'] ?? []),
            drafts: $this->normalizedArray($currentSchema['drafts'] ?? []),
            category: $categoryModel?->key,
            api: $this->normalizedArray($currentSchema['api'] ?? []),
            published: false,
        );

        return DB::transaction(function () use ($latest, $schema, $nextVersion, $actor, $categoryModel, $effectiveOwner): FormDefinition {
            return $this->createRevision(
                key: (string) $latest->key,
                schema: $schema,
                formUuid: (string) $latest->form_uuid,
                versionNumber: $nextVersion,
                published: false,
                actor: $actor,
                meta: $this->normalizedArray($latest->meta ?? []),
                category: $categoryModel,
                owner: $effectiveOwner,
            );
        });
    }

    public function softDelete(string $key, ?Model $actor = null, ?OwnershipReference $owner = null): int
    {
        $definitions = $this->repository->byKey($key, false, $owner);

        if ($definitions->isEmpty()) {
            throw FormNotFoundException::forKey($key);
        }

        $updates = [];
        $userType = $actor?->getMorphClass();
        $userId = $actor?->getKey();

        if ($userType !== null) {
            $updates['updated_by_type'] = $userType;
        }

        if ($userId !== null) {
            $updates['updated_by_id'] = (string) $userId;
        }

        if ($updates !== []) {
            $updateQuery = ModelClassResolver::formDefinition()::query()
                ->where('key', $key);
            $this->ownership->applyScope($updateQuery, $owner);
            $updateQuery->update($updates);
        }

        return $this->repository->softDeleteKey($key, $owner);
    }

    public function revisions(string $key, bool $includeDeleted = true, ?OwnershipReference $owner = null): array
    {
        $definitions = $this->repository->byKey($key, $includeDeleted, $owner);

        if ($definitions->isEmpty()) {
            throw FormNotFoundException::forKey($key);
        }

        return $definitions
            ->map(fn (FormDefinition $definition): array => $this->toSummaryArray($definition))
            ->values()
            ->all();
    }

    public function diff(string $key, int $fromVersion, int $toVersion, bool $includeDeleted = true, ?OwnershipReference $owner = null): array
    {
        $from = $this->repository->find($key, (string) $fromVersion, includeDeleted: $includeDeleted, owner: $owner);
        $to = $this->repository->find($key, (string) $toVersion, includeDeleted: $includeDeleted, owner: $owner);

        if (! $from instanceof FormDefinition) {
            throw FormNotFoundException::forKey($key, (string) $fromVersion);
        }

        if (! $to instanceof FormDefinition) {
            throw FormNotFoundException::forKey($key, (string) $toVersion);
        }

        $fromSchema = is_array($from->schema) ? $from->schema : [];
        $toSchema = is_array($to->schema) ? $to->schema : [];

        $fromFlat = $this->flatten($fromSchema);
        $toFlat = $this->flatten($toSchema);

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($toFlat as $path => $value) {
            if (! array_key_exists($path, $fromFlat)) {
                $added[] = ['path' => $path, 'value' => $value];
                continue;
            }

            if ($this->jsonValue($fromFlat[$path]) !== $this->jsonValue($value)) {
                $changed[] = [
                    'path' => $path,
                    'from' => $fromFlat[$path],
                    'to' => $value,
                ];
            }
        }

        foreach ($fromFlat as $path => $value) {
            if (! array_key_exists($path, $toFlat)) {
                $removed[] = ['path' => $path, 'value' => $value];
            }
        }

        return [
            'key' => $key,
            'from_version' => (int) $fromVersion,
            'to_version' => (int) $toVersion,
            'summary' => [
                'added' => count($added),
                'removed' => count($removed),
                'changed' => count($changed),
            ],
            'changes' => [
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
        ];
    }

    public function paginateActive(int $perPage = 15, array $filters = [], ?OwnershipReference $owner = null): LengthAwarePaginator
    {
        $query = $this->queryActive($filters, $owner);

        $paginator = $query->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(fn (FormDefinition $definition): array => $this->toDetailArray($definition))
                ->values(),
        );

        return $paginator;
    }

    public function queryActive(array $filters = [], ?OwnershipReference $owner = null): Builder
    {
        $query = ModelClassResolver::formDefinition()::query()
            ->where('is_active', true)
            ->with('categoryModel')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
        $this->ownership->applyScope($query, $owner);

        $category = $this->normalizedOptionalString($filters['category'] ?? null);

        if ($category !== null) {
            $query->where('category', $category);
        }

        $published = $this->normalizeOptionalBool($filters['is_published'] ?? null);

        if ($published !== null) {
            $query->where('is_published', $published);
        }

        return $query;
    }

    public function toDetailArray(FormDefinition $definition): array
    {
        $schema = is_array($definition->schema) ? $definition->schema : [];
        $meta = is_array($definition->meta) ? $definition->meta : [];
        $categoryModel = $definition->relationLoaded('categoryModel')
            ? $definition->getRelation('categoryModel')
            : $definition->categoryModel()->first();
        $schemaCategory = $this->normalizedOptionalString($schema['category'] ?? null);
        $legacyCategory = $this->normalizedOptionalString($definition->category ?? null);
        $categoryKey = $categoryModel instanceof FormCategory
            ? (string) $categoryModel->key
            : ($schemaCategory ?? $legacyCategory);

        return [
            'key' => (string) $definition->key,
            'form_uuid' => (string) ($definition->form_uuid ?? ''),
            'revision_id' => (string) ($definition->revision_id ?? ''),
            'version' => (string) $definition->version,
            'version_number' => (int) ($definition->version_number ?? 0),
            'title' => (string) ($definition->title ?? ''),
            'category' => $categoryKey,
            'category_item' => $categoryModel instanceof FormCategory ? $this->categories->toArray($categoryModel) : null,
            'is_active' => (bool) $definition->is_active,
            'is_published' => (bool) $definition->is_published,
            'published_at' => $definition->published_at?->toIso8601String(),
            'schema_hash' => (string) $definition->schema_hash,
            'schema' => $schema,
            'meta' => $meta,
            'created_by_type' => $definition->created_by_type,
            'created_by_id' => $definition->created_by_id,
            'updated_by_type' => $definition->updated_by_type,
            'updated_by_id' => $definition->updated_by_id,
            'owner_type' => $definition->owner_type,
            'owner_id' => $definition->owner_id,
            'created_at' => $definition->created_at?->toIso8601String(),
            'updated_at' => $definition->updated_at?->toIso8601String(),
            'deleted_at' => $definition->deleted_at?->toIso8601String(),
        ];
    }

    private function createRevision(
        string $key,
        array $schema,
        string $formUuid,
        int $versionNumber,
        bool $published,
        ?Model $actor,
        array $meta = [],
        ?FormCategory $category = null,
        ?OwnershipReference $owner = null,
    ): FormDefinition {
        $deactivate = ModelClassResolver::formDefinition()::query()
            ->where('key', $key);
        $this->ownership->applyScope($deactivate, $owner);
        $deactivate->update(['is_active' => false]);

        $publishedAt = $published ? Carbon::now() : null;

        $definition = ModelClassResolver::formDefinition()::query()->make([
            'key' => $key,
            'form_uuid' => $formUuid,
            'revision_id' => (string) Str::ulid(),
            'version' => (string) $versionNumber,
            'version_number' => $versionNumber,
            'title' => (string) ($schema['title'] ?? ''),
            'category' => (string) ($category?->key ?? ''),
            'form_category_id' => $category?->getKey(),
            'schema' => $schema,
            'meta' => $meta,
            'schema_hash' => Schema::hash($schema),
            'is_active' => true,
            'is_published' => $published,
            'published_at' => $publishedAt,
            'created_by_type' => $actor?->getMorphClass(),
            'created_by_id' => $actor?->getKey(),
            'updated_by_type' => $actor?->getMorphClass(),
            'updated_by_id' => $actor?->getKey(),
        ]);

        $this->ownership->assignToModel($definition, $owner);
        $definition->save();

        return $definition->refresh();
    }

    private function normalizeSchema(
        string $key,
        int $versionNumber,
        string $title,
        mixed $fields,
        mixed $pages,
        mixed $conditions,
        mixed $drafts,
        ?string $category,
        array $api,
        bool $published,
    ): array {
        if ($title === '') {
            throw new FormForgeException('Form title cannot be empty.');
        }

        if (! is_array($fields)) {
            throw new FormForgeException('Form fields payload must be an array.');
        }

        if (! is_array($pages)) {
            throw new FormForgeException('Form pages payload must be an array.');
        }

        if (! is_array($conditions)) {
            throw new FormForgeException('Form conditions payload must be an array.');
        }

        if (! is_array($drafts)) {
            throw new FormForgeException('Form drafts payload must be an object.');
        }

        $normalizedFields = $fields;

        if ($pages !== []) {
            $normalizedFields = FormSchemaLayout::flattenFields([
                'pages' => $pages,
            ]);
        }

        if ($pages === [] && $normalizedFields === []) {
            $normalizedFields = [[
                'type' => 'text',
                'name' => 'short_text',
                'required' => false,
            ]];
        }

        $schema = [
            'key' => $key,
            'version' => (string) $versionNumber,
            'title' => $title,
            'fields' => $normalizedFields,
            'is_published' => $published,
        ];

        if ($category !== null) {
            $schema['category'] = $category;
        }

        if ($api !== []) {
            $schema['api'] = $api;
        }

        $normalized = FormBlueprint::fromSchemaArray($schema)->toSchemaArray();
        $normalizedPages = $pages === []
            ? $this->normalizedArray($normalized['pages'] ?? [])
            : $this->applyNormalizedFieldsToPages($pages, $this->normalizedArray($normalized['fields'] ?? []));

        $normalized['pages'] = $normalizedPages;
        $normalized['conditions'] = $conditions;
        $normalized['drafts'] = $drafts;
        $normalized = FormSchemaLayout::normalize($normalized);
        $normalized['is_published'] = $published;

        return $normalized;
    }

    private function applyNormalizedFieldsToPages(array $pages, array $fields): array
    {
        $byName = [];
        $byFieldKey = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));
            $fieldKey = trim((string) ($field['field_key'] ?? ''));

            if ($name !== '') {
                $byName[$name] = $field;
            }

            if ($fieldKey !== '') {
                $byFieldKey[$fieldKey] = $field;
            }
        }

        $resolved = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $sourceFields = $this->normalizedArray($page['fields'] ?? []);

            if ($sourceFields === [] && is_array($page['sections'] ?? null)) {
                foreach ($this->normalizedArray($page['sections'] ?? []) as $section) {
                    if (! is_array($section)) {
                        continue;
                    }

                    foreach ($this->normalizedArray($section['fields'] ?? []) as $fieldRaw) {
                        if (is_array($fieldRaw)) {
                            $sourceFields[] = $fieldRaw;
                        }
                    }
                }
            }

            $resolvedFields = [];

            foreach ($sourceFields as $fieldRaw) {
                if (! is_array($fieldRaw)) {
                    continue;
                }

                $name = trim((string) ($fieldRaw['name'] ?? ''));
                $fieldKey = trim((string) ($fieldRaw['field_key'] ?? ''));
                $normalizedField = $byFieldKey[$fieldKey] ?? ($byName[$name] ?? null);

                if (! is_array($normalizedField)) {
                    continue;
                }

                $resolvedFields[] = array_merge($fieldRaw, $normalizedField);
            }

            $page['fields'] = $resolvedFields;
            unset($page['sections']);
            $resolved[] = $page;
        }

        return $resolved;
    }

    private function isPublishable(string $title, array $pages, mixed $fields): bool
    {
        if ($title === '') {
            return false;
        }

        if (! is_array($fields) || $fields === []) {
            return false;
        }

        if ($pages === []) {
            return false;
        }

        $hasFieldInPage = false;

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageFields = $page['fields'] ?? [];

            if (is_array($pageFields) && $pageFields !== []) {
                $hasFieldInPage = true;
                break;
            }
        }

        return $hasFieldInPage;
    }

    private function buildGeneratedKey(): string
    {
        do {
            $candidate = (string) Str::uuid();
        } while ($this->repository->keyExists($candidate, true));

        return $candidate;
    }

    private function activeOrFail(string $key, ?OwnershipReference $owner = null): FormDefinition
    {
        $latest = $this->repository->latestActive($key, false, $owner);

        if (! $latest instanceof FormDefinition) {
            throw FormNotFoundException::forKey($key);
        }

        return $latest;
    }

    private function normalizedArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function normalizedOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeOptionalBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function resolveOptionalCategoryModel(mixed $value, ?OwnershipReference $owner = null): ?FormCategory
    {
        $normalized = $this->normalizedOptionalString($value);

        if ($normalized === null) {
            return null;
        }

        return $this->categories->ensureForForms($normalized, $owner);
    }

    private function resolveCurrentCategoryModel(FormDefinition $definition, array $schema, ?OwnershipReference $owner = null): ?FormCategory
    {
        $loaded = $definition->relationLoaded('categoryModel')
            ? $definition->getRelation('categoryModel')
            : $definition->categoryModel()->first();

        if ($loaded instanceof FormCategory) {
            return $loaded;
        }

        $schemaCategory = $this->normalizedOptionalString($schema['category'] ?? null);

        if ($schemaCategory !== null) {
            return $this->categories->ensureForForms($schemaCategory, $owner);
        }

        $legacyCategory = $this->normalizedOptionalString($definition->category ?? null);

        if ($legacyCategory !== null) {
            return $this->categories->ensureForForms($legacyCategory, $owner);
        }

        return null;
    }

    private function toSummaryArray(FormDefinition $definition): array
    {
        return [
            'revision_id' => (string) ($definition->revision_id ?? ''),
            'version' => (string) $definition->version,
            'version_number' => (int) ($definition->version_number ?? 0),
            'is_active' => (bool) $definition->is_active,
            'is_published' => (bool) $definition->is_published,
            'published_at' => $definition->published_at?->toIso8601String(),
            'deleted_at' => $definition->deleted_at?->toIso8601String(),
            'created_at' => $definition->created_at?->toIso8601String(),
            'updated_at' => $definition->updated_at?->toIso8601String(),
        ];
    }

    private function flatten(mixed $value, string $prefix = ''): array
    {
        if (! is_array($value)) {
            return [$prefix === '' ? '$' : $prefix => $value];
        }

        if ($value === []) {
            return [$prefix === '' ? '$' : $prefix => []];
        }

        $result = [];

        foreach ($value as $key => $entry) {
            $path = $prefix === ''
                ? '$.' . (string) $key
                : $prefix . '.' . (string) $key;

            foreach ($this->flatten($entry, $path) as $entryPath => $entryValue) {
                $result[$entryPath] = $entryValue;
            }
        }

        return $result;
    }

    private function jsonValue(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : 'null';
    }
}
