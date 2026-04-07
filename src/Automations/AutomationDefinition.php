<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations;

final class AutomationDefinition
{
    public const TARGET_FORM = 'form';

    public const TARGET_RESOLVER = 'resolver';

    public function __construct(
        public readonly string $targetType,
        public readonly string $targetValue,
        public readonly string $automationKey,
        public readonly string $handlerClass,
        public readonly bool $queued,
        public readonly ?string $queue,
        public readonly ?string $connection,
    ) {
    }

    public function targetIdentifier(): string
    {
        return $this->targetType . ':' . $this->targetValue;
    }

    public function registrationKey(): string
    {
        return $this->targetIdentifier() . '#' . $this->automationKey;
    }
}
