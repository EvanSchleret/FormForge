<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Persistence;

use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Support\Version;
use Illuminate\Support\Collection;

class FormDefinitionRepository
{
    public function find(string $key, string $version, ?bool $published = null, ?string $category = null): ?FormDefinition
    {
        $query = FormDefinition::query()
            ->where('key', $key)
            ->where('version', $version);

        if ($published !== null) {
            $query->where('is_published', $published);
        }

        if (is_string($category) && trim($category) !== '') {
            $query->where('category', trim($category));
        }

        return $query->first();
    }

    public function versions(string $key): array
    {
        $versions = FormDefinition::query()
            ->where('key', $key)
            ->pluck('version')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        return Version::sort(array_values(array_unique($versions)));
    }

    public function latest(string $key, ?bool $published = null, ?string $category = null): ?FormDefinition
    {
        $query = FormDefinition::query()
            ->where('key', $key);

        if ($published !== null) {
            $query->where('is_published', $published);
        }

        if (is_string($category) && trim($category) !== '') {
            $query->where('category', trim($category));
        }

        $all = $query->get();

        if ($all->isEmpty()) {
            return null;
        }

        return $all->sort(static fn (FormDefinition $left, FormDefinition $right): int => Version::compare((string) $left->version, (string) $right->version))
            ->last();
    }

    public function all(?string $category = null, ?bool $published = null): Collection
    {
        $query = FormDefinition::query()
            ->orderBy('key')
            ->orderBy('version');

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
}
