<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use Illuminate\Pagination\LengthAwarePaginator;

class SubmissionReadService
{
    public function paginateForForm(string $formKey, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = ModelClassResolver::formSubmission()::query()
            ->where('form_key', $formKey)
            ->with('files')
            ->orderByDesc('id');

        $version = $this->normalizeOptionalString($filters['version'] ?? null);

        if ($version !== null) {
            $query->where('form_version', $version);
        }

        $isTest = $this->normalizeOptionalBool($filters['is_test'] ?? null);

        if ($isTest !== null) {
            $query->where('is_test', $isTest);
        }

        return $query->paginate($perPage);
    }

    public function findForForm(string $formKey, string $submissionUuid): ?FormSubmission
    {
        return ModelClassResolver::formSubmission()::query()
            ->where('form_key', $formKey)
            ->where('uuid', $submissionUuid)
            ->with('files')
            ->first();
    }

    public function existsForForm(string $formKey): bool
    {
        return ModelClassResolver::formSubmission()::query()
            ->where('form_key', $formKey)
            ->exists();
    }

    public function deleteForForm(string $formKey, string $submissionUuid): bool
    {
        $submission = ModelClassResolver::formSubmission()::query()
            ->where('form_key', $formKey)
            ->where('uuid', $submissionUuid)
            ->first();

        if (! $submission instanceof FormSubmission) {
            return false;
        }

        return (bool) $submission->delete();
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
}
