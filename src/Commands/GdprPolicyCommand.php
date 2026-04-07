<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Submissions\SubmissionPrivacyService;
use Illuminate\Console\Command;

class GdprPolicyCommand extends Command
{
    protected $signature = 'formforge:gdpr:policy
                            {scope : Policy scope (global|form)}
                            {--form= : Form key when scope=form}
                            {--action=none : Action (none|anonymize|delete)}
                            {--after-days= : Delay in days before action}
                            {--fields= : Comma-separated payload field paths to anonymize}
                            {--delete-files= : Whether to delete files on action (true|false)}
                            {--redact-submitter= : Whether to nullify submitter identifiers}
                            {--redact-network= : Whether to nullify IP/user-agent}
                            {--enabled=1 : Enable/disable policy (true|false)}';

    protected $description = 'Create or update FormForge GDPR policy (global or form)';

    public function handle(SubmissionPrivacyService $privacy): int
    {
        $scope = strtolower(trim((string) $this->argument('scope')));

        if (! in_array($scope, ['global', 'form'], true)) {
            $this->error('Scope must be one of: global, form.');

            return self::FAILURE;
        }

        $payload = [
            'action' => $this->option('action'),
            'after_days' => $this->option('after-days'),
            'fields' => $this->option('fields'),
            'delete_files' => $this->option('delete-files'),
            'redact_submitter' => $this->option('redact-submitter'),
            'redact_network' => $this->option('redact-network'),
            'enabled' => $this->option('enabled'),
        ];

        try {
            if ($scope === 'global') {
                $policy = $privacy->upsertGlobalPolicy($payload);
            } else {
                $formKey = trim((string) ($this->option('form') ?? ''));

                if ($formKey === '') {
                    $this->error('Option [--form] is required when scope=form.');

                    return self::FAILURE;
                }

                $policy = $privacy->upsertFormPolicy($formKey, $payload);
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['field', 'value'], [
            ['scope', (string) $policy->scope],
            ['form_key', (string) ($policy->form_key ?? '')],
            ['action', (string) $policy->action],
            ['after_days', (string) ($policy->after_days ?? '')],
            ['anonymize_fields', json_encode($policy->anonymize_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'],
            ['delete_files', (bool) $policy->delete_files ? 'yes' : 'no'],
            ['redact_submitter', (bool) $policy->redact_submitter ? 'yes' : 'no'],
            ['redact_network', (bool) $policy->redact_network ? 'yes' : 'no'],
            ['enabled', (bool) $policy->enabled ? 'yes' : 'no'],
        ]);

        $this->info('GDPR policy upserted.');

        return self::SUCCESS;
    }
}

