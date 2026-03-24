<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Automations;

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomation;
use EvanSchleret\FormForge\Automations\Jobs\RunSubmissionAutomationJob;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Models\SubmissionAutomationRun;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;

class SubmissionAutomationDispatcher
{
    public function __construct(
        private readonly AutomationRegistry $registry,
        private readonly Dispatcher $bus,
    ) {
    }

    public function dispatch(FormSubmission $submission): void
    {
        if (! (bool) config('formforge.automations.enabled', true)) {
            return;
        }

        $definitions = $this->registry->resolveForForm((string) $submission->form_key);

        foreach ($definitions as $definition) {
            if ($definition->queued) {
                $this->bus->dispatch(new RunSubmissionAutomationJob(
                    submissionId: (int) $submission->getKey(),
                    automationKey: $definition->automationKey,
                    connection: $definition->connection,
                    queue: $definition->queue,
                ));

                continue;
            }

            $this->execute($submission, $definition);
        }
    }

    public function run(int $submissionId, string $automationKey): void
    {
        $submission = FormSubmission::query()->find($submissionId);

        if (! $submission instanceof FormSubmission) {
            return;
        }

        $definition = $this->registry->resolve((string) $submission->form_key, $automationKey);

        if (! $definition instanceof AutomationDefinition) {
            return;
        }

        $this->execute($submission, $definition);
    }

    private function execute(FormSubmission $submission, AutomationDefinition $definition): void
    {
        $run = $this->startRun($submission, $definition);

        if ($run === null) {
            return;
        }

        try {
            $handler = app()->make($definition->handlerClass);

            if (! $handler instanceof SubmissionAutomation) {
                throw new FormForgeException("Automation handler [{$definition->handlerClass}] must implement [" . SubmissionAutomation::class . '].');
            }

            $handler->handle($submission->loadMissing('files'));

            $run->status = 'completed';
            $run->finished_at = Carbon::now();
            $run->last_error = null;
            $run->save();
        } catch (\Throwable $exception) {
            $run->status = 'failed';
            $run->finished_at = Carbon::now();
            $run->last_error = $exception->getMessage();
            $run->save();

            throw $exception;
        }
    }

    private function startRun(FormSubmission $submission, AutomationDefinition $definition): ?SubmissionAutomationRun
    {
        $now = Carbon::now();

        $inserted = (int) SubmissionAutomationRun::query()->insertOrIgnore([
            'form_submission_id' => (int) $submission->getKey(),
            'form_key' => (string) $submission->form_key,
            'automation_key' => $definition->automationKey,
            'handler_class' => $definition->handlerClass,
            'status' => 'running',
            'attempts' => 1,
            'started_at' => $now,
            'finished_at' => null,
            'last_error' => null,
            'meta' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $run = SubmissionAutomationRun::query()
            ->where('form_submission_id', (int) $submission->getKey())
            ->where('automation_key', $definition->automationKey)
            ->first();

        if (! $run instanceof SubmissionAutomationRun) {
            return null;
        }

        if ($inserted === 0) {
            if ($run->status === 'completed') {
                return null;
            }

            $run->handler_class = $definition->handlerClass;
            $run->status = 'running';
            $run->attempts = ((int) $run->attempts) + 1;
            $run->started_at = $now;
            $run->finished_at = null;
            $run->last_error = null;
            $run->save();
        }

        return $run;
    }
}
