<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Support\FormSchemaLayout;
use Illuminate\Support\Carbon;

class SubmissionStatisticsService
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly SubmissionReadService $submissions,
    ) {
    }

    public function summarize(
        string $formKey,
        FormDefinition $definition,
        array $filters = [],
        ?OwnershipReference $owner = null,
    ): array {
        $timezone = $this->timezone($filters['timezone'] ?? config('app.timezone', 'UTC'));
        $granularity = ($filters['granularity'] ?? 'day') === 'week' ? 'week' : 'day';
        $from = $this->bound($filters['from'] ?? null, false, $timezone);
        $to = $this->bound($filters['to'] ?? null, true, $timezone);
        $appTimezone = $this->timezone(config('app.timezone', 'UTC'));
        $queryFilters = [
            'version' => (string) $definition->version,
            'is_test' => $this->boolean($filters['is_test'] ?? false),
        ];

        if ($from instanceof Carbon) {
            $queryFilters['from'] = $from->copy()->setTimezone($appTimezone)->format('Y-m-d H:i:s');
        }

        if ($to instanceof Carbon) {
            $queryFilters['to'] = $to->copy()->setTimezone($appTimezone)->format('Y-m-d H:i:s');
        }

        $query = $this->submissions->queryForForm($formKey, $queryFilters, $owner);
        $firstCreatedAt = (clone $query)->orderBy('created_at')->value('created_at');
        $lastCreatedAt = (clone $query)->orderByDesc('created_at')->value('created_at');
        $schema = is_array($definition->schema) ? $definition->schema : [];
        $fields = FormSchemaLayout::normalize($schema)['fields'] ?? [];
        $fieldDefinitions = [];
        $questionStatistics = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fieldKey = (string) ($field['field_key'] ?? '');

            if ($fieldKey === '') {
                continue;
            }

            $fieldDefinitions[$fieldKey] = $field;
            $questionStatistics[$fieldKey] = $this->emptyQuestionStatistics($field);
        }

        $periodStatistics = [];
        $submissionCount = 0;

        $query->chunkById(self::CHUNK_SIZE, function ($rows) use (
            &$questionStatistics,
            &$periodStatistics,
            &$submissionCount,
            $fieldDefinitions,
            $schema,
            $timezone,
            $granularity,
        ): void {
            foreach ($rows as $submission) {
                if (! $submission instanceof FormSubmission) {
                    continue;
                }

                $submissionCount++;
                $payload = is_array($submission->payload) ? $submission->payload : [];
                $createdAt = $this->submissionDate($submission->created_at, $timezone);
                $periodKey = $createdAt instanceof Carbon
                    ? $this->periodKey($createdAt, $granularity)
                    : null;

                if ($periodKey !== null && ! array_key_exists($periodKey, $periodStatistics)) {
                    $periodStatistics[$periodKey] = [
                        'submissions' => 0,
                        'questions' => [],
                    ];
                }

                if ($periodKey !== null) {
                    $periodStatistics[$periodKey]['submissions']++;
                }

                $resolved = FormSchemaLayout::resolve($schema, $payload);
                $visibleFieldKeys = [];

                foreach ((array) ($resolved['fields'] ?? []) as $visibleField) {
                    if (is_array($visibleField) && is_string($visibleField['field_key'] ?? null)) {
                        $visibleFieldKeys[$visibleField['field_key']] = true;
                    }
                }

                foreach ($fieldDefinitions as $fieldKey => $field) {
                    if (! isset($visibleFieldKeys[$fieldKey])) {
                        continue;
                    }

                    $name = (string) ($field['name'] ?? '');
                    $value = $name !== '' ? ($payload[$name] ?? null) : null;
                    $answered = $this->hasAnswer($value);
                    $this->recordQuestionAnswer($questionStatistics[$fieldKey], $field, $value, $answered);

                    if ($periodKey !== null) {
                        if (! array_key_exists($fieldKey, $periodStatistics[$periodKey]['questions'])) {
                            $periodStatistics[$periodKey]['questions'][$fieldKey] = $this->emptyQuestionStatistics($field);
                        }

                        $this->recordQuestionAnswer(
                            $periodStatistics[$periodKey]['questions'][$fieldKey],
                            $field,
                            $value,
                            $answered,
                        );
                    }
                }
            }
        }, 'id');

        $periods = $this->periods($from, $to, $firstCreatedAt, $lastCreatedAt, $timezone, $granularity);
        $series = [];

        foreach ($periods as $period) {
            $key = $period['period'];
            $series[] = [
                ...$period,
                'submissions' => (int) ($periodStatistics[$key]['submissions'] ?? 0),
            ];
        }

        $questions = [];

        foreach ($fieldDefinitions as $fieldKey => $field) {
            $statistics = $questionStatistics[$fieldKey];
            $questionSeries = [];

            foreach ($periods as $period) {
                $periodQuestion = $periodStatistics[$period['period']]['questions'][$fieldKey]
                    ?? $this->emptyQuestionStatistics($field);
                $questionSeries[] = [
                    'period' => $period['period'],
                    'eligible_count' => $periodQuestion['eligible_count'],
                    'answered_count' => $periodQuestion['answered_count'],
                    'response_rate' => $this->rate($periodQuestion['answered_count'], $periodQuestion['eligible_count']),
                    'summary' => $this->summarizeQuestion($field, $periodQuestion),
                ];
            }

            $questions[] = [
                'field_key' => $fieldKey,
                'name' => (string) ($field['name'] ?? ''),
                'label' => (string) ($field['label'] ?? $field['name'] ?? ''),
                'type' => (string) ($field['type'] ?? 'text'),
                'eligible_count' => $statistics['eligible_count'],
                'answered_count' => $statistics['answered_count'],
                'response_rate' => $this->rate($statistics['answered_count'], $statistics['eligible_count']),
                'summary' => $this->summarizeQuestion($field, $statistics),
                'series' => $questionSeries,
            ];
        }

        return [
            'form_key' => $formKey,
            'version' => (string) $definition->version,
            'period' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
                'granularity' => $granularity,
                'timezone' => $timezone,
            ],
            'totals' => [
                'submissions' => $submissionCount,
            ],
            'series' => $series,
            'questions' => $questions,
        ];
    }

    private function emptyQuestionStatistics(array $field): array
    {
        $choices = [];
        $type = (string) ($field['type'] ?? '');

        foreach ((array) ($field['options'] ?? []) as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : $option;
            $label = is_array($option) && is_string($option['label'] ?? null)
                ? (string) $option['label']
                : $this->displayValue($value);
            $key = $this->valueKey($value);

            $choices[$key] = [
                'value' => $value,
                'label' => $label,
                'count' => 0,
            ];
        }

        if (in_array($type, ['checkbox', 'consent', 'switch'], true)) {
            foreach ([true, false] as $value) {
                $key = $this->valueKey($value);
                $choices[$key] = [
                    'value' => $value,
                    'label' => $value ? 'true' : 'false',
                    'count' => 0,
                ];
            }
        }

        $addressParts = [];

        foreach ((array) ($field['address_fields'] ?? []) as $part) {
            if (! is_array($part) || ! (bool) ($part['visible'] ?? true)) {
                continue;
            }

            $key = (string) ($part['key'] ?? '');

            if ($key !== '') {
                $addressParts[$key] = [
                    'label' => (string) ($part['label'] ?? $key),
                    'eligible_count' => 0,
                    'answered_count' => 0,
                ];
            }
        }

        return [
            'eligible_count' => 0,
            'answered_count' => 0,
            'choices' => $choices,
            'total_selections' => 0,
            'numeric_count' => 0,
            'numeric_sum' => 0.0,
            'numeric_min' => null,
            'numeric_max' => null,
            'text_length_sum' => 0,
            'text_length_min' => null,
            'text_length_max' => null,
            'file_count' => 0,
            'file_size_total' => 0,
            'temporal_buckets' => [],
            'address_complete_count' => 0,
            'address_parts' => $addressParts,
        ];
    }

    private function recordQuestionAnswer(array &$statistics, array $field, mixed $value, bool $answered): void
    {
        $statistics['eligible_count']++;
        $type = (string) ($field['type'] ?? '');

        if ($type === 'address') {
            $this->recordAddressStatistics($statistics, $field, $value, $answered);
        }

        if (! $answered) {
            return;
        }

        $statistics['answered_count']++;

        if (in_array($type, ['text', 'textarea', 'email'], true) && is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            $statistics['text_length_sum'] += $length;
            $statistics['text_length_min'] = $statistics['text_length_min'] === null
                ? $length
                : min($statistics['text_length_min'], $length);
            $statistics['text_length_max'] = $statistics['text_length_max'] === null
                ? $length
                : max($statistics['text_length_max'], $length);

            return;
        }

        if ($type === 'number' && is_numeric($value)) {
            $number = (float) $value;
            $statistics['numeric_count']++;
            $statistics['numeric_sum'] += $number;
            $statistics['numeric_min'] = $statistics['numeric_min'] === null
                ? $number
                : min($statistics['numeric_min'], $number);
            $statistics['numeric_max'] = $statistics['numeric_max'] === null
                ? $number
                : max($statistics['numeric_max'], $number);

            return;
        }

        if ($type === 'checkbox_group') {
            $values = is_array($value) ? $value : [$value];
            $seenOptions = [];

            foreach ($values as $selectedValue) {
                $choiceKey = $this->valueKey($selectedValue);

                if (! isset($seenOptions[$choiceKey]) && array_key_exists($choiceKey, $statistics['choices'])) {
                    $statistics['choices'][$choiceKey]['count']++;
                    $statistics['total_selections']++;
                    $seenOptions[$choiceKey] = true;
                }
            }

            return;
        }

        if (in_array($type, ['radio', 'select', 'select_menu', 'checkbox', 'consent', 'switch'], true)) {
            $choiceKey = $this->valueKey($value);

            if (array_key_exists($choiceKey, $statistics['choices'])) {
                $statistics['choices'][$choiceKey]['count']++;
            }

            return;
        }

        if ($type === 'temporal') {
            $bucket = $this->temporalBucket($value, (string) ($field['temporal_mode'] ?? 'date'));

            if ($bucket !== null) {
                $statistics['temporal_buckets'][$bucket] = ($statistics['temporal_buckets'][$bucket] ?? 0) + 1;
            }

            return;
        }

        if ($type === 'file') {
            foreach ($this->fileItems($value) as $file) {
                if (! is_array($file)) {
                    continue;
                }

                $statistics['file_count']++;
                $size = is_numeric($file['size'] ?? null) ? max(0, (int) $file['size']) : 0;
                $statistics['file_size_total'] += $size;
            }

            return;
        }

    }

    private function recordAddressStatistics(array &$statistics, array $field, mixed $value, bool $answered): void
    {
        $answeredParts = 0;
        $visibleParts = count($statistics['address_parts']);
        $allRequiredPartsAnswered = true;

        foreach ($statistics['address_parts'] as $key => &$partStatistics) {
            $partStatistics['eligible_count']++;
            $partValue = is_array($value) ? ($value[$key] ?? null) : null;
                $partAnswered = $this->hasAnswer($partValue);

            if ($partAnswered) {
                $partStatistics['answered_count']++;
                $answeredParts++;
            }

            if ((bool) ($this->addressPart($field, $key)['required'] ?? false) && ! $partAnswered) {
                $allRequiredPartsAnswered = false;
            }
        }
        unset($partStatistics);

        if ($answered && $visibleParts > 0 && $answeredParts === $visibleParts && $allRequiredPartsAnswered) {
            $statistics['address_complete_count']++;
        }
    }

    private function summarizeQuestion(array $field, array $statistics): array
    {
        $type = (string) ($field['type'] ?? '');
        $answered = (int) $statistics['answered_count'];

        if (in_array($type, ['text', 'textarea', 'email'], true)) {
            return [
                'kind' => 'text',
                'count' => $answered,
                'min_length' => $statistics['text_length_min'],
                'average_length' => $answered > 0 ? round($statistics['text_length_sum'] / $answered, 2) : null,
                'max_length' => $statistics['text_length_max'],
            ];
        }

        if ($type === 'number') {
            return [
                'kind' => 'number',
                'count' => $statistics['numeric_count'],
                'min' => $statistics['numeric_min'],
                'average' => $statistics['numeric_count'] > 0
                    ? round($statistics['numeric_sum'] / $statistics['numeric_count'], 2)
                    : null,
                'max' => $statistics['numeric_max'],
            ];
        }

        if ($type === 'address') {
            $parts = [];

            foreach ($statistics['address_parts'] as $key => $part) {
                $parts[] = [
                    'key' => $key,
                    'label' => $part['label'],
                    'eligible_count' => $part['eligible_count'],
                    'answered_count' => $part['answered_count'],
                    'response_rate' => $this->rate($part['answered_count'], $part['eligible_count']),
                ];
            }

            return [
                'kind' => 'address',
                'complete_count' => $statistics['address_complete_count'],
                'parts' => $parts,
            ];
        }

        if ($type === 'file') {
            return [
                'kind' => 'file',
                'file_count' => $statistics['file_count'],
                'total_size_bytes' => $statistics['file_size_total'],
                'average_size_bytes' => $statistics['file_count'] > 0
                    ? round($statistics['file_size_total'] / $statistics['file_count'], 2)
                    : null,
            ];
        }

        if ($type === 'temporal') {
            return [
                'kind' => 'temporal',
                'buckets' => $this->bucketRows($statistics['temporal_buckets'], $answered),
            ];
        }

        if ($type === 'checkbox_group') {
            return [
                'kind' => 'multiple_choice',
                'total_selections' => $statistics['total_selections'],
                'choices' => $this->choiceRows($statistics['choices'], $answered),
            ];
        }

        if (in_array($type, ['radio', 'select', 'select_menu', 'checkbox', 'consent', 'switch'], true)) {
            return [
                'kind' => 'choice',
                'choices' => $this->choiceRows($statistics['choices'], $answered),
            ];
        }

        return [
            'kind' => 'count',
            'count' => $answered,
        ];
    }

    private function choiceRows(array $choices, int $respondents): array
    {
        return array_values(array_map(fn (array $choice): array => [
            'value' => $choice['value'],
            'label' => $choice['label'],
            'count' => $choice['count'],
            'percentage' => $this->rate($choice['count'], $respondents),
        ], $choices));
    }

    private function bucketRows(array $buckets, int $respondents): array
    {
        ksort($buckets);

        $rows = [];

        foreach ($buckets as $key => $count) {
            $rows[] = [
                'key' => (string) $key,
                'count' => $count,
                'percentage' => $this->rate($count, $respondents),
            ];
        }

        return $rows;
    }

    private function periods(
        ?Carbon $from,
        ?Carbon $to,
        mixed $firstCreatedAt,
        mixed $lastCreatedAt,
        string $timezone,
        string $granularity,
    ): array {
        $first = $from?->copy() ?? $this->submissionDate($firstCreatedAt, $timezone);
        $last = $to?->copy() ?? $this->submissionDate($lastCreatedAt, $timezone);

        if (! $first instanceof Carbon && $last instanceof Carbon) {
            $first = $last->copy();
        }

        if (! $last instanceof Carbon && $first instanceof Carbon) {
            $last = $first->copy();
        }

        if (! $first instanceof Carbon || ! $last instanceof Carbon) {
            return [];
        }

        $cursor = $this->periodStart($first, $granularity);
        $end = $this->periodStart($last, $granularity);
        $periods = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $periodEnd = $granularity === 'week'
                ? $cursor->copy()->endOfWeek(Carbon::SUNDAY)
                : $cursor->copy()->endOfDay();
            $periods[] = [
                'period' => $this->periodKey($cursor, $granularity),
                'from' => $cursor->toDateString(),
                'to' => $periodEnd->toDateString(),
            ];
            $cursor = $granularity === 'week' ? $cursor->addWeek() : $cursor->addDay();
        }

        return $periods;
    }

    private function periodStart(Carbon $date, string $granularity): Carbon
    {
        $period = $date->copy();

        return $granularity === 'week'
            ? $period->startOfWeek(Carbon::MONDAY)
            : $period->startOfDay();
    }

    private function periodKey(Carbon $date, string $granularity): string
    {
        $local = $date->copy();

        return $granularity === 'week'
            ? $local->startOfWeek(Carbon::MONDAY)->format('o-\WW')
            : $local->format('Y-m-d');
    }

    private function temporalBucket(mixed $value, string $mode): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if ($mode === 'time' && preg_match('/^(\d{2}):\d{2}/', $value, $matches) === 1) {
            return $matches[1] . ':00';
        }

        if ($mode !== 'time' && preg_match('/^(\d{4}-\d{2})-\d{2}/', $value, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function fileItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            return [$value];
        }

        return $value;
    }

    private function hasAnswer(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasAnswer($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_bool($value) || is_numeric($value);
    }

    private function addressPart(array $field, string $key): array
    {
        foreach ((array) ($field['address_fields'] ?? []) as $part) {
            if (is_array($part) && ($part['key'] ?? null) === $key) {
                return $part;
            }
        }

        return [];
    }

    private function valueKey(mixed $value): string
    {
        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        return is_string($encoded) ? $encoded : serialize($value);
    }

    private function displayValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return $value === null ? '' : (string) $value;
        }

        return '';
    }

    private function rate(int|float $count, int|float $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 2) : 0.0;
    }

    private function bound(mixed $value, bool $endOfDay, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $raw = trim($value);

        try {
            $date = Carbon::parse($raw, $timezone);
        } catch (\Throwable) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    private function submissionDate(mixed $value, string $timezone): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value, $this->timezone(config('app.timezone', 'UTC')))
                ->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function timezone(mixed $value): string
    {
        $timezone = is_string($value) && trim($value) !== '' ? trim($value) : 'UTC';

        try {
            Carbon::now($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
