<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations\Jobs;

use EvanSchleret\FormForge\Automations\SubmissionAutomationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class RunSubmissionAutomationJob implements ShouldQueue
{
    public ?string $connection = null;

    public ?string $queue = null;

    public function __construct(
        public readonly int $submissionId,
        public readonly string $registrationKey,
        ?string $connection = null,
        ?string $queue = null,
    ) {
        $this->connection = $connection;
        $this->queue = $queue;
    }

    public function handle(SubmissionAutomationDispatcher $dispatcher): void
    {
        $dispatcher->run($this->submissionId, $this->registrationKey);
    }
}
