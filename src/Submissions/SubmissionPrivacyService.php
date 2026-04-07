<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Models\SubmissionPrivacyOverride;
use EvanSchleret\FormForge\Models\SubmissionPrivacyPolicy;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubmissionPrivacyService
{
    public function __construct(
        private readonly SubmissionReadService $submissions,
    ) {
    }

    public function upsertGlobalPolicy(array $input): SubmissionPrivacyPolicy
    {
        $normalized = $this->normalizePolicyInput($input, null);
        $modelClass = ModelClassResolver::submissionPrivacyPolicy();

        /** @var SubmissionPrivacyPolicy $policy */
        $policy = $modelClass::query()->updateOrCreate(
            [
                'scope' => 'global',
                'form_key' => null,
            ],
            $normalized,
        );

        return $policy->refresh();
    }

    public function upsertFormPolicy(string $formKey, array $input): SubmissionPrivacyPolicy
    {
        $formKey = trim($formKey);

        if ($formKey === '') {
            throw new FormForgeException('Form key is required for form GDPR policy.');
        }

        $normalized = $this->normalizePolicyInput($input, null);
        $modelClass = ModelClassResolver::submissionPrivacyPolicy();

        /** @var SubmissionPrivacyPolicy $policy */
        $policy = $modelClass::query()->updateOrCreate(
            [
                'scope' => 'form',
                'form_key' => $formKey,
            ],
            $normalized,
        );

        return $policy->refresh();
    }

    /**
     * @return array{override: SubmissionPrivacyOverride, executed: bool, deleted: bool, anonymized: bool}
     */
    public function scheduleResponseAction(
        string $formKey,
        string $submissionUuid,
        string $action,
        array $input = [],
        ?OwnershipReference $owner = null,
        ?Model $requestedBy = null,
    ): array {
        $formKey = trim($formKey);
        $submissionUuid = trim($submissionUuid);

        if ($formKey === '' || $submissionUuid === '') {
            throw new FormForgeException('Form key and submission UUID are required.');
        }

        $submission = $this->submissions->findForForm($formKey, $submissionUuid, $owner);

        if (! $submission instanceof FormSubmission) {
            throw new FormForgeException("Submission [{$submissionUuid}] not found for form [{$formKey}].");
        }

        $normalized = $this->normalizePolicyInput($input, $action);
        $now = Carbon::now();
        $executeNow = $this->toBool($input['now'] ?? true) ?? true;
        $executeAt = $this->normalizeDateTime($input['execute_at'] ?? $input['at'] ?? null);

        if ($executeNow) {
            $executeAt = $now;
        }

        if (! $executeNow && ! $executeAt instanceof Carbon) {
            $executeAt = null;
        }

        $modelClass = ModelClassResolver::submissionPrivacyOverride();

        /** @var SubmissionPrivacyOverride $override */
        $override = $modelClass::query()->create([
            'form_submission_id' => (int) $submission->getKey(),
            'action' => (string) $normalized['action'],
            'execute_at' => $executeAt,
            'anonymize_fields' => $normalized['anonymize_fields'],
            'delete_files' => (bool) $normalized['delete_files'],
            'redact_submitter' => (bool) $normalized['redact_submitter'],
            'redact_network' => (bool) $normalized['redact_network'],
            'reason' => $this->normalizeString($input['reason'] ?? null),
            'requested_by_type' => $requestedBy?->getMorphClass(),
            'requested_by_id' => $requestedBy?->getKey(),
        ]);

        $deleted = false;
        $anonymized = false;
        $executed = false;

        if ($executeNow) {
            $decision = $this->decisionFromOverride($override, $now);

            if ($decision !== null) {
                $result = $this->applyDecision($submission->fresh(['files']) ?? $submission, $decision, $now);
                $deleted = $result['deleted'];
                $anonymized = $result['anonymized'];
                $executed = $deleted || $anonymized;
            }
        }

        return [
            'override' => $override->fresh() ?? $override,
            'executed' => $executed,
            'deleted' => $deleted,
            'anonymized' => $anonymized,
        ];
    }

    /**
     * @return array<string, int|bool>
     */
    public function run(array $options = [], ?OwnershipReference $owner = null): array
    {
        $enabled = (bool) config('formforge.gdpr.enabled', true);
        $dryRun = $this->toBool($options['dry_run'] ?? false) ?? false;
        $chunk = max(1, (int) ($options['chunk'] ?? config('formforge.gdpr.runner.chunk', 500)));
        $now = Carbon::now();

        $stats = [
            'enabled' => $enabled,
            'dry_run' => $dryRun,
            'scanned' => 0,
            'eligible' => 0,
            'eligible_anonymize' => 0,
            'eligible_delete' => 0,
            'anonymized' => 0,
            'deleted' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        if (! $enabled) {
            return $stats;
        }

        $query = $this->submissionQuery($owner)->orderBy('id');

        $query->chunkById($chunk, function ($rows) use (&$stats, $now, $dryRun): void {
            foreach ($rows as $row) {
                if (! $row instanceof FormSubmission) {
                    continue;
                }

                $stats['scanned']++;
                $decision = $this->resolveDecision($row, $now);

                if ($decision === null) {
                    $stats['skipped']++;
                    continue;
                }

                $stats['eligible']++;
                $action = (string) $decision['action'];

                if ($action === 'anonymize') {
                    $stats['eligible_anonymize']++;
                } elseif ($action === 'delete') {
                    $stats['eligible_delete']++;
                }

                if ($dryRun) {
                    continue;
                }

                $result = $this->applyDecision($row, $decision, $now);

                if ($result['deleted']) {
                    $stats['deleted']++;
                } elseif ($result['anonymized']) {
                    $stats['anonymized']++;
                } else {
                    $stats['failed']++;
                }
            }
        }, 'id');

        return $stats;
    }

    private function applyDecision(FormSubmission $submission, array $decision, Carbon $now): array
    {
        $action = (string) ($decision['action'] ?? 'none');
        $overrideId = is_numeric($decision['override_id'] ?? null) ? (int) $decision['override_id'] : null;
        $deleted = false;
        $anonymized = false;

        try {
            if ($action === 'delete') {
                $deleted = $this->deleteSubmission($submission, (bool) ($decision['delete_files'] ?? true));
            } elseif ($action === 'anonymize') {
                $anonymized = $this->anonymizeSubmission(
                    submission: $submission,
                    anonymizeFields: is_array($decision['anonymize_fields'] ?? null) ? $decision['anonymize_fields'] : [],
                    deleteFiles: (bool) ($decision['delete_files'] ?? false),
                    redactSubmitter: (bool) ($decision['redact_submitter'] ?? true),
                    redactNetwork: (bool) ($decision['redact_network'] ?? true),
                    now: $now,
                );
            }
        } catch (\Throwable) {
            return [
                'deleted' => false,
                'anonymized' => false,
            ];
        }

        if (($deleted || $anonymized) && $overrideId !== null) {
            $this->markOverrideProcessed($overrideId, $now);
        }

        return [
            'deleted' => $deleted,
            'anonymized' => $anonymized,
        ];
    }

    private function deleteSubmission(FormSubmission $submission, bool $deleteFiles): bool
    {
        return DB::transaction(function () use ($submission, $deleteFiles): bool {
            if ($deleteFiles) {
                $this->deleteFiles($submission);
            }

            return (bool) $submission->delete();
        });
    }

    private function anonymizeSubmission(
        FormSubmission $submission,
        array $anonymizeFields,
        bool $deleteFiles,
        bool $redactSubmitter,
        bool $redactNetwork,
        Carbon $now,
    ): bool {
        return DB::transaction(function () use ($submission, $anonymizeFields, $deleteFiles, $redactSubmitter, $redactNetwork, $now): bool {
            $payload = is_array($submission->payload) ? $submission->payload : [];

            if ($anonymizeFields === []) {
                $payload = [];
            } else {
                foreach ($anonymizeFields as $fieldPath) {
                    $path = $this->normalizeString($fieldPath);

                    if ($path === null) {
                        continue;
                    }

                    Arr::set($payload, $path, '[redacted]');
                }
            }

            if ($deleteFiles) {
                $this->deleteFiles($submission);
                $payload = $this->scrubFileReferences($payload);
            }

            $updates = [
                'payload' => $payload,
                'anonymized_at' => $now,
            ];

            if ($redactSubmitter) {
                $updates['submitted_by_type'] = null;
                $updates['submitted_by_id'] = null;
            }

            if ($redactNetwork) {
                $updates['ip_address'] = null;
                $updates['user_agent'] = null;
            }

            return $submission->update($updates);
        });
    }

    private function deleteFiles(FormSubmission $submission): void
    {
        $submission->loadMissing('files');

        foreach ($submission->files as $file) {
            $disk = trim((string) ($file->disk ?? ''));
            $path = trim((string) ($file->path ?? ''));

            if ($disk !== '' && $path !== '') {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable) {
                }
            }

            try {
                $file->delete();
            } catch (\Throwable) {
            }
        }
    }

    private function scrubFileReferences(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->looksLikeStoredFile($value)) {
            return ['redacted' => true];
        }

        foreach ($value as $key => $entry) {
            $value[$key] = $this->scrubFileReferences($entry);
        }

        return $value;
    }

    private function looksLikeStoredFile(array $entry): bool
    {
        $disk = $entry['disk'] ?? null;
        $path = $entry['path'] ?? null;

        return is_string($disk) && trim($disk) !== '' && is_string($path) && trim($path) !== '';
    }

    private function markOverrideProcessed(int $overrideId, Carbon $now): void
    {
        $modelClass = ModelClassResolver::submissionPrivacyOverride();

        $modelClass::query()
            ->whereKey($overrideId)
            ->update([
                'processed_at' => $now,
            ]);
    }

    private function resolveDecision(FormSubmission $submission, Carbon $now): ?array
    {
        $override = $this->pendingOverrideForSubmission((int) $submission->getKey(), $now);

        if ($override instanceof SubmissionPrivacyOverride) {
            return $this->decisionFromOverride($override, $now);
        }

        $formPolicy = $this->formPolicyForKey((string) $submission->form_key);

        if ($formPolicy instanceof SubmissionPrivacyPolicy) {
            return $this->decisionFromPolicy($formPolicy, $submission, $now, 'form');
        }

        $globalPolicy = $this->globalPolicy();

        if ($globalPolicy instanceof SubmissionPrivacyPolicy) {
            return $this->decisionFromPolicy($globalPolicy, $submission, $now, 'global');
        }

        $default = $this->normalizePolicyInput((array) config('formforge.gdpr.default_policy', []), null);

        return $this->decisionFromArray($default, $submission, $now, 'default');
    }

    private function decisionFromOverride(SubmissionPrivacyOverride $override, Carbon $now): ?array
    {
        $action = $this->normalizeAction((string) ($override->action ?? 'none'));

        if ($action === 'none') {
            $this->markOverrideProcessed((int) $override->getKey(), $now);

            return null;
        }

        return [
            'action' => $action,
            'anonymize_fields' => is_array($override->anonymize_fields) ? $override->anonymize_fields : [],
            'delete_files' => (bool) ($override->delete_files ?? false),
            'redact_submitter' => (bool) ($override->redact_submitter ?? true),
            'redact_network' => (bool) ($override->redact_network ?? true),
            'source' => 'override',
            'override_id' => (int) $override->getKey(),
        ];
    }

    private function decisionFromPolicy(
        SubmissionPrivacyPolicy $policy,
        FormSubmission $submission,
        Carbon $now,
        string $source,
    ): ?array {
        return $this->decisionFromArray([
            'action' => (string) ($policy->action ?? 'none'),
            'after_days' => $policy->after_days,
            'anonymize_fields' => is_array($policy->anonymize_fields) ? $policy->anonymize_fields : [],
            'delete_files' => (bool) ($policy->delete_files ?? false),
            'redact_submitter' => (bool) ($policy->redact_submitter ?? true),
            'redact_network' => (bool) ($policy->redact_network ?? true),
            'enabled' => (bool) ($policy->enabled ?? true),
        ], $submission, $now, $source);
    }

    private function decisionFromArray(array $policy, FormSubmission $submission, Carbon $now, string $source): ?array
    {
        if (! (bool) ($policy['enabled'] ?? true)) {
            return null;
        }

        $action = $this->normalizeAction((string) ($policy['action'] ?? 'none'));

        if ($action === 'none') {
            return null;
        }

        $afterDays = $this->normalizeAfterDays($policy['after_days'] ?? null);

        if ($afterDays !== null) {
            $threshold = $now->copy()->subDays($afterDays);
            $createdAt = $submission->created_at;

            if (! $createdAt instanceof Carbon || $createdAt->greaterThan($threshold)) {
                return null;
            }
        }

        return [
            'action' => $action,
            'anonymize_fields' => is_array($policy['anonymize_fields'] ?? null) ? $policy['anonymize_fields'] : [],
            'delete_files' => (bool) ($policy['delete_files'] ?? false),
            'redact_submitter' => (bool) ($policy['redact_submitter'] ?? true),
            'redact_network' => (bool) ($policy['redact_network'] ?? true),
            'source' => $source,
            'override_id' => null,
        ];
    }

    private function pendingOverrideForSubmission(int $submissionId, Carbon $now): ?SubmissionPrivacyOverride
    {
        $modelClass = ModelClassResolver::submissionPrivacyOverride();

        /** @var SubmissionPrivacyOverride|null $override */
        $override = $modelClass::query()
            ->where('form_submission_id', $submissionId)
            ->whereNull('processed_at')
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('execute_at')
                    ->orWhere('execute_at', '<=', $now);
            })
            ->orderByDesc('id')
            ->first();

        return $override;
    }

    private function formPolicyForKey(string $formKey): ?SubmissionPrivacyPolicy
    {
        $modelClass = ModelClassResolver::submissionPrivacyPolicy();

        /** @var SubmissionPrivacyPolicy|null $policy */
        $policy = $modelClass::query()
            ->forForm($formKey)
            ->where('enabled', true)
            ->orderByDesc('id')
            ->first();

        return $policy;
    }

    private function globalPolicy(): ?SubmissionPrivacyPolicy
    {
        $modelClass = ModelClassResolver::submissionPrivacyPolicy();

        /** @var SubmissionPrivacyPolicy|null $policy */
        $policy = $modelClass::query()
            ->global()
            ->whereNull('form_key')
            ->where('enabled', true)
            ->orderByDesc('id')
            ->first();

        return $policy;
    }

    private function submissionQuery(?OwnershipReference $owner): Builder
    {
        $submissionClass = ModelClassResolver::formSubmission();
        $query = $submissionClass::query()->withoutGlobalScopes();

        if (! $owner instanceof OwnershipReference) {
            return $query;
        }

        $submissionTable = $query->getModel()->getTable();
        $formsTable = (string) config('formforge.database.forms_table', 'formforge_forms');

        $query->whereExists(function ($exists) use ($submissionTable, $formsTable, $owner): void {
            $exists->selectRaw('1')
                ->from("{$formsTable} as scoped_forms")
                ->whereColumn('scoped_forms.key', "{$submissionTable}.form_key")
                ->where('scoped_forms.owner_type', $owner->type)
                ->where('scoped_forms.owner_id', $owner->id);
        });

        return $query;
    }

    private function normalizePolicyInput(array $input, ?string $forcedAction): array
    {
        $default = (array) config('formforge.gdpr.default_policy', []);
        $action = $forcedAction ?? (string) ($input['action'] ?? $default['action'] ?? 'none');
        $normalizedAction = $this->normalizeAction($action);
        $defaultDeleteFiles = $normalizedAction === 'delete'
            ? true
            : (bool) ($default['delete_files'] ?? false);

        return [
            'action' => $normalizedAction,
            'after_days' => $this->normalizeAfterDays($input['after_days'] ?? $default['after_days'] ?? null),
            'anonymize_fields' => $this->normalizeFields($input['anonymize_fields'] ?? $input['fields'] ?? $default['anonymize_fields'] ?? []),
            'delete_files' => $this->toBool($input['delete_files'] ?? $defaultDeleteFiles) ?? $defaultDeleteFiles,
            'redact_submitter' => $this->toBool($input['redact_submitter'] ?? $default['redact_submitter'] ?? true) ?? true,
            'redact_network' => $this->toBool($input['redact_network'] ?? $default['redact_network'] ?? true) ?? true,
            'enabled' => $this->toBool($input['enabled'] ?? $default['enabled'] ?? true) ?? true,
        ];
    }

    private function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));

        if (! in_array($action, ['none', 'anonymize', 'delete'], true)) {
            throw new FormForgeException("Unsupported GDPR action [{$action}]. Allowed values: none, anonymize, delete.");
        }

        return $action;
    }

    private function normalizeAfterDays(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new FormForgeException('GDPR [after_days] must be numeric or null.');
        }

        $days = (int) $value;

        if ($days < 0) {
            throw new FormForgeException('GDPR [after_days] must be greater than or equal to 0.');
        }

        return $days;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeFields(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $fields = [];

        foreach ($value as $item) {
            $field = $this->normalizeString($item);

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    private function normalizeDateTime(mixed $value): ?Carbon
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }
}
