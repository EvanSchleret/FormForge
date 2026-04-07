<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Submissions\SubmissionPrivacyService;
use Illuminate\Console\Command;

class GdprResponseCommand extends Command
{
    protected $signature = 'formforge:gdpr:response
                            {formKey : Form key}
                            {submissionUuid : Submission UUID}
                            {--action=anonymize : Action (anonymize|delete|none)}
                            {--now : Execute immediately}
                            {--at= : Schedule datetime (ignored when --now is set)}
                            {--fields= : Comma-separated payload field paths to anonymize}
                            {--delete-files= : Whether to delete files (true|false)}
                            {--redact-submitter= : Whether to nullify submitter identifiers}
                            {--redact-network= : Whether to nullify IP/user-agent}
                            {--reason= : Optional reason}
                            {--owner-type= : Optional owner type for scoped matching}
                            {--owner-id= : Optional owner id for scoped matching}';

    protected $description = 'Schedule or execute a GDPR action for a specific FormForge submission';

    public function handle(SubmissionPrivacyService $privacy): int
    {
        $formKey = trim((string) $this->argument('formKey'));
        $submissionUuid = trim((string) $this->argument('submissionUuid'));
        $ownerType = $this->normalizeOptionalString($this->option('owner-type'));
        $ownerId = $this->normalizeOptionalString($this->option('owner-id'));
        $owner = null;

        if (($ownerType === null) !== ($ownerId === null)) {
            $this->error('Both --owner-type and --owner-id must be provided together.');

            return self::FAILURE;
        }

        if ($ownerType !== null && $ownerId !== null) {
            $owner = OwnershipReference::from([
                'type' => $ownerType,
                'id' => $ownerId,
            ]);
        }

        try {
            $result = $privacy->scheduleResponseAction(
                formKey: $formKey,
                submissionUuid: $submissionUuid,
                action: (string) ($this->option('action') ?? 'anonymize'),
                input: [
                    'now' => (bool) $this->option('now'),
                    'at' => $this->option('at'),
                    'fields' => $this->option('fields'),
                    'delete_files' => $this->option('delete-files'),
                    'redact_submitter' => $this->option('redact-submitter'),
                    'redact_network' => $this->option('redact-network'),
                    'reason' => $this->option('reason'),
                ],
                owner: $owner,
                requestedBy: null,
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        /** @var \EvanSchleret\FormForge\Models\SubmissionPrivacyOverride $override */
        $override = $result['override'];

        $this->table(['field', 'value'], [
            ['override_id', (string) $override->getKey()],
            ['action', (string) $override->action],
            ['execute_at', (string) ($override->execute_at?->toIso8601String() ?? '')],
            ['processed_at', (string) ($override->processed_at?->toIso8601String() ?? '')],
            ['executed', (bool) $result['executed'] ? 'yes' : 'no'],
            ['anonymized', (bool) $result['anonymized'] ? 'yes' : 'no'],
            ['deleted', (bool) $result['deleted'] ? 'yes' : 'no'],
        ]);

        $this->info('GDPR response action scheduled.');

        return self::SUCCESS;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

