<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Persistence;

use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Support\Version;
use Illuminate\Support\Collection;

class FormDefinitionRepository
{
    public function find(
        string $key,
        string $version,
        ?bool $published = null,
        ?string $category = null,
        bool $includeDeleted = false,
    ): ?FormDefinition {
        $query = FormDefinition::query()
            ->where('key', $key)
            ->where('version', $version);

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

    public function versions(string $key, bool $includeDeleted = false): array
    {
        $query = FormDefinition::query()
            ->where('key', $key)
            ->orderBy('version_number')
            ->orderBy('version');

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
    ): ?FormDefinition {
        $query = FormDefinition::query()
            ->where('key', $key);

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

    public function latestActive(string $key, bool $includeDeleted = false): ?FormDefinition
    {
        $query = FormDefinition::query()
            ->where('key', $key)
            ->where('is_active', true);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        return $query
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->first();
    }

    public function all(?string $category = null, ?bool $published = null, bool $includeDeleted = false): Collection
    {
        $query = FormDefinition::query()
            ->orderBy('key')
            ->orderBy('version_number')
            ->orderBy('version');

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

    public function activate(string $key, string $version): void
    {
        FormDefinition::query()
            ->where('key', $key)
            ->update(['is_active' => false]);

        FormDefinition::query()
            ->where('key', $key)
            ->where('version', $version)
            ->update(['is_active' => true]);
    }

    public function nextVersionNumber(string $key, bool $includeDeleted = true): int
    {
        $query = FormDefinition::query()->where('key', $key);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        $max = $query->max('version_number');

        if (is_numeric($max)) {
            return ((int) $max) + 1;
        }

        $versions = $this->versions($key, $includeDeleted);
        $numeric = array_filter($versions, static fn (string $version): bool => ctype_digit($version));

        if ($numeric === []) {
            return 1;
        }

        $asInt = array_map(static fn (string $version): int => (int) $version, $numeric);

        return max($asInt) + 1;
    }

    public function keyExists(string $key, bool $includeDeleted = true): bool
    {
        $query = FormDefinition::query()->where('key', $key);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        return $query->exists();
    }

    public function byKey(string $key, bool $includeDeleted = false): Collection
    {
        $query = FormDefinition::query()
            ->where('key', $key)
            ->orderBy('version_number')
            ->orderBy('version');

        if ($includeDeleted) {
            $query->withTrashed();
        }

        return $query->get();
    }

    public function softDeleteKey(string $key): int
    {
        FormDefinition::query()
            ->where('key', $key)
            ->update(['is_active' => false]);

        return (int) FormDefinition::query()
            ->where('key', $key)
            ->delete();
    }
}
