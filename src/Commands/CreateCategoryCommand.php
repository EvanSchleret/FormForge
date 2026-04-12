<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Management\FormCategoryService;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Illuminate\Console\Command;

class CreateCategoryCommand extends Command
{
    protected $signature = 'formforge:category:create
        {name? : Category name}
        {--slug= : Category slug}
        {--description= : Category description}
        {--is-active= : Category active flag (true|false|1|0)}
        {--is-system= : Category system flag (true|false|1|0)}
        {--owner-type= : Optional owner type for scoped category creation}
        {--owner-id= : Optional owner id for scoped category creation}';

    protected $description = 'Create a FormForge category';

    public function handle(FormCategoryService $categories): int
    {
        $name = $this->normalizeOptionalString($this->argument('name'));
        $interactive = $name === null;

        if ($interactive) {
            $name = $this->promptRequired('Category name');
        }

        if ($name === null) {
            $this->error('Category name cannot be empty.');

            return self::FAILURE;
        }

        $slug = $this->normalizeOptionalString($this->option('slug'));
        $description = $this->normalizeOptionalString($this->option('description'));

        try {
            $isActive = $this->resolveBooleanOption('is-active');
            $isSystem = $this->resolveBooleanOption('is-system');
            $owner = $this->resolveOwner();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($interactive) {
            if ($slug === null) {
                $slug = $this->normalizeOptionalString($this->ask('Category slug (optional)'));
            }

            if ($description === null) {
                $description = $this->normalizeOptionalString($this->ask('Category description (optional)'));
            }

            if ($isActive === null) {
                $isActive = $this->confirm('Is category active?', true);
            }

            if ($isSystem === null) {
                $isSystem = $this->confirm('Is this a system category?', false);
            }
        }

        $payload = [
            'name' => $name,
        ];

        if ($slug !== null) {
            $payload['slug'] = $slug;
        }

        if ($description !== null) {
            $payload['description'] = $description;
        }

        if ($isActive !== null) {
            $payload['is_active'] = $isActive;
        }

        if ($isSystem !== null) {
            $payload['is_system'] = $isSystem;
        }

        try {
            $category = $categories->create($payload, $owner);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Category created.');
        $this->table(['field', 'value'], [
            ['key', (string) $category->key],
            ['slug', (string) ($category->slug ?? '')],
            ['name', (string) $category->name],
            ['description', (string) ($category->description ?? '')],
            ['is_active', (bool) $category->is_active ? 'yes' : 'no'],
            ['is_system', (bool) $category->is_system ? 'yes' : 'no'],
            ['owner_type', (string) ($category->owner_type ?? '')],
            ['owner_id', (string) ($category->owner_id ?? '')],
        ]);

        return self::SUCCESS;
    }

    private function promptRequired(string $question): ?string
    {
        while (true) {
            $value = $this->normalizeOptionalString($this->ask($question));

            if ($value !== null) {
                return $value;
            }

            $this->error('This value is required.');
        }
    }

    private function resolveBooleanOption(string $option): ?bool
    {
        $raw = $this->option($option);

        if ($raw === null) {
            return null;
        }

        $resolved = $this->normalizeOptionalBool($raw);

        if ($resolved !== null) {
            return $resolved;
        }

        throw new \InvalidArgumentException("Option [--{$option}] must be a boolean value.");
    }

    private function resolveOwner(): ?OwnershipReference
    {
        $type = $this->normalizeOptionalString($this->option('owner-type'));
        $id = $this->normalizeOptionalString($this->option('owner-id'));

        if ($type === null && $id === null) {
            return null;
        }

        if ($type === null || $id === null) {
            throw new \InvalidArgumentException('Both --owner-type and --owner-id must be provided together.');
        }

        return OwnershipReference::from([
            'type' => $type,
            'id' => $id,
        ]);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

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
