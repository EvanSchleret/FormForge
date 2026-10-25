<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Tests\TestCase;
use EvanSchleret\FormForge\Tests\Fixtures\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('formforge.http.management.auth', 'public');
    config()->set('formforge.http.management.ability', null);
});

function createFormForgeStatisticsFixtureForm(TestCase $test, array $fields, array $conditions = []): string
{
    $response = $test->postJson('/api/formforge/v1/forms', [
        'title' => 'Statistics ' . Str::lower(Str::random(8)),
        'fields' => $fields,
        'pages' => [],
        'conditions' => $conditions,
    ]);

    $response->assertCreated();

    return (string) $response->json('data.key');
}

function createFormForgeStatisticsFixtureSubmission(
    string $formKey,
    string $version,
    array $payload,
    string $createdAt,
    bool $isTest = false,
): FormSubmission {
    return FormSubmission::query()->create([
        'form_key' => $formKey,
        'form_version' => $version,
        'payload' => $payload,
        'is_test' => $isTest,
        'created_at' => Carbon::parse($createdAt),
        'updated_at' => Carbon::parse($createdAt),
    ]);
}

it('summarizes every supported question type and its daily series', function (): void {
    $fields = [
        ['field_key' => 'fk_feedback', 'type' => 'text', 'name' => 'feedback', 'label' => 'Feedback'],
        ['field_key' => 'fk_rating', 'type' => 'number', 'name' => 'rating', 'label' => 'Rating'],
        [
            'field_key' => 'fk_recommend',
            'type' => 'radio',
            'name' => 'recommend',
            'label' => 'Recommend',
            'options' => [
                ['label' => 'Yes', 'value' => 'yes'],
                ['label' => 'No', 'value' => 'no'],
            ],
        ],
        [
            'field_key' => 'fk_topics',
            'type' => 'checkbox_group',
            'name' => 'topics',
            'label' => 'Topics',
            'options' => [
                ['label' => 'Features', 'value' => 'features'],
                ['label' => 'Price', 'value' => 'price'],
            ],
        ],
        ['field_key' => 'fk_date', 'type' => 'temporal', 'temporal_mode' => 'date', 'name' => 'preferred_date', 'label' => 'Preferred date'],
        ['field_key' => 'fk_time', 'type' => 'temporal', 'temporal_mode' => 'time', 'name' => 'preferred_time', 'label' => 'Preferred time'],
        ['field_key' => 'fk_files', 'type' => 'file', 'name' => 'attachments', 'label' => 'Attachments'],
        [
            'field_key' => 'fk_address',
            'type' => 'address',
            'name' => 'address',
            'label' => 'Address',
            'address_fields' => [
                ['key' => 'line1', 'label' => 'Address line 1', 'visible' => true, 'required' => true],
                ['key' => 'line2', 'label' => 'Address line 2', 'visible' => true, 'required' => false],
                ['key' => 'city', 'label' => 'City', 'visible' => true, 'required' => true],
                ['key' => 'country', 'label' => 'Country', 'visible' => false, 'required' => false],
            ],
        ],
        ['field_key' => 'fk_has_company', 'type' => 'consent', 'name' => 'has_company', 'label' => 'Has company'],
        ['field_key' => 'fk_company_name', 'type' => 'text', 'name' => 'company_name', 'label' => 'Company name'],
    ];
    $conditions = [[
        'condition_key' => 'cd_hide_company',
        'target_type' => 'field',
        'target_key' => 'fk_company_name',
        'action' => 'hide',
        'match' => 'all',
        'when' => [[
            'field_key' => 'fk_has_company',
            'operator' => 'eq',
            'value' => false,
        ]],
    ]];
    $key = createFormForgeStatisticsFixtureForm($this, $fields, $conditions);

    createFormForgeStatisticsFixtureSubmission($key, '1', [
        'feedback' => 'Great',
        'rating' => 4,
        'recommend' => 'yes',
        'topics' => ['features', 'price'],
        'preferred_date' => '2026-05-04',
        'preferred_time' => '09:30',
        'attachments' => [
            ['name' => 'report.pdf', 'size' => 100],
            ['name' => 'image.png', 'size' => 200],
        ],
        'address' => ['line1' => 'Main street', 'line2' => 'Suite 2', 'city' => 'Geneva', 'country' => 'CH'],
        'has_company' => true,
        'company_name' => 'Acme',
    ], '2026-05-04 09:00:00');
    createFormForgeStatisticsFixtureSubmission($key, '1', [
        'feedback' => 'Okay!',
        'rating' => 0,
        'recommend' => 'no',
        'topics' => ['price', 'features', 'features'],
        'preferred_date' => '2026-06-15',
        'preferred_time' => '14:45',
        'attachments' => ['name' => 'notes.txt', 'size' => 50],
        'address' => ['line1' => 'Lake road', 'city' => 'Zurich', 'country' => 'CH'],
        'has_company' => false,
    ], '2026-05-06 14:00:00');

    $response = $this->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1&timezone=UTC")
        ->assertOk()
        ->assertJsonPath('data.totals.submissions', 2)
        ->assertJsonPath('data.period.granularity', 'day')
        ->assertJsonCount(3, 'data.series')
        ->assertJsonPath('data.series.1.submissions', 0);

    $questions = collect($response->json('data.questions'))->keyBy('name');

    expect($questions)->toHaveCount(10)
        ->and($questions['feedback']['series'])->toHaveCount(3)
        ->and($questions['feedback']['series'][1])->toMatchArray([
            'period' => '2026-05-05',
            'eligible_count' => 0,
            'answered_count' => 0,
            'response_rate' => 0,
            'summary' => [
                'kind' => 'text',
                'count' => 0,
                'min_length' => null,
                'average_length' => null,
                'max_length' => null,
            ],
        ])
        ->and($questions['feedback']['summary'])->toBe([
            'kind' => 'text',
            'count' => 2,
            'min_length' => 5,
            'average_length' => 5,
            'max_length' => 5,
        ])
        ->and($questions['rating']['summary'])->toBe([
            'kind' => 'number',
            'count' => 2,
            'min' => 0,
            'average' => 2,
            'max' => 4,
        ])
        ->and($questions['recommend']['summary']['kind'])->toBe('choice')
        ->and($questions['recommend']['summary']['choices'][0])->toMatchArray([
            'value' => 'yes',
            'count' => 1,
            'percentage' => 50,
        ])
        ->and($questions['topics']['summary']['kind'])->toBe('multiple_choice')
        ->and($questions['topics']['summary']['total_selections'])->toBe(4)
        ->and($questions['topics']['summary']['choices'][0])->toMatchArray([
            'value' => 'features',
            'count' => 2,
            'percentage' => 100,
        ])
        ->and($questions['preferred_date']['summary']['buckets'])->toBe([
            ['key' => '2026-05', 'count' => 1, 'percentage' => 50],
            ['key' => '2026-06', 'count' => 1, 'percentage' => 50],
        ])
        ->and($questions['preferred_time']['summary']['buckets'])->toBe([
            ['key' => '09:00', 'count' => 1, 'percentage' => 50],
            ['key' => '14:00', 'count' => 1, 'percentage' => 50],
        ])
        ->and($questions['attachments']['summary'])->toBe([
            'kind' => 'file',
            'file_count' => 3,
            'total_size_bytes' => 350,
            'average_size_bytes' => 116.67,
        ])
        ->and($questions['address']['summary']['complete_count'])->toBe(1)
        ->and($questions['address']['series'][2]['summary']['complete_count'])->toBe(0)
        ->and($questions['address']['summary']['parts'])->toHaveCount(3)
        ->and(collect($questions['address']['summary']['parts'])->firstWhere('key', 'line2'))
        ->toMatchArray(['eligible_count' => 2, 'answered_count' => 1, 'response_rate' => 50])
        ->and($questions['has_company']['summary']['choices'][0])->toMatchArray([
            'value' => true,
            'count' => 1,
            'percentage' => 50,
        ])
        ->and($questions['company_name']['eligible_count'])->toBe(1)
        ->and($questions['company_name']['answered_count'])->toBe(1)
        ->and($questions['company_name']['series'][2])->toMatchArray([
            'period' => '2026-05-06',
            'eligible_count' => 0,
            'answered_count' => 0,
            'response_rate' => 0,
        ]);
});

it('filters statistics by version, test mode and timezone-aware date bounds', function (): void {
    $key = createFormForgeStatisticsFixtureForm($this, [
        ['field_key' => 'fk_answer', 'type' => 'text', 'name' => 'answer', 'label' => 'Answer'],
    ]);
    $this->patchJson("/api/formforge/v1/forms/{$key}", [
        'title' => 'Statistics version 2',
        'fields' => [
            ['field_key' => 'fk_answer_v2', 'type' => 'text', 'name' => 'answer', 'label' => 'Answer'],
        ],
    ])->assertOk();

    createFormForgeStatisticsFixtureSubmission($key, '1', ['answer' => 'Included'], '2026-05-01 22:30:00');
    createFormForgeStatisticsFixtureSubmission($key, '1', ['answer' => 'Out of local day'], '2026-05-02 22:30:00');
    createFormForgeStatisticsFixtureSubmission($key, '1', ['answer' => 'Test submission'], '2026-05-01 23:00:00', true);
    createFormForgeStatisticsFixtureSubmission($key, '2', ['answer' => 'Other version'], '2026-05-01 23:30:00');

    $this->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1&from=2026-05-02&to=2026-05-02&timezone=Europe%2FZurich&granularity=week")
        ->assertOk()
        ->assertJsonPath('data.version', '1')
        ->assertJsonPath('data.period.timezone', 'Europe/Zurich')
        ->assertJsonPath('data.period.granularity', 'week')
        ->assertJsonPath('data.totals.submissions', 1)
        ->assertJsonPath('data.series.0.period', '2026-W18')
        ->assertJsonPath('data.series.0.from', '2026-04-27')
        ->assertJsonPath('data.series.0.to', '2026-05-03');

    $this->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1&is_test=1&timezone=UTC")
        ->assertOk()
        ->assertJsonPath('data.totals.submissions', 1);
});

it('validates statistics query filters', function (): void {
    $key = 'statistics_validation_' . Str::lower(Str::random(8));

    $this->getJson("/api/formforge/v1/forms/{$key}/statistics")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['version']);

    $this->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1&from=2026-05-03&to=2026-05-02&granularity=month&timezone=Invalid%2FTimezone")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to', 'granularity', 'timezone']);
});

it('returns zero-safe summaries when a form has no submissions', function (): void {
    $key = createFormForgeStatisticsFixtureForm($this, [
        ['field_key' => 'fk_feedback', 'type' => 'text', 'name' => 'feedback', 'label' => 'Feedback'],
    ]);

    $this->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1&timezone=UTC")
        ->assertOk()
        ->assertJsonPath('data.totals.submissions', 0)
        ->assertJsonCount(0, 'data.series')
        ->assertJsonPath('data.questions.0.eligible_count', 0)
        ->assertJsonPath('data.questions.0.answered_count', 0)
        ->assertJsonPath('data.questions.0.response_rate', 0)
        ->assertJsonPath('data.questions.0.summary.count', 0)
        ->assertJsonPath('data.questions.0.summary.average_length', null);
});

it('requires the management statistics ability', function (): void {
    $key = createFormForgeStatisticsFixtureForm($this, [
        ['field_key' => 'fk_answer', 'type' => 'text', 'name' => 'answer', 'label' => 'Answer'],
    ]);
    config()->set('formforge.http.management.auth', 'required');
    config()->set('formforge.http.management.abilities.statistics', 'formforge.statistics');
    Gate::define('formforge.statistics', static fn (?User $user): bool => $user !== null && $user->name === 'allowed');

    $this->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1")
        ->assertUnauthorized();

    $denied = User::query()->create(['name' => 'denied']);
    $this->actingAs($denied)
        ->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1")
        ->assertForbidden();

    $allowed = User::query()->create(['name' => 'allowed']);
    $this->actingAs($allowed)
        ->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1")
        ->assertOk();
});

it('summarizes ten thousand submissions across database chunks', function (): void {
    $key = createFormForgeStatisticsFixtureForm($this, [
        ['field_key' => 'fk_answer', 'type' => 'text', 'name' => 'answer', 'label' => 'Answer'],
    ]);
    $timestamp = '2026-05-04 09:00:00';
    $rows = [];

    for ($index = 0; $index < 10001; $index++) {
        $rows[] = [
            'uuid' => (string) Str::uuid(),
            'form_key' => $key,
            'form_version' => '1',
            'payload' => json_encode(['answer' => 'completed'], JSON_THROW_ON_ERROR),
            'is_test' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    foreach (array_chunk($rows, 100) as $batch) {
        FormSubmission::query()->insert($batch);
    }

    $this->getJson("/api/formforge/v1/forms/{$key}/statistics?version=1&timezone=UTC")
        ->assertOk()
        ->assertJsonPath('data.totals.submissions', 10001)
        ->assertJsonPath('data.series.0.submissions', 10001)
        ->assertJsonPath('data.questions.0.eligible_count', 10001)
        ->assertJsonPath('data.questions.0.answered_count', 10001)
        ->assertJsonPath('data.questions.0.summary.count', 10001);
});
