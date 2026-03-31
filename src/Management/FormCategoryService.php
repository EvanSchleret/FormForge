<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Management;

use EvanSchleret\FormForge\Exceptions\FormConflictException;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormCategory;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use EvanSchleret\FormForge\Ownership\OwnershipManager;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
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
        $this->ownership->applyScope($query, $owner);

        $search = $this->normalizedOptionalString($filters['search'] ?? null);

        if ($search !== null) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('key', 'like', '%' . $search . '%')
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
        $this->ownership->applyScope($query, $owner);

        return $query->first();
    }

    public function ensureForForms(?string $key, ?OwnershipReference $owner = null): ?FormCategory
    {
        $normalized = $this->normalizedOptionalString($key);

        if ($normalized === null) {
            return $this->ensureDefaultCategory($owner);
        }

        $existing = $this->findByKey($normalized, $owner);

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

        $isSystem = false;

        if (array_key_exists('is_system', $input)) {
            $resolved = $this->normalizeOptionalBool($input['is_system']);

            if ($resolved === null) {
                throw new FormForgeException('Category is_system must be a boolean value.');
            }

            $isSystem = $resolved;
        }

        $category = $this->categoryModelClass()::query()->make([
            'key' => (string) Str::uuid(),
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

            $category->name = $name;
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

    private function ensureDefaultCategory(?OwnershipReference $owner = null): FormCategory
    {
        $defaultName = trim((string) config('formforge.forms.default_category', 'general'));

        if ($defaultName === '') {
            $defaultName = 'general';
        }

        $query = $this->categoryModelClass()::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($defaultName)]);
        $this->ownership->applyScope($query, $owner);
        $existing = $query->first();

        if ($existing instanceof FormCategory) {
            if (! (bool) ($existing->is_system ?? false)) {
                $existing->is_system = true;
                $existing->save();
            }

            return $existing;
        }

        $category = $this->categoryModelClass()::query()->make([
            'key' => (string) Str::uuid(),
            'name' => $defaultName,
            'description' => null,
            'meta' => ['default' => true],
            'is_active' => true,
            'is_system' => true,
        ]);

        $this->ownership->assignToModel($category, $owner);
        $category->save();

        return $category->refresh();
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
}
