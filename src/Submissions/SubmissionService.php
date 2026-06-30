<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Automations\SubmissionAutomationDispatcher;
use EvanSchleret\FormForge\Definition\FieldType;
use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Support\ModelClassResolver;
use EvanSchleret\FormForge\Support\FormSchemaLayout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubmissionService
{
    public function __construct(
        private readonly SubmissionValidator $validator,
        private readonly UploadManager $uploadManager,
        private readonly SubmissionAutomationDispatcher $automations,
    ) {
    }

    public function submit(
        array $schema,
        array $payload,
        ?Model $submittedBy = null,
        ?Request $request = null,
        bool $isTest = false,
        array $submissionMeta = [],
    ): FormSubmission
    {
        $normalizedInputPayload = $this->validator->normalizeInputPayload($schema, $payload);
        $effectiveSchema = FormSchemaLayout::resolve($schema, $normalizedInputPayload);
        $validated = $this->validator->validate($effectiveSchema, $normalizedInputPayload);
        $fields = Arr::get($effectiveSchema, 'fields', []);

        if (! is_array($fields)) {
            $fields = [];
        }

        $request = $request ?? $this->resolveRequest();
        $storeIp = (bool) config('formforge.submissions.store_ip', true);
        $storeUserAgent = (bool) config('formforge.submissions.store_user_agent', true);

        $submission = DB::transaction(function () use ($effectiveSchema, $fields, $validated, $submittedBy, $request, $storeIp, $storeUserAgent, $isTest, $submissionMeta): FormSubmission {
            [$normalizedPayload, $files] = $this->normalizePayload($effectiveSchema, $fields, $validated, $submittedBy);

            $meta = $submissionMeta;

            if (! is_array($meta)) {
                $meta = [];
            }

            $submission = ModelClassResolver::formSubmission()::query()->create([
                'form_key' => (string) ($effectiveSchema['key'] ?? ''),
                'form_version' => (string) ($effectiveSchema['version'] ?? ''),
                'payload' => $normalizedPayload,
                'is_test' => $isTest,
                'submitted_by_type' => $submittedBy?->getMorphClass(),
                'submitted_by_id' => $submittedBy?->getKey(),
                'ip_address' => $storeIp ? $request?->ip() : null,
                'user_agent' => $storeUserAgent ? $request?->userAgent() : null,
                'meta' => $meta,
            ]);

            foreach ($files as $row) {
                $submission->files()->create([
                    'field_key' => $row['field_key'],
                    'disk' => $row['disk'],
                    'path' => $row['path'],
                    'original_name' => $row['original_name'],
                    'stored_name' => $row['stored_name'],
                    'mime_type' => $row['mime_type'],
                    'extension' => $row['extension'],
                    'size' => $row['size'],
                    'checksum' => $row['checksum'],
                    'metadata' => $row['metadata'],
                ]);
            }

            return $submission;
        });

        $submission = $submission->load('files');
        $this->automations->dispatch($submission);

        return $submission;
    }

    public function validateField(array $schema, string $field, mixed $value): array
    {
        $effectiveSchema = FormSchemaLayout::resolve($schema, [$field => $value]);

        return $this->validator->validateField($effectiveSchema, $field, $value);
    }

    public function validateFieldWithLocale(array $schema, string $field, mixed $value, ?string $locale = null): array
    {
        $effectiveSchema = FormSchemaLayout::resolve($schema, [$field => $value]);

        return $this->validator->validateField($effectiveSchema, $field, $value, $locale);
    }

    public function exportableFields(array $schema): array
    {
        return $this->validator->exportableFields($schema);
    }

    public function flattenExportableFields(array $schema): array
    {
        return $this->validator->flattenExportableFields($schema);
    }

    public function resolveExportableField(array $schema, string $identifier): ?array
    {
        return $this->validator->resolveExportableField($schema, $identifier);
    }

    public function validateExportableHeaders(array $schema, array $headers): array
    {
        return $this->validator->validateExportableHeaders($schema, $headers);
    }

    public function mapExportableRow(array $schema, array $row, bool $strict = true): array
    {
        return $this->validator->mapExportableRow($schema, $row, $strict);
    }

    public function describeFields(array $schema): array
    {
        $effectiveSchema = FormSchemaLayout::resolve($schema);

        return $this->validator->describeFields($effectiveSchema);
    }

    public function resolveField(array $schema, string $identifier): ?array
    {
        $effectiveSchema = FormSchemaLayout::resolve($schema);

        return $this->validator->resolveField($effectiveSchema, $identifier);
    }

    public function validateFields(array $schema, array $payload, array $onlyFields = []): array
    {
        $normalizedInputPayload = $this->validator->normalizeInputPayload($schema, $payload);
        $effectiveSchema = FormSchemaLayout::resolve($schema, $normalizedInputPayload);

        return $this->validator->validateFields($effectiveSchema, $normalizedInputPayload, $onlyFields);
    }

    public function validateFieldsWithLocale(array $schema, array $payload, array $onlyFields = [], ?string $locale = null): array
    {
        $normalizedInputPayload = $this->validator->normalizeInputPayload($schema, $payload);
        $effectiveSchema = FormSchemaLayout::resolve($schema, $normalizedInputPayload);

        return $this->validator->validateFields($effectiveSchema, $normalizedInputPayload, $onlyFields, $locale);
    }

    private function normalizePayload(array $schema, array $fields, array $validated, ?Model $submittedBy = null): array
    {
        $payload = [];
        $files = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? '');

            if ($name === '' || $type === '') {
                continue;
            }

            $hasValue = array_key_exists($name, $validated);

            if (! $hasValue) {
                if (array_key_exists('default', $field) && $field['default'] !== null) {
                    $payload[$name] = $this->normalizeValue($field, $field['default']);
                }

                continue;
            }

            $value = $validated[$name];

            if ($value === null) {
                $payload[$name] = null;
                continue;
            }

            if ($type === FieldType::FILE) {
                $processed = $this->uploadManager->process($schema, $field, $value, $submittedBy);
                $payload[$name] = $processed['payload'];

                foreach ($processed['files'] as $file) {
                    $files[] = [
                        'field_key' => (string) ($field['field_key'] ?? $name),
                        'disk' => (string) ($file['disk'] ?? ''),
                        'path' => (string) ($file['path'] ?? ''),
                        'original_name' => (string) ($file['original_name'] ?? ''),
                        'stored_name' => (string) ($file['stored_name'] ?? ''),
                        'mime_type' => (string) ($file['mime_type'] ?? ''),
                        'extension' => (string) ($file['extension'] ?? ''),
                        'size' => (int) ($file['size'] ?? 0),
                        'checksum' => (string) ($file['checksum'] ?? ''),
                        'metadata' => is_array($file['metadata'] ?? null) ? $file['metadata'] : [],
                    ];
                }

                continue;
            }

            $payload[$name] = $this->normalizeValue($field, $value);
        }

        return [$payload, $files];
    }

    private function normalizeValue(array $field, mixed $value): mixed
    {
        $type = FieldType::normalize((string) ($field['type'] ?? ''));
        $temporalMode = (string) ($field['temporal_mode'] ?? FieldType::temporalMode((string) ($field['type'] ?? '')) ?? 'date');

        return match ($type) {
            FieldType::TEXT,
            FieldType::RADIO => (string) $value,

            FieldType::NUMBER => $this->normalizeNumber($value),

            FieldType::CONSENT => (bool) $value,

            FieldType::CHECKBOX_GROUP => array_values(is_array($value) ? $value : [$value]),

            FieldType::TEMPORAL => match ($temporalMode) {
                'date' => Carbon::parse((string) $value)->format('Y-m-d'),
                'time' => Carbon::parse((string) $value)->format('H:i:s'),
                default => $value,
            },

            FieldType::DATE => Carbon::parse((string) $value)->format('Y-m-d'),

            FieldType::TIME => Carbon::parse((string) $value)->format('H:i:s'),

            default => $value,
        };
    }

    private function normalizeNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $raw = trim((string) $value);

        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return (float) $raw;
    }

    private function resolveRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }
}
