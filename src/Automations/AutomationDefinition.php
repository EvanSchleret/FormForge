<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations;

final class AutomationDefinition
{
    public function __construct(
        public readonly string $formKey,
        public readonly string $automationKey,
        public readonly string $handlerClass,
        public readonly bool $queued,
        public readonly ?string $queue,
        public readonly ?string $connection,
    ) {
    }
}
