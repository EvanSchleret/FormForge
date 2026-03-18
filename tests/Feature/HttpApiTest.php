<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\Tests\Fixtures\MarkSubmissionMiddleware;
use EvanSchleret\FormForge\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('exposes schema endpoints over HTTP', function (): void {
    $key = 'schema_http_' . Str::lower(Str::random(8));

    Form::define($key)
        ->title('Schema')
        ->version('1')
        ->text('name')->required();

    Form::define($key)
        ->title('Schema')
        ->version('2')
        ->text('name')->required()
        ->email('email')->required();

    $this->getJson("/api/formforge/v1/forms/{$key}")
        ->assertOk()
        ->assertJsonPath('data.key', $key)
        ->assertJsonPath('data.version', '2');

    $this->getJson("/api/formforge/v1/forms/{$key}/versions")
        ->assertOk()
        ->assertJsonPath('data.key', $key)
        ->assertJsonPath('data.versions.0', '1')
        ->assertJsonPath('data.versions.1', '2');

    $this->getJson("/api/formforge/v1/forms/{$key}/versions/1")
        ->assertOk()
        ->assertJsonPath('data.key', $key)
        ->assertJsonPath('data.version', '1');
});

it('accepts form submissions over HTTP', function (): void {
    $key = 'submit_http_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Evan',
    ])
        ->assertCreated()
        ->assertJsonPath('data.form_key', $key)
        ->assertJsonPath('data.form_version', '1')
        ->assertJsonPath('data.payload.name', 'Evan');

    $this->assertDatabaseHas('formforge_submissions', [
        'form_key' => $key,
        'form_version' => '1',
    ]);
});

it('rejects submission when endpoint auth is required and request is unauthenticated', function (): void {
    config()->set('formforge.http.submission.auth', 'required');

    $key = 'submit_auth_required_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Evan',
    ])->assertUnauthorized();
});

it('supports optional submission auth for guests and authenticated users', function (): void {
    config()->set('formforge.http.submission.auth', 'optional');

    $key = 'submit_auth_optional_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $guestResponse = $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Guest',
    ]);

    $guestResponse
        ->assertCreated()
        ->assertJsonPath('data.submitted_by_type', null)
        ->assertJsonPath('data.submitted_by_id', null);

    $user = User::query()->create([
        'name' => 'Evan',
    ]);

    $authResponse = $this->actingAs($user)->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Member',
    ]);

    $authResponse->assertCreated();

    expect((string) $authResponse->json('data.submitted_by_type'))->toBe($user->getMorphClass());
    expect((string) $authResponse->json('data.submitted_by_id'))->toBe((string) $user->getKey());
});

it('allows per-form submission auth override to public', function (): void {
    config()->set('formforge.http.submission.auth', 'required');

    $key = 'submit_form_public_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->api([
            'submission' => [
                'auth' => 'public',
            ],
        ])
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Evan',
    ])->assertCreated();
});

it('allows per-form submission auth override to required', function (): void {
    config()->set('formforge.http.submission.auth', 'public');

    $key = 'submit_form_required_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->api([
            'submission' => [
                'auth' => 'required',
            ],
        ])
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Evan',
    ])->assertUnauthorized();
});

it('runs per-form dynamic middleware for submissions', function (): void {
    app('router')->aliasMiddleware('formforge.test.header', MarkSubmissionMiddleware::class);

    $key = 'submit_form_middleware_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->api([
            'submission' => [
                'middleware' => ['formforge.test.header'],
            ],
        ])
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Evan',
    ])
        ->assertCreated()
        ->assertHeader('X-FormForge-Test', '1');
});

it('registers HTTP helper commands', function (): void {
    $key = 'http_cmd_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $this->artisan('formforge:http:options')
        ->assertExitCode(0);

    $this->artisan('formforge:http:routes')
        ->assertExitCode(0);

    $this->artisan("formforge:http:resolve {$key} --endpoint=submission")
        ->expectsOutputToContain("Form: {$key}@1")
        ->assertExitCode(0);
});

it('blocks live submission for unpublished forms and allows test submissions', function (): void {
    $key = 'unpublished_http_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->unpublished()
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Live',
    ])->assertStatus(422);

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        '_formforge_test' => true,
        'name' => 'Debug',
    ])
        ->assertCreated()
        ->assertJsonPath('data.is_test', true)
        ->assertJsonPath('data.meta.mode', 'test');
});

it('supports staged upload then JSON submit with upload_token', function (): void {
    Storage::fake('local');

    config()->set('formforge.uploads.mode', 'staged');
    config()->set('formforge.uploads.disk', 'local');
    config()->set('formforge.uploads.temporary_disk', 'local');
    config()->set('formforge.http.upload.auth', 'public');

    $key = 'staged_http_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required()
        ->file('resume')
            ->required()
            ->accept(['.pdf'])
            ->storageDisk('local')
            ->storageDirectory('formforge-tests');

    $stageResponse = $this->post("/api/formforge/v1/forms/{$key}/uploads/stage", [
        'field' => 'resume',
        'file' => UploadedFile::fake()->create('resume.pdf', 64, 'application/pdf'),
    ]);

    $stageResponse->assertCreated();

    $token = (string) $stageResponse->json('data.staged.upload_token');

    $submitResponse = $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'payload' => [
            'name' => 'Evan',
            'resume' => [
                'upload_token' => $token,
            ],
        ],
    ]);

    $submitResponse
        ->assertCreated()
        ->assertJsonPath('data.payload.name', 'Evan');

    $path = (string) $submitResponse->json('data.payload.resume.path');
    Storage::disk('local')->assertExists($path);
});
