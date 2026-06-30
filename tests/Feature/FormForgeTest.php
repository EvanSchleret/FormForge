<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Exceptions\ImmutableVersionException;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\Exceptions\UnknownFieldsException;
use EvanSchleret\FormForge\Definition\FieldType;
use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Models\FormCategory;
use EvanSchleret\FormForge\Models\FormDraft;
use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Models\SubmissionAutomationRun;
use EvanSchleret\FormForge\Models\StagedUpload;
use EvanSchleret\FormForge\Automations\SubmissionAutomationDispatcher;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Registry\FormRegistry;
use EvanSchleret\FormForge\Support\FormSchemaLayout;
use EvanSchleret\FormForge\Tests\Fixtures\ActiveFormKeyAutomationResolver;
use EvanSchleret\FormForge\Tests\Fixtures\CreateRecordFromSubmissionAutomation;
use EvanSchleret\FormForge\Tests\Fixtures\Models\CustomFormSubmission;
use EvanSchleret\FormForge\Tests\Fixtures\User;
use EvanSchleret\FormForge\Submissions\SubmissionValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
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
        ->text('email')->required();

    Form::define($key)
        ->title('Registration')
        ->version('2')
        ->text('name')->required()->max(120)
        ->text('email')->required();

    $schemaV1 = Form::get($key, '1')->toArray();
    $schemaV1Again = Form::get($key, '1')->toArray();
    $schemaV2 = Form::get($key, '2')->toArray();

    expect($schemaV1)->toBe($schemaV1Again);
    expect($schemaV1['fields'][0]['field_key'])->toBe($schemaV2['fields'][0]['field_key']);
});

it('sanitizes rich text field labels before exporting the schema', function (): void {
    $key = 'rich_label_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')
            ->label('<p>Hello <strong>World</strong><script>alert(1)</script><a href="javascript:alert(1)">Link</a></p>');

    $schema = Form::get($key, '1')->toArray();

    expect($schema['fields'][0]['label'])->toBe('<p>Hello <strong>World</strong><a>Link</a></p>');
});

it('treats nullish values as absent for optional fields', function (): void {
    $key = 'nullish_optional_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')
        ->text('email')
        ->temporal('vacation', 'date');

    $submission = Form::get($key, '1')->submit([
        'name' => '',
        'email' => null,
        'vacation' => '',
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

it('exports address fields and validates nested address rules', function (): void {
    $key = 'address_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->address('address')->addressFields([
            ['key' => 'line1', 'label' => 'Address Line 1', 'visible' => true, 'required' => true],
            ['key' => 'line2', 'label' => 'Address Line 2', 'visible' => true, 'required' => false],
            ['key' => 'city', 'label' => 'City', 'visible' => true, 'required' => false],
            ['key' => 'state', 'label' => 'State', 'visible' => true, 'required' => false],
            ['key' => 'zip', 'label' => 'Zip', 'visible' => true, 'required' => false],
            ['key' => 'country', 'label' => 'Country', 'visible' => true, 'required' => false],
        ])->meta([
            'validation' => [
                'match' => 'all',
                'rules' => [
                    [
                        'validation_key' => 'vr_line1_min',
                        'target' => 'line1',
                        'operator' => 'min',
                        'value' => 5,
                        'unit' => 'characters',
                    ],
                ],
            ],
        ]);

    $schema = Form::get($key, '1')->toArray();

    expect($schema['fields'][0]['type'])->toBe(FieldType::ADDRESS);
    expect($schema['fields'][0]['address_fields'])->toHaveCount(6);

    expect(static fn () => Form::get($key, '1')->submit([
        'address' => [
            'line1' => '1234',
            'line2' => null,
            'city' => 'Geneva',
            'state' => 'GE',
            'zip' => '1200',
            'country' => 'CH',
        ],
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    $submission = Form::get($key, '1')->submit([
        'address' => [
            'line1' => '1 Route des Acacias',
            'line2' => null,
            'city' => 'Geneva',
            'state' => 'GE',
            'zip' => '1200',
            'country' => 'CH',
        ],
    ]);

    expect($submission->payload['address']['line1'])->toBe('1 Route des Acacias');
    expect($submission->payload['address']['city'])->toBe('Geneva');
});

it('exports translated default address labels according to the active locale', function (): void {
    $key = 'address_locale_' . Str::lower(Str::random(8));

    app()->setLocale('fr');

    try {
        Form::define($key)
            ->version('1')
            ->address('address');

        $schema = Form::get($key, '1')->toArray();

        expect($schema['fields'][0]['address_fields'])->toEqual([
            ['key' => 'line1', 'label' => "Ligne d'adresse 1", 'visible' => true, 'required' => true],
            ['key' => 'line2', 'label' => "Ligne d'adresse 2", 'visible' => false, 'required' => false],
            ['key' => 'city', 'label' => 'Ville', 'visible' => true, 'required' => true],
            ['key' => 'state', 'label' => 'État', 'visible' => false, 'required' => false],
            ['key' => 'zip', 'label' => 'Code postal', 'visible' => true, 'required' => true],
            ['key' => 'country', 'label' => 'Pays', 'visible' => true, 'required' => true],
        ]);
    } finally {
        app()->setLocale('en');
    }
});

it('preserves empty address field labels when exporting the schema', function (): void {
    $key = 'address_empty_labels_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->address('address')
        ->addressFields([
            ['key' => 'line1', 'label' => '', 'visible' => true, 'required' => true],
            ['key' => 'line2', 'label' => '', 'visible' => true, 'required' => false],
            ['key' => 'city', 'label' => 'City', 'visible' => true, 'required' => true],
            ['key' => 'state', 'label' => 'State', 'visible' => false, 'required' => false],
            ['key' => 'zip', 'label' => 'Zip', 'visible' => true, 'required' => true],
            ['key' => 'country', 'label' => 'Country', 'visible' => true, 'required' => true],
        ]);

    $schema = Form::get($key, '1')->toArray();

    expect($schema['fields'][0]['address_fields'])->toEqual([
        ['key' => 'line1', 'label' => '', 'visible' => true, 'required' => true],
        ['key' => 'line2', 'label' => '', 'visible' => true, 'required' => false],
        ['key' => 'city', 'label' => 'City', 'visible' => true, 'required' => true],
        ['key' => 'state', 'label' => 'State', 'visible' => false, 'required' => false],
        ['key' => 'zip', 'label' => 'Zip', 'visible' => true, 'required' => true],
        ['key' => 'country', 'label' => 'Country', 'visible' => true, 'required' => true],
    ]);
});

it('ignores top-level required on address fields and only enforces required address subfields', function (): void {
    $key = 'address_required_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->address('address')
            ->required()
            ->addressFields([
                ['key' => 'line1', 'label' => 'Address Line 1', 'visible' => true, 'required' => false],
                ['key' => 'line2', 'label' => 'Address Line 2', 'visible' => true, 'required' => false],
                ['key' => 'city', 'label' => 'City', 'visible' => true, 'required' => false],
                ['key' => 'state', 'label' => 'State', 'visible' => true, 'required' => false],
                ['key' => 'zip', 'label' => 'Zip', 'visible' => true, 'required' => false],
                ['key' => 'country', 'label' => 'Country', 'visible' => true, 'required' => false],
            ]);

    $submission = Form::get($key, '1')->submit([
        'address' => [
            'line1' => null,
            'line2' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'country' => null,
        ],
    ]);

    expect($submission->payload)->toBe([]);
});

it('exports temporal fields through the merged temporal type and keeps legacy schemas compatible', function (): void {
    $key = 'temporal_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->temporal('travel', 'date');

    $schema = Form::get($key, '1')->toArray();

    expect($schema['fields'][0]['type'])->toBe(FieldType::TEMPORAL);
    expect($schema['fields'][0]['temporal_mode'])->toBe('date');

    $normalized = FormSchemaLayout::normalize([
        'key' => $key,
        'version' => '1',
        'schema_version' => 2,
        'title' => 'Temporal',
        'fields' => [
            [
                'field_key' => 'fk_trip',
                'type' => 'date',
                'name' => 'trip',
                'required' => false,
                'nullable' => false,
                'default' => null,
                'rules' => [],
                'meta' => [],
            ],
        ],
    ]);

    expect($normalized['fields'][0]['type'])->toBe(FieldType::TEMPORAL);
    expect($normalized['fields'][0]['temporal_mode'])->toBe('date');
});

it('exports time temporal fields with a default 24-hour cycle', function (): void {
    $key = 'time_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->temporal('meeting_time', 'time');

    $schema = Form::get($key, '1')->toArray();

    expect($schema['fields'][0]['type'])->toBe(FieldType::TEMPORAL);
    expect($schema['fields'][0]['temporal_mode'])->toBe('time');
    expect($schema['fields'][0]['hour_cycle'])->toBe(24);
});

it('syncs runtime definitions idempotently and enforces immutability', function (): void {
    $key = 'sync_' . Str::lower(Str::random(8));
    $initialCategoryCount = FormCategory::query()->count();

    $manager = app(FormManager::class);

    $manager->define($key)
        ->title('Sync')
        ->version('1')
        ->text('name')->required();

    $first = $manager->sync();
    $second = $manager->sync();
    $definition = FormDefinition::query()->where('key', $key)->first();

    expect($first['created'])->toBe(1);
    expect($second['unchanged'])->toBe(1);
    expect($definition)->toBeInstanceOf(FormDefinition::class);
    expect($definition?->category)->toBe('');
    expect($definition?->form_category_id)->toBeNull();
    expect(FormCategory::query()->count())->toBe($initialCategoryCount);

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

it('accepts field_key payloads and mixed payloads with name priority', function (): void {
    $key = 'field_key_payload_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required()
        ->text('email')->required();

    $form = Form::get($key, '1');
    $schema = $form->toArray();
    $nameField = collect($schema['fields'])->firstWhere('name', 'name');
    $emailField = collect($schema['fields'])->firstWhere('name', 'email');
    $nameFieldKey = (string) ($nameField['field_key'] ?? '');
    $emailFieldKey = (string) ($emailField['field_key'] ?? '');

    $byFieldKey = $form->submit([
        $nameFieldKey => 'Evan',
        $emailFieldKey => 'evan@example.com',
    ]);
    expect($byFieldKey->payload)->toBe([
        'name' => 'Evan',
        'email' => 'evan@example.com',
    ]);

    $mixed = $form->submit([
        'name' => 'Name Wins',
        $nameFieldKey => 'Ignored',
        $emailFieldKey => 'mail@example.com',
    ]);
    expect($mixed->payload)->toBe([
        'name' => 'Name Wins',
        'email' => 'mail@example.com',
    ]);
});

it('rejects payload conflicts when multiple aliases target the same field without name', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'email',
            'field_key' => 'email_field_key',
            'key' => 'email_key',
            'type' => 'text',
            'rules' => ['required', 'email'],
        ]],
    ];

    expect(static fn () => $validator->validate($schema, [
        'email_field_key' => 'a@example.com',
        'email_key' => 'b@example.com',
    ]))->toThrow(\EvanSchleret\FormForge\Exceptions\FormForgeException::class);
});

it('validates a single field through form methods', function (): void {
    $key = 'field_validate_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('email')->required()->rules('email');

    $form = Form::get($key, '1');
    $valid = $form->validateField('email', 'evan@example.com');
    $invalid = $form->validateField('email', 'not-an-email');

    expect($valid['valid'])->toBeTrue();
    expect($valid['errors'])->toBe([]);
    expect($invalid['valid'])->toBeFalse();
    expect($invalid['errors'])->toHaveKey('email');
});

it('validates a single field through aliases', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'email',
            'field_key' => ' email_field_key ',
            'key' => ' email_key ',
            'id' => ' email_id ',
            'type' => 'text',
            'rules' => ['required', 'email'],
        ]],
    ];

    $validByName = $validator->validateField($schema, 'email', 'evan@example.com');
    $validByFieldKey = $validator->validateField($schema, 'email_field_key', 'evan@example.com');
    $validByKey = $validator->validateField($schema, 'email_key', 'evan@example.com');
    $validById = $validator->validateField($schema, 'email_id', 'evan@example.com');

    expect($validByName['valid'])->toBeTrue();
    expect($validByFieldKey['valid'])->toBeTrue();
    expect($validByKey['valid'])->toBeTrue();
    expect($validById['valid'])->toBeTrue();

    expect(static fn () => $validator->validateField($schema, 'unknown_field_alias', 'evan@example.com'))
        ->toThrow(UnknownFieldsException::class);
});

it('stores field validation rules in the serialized schema', function (): void {
    $key = 'validation_meta_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('nickname')
        ->meta([
            'validation' => [
                'match' => 'all',
                'rules' => [
                    [
                        'validation_key' => 'vr_min',
                        'operator' => 'min',
                        'value' => 3,
                        'unit' => 'characters',
                    ],
                ],
            ],
        ]);

    $schema = Form::get($key, '1')->toArray();
    $validation = $schema['fields'][0]['meta']['validation'] ?? null;

    expect($validation)->toBeArray();
    expect($validation['match'])->toBe('all');
    expect($validation['rules'])->toHaveCount(1);
});

it('validates field meta rules on the backend', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'nickname',
            'field_key' => 'nickname_field_key',
            'type' => 'text',
            'required' => true,
            'rules' => ['required', 'string'],
            'meta' => [
                'validation' => [
                    'match' => 'all',
                    'rules' => [
                        [
                            'validation_key' => 'vr_min',
                            'operator' => 'min',
                            'value' => 3,
                            'unit' => 'characters',
                        ],
                        [
                            'validation_key' => 'vr_max',
                            'operator' => 'max',
                            'value' => 8,
                            'unit' => 'characters',
                        ],
                        [
                            'validation_key' => 'vr_regex',
                            'operator' => 'regex',
                            'value' => '^[a-z]+$',
                        ],
                        [
                            'validation_key' => 'vr_contains',
                            'operator' => 'contains',
                            'value' => 'abc',
                        ],
                        [
                            'validation_key' => 'vr_not_contains',
                            'operator' => 'not_contains',
                            'value' => 'zzz',
                        ],
                        [
                            'validation_key' => 'vr_eq',
                            'operator' => 'eq',
                            'value' => 'abcde',
                        ],
                        [
                            'validation_key' => 'vr_neq',
                            'operator' => 'neq',
                            'value' => 'forbidden',
                        ],
                    ],
                ],
            ],
        ]],
    ];

    $valid = $validator->validateField($schema, 'nickname', 'abcde');
    $invalid = $validator->validateField($schema, 'nickname', 'abczzz');

    expect($valid['valid'])->toBeTrue();
    expect($valid['errors'])->toBe([]);
    expect($invalid['valid'])->toBeFalse();
    expect($invalid['errors'])->toHaveKey('nickname');
});

it('supports any-match field meta validation on the backend', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'nickname',
            'field_key' => 'nickname_field_key',
            'type' => 'text',
            'required' => true,
            'rules' => ['required', 'string'],
            'meta' => [
                'validation' => [
                    'match' => 'any',
                    'rules' => [
                        [
                            'validation_key' => 'vr_eq',
                            'operator' => 'eq',
                            'value' => 'alpha',
                        ],
                        [
                            'validation_key' => 'vr_contains',
                            'operator' => 'contains',
                            'value' => 'beta',
                        ],
                    ],
                ],
            ],
        ]],
    ];

    $accepted = $validator->validateField($schema, 'nickname', 'beta value');
    $rejected = $validator->validateField($schema, 'nickname', 'gamma value');

    expect($accepted['valid'])->toBeTrue();
    expect($rejected['valid'])->toBeFalse();
});

it('validates temporal field meta rules on the backend', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'meeting',
            'field_key' => 'meeting_field_key',
            'type' => 'temporal',
            'temporal_mode' => 'date',
            'required' => true,
            'rules' => ['required'],
            'meta' => [
                'validation' => [
                    'match' => 'all',
                    'rules' => [
                        [
                            'validation_key' => 'vr_after',
                            'operator' => 'after',
                            'value' => '2026-01-01',
                        ],
                        [
                            'validation_key' => 'vr_before',
                            'operator' => 'before',
                            'value' => '2026-12-31',
                        ],
                        [
                            'validation_key' => 'vr_between',
                            'operator' => 'between',
                            'value' => [
                                'start' => '2026-03-01',
                                'end' => '2026-09-30',
                            ],
                        ],
                    ],
                ],
            ],
        ]],
    ];

    $valid = $validator->validateField($schema, 'meeting', '2026-06-15');
    $tooEarly = $validator->validateField($schema, 'meeting', '2025-12-31');
    $tooLate = $validator->validateField($schema, 'meeting', '2027-01-01');
    $outsideRange = $validator->validateField($schema, 'meeting', '2026-10-01');

    expect($valid['valid'])->toBeTrue();
    expect($tooEarly['valid'])->toBeFalse();
    expect($tooLate['valid'])->toBeFalse();
    expect($outsideRange['valid'])->toBeFalse();
});

it('describes fields with normalized descriptors', function (): void {
    $key = 'describe_fields_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->radio('country')->required()->options([
            ['label' => 'France', 'value' => 'fr'],
            ['label' => 'Switzerland', 'value' => 'ch'],
        ])
        ->text('nickname');

    $descriptors = Form::get($key, '1')->describeFields();
    $country = collect($descriptors)->firstWhere('name', 'country');
    $nickname = collect($descriptors)->firstWhere('name', 'nickname');

    expect($country)->not->toBeNull();
    expect($country['required'])->toBeTrue();
    expect($country['options'])->toHaveCount(2);
    expect($country['lookup_keys'])->toContain('country');
    expect($country['field_key'])->not->toBeNull();
    expect($country['rules'])->toContain('required');

    expect($nickname)->not->toBeNull();
    expect($nickname['required'])->toBeFalse();
    expect($nickname['options'])->toBe([]);
});

it('exports flattened exportable fields for simple and address fields', function (): void {
    $key = 'exportable_fields_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->label('Full name')->required()
        ->address('address')->label('Mailing address')->addressFields([
            ['key' => 'line1', 'label' => 'Address line 1', 'visible' => true, 'required' => true],
            ['key' => 'line2', 'label' => 'Address line 2', 'visible' => false, 'required' => false],
            ['key' => 'city', 'label' => 'City', 'visible' => true, 'required' => true],
            ['key' => 'state', 'label' => 'State', 'visible' => false, 'required' => false],
            ['key' => 'zip', 'label' => 'Postal code', 'visible' => true, 'required' => true],
            ['key' => 'country', 'label' => 'Country', 'visible' => true, 'required' => true],
        ]);

    $fields = Form::get($key, '1')->exportableFields();
    $paths = array_column($fields, 'path');
    $name = collect($fields)->firstWhere('path', 'name');
    $addressLine1 = collect($fields)->firstWhere('path', 'address.line1');
    $addressLine2 = collect($fields)->firstWhere('path', 'address.line2');

    expect($paths)->toBe([
        'name',
        'address.line1',
        'address.city',
        'address.zip',
        'address.country',
    ]);

    expect($name)->not->toBeNull();
    expect($name['label'])->toBe('Full name');
    expect($name['type'])->toBe(FieldType::TEXT);
    expect($name['required'])->toBeTrue();
    expect($name['composite'])->toBeFalse();

    expect($addressLine1)->not->toBeNull();
    expect($addressLine1['id'])->toContain('.line1');
    expect($addressLine1['label'])->toBe('Address line 1');
    expect($addressLine1['type'])->toBe(FieldType::ADDRESS);
    expect($addressLine1['required'])->toBeTrue();
    expect($addressLine1['composite'])->toBeTrue();
    expect($addressLine1['subfield'])->toBe([
        'key' => 'line1',
        'label' => 'Address line 1',
        'required' => true,
        'visible' => true,
    ]);

    expect($addressLine2)->toBeNull();
});

it('validates exportable headers and maps rows back to nested payloads', function (): void {
    $key = 'exportable_headers_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('email')->required()
        ->address('address')->addressFields([
            ['key' => 'line1', 'label' => 'Address line 1', 'visible' => true, 'required' => true],
            ['key' => 'line2', 'label' => 'Address line 2', 'visible' => false, 'required' => false],
            ['key' => 'city', 'label' => 'City', 'visible' => true, 'required' => true],
            ['key' => 'state', 'label' => 'State', 'visible' => false, 'required' => false],
            ['key' => 'zip', 'label' => 'Postal code', 'visible' => true, 'required' => true],
            ['key' => 'country', 'label' => 'Country', 'visible' => true, 'required' => true],
        ]);

    $form = Form::get($key, '1');

    $valid = $form->validateExportableHeaders([
        'email',
        'address.line1',
        'address.city',
        'address.zip',
        'address.country',
    ]);

    expect($valid['valid'])->toBeTrue();
    expect($valid['missing'])->toBe([]);
    expect($valid['unknown'])->toBe([]);

    $invalid = $form->validateExportableHeaders([
        'email',
        'address.line1',
        'address.zip',
        'unexpected',
    ]);

    expect($invalid['valid'])->toBeFalse();
    expect($invalid['missing'])->toBe([
        'address.city',
        'address.country',
    ]);
    expect($invalid['unknown'])->toBe(['unexpected']);

    $mapped = $form->mapExportableRow([
        'email' => 'evan@example.com',
        'address.line1' => '1 Route des Acacias',
        'address.city' => 'Geneva',
        'address.zip' => '1200',
        'address.country' => 'CH',
        'unexpected' => 'ignored',
    ], false);

    expect($mapped)->toBe([
        'email' => 'evan@example.com',
        'address' => [
            'line1' => '1 Route des Acacias',
            'city' => 'Geneva',
            'zip' => '1200',
            'country' => 'CH',
        ],
    ]);
});

it('validates partial fields subset and reports canonical errors', function (): void {
    $key = 'validate_fields_subset_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('email')->required()->rules('email')
        ->number('age')->required();

    $form = Form::get($key, '1');
    $invalid = $form->validateFields([
        'email' => 'invalid',
        'age' => 'abc',
    ]);

    expect($invalid['valid'])->toBeFalse();
    expect($invalid['errors'])->toHaveKey('email');
    expect($invalid['errors'])->toHaveKey('age');

    $valid = $form->validateFields([
        'email' => 'evan@example.com',
        'age' => 42,
    ], ['email']);

    expect($valid['valid'])->toBeTrue();
    expect($valid['validated'])->toHaveKey('email', 'evan@example.com');
    expect($valid['validated'])->not->toHaveKey('age');
});

it('validates partial fields with aliases and unresolved identifiers', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'email',
            'field_key' => 'email_field_key',
            'key' => 'email_key',
            'id' => 'email_id',
            'type' => 'text',
            'rules' => ['required', 'email'],
        ]],
    ];

    $valid = $validator->validateFields($schema, ['email_field_key' => 'evan@example.com'], ['email_id']);
    expect($valid['valid'])->toBeTrue();
    expect($valid['validated'])->toHaveKey('email', 'evan@example.com');

    $invalid = $validator->validateFields($schema, ['email' => 'evan@example.com'], ['missing_alias']);
    expect($invalid['valid'])->toBeFalse();
    expect($invalid['errors'])->toHaveKey('missing_alias');
});

it('ignores unknown payload keys in partial validation', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'email',
            'type' => 'text',
            'rules' => ['required', 'email'],
        ]],
    ];

    $result = $validator->validateFields($schema, [
        'email' => 'evan@example.com',
        'unknown' => 'value',
    ]);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBe([]);
    expect($result['validated'])->toBe(['email' => 'evan@example.com']);
});

it('translates partial validation messages to french with explicit locale', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'email',
            'type' => 'text',
            'rules' => ['required', 'email'],
        ]],
    ];

    $result = $validator->validateFields($schema, ['email' => 'evan@example.com'], ['missing_alias'], 'fr');

    expect($result['valid'])->toBeFalse();
    expect((string) ($result['errors']['missing_alias'][0] ?? ''))->toBe('Identifiant de champ inconnu.');
});

it('uses request query locale on the fly for validation messages', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'email',
            'type' => 'text',
            'rules' => ['required', 'email'],
        ]],
    ];

    app()->instance('request', Request::create('/fake?formforge_locale=fr', 'POST'));

    $result = $validator->validateFields($schema, ['email' => 'evan@example.com'], ['missing_alias']);

    expect($result['valid'])->toBeFalse();
    expect((string) ($result['errors']['missing_alias'][0] ?? ''))->toBe('Identifiant de champ inconnu.');
});

it('uses request header locale and falls back to english for unsupported locales', function (): void {
    $validator = app(SubmissionValidator::class);
    $schema = [
        'fields' => [[
            'name' => 'email',
            'type' => 'text',
            'rules' => ['required', 'email'],
        ]],
    ];

    app()->instance('request', Request::create('/fake', 'POST', [], [], [], ['HTTP_X_FORMFORGE_LOCALE' => 'fr']));

    $frResult = $validator->validateFields($schema, ['email' => 'evan@example.com'], ['missing_alias']);
    expect((string) ($frResult['errors']['missing_alias'][0] ?? ''))->toBe('Identifiant de champ inconnu.');

    app()->instance('request', Request::create('/fake?formforge_locale=es', 'POST'));

    $fallback = $validator->validateFields($schema, ['email' => 'evan@example.com'], ['missing_alias']);
    expect((string) ($fallback['errors']['missing_alias'][0] ?? ''))->toBe('Unknown field identifier.');
});

it('normalizes primitive and temporal values before persistence', function (): void {
    $key = 'normalize_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->number('age')->required()
        ->number('price')->required()
        ->addField(FieldType::CONSENT, 'newsletter')->required()
        ->addField(FieldType::CONSENT, 'active')->required()
        ->date('birthday')->required()
        ->time('meeting_time')->required()
        ->temporal('meeting_at', 'time')->required()
        ->temporal('vacation', 'date')->required()
        ->temporal('window', 'time')->required();

    $submission = Form::get($key, '1')->submit([
        'age' => '42',
        'price' => '3.14',
        'newsletter' => '1',
        'active' => true,
        'birthday' => '2026-01-05',
        'meeting_time' => '09:10:11',
        'meeting_at' => '09:10:11',
        'vacation' => '2026-08-01',
        'window' => '10:10:11',
    ]);

    expect($submission->payload['age'])->toBe(42);
    expect($submission->payload['price'])->toBe(3.14);
    expect($submission->payload['newsletter'])->toBeTrue();
    expect($submission->payload['active'])->toBeTrue();
    expect($submission->payload['birthday'])->toBe('2026-01-05');
    expect($submission->payload['meeting_time'])->toBe('09:10:11');
    expect($submission->payload['meeting_at'])->toBe('09:10:11');
    expect($submission->payload['vacation'])->toBe('2026-08-01');
    expect($submission->payload['window'])->toBe('10:10:11');
});

it('validates date boundaries for date fields', function (): void {
    $key = 'date_bounds_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->date('birthday')
        ->required()
        ->min('2026-01-01')
        ->max('2026-12-31');

    $submission = Form::get($key, '1')->submit([
        'birthday' => '2026-06-15',
    ]);

    expect($submission->payload['birthday'])->toBe('2026-06-15');

    expect(static fn () => Form::get($key, '1')->submit([
        'birthday' => '2025-12-31',
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
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
    Schema::create('records', function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('form_submission_id');
        $table->string('email');
        $table->string('plan');
    });

    $key = 'automation_' . Str::lower(Str::random(8));

    Form::automation($key)
        ->sync()
        ->handler(CreateRecordFromSubmissionAutomation::class, 'create_record');

    Form::define($key)
        ->version('1')
        ->text('email')->required()->rules('email')
        ->text('plan')->required();

    $submission = Form::get($key, '1')->submit([
        'email' => 'member@example.com',
        'plan' => 'pro',
    ]);

    expect(DB::table('records')->count())->toBe(1);
    expect(DB::table('records')->value('email'))->toBe('member@example.com');
    expect(DB::table('records')->value('plan'))->toBe('pro');
    expect((int) ($submission->refresh()->meta['record_id'] ?? 0))->toBeGreaterThan(0);

    $run = SubmissionAutomationRun::query()->first();

    expect($run)->not->toBeNull();
    expect($run?->automation_key)->toBe('create_record');
    expect($run?->status)->toBe('completed');

    app(SubmissionAutomationDispatcher::class)->dispatch($submission);

    expect(DB::table('records')->count())->toBe(1);

    $run->refresh();
    expect($run->attempts)->toBe(1);
});

it('runs resolver-based submission automations using runtime form resolution', function (): void {
    Schema::create('records', function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('form_submission_id');
        $table->string('email');
        $table->string('plan');
    });

    $firstKey = 'automation_first_' . Str::lower(Str::random(8));
    $secondKey = 'automation_second_' . Str::lower(Str::random(8));

    Form::automationForResolver(ActiveFormKeyAutomationResolver::class)
        ->sync()
        ->handler(CreateRecordFromSubmissionAutomation::class, 'create_record_active');

    Form::define($firstKey)
        ->version('1')
        ->text('email')->required()->rules('email')
        ->text('plan')->required();

    Form::define($secondKey)
        ->version('1')
        ->text('email')->required()->rules('email')
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

    expect(DB::table('records')->count())->toBe(1);
    expect(DB::table('records')->value('email'))->toBe('first@example.com');

    config()->set('formforge.tests.active_form_key', $secondKey);

    Form::get($secondKey, '1')->submit([
        'email' => 'second@example.com',
        'plan' => 'pro',
    ]);

    expect(DB::table('records')->count())->toBe(2);
    expect(DB::table('records')->orderByDesc('id')->value('email'))->toBe('second@example.com');

    expect(
        SubmissionAutomationRun::query()->where('automation_key', 'create_record_active')->count()
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

it('creates a category through artisan command', function (): void {
    $this->artisan('formforge:category:create "Customer Survey" --slug=customer-survey --description="Survey forms" --is-active=0 --is-system=1')
        ->expectsOutputToContain('Category created.')
        ->assertExitCode(0);

    $category = FormCategory::query()->where('slug', 'customer-survey')->first();

    expect($category)->toBeInstanceOf(FormCategory::class);
    expect($category?->name)->toBe('Customer Survey');
    expect($category?->description)->toBe('Survey forms');
    expect((bool) $category?->is_active)->toBeFalse();
    expect((bool) $category?->is_system)->toBeTrue();
});

it('prompts for category creation when no argument is provided', function (): void {
    $this->artisan('formforge:category:create')
        ->expectsQuestion('Category name', 'Interactive Category')
        ->expectsQuestion('Category slug (optional)', 'interactive-category')
        ->expectsQuestion('Category description (optional)', 'Created from prompt')
        ->expectsConfirmation('Is category active?', 'yes')
        ->expectsConfirmation('Is this a system category?', 'no')
        ->expectsOutputToContain('Category created.')
        ->assertExitCode(0);

    $category = FormCategory::query()->where('slug', 'interactive-category')->first();

    expect($category)->toBeInstanceOf(FormCategory::class);
    expect($category?->name)->toBe('Interactive Category');
    expect($category?->description)->toBe('Created from prompt');
    expect((bool) $category?->is_active)->toBeTrue();
    expect((bool) $category?->is_system)->toBeFalse();
});

it('scaffolds a submission automation class with make command', function (): void {
    $directory = storage_path('app/formforge-tests/automations/' . Str::lower(Str::random(8)));
    File::ensureDirectoryExists($directory);

    $this->artisan("formforge:make:automation Examples/CreateRecord --path={$directory} --namespace=App\\\\FormForge\\\\Automations --form=generic-application --sync")
        ->expectsOutputToContain('Automation created:')
        ->expectsOutputToContain("Form::automation('generic-application')->sync()->handler(")
        ->assertExitCode(0);

    $generated = $directory . '/Examples/CreateRecord.php';

    expect(File::exists($generated))->toBeTrue();

    $content = (string) File::get($generated);

    expect($content)->toContain('namespace App\\FormForge\\Automations\\Examples;');
    expect($content)->toContain('class CreateRecord implements SubmissionAutomation');
    expect($content)->toContain('public function handle(FormSubmission $submission): void');
});

it('scaffolds a submission automation resolver class with make command', function (): void {
    $directory = storage_path('app/formforge-tests/automation-resolvers/' . Str::lower(Str::random(8)));
    File::ensureDirectoryExists($directory);

    $this->artisan("formforge:make:automation-resolver Examples/ResolveActiveForm --path={$directory} --namespace=App\\\\FormForge\\\\AutomationResolvers")
        ->expectsOutputToContain('Automation resolver created:')
        ->expectsOutputToContain('Form::automationForResolver(')
        ->assertExitCode(0);

    $generated = $directory . '/Examples/ResolveActiveForm.php';

    expect(File::exists($generated))->toBeTrue();

    $content = (string) File::get($generated);

    expect($content)->toContain('namespace App\\FormForge\\AutomationResolvers\\Examples;');
    expect($content)->toContain('class ResolveActiveForm implements SubmissionAutomationResolver');
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

it('resolves the latest active form by uuid across versions', function (): void {
    $formUuid = (string) Str::uuid();
    $key = 'uuid_latest_' . Str::lower(Str::random(8));

    $createDefinition = function (
        string $version,
        int $versionNumber,
        bool $active = true,
        ?string $ownerType = null,
        ?string $ownerId = null,
    ) use ($formUuid, $key): FormDefinition {
        $schema = [
            'key' => $key,
            'version' => $version,
            'schema_version' => 2,
            'title' => 'UUID latest ' . $version,
            'fields' => [],
        ];

        return FormDefinition::query()->create([
            'key' => $key,
            'form_uuid' => $formUuid,
            'revision_id' => (string) Str::ulid(),
            'version' => $version,
            'version_number' => $versionNumber,
            'title' => 'UUID latest ' . $version,
            'schema' => $schema,
            'schema_hash' => hash('sha256', json_encode($schema) ?: ''),
            'is_active' => $active,
            'is_published' => true,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ]);
    };

    $createDefinition('1', 1);
    $createDefinition('2', 2);
    $createDefinition('3', 3);
    $createDefinition('3b', 3);
    $createDefinition('4', 4, false);

    $latest = Form::latestByUuid($formUuid);

    expect($latest->version())->toBe('3b');
    expect($latest->key())->toBe($key);
});

it('resolves the latest active form by uuid through the scoped owner API', function (): void {
    $formUuid = (string) Str::uuid();
    $key = 'uuid_scoped_' . Str::lower(Str::random(8));
    $ownerA = ['type' => 'user', 'id' => '101'];
    $ownerB = ['type' => 'user', 'id' => '202'];

    $schema = static fn (string $version) => [
        'key' => $key,
        'version' => $version,
        'schema_version' => 2,
        'title' => 'Scoped UUID ' . $version,
        'fields' => [],
    ];

    FormDefinition::query()->create([
        'key' => $key,
        'form_uuid' => $formUuid,
        'revision_id' => (string) Str::ulid(),
        'version' => '1',
        'version_number' => 1,
        'title' => 'Scoped UUID 1',
        'schema' => $schema('1'),
        'schema_hash' => hash('sha256', json_encode($schema('1')) ?: ''),
        'is_active' => true,
        'is_published' => true,
        'owner_type' => $ownerA['type'],
        'owner_id' => $ownerA['id'],
    ]);

    FormDefinition::query()->create([
        'key' => $key,
        'form_uuid' => $formUuid,
        'revision_id' => (string) Str::ulid(),
        'version' => '2',
        'version_number' => 2,
        'title' => 'Scoped UUID 2',
        'schema' => $schema('2'),
        'schema_hash' => hash('sha256', json_encode($schema('2')) ?: ''),
        'is_active' => true,
        'is_published' => true,
        'owner_type' => $ownerA['type'],
        'owner_id' => $ownerA['id'],
    ]);

    FormDefinition::query()->create([
        'key' => $key,
        'form_uuid' => $formUuid,
        'revision_id' => (string) Str::ulid(),
        'version' => '3b',
        'version_number' => 3,
        'title' => 'Scoped UUID 3b',
        'schema' => $schema('3b'),
        'schema_hash' => hash('sha256', json_encode($schema('3b')) ?: ''),
        'is_active' => true,
        'is_published' => true,
        'owner_type' => $ownerB['type'],
        'owner_id' => $ownerB['id'],
    ]);

    $latestA = Form::for($ownerA)->latestByUuid($formUuid);
    $latestB = Form::for($ownerB)->latestByUuid($formUuid);

    expect($latestA->version)->toBe('2');
    expect($latestB->version)->toBe('3b');
});

it('throws when no form exists for a uuid lookup', function (): void {
    $missing = (string) Str::uuid();

    expect(static fn () => Form::latestByUuid($missing))
        ->toThrow(FormNotFoundException::class);
});

it('exports submissions through facade in csv and jsonl formats', function (): void {
    $key = 'exports_facade_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    Form::get($key, '1')->submit([
        'name' => 'Live',
    ]);

    Form::get($key, '1')->submit(
        payload: ['name' => 'Test'],
        isTest: true,
    );

    $csv = Form::exportSubmissions($key, 'csv', [
        'is_test' => '0',
    ]);

    $csvLines = array_values(array_filter(array_map(static fn (string $line): string => trim($line), explode("\n", $csv)), static fn (string $line): bool => $line !== ''));

    expect($csvLines)->toHaveCount(2);
    expect($csvLines[0])->toContain('id,form_key,form_version');
    expect($csvLines[1])->toContain($key);
    expect($csvLines[1])->toContain('"Live"');

    $jsonl = Form::exportSubmissions($key, 'jsonl', [
        'is_test' => '1',
    ]);

    $jsonlLines = array_values(array_filter(array_map(static fn (string $line): string => trim($line), explode("\n", $jsonl)), static fn (string $line): bool => $line !== ''));

    expect($jsonlLines)->toHaveCount(1);

    $decoded = json_decode($jsonlLines[0], true);

    expect($decoded)->toBeArray();
    expect($decoded['form_key'] ?? null)->toBe($key);
    expect($decoded['is_test'] ?? null)->toBeTrue();
    expect($decoded['payload']['name'] ?? null)->toBe('Test');
});

it('exports scoped submissions through Form::for owner API', function (): void {
    $ownerA = ['type' => 'user', 'id' => '101'];
    $ownerB = ['type' => 'user', 'id' => '202'];

    $created = Form::for($ownerA)->create([
        'title' => 'Scoped Export',
        'fields' => [
            ['type' => 'text', 'name' => 'name', 'required' => true],
        ],
    ]);

    $key = (string) $created->key;

    Form::get($key, '1')->submit([
        'name' => 'Scoped Row',
    ]);

    $csvA = Form::for($ownerA)->exportSubmissions($key, 'csv');
    $csvB = Form::for($ownerB)->exportSubmissions($key, 'csv');

    $csvALines = array_values(array_filter(array_map(static fn (string $line): string => trim($line), explode("\n", $csvA)), static fn (string $line): bool => $line !== ''));
    $csvBLines = array_values(array_filter(array_map(static fn (string $line): string => trim($line), explode("\n", $csvB)), static fn (string $line): bool => $line !== ''));

    expect($csvALines)->toHaveCount(2);
    expect($csvALines[1])->toContain('Scoped Row');
    expect($csvBLines)->toHaveCount(1);
});

it('exports submissions with artisan command', function (): void {
    $key = 'exports_command_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    Form::get($key, '1')->submit([
        'name' => 'Command Live',
    ]);

    Form::get($key, '1')->submit(
        payload: ['name' => 'Command Test'],
        isTest: true,
    );

    $directory = storage_path('app/formforge-tests/exports/' . Str::lower(Str::random(8)));
    File::ensureDirectoryExists($directory);
    $output = $directory . '/submissions.jsonl';

    $this->artisan("formforge:submissions:export {$key} --format=jsonl --is-test=1 --output={$output}")
        ->expectsOutputToContain('Export generated:')
        ->expectsOutputToContain('Rows exported: 1')
        ->assertExitCode(0);

    expect(File::exists($output))->toBeTrue();

    $content = trim((string) File::get($output));
    $decoded = json_decode($content, true);

    expect($decoded)->toBeArray();
    expect($decoded['form_key'] ?? null)->toBe($key);
    expect($decoded['is_test'] ?? null)->toBeTrue();
    expect($decoded['payload']['name'] ?? null)->toBe('Command Test');
});

it('applies form-level GDPR policy through facade run', function (): void {
    $key = 'gdpr_form_policy_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required()
        ->text('email')->required()->rules('email');

    Form::get($key, '1')->submit([
        'name' => 'Sensitive',
        'email' => 'sensitive@example.com',
    ]);

    Form::setGdprFormPolicy($key, [
        'action' => 'anonymize',
        'after_days' => 0,
        'anonymize_fields' => ['name'],
        'enabled' => true,
    ]);

    $result = Form::runGdpr();

    expect((int) ($result['eligible'] ?? 0))->toBe(1);
    expect((int) ($result['anonymized'] ?? 0))->toBe(1);

    $submission = FormSubmission::query()->where('form_key', $key)->first();

    expect($submission)->toBeInstanceOf(FormSubmission::class);
    expect($submission?->payload['name'] ?? null)->toBe('[redacted]');
    expect($submission?->payload['email'] ?? null)->toBe('sensitive@example.com');
    expect($submission?->anonymized_at)->not->toBeNull();
});

it('applies GDPR action immediately for one response through facade', function (): void {
    $key = 'gdpr_response_now_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required()
        ->text('email')->required()->rules('email');

    $submission = Form::get($key, '1')->submit([
        'name' => 'One-off',
        'email' => 'one-off@example.com',
    ]);

    $result = Form::scheduleGdprResponseAction($key, (string) $submission->uuid, 'anonymize', [
        'now' => true,
        'fields' => ['email'],
    ]);

    expect((bool) ($result['executed'] ?? false))->toBeTrue();
    expect((bool) ($result['anonymized'] ?? false))->toBeTrue();

    $fresh = FormSubmission::query()->where('uuid', (string) $submission->uuid)->first();

    expect($fresh)->toBeInstanceOf(FormSubmission::class);
    expect($fresh?->payload['name'] ?? null)->toBe('One-off');
    expect($fresh?->payload['email'] ?? null)->toBe('[redacted]');
    expect($fresh?->anonymized_at)->not->toBeNull();
});

it('runs GDPR command in dry-run mode', function (): void {
    $key = 'gdpr_dry_run_' . Str::lower(Str::random(8));

    Form::define($key)
        ->version('1')
        ->text('name')->required();

    Form::get($key, '1')->submit([
        'name' => 'Dry run',
    ]);

    Form::setGdprFormPolicy($key, [
        'action' => 'anonymize',
        'after_days' => 0,
        'enabled' => true,
    ]);

    $this->artisan('formforge:gdpr:run --dry-run')
        ->expectsOutputToContain('GDPR dry-run completed.')
        ->assertExitCode(0);

    $submission = FormSubmission::query()->where('form_key', $key)->first();

    expect($submission)->toBeInstanceOf(FormSubmission::class);
    expect($submission?->payload['name'] ?? null)->toBe('Dry run');
    expect($submission?->anonymized_at)->toBeNull();
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
