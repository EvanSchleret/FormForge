<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionExportService
{
    private const CSV_COLUMNS = [
        'id',
        'form_key',
        'form_version',
        'is_test',
        'submitted_by_type',
        'submitted_by_id',
        'ip_address',
        'user_agent',
        'created_at',
        'updated_at',
        'payload_json',
        'files_json',
        'meta_json',
    ];

    public function __construct(
        private readonly SubmissionReadService $submissions,
    ) {
    }

    public function exportToString(
        string $formKey,
        string $format = 'csv',
        array $filters = [],
        ?OwnershipReference $owner = null,
        bool $withHeader = true,
    ): string {
        $stream = fopen('php://temp', 'w+b');

        if (! is_resource($stream)) {
            throw new FormForgeException('Unable to open temporary stream for export.');
        }

        try {
            $this->streamToHandle($stream, $formKey, $format, $filters, $owner, $withHeader);
            rewind($stream);
            $content = stream_get_contents($stream);

            return is_string($content) ? $content : '';
        } finally {
            fclose($stream);
        }
    }

    public function exportToPath(
        string $path,
        string $formKey,
        string $format = 'csv',
        array $filters = [],
        ?OwnershipReference $owner = null,
        bool $withHeader = true,
    ): int {
        $stream = fopen($path, 'w+b');

        if (! is_resource($stream)) {
            throw new FormForgeException("Unable to open export output path [{$path}].");
        }

        try {
            return $this->streamToHandle($stream, $formKey, $format, $filters, $owner, $withHeader);
        } finally {
            fclose($stream);
        }
    }

    public function downloadResponse(
        string $formKey,
        string $format = 'csv',
        array $filters = [],
        ?OwnershipReference $owner = null,
        ?string $filename = null,
        bool $withHeader = true,
    ): StreamedResponse {
        $resolvedFormat = $this->normalizeFormat($format);
        $downloadName = $this->normalizeFilename($filename, $formKey, $resolvedFormat);

        return response()->streamDownload(
            function () use ($formKey, $resolvedFormat, $filters, $owner, $withHeader): void {
                $stream = fopen('php://output', 'wb');

                if (! is_resource($stream)) {
                    throw new FormForgeException('Unable to open output stream for export.');
                }

                try {
                    $this->streamToHandle($stream, $formKey, $resolvedFormat, $filters, $owner, $withHeader);
                } finally {
                    fclose($stream);
                }
            },
            $downloadName,
            [
                'Content-Type' => $this->contentTypeFor($resolvedFormat),
            ],
        );
    }

    public function streamToHandle(
        mixed $handle,
        string $formKey,
        string $format = 'csv',
        array $filters = [],
        ?OwnershipReference $owner = null,
        bool $withHeader = true,
    ): int {
        if (! is_resource($handle)) {
            throw new \InvalidArgumentException('Export handle must be a valid stream resource.');
        }

        $resolvedFormat = $this->normalizeFormat($format);
        $query = $this->submissions
            ->queryForForm($formKey, $filters, $owner, true)
            ->orderBy('id');

        $count = 0;

        if ($resolvedFormat === 'csv' && $withHeader) {
            fputcsv($handle, self::CSV_COLUMNS);
        }

        $query->chunkById(250, function ($rows) use ($resolvedFormat, $handle, &$count): void {
            foreach ($rows as $row) {
                if (! $row instanceof FormSubmission) {
                    continue;
                }

                $serialized = $this->serializeSubmission($row);

                if ($resolvedFormat === 'csv') {
                    fputcsv($handle, $this->toCsvRow($serialized));
                } else {
                    fwrite($handle, $this->toJsonLine($serialized));
                }

                $count++;
            }
        }, 'id');

        return $count;
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        if (! in_array($format, ['csv', 'jsonl'], true)) {
            throw new FormForgeException("Unsupported export format [{$format}]. Allowed values: csv, jsonl.");
        }

        return $format;
    }

    private function normalizeFilename(?string $filename, string $formKey, string $format): string
    {
        $candidate = is_string($filename) ? trim($filename) : '';

        if ($candidate !== '') {
            return $candidate;
        }

        $timestamp = now()->format('Ymd_His');
        $safeFormKey = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', trim($formKey));
        $safeFormKey = is_string($safeFormKey) && $safeFormKey !== '' ? $safeFormKey : 'form';

        return "formforge_{$safeFormKey}_submissions_{$timestamp}.{$format}";
    }

    private function contentTypeFor(string $format): string
    {
        if ($format === 'jsonl') {
            return 'application/x-ndjson';
        }

        return 'text/csv; charset=UTF-8';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubmission(FormSubmission $submission): array
    {
        $submission->loadMissing('files');

        $files = [];

        foreach ($submission->files as $file) {
            $files[] = [
                'field_key' => (string) ($file->field_key ?? ''),
                'disk' => (string) ($file->disk ?? ''),
                'path' => (string) ($file->path ?? ''),
                'original_name' => (string) ($file->original_name ?? ''),
                'stored_name' => (string) ($file->stored_name ?? ''),
                'mime_type' => (string) ($file->mime_type ?? ''),
                'extension' => (string) ($file->extension ?? ''),
                'size' => (int) ($file->size ?? 0),
                'checksum' => (string) ($file->checksum ?? ''),
                'metadata' => is_array($file->metadata) ? $file->metadata : [],
            ];
        }

        return [
            'id' => (string) ($submission->uuid ?? ''),
            'form_key' => (string) ($submission->form_key ?? ''),
            'form_version' => (string) ($submission->form_version ?? ''),
            'is_test' => (bool) ($submission->is_test ?? false),
            'submitted_by_type' => $this->nullableString($submission->submitted_by_type ?? null),
            'submitted_by_id' => $this->nullableString($submission->submitted_by_id ?? null),
            'ip_address' => $this->nullableString($submission->ip_address ?? null),
            'user_agent' => $this->nullableString($submission->user_agent ?? null),
            'created_at' => $submission->created_at?->toIso8601String(),
            'updated_at' => $submission->updated_at?->toIso8601String(),
            'payload' => is_array($submission->payload) ? $submission->payload : [],
            'files' => $files,
            'meta' => is_array($submission->meta) ? $submission->meta : [],
        ];
    }

    /**
     * @param array<string, mixed> $submission
     * @return array<int, string>
     */
    private function toCsvRow(array $submission): array
    {
        return [
            (string) ($submission['id'] ?? ''),
            (string) ($submission['form_key'] ?? ''),
            (string) ($submission['form_version'] ?? ''),
            $this->boolString($submission['is_test'] ?? false),
            (string) ($submission['submitted_by_type'] ?? ''),
            (string) ($submission['submitted_by_id'] ?? ''),
            (string) ($submission['ip_address'] ?? ''),
            (string) ($submission['user_agent'] ?? ''),
            (string) ($submission['created_at'] ?? ''),
            (string) ($submission['updated_at'] ?? ''),
            $this->jsonEncode($submission['payload'] ?? []),
            $this->jsonEncode($submission['files'] ?? []),
            $this->jsonEncode($submission['meta'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $submission
     */
    private function toJsonLine(array $submission): string
    {
        return $this->jsonEncode($submission) . "\n";
    }

    private function jsonEncode(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function boolString(mixed $value): string
    {
        return (bool) $value ? '1' : '0';
    }
}

