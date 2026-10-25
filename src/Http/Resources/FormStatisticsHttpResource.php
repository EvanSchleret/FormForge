<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Resources;

use EvanSchleret\LaravelTypeBridge\Attributes\TypeBridgeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[TypeBridgeResource(
    name: 'FormForgeStatisticsResponse',
    fileName: 'statistics',
    structure: [
        'form_key' => 'string',
        'version' => 'string',
        'period' => 'FormForgeStatisticsPeriodFilter',
        'totals' => 'FormForgeStatisticsTotals',
        'series' => 'FormForgeStatisticsPeriod[]',
        'questions' => 'FormForgeQuestionStatistics[]',
    ],
    types: [
        'FormForgeStatisticsGranularity' => '"day" | "week"',
        'FormForgeStatisticsPeriod' => '{ period: string; from: string; to: string; submissions: number }',
        'FormForgeStatisticsPeriodFilter' => '{ from: string | null; to: string | null; granularity: FormForgeStatisticsGranularity; timezone: string }',
        'FormForgeStatisticsTotals' => '{ submissions: number }',
        'FormForgeStatisticsChoice' => '{ value: string | number | boolean | null; label: string; count: number; percentage: number }',
        'FormForgeStatisticsBucket' => '{ key: string; count: number; percentage: number }',
        'FormForgeStatisticsAddressPart' => '{ key: string; label: string; eligible_count: number; answered_count: number; response_rate: number }',
        'FormForgeQuestionStatisticsPoint' => '{ period: string; eligible_count: number; answered_count: number; response_rate: number; summary: FormForgeQuestionStatisticsSummary }',
        'FormForgeQuestionStatistics' => '{ field_key: string; name: string; label: string; type: string; eligible_count: number; answered_count: number; response_rate: number; summary: FormForgeQuestionStatisticsSummary; series: FormForgeQuestionStatisticsPoint[] }',
    ],
    append: [
        'export type FormForgeQuestionStatisticsSummary =',
        '  | { kind: "text"; count: number; min_length: number | null; average_length: number | null; max_length: number | null }',
        '  | { kind: "number"; count: number; min: number | null; average: number | null; max: number | null }',
        '  | { kind: "address"; complete_count: number; parts: FormForgeStatisticsAddressPart[] }',
        '  | { kind: "file"; file_count: number; total_size_bytes: number; average_size_bytes: number | null }',
        '  | { kind: "temporal"; buckets: FormForgeStatisticsBucket[] }',
        '  | { kind: "multiple_choice"; total_selections: number; choices: FormForgeStatisticsChoice[] }',
        '  | { kind: "choice"; choices: FormForgeStatisticsChoice[] }',
        '  | { kind: "count"; count: number }',
    ],
)]
final class FormStatisticsHttpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
