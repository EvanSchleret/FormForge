<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Management;

use EvanSchleret\FormForge\Exceptions\FormConflictException;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormCategory;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use EvanSchleret\FormForge\Ownership\OwnershipManager;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class FormCategoryService
{
    public function __construct(
        private readonly OwnershipManager $ownership,
    ) {
    }

    private function categoryModelClass(): string
    {
        return ModelClassResolver::formCategory();
    }

    private function formDefinitionModelClass(): string
    {
        return ModelClassResolver::formDefinition();
    }

    public function paginate(int $perPage = 15, array $filters = [], ?OwnershipReference $owner = null): LengthAwarePaginator
    {
        $query = $this->categoryModelClass()::query()
            ->orderBy('key');
        $this->applyOwnerOrSystemScope($query, $owner);

        $search = $this->normalizedOptionalString($filters['search'] ?? null);

        if ($search !== null) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('key', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        $isActive = $this->normalizeOptionalBool($filters['is_active'] ?? null);

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->paginate($perPage);
    }

    public function findByKey(string $key, ?OwnershipReference $owner = null): ?FormCategory
    {
        $query = $this->categoryModelClass()::query()
            ->where('key', trim($key));
        $this->applyOwnerOrSystemScope($query, $owner);

        return $query->first();
    }

    public function findBySlug(string $slug, ?OwnershipReference $owner = null): ?FormCategory
    {
        $normalized = $this->normalizedSlug($slug);

        if ($normalized === null) {
            return null;
        }

        if ($owner instanceof OwnershipReference) {
            $owned = $this->categoryModelClass()::query()
                ->where('slug', $normalized)
                ->where('owner_type', $owner->type)
                ->where('owner_id', $owner->id)
                ->first();

            if ($owned instanceof FormCategory) {
                return $owned;
            }

            return $this->categoryModelClass()::query()
                ->where('slug', $normalized)
                ->where('is_system', true)
                ->whereNull('owner_type')
                ->whereNull('owner_id')
                ->first();
        }

        return $this->categoryModelClass()::query()
            ->where('slug', $normalized)
            ->first();
    }

    public function findByIdentifier(string $identifier, ?OwnershipReference $owner = null): ?FormCategory
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $byKey = $this->findByKey($identifier, $owner);

        if ($byKey instanceof FormCategory) {
            return $byKey;
        }

        return $this->findBySlug($identifier, $owner);
    }

    public function ensureForForms(?string $key, ?OwnershipReference $owner = null): ?FormCategory
    {
        $normalized = $this->normalizedOptionalString($key);

        if ($normalized === null) {
            return null;
        }

        $existing = $this->findByIdentifier($normalized, $owner);

        if ($existing instanceof FormCategory) {
            return $existing;
        }

        throw new FormForgeException("Category [{$normalized}] not found.");
    }

    public function create(array $input, ?OwnershipReference $owner = null): FormCategory
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new FormForgeException('Category name cannot be empty.');
        }

        $this->assertNameAllowed($name);

        $isSystem = false;

        if (array_key_exists('is_system', $input)) {
            $resolved = $this->normalizeOptionalBool($input['is_system']);

            if ($resolved === null) {
                throw new FormForgeException('Category is_system must be a boolean value.');
            }

            $isSystem = $resolved;
        }

        $requestedSlug = array_key_exists('slug', $input)
            ? $this->normalizedSlug($input['slug'])
            : null;

        if (array_key_exists('slug', $input) && $requestedSlug === null) {
            throw new FormForgeException('Category slug cannot be empty.');
        }

        $baseSlug = $requestedSlug ?? $this->normalizedSlug($name) ?? 'category';
        $slug = $this->uniqueSlug($baseSlug, owner: $owner);

        $category = $this->categoryModelClass()::query()->make([
            'key' => (string) Str::uuid(),
            'slug' => $slug,
            'name' => $name,
            'description' => $this->nullableDescription($input['description'] ?? null),
            'meta' => $this->normalizedArray($input['meta'] ?? []),
            'is_active' => $this->normalizeOptionalBool($input['is_active'] ?? null) ?? true,
            'is_system' => $isSystem,
        ]);

        $this->ownership->assignToModel($category, $owner);
        $category->save();

        return $category->refresh();
    }

    public function update(string $key, array $input, ?OwnershipReference $owner = null): FormCategory
    {
        $category = $this->findByKey($key, $owner);

        if (! $category instanceof FormCategory) {
            throw new FormForgeException("Category [{$key}] not found.");
        }

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);

            if ($name === '') {
                throw new FormForgeException('Category name cannot be empty.');
            }

            $this->assertNameAllowed($name);

            $category->name = $name;
        }

        if (array_key_exists('slug', $input)) {
            $requestedSlug = $this->normalizedSlug($input['slug']);

            if ($requestedSlug === null) {
                throw new FormForgeException('Category slug cannot be empty.');
            }

            $categoryOwner = $this->ownership->fromModel($category);
            $category->slug = $this->uniqueSlug($requestedSlug, (int) $category->getKey(), $categoryOwner);
        }

        if (array_key_exists('description', $input)) {
            $category->description = $this->nullableDescription($input['description']);
        }

        if (array_key_exists('meta', $input)) {
            $category->meta = $this->normalizedArray($input['meta']);
        }

        if (array_key_exists('is_active', $input)) {
            $resolved = $this->normalizeOptionalBool($input['is_active']);

            if ($resolved === null) {
                throw new FormForgeException('Category is_active must be a boolean value.');
            }

            $category->is_active = $resolved;
        }

        if (array_key_exists('is_system', $input)) {
            $resolved = $this->normalizeOptionalBool($input['is_system']);

            if ($resolved === null) {
                throw new FormForgeException('Category is_system must be a boolean value.');
            }

            $category->is_system = $resolved;
        }

        $category->save();

        return $category->refresh();
    }

    public function delete(string $key, ?OwnershipReference $owner = null): bool
    {
        $category = $this->findByKey($key, $owner);

        if (! $category instanceof FormCategory) {
            return false;
        }

        if ((bool) $category->is_system) {
            throw new FormConflictException("Category [{$category->key}] is a system category and cannot be deleted.");
        }

        $inUse = $this->formDefinitionModelClass()::query()
            ->withTrashed()
            ->where('form_category_id', $category->getKey())
            ->exists();

        if ($inUse) {
            throw new FormConflictException("Category [{$category->key}] is still linked to one or more forms.");
        }

        return (bool) $category->delete();
    }

    public function toArray(FormCategory $category): array
    {
        return [
            'id' => (int) $category->getKey(),
            'key' => (string) $category->key,
            'slug' => $this->normalizedOptionalString($category->slug),
            'name' => (string) $category->name,
            'description' => $category->description,
            'is_active' => (bool) $category->is_active,
            'is_system' => (bool) ($category->is_system ?? false),
            'owner_type' => $category->owner_type,
            'owner_id' => $category->owner_id,
            'meta' => is_array($category->meta) ? $category->meta : [],
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }

    private function normalizedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return $value;
    }

    private function nullableDescription(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $description = trim((string) $value);

        return $description === '' ? null : $description;
    }

    private function normalizedOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function assertNameAllowed(string $name): void
    {
        $forbidden = config('formforge.categories.forbidden_names', []);

        if (! is_array($forbidden)) {
            return;
        }

        $normalizedName = strtolower(trim($name));

        if ($normalizedName === '') {
            return;
        }

        foreach ($forbidden as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            if (strtolower(trim($candidate)) === $normalizedName) {
                throw new FormForgeException("Category name [{$name}] is forbidden.");
            }
        }
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

    private function normalizedSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $slug = trim((string) Str::slug($value), '-');

        return $slug === '' ? null : $slug;
    }

    private function uniqueSlug(string $baseSlug, ?int $ignoreId = null, ?OwnershipReference $owner = null): string
    {
        $candidate = $baseSlug;
        $counter = 2;

        while ($this->slugExists($candidate, $ignoreId, $owner)) {
            $candidate = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function slugExists(string $slug, ?int $ignoreId = null, ?OwnershipReference $owner = null): bool
    {
        $query = $this->categoryModelClass()::query()->where('slug', $slug);

        if (is_int($ignoreId) && $ignoreId > 0) {
            $query->whereKeyNot($ignoreId);
        }

        if (! $owner instanceof OwnershipReference) {
            $query
                ->whereNull('owner_type')
                ->whereNull('owner_id');

            return $query->exists();
        }

        $query->where(static function (Builder $builder) use ($owner): void {
            $builder
                ->where(static function (Builder $owned) use ($owner): void {
                    $owned
                        ->where('owner_type', $owner->type)
                        ->where('owner_id', $owner->id);
                })
                ->orWhere(static function (Builder $system): void {
                    $system
                        ->where('is_system', true)
                        ->whereNull('owner_type')
                        ->whereNull('owner_id');
                });
        });

        return $query->exists();
    }

    private function applyOwnerOrSystemScope(Builder $query, ?OwnershipReference $owner): void
    {
        if (! $owner instanceof OwnershipReference) {
            return;
        }

        $query->where(static function (Builder $builder) use ($owner): void {
            $builder
                ->where(static function (Builder $scoped) use ($owner): void {
                    $scoped
                        ->where('owner_type', $owner->type)
                        ->where('owner_id', $owner->id);
                })
                ->orWhere(static function (Builder $system): void {
                    $system
                        ->where('is_system', true)
                        ->whereNull('owner_type')
                        ->whereNull('owner_id');
                });
        });
    }
}
