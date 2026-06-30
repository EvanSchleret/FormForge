<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge;

use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Support\FormPublicLinkResolver;
use EvanSchleret\FormForge\Submissions\SubmissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FormInstance
{
    public function __construct(
        private readonly array $schema,
        private readonly SubmissionService $submissionService,
    ) {
    }

    public function key(): string
    {
        return (string) ($this->schema['key'] ?? '');
    }

    public function version(): string
    {
        return (string) ($this->schema['version'] ?? '');
    }

    public function schemaVersion(): int
    {
        return (int) ($this->schema['schema_version'] ?? 1);
    }

    public function title(): string
    {
        return (string) ($this->schema['title'] ?? '');
    }

    public function fields(): array
    {
        $fields = $this->schema['fields'] ?? [];

        return is_array($fields) ? $fields : [];
    }

    public function pages(): array
    {
        $pages = $this->schema['pages'] ?? [];

        return is_array($pages) ? $pages : [];
    }

    public function conditions(): array
    {
        $conditions = $this->schema['conditions'] ?? [];

        return is_array($conditions) ? $conditions : [];
    }

    public function draftsEnabled(): bool
    {
        $drafts = $this->schema['drafts'] ?? [];

        if (! is_array($drafts)) {
            return (bool) config('formforge.drafts.default_enabled', false);
        }

        return (bool) ($drafts['enabled'] ?? config('formforge.drafts.default_enabled', false));
    }

    public function category(): string
    {
        return (string) ($this->schema['category'] ?? config('formforge.forms.default_category', 'general'));
    }

    public function isPublished(): bool
    {
        if (array_key_exists('is_published', $this->schema)) {
            return (bool) $this->schema['is_published'];
        }

        return (bool) config('formforge.forms.default_published', true);
    }

    public function publishAt(): ?Carbon
    {
        return $this->resolveDateTime($this->schema['publish_at'] ?? null);
    }

    public function pauseAt(): ?Carbon
    {
        return $this->resolveDateTime($this->schema['pause_at'] ?? null);
    }

    public function responseLimit(): ?int
    {
        $value = $this->schema['response_limit'] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value !== '' && ctype_digit($value)) {
                $limit = (int) $value;

                return $limit > 0 ? $limit : null;
            }
        }

        return null;
    }

    public function submissionCodeRequired(): bool
    {
        return (bool) ($this->schema['submission_code_required'] ?? false);
    }

    public function publicUrl(): ?string
    {
        $request = request();

        if (! $request instanceof Request) {
            return null;
        }

        $configured = config('formforge.http.public_link.resolver');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        if (! class_exists($configured) || ! is_subclass_of($configured, FormPublicLinkResolver::class)) {
            return null;
        }

        $resolver = app($configured);

        if (! $resolver instanceof FormPublicLinkResolver) {
            return null;
        }

        return $resolver->resolve([
            'key' => $this->key(),
            'version' => $this->version(),
            'schema' => $this->schema,
        ], $request);
    }

    private function resolveDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function toArray(): array
    {
        $schema = $this->schema;
        $schema['public_url'] = $this->publicUrl();

        return $schema;
    }

    public function toJson(int $options = 0): string
    {
        $json = json_encode($this->schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $options);

        return $json === false ? '{}' : $json;
    }

    public function submit(
        array $payload,
        ?Model $submittedBy = null,
        ?Request $request = null,
        bool $isTest = false,
        array $submissionMeta = [],
    ): FormSubmission
    {
        return $this->submissionService->submit(
            schema: $this->schema,
            payload: $payload,
            submittedBy: $submittedBy,
            request: $request,
            isTest: $isTest,
            submissionMeta: $submissionMeta,
        );
    }

    public function validateField(string $field, mixed $value, ?string $locale = null): array
    {
        return $this->submissionService->validateFieldWithLocale($this->schema, $field, $value, $locale);
    }

    public function exportableFields(): array
    {
        return $this->submissionService->exportableFields($this->schema);
    }

    public function flattenExportableFields(): array
    {
        return $this->submissionService->flattenExportableFields($this->schema);
    }

    public function resolveExportableField(string $identifier): ?array
    {
        return $this->submissionService->resolveExportableField($this->schema, $identifier);
    }

    public function validateExportableHeaders(array $headers): array
    {
        return $this->submissionService->validateExportableHeaders($this->schema, $headers);
    }

    public function mapExportableRow(array $row, bool $strict = true): array
    {
        return $this->submissionService->mapExportableRow($this->schema, $row, $strict);
    }

    public function describeFields(): array
    {
        return $this->submissionService->describeFields($this->schema);
    }

    public function resolveField(string $identifier): ?array
    {
        return $this->submissionService->resolveField($this->schema, $identifier);
    }

    public function validateFields(array $payload, array $onlyFields = [], ?string $locale = null): array
    {
        return $this->submissionService->validateFieldsWithLocale($this->schema, $payload, $onlyFields, $locale);
    }
}
