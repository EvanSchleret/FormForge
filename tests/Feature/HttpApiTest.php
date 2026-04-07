<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\Http\Authorization\FormForgeAuthorizationContext;
use EvanSchleret\FormForge\Models\FormCategory;
use EvanSchleret\FormForge\Ownership\NullOwnershipResolver;
use EvanSchleret\FormForge\Tests\Fixtures\Controllers\CustomFormManagementController;
use EvanSchleret\FormForge\Tests\Fixtures\FormForgeOwnershipResolver;
use EvanSchleret\FormForge\Tests\Fixtures\MarkSubmissionMiddleware;
use EvanSchleret\FormForge\Tests\Fixtures\MutateRouteKeyMiddleware;
use EvanSchleret\FormForge\Tests\Fixtures\Policies\UserScopedFormForgePolicy;
use EvanSchleret\FormForge\Tests\Fixtures\User;
use EvanSchleret\FormForge\Tests\Fixtures\UserSummaryResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
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

it('allows overriding package HTTP controllers via configuration', function (): void {
    config()->set('formforge.http.controllers.management', CustomFormManagementController::class);
    $this->app['router']->setRoutes(new RouteCollection());
    require dirname(__DIR__, 2) . '/routes/formforge.php';

    $this->getJson('/api/formforge/v1/forms')
        ->assertOk()
        ->assertJsonPath('meta.custom_controller', true);
});

it('allows disabling specific package HTTP endpoint groups', function (): void {
    config()->set('formforge.http.endpoints.management', false);
    $this->app['router']->setRoutes(new RouteCollection());
    require dirname(__DIR__, 2) . '/routes/formforge.php';

    $key = 'schema_only_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $this->getJson("/api/formforge/v1/forms/{$key}")
        ->assertOk()
        ->assertJsonPath('data.key', $key);

    $this->getJson('/api/formforge/v1/forms')
        ->assertNotFound();
});

it('supports scoped management routes with policy authorization', function (): void {
    config()->set('formforge.http.endpoints.management', false);
    config()->set('formforge.ownership.enabled', false);
    config()->set('formforge.http.scoped_routes', [[
        'name' => 'user',
        'prefix' => 'users/{user}',
        'owner' => [
            'route_param' => 'user',
            'model' => User::class,
        ],
        'authorization' => [
            'mode' => 'policy',
            'policy' => UserScopedFormForgePolicy::class,
        ],
        'endpoints' => [
            'management' => true,
            'schema' => false,
            'submission' => false,
            'upload' => false,
            'resolve' => false,
            'draft' => false,
        ],
    ]]);

    $this->app['router']->setRoutes(new RouteCollection());
    require dirname(__DIR__, 2) . '/routes/formforge.php';

    $owner = User::query()->create(['name' => 'Owner']);
    $other = User::query()->create(['name' => 'Other']);

    $create = $this->actingAs($owner)->postJson("/api/formforge/v1/users/{$owner->getKey()}/forms", [
        'title' => 'Scoped Form',
        'fields' => [
            ['type' => 'text', 'name' => 'name'],
        ],
    ]);

    $create
        ->assertCreated()
        ->assertJsonPath('data.owner_type', $owner->getMorphClass())
        ->assertJsonPath('data.owner_id', (string) $owner->getKey());

    $this->actingAs($owner)->getJson("/api/formforge/v1/users/{$owner->getKey()}/forms")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($other)->getJson("/api/formforge/v1/users/{$owner->getKey()}/forms")
        ->assertForbidden();

    $this->getJson('/api/formforge/v1/forms')
        ->assertNotFound();
});

it('supports scoped management routes with gate authorization', function (): void {
    config()->set('formforge.http.endpoints.management', false);
    config()->set('formforge.ownership.enabled', false);
    config()->set('formforge.http.scoped_routes', [[
        'name' => 'user',
        'prefix' => 'users/{user}',
        'owner' => [
            'route_param' => 'user',
            'model' => User::class,
        ],
        'authorization' => [
            'mode' => 'gate',
            'abilities' => [
                'management.index' => 'formforge.scoped.index',
                'management.create' => 'formforge.scoped.create',
            ],
        ],
        'endpoints' => [
            'management' => true,
            'schema' => false,
            'submission' => false,
            'upload' => false,
            'resolve' => false,
            'draft' => false,
        ],
    ]]);

    Gate::define('formforge.scoped.index', static function (?User $user, FormForgeAuthorizationContext $context): bool {
        $owner = $context->ownerModel();

        if (! $user instanceof User || ! $owner instanceof User) {
            return false;
        }

        return (int) $user->getKey() === (int) $owner->getKey();
    });

    Gate::define('formforge.scoped.create', static function (?User $user, FormForgeAuthorizationContext $context): bool {
        $owner = $context->ownerModel();

        if (! $user instanceof User || ! $owner instanceof User) {
            return false;
        }

        return (int) $user->getKey() === (int) $owner->getKey();
    });

    $this->app['router']->setRoutes(new RouteCollection());
    require dirname(__DIR__, 2) . '/routes/formforge.php';

    $owner = User::query()->create(['name' => 'Owner']);
    $other = User::query()->create(['name' => 'Other']);

    $this->actingAs($owner)->postJson("/api/formforge/v1/users/{$owner->getKey()}/forms", [
        'title' => 'Scoped Gate Form',
        'fields' => [
            ['type' => 'text', 'name' => 'name'],
        ],
    ])->assertCreated();

    $this->actingAs($owner)->getJson("/api/formforge/v1/users/{$owner->getKey()}/forms")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($other)->getJson("/api/formforge/v1/users/{$owner->getKey()}/forms")
        ->assertForbidden();
});

it('uses original route form key when middleware mutates key parameter', function (): void {
    app('router')->aliasMiddleware('formforge.test.mutate.key', MutateRouteKeyMiddleware::class);

    config()->set('formforge.http.endpoints.schema', false);
    config()->set('formforge.http.scoped_routes', [[
        'name' => 'user',
        'prefix' => 'users/{user}',
        'middleware' => ['formforge.test.mutate.key'],
        'owner' => [
            'route_param' => 'user',
            'model' => User::class,
        ],
        'authorization' => [
            'mode' => 'none',
        ],
        'endpoints' => [
            'schema' => true,
            'management' => false,
            'submission' => false,
            'upload' => false,
            'resolve' => false,
            'draft' => false,
        ],
    ]]);

    $this->app['router']->setRoutes(new RouteCollection());
    require dirname(__DIR__, 2) . '/routes/formforge.php';

    $user = User::query()->create(['name' => 'Owner']);
    $key = 'scoped_schema_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $this->getJson("/api/formforge/v1/users/{$user->getKey()}/forms/{$key}")
        ->assertOk()
        ->assertJsonPath('data.key', $key);
});

it('fails closed on management endpoints when ownership is enabled and unresolved', function (): void {
    config()->set('formforge.ownership.enabled', true);
    config()->set('formforge.ownership.required', false);
    config()->set('formforge.ownership.endpoints', ['management']);
    config()->set('formforge.ownership.resolver', NullOwnershipResolver::class);

    $this->getJson('/api/formforge/v1/forms')
        ->assertForbidden();
});

it('allows unresolved ownership on management when fail-closed endpoints are disabled', function (): void {
    config()->set('formforge.ownership.enabled', true);
    config()->set('formforge.ownership.required', false);
    config()->set('formforge.ownership.endpoints', ['management']);
    config()->set('formforge.ownership.fail_closed_endpoints', []);
    config()->set('formforge.ownership.resolver', NullOwnershipResolver::class);

    $this->getJson('/api/formforge/v1/forms')
        ->assertOk();
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
    $category = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Contact',
    ])->assertCreated();

    $categorySlug = (string) $category->json('data.slug');

    $response = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Contact Form',
        'fields' => [
            [
                'type' => 'text',
                'name' => 'name',
                'required' => true,
            ],
        ],
        'category' => $categorySlug,
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
    $surveyCategory = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Survey',
    ])->assertCreated();

    $contactCategory = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Contact',
    ])->assertCreated();

    $surveyCategoryKey = (string) $surveyCategory->json('data.key');
    $contactCategoryKey = (string) $contactCategory->json('data.key');

    $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Form A',
        'fields' => [['type' => 'text', 'name' => 'name']],
        'category' => $surveyCategoryKey,
    ])->assertCreated();

    $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Form B',
        'fields' => [['type' => 'email', 'name' => 'email']],
        'category' => $contactCategoryKey,
    ])->assertCreated();

    $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Form C',
        'fields' => [['type' => 'textarea', 'name' => 'message']],
        'category' => $surveyCategoryKey,
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

it('filters forms by category slug on management endpoint', function (): void {
    $surveyCategory = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Survey',
    ])->assertCreated();

    $contactCategory = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Contact',
    ])->assertCreated();

    $surveyCategoryKey = (string) $surveyCategory->json('data.key');
    $surveyCategorySlug = (string) $surveyCategory->json('data.slug');
    $contactCategoryKey = (string) $contactCategory->json('data.key');

    $surveyCreate = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Survey Form',
        'fields' => [['type' => 'text', 'name' => 'name']],
        'category' => $surveyCategoryKey,
    ])->assertCreated();

    $contactCreate = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Contact Form',
        'fields' => [['type' => 'email', 'name' => 'email']],
        'category' => $contactCategoryKey,
    ])->assertCreated();

    $surveyFormKey = (string) $surveyCreate->json('data.key');
    $contactFormKey = (string) $contactCreate->json('data.key');

    $this->getJson('/api/formforge/v1/forms?category=' . urlencode($surveyCategorySlug))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.data.0.key', $surveyFormKey);

    $this->getJson('/api/formforge/v1/forms?category=' . urlencode($contactCategoryKey))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.data.0.key', $contactFormKey);
});

it('manages form categories through HTTP endpoints', function (): void {
    $create = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Customer Survey',
        'description' => 'Survey-oriented forms',
    ]);

    $create
        ->assertCreated()
        ->assertJsonPath('data.name', 'Customer Survey')
        ->assertJsonPath('data.slug', 'customer-survey')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.is_system', false);

    $categoryKey = (string) $create->json('data.key');

    expect(Str::isUuid($categoryKey))->toBeTrue();

    $this->getJson('/api/formforge/v1/categories')
        ->assertOk()
        ->assertJsonFragment(['key' => $categoryKey]);

    $this->getJson("/api/formforge/v1/categories/{$categoryKey}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Customer Survey');

    $this->patchJson("/api/formforge/v1/categories/{$categoryKey}", [
        'description' => 'Updated description',
        'is_active' => false,
        'slug' => 'customer-survey-updated',
    ])
        ->assertOk()
        ->assertJsonPath('data.description', 'Updated description')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.slug', 'customer-survey-updated');

    $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Survey Form',
        'category' => $categoryKey,
        'fields' => [
            ['type' => 'text', 'name' => 'name'],
        ],
    ])->assertCreated();

    $this->deleteJson("/api/formforge/v1/categories/{$categoryKey}")
        ->assertStatus(409);

    $temporary = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Temporary Category',
    ])->assertCreated();

    $temporaryKey = (string) $temporary->json('data.key');

    expect(Str::isUuid($temporaryKey))->toBeTrue();

    $this->deleteJson("/api/formforge/v1/categories/{$temporaryKey}")
        ->assertOk()
        ->assertJsonPath('data.key', $temporaryKey)
        ->assertJsonPath('data.deleted', true);
});

it('prevents deleting system categories', function (): void {
    $create = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Core',
        'is_system' => true,
    ]);

    $create
        ->assertCreated()
        ->assertJsonPath('data.is_system', true);

    $categoryKey = (string) $create->json('data.key');

    $this->deleteJson("/api/formforge/v1/categories/{$categoryKey}")
        ->assertStatus(409);
});

it('scopes management forms and categories by ownership context', function (): void {
    config()->set('formforge.ownership.enabled', true);
    config()->set('formforge.ownership.required', true);
    config()->set('formforge.ownership.endpoints', ['management']);
    config()->set('formforge.ownership.resolver', FormForgeOwnershipResolver::class);

    $ownerAHeaders = [
        'X-FormForge-Owner-Type' => 'user',
        'X-FormForge-Owner-Id' => '1',
    ];

    $ownerBHeaders = [
        'X-FormForge-Owner-Type' => 'user',
        'X-FormForge-Owner-Id' => '2',
    ];

    $this->postJson('/api/formforge/v1/categories', [
        'name' => 'No Owner',
    ])->assertForbidden();

    $categoryA = $this->withHeaders($ownerAHeaders)->postJson('/api/formforge/v1/categories', [
        'name' => 'Owner A Category',
    ])->assertCreated();

    $categoryAKey = (string) $categoryA->json('data.key');

    $createA = $this->withHeaders($ownerAHeaders)->postJson('/api/formforge/v1/forms', [
        'title' => 'Owner A Form',
        'category' => $categoryAKey,
        'fields' => [
            ['type' => 'text', 'name' => 'name'],
        ],
    ])->assertCreated();

    $formAKey = (string) $createA->json('data.key');

    $this->withHeaders($ownerAHeaders)->getJson('/api/formforge/v1/forms')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.data.0.owner_type', 'user')
        ->assertJsonPath('data.data.0.owner_id', '1');

    $this->withHeaders($ownerBHeaders)->getJson('/api/formforge/v1/forms')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->withHeaders($ownerBHeaders)->patchJson("/api/formforge/v1/forms/{$formAKey}", [
        'title' => 'Blocked',
    ])->assertNotFound();

    $this->withHeaders($ownerBHeaders)->postJson('/api/formforge/v1/forms', [
        'title' => 'Owner B Form',
        'category' => $categoryAKey,
        'fields' => [
            ['type' => 'text', 'name' => 'name'],
        ],
    ])->assertStatus(422);
});

it('includes global system categories in scoped category listing', function (): void {
    config()->set('formforge.ownership.enabled', true);
    config()->set('formforge.ownership.required', true);
    config()->set('formforge.ownership.endpoints', ['management']);
    config()->set('formforge.ownership.resolver', FormForgeOwnershipResolver::class);

    $systemCategory = FormCategory::query()->create([
        'key' => (string) Str::uuid(),
        'name' => 'System Core',
        'description' => null,
        'meta' => [],
        'is_active' => true,
        'is_system' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]);

    $ownerHeaders = [
        'X-FormForge-Owner-Type' => 'user',
        'X-FormForge-Owner-Id' => '1',
    ];

    $this->withHeaders($ownerHeaders)->getJson('/api/formforge/v1/categories')
        ->assertOk()
        ->assertJsonFragment([
            'key' => (string) $systemCategory->key,
            'is_system' => true,
            'owner_type' => null,
            'owner_id' => null,
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

it('clears form category when patched with explicit null', function (): void {
    $category = $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Survey',
    ])->assertCreated();

    $categoryKey = (string) $category->json('data.key');

    $create = $this->postJson('/api/formforge/v1/forms', [
        'title' => 'Survey V1',
        'category' => $categoryKey,
        'fields' => [
            ['type' => 'text', 'name' => 'name', 'required' => true],
        ],
    ])->assertCreated();

    $key = (string) $create->json('data.key');

    $this->patchJson("/api/formforge/v1/forms/{$key}", [
        'category' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.category', null)
        ->assertJsonPath('data.category_item', null);
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

it('supports management category ability authorization', function (): void {
    config()->set('formforge.http.management.auth', 'required');
    config()->set('formforge.http.management.abilities.category_create', 'formforge.category-create');

    Gate::define('formforge.category-create', static fn (?User $user): bool => $user !== null && $user->name === 'allowed');

    $this->postJson('/api/formforge/v1/categories', [
        'name' => 'Blocked',
    ])->assertUnauthorized();

    $denied = User::query()->create(['name' => 'denied']);

    $this->actingAs($denied)->postJson('/api/formforge/v1/categories', [
        'name' => 'Blocked',
    ])->assertForbidden();

    $allowed = User::query()->create(['name' => 'allowed']);

    $allowedResponse = $this->actingAs($allowed)->postJson('/api/formforge/v1/categories', [
        'name' => 'Allowed',
    ]);

    $allowedResponse
        ->assertCreated()
        ->assertJsonPath('data.name', 'Allowed');

    expect(Str::isUuid((string) $allowedResponse->json('data.key')))->toBeTrue();
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
