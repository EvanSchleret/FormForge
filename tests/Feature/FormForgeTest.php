<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Exceptions\ImmutableVersionException;
use EvanSchleret\FormForge\Exceptions\InvalidFieldDefinitionException;
use EvanSchleret\FormForge\Exceptions\UnknownFieldsException;
use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Models\StagedUpload;
use EvanSchleret\FormForge\Registry\FormRegistry;
use EvanSchleret\FormForge\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('exports a deterministic schema and keeps generated field_key stable across versions', function (): void {
    $key = 'registration_' . Str::lower(Str::random(8));

    Form::define($key)
        ->title('Registration')
        ->version('1')
        ->text('name')->required()->max(120)
        ->email('email')->required();

    Form::define($key)
        ->title('Registration')
        ->version('2')
        ->text('name')->required()->max(120)
        ->email('email')->required();

    $schemaV1 = Form::get($key, '1')->toArray();
    $schemaV1Again = Form::get($key, '1')->toArray();
    $schemaV2 = Form::get($key, '2')->toArray();

    expect($schemaV1)->toBe($schemaV1Again);
    expect($schemaV1['fields'][0]['field_key'])->toBe($schemaV2['fields'][0]['field_key']);
});

it('rejects required and nullable on the same field', function (): void {
    $key = 'conflict_' . Str::lower(Str::random(8));

    Form::define($key)->version('1')->text('name')->required();

    expect(static fn () => Form::define($key . '_2')->version('1')->text('name')->required()->nullable())
        ->toThrow(InvalidFieldDefinitionException::class);
});

it('syncs runtime definitions idempotently and enforces immutability', function (): void {
    $key = 'sync_' . Str::lower(Str::random(8));

    $manager = app(FormManager::class);

    $manager->define($key)
        ->title('Sync')
        ->version('1')
        ->text('name')->required();

    $first = $manager->sync();
    $second = $manager->sync();

    expect($first['created'])->toBe(1);
    expect($second['unchanged'])->toBe(1);

    app()->instance(FormRegistry::class, new FormRegistry());
    app()->forgetInstance(FormManager::class);

    $freshManager = app(FormManager::class);

    $freshManager->define($key)
        ->title('Sync')
        ->version('1')
        ->text('name')->required()->max(10);

    expect(static fn () => $freshManager->sync())
        ->toThrow(ImmutableVersionException::class);
});

it('rejects unknown fields by default and ignores them when configured', function (): void {
    $key = 'unknown_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $form = Form::get($key, '1');

    expect(static fn () => $form->submit([
        'name' => 'Evan',
        'unexpected' => 'x',
    ]))->toThrow(UnknownFieldsException::class);

    config()->set('formforge.validation.reject_unknown_fields', false);

    $submission = $form->submit([
        'name' => 'Evan',
        'unexpected' => 'x',
    ]);

    expect($submission->payload)->toBe([
        'name' => 'Evan',
    ]);
});

it('normalizes primitive and date range values before persistence', function (): void {
    $key = 'normalize_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->number('age')->required()
        ->number('price')->required()
        ->checkbox('newsletter')->required()
        ->switch('active')->required()
        ->date('birthday')->required()
        ->time('meeting_time')->required()
        ->datetime('meeting_at')->required()
        ->dateRange('vacation')->required()
        ->datetimeRange('window')->required();

    $submission = Form::get($key, '1')->submit([
        'age' => '42',
        'price' => '3.14',
        'newsletter' => '1',
        'active' => true,
        'birthday' => '2026-01-05',
        'meeting_time' => '09:10:11',
        'meeting_at' => '2026-01-05T09:10:11+00:00',
        'vacation' => [
            'start' => '2026-08-01',
            'end' => '2026-08-10',
        ],
        'window' => [
            'start' => '2026-01-05T09:10:11+00:00',
            'end' => '2026-01-05T10:10:11+00:00',
        ],
    ]);

    expect($submission->payload['age'])->toBe(42);
    expect($submission->payload['price'])->toBe(3.14);
    expect($submission->payload['newsletter'])->toBeTrue();
    expect($submission->payload['active'])->toBeTrue();
    expect($submission->payload['birthday'])->toBe('2026-01-05');
    expect($submission->payload['meeting_time'])->toBe('09:10:11');
    expect($submission->payload['meeting_at'])->toContain('2026-01-05T09:10:11');
    expect($submission->payload['vacation'])->toBe([
        'start' => '2026-08-01',
        'end' => '2026-08-10',
    ]);
    expect($submission->payload['window']['start'])->toContain('2026-01-05T09:10:11');
});

it('stores uploaded files and persists normalized metadata in managed mode', function (): void {
    Storage::fake('local');

    config()->set('formforge.uploads.mode', 'managed');
    config()->set('formforge.uploads.disk', 'local');

    $key = 'upload_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->file('resume')
            ->required()
            ->accept(['.pdf'])
            ->maxSize(1024 * 1024)
            ->storageDisk('local')
            ->storageDirectory('formforge-tests');

    $file = UploadedFile::fake()->create('resume.pdf', 64, 'application/pdf');

    $submission = Form::get($key, '1')->submit([
        'resume' => $file,
    ]);

    $payloadFile = $submission->payload['resume'];

    expect($payloadFile['disk'])->toBe('local');
    expect($payloadFile['path'])->toContain($key);
    expect($submission->files)->toHaveCount(1);

    Storage::disk('local')->assertExists($payloadFile['path']);
});

it('accepts direct upload references and persists file metadata', function (): void {
    Storage::fake('local');

    config()->set('formforge.uploads.mode', 'direct');
    config()->set('formforge.uploads.disk', 'local');

    Storage::disk('local')->put('incoming/sample.txt', 'hello');

    $key = 'direct_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->file('document')
            ->required()
            ->storageDisk('local');

    $submission = Form::get($key, '1')->submit([
        'document' => [
            'disk' => 'local',
            'path' => 'incoming/sample.txt',
        ],
    ]);

    expect($submission->payload['document']['path'])->toBe('incoming/sample.txt');
    expect($submission->files)->toHaveCount(1);
});

it('persists polymorphic submitter columns', function (): void {
    $user = User::query()->create([
        'name' => 'Evan',
    ]);

    $key = 'submitter_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $submission = Form::get($key, '1')->submit([
        'name' => 'FormForge',
    ], $user);

    expect($submission->submitted_by_type)->toBe($user->getMorphClass());
    expect((string) $submission->submitted_by_id)->toBe((string) $user->getKey());
});

it('supports form category and publication metadata in schema', function (): void {
    $key = 'meta_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->category('survey')
        ->unpublished()
        ->text('name')->required();

    $schema = Form::get($key, '1')->toArray();

    expect($schema['category'])->toBe('survey');
    expect($schema['is_published'])->toBeFalse();
});

it('persists test submission state and metadata', function (): void {
    $key = 'test_submission_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $submission = Form::get($key, '1')->submit(
        payload: ['name' => 'Debug'],
        isTest: true,
        submissionMeta: ['source' => 'suite'],
    );

    expect($submission->is_test)->toBeTrue();
    expect($submission->meta['source'])->toBe('suite');
});

it('supports list command filtering by category and publication', function (): void {
    $surveyKey = 'survey_' . Str::lower(Str::random(8));
    $contactKey = 'contact_' . Str::lower(Str::random(8));

    Form::define($surveyKey)
        ->version('1')
        ->category('survey')
        ->published()
        ->text('name')->required();

    Form::define($contactKey)
        ->version('1')
        ->category('contact')
        ->unpublished()
        ->text('name')->required();

    $this->artisan('formforge:list --category=survey --published=yes')
        ->expectsOutputToContain($surveyKey)
        ->assertExitCode(0);
});

it('cleans up expired staged upload tokens and temporary files', function (): void {
    Storage::fake('local');

    config()->set('formforge.uploads.temporary_disk', 'local');

    Storage::disk('local')->put('formforge/tmp/expired-a.pdf', 'a');
    Storage::disk('local')->put('formforge/tmp/expired-b.pdf', 'b');
    Storage::disk('local')->put('formforge/tmp/active.pdf', 'active');

    $expiredA = StagedUpload::query()->create([
        'token' => 'upl_' . Str::lower(Str::random(24)),
        'form_key' => 'cleanup_form',
        'form_version' => '1',
        'field_key' => 'resume',
        'field_name' => 'resume',
        'disk' => 'local',
        'path' => 'formforge/tmp/expired-a.pdf',
        'original_name' => 'expired-a.pdf',
        'stored_name' => 'expired-a.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size' => 1,
        'checksum' => '',
        'metadata' => ['mode' => 'staged_token'],
        'expires_at' => Carbon::now()->subMinutes(10),
    ]);

    $expiredB = StagedUpload::query()->create([
        'token' => 'upl_' . Str::lower(Str::random(24)),
        'form_key' => 'cleanup_form',
        'form_version' => '1',
        'field_key' => 'resume',
        'field_name' => 'resume',
        'disk' => 'local',
        'path' => 'formforge/tmp/expired-b.pdf',
        'original_name' => 'expired-b.pdf',
        'stored_name' => 'expired-b.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size' => 1,
        'checksum' => '',
        'metadata' => ['mode' => 'staged_token'],
        'expires_at' => Carbon::now()->subMinutes(5),
    ]);

    $active = StagedUpload::query()->create([
        'token' => 'upl_' . Str::lower(Str::random(24)),
        'form_key' => 'cleanup_form',
        'form_version' => '1',
        'field_key' => 'resume',
        'field_name' => 'resume',
        'disk' => 'local',
        'path' => 'formforge/tmp/active.pdf',
        'original_name' => 'active.pdf',
        'stored_name' => 'active.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size' => 1,
        'checksum' => '',
        'metadata' => ['mode' => 'staged_token'],
        'expires_at' => Carbon::now()->addMinutes(10),
    ]);

    $this->artisan('formforge:uploads:cleanup --chunk=1')
        ->assertExitCode(0);

    $this->assertDatabaseMissing('formforge_staged_uploads', [
        'id' => $expiredA->id,
    ]);
    $this->assertDatabaseMissing('formforge_staged_uploads', [
        'id' => $expiredB->id,
    ]);
    $this->assertDatabaseHas('formforge_staged_uploads', [
        'id' => $active->id,
    ]);

    Storage::disk('local')->assertMissing('formforge/tmp/expired-a.pdf');
    Storage::disk('local')->assertMissing('formforge/tmp/expired-b.pdf');
    Storage::disk('local')->assertExists('formforge/tmp/active.pdf');
});

it('supports dry-run cleanup for expired staged upload tokens', function (): void {
    Storage::fake('local');

    Storage::disk('local')->put('formforge/tmp/expired-dry-run.pdf', 'dry-run');

    $expired = StagedUpload::query()->create([
        'token' => 'upl_' . Str::lower(Str::random(24)),
        'form_key' => 'cleanup_form',
        'form_version' => '1',
        'field_key' => 'resume',
        'field_name' => 'resume',
        'disk' => 'local',
        'path' => 'formforge/tmp/expired-dry-run.pdf',
        'original_name' => 'expired-dry-run.pdf',
        'stored_name' => 'expired-dry-run.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size' => 1,
        'checksum' => '',
        'metadata' => ['mode' => 'staged_token'],
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    $this->artisan('formforge:uploads:cleanup --dry-run')
        ->assertExitCode(0);

    $this->assertDatabaseHas('formforge_staged_uploads', [
        'id' => $expired->id,
    ]);
    Storage::disk('local')->assertExists('formforge/tmp/expired-dry-run.pdf');
});

it('can keep temporary files while cleaning expired staged upload tokens', function (): void {
    Storage::fake('local');

    Storage::disk('local')->put('formforge/tmp/expired-keep-files.pdf', 'keep-files');

    $expired = StagedUpload::query()->create([
        'token' => 'upl_' . Str::lower(Str::random(24)),
        'form_key' => 'cleanup_form',
        'form_version' => '1',
        'field_key' => 'resume',
        'field_name' => 'resume',
        'disk' => 'local',
        'path' => 'formforge/tmp/expired-keep-files.pdf',
        'original_name' => 'expired-keep-files.pdf',
        'stored_name' => 'expired-keep-files.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size' => 1,
        'checksum' => '',
        'metadata' => ['mode' => 'staged_token'],
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    $this->artisan('formforge:uploads:cleanup --keep-files')
        ->assertExitCode(0);

    $this->assertDatabaseMissing('formforge_staged_uploads', [
        'id' => $expired->id,
    ]);
    Storage::disk('local')->assertExists('formforge/tmp/expired-keep-files.pdf');
});
