<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Exceptions\ImmutableVersionException;
use EvanSchleret\FormForge\Exceptions\UnknownFieldsException;
use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Models\FormDraft;
use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Models\SubmissionAutomationRun;
use EvanSchleret\FormForge\Models\StagedUpload;
use EvanSchleret\FormForge\Automations\SubmissionAutomationDispatcher;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Registry\FormRegistry;
use EvanSchleret\FormForge\Tests\Fixtures\ActiveFormKeyAutomationResolver;
use EvanSchleret\FormForge\Tests\Fixtures\CreateMembershipFromSubmissionAutomation;
use EvanSchleret\FormForge\Tests\Fixtures\Models\CustomFormSubmission;
use EvanSchleret\FormForge\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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

it('treats nullish values as absent for optional fields', function (): void {
    $key = 'nullish_optional_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')
        ->email('email')
        ->dateRange('vacation');

    $submission = Form::get($key, '1')->submit([
        'name' => '',
        'email' => null,
        'vacation' => [
            'start' => null,
            'end' => '',
        ],
    ]);

    expect($submission->payload)->toBe([]);
});

it('rejects nullish values when field is required', function (): void {
    $key = 'nullish_required_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    expect(static fn () => Form::get($key, '1')->submit([
        'name' => null,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(static fn () => Form::get($key, '1')->submit([
        'name' => '',
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
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

it('supports overriding package models via config', function (): void {
    config()->set('formforge.models.form_submission', CustomFormSubmission::class);

    $key = 'custom_model_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    $submission = Form::get($key, '1')->submit([
        'name' => 'Evan',
    ]);

    expect($submission)->toBeInstanceOf(CustomFormSubmission::class);
    expect(($submission->meta['custom_model'] ?? false))->toBeTrue();
});

it('runs code-first submission automations after form submit', function (): void {
    Schema::create('memberships', function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('form_submission_id');
        $table->string('email');
        $table->string('plan');
    });

    $key = 'automation_' . Str::lower(Str::random(8));

    Form::automation($key)
        ->sync()
        ->handler(CreateMembershipFromSubmissionAutomation::class, 'create_membership');

    Form::define($key)
        ->version('1')
        ->email('email')->required()
        ->text('plan')->required();

    $submission = Form::get($key, '1')->submit([
        'email' => 'member@example.com',
        'plan' => 'pro',
    ]);

    expect(DB::table('memberships')->count())->toBe(1);
    expect(DB::table('memberships')->value('email'))->toBe('member@example.com');
    expect(DB::table('memberships')->value('plan'))->toBe('pro');

    $run = SubmissionAutomationRun::query()->first();

    expect($run)->not->toBeNull();
    expect($run?->automation_key)->toBe('create_membership');
    expect($run?->status)->toBe('completed');

    app(SubmissionAutomationDispatcher::class)->dispatch($submission);

    expect(DB::table('memberships')->count())->toBe(1);

    $run->refresh();
    expect($run->attempts)->toBe(1);
});

it('runs resolver-based submission automations using runtime form resolution', function (): void {
    Schema::create('memberships', function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('form_submission_id');
        $table->string('email');
        $table->string('plan');
    });

    $firstKey = 'automation_first_' . Str::lower(Str::random(8));
    $secondKey = 'automation_second_' . Str::lower(Str::random(8));

    Form::automationForResolver(ActiveFormKeyAutomationResolver::class)
        ->sync()
        ->handler(CreateMembershipFromSubmissionAutomation::class, 'create_membership_active');

    Form::define($firstKey)
        ->version('1')
        ->email('email')->required()
        ->text('plan')->required();

    Form::define($secondKey)
        ->version('1')
        ->email('email')->required()
        ->text('plan')->required();

    config()->set('formforge.tests.active_form_key', $firstKey);

    Form::get($firstKey, '1')->submit([
        'email' => 'first@example.com',
        'plan' => 'starter',
    ]);

    Form::get($secondKey, '1')->submit([
        'email' => 'ignored@example.com',
        'plan' => 'pro',
    ]);

    expect(DB::table('memberships')->count())->toBe(1);
    expect(DB::table('memberships')->value('email'))->toBe('first@example.com');

    config()->set('formforge.tests.active_form_key', $secondKey);

    Form::get($secondKey, '1')->submit([
        'email' => 'second@example.com',
        'plan' => 'pro',
    ]);

    expect(DB::table('memberships')->count())->toBe(2);
    expect(DB::table('memberships')->orderByDesc('id')->value('email'))->toBe('second@example.com');

    expect(
        SubmissionAutomationRun::query()->where('automation_key', 'create_membership_active')->count()
    )->toBe(2);
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

it('scaffolds a submission automation class with make command', function (): void {
    $directory = storage_path('app/formforge-tests/automations/' . Str::lower(Str::random(8)));
    File::ensureDirectoryExists($directory);

    $this->artisan("formforge:make:automation Membership/CreateMembership --path={$directory} --namespace=App\\\\FormForge\\\\Automations --form=membership-application --sync")
        ->expectsOutputToContain('Automation created:')
        ->expectsOutputToContain("Form::automation('membership-application')->sync()->handler(")
        ->assertExitCode(0);

    $generated = $directory . '/Membership/CreateMembership.php';

    expect(File::exists($generated))->toBeTrue();

    $content = (string) File::get($generated);

    expect($content)->toContain('namespace App\\FormForge\\Automations\\Membership;');
    expect($content)->toContain('class CreateMembership implements SubmissionAutomation');
    expect($content)->toContain('public function handle(FormSubmission $submission): void');
});

it('scaffolds a submission automation resolver class with make command', function (): void {
    $directory = storage_path('app/formforge-tests/automation-resolvers/' . Str::lower(Str::random(8)));
    File::ensureDirectoryExists($directory);

    $this->artisan("formforge:make:automation-resolver Membership/ResolveActiveMembershipForm --path={$directory} --namespace=App\\\\FormForge\\\\AutomationResolvers")
        ->expectsOutputToContain('Automation resolver created:')
        ->expectsOutputToContain('Form::automationForResolver(')
        ->assertExitCode(0);

    $generated = $directory . '/Membership/ResolveActiveMembershipForm.php';

    expect(File::exists($generated))->toBeTrue();

    $content = (string) File::get($generated);

    expect($content)->toContain('namespace App\\FormForge\\AutomationResolvers\\Membership;');
    expect($content)->toContain('class ResolveActiveMembershipForm implements SubmissionAutomationResolver');
    expect($content)->toContain('public function matches(FormSubmission $submission): bool');
});

it('scaffolds HTTP override controllers with make command', function (): void {
    $directory = storage_path('app/formforge-tests/http-controllers/' . Str::lower(Str::random(8)));
    File::ensureDirectoryExists($directory);

    $this->artisan("formforge:make:http-controller --controller=management --controller=schema --path={$directory} --namespace=App\\\\Http\\\\Controllers\\\\FormForge")
        ->expectsOutputToContain('Controller created:')
        ->expectsOutputToContain("'management' => \\App\\Http\\Controllers\\FormForge\\FormForgeManagementController::class,")
        ->expectsOutputToContain("'schema' => \\App\\Http\\Controllers\\FormForge\\FormForgeSchemaController::class,")
        ->assertExitCode(0);

    $management = $directory . '/FormForgeManagementController.php';
    $schema = $directory . '/FormForgeSchemaController.php';

    expect(File::exists($management))->toBeTrue();
    expect(File::exists($schema))->toBeTrue();

    $managementContent = (string) File::get($management);
    $schemaContent = (string) File::get($schema);

    expect($managementContent)->toContain('class FormForgeManagementController extends FormManagementController');
    expect($managementContent)->toContain('public function index(Request $request, FormMutationService $mutations): JsonResponse');
    expect($schemaContent)->toContain('class FormForgeSchemaController extends FormSchemaController');
});

it('scaffolds scoped policy with model helper', function (): void {
    $directory = storage_path('app/formforge-tests/policies/' . Str::lower(Str::random(8)));
    File::ensureDirectoryExists($directory);

    $this->artisan("formforge:make:policy UserFormForgePolicy --path={$directory} --namespace=App\\\\Policies\\\\FormForge --model=App\\\\Models\\\\User --param=user")
        ->expectsOutputToContain('Policy created:')
        ->expectsOutputToContain("'mode' => 'policy'")
        ->assertExitCode(0);

    $policy = $directory . '/UserFormForgePolicy.php';

    expect(File::exists($policy))->toBeTrue();

    $content = (string) File::get($policy);

    expect($content)->toContain('class UserFormForgePolicy extends BaseFormForgePolicy');
    expect($content)->toContain('protected function owner(FormForgeAuthorizationContext $context): ?User');
    expect($content)->toContain('public function management_index(mixed $user, FormForgeAuthorizationContext $context): bool');
});

it('builds ownership reference from model, array, and existing reference', function (): void {
    $user = User::query()->create([
        'name' => 'Owner',
    ]);

    $fromModel = OwnershipReference::from($user);
    $fromArray = OwnershipReference::from([
        'type' => 'user',
        'id' => '42',
    ]);
    $fromLegacyArray = OwnershipReference::from([
        'owner_type' => 'team',
        'owner_id' => '7',
    ]);
    $fromSelf = OwnershipReference::from($fromModel);

    expect($fromModel->type)->toBe($user->getMorphClass());
    expect($fromModel->id)->toBe((string) $user->getKey());
    expect($fromArray->type)->toBe('user');
    expect($fromArray->id)->toBe('42');
    expect($fromLegacyArray->type)->toBe('team');
    expect($fromLegacyArray->id)->toBe('7');
    expect($fromSelf)->toBe($fromModel);
});

it('rejects invalid ownership payloads when building ownership reference', function (): void {
    expect(static fn () => OwnershipReference::from([
        'type' => '',
        'id' => '',
    ]))->toThrow(\InvalidArgumentException::class);

    expect(static fn () => OwnershipReference::from([
        'owner_type' => 'user',
        'owner_id' => '',
    ]))->toThrow(\InvalidArgumentException::class);
});

it('scopes management mutations and reads through Form::for owner API', function (): void {
    $ownerA = ['type' => 'user', 'id' => '1'];
    $ownerB = ['type' => 'user', 'id' => '2'];

    $created = Form::for($ownerA)->create([
        'title' => 'Owner A Form',
        'fields' => [
            ['type' => 'text', 'name' => 'name'],
        ],
    ]);

    expect($created->owner_type)->toBe('user');
    expect($created->owner_id)->toBe('1');

    $listA = Form::for($ownerA)->paginateActive();
    $listB = Form::for($ownerB)->paginateActive();
    $queryA = Form::for($ownerA)->queryActive();
    $queryB = Form::for($ownerB)->queryActive();

    expect($listA->total())->toBe(1);
    expect($listB->total())->toBe(0);
    expect($queryA->getModel())->toBeInstanceOf(FormDefinition::class);
    expect($queryA->count())->toBe(1);
    expect($queryB->count())->toBe(0);

    $latestA = Form::for($ownerA)->latestActive((string) $created->key);
    $latestB = Form::for($ownerB)->latestActive((string) $created->key);

    expect($latestA)->not->toBeNull();
    expect($latestB)->toBeNull();
});

it('merges published config with latest defaults without losing project overrides', function (): void {
    $configPath = config_path('formforge.php');
    File::ensureDirectoryExists(dirname($configPath));

    $original = File::exists($configPath) ? (string) File::get($configPath) : null;

    try {
        File::put($configPath, <<<'PHP'
<?php

declare(strict_types=1);

return [
    'forms' => [
        'default_category' => 'tenant-custom',
    ],
    'http' => [
        'prefix' => 'api/custom-formforge/v1',
    ],
];
PHP);

        $this->artisan('formforge:install:merge --skip-migrations --no-backup')
            ->assertExitCode(0);

        $merged = (static function (string $path): mixed {
            return require $path;
        })($configPath);

        expect($merged)->toBeArray();
        expect($merged['forms']['default_category'])->toBe('tenant-custom');
        expect($merged['http']['prefix'])->toBe('api/custom-formforge/v1');
        expect($merged['http']['resources']['submission'] ?? null)->toBeNull();
        expect($merged['http']['resources']['submitter'] ?? null)->toBeNull();
        expect($merged['http']['middleware'])->toContain('api');

        $content = (string) File::get($configPath);

        expect($content)->toContain('| Database');
        expect($content)->toContain('return [');
        expect($content)->toContain("'prefix' => 'api/custom-formforge/v1'");
        expect($content)->not->toContain('Existing Project Overrides (Merged)');
        expect($content)->not->toContain('$config = [');
    } finally {
        if ($original === null) {
            File::delete($configPath);
        } else {
            File::put($configPath, $original);
        }
    }
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

it('cleans up expired drafts', function (): void {
    $expired = FormDraft::query()->create([
        'form_key' => 'draft_cleanup',
        'form_version' => '1',
        'owner_type' => User::class,
        'owner_id' => '1',
        'payload' => ['name' => 'Expired'],
        'meta' => ['step' => 'main'],
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    $active = FormDraft::query()->create([
        'form_key' => 'draft_cleanup',
        'form_version' => '1',
        'owner_type' => User::class,
        'owner_id' => '2',
        'payload' => ['name' => 'Active'],
        'meta' => ['step' => 'main'],
        'expires_at' => Carbon::now()->addDay(),
    ]);

    $this->artisan('formforge:drafts:cleanup --chunk=1')
        ->assertExitCode(0);

    $this->assertDatabaseMissing('formforge_drafts', [
        'id' => $expired->id,
    ]);
    $this->assertDatabaseHas('formforge_drafts', [
        'id' => $active->id,
    ]);
});

it('supports dry-run cleanup for expired drafts', function (): void {
    $expired = FormDraft::query()->create([
        'form_key' => 'draft_cleanup',
        'form_version' => '1',
        'owner_type' => User::class,
        'owner_id' => '1',
        'payload' => ['name' => 'Expired'],
        'meta' => ['step' => 'main'],
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    $this->artisan('formforge:drafts:cleanup --dry-run')
        ->assertExitCode(0);

    $this->assertDatabaseHas('formforge_drafts', [
        'id' => $expired->id,
    ]);
});
