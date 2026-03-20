<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\Tests\Fixtures\MarkSubmissionMiddleware;
use EvanSchleret\FormForge\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
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

it('creates a form revision through management endpoint', function (): void {
    $response = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Contact Form',
        'fields' => [
            [
                'type' => 'text',
                'name' => 'name',
                'required' => true,
            ],
        ],
        'category' => 'contact',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.title', 'Contact Form')
        ->assertJsonPath('data.version_number', 1)
        ->assertJsonPath('data.is_published', false);

    $key = (string) $response->json('data.key');

    expect($key)->not->toBe('');

    $this->getJson("/api/formforge/v1/forms/{$key}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Contact Form');
});

it('patches a form and creates a new draft revision', function (): void {
    $create = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Survey V1',
        'fields' => [
            ['type' => 'text', 'name' => 'name', 'required' => true],
        ],
    ])->assertCreated();

    $key = (string) $create->json('data.key');

    $patch = $this->patchJson("/api/formforge/v1/forms/{$key}", [
        'title' => 'Survey V2',
        'fields' => [
            ['type' => 'text', 'name' => 'name', 'required' => true],
            ['type' => 'email', 'name' => 'email', 'required' => true],
        ],
    ]);

    $patch
        ->assertOk()
        ->assertJsonPath('data.version_number', 2)
        ->assertJsonPath('data.title', 'Survey V2')
        ->assertJsonPath('data.is_published', false);

    $this->getJson("/api/formforge/v1/forms/{$key}")
        ->assertOk()
        ->assertJsonPath('data.version', '2')
        ->assertJsonPath('data.title', 'Survey V2');
});

it('publishes and unpublishes through revisioned endpoints', function (): void {
    $create = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Feedback',
        'fields' => [
            ['type' => 'text', 'name' => 'message', 'required' => true],
        ],
    ])->assertCreated();

    $key = (string) $create->json('data.key');

    $publish = $this->postJson("/api/formforge/v1/forms/{$key}/publish");

    $publish
        ->assertOk()
        ->assertJsonPath('data.is_published', true)
        ->assertJsonPath('data.version_number', 2);

    $unpublish = $this->postJson("/api/formforge/v1/forms/{$key}/unpublish");

    $unpublish
        ->assertOk()
        ->assertJsonPath('data.is_published', false)
        ->assertJsonPath('data.version_number', 3);
});

it('replays idempotent create requests', function (): void {
    $payload = [
        'title' => 'Idempotent Form',
        'fields' => [
            ['type' => 'text', 'name' => 'name', 'required' => true],
        ],
    ];

    $first = $this
        ->withHeaders(['Idempotency-Key' => 'idem-create-1'])
        ->postJson('/api/formforge/v1/forms', $payload);

    $first
        ->assertCreated()
        ->assertJsonPath('meta.replayed', false);

    $second = $this
        ->withHeaders(['Idempotency-Key' => 'idem-create-1'])
        ->postJson('/api/formforge/v1/forms', $payload);

    $second
        ->assertCreated()
        ->assertJsonPath('meta.replayed', true)
        ->assertJsonPath('data.revision_id', $first->json('data.revision_id'));
});

it('supports management ability authorization', function (): void {
    config()->set('formforge.http.management.auth', 'required');
    config()->set('formforge.http.management.abilities.create', 'formforge.create');

    Gate::define('formforge.create', static fn (?User $user): bool => $user !== null && $user->name === 'allowed');

    $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Forbidden',
        'fields' => [['type' => 'text', 'name' => 'name']],
    ])->assertUnauthorized();

    $denied = User::query()->create(['name' => 'denied']);

    $this->actingAs($denied)->postJson('/api/formforge/v1/forms', [
        'title' => 'Forbidden',
        'fields' => [['type' => 'text', 'name' => 'name']],
    ])->assertForbidden();

    $allowed = User::query()->create(['name' => 'allowed']);

    $this->actingAs($allowed)->postJson('/api/formforge/v1/forms', [
        'title' => 'Allowed',
        'fields' => [['type' => 'text', 'name' => 'name']],
    ])->assertCreated();
});

it('keeps deleted forms readable through revisions endpoint for admin flows', function (): void {
    $create = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Delete Flow',
        'fields' => [
            ['type' => 'text', 'name' => 'name', 'required' => true],
        ],
    ])->assertCreated();

    $key = (string) $create->json('data.key');

    $this->postJson("/api/formforge/v1/forms/{$key}/publish")
        ->assertOk();

    $this->deleteJson("/api/formforge/v1/forms/{$key}")
        ->assertOk()
        ->assertJsonPath('data.key', $key);

    $this->getJson("/api/formforge/v1/forms/{$key}")
        ->assertNotFound();

    $this->getJson("/api/formforge/v1/forms/{$key}/revisions?include_deleted=1")
        ->assertOk()
        ->assertJsonPath('data.key', $key);
});

it('returns a diff between two form versions', function (): void {
    $create = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Diff Form',
        'fields' => [
            ['type' => 'text', 'name' => 'name', 'required' => true],
        ],
    ])->assertCreated();

    $key = (string) $create->json('data.key');

    $this->patchJson("/api/formforge/v1/forms/{$key}", [
        'fields' => [
            ['type' => 'text', 'name' => 'name', 'required' => true],
            ['type' => 'email', 'name' => 'email', 'required' => true],
        ],
    ])->assertOk();

    $this->getJson("/api/formforge/v1/forms/{$key}/diff/1/2")
        ->assertOk()
        ->assertJsonPath('data.from_version', 1)
        ->assertJsonPath('data.to_version', 2);
});
