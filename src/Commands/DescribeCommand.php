<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use EvanSchleret\FormForge\Registry\FormRegistry;
use EvanSchleret\FormForge\Support\Schema;
use Illuminate\Console\Command;

class DescribeCommand extends Command
{
    protected $signature = 'formforge:describe {formKey} {--version=} {--json : Print only schema JSON}';

    protected $description = 'Describe a FormForge definition and runtime/database status';

    public function handle(FormManager $forms, FormRegistry $registry, FormDefinitionRepository $repository): int
    {
        $key = (string) $this->argument('formKey');
        $version = $this->option('version');

        if (! is_string($version) || trim($version) === '') {
            return $this->describeVersions($key, $forms, $registry, $repository);
        }

        return $this->describeSingle($key, trim($version), $forms, $registry, $repository);
    }

    private function describeSingle(
        string $key,
        string $version,
        FormManager $forms,
        FormRegistry $registry,
        FormDefinitionRepository $repository,
    ): int {
        $runtime = $registry->find($key, $version);
        $runtimeSchema = $runtime?->toSchemaArray();
        $persisted = $repository->find($key, $version);
        $persistedSchema = $persisted?->schema;

        if ($runtimeSchema === null && $persistedSchema === null) {
            $this->error("Form [{$key}] version [{$version}] was not found.");

            return self::FAILURE;
        }

        $status = 'runtime-only';

        if ($runtimeSchema !== null && $persistedSchema !== null) {
            $runtimeHash = Schema::hash($runtimeSchema);
            $persistedHash = (string) $persisted->schema_hash;
            $status = $runtimeHash === $persistedHash ? 'synced' : 'drift';
        } elseif ($runtimeSchema === null && $persistedSchema !== null) {
            $status = 'db-only';
        }

        $instance = $forms->get($key, $version);
        $json = json_encode($instance->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = '{}';
        }

        if ((bool) $this->option('json')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $this->info("Form: {$key}");
        $this->line("Version: {$version}");
        $this->line("Status: {$status}");
        $this->line('Category: ' . $instance->category());
        $this->line('Published: ' . ($instance->isPublished() ? 'yes' : 'no'));

        if ($persisted !== null) {
            $this->line('DB active: ' . ($persisted->is_active ? 'yes' : 'no'));
        }

        $this->newLine();
        $this->line($json);

        return self::SUCCESS;
    }

    private function describeVersions(
        string $key,
        FormManager $forms,
        FormRegistry $registry,
        FormDefinitionRepository $repository,
    ): int {
        $versions = $forms->versions($key);

        if ($versions === []) {
            $this->error("Form [{$key}] was not found.");

            return self::FAILURE;
        }

        $rows = [];

        foreach ($versions as $version) {
            $runtimeSchema = $registry->find($key, $version)?->toSchemaArray();
            $persisted = $repository->find($key, $version);

            $status = 'runtime-only';

            if ($runtimeSchema !== null && $persisted !== null) {
                $status = Schema::hash($runtimeSchema) === (string) $persisted->schema_hash ? 'synced' : 'drift';
            } elseif ($runtimeSchema === null && $persisted !== null) {
                $status = 'db-only';
            }

            $rows[] = [
                $version,
                (string) ($runtimeSchema['category'] ?? $persisted?->category ?? config('formforge.forms.default_category', 'general')),
                ((bool) ($runtimeSchema['is_published'] ?? $persisted?->is_published ?? config('formforge.forms.default_published', true))) ? 'yes' : 'no',
                $runtimeSchema !== null ? 'yes' : 'no',
                $persisted !== null ? 'yes' : 'no',
                $persisted?->is_active ? 'yes' : 'no',
                $status,
            ];
        }

        $this->info("Form: {$key}");
        $this->table(['version', 'category', 'published', 'runtime', 'db', 'active', 'status'], $rows);

        $latest = $forms->get($key);
        $this->line('Latest resolved version: ' . $latest->version());

        if ((bool) $this->option('json')) {
            $json = json_encode($latest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->newLine();
            $this->line($json === false ? '{}' : $json);
        }

        return self::SUCCESS;
    }
}
