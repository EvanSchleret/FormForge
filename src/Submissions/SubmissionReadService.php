<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Ownership\OwnershipManager;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class SubmissionReadService
{
    public function __construct(
        private readonly OwnershipManager $ownership,
    ) {
    }

    public function paginateForForm(string $formKey, int $perPage = 15, array $filters = [], ?OwnershipReference $owner = null): LengthAwarePaginator
    {
        $query = $this->queryForForm($formKey, $filters, $owner, true)
            ->orderByDesc('id');

        return $query->paginate($perPage);
    }

    public function queryForForm(
        string $formKey,
        array $filters = [],
        ?OwnershipReference $owner = null,
        bool $withFiles = false,
    ): Builder {
        $query = ModelClassResolver::formSubmission()::query()
            ->withoutGlobalScopes()
            ->where('form_key', $formKey);

        if ($withFiles) {
            $query->with('files');
        }

        $this->applyOwnerScope($query, $owner);
        $this->applyFilters($query, $filters);

        return $query;
    }

    public function findForForm(string $formKey, string $submissionUuid, ?OwnershipReference $owner = null): ?FormSubmission
    {
        $query = $this->queryForForm($formKey, owner: $owner, withFiles: true)
            ->where('uuid', $submissionUuid);

        return $query->first();
    }

    public function existsForForm(string $formKey, ?OwnershipReference $owner = null): bool
    {
        $query = $this->queryForForm($formKey, owner: $owner);

        return $query->exists();
    }

    public function deleteForForm(string $formKey, string $submissionUuid, ?OwnershipReference $owner = null): bool
    {
        $query = $this->queryForForm($formKey, owner: $owner)
            ->where('uuid', $submissionUuid);
        $submission = $query->first();

        if (! $submission instanceof FormSubmission) {
            return false;
        }

        return (bool) $submission->delete();
    }

    private function applyOwnerScope(Builder $query, ?OwnershipReference $owner): void
    {
        if (! $owner instanceof OwnershipReference) {
            return;
        }

        $formDefinitionModel = ModelClassResolver::formDefinition();
        $submissionTable = $query->getModel()->getTable();

        $query->whereExists(static function ($exists) use ($formDefinitionModel, $owner, $submissionTable): void {
            $table = (new $formDefinitionModel())->getTable();

            $exists
                ->selectRaw('1')
                ->from("{$table} as scoped_forms")
                ->whereColumn('scoped_forms.key', "{$submissionTable}.form_key")
                ->where('scoped_forms.is_active', true)
                ->where('scoped_forms.owner_type', $owner->type)
                ->where('scoped_forms.owner_id', $owner->id);
        });
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $version = $this->normalizeOptionalString($filters['version'] ?? null);

        if ($version !== null) {
            $query->where('form_version', $version);
        }

        $isTest = $this->normalizeOptionalBool($filters['is_test'] ?? null);

        if ($isTest !== null) {
            $query->where('is_test', $isTest);
        }

        $submittedByType = $this->normalizeOptionalString($filters['submitted_by_type'] ?? null);

        if ($submittedByType !== null) {
            $query->where('submitted_by_type', $submittedByType);
        }

        $submittedById = $this->normalizeOptionalString($filters['submitted_by_id'] ?? null);

        if ($submittedById !== null) {
            $query->where('submitted_by_id', $submittedById);
        }

        $hasFiles = $this->normalizeOptionalBool($filters['has_files'] ?? null);

        if ($hasFiles === true) {
            $query->whereHas('files');
        } elseif ($hasFiles === false) {
            $query->whereDoesntHave('files');
        }

        $from = $this->normalizeDateTime(
            $filters['from'] ?? $filters['created_from'] ?? null,
            false,
        );

        if ($from instanceof Carbon) {
            $query->where('created_at', '>=', $from);
        }

        $to = $this->normalizeDateTime(
            $filters['to'] ?? $filters['created_to'] ?? null,
            true,
        );

        if ($to instanceof Carbon) {
            $query->where('created_at', '<=', $to);
        }
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeOptionalBool(mixed $value): ?bool
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

    private function normalizeDateTime(mixed $value, bool $endOfDay): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        if (strlen($value) <= 10) {
            return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
        }

        return $parsed;
    }
}
