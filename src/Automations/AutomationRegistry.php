<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations;

use EvanSchleret\FormForge\Exceptions\FormForgeException;

class AutomationRegistry
{
    /**
     * @var array<string, array<string, AutomationDefinition>>
     */
    private array $definitions = [];

    public function forForm(string $formKey): AutomationBuilder
    {
        $formKey = trim($formKey);

        if ($formKey === '') {
            throw new FormForgeException('Automation form key cannot be empty.');
        }

        return new AutomationBuilder($formKey, $this);
    }

    public function register(AutomationDefinition $definition): void
    {
        $formKey = trim($definition->formKey);
        $automationKey = trim($definition->automationKey);

        if ($formKey === '' || $automationKey === '') {
            throw new FormForgeException('Automation form key and automation key cannot be empty.');
        }

        if (isset($this->definitions[$formKey][$automationKey])) {
            throw new FormForgeException("Automation key [{$automationKey}] is already registered for form [{$formKey}].");
        }

        $this->definitions[$formKey][$automationKey] = $definition;
    }

    /**
     * @return array<int, AutomationDefinition>
     */
    public function resolveForForm(string $formKey): array
    {
        $formKey = trim($formKey);

        if ($formKey === '' || ! isset($this->definitions[$formKey])) {
            return [];
        }

        return array_values($this->definitions[$formKey]);
    }

    public function resolve(string $formKey, string $automationKey): ?AutomationDefinition
    {
        $formKey = trim($formKey);
        $automationKey = trim($automationKey);

        if ($formKey === '' || $automationKey === '') {
            return null;
        }

        return $this->definitions[$formKey][$automationKey] ?? null;
    }

    public function has(string $formKey, string $automationKey): bool
    {
        return $this->resolve($formKey, $automationKey) !== null;
    }
}
