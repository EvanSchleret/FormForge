<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use EvanSchleret\FormForge\Registry\FormRegistry;
use EvanSchleret\FormForge\Support\Schema;
use EvanSchleret\FormForge\Support\Version;
use Illuminate\Console\Command;

class ListCommand extends Command
{
    protected $signature = 'formforge:list {--category=} {--published=}';

    protected $description = 'List FormForge definitions and runtime/database drift status';

    public function handle(FormRegistry $registry, FormDefinitionRepository $repository): int
    {
        $categoryFilter = $this->normalizeCategoryFilter($this->option('category'));
        $publishedFilter = $this->normalizePublishedFilter($this->option('published'));
        $runtime = [];

        foreach ($registry->all() as $blueprint) {
            $version = $blueprint->versionValue();

            if ($version === null) {
                continue;
            }

            $schema = $blueprint->toSchemaArray();
            $index = $schema['key'] . '|' . $schema['version'];

            if (! isset($runtime[$index])) {
                $runtime[$index] = [];
            }

            $runtime[$index][] = [
                'key' => (string) $schema['key'],
                'version' => (string) $schema['version'],
                'hash' => Schema::hash($schema),
                'category' => (string) ($schema['category'] ?? config('formforge.forms.default_category', 'general')),
                'published' => (bool) ($schema['is_published'] ?? config('formforge.forms.default_published', true)),
            ];
        }

        $persisted = [];

        foreach ($repository->all($categoryFilter, $publishedFilter) as $definition) {
            $key = (string) $definition->key;
            $version = (string) $definition->version;
            $index = $key . '|' . $version;

            $persisted[$index] = [
                'key' => $key,
                'version' => $version,
                'hash' => (string) $definition->schema_hash,
                'active' => (bool) $definition->is_active,
                'category' => (string) ($definition->category ?? config('formforge.forms.default_category', 'general')),
                'published' => (bool) ($definition->is_published ?? config('formforge.forms.default_published', true)),
            ];
        }

        $indexes = array_values(array_unique([...array_keys($runtime), ...array_keys($persisted)]));

        usort($indexes, static function (string $left, string $right): int {
            [$leftKey, $leftVersion] = explode('|', $left, 2);
            [$rightKey, $rightVersion] = explode('|', $right, 2);

            return strcmp($leftKey, $rightKey) ?: Version::compare($leftVersion, $rightVersion);
        });

        $rows = [];

        foreach ($indexes as $index) {
            [$key, $version] = explode('|', $index, 2);
            $runtimeRows = $runtime[$index] ?? [];
            $persistedRow = $persisted[$index] ?? null;

            $status = 'runtime-only';
            $source = 'runtime';
            $active = 'no';
            $category = '-';
            $published = '-';

            if (count($runtimeRows) > 1) {
                $status = 'runtime-duplicate';
                $source = 'runtime';
                $category = (string) ($runtimeRows[0]['category'] ?? '-');
                $published = (bool) ($runtimeRows[0]['published'] ?? false) ? 'yes' : 'no';
            } elseif ($runtimeRows !== [] && $persistedRow !== null) {
                $runtimeHash = (string) $runtimeRows[0]['hash'];
                $persistedHash = (string) $persistedRow['hash'];
                $status = $runtimeHash === $persistedHash ? 'synced' : 'drift';
                $source = 'both';
                $active = $persistedRow['active'] ? 'yes' : 'no';
                $category = (string) ($persistedRow['category'] ?? $runtimeRows[0]['category'] ?? '-');
                $published = (bool) ($persistedRow['published'] ?? $runtimeRows[0]['published'] ?? false) ? 'yes' : 'no';
            } elseif ($runtimeRows === [] && $persistedRow !== null) {
                $status = 'db-only';
                $source = 'db';
                $active = $persistedRow['active'] ? 'yes' : 'no';
                $category = (string) ($persistedRow['category'] ?? '-');
                $published = (bool) ($persistedRow['published'] ?? false) ? 'yes' : 'no';
            } elseif ($runtimeRows !== []) {
                $category = (string) ($runtimeRows[0]['category'] ?? '-');
                $published = (bool) ($runtimeRows[0]['published'] ?? false) ? 'yes' : 'no';
            }

            if ($categoryFilter !== null && $category !== $categoryFilter) {
                continue;
            }

            if ($publishedFilter !== null && (($published === 'yes') !== $publishedFilter)) {
                continue;
            }

            $rows[] = [
                $key,
                $version,
                $category,
                $published,
                $source,
                $status,
                $active,
            ];
        }

        if ($rows === []) {
            $this->info('No FormForge definitions found.');

            return self::SUCCESS;
        }

        $this->table(['key', 'version', 'category', 'published', 'source', 'status', 'active'], $rows);

        return self::SUCCESS;
    }

    private function normalizePublishedFilter(mixed $value): ?bool
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'published'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'draft', 'unpublished'], true)) {
            return false;
        }

        return null;
    }

    private function normalizeCategoryFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
