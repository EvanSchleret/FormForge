<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Registry;

use EvanSchleret\FormForge\Definition\FormBlueprint;

class FormRegistry
{
    private array $blueprints = [];

    public function register(FormBlueprint $blueprint): void
    {
        $this->blueprints[] = $blueprint;
    }

    public function all(): array
    {
        return $this->blueprints;
    }

    public function byKey(string $key): array
    {
        return array_values(array_filter(
            $this->blueprints,
            static fn (FormBlueprint $blueprint): bool => $blueprint->key() === $key,
        ));
    }

    public function find(string $key, string $version): ?FormBlueprint
    {
        foreach ($this->blueprints as $blueprint) {
            if ($blueprint->key() !== $key) {
                continue;
            }

            if ($blueprint->versionValue() === $version) {
                return $blueprint;
            }
        }

        return null;
    }
}
