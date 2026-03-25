<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\Tests\Fixtures\MarkSubmissionMiddleware;
use EvanSchleret\FormForge\Tests\Fixtures\User;
use EvanSchleret\FormForge\Tests\Fixtures\UserSummaryResource;
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

it('supports configurable submitter resource serialization for submissions', function (): void {
    config()->set('formforge.http.submission.auth', 'optional');
    config()->set('formforge.http.resources.submitter', UserSummaryResource::class);

    $key = 'submitter_resource_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $user = User::query()->create([
        'name' => 'Evan',
    ]);

    $submit = $this->actingAs($user)->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Member',
    ]);

    $submit
        ->assertCreated()
        ->assertJsonPath('data.submitted_by.id', $user->getKey())
        ->assertJsonPath('data.submitted_by.name', 'Evan');

    $this->getJson("/api/formforge/v1/forms/{$key}/responses")
        ->assertOk()
        ->assertJsonPath('data.data.0.submitted_by.id', $user->getKey())
        ->assertJsonPath('data.data.0.submitted_by.name', 'Evan');
});

it('enriches file metadata with urls when enabled', function (): void {
    config()->set('formforge.uploads.mode', 'direct');
    config()->set('formforge.http.resources.file_urls.enabled', true);
    config()->set('formforge.http.resources.file_urls.temporary', true);
    config()->set('formforge.http.resources.file_urls.ttl_seconds', 600);
    config()->set('formforge.uploads.disk', 'local');

    Storage::disk('local')->put('formforge-tests/incoming/identity.pdf', 'content');
    Storage::disk('local')->buildTemporaryUrlsUsing(
        static fn (string $path, \DateTimeInterface $expiration): string => 'https://signed.example.test/' . ltrim($path, '/') . '?expires=' . $expiration->getTimestamp(),
    );

    $key = 'signed_urls_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->file('identity_document')->required()->storageDisk('local');

    $response = $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'identity_document' => [
            'disk' => 'local',
            'path' => 'formforge-tests/incoming/identity.pdf',
        ],
    ])->assertCreated();

    $payloadUrl = (string) $response->json('data.payload.identity_document.url');
    $fileUrl = (string) $response->json('data.files.0.url');

    expect($payloadUrl)->not->toBe('');
    expect($payloadUrl)->toContain('formforge-tests/incoming/identity.pdf');
    expect($fileUrl)->toBe($payloadUrl);
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

it('allows submitting staged forms when optional file fields are omitted', function (): void {
    config()->set('formforge.uploads.mode', 'staged');

    $key = 'staged_optional_files_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('short_text')->required()
        ->file('first_optional_file')
        ->file('second_optional_file');

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'payload' => [
            'short_text' => 'serg',
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.payload.short_text', 'serg');
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

it('lists forms through paginated management endpoint', function (): void {
    $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Form A',
        'fields' => [['type' => 'text', 'name' => 'name']],
        'category' => 'survey',
    ])->assertCreated();

    $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Form B',
        'fields' => [['type' => 'email', 'name' => 'email']],
        'category' => 'contact',
    ])->assertCreated();

    $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Form C',
        'fields' => [['type' => 'textarea', 'name' => 'message']],
        'category' => 'survey',
    ])->assertCreated();

    $this->getJson('/api/formforge/v1/forms?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data.data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonStructure([
            'data' => [
                'data' => [
                    [
                        'key',
                        'title',
                        'category',
                        'version_number',
                        'schema',
                    ],
                ],
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'path',
                'per_page',
                'to',
                'total',
            ],
            'links' => [
                'first',
                'last',
                'prev',
                'next',
            ],
        ]);
});

it('lists form responses through paginated management endpoint', function (): void {
    $key = 'responses_http_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required()
        ->email('email')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'A',
        'email' => 'a@example.com',
    ])->assertCreated();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'B',
        'email' => 'b@example.com',
    ])->assertCreated();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'C',
        'email' => 'c@example.com',
    ])->assertCreated();

    $this->getJson("/api/formforge/v1/forms/{$key}/responses?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data.data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonStructure([
            'data' => [
                'data' => [
                    [
                        'id',
                        'form_key',
                        'form_version',
                        'payload',
                        'files',
                    ],
                ],
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'path',
                'per_page',
                'to',
                'total',
            ],
            'links' => [
                'first',
                'last',
                'prev',
                'next',
            ],
        ]);
});

it('returns one form response through management endpoint', function (): void {
    $key = 'response_show_http_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $submit = $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'One',
    ])->assertCreated();

    $submissionUuid = (string) $submit->json('data.id');

    $this->getJson("/api/formforge/v1/forms/{$key}/responses/{$submissionUuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $submissionUuid)
        ->assertJsonPath('data.form_key', $key)
        ->assertJsonPath('data.payload.name', 'One');
});

it('deletes one form response through management endpoint', function (): void {
    $key = 'response_delete_http_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $submit = $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'To Delete',
    ])->assertCreated();

    $submissionUuid = (string) $submit->json('data.id');

    $this->deleteJson("/api/formforge/v1/forms/{$key}/responses/{$submissionUuid}")
        ->assertOk()
        ->assertJsonPath('data.form_key', $key)
        ->assertJsonPath('data.submission_uuid', $submissionUuid)
        ->assertJsonPath('data.deleted', true);

    $this->assertDatabaseMissing('formforge_submissions', [
        'uuid' => $submissionUuid,
        'form_key' => $key,
    ]);
});

it('creates a seeded draft form with default page and short_text field when empty', function (): void {
    $response = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Empty Draft',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.title', 'Empty Draft')
        ->assertJsonPath('data.version_number', 1)
        ->assertJsonPath('data.is_published', false)
        ->assertJsonCount(1, 'data.schema.fields')
        ->assertJsonCount(1, 'data.schema.pages')
        ->assertJsonPath('data.schema.fields.0.type', 'text')
        ->assertJsonPath('data.schema.fields.0.name', 'short_text')
        ->assertJsonPath('data.schema.pages.0.fields.0.name', 'short_text');
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

it('supports management responses ability authorization', function (): void {
    config()->set('formforge.http.management.auth', 'required');
    config()->set('formforge.http.management.abilities.responses', 'formforge.responses');

    Gate::define('formforge.responses', static fn (?User $user): bool => $user !== null && $user->name === 'allowed');

    $key = 'responses_ability_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Alpha',
    ])->assertCreated();

    $this->getJson("/api/formforge/v1/forms/{$key}/responses")
        ->assertUnauthorized();

    $denied = User::query()->create(['name' => 'denied']);

    $this->actingAs($denied)->getJson("/api/formforge/v1/forms/{$key}/responses")
        ->assertForbidden();

    $allowed = User::query()->create(['name' => 'allowed']);

    $this->actingAs($allowed)->getJson("/api/formforge/v1/forms/{$key}/responses")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('supports management response delete ability authorization', function (): void {
    config()->set('formforge.http.management.auth', 'required');
    config()->set('formforge.http.management.abilities.response_delete', 'formforge.response-delete');

    Gate::define('formforge.response-delete', static fn (?User $user): bool => $user !== null && $user->name === 'allowed');

    $key = 'responses_delete_ability_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $submit = $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        'name' => 'Alpha',
    ])->assertCreated();

    $submissionUuid = (string) $submit->json('data.id');

    $this->deleteJson("/api/formforge/v1/forms/{$key}/responses/{$submissionUuid}")
        ->assertUnauthorized();

    $denied = User::query()->create(['name' => 'denied']);

    $this->actingAs($denied)->deleteJson("/api/formforge/v1/forms/{$key}/responses/{$submissionUuid}")
        ->assertForbidden();

    $allowed = User::query()->create(['name' => 'allowed']);

    $this->actingAs($allowed)->deleteJson("/api/formforge/v1/forms/{$key}/responses/{$submissionUuid}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);
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

it('resolves effective schema over HTTP with conditional visibility', function (): void {
    $create = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Resolve Conditions',
        'pages' => [
            [
                'page_key' => 'pg_main',
                'title' => 'Main',
                'fields' => [
                    [
                        'field_key' => 'fk_has_company',
                        'type' => 'checkbox',
                        'name' => 'has_company',
                    ],
                    [
                        'field_key' => 'fk_company_name',
                        'type' => 'text',
                        'name' => 'company_name',
                    ],
                ],
            ],
        ],
        'conditions' => [
            [
                'condition_key' => 'cd_hide_company',
                'target_type' => 'field',
                'target_key' => 'fk_company_name',
                'action' => 'hide',
                'match' => 'all',
                'when' => [
                    [
                        'field_key' => 'fk_has_company',
                        'operator' => 'eq',
                        'value' => false,
                    ],
                ],
            ],
        ],
        'drafts' => [
            'enabled' => true,
        ],
    ])->assertCreated();

    $key = (string) $create->json('data.key');

    $this->postJson("/api/formforge/v1/forms/{$key}/resolve", [
        'payload' => [
            'has_company' => false,
        ],
        'debug' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.schema.fields.0.name', 'has_company')
        ->assertJsonCount(1, 'data.schema.fields')
        ->assertJsonMissingPath('data.schema.pages.0.sections')
        ->assertJsonPath('data.schema.debug.conditions.0.matched', true);
});

it('returns not found on resolve endpoint when environment is not allowed', function (): void {
    config()->set('formforge.http.resolve.enabled_environments', ['local']);

    $key = 'resolve_env_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/resolve", [
        'payload' => ['name' => 'Evan'],
    ])->assertNotFound();
});

it('stores, reads and deletes authenticated draft states', function (): void {
    config()->set('formforge.drafts.default_enabled', true);

    $key = 'draft_http_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')
        ->email('email');

    $user = User::query()->create([
        'name' => 'Draft User',
    ]);

    $this->actingAs($user)->postJson("/api/formforge/v1/forms/{$key}/drafts", [
        'payload' => [
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ],
        'meta' => [
            'step' => 'main',
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.form_key', $key)
        ->assertJsonPath('data.payload.name', 'Alice')
        ->assertJsonPath('data.meta.step', 'main');

    $this->actingAs($user)->getJson("/api/formforge/v1/forms/{$key}/drafts/current")
        ->assertOk()
        ->assertJsonPath('data.form_key', $key)
        ->assertJsonPath('data.payload.email', 'alice@example.com');

    $this->actingAs($user)->deleteJson("/api/formforge/v1/forms/{$key}/drafts/current")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    $this->actingAs($user)->getJson("/api/formforge/v1/forms/{$key}/drafts/current")
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('isolates draft states per authenticated user', function (): void {
    config()->set('formforge.drafts.default_enabled', true);

    $key = 'draft_isolation_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name');

    $first = User::query()->create(['name' => 'First']);
    $second = User::query()->create(['name' => 'Second']);

    $this->actingAs($first)->postJson("/api/formforge/v1/forms/{$key}/drafts", [
        'payload' => ['name' => 'Alice'],
    ])->assertOk();

    $this->actingAs($second)->postJson("/api/formforge/v1/forms/{$key}/drafts", [
        'payload' => ['name' => 'Bob'],
    ])->assertOk();

    $this->actingAs($first)->getJson("/api/formforge/v1/forms/{$key}/drafts/current")
        ->assertOk()
        ->assertJsonPath('data.payload.name', 'Alice');

    $this->actingAs($second)->getJson("/api/formforge/v1/forms/{$key}/drafts/current")
        ->assertOk()
        ->assertJsonPath('data.payload.name', 'Bob');
});

it('rejects draft endpoints when drafts are disabled for form', function (): void {
    config()->set('formforge.drafts.default_enabled', false);

    $key = 'draft_disabled_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name');

    $user = User::query()->create(['name' => 'Draft Disabled']);

    $this->actingAs($user)->postJson("/api/formforge/v1/forms/{$key}/drafts", [
        'payload' => ['name' => 'Alice'],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('draft');
});

it('rejects draft endpoint requests without an authenticated owner', function (): void {
    config()->set('formforge.http.draft.auth', 'public');
    config()->set('formforge.drafts.default_enabled', true);

    $key = 'draft_guest_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name');

    $this->postJson("/api/formforge/v1/forms/{$key}/drafts", [
        'payload' => ['name' => 'Guest'],
    ])->assertUnauthorized();
});

it('supports draft endpoint ability authorization', function (): void {
    config()->set('formforge.http.draft.auth', 'required');
    config()->set('formforge.http.draft.ability', 'formforge.draft');
    config()->set('formforge.drafts.default_enabled', true);

    Gate::define('formforge.draft', static fn (?User $user): bool => $user !== null && $user->name === 'allowed');

    $key = 'draft_ability_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name');

    $this->postJson("/api/formforge/v1/forms/{$key}/drafts", [
        'payload' => ['name' => 'Guest'],
    ])->assertUnauthorized();

    $denied = User::query()->create(['name' => 'denied']);

    $this->actingAs($denied)->postJson("/api/formforge/v1/forms/{$key}/drafts", [
        'payload' => ['name' => 'Denied'],
    ])->assertForbidden();

    $allowed = User::query()->create(['name' => 'allowed']);

    $this->actingAs($allowed)->postJson("/api/formforge/v1/forms/{$key}/drafts", [
        'payload' => ['name' => 'Allowed'],
    ])
        ->assertOk()
        ->assertJsonPath('data.payload.name', 'Allowed');
});

it('rejects test submissions outside configured environments', function (): void {
    config()->set('formforge.submissions.testing.enabled_environments', ['local']);

    $key = 'test_env_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->unpublished()
        ->text('name')->required();

    $this->postJson("/api/formforge/v1/forms/{$key}/submit", [
        '_formforge_test' => true,
        'name' => 'Debug',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('test');
});
