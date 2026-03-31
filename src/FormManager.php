<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge;

use EvanSchleret\FormForge\Automations\AutomationBuilder;
use EvanSchleret\FormForge\Automations\AutomationRegistry;
use EvanSchleret\FormForge\Definition\FormBlueprint;
use EvanSchleret\FormForge\Exceptions\DuplicateFormVersionException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\Exceptions\ImmutableVersionException;
use EvanSchleret\FormForge\Management\FormCategoryService;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use EvanSchleret\FormForge\Registry\FormRegistry;
use EvanSchleret\FormForge\Submissions\SubmissionService;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use EvanSchleret\FormForge\Support\FormSchemaLayout;
use EvanSchleret\FormForge\Support\Schema;
use EvanSchleret\FormForge\Support\Version;
use Illuminate\Support\Facades\DB;

class FormManager
{
    public function __construct(
        private readonly FormRegistry $registry,
        private readonly FormDefinitionRepository $repository,
        private readonly SubmissionService $submissionService,
        private readonly AutomationRegistry $automationRegistry,
        private readonly FormCategoryService $categories,
    ) {
    }

    public function define(string $key): FormBlueprint
    {
        $blueprint = new FormBlueprint($key);
        $this->registry->register($blueprint);

        return $blueprint;
    }

    public function get(string $key, ?string $version = null): FormInstance
    {
        if ($version !== null) {
            $runtime = $this->registry->find($key, $version);

            if ($runtime !== null) {
                return $this->newInstance($runtime->toSchemaArray());
            }

            $persisted = $this->repository->find($key, $version);

            if ($persisted !== null) {
                return $this->newInstance((array) $persisted->schema);
            }

            throw FormNotFoundException::forKey($key, $version);
        }

        $runtimeForms = $this->runtimeVersionsForKey($key);

        if ($runtimeForms !== []) {
            $latest = $this->selectLatestBlueprint($runtimeForms);

            return $this->newInstance($latest->toSchemaArray());
        }

        $latestPersisted = $this->repository->latest($key);

        if ($latestPersisted !== null) {
            return $this->newInstance((array) $latestPersisted->schema);
        }

        throw FormNotFoundException::forKey($key);
    }

    public function versions(string $key): array
    {
        $runtime = [];

        foreach ($this->runtimeVersionsForKey($key) as $blueprint) {
            $version = $blueprint->versionValue();

            if ($version !== null) {
                $runtime[] = $version;
            }
        }

        $persisted = $this->repository->versions($key);
        $versions = array_values(array_unique([...$runtime, ...$persisted]));

        return Version::sort($versions);
    }

    public function all(): array
    {
        $resolved = [];

        foreach ($this->registry->all() as $blueprint) {
            $version = $blueprint->versionValue();

            if ($version === null) {
                continue;
            }

            $schema = $blueprint->toSchemaArray();
            $resolved[$schema['key'] . '|' . $schema['version']] = $schema;
        }

        foreach ($this->repository->all() as $persisted) {
            $schema = (array) $persisted->schema;
            $index = (string) ($schema['key'] ?? '') . '|' . (string) ($schema['version'] ?? '');

            if ($index === '|') {
                continue;
            }

            if (! isset($resolved[$index])) {
                $resolved[$index] = $schema;
            }
        }

        $instances = [];

        foreach ($resolved as $schema) {
            $instances[] = $this->newInstance($schema);
        }

        usort($instances, static fn (FormInstance $left, FormInstance $right): int =>
            strcmp($left->key(), $right->key()) ?: Version::compare($left->version(), $right->version())
        );

        return $instances;
    }

    public function automation(string $formKey): AutomationBuilder
    {
        return $this->automationRegistry->forForm($formKey);
    }

    public function sync(): array
    {
        $schemas = $this->runtimeSchemasForSync();

        if ($schemas === []) {
            return [
                'created' => 0,
                'unchanged' => 0,
            ];
        }

        $created = 0;
        $unchanged = 0;
        $latestByKey = [];

        DB::transaction(function () use ($schemas, &$created, &$unchanged, &$latestByKey): void {
            foreach ($schemas as $schema) {
                $key = (string) $schema['key'];
                $version = (string) $schema['version'];
                $hash = Schema::hash($schema);

                $existing = $this->repository->find($key, $version);

                if ($existing === null) {
                    $category = $this->categories->ensureForForms(is_string($schema['category'] ?? null) ? (string) $schema['category'] : null);

                    ModelClassResolver::formDefinition()::query()->create([
                        'key' => $key,
                        'version' => $version,
                        'title' => (string) $schema['title'],
                        'category' => (string) $category->key,
                        'form_category_id' => (int) $category->getKey(),
                        'schema' => $schema,
                        'schema_hash' => $hash,
                        'is_active' => false,
                        'is_published' => (bool) ($schema['is_published'] ?? config('formforge.forms.default_published', true)),
                    ]);

                    $created++;
                } elseif ((string) $existing->schema_hash === $hash) {
                    $unchanged++;
                } else {
                    throw ImmutableVersionException::forKeyVersion($key, $version);
                }

                if (! isset($latestByKey[$key]) || Version::compare($version, $latestByKey[$key]) > 0) {
                    $latestByKey[$key] = $version;
                }
            }

            foreach ($latestByKey as $key => $version) {
                $this->repository->activate($key, $version);
            }
        });

        return [
            'created' => $created,
            'unchanged' => $unchanged,
        ];
    }

    private function runtimeSchemasForSync(): array
    {
        $schemas = [];

        foreach ($this->registry->all() as $blueprint) {
            $version = $blueprint->versionValue();

            if ($version === null) {
                continue;
            }

            $schema = $blueprint->toSchemaArray();
            $index = $schema['key'] . '|' . $schema['version'];

            if (isset($schemas[$index])) {
                throw DuplicateFormVersionException::forKeyVersion($schema['key'], $schema['version']);
            }

            $schemas[$index] = $schema;
        }

        return array_values($schemas);
    }

    private function runtimeVersionsForKey(string $key): array
    {
        $forms = [];

        foreach ($this->registry->byKey($key) as $blueprint) {
            if ($blueprint->versionValue() !== null) {
                $forms[] = $blueprint;
            }
        }

        return $forms;
    }

    private function selectLatestBlueprint(array $forms): FormBlueprint
    {
        usort($forms, static fn (FormBlueprint $left, FormBlueprint $right): int =>
            Version::compare((string) $left->versionValue(), (string) $right->versionValue())
        );

        return $forms[count($forms) - 1];
    }

    private function newInstance(array $schema): FormInstance
    {
        return new FormInstance(FormSchemaLayout::normalize($schema), $this->submissionService);
    }
}
