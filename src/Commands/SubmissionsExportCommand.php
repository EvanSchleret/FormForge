<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Submissions\SubmissionExportService;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

class SubmissionsExportCommand extends Command
{
    protected $signature = 'formforge:submissions:export
        {formKey : Form key to export}
        {--format=csv : Export format (csv|jsonl)}
        {--output= : Output file path}
        {--owner-type= : Optional owner type for scoped export}
        {--owner-id= : Optional owner id for scoped export}
        {--form-version= : Filter by form version}
        {--is-test= : Filter by test mode (true|false|1|0)}
        {--submitted-by-type= : Filter by submitter morph type}
        {--submitted-by-id= : Filter by submitter id}
        {--has-files= : Filter by file presence (true|false|1|0)}
        {--from= : Filter created_at from datetime}
        {--to= : Filter created_at to datetime}
        {--no-header : Disable CSV header row}';

    protected $description = 'Export FormForge submissions to CSV or JSONL with filters';

    public function handle(SubmissionExportService $exports, Filesystem $files): int
    {
        $formKey = trim((string) $this->argument('formKey'));
        $format = trim((string) ($this->option('format') ?? 'csv'));
        $owner = $this->resolveOwner();
        $filters = $this->resolveFilters();
        $withHeader = ! (bool) $this->option('no-header');

        if ($formKey === '') {
            $this->error('Argument [formKey] cannot be empty.');

            return self::FAILURE;
        }

        $output = $this->resolveOutputPath($formKey, $format);
        $directory = dirname($output);

        if ($directory === '' || $directory === '.') {
            $directory = getcwd() ?: '.';
        }

        $files->ensureDirectoryExists($directory);

        try {
            $count = $exports->exportToPath($output, $formKey, $format, $filters, $owner, $withHeader);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Export generated: {$output}");
        $this->line("Rows exported: {$count}");

        return self::SUCCESS;
    }

    private function resolveOutputPath(string $formKey, string $format): string
    {
        $provided = $this->normalizeOptionalString($this->option('output'));

        if ($provided !== null) {
            return $provided;
        }

        $normalizedFormat = strtolower(trim($format));
        $extension = $normalizedFormat === 'jsonl' ? 'jsonl' : 'csv';
        $timestamp = Carbon::now()->format('Ymd_His');
        $safeFormKey = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $formKey);
        $safeFormKey = is_string($safeFormKey) && $safeFormKey !== '' ? $safeFormKey : 'form';

        return storage_path("app/formforge/exports/{$safeFormKey}_{$timestamp}.{$extension}");
    }

    private function resolveOwner(): ?OwnershipReference
    {
        $type = $this->normalizeOptionalString($this->option('owner-type'));
        $id = $this->normalizeOptionalString($this->option('owner-id'));

        if ($type === null && $id === null) {
            return null;
        }

        if ($type === null || $id === null) {
            throw new \InvalidArgumentException('Both --owner-type and --owner-id must be provided together.');
        }

        return OwnershipReference::from([
            'type' => $type,
            'id' => $id,
        ]);
    }

    private function resolveFilters(): array
    {
        return [
            'version' => $this->normalizeOptionalString($this->option('form-version')),
            'is_test' => $this->normalizeOptionalString($this->option('is-test')),
            'submitted_by_type' => $this->normalizeOptionalString($this->option('submitted-by-type')),
            'submitted_by_id' => $this->normalizeOptionalString($this->option('submitted-by-id')),
            'has_files' => $this->normalizeOptionalString($this->option('has-files')),
            'from' => $this->normalizeOptionalString($this->option('from')),
            'to' => $this->normalizeOptionalString($this->option('to')),
        ];
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
