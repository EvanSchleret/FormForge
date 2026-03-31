<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Persistence;

use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Ownership\OwnershipManager;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use EvanSchleret\FormForge\Support\Version;
use Illuminate\Support\Collection;

class FormDefinitionRepository
{
    private function ownership(): OwnershipManager
    {
        return app(OwnershipManager::class);
    }

    private function modelClass(): string
    {
        return ModelClassResolver::formDefinition();
    }

    public function find(
        string $key,
        string $version,
        ?bool $published = null,
        ?string $category = null,
        bool $includeDeleted = false,
        ?OwnershipReference $owner = null,
    ): ?FormDefinition {
        $query = $this->modelClass()::query()
            ->where('key', $key)
            ->where('version', $version);
        $this->ownership()->applyScope($query, $owner);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        if ($published !== null) {
            $query->where('is_published', $published);
        }

        if (is_string($category) && trim($category) !== '') {
            $query->where('category', trim($category));
        }

        return $query->first();
    }

    public function versions(string $key, bool $includeDeleted = false, ?OwnershipReference $owner = null): array
    {
        $query = $this->modelClass()::query()
            ->where('key', $key)
            ->orderBy('version_number')
            ->orderBy('version');
        $this->ownership()->applyScope($query, $owner);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        $versions = $query->pluck('version')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        return Version::sort(array_values(array_unique($versions)));
    }

    public function latest(
        string $key,
        ?bool $published = null,
        ?string $category = null,
        bool $includeDeleted = false,
        ?OwnershipReference $owner = null,
    ): ?FormDefinition {
        $query = $this->modelClass()::query()
            ->where('key', $key);
        $this->ownership()->applyScope($query, $owner);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        if ($published !== null) {
            $query->where('is_published', $published);
        }

        if (is_string($category) && trim($category) !== '') {
            $query->where('category', trim($category));
        }

        $latest = $query
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->first();

        if ($latest !== null) {
            return $latest;
        }

        $all = $query->get();

        return $all->isEmpty()
            ? null
            : $all->sort(static fn (FormDefinition $left, FormDefinition $right): int => Version::compare((string) $left->version, (string) $right->version))->last();
    }

    public function latestActive(string $key, bool $includeDeleted = false, ?OwnershipReference $owner = null): ?FormDefinition
    {
        $query = $this->modelClass()::query()
            ->where('key', $key)
            ->where('is_active', true);
        $this->ownership()->applyScope($query, $owner);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        return $query
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->first();
    }

    public function all(?string $category = null, ?bool $published = null, bool $includeDeleted = false, ?OwnershipReference $owner = null): Collection
    {
        $query = $this->modelClass()::query()
            ->orderBy('key')
            ->orderBy('version_number')
            ->orderBy('version');
        $this->ownership()->applyScope($query, $owner);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        if (is_string($category) && trim($category) !== '') {
            $query->where('category', trim($category));
        }

        if ($published !== null) {
            $query->where('is_published', $published);
        }

        return $query->get();
    }

    public function activate(string $key, string $version, ?OwnershipReference $owner = null): void
    {
        $deactivate = $this->modelClass()::query()
            ->where('key', $key);
        $this->ownership()->applyScope($deactivate, $owner);
        $deactivate->update(['is_active' => false]);

        $activate = $this->modelClass()::query()
            ->where('key', $key)
            ->where('version', $version);
        $this->ownership()->applyScope($activate, $owner);
        $activate->update(['is_active' => true]);
    }

    public function nextVersionNumber(string $key, bool $includeDeleted = true, ?OwnershipReference $owner = null): int
    {
        $query = $this->modelClass()::query()->where('key', $key);
        $this->ownership()->applyScope($query, $owner);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        $max = $query->max('version_number');

        if (is_numeric($max)) {
            return ((int) $max) + 1;
        }

        $versions = $this->versions($key, $includeDeleted, $owner);
        $numeric = array_filter($versions, static fn (string $version): bool => ctype_digit($version));

        if ($numeric === []) {
            return 1;
        }

        $asInt = array_map(static fn (string $version): int => (int) $version, $numeric);

        return max($asInt) + 1;
    }

    public function keyExists(string $key, bool $includeDeleted = true, ?OwnershipReference $owner = null): bool
    {
        $query = $this->modelClass()::query()->where('key', $key);
        $this->ownership()->applyScope($query, $owner);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        return $query->exists();
    }

    public function byKey(string $key, bool $includeDeleted = false, ?OwnershipReference $owner = null): Collection
    {
        $query = $this->modelClass()::query()
            ->where('key', $key)
            ->orderBy('version_number')
            ->orderBy('version');
        $this->ownership()->applyScope($query, $owner);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        return $query->get();
    }

    public function softDeleteKey(string $key, ?OwnershipReference $owner = null): int
    {
        $deactivate = $this->modelClass()::query()->where('key', $key);
        $this->ownership()->applyScope($deactivate, $owner);
        $deactivate->update(['is_active' => false]);

        $softDelete = $this->modelClass()::query()->where('key', $key);
        $this->ownership()->applyScope($softDelete, $owner);

        return (int) $softDelete->delete();
    }
}
