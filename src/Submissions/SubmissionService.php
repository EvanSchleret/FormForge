<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Automations\SubmissionAutomationDispatcher;
use EvanSchleret\FormForge\Definition\FieldType;
use EvanSchleret\FormForge\Models\FormSubmission;
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
        $effectiveSchema = FormSchemaLayout::resolve($schema, $payload);
        $validated = $this->validator->validate($effectiveSchema, $payload);
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

            $submission = FormSubmission::query()->create([
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
                    $payload[$name] = $this->normalizeValue($type, $field['default']);
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

            $payload[$name] = $this->normalizeValue($type, $value);
        }

        return [$payload, $files];
    }

    private function normalizeValue(string $type, mixed $value): mixed
    {
        return match ($type) {
            FieldType::TEXT,
            FieldType::TEXTAREA,
            FieldType::EMAIL => (string) $value,

            FieldType::NUMBER => $this->normalizeNumber($value),

            FieldType::CHECKBOX,
            FieldType::SWITCH => (bool) $value,

            FieldType::CHECKBOX_GROUP => array_values(is_array($value) ? $value : [$value]),

            FieldType::DATE => Carbon::parse((string) $value)->format('Y-m-d'),

            FieldType::TIME => Carbon::parse((string) $value)->format('H:i:s'),

            FieldType::DATETIME => Carbon::parse((string) $value)->toIso8601String(),

            FieldType::DATE_RANGE => [
                'start' => Carbon::parse((string) Arr::get($value, 'start'))->format('Y-m-d'),
                'end' => Carbon::parse((string) Arr::get($value, 'end'))->format('Y-m-d'),
            ],

            FieldType::DATETIME_RANGE => [
                'start' => Carbon::parse((string) Arr::get($value, 'start'))->toIso8601String(),
                'end' => Carbon::parse((string) Arr::get($value, 'end'))->toIso8601String(),
            ],

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
