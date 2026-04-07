<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations;

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomation;
use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomationResolver;
use EvanSchleret\FormForge\Exceptions\FormForgeException;

class AutomationBuilder
{
    private bool $queued;

    private ?string $queue;

    private ?string $connection;

    public function __construct(
        private readonly string $targetType,
        private readonly string $targetValue,
        private readonly AutomationRegistry $registry,
    ) {
        $this->validateTarget();
        $this->queued = (bool) config('formforge.automations.queue.enabled', true);
        $this->queue = $this->normalizeOptionalString(config('formforge.automations.queue.name'));
        $this->connection = $this->normalizeOptionalString(config('formforge.automations.queue.connection'));
    }

    public function queue(?string $queue = null, ?string $connection = null): self
    {
        $this->queued = true;
        $this->queue = $this->normalizeOptionalString($queue) ?? $this->queue;
        $this->connection = $this->normalizeOptionalString($connection) ?? $this->connection;

        return $this;
    }

    public function sync(): self
    {
        $this->queued = false;
        $this->queue = null;
        $this->connection = null;

        return $this;
    }

    public function handler(string $handlerClass, ?string $automationKey = null): self
    {
        $handlerClass = trim($handlerClass);

        if ($handlerClass === '' || ! class_exists($handlerClass)) {
            throw new FormForgeException("Automation handler class [{$handlerClass}] is invalid.");
        }

        if (! is_subclass_of($handlerClass, SubmissionAutomation::class)) {
            throw new FormForgeException("Automation handler [{$handlerClass}] must implement [" . SubmissionAutomation::class . '].');
        }

        $key = $this->normalizeOptionalString($automationKey) ?? $this->defaultAutomationKey($handlerClass);

        $this->registry->register(new AutomationDefinition(
            targetType: $this->targetType,
            targetValue: $this->targetValue,
            automationKey: $key,
            handlerClass: $handlerClass,
            queued: $this->queued,
            queue: $this->queue,
            connection: $this->connection,
        ));

        return $this;
    }

    private function defaultAutomationKey(string $handlerClass): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', class_basename($handlerClass)) ?? '');
        $base = trim($base, '_');
        $base = $base === '' ? 'automation' : $base;

        $candidate = $base;
        $counter = 2;

        while ($this->registry->hasForTarget($this->targetType, $this->targetValue, $candidate)) {
            $candidate = $base . '_' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function validateTarget(): void
    {
        if (! in_array($this->targetType, [AutomationDefinition::TARGET_FORM, AutomationDefinition::TARGET_RESOLVER], true)) {
            throw new FormForgeException("Automation target type [{$this->targetType}] is invalid.");
        }

        if ($this->targetValue === '') {
            throw new FormForgeException('Automation target value cannot be empty.');
        }

        if ($this->targetType !== AutomationDefinition::TARGET_RESOLVER) {
            return;
        }

        if (! class_exists($this->targetValue) || ! is_subclass_of($this->targetValue, SubmissionAutomationResolver::class)) {
            throw new FormForgeException("Automation resolver [{$this->targetValue}] must implement [" . SubmissionAutomationResolver::class . '].');
        }
    }
}
