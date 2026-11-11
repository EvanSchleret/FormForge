<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\Models\StagedUpload;
use EvanSchleret\FormForge\Support\Uploads\UploadPolicy;
use EvanSchleret\FormForge\Submissions\StagedUploadService;
use EvanSchleret\FormForge\Tests\Fixtures\RejectingUploadScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

it('enforces the global extension allowlist and detected MIME types', function (): void {
    config()->set('formforge.uploads.allowed_extensions', ['pdf', 'jpg', 'png']);

    $policy = app(UploadPolicy::class);

    expect($policy->acceptedTypes(['image/*']))
        ->toContain('.jpg')
        ->toContain('.png')
        ->not->toContain('.pdf');

    expect($policy->acceptsMetadata([
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
    ], ['.pdf']))->toBeTrue();

    expect($policy->acceptsMetadata([
        'extension' => 'pdf',
        'mime_type' => 'text/plain',
    ], ['.pdf']))->toBeFalse();

    expect($policy->acceptsMetadata([
        'extension' => 'exe',
        'mime_type' => 'application/pdf',
    ], ['.pdf']))->toBeFalse();
});

it('caps upload limits at PHP and field-level ceilings', function (): void {
    config()->set('formforge.uploads.limits.max_file_size', 100 * 1024 * 1024);
    config()->set('formforge.uploads.limits.max_total_size', 200 * 1024 * 1024);
    config()->set('formforge.uploads.limits.max_files', 1000);
    config()->set('formforge.uploads.limits.multipart_reserve', 1024);

    $policy = app(UploadPolicy::class);
    $phpPostLimit = $policy->phpPostLimit();
    $effectivePostLimit = $phpPostLimit === null
        ? PHP_INT_MAX
        : max(1, $phpPostLimit - min(1024, max(16 * 1024, (int) floor($phpPostLimit * 0.05))));

    expect($policy->maxFileSize())->toBeLessThanOrEqual(100 * 1024 * 1024);
    expect($policy->maxFileSize())->toBeLessThanOrEqual($effectivePostLimit);
    expect($policy->maxSubmissionSize())->toBeLessThanOrEqual($effectivePostLimit);
    expect($policy->maxFiles())->toBeLessThanOrEqual(1000);

    if ($policy->phpUploadLimit() !== null) {
        expect($policy->maxFileSize())->toBeLessThanOrEqual($policy->phpUploadLimit());
    }

    if ($policy->phpMaxFiles() !== null) {
        expect($policy->maxFiles())->toBeLessThanOrEqual($policy->phpMaxFiles());
    }

    expect($policy->maxFileSize(4096))->toBeLessThanOrEqual(4096);
    expect($policy->maxFieldTotalSize(8192))->toBeLessThanOrEqual(8192);
    expect($policy->maxFiles(2))->toBeLessThanOrEqual(2);
});

it('exposes PHP-capped upload limits and field limits in the form schema', function (): void {
    $key = 'upload_limits_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->file('attachments')
            ->multiple()
            ->maxSize(PHP_INT_MAX)
            ->maxFiles(PHP_INT_MAX)
            ->maxTotalSize(PHP_INT_MAX);

    $schema = Form::get($key, '1')->toArray();
    $field = $schema['fields'][0];

    expect($schema['upload_limits']['max_file_size'])->toBeGreaterThan(0);
    expect($field['max_size'])->toBeLessThanOrEqual($schema['upload_limits']['max_file_size']);
    expect($field['max_files'])->toBeLessThanOrEqual($schema['upload_limits']['max_files']);
    expect($field['max_total_size'])->toBeLessThanOrEqual($schema['upload_limits']['max_total_size']);
});

it('rejects a managed upload when scanning fails before storing the file', function (): void {
    Storage::fake('local');

    config()->set('formforge.uploads.mode', 'managed');
    config()->set('formforge.uploads.disk', 'local');
    config()->set('formforge.uploads.scanner.driver', RejectingUploadScanner::class);

    $key = 'scanner_reject_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->file('document')
            ->storageDisk('local');

    expect(static fn () => Form::get($key, '1')->submit([
        'document' => UploadedFile::fake()->create('document.txt', 1, 'text/plain'),
    ]))->toThrow(FormForgeException::class, 'Upload rejected by the test scanner.');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects a staged upload when scanning fails before creating a token or file', function (): void {
    Storage::fake('local');

    config()->set('formforge.uploads.temporary_disk', 'local');
    config()->set('formforge.uploads.scanner.driver', RejectingUploadScanner::class);

    $key = 'staged_scanner_reject_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->file('document');

    $form = Form::get($key, '1')->toArray();

    expect(static fn () => app(StagedUploadService::class)->stage(
        $form,
        $form['fields'][0],
        UploadedFile::fake()->create('document.txt', 1, 'text/plain'),
    ))->toThrow(FormForgeException::class, 'Upload rejected by the test scanner.');

    expect(StagedUpload::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('fails the scanner check when phpMussel is selected but its optional package is missing', function (): void {
    if (class_exists(\phpMussel\Core\Loader::class)) {
        $this->markTestSkipped('phpMussel is installed in this environment.');
    }

    config()->set('formforge.uploads.scanner.driver', 'phpmussel');

    $this->artisan('formforge:scanner:check')
        ->assertExitCode(Command::FAILURE);
});
