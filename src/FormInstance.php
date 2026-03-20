<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge;

use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Submissions\SubmissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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

    public function toArray(): array
    {
        return $this->schema;
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
}
