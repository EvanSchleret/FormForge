<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations;

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomationResolver;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormSubmission;

class AutomationRegistry
{
    /**
     * @var array<string, array<string, AutomationDefinition>>
     */
    private array $definitions = [];

    /**
     * @var array<string, AutomationDefinition>
     */
    private array $definitionsByRegistrationKey = [];

    public function forForm(string $formKey): AutomationBuilder
    {
        $formKey = trim($formKey);

        if ($formKey === '') {
            throw new FormForgeException('Automation form key cannot be empty.');
        }

        return new AutomationBuilder(
            targetType: AutomationDefinition::TARGET_FORM,
            targetValue: $formKey,
            registry: $this,
        );
    }

    public function forResolver(string $resolverClass): AutomationBuilder
    {
        $resolverClass = trim($resolverClass);

        if ($resolverClass === '') {
            throw new FormForgeException('Automation resolver class cannot be empty.');
        }

        if (! class_exists($resolverClass) || ! is_subclass_of($resolverClass, SubmissionAutomationResolver::class)) {
            throw new FormForgeException("Automation resolver [{$resolverClass}] must implement [" . SubmissionAutomationResolver::class . '].');
        }

        return new AutomationBuilder(
            targetType: AutomationDefinition::TARGET_RESOLVER,
            targetValue: $resolverClass,
            registry: $this,
        );
    }

    public function register(AutomationDefinition $definition): void
    {
        $targetType = trim($definition->targetType);
        $targetValue = trim($definition->targetValue);
        $automationKey = trim($definition->automationKey);
        $targetIdentifier = $targetType . ':' . $targetValue;

        if ($targetType === '' || $targetValue === '' || $automationKey === '') {
            throw new FormForgeException('Automation target and automation key cannot be empty.');
        }

        if (isset($this->definitions[$targetIdentifier][$automationKey])) {
            throw new FormForgeException("Automation key [{$automationKey}] is already registered for target [{$targetIdentifier}].");
        }

        $registrationKey = $definition->registrationKey();

        $this->definitions[$targetIdentifier][$automationKey] = $definition;
        $this->definitionsByRegistrationKey[$registrationKey] = $definition;
    }

    /**
     * @return array<int, AutomationDefinition>
     */
    public function resolveForForm(string $formKey): array
    {
        $formKey = trim($formKey);
        $targetIdentifier = AutomationDefinition::TARGET_FORM . ':' . $formKey;

        if ($formKey === '' || ! isset($this->definitions[$targetIdentifier])) {
            return [];
        }

        return array_values($this->definitions[$targetIdentifier]);
    }

    /**
     * @return array<int, AutomationDefinition>
     */
    public function resolveForSubmission(FormSubmission $submission): array
    {
        $resolved = $this->resolveForForm((string) $submission->form_key);

        foreach ($this->definitions as $targetIdentifier => $definitions) {
            if (! str_starts_with($targetIdentifier, AutomationDefinition::TARGET_RESOLVER . ':')) {
                continue;
            }

            if ($definitions === []) {
                continue;
            }

            $definition = array_values($definitions)[0];

            if (! $this->matchesSubmission($definition, $submission)) {
                continue;
            }

            $resolved = [...$resolved, ...array_values($definitions)];
        }

        return $resolved;
    }

    public function resolve(string $formKey, string $automationKey): ?AutomationDefinition
    {
        $formKey = trim($formKey);
        $automationKey = trim($automationKey);

        if ($formKey === '' || $automationKey === '') {
            return null;
        }

        $targetIdentifier = AutomationDefinition::TARGET_FORM . ':' . $formKey;

        return $this->definitions[$targetIdentifier][$automationKey] ?? null;
    }

    public function resolveRegistration(string $registrationKey): ?AutomationDefinition
    {
        $registrationKey = trim($registrationKey);

        if ($registrationKey === '') {
            return null;
        }

        return $this->definitionsByRegistrationKey[$registrationKey] ?? null;
    }

    public function has(string $formKey, string $automationKey): bool
    {
        return $this->resolve($formKey, $automationKey) !== null;
    }

    public function hasForTarget(string $targetType, string $targetValue, string $automationKey): bool
    {
        $targetType = trim($targetType);
        $targetValue = trim($targetValue);
        $automationKey = trim($automationKey);

        if ($targetType === '' || $targetValue === '' || $automationKey === '') {
            return false;
        }

        return isset($this->definitions[$targetType . ':' . $targetValue][$automationKey]);
    }

    public function matchesSubmission(AutomationDefinition $definition, FormSubmission $submission): bool
    {
        if ($definition->targetType === AutomationDefinition::TARGET_FORM) {
            return (string) $submission->form_key === $definition->targetValue;
        }

        if ($definition->targetType !== AutomationDefinition::TARGET_RESOLVER) {
            return false;
        }

        $resolver = app()->make($definition->targetValue);

        if (! $resolver instanceof SubmissionAutomationResolver) {
            throw new FormForgeException("Automation resolver [{$definition->targetValue}] must implement [" . SubmissionAutomationResolver::class . '].');
        }

        return $resolver->matches($submission);
    }
}
